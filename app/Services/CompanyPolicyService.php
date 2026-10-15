<?php

namespace App\Services;

use App\Models\AccountAudit;
use App\Models\Company;
use App\Models\CompanyPolicyAcknowledgement;
use App\Models\CompanyPolicyVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyPolicyService
{
    public const REQUIRED = 'POLICY_ACKNOWLEDGEMENT_REQUIRED';

    public function company(User $user, bool $lock = false): Company
    {
        abort_unless($user->active && $user->company_id, 403, 'An active company account is required.');

        return Company::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($user->company_id);
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
        return CompanyPolicyAcknowledgement::where('company_id', $user->company_id)->where('user_id', $user->id)->where('policy_version_id', $policy->id)->first();
    }

    public function saveDraft(User $owner, string $title, string $content, int $revision): void
    {
        DB::transaction(function () use ($owner, $title, $content, $revision) {
            abort_unless($owner->role === 'admin', 403);
            $company = $this->company($owner, true);
            $this->checkRevision($company, $revision);
            $company->update(['policy_draft_title' => $title, 'policy_draft_content' => $content, 'policy_draft_revision' => $revision + 1]);
            AccountAudit::create(['actor_id' => $owner->id, 'action' => 'policy_draft_saved', 'data' => ['company_id' => $company->id, 'draft_revision' => $revision + 1]]);
        });
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
            if (! filled($company->policy_draft_title) || ! filled($company->policy_draft_content)) {
                throw ValidationException::withMessages(['draft' => 'Save a policy draft before publishing.']);
            }
            $policy = $company->policyVersions()->create([
                'version' => ((int) $company->policyVersions()->max('version')) + 1,
                'title' => $company->policy_draft_title, 'content' => $company->policy_draft_content,
                'published_by_id' => $owner->id, 'published_at' => now(),
            ]);
            $company->update(['current_policy_version_id' => $policy->id, 'policy_draft_title' => null, 'policy_draft_content' => null, 'policy_draft_revision' => $revision + 1]);
            User::where('company_id', $company->id)->where('active', true)->whereIn('role', User::ASSIGNEE_ROLES)->chunkById(100, function ($staff) use ($policy) {
                foreach ($staff as $person) {
                    app(AlertService::class)->send($person, null, 'policy_published', 'Company policy version '.$policy->version.' is ready. Read and acknowledge it to continue.', 'policy:'.$policy->id.':user:'.$person->id);
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
            abort_unless($freshUser->active && $freshUser->company_id === $company->id && in_array($freshUser->role, User::ASSIGNEE_ROLES), 403);
            $current = $company->policyVersions()->whereKey($company->current_policy_version_id)->first();
            abort_unless($current, 422, 'There is no published company policy to acknowledge.');
            if ($current->id !== $versionId) {
                return ['acknowledged' => false, 'code' => self::REQUIRED, 'message' => 'A newer company policy is available. Read and acknowledge the current version.', 'policy' => $current->toPolicyData()];
            }
            $record = CompanyPolicyAcknowledgement::firstOrCreate([
                'company_id' => $freshUser->company_id, 'user_id' => $freshUser->id, 'policy_version_id' => $current->id,
            ], ['acknowledged_at' => now()]);

            return ['acknowledged' => true, 'policy' => $current->toPolicyData(), 'acknowledged_at' => $record->acknowledged_at->toIso8601String()];
        });
    }
}
