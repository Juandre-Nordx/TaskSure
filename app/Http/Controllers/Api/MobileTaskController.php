<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TaskController;
use App\Models\Category;
use App\Models\Task;
use App\Services\CalendarEvents;
use App\Services\TaskQuery;
use App\Support\MobileTaskData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MobileTaskController extends Controller
{
    public function index(Request $request, TaskQuery $query, TaskController $web, string $scope = 'my')
    {
        $filters = $web->filters($request);
        if ($scope === 'team') {
            abort_unless($request->user()->isSupervisor(), 403);
        }
        $filters['scope'] = $scope;
        if ($request->routeIs('mobile.reviews')) {
            $filters['status'] = 'completed';
            $filters['basis'] = 'completed';
        }
        $v = $request->validate(['page' => 'nullable|integer|min:1', 'open' => 'nullable|boolean', 'has_submissions' => 'nullable|boolean']);
        $tasks = $query->build($request->user(), $filters)->with(['category', 'employee', 'reviewer', 'template.creator', 'template.employee'])->withCount('submissions')
            ->when($request->boolean('open'), fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']))
            ->when($request->boolean('has_submissions'), fn ($q) => $q->whereHas('submissions'))
            ->orderBy('due_at')->orderBy('id')->paginate(20);

        return response()->json(['data' => $tasks->getCollection()->map(fn ($task) => MobileTaskData::summary($task)), 'meta' => ['page' => $tasks->currentPage(), 'last_page' => $tasks->lastPage(), 'total' => $tasks->total()]]);
    }

    public function options(Request $request)
    {
        abort_unless($request->user()->isSupervisor(), 403);

        return response()->json(['assignees' => $request->user()->assignableEmployees()->orderBy('name')->get(['id', 'name', 'role']), 'categories' => Category::forStore($request->user())->orderBy('name')->get(['id', 'name'])]);
    }

    public function show(Task $task, Request $request)
    {
        Gate::authorize('view', $task);

        return response()->json(['data' => MobileTaskData::details($task, $request->user())]);
    }

    public function calendar(Request $request, CalendarEvents $calendar)
    {
        $request->validate(['include_recurring' => 'nullable|boolean']);

        $events = $calendar->forUser($request->user(), $calendar->range($request, true) + ['scope' => 'my'], true, $request->boolean('include_recurring'));

        return response()->json($events)->header('Cache-Control', 'private, no-store');
    }
}
