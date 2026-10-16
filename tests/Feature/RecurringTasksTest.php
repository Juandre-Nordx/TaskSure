<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\CompanyPolicyService;
use App\Services\ScheduledTasks;
use App\Services\TaskWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecurringTasksTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/mobile/v1';

    private User $owner;

    private User $manager;

    private User $employee;

    private User $outsider;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));
        $this->owner = $this->person('admin');
        $this->manager = $this->person('manager');
        $this->employee = $this->person('employee');
        $this->outsider = $this->person('employee');
        $this->manager->employees()->attach($this->employee);
        $this->category = Category::create(['name' => 'Routines']);
        Storage::fake('evidence');
    }

    private function person(string $role, ?Company $company = null): User
    {
        return User::create(['name' => $role.' '.uniqid(), 'email' => uniqid().'@recurrence.test', 'password' => 'RoutinePassword123!', 'role' => $role, 'active' => true, 'company_id' => $company?->id]);
    }

    private function fields(array $extra = []): array
    {
        return $extra + ['title' => 'Restock daily', 'instructions' => 'Restock and photograph the shelves.', 'employee_id' => $this->employee->id, 'category_id' => $this->category->id, 'area' => 'Beer', 'priority' => 'high', 'required_evidence' => ['photo', 'note'], 'due_at' => '2026-10-15T17:00', 'scheduled_at' => '2026-10-15T09:00', 'recurrence' => 'daily'];
    }

    private function createSeries(?User $actor = null, array $extra = []): Task
    {
        $this->actingAs($actor ?? $this->owner)->post('/tasks', $this->fields($extra))->assertRedirect()->assertSessionHasNoErrors();

        return Task::latest('id')->firstOrFail();
    }

    private function mobile(User $person): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$person->createToken('Phone', ['mobile', $person->role])->plainTextToken);
    }

    public function test_owner_and_scoped_manager_can_assign_a_recurring_task_with_an_immediate_first_occurrence(): void
    {
        foreach ([$this->owner, $this->manager] as $actor) {
            $task = $this->createSeries($actor);
            $plan = $task->template;
            $this->assertSame($actor->id, $plan->creator_id);
            $this->assertSame($this->employee->id, $plan->employee_id);
            $this->assertSame('2026-10-15', $task->occurrence_date);
            $this->assertSame('2026-10-16', $plan->next_date->toDateString());
            $this->assertSame('2026-10-15 15:00', $task->due_at->format('Y-m-d H:i'));
            $this->assertSame('2026-10-15 07:00', $task->scheduled_at->format('Y-m-d H:i'));
            $this->assertSame(480, $plan->scheduled_offset_minutes);
            $this->assertSame(1, $plan->tasks()->count());
            $this->get('/tasks/'.$task->id)->assertOk()->assertSee('Recurring task')->assertSee('Pause series');
            $this->get('/recurring-tasks')->assertOk()->assertSee($task->title);
            $this->assertDatabaseHas('alerts', ['task_id' => $task->id, 'user_id' => $this->employee->id, 'type' => 'assigned']);
        }
    }

    public function test_one_off_assignment_remains_compatible_with_existing_clients_and_past_deadlines(): void
    {
        $fields = $this->fields(['due_at' => '2026-10-14T17:00', 'scheduled_at' => null]);
        unset($fields['recurrence']);
        $this->actingAs($this->owner)->post('/tasks', $fields)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_templates', 0);
        $this->assertNull(Task::firstOrFail()->task_template_id);
    }

    public function test_daily_weekly_and_monthly_options_advance_from_the_first_deadline(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 31));
        foreach (['daily' => '2026-02-01', 'weekly' => '2026-02-07', 'monthly' => '2026-02-28'] as $frequency => $next) {
            $task = $this->createSeries(null, ['recurrence' => $frequency, 'due_at' => '2026-01-31T17:00', 'scheduled_at' => null]);
            $this->assertSame($next, $task->template->next_date->toDateString());
            $this->assertSame(31, $task->template->anchor_day);
        }
    }

    public function test_retried_generation_never_duplicates_the_first_task_or_its_notifications(): void
    {
        $task = $this->createSeries();
        $plan = $task->template;
        $plan->update(['next_date' => '2026-10-15']);
        $scheduler = app(ScheduledTasks::class);
        $this->assertSame(0, $scheduler->occurrences());
        $this->assertSame(1, Alert::where('type', 'assigned')->count());
        $this->travel(1)->days();
        $this->assertSame(1, $scheduler->occurrences());
        $this->assertSame(0, $scheduler->occurrences());
        $this->assertSame(2, $plan->tasks()->count());
        $this->assertSame(2, Alert::where('type', 'assigned')->count());
        $next = $plan->tasks()->where('id', '!=', $task->id)->firstOrFail();
        $this->assertSame('2026-10-16 07:00', $next->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-16 15:00', $next->due_at->format('Y-m-d H:i'));
        $this->assertSame(['photo', 'note'], $next->required_evidence);
    }

    public function test_end_date_is_inclusive_and_completed_instances_keep_their_proof_and_deadlines(): void
    {
        $task = $this->createSeries(null, ['recurrence_until' => '2026-10-16']);
        Storage::disk('evidence')->put('original.jpg', 'Original private evidence');
        Attachment::create(['task_id' => $task->id, 'user_id' => $this->employee->id, 'path' => 'original.jpg', 'original_name' => 'original.jpg', 'mime' => 'image/jpeg', 'size' => 25, 'kind' => 'photo']);
        app(TaskWorkflow::class)->complete($task, $this->employee, 'Original completion');
        app(TaskWorkflow::class)->markReviewed($task, $this->owner, 'Original review');
        $deadline = $task->fresh()->due_at->toIso8601String();
        $plan = $task->template;
        $this->travel(1)->days();
        $this->assertSame(1, app(ScheduledTasks::class)->occurrences());
        $next = $plan->tasks()->where('id', '!=', $task->id)->firstOrFail();
        $this->assertSame('assigned', $next->status);
        $this->assertSame('not_reviewed', $next->review_status);
        $this->assertSame(0, $next->attachments()->count());
        $this->assertSame(0, $next->submissions()->count());
        $this->assertFalse($plan->fresh()->active);
        $this->travel(7)->days();
        $this->assertSame(0, app(ScheduledTasks::class)->occurrences());
        $this->assertSame(2, $plan->tasks()->count());
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame($deadline, $task->fresh()->due_at->toIso8601String());
        $this->assertSame(1, $task->submissions()->count());
        $this->assertSame('Original private evidence', Storage::disk('evidence')->get('original.jpg'));
    }

    public function test_overnight_occurrence_is_generated_when_its_scheduled_start_arrives(): void
    {
        $task = $this->createSeries(null, ['scheduled_at' => '2026-10-14T18:00', 'due_at' => '2026-10-15T06:00']);
        $this->travelTo(now()->setTime(15, 59));
        $this->assertSame(0, app(ScheduledTasks::class)->occurrences());
        $this->travel(1)->minutes();
        $this->assertSame(1, app(ScheduledTasks::class)->occurrences());
        $next = $task->template->tasks()->latest('id')->firstOrFail();
        $this->assertSame('2026-10-15 16:00', $next->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-16 04:00', $next->due_at->format('Y-m-d H:i'));
    }

    public function test_occurrences_follow_the_sast_day_boundary_even_while_utc_is_still_yesterday(): void
    {
        $this->createSeries(null, ['scheduled_at' => null]);
        $this->travelTo(now()->setTime(21, 59));
        $this->assertSame(0, app(ScheduledTasks::class)->occurrences());
        $this->travel(1)->minutes();
        $this->assertSame(1, app(ScheduledTasks::class)->occurrences());
        $this->assertSame('2026-10-16', Task::latest('id')->firstOrFail()->occurrence_date);
    }

    public function test_owner_and_creator_can_pause_and_resume_without_changing_existing_tasks(): void
    {
        $task = $this->createSeries($this->manager);
        $plan = $task->template;
        $this->post('/recurring-tasks/'.$plan->id.'/status', ['active' => 0])->assertRedirect();
        $this->travel(2)->days();
        $this->assertSame(0, app(ScheduledTasks::class)->occurrences());
        $this->assertSame(1, $plan->tasks()->count());
        $this->actingAs($this->owner)->post('/recurring-tasks/'.$plan->id.'/status', ['active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, $plan->tasks()->count());
        $this->assertSame('assigned', $task->fresh()->status);
        $this->assertSame('2026-10-15 15:00', $task->fresh()->due_at->format('Y-m-d H:i'));
    }

    public function test_generation_stops_for_revoked_scope_inactive_accounts_and_removed_store_membership(): void
    {
        $task = $this->createSeries($this->manager);
        $this->travel(1)->days();
        $scheduler = app(ScheduledTasks::class);
        $this->manager->employees()->detach($this->employee);
        $this->assertSame(0, $scheduler->occurrences());
        $this->manager->employees()->attach($this->employee);
        $this->manager->update(['active' => false]);
        $this->assertSame(0, $scheduler->occurrences());
        $this->manager->update(['active' => true]);
        $this->employee->update(['active' => false]);
        $this->assertSame(0, $scheduler->occurrences());
        $this->employee->update(['active' => true]);
        $this->employee->stores()->updateExistingPivot($task->store_id, ['active' => false]);
        $this->assertSame(0, $scheduler->occurrences());
        $this->employee->stores()->updateExistingPivot($task->store_id, ['active' => true]);
        $this->assertSame(1, $scheduler->occurrences());
        $this->assertSame(2, $task->template->tasks()->count());
    }

    public function test_existing_completed_task_can_be_made_recurring_without_reopening_or_overwriting_history(): void
    {
        $task = $this->createSeries(null, ['recurrence' => null, 'required_evidence' => ['note'], 'due_at' => '2026-10-01T17:00', 'scheduled_at' => null]);
        app(TaskWorkflow::class)->complete($task, $this->employee, 'Historical completion');
        $before = $task->fresh()->only(['status', 'due_at', 'completed_at', 'completed_by_id', 'current_completion_id', 'review_status']);
        $this->post('/tasks/'.$task->id.'/recurrence', ['frequency' => 'weekly', 'next_date' => '2026-10-16', 'ends_on' => '2026-11-06'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals($before, $task->fresh()->only(array_keys($before)));
        $this->assertSame('2026-10-16', $task->fresh()->template->next_date->toDateString());
        $this->assertSame(1, $task->submissions()->count());
        $this->postJson('/tasks/'.$task->id.'/recurrence', ['frequency' => 'daily', 'next_date' => '2026-10-16'])->assertUnprocessable();
        $this->assertDatabaseCount('task_templates', 1);
    }

    public function test_employees_unscoped_managers_and_other_companies_cannot_create_or_control_a_series(): void
    {
        $task = $this->createSeries();
        $plan = $task->template;
        $this->actingAs($this->employee)->post('/tasks', $this->fields())->assertForbidden();
        $this->get('/recurring-tasks')->assertForbidden();
        $this->post('/tasks/'.$task->id.'/recurrence', ['frequency' => 'daily', 'next_date' => '2026-10-16'])->assertForbidden();
        $this->post('/recurring-tasks/'.$plan->id.'/status', ['active' => 0])->assertForbidden();
        $this->actingAs($this->manager)->post('/recurring-tasks/'.$plan->id.'/status', ['active' => 0])->assertForbidden();
        $this->post('/tasks', $this->fields(['employee_id' => $this->outsider->id]))->assertForbidden();
        $company = Company::create(['name' => 'Other test company']);
        $foreignOwner = $this->person('admin', $company);
        $this->actingAs($foreignOwner)->withSession(['workspace.store_id' => $foreignOwner->storeId()])->get('/recurring-tasks')->assertOk()->assertDontSee($task->title);
        $this->post('/recurring-tasks/'.$plan->id.'/status', ['active' => 0])->assertForbidden();
        $this->post('/tasks/'.$task->id.'/recurrence', [])->assertForbidden();
        $this->assertTrue($plan->fresh()->active);
        $this->assertDatabaseCount('task_templates', 1);
    }

    public function test_invalid_repeat_dates_and_frequencies_leave_no_partial_tasks_or_templates(): void
    {
        $this->actingAs($this->owner);
        foreach ([['recurrence' => 'yearly'], ['recurrence_until' => '2026-10-14'], ['due_at' => '2026-10-14T17:00', 'scheduled_at' => null], ['recurrence' => null, 'recurrence_until' => '2026-10-16']] as $bad) {
            $this->postJson('/tasks', $this->fields($bad))->assertUnprocessable();
        }
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_templates', 0);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_legacy_store_settings_creation_generates_due_instances_immediately_and_edits_only_future_work(): void
    {
        $fields = $this->fields();
        unset($fields['due_at'], $fields['scheduled_at'], $fields['recurrence']);
        $fields += ['frequency' => 'daily', 'next_date' => '2026-10-15', 'due_time' => '17:00', 'active' => 1];
        $this->actingAs($this->owner)->post('/admin/templates', $fields)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tasks', 1);
        $plan = TaskTemplate::firstOrFail();
        $this->post('/admin/templates/'.$plan->id, ['title' => 'New instructions title', 'next_date' => '2026-10-16'] + $fields)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tasks', 1);
        $this->travel(1)->days();
        $this->artisan('tasks:generate')->expectsOutput('Generated 1 occurrences.')->assertSuccessful();
        $this->assertSame('Restock daily', Task::oldest('id')->firstOrFail()->title);
        $this->assertSame('New instructions title', Task::latest('id')->firstOrFail()->title);
        $this->artisan('tasks:generate')->expectsOutput('Generated 0 occurrences.')->assertSuccessful();
    }

    public function test_editing_monthly_instructions_preserves_the_day_31_anchor_after_a_shorter_month(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 31)->setTime(8, 0));
        $task = $this->createSeries(extra: ['recurrence' => 'monthly', 'due_at' => '2026-01-31T17:00', 'scheduled_at' => '2026-01-31T09:00']);
        $plan = $task->template;
        $this->assertSame('2026-02-28', $plan->next_date->toDateString());
        $fields = $task->only(['employee_id', 'category_id', 'instructions', 'area', 'priority', 'required_evidence']);
        $this->post('/admin/templates/'.$plan->id, $fields + ['title' => 'Updated instructions title', 'frequency' => 'monthly', 'next_date' => '2026-02-28', 'due_time' => '17:00', 'active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(31, $plan->fresh()->anchor_day);
        $this->travelTo(now()->setDate(2026, 2, 28));
        $this->assertSame(1, app(ScheduledTasks::class)->occurrences());
        $this->assertSame('2026-03-31', $plan->fresh()->next_date->toDateString());
        $this->assertSame('Updated instructions title', $plan->tasks()->latest('id')->firstOrFail()->title);
        $this->assertSame('Restock daily', $task->fresh()->title);
    }

    public function test_manager_mobile_can_create_convert_pause_and_resume_with_server_owned_schedule_identity(): void
    {
        $this->mobile($this->manager);
        $response = $this->postJson(self::API.'/team/tasks', $this->fields(['creator_id' => $this->owner->id, 'task_template_id' => 999]))->assertCreated();
        $task = Task::findOrFail($response->json('data.id'));
        $this->assertSame($this->manager->id, $task->creator_id);
        $this->assertSame($this->manager->id, $task->template->creator_id);
        $this->getJson(self::API.'/tasks/'.$task->id)->assertOk()->assertJsonPath('data.recurrence.frequency', 'daily')->assertJsonPath('data.permissions.recurrence', true);
        $this->postJson(self::API.'/recurring-tasks/'.$task->task_template_id.'/status', ['active' => false])->assertOk();
        $this->postJson(self::API.'/recurring-tasks/'.$task->task_template_id.'/status', ['active' => true])->assertOk();
        $oneOff = $this->postJson(self::API.'/team/tasks', $this->fields(['recurrence' => null]))->assertCreated();
        $this->postJson(self::API.'/tasks/'.$oneOff->json('data.id').'/recurrence', ['frequency' => 'monthly', 'next_date' => '2026-10-31'])->assertCreated();
        $this->mobile($this->employee);
        $this->getJson(self::API.'/tasks/'.$task->id)->assertOk()->assertJsonPath('data.permissions.recurrence', false);
        $this->postJson(self::API.'/recurring-tasks/'.$task->task_template_id.'/status', ['active' => false])->assertForbidden();
    }

    public function test_outstanding_company_policy_also_blocks_the_new_recurrence_endpoints(): void
    {
        $task = $this->createSeries($this->manager);
        $service = app(CompanyPolicyService::class);
        $service->saveDraft($this->owner, 'Current policy', 'Read this first', 0);
        $service->publish($this->owner, 1);
        $this->mobile($this->manager);
        $this->postJson(self::API.'/tasks/'.$task->id.'/recurrence', [])->assertStatus(423);
        $this->postJson(self::API.'/recurring-tasks/'.$task->task_template_id.'/status', ['active' => false])->assertStatus(423);
        $this->assertTrue($task->template->active);
    }
}
