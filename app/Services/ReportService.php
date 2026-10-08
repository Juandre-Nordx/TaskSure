<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Support\BusinessTime as BT;

class ReportService
{
    public function __construct(private TaskQuery $query) {}

    public function run(User $user, array $filters): array
    {
        $tasks = $this->query->build($user, $filters)->with(['employee', 'category', 'submissions.attachments', 'deadlineChanges.user', 'activities'])->orderBy('due_at')->get();
        $rows = $tasks->map(function ($t) {
            $first = $t->submissions->first();
            $accepted = $t->submissions->firstWhere('review_status', 'approved');

            return ['id' => $t->id, 'employee' => $t->employee->name, 'title' => $t->title, 'category' => $t->category->name, 'priority' => $t->priority, 'status' => Task::STATUSES[$t->status], 'assigned' => BT::show($t->created_at), 'due' => BT::show($t->due_at), 'first_submission' => BT::show($first?->submitted_at), 'first_punctuality' => $first ? ($first->submitted_at->lte($first->due_at) ? 'On time' : 'Late') : 'Not submitted', 'accepted_submission' => BT::show($accepted?->submitted_at), 'accepted_punctuality' => $accepted ? ($accepted->submitted_at->lte($accepted->due_at) ? 'On time' : 'Late') : 'Not accepted', 'approved' => BT::show($t->approved_at), 'overdue' => $t->overdue() ? 'Yes' : 'No', 'corrections' => $t->submissions->where('review_status', 'changes_requested')->count(), 'blockers' => $t->activities->where('type', 'blocker')->pluck('body')->implode(' | '), 'cancellation' => $t->cancellation_reason ?? '', 'evidence' => $t->submissions->flatMap->attachments->map(fn ($a) => url('/attachments/'.$a->id))->implode(' | '), 'deadline_changes' => $t->deadlineChanges->map(fn ($d) => BT::show($d->old_due_at).' → '.BT::show($d->new_due_at).' by '.$d->user->name.': '.$d->reason)->implode(' | '), 'attempts' => $t->submissions->map(fn ($s) => '#'.$s->id.' submitted '.BT::show($s->submitted_at).' against '.BT::show($s->due_at).'; '.$s->review_status.' '.BT::show($s->reviewed_at).'; '.($s->review_reason ?? ''))->implode(' | ')];
        })->all();
        $employees = $user->visibleEmployees()->when(! empty($filters['employee_id']), fn ($q) => $q->whereKey($filters['employee_id']))->orderBy('name')->get();
        $summary = $employees->map(function ($e) use ($tasks) {
            $all = $tasks->where('employee_id', $e->id);
            $live = $all->where('status', '!=', 'cancelled');
            $submitted = $live->filter(fn ($t) => $t->submissions->isNotEmpty());
            $on = $submitted->filter(fn ($t) => $t->submissions->first()->submitted_at->lte($t->submissions->first()->due_at))->count();

            return ['employee' => $e->name, 'assigned' => $all->count(), 'approved' => $live->where('status', 'approved')->count(), 'pending' => $live->whereIn('status', ['assigned', 'in_progress', 'changes_requested'])->count(), 'awaiting_review' => $live->where('status', 'submitted')->count(), 'first_on_time' => $on, 'first_late' => $submitted->count() - $on, 'submitted_denominator' => $submitted->count(), 'overdue' => $live->filter->overdue()->count(), 'corrections' => $all->sum(fn ($t) => $t->submissions->where('review_status', 'changes_requested')->count())];
        })->all();

        return compact('rows', 'summary', 'filters');
    }
}
