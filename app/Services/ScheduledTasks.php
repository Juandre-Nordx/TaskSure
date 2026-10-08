<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskTemplate;
use App\Support\BusinessTime as BT;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ScheduledTasks
{
    public function __construct(private AlertService $alerts) {}

    public function occurrences(): int
    {
        $count = 0;
        $today = now()->timezone(config('tasksure.timezone'))->toDateString();
        foreach (TaskTemplate::where('active', true)->pluck('id') as $id) {
            DB::transaction(function () use ($id, $today, &$count) {
                $p = TaskTemplate::lockForUpdate()->findOrFail($id);
                if (! $p->active || ! $p->employee->active) {
                    return;
                }$loops = 0;
                while ($p->next_date->toDateString() <= $today && $loops++ < 366) {
                    $date = $p->next_date->toDateString();
                    $t = Task::firstOrCreate(['task_template_id' => $p->id, 'occurrence_date' => $date], ['employee_id' => $p->employee_id, 'creator_id' => $p->creator_id, 'category_id' => $p->category_id, 'title' => $p->title, 'instructions' => $p->instructions, 'area' => $p->area, 'priority' => $p->priority, 'required_evidence' => $p->required_evidence, 'due_at' => BT::utc($date.' '.$p->due_time)]);
                    if ($t->wasRecentlyCreated) {
                        $count++;
                        $t->activities()->create(['user_id' => $p->creator_id, 'type' => 'assigned', 'body' => 'Recurring occurrence generated.']);
                        $this->alerts->send($p->employee, $t, 'assigned', 'You were assigned '.$t->title, 'assigned:'.$t->id);
                    }$d = CarbonImmutable::parse($date);
                    $next = match ($p->frequency) {
                        'daily' => $d->addDay(),'weekly' => $d->addWeek(),default => $d->startOfMonth()->addMonth()->day(min($p->anchor_day ?? $d->day, $d->startOfMonth()->addMonth()->daysInMonth))
                    };
                    $p->update(['next_date' => $next->toDateString()]);
                }
            });
        }

        return $count;
    }

    public function reminders(): int
    {
        $count = 0;
        $minutes = (int) (DB::table('settings')->where('key', 'reminder_minutes')->value('value') ?? config('tasksure.reminder_minutes'));
        Task::with('employee')->whereNotIn('status', ['approved', 'cancelled'])->where('due_at', '<=', now()->addMinutes($minutes))->chunkById(100, function ($tasks) use (&$count) {
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
