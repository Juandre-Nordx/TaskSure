<?php

namespace App\Services;

use App\Models\AccountAudit;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaskRecurrence
{
    public function make(Task $task, User $actor, string $frequency, ?string $firstRepeat = null, ?string $endsOn = null): TaskTemplate
    {
        return DB::transaction(function () use ($task, $actor, $frequency, $firstRepeat, $endsOn) {
            $original = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('manage', $original);
            if ($original->task_template_id) {
                throw ValidationException::withMessages(['recurrence' => 'This task already belongs to a recurring series.']);
            }
            if (! $original->employee->active) {
                throw ValidationException::withMessages(['recurrence' => 'Choose an active assignee before creating a recurring series.']);
            }
            $date = BusinessTime::show($original->due_at, 'Y-m-d');
            $plan = new TaskTemplate($original->only(['employee_id', 'category_id', 'title', 'instructions', 'area', 'priority', 'required_evidence']));
            $plan->fill([
                'creator_id' => $actor->id, 'frequency' => $frequency,
                'anchor_day' => (int) substr($firstRepeat ?? $date, 8, 2),
                'due_time' => BusinessTime::show($original->due_at, 'H:i'),
                'ends_on' => $endsOn,
                'scheduled_offset_minutes' => $original->scheduled_at ? (int) $original->scheduled_at->diffInMinutes($original->due_at) : null,
            ]);
            $plan->next_date = $firstRepeat ?? $plan->followingDate($date);
            $plan->active = ! $plan->ends_on || $plan->next_date->lte($plan->ends_on);
            $plan->save();
            // Only series metadata changes; the original task's status/deadline/proof stay intact.
            $original->update(['task_template_id' => $plan->id, 'occurrence_date' => $date]);
            app(TaskWorkflow::class)->log($original, $actor, 'recurrence_enabled', 'Recurring series created. Existing task history is unchanged.', ['template_id' => $plan->id, 'frequency' => $frequency, 'next_date' => $plan->next_date->toDateString(), 'ends_on' => $endsOn]);
            AccountAudit::create(['actor_id' => $actor->id, 'action' => 'template_created_from_task', 'data' => ['template_id' => $plan->id, 'task_id' => $original->id]]);

            return $plan;
        });
    }

    public function setActive(TaskTemplate $template, User $actor, bool $active): void
    {
        DB::transaction(function () use ($template, $actor, $active) {
            $plan = TaskTemplate::lockForUpdate()->findOrFail($template->id);
            abort_unless($plan->manageableBy($actor), 403);
            if ($active && $plan->ends_on && $plan->next_date->gt($plan->ends_on)) {
                throw ValidationException::withMessages(['active' => 'This series has ended. Its end date must be updated in Store settings before resuming.']);
            }
            $plan->update(['active' => $active]);
            AccountAudit::create(['actor_id' => $actor->id, 'action' => $active ? 'template_resumed' : 'template_paused', 'data' => ['template_id' => $plan->id]]);
        });
    }
}
