<?php

namespace App\Services;

use App\Models\AccountAudit;
use App\Models\Company;
use App\Models\CompanyPolicyAcknowledgement;
use App\Models\CompanyPolicyVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyPolicyService
{
    public const REQUIRED = 'POLICY_ACKNOWLEDGEMENT_REQUIRED';

    public function company(User $user, bool $lock = false): Company
    {
        abort_unless($user->active && $user->workspaceCompanyId(), 403, 'An active company account is required.');

        return Company::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($user->workspaceCompanyId());
    }

    public function current(User $user): ?CompanyPolicyVersion
    {
        $company = $this->company($user);

        return $company->policyVersions()->whereKey($company->current_policy_version_id)->first();
    }

    public function requiredFor(User $user): ?CompanyPolicyVersion
    {
        $policy = $this->current($user);
        if (! in_array($user->role, User::ASSIGNEE_ROLES) || ! $policy) {
            return null;
        }

        return $this->acknowledgement($user, $policy) ? null : $policy;
    }

    public function acknowledgement(User $user, CompanyPolicyVersion $policy): ?CompanyPolicyAcknowledgement
    {
        return CompanyPolicyAcknowledgement::where('company_id', $user->workspaceCompanyId())->where('user_id', $user->id)->where('policy_version_id', $policy->id)->first();
    }

    public function saveDraft(User $owner, string $title, string $content, int $revision, ?UploadedFile $pdf = null, ?string $format = null): void
    {
        $storedPath = null;
        try {
            DB::transaction(function () use ($owner, $title, $content, $revision, $pdf, $format, &$storedPath) {
                abort_unless($owner->role === 'admin', 403);
                $company = $this->company($owner, true);
                $this->checkRevision($company, $revision);
                $document = ['path' => null, 'name' => null, 'size' => null, 'sha256' => null];
                if (($format ?? ($pdf ? 'pdf' : 'text')) === 'pdf') {
                    if ($pdf) {
                        $storedPath = $pdf->storeAs('company-policies/'.$company->id, Str::uuid().'.pdf', 'evidence');
                        $document = [
                            'path' => $storedPath,
                            'name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $pdf->getClientOriginalName()))) ?: 'company-policy.pdf', 0, 180),
                            'size' => $pdf->getSize(), 'sha256' => hash_file('sha256', $pdf->getRealPath()),
                        ];
                    } else {
                        $current = $company->currentPolicy;
                        foreach (array_keys($document) as $field) {
                            $document[$field] = $company->{'policy_draft_pdf_'.$field} ?? ($company->policy_draft_title === null ? $current?->{'pdf_'.$field} : null);
                        }
                        if (! $document['path'] || ! Storage::disk('evidence')->exists($document['path'])) {
                            throw ValidationException::withMessages(['policy_pdf' => 'Upload a PDF before saving this draft.']);
                        }
                    }
                    $content = '';
                } elseif (! filled($content)) {
                    throw ValidationException::withMessages(['content' => 'Enter the policy content or choose PDF upload.']);
                }
                $fields = ['policy_draft_title' => $title, 'policy_draft_content' => $content, 'policy_draft_revision' => $revision + 1];
                foreach ($document as $field => $value) {
                    $fields['policy_draft_pdf_'.$field] = $value;
                }
                $company->update($fields);
                AccountAudit::create(['actor_id' => $owner->id, 'action' => 'policy_draft_saved', 'data' => ['company_id' => $company->id, 'draft_revision' => $revision + 1]]);
            });
        } catch (\Throwable $exception) {
            // File writes are not transactional. Remove only the new, uncommitted
            // upload; never delete a PDF referenced by a published version.
            if ($storedPath) {
                Storage::disk('evidence')->delete($storedPath);
            }
            throw $exception;
        }
    }

    private function checkRevision(Company $company, int $revision): void
    {
        if ($company->policy_draft_revision !== $revision) {
            throw ValidationException::withMessages(['draft_revision' => 'The policy draft changed. Reload this page before saving or publishing.']);
        }
    }

    public function publish(User $owner, int $revision): CompanyPolicyVersion
    {
        return DB::transaction(function () use ($owner, $revision) {
            abort_unless($owner->role === 'admin', 403);
            $company = $this->company($owner, true);
            $this->checkRevision($company, $revision);
            if (! filled($company->policy_draft_title) || (! $company->policy_draft_pdf_path && ! filled($company->policy_draft_content))) {
                throw ValidationException::withMessages(['draft' => 'Save a policy draft before publishing.']);
            }
            if ($company->policy_draft_pdf_path && ! Storage::disk('evidence')->exists($company->policy_draft_pdf_path)) {
                throw ValidationException::withMessages(['policy_pdf' => 'The draft PDF is unavailable. Upload it again before publishing.']);
            }
            $policy = $company->policyVersions()->create([
                'version' => ((int) $company->policyVersions()->max('version')) + 1,
                'title' => $company->policy_draft_title, 'content' => $company->policy_draft_content,
                'published_by_id' => $owner->id, 'published_at' => now(),
                'pdf_path' => $company->policy_draft_pdf_path, 'pdf_name' => $company->policy_draft_pdf_name,
                'pdf_size' => $company->policy_draft_pdf_size, 'pdf_sha256' => $company->policy_draft_pdf_sha256,
            ]);
            $company->update(['current_policy_version_id' => $policy->id, 'policy_draft_title' => null, 'policy_draft_content' => null, 'policy_draft_revision' => $revision + 1, 'policy_draft_pdf_path' => null, 'policy_draft_pdf_name' => null, 'policy_draft_pdf_size' => null, 'policy_draft_pdf_sha256' => null]);
            User::whereHas('stores', fn ($q) => $q->where('stores.company_id', $company->id)->where('store_user.active', true)->where('stores.active', true))->where('active', true)->whereIn('role', User::ASSIGNEE_ROLES)->chunkById(100, function ($staff) use ($policy) {
                foreach ($staff as $person) {
                    app(AlertService::class)->send($person, null, 'policy_published', 'Company policy version '.$policy->version.' is ready. Read and acknowledge it to continue.', 'policy:'.$policy->id.':user:'.$person->id, $policy->company_id);
                }
            });
            AccountAudit::create(['actor_id' => $owner->id, 'action' => 'policy_published', 'data' => ['company_id' => $company->id, 'policy_version_id' => $policy->id]]);

            return $policy;
        });
    }

    /** The company row serialises acknowledgement with publication of a new version. */
    public function acknowledge(User $user, int $versionId): array
    {
        return DB::transaction(function () use ($user, $versionId) {
            abort_unless(in_array($user->role, User::ASSIGNEE_ROLES), 403);
            $company = $this->company($user, true);
            $freshUser = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($freshUser->active && $freshUser->inStore($user->storeId()) && $user->workspaceCompanyId() === $company->id && in_array($freshUser->role, User::ASSIGNEE_ROLES), 403);
            $current = $company->policyVersions()->whereKey($company->current_policy_version_id)->first();
            abort_unless($current, 422, 'There is no published company policy to acknowledge.');
            if ($current->id !== $versionId) {
                return ['acknowledged' => false, 'code' => self::REQUIRED, 'message' => 'A newer company policy is available. Read and acknowledge the current version.', 'policy' => $current->toPolicyData()];
            }
            $record = CompanyPolicyAcknowledgement::firstOrCreate([
                'company_id' => $company->id, 'user_id' => $freshUser->id, 'policy_version_id' => $current->id,
            ], ['acknowledged_at' => now()]);

            return ['acknowledged' => true, 'policy' => $current->toPolicyData(), 'acknowledged_at' => $record->acknowledged_at->toIso8601String()];
        });
    }
}
