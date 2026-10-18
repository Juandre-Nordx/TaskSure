<?php

namespace App\Models;

use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public const CATEGORIES = ['accounts' => 'Accounts', 'tasks' => 'Tasks', 'policies' => 'Policies', 'reports' => 'Reports', 'settings' => 'Settings'];

    public const ROLES = ['super_admin' => 'Super Admin', 'admin' => 'Owner', 'manager' => 'Manager', 'employee' => 'Employee'];

    public const ACTIONS = [
        'account.login' => 'Login', 'account.logout' => 'Logout', 'account.created' => 'User created',
        'account.updated' => 'Account edited', 'account.role_changed' => 'Role changed',
        'account.deactivated' => 'Account / store access deactivated', 'account.reactivated' => 'Account / store access reactivated',
        'account.store_access_added' => 'Store access added', 'account.password_changed' => 'Password changed (values excluded)',
        'account.platform_setup' => 'Platform administrator setup (historical)',
        'store.created' => 'Store created', 'store.updated' => 'Store edited', 'store.owner_assigned' => 'Owner assigned', 'store.saved' => 'Store saved (historical)',
        'settings.company_changed' => 'Company / store settings changed', 'settings.category_created' => 'Category created',
        'settings.reminders_changed' => 'Reminder settings changed',
        'task.created' => 'Task created and assigned', 'task.assigned' => 'Task reassigned', 'task.edited' => 'Task edited',
        'task.deadline_changed' => 'Deadline changed', 'task.started' => 'Task started', 'task.completed' => 'Task completed',
        'task.reviewed' => 'Task reviewed', 'task.reopened' => 'Task reopened', 'task.cancelled' => 'Task cancelled',
        'task.comment_added' => 'Comment added (content excluded)', 'task.blocker_added' => 'Blocker added (content excluded)',
        'task.schedule_created' => 'Recurring schedule created', 'task.schedule_edited' => 'Recurring schedule edited',
        'task.schedule_paused' => 'Recurring schedule paused', 'task.schedule_resumed' => 'Recurring schedule resumed',
        'evidence.uploaded' => 'Photo / file uploaded', 'evidence.deleted' => 'Photo / file record deleted',
        'policy.draft_saved' => 'Policy draft saved', 'policy.uploaded' => 'Policy PDF uploaded', 'policy.published' => 'Policy published',
        'policy.pdf_opened' => 'Policy PDF served for viewing', 'policy.downloaded' => 'Policy PDF served for download',
        'policy.acknowledged' => 'Policy acknowledged',
        'report.generated' => 'Report generated', 'report.pdf_exported' => 'Report exported to PDF', 'report.xlsx_exported' => 'Report exported to Excel',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'before_values' => 'array', 'after_values' => 'array', 'details' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Activity logs are read-only.'));
        static::deleting(fn () => throw new \LogicException('Activity history must be preserved.'));
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    public function displayValue(string $field, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : '—', $value)) ?: 'None';
        }
        if (str_ends_with($field, '_at') && is_string($value) && $value !== '') {
            try {
                return BusinessTime::show($value, 'd M Y, H:i:s').' SAST';
            } catch (\Throwable) {
                return 'Unknown historical timestamp';
            }
        }

        return (string) $value;
    }
}
