<?php

namespace App\Http\Controllers;

use App\Models\AccountAudit;
use App\Models\Category;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->user()->role === 'admin', 403);
    }

    public function users(Request $r)
    {
        $this->admin($r);

        return view('admin.users', ['users' => User::with('employees')->orderBy('role')->orderBy('name')->get(), 'employees' => User::where('role', 'employee')->get(), 'audits' => AccountAudit::latest()->limit(30)->get()]);
    }

    public function saveUser(Request $r, ?User $user = null)
    {
        $this->admin($r);
        $new = ! $user;
        $user ??= new User;
        $v = $r->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:180', Rule::unique('users')->ignore($user->id)], 'role' => 'required|in:admin,manager,employee', 'password' => [$new ? 'required' : 'nullable', 'confirmed', Password::min(12)->mixedCase()->numbers()], 'active' => 'required|boolean', 'employees' => 'nullable|array', 'employees.*' => ['integer', Rule::exists('users', 'id')->where('role', 'employee')]]);
        if ($user->exists && $user->role !== $v['role']) {
            return back()->withErrors(['role' => 'Create a separate account to change role; existing task and scope history must remain consistent.']);
        }abort_if($user->id === $r->user()->id && ! $v['active'], 422, 'You cannot deactivate yourself.');
        DB::transaction(function () use ($user, $v, $r, $new) {
            $before = $user->exists ? $user->only(['name', 'email', 'role', 'active']) : [];
            $scopeBefore = $user->exists ? $user->employees()->pluck('users.id')->all() : [];
            if (empty($v['password'])) {
                unset($v['password']);
            }unset($v['password_confirmation']);
            $scope = $v['employees'] ?? [];
            unset($v['employees']);
            $user->fill($v)->save();
            if ($user->role === 'manager') {
                $user->employees()->sync($scope);
            }if (! $user->active || isset($v['password'])) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }AccountAudit::create(['actor_id' => $r->user()->id, 'subject_id' => $user->id, 'action' => $new ? 'created' : 'updated', 'data' => ['before' => $before, 'after' => $user->only(['name', 'email', 'role', 'active']), 'scope_before' => $scopeBefore, 'scope_after' => $scope, 'password_changed' => isset($v['password'])]]);
        });

        return back()->with('success', 'Account saved.');
    }

    public function settings(Request $r)
    {
        $this->admin($r);

        return view('admin.settings', ['categories' => Category::all(), 'templates' => TaskTemplate::with('employee')->get(), 'employees' => User::where('role', 'employee')->where('active', true)->get(), 'reminder' => DB::table('settings')->where('key', 'reminder_minutes')->value('value') ?? config('tasksure.reminder_minutes')]);
    }

    public function category(Request $r)
    {
        $this->admin($r);
        $v = $r->validate(['name' => 'required|string|max:80|unique:categories']);
        Category::create($v);
        AccountAudit::create(['actor_id' => $r->user()->id, 'action' => 'category_created', 'data' => $v]);

        return back()->with('success', 'Category added.');
    }

    public function reminders(Request $r)
    {
        $this->admin($r);
        $v = $r->validate(['reminder_minutes' => 'required|integer|min:1|max:10080']);
        DB::table('settings')->updateOrInsert(['key' => 'reminder_minutes'], ['value' => $v['reminder_minutes']]);
        AccountAudit::create(['actor_id' => $r->user()->id, 'action' => 'reminders_updated', 'data' => $v]);

        return back()->with('success', 'Reminder interval saved.');
    }

    public function template(Request $r, ?TaskTemplate $template = null)
    {
        $this->admin($r);
        $v = $r->validate(['title' => 'required|string|max:180', 'instructions' => 'required|string|max:10000', 'area' => 'required|string|max:100', 'employee_id' => ['required', Rule::exists('users', 'id')->where('role', 'employee')->where('active', true)], 'category_id' => 'required|exists:categories,id', 'priority' => ['required', Rule::in(Task::PRIORITIES)], 'required_evidence' => 'required|array|min:1', 'required_evidence.*' => 'in:photo,document,note|distinct', 'frequency' => 'required|in:daily,weekly,monthly', 'next_date' => 'required|date_format:Y-m-d', 'due_time' => 'required|date_format:H:i', 'active' => 'required|boolean']);
        $v['anchor_day'] = (int) substr($v['next_date'], 8, 2);
        $template ??= new TaskTemplate(['creator_id' => $r->user()->id]);
        $template->fill($v)->save();
        AccountAudit::create(['actor_id' => $r->user()->id, 'action' => 'template_saved', 'data' => ['template_id' => $template->id] + $v]);

        return back()->with('success', 'Template saved. Existing task instances retain their history.');
    }
}
