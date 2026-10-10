<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Category;
use App\Models\PushDevice;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/mobile/v1';

    private User $employee;

    private User $other;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employee = $this->user('employee');
        $this->other = $this->user('employee');
        $this->admin = $this->user('admin');
        $this->category = Category::create(['name' => 'Stock']);
        Storage::fake('evidence');
    }

    private function user(string $role): User
    {
        return User::create(['name' => 'Test '.$role, 'email' => uniqid().'@mobile.test', 'role' => $role, 'active' => true, 'password' => 'EmployeePassword123!']);
    }

    private function task(array $extra = []): Task
    {
        return Task::create($extra + ['employee_id' => $this->employee->id, 'creator_id' => $this->admin->id, 'category_id' => $this->category->id, 'title' => 'Mobile shelf check', 'instructions' => 'Check and photograph the shelf.', 'area' => 'Beer', 'priority' => 'high', 'required_evidence' => ['photo', 'note'], 'due_at' => now()->addHour()]);
    }

    private function bearer(User $user, array $abilities = ['employee'], $expires = null): string
    {
        return $user->createToken('test-phone', $abilities, $expires ?? now()->addDay())->plainTextToken;
    }

    private function authenticate(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_employee_login_issues_a_hashed_expiring_token_and_rejects_web_roles(): void
    {
        $response = $this->postJson(self::API.'/login', ['email' => $this->employee->email, 'password' => 'EmployeePassword123!', 'device_name' => 'Android test'])
            ->assertOk()->assertJsonPath('user.id', $this->employee->id)->assertJsonMissingPath('user.password')
            ->assertJsonPath('config.timezone', 'Africa/Johannesburg');
        $token = $response->json('token');
        $this->assertNotEquals($token, $this->employee->tokens()->first()->token);
        $this->assertNotNull($this->employee->tokens()->first()->expires_at);
        $this->authenticate($token);
        $this->getJson(self::API.'/me')->assertOk();
        $this->postJson(self::API.'/login', ['email' => $this->admin->email, 'password' => 'EmployeePassword123!', 'device_name' => 'Owner phone'])->assertForbidden();
        $this->employee->update(['active' => false]);
        $this->postJson(self::API.'/login', ['email' => $this->employee->email, 'password' => 'EmployeePassword123!', 'device_name' => 'Inactive phone'])->assertUnprocessable();
    }

    public function test_api_requires_employee_bearer_access_with_active_account_and_ability(): void
    {
        $this->getJson(self::API.'/tasks')->assertUnauthorized();
        $this->actingAs($this->employee)->getJson(self::API.'/tasks')->assertUnauthorized();
        $this->authenticate($this->bearer($this->admin));
        $this->getJson(self::API.'/tasks')->assertForbidden();
        $this->authenticate($this->bearer($this->employee, ['reports']));
        $this->getJson(self::API.'/tasks')->assertForbidden();
        $this->authenticate($this->bearer($this->employee, ['employee'], now()->subMinute()));
        $this->getJson(self::API.'/tasks')->assertUnauthorized();
        $valid = $this->bearer($this->employee);
        $this->employee->update(['active' => false]);
        $this->authenticate($valid);
        $this->getJson(self::API.'/tasks')->assertUnauthorized();
        $this->assertEquals(0, $this->employee->tokens()->count());
    }

    public function test_tasks_calendar_and_notifications_do_not_cross_employee_boundaries(): void
    {
        $task = $this->task();
        $private = $this->task(['employee_id' => $this->other->id]);
        $alert = Alert::create(['user_id' => $this->other->id, 'task_id' => $private->id, 'type' => 'assigned', 'message' => 'Private update', 'dedupe_key' => 'private-update']);
        $this->authenticate($this->bearer($this->employee));
        $this->getJson(self::API.'/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $task->id);
        $this->getJson(self::API.'/tasks?employee_id='.$this->other->id)->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/tasks/'.$private->id)->assertForbidden();
        $this->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'approve'])->assertForbidden();
        $this->getJson(self::API.'/calendar?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(1);
        $this->getJson(self::API.'/calendar?from=invalid&to=invalid')->assertUnprocessable();
        $this->getJson(self::API.'/calendar?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
        $this->getJson(self::API.'/notifications')->assertJsonCount(0, 'data');
        $this->postJson(self::API.'/notifications/'.$alert->id.'/read')->assertForbidden();
    }

    public function test_mobile_evidence_and_resubmissions_use_the_existing_workflow(): void
    {
        $task = $this->task();
        $token = $this->bearer($this->employee);
        $this->authenticate($token);
        $this->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'start'])->assertOk();
        $this->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'submit', 'note' => 'All done'])->assertUnprocessable();
        $this->post(self::API.'/tasks/'.$task->id.'/uploads', ['file' => UploadedFile::fake()->image('proof.jpg')], ['Accept' => 'application/json'])->assertOk();
        $attachment = $task->attachments()->firstOrFail();
        $this->getJson(self::API.'/tasks/'.$task->id)->assertJsonPath('data.draft_evidence.0.id', $attachment->id)->assertJsonMissingPath('data.draft_evidence.0.path');
        $this->getJson(self::API.'/attachments/'.$attachment->id.'?preview=1')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'submit', 'note' => 'Checked every shelf'])->assertOk();
        $first = $task->submissions()->firstOrFail();
        $originalDue = $first->due_at->copy();
        $this->post(self::API.'/tasks/'.$task->id.'/uploads', ['file' => UploadedFile::fake()->image('late.jpg')], ['Accept' => 'application/json'])->assertUnprocessable();
        app(TaskWorkflow::class)->review($task, $this->admin, 'changes_requested', 'Photograph the bottom shelf');
        app(TaskWorkflow::class)->reschedule($task, $this->admin, now()->addHours(3), 'Extra stock arrived');
        $this->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'submit', 'note' => 'Second attempt'])->assertUnprocessable();
        $this->post(self::API.'/tasks/'.$task->id.'/uploads', ['file' => UploadedFile::fake()->image('fresh.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->postJson(self::API.'/tasks/'.$task->id.'/actions', ['action' => 'submit', 'note' => 'Bottom shelf checked'])->assertOk();
        $this->getJson(self::API.'/tasks/'.$task->id)->assertJsonCount(2, 'data.submissions')->assertJsonPath('data.submissions.0.review_reason', 'Photograph the bottom shelf');
        $this->assertTrue($first->fresh()->due_at->equalTo($originalDue));
        $this->assertCount(1, $first->attachments);
        $this->getJson(self::API.'/tasks?has_submissions=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.submission_count', 2);
        $this->authenticate($this->bearer($this->other));
        $this->getJson(self::API.'/attachments/'.$attachment->id)->assertForbidden();
        $this->post(self::API.'/tasks/'.$task->id.'/uploads', ['file' => UploadedFile::fake()->image('stolen.jpg')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_logout_revokes_only_this_token_and_its_push_device(): void
    {
        $phone = $this->bearer($this->employee);
        $otherPhone = $this->bearer($this->employee);
        $this->authenticate($phone);
        $this->postJson(self::API.'/devices', ['platform' => 'android', 'token' => 'firebase-test-token'])->assertOk();
        $device = PushDevice::firstOrFail();
        $this->assertNotEquals('firebase-test-token', $device->getRawOriginal('token'));
        $this->postJson(self::API.'/logout')->assertOk();
        $this->assertDatabaseCount('push_devices', 0);
        $this->authenticate($phone);
        $this->getJson(self::API.'/me')->assertUnauthorized();
        $this->authenticate($otherPhone);
        $this->getJson(self::API.'/me')->assertOk();
    }

    public function test_admin_password_change_and_password_reset_revoke_mobile_access(): void
    {
        $token = $this->bearer($this->employee);
        $this->actingAs($this->admin)->post('/admin/users/'.$this->employee->id, ['name' => $this->employee->name, 'email' => $this->employee->email, 'role' => 'employee', 'active' => '1', 'password' => 'ChangedPassword123!', 'password_confirmation' => 'ChangedPassword123!'])->assertRedirect()->assertSessionHasNoErrors();
        $this->authenticate($token);
        $this->getJson(self::API.'/me')->assertUnauthorized();
        $this->bearer($this->employee);
        $reset = Password::createToken($this->employee);
        $this->post('/reset-password', ['email' => $this->employee->email, 'token' => $reset, 'password' => 'ResetPassword123!', 'password_confirmation' => 'ResetPassword123!'])->assertRedirect('/login');
        $this->assertEquals(0, $this->employee->tokens()->count());
    }

    public function test_mobile_login_is_throttled_and_cors_allows_native_origins_only(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::API.'/login', ['email' => 'nobody@mobile.test', 'password' => 'wrong', 'device_name' => 'test'])->assertUnprocessable();
        }
        $this->postJson(self::API.'/login', ['email' => 'nobody@mobile.test', 'password' => 'wrong', 'device_name' => 'test'])->assertStatus(429);
        $this->call('OPTIONS', self::API.'/me', [], [], [], ['HTTP_ORIGIN' => 'capacitor://localhost', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization'])->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', 'capacitor://localhost');
        $this->call('OPTIONS', self::API.'/me', [], [], [], ['HTTP_ORIGIN' => 'https://untrusted.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
