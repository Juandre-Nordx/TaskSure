<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class MobileTaskData
{
    public static function summary(Task $task): array
    {
        return [
            'id' => $task->id, 'title' => $task->title, 'area' => $task->area,
            'priority' => $task->priority, 'status' => $task->status,
            'status_label' => Task::STATUSES[$task->status], 'overdue' => $task->overdue(),
            'assignee' => $task->employee->only(['id', 'name', 'role']),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'review_status' => $task->review_status,
            'reviewed_at' => $task->reviewed_at?->toIso8601String(),
            'reviewer' => $task->reviewer?->only(['id', 'name']),
            'category' => $task->category->only(['id', 'name']),
            'due_at' => $task->due_at->toIso8601String(),
            'scheduled_at' => $task->scheduled_at?->toIso8601String(),
            'required_evidence' => $task->required_evidence,
            'submission_count' => $task->submissions_count,
        ];
    }

    public static function attachment(Attachment $attachment): array
    {
        return $attachment->only(['id', 'original_name', 'mime', 'size', 'kind']) + ['url' => '/attachments/'.$attachment->id];
    }

    public static function details(Task $task, User $user): array
    {
        $task->load(['category', 'creator', 'employee', 'completer', 'reviewer', 'reviews.reviewer', 'attachments', 'submissions.attachments', 'submissions.reviewer', 'activities.user', 'deadlineChanges.user']);

        return self::summary($task) + [
            'instructions' => $task->instructions, 'creator' => $task->creator->only(['id', 'name']),
            'created_at' => $task->created_at->toIso8601String(), 'started_at' => $task->started_at?->toIso8601String(),
            'completed_by' => $task->completer?->only(['id', 'name']), 'cancellation_reason' => $task->cancellation_reason,
            'permissions' => ['work' => Gate::forUser($user)->allows('work', $task), 'manage' => Gate::forUser($user)->allows('manage', $task), 'review' => Gate::forUser($user)->allows('review', $task)],
            'draft_evidence' => $task->attachments->whereNull('submission_id')->where('user_id', $user->id)->map(fn ($a) => self::attachment($a))->values(),
            'submissions' => $task->submissions->map(fn ($s) => [
                'id' => $s->id, 'note' => $s->note, 'submitted_at' => $s->submitted_at->toIso8601String(),
                'due_at' => $s->due_at->toIso8601String(), 'on_time' => $s->submitted_at->lte($s->due_at),
                'review_status' => $s->review_status, 'review_reason' => $s->review_reason,
                'history_label' => $s->reviewLabel(), 'started_at' => $s->started_at?->toIso8601String(),
                'reviewed_at' => $s->reviewed_at?->toIso8601String(), 'reviewer' => $s->reviewer?->name,
                'attachments' => $s->attachments->map(fn ($a) => self::attachment($a)),
            ]),
            'reviews' => $task->reviews->map(fn ($review) => ['id' => $review->id, 'submission_id' => $review->submission_id, 'action' => $review->action, 'note' => $review->note, 'reviewer' => $review->reviewer?->name, 'occurred_at' => $review->occurred_at?->toIso8601String()]),
            'activities' => $task->activities->map(fn ($a) => ['id' => $a->id, 'type' => $a->type, 'body' => $a->body, 'user' => $a->user?->name ?? 'System', 'created_at' => $a->created_at->toIso8601String()]),
            'deadline_changes' => $task->deadlineChanges->map(fn ($d) => ['old_due_at' => $d->old_due_at->toIso8601String(), 'new_due_at' => $d->new_due_at->toIso8601String(), 'reason' => $d->reason, 'user' => $d->user->name, 'created_at' => $d->created_at->toIso8601String()]),
        ];
    }
}
