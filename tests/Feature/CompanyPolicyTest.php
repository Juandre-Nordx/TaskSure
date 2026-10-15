<?php

namespace Tests\Feature;

use App\Jobs\SendAlertPush;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyPolicyVersion;
use App\Models\Task;
use App\Models\User;
use App\Services\CompanyPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/mobile/v1';

    private User $owner;

    private User $manager;

    private User $employee;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->person('admin');
        $this->manager = $this->person('manager');
        $this->employee = $this->person('employee');
        $this->manager->employees()->attach($this->employee);
        $category = Category::create(['name' => 'Policy QA']);
        $this->task = Task::create(['employee_id' => $this->employee->id, 'creator_id' => $this->owner->id, 'category_id' => $category->id, 'title' => 'Existing assignment', 'instructions' => 'Keep working normally.', 'area' => 'Stock', 'priority' => 'normal', 'required_evidence' => ['note'], 'due_at' => now()->addDay()]);
        Storage::fake('evidence');
    }

    private function person(string $role, ?Company $company = null): User
    {
        $user = User::create(['name' => 'Policy '.$role.' '.uniqid(), 'email' => uniqid().'@policy.test', 'role' => $role, 'active' => true, 'password' => 'PolicyPassword123!', 'company_id' => $company?->id]);

        return $user;
    }

    private function publish(?User $owner = null, string $title = 'Company safety policy'): CompanyPolicyVersion
    {
        $owner ??= $this->owner;
        $service = app(CompanyPolicyService::class);
        $revision = $service->company($owner)->policy_draft_revision;
        $service->saveDraft($owner, $title, "Read the full policy.\n\nHandle stock safely.\nReport incidents.", $revision);

        return $service->publish($owner, $revision + 1);
    }

    private function mobile(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $token = $user->createToken('Policy phone', ['mobile', $user->role], now()->addDay())->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function acknowledge(CompanyPolicyVersion $policy, array $extra = [])
    {
        return $this->postJson(self::API.'/company-policy/acknowledge', $extra + ['policy_version_id' => $policy->id, 'acknowledged' => true]);
    }

    public function test_owner_saves_draft_without_notices_or_access_interruption_then_publishes(): void
    {
        config(['mobile.push_enabled' => true]);
        Bus::fake([SendAlertPush::class]);
        $this->actingAs($this->owner)->get('/admin/company-policy')->assertOk()->assertSee('Company policy');
        $this->post('/admin/company-policy/draft', ['title' => 'Store policy', 'content' => "First paragraph.\nSecond paragraph.", 'draft_revision' => 0])->assertRedirect('/admin/company-policy')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('company_policy_versions', 0);
        $this->assertDatabaseCount('alerts', 0);
        $this->actingAs($this->employee)->get('/tasks')->assertOk();
        $this->actingAs($this->owner)->post('/admin/company-policy/publish', ['draft_revision' => 1])->assertRedirect('/admin/company-policy')->assertSessionHasNoErrors();
        $policy = CompanyPolicyVersion::firstOrFail();
        $this->assertSame($this->owner->company_id, $policy->company_id);
        $this->assertSame(1, $policy->version);
        $this->assertSame($this->owner->id, $policy->published_by_id);
        $this->assertNotNull($policy->published_at);
        $this->assertDatabaseCount('alerts', 2);
        foreach ([$this->manager, $this->employee] as $staff) {
            $this->assertDatabaseHas('alerts', ['user_id' => $staff->id, 'type' => 'policy_published', 'task_id' => null]);
        }
        Bus::assertDispatchedTimes(SendAlertPush::class, 2);
        $this->get('/admin/company-policy')->assertOk()->assertSee('Outstanding')->assertSee('Version 1');
        $this->post('/admin/company-policy/publish', ['draft_revision' => 1])->assertSessionHasErrors('draft_revision');
        $this->assertDatabaseCount('company_policy_versions', 1);
    }

    public function test_only_owner_can_manage_or_publish_and_inactive_staff_are_not_notified(): void
    {
        foreach ([$this->manager, $this->employee] as $staff) {
            $this->actingAs($staff)->get('/admin/company-policy')->assertForbidden();
            $this->post('/admin/company-policy/draft', ['title' => 'Forged', 'content' => 'Forged', 'draft_revision' => 0])->assertForbidden();
            $this->post('/admin/company-policy/publish', ['draft_revision' => 0])->assertForbidden();
        }
        $inactive = $this->person('employee');
        $inactive->update(['active' => false]);
        $this->publish();
        $this->assertDatabaseMissing('alerts', ['user_id' => $inactive->id]);
        $this->assertDatabaseMissing('alerts', ['user_id' => $this->owner->id]);
    }

    public function test_maximum_length_title_can_be_published_after_many_versions_without_overflowing_notifications(): void
    {
        CompanyPolicyVersion::create(['company_id' => $this->owner->company_id, 'version' => 99, 'title' => 'Previous policy', 'content' => 'Previous content', 'published_by_id' => $this->owner->id, 'published_at' => now()->subDay()]);
        $title = str_repeat('P', 180);
        $policy = $this->publish(null, $title);
        $this->assertSame(100, $policy->version);
        $this->assertSame($title, $policy->title);
        $this->assertDatabaseCount('alerts', 2);
        $this->assertDatabaseHas('alerts', ['user_id' => $this->employee->id, 'message' => 'Company policy version 100 is ready. Read and acknowledge it to continue.']);
    }

    public function test_web_requires_acknowledgement_for_manager_and_employee_but_keeps_policy_and_logout_access(): void
    {
        $policy = $this->publish();
        foreach ([$this->manager, $this->employee] as $staff) {
            $this->actingAs($staff)->get('/tasks/'.$this->task->id)->assertRedirect('/company-policy');
            $this->get('/company-policy')->assertOk()->assertSee($policy->title)->assertSee($policy->content)->assertSee('I have read and understand this company policy.')->assertSee('type="submit" disabled', false)->assertDontSee(' checked', false)->assertHeader('Cache-Control', 'no-store, private');
            $this->post('/company-policy/acknowledge', ['policy_version_id' => $policy->id])->assertSessionHasErrors('acknowledged');
            $this->post('/company-policy/acknowledge', ['policy_version_id' => $policy->id, 'acknowledged' => 0])->assertSessionHasErrors('acknowledged');
            $this->getJson('/calendar/events')->assertStatus(423)->assertJsonPath('code', CompanyPolicyService::REQUIRED);
            $this->post('/logout')->assertRedirect('/login');
        }
        $this->actingAs($this->owner)->get('/admin/company-policy')->assertOk();
        $this->get('/')->assertOk();
    }

    public function test_web_acknowledgement_restores_intended_page_and_never_accepts_open_redirect(): void
    {
        $policy = $this->publish();
        $this->actingAs($this->employee)->get('/tasks/'.$this->task->id.'?return=https://untrusted.test')->assertRedirect('/company-policy');
        $this->post('/company-policy/acknowledge', ['policy_version_id' => $policy->id, 'acknowledged' => 1])->assertRedirect('/tasks/'.$this->task->id.'?return=https://untrusted.test');
        $this->get('/tasks/'.$this->task->id)->assertOk();
        $this->withSession(['policy.intended' => '//untrusted.test'])->post('/company-policy/acknowledge', ['policy_version_id' => $policy->id, 'acknowledged' => 1])->assertRedirect('/');
    }

    public function test_staff_mobile_login_and_all_protected_functions_are_locked_until_confirmation(): void
    {
        $policy = $this->publish();
        foreach ([$this->manager, $this->employee] as $staff) {
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            $login = $this->postJson(self::API.'/login', ['email' => $staff->email, 'password' => 'PolicyPassword123!', 'device_name' => 'Android'])->assertOk()->assertJsonPath('policy_required', true)->assertJsonPath('policy.id', $policy->id);
            $this->app['auth']->forgetGuards();
            $this->withHeader('Authorization', 'Bearer '.$login->json('token'));
            foreach (['/tasks', '/team/tasks', '/reviews', '/calendar', '/notifications', '/tasks/'.$this->task->id] as $path) {
                $this->getJson(self::API.$path)->assertStatus(423)->assertJsonPath('code', CompanyPolicyService::REQUIRED)->assertJsonPath('policy.id', $policy->id);
            }
            $this->postJson(self::API.'/tasks/'.$this->task->id.'/actions', ['action' => 'complete', 'note' => 'Bypass'])->assertStatus(423);
            $this->postJson(self::API.'/team/tasks', [])->assertStatus(423);
            $this->postJson(self::API.'/devices', ['platform' => 'android', 'token' => 'Bypass'])->assertStatus(423);
            $this->getJson(self::API.'/me')->assertOk()->assertJsonPath('policy_required', true);
            $this->getJson(self::API.'/company-policy')->assertOk()->assertJsonPath('required', true)->assertJsonPath('policy.content', $policy->content);
            $this->postJson(self::API.'/logout')->assertOk();
        }
        $this->assertSame('assigned', $this->task->fresh()->status);
    }

    public function test_acknowledgement_is_server_owned_idempotent_and_unlocks_normal_access(): void
    {
        $policy = $this->publish();
        $this->mobile($this->employee);
        foreach ([['user_id' => $this->manager->id], ['company_id' => 999], ['acknowledged_at' => '2000-01-01']] as $forged) {
            $this->acknowledge($policy, $forged)->assertUnprocessable();
        }
        $this->assertDatabaseCount('company_policy_acknowledgements', 0);
        $response = $this->acknowledge($policy)->assertOk()->assertJsonPath('acknowledged', true);
        $firstTime = $response->json('acknowledged_at');
        $this->travel(10)->minutes();
        $this->acknowledge($policy)->assertOk()->assertJsonPath('acknowledged_at', $firstTime);
        $this->assertDatabaseCount('company_policy_acknowledgements', 1);
        $this->assertDatabaseHas('company_policy_acknowledgements', ['company_id' => $this->employee->company_id, 'user_id' => $this->employee->id, 'policy_version_id' => $policy->id]);
        $this->getJson(self::API.'/me')->assertJsonPath('policy_required', false);
        $this->getJson(self::API.'/tasks')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(self::API.'/tasks/'.$this->task->id.'/actions', ['action' => 'start'])->assertOk();
        $this->postJson(self::API.'/tasks/'.$this->task->id.'/actions', ['action' => 'complete', 'note' => 'Completed after confirmation'])->assertOk();
        $this->assertSame('completed', $this->task->fresh()->status);
    }

    public function test_new_policy_requires_confirmation_again_and_drafts_do_not_change_access(): void
    {
        $first = $this->publish();
        $this->mobile($this->employee);
        $this->acknowledge($first)->assertOk();
        $service = app(CompanyPolicyService::class);
        $revision = $service->company($this->owner)->policy_draft_revision;
        $service->saveDraft($this->owner, 'Revised policy', 'New instructions', $revision);
        $this->getJson(self::API.'/tasks')->assertOk();
        $this->assertSame($first->content, $first->fresh()->content);
        $second = $service->publish($this->owner, $revision + 1);
        $this->getJson(self::API.'/tasks')->assertStatus(423)->assertJsonPath('policy.id', $second->id);
        $this->acknowledge($first)->assertStatus(423)->assertJsonPath('policy.id', $second->id);
        $this->assertDatabaseCount('company_policy_acknowledgements', 1);
        $this->acknowledge($second)->assertOk();
        $this->assertDatabaseCount('company_policy_acknowledgements', 2);
        $this->getJson(self::API.'/tasks')->assertOk();
        $this->assertDatabaseCount('company_policy_versions', 2);
    }

    public function test_publishing_between_reading_and_acknowledging_returns_latest_policy_without_unlocking(): void
    {
        $first = $this->publish();
        $this->mobile($this->manager);
        $this->getJson(self::API.'/company-policy')->assertJsonPath('policy.id', $first->id);
        $latest = $this->publish(null, 'New policy while confirmation was open');
        $this->acknowledge($first)->assertStatus(423)->assertJsonPath('code', CompanyPolicyService::REQUIRED)->assertJsonPath('policy.id', $latest->id)->assertJsonPath('policy.content', $latest->content);
        $this->assertDatabaseCount('company_policy_acknowledgements', 0);
        $this->getJson(self::API.'/tasks')->assertStatus(423);
        $this->acknowledge($latest)->assertOk();
        $this->getJson(self::API.'/team/tasks')->assertOk();
    }

    public function test_web_stale_confirmation_displays_current_version_with_fresh_checkbox(): void
    {
        $first = $this->publish();
        $this->actingAs($this->employee)->get('/company-policy')->assertSee($first->title);
        $second = $this->publish(null, 'Latest web policy');
        $this->post('/company-policy/acknowledge', ['policy_version_id' => $first->id, 'acknowledged' => 1])->assertRedirect('/company-policy');
        $this->get('/company-policy')->assertOk()->assertSee($second->title)->assertSee('type="submit" disabled', false)->assertDontSee(' checked', false);
        $this->assertDatabaseCount('company_policy_acknowledgements', 0);
    }

    public function test_new_staff_and_already_signed_in_staff_are_required_to_acknowledge(): void
    {
        $this->mobile($this->employee);
        $this->getJson(self::API.'/tasks')->assertOk();
        $policy = $this->publish();
        $this->getJson(self::API.'/tasks')->assertStatus(423);
        $new = $this->person('employee');
        $this->mobile($new);
        $this->getJson(self::API.'/company-policy')->assertJsonPath('required', true)->assertJsonPath('policy.id', $policy->id);
        $this->getJson(self::API.'/tasks')->assertStatus(423);
    }

    public function test_no_published_policy_allows_normal_web_and_mobile_access(): void
    {
        $this->actingAs($this->employee)->get('/tasks')->assertOk();
        $this->mobile($this->manager);
        $this->getJson(self::API.'/tasks')->assertOk();
        $this->getJson(self::API.'/company-policy')->assertOk()->assertJsonPath('policy', null)->assertJsonPath('required', false);
    }

    public function test_unlinked_accounts_are_blocked_from_features_but_can_still_sign_out(): void
    {
        foreach ([$this->owner, $this->manager, $this->employee] as $person) {
            $person->update(['company_id' => null]);
            $this->actingAs($person)->get('/')->assertForbidden()->assertSee('A company account is required.');
            $this->post('/logout')->assertRedirect('/login');
        }

        $this->mobile($this->employee);
        $this->getJson(self::API.'/tasks')->assertForbidden();
        $this->postJson(self::API.'/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // A failed mobile sign-in must not issue an unusable token before checking setup.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson(self::API.'/login', ['email' => $this->employee->email, 'password' => 'PolicyPassword123!', 'device_name' => 'Unlinked phone'])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_company_boundary_applies_to_policies_notices_history_and_owner_account_actions(): void
    {
        $otherCompany = Company::create(['name' => 'Isolated test business']);
        $otherOwner = $this->person('admin', $otherCompany);
        $otherStaff = $this->person('employee', $otherCompany);
        $otherPolicy = $this->publish($otherOwner, 'Private other company policy');
        $this->assertDatabaseMissing('alerts', ['user_id' => $this->employee->id]);
        $this->assertDatabaseHas('alerts', ['user_id' => $otherStaff->id, 'type' => 'policy_published']);
        $this->mobile($this->employee);
        $this->getJson(self::API.'/company-policy?company_id='.$otherCompany->id)->assertJsonPath('policy', null)->assertJsonPath('required', false);
        $this->getJson(self::API.'/tasks')->assertOk();
        $this->flushHeaders();
        $this->actingAs($this->owner)->get('/admin/company-policy/versions/'.$otherPolicy->id)->assertNotFound();
        $this->get('/admin/company-policy')->assertDontSee('Private other company policy')->assertDontSee($otherStaff->name);
        $this->post('/admin/company-policy/draft', ['title' => 'Forged', 'content' => 'Forged', 'draft_revision' => 0, 'company_id' => $otherCompany->id])->assertSessionHasErrors('company_id');
        $this->post('/admin/users/'.$otherStaff->id, [])->assertNotFound();
        $this->get('/admin/users')->assertDontSee($otherStaff->name);
        $ownPolicy = $this->publish();
        $this->mobile($this->employee);
        $this->acknowledge($otherPolicy)->assertStatus(423)->assertJsonPath('policy.id', $ownPolicy->id)->assertDontSee('Private other company policy');
        $this->assertDatabaseCount('company_policy_acknowledgements', 0);
    }

    public function test_owner_overview_filters_current_active_staff_and_preserves_previous_versions(): void
    {
        $policy = $this->publish();
        $this->mobile($this->employee);
        $this->acknowledge($policy)->assertOk();
        $this->flushHeaders();
        $this->actingAs($this->owner)->get('/admin/company-policy?role=employee&ack_status=acknowledged')->assertOk()->assertSee($this->employee->name)->assertDontSee($this->manager->name)->assertSee('Acknowledged');
        $this->get('/admin/company-policy?role=manager&ack_status=outstanding')->assertOk()->assertSee($this->manager->name)->assertDontSee($this->employee->name);
        $this->get('/admin/company-policy/versions/'.$policy->id)->assertOk()->assertSee($policy->content);
        $this->publish(null, 'Version two');
        $this->get('/admin/company-policy?ack_status=acknowledged')->assertDontSee($this->employee->name);
        $this->get('/admin/company-policy?ack_status=outstanding')->assertSee($this->employee->name);
        $this->get('/admin/company-policy/versions/'.$policy->id)->assertOk()->assertSee($policy->content);
        $this->assertDatabaseHas('company_policy_acknowledgements', ['policy_version_id' => $policy->id, 'user_id' => $this->employee->id]);
    }

    public function test_published_content_cannot_be_edited_in_place_and_is_escaped_for_web(): void
    {
        $service = app(CompanyPolicyService::class);
        $service->saveDraft($this->owner, '<script>title</script>', '<img src=x onerror=alert(1)>', 0);
        $policy = $service->publish($this->owner, 1);
        $this->actingAs($this->employee)->get('/company-policy')->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->assertDontSee('<img src=x', false);
        try {
            $policy->update(['content' => 'Overwritten']);
            $this->fail('Published policy content must be immutable.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        $this->assertSame('<img src=x onerror=alert(1)>', $policy->fresh()->content);
    }

    public function test_owner_created_staff_inherit_owner_company_ignoring_client_company_authority(): void
    {
        $other = Company::create(['name' => 'Foreign test company']);
        $this->actingAs($this->owner)->post('/admin/users', ['name' => 'New colleague', 'email' => 'new-colleague@policy.test', 'role' => 'employee', 'active' => '1', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!', 'company_id' => $other->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->owner->company_id, User::where('email', 'new-colleague@policy.test')->firstOrFail()->company_id);
    }
}
