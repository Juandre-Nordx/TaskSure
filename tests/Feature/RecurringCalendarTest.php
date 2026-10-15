<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\CompanyPolicyService;
use App\Services\ScheduledTasks;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringCalendarTest extends TestCase
{
    use RefreshDatabase;

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
        $this->category = Category::create(['name' => 'Calendar stock']);
    }

    private function person(string $role, ?Company $company = null): User
    {
        return User::create(['name' => $role.' '.uniqid(), 'email' => uniqid().'@calendar.test', 'password' => 'CalendarPassword123!', 'role' => $role, 'active' => true, 'company_id' => $company?->id]);
    }

    private function plan(array $extra = []): TaskTemplate
    {
        return TaskTemplate::create($extra + ['creator_id' => $this->owner->id, 'employee_id' => $this->employee->id, 'category_id' => $this->category->id, 'title' => 'Daily fridge check', 'instructions' => 'Check all shelves.', 'area' => 'Cold room', 'priority' => 'high', 'required_evidence' => ['photo', 'note'], 'frequency' => 'daily', 'next_date' => '2026-10-15', 'due_time' => '17:00', 'anchor_day' => 15, 'active' => true, 'scheduled_offset_minutes' => 480]);
    }

    private function web(User $user, array $filters = []): array
    {
        return $this->actingAs($user)->getJson('/calendar/events?'.http_build_query($filters + ['from' => '2026-10-15', 'to' => '2026-10-21']))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    private function mobile(User $user, array $filters = [])
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('Calendar phone', ['mobile', $user->role])->plainTextToken);

        return $this->getJson('/api/mobile/v1/calendar?'.http_build_query($filters + ['from' => '2026-10-15', 'to' => '2026-10-21', 'include_recurring' => 1]));
    }

    private function days(array $events): array
    {
        return array_map(fn ($event) => BusinessTime::show($event['start'], 'Y-m-d'), $events);
    }

    public function test_new_daily_assignment_appears_on_every_visible_day_without_creating_future_tasks(): void
    {
        $plan = $this->plan();
        $this->assertSame(1, app(ScheduledTasks::class)->occurrences());
        $cursor = $plan->fresh()->next_date->toDateString();
        $events = $this->web($this->owner);
        $this->assertCount(7, $events);
        $this->assertSame(['2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21'], $this->days($events));
        $this->assertFalse($events[0]['extendedProps']['planned']);
        $this->assertTrue($events[0]['editable']);
        $this->assertStringStartsWith('/tasks/', $events[0]['url']);
        foreach (array_slice($events, 1) as $event) {
            $this->assertTrue($event['extendedProps']['planned']);
            $this->assertFalse($event['editable']);
            $this->assertFalse($event['startEditable']);
            $this->assertArrayNotHasKey('url', $event);
            $this->assertStringContainsString('Planned recurring', $event['title']);
            $this->assertSame('17:00', BusinessTime::show($event['start'], 'H:i'));
            $this->assertSame('09:00', BusinessTime::show($event['extendedProps']['scheduled_at'], 'H:i'));
        }
        $this->web($this->employee);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('alerts', 1);
        $this->assertSame($cursor, $plan->fresh()->next_date->toDateString());
        $this->assertCount(30, $this->web($this->employee, ['from' => '2026-11-01', 'to' => '2026-11-30']));
        $this->assertCount(31, $this->web($this->employee, ['from' => '2046-12-01', 'to' => '2046-12-31']));
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_pause_removes_only_planned_dates_and_resume_and_inclusive_end_date_are_reflected(): void
    {
        $plan = $this->plan();
        app(ScheduledTasks::class)->occurrences();
        $this->web($this->owner);
        $this->postJson('/recurring-tasks/'.$plan->id.'/status', ['active' => false])->assertOk();
        $events = $this->web($this->employee);
        $this->assertCount(1, $events);
        $this->assertFalse($events[0]['extendedProps']['planned']);
        $this->actingAs($this->owner)->postJson('/recurring-tasks/'.$plan->id.'/status', ['active' => true])->assertOk();
        $plan->update(['ends_on' => '2026-10-18']);
        $this->assertCount(4, $this->web($this->employee));
        $this->assertCount(0, $this->web($this->employee, ['from' => '2026-10-19', 'to' => '2026-10-31']));
        $plan->update(['active' => false]);
        $this->assertCount(1, $this->web($this->employee));
    }

    public function test_generated_occurrence_replaces_the_preview_once_without_duplicate_alerts(): void
    {
        $plan = $this->plan();
        app(ScheduledTasks::class)->occurrences();
        $this->assertTrue($this->web($this->employee)[1]['extendedProps']['planned']);
        $this->travel(1)->days();
        $this->assertSame(1, app(ScheduledTasks::class)->occurrences());
        $this->assertSame(0, app(ScheduledTasks::class)->occurrences());
        $events = $this->web($this->employee);
        $this->assertCount(7, $events);
        $this->assertFalse($events[1]['extendedProps']['planned']);
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseCount('alerts', 2);
        $this->assertSame('2026-10-17', $plan->fresh()->next_date->toDateString());
    }

    public function test_rescheduling_cancelling_or_reassigning_an_instance_never_resurrects_a_preview(): void
    {
        $this->plan();
        $this->travel(3)->days();
        app(ScheduledTasks::class)->occurrences();
        $tasks = Task::orderBy('id')->get();
        // Move the cursor backwards to model an imported template or retried cursor.
        TaskTemplate::query()->update(['next_date' => '2026-10-15']);
        $tasks[0]->update(['due_at' => BusinessTime::utc('2026-11-05 17:00')]);
        $tasks[1]->update(['status' => 'cancelled']);
        $tasks[2]->update(['employee_id' => $this->outsider->id]);
        $events = $this->web($this->employee);
        $this->assertSame(['2026-10-16', '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21'], $this->days($events));
        $this->assertStringContainsString('Cancelled', $events[0]['title']);
        $assigned = $this->web($this->employee, ['status' => 'assigned']);
        $this->assertSame(['2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21'], $this->days($assigned));
        $this->assertFalse($assigned[0]['extendedProps']['planned']);
        $this->assertSame(['2026-11-05', '2026-11-05'], $this->days($this->web($this->employee, ['from' => '2026-11-05', 'to' => '2026-11-05'])));
        $this->assertDatabaseCount('tasks', 4);
    }

    public function test_calendar_permissions_hide_other_staff_and_companies_but_include_permitted_series_from_other_creators(): void
    {
        $this->plan();
        $this->plan(['employee_id' => $this->outsider->id, 'title' => 'Private outsider schedule']);
        $foreignCompany = Company::create(['name' => 'Other calendar company']);
        $foreignOwner = $this->person('admin', $foreignCompany);
        $foreignEmployee = $this->person('employee', $foreignCompany);
        $this->plan(['creator_id' => $foreignOwner->id, 'employee_id' => $foreignEmployee->id, 'title' => 'Foreign schedule']);
        $this->assertCount(14, $this->web($this->owner));
        foreach ([$this->manager, $this->employee] as $person) {
            $events = $this->web($person);
            $this->assertCount(7, $events);
            $this->assertSame(['Daily fridge check · Planned recurring'], array_values(array_unique(array_column($events, 'title'))));
            $this->assertCount(0, $this->web($person, ['employee_id' => $this->outsider->id]));
        }
        $this->assertSame('Foreign schedule · Planned recurring', $this->web($foreignOwner)[0]['title']);
        $this->manager->employees()->detach($this->employee);
        $this->assertCount(0, $this->web($this->manager));
    }

    public function test_mobile_calendar_stays_personal_and_old_clients_keep_numeric_task_ids(): void
    {
        $this->plan();
        $this->plan(['employee_id' => $this->manager->id, 'title' => 'Manager personal schedule']);
        app(ScheduledTasks::class)->occurrences();
        $events = $this->mobile($this->employee)->assertOk()->json();
        $this->assertCount(7, $events);
        $this->assertFalse($events[0]['editable']);
        $this->assertTrue($events[1]['extendedProps']['planned']);
        $this->assertSame('Daily fridge check · Assigned', $events[0]['title']);
        $personal = $this->mobile($this->manager, ['employee_id' => $this->employee->id, 'scope' => 'team'])->assertOk()->json();
        $this->assertCount(7, $personal);
        $this->assertSame('Manager personal schedule · Assigned', $personal[0]['title']);
        $legacy = $this->mobile($this->employee, ['include_recurring' => 0])->assertOk()->json();
        $this->assertCount(1, $legacy);
        $this->assertTrue(ctype_digit($legacy[0]['id']));
        $this->getJson('/api/mobile/v1/calendar?from=2026-10-15&to=2026-10-21')->assertOk()->assertJsonCount(1);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_weekly_and_monthly_dates_preserve_the_anchor_in_future_views(): void
    {
        $weekly = $this->plan(['frequency' => 'weekly']);
        $this->assertSame(['2026-11-05', '2026-11-12', '2026-11-19', '2026-11-26'], $this->days($this->web($this->employee, ['from' => '2026-11-01', 'to' => '2026-11-30'])));
        $weekly->update(['active' => false]);
        $monthly = $this->plan(['frequency' => 'monthly', 'anchor_day' => 31, 'next_date' => '2027-01-31']);
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $this->days($this->web($this->employee, ['from' => '2027-01-01', 'to' => '2027-03-31'])));
        $this->assertSame(['2028-02-29', '2028-03-31'], $this->days($this->web($this->employee, ['from' => '2028-02-01', 'to' => '2028-03-31'])));
        $this->assertSame(['2046-04-30', '2046-05-31'], $this->days($this->web($this->employee, ['from' => '2046-04-01', 'to' => '2046-06-01'])));
        $this->assertSame('2027-01-31', $monthly->fresh()->next_date->toDateString());
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_calendar_filters_apply_to_planned_dates_and_do_not_invent_completion_or_review(): void
    {
        $this->plan();
        foreach ([['q' => 'not present'], ['priority' => 'urgent'], ['category_id' => 999], ['status' => 'completed'], ['status' => 'in_progress'], ['status' => 'cancelled'], ['review_state' => 'not_reviewed'], ['overdue' => 1], ['scope' => 'my']] as $filter) {
            $this->assertCount(0, $this->web($this->manager, $filter));
        }
        $this->assertCount(7, $this->web($this->manager, ['q' => 'Cold room', 'priority' => 'high', 'status' => 'assigned', 'category_id' => $this->category->id, 'scope' => 'team']));
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_calendar_range_is_bounded_and_fullcalendar_end_is_exclusive_in_sast(): void
    {
        $this->plan(['due_time' => '00:30']);
        $this->actingAs($this->employee)->getJson('/calendar/events?start=2026-10-15&end=2026-10-17')->assertOk()->assertJsonCount(2)->assertJsonPath('0.start', '2026-10-14T22:30:00+00:00');
        $this->getJson('/calendar/events?'.http_build_query(['start' => '2026-10-14T22:00:00Z', 'end' => '2026-10-16T22:00:00Z']))->assertOk()->assertJsonCount(2);
        $this->getJson('/calendar/events?from=2026-10-20&to=2026-10-15')->assertUnprocessable();
        $this->getJson('/calendar/events?from=2026-10-15&to=2027-10-15')->assertUnprocessable();
        $this->getJson('/calendar/events?start=invalid&end=2026-11-01')->assertUnprocessable();
        $this->mobile($this->employee, ['from' => '2026-10-15', 'to' => '2027-10-15'])->assertUnprocessable();
        $this->getJson('/api/mobile/v1/calendar?include_recurring=1')->assertUnprocessable();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_inactive_assignee_or_creator_and_lost_creator_scope_remove_future_dates(): void
    {
        $plan = $this->plan(['creator_id' => $this->manager->id]);
        $this->assertCount(7, $this->web($this->employee));
        $this->manager->employees()->detach($this->employee);
        $this->assertCount(0, $this->web($this->employee));
        $this->manager->employees()->attach($this->employee);
        $this->manager->update(['active' => false]);
        $this->assertCount(0, $this->web($this->employee));
        $this->manager->update(['active' => true]);
        $this->employee->update(['active' => false]);
        $this->assertCount(0, $this->web($this->owner));
        $this->employee->update(['active' => true]);
        $plan->update(['ends_on' => '2026-10-14']);
        $this->assertCount(0, $this->web($this->owner));
    }

    public function test_policy_guard_still_protects_calendar_previews(): void
    {
        $this->plan();
        $service = app(CompanyPolicyService::class);
        $service->saveDraft($this->owner, 'Calendar policy', 'Read first', 0);
        $service->publish($this->owner, 1);
        $this->actingAs($this->employee)->getJson('/calendar/events?from=2026-10-15&to=2026-10-21')->assertStatus(423)->assertJsonPath('code', CompanyPolicyService::REQUIRED);
        $this->mobile($this->employee)->assertStatus(423)->assertJsonPath('code', CompanyPolicyService::REQUIRED);
        $this->assertDatabaseCount('tasks', 0);
    }
}
