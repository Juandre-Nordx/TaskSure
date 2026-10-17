<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\CompanyPolicyVersion;
use App\Models\User;
use App\Services\CompanyPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyPolicyLibraryTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/mobile/v1';

    private User $owner;

    private User $employee;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('evidence');
        config(['mobile.push_enabled' => false, 'tasksure.email_enabled' => false]);
        $this->owner = $this->person('admin');
        $this->employee = $this->person('employee');
        $this->manager = $this->person('manager');
    }

    private function person(string $role, ?Company $company = null): User
    {
        return User::create(['name' => 'Library '.$role.' '.uniqid(), 'email' => uniqid().'@library.test', 'role' => $role, 'active' => true, 'password' => 'LibraryPassword123!', 'company_id' => $company?->id]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('policy.pdf', file_get_contents(base_path('tests/Fixtures/company-policy.pdf')));
    }

    private function published(string $title, ?User $owner = null): CompanyPolicyVersion
    {
        $owner ??= $this->owner;
        $service = app(CompanyPolicyService::class);
        $entry = $service->createDraft($owner, $title, '', $this->pdf(), 'pdf');

        return $service->publish($owner, $entry->draft_revision, $entry->id);
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

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('Library phone', ['mobile', $user->role], now()->addDay())->plainTextToken);
    }

    private function confirm(CompanyPolicyVersion $version)
    {
        return $this->postJson(self::API.'/company-policy/acknowledge', ['policy_version_id' => $version->id, 'acknowledged' => true]);
    }

    public function test_owner_adds_multiple_pdf_policies_and_keeps_independent_drafts_versions_and_notices(): void
    {
        foreach (['Safety policy', 'Staff conduct'] as $title) {
            $this->web($this->owner)->get('/admin/company-policy/create')->assertOk()->assertSee('Add policy')->assertSee('Upload PDF');
            $this->post('/admin/company-policy', ['title' => $title, 'format' => 'pdf', 'policy_pdf' => $this->pdf(), 'draft_revision' => 0])->assertRedirect()->assertSessionHasNoErrors();
        }
        $entries = CompanyPolicy::orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['Safety policy', 'Staff conduct'], $entries->pluck('draft_title')->all());
        $this->assertDatabaseCount('alerts', 0);
        $this->web($this->employee)->get('/tasks')->assertOk();
        foreach ($entries as $entry) {
            $this->web($this->owner)->post('/admin/company-policy/publish', ['policy_id' => $entry->id, 'draft_revision' => $entry->draft_revision])->assertRedirect('/admin/company-policy?policy_id='.$entry->id)->assertSessionHasNoErrors();
        }
        $this->assertSame([1, 1], CompanyPolicyVersion::orderBy('id')->pluck('version')->all());
        $this->assertDatabaseCount('alerts', 4);
        $this->assertCount(2, Storage::disk('evidence')->allFiles());
        $this->get('/admin/company-policy')->assertOk()->assertSee('Safety policy')->assertSee('Staff conduct')->assertSee('Update this policy')->assertSee('Add policy');
        $first = $entries[0]->fresh();
        $this->post('/admin/company-policy/draft', ['policy_id' => $first->id, 'title' => 'Safety revision', 'format' => 'pdf', 'draft_revision' => $first->draft_revision])->assertSessionHasNoErrors();
        $this->assertSame(1, $entries[1]->fresh()->currentVersion->version);
        $this->assertNull($entries[1]->fresh()->draft_title);
    }

    public function test_manager_and_employee_must_acknowledge_every_published_policy_on_the_api(): void
    {
        $first = $this->published('Safety policy');
        $second = $this->published('Staff conduct');
        foreach ([$this->employee, $this->manager] as $user) {
            $this->mobile($user)->getJson(self::API.'/company-policy')->assertOk()->assertJsonCount(2, 'policies')->assertJsonPath('outstanding_count', 2)->assertJsonPath('policy.id', $first->id)->assertJsonPath('policies.1.policy_id', $second->company_policy_id);
            $this->getJson(self::API.'/tasks')->assertStatus(423);
            foreach ([$first, $second] as $policy) {
                $this->getJson(self::API.'/company-policy/versions/'.$policy->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
                $this->getJson(self::API.'/company-policy/versions/'.$policy->id.'/pdf?download=1')->assertDownload('company-policy-'.$policy->company_policy_id.'-v1.pdf');
            }
            $this->assertDatabaseMissing('company_policy_acknowledgements', ['user_id' => $user->id]);
            $this->confirm($first)->assertOk()->assertJsonPath('remaining_count', 1);
            $this->confirm($first)->assertOk()->assertJsonPath('remaining_count', 1);
            $this->getJson(self::API.'/tasks')->assertStatus(423)->assertJsonPath('policy.id', $second->id);
            $this->getJson(self::API.'/company-policy?policy_id='.$first->company_policy_id)->assertJsonPath('required', true)->assertJsonPath('selected_required', false)->assertJsonPath('policy.id', $first->id);
            $this->confirm($second)->assertOk()->assertJsonPath('remaining_count', 0);
            $this->getJson(self::API.'/tasks')->assertOk();
            $this->getJson(self::API.'/company-policy')->assertJsonCount(2, 'policies')->assertJsonPath('required', false)->assertJsonPath('policies.0.required', false)->assertJsonPath('policies.1.required', false);
        }
        $this->assertDatabaseCount('company_policy_acknowledgements', 4);
    }

    public function test_web_lists_all_policies_and_retains_the_intended_page_until_the_last_confirmation(): void
    {
        $first = $this->published('Safety policy');
        $second = $this->published('Staff conduct');
        $this->web($this->employee)->get('/calendar')->assertRedirect('/company-policy');
        $this->get('/company-policy')->assertOk()->assertSee('Safety policy')->assertSee('Staff conduct')->assertSee('2 policies require')->assertSee('type="submit" disabled', false)->assertDontSee(' checked', false)->assertDontSee('Continue to TaskSure');
        $this->post('/company-policy/acknowledge', ['policy_version_id' => $first->id, 'acknowledged' => 1])->assertRedirect('/company-policy');
        $this->assertSame('/calendar', session('policy.intended'));
        $this->get('/company-policy?policy_id='.$first->company_policy_id)->assertOk()->assertSee('Acknowledged')->assertSee('1 policy requires')->assertDontSee('id="policy-confirm"', false)->assertDontSee('Continue to TaskSure');
        $this->get('/calendar')->assertRedirect('/company-policy');
        $this->get('/company-policy?policy_id='.$second->company_policy_id)->assertOk()->assertSee('type="submit" disabled', false);
        $this->post('/company-policy/acknowledge', ['policy_version_id' => $second->id, 'acknowledged' => 1])->assertRedirect('/calendar');
        $this->get('/calendar')->assertOk();
        $this->get('/company-policy')->assertOk()->assertSee('Safety policy')->assertSee('Staff conduct')->assertSee('Continue to TaskSure');
    }

    public function test_revising_one_policy_preserves_the_other_policy_and_its_acknowledgement(): void
    {
        $first = $this->published('Safety policy');
        $second = $this->published('Staff conduct');
        $service = app(CompanyPolicyService::class);
        $service->acknowledge($this->employee, $first->id);
        $service->acknowledge($this->employee, $second->id);
        $secondTime = $service->acknowledgement($this->employee, $second)->acknowledged_at;
        $entry = CompanyPolicy::findOrFail($first->company_policy_id);
        $draft = $service->saveDraft($this->owner, 'Updated safety policy', '', $entry->draft_revision, $this->pdf(), 'pdf', $entry->id);
        $this->mobile($this->employee)->getJson(self::API.'/tasks')->assertOk();
        $latest = $service->publish($this->owner, $draft->draft_revision, $draft->id);
        $this->assertSame(2, $latest->version);
        $this->getJson(self::API.'/company-policy')->assertJsonCount(2, 'policies')->assertJsonPath('outstanding_count', 1)->assertJsonPath('policy.id', $latest->id);
        $this->confirm($first)->assertStatus(423)->assertJsonPath('policy.id', $latest->id);
        $this->confirm($second)->assertOk()->assertJsonPath('remaining_count', 1);
        $this->getJson(self::API.'/tasks')->assertStatus(423);
        $this->confirm($latest)->assertOk()->assertJsonPath('remaining_count', 0);
        $this->getJson(self::API.'/tasks')->assertOk();
        $this->assertTrue($secondTime->equalTo($service->acknowledgement($this->employee, $second)->acknowledged_at));
        Storage::disk('evidence')->assertExists([$first->pdf_path, $second->pdf_path, $latest->pdf_path]);
        $this->web($this->owner)->get('/admin/company-policy/versions/'.$first->id)->assertOk()->assertSee($this->employee->name)->assertSee('Acknowledgements for version 1');
        $this->get('/admin/company-policy?policy_id='.$second->company_policy_id)->assertSee('Staff conduct')->assertDontSee('Version 2 · Updated safety policy');
    }

    public function test_a_new_policy_published_during_confirmation_keeps_access_locked(): void
    {
        $first = $this->published('Safety policy');
        $this->mobile($this->employee)->getJson(self::API.'/company-policy')->assertJsonPath('outstanding_count', 1);
        $second = $this->published('Policy published while reading');
        $this->confirm($first)->assertOk()->assertJsonPath('remaining_count', 1);
        $this->getJson(self::API.'/tasks')->assertStatus(423)->assertJsonPath('policy.id', $second->id);
        $this->confirm($second)->assertOk()->assertJsonPath('remaining_count', 0);
        $this->getJson(self::API.'/tasks')->assertOk();
        $newStaff = $this->person('employee');
        $this->mobile($newStaff)->getJson(self::API.'/company-policy')->assertJsonPath('outstanding_count', 2);
    }

    public function test_company_isolation_applies_to_selection_drafts_publication_library_and_pdfs(): void
    {
        $first = $this->published('Our safety policy');
        $foreignCompany = Company::create(['name' => 'Another company']);
        $foreignOwner = $this->person('admin', $foreignCompany);
        $foreign = $this->published('Private foreign policy', $foreignOwner);
        $this->web($this->owner)->get('/admin/company-policy?policy_id='.$foreign->company_policy_id)->assertNotFound();
        $this->post('/admin/company-policy/draft', ['policy_id' => $foreign->company_policy_id, 'draft_revision' => 2, 'title' => 'Forged', 'format' => 'text', 'content' => 'Forged'])->assertNotFound();
        $this->post('/admin/company-policy/publish', ['policy_id' => $foreign->company_policy_id, 'draft_revision' => 2])->assertNotFound();
        $this->get('/admin/company-policy/draft/pdf?policy_id='.$foreign->company_policy_id)->assertNotFound();
        $this->post('/admin/company-policy', ['company_id' => $foreignCompany->id, 'title' => 'Forged', 'format' => 'text', 'content' => 'Forged', 'draft_revision' => 0])->assertSessionHasErrors('company_id');
        $this->mobile($this->employee)->getJson(self::API.'/company-policy?policy_id='.$foreign->company_policy_id)->assertNotFound();
        $this->getJson(self::API.'/company-policy')->assertJsonCount(1, 'policies')->assertDontSee('Private foreign policy');
        $this->getJson(self::API.'/company-policy/versions/'.$foreign->id.'/pdf')->assertNotFound();
        $this->confirm($foreign)->assertStatus(423)->assertJsonPath('policy.id', $first->id);
        $this->assertSame('Private foreign policy', $foreign->fresh()->title);
        $this->assertDatabaseCount('company_policy_acknowledgements', 0);
    }

    public function test_policy_creation_is_owner_only_and_unpublished_items_do_not_appear_to_staff(): void
    {
        foreach ([$this->employee, $this->manager] as $user) {
            $this->web($user)->get('/admin/company-policy/create')->assertForbidden();
            $this->post('/admin/company-policy', ['title' => 'Forged', 'format' => 'text', 'content' => 'Forged', 'draft_revision' => 0])->assertForbidden();
        }
        $service = app(CompanyPolicyService::class);
        $entry = $service->createDraft($this->owner, 'Unpublished draft', 'Not yet approved', null, 'text');
        $this->mobile($this->employee)->getJson(self::API.'/company-policy')->assertJsonCount(0, 'policies')->assertJsonPath('required', false);
        $this->getJson(self::API.'/company-policy?policy_id='.$entry->id)->assertNotFound();
        $this->getJson(self::API.'/tasks')->assertOk();
        $this->postJson(self::API.'/logout')->assertOk();
    }

    public function test_owner_filters_acknowledgements_for_the_selected_policy_version(): void
    {
        $first = $this->published('Safety policy');
        $second = $this->published('Staff conduct');
        app(CompanyPolicyService::class)->acknowledge($this->employee, $first->id);
        $this->web($this->owner)->get('/admin/company-policy?policy_id='.$first->company_policy_id.'&ack_status=acknowledged')->assertOk()->assertSee($this->employee->name)->assertDontSee($this->manager->name);
        $this->get('/admin/company-policy?policy_id='.$second->company_policy_id.'&ack_status=acknowledged')->assertOk()->assertDontSee($this->employee->name);
        $this->get('/admin/company-policy?policy_id='.$second->company_policy_id.'&ack_status=outstanding&role=manager')->assertOk()->assertSee($this->manager->name)->assertDontSee($this->employee->name);
        $this->get('/admin/company-policy/versions/'.$first->id)->assertSee($this->employee->name);
        $this->get('/admin/company-policy/versions/'.$second->id)->assertDontSee($this->employee->name);
    }
}
