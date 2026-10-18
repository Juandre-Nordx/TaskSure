<?php

namespace Tests\Feature;

use App\Models\AccountAudit;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyPolicyAcknowledgement;
use App\Models\Store;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CompanyPolicyService;
use App\Services\ExistingActivityRecorder;
use App\Services\ReportService;
use App\Services\ScheduledTasks;
use App\Services\TaskWorkflow;
use App\Support\StoreContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActivityLogsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'ActivityFixture123!';

    private const API = '/api/mobile/v1';

    private User $platform;

    private User $owner;

    private User $manager;

    private User $worker;

    private User $unscoped;

    private Store $store;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00:00', 'UTC'));
        $this->platform = $this->person('super_admin', 'Platform operator');
        $this->owner = $this->person('admin', 'Store owner');
        $this->manager = $this->person('manager', 'Manager Alpha');
        $this->worker = $this->person('employee', 'Worker Alpha');
        $this->unscoped = $this->person('employee', 'Unscoped employee');
        $this->manager->employees()->attach($this->worker);
        $this->store = Store::findOrFail($this->owner->storeId());
        $this->store->update(['name' => 'Alpha branch']);
        $this->category = Category::create(['name' => 'Activity stock']);
        Storage::fake('evidence');
        config(['mobile.push_enabled' => false, 'tasksure.email_enabled' => false]);
    }

    private function person(string $role, string $name, ?Company $company = null): User
    {
        return User::create(['name' => $name, 'email' => uniqid().'@activity.test', 'password' => self::PASSWORD, 'role' => $role, 'active' => true, 'company_id' => $company?->id]);
    }

    private function web(User $user, ?Store $store = null): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->flushSession();

        return $this->actingAs($user, 'web')->withSession(['workspace.store_id' => ($store ?? $this->store)->id]);
    }

    private function guest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->flushSession();

        return $this;
    }

    private function mobile(User $user, ?Store $store = null): static
    {
        $store ??= $this->store;
        $token = app(StoreContext::class)->run($store, fn () => $user->createToken('Audit test phone', ['mobile', $user->role], now()->addDay()));
        $this->guest()->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->withHeader('X-TaskSure-Store', (string) $store->id);

        return $this;
    }

    private function task(string $title = 'Audit beer fridges', array $extra = []): Task
    {
        return Task::create($extra + ['title' => $title, 'instructions' => 'TaskBodySecretCanary', 'area' => 'Beer', 'priority' => 'normal', 'employee_id' => $this->worker->id, 'creator_id' => $this->owner->id, 'category_id' => $this->category->id, 'required_evidence' => ['note'], 'due_at' => now()->addHour()]);
    }

    private function event(string $action, string $result = 'success'): ActivityLog
    {
        return ActivityLog::where('action', $action)->where('result', $result)->latest('id')->firstOrFail();
    }

    private function taskData(array $extra = []): array
    {
        return $extra + ['title' => 'Created from phone', 'instructions' => 'TaskBodySecretCanary', 'area' => 'Beer', 'priority' => 'high', 'employee_id' => $this->worker->id, 'category_id' => $this->category->id, 'required_evidence' => ['photo', 'note'], 'due_at' => '2026-10-18T16:00'];
    }

    public function test_web_login_success_failure_unknown_account_and_logout_are_safe_and_attributed(): void
    {
        $this->guest()->post('/login', ['email' => $this->worker->email, 'password' => 'WrongPasswordCanary123!'])->assertSessionHasErrors('email');
        $failed = $this->event('account.login', 'failure');
        $this->assertSame('claimed', $failed->attribution);
        $this->assertSame($this->worker->id, $failed->actor_id);
        $this->assertSame('employee', $failed->actor_role);
        $this->assertSame('credentials_rejected', $failed->details['reason']);
        $this->guest()->post('/login', ['email' => 'unknown@activity.test', 'password' => 'WrongPasswordCanary123!'])->assertSessionHasErrors('email');
        $this->assertNull($this->event('account.login', 'failure')->actor_id);
        $this->guest()->post('/login', ['email' => $this->owner->email, 'password' => self::PASSWORD])->assertRedirect('/');
        $success = $this->event('account.login');
        $this->assertSame($this->owner->id, $success->actor_id);
        $this->assertSame('authenticated', $success->attribution);
        $this->assertSame($this->store->id, $success->store_id);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertSame($this->owner->id, $this->event('account.logout')->actor_id);
        $this->assertSame(2, ActivityLog::where('action', 'account.login')->where('result', 'failure')->count());
        $dump = ActivityLog::all()->toJson();
        foreach ([self::PASSWORD, 'WrongPasswordCanary123!', $this->owner->password] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_mobile_login_validation_role_restriction_token_privacy_and_logout(): void
    {
        $this->guest()->postJson(self::API.'/login', ['email' => $this->owner->email, 'password' => self::PASSWORD, 'device_name' => 'Phone'])->assertForbidden();
        $this->assertSame('admin', $this->event('account.login', 'failure')->actor_role);
        $this->guest()->postJson(self::API.'/login', ['email' => $this->worker->email, 'password' => 'RejectedPasswordCanary', 'device_name' => 'Phone'])->assertUnprocessable();
        $this->guest()->postJson(self::API.'/login', ['email' => ['invalid'], 'password' => 'RejectedPasswordCanary'])->assertUnprocessable();
        $this->assertSame(3, ActivityLog::where('action', 'account.login')->where('result', 'failure')->count());
        $this->assertSame('claimed', $this->event('account.login', 'failure')->attribution);
        $response = $this->guest()->postJson(self::API.'/login', ['email' => $this->manager->email, 'password' => self::PASSWORD, 'device_name' => 'DeviceNameSecretCanary'])->assertOk();
        $token = $response->json('token');
        $this->assertSame('mobile', $this->event('account.login')->source);
        $this->assertSame($this->manager->id, $this->event('account.login')->actor_id);
        $this->guest()->withHeader('Authorization', 'Bearer '.$token)->postJson(self::API.'/logout')->assertOk();
        $this->assertSame($this->manager->id, $this->event('account.logout')->actor_id);
        $this->assertSame($this->store->id, $this->event('account.logout')->store_id);
        foreach ([$token, self::PASSWORD, 'DeviceNameSecretCanary', 'RejectedPasswordCanary'] as $secret) {
            $this->assertStringNotContainsString($secret, ActivityLog::all()->toJson());
        }
    }

    public function test_account_creation_deactivation_role_rejection_and_actor_snapshots(): void
    {
        $this->web($this->owner)->post('/admin/users', ['name' => 'New employee', 'email' => 'new@activity.test', 'role' => 'employee', 'active' => 1, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'actor_id' => $this->platform->id, 'company_id' => 999])->assertRedirect()->assertSessionHasNoErrors();
        $created = $this->event('account.created');
        $this->assertSame($this->owner->id, $created->actor_id);
        $this->assertSame($this->store->company_id, $created->company_id);
        $this->assertSame('employee', $created->after_values['role']);
        $task = $this->task();
        $this->web($this->worker)->post('/tasks/'.$task->id.'/action', ['action' => 'start'])->assertRedirect();
        $started = $this->event('task.started');
        $oldName = $started->actor_name;
        $oldEmail = $started->actor_email;
        $this->web($this->owner)->post('/admin/users/'.$this->worker->id, ['name' => 'Renamed deactivated user', 'email' => $this->worker->email, 'role' => 'employee', 'active' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $deactivated = $this->event('account.deactivated');
        $this->assertTrue($deactivated->before_values['store_access_active']);
        $this->assertFalse($deactivated->after_values['store_access_active']);
        $this->assertSame($this->worker->id, $started->fresh()->actor_id);
        $this->assertSame($oldName, $started->fresh()->actor_name);
        $this->assertSame($oldEmail, $started->fresh()->actor_email);
        $this->assertSame('employee', $started->fresh()->actor_role);
        $this->postJson('/admin/users/'.$this->worker->id, ['name' => 'Renamed deactivated user', 'email' => $this->worker->email, 'role' => 'manager', 'active' => 1])->assertUnprocessable();
        $this->assertSame('employee', $this->worker->fresh()->role);
        $this->assertSame([], $this->event('account.updated', 'failure')->after_values);
    }

    public function test_store_creation_owner_assignment_and_company_setting_before_after(): void
    {
        $this->web($this->platform)->post('/super-admin/stores', ['name' => 'Beta branch', 'company_name' => 'Beta company', 'owner_name' => 'Beta owner', 'owner_email' => 'beta@activity.test', 'active' => 1, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertRedirect('/super-admin')->assertSessionHasNoErrors();
        $store = Store::where('name', 'Beta branch')->firstOrFail();
        $created = $this->event('store.created');
        $this->assertSame($store->id, $created->record_id);
        $this->assertSame($this->platform->id, $created->actor_id);
        $this->assertSame([], $created->before_values);
        $this->assertSame($store->owner_id, $this->event('store.owner_assigned')->after_values['owner_id']);
        $this->assertSame($store->owner_id, $this->event('account.created')->record_id);
        $this->web($store->owner, $store)->post('/admin/company-settings', ['company_name' => 'Beta renamed', 'store_name' => 'Beta renamed branch'])->assertRedirect();
        $settings = $this->event('settings.company_changed');
        $this->assertSame('Beta company', $settings->before_values['company_name']);
        $this->assertSame('Beta renamed', $settings->after_values['company_name']);
        $this->assertSame($store->id, $settings->store_id);
        $this->web($this->platform)->post('/super-admin/stores/'.$store->id, ['name' => 'Beta renamed branch', 'active' => 1, 'owner_id' => $this->owner->id])->assertRedirect('/super-admin');
        $assigned = $this->event('store.owner_assigned');
        $this->assertSame($store->owner_id, $assigned->before_values['owner_id']);
        $this->assertSame($this->owner->id, $assigned->after_values['owner_id']);
        $this->assertSame('Beta owner', $assigned->before_values['owner_name']);
        $this->assertStringNotContainsString(self::PASSWORD, ActivityLog::all()->toJson());
    }

    public function test_native_task_creation_upload_start_completion_review_and_reopening_share_server_events(): void
    {
        $created = $this->mobile($this->manager)->postJson(self::API.'/team/tasks', $this->taskData(['actor_id' => $this->platform->id]))->assertCreated();
        $task = Task::findOrFail($created->json('data.id'));
        $event = $this->event('task.created');
        $this->assertSame('mobile', $event->source);
        $this->assertSame($this->manager->id, $event->actor_id);
        $this->assertSame($task->id, $event->record_id);
        $this->assertSame($this->worker->id, $event->after_values['employee_id']);
        $this->mobile($this->worker)->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'start', 'actor_id' => $this->owner->id])->assertOk();
        $this->assertSame('assigned', $this->event('task.started')->before_values['status']);
        $this->assertSame('in_progress', $this->event('task.started')->after_values['status']);
        $this->postJson(self::API.'/tasks/'.$task->id.'/uploads', ['file' => UploadedFile::fake()->image('fridge-proof.jpg')])->assertOk();
        $upload = $this->event('evidence.uploaded');
        $this->assertSame('evidence', $upload->record_type);
        $this->assertSame('fridge-proof.jpg', $upload->after_values['original_name']);
        $this->assertArrayNotHasKey('path', $upload->after_values);
        $this->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'complete', 'note' => 'CompletionNoteSecretCanary', 'completed_at' => '2000-01-01', 'user_id' => $this->owner->id])->assertOk();
        $completed = $this->event('task.completed');
        $this->assertSame($this->worker->id, $completed->actor_id);
        $this->assertSame($this->worker->id, $completed->after_values['completed_by_id']);
        $this->assertSame('completed', $completed->after_values['status']);
        $this->mobile($this->manager)->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'mark_reviewed'])->assertOk();
        $this->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'mark_reviewed'])->assertOk();
        $this->assertSame(1, ActivityLog::where('action', 'task.reviewed')->where('result', 'success')->count());
        $this->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'reopen', 'reason' => 'ReopeningNoteSecretCanary'])->assertOk();
        $reopened = $this->event('task.reopened');
        $this->assertSame('completed', $reopened->before_values['status']);
        $this->assertSame('assigned', $reopened->after_values['status']);
        $this->assertNotNull($reopened->before_values['completed_at']);
        $this->assertNull($reopened->after_values['completed_at']);
        $this->assertNotNull($task->activities()->where('type', 'reopened')->firstOrFail()->data['previous_completed_at']);
        foreach (['TaskBodySecretCanary', 'CompletionNoteSecretCanary', 'ReopeningNoteSecretCanary'] as $secret) {
            $this->assertStringNotContainsString($secret, ActivityLog::all()->toJson());
        }
    }

    public function test_task_edits_reassignment_deadlines_failed_scope_and_evidence_deletion(): void
    {
        $task = $this->task();
        $this->web($this->owner);
        $task->update(['title' => 'Updated stock check', 'priority' => 'urgent', 'instructions' => 'UpdatedTaskBodySecretCanary']);
        $edited = $this->event('task.edited');
        $this->assertSame('Audit beer fridges', $edited->before_values['title']);
        $this->assertSame('Updated stock check', $edited->after_values['title']);
        $this->assertSame('normal', $edited->before_values['priority']);
        $this->assertSame('urgent', $edited->after_values['priority']);
        $this->assertArrayNotHasKey('instructions', $edited->after_values);
        $this->post('/tasks/'.$task->id.'/action', ['action' => 'reassign', 'employee_id' => $this->manager->id, 'reason' => 'Change shift'])->assertRedirect();
        $this->assertSame($this->worker->id, $this->event('task.assigned')->before_values['employee_id']);
        $this->assertSame($this->manager->id, $this->event('task.assigned')->after_values['employee_id']);
        $this->post('/tasks/'.$task->id.'/action', ['action' => 'reschedule', 'due_at' => '2026-10-19T10:30', 'reason' => 'New shift', 'confirmed' => 1])->assertRedirect();
        $deadline = $this->event('task.deadline_changed');
        $this->assertSame('2026-10-19 08:30:00', CarbonImmutable::parse($deadline->after_values['due_at'])->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-18 09:00:00', CarbonImmutable::parse($deadline->before_values['due_at'])->utc()->format('Y-m-d H:i:s'));
        $this->mobile($this->worker)->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'start'])->assertForbidden();
        $failed = $this->event('task.started', 'failure');
        $this->assertSame($this->worker->id, $failed->actor_id);
        $this->assertSame($task->id, $failed->record_id);
        $this->assertSame([], $failed->after_values);
        $this->assertStringNotContainsString('Updated stock check', $failed->record_label);
        $this->mobile($this->manager)->postJson(self::API.'/tasks/'.$task->id.'/uploads', ['file' => UploadedFile::fake()->image('unsubmitted.jpg')])->assertOk();
        $attachment = $task->attachments()->firstOrFail();
        $privatePath = $attachment->path;
        $attachment->delete();
        $deleted = $this->event('evidence.deleted');
        $this->assertSame($attachment->id, $deleted->record_id);
        $this->assertSame('unsubmitted.jpg', $deleted->before_values['original_name']);
        $this->assertStringNotContainsString($privatePath, ActivityLog::all()->toJson());
        $this->assertStringNotContainsString('UpdatedTaskBodySecretCanary', ActivityLog::all()->toJson());
    }

    public function test_policies_record_upload_publication_download_and_exact_idempotent_acknowledgement(): void
    {
        $bytes = str_replace('%%EOF', "%PdfContentsSecretCanary\n%%EOF", file_get_contents(base_path('tests/Fixtures/company-policy.pdf')));
        $this->web($this->owner)->post('/admin/company-policy/draft', ['title' => 'Audit safety policy', 'format' => 'pdf', 'policy_pdf' => UploadedFile::fake()->createWithContent('safety.pdf', $bytes), 'draft_revision' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $entry = app(CompanyPolicyService::class)->defaultPolicy($this->owner);
        $this->assertSame('safety.pdf', $this->event('policy.uploaded')->after_values['draft_pdf_name']);
        $this->post('/admin/company-policy/publish', ['draft_revision' => $entry->draft_revision])->assertRedirect()->assertSessionHasNoErrors();
        $policy = $entry->fresh()->currentVersion;
        $this->assertSame($policy->id, $this->event('policy.published')->record_id);
        $this->web($this->manager)->get('/company-policy/versions/'.$policy->id.'/pdf?download=1')->assertOk();
        $this->assertSame($policy->id, $this->event('policy.downloaded')->record_id);
        $this->assertDatabaseCount('company_policy_acknowledgements', 0);
        $this->mobile($this->worker)->getJson(self::API.'/company-policy/versions/'.$policy->id.'/pdf')->assertOk();
        $this->assertSame('mobile', $this->event('policy.pdf_opened')->source);
        $this->postJson(self::API.'/company-policy/acknowledge', ['policy_version_id' => $policy->id, 'acknowledged' => false])->assertUnprocessable();
        $this->event('policy.acknowledged', 'failure');
        foreach ([1, 2] as $_) {
            $this->postJson(self::API.'/company-policy/acknowledge', ['policy_version_id' => $policy->id, 'acknowledged' => true])->assertOk();
        }
        $ack = $this->event('policy.acknowledged');
        $this->assertSame($this->worker->id, $ack->actor_id);
        $this->assertSame($policy->id, $ack->after_values['policy_version_id']);
        $this->assertSame(1, ActivityLog::where('action', 'policy.acknowledged')->where('result', 'success')->count());
        $service = app(CompanyPolicyService::class);
        $service->saveDraft($this->owner, 'Replacement safety policy', 'PolicyBodySecretCanary', $entry->fresh()->draft_revision, null, 'text');
        $service->publish($this->owner, $entry->fresh()->draft_revision);
        $this->postJson(self::API.'/company-policy/acknowledge', ['policy_version_id' => $policy->id, 'acknowledged' => true])->assertStatus(423);
        $this->assertSame('policy_confirmation_required', $this->event('policy.acknowledged', 'failure')->details['reason']);
        $this->assertSame(1, ActivityLog::where('action', 'policy.acknowledged')->where('result', 'success')->count());
        foreach (['PdfContentsSecretCanary', 'PolicyBodySecretCanary', $policy->pdf_path] as $secret) {
            $this->assertStringNotContainsString($secret, ActivityLog::all()->toJson());
        }
    }

    public function test_reports_log_generation_pdf_and_excel_metadata_and_failed_requests_without_exception_contents(): void
    {
        $task = $this->task();
        $this->web($this->owner)->get('/reports?employee_id='.$this->worker->id.'&overdue_status=still_overdue')->assertOk();
        $this->assertSame(0, $this->event('report.generated')->details['selected_tasks']);
        $this->get('/reports?format=pdf&employee_id='.$this->worker->id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $this->event('report.pdf_exported');
        $this->assertSame($this->owner->id, $pdf->actor_id);
        $this->assertSame($this->store->id, $pdf->store_id);
        $this->assertSame(1, $pdf->details['selected_tasks']);
        $this->get('/reports?format=xlsx')->assertOk()->streamedContent();
        $this->event('report.xlsx_exported');
        $this->getJson('/reports?deadline_from=2026-02-30')->assertUnprocessable();
        $this->assertSame('validation_failed', $this->event('report.generated', 'failure')->details['reason']);
        $this->mock(ReportService::class)->shouldReceive('run')->once()->andThrow(new \RuntimeException('ExceptionSecretCanary'));
        $this->get('/reports')->assertStatus(500);
        $this->assertSame('operation_failed', $this->event('report.generated', 'failure')->details['reason']);
        $this->assertStringNotContainsString('ExceptionSecretCanary', ActivityLog::all()->toJson());
    }

    public function test_activity_tab_details_and_mutation_permissions_remain_web_super_admin_only(): void
    {
        $log = app(ActivityLogger::class)->write('task.edited', $this->task(), actor: $this->owner);
        $this->guest()->get('/super-admin/activity-logs/'.$log->id)->assertRedirect('/login');
        $this->get('/super-admin/activity-logs/9223372036854775806')->assertRedirect('/login');
        foreach ([$this->owner, $this->manager, $this->worker] as $user) {
            $this->web($user)->get('/super-admin/activity-logs')->assertForbidden();
            $this->get('/super-admin/activity-logs/'.$log->id)->assertForbidden();
            $this->get('/super-admin/activity-logs/9223372036854775806')->assertForbidden();
        }
        $this->web($this->platform)->get('/super-admin')->assertOk()->assertSee('Activity logs');
        $this->get('/super-admin/activity-logs')->assertOk()->assertSee('View details')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/super-admin/activity-logs/'.$log->id)->assertOk()->assertSee('Before and after')->assertSee('READ-ONLY EVENT');
        foreach (['0', '9223372036854775806', '999999999999999999999999999999'] as $missing) {
            $this->get('/super-admin/activity-logs/'.$missing)->assertNotFound();
        }
        foreach (['POST', 'PATCH', 'DELETE'] as $method) {
            $this->call($method, '/super-admin/activity-logs/'.$log->id)->assertStatus(405);
        }
        $this->mobile($this->worker)->getJson(self::API.'/super-admin/activity-logs')->assertNotFound();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $log->update(['result' => 'failure']) : $log->delete();
                $this->fail('Activity mutation must fail.');
            } catch (\LogicException) {
                $this->assertSame('success', $log->fresh()->result);
            }
        }
    }

    public function test_filters_use_snapshots_sast_end_minute_company_store_role_action_result_and_safe_search(): void
    {
        $other = Store::create(['name' => 'Second alpha branch', 'company_id' => $this->store->company_id, 'owner_id' => $this->owner->id, 'active' => true]);
        $foreign = Company::create(['name' => 'Foreign activity company']);
        $foreignManager = $this->person('manager', 'Manager Foreign', $foreign);
        $task = $this->task('Filter beer fridges');
        $logger = app(ActivityLogger::class);
        foreach (['2026-10-18 07:59:59', '2026-10-18 08:00:59', '2026-10-18 08:01:00'] as $at) {
            $this->travelTo(CarbonImmutable::parse($at, 'UTC'));
            $target = $logger->write('task.completed', $task, details: ['reason' => 'test_failure'], actor: $this->manager, result: 'failure');
        }
        $logger->write('task.completed', $task, actor: $this->owner, result: 'failure');
        $foreignTask = $this->task('Filter beer fridges', ['store_id' => $foreignManager->storeId(), 'employee_id' => $foreignManager->id]);
        $logger->write('task.completed', $foreignTask, actor: $foreignManager, result: 'failure');
        $secondTask = $this->task('Filter beer fridges', ['store_id' => $other->id]);
        $logger->write('task.completed', $secondTask, actor: $this->manager, result: 'failure');
        $filters = ['start' => '2026-10-18T10:00', 'end' => '2026-10-18T10:00', 'company_id' => $this->store->company_id, 'store_id' => $this->store->id, 'user' => strtoupper($this->manager->email), 'role' => 'manager', 'category' => 'tasks', 'action' => 'task.completed', 'result' => 'failure', 'q' => 'beer fridges'];
        $response = $this->web($this->platform)->get('/super-admin/activity-logs?'.http_build_query($filters))->assertOk();
        $logs = $response->viewData('logs');
        $this->assertSame(1, $logs->total());
        $this->assertSame('2026-10-18 08:00:59', $logs->first()->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->manager->id, $logs->first()->actor_id);
        $this->get('/super-admin/activity-logs?q='.$task->id)->assertOk()->assertViewHas('logs', fn ($rows) => $rows->total() > 0);
        $this->getJson('/super-admin/activity-logs?'.http_build_query(['company_id' => $foreign->id, 'store_id' => $this->store->id]))->assertUnprocessable();
        foreach ([['start' => 'invalid'], ['start' => '2026-10-20T10:00', 'end' => '2026-10-19T10:00'], ['role' => 'developer'], ['category' => 'secrets'], ['page' => 0]] as $invalid) {
            $this->getJson('/super-admin/activity-logs?'.http_build_query($invalid))->assertUnprocessable();
        }
    }

    public function test_pagination_html_escaping_and_reading_events_do_not_create_more_events(): void
    {
        $logger = app(ActivityLogger::class);
        $task = $this->task('<script>LabelCanary</script>');
        for ($i = 0; $i < 28; $i++) {
            $logger->write('task.edited', $task, actor: $this->manager);
        }
        $count = ActivityLog::count();
        $response = $this->web($this->platform)->get('/super-admin/activity-logs?category=tasks&role=manager')->assertOk();
        $this->assertCount(25, $response->viewData('logs'));
        $this->assertSame(28, $response->viewData('logs')->total());
        $response->assertSee('category=tasks')->assertSee('role=manager')->assertSee('&lt;script&gt;LabelCanary&lt;/script&gt;', false)->assertDontSee('<script>LabelCanary</script>', false);
        $this->get('/super-admin/activity-logs?category=tasks&role=manager&page=2')->assertOk()->assertViewHas('logs', fn ($rows) => $rows->count() === 3);
        $this->get('/super-admin/activity-logs/'.$this->event('task.edited')->id)->assertOk()->assertDontSee('<script>LabelCanary</script>', false);
        $this->assertSame($count, ActivityLog::count());
    }

    public function test_rolled_back_transitions_do_not_leave_success_logs_and_invalid_actions_record_failure(): void
    {
        $task = $this->task('Transactional audit', ['required_evidence' => ['photo']]);
        try {
            DB::transaction(function () use ($task) {
                app(TaskWorkflow::class)->start($task, $this->worker);
                throw new \RuntimeException('Rollback test');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollback test', $error->getMessage());
        }
        $this->assertSame('assigned', $task->fresh()->status);
        $this->assertSame(0, ActivityLog::where('action', 'task.started')->count());
        $this->mobile($this->worker)->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'complete', 'note' => 'Missing photo'])->assertUnprocessable();
        $this->assertSame(0, ActivityLog::where('action', 'task.completed')->where('result', 'success')->count());
        $this->assertSame(1, ActivityLog::where('action', 'task.completed')->where('result', 'failure')->count());
    }

    public function test_historical_import_is_safe_idempotent_and_does_not_invent_missing_events_or_roles(): void
    {
        $task = $this->task('Historical task');
        $audit = AccountAudit::withoutEvents(fn () => AccountAudit::create(['actor_id' => $this->owner->id, 'subject_id' => $this->worker->id, 'store_id' => $this->store->id, 'company_id' => $this->store->company_id, 'action' => 'updated', 'data' => ['before' => ['name' => 'Before historical name', 'password' => 'HistoricalPasswordCanary'], 'after' => ['name' => 'After historical name', 'access_token' => 'HistoricalTokenCanary']], 'created_at' => '2026-10-01 08:00:00', 'updated_at' => '2026-10-01 08:00:00']));
        $activity = TaskActivity::withoutEvents(fn () => TaskActivity::create(['task_id' => $task->id, 'user_id' => $this->worker->id, 'type' => 'completed', 'body' => 'HistoricalFileContentsCanary', 'data' => ['password' => 'HistoricalPasswordCanary', 'access_token' => 'HistoricalTokenCanary'], 'created_at' => '2026-10-02 08:00:00', 'updated_at' => '2026-10-02 08:00:00']));
        $sourceBefore = [$audit->fresh()->toArray(), $activity->fresh()->toArray()];
        $this->artisan('tasksure:import-activity-history')->assertSuccessful();
        $this->assertSame(2, ActivityLog::count());
        $this->artisan('tasksure:import-activity-history')->assertSuccessful();
        $this->assertSame(2, ActivityLog::count());
        $this->assertSame($sourceBefore, [$audit->fresh()->toArray(), $activity->fresh()->toArray()]);
        foreach (ActivityLog::all() as $log) {
            $this->assertSame('history', $log->source);
            $this->assertNull($log->actor_role);
            $this->assertSame('historical', $log->attribution);
        }
        $historical = $this->event('account.updated');
        $this->assertSame('Before historical name', $historical->before_values['name']);
        $this->assertSame('2026-10-01 08:00:00', $historical->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, ActivityLog::where('action', 'account.login')->count());
        foreach (['HistoricalPasswordCanary', 'HistoricalTokenCanary', 'HistoricalFileContentsCanary'] as $secret) {
            $this->assertStringNotContainsString($secret, ActivityLog::all()->toJson());
        }
        // A current tracked event has the same origin key, so import never duplicates it.
        $this->web($this->worker)->post('/tasks/'.$task->id.'/action', ['action' => 'start'])->assertRedirect();
        $this->artisan('tasksure:import-activity-history')->assertSuccessful();
        $this->assertSame(3, ActivityLog::count());
    }

    public function test_scheduler_attribution_and_super_admin_role_promotion_are_explicit(): void
    {
        $task = $this->task();
        TaskTemplate::create($task->only(['store_id', 'employee_id', 'creator_id', 'category_id', 'title', 'instructions', 'area', 'priority', 'required_evidence']) + ['frequency' => 'daily', 'next_date' => '2026-10-18', 'due_time' => '17:00', 'active' => true]);
        $this->assertSame(1, app(ScheduledTasks::class)->occurrences());
        $generated = $this->event('task.created');
        $this->assertNull($generated->actor_id);
        $this->assertSame('system', $generated->attribution);
        $this->assertSame('scheduler', $generated->source);
        $this->artisan('tasksure:super-admin', ['email' => $this->owner->email, '--promote' => true])->expectsConfirmation('Promotion removes normal store task access and clears this account as assigned store owner. Continue?', 'yes')->assertSuccessful();
        $role = $this->event('account.role_changed');
        $this->assertSame('admin', $role->before_values['role']);
        $this->assertSame('super_admin', $role->after_values['role']);
        $this->assertNull($role->actor_id);
        $this->assertSame($this->owner->id, $this->event('store.owner_assigned')->before_values['owner_id']);
        $this->assertNull($this->event('store.owner_assigned')->after_values['owner_id']);
    }

    public function test_unauthenticated_requests_and_throttled_signins_keep_unknown_identity_separate_from_system(): void
    {
        $task = $this->task();
        $this->guest()->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'complete', 'note' => 'SecretUnauthenticatedNote'])->assertUnauthorized();
        $failed = $this->event('task.completed', 'failure');
        $this->assertSame('unknown', $failed->attribution);
        $this->assertSame('Unauthenticated request', $failed->actor_name);
        $this->assertNull($failed->actor_id);
        for ($i = 0; $i < 6; $i++) {
            $response = $this->guest()->postJson(self::API.'/login', ['email' => $this->worker->email, 'password' => 'ThrottledPasswordCanary', 'device_name' => 'Phone']);
            $i < 5 ? $response->assertUnprocessable() : $response->assertStatus(429);
        }
        $this->assertSame(6, ActivityLog::where('action', 'account.login')->where('result', 'failure')->count());
        $this->assertSame('rate_limited', $this->event('account.login', 'failure')->details['reason']);
        $this->assertStringNotContainsString('ThrottledPasswordCanary', ActivityLog::all()->toJson());
        $this->assertStringNotContainsString('SecretUnauthenticatedNote', ActivityLog::all()->toJson());
    }

    public function test_history_preserves_known_acknowledgement_metadata_and_skips_unknown_timestamps(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 08:00:00', 'UTC'));
        $service = app(CompanyPolicyService::class);
        $entry = $service->createDraft($this->owner, 'Historical policy', 'Historical policy body');
        $policy = $service->publish($this->owner, $entry->draft_revision);
        $ack = CompanyPolicyAcknowledgement::withoutEvents(fn () => CompanyPolicyAcknowledgement::create(['company_id' => $policy->company_id, 'user_id' => $this->worker->id, 'policy_version_id' => $policy->id, 'acknowledged_at' => '2026-10-11 08:00:00']));
        AccountAudit::withoutEvents(function () {
            $audit = new AccountAudit(['actor_id' => $this->owner->id, 'subject_id' => $this->worker->id, 'action' => 'created']);
            $audit->timestamps = false;
            $audit->save();
        });
        $count = ActivityLog::count();
        $this->artisan('tasksure:import-activity-history')->assertSuccessful();
        $this->assertSame($count + 1, ActivityLog::count());
        $log = $this->event('policy.acknowledged');
        $this->assertSame('history', $log->source);
        $this->assertNull($log->actor_role);
        $this->assertSame($policy->id, $log->after_values['policy_version_id']);
        $this->assertSame($this->worker->id, $log->after_values['user_id']);
        $this->assertSame('2026-10-11 08:00:00', $log->occurred_at->format('Y-m-d H:i:s'));
        $this->artisan('tasksure:import-activity-history')->assertSuccessful();
        $this->assertSame($count + 1, ActivityLog::count());
        $this->assertSame($ack->acknowledged_at->toIso8601String(), $ack->fresh()->acknowledged_at->toIso8601String());
    }

    public function test_successful_redirects_and_repeated_policy_confirmation_do_not_create_false_failures(): void
    {
        $service = app(CompanyPolicyService::class);
        $this->web($this->owner);
        $one = $service->createDraft($this->owner, 'First required policy', 'First content');
        $first = $service->publish($this->owner, $one->draft_revision, $one->id);
        $two = $service->createDraft($this->owner, 'Second required policy', 'Second content');
        $second = $service->publish($this->owner, $two->draft_revision, $two->id);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertSame(0, ActivityLog::where('action', 'account.logout')->where('result', 'failure')->count());
        $this->guest()->withSession(['url.intended' => '/company-policy'])->post('/login', ['email' => $this->worker->email, 'password' => self::PASSWORD])->assertRedirect('/company-policy');
        $this->assertSame(0, ActivityLog::where('action', 'account.login')->where('result', 'failure')->count());
        foreach ([1, 2] as $_) {
            $this->post('/company-policy/acknowledge', ['policy_version_id' => $first->id, 'acknowledged' => true])->assertRedirect('/company-policy');
        }
        $this->assertSame(1, ActivityLog::where('action', 'policy.acknowledged')->where('result', 'success')->count());
        $this->assertSame(0, ActivityLog::where('action', 'policy.acknowledged')->where('result', 'failure')->count());
        $this->post('/tasks/'.$this->task()->id.'/action', ['action' => 'start'])->assertRedirect('/company-policy');
        $this->assertSame('policy_confirmation_required', $this->event('task.started', 'failure')->details['reason']);
        $this->assertSame(0, ActivityLog::where('action', 'task.started')->where('result', 'success')->count());
    }

    public function test_activity_history_migration_is_forward_only(): void
    {
        $task = $this->task();
        $before = [$this->owner->fresh()->toArray(), $task->fresh()->toArray()];
        $migration = require database_path('migrations/2026_10_18_000001_create_activity_logs.php');
        $this->assertSame($before, [$this->owner->fresh()->toArray(), $task->fresh()->toArray()]);
        $this->assertSame(0, ActivityLog::count());
        try {
            $migration->down();
            $this->fail('Activity history cannot be dropped by rollback.');
        } catch (\RuntimeException) {
            $this->assertTrue(Schema::hasTable('activity_logs'));
        }
    }

    public function test_a_successful_retry_does_not_inherit_a_previous_validation_failure(): void
    {
        $this->web($this->owner)->post('/admin/reminders', ['reminder_minutes' => 'invalid'])->assertSessionHasErrors('reminder_minutes');
        $this->post('/admin/reminders', ['reminder_minutes' => 60])->assertRedirect();
        $this->assertSame(1, ActivityLog::where('action', 'settings.reminders_changed')->where('result', 'success')->count());
        $this->assertSame(1, ActivityLog::where('action', 'settings.reminders_changed')->where('result', 'failure')->count());
        $this->assertSame(60, $this->event('settings.reminders_changed')->after_values['reminder_minutes']);
    }

    public function test_mobile_login_does_not_leave_a_token_when_success_auditing_fails(): void
    {
        $logger = $this->mock(ActivityLogger::class);
        $logger->shouldReceive('write')->andThrow(new \RuntimeException('Simulated activity storage failure'));
        $logger->shouldReceive('loginFailure')->andReturnNull();
        $this->guest()->postJson(self::API.'/login', ['email' => $this->worker->email, 'password' => self::PASSWORD, 'device_name' => 'Audit outage test'])->assertStatus(500);
        $this->assertSame(0, $this->worker->tokens()->count());
        $this->assertSame(0, ActivityLog::where('action', 'account.login')->where('result', 'success')->count());
    }

    public function test_super_admin_setup_rolls_back_the_new_account_when_auditing_fails(): void
    {
        $this->mock(ExistingActivityRecorder::class)->shouldReceive('account')->andThrow(new \RuntimeException('Simulated activity storage failure'));
        try {
            $this->artisan('tasksure:super-admin', ['email' => 'atomic-platform@activity.test', 'name' => 'Atomic platform operator'])
                ->expectsQuestion('New password (12+ characters, mixed case and number)', self::PASSWORD)
                ->expectsQuestion('Confirm password', self::PASSWORD)->run();
            $this->fail('The simulated audit failure should fail setup.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated activity storage failure', $error->getMessage());
        }
        $this->assertDatabaseMissing('users', ['email' => 'atomic-platform@activity.test']);
        $this->assertSame(0, ActivityLog::where('action', 'account.created')->count());
    }
}
