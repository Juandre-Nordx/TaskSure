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
                $loops = 0;
                while ($loops++ < 366) {
                    $date = $p->next_date->toDateString();
                    if ($p->ends_on && $date > $p->ends_on->toDateString()) {
                        $p->update(['active' => false]);
                        break;
                    }
                    $due = BT::utc($date.' '.$p->due_time);
                    $start = $p->scheduled_offset_minutes !== null ? $due->subMinutes($p->scheduled_offset_minutes) : null;
                    if ($date > $today && (! $start || $start->isFuture())) {
                        break;
                    }
                    $t = Task::firstOrCreate(['task_template_id' => $p->id, 'occurrence_date' => $date], ['employee_id' => $p->employee_id, 'creator_id' => $p->creator_id, 'category_id' => $p->category_id, 'title' => $p->title, 'instructions' => $p->instructions, 'area' => $p->area, 'priority' => $p->priority, 'required_evidence' => $p->required_evidence, 'due_at' => $due, 'scheduled_at' => $start]);
                    if ($t->wasRecentlyCreated) {
                        $count++;
                        $t->activities()->create(['user_id' => $p->creator_id, 'type' => 'assigned', 'body' => 'Recurring occurrence generated.']);
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
        $minutes = (int) (DB::table('settings')->where('key', 'reminder_minutes')->value('value') ?? config('tasksure.reminder_minutes'));
        Task::with('employee')->whereNotIn('status', ['completed', 'cancelled'])->where('due_at', '<=', now()->addMinutes($minutes))->chunkById(100, function ($tasks) use (&$count) {
            foreach ($tasks as $t) {
                if (! $t->employee->active) {
                    continue;
                }$type = $t->due_at->isPast() ? 'overdue' : 'upcoming';
                $key = $type.':'.$t->id.':'.$t->due_at->timestamp;
                $this->alerts->send($t->employee, $t, $type, $t->title.($type === 'overdue' ? ' is overdue.' : ' is due soon.'), $key);
                $this->alerts->reviewers($t, $type, $t->title.($type === 'overdue' ? ' is overdue.' : ' is due soon.'), $key.':reviewer');
                $count++;
            }
        });

        return $count;
    }
}
