<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CalendarEvents
{
    public function __construct(private TaskQuery $query) {}

    public function range(Request $request, bool $required = false): array
    {
        $dates = $request->validate([
            'from' => ($required ? 'required' : 'nullable|required_with:to').'|date_format:Y-m-d',
            'to' => ($required ? 'required' : 'nullable|required_with:from').'|date_format:Y-m-d|after_or_equal:from',
            'start' => 'nullable|required_with:end|date', 'end' => 'nullable|required_with:start|date|after:start',
        ]);
        $zone = config('tasksure.timezone');
        $from = $dates['from'] ?? (filled($dates['start'] ?? null) ? CarbonImmutable::parse($dates['start'], $zone)->setTimezone($zone)->toDateString() : now()->timezone($zone)->startOfMonth()->toDateString());
        $to = $dates['to'] ?? (filled($dates['end'] ?? null) ? CarbonImmutable::parse($dates['end'], $zone)->setTimezone($zone)->subMicrosecond()->toDateString() : now()->timezone($zone)->endOfMonth()->toDateString());
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 93) {
            throw ValidationException::withMessages(['to' => 'Choose a calendar range of 94 days or fewer.']);
        }

        return ['from' => $from, 'to' => $to];
    }

    public function forUser(User $user, array $filters, bool $mobile = false, bool $includeRecurring = true): Collection
    {
        $filters['basis'] = 'due';
        $events = $this->query->build($user, $filters)->get()->map(fn ($task) => $this->assignment($task, $user, $mobile));
        if (! $includeRecurring || (! empty($filters['status']) && $filters['status'] !== 'assigned') || ! empty($filters['review_state']) || ! empty($filters['overdue'])) {
            return $events;
        }
        $templates = TaskTemplate::where('active', true)
            ->where('store_id', $user->storeId())
            ->whereIn('employee_id', $user->visibleEmployees()->select('users.id'))
            ->when(($filters['scope'] ?? null) === 'my', fn ($q) => $q->where('employee_id', $user->id))
            ->when(($filters['scope'] ?? null) === 'team', fn ($q) => $q->where('employee_id', '!=', $user->id))
            ->with(['employee', 'creator']);
        foreach (['employee_id', 'category_id', 'priority'] as $key) {
            if (! empty($filters[$key])) {
                $templates->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['q'])) {
            $templates->where(fn ($q) => $q->where('title', 'like', '%'.$filters['q'].'%')->orWhere('area', 'like', '%'.$filters['q'].'%'));
        }
        $templates = $templates->get();
        // Check every existing instance in the date range, including filtered-out,
        // cancelled, reassigned or rescheduled tasks. Never resurrect it as a preview.
        $existing = DB::table('tasks')->whereIn('task_template_id', $templates->modelKeys())
            ->whereBetween('occurrence_date', [$filters['from'], $filters['to']])
            ->get(['task_template_id', 'occurrence_date'])->keyBy(fn ($task) => $task->task_template_id.':'.$task->occurrence_date);
        foreach ($templates as $template) {
            if ($template->generationBlock()) {
                continue;
            }
            $date = $this->firstDate($template, $filters['from']);
            while ($date <= $filters['to'] && (! $template->ends_on || $date <= $template->ends_on->toDateString())) {
                if (! $existing->has($template->id.':'.$date)) {
                    $due = BusinessTime::utc($date.' '.$template->dueTimeOn($date));
                    $events->push([
                        'id' => 'recurring:'.$template->id.':'.$date, 'title' => $template->title.' · Planned recurring',
                        'start' => $due->toIso8601String(), 'editable' => false, 'startEditable' => false, 'durationEditable' => false,
                        'backgroundColor' => '#527b8a', 'classNames' => ['planned-recurring'],
                        'extendedProps' => [
                            'planned' => true, 'template_id' => $template->id, 'occurrence_date' => $date,
                            'assignment_title' => $template->title, 'frequency' => $template->frequency,
                            'assignee' => $template->employee->name, 'area' => $template->area, 'instructions' => $template->instructions,
                            'due_at' => $due->toIso8601String(),
                            'scheduled_at' => $template->scheduled_offset_minutes !== null ? $due->subMinutes($template->scheduled_offset_minutes)->toIso8601String() : null,
                        ],
                    ]);
                }
                $date = $template->followingDate($date);
            }
        }

        return $events->sortBy('start')->values();
    }

    private function firstDate(TaskTemplate $template, string $from): string
    {
        if ($template->frequency === 'specific_dates') {
            return $template->specificDateOnOrAfter(max($from, $template->next_date->toDateString()));
        }
        $cursor = CarbonImmutable::parse($template->next_date->toDateString(), config('tasksure.timezone'));
        $target = CarbonImmutable::parse($from, config('tasksure.timezone'));
        if ($cursor->gte($target)) {
            return $cursor->toDateString();
        }
        // Jump to the requested view rather than walking years of unseen dates.
        if ($template->frequency !== 'monthly') {
            $step = $template->frequency === 'weekly' ? 7 : 1;

            return $cursor->addDays((int) ceil($cursor->diffInDays($target) / $step) * $step)->toDateString();
        }
        $month = $target->startOfMonth();
        $date = $month->day(min($template->anchor_day ?? $cursor->day, $month->daysInMonth))->toDateString();

        return $date < $from ? $template->followingDate($date) : $date;
    }

    private function assignment(Task $task, User $user, bool $mobile): array
    {
        return [
            'id' => (string) $task->id, 'title' => $task->title.' · '.Task::STATUSES[$task->status].($task->overdue() ? ' · OVERDUE' : ''),
            'start' => $task->due_at->toIso8601String(), 'end' => null, 'url' => $mobile ? null : '/tasks/'.$task->id,
            'backgroundColor' => $task->overdue() ? '#b45309' : ($task->status === 'completed' ? '#16755c' : '#264f44'),
            'editable' => ! $mobile && $user->isSupervisor() && ! in_array($task->status, ['completed', 'cancelled']),
            'extendedProps' => ['planned' => false, 'due_at' => $task->due_at->toIso8601String(), 'scheduled_at' => $task->scheduled_at?->toIso8601String()],
        ];
    }
}
