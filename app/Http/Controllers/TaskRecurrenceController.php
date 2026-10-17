<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskTemplate;
use App\Services\ScheduledTasks;
use App\Services\TaskRecurrence;
use App\Support\BusinessTime;
use App\Support\SpecificDates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaskRecurrenceController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isSupervisor(), 403);
        $templates = TaskTemplate::manageable($request->user())->with(['creator', 'employee'])->withCount('tasks')->orderByDesc('id')->paginate(20);

        return view('tasks.recurring', compact('templates'));
    }

    public function make(Task $task, Request $request, TaskRecurrence $recurrence, ScheduledTasks $scheduler)
    {
        Gate::authorize('manage', $task);
        $today = now()->timezone(config('tasksure.timezone'))->toDateString();
        $originalDate = BusinessTime::show($task->due_at, 'Y-m-d');
        $data = $request->validate([
            'frequency' => 'required|in:daily,weekly,monthly,specific_dates',
            'next_date' => ['required_unless:frequency,specific_dates', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'after:'.$originalDate],
            'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:next_date|prohibited_if:frequency,specific_dates',
            'specific_dates' => 'required_if:frequency,specific_dates|nullable|array|min:1|max:366|prohibited_unless:frequency,specific_dates',
            'specific_dates.*' => 'required|date_format:Y-m-d|distinct',
            'due_time' => 'required_if:frequency,specific_dates|nullable|date_format:H:i|prohibited_unless:frequency,specific_dates',
        ]);
        $specific = $data['frequency'] === 'specific_dates';
        $dates = $specific ? SpecificDates::normalise($data['specific_dates'], [], $originalDate) : [];
        $plan = $recurrence->make($task, $request->user(), $data['frequency'], $specific ? $dates[0] : $data['next_date'], $data['ends_on'] ?? null, $dates, $data['due_time'] ?? null);
        $scheduler->occurrences($plan->id);

        return $request->expectsJson()
            ? response()->json(['message' => 'Recurring schedule saved.', 'data' => ['id' => $plan->id]], 201)
            : back()->with('success', 'Recurring schedule saved. Existing task history is unchanged.');
    }

    public function edit(TaskTemplate $template, Request $request)
    {
        abort_unless($template->manageableBy($request->user()) && $template->frequency === 'specific_dates', 403);

        return view('tasks.specific-schedule', compact('template'));
    }

    public function update(TaskTemplate $template, Request $request, TaskRecurrence $recurrence, ScheduledTasks $scheduler)
    {
        abort_unless($template->manageableBy($request->user()), 403);
        $data = $request->validate(['specific_dates' => 'required|array|min:1|max:366', 'specific_dates.*' => 'required|date_format:Y-m-d|distinct', 'due_time' => 'required|date_format:H:i', 'schedule_revision' => 'required|integer|min:0', 'active' => 'required|boolean']);
        $recurrence->updateSpecific($template, $request->user(), $data['specific_dates'], $data['due_time'], (int) $data['schedule_revision'], (bool) $data['active']);
        $scheduler->occurrences($template->id);

        return $request->expectsJson() ? response()->json(['message' => 'Schedule saved. Started and completed work is unchanged.']) : back()->with('success', 'Schedule saved. Started and completed work is unchanged.');
    }

    public function status(TaskTemplate $template, Request $request, TaskRecurrence $recurrence, ScheduledTasks $scheduler)
    {
        abort_unless($template->manageableBy($request->user()), 403);
        $data = $request->validate(['active' => 'required|boolean']);
        $active = (bool) $data['active'];
        $recurrence->setActive($template, $request->user(), $active);
        if ($active) {
            $scheduler->occurrences($template->id);
        }
        $message = $active ? 'Recurring schedule resumed.' : 'Recurring schedule paused. Existing tasks are unchanged.';

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);
    }
}
