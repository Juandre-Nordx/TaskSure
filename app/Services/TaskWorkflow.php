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
        $t->activities()->create(['user_id' => $u->id, 'type' => $type, 'body' => $body, 'data' => $data]);
    }

    public function start(Task $task, User $u): void
    {
        DB::transaction(function () use ($task, $u) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('work', $t);
            $this->require(in_array($t->status, ['assigned', 'changes_requested']), 'This task cannot be started now.');
            $t->update(['status' => 'in_progress', 'started_at' => $t->started_at ?? now()]);
            $this->log($t, $u, 'started', 'Work started.');
        });
    }

    public function submit(Task $task, User $u, ?string $note): void
    {
        DB::transaction(function () use ($task, $u, $note) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('work', $t);
            $this->require(in_array($t->status, ['assigned', 'in_progress', 'changes_requested']), 'This task is already submitted or closed.');
            $files = $t->attachments()->whereNull('submission_id')->where('user_id', $u->id)->get();
            foreach ($t->required_evidence as $kind) {
                $this->require($kind === 'note' ? filled($note) : $files->contains('kind', $kind), 'Required evidence missing: '.$kind.'. Upload fresh evidence for each attempt.');
            }
            $s = $t->submissions()->create(['employee_id' => $u->id, 'note' => $note, 'submitted_at' => now(), 'due_at' => $t->due_at]);
            $t->attachments()->whereIn('id', $files->pluck('id'))->update(['submission_id' => $s->id]);
            $t->update(['status' => 'submitted']);
            $this->log($t, $u, 'submitted', 'Submission #'.$s->id.' sent for review.');
            $this->alerts->reviewers($t, 'submitted', $t->title.' is awaiting review.', 'submission:'.$s->id);
        });
    }

    public function review(Task $task, User $u, string $decision, ?string $reason): void
    {
        DB::transaction(function () use ($task, $u, $decision, $reason) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('manage', $t);
            $this->require($t->status === 'submitted', 'There is no pending submission.');
            $s = $t->submissions()->where('review_status', 'pending')->latest('id')->firstOrFail();
            $this->require($decision !== 'changes_requested' || filled($reason), 'Explain the corrections needed.');
            $s->update(['review_status' => $decision, 'reviewer_id' => $u->id, 'reviewed_at' => now(), 'review_reason' => $reason]);
            $t->update(['status' => $decision, 'approved_at' => $decision === 'approved' ? now() : null]);
            $this->log($t, $u, $decision, $reason ?? 'Submission approved.');
            $this->alerts->send($t->employee, $t, $decision, $t->title.': '.Task::STATUSES[$decision].($reason ? ' — '.$reason : ''), 'review:'.$s->id);
        });
    }

    public function reschedule(Task $task, User $u, $due, string $reason): void
    {
        DB::transaction(function () use ($task, $u, $due, $reason) {
            $t = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($u)->authorize('manage', $t);
            $this->require(! in_array($t->status, ['approved', 'cancelled']), 'Closed tasks cannot be rescheduled.');
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
            $this->require(! in_array($t->status, ['submitted', 'approved', 'cancelled']), 'Review or close the current submission before reassigning.');
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
            $this->require(! in_array($t->status, ['approved', 'cancelled']), 'Closed tasks cannot be cancelled.');
            $t->submissions()->where('review_status', 'pending')->update(['review_status' => 'cancelled', 'reviewer_id' => $u->id, 'reviewed_at' => now(), 'review_reason' => $reason]);
            $t->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);
            $this->log($t, $u, 'cancelled', $reason);
            $this->alerts->send($t->employee, $t, 'cancelled', $t->title.' was cancelled.', 'cancel:'.$t->id);
        });
    }
}
