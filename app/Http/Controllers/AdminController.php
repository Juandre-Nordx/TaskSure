<?php

namespace App\Http\Controllers;

use App\Models\AccountAudit;
use App\Models\Category;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ScheduledTasks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->user()->role === 'admin', 403);
    }

    private function identityEditable(User $owner, User $person): bool
    {
        return $person->stores()->whereNotIn('stores.id', $owner->stores()->wherePivot('active', true)->select('stores.id'))->doesntExist();
    }

    public function users(Request $r)
    {
        $this->admin($r);
        $users = $r->user()->membersInStore()->where('role', '!=', 'super_admin')->with('employees')->orderBy('role')->orderBy('name')->get();
        foreach ($users as $person) {
            $person->identity_editable = $this->identityEditable($r->user(), $person);
            $person->membership_active = (bool) DB::table('store_user')->where('store_id', $r->user()->storeId())->where('user_id', $person->id)->value('active');
        }

        return view('admin.users', ['users' => $users, 'availableStaff' => User::whereIn('role', User::ASSIGNEE_ROLES)->where('active', true)->whereHas('stores', fn ($q) => $q->whereIn('stores.id', $r->user()->stores()->wherePivot('active', true)->select('stores.id')))->orderBy('name')->get(), 'employees' => $r->user()->visibleEmployees()->orderBy('name')->get(), 'audits' => AccountAudit::where('store_id', $r->user()->storeId())->where('action', 'not like', 'platform_%')->latest()->limit(30)->get()]);
    }

    public function membership(Request $r)
    {
        $this->admin($r);
        $data = $r->validate(['user_id' => 'required|integer']);
        $user = User::whereKey($data['user_id'])->whereIn('role', User::ASSIGNEE_ROLES)->where('active', true)
            ->whereHas('stores', fn ($q) => $q->whereIn('stores.id', $r->user()->stores()->wherePivot('active', true)->select('stores.id')))->firstOrFail();
        $user->stores()->syncWithoutDetaching([$r->user()->storeId() => ['active' => true]]);
        AccountAudit::create(['actor_id' => $r->user()->id, 'subject_id' => $user->id, 'action' => 'store_membership_added']);

        return back()->with('success', 'Team member added to this store. Configure manager scope separately for this store.');
    }

    public function saveUser(Request $r, ?User $user = null)
    {
        $this->admin($r);
        abort_if($user && ! $user->stores()->whereKey($r->user()->storeId())->exists(), 404);
        abort_if($user && ($user->role === 'super_admin' || ($user->role === 'admin' && $user->id !== $r->user()->id)), 403, 'Owner assignments are managed by Super Admin.');
        $new = ! $user;
        $user ??= new User;
        $v = $r->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:180', Rule::unique('users')->ignore($user->id)], 'role' => $new ? 'required|in:manager,employee' : 'required|in:admin,manager,employee', 'password' => [$new ? 'required' : 'nullable', 'confirmed', Password::min(12)->mixedCase()->numbers()], 'active' => 'required|boolean', 'employees' => 'nullable|array', 'employees.*' => ['integer', Rule::exists('store_user', 'user_id')->where('store_id', $r->user()->storeId())]]);
        if ($user->exists && $user->role !== $v['role']) {
            throw ValidationException::withMessages(['role' => 'Roles stay fixed to preserve history. Super Admin manages owner assignments.']);
        }
        abort_if($user->id === $r->user()->id && ! $v['active'], 422, 'You cannot deactivate yourself.');
        $editable = $new || $this->identityEditable($r->user(), $user);
        if (! $editable && ($v['name'] !== $user->name || $v['email'] !== $user->email || filled($v['password'] ?? null))) {
            throw ValidationException::withMessages(['name' => 'Shared account identity is managed by an owner authorised for all of its stores. Store access and manager scope can still be updated here.']);
        }
        $scope = array_values(array_unique($v['employees'] ?? []));
        abort_unless(User::whereIn('id', $scope)->whereIn('role', User::ASSIGNEE_ROLES)->count() === count($scope), 422, 'Manager scope can contain managers and employees only.');
        DB::transaction(function () use ($user, $v, $r, $new, $editable, $scope) {
            $before = $user->exists ? $user->only(['name', 'email', 'role', 'active']) : [];
            $scopeBefore = $user->exists ? $user->employees()->pluck('users.id')->all() : [];
            $identity = ['name' => $v['name'], 'email' => $v['email']];
            if (filled($v['password'] ?? null)) {
                $identity['password'] = $v['password'];
            }
            if ($new) {
                User::withoutEvents(fn () => $user->fill($identity + ['company_id' => $r->user()->workspaceCompanyId(), 'role' => $v['role'], 'active' => (bool) $v['active']])->save());
            } elseif ($editable) {
                if ($user->stores()->count() === 1 || $v['active']) {
                    $identity['active'] = (bool) $v['active'];
                }
                $user->fill($identity)->save();
            }
            $user->stores()->syncWithoutDetaching([$r->user()->storeId() => ['active' => (bool) $v['active']]]);
            if ($user->role === 'manager') {
                $user->employees()->sync($scope);
            }
            if (isset($identity['password']) || ! $user->active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete();
            } elseif (! $v['active']) {
                $user->tokens()->where('store_id', $r->user()->storeId())->delete();
            }
            AccountAudit::create(['actor_id' => $r->user()->id, 'subject_id' => $user->id, 'action' => $new ? 'created' : 'updated', 'data' => ['before' => $before, 'after' => $user->only(['name', 'email', 'role', 'active']), 'membership_active' => (bool) $v['active'], 'scope_before' => $scopeBefore, 'scope_after' => $scope, 'password_changed' => isset($identity['password'])]]);
        });

        return back()->with('success', 'Account and store access saved.');
    }

    public function settings(Request $r)
    {
        $this->admin($r);

        return view('admin.settings', ['categories' => Category::forStore($r->user())->get(), 'templates' => TaskTemplate::where('store_id', $r->user()->storeId())->with('employee')->get(), 'employees' => $r->user()->assignableEmployees()->orderBy('name')->get(), 'reminder' => DB::table('store_settings')->where('store_id', $r->user()->storeId())->where('key', 'reminder_minutes')->value('value') ?? config('tasksure.reminder_minutes')]);
    }

    public function category(Request $r)
    {
        $this->admin($r);
        $v = $r->validate(['name' => 'required|string|max:80']);
        if (Category::forStore($r->user())->where('name', $v['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'This store already has that category.']);
        }
        $category = Category::firstOrCreate($v);
        $category->stores()->syncWithoutDetaching([$r->user()->storeId()]);
        AccountAudit::create(['actor_id' => $r->user()->id, 'action' => 'category_created', 'data' => $v]);

        return back()->with('success', 'Category added to this store.');
    }

    public function reminders(Request $r)
    {
        $this->admin($r);
        $v = $r->validate(['reminder_minutes' => 'required|integer|min:1|max:10080']);
        DB::table('store_settings')->updateOrInsert(['store_id' => $r->user()->storeId(), 'key' => 'reminder_minutes'], ['value' => $v['reminder_minutes']]);
        AccountAudit::create(['actor_id' => $r->user()->id, 'action' => 'reminders_updated', 'data' => $v]);

        return back()->with('success', 'Store reminder interval saved.');
    }

    public function template(Request $r, ?TaskTemplate $template = null)
    {
        $this->admin($r);
        abort_if($template && $template->store_id !== $r->user()->storeId(), 404);
        $v = $r->validate(['title' => 'required|string|max:180', 'instructions' => 'required|string|max:10000', 'area' => 'required|string|max:100', 'employee_id' => ['required', Rule::exists('store_user', 'user_id')->where('store_id', $r->user()->storeId())->where('active', true)], 'category_id' => ['required', Rule::exists('category_store', 'category_id')->where('store_id', $r->user()->storeId())], 'priority' => ['required', Rule::in(Task::PRIORITIES)], 'required_evidence' => 'required|array|min:1', 'required_evidence.*' => 'in:photo,document,note|distinct', 'frequency' => 'required|in:daily,weekly,monthly', 'next_date' => 'required|date_format:Y-m-d', 'due_time' => 'required|date_format:H:i', 'active' => 'required|boolean', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:next_date']);
        abort_unless(User::findOrFail($v['employee_id'])->inStore($r->user()->storeId()) && in_array(User::findOrFail($v['employee_id'])->role, User::ASSIGNEE_ROLES), 422);
        $template ??= new TaskTemplate(['creator_id' => $r->user()->id, 'store_id' => $r->user()->storeId()]);
        DB::transaction(function () use (&$template, $v, $r) {
            if ($template->exists) {
                $template = TaskTemplate::lockForUpdate()->findOrFail($template->id);
            }
            if (! $template->exists || $template->frequency !== $v['frequency'] || $template->next_date->toDateString() !== $v['next_date']) {
                $v['anchor_day'] = (int) substr($v['next_date'], 8, 2);
            }
            $template->fill($v)->save();
            AccountAudit::create(['actor_id' => $r->user()->id, 'action' => 'template_saved', 'data' => ['template_id' => $template->id] + $v]);
        });
        app(ScheduledTasks::class)->occurrences($template->id);

        return back()->with('success', 'Template saved. Existing task instances retain their history.');
    }
}
