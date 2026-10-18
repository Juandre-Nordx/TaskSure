<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaskWorkflow
{
    public function __construct(private AlertService $alerts) {}

    private function require(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['action' => $message]);
        }
    }

    public function log(Task $t, User $u, string $type, string $body, array $data = []): void
    {
        $logger = app(ActivityLogger::class);
        $activity = $t->activities()->make(['user_id' => $u->id, 'type' => $type, 'body' => $body, 'data' => $data]);
        $keys = array_flip(array_keys($t->getChanges()));
        if (isset($keys['employee_id'])) {
            $keys['employee_name'] = true;
        }
        $activity->auditBefore = $type === 'assigned' ? [] : array_intersect_key($logger->snapshot($t, true), $keys);
        $activity->auditAfter = $type === 'assigned' ? $logger->snapshot($t) : array_intersect_key($logger->snapshot($t), $keys);
        $activity->setRelation('task', $t);
        $activity->save();
    }

    public function start(Task $task, User $u): void
    {
        DB::transaction(function () use ($task, $u) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('work', $t);
            $this->require($t->status === 'assigned', 'This task cannot be started now.');
            $t->update(['status' => 'in_progress', 'started_at' => $t->started_at ?? now()]);
            $this->log($t, $u, 'started', 'Work started.');
        });
    }

    public function complete(Task $task, User $u, ?string $note): void
    {
        DB::transaction(function () use ($task, $u, $note) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('work', $t);
            $this->require(in_array($t->status, ['assigned', 'in_progress']), 'This task is already completed or closed.');
            $files = $t->attachments()->whereNull('submission_id')->where('user_id', $u->id)->get();
            foreach ($t->required_evidence as $kind) {
                $this->require($kind === 'note' ? filled($note) : $files->contains('kind', $kind), 'Required evidence missing: '.$kind.'. Upload fresh evidence for each attempt.');
            }
            $completedAt = now();
            $s = $t->submissions()->create(['employee_id' => $u->id, 'note' => $note, 'submitted_at' => $completedAt, 'due_at' => $t->due_at, 'started_at' => $t->started_at]);
            $t->attachments()->whereIn('id', $files->pluck('id'))->update(['submission_id' => $s->id]);
            $t->update(['status' => 'completed', 'completed_at' => $completedAt, 'completed_by_id' => $u->id, 'current_completion_id' => $s->id, 'review_status' => 'not_reviewed', 'reviewed_at' => null, 'reviewer_id' => null]);
            $this->log($t, $u, 'completed', 'Task completed. Completion #'.$s->id.' recorded.', ['submission_id' => $s->id]);
            $this->alerts->reviewers($t, 'completed', $t->title.' is completed.', 'completion:'.$s->id);
        });
    }

    public function markReviewed(Task $task, User $u, ?string $note): void
    {
        DB::transaction(function () use ($task, $u, $note) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('review', $t);
            $this->require($t->status === 'completed', 'Only completed tasks can be reviewed.');
            if ($t->review_status === 'reviewed') {
                return;
            }
            $review = $t->reviews()->create(['submission_id' => $t->current_completion_id, 'reviewer_id' => $u->id, 'action' => 'reviewed', 'note' => $note, 'occurred_at' => now()]);
            $t->update(['review_status' => 'reviewed', 'reviewed_at' => $review->occurred_at, 'reviewer_id' => $u->id]);
            $this->log($t, $u, 'reviewed', $note ?? 'Task marked as reviewed.', ['review_id' => $review->id, 'submission_id' => $t->current_completion_id]);
            $this->alerts->send($t->employee, $t, 'reviewed', $t->title.' was reviewed.'.($note ? ' — '.$note : ''), 'task-review:'.$review->id);
        });
    }

    public function reopen(Task $task, User $u, string $reason): void
    {
        DB::transaction(function () use ($task, $u, $reason) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('review', $t);
            $this->require($t->status === 'completed', 'Only completed tasks can be reopened.');
            $this->require(filled(trim($reason)), 'Explain the corrections needed.');
            $review = $t->reviews()->create(['submission_id' => $t->current_completion_id, 'reviewer_id' => $u->id, 'action' => 'reopened', 'note' => $reason, 'occurred_at' => now()]);
            $previous = ['review_id' => $review->id, 'submission_id' => $t->current_completion_id, 'previous_started_at' => $t->started_at?->toIso8601String(), 'previous_completed_at' => $t->completed_at?->toIso8601String(), 'previous_completed_by_id' => $t->completed_by_id];
            $t->update(['status' => 'assigned', 'started_at' => null, 'completed_at' => null, 'completed_by_id' => null, 'current_completion_id' => null, 'review_status' => 'not_reviewed', 'reviewed_at' => null, 'reviewer_id' => null]);
            $this->log($t, $u, 'reopened', $reason, $previous);
            $this->alerts->send($t->employee, $t, 'reopened', $t->title.' was reopened — '.$reason, 'task-reopen:'.$review->id);
        });
    }

    public function reschedule(Task $task, User $u, $due, string $reason): void
    {
        DB::transaction(function () use ($task, $u, $due, $reason) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('manage', $t);
            $this->require(! in_array($t->status, ['completed', 'cancelled']), 'Closed tasks cannot be rescheduled.');
            $this->require(! $t->scheduled_at || $due->gte($t->scheduled_at), 'Deadline must follow scheduled start.');
            $change = $t->deadlineChanges()->create(['user_id' => $u->id, 'old_due_at' => $t->due_at, 'new_due_at' => $due, 'reason' => $reason]);
            $t->update(['due_at' => $due]);
            $this->log($t, $u, 'rescheduled', $reason);
            $this->alerts->send($t->employee, $t, 'deadline_changed', $t->title.' has a new deadline.', 'deadline:'.$change->id);
        });
    }

    public function reassign(Task $task, User $u, User $employee, string $reason): void
    {
        DB::transaction(function () use ($task, $u, $employee, $reason) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('manage', $t);
            $this->require(in_array($t->status, ['assigned', 'in_progress']), 'Reopen a completed task before reassigning.');
            $this->require($employee->active && $u->canManageEmployee($employee), 'Choose an active person within your permitted team.');
            $old = $t->employee_id;
            $t->update(['employee_id' => $employee->id, 'status' => 'assigned', 'started_at' => null]);
            $this->log($t, $u, 'reassigned', $reason, ['old_employee_id' => $old, 'new_employee_id' => $employee->id]);
            $key = 'reassigned:'.$t->activities()->max('id');
            $this->alerts->send($employee, $t, 'assigned', 'You were assigned '.$t->title, $key);
            $this->alerts->send(User::findOrFail($old), null, 'reassigned', $t->title.' was reassigned to another employee.', $key.':old');
        });
    }

    public function cancel(Task $task, User $u, string $reason): void
    {
        DB::transaction(function () use ($task, $u, $reason) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('manage', $t);
            $this->require(! in_array($t->status, ['completed', 'cancelled']), 'Closed tasks cannot be cancelled.');
            $t->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);
            $this->log($t, $u, 'cancelled', $reason);
            $this->alerts->send($t->employee, $t, 'cancelled', $t->title.' was cancelled.', 'cancel:'.$t->id);
        });
    }
}
