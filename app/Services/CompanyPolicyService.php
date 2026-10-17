<?php

namespace App\Services;

use App\Models\AccountAudit;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\CompanyPolicyAcknowledgement;
use App\Models\CompanyPolicyVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    public function defaultPolicy(User $user): ?CompanyPolicy
    {
        return $this->company($user)->policies()->orderBy('id')->first();
    }

    private function currentVersions(User $user): Builder
    {
        $company = $this->company($user);

        return CompanyPolicyVersion::where('company_id', $company->id)
            ->whereHas('policy', fn ($query) => $query->where('company_id', $company->id)->whereColumn('company_policies.current_policy_version_id', 'company_policy_versions.id'))
            ->orderBy('company_policy_id');
    }

    public function current(User $user, ?int $policyId = null): ?CompanyPolicyVersion
    {
        return $this->currentVersions($user)->when($policyId !== null, fn ($query) => $query->where('company_policy_id', $policyId))->first();
    }

    public function requiredFor(User $user): ?CompanyPolicyVersion
    {
        if (! in_array($user->role, User::ASSIGNEE_ROLES)) {
            return null;
        }

        return $this->currentVersions($user)->whereDoesntHave('acknowledgements', fn ($query) => $query->where('company_id', $user->workspaceCompanyId())->where('user_id', $user->id))->first();
    }

    public function state(User $user, ?int $selectedPolicyId = null, ?int $selectedVersionId = null, int $historyPage = 1): array
    {
        $versions = $this->currentVersions($user)->get();
        $records = CompanyPolicyAcknowledgement::where('company_id', $user->workspaceCompanyId())->where('user_id', $user->id)
            ->whereIn('policy_version_id', $versions->modelKeys())->get()->keyBy('policy_version_id');
        $policies = $versions->map(function ($version) use ($records, $user) {
            $record = $records->get($version->id);

            return $version->toPolicyData() + ['required' => ! $record && in_array($user->role, User::ASSIGNEE_ROLES), 'acknowledged_at' => $record?->acknowledged_at->toIso8601String()];
        });
        $outstanding = $policies->where('required', true)->count();
        $selected = $selectedPolicyId === null ? ($policies->firstWhere('required', true) ?? $policies->first()) : $policies->firstWhere('policy_id', $selectedPolicyId);
        abort_if($selectedPolicyId !== null && ! $selected, 404);
        $current = $selected;
        $history = ['data' => [], 'meta' => ['page' => 1, 'last_page' => 1, 'total' => 0]];
        if ($current) {
            $query = CompanyPolicyVersion::where('company_id', $user->workspaceCompanyId())->where('company_policy_id', $current['policy_id']);
            if ($selectedVersionId !== null) {
                $version = (clone $query)->where('version', '<=', $current['version'])->findOrFail($selectedVersionId);
                $record = $this->acknowledgement($user, $version);
                $selected = $version->toPolicyData() + ['required' => $version->id === $current['id'] && $current['required'], 'acknowledged_at' => $record?->acknowledged_at->toIso8601String()];
            }
            $previous = $query->where('version', '<', $current['version'])->orderByDesc('version')->paginate(20, ['id', 'company_policy_id', 'version', 'title', 'published_at'], 'history_page', $historyPage);
            $previousRecords = CompanyPolicyAcknowledgement::where('company_id', $user->workspaceCompanyId())->where('user_id', $user->id)
                ->whereIn('policy_version_id', $previous->modelKeys())->get()->keyBy('policy_version_id');
            $history = ['data' => $previous->getCollection()->map(fn ($version) => $version->only(['id', 'version', 'title']) + [
                'policy_id' => (int) $version->company_policy_id, 'published_at' => $version->published_at->toIso8601String(),
                'acknowledged_at' => $previousRecords->get($version->id)?->acknowledged_at->toIso8601String(),
            ])->all(), 'meta' => ['page' => $previous->currentPage(), 'last_page' => $previous->lastPage(), 'total' => $previous->total()]];
        }

        // Keep the single-policy fields for existing installed clients. Their
        // next refresh advances to the next outstanding policy, never past it.
        return ['policies' => $policies->values()->all(), 'required' => $outstanding > 0, 'outstanding_count' => $outstanding,
            'policy' => $selected, 'selected_required' => $selected['required'] ?? false, 'acknowledged_at' => $selected['acknowledged_at'] ?? null,
            'current_policy' => $current, 'selected_is_current' => $selected && $selected['id'] === $current['id'], 'history' => $history];
    }

    private function entry(Company $company, ?int $policyId, bool $create = false): CompanyPolicy
    {
        if ($policyId !== null) {
            return $company->policies()->lockForUpdate()->findOrFail($policyId);
        }

        return (! $create ? $company->policies()->orderBy('id')->lockForUpdate()->first() : null) ?? $company->policies()->create(['draft_revision' => 0]);
    }

    public function acknowledgement(User $user, CompanyPolicyVersion $policy): ?CompanyPolicyAcknowledgement
    {
        return CompanyPolicyAcknowledgement::where('company_id', $user->workspaceCompanyId())->where('user_id', $user->id)->where('policy_version_id', $policy->id)->first();
    }

    public function createDraft(User $owner, string $title, string $content, ?UploadedFile $pdf = null, ?string $format = null): CompanyPolicy
    {
        return $this->saveDraft($owner, $title, $content, 0, $pdf, $format, null, true);
    }

    public function saveDraft(User $owner, string $title, string $content, int $revision, ?UploadedFile $pdf = null, ?string $format = null, ?int $policyId = null, bool $create = false): CompanyPolicy
    {
        $storedPath = null;
        try {
            return DB::transaction(function () use ($owner, $title, $content, $revision, $pdf, $format, $policyId, $create, &$storedPath) {
                abort_unless($owner->role === 'admin', 403);
                $company = $this->company($owner, true);
                $entry = $this->entry($company, $policyId, $create);
                $this->checkRevision($entry, $revision);
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
                        $current = $entry->currentVersion;
                        foreach (array_keys($document) as $field) {
                            $document[$field] = $entry->{'draft_pdf_'.$field} ?? ($entry->draft_title === null ? $current?->{'pdf_'.$field} : null);
                        }
                        if (! $document['path'] || ! Storage::disk('evidence')->exists($document['path'])) {
                            throw ValidationException::withMessages(['policy_pdf' => 'Upload a PDF before saving this draft.']);
                        }
                    }
                    $content = '';
                } elseif (! filled($content)) {
                    throw ValidationException::withMessages(['content' => 'Enter the policy content or choose PDF upload.']);
                }
                $fields = ['draft_title' => $title, 'draft_content' => $content, 'draft_revision' => $revision + 1];
                if (! $entry->current_policy_version_id) {
                    $fields['name'] = $title;
                }
                foreach ($document as $field => $value) {
                    $fields['draft_pdf_'.$field] = $value;
                }
                $entry->update($fields);
                AccountAudit::create(['actor_id' => $owner->id, 'action' => 'policy_draft_saved', 'data' => ['company_id' => $company->id, 'policy_id' => $entry->id, 'draft_revision' => $revision + 1]]);

                return $entry->fresh();
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

    private function checkRevision(CompanyPolicy $entry, int $revision): void
    {
        if ($entry->draft_revision !== $revision) {
            throw ValidationException::withMessages(['draft_revision' => 'The policy draft changed. Reload this page before saving or publishing.']);
        }
    }

    public function publish(User $owner, int $revision, ?int $policyId = null): CompanyPolicyVersion
    {
        return DB::transaction(function () use ($owner, $revision, $policyId) {
            abort_unless($owner->role === 'admin', 403);
            $company = $this->company($owner, true);
            $entry = $this->entry($company, $policyId);
            $this->checkRevision($entry, $revision);
            if (! filled($entry->draft_title) || (! $entry->draft_pdf_path && ! filled($entry->draft_content))) {
                throw ValidationException::withMessages(['draft' => 'Save a policy draft before publishing.']);
            }
            if ($entry->draft_pdf_path && ! Storage::disk('evidence')->exists($entry->draft_pdf_path)) {
                throw ValidationException::withMessages(['policy_pdf' => 'The draft PDF is unavailable. Upload it again before publishing.']);
            }
            $policy = $entry->versions()->create([
                'company_id' => $company->id,
                'version' => ((int) $entry->versions()->max('version')) + 1,
                'title' => $entry->draft_title, 'content' => $entry->draft_content,
                'published_by_id' => $owner->id, 'published_at' => now(),
                'pdf_path' => $entry->draft_pdf_path, 'pdf_name' => $entry->draft_pdf_name,
                'pdf_size' => $entry->draft_pdf_size, 'pdf_sha256' => $entry->draft_pdf_sha256,
            ]);
            $entry->update(['name' => $policy->title, 'current_policy_version_id' => $policy->id, 'draft_title' => null, 'draft_content' => null, 'draft_revision' => $revision + 1, 'draft_pdf_path' => null, 'draft_pdf_name' => null, 'draft_pdf_size' => null, 'draft_pdf_sha256' => null]);
            User::whereHas('stores', fn ($q) => $q->where('stores.company_id', $company->id)->where('store_user.active', true)->where('stores.active', true))->where('active', true)->whereIn('role', User::ASSIGNEE_ROLES)->chunkById(100, function ($staff) use ($policy) {
                foreach ($staff as $person) {
                    app(AlertService::class)->send($person, null, 'policy_published', 'Company policy “'.Str::limit($policy->title, 100).'” version '.$policy->version.' is ready. Read and acknowledge it to continue.', 'policy:'.$policy->id.':user:'.$person->id, $policy->company_id);
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
            $submitted = CompanyPolicyVersion::where('company_id', $company->id)->find($versionId);
            $current = $submitted ? $this->current($user, $submitted->company_policy_id) : ($this->requiredFor($user) ?? $this->current($user));
            abort_unless($current, 422, 'There is no published company policy to acknowledge.');
            if ($current->id !== $versionId) {
                return ['acknowledged' => false, 'code' => self::REQUIRED, 'message' => 'A newer company policy is available. Read and acknowledge the current version.', 'policy' => $current->toPolicyData()];
            }
            $record = CompanyPolicyAcknowledgement::firstOrCreate([
                'company_id' => $company->id, 'user_id' => $freshUser->id, 'policy_version_id' => $current->id,
            ], ['acknowledged_at' => now()]);

            return ['acknowledged' => true, 'policy' => $current->toPolicyData(), 'acknowledged_at' => $record->acknowledged_at->toIso8601String(), 'remaining_count' => $this->state($user)['outstanding_count']];
        });
    }
}
