<?php

namespace App\Services;

use App\Jobs\SendAlertEmail;
use App\Jobs\SendAlertPush;
use App\Models\Alert;
use App\Models\Task;
use App\Models\User;
use App\Support\StoreContext;

class AlertService
{
    public function send(User $user, ?Task $task, string $type, string $message, string $key, ?int $companyId = null): void
    {
        $a = Alert::firstOrCreate(['dedupe_key' => $key], ['user_id' => $user->id, 'task_id' => $task?->id, 'store_id' => $task?->store_id ?? ($type === 'policy_published' ? null : $user->storeId()), 'company_id' => $companyId ?? ($task?->store->company_id ?? $user->workspaceCompanyId()), 'type' => $type, 'message' => $message, 'email_status' => config('tasksure.email_enabled') ? 'queued' : 'disabled']);
        if ($a->wasRecentlyCreated && config('tasksure.email_enabled')) {
            SendAlertEmail::dispatch($a->id)->afterCommit();
        }
        if ($a->wasRecentlyCreated && config('mobile.push_enabled') && in_array($user->role, User::ASSIGNEE_ROLES)) {
            SendAlertPush::dispatch($a->id)->afterCommit();
        }
    }

    public function reviewers(Task $task, string $type, string $message, string $key, ?int $companyId = null): void
    {
        app(StoreContext::class)->run($task->store, function () use ($task, $type, $message, $key) {
            $task->employee->membersInStore($task->store_id)->where('active', true)->whereIn('role', ['admin', 'manager'])->where('id', '!=', $task->employee_id)->get()->filter(fn ($user) => $user->canManageEmployee($task->employee, $task->store_id))->each(fn ($user) => $this->send($user, $task, $type, $message, $key.':'.$user->id));
        });
    }
}
