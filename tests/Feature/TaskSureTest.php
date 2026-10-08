<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppGateway;
use App\Jobs\SendAlertEmail;
use App\Models\Alert;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ReportService;
use App\Services\ScheduledTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class TaskSureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $employee;

    private User $outsider;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user('admin');
        $this->manager = $this->user('manager');
        $this->employee = $this->user('employee');
        $this->outsider = $this->user('employee');
        $this->manager->employees()->attach($this->employee);
        $this->category = Category::create(['name' => 'Restocking']);
        Storage::fake('evidence');
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role.' person '.uniqid(), 'email' => uniqid().'@example.test', 'role' => $role, 'password' => 'TestPassword123!', 'active' => true]);
    }

    private function task(array $extra = []): Task
    {
        return Task::create($extra + ['title' => 'Restock beer fridges', 'instructions' => 'Fill gaps and photograph the shelf.', 'area' => 'Beer fridges', 'priority' => 'normal', 'employee_id' => $this->employee->id, 'creator_id' => $this->admin->id, 'category_id' => $this->category->id, 'required_evidence' => ['note'], 'due_at' => now()->addHour()]);
    }

    private function action(Task $t, User $u, array $data)
    {
        return $this->actingAs($u)->post('/tasks/'.$t->id.'/action', $data);
    }

    public function test_employee_and_manager_record_and_file_boundaries(): void
    {
        $t = $this->task();
        $other = $this->task(['employee_id' => $this->outsider->id, 'title' => 'Private receiving task']);
        $file = Attachment::create(['task_id' => $other->id, 'user_id' => $this->outsider->id, 'path' => 'evidence/test.pdf', 'original_name' => 'test.pdf', 'mime' => 'application/pdf', 'size' => 10, 'kind' => 'document']);
        foreach ([$this->employee, $this->manager] as $u) {
            $this->actingAs($u)->get('/tasks/'.$other->id)->assertForbidden();
            $this->get('/attachments/'.$file->id)->assertForbidden();
            $this->get('/tasks')->assertDontSee('Private receiving task');
            $this->get('/calendar/events')->assertJsonCount(1);
            $this->get('/reports')->assertDontSee('Private receiving task');
            $this->get('/tasks/'.$t->id)->assertOk();
        }
        $this->action($t, $this->employee, ['action' => 'reschedule', 'due_at' => '2026-10-09T17:00', 'reason' => 'No', 'confirmed' => '1'])->assertForbidden();
        $this->action($t, $this->employee, ['action' => 'approve'])->assertForbidden();
        $this->actingAs($this->manager)->get('/admin/users')->assertForbidden();
    }

    public function test_assignment_reassignment_and_scope_validation(): void
    {
        $data = ['title' => 'Opening check', 'instructions' => 'Check exits', 'area' => 'Entrance', 'category_id' => $this->category->id, 'priority' => 'high', 'required_evidence' => ['note'], 'due_at' => '2026-10-09T17:00'];
        $this->actingAs($this->manager)->post('/tasks', $data + ['employee_id' => $this->outsider->id])->assertForbidden();
        $this->post('/tasks', $data + ['employee_id' => $this->employee->id])->assertRedirect();
        $t = Task::where('title', 'Opening check')->firstOrFail();
        $this->assertEquals('2026-10-09 15:00:00', $t->due_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('alerts', ['task_id' => $t->id, 'user_id' => $this->employee->id, 'type' => 'assigned']);
        $this->action($t, $this->manager, ['action' => 'reassign', 'employee_id' => $this->outsider->id, 'reason' => 'Change shift'])->assertForbidden();
        $this->action($t, $this->admin, ['action' => 'reassign', 'employee_id' => $this->outsider->id, 'reason' => 'Change shift'])->assertRedirect();
        $this->assertEquals($this->outsider->id, $t->fresh()->employee_id);
        $this->assertDatabaseHas('task_activities', ['task_id' => $t->id, 'type' => 'reassigned']);
    }

    public function test_required_evidence_and_safe_uploads(): void
    {
        $t = $this->task(['required_evidence' => ['photo', 'document', 'note']]);
        $this->action($t, $this->employee, ['action' => 'submit', 'note' => 'Done'])->assertSessionHasErrors('action');
        $this->actingAs($this->employee)->post('/tasks/'.$t->id.'/uploads', ['file' => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')])->assertSessionHasErrors('file');
        $this->post('/tasks/'.$t->id.'/uploads', ['file' => UploadedFile::fake()->image('proof.jpg')])->assertRedirect();
        $this->post('/tasks/'.$t->id.'/uploads', ['file' => UploadedFile::fake()->createWithContent('count.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")])->assertRedirect();
        $this->action($t, $this->employee, ['action' => 'submit', 'note' => 'Completed count and refill'])->assertRedirect();
        $this->assertEquals('submitted', $t->fresh()->status);
        $this->assertCount(2, $t->submissions()->first()->attachments);
        foreach ($t->attachments as $a) {
            Storage::disk('evidence')->assertExists($a->path);
            $this->get('/attachments/'.$a->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        }$this->post('/tasks/'.$t->id.'/uploads', ['file' => UploadedFile::fake()->image('late.jpg')])->assertUnprocessable();
    }

    public function test_submission_punctuality_deadline_changes_and_attempts(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(8, 0));
        $t = $this->task(['due_at' => now()->addHour()]);
        $this->action($t, $this->employee, ['action' => 'submit', 'note' => 'First attempt'])->assertRedirect();
        $first = $t->submissions()->first();
        $original = $first->due_at->copy();
        $this->travel(2)->hours();
        $this->action($t, $this->manager, ['action' => 'changes_requested'])->assertSessionHasErrors('reason');
        $this->action($t, $this->manager, ['action' => 'changes_requested', 'reason' => 'Check the lower shelf'])->assertRedirect();
        $this->action($t, $this->manager, ['action' => 'reschedule', 'due_at' => '2026-10-08T15:00', 'reason' => 'New shift', 'confirmed' => '1'])->assertRedirect();
        $this->assertTrue($first->fresh()->due_at->equalTo($original));
        $this->action($t, $this->employee, ['action' => 'submit', 'note' => 'Second attempt'])->assertRedirect();
        $this->travel(5)->hours();
        $this->action($t, $this->manager, ['action' => 'approve'])->assertRedirect();
        $this->assertCount(2, $t->submissions()->get());
        $this->assertCount(1, $t->deadlineChanges()->get());
        $report = app(ReportService::class)->run($this->admin, []);
        $row = $report['rows'][0];
        $this->assertEquals('On time', $row['first_punctuality']);
        $this->assertEquals('On time', $row['accepted_punctuality']);
        $this->assertEquals(1, $row['corrections']);
        $this->assertNotEquals($row['accepted_submission'], $row['approved']);
        $this->assertStringContainsString('Check the lower shelf', $row['attempts']);
    }

    public function test_resubmission_requires_fresh_proof(): void
    {
        $t = $this->task(['required_evidence' => ['photo']]);
        $this->actingAs($this->employee)->post('/tasks/'.$t->id.'/uploads', ['file' => UploadedFile::fake()->image('first.jpg')]);
        $this->action($t, $this->employee, ['action' => 'submit']);
        $this->action($t, $this->manager, ['action' => 'changes_requested', 'reason' => 'Wrong shelf']);
        $this->action($t, $this->employee, ['action' => 'submit'])->assertSessionHasErrors('action');
        $this->assertCount(1, $t->submissions()->first()->attachments);
    }

    public function test_blockers_do_not_extend_deadlines_and_notifications_deduplicate(): void
    {
        $t = $this->task(['due_at' => now()->subHour()]);
        $due = $t->due_at->copy();
        $this->action($t, $this->employee, ['action' => 'blocker', 'body' => 'No stock in receiving bay'])->assertRedirect();
        $this->assertTrue($due->equalTo($t->fresh()->due_at));
        $this->assertDatabaseHas('alerts', ['user_id' => $this->manager->id, 'task_id' => $t->id, 'type' => 'blocker']);
        app(ScheduledTasks::class)->reminders();
        $count = Alert::count();
        app(ScheduledTasks::class)->reminders();
        $this->assertEquals($count, Alert::count());
        $this->assertDatabaseHas('alerts', ['user_id' => $this->employee->id, 'type' => 'overdue']);
    }

    public function test_recurring_occurrences_are_idempotent_and_future_edits_preserve_history(): void
    {
        $p = TaskTemplate::create(['title' => 'Clean aisles', 'instructions' => 'Sweep', 'area' => 'Wine', 'priority' => 'normal', 'employee_id' => $this->employee->id, 'creator_id' => $this->admin->id, 'category_id' => $this->category->id, 'required_evidence' => ['note'], 'frequency' => 'daily', 'next_date' => now()->timezone('Africa/Johannesburg')->subDay()->toDateString(), 'anchor_day' => 1, 'due_time' => '17:00', 'active' => true]);
        $s = app(ScheduledTasks::class);
        $this->assertEquals(2, $s->occurrences());
        $this->assertEquals(0, $s->occurrences());
        $this->assertEquals(2, Task::count());
        $p->update(['title' => 'New routine']);
        $this->travel(1)->days();
        $this->assertEquals(1, $s->occurrences());
        $this->assertEquals(2, Task::where('title', 'Clean aisles')->count());
        $this->assertEquals(1, Task::where('title', 'New routine')->count());
    }

    public function test_monthly_anchor_survives_short_months(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 31));
        $p = TaskTemplate::create(['title' => 'Monthly count', 'instructions' => 'Count', 'area' => 'Wine', 'priority' => 'normal', 'employee_id' => $this->employee->id, 'creator_id' => $this->admin->id, 'category_id' => $this->category->id, 'required_evidence' => ['note'], 'frequency' => 'monthly', 'next_date' => '2026-01-31', 'anchor_day' => 31, 'due_time' => '17:00', 'active' => true]);
        $s = app(ScheduledTasks::class);
        $s->occurrences();
        $this->assertEquals('2026-02-28', $p->fresh()->next_date->toDateString());
        $this->travelTo(now()->setDate(2026, 2, 28));
        $s->occurrences();
        $this->assertEquals('2026-03-31', $p->fresh()->next_date->toDateString());
    }

    public function test_reports_and_exports_share_filters_and_values(): void
    {
        $t = $this->task();
        $this->task(['employee_id' => $this->outsider->id, 'title' => 'Excluded task']);
        $this->action($t, $this->employee, ['action' => 'submit', 'note' => 'Done']);
        $this->action($t, $this->manager, ['action' => 'approve']);
        $f = ['employee_id' => $this->employee->id, 'category_id' => $this->category->id, 'basis' => 'due'];
        $report = app(ReportService::class)->run($this->admin, $f);
        $this->actingAs($this->admin)->get('/reports?'.http_build_query($f))->assertOk()->assertSee($t->title)->assertDontSee('Excluded task');
        $r = $this->get('/reports?'.http_build_query($f + ['format' => 'xlsx']))->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $r->streamedContent());
        $book = IOFactory::load($tmp);
        unlink($tmp);
        $rows = $book->getSheetByName('Task details')->toArray();
        $this->assertEquals(array_keys($report['rows'][0]), $rows[0]);
        $this->assertEquals(array_map('strval', array_values($report['rows'][0])), array_map(fn ($v) => (string) $v, $rows[1]));
        $this->assertCount(2, $rows);
        $summary = $book->getSheetByName('Comparison')->toArray();
        $this->assertEquals(array_map('strval', array_values($report['summary'][0])), array_map(fn ($v) => (string) $v, $summary[1]));
        $pdf = $this->get('/reports?'.http_build_query($f + ['format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertGreaterThan(5000, strlen($pdf->getContent()));
    }

    public function test_deactivation_and_admin_audit(): void
    {
        $this->actingAs($this->admin)->post('/admin/users/'.$this->employee->id, ['name' => $this->employee->name, 'email' => $this->employee->email, 'role' => 'employee', 'active' => '0'])->assertRedirect();
        $this->assertDatabaseHas('account_audits', ['subject_id' => $this->employee->id, 'action' => 'updated']);
        $this->actingAs($this->employee->fresh())->get('/')->assertRedirect('/login');
        $this->post('/login', ['email' => $this->employee->email, 'password' => 'TestPassword123!'])->assertSessionHasErrors('email');
        $this->get('/register')->assertNotFound();
    }

    public function test_login_rate_limit_and_password_reset(): void
    {
        $this->get('/logout')->assertStatus(405);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'invalid@test.test', 'password' => 'no'])->assertRedirect();
        }$this->post('/login', ['email' => 'invalid@test.test', 'password' => 'no'])->assertStatus(429);
        $this->get('/forgot-password')->assertOk();
        config(['mail.default' => 'array']);
        $this->post('/forgot-password', ['email' => $this->employee->email])->assertSessionHasErrors('email');
        $token = Password::createToken($this->employee);
        $this->post('/reset-password', ['token' => $token, 'email' => $this->employee->email, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('NewPassword123!', $this->employee->fresh()->password));
    }

    public function test_admin_settings_and_calendar_reschedule_confirmation(): void
    {
        $t = $this->task();
        $this->action($t, $this->manager, ['action' => 'reschedule', 'due_at' => '2026-10-09T17:00', 'reason' => 'Shift change'])->assertSessionHasErrors('confirmed');
        $this->actingAs($this->admin)->get('/admin/settings')->assertOk();
        $this->get('/admin/users')->assertOk();
        $this->get('/calendar')->assertOk();
        $this->post('/admin/reminders', ['reminder_minutes' => 90])->assertRedirect();
        $this->assertDatabaseHas('settings', ['key' => 'reminder_minutes', 'value' => '90']);
    }

    public function test_approved_and_cancelled_tasks_are_closed(): void
    {
        $t = $this->task();
        $this->action($t, $this->admin, ['action' => 'cancel', 'reason' => 'Delivery cancelled'])->assertRedirect();
        $this->action($t, $this->employee, ['action' => 'submit', 'note' => 'Done'])->assertForbidden();
        $this->assertEquals('Delivery cancelled', $t->fresh()->cancellation_reason);
        $r = app(ReportService::class)->run($this->admin, []);
        $this->assertEquals(0, $r['summary'][0]['submitted_denominator']);
    }

    public function test_late_submission_and_date_basis_are_explicit(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(8, 0));
        $t = $this->task(['due_at' => now()->subHour()]);
        $this->action($t, $this->employee, ['action' => 'submit', 'note' => 'Completed after the deadline'])->assertRedirect();
        $this->action($t, $this->manager, ['action' => 'approve'])->assertRedirect();
        $r = app(ReportService::class)->run($this->admin, []);
        $this->assertEquals('Late', $r['rows'][0]['first_punctuality']);
        $this->assertEquals('Late', $r['rows'][0]['accepted_punctuality']);
        $this->assertEquals(1, $r['summary'][0]['first_late']);
        $t->update(['created_at' => now()->subDays(2)]);
        $period = ['from' => '2026-10-08', 'to' => '2026-10-08'];
        $this->assertCount(0, app(ReportService::class)->run($this->admin, $period + ['basis' => 'assigned'])['rows']);
        $this->assertCount(1, app(ReportService::class)->run($this->admin, $period + ['basis' => 'due'])['rows']);
        $this->assertCount(1, app(ReportService::class)->run($this->admin, $period + ['basis' => 'approved'])['rows']);
    }

    public function test_unconfigured_delivery_is_never_recorded_as_sent_and_upload_limits_apply(): void
    {
        $t = $this->task();
        config(['tasksure.upload_max_kb' => 1]);
        $this->actingAs($this->employee)->post('/tasks/'.$t->id.'/uploads', ['file' => UploadedFile::fake()->image('large.jpg')->size(2)])->assertSessionHasErrors('file');
        $a = Alert::create(['user_id' => $this->employee->id, 'task_id' => $t->id, 'type' => 'assigned', 'message' => 'Assignment', 'dedupe_key' => 'mail-test', 'email_status' => 'queued']);
        config(['tasksure.email_enabled' => true, 'mail.default' => 'log']);
        try {
            (new SendAlertEmail($a->id))->handle();
            $this->fail('Unconfigured email must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }
        $this->assertEquals('failed', $a->fresh()->email_status);
        $this->assertNull($a->fresh()->emailed_at);
        try {
            app(WhatsAppGateway::class)->send('fictional-recipient', 'test');
            $this->fail('Unconfigured WhatsApp must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unconfigured', $e->getMessage());
        }
    }
}
