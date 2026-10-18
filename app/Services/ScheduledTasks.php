<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskTemplate;
use App\Support\BusinessTime as BT;
use Illuminate\Support\Facades\DB;

class ScheduledTasks
{
    public function __construct(private AlertService $alerts) {}

    public function occurrences(?int $templateId = null): int
    {
        $count = 0;
        $today = now()->timezone(config('tasksure.timezone'))->toDateString();
        foreach (TaskTemplate::where('active', true)->when($templateId, fn ($q) => $q->whereKey($templateId))->pluck('id') as $id) {
            DB::transaction(function () use ($id, $today, &$count) {
                $p = TaskTemplate::lockForUpdate()->findOrFail($id);
                if ($p->generationBlock()) {
                    return;
                }
                if ($p->frequency === 'specific_dates') {
                    $p->next_date = $p->specificDateOnOrAfter($p->next_date->toDateString());
                }
                $loops = 0;
                while ($loops++ < 366) {
                    $date = $p->next_date->toDateString();
                    if ($p->ends_on && $date > $p->ends_on->toDateString()) {
                        $p->update(['active' => false]);
                        break;
                    }
                    $due = BT::utc($date.' '.$p->dueTimeOn($date));
                    $start = $p->scheduled_offset_minutes !== null ? $due->subMinutes($p->scheduled_offset_minutes) : null;
                    if ($date > $today && (! $start || $start->isFuture())) {
                        break;
                    }
                    $t = Task::firstOrCreate(['task_template_id' => $p->id, 'occurrence_date' => $date], ['store_id' => $p->store_id, 'employee_id' => $p->employee_id, 'creator_id' => $p->creator_id, 'category_id' => $p->category_id, 'title' => $p->title, 'instructions' => $p->instructions, 'area' => $p->area, 'priority' => $p->priority, 'required_evidence' => $p->required_evidence, 'due_at' => $due, 'scheduled_at' => $start]);
                    if ($t->wasRecentlyCreated) {
                        $count++;
                        $activity = $t->activities()->make(['user_id' => $p->creator_id, 'type' => 'assigned', 'body' => 'Recurring occurrence generated.']);
                        $activity->auditSystem = true;
                        $activity->save();
                        $this->alerts->send($p->employee, $t, 'assigned', 'You were assigned '.$t->title, 'assigned:'.$t->id);
                    }
                    $p->update(['next_date' => $p->followingDate($date)]);
                }
            });
        }

        return $count;
    }

    public function reminders(): int
    {
        $count = 0;
        Task::with(['employee', 'store'])->whereNotIn('status', ['completed', 'cancelled'])->where('due_at', '<=', now()->addMinutes(10080))->chunkById(100, function ($tasks) use (&$count) {
            foreach ($tasks as $t) {
                if (! $t->store->active || ! $t->employee->inStore($t->store_id)) {
                    continue;
                }
                $minutes = (int) (DB::table('store_settings')->where('store_id', $t->store_id)->where('key', 'reminder_minutes')->value('value') ?? config('tasksure.reminder_minutes'));
                if ($t->due_at->gt(now()->addMinutes($minutes))) {
                    continue;
                }
                $type = $t->due_at->isPast() ? 'overdue' : 'upcoming';
                $key = $type.':'.$t->id.':'.$t->due_at->timestamp;
                $this->alerts->send($t->employee, $t, $type, $t->title.($type === 'overdue' ? ' is overdue.' : ' is due soon.'), $key);
                $this->alerts->reviewers($t, $type, $t->title.($type === 'overdue' ? ' is overdue.' : ' is due soon.'), $key.':reviewer');
                $count++;
            }
        });

        return $count;
    }
}
