<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TaskController;
use App\Models\Task;
use App\Services\TaskQuery;
use App\Support\MobileTaskData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MobileTaskController extends Controller
{
    public function index(Request $request, TaskQuery $query, TaskController $web)
    {
        $filters = $web->filters($request);
        $v = $request->validate(['page' => 'nullable|integer|min:1', 'open' => 'nullable|boolean', 'has_submissions' => 'nullable|boolean']);
        $tasks = $query->build($request->user(), $filters)->with('category')->withCount('submissions')
            ->when($request->boolean('open'), fn ($q) => $q->whereNotIn('status', ['approved', 'cancelled']))
            ->when($request->boolean('has_submissions'), fn ($q) => $q->whereHas('submissions'))
            ->orderBy('due_at')->orderBy('id')->paginate(20);

        return response()->json(['data' => $tasks->getCollection()->map(fn ($task) => MobileTaskData::summary($task)), 'meta' => ['page' => $tasks->currentPage(), 'last_page' => $tasks->lastPage(), 'total' => $tasks->total()]]);
    }

    public function show(Task $task, Request $request)
    {
        Gate::authorize('view', $task);

        return response()->json(['data' => MobileTaskData::details($task, $request->user()->id)]);
    }

    public function calendar(Request $request, TaskQuery $query)
    {
        $filters = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        $request->validate(['to' => 'before_or_equal:'.CarbonImmutable::parse($filters['from'])->addDays(93)->toDateString()]);

        return response()->json($query->build($request->user(), $filters)->get()->map(fn ($task) => [
            'id' => (string) $task->id, 'title' => $task->title.' · '.Task::STATUSES[$task->status].($task->overdue() ? ' · OVERDUE' : ''),
            'start' => $task->due_at->toIso8601String(), 'editable' => false,
            'backgroundColor' => $task->overdue() ? '#b45309' : ($task->status === 'approved' ? '#16755c' : '#264f44'),
        ]));
    }
}
