<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Task;

class MobileTaskData
{
    public static function summary(Task $task): array
    {
        return [
            'id' => $task->id, 'title' => $task->title, 'area' => $task->area,
            'priority' => $task->priority, 'status' => $task->status,
            'status_label' => Task::STATUSES[$task->status], 'overdue' => $task->overdue(),
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

    public static function details(Task $task, int $userId): array
    {
        $task->load(['category', 'creator', 'attachments', 'submissions.attachments', 'submissions.reviewer', 'activities.user', 'deadlineChanges.user']);

        return self::summary($task) + [
            'instructions' => $task->instructions, 'creator' => $task->creator->only(['id', 'name']),
            'created_at' => $task->created_at->toIso8601String(), 'started_at' => $task->started_at?->toIso8601String(),
            'approved_at' => $task->approved_at?->toIso8601String(), 'cancellation_reason' => $task->cancellation_reason,
            'draft_evidence' => $task->attachments->whereNull('submission_id')->where('user_id', $userId)->map(fn ($a) => self::attachment($a))->values(),
            'submissions' => $task->submissions->map(fn ($s) => [
                'id' => $s->id, 'note' => $s->note, 'submitted_at' => $s->submitted_at->toIso8601String(),
                'due_at' => $s->due_at->toIso8601String(), 'on_time' => $s->submitted_at->lte($s->due_at),
                'review_status' => $s->review_status, 'review_reason' => $s->review_reason,
                'reviewed_at' => $s->reviewed_at?->toIso8601String(), 'reviewer' => $s->reviewer?->name,
                'attachments' => $s->attachments->map(fn ($a) => self::attachment($a)),
            ]),
            'activities' => $task->activities->map(fn ($a) => ['id' => $a->id, 'type' => $a->type, 'body' => $a->body, 'user' => $a->user?->name ?? 'System', 'created_at' => $a->created_at->toIso8601String()]),
            'deadline_changes' => $task->deadlineChanges->map(fn ($d) => ['old_due_at' => $d->old_due_at->toIso8601String(), 'new_due_at' => $d->new_due_at->toIso8601String(), 'reason' => $d->reason, 'user' => $d->user->name, 'created_at' => $d->created_at->toIso8601String()]),
        ];
    }
}
