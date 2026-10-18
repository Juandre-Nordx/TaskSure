<?php

namespace App\Services;

use App\Models\AccountAudit;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\CompanyPolicyAcknowledgement;
use App\Models\CompanyPolicyVersion;
use App\Models\Store;
use App\Models\TaskActivity;
use App\Models\TaskTemplate;
use App\Models\User;

class ExistingActivityRecorder
{
    public function __construct(private ActivityLogger $logger) {}

    public function account(AccountAudit $audit, bool $historical = false): void
    {
        if ($historical && ! $audit->created_at) {
            return;
        }
        $data = is_array($audit->data) ? $audit->data : [];
        $actor = $audit->actor_id ? User::find($audit->actor_id) : null;
        $store = $audit->store_id ? Store::find($audit->store_id) : null;
        $company = ($audit->company_id ?? $data['company_id'] ?? null) ? Company::find($audit->company_id ?? $data['company_id']) : null;
        $subject = $audit->subject_id ? User::find($audit->subject_id) : null;
        $before = is_array($data['before'] ?? null) ? $data['before'] : [];
        $after = is_array($data['after'] ?? null) ? $data['after'] : [];
        $emit = function (string $action, $record = null, array $old = [], array $new = [], array $details = []) use ($audit, $actor, $store, $company, $historical) {
            return $this->logger->write($action, $record, $old, $new, $details, actor: $actor, store: $store, company: $company,
                origin: 'account_audit:'.$audit->id.':'.$action, historical: $historical, occurredAt: $audit->created_at,
                attribution: $actor ? 'authenticated' : 'system');
        };
        switch ($audit->action) {
            case 'bootstrap_admin':
                $emit('account.created', $subject, [], $historical || ! $subject ? [] : $this->logger->snapshot($subject));
                break;
            case 'created': case 'updated':
                if (! $historical && $subject && ! $after) {
                    $after = $this->logger->snapshot($subject);
                }
                $before += isset($data['membership_before']) ? ['store_access_active' => $data['membership_before']] : [];
                $after += isset($data['membership_active']) ? ['store_access_active' => $data['membership_active']] : [];
                $before += isset($data['scope_before']) ? ['permitted_staff_ids' => $data['scope_before']] : [];
                $after += isset($data['scope_after']) ? ['permitted_staff_ids' => $data['scope_after']] : [];
                $action = match (true) {
                    $audit->action === 'created' => 'account.created',
                    isset($before['role'], $after['role']) && $before['role'] !== $after['role'] => 'account.role_changed',
                    (($before['active'] ?? true) && ! ($after['active'] ?? true)) || (($before['store_access_active'] ?? true) && ! ($after['store_access_active'] ?? true)) => 'account.deactivated',
                    (! ($before['active'] ?? true) && ($after['active'] ?? false)) || (! ($before['store_access_active'] ?? true) && ($after['store_access_active'] ?? false)) => 'account.reactivated',
                    default => 'account.updated',
                };
                $emit($action, $subject ?? ['type' => 'user', 'id' => $audit->subject_id, 'label' => 'Account'], $before, $after, ['password_changed' => (bool) ($data['password_changed'] ?? false)]);
                break;
            case 'store_membership_added':
                $emit('account.store_access_added', $subject, [], ['store_id' => $audit->store_id, 'store_access_active' => true]);
                break;
            case 'platform_store_saved':
                $record = $store ?? ['type' => 'store', 'id' => $audit->store_id, 'label' => $data['name'] ?? 'Store'];
                $after = $after ?: array_intersect_key($data, array_flip(['name', 'active', 'owner_id']));
                $emit(array_key_exists('store_created', $data) ? ($data['store_created'] ? 'store.created' : 'store.updated') : ($historical ? 'store.saved' : 'store.updated'), $record, $before, $after);
                if (($data['owner_id'] ?? null) !== ($data['old_owner_id'] ?? null)) {
                    $emit('store.owner_assigned', $record, ['owner_id' => $data['old_owner_id'] ?? null, 'owner_name' => $before['owner_name'] ?? null], ['owner_id' => $data['owner_id'], 'owner_name' => $after['owner_name'] ?? null]);
                }
                if ($data['owner_created'] ?? false) {
                    $owner = User::find($data['owner_id']);
                    $emit('account.created', $owner, [], $data['owner_snapshot'] ?? []);
                }
                break;
            case 'platform_owner_password_set':
                $emit('account.password_changed', $subject, details: ['password_changed' => true]);
                break;
            case 'platform_super_admin_setup':
                $emit(array_key_exists('account_created', $data) ? ($data['account_created'] ? 'account.created' : 'account.role_changed') : 'account.platform_setup', $subject, $before, $after);
                break;
            case 'company_settings_saved':
                $emit('settings.company_changed', $store, $before, ['company_name' => $data['company_name'] ?? null, 'store_name' => $data['store_name'] ?? null]);
                break;
            case 'category_created':
                $emit('settings.category_created', ['type' => 'settings', 'id' => $data['category_id'] ?? null, 'label' => $data['name'] ?? 'Category'], [], ['name' => $data['name'] ?? null]);
                break;
            case 'reminders_updated':
                $emit('settings.reminders_changed', $store, $before, ['reminder_minutes' => $data['reminder_minutes'] ?? null]);
                break;
            case 'policy_draft_saved':
                $policy = CompanyPolicy::find($data['policy_id'] ?? null);
                $emit('policy.draft_saved', $policy, $before, $after, ['draft_revision' => $data['draft_revision'] ?? null, 'changed_fields' => $data['changed_fields'] ?? []]);
                if ($data['pdf_uploaded'] ?? false) {
                    $emit('policy.uploaded', $policy, [], $after);
                }
                break;
            case 'policy_published':
                $version = CompanyPolicyVersion::find($data['policy_version_id'] ?? null);
                $emit('policy.published', $version, $before, $after, ['policy_version_id' => $data['policy_version_id'] ?? null]);
                break;
            case 'template_created_from_task': case 'template_saved': case 'specific_schedule_updated': case 'template_paused': case 'template_resumed':
                $template = TaskTemplate::find($data['template_id'] ?? null);
                $action = match ($audit->action) {
                    'template_created_from_task' => 'task.schedule_created', 'template_paused' => 'task.schedule_paused', 'template_resumed' => 'task.schedule_resumed', default => ($data['created'] ?? false) ? 'task.schedule_created' : 'task.schedule_edited',
                };
                if (! $before && isset($data['before_dates'])) {
                    $before = ['specific_dates' => $data['before_dates'], 'due_time' => $data['before_due_time'] ?? null];
                }
                if (! $after && ! $historical && $template) {
                    $after = $this->logger->snapshot($template);
                }
                $emit($action, $template, $before, $after, ['task_id' => $data['task_id'] ?? null]);
                break;
        }
    }

