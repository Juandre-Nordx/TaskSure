<?php

namespace App\Services;

use App\Models\AccountAudit;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\SpecificDates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaskRecurrence
{
    public function make(Task $task, User $actor, string $frequency, ?string $firstRepeat = null, ?string $endsOn = null, array $specificDates = [], ?string $dueTime = null, ?string $creationKey = null, ?string $fingerprint = null): TaskTemplate
    {
        return DB::transaction(function () use ($task, $actor, $frequency, $firstRepeat, $endsOn, $specificDates, $dueTime, $creationKey, $fingerprint) {
            $original = Task::lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('manage', $original);
            if ($original->task_template_id) {
                throw ValidationException::withMessages(['recurrence' => 'This task already belongs to a recurring series.']);
            }
            if (! $original->employee->active) {
                throw ValidationException::withMessages(['recurrence' => 'Choose an active assignee before creating a recurring series.']);
            }
            $date = BusinessTime::show($original->due_at, 'Y-m-d');
            if ($frequency === 'specific_dates') {
                $specificDates = SpecificDates::normalise($specificDates, [], $firstRepeat !== null ? $date : null);
                $endsOn = end($specificDates);
            }
            $plan = new TaskTemplate($original->only(['employee_id', 'category_id', 'title', 'instructions', 'area', 'priority', 'required_evidence']));
            $plan->fill([
                'creator_id' => $actor->id, 'store_id' => $original->store_id, 'frequency' => $frequency,
                'anchor_day' => (int) substr($firstRepeat ?? $date, 8, 2),
                'due_time' => $dueTime ?? BusinessTime::show($original->due_at, 'H:i'),
                'ends_on' => $endsOn,
                'specific_dates' => $frequency === 'specific_dates' ? $specificDates : null,
                'specific_due_times' => $frequency === 'specific_dates' ? array_fill_keys($specificDates, $dueTime ?? BusinessTime::show($original->due_at, 'H:i')) : null,
                'creation_key' => $creationKey, 'creation_fingerprint' => $fingerprint, 'schedule_revision' => 0,
                'scheduled_offset_minutes' => $original->scheduled_at ? (int) $original->scheduled_at->diffInMinutes($original->due_at) : null,
            ]);
            $plan->next_date = $firstRepeat ?? $plan->followingDate($date);
            $plan->active = ! $plan->ends_on || $plan->next_date->lte($plan->ends_on);
            $plan->save();
            // Only series metadata changes; the original task's status/deadline/proof stay intact.
            $original->update(['task_template_id' => $plan->id, 'occurrence_date' => $date]);
            app(TaskWorkflow::class)->log($original, $actor, 'recurrence_enabled', 'Recurring series created. Existing task history is unchanged.', ['template_id' => $plan->id, 'frequency' => $frequency, 'next_date' => $plan->next_date->toDateString(), 'ends_on' => $endsOn]);
            AccountAudit::create(['actor_id' => $actor->id, 'action' => 'template_created_from_task', 'data' => ['template_id' => $plan->id, 'task_id' => $original->id, 'after' => app(ActivityLogger::class)->snapshot($plan)]]);

            return $plan;
        });
    }

    public function creation(User $actor, string $key, string $fingerprint): ?TaskTemplate
    {
        // Serialise retries before creating the first task/template, not just later instances.
        DB::table('stores')->where('id', $actor->storeId())->lockForUpdate()->first();
        $existing = TaskTemplate::where('creation_key', $key)->first();
        if ($existing && ($existing->store_id !== $actor->storeId() || $existing->creator_id !== $actor->id || $existing->creation_fingerprint !== $fingerprint)) {
            throw ValidationException::withMessages(['creation_key' => 'This save request was already used for another schedule. Reload before creating a new schedule.']);
        }

        return $existing;
    }

    public function updateSpecific(TaskTemplate $template, User $actor, array $dates, string $time, int $revision, bool $active, array $content = []): TaskTemplate
    {
        return DB::transaction(function () use ($template, $actor, $dates, $time, $revision, $active, $content) {
            $plan = TaskTemplate::lockForUpdate()->findOrFail($template->id);
            $before = app(ActivityLogger::class)->snapshot($plan);
            abort_unless($plan->manageableBy($actor), 403);
            if ($plan->frequency !== 'specific_dates' || $plan->schedule_revision !== $revision) {
                throw ValidationException::withMessages(['schedule_revision' => 'The schedule changed. Reload it before saving.']);
            }
            $past = array_filter($plan->specific_dates, fn ($date) => BusinessTime::utc($date.' '.$plan->dueTimeOn($date))->lte(now()));
            $dates = SpecificDates::normalise(array_values(array_unique(array_merge($dates, $past))), $plan->specific_dates);
            $content = array_intersect_key($content, array_flip(['employee_id', 'category_id', 'title', 'instructions', 'area', 'priority', 'required_evidence']));
            $oldDates = $plan->specific_dates;
            $previousTime = $plan->due_time;
            $times = [];
            foreach ($dates as $date) {
                // Preserve deadlines for past dates that have not yet been generated (e.g. paused series).
                $oldTime = $plan->dueTimeOn($date);
                $times[$date] = in_array($date, $oldDates, true) && BusinessTime::utc($date.' '.$oldTime)->lte(now()) ? $oldTime : $time;
            }
            $workflow = app(TaskWorkflow::class);
            foreach ($plan->tasks()->orderBy('id')->lockForUpdate()->get() as $task) {
                // A template's assignee may have changed since an occurrence was
                // created. Current series permission never grants access to an
                // older occurrence outside the manager's present scope.
                if (! Gate::forUser($actor)->allows('manage', $task)) {
                    continue;
                }
                $date = $task->occurrence_date;
                if ((! in_array($date, $oldDates, true) && ! $task->schedule_removed) || ! $task->due_at->isFuture() || $task->started_at || $task->submissions()->exists() || $task->attachments()->exists()
                    || $task->activities()->whereNotIn('type', ['assigned', 'recurrence_enabled', 'rescheduled', 'cancelled', 'specific_date_removed', 'specific_date_restored', 'specific_schedule_changed'])->exists()) {
                    continue;
                }
                $removed = $task->schedule_removed && $task->status === 'cancelled';
                if (! $removed && ($task->status !== 'assigned' || $task->employee_id !== $plan->employee_id || ! $task->due_at->equalTo(BusinessTime::utc($date.' '.$plan->dueTimeOn($date))))) {
                    continue;
                }
                if (! in_array($date, $dates, true)) {
                    if (! $removed) {
                        $workflow->cancel($task, $actor, 'Removed from specific-date schedule.');
                        $task->refresh()->update(['schedule_removed' => true]);
                        $workflow->log($task, $actor, 'specific_date_removed', 'Date removed from the schedule; task history retained.');
                    }

                    continue;
                }
                if ($removed) {
                    $task->update(['status' => 'assigned', 'schedule_removed' => false, 'cancellation_reason' => null]);
                    $workflow->log($task, $actor, 'specific_date_restored', 'Date restored to the schedule using the original task.');
                    app(AlertService::class)->send($task->employee, $task, 'assigned', $task->title.' was restored to the schedule.', 'schedule-restored:'.$plan->id.':'.$revision.':'.$task->id);
                }
                $due = BusinessTime::utc($date.' '.$times[$date]);
                $task->update(['scheduled_at' => $plan->scheduled_offset_minutes !== null ? $due->subMinutes($plan->scheduled_offset_minutes) : null]);
                if (! $task->due_at->equalTo($due)) {
                    $workflow->reschedule($task, $actor, $due, 'Specific-date schedule updated.');
                }
                $workflow->log($task, $actor, 'specific_schedule_changed', 'Future unstarted occurrence updated from its schedule.', ['schedule_revision' => $revision + 1]);
            }
            $existingDates = $plan->tasks()->pluck('occurrence_date')->all();
            $next = collect($dates)->first(fn ($date) => ! in_array($date, $existingDates, true)) ?? CarbonImmutable::parse(end($dates))->addDay()->toDateString();
            $plan->update($content + ['specific_dates' => $dates, 'specific_due_times' => $times, 'due_time' => $time, 'next_date' => $next, 'ends_on' => end($dates), 'active' => $active, 'schedule_revision' => $revision + 1]);
            AccountAudit::create(['actor_id' => $actor->id, 'action' => 'specific_schedule_updated', 'data' => ['template_id' => $plan->id, 'before_dates' => $oldDates, 'dates' => $dates, 'before_due_time' => $previousTime, 'due_time' => $time, 'schedule_revision' => $revision + 1, 'before' => $before, 'after' => app(ActivityLogger::class)->snapshot($plan)]]);

            return $plan->fresh();
        });
    }

    public function setActive(TaskTemplate $template, User $actor, bool $active): void
    {
        DB::transaction(function () use ($template, $actor, $active) {
            $plan = TaskTemplate::lockForUpdate()->findOrFail($template->id);
            $before = app(ActivityLogger::class)->snapshot($plan);
            abort_unless($plan->manageableBy($actor), 403);
            if ($active && $plan->ends_on && $plan->next_date->gt($plan->ends_on)) {
                throw ValidationException::withMessages(['active' => 'This series has ended. Its end date must be updated in Store settings before resuming.']);
            }
            $plan->update(['active' => $active] + ($plan->frequency === 'specific_dates' ? ['schedule_revision' => $plan->schedule_revision + 1] : []));
            AccountAudit::create(['actor_id' => $actor->id, 'action' => $active ? 'template_resumed' : 'template_paused', 'data' => ['template_id' => $plan->id, 'before' => $before, 'after' => app(ActivityLogger::class)->snapshot($plan)]]);
        });
    }
}
