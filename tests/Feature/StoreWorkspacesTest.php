<?php

namespace Tests\Feature;

use App\Jobs\SendAlertEmail;
use App\Jobs\SendAlertPush;
use App\Models\Alert;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Company;
use App\Models\PushDevice;
use App\Models\Store;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\CompanyPolicyService;
use App\Services\MobilePushGateway;
use App\Services\ScheduledTasks;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class StoreWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    private User $owner;

    private User $manager;

    private User $employee;

    private User $otherOwner;

    private User $otherEmployee;

    private Store $a;

    private Store $b;

    private Store $c;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(8, 0));
        $this->owner = $this->person('admin');
        $this->manager = $this->person('manager');
        $this->employee = $this->person('employee');
        $this->a = Store::findOrFail($this->owner->storeId());
        $this->a->update(['name' => 'Store A']);
        $this->manager->employees()->attach($this->employee);
        $foreign = Company::create(['name' => 'Company B']);
        $this->otherOwner = $this->person('admin', $foreign);
        $this->otherEmployee = $this->person('employee', $foreign);
        $this->b = Store::findOrFail($this->otherOwner->storeId());
        $this->b->update(['name' => 'Store B']);
        $this->c = Store::create(['name' => 'Store C', 'company_id' => $this->a->company_id, 'owner_id' => $this->owner->id, 'active' => true]);
        $this->c->users()->attach($this->owner, ['active' => true]);
        $this->platform = $this->person('super_admin');
        $this->category = Category::create(['name' => 'Stock A']);
        Storage::fake('evidence');
        Mail::fake();
    }

    private function person(string $role, ?Company $company = null): User
    {
        return User::create(['name' => $role.' '.uniqid(), 'email' => uniqid().'@stores.test', 'role' => $role, 'password' => 'StorePassword123!', 'active' => true, 'company_id' => $company?->id]);
    }

    private function web(User $person, Store $store): static
    {
        $this->flushHeaders();

        return $this->actingAs($person)->withSession(['workspace.store_id' => $store->id]);
    }

    private function task(Store $store, User $assignee, array $extra = []): Task
    {
        return app(StoreContext::class)->run($store, fn () => Task::create($extra + ['title' => 'Task '.$store->name, 'employee_id' => $assignee->id, 'creator_id' => $store->owner_id ?? $this->owner->id, 'category_id' => $this->category->id, 'instructions' => 'Preserve separate store proof.', 'area' => 'Stockroom', 'priority' => 'normal', 'required_evidence' => ['note'], 'due_at' => now()->addHour()]));
    }

    private function token(User $person, Store $store)
    {
        return app(StoreContext::class)->run($store, fn () => $person->createToken('Store phone', ['mobile', $person->role], now()->addDay()));
    }

    private function mobile(string $token, ?Store $store = null): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$token);
        if ($store) {
            $this->withHeader('X-TaskSure-Store', (string) $store->id);
        }

        return $this;
    }

    public function test_platform_dashboard_is_separate_from_owner_and_task_permissions(): void
    {
        foreach ([$this->owner, $this->manager, $this->employee] as $person) {
            $this->actingAs($person)->get('/super-admin')->assertForbidden();
            $this->post('/super-admin/stores', [])->assertForbidden();
        }
        $task = $this->task($this->a, $this->employee);
        $this->actingAs($this->platform)->get('/super-admin')->assertOk()->assertSee('Store A')->assertSee('Company B');
        $this->get('/')->assertRedirect('/super-admin');
        foreach (['/tasks', '/tasks/'.$task->id, '/reports', '/admin/users', '/admin/company-settings'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/logout')->assertRedirect('/login');
        $this->postJson('/api/mobile/v1/login', ['email' => $this->platform->email, 'password' => 'StorePassword123!', 'device_name' => 'Phone'])->assertForbidden();
        $forged = $this->platform->createToken('Forged mobile ability', ['mobile']);
        $this->mobile($forged->plainTextToken)->getJson('/api/mobile/v1/me')->assertForbidden();
    }

    public function test_platform_creates_stores_with_existing_or_new_owners_without_password_disclosure(): void
    {
        $oldPassword = $this->owner->password;
        $this->actingAs($this->platform)->post('/super-admin/stores', ['name' => '  New branch  ', 'company_name' => '  New company  ', 'owner_id' => $this->owner->id, 'active' => 1])->assertRedirect('/super-admin')->assertSessionHasNoErrors();
        $store = Store::where('name', 'New branch')->sole();
        $this->assertSame('New company', $store->company->name);
        $this->assertSame($this->owner->id, $store->owner_id);
        $this->assertTrue($this->owner->inStore($store->id));
        $this->assertSame($oldPassword, $this->owner->fresh()->password);
        $this->post('/super-admin/stores', ['name' => 'New owner branch', 'company_id' => $this->a->company_id, 'owner_name' => 'New owner', 'owner_email' => 'new-owner@stores.test', 'active' => 1])->assertRedirect('/super-admin')->assertSessionHasNoErrors();
        $owner = User::where('email', 'new-owner@stores.test')->sole();
        $newStore = Store::where('name', 'New owner branch')->sole();
        $this->assertSame('admin', $owner->role);
        $this->assertSame([$newStore->id], $owner->stores()->pluck('stores.id')->all());
        $this->assertFalse(Hash::check('StorePassword123!', $owner->password));
        $this->get('/super-admin/stores/'.$newStore->id.'/edit')->assertOk()->assertDontSee($owner->password);
        Mail::assertNothingSent();
        $this->assertSame('admin', $this->owner->fresh()->role);
    }

    public function test_owner_assignment_changes_revoke_only_that_store_and_keep_history(): void
    {
        $task = $this->task($this->c, $this->employee);
        $this->actingAs($this->platform)->post('/super-admin/stores/'.$this->c->id, ['name' => 'Store C', 'owner_id' => $this->otherOwner->id, 'active' => 1])->assertRedirect('/super-admin');
        $this->assertFalse($this->owner->inStore($this->c->id));
        $this->assertTrue($this->owner->inStore($this->a->id));
        $this->assertTrue($this->otherOwner->inStore($this->c->id));
        $this->web($this->owner, $this->c)->get('/tasks')->assertForbidden();
        $this->assertSame($this->c->id, $task->fresh()->store_id);
        $this->actingAs($this->platform)->postJson('/super-admin/stores/'.$this->c->id, ['name' => 'Store C', 'owner_id' => $this->employee->id, 'active' => 1])->assertUnprocessable();
        $this->assertSame($this->otherOwner->id, $this->c->fresh()->owner_id);
    }

    public function test_owner_can_save_company_and_selected_store_names_and_other_stores_share_company_name(): void
    {
        $this->web($this->owner, $this->a)->post('/admin/company-settings', ['company_name' => '  Ultra Liquor <Main>  ', 'store_name' => '  Main branch  '])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Ultra Liquor <Main>', $this->a->fresh()->company->name);
        $this->assertSame('Main branch', $this->a->fresh()->name);
        $this->assertSame('Store C', $this->c->fresh()->name);
        $this->web($this->owner, $this->a)->get('/admin/company-settings')->assertOk()->assertSee('Ultra Liquor &lt;Main&gt;', false)->assertSee('Main branch');
        $this->get('/reports')->assertOk()->assertSee('Main branch');
        $this->web($this->owner, $this->c)->get('/')->assertOk()->assertSee('Ultra Liquor &lt;Main&gt;', false)->assertSee('Store C');
        $this->postJson('/admin/company-settings', ['company_name' => 'Wrong', 'store_name' => 'Wrong', 'company_id' => $this->b->company_id])->assertUnprocessable();
        $this->postJson('/admin/company-settings', ['company_name' => '  ', 'store_name' => ''])->assertUnprocessable();
        $this->web($this->manager, $this->a)->get('/admin/company-settings')->assertForbidden();
        $this->assertSame('Company B', $this->b->fresh()->company->name);
    }

    public function test_owner_store_switch_is_authorised_and_stale_forms_are_blocked(): void
    {
        $a = $this->task($this->a, $this->employee);
        $this->employee->stores()->attach($this->c, ['active' => true]);
        $c = $this->task($this->c, $this->employee);
        $this->web($this->owner, $this->a)->post('/stores/select', ['store_id' => $this->c->id])->assertRedirect('/')->assertSessionHas('workspace.store_id', $this->c->id);
        $this->withSession(['workspace.store_id' => $this->c->id])->get('/tasks')->assertOk()->assertSee($c->title)->assertDontSee($a->title);
        $this->postJson('/tasks/'.$c->id.'/action', ['action' => 'cancel', 'reason' => 'Stale tab', '_workspace_store_id' => $this->a->id])->assertStatus(409)->assertJsonPath('code', 'STORE_CONTEXT_CHANGED');
        $this->assertSame('assigned', $c->fresh()->status);
        $this->post('/stores/select', ['store_id' => $this->b->id])->assertForbidden();
        $this->get('/stores')->assertOk()->assertDontSee('Store B');
        $this->get('/tasks/'.$a->id)->assertForbidden();
    }

    public function test_company_and_store_names_remain_plain_text_in_exported_spreadsheets(): void
    {
        $this->web($this->owner, $this->a)->post('/admin/company-settings', ['company_name' => '=2+2', 'store_name' => '=SUM(1,2)'])->assertRedirect();
        $response = $this->get('/reports?format=xlsx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'store-report');
        try {
            file_put_contents($path, $response->streamedContent());
            $sheet = IOFactory::load($path)->getSheetByName('Metric definitions');
            $this->assertSame('=2+2', $sheet->getCell('B1')->getValue());
            $this->assertSame('=SUM(1,2)', $sheet->getCell('B2')->getValue());
            $this->assertSame('s', $sheet->getCell('B1')->getDataType());
            $this->assertSame('s', $sheet->getCell('B2')->getDataType());
        } finally {
            unlink($path);
        }
    }

    public function test_tasks_files_reports_notifications_templates_and_categories_are_store_scoped(): void
    {
        $taskA = $this->task($this->a, $this->employee);
        $taskB = $this->task($this->b, $this->otherEmployee);
        $file = Attachment::create(['task_id' => $taskB->id, 'user_id' => $this->otherEmployee->id, 'path' => 'private-b.pdf', 'original_name' => 'b.pdf', 'mime' => 'application/pdf', 'size' => 10, 'kind' => 'document']);
        $categoryB = app(StoreContext::class)->run($this->b, fn () => Category::create(['name' => 'Private category B']));
        $templateB = TaskTemplate::create($taskB->only(['store_id', 'employee_id', 'creator_id', 'category_id', 'title', 'instructions', 'area', 'priority', 'required_evidence']) + ['frequency' => 'daily', 'next_date' => '2026-10-17', 'due_time' => '17:00', 'active' => true]);
        $alertB = Alert::create(['user_id' => $this->owner->id, 'task_id' => $taskB->id, 'type' => 'assigned', 'message' => 'Private alert B', 'dedupe_key' => 'private-b']);
        foreach ([$this->owner, $this->manager, $this->employee] as $person) {
            $this->web($person, $this->a)->get('/tasks/'.$taskB->id)->assertForbidden();
            $this->get('/attachments/'.$file->id)->assertForbidden();
            $this->get('/tasks?store_id='.$this->b->id)->assertOk()->assertDontSee($taskB->title);
            $this->get('/reports')->assertOk()->assertDontSee($taskB->title);
            $this->get('/notifications')->assertOk()->assertDontSee('Private alert B');
            $this->post('/notifications/'.$alertB->id.'/read')->assertForbidden();
            $events = $this->getJson('/calendar/events?from=2026-10-16&to=2026-10-20')->assertOk()->json();
            $this->assertSame([$taskA->title.' · Assigned'], array_values(array_unique(array_column($events, 'title'))));
        }
        $this->web($this->owner, $this->a)->get('/admin/settings')->assertOk()->assertDontSee($categoryB->name)->assertDontSee($taskB->title);
        $this->post('/admin/templates/'.$templateB->id, [])->assertNotFound();
        $this->post('/admin/users/'.$this->otherEmployee->id, [])->assertNotFound();
        $this->post('/admin/memberships', ['user_id' => $this->otherEmployee->id])->assertNotFound();
        $this->get('/admin/users')->assertDontSee($this->otherEmployee->email);
    }

    public function test_owners_can_share_their_staff_but_manager_scope_is_separate_in_each_store(): void
    {
        $this->web($this->owner, $this->c)->post('/admin/memberships', ['user_id' => $this->manager->id])->assertRedirect();
        $this->post('/admin/memberships', ['user_id' => $this->employee->id])->assertRedirect();
        $task = $this->task($this->c, $this->employee);
        $this->web($this->manager, $this->c)->get('/tasks/'.$task->id)->assertForbidden();
        app(StoreContext::class)->run($this->c, fn () => $this->manager->employees()->attach($this->employee));
        $this->get('/tasks/'.$task->id)->assertOk();
        $this->web($this->manager, $this->a)->get('/tasks/'.$task->id)->assertForbidden();
        $this->assertSame(2, DB::table('store_manager_employee')->where('manager_id', $this->manager->id)->where('employee_id', $this->employee->id)->count());
    }

    public function test_policy_overview_excludes_inactive_store_memberships_and_assignment_rejects_foreign_categories(): void
    {
        app(CompanyPolicyService::class)->createDraft($this->owner, 'Store policy draft', 'Not yet published', null, 'text');
        $this->employee->stores()->attach($this->c, ['active' => true]);
        $this->employee->stores()->updateExistingPivot($this->a->id, ['active' => false]);
        $this->web($this->owner, $this->a)->get('/admin/company-policy')->assertOk()->assertSee($this->manager->name)->assertDontSee($this->employee->name);
        $this->get('/tasks/create')->assertOk()->assertDontSee($this->employee->name);
        $token = $this->token($this->manager, $this->a);
        $this->mobile($token->plainTextToken, $this->a)->getJson('/api/mobile/v1/team/options')->assertOk()->assertJsonMissing(['id' => $this->employee->id, 'name' => $this->employee->name]);
        $this->web($this->owner, $this->c)->get('/admin/company-policy')->assertOk()->assertSee($this->employee->name);
        $foreign = app(StoreContext::class)->run($this->b, fn () => Category::create(['name' => 'Foreign assignment category']));
        $count = Task::count();
        $this->postJson('/tasks', ['title' => 'Forged category', 'instructions' => 'Invalid store category', 'area' => 'Stock', 'employee_id' => $this->employee->id, 'category_id' => $foreign->id, 'priority' => 'normal', 'required_evidence' => ['note'], 'due_at' => '2026-10-17T17:00'])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertSame($count, Task::count());
        $this->employee->update(['company_id' => $this->b->company_id]);
        $this->assertTrue($this->employee->inStore($this->c->id));
        $this->assertFalse($this->employee->inStore($this->b->id));
    }

    public function test_mobile_tokens_bind_a_store_and_switching_invalidates_old_context_and_device_registration(): void
    {
        $this->employee->stores()->attach($this->c, ['active' => true]);
        $a = $this->task($this->a, $this->employee);
        $c = $this->task($this->c, $this->employee);
        $token = $this->token($this->employee, $this->a);
        $this->mobile($token->plainTextToken, $this->a)->getJson('/api/mobile/v1/me')->assertOk()->assertJsonPath('store.id', $this->a->id)->assertJsonCount(2, 'stores');
        $this->postJson('/api/mobile/v1/devices', ['platform' => 'android', 'token' => 'store-device-token'])->assertOk();
        $this->postJson('/api/mobile/v1/stores/select', ['store_id' => $this->b->id])->assertForbidden();
        $this->postJson('/api/mobile/v1/stores/select', ['store_id' => $this->c->id])->assertOk()->assertJsonPath('store.id', $this->c->id);
        $this->assertDatabaseCount('push_devices', 0);
        $this->getJson('/api/mobile/v1/tasks')->assertStatus(409)->assertJsonPath('code', 'STORE_CONTEXT_CHANGED');
        $this->mobile($token->plainTextToken, $this->c)->getJson('/api/mobile/v1/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $c->id);
        $this->getJson('/api/mobile/v1/tasks/'.$a->id)->assertForbidden();
        $this->postJson('/api/mobile/v1/tasks/'.$a->id.'/actions', ['action' => 'complete', 'note' => 'No cross-store change'])->assertForbidden();
        $this->postJson('/api/mobile/v1/logout')->assertOk();
        $this->assertSame(0, $this->employee->tokens()->count());
    }

    public function test_shared_company_policy_acknowledgement_survives_switch_but_unrelated_company_does_not_share_it(): void
    {
        $this->employee->stores()->attach($this->c, ['active' => true]);
        $this->employee->stores()->attach($this->b, ['active' => true]);
        $policies = app(CompanyPolicyService::class);
        $a = app(StoreContext::class)->run($this->a, function () use ($policies) {
            $policies->saveDraft($this->owner, 'Company A policy', 'A rules', 0);

            return $policies->publish($this->owner, 1);
        });
        $b = app(StoreContext::class)->run($this->b, function () use ($policies) {
            $policies->saveDraft($this->otherOwner, 'Company B policy', 'B rules', 0);

            return $policies->publish($this->otherOwner, 1);
        });
        $this->assertSame(1, Alert::where('user_id', $this->employee->id)->where('company_id', $this->a->company_id)->count());
        $token = $this->token($this->employee, $this->a);
        $this->mobile($token->plainTextToken, $this->a)->getJson('/api/mobile/v1/tasks')->assertStatus(423);
        $this->postJson('/api/mobile/v1/company-policy/acknowledge', ['policy_version_id' => $a->id, 'acknowledged' => true])->assertOk();
        $this->postJson('/api/mobile/v1/stores/select', ['store_id' => $this->c->id])->assertOk()->assertJsonPath('policy_required', false);
        $this->mobile($token->plainTextToken, $this->c)->getJson('/api/mobile/v1/tasks')->assertOk();
        $this->postJson('/api/mobile/v1/stores/select', ['store_id' => $this->b->id])->assertOk()->assertJsonPath('policy_required', true)->assertJsonPath('policy.id', $b->id);
        $this->mobile($token->plainTextToken, $this->b)->postJson('/api/mobile/v1/company-policy/acknowledge', ['policy_version_id' => $a->id, 'acknowledged' => true])->assertStatus(423)->assertJsonPath('policy.id', $b->id);
        $this->assertDatabaseCount('company_policy_acknowledgements', 1);
    }

    public function test_store_deactivation_blocks_operations_and_delivery_but_allows_selection_sign_out_and_reactivation(): void
    {
        $task = $this->task($this->a, $this->employee);
        $alert = Alert::create(['user_id' => $this->employee->id, 'task_id' => $task->id, 'type' => 'assigned', 'message' => 'Original', 'dedupe_key' => 'inactive-store']);
        $this->actingAs($this->platform)->post('/super-admin/stores/'.$this->a->id, ['name' => 'Store A', 'owner_id' => $this->owner->id, 'active' => 0])->assertRedirect();
        $this->web($this->owner, $this->a)->get('/')->assertForbidden()->assertSee('This store is inactive.');
        $this->get('/stores')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        $token = $this->token($this->employee, $this->a);
        $this->mobile($token->plainTextToken, $this->a)->getJson('/api/mobile/v1/me')->assertOk()->assertJsonPath('store_available', false);
        $this->getJson('/api/mobile/v1/tasks')->assertForbidden()->assertJsonPath('code', 'STORE_INACTIVE');
        (new SendAlertEmail($alert->id))->handle();
        Mail::assertNothingSent();
        $gateway = $this->mock(MobilePushGateway::class);
        $gateway->shouldNotReceive('send');
        (new SendAlertPush($alert->id))->handle($gateway);
        $this->postJson('/api/mobile/v1/logout')->assertOk();
        $this->flushHeaders();
        $this->actingAs($this->platform)->post('/super-admin/stores/'.$this->a->id, ['name' => 'Store A', 'owner_id' => $this->owner->id, 'active' => 1])->assertRedirect();
        $this->web($this->owner, $this->a)->get('/tasks/'.$task->id)->assertOk();
        $this->assertSame('assigned', $task->fresh()->status);
    }

    public function test_shared_identity_changes_cannot_affect_stores_an_owner_does_not_manage(): void
    {
        $this->employee->stores()->attach($this->b, ['active' => true]);
        $old = $this->employee->only(['name', 'email', 'password']);
        $a = $this->token($this->employee, $this->a);
        $b = $this->token($this->employee, $this->b);
        $this->web($this->owner, $this->a)->postJson('/admin/users/'.$this->employee->id, ['name' => 'Changed globally', 'email' => $this->employee->email, 'role' => 'employee', 'active' => 1])->assertUnprocessable();
        $this->post('/admin/users/'.$this->employee->id, ['name' => $this->employee->name, 'email' => $this->employee->email, 'role' => 'employee', 'active' => 0])->assertRedirect();
        $this->assertSame($old, $this->employee->fresh()->only(['name', 'email', 'password']));
        $this->assertTrue($this->employee->fresh()->active);
        $this->assertFalse($this->employee->inStore($this->a->id));
        $this->assertTrue($this->employee->inStore($this->b->id));
        $this->assertFalse($this->employee->tokens()->whereKey($a->accessToken->id)->exists());
        $this->assertTrue($this->employee->tokens()->whereKey($b->accessToken->id)->exists());
    }

    public function test_push_worker_routes_to_the_matching_store_and_ignores_revoked_access(): void
    {
        config(['mobile.push_enabled' => true]);
        $this->employee->stores()->attach($this->c, ['active' => true]);
        $task = $this->task($this->a, $this->employee);
        $a = $this->token($this->employee, $this->a);
        $c = $this->token($this->employee, $this->c);
        $deviceA = PushDevice::create(['user_id' => $this->employee->id, 'personal_access_token_id' => $a->accessToken->id, 'platform' => 'android', 'token_hash' => hash('sha256', 'a'), 'token' => 'a']);
        PushDevice::create(['user_id' => $this->employee->id, 'personal_access_token_id' => $c->accessToken->id, 'platform' => 'android', 'token_hash' => hash('sha256', 'c'), 'token' => 'c']);
        $alert = Alert::create(['user_id' => $this->employee->id, 'task_id' => $task->id, 'type' => 'assigned', 'message' => 'A only', 'dedupe_key' => 'a-only']);
        $gateway = $this->mock(MobilePushGateway::class);
        $gateway->shouldReceive('send')->once()->withArgs(fn ($device, $data) => $device->id === $deviceA->id && $data['store_id'] === (string) $this->a->id)->andReturn(true);
        (new SendAlertPush($alert->id))->handle($gateway);
        $this->assertDatabaseCount('push_deliveries', 1);
        $this->employee->stores()->updateExistingPivot($this->a->id, ['active' => false]);
        (new SendAlertPush($alert->id))->handle($gateway);
        $this->assertDatabaseCount('push_deliveries', 1);
    }

    public function test_store_settings_and_scheduler_state_are_independent(): void
    {
        $this->employee->stores()->attach($this->c, ['active' => true]);
        $this->web($this->owner, $this->a)->post('/admin/reminders', ['reminder_minutes' => 10])->assertRedirect();
        $this->web($this->owner, $this->c)->post('/admin/reminders', ['reminder_minutes' => 30])->assertRedirect();
        $a = $this->task($this->a, $this->employee, ['due_at' => now()->addMinutes(20)]);
        $c = $this->task($this->c, $this->employee, ['due_at' => now()->addMinutes(20)]);
        $this->assertSame(1, app(ScheduledTasks::class)->reminders());
        $this->assertSame(0, Alert::where('task_id', $a->id)->count());
        $this->assertGreaterThan(0, Alert::where('task_id', $c->id)->where('store_id', $this->c->id)->count());
        $template = TaskTemplate::create($c->only(['store_id', 'creator_id', 'employee_id', 'category_id', 'title', 'instructions', 'area', 'priority', 'required_evidence']) + ['frequency' => 'daily', 'next_date' => '2026-10-16', 'due_time' => '17:00', 'active' => true]);
        $this->c->update(['active' => false]);
        $this->assertSame(0, app(ScheduledTasks::class)->occurrences());
        $this->assertSame('2026-10-16', $template->fresh()->next_date->toDateString());
        $this->c->update(['active' => true]);
        $this->assertSame(1, app(ScheduledTasks::class)->occurrences());
        $this->assertSame($this->c->id, $template->tasks()->sole()->store_id);
    }

    public function test_role_escalation_is_denied_and_super_admin_setup_requires_explicit_promotion(): void
    {
        $this->web($this->owner, $this->a)->postJson('/admin/users', ['name' => 'Escalation', 'email' => 'escalation@stores.test', 'role' => 'super_admin', 'active' => 1, 'password' => 'StorePassword123!', 'password_confirmation' => 'StorePassword123!'])->assertUnprocessable();
        $this->postJson('/admin/users/'.$this->manager->id, ['name' => $this->manager->name, 'email' => $this->manager->email, 'role' => 'admin', 'active' => 1])->assertUnprocessable();
        $this->artisan('tasksure:super-admin', ['email' => $this->owner->email])->assertFailed();
        $this->assertSame('admin', $this->owner->fresh()->role);
        $oldHash = $this->owner->password;
        $this->artisan('tasksure:super-admin', ['email' => $this->owner->email, '--promote' => true])->expectsConfirmation('Promotion removes normal store task access and clears this account as assigned store owner. Continue?', 'yes')->assertSuccessful();
        $this->assertSame('super_admin', $this->owner->fresh()->role);
        $this->assertSame($oldHash, $this->owner->fresh()->password);
        $this->assertNull($this->a->fresh()->owner_id);
        $this->artisan('tasksure:super-admin', ['email' => 'new-platform@stores.test', 'name' => 'Platform operator'])->expectsQuestion('New password (12+ characters, mixed case and number)', 'NewPlatformPassword123!')->expectsQuestion('Confirm password', 'NewPlatformPassword123!')->assertSuccessful();
        $new = User::where('email', 'new-platform@stores.test')->sole();
        $this->assertSame('super_admin', $new->role);
        $this->assertSame(0, $new->stores()->count());
        $this->assertTrue(Hash::check('NewPlatformPassword123!', $new->password));
    }
}