    public function task(TaskActivity $activity, bool $historical = false): void
    {
        if ($historical && ! $activity->created_at) {
            return;
        }
        $task = $activity->task;
        if (! $task) {
            return;
        }
        $data = is_array($activity->data) ? $activity->data : [];
        $action = match ($activity->type) {
            'assigned' => 'task.created', 'reassigned' => 'task.assigned', 'started' => 'task.started',
            'completed', 'submitted' => 'task.completed', 'reviewed', 'approved' => 'task.reviewed',
            'reopened', 'changes_requested' => 'task.reopened', 'rescheduled' => 'task.deadline_changed', 'cancelled' => 'task.cancelled',
            'specific_date_removed' => 'task.edited', 'specific_date_restored' => 'task.edited',
            'upload' => 'evidence.uploaded', 'comment' => 'task.comment_added', 'blocker' => 'task.blocker_added', default => null,
        };
        if (! $action) {
            return;
        }
        $actor = $activity->auditSystem ? null : User::find($activity->user_id);
        $record = $task;
        $before = $historical ? [] : $activity->auditBefore;
        $after = $historical ? [] : $activity->auditAfter;
        if (! $historical && $activity->type === 'assigned' && ! $after) {
            $after = $this->logger->snapshot($task);
        }
        if ($activity->type === 'upload' && ($attachment = Attachment::find($data['attachment_id'] ?? null))) {
            $record = $attachment;
            $after = $historical ? [] : $this->logger->snapshot($attachment);
        }
        if ($historical && $activity->type === 'reassigned') {
            $before = ['employee_id' => $data['old_employee_id'] ?? null];
            $after = ['employee_id' => $data['new_employee_id'] ?? null];
        }
        $this->logger->write($action, $record, $before, $after, $data, actor: $actor, store: $task->store,
            origin: 'task_activity:'.$activity->id, historical: $historical, occurredAt: $activity->created_at,
            attribution: $activity->auditSystem || ! $actor ? 'system' : 'authenticated', source: $activity->auditSystem ? 'scheduler' : null);
    }

    public function acknowledgement(CompanyPolicyAcknowledgement $ack, bool $historical = false): void
    {
        if ($historical && ! $ack->acknowledged_at) {
            return;
        }
        $this->logger->write('policy.acknowledged', CompanyPolicyVersion::find($ack->policy_version_id),
            after: $this->logger->snapshot($ack), details: ['policy_version_id' => $ack->policy_version_id], actor: $ack->user,
            company: Company::find($ack->company_id), origin: 'policy_acknowledgement:'.$ack->id, historical: $historical, occurredAt: $ack->acknowledged_at);
    }
}
