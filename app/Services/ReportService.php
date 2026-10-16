<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Support\BusinessTime as BT;

class ReportService
{
    public function __construct(private TaskQuery $query) {}

    public function run(User $user, array $filters): array
    {
        $tasks = $this->query->build($user, $filters)->with(['employee', 'category', 'completer', 'reviewer', 'reviews.reviewer', 'submissions.attachments', 'deadlineChanges.user', 'activities'])->orderBy('due_at')->get();
        $rows = $tasks->map(function ($t) {
            $first = $t->submissions->first();
            $latest = $t->submissions->last();

            return [
                'id' => $t->id, 'employee' => $t->employee->name, 'role' => $t->employee->role,
                'title' => $t->title, 'category' => $t->category->name, 'priority' => $t->priority,
                'status' => Task::STATUSES[$t->status], 'assigned' => BT::show($t->created_at),
                'due' => BT::show($t->due_at), 'started' => BT::show($t->started_at),
                'first_completion' => BT::show($first?->submitted_at),
                'first_punctuality' => $first ? ($first->submitted_at->lte($first->due_at) ? 'On time' : 'Late') : 'No completion recorded',
                'latest_completion' => BT::show($latest?->submitted_at),
                'latest_punctuality' => $latest ? ($latest->submitted_at->lte($latest->due_at) ? 'On time' : 'Late') : 'No completion recorded',
                'completed' => BT::show($t->completed_at), 'completed_by' => $t->completer?->name ?? 'Unknown / not completed',
                'review_state' => $t->status === 'completed' ? ($t->review_status === 'reviewed' ? 'Reviewed' : 'Not reviewed') : 'Not applicable',
                'reviewed' => BT::show($t->reviewed_at), 'reviewer' => $t->reviewer?->name ?? 'Unknown / not reviewed',
                'overdue' => $t->overdue() ? 'Yes' : 'No',
                'corrections' => $t->reviews->where('action', 'reopened')->count() + $t->submissions->where('review_status', 'changes_requested')->count(),
                'blockers' => $t->activities->where('type', 'blocker')->pluck('body')->implode(' | '),
                'cancellation' => $t->cancellation_reason ?? '',
                'evidence' => $t->submissions->flatMap->attachments->map(fn ($a) => url('/attachments/'.$a->id))->implode(' | '),
                'deadline_changes' => $t->deadlineChanges->map(fn ($d) => BT::show($d->old_due_at).' → '.BT::show($d->new_due_at).' by '.$d->user->name.': '.$d->reason)->implode(' | '),
                'attempts' => $t->submissions->map(fn ($s) => '#'.$s->id.' completed '.BT::show($s->submitted_at).' against '.BT::show($s->due_at).'; '.$s->reviewLabel().'; '.BT::show($s->reviewed_at).'; '.($s->review_reason ?? ''))->implode(' | '),
                'review_history' => $t->reviews->map(fn ($r) => $r->action.' by '.($r->reviewer?->name ?? 'Unknown historical reviewer').' at '.BT::show($r->occurred_at).'; '.($r->note ?? ''))->implode(' | '),
            ];
        })->all();
        $employees = $user->visibleEmployees()->when(! empty($filters['employee_id']), fn ($q) => $q->whereKey($filters['employee_id']))
            ->when(($filters['scope'] ?? null) === 'my', fn ($q) => $q->whereKey($user->id))
            ->when(($filters['scope'] ?? null) === 'team', fn ($q) => $q->whereKeyNot($user->id))->orderBy('name')->get();
        $summary = $employees->map(function ($e) use ($tasks) {
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
                'overdue' => $live->filter->overdue()->count(),
                'corrections' => $all->sum(fn ($t) => $t->reviews->where('action', 'reopened')->count() + $t->submissions->where('review_status', 'changes_requested')->count()),
            ];
        })->all();

        return compact('rows', 'summary', 'filters') + ['workspace' => Store::with('company')->find($user->storeId())];
    }
}
