<?php

namespace Tests\Feature;

use App\Jobs\SendAlertPush;
use App\Models\Alert;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use App\Services\AlertService;
use App\Services\ReportService;
use App\Services\ScheduledTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompletionReviewTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/mobile/v1';

    private User $owner;

    private User $manager;

    private User $worker;

    private User $teamManager;

    private User $outsider;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->person('admin');
        $this->manager = $this->person('manager');
        $this->worker = $this->person('employee');
        $this->teamManager = $this->person('manager');
        $this->outsider = $this->person('employee');
        $this->manager->employees()->attach([$this->worker->id, $this->teamManager->id]);
        $this->category = Category::create(['name' => 'Opening']);
        Storage::fake('evidence');
    }

    private function person(string $role): User
    {
        return User::create(['name' => $role.' '.uniqid(), 'email' => uniqid().'@review.test', 'password' => 'ExistingAccount123!', 'role' => $role, 'active' => true]);
    }

    private function task(?User $assignee = null, array $extra = []): Task
    {
        return Task::create($extra + ['title' => 'Check shelves '.uniqid(), 'instructions' => 'Check every shelf.', 'area' => 'Wine', 'priority' => 'high', 'required_evidence' => ['note'], 'employee_id' => ($assignee ?? $this->worker)->id, 'creator_id' => $this->owner->id, 'category_id' => $this->category->id, 'due_at' => now()->addHour()]);
    }

    private function signIn(User $user): string
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $response = $this->postJson(self::API.'/login', ['email' => $user->email, 'password' => 'ExistingAccount123!', 'device_name' => 'Physical phone fixture'])->assertOk()->assertJsonPath('user.role', $user->role);
        $token = $response->json('token');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token);

        return $token;
    }

    private function action(Task $task, string $action, array $data = [])
    {
        return $this->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => $action] + $data);
    }

    public function test_existing_manager_accounts_have_separate_personal_and_scoped_team_views(): void
    {
        $mine = $this->task($this->manager);
        $team = $this->task($this->worker);
        $peer = $this->task($this->teamManager);
        $private = $this->task($this->outsider);
        $this->signIn($this->manager);
        $this->getJson(self::API.'/tasks?scope=team')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson(self::API.'/team/tasks')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(self::API.'/calendar?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', (string) $mine->id);
        $this->getJson(self::API.'/tasks/'.$team->id)->assertOk()->assertJsonPath('data.permissions.work', false)->assertJsonPath('data.permissions.review', true);
        $this->getJson(self::API.'/tasks/'.$peer->id)->assertOk()->assertJsonPath('data.assignee.role', 'manager');
        $this->getJson(self::API.'/tasks/'.$private->id)->assertForbidden();
        $this->action($team, 'complete', ['note' => 'Cannot do team work as this user'])->assertForbidden();
        $this->action($mine, 'start')->assertOk();
        $this->action($mine, 'complete', ['note' => 'Manager completed personal work'])->assertOk();
        $this->assertEquals($this->manager->id, $mine->fresh()->completed_by_id);
        $this->action($mine, 'mark_reviewed')->assertForbidden();
        $this->postJson(self::API.'/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::API.'/me')->assertUnauthorized();
    }

    public function test_assignment_options_and_posts_allow_both_roles_but_respect_scope_and_activity(): void
    {
        $inactive = $this->person('manager');
        $inactive->update(['active' => false]);
        $this->manager->employees()->attach($inactive);
        $this->signIn($this->manager);
        $this->getJson(self::API.'/team/options')->assertOk()->assertJsonCount(3, 'assignees');
        $body = ['title' => 'Team task', 'instructions' => 'Read instructions', 'area' => 'Wine', 'category_id' => $this->category->id, 'priority' => 'normal', 'required_evidence' => ['note'], 'due_at' => '2026-10-20T17:00'];
        foreach ([$this->worker, $this->teamManager, $this->manager] as $person) {
            $response = $this->postJson(self::API.'/team/tasks', $body + ['employee_id' => $person->id])->assertCreated();
            $this->assertDatabaseHas('tasks', ['id' => $response->json('data.id'), 'employee_id' => $person->id, 'due_at' => '2026-10-20 15:00:00']);
            $this->assertDatabaseHas('alerts', ['task_id' => $response->json('data.id'), 'user_id' => $person->id, 'type' => 'assigned']);
        }
        foreach ([$this->outsider, $inactive, $this->owner] as $person) {
            $this->postJson(self::API.'/team/tasks', $body + ['employee_id' => $person->id])->assertForbidden();
        }
        $this->flushHeaders();
        $this->actingAs($this->owner)->get('/tasks/create')->assertOk()->assertSee($this->teamManager->name)->assertSee('Manager');
    }

    public function test_workers_cannot_gain_management_access_by_calling_mobile_endpoints(): void
    {
        $mine = $this->task();
        $private = $this->task($this->outsider);
        $this->signIn($this->worker);
        foreach (['/team/tasks', '/team/options', '/reviews'] as $path) {
            $this->getJson(self::API.$path)->assertForbidden();
        }
        $this->postJson(self::API.'/team/tasks', [])->assertForbidden();
        foreach (['mark_reviewed', 'reopen', 'reassign', 'cancel', 'reschedule'] as $action) {
            $this->action($mine, $action, ['reason' => 'Forged request'])->assertForbidden();
        }
        $this->action($private, 'start')->assertForbidden();
        $this->post(self::API.'/tasks/'.$private->id.'/uploads', ['file' => UploadedFile::fake()->image('private.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->getJson(self::API.'/tasks?scope=team&employee_id='.$this->outsider->id)->assertJsonCount(0, 'data');
    }

    public function test_completion_is_immediate_and_server_owned_without_a_review_step(): void
    {
        $task = $this->task(null, ['required_evidence' => ['photo', 'note']]);
        $this->signIn($this->worker);
        $this->action($task, 'start')->assertOk();
        $this->action($task, 'complete', ['note' => '   '])->assertUnprocessable();
        $this->post(self::API.'/tasks/'.$task->id.'/uploads', ['file' => UploadedFile::fake()->image('shelf.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->action($task, 'complete', ['note' => 'Shelf checked', 'completed_at' => '2000-01-01', 'completed_by_id' => $this->owner->id, 'review_status' => 'reviewed'])->assertOk();
        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame('not_reviewed', $task->review_status);
        $this->assertSame($this->worker->id, $task->completed_by_id);
        $this->assertTrue($task->completed_at->between(now()->subMinute(), now()));
        $this->assertNull($task->reviewed_at);
        $this->assertNull($task->approved_at);
        $this->assertFalse($task->overdue());
        $this->assertCount(1, $task->submissions);
        $this->assertTrue($task->submissions->first()->started_at->equalTo($task->started_at));
        $this->action($task, 'complete', ['note' => 'Duplicate'])->assertForbidden();
        $this->action($task, 'approve')->assertUnprocessable();
        $this->getJson(self::API.'/tasks?open=1')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('alerts', ['task_id' => $task->id, 'user_id' => $this->manager->id, 'type' => 'completed']);
    }

    public function test_optional_review_records_actor_and_time_without_changing_completion(): void
    {
        $task = $this->task();
        $this->signIn($this->worker);
        $this->action($task, 'complete', ['note' => 'Done'])->assertOk();
        $completedAt = $task->fresh()->completed_at;
        $this->travel(2)->hours();
        $this->signIn($this->manager);
        $this->action($task, 'mark_reviewed', ['reason' => 'Inspected', 'reviewer_id' => $this->owner->id])->assertOk();
        $reviewedAt = $task->fresh()->reviewed_at;
        $this->travel(1)->hour();
        $this->action($task, 'mark_reviewed')->assertOk();
        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame('reviewed', $task->review_status);
        $this->assertSame($this->manager->id, $task->reviewer_id);
        $this->assertTrue($task->completed_at->equalTo($completedAt));
        $this->assertTrue($task->reviewed_at->equalTo($reviewedAt));
        $this->assertCount(1, $task->reviews);
        $this->assertSame('reviewed', $task->reviews->first()->action);
        $this->assertDatabaseHas('alerts', ['task_id' => $task->id, 'user_id' => $this->worker->id, 'type' => 'reviewed']);
        $summary = collect(app(ReportService::class)->run($this->owner, [])['summary'])->firstWhere('employee', $this->worker->name);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(1, $summary['reviewed']);
        $this->assertSame(0, $summary['not_reviewed']);
    }

    public function test_reopening_preserves_evidence_reviews_and_deadlines_and_requires_fresh_completion(): void
    {
        $task = $this->task(null, ['required_evidence' => ['photo', 'note']]);
        $this->signIn($this->worker);
        $this->post(self::API.'/tasks/'.$task->id.'/uploads', ['file' => UploadedFile::fake()->image('first.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->action($task, 'complete', ['note' => 'First completion'])->assertOk();
        $first = $task->submissions()->firstOrFail();
        $original = $first->getRawOriginal();
        $this->signIn($this->manager);
        $this->action($task, 'mark_reviewed')->assertOk();
        $this->action($task, 'reopen')->assertUnprocessable();
        $this->action($task, 'reopen', ['reason' => 'Photograph the lower shelf'])->assertOk();
        $task->refresh();
        $this->assertSame('assigned', $task->status);
        $this->assertNull($task->completed_at);
        $this->assertNull($task->reviewed_at);
        $this->assertNull($task->reviewer_id);
        $this->assertSame('not_reviewed', $task->review_status);
        $this->assertDatabaseHas('alerts', ['task_id' => $task->id, 'user_id' => $this->worker->id, 'type' => 'reopened']);
        $this->signIn($this->worker);
        $this->action($task, 'complete', ['note' => 'Reuse old photo'])->assertUnprocessable();
        $this->action($task, 'start')->assertOk();
        $this->post(self::API.'/tasks/'.$task->id.'/uploads', ['file' => UploadedFile::fake()->image('second.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->action($task, 'complete', ['note' => 'Lower shelf checked'])->assertOk();
        $this->assertSame($original, $first->fresh()->getRawOriginal());
        $this->assertCount(1, $first->attachments);
        Storage::disk('evidence')->assertExists($first->attachments->first()->path);
        $this->getJson(self::API.'/tasks/'.$task->id)->assertJsonCount(2, 'data.submissions')->assertJsonCount(2, 'data.reviews')->assertJsonPath('data.status', 'completed')->assertJsonPath('data.review_status', 'not_reviewed')->assertJsonPath('data.reviews.1.note', 'Photograph the lower shelf');
    }

    public function test_review_filters_use_completion_date_in_sast_and_exclude_unauthorised_work(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(22, 30));
        $task = $this->task();
        $other = $this->task($this->outsider);
        $this->signIn($this->worker);
        $this->action($task, 'complete', ['note' => 'After midnight SAST'])->assertOk();
        $this->signIn($this->manager);
        $this->getJson(self::API.'/reviews?from=2026-10-15&to=2026-10-15&review_state=not_reviewed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $task->id);
        $this->getJson(self::API.'/reviews?from=2026-10-14&to=2026-10-14')->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/reviews?employee_id='.$this->outsider->id)->assertJsonCount(0, 'data');
        $this->action($other, 'reopen', ['reason' => 'Outside scope'])->assertForbidden();
        $this->action($task, 'mark_reviewed')->assertOk();
        $this->getJson(self::API.'/reviews?review_state=not_reviewed')->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/reviews?review_state=reviewed')->assertJsonCount(1, 'data');
        $this->flushHeaders();
        $this->actingAs($this->manager)->get('/reviews?from=2026-10-15&to=2026-10-15&review_state=reviewed')->assertOk()->assertSee($task->title)->assertDontSee($other->title);
        $this->get('/tasks/'.$task->id)->assertOk()->assertSee('Reopen task')->assertDontSee('Approve work');
        $this->actingAs($this->worker)->get('/reviews')->assertForbidden();
    }

    public function test_completed_work_does_not_generate_overdue_alerts_and_manager_alerts_queue_push(): void
    {
        config(['mobile.push_enabled' => true]);
        Bus::fake([SendAlertPush::class]);
        $task = $this->task($this->manager, ['due_at' => now()->subHour()]);
        $this->signIn($this->manager);
        $this->action($task, 'complete', ['note' => 'Manager work complete'])->assertOk();
        $before = Alert::count();
        app(ScheduledTasks::class)->reminders();
        $this->assertSame($before, Alert::count());
        app(AlertService::class)->send($this->manager, $task, 'assigned', 'New manager task', 'manager-push-test');
        Bus::assertDispatched(SendAlertPush::class, fn ($job) => $job->alertId === Alert::where('dedupe_key', 'manager-push-test')->value('id'));
    }

    public function test_old_employee_tokens_and_complete_action_alias_remain_usable_during_apk_rollout(): void
    {
        $task = $this->task();
        $token = $this->worker->createToken('Existing APK', ['employee'], now()->addDay())->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'submit', 'note' => 'Existing app'])->assertOk();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('not_reviewed', $task->fresh()->review_status);
    }

    public function test_legacy_migration_preserves_attempts_and_handles_all_original_statuses(): void
    {
        $assigned = $this->task(null, ['status' => 'assigned']);
        $progress = $this->task(null, ['status' => 'in_progress']);
        $correction = $this->task(null, ['status' => 'changes_requested']);
        $cancelled = $this->task(null, ['status' => 'cancelled', 'cancellation_reason' => 'Historical cancellation']);
        $submitted = $this->task(null, ['status' => 'submitted']);
        $approved = $this->task(null, ['status' => 'approved', 'approved_at' => now()->subDay()]);
        $missing = $this->task(null, ['status' => 'approved']);
        $timestampOnly = $this->task(null, ['status' => 'approved', 'approved_at' => now()->subDays(2)]);
        foreach ([$correction, $submitted, $approved] as $task) {
            $task->submissions()->create(['employee_id' => $this->worker->id, 'note' => 'Historical note', 'submitted_at' => now()->subDays(3), 'due_at' => now()->subDays(2), 'review_status' => $task->status === 'submitted' ? 'pending' : ($task->status === 'approved' ? 'approved' : 'changes_requested'), 'reviewer_id' => $task->status === 'submitted' ? null : $this->manager->id, 'reviewed_at' => $task->status === 'submitted' ? null : now()->subDay(), 'review_reason' => 'Original review note']);
        }
        $snapshots = DB::table('submissions')->orderBy('id')->get()->toJson();
        $legacyFile = $approved->attachments()->create(['submission_id' => $approved->submissions()->first()->id, 'user_id' => $this->worker->id, 'path' => 'legacy/photo.jpg', 'original_name' => 'Original.jpg', 'mime' => 'image/jpeg', 'kind' => 'photo', 'size' => 100]);
        Storage::disk('evidence')->put($legacyFile->path, 'Original evidence bytes');
        $legacyEvent = $approved->activities()->create(['user_id' => $this->manager->id, 'type' => 'approved', 'body' => 'Original approval event', 'created_at' => now()->subDay()]);
        $originalEvent = $legacyEvent->fresh()->getRawOriginal();
        $originalUpdated = $approved->updated_at;
        $migration = require database_path('migrations/2026_10_14_000001_separate_completion_from_review.php');
        $migration->migrateLegacyTasks();
        $this->assertSame('assigned', $assigned->fresh()->status);
        $this->assertSame('in_progress', $progress->fresh()->status);
        $this->assertSame('assigned', $correction->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame('Historical cancellation', $cancelled->fresh()->cancellation_reason);
        $this->assertSame('completed', $submitted->fresh()->status);
        $this->assertSame('not_reviewed', $submitted->fresh()->review_status);
        $this->assertTrue($submitted->fresh()->completed_at->equalTo($submitted->submissions()->first()->submitted_at));
        $this->assertSame('reviewed', $approved->fresh()->review_status);
        $this->assertSame($this->manager->id, $approved->fresh()->reviewer_id);
        $this->assertTrue($approved->fresh()->updated_at->equalTo($originalUpdated));
        $this->assertNotNull($approved->fresh()->approved_at);
        $this->assertSame('completed', $missing->fresh()->status);
        $this->assertSame('not_reviewed', $missing->fresh()->review_status);
        $this->assertNull($missing->fresh()->completed_at);
        $this->assertNull($missing->fresh()->completed_by_id);
        $this->assertNull($missing->fresh()->reviewer_id);
        $this->assertSame('reviewed', $timestampOnly->fresh()->review_status);
        $this->assertNull($timestampOnly->fresh()->reviewer_id);
        $this->assertTrue($timestampOnly->fresh()->reviewed_at->equalTo($timestampOnly->approved_at));
        $this->assertSame($snapshots, DB::table('submissions')->orderBy('id')->get()->toJson());
        $this->assertSame($originalEvent, $legacyEvent->fresh()->getRawOriginal());
        $this->assertSame('Original evidence bytes', Storage::disk('evidence')->get($legacyFile->path));
        $this->assertDatabaseHas('attachments', ['id' => $legacyFile->id, 'submission_id' => $approved->submissions()->first()->id]);
        $reviews = DB::table('task_reviews')->count();
        $migration->migrateLegacyTasks();
        $this->assertSame($reviews, DB::table('task_reviews')->count());
    }

    public function test_unknown_legacy_status_stops_migration_before_any_changes(): void
    {
        $task = $this->task(null, ['status' => 'custom_legacy_state']);
        $migration = require database_path('migrations/2026_10_14_000001_separate_completion_from_review.php');
        try {
            $migration->up();
            $this->fail('Unknown statuses must require a deliberate mapping.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unrecognised task statuses', $exception->getMessage());
        }
        $this->assertSame('custom_legacy_state', $task->fresh()->status);
        $this->assertDatabaseCount('task_reviews', 0);
    }
}
