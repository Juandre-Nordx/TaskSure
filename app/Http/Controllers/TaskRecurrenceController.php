<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskTemplate;
use App\Services\ScheduledTasks;
use App\Services\TaskRecurrence;
use App\Support\BusinessTime;
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
            'frequency' => 'required|in:daily,weekly,monthly',
            'next_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'after:'.$originalDate],
            'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:next_date',
        ]);
        $plan = $recurrence->make($task, $request->user(), $data['frequency'], $data['next_date'], $data['ends_on'] ?? null);
        $scheduler->occurrences($plan->id);

        return $request->expectsJson()
            ? response()->json(['message' => 'Recurring schedule saved.', 'data' => ['id' => $plan->id]], 201)
            : back()->with('success', 'Recurring schedule saved. Existing task history is unchanged.');
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
