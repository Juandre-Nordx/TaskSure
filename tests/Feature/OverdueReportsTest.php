<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Store;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ReportService;
use App\Services\TaskWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class OverdueReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $worker;

    private User $unscoped;

    private Category $category;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00', 'UTC'));
        $this->owner = $this->person('admin', 'Report owner');
        $this->manager = $this->person('manager', 'Report manager');
        $this->worker = $this->person('employee', 'Report worker');
        $this->unscoped = $this->person('employee', 'Unscoped worker');
        $this->manager->employees()->attach($this->worker);
        $this->store = Store::findOrFail($this->owner->storeId());
        $this->store->update(['name' => 'Cape Town branch']);
        $this->store->company->update(['name' => 'Report company']);
        $this->category = Category::create(['name' => 'Restocking']);
    }

    private function person(string $role, string $name, ?Company $company = null): User
    {
        return User::create(['name' => $name, 'email' => uniqid().'@reports.test', 'password' => 'ReportFixture123!', 'role' => $role, 'active' => true, 'company_id' => $company?->id]);
    }

    private function task(string $title, array $extra = []): Task
    {
        return Task::create($extra + ['title' => $title, 'instructions' => 'Check the stock.', 'area' => 'Beer fridges', 'priority' => 'normal', 'employee_id' => $this->worker->id, 'creator_id' => $this->owner->id, 'category_id' => $this->category->id, 'required_evidence' => ['note'], 'due_at' => now()->subHour()]);
    }

    private function completed(string $title, string $deadline, string $completion, array $extra = []): Task
    {
        $task = $this->task($title, $extra + ['due_at' => CarbonImmutable::parse($deadline, 'UTC')]);
        $at = CarbonImmutable::parse($completion, 'UTC');
        $attempt = $task->submissions()->create(['employee_id' => $task->employee_id, 'note' => 'Done.', 'submitted_at' => $at, 'due_at' => $task->due_at]);
        $task->update(['status' => 'completed', 'completed_at' => $at, 'completed_by_id' => $task->employee_id, 'current_completion_id' => $attempt->id]);

        return $task;
    }

    private function report(array $filters = [], ?User $user = null): array
    {
        return app(ReportService::class)->run($user ?? $this->owner, $filters);
    }

    public function test_strict_deadlines_current_state_and_completion_time_determine_overdue_totals(): void
    {
        $lateOpen = $this->task('Long overdue', ['status' => 'in_progress', 'due_at' => now()->subDay()->subHours(2)->subMinutes(3)->subSeconds(4)]);
        $this->task('One second overdue', ['due_at' => now()->subSecond()]);
        $this->task('Due exactly now', ['due_at' => now()]);
        $this->task('Not due yet', ['due_at' => now()->addSecond()]);
        $this->task('Cancelled work', ['status' => 'cancelled']);
        $this->completed('Finished early', '2026-10-18 10:00:00', '2026-10-18 09:59:59');
        $onTime = $this->completed('Finished at deadline', '2026-10-18 10:00:00', '2026-10-18 10:00:00');
        $onTime->update(['review_status' => 'reviewed', 'reviewed_at' => now(), 'reviewer_id' => $this->manager->id]);
        $late = $this->completed('Finished late', '2026-10-17 10:00:00', '2026-10-17 12:15:00');
        $oneSecondLate = $this->completed('Finished one second late', '2026-10-18 10:00:00', '2026-10-18 10:00:01');
        $unknown = $this->task('Legacy unknown completion', ['status' => 'completed']);
        $report = $this->report();
        $this->assertSame(['selected' => 10, 'still_overdue' => 2, 'completed_late' => 2], $report['totals']);
        $rows = collect($report['rows'])->keyBy('id');
        $this->assertSame('1d 2h 3m 4s', $rows[$lateOpen->id]['time_overdue_or_late']);
        $this->assertSame('2h 15m', $rows[$late->id]['time_overdue_or_late']);
        $this->assertSame('Completed late', $rows[$late->id]['overdue_state']);
        $this->assertSame('1s', $rows[$oneSecondLate->id]['time_overdue_or_late']);
        $this->assertSame('18 Oct 2026, 12:00:01', $rows[$oneSecondLate->id]['completed']);
        $this->assertSame('On time', $rows[$onTime->id]['overdue_state']);
        $this->assertSame('—', $rows[$onTime->id]['time_overdue_or_late']);
        $this->assertSame('Completion time unknown', $rows[$unknown->id]['overdue_state']);
        $this->assertSame('—', $rows[$unknown->id]['completed']);
        $worker = collect($report['summary'])->firstWhere('employee', $this->worker->name);
        $this->assertSame(2, $worker['overdue']);
        $this->assertSame(2, $worker['completed_late']);
        $this->assertSame('18 Oct 2026, 14:00:00', $report['report']['generated_at']);
    }

    public function test_each_overdue_filter_and_existing_options_use_the_same_selected_cohort(): void
    {
        $open = $this->task('Selected overdue', ['priority' => 'high']);
        $late = $this->completed('Selected late', '2026-10-18 10:00:00', '2026-10-18 11:00:00', ['priority' => 'high']);
        $this->task('Other priority');
        $this->task('Other person', ['employee_id' => $this->unscoped->id, 'priority' => 'high']);
        $filters = ['q' => 'Selected', 'employee_id' => $this->worker->id, 'priority' => 'high', 'category_id' => $this->category->id];
        $this->assertSame([$late->id, $open->id], array_column($this->report($filters)['rows'], 'id'));
        $still = $this->report($filters + ['overdue_status' => 'still_overdue']);
        $this->assertSame([$open->id], array_column($still['rows'], 'id'));
        $this->assertSame(['selected' => 1, 'still_overdue' => 1, 'completed_late' => 0], $still['totals']);
        $finished = $this->report($filters + ['overdue_status' => 'completed_late', 'status' => 'completed']);
        $this->assertSame([$late->id], array_column($finished['rows'], 'id'));
        $this->assertSame(['selected' => 1, 'still_overdue' => 0, 'completed_late' => 1], $finished['totals']);
        $this->assertSame([$open->id], array_column($this->report($filters + ['overdue' => true])['rows'], 'id'));
        $this->assertSame([], $this->report($filters + ['review_state' => 'reviewed'])['rows']);
        $this->assertSame(2, $this->report($filters + ['overdue_status' => 'all'])['totals']['selected']);
    }

    public function test_deadline_dates_include_the_whole_sast_day_and_intersect_the_existing_date_basis(): void
    {
        $before = $this->task('Before SAST day', ['due_at' => '2026-10-17 21:59:59', 'created_at' => '2026-10-10 08:00:00']);
        $start = $this->task('Start of SAST day', ['due_at' => '2026-10-17 22:00:00', 'created_at' => '2026-10-10 08:00:00']);
        $end = $this->task('End of SAST day', ['due_at' => '2026-10-18 21:59:59', 'created_at' => '2026-10-10 08:00:00']);
        $after = $this->task('Next SAST day', ['due_at' => '2026-10-18 22:00:00', 'created_at' => '2026-10-10 08:00:00']);
        $filters = ['basis' => 'assigned', 'from' => '2026-10-10', 'to' => '2026-10-10', 'deadline_from' => '2026-10-18', 'deadline_to' => '2026-10-18'];
        $this->assertSame([$start->id, $end->id], array_column($this->report($filters)['rows'], 'id'));
        $this->assertSame([], $this->report(array_replace($filters, ['from' => '2026-10-11', 'to' => '2026-10-11']))['rows']);
        $this->assertSame([$before->id, $start->id, $end->id], array_column($this->report(['deadline_to' => '2026-10-18'])['rows'], 'id'));
        $this->assertSame([$start->id, $end->id, $after->id], array_column($this->report(['deadline_from' => '2026-10-18'])['rows'], 'id'));
        $this->actingAs($this->owner)->get('/reports?'.http_build_query($filters))->assertOk()->assertViewHas('totals.selected', 2);
        $this->get('/reports?deadline_to=2026-10-18')->assertOk()->assertViewHas('totals.selected', 3);
        $this->get('/reports?deadline_from=2026-10-18')->assertOk()->assertViewHas('totals.selected', 3);
    }

    public function test_current_attempt_not_first_attempt_or_review_controls_completed_late_and_reopened_work(): void
    {
        $task = $this->completed('Recompleted task', '2026-10-17 08:00:00', '2026-10-17 09:00:00');
        $first = $task->currentCompletion;
        app(TaskWorkflow::class)->reopen($task, $this->manager, 'Check another shelf.');
        app(TaskWorkflow::class)->reschedule($task->fresh(), $this->manager, now()->addHour(), 'Extra work needed.');
        app(TaskWorkflow::class)->complete($task->fresh(), $this->worker, 'All shelves checked.');
        $this->assertSame(0, $this->report()['totals']['completed_late']);
        $this->assertSame('Late', $this->report()['rows'][0]['first_punctuality']);
        $this->assertSame('On time', $this->report()['rows'][0]['overdue_state']);
        $this->travel(2)->hours();
        app(TaskWorkflow::class)->markReviewed($task->fresh(), $this->manager, null);
        $this->assertSame(0, $this->report()['totals']['completed_late']);
        app(TaskWorkflow::class)->reopen($task->fresh(), $this->manager, 'One more check.');
        $this->assertSame(['selected' => 1, 'still_overdue' => 1, 'completed_late' => 0], $this->report()['totals']);
        $this->assertSame('—', $this->report()['rows'][0]['completed']);
        $this->assertSame('2026-10-17 08:00:00', $first->fresh()->due_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $task->submissions()->count());
    }

    public function test_retained_completion_deadline_is_used_for_late_duration_and_new_deadline_filter(): void
    {
        $task = $this->completed('Preserved deadline', '2026-10-17 10:00:00', '2026-10-17 11:00:00');
        // Simulate a legacy row whose current deadline differs from the immutable attempt snapshot.
        $task->update(['due_at' => '2026-10-19 10:00:00']);
        $filters = ['deadline_from' => '2026-10-17', 'deadline_to' => '2026-10-17', 'overdue_status' => 'completed_late'];
        $row = $this->report($filters)['rows'][0];
        $this->assertSame('17 Oct 2026, 12:00:00', $row['deadline']);
        $this->assertSame('19 Oct 2026, 12:00', $row['due']);
        $this->assertSame('1h', $row['time_overdue_or_late']);
        $this->assertSame([], $this->report($filters + ['basis' => 'due', 'from' => '2026-10-17', 'to' => '2026-10-17'])['rows']);
        $legacy = $this->task('Legacy known completion', ['status' => 'completed', 'completed_at' => '2026-10-18 11:30:00']);
        $this->assertSame(2, $this->report(['overdue_status' => 'completed_late'])['totals']['completed_late']);
        $this->assertSame('30m', collect($this->report()['rows'])->firstWhere('id', $legacy->id)['time_overdue_or_late']);
    }

    public function test_recurring_occurrences_are_independent_and_future_previews_are_not_counted(): void
    {
        foreach (['daily', 'specific_dates'] as $frequency) {
            $template = TaskTemplate::create(['title' => 'Restock beer fridges', 'instructions' => 'Restock.', 'area' => 'Beer', 'priority' => 'normal', 'required_evidence' => ['note'], 'employee_id' => $this->worker->id, 'creator_id' => $this->owner->id, 'category_id' => $this->category->id, 'frequency' => $frequency, 'next_date' => '2026-10-19', 'due_time' => '16:00', 'active' => true, 'specific_dates' => $frequency === 'specific_dates' ? ['2026-10-19', '2026-10-22'] : null]);
            $this->task($template->title, ['task_template_id' => $template->id, 'occurrence_date' => '2026-10-15']);
            $this->completed($template->title, '2026-10-16 14:00:00', '2026-10-16 15:00:00', ['task_template_id' => $template->id, 'occurrence_date' => '2026-10-16']);
            $this->completed($template->title, '2026-10-17 14:00:00', '2026-10-17 13:00:00', ['task_template_id' => $template->id, 'occurrence_date' => '2026-10-17']);
            $this->task($template->title, ['task_template_id' => $template->id, 'occurrence_date' => '2026-10-18', 'due_at' => '2026-10-18 14:00:00']);
        }
        $report = $this->report();
        $this->assertSame(['selected' => 8, 'still_overdue' => 2, 'completed_late' => 2], $report['totals']);
        $this->assertCount(8, array_unique(array_column($report['rows'], 'id')));
        $this->assertSame(2, $this->report(['overdue_status' => 'completed_late'])['totals']['selected']);
        $this->assertSame(8, Task::count());
    }

    public function test_owner_manager_employee_and_store_boundaries_apply_to_filtered_reports_and_exports(): void
    {
        $visible = $this->task('Authorised task');
        $this->task('Unscoped task', ['employee_id' => $this->unscoped->id]);
        $personal = $this->task('Manager personal task', ['employee_id' => $this->manager->id]);
        $otherStore = Store::create(['company_id' => $this->store->company_id, 'owner_id' => $this->owner->id, 'name' => 'Other branch', 'active' => true]);
        $this->owner->stores()->attach($otherStore, ['active' => true]);
        $this->manager->stores()->attach($otherStore, ['active' => true]);
        $this->worker->stores()->attach($otherStore, ['active' => true]);
        $this->task('Shared staff other branch', ['store_id' => $otherStore->id]);
        $foreign = Company::create(['name' => 'Foreign company']);
        $foreignWorker = $this->person('employee', 'Foreign worker', $foreign);
        $this->task('Foreign company task', ['employee_id' => $foreignWorker->id, 'store_id' => $foreignWorker->storeId()]);
        foreach ([$this->owner, $this->manager, $this->worker] as $user) {
            $response = $this->actingAs($user)->withSession(['workspace.store_id' => $this->store->id])->get('/reports?overdue_status=still_overdue&store_id='.$otherStore->id)->assertOk();
            $response->assertDontSee('Shared staff other branch')->assertDontSee('Foreign company task')->assertDontSee('Foreign worker');
            $this->assertContains($visible->id, array_column($response->viewData('rows'), 'id'));
            if ($user->role !== 'admin') {
                $response->assertDontSee('Unscoped task')->assertDontSee('Unscoped worker');
            }
        }
        $this->actingAs($this->manager)->get('/reports?scope=my&overdue_status=still_overdue')->assertOk()->assertViewHas('rows', fn ($rows) => array_column($rows, 'id') === [$personal->id]);
        $this->get('/reports?scope=team&overdue_status=still_overdue')->assertOk()->assertViewHas('rows', fn ($rows) => array_column($rows, 'id') === [$visible->id]);
        $forged = ['employee_id' => $this->unscoped->id, 'overdue_status' => 'still_overdue'];
        $this->get('/reports?'.http_build_query($forged))->assertOk()->assertViewHas('totals.selected', 0)->assertDontSee('Unscoped worker');
        $this->assertSame([], $this->report($forged, $this->manager)['summary']);
        $this->get('/reports?'.http_build_query($forged + ['format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $sheet = $this->spreadsheet($forged);
        $this->assertSame([[null]], $sheet->getSheetByName('Task details')->toArray());
        $this->actingAs($this->owner)->withSession(['workspace.store_id' => $otherStore->id])->get('/reports?overdue_status=still_overdue')->assertOk()->assertSee('Shared staff other branch')->assertDontSee('Authorised task');
    }

    private function spreadsheet(array $filters)
    {
        $response = $this->get('/reports?'.http_build_query($filters + ['format' => 'xlsx']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'overdue-report');
        try {
            file_put_contents($path, $response->streamedContent());

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    public function test_screen_pdf_and_excel_preserve_the_filtered_rows_totals_and_existing_report_details(): void
    {
        $late = $this->completed('Included late task', '2026-10-17 10:00:00', '2026-10-17 11:15:00', ['priority' => 'high']);
        $this->task('Excluded open task');
        $this->completed('Excluded early task', '2026-10-17 10:00:00', '2026-10-17 09:00:00');
        $filters = ['employee_id' => $this->worker->id, 'overdue_status' => 'completed_late', 'deadline_from' => '2026-10-17', 'deadline_to' => '2026-10-17', 'priority' => 'high'];
        $data = $this->report($filters);
        $response = $this->actingAs($this->owner)->get('/reports?'.http_build_query($filters))->assertOk()->assertSee($late->title)->assertDontSee('Excluded open task')->assertDontSee('Excluded early task');
        foreach (['Comparison · selected task cohort', 'Individual task records', 'First on time', 'On-time rate', 'Deadline from · SAST', 'Completed late', '1h 15m'] as $text) {
            $response->assertSee($text);
        }
        $response->assertSee('overdue_status=completed_late')->assertSee('deadline_from=2026-10-17');
        $html = view('reports.pdf', $data)->render();
        foreach (['Report company', 'Cape Town branch', 'Reporting period:', 'Deadline period:', '17 Oct 2026', 'Selected tasks: 1', 'Still overdue: 0', 'Completed late: 1', 'Discussion notes', 'Follow-up actions', 'Responsible person', 'Included late task', '1h 15m'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Excluded open task', $html);
        $this->assertStringNotContainsString('Excluded early task', $html);
        $pdf = $this->get('/reports?'.http_build_query($filters + ['format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDownload('tasksure-report.pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $book = $this->spreadsheet($filters);
        $rows = $book->getSheetByName('Task details')->toArray(null, true, true, false);
        $this->assertCount(2, $rows);
        $this->assertEquals(array_keys($data['rows'][0]), $rows[0]);
        $this->assertEquals(array_map('strval', array_values($data['rows'][0])), array_map('strval', $rows[1]));
        $meta = collect($book->getSheetByName('Metric definitions')->toArray())->mapWithKeys(fn ($row) => [$row[0] => $row[1]]);
        $this->assertSame('Completed late', $meta['Overdue reporting']);
        $this->assertSame('2026-10-17', $meta['Deadline from']);
        $this->assertSame('1', $meta['Completed late']);
        $this->assertSame('0', $meta['Still overdue']);
        $this->assertSame('Report company', $meta['Company']);
    }

    public function test_empty_results_remain_exportable_with_zero_totals(): void
    {
        $this->actingAs($this->owner)->get('/reports?overdue_status=completed_late')->assertOk()->assertViewHas('totals', ['selected' => 0, 'still_overdue' => 0, 'completed_late' => 0])->assertSee('No tasks match these filters.');
        $this->get('/reports?overdue_status=completed_late&format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->spreadsheet(['overdue_status' => 'completed_late']);
    }

    public function test_new_filter_validation_applies_to_every_export_and_does_not_query_an_invalid_period(): void
    {
        $this->actingAs($this->owner);
        foreach (['', 'pdf', 'xlsx'] as $format) {
            foreach ([['overdue_status' => 'made_up'], ['deadline_from' => '2026-02-30'], ['deadline_from' => '2026-10-20', 'deadline_to' => '2026-10-19'], ['deadline_to' => ['2026-10-20']]] as $invalid) {
                $this->getJson('/reports?'.http_build_query($invalid + ['format' => $format]))->assertUnprocessable();
            }
        }
    }
}
