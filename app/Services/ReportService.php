<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Support\BusinessTime as BT;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ReportService
{
    public const OVERDUE_OPTIONS = ['all' => 'All', 'still_overdue' => 'Still overdue', 'completed_late' => 'Completed late'];

    public const DATE_BASES = ['due' => 'Tasks due in period', 'assigned' => 'Tasks assigned in period', 'completed' => 'Tasks completed in period', 'reviewed' => 'Tasks reviewed in period'];

    public function __construct(private TaskQuery $query) {}

    public function run(User $user, array $filters): array
    {
        $asOf = CarbonImmutable::now();
        // Evaluate the legacy overdue option against this report's timestamp too.
        $tasks = $this->query->build($user, array_diff_key($filters, ['overdue' => true]))->with('currentCompletion')->orderBy('due_at')->orderBy('id')->get();
        $timings = $tasks->mapWithKeys(fn ($task) => [$task->id => $this->timing($task, $asOf)]);
        $deadlineFrom = filled($filters['deadline_from'] ?? null) ? BT::utc($filters['deadline_from'].' 00:00:00') : null;
        $deadlineUntil = filled($filters['deadline_to'] ?? null) ? BT::utc($filters['deadline_to'].' 00:00:00')->addDay() : null;
        $overdueStatus = $filters['overdue_status'] ?? 'all';
        // Filter the same scoped cohort used by the screen and both exports. Never group recurring occurrences.
        $tasks = $tasks->filter(function ($task) use ($timings, $deadlineFrom, $deadlineUntil, $overdueStatus, $filters) {
            $timing = $timings[$task->id];

            return (! $deadlineFrom || $timing['deadline']?->gte($deadlineFrom))
                && (! $deadlineUntil || $timing['deadline']?->lt($deadlineUntil))
                && (! $overdueStatus || $overdueStatus === 'all' || $timing['state'] === $overdueStatus)
                && (empty($filters['overdue']) || $timing['state'] === 'still_overdue');
        });
        // Load detailed histories only for occurrences included in the report.
        $tasks->load(['employee', 'category', 'completer', 'reviewer', 'reviews.reviewer', 'submissions.attachments', 'deadlineChanges.user', 'activities']);
        $rows = $tasks->map(function ($t) use ($timings) {
            $first = $t->submissions->first();
            $latest = $t->submissions->last();
            $timing = $timings[$t->id];

            return [
                'id' => $t->id, 'employee' => $t->employee->name, 'role' => $t->employee->role,
                'title' => $t->title, 'category' => $t->category->name, 'priority' => $t->priority,
                'status' => Task::STATUSES[$t->status], 'assigned' => BT::show($t->created_at),
                'due' => BT::show($t->due_at), 'started' => BT::show($t->started_at),
                'first_completion' => BT::show($first?->submitted_at),
                'first_punctuality' => $first ? ($first->submitted_at->lte($first->due_at) ? 'On time' : 'Late') : 'No completion recorded',
                'latest_completion' => BT::show($latest?->submitted_at),
                'latest_punctuality' => $latest ? ($latest->submitted_at->lte($latest->due_at) ? 'On time' : 'Late') : 'No completion recorded',
                'completed' => BT::show($t->completed_at, 'd M Y, H:i:s'), 'completed_by' => $t->completer?->name ?? 'Unknown / not completed',
                'review_state' => $t->status === 'completed' ? ($t->review_status === 'reviewed' ? 'Reviewed' : 'Not reviewed') : 'Not applicable',
                'reviewed' => BT::show($t->reviewed_at), 'reviewer' => $t->reviewer?->name ?? 'Unknown / not reviewed',
                'deadline' => BT::show($timing['deadline'], 'd M Y, H:i:s'),
                'overdue_state' => $timing['label'], 'time_overdue_or_late' => $timing['duration'],
                'overdue' => $timing['state'] === 'still_overdue' ? 'Yes' : 'No',
                'corrections' => $t->reviews->where('action', 'reopened')->count() + $t->submissions->where('review_status', 'changes_requested')->count(),
                'blockers' => $t->activities->where('type', 'blocker')->pluck('body')->implode(' | '),
                'cancellation' => $t->cancellation_reason ?? '',
                'evidence' => $t->submissions->flatMap->attachments->map(fn ($a) => url('/attachments/'.$a->id))->implode(' | '),
                'deadline_changes' => $t->deadlineChanges->map(fn ($d) => BT::show($d->old_due_at).' → '.BT::show($d->new_due_at).' by '.$d->user->name.': '.$d->reason)->implode(' | '),
                'attempts' => $t->submissions->map(fn ($s) => '#'.$s->id.' completed '.BT::show($s->submitted_at).' against '.BT::show($s->due_at).'; '.$s->reviewLabel().'; '.BT::show($s->reviewed_at).'; '.($s->review_reason ?? ''))->implode(' | '),
                'review_history' => $t->reviews->map(fn ($r) => $r->action.' by '.($r->reviewer?->name ?? 'Unknown historical reviewer').' at '.BT::show($r->occurred_at).'; '.($r->note ?? ''))->implode(' | '),
            ];
        })->values()->all();
        $employees = $user->visibleEmployees()->when(! empty($filters['employee_id']), fn ($q) => $q->whereKey($filters['employee_id']))
            ->when(($filters['scope'] ?? null) === 'my', fn ($q) => $q->whereKey($user->id))
            ->when(($filters['scope'] ?? null) === 'team', fn ($q) => $q->whereKeyNot($user->id))->orderBy('name')->get();
        $summary = $employees->map(function ($e) use ($tasks, $timings) {
            $all = $tasks->where('employee_id', $e->id);
            $live = $all->where('status', '!=', 'cancelled');
            $attempted = $live->filter(fn ($t) => $t->submissions->isNotEmpty());
            $on = $attempted->filter(fn ($t) => $t->submissions->first()->submitted_at->lte($t->submissions->first()->due_at))->count();
            $completed = $live->where('status', 'completed');

            return [
                'employee' => $e->name, 'role' => $e->role, 'assigned' => $all->count(),
                'completed' => $completed->count(), 'reviewed' => $completed->where('review_status', 'reviewed')->count(),
                'not_reviewed' => $completed->where('review_status', 'not_reviewed')->count(),
                'pending' => $live->whereIn('status', ['assigned', 'in_progress'])->count(),
                'first_on_time' => $on, 'first_late' => $attempted->count() - $on, 'completion_denominator' => $attempted->count(),
                'overdue' => $live->filter(fn ($t) => $timings[$t->id]['state'] === 'still_overdue')->count(),
                'completed_late' => $completed->filter(fn ($t) => $timings[$t->id]['state'] === 'completed_late')->count(),
                'corrections' => $all->sum(fn ($t) => $t->reviews->where('action', 'reopened')->count() + $t->submissions->where('review_status', 'changes_requested')->count()),
            ];
        })->all();

        $totals = ['selected' => $tasks->count(), 'still_overdue' => array_sum(array_column($summary, 'overdue')), 'completed_late' => array_sum(array_column($summary, 'completed_late'))];
        $report = [
            'generated_at' => BT::show($asOf, 'd M Y, H:i:s'),
            'date_basis' => self::DATE_BASES[$filters['basis'] ?? 'due'],
            'period' => $this->period($filters['from'] ?? null, $filters['to'] ?? null),
            'deadline_period' => $this->period($filters['deadline_from'] ?? null, $filters['deadline_to'] ?? null),
            'overdue_status' => self::OVERDUE_OPTIONS[$overdueStatus ?: 'all'],
            'staff' => ! empty($filters['employee_id']) ? ($employees->first()?->name ?? 'No permitted person selected') : 'All permitted people',
            'additional_filters' => array_filter([
                'Search' => $filters['q'] ?? null,
                'Category' => ! empty($filters['category_id']) ? (Category::forStore($user)->find($filters['category_id'])?->name ?? 'No permitted category selected') : null,
                'Priority' => $filters['priority'] ?? null,
                'Status' => ! empty($filters['status']) ? Task::STATUSES[$filters['status']] : null,
                'Scope' => ['my' => 'My tasks', 'team' => 'Team tasks'][$filters['scope'] ?? ''] ?? null,
                'Review state' => ['reviewed' => 'Reviewed', 'not_reviewed' => 'Not reviewed'][$filters['review_state'] ?? ''] ?? null,
                'Legacy overdue filter' => ! empty($filters['overdue']) ? 'Still overdue only' : null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];

        return compact('rows', 'summary', 'filters', 'totals', 'report') + ['workspace' => Store::with('company')->find($user->storeId())];
    }

    private function timing(Task $task, CarbonInterface $asOf): array
    {
        $deadline = $task->status === 'completed' ? ($task->currentCompletion?->due_at ?? $task->due_at) : $task->due_at;
        $state = null;
        $label = match ($task->status) {
            'completed' => $task->completed_at ? 'On time' : 'Completion time unknown',
            'cancelled' => 'Cancelled',
            default => 'Not overdue',
        };
        $end = null;
        if ($deadline && in_array($task->status, ['assigned', 'in_progress']) && $deadline->lt($asOf)) {
            $state = 'still_overdue';
            $end = $asOf;
        } elseif ($deadline && $task->status === 'completed' && $task->completed_at?->gt($deadline)) {
            $state = 'completed_late';
            $end = $task->completed_at;
        }
        if ($state) {
            $label = self::OVERDUE_OPTIONS[$state];
        }

        return ['deadline' => $deadline, 'state' => $state, 'label' => $label, 'duration' => $end ? $this->duration((int) $deadline->diffInSeconds($end)) : '—'];
    }

    private function duration(int $seconds): string
    {
        if ($seconds === 0) {
            return '<1s';
        }
        $parts = [];
        foreach ([86400 => 'd', 3600 => 'h', 60 => 'm', 1 => 's'] as $unit => $suffix) {
            $value = intdiv($seconds, $unit);
            if ($value) {
                $parts[] = $value.$suffix;
            }
            $seconds %= $unit;
        }

        return implode(' ', $parts);
    }

    private function period(?string $from, ?string $to): string
    {
        return ($from ? BT::show(BT::utc($from), 'd M Y') : 'Any start date').' – '.($to ? BT::show(BT::utc($to), 'd M Y') : 'Any end date');
    }
}
