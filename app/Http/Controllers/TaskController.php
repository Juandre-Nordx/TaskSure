<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use App\Services\AlertService;
use App\Services\TaskQuery;
use App\Services\TaskWorkflow;
use App\Support\BusinessTime as BT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class TaskController extends Controller
{
    public function __construct(private TaskQuery $query, private TaskWorkflow $workflow) {}

    public function filters(Request $r): array
    {
        return $r->validate(['q' => 'nullable|string|max:100', 'employee_id' => 'nullable|integer', 'category_id' => 'nullable|integer', 'priority' => ['nullable', Rule::in(Task::PRIORITIES)], 'status' => ['nullable', Rule::in(array_keys(Task::STATUSES))], 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'basis' => 'nullable|in:assigned,due,approved', 'overdue' => 'nullable|boolean', 'sort' => 'nullable|in:due_at,title,created_at,priority', 'direction' => 'nullable|in:asc,desc']);
    }

    public function dashboard(Request $r)
    {
        $all = Task::visible($r->user());
        $today = now()->timezone(config('tasksure.timezone'))->format('Y-m-d');
        $counts = ['Open' => (clone $all)->whereIn('status', ['assigned', 'in_progress', 'changes_requested'])->count(), 'Overdue' => (clone $all)->whereNotIn('status', ['approved', 'cancelled'])->where('due_at', '<', now())->count(), 'Awaiting review' => (clone $all)->where('status', 'submitted')->count(), 'Approved' => (clone $all)->where('status', 'approved')->count()];
        $todayTasks = (clone $all)->with(['employee', 'category'])->whereBetween('due_at', [BT::utc($today.' 00:00'), BT::utc($today.' 23:59:59')])->orderBy('due_at')->get();
        $tasks = (clone $all)->with(['employee', 'category'])->whereNotIn('status', ['approved', 'cancelled'])->orderBy('due_at')->limit(8)->get();

        $upcomingTasks = (clone $all)->with(['employee', 'category'])->whereNotIn('status', ['approved', 'cancelled'])->where('due_at', '>', BT::utc($today.' 23:59:59'))->orderBy('due_at')->limit(4)->get();

        return view('dashboard', compact('tasks', 'todayTasks', 'upcomingTasks', 'counts'));
    }

    public function index(Request $r)
    {
        $f = $this->filters($r);
        $tasks = $this->query->build($r->user(), $f)->with(['employee', 'category'])->when(($f['sort'] ?? 'due_at') === 'priority', fn ($q) => $q->orderByRaw("CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END ".($f['direction'] ?? 'asc')), fn ($q) => $q->orderBy($f['sort'] ?? 'due_at', $f['direction'] ?? 'asc'))->paginate(15)->withQueryString();

        return view('tasks.index', ['tasks' => $tasks, 'employees' => $r->user()->visibleEmployees()->get(), 'categories' => Category::all()]);
    }

    public function create(Request $r)
    {
        abort_unless($r->user()->isSupervisor(), 403);

        return view('tasks.create', ['employees' => $r->user()->visibleEmployees()->where('active', true)->get(), 'categories' => Category::all()]);
    }

    public function store(Request $r, AlertService $alerts)
    {
        abort_unless($r->user()->isSupervisor(), 403);
        $v = $r->validate(['title' => 'required|string|max:180', 'instructions' => 'required|string|max:10000', 'area' => 'required|string|max:100', 'employee_id' => 'required|exists:users,id', 'category_id' => 'required|exists:categories,id', 'priority' => ['required', Rule::in(Task::PRIORITIES)], 'required_evidence' => 'required|array|min:1', 'required_evidence.*' => 'required|in:photo,document,note|distinct', 'scheduled_at' => 'nullable|date_format:Y-m-d\TH:i', 'due_at' => 'required|date_format:Y-m-d\TH:i|after_or_equal:scheduled_at']);
        $e = User::findOrFail($v['employee_id']);
        abort_unless($e->active && $r->user()->canManageEmployee($e), 403);
        $v['scheduled_at'] = filled($v['scheduled_at'] ?? null) ? BT::utc($v['scheduled_at']) : null;
        $v['due_at'] = BT::utc($v['due_at']);
        $t = DB::transaction(function () use ($r, $v, $e, $alerts) {
            $t = Task::create($v + ['creator_id' => $r->user()->id]);
            $this->workflow->log($t, $r->user(), 'assigned', 'Task assigned.');
            $alerts->send($e, $t, 'assigned', 'You were assigned '.$t->title, 'assigned:'.$t->id);

            return $t;
        });

        return redirect('/tasks/'.$t->id)->with('success', 'Task assigned.');
    }

    public function show(Task $task, Request $r)
    {
        Gate::authorize('view', $task);
        $task->load(['employee', 'creator', 'category', 'submissions.attachments', 'submissions.reviewer', 'attachments', 'activities.user', 'deadlineChanges.user']);

        return view('tasks.show', ['task' => $task, 'employees' => $r->user()->visibleEmployees()->where('active', true)->get()]);
    }

    public function action(Task $task, Request $r)
    {
        $action = $r->validate(['action' => 'required|in:start,submit,approve,changes_requested,reschedule,reassign,cancel,comment,blocker'])['action'];
        Gate::authorize(in_array($action, ['approve', 'changes_requested', 'reschedule', 'reassign', 'cancel']) ? 'manage' : (in_array($action, ['comment']) ? 'view' : 'work'), $task);
        switch ($action) {
            case 'start':$this->workflow->start($task, $r->user());
                break;
            case 'submit':$v = $r->validate(['note' => 'nullable|string|max:10000']);
                $this->workflow->submit($task, $r->user(), $v['note'] ?? null);
                break;
            case 'approve':case 'changes_requested':$v = $r->validate(['reason' => ($action === 'changes_requested' ? 'required' : 'nullable').'|string|max:5000']);
                $this->workflow->review($task, $r->user(), $action === 'approve' ? 'approved' : $action, $v['reason'] ?? null);
                break;
            case 'reschedule':$v = $r->validate(['due_at' => 'required|date_format:Y-m-d\TH:i', 'reason' => 'required|string|max:5000', 'confirmed' => 'accepted']);
                $this->workflow->reschedule($task, $r->user(), BT::utc($v['due_at']), $v['reason']);
                break;
            case 'reassign':$v = $r->validate(['employee_id' => 'required|exists:users,id', 'reason' => 'required|string|max:5000']);
                $e = User::findOrFail($v['employee_id']);
                abort_unless($e->active && $r->user()->canManageEmployee($e), 403);
                $this->workflow->reassign($task, $r->user(), $e, $v['reason']);
                break;
            case 'cancel':$v = $r->validate(['reason' => 'required|string|max:5000']);
                $this->workflow->cancel($task, $r->user(), $v['reason']);
                break;
            default:$v = $r->validate(['body' => 'required|string|max:5000']);
                DB::transaction(function () use ($task, $r, $action, $v) {
                    $this->workflow->log($task, $r->user(), $action, $v['body']);
                    if ($action === 'blocker') {
                        app(AlertService::class)->reviewers($task, 'blocker', $task->title.': '.$v['body'], 'blocker:'.$task->activities()->max('id'));
                    }
                });
        }

        return $r->expectsJson() ? response()->json(['message' => 'Task updated.']) : back()->with('success', 'Task updated.');
    }

    public function upload(Task $task, Request $r)
    {
        Gate::authorize('work', $task);
        $r->validate(['file' => ['required', File::types(['jpg', 'jpeg', 'png', 'webp', 'pdf'])->max(config('tasksure.upload_max_kb'))]]);
        $file = $r->file('file');
        $mime = $file->getMimeType();
        $kind = str_starts_with($mime, 'image/') ? 'photo' : 'document';
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']), 422);
        if ($kind === 'photo') {
            abort_unless(@getimagesize($file->getRealPath()) !== false, 422);
        } else {
            abort_unless(str_starts_with(file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-'), 422);
        }$path = null;
        try {
            DB::transaction(function () use ($task, $r, $file, $mime, $kind, &$path) {
                $t = Task::lockForUpdate()->findOrFail($task->id);
                Gate::authorize('work', $t);
                abort_unless(in_array($t->status, ['assigned', 'in_progress', 'changes_requested']), 422);
                $path = $file->store('evidence', 'evidence');
                $t->attachments()->create(['user_id' => $r->user()->id, 'path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180), 'mime' => $mime, 'kind' => $kind, 'size' => $file->getSize()]);
                $this->workflow->log($t, $r->user(), 'upload', 'Evidence uploaded.');
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('evidence')->delete($path);
            }throw $e;
        }

        return $r->expectsJson() ? response()->json(['message' => 'Evidence uploaded.']) : back()->with('success', 'Evidence uploaded.');
    }

    public function attachment(Attachment $attachment, Request $r)
    {
        Gate::authorize('view', $attachment->task);
        abort_unless(Storage::disk('evidence')->exists($attachment->path), 404);
        $inline = $r->boolean('preview') && $attachment->kind === 'photo';

        return response()->file(Storage::disk('evidence')->path($attachment->path), ['Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="evidence-'.$attachment->id.'.'.pathinfo($attachment->path, PATHINFO_EXTENSION).'"']);
    }

    public function calendar(Request $r)
    {
        return view('calendar', ['employees' => $r->user()->visibleEmployees()->get(), 'categories' => Category::all()]);
    }

    public function events(Request $r)
    {
        $f = $this->filters($r);

        return response()->json($this->query->build($r->user(), $f)->with('employee')->get()->map(fn ($t) => ['id' => $t->id, 'title' => $t->title.' · '.Task::STATUSES[$t->status].($t->overdue() ? ' · OVERDUE' : ''), 'start' => $t->due_at, 'end' => null, 'url' => '/tasks/'.$t->id, 'backgroundColor' => $t->overdue() ? '#b45309' : ($t->status === 'approved' ? '#16755c' : '#264f44'), 'editable' => $r->user()->isSupervisor() && ! in_array($t->status, ['approved', 'cancelled']), 'extendedProps' => ['due_at' => $t->due_at->toIso8601String(), 'scheduled_at' => $t->scheduled_at?->toIso8601String()]]));
    }
}
