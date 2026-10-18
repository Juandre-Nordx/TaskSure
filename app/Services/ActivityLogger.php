<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\CompanyPolicyAcknowledgement;
use App\Models\CompanyPolicyVersion;
use App\Models\Store;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\StoreContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    // Only structured, explicitly allowed metadata enters the activity store.
    // Request bodies, credentials, tokens, file paths/contents and free-form notes are never copied.
    private const FIELDS = [
        'id', 'name', 'email', 'role', 'active', 'company_id', 'store_id', 'company_name', 'store_name',
        'owner_id', 'owner_name', 'employee_id', 'employee_name', 'creator_id', 'category_id', 'priority', 'title', 'area',
        'status', 'required_evidence', 'scheduled_at', 'due_at', 'started_at', 'completed_at', 'completed_by_id',
        'current_completion_id', 'review_status', 'reviewed_at', 'reviewer_id', 'task_template_id', 'occurrence_date',
        'task_id', 'original_name', 'mime', 'kind', 'size', 'draft_title', 'draft_revision', 'current_policy_version_id',
        'draft_pdf_name', 'draft_pdf_size', 'version', 'company_policy_id', 'published_at', 'published_by_id', 'pdf_name', 'pdf_size',
        'policy_version_id', 'user_id', 'acknowledged_at', 'frequency', 'next_date', 'due_time', 'ends_on', 'specific_dates',
        'scheduled_offset_minutes', 'anchor_day', 'schedule_revision', 'store_access_active', 'permitted_staff_ids', 'reminder_minutes',
    ];

    private const DETAILS = ['http_status', 'reason', 'format', 'selected_tasks', 'still_overdue', 'completed_late',
        'basis', 'from', 'to', 'deadline_from', 'deadline_to', 'overdue_status', 'employee_id', 'category_id', 'priority',
        'status', 'scope', 'review_state', 'overdue', 'q', 'changed_fields', 'submission_id', 'review_id', 'attachment_id',
        'template_id', 'task_id', 'policy_id', 'policy_version_id', 'draft_revision', 'password_changed', 'historical_note'];

    public function snapshot(Model $model, bool $previous = false): array
    {
        if ($previous) {
            $copy = clone $model;
            $copy->setRawAttributes(array_replace($model->getAttributes(), $model->getPrevious()), true);
            $values = $copy->attributesToArray();
        } else {
            $values = $model->attributesToArray();
        }
        if ($model instanceof Task || $model instanceof TaskTemplate) {
            $values['employee_name'] = User::whereKey($values['employee_id'] ?? null)->value('name');
        }
        if ($model instanceof Store) {
            $values['owner_name'] = User::whereKey($values['owner_id'] ?? null)->value('name');
        }

        return $this->safe($values, self::FIELDS);
    }

    public function storeFor(?User $actor): ?Store
    {
        $context = app(StoreContext::class);
        if ($context->resolved && $context->store) {
            return $context->store;
        }
        if (! $actor || $actor->role === 'super_admin') {
            return null;
        }
        $request = request();
        if ($request->attributes->get('activity_store') instanceof Store) {
            return $request->attributes->get('activity_store');
        }
        $id = $actor->currentAccessToken()?->store_id
            ?? ($request->hasSession() ? $request->session()->get('workspace.store_id') : null)
            ?? $actor->storeId();

        return $id && $actor->inStore((int) $id, false) ? Store::find($id) : null;
    }

    public function write(string $action, Model|array|null $record = null, array $before = [], array $after = [], array $details = [],
        ?User $actor = null, ?Store $store = null, ?Company $company = null, string $result = 'success',
        ?string $origin = null, bool $historical = false, mixed $occurredAt = null, string $attribution = 'authenticated', ?string $claimedEmail = null, ?string $source = null,
    ): ActivityLog {
        if (! isset(ActivityLog::ACTIONS[$action]) || ! in_array($result, ['success', 'failure'])) {
            throw new \InvalidArgumentException('Unsupported activity event.');
        }
        if ($historical && ! $occurredAt) {
            throw new \InvalidArgumentException('A recorded timestamp is required for historical activity.');
        }
        $actor ??= $historical || $attribution !== 'authenticated' ? null : Auth::user();
        if ($record instanceof Task || $record instanceof TaskTemplate) {
            $store ??= Store::find($record->store_id);
        }
        if ($record instanceof Attachment) {
            $store ??= $record->task?->store;
        }
        if ($record instanceof Store) {
            $store ??= $record;
        }
        if ($record instanceof CompanyPolicy || $record instanceof CompanyPolicyVersion || $record instanceof CompanyPolicyAcknowledgement || $record instanceof Company) {
            $company ??= $record instanceof Company ? $record : Company::find($record->company_id);
        }
        if (! $historical) {
            $store ??= $this->storeFor($actor);
        }
        // A company-owned record determines company scope; unrelated context must not relabel it.
        if ($company && $store && $store->company_id !== $company->id) {
            $store = null;
        }
        $company ??= $store?->company;
        [$type, $id, $label] = $this->recordData($record);
        $category = match (strtok($action, '.')) {
            'account' => 'accounts', 'task', 'evidence' => 'tasks', 'policy' => 'policies', 'report' => 'reports', default => 'settings',
        };
        $before = $this->safe($before, self::FIELDS);
        $after = $this->safe($after, self::FIELDS);
        $details = $this->safe($details, self::DETAILS);
        if ($historical) {
            $details['historical_note'] = 'Imported from an existing record. Actor role at the time is unknown; names/labels may reflect the currently known account or record. No missing events were reconstructed.';
        }
        $identity = $historical ? 'historical' : ($actor || $attribution === 'claimed' ? $attribution : ($attribution === 'system' || ! request()->route() ? 'system' : 'unknown'));
        $values = [
            'occurred_at' => $historical && $occurredAt ? CarbonImmutable::parse($occurredAt)->utc() : CarbonImmutable::now('UTC'),
            'actor_id' => $actor?->id, 'actor_name' => $this->text($actor?->name ?? match ($identity) {
                'historical' => 'Unknown historical actor', 'claimed' => 'Unrecognised account', 'unknown' => 'Unauthenticated request', default => 'Console / system'
            }, 255),
            'actor_email' => $this->text($claimedEmail ?? $actor?->email, 255),
            'actor_role' => $historical ? null : $actor?->role,
            'attribution' => $identity,
            'company_id' => $company?->id, 'company_name' => $this->text($company?->name, 180),
            'store_id' => $store?->id, 'store_name' => $this->text($store?->name, 180),
            'category' => $category, 'action' => $action, 'result' => $result,
            'source' => $historical ? 'history' : ($source ?? (app()->runningInConsole() && ! request()->route() ? 'console' : (request()->is('api/*') ? 'mobile' : 'web'))),
            'record_type' => $type, 'record_id' => $id, 'record_label' => $this->text($label, 220),
            'before_values' => $before, 'after_values' => $after, 'details' => $details, 'origin_key' => $origin,
        ];
        $values['search_text'] = $this->text(implode(' ', array_filter([
            $values['actor_name'], $values['actor_email'], $values['company_name'], $values['store_name'], $action,
            ActivityLog::ACTIONS[$action], $type, $id, $label, json_encode([$before, $after, $details], JSON_UNESCAPED_UNICODE),
        ])), 32000);
        if ($result === 'failure') {
            request()->attributes->set('activity_failure_logged', true);
        } else {
            $actions = request()->attributes->get('activity_success_actions', []);
            $actions[$action] = true;
            request()->attributes->set('activity_success_actions', $actions);
        }

        return $origin ? ActivityLog::firstOrCreate(['origin_key' => $origin], $values) : ActivityLog::create($values);
    }

    public function loginFailure(string $reason, int $status, mixed $email): void
    {
        $email = is_string($email) && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        $claimed = $email ? User::where('email', $email)->first() : null;
        $this->write('account.login', $claimed ?? ['type' => 'authentication', 'label' => 'Sign-in attempt'],
            details: ['reason' => $reason, 'http_status' => $status], actor: $claimed, result: 'failure', attribution: 'claimed', claimedEmail: $email);
    }

    private function recordData(Model|array|null $record): array
    {
        if (is_array($record)) {
            return [$record['type'], $record['id'] ?? null, $record['label']];
        }
        $type = match (true) {
            $record instanceof Task => 'task', $record instanceof User => 'user', $record instanceof Store => 'store',
            $record instanceof Company => 'company', $record instanceof Attachment => 'evidence',
            $record instanceof CompanyPolicy => 'policy', $record instanceof CompanyPolicyVersion => 'policy_version',
            $record instanceof CompanyPolicyAcknowledgement => 'policy_acknowledgement', $record instanceof TaskTemplate => 'recurring_task', default => 'settings',
        };
        $label = $record?->getAttribute('title') ?? $record?->getAttribute('name') ?? $record?->getAttribute('draft_title')
            ?? $record?->getAttribute('original_name') ?? ucfirst(str_replace('_', ' ', $type));

        return [$type, $record?->getKey(), $label];
    }

    public function safe(array $values, array $keys): array
    {
        $out = [];
        foreach (array_intersect_key($values, array_flip($keys)) as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $value = CarbonImmutable::instance($value)->utc()->toIso8601String();
            }
            if (is_array($value)) {
                // Only flat lists are permitted, never nested request bodies or arbitrary JSON.
                $value = array_map(fn ($item) => is_scalar($item) || $item === null ? (is_string($item) ? $this->text($item, 180) : $item) : '[excluded]', array_slice(array_values($value), 0, 500));
            } elseif (is_string($value)) {
                $value = $this->text($value, 500);
            } elseif (! is_scalar($value) && $value !== null) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function text(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '', 0, $max);
    }
}
