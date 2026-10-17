<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPolicyVersion;
use App\Models\User;
use App\Services\CompanyPolicyService;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyPolicyHistoryTest extends TestCase
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

    private function replace(CompanyPolicyVersion $previous, string $title = 'Current policy'): CompanyPolicyVersion
    {
        $service = app(CompanyPolicyService::class);
        $entry = $previous->policy;
        $draft = $service->saveDraft($this->owner, $title, 'Latest written policy', $entry->draft_revision, null, 'text', $entry->id);

        return $service->publish($this->owner, $draft->draft_revision, $draft->id);
    }

    private function url(CompanyPolicyVersion $version): string
    {
        return '/company-policy?policy_id='.$version->company_policy_id.'&version_id='.$version->id;
    }

    public function test_managers_and_employees_can_read_previous_versions_and_download_original_pdfs_while_locked(): void
    {
        $original = $this->published('Original PDF policy');
        $current = $this->replace($original);
        foreach ([$this->employee, $this->manager] as $user) {
            $this->web($user)->get($this->url($original))->assertOk()->assertSee('Previous published versions')->assertSee('Original PDF policy')->assertSee('Previous version — read only')->assertSee('Read current version (version 2)')->assertSee('Read policy')->assertSee('Download PDF')->assertSee('1 policy requires')->assertDontSee('id="policy-confirm"', false)->assertDontSee('Continue to TaskSure');
            $this->get('/company-policy/versions/'.$original->id.'/pdf?download=1')->assertDownload('company-policy-'.$original->company_policy_id.'-v1.pdf');
            $this->get('/tasks')->assertRedirect('/company-policy');
            $this->mobile($user)->getJson(self::API.$this->url($original))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('policy.id', $original->id)->assertJsonPath('policy.document.url', '/company-policy/versions/'.$original->id.'/pdf')->assertJsonPath('current_policy.id', $current->id)->assertJsonPath('selected_is_current', false)->assertJsonPath('selected_required', false)->assertJsonPath('required', true)->assertJsonPath('outstanding_count', 1)->assertJsonPath('history.data.0.id', $original->id);
            $response = $this->getJson(self::API.'/company-policy/versions/'.$original->id.'/pdf')->assertOk();
            $this->assertSame(file_get_contents(base_path('tests/Fixtures/company-policy.pdf')), $response->streamedContent());
            $this->getJson(self::API.'/tasks')->assertStatus(423)->assertJsonPath('policy.id', $current->id);
        }
        $this->assertDatabaseCount('company_policy_acknowledgements', 0);
        $this->postJson(self::API.'/logout')->assertOk();
    }

    public function test_history_shows_only_the_authenticated_users_own_acknowledgements_and_never_changes_them(): void
    {
        $service = app(CompanyPolicyService::class);
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(7, 0));
        $original = $this->published('Old PDF policy');
        $this->travel(5)->minutes();
        $employeeAck = $service->acknowledge($this->employee, $original->id)['acknowledged_at'];
        $this->travel(2)->hours();
        $managerAck = $service->acknowledge($this->manager, $original->id)['acknowledged_at'];
        $this->travel(1)->hours();
        $current = $this->replace($original);
        foreach ([[$this->employee, $employeeAck, $managerAck], [$this->manager, $managerAck, $employeeAck]] as [$user, $own, $other]) {
            $response = $this->mobile($user)->getJson(self::API.$this->url($original))->assertOk()->assertJsonPath('acknowledged_at', $own)->assertJsonPath('history.data.0.acknowledged_at', $own)->assertJsonMissingPath('history.data.0.user_id')->assertJsonMissingPath('history.data.0.acknowledgements');
            $this->assertStringNotContainsString($other, $response->getContent());
            $this->confirm($current)->assertOk();
            $this->getJson(self::API.$this->url($original))->assertJsonPath('required', false)->assertJsonPath('selected_required', false)->assertJsonPath('acknowledged_at', $own);
            $this->getJson(self::API.'/tasks')->assertOk();
            $this->web($user)->get($this->url($original))->assertOk()->assertSee('Acknowledged '.BusinessTime::show($own))->assertSee('Continue to TaskSure')->assertDontSee('id="policy-confirm"', false);
            $this->get('/admin/company-policy')->assertForbidden();
        }
        $this->assertDatabaseCount('company_policy_acknowledgements', 4);
        $this->assertSame($employeeAck, $service->acknowledgement($this->employee, $original)->acknowledged_at->toIso8601String());
        $this->assertSame($managerAck, $service->acknowledgement($this->manager, $original)->acknowledged_at->toIso8601String());
    }

    public function test_history_selection_is_scoped_to_company_and_parent_policy_and_validates_query_input(): void
    {
        $original = $this->published('Our previous policy');
        $this->replace($original);
        $another = $this->published('Different policy in our company');
        $foreignCompany = Company::create(['name' => 'Foreign company']);
        $foreign = $this->published('Foreign confidential PDF', $this->person('admin', $foreignCompany));
        foreach ([$this->employee, $this->manager] as $user) {
            foreach ([$another, $foreign] as $other) {
                $forged = '/company-policy?policy_id='.$original->company_policy_id.'&version_id='.$other->id;
                $this->web($user)->get($forged)->assertNotFound();
                $this->mobile($user)->getJson(self::API.$forged)->assertNotFound();
            }
            $this->getJson(self::API.'/company-policy?policy_id='.$foreign->company_policy_id)->assertNotFound();
            $this->getJson(self::API.'/company-policy?policy_id='.$original->company_policy_id.'&version_id=99999')->assertNotFound();
            $this->getJson(self::API.'/company-policy?version_id='.$original->id)->assertUnprocessable()->assertJsonValidationErrors('policy_id');
            $this->getJson(self::API.$this->url($original).'&history_page=0')->assertUnprocessable()->assertJsonValidationErrors('history_page');
            $this->getJson(self::API.$this->url($original))->assertOk()->assertDontSee('Foreign confidential PDF')->assertJsonCount(1, 'history.data')->assertJsonPath('history.data.0.id', $original->id);
            $this->getJson(self::API.'/company-policy/versions/'.$foreign->id.'/pdf')->assertNotFound();
        }
    }

    public function test_saved_drafts_are_not_in_staff_history_and_do_not_replace_original_published_content(): void
    {
        $service = app(CompanyPolicyService::class);
        $original = $this->published('Previous PDF policy');
        $current = $this->replace($original);
        $entry = $current->policy;
        $service->saveDraft($this->owner, 'Secret saved draft', 'Unpublished content', $entry->draft_revision, null, 'text', $entry->id);
        $newEntry = $service->createDraft($this->owner, 'Unpublished separate policy', 'Do not expose', null, 'text');
        $this->mobile($this->employee)->getJson(self::API.'/company-policy')->assertJsonCount(1, 'policies')->assertJsonCount(1, 'history.data')->assertJsonPath('history.meta.total', 1)->assertJsonPath('history.data.0.id', $original->id)->assertDontSee('Secret saved draft')->assertDontSee('Unpublished content')->assertDontSee('Unpublished separate policy');
        $this->getJson(self::API.'/company-policy?policy_id='.$newEntry->id.'&version_id='.$original->id)->assertNotFound();
        $this->getJson(self::API.$this->url($original))->assertJsonPath('policy.title', 'Previous PDF policy');
        $this->getJson(self::API.'/company-policy?policy_id='.$current->company_policy_id.'&version_id='.$current->id)->assertJsonPath('selected_is_current', true)->assertJsonPath('selected_required', true)->assertJsonPath('policy.content', 'Latest written policy');
        Storage::disk('evidence')->assertExists($original->pdf_path);
    }

    public function test_confirming_a_historical_version_cannot_unlock_current_policy_and_web_returns_to_latest(): void
    {
        $original = $this->published('Historical version');
        $current = $this->replace($original);
        $this->mobile($this->employee)->getJson(self::API.$this->url($original))->assertJsonPath('selected_required', false);
        $this->confirm($original)->assertStatus(423)->assertJsonPath('policy.id', $current->id);
        $this->assertDatabaseCount('company_policy_acknowledgements', 0);
        $this->web($this->employee)->get('/calendar')->assertRedirect('/company-policy');
        $this->get($this->url($original))->assertOk();
        $this->post('/company-policy/acknowledge', ['policy_version_id' => $original->id, 'acknowledged' => 1])->assertRedirect('/company-policy?policy_id='.$current->company_policy_id);
        $this->get('/company-policy?policy_id='.$current->company_policy_id)->assertOk()->assertSee('type="submit" disabled', false)->assertDontSee(' checked', false);
        $this->post('/company-policy/acknowledge', ['policy_version_id' => $current->id, 'acknowledged' => 1])->assertRedirect('/calendar');
        $this->get($this->url($original))->assertOk()->assertSee('Not acknowledged for this version')->assertSee('Continue to TaskSure');
        $this->get('/calendar')->assertOk();
    }

    public function test_history_is_paginated_newest_first_and_an_older_selection_survives_page_changes(): void
    {
        $original = $this->published('Version one');
        $current = $original;
        for ($i = 2; $i <= 22; $i++) {
            $current = $this->replace($current, 'Published policy '.$i);
        }
        $this->mobile($this->employee)->getJson(self::API.'/company-policy')->assertOk()->assertJsonCount(20, 'history.data')->assertJsonPath('history.data.0.version', 21)->assertJsonPath('history.data.19.version', 2)->assertJsonPath('history.meta.total', 21)->assertJsonPath('history.meta.last_page', 2);
        $this->getJson(self::API.$this->url($original).'&history_page=2')->assertOk()->assertJsonCount(1, 'history.data')->assertJsonPath('history.data.0.id', $original->id)->assertJsonPath('policy.id', $original->id)->assertJsonPath('current_policy.version', 22)->assertJsonPath('selected_required', false)->assertJsonPath('required', true)->assertJsonPath('history.meta.page', 2);
        $this->web($this->manager)->get($this->url($original).'&history_page=2')->assertOk()->assertSee('Page 2 of 2')->assertSee('Previous version — read only')->assertSee('Version one')->assertDontSee('id="policy-confirm"', false);
        $this->assertDatabaseCount('company_policy_acknowledgements', 0);
    }

    public function test_no_policy_or_no_previous_versions_keeps_access_and_signout_available(): void
    {
        $this->mobile($this->employee)->getJson(self::API.'/company-policy')->assertOk()->assertJsonPath('required', false)->assertJsonPath('history.data', [])->assertJsonPath('history.meta.total', 0);
        $this->getJson(self::API.'/tasks')->assertOk();
        $this->published('First version');
        $this->getJson(self::API.'/company-policy')->assertJsonPath('history.data', [])->assertJsonPath('selected_required', true);
        $this->web($this->manager)->get('/company-policy')->assertOk()->assertSee('No previous published versions.')->assertSee('Sign out');
        $this->post('/logout')->assertRedirect('/login');
    }
}
