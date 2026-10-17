<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPolicyVersion;
use App\Models\User;
use App\Services\CompanyPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyPolicyPdfTest extends TestCase
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
        $this->owner = $this->person('admin');
        $this->employee = $this->person('employee');
        $this->manager = $this->person('manager');
        config(['mobile.push_enabled' => false, 'tasksure.email_enabled' => false]);
    }

    private function person(string $role, ?Company $company = null): User
    {
        return User::create(['name' => 'PDF '.$role.' '.uniqid(), 'email' => uniqid().'@policy-pdf.test', 'password' => 'PdfPassword123!', 'active' => true, 'role' => $role, 'company_id' => $company?->id]);
    }

    private function pdf(string $suffix = '', string $name = 'company-policy.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/Fixtures/company-policy.pdf')).$suffix);
    }

    private function save(?UploadedFile $pdf = null, string $title = 'Uploaded policy', ?int $revision = null)
    {
        $revision ??= app(CompanyPolicyService::class)->company($this->owner)->policy_draft_revision;

        return $this->web($this->owner)->post('/admin/company-policy/draft', ['title' => $title, 'format' => 'pdf', 'policy_pdf' => $pdf, 'draft_revision' => $revision]);
    }

    private function publish(): CompanyPolicyVersion
    {
        $revision = app(CompanyPolicyService::class)->company($this->owner)->policy_draft_revision;
        $this->web($this->owner)->post('/admin/company-policy/publish', ['draft_revision' => $revision])->assertRedirect('/admin/company-policy')->assertSessionHasNoErrors();

        return CompanyPolicyVersion::latest('id')->firstOrFail();
    }

    private function web(User $person): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->flushSession();

        return $this->actingAs($person, 'web');
    }

    private function mobile(User $person): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$person->createToken('PDF phone', ['mobile', $person->role], now()->addDay())->plainTextToken);
    }

    public function test_pdf_draft_is_private_and_does_not_notify_or_block_staff_until_published(): void
    {
        $this->save($this->pdf())->assertRedirect('/admin/company-policy')->assertSessionHasNoErrors();
        $company = app(CompanyPolicyService::class)->company($this->owner);
        Storage::disk('evidence')->assertExists($company->policy_draft_pdf_path);
        $this->assertStringStartsWith('company-policies/'.$company->id.'/', $company->policy_draft_pdf_path);
        $this->assertSame(hash_file('sha256', base_path('tests/Fixtures/company-policy.pdf')), $company->policy_draft_pdf_sha256);
        $this->assertSame('', $company->policy_draft_content);
        $this->assertDatabaseCount('alerts', 0);
        $this->web($this->employee)->get('/tasks')->assertOk();
        $this->get('/admin/company-policy/draft/pdf')->assertForbidden();
        $this->web($this->owner)->get('/admin/company-policy/draft/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/admin/company-policy')->assertOk()->assertSee('Upload PDF')->assertSee('company-policy.pdf')->assertSee('Publish policy');
        $policy = $this->publish();
        $this->assertSame($company->policy_draft_pdf_path, $policy->pdf_path);
        $this->assertSame($company->policy_draft_pdf_sha256, $policy->pdf_sha256);
        $this->assertDatabaseCount('alerts', 2);
        $this->assertNull($company->fresh()->policy_draft_pdf_path);
    }

    public function test_staff_can_read_and_download_while_locked_without_recording_confirmation(): void
    {
        $this->save($this->pdf());
        $policy = $this->publish();
        $path = '/company-policy/versions/'.$policy->id.'/pdf';
        foreach ([$this->employee, $this->manager] as $person) {
            $this->web($person)->get('/tasks')->assertRedirect('/company-policy');
            $this->get('/company-policy')->assertOk()->assertSee('Read policy')->assertSee('Download PDF')->assertSee('type="submit" disabled', false)->assertDontSee(' checked', false);
            $inline = $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
            $this->assertSame(file_get_contents(base_path('tests/Fixtures/company-policy.pdf')), $inline->streamedContent());
            $download = $this->get($path.'?download=1')->assertOk()->assertDownload('company-policy-v1.pdf')->assertHeader('Cache-Control', 'no-store, private');
            $this->assertSame($inline->streamedContent(), $download->streamedContent());
            $this->assertDatabaseCount('company_policy_acknowledgements', 0);
            $this->get('/tasks')->assertRedirect('/company-policy');
            $this->post('/logout')->assertRedirect('/login');

            $this->mobile($person);
            $this->getJson(self::API.'/company-policy')->assertOk()->assertJsonPath('policy.document.url', $path)->assertJsonPath('policy.document.name', 'company-policy.pdf')->assertJsonPath('required', true)->assertDontSee($policy->pdf_path)->assertDontSee($policy->pdf_sha256);
            $this->getJson(self::API.$path)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->getJson(self::API.$path.'?download=1')->assertOk()->assertDownload();
            $this->assertDatabaseCount('company_policy_acknowledgements', 0);
            $this->getJson(self::API.'/tasks')->assertStatus(423)->assertJsonPath('code', CompanyPolicyService::REQUIRED);
            $this->postJson(self::API.'/company-policy/acknowledge', ['policy_version_id' => $policy->id, 'acknowledged' => false])->assertUnprocessable();
            $this->postJson(self::API.'/logout')->assertOk();
        }
    }

    public function test_pdf_validation_rejects_renamed_truncated_wrong_extension_and_oversize_files(): void
    {
        config(['tasksure.upload_max_kb' => 2]);
        foreach ([
            UploadedFile::fake()->createWithContent('fake.pdf', 'Not a PDF'),
            UploadedFile::fake()->createWithContent('truncated.pdf', "%PDF-1.4\nunfinished"),
            $this->pdf('', 'company-policy.html'),
            $this->pdf(str_repeat('x', 2048)),
        ] as $file) {
            $this->save($file)->assertSessionHasErrors('policy_pdf');
            $this->assertCount(0, Storage::disk('evidence')->allFiles());
        }
        $this->save()->assertSessionHasErrors('policy_pdf');
        $this->assertSame(0, app(CompanyPolicyService::class)->company($this->owner)->policy_draft_revision);
        $this->assertDatabaseCount('company_policy_versions', 0);
    }

    public function test_pdf_upload_read_download_and_history_require_the_correct_company_and_role(): void
    {
        $this->save($this->pdf());
        $policy = $this->publish();
        $foreign = Company::create(['name' => 'Unrelated PDF company']);
        $otherOwner = $this->person('admin', $foreign);
        $otherEmployee = $this->person('employee', $foreign);
        $path = '/company-policy/versions/'.$policy->id.'/pdf';
        $this->web($otherOwner)->get($path)->assertNotFound();
        $this->get($path.'?download=1')->assertNotFound();
        $this->get('/admin/company-policy/versions/'.$policy->id)->assertNotFound();
        $this->get('/admin/company-policy/draft/pdf')->assertNotFound();
        $this->post('/admin/company-policy/draft', ['title' => 'Forged', 'format' => 'pdf', 'policy_pdf' => $this->pdf(), 'draft_revision' => 0, 'company_id' => $policy->company_id])->assertSessionHasErrors('company_id');
        $this->mobile($otherEmployee);
        $this->getJson(self::API.$path.'?company_id='.$policy->company_id)->assertNotFound();
        $this->getJson(self::API.$path.'?download=1')->assertNotFound();
        $this->getJson(self::API.'/tasks')->assertOk();
        $this->flushHeaders();
        foreach ([$this->employee, $this->manager] as $person) {
            // Staff without any published policy cannot manage drafts either.
            $staff = $this->person($person->role, $foreign);
            $this->web($staff)->post('/admin/company-policy/draft', ['title' => 'Forged', 'format' => 'pdf', 'policy_pdf' => $this->pdf(), 'draft_revision' => 0])->assertForbidden();
            $this->post('/admin/company-policy/publish', ['draft_revision' => 0])->assertForbidden();
        }
        $this->assertCount(1, Storage::disk('evidence')->allFiles());
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($path)->assertRedirect('/login');
        $this->getJson(self::API.$path)->assertUnauthorized();
    }

    public function test_replacement_is_a_draft_until_published_and_requires_the_exact_new_version(): void
    {
        $this->save($this->pdf());
        $first = $this->publish();
        $this->web($this->employee)->post('/company-policy/acknowledge', ['policy_version_id' => $first->id, 'acknowledged' => 1])->assertRedirect();
        $this->get('/tasks')->assertOk();
        $this->save($this->pdf("\n% revision two\n"), 'Replacement policy')->assertSessionHasNoErrors();
        $draft = app(CompanyPolicyService::class)->company($this->owner);
        $this->assertNotSame($first->pdf_path, $draft->policy_draft_pdf_path);
        $this->assertSame($first->id, $draft->current_policy_version_id);
        $this->web($this->employee)->get('/tasks')->assertOk();
        $this->assertDatabaseCount('alerts', 2);
        $second = $this->publish();
        $this->assertSame(2, $second->version);
        $this->assertNotSame($first->pdf_sha256, $second->pdf_sha256);
        Storage::disk('evidence')->assertExists([$first->pdf_path, $second->pdf_path]);
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/company-policy.pdf')), Storage::disk('evidence')->get($first->pdf_path));
        $this->mobile($this->employee);
        $this->postJson(self::API.'/company-policy/acknowledge', ['policy_version_id' => $first->id, 'acknowledged' => true])->assertStatus(423)->assertJsonPath('policy.id', $second->id)->assertJsonPath('policy.document.url', '/company-policy/versions/'.$second->id.'/pdf');
        $this->getJson(self::API.'/tasks')->assertStatus(423);
        $this->assertDatabaseCount('company_policy_acknowledgements', 1);
        $this->postJson(self::API.'/company-policy/acknowledge', ['policy_version_id' => $second->id, 'acknowledged' => true, 'user_id' => $this->manager->id])->assertUnprocessable();
        $payload = ['policy_version_id' => $second->id, 'acknowledged' => true];
        $ack = $this->postJson(self::API.'/company-policy/acknowledge', $payload)->assertOk()->assertJsonPath('acknowledged', true);
        $this->postJson(self::API.'/company-policy/acknowledge', $payload)->assertOk()->assertJsonPath('acknowledged_at', $ack->json('acknowledged_at'));
        $this->assertDatabaseCount('company_policy_acknowledgements', 2);
        $this->assertDatabaseHas('company_policy_acknowledgements', ['company_id' => $first->company_id, 'user_id' => $this->employee->id, 'policy_version_id' => $first->id]);
        $this->getJson(self::API.'/tasks')->assertOk();
        $new = $this->person('employee');
        $this->mobile($new);
        $this->getJson(self::API.'/tasks')->assertStatus(423)->assertJsonPath('policy.id', $second->id);
        $this->flushHeaders();
        $this->employee->update(['active' => false]);
        $this->web($this->owner)->get('/admin/company-policy/versions/'.$first->id)->assertOk()->assertSee($this->employee->name)->assertSee('Acknowledged')->assertSee('/company-policy/versions/'.$first->id.'/pdf', false);
        $this->get('/admin/company-policy?ack_status=outstanding')->assertOk()->assertSee($this->manager->name)->assertSee($new->name)->assertDontSee($this->employee->name);
    }

    public function test_title_edits_can_reuse_the_pdf_but_stale_draft_uploads_cannot_overwrite_it(): void
    {
        $this->save($this->pdf());
        $policy = $this->publish();
        $this->save(null, 'New title using existing PDF')->assertSessionHasNoErrors();
        $company = app(CompanyPolicyService::class)->company($this->owner);
        $this->assertSame($policy->pdf_path, $company->policy_draft_pdf_path);
        $this->save($this->pdf("\n% stale overwrite\n"), 'Stale draft', 0)->assertSessionHasErrors('draft_revision');
        $this->assertCount(1, Storage::disk('evidence')->allFiles());
        $this->assertSame('New title using existing PDF', $company->fresh()->policy_draft_title);
        $this->save(null, 'Updated saved title')->assertSessionHasNoErrors();
        $new = $this->publish();
        $this->assertSame($policy->pdf_path, $new->pdf_path);
        $this->assertSame(2, $new->version);
        $this->assertSame('Uploaded policy', $policy->fresh()->title);
    }

    public function test_a_missing_draft_pdf_cannot_be_published_or_used_as_a_new_draft(): void
    {
        $this->save($this->pdf());
        $company = app(CompanyPolicyService::class)->company($this->owner);
        Storage::disk('evidence')->delete($company->policy_draft_pdf_path);
        $this->post('/admin/company-policy/publish', ['draft_revision' => 1])->assertSessionHasErrors('policy_pdf');
        $this->save()->assertSessionHasErrors('policy_pdf');
        $this->assertDatabaseCount('company_policy_versions', 0);
    }
}
