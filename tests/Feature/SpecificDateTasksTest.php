<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpecificDateTasksTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/mobile/v1';

    private const DATES = ['2026-10-20', '2026-10-22', '2026-10-25', '2026-10-30'];

    private User $owner;

    private User $manager;

    private User $employee;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(8, 0));
        config(['mobile.push_enabled' => false, 'tasksure.email_enabled' => false]);
        $this->owner = $this->person('admin');
        $this->manager = $this->person('manager');
        $this->employee = $this->person('employee');
        $this->manager->employees()->attach($this->employee);
        $this->category = Category::create(['name' => 'Fridges']);
        Storage::fake('evidence');
    }

    private function person(string $role, ?Company $company = null): User
    {
        return User::create(['name' => $role.' '.uniqid(), 'email' => uniqid().'@dates.test', 'password' => 'DatesPassword123!', 'role' => $role, 'active' => true, 'company_id' => $company?->id]);
    }

    private function web(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->flushSession();

        return $this->actingAs($user, 'web');
    }

    private function mobile(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->flushSession();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('Dates phone', ['mobile', $user->role])->plainTextToken);
    }

    private function fields(array $extra = []): array
    {
        return $extra + ['title' => 'Restock the beer fridges', 'instructions' => 'Photograph the stocked fridge.', 'employee_id' => $this->employee->id, 'category_id' => $this->category->id, 'area' => 'Beer fridges', 'priority' => 'normal', 'required_evidence' => ['photo', 'note'], 'recurrence' => 'specific_dates', 'specific_dates' => self::DATES, 'due_time' => '16:00', 'creation_key' => (string) Str::uuid()];
    }

    private function series(?User $actor = null): Task
    {
        $this->web($actor ?? $this->owner)->post('/tasks', $this->fields())->assertRedirect()->assertSessionHasNoErrors();

        return Task::latest('id')->firstOrFail();
    }

    private function datedTask(TaskTemplate $plan, string $date): Task
    {
        $task = Task::create($plan->only(['store_id', 'creator_id', 'employee_id', 'category_id', 'title', 'instructions', 'area', 'priority', 'required_evidence']) + ['task_template_id' => $plan->id, 'occurrence_date' => $date, 'due_at' => $date.' 14:00:00']);
        $task->activities()->create(['user_id' => $plan->creator_id, 'type' => 'assigned', 'body' => 'Assigned occurrence.']);

        return $task;
    }

    private function update(TaskTemplate $plan, array $dates, string $time = '17:00', ?int $revision = null)
    {
        return $this->postJson('/recurring-tasks/'.$plan->id.'/schedule', ['specific_dates' => $dates, 'due_time' => $time, 'active' => true, 'schedule_revision' => $revision ?? $plan->fresh()->schedule_revision]);
    }

    public function test_owner_and_scoped_manager_create_exact_sorted_dates_at_the_common_sast_deadline(): void
    {
        foreach ([$this->owner, $this->manager] as $actor) {
            $this->web($actor)->get('/tasks/create')->assertOk()->assertSee('Specific dates')->assertSee('data-specific-picker', false);
            $this->postJson('/tasks', $this->fields(['specific_dates' => array_reverse(self::DATES)]))->assertCreated();
            $task = Task::latest('id')->firstOrFail();
            $plan = $task->template;
            $this->assertSame(self::DATES, $plan->specific_dates);
            $this->assertSame('2026-10-20', $task->occurrence_date);
            $this->assertSame('2026-10-20 14:00', $task->due_at->format('Y-m-d H:i'));
            $this->assertSame('2026-10-22', $plan->next_date->toDateString());
            $this->assertSame('2026-10-30', $plan->ends_on->toDateString());
            $this->assertSame($actor->id, $plan->creator_id);
            $this->assertSame(1, $plan->tasks()->count());
            $this->get('/recurring-tasks/'.$plan->id.'/edit')->assertOk()->assertSee('Edit specific dates');
        }
    }

    public function test_calendar_projects_only_selected_dates_and_generation_replaces_previews_without_duplicates(): void
    {
        $task = $this->series();
        $events = $this->web($this->employee)->getJson('/calendar/events?from=2026-10-17&to=2026-10-31')->assertOk()->json();
        $this->assertCount(4, $events);
        $this->assertSame(3, collect($events)->where('extendedProps.planned', true)->count());
        $this->assertSame(self::DATES, collect($events)->pluck('start')->map(fn ($time) => substr($time, 0, 10))->all());
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(21, 59));
        $this->assertSame(0, app(ScheduledTasks::class)->occurrences());
        $this->travel(1)->minutes();
        $this->assertSame(1, app(ScheduledTasks::class)->occurrences());
        $this->assertSame(0, app(ScheduledTasks::class)->occurrences());
        $this->assertSame('2026-10-22', Task::latest('id')->firstOrFail()->occurrence_date);
        $this->mobile($this->employee)->getJson(self::API.'/calendar?from=2026-10-17&to=2026-10-31&include_recurring=1')->assertOk()->assertJsonCount(4);
        $this->travelTo(now()->setDate(2026, 10, 31));
        $this->assertSame(2, app(ScheduledTasks::class)->occurrences());
        $this->assertSame(0, app(ScheduledTasks::class)->occurrences());
        $this->assertSame(4, $task->template->tasks()->count());
        $this->assertSame('Finished', $task->template->generationBlock());
        $this->assertDatabaseCount('alerts', 4);
    }

    public function test_each_occurrence_requires_its_own_photo_note_completion_and_review(): void
    {
        $first = $this->series();
        Storage::disk('evidence')->put('fridge.jpg', 'Original photo');
        Attachment::create(['task_id' => $first->id, 'user_id' => $this->employee->id, 'path' => 'fridge.jpg', 'original_name' => 'fridge.jpg', 'mime' => 'image/jpeg', 'size' => 14, 'kind' => 'photo']);
        app(TaskWorkflow::class)->complete($first, $this->employee, 'First fridge complete.');
        app(TaskWorkflow::class)->markReviewed($first, $this->owner, 'Checked first date.');
        $this->travelTo(now()->setDate(2026, 10, 22));
        app(ScheduledTasks::class)->occurrences();
        $next = Task::latest('id')->firstOrFail();
        $this->assertSame('assigned', $next->status);
        $this->assertSame('not_reviewed', $next->review_status);
        $this->assertSame(0, $next->submissions()->count());
        $this->assertSame(0, $next->attachments()->count());
        $this->mobile($this->employee)->postJson(self::API.'/tasks/'.$next->id.'/actions', ['action' => 'complete', 'note' => 'Second fridge done'])->assertUnprocessable();
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('reviewed', $first->fresh()->review_status);
        $this->assertSame('First fridge complete.', $first->submissions()->sole()->note);
    }

    public function test_retried_creation_reuses_one_template_task_and_alert_and_rejects_reusing_the_key_for_different_content(): void
    {
        $fields = $this->fields();
        $this->mobile($this->manager)->postJson(self::API.'/team/tasks', $fields)->assertCreated();
        $firstId = Task::firstOrFail()->id;
        $this->postJson(self::API.'/team/tasks', ['specific_dates' => array_reverse(self::DATES)] + $fields)->assertCreated()->assertJsonPath('data.id', $firstId);
        $this->postJson(self::API.'/team/tasks', ['title' => 'Different work'] + $fields)->assertUnprocessable()->assertJsonValidationErrors('creation_key');
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_templates', 1);
        $this->assertDatabaseCount('alerts', 1);
        $this->web($this->owner)->postJson('/tasks', $fields)->assertUnprocessable();
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_invalid_dates_times_counts_and_ambiguous_deadlines_do_not_create_partial_records(): void
    {
        $this->web($this->owner);
        $tooMany = [];
        for ($i = 0; $i < 367; $i++) {
            $tooMany[] = now()->addDays($i)->toDateString();
        }
        foreach ([['specific_dates' => []], ['specific_dates' => ['2026-10-20', '2026-10-20']], ['specific_dates' => ['2026-02-30']], ['specific_dates' => ['2026-10-16']], ['specific_dates' => $tooMany], ['due_time' => '25:00'], ['creation_key' => 'wrong'], ['due_at' => '2026-10-20T16:00'], ['scheduled_at' => '2026-10-20T12:00'], ['recurrence_until' => '2026-10-31']] as $bad) {
            $this->postJson('/tasks', $this->fields($bad))->assertUnprocessable();
        }
        $this->assertDatabaseCount('task_templates', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_schedule_edits_update_future_unstarted_deadlines_but_preserve_completed_started_and_evidence_history(): void
    {
        $first = $this->series();
        $plan = $first->template;
        $future = $this->datedTask($plan, '2026-10-22');
        $started = $this->datedTask($plan, '2026-10-25');
        app(TaskWorkflow::class)->start($started, $this->employee);
        $removed = $this->datedTask($plan, '2026-10-30');
        Storage::disk('evidence')->put('original.jpg', 'Original evidence');
        Attachment::create(['task_id' => $first->id, 'user_id' => $this->employee->id, 'path' => 'original.jpg', 'original_name' => 'original.jpg', 'mime' => 'image/jpeg', 'size' => 17, 'kind' => 'photo']);
        app(TaskWorkflow::class)->complete($first, $this->employee, 'Original completion');
        app(TaskWorkflow::class)->markReviewed($first, $this->owner, 'Original review');
        $before = [];
        foreach (['tasks' => [$first->id, $started->id], 'submissions' => null, 'attachments' => null, 'task_reviews' => null] as $table => $ids) {
            $before[$table] = DB::table($table)->when($ids, fn ($q) => $q->whereIn('id', $ids))->get()->toJson();
        }
        $this->update($plan, ['2026-10-22', '2026-10-25'])->assertOk();
        $this->assertSame('2026-10-22 15:00', $future->fresh()->due_at->format('Y-m-d H:i'));
        $this->assertSame(1, $future->deadlineChanges()->count());
        $this->assertSame('cancelled', $removed->fresh()->status);
        $this->assertTrue($removed->fresh()->schedule_removed);
        foreach (['tasks' => [$first->id, $started->id], 'submissions' => null, 'attachments' => null, 'task_reviews' => null] as $table => $ids) {
            $this->assertSame($before[$table], DB::table($table)->when($ids, fn ($q) => $q->whereIn('id', $ids))->get()->toJson());
        }
        $this->assertSame('Original evidence', Storage::disk('evidence')->get('original.jpg'));
        $this->update($plan, ['2026-10-22', '2026-10-25', '2026-10-30'])->assertOk();
        $this->assertSame('assigned', $removed->fresh()->status);
        $this->assertFalse($removed->fresh()->schedule_removed);
        $this->assertSame('2026-10-30 15:00', $removed->fresh()->due_at->format('Y-m-d H:i'));
        $this->assertSame(1, $plan->tasks()->where('occurrence_date', '2026-10-30')->count());
        $this->assertSame('completed', $first->fresh()->status);
        $this->update($plan, self::DATES, revision: 0)->assertUnprocessable()->assertJsonValidationErrors('schedule_revision');
    }

    public function test_manual_cancellations_and_unsubmitted_proof_are_never_discarded_or_restored_by_schedule_edits(): void
    {
        $first = $this->series();
        $plan = $first->template;
        $cancelled = $this->datedTask($plan, '2026-10-22');
        app(TaskWorkflow::class)->cancel($cancelled, $this->owner, 'Manually cancelled');
        Attachment::create(['task_id' => $first->id, 'user_id' => $this->employee->id, 'path' => 'pending.jpg', 'original_name' => 'pending.jpg', 'mime' => 'image/jpeg', 'size' => 10, 'kind' => 'photo']);
        $this->update($plan, ['2026-10-25', '2026-10-30'])->assertOk();
        $this->assertSame('assigned', $first->fresh()->status);
        $this->assertSame('2026-10-20 14:00', $first->fresh()->due_at->format('Y-m-d H:i'));
        $this->update($plan, self::DATES)->assertOk();
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertFalse($cancelled->fresh()->schedule_removed);
        $this->assertSame('Manually cancelled', $cancelled->fresh()->cancellation_reason);
        $this->assertSame(1, $first->attachments()->count());
    }

    public function test_managers_can_edit_only_their_own_scoped_series_and_company_policy_guard_still_applies(): void
    {
        $first = $this->series($this->manager);
        $plan = $first->template;
        $this->mobile($this->manager)->getJson(self::API.'/tasks/'.$first->id)->assertJsonPath('data.recurrence.specific_dates', self::DATES)->assertJsonPath('data.recurrence.schedule_revision', 0);
        $this->postJson(self::API.'/recurring-tasks/'.$plan->id.'/schedule', ['specific_dates' => self::DATES, 'due_time' => '17:00', 'active' => true, 'schedule_revision' => 0])->assertOk();
        $this->mobile($this->employee)->postJson(self::API.'/recurring-tasks/'.$plan->id.'/schedule', [])->assertForbidden();
        $other = $this->person('admin', Company::create(['name' => 'Other company']));
        $this->web($other)->get('/recurring-tasks/'.$plan->id.'/edit')->assertForbidden();
        $this->update($plan, self::DATES)->assertForbidden();
        $this->manager->employees()->detach($this->employee);
        $this->web($this->manager)->get('/recurring-tasks/'.$plan->id.'/edit')->assertForbidden();
        $this->manager->employees()->attach($this->employee);
        $service = app(CompanyPolicyService::class);
        $draft = $service->createDraft($this->owner, 'Current policy', 'Read before work', null, 'text');
        $service->publish($this->owner, $draft->draft_revision, $draft->id);
        $this->mobile($this->manager)->postJson(self::API.'/recurring-tasks/'.$plan->id.'/schedule', [])->assertStatus(423);
    }

    public function test_schedule_changes_cannot_restore_old_occurrences_outside_the_managers_current_scope(): void
    {
        $first = $this->series($this->manager);
        $plan = $first->template;
        $old = $this->datedTask($plan, '2026-10-22');
        $this->update($plan, ['2026-10-20', '2026-10-25', '2026-10-30'])->assertOk();
        $this->assertTrue($old->fresh()->schedule_removed);
        $nextAssignee = $this->person('employee');
        $plan->update(['employee_id' => $nextAssignee->id]);
        $this->manager->employees()->sync([$nextAssignee->id]);
        $before = $old->fresh()->getAttributes();
        $this->update($plan, self::DATES)->assertOk();
        $this->assertSame($before, $old->fresh()->getAttributes());
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame($nextAssignee->id, $plan->fresh()->employee_id);
    }

    public function test_pausing_a_specific_series_invalidates_an_open_edit_form_instead_of_losing_the_pause(): void
    {
        $first = $this->series();
        $plan = $first->template;
        $this->postJson('/recurring-tasks/'.$plan->id.'/status', ['active' => false])->assertOk();
        $this->update($plan, self::DATES, revision: 0)->assertUnprocessable()->assertJsonValidationErrors('schedule_revision');
        $this->assertFalse($plan->fresh()->active);
        $this->assertSame(self::DATES, $plan->fresh()->specific_dates);
    }

    public function test_conversion_preserves_the_original_completed_task_and_uses_only_the_chosen_repeat_dates(): void
    {
        $fields = $this->fields(['recurrence' => null, 'due_at' => '2026-10-01T18:00', 'required_evidence' => ['note']]);
        unset($fields['specific_dates'],$fields['due_time'],$fields['creation_key']);
        $this->web($this->manager)->post('/tasks', $fields)->assertRedirect()->assertSessionHasNoErrors();
        $original = Task::firstOrFail();
        app(TaskWorkflow::class)->complete($original, $this->employee, 'Old completed work');
        $before = $original->fresh()->only(['status', 'due_at', 'completed_at', 'current_completion_id']);
        $this->postJson('/tasks/'.$original->id.'/recurrence', ['frequency' => 'specific_dates', 'specific_dates' => self::DATES, 'due_time' => '16:00'])->assertCreated();
        $this->assertEquals($before, $original->fresh()->only(array_keys($before)));
        $plan = $original->fresh()->template;
        $this->assertSame('2026-10-20', $plan->next_date->toDateString());
        $this->update($plan, ['2026-10-22', '2026-10-30'])->assertOk();
        $this->assertEquals($before, $original->fresh()->only(array_keys($before)));
        $this->assertSame('Old completed work', $original->submissions()->sole()->note);
    }

    public function test_paused_legacy_template_keeps_past_uncreated_deadlines_when_future_time_is_edited(): void
    {
        $fields = $this->fields(['specific_dates' => ['2026-10-17', '2026-10-22']]);
        unset($fields['recurrence']);
        $fields += ['frequency' => 'specific_dates', 'active' => false];
        $this->web($this->owner)->post('/admin/templates', $fields)->assertRedirect()->assertSessionHasNoErrors();
        $plan = TaskTemplate::firstOrFail();
        $this->assertDatabaseCount('tasks', 0);
        $this->travelTo(now()->setDate(2026, 10, 20));
        $this->post('/admin/templates/'.$plan->id, ['due_time' => '17:00', 'schedule_revision' => 0] + $fields)->assertSessionHasNoErrors();
        $this->assertSame('16:00', $plan->fresh()->dueTimeOn('2026-10-17'));
        $this->assertSame('17:00', $plan->fresh()->dueTimeOn('2026-10-22'));
        $this->postJson('/recurring-tasks/'.$plan->id.'/status', ['active' => true])->assertOk();
        $this->assertSame('2026-10-17 14:00', Task::firstOrFail()->due_at->format('Y-m-d H:i'));
        $this->assertSame(1, $plan->tasks()->count());
    }
}
