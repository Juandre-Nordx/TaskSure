<?php

namespace App\Services;

use App\Jobs\SendAlertEmail;
use App\Models\Alert;
use App\Models\Task;
use App\Models\User;

class AlertService
{
    public function send(User $user, ?Task $task, string $type, string $message, string $key): void
    {
        $a = Alert::firstOrCreate(['dedupe_key' => $key], ['user_id' => $user->id, 'task_id' => $task?->id, 'type' => $type, 'message' => $message, 'email_status' => config('tasksure.email_enabled') ? 'queued' : 'disabled']);
        if ($a->wasRecentlyCreated && config('tasksure.email_enabled')) {
            SendAlertEmail::dispatch($a->id)->afterCommit();
        }
    }

    public function reviewers(Task $task, string $type, string $message, string $key): void
    {
        User::where('active', true)->where(fn ($q) => $q->where('role', 'admin')->orWhereIn('id', $task->employee->managers()->select('users.id')))->get()->each(fn ($u) => $this->send($u, $task, $type, $message, $key.':'.$u->id));
    }
}
