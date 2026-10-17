<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyPolicyLibraryUpgradeTest extends TestCase
{
    public function test_library_upgrade_preserves_original_rows_and_keeps_old_uploads_as_one_policy_history(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $originalSchema = Schema::getFacadeRoot();
        config(['database.connections.library_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('library_upgrade');
        Schema::swap(DB::connection()->getSchemaBuilder());
        try {
            $migration = database_path('migrations/2026_10_17_000001_create_company_policy_library.php');
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if ($path < $migration) {
                    (require $path)->up();
                }
            }
            $company = DB::table('companies')->first();
            $store = DB::table('stores')->first();
            $time = '2026-10-01 08:00:00';
            foreach (['admin', 'employee'] as $i => $role) {
                DB::table('users')->insert(['id' => $i + 1, 'name' => 'Existing '.$role, 'email' => $role.'@pdf-upgrade.test', 'password' => 'original-password-hash', 'role' => $role, 'company_id' => $company->id, 'active' => true]);
            }
            DB::table('categories')->insert(['id' => 1, 'name' => 'Original stock']);
            DB::table('tasks')->insert(['id' => 1, 'store_id' => $store->id, 'creator_id' => 1, 'employee_id' => 2, 'category_id' => 1, 'title' => 'Original client task', 'instructions' => 'Keep this task', 'area' => 'Stock', 'priority' => 'normal', 'required_evidence' => '["note"]', 'status' => 'completed', 'completed_at' => $time, 'completed_by_id' => 2, 'due_at' => $time]);
            DB::table('submissions')->insert(['id' => 1, 'task_id' => 1, 'employee_id' => 2, 'note' => 'Original proof note', 'submitted_at' => $time, 'due_at' => $time]);
            DB::table('attachments')->insert(['id' => 1, 'task_id' => 1, 'submission_id' => 1, 'user_id' => 2, 'path' => 'original/evidence.pdf', 'original_name' => 'Original evidence.pdf', 'mime' => 'application/pdf', 'size' => 100, 'kind' => 'document']);
            DB::table('company_policy_versions')->insert(['id' => 1, 'company_id' => $company->id, 'version' => 1, 'title' => 'Existing written policy', 'content' => 'Original published content', 'published_by_id' => 1, 'published_at' => $time]);
            DB::table('company_policy_versions')->insert(['id' => 2, 'company_id' => $company->id, 'version' => 2, 'title' => 'Current PDF policy', 'content' => '', 'published_by_id' => 1, 'published_at' => $time, 'pdf_path' => 'company-policies/'.$company->id.'/original.pdf', 'pdf_name' => 'Original.pdf', 'pdf_size' => 2048, 'pdf_sha256' => str_repeat('a', 64)]);
            DB::table('companies')->where('id', $company->id)->update(['current_policy_version_id' => 2, 'policy_draft_title' => 'Unpublished replacement', 'policy_draft_content' => '', 'policy_draft_revision' => 8, 'policy_draft_pdf_path' => 'company-policies/'.$company->id.'/draft.pdf', 'policy_draft_pdf_name' => 'Replacement.pdf', 'policy_draft_pdf_size' => 4096, 'policy_draft_pdf_sha256' => str_repeat('b', 64)]);
            foreach ([1, 2] as $versionId) {
                DB::table('company_policy_acknowledgements')->insert(['company_id' => $company->id, 'user_id' => 2, 'policy_version_id' => $versionId, 'acknowledged_at' => $time]);
            }
            $otherId = DB::table('companies')->insertGetId(['name' => 'Other existing company', 'policy_draft_revision' => 0]);
            DB::table('company_policy_versions')->insert(['id' => 3, 'company_id' => $otherId, 'version' => 1, 'title' => 'Other company policy', 'content' => 'Separate content', 'published_by_id' => 1, 'published_at' => $time]);
            DB::table('companies')->where('id', $otherId)->update(['current_policy_version_id' => 3]);
            $draftOnlyId = DB::table('companies')->insertGetId(['name' => 'Draft-only company', 'policy_draft_title' => 'Never published', 'policy_draft_content' => 'Keep draft', 'policy_draft_revision' => 3]);
            $emptyId = DB::table('companies')->insertGetId(['name' => 'Company without policies', 'policy_draft_revision' => 0]);
            DB::table('sessions')->insert(['id' => 'original-session', 'user_id' => 2, 'payload' => 'preserve-session', 'last_activity' => 1]);
            DB::table('personal_access_tokens')->insert(['id' => 1, 'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => 2, 'name' => 'Original phone', 'token' => str_repeat('a', 64), 'abilities' => '["mobile","employee"]', 'store_id' => $store->id]);
            $before = [];
            foreach (['companies', 'company_policy_versions', 'company_policy_acknowledgements', 'users', 'tasks', 'submissions', 'attachments', 'sessions', 'personal_access_tokens'] as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $before[$table] = DB::table($table)->select($columns[$table])->get()->toJson();
            }
            (require $migration)->up();
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->select($columns[$table])->get()->toJson(), 'Original client fields changed in '.$table);
            }
            $this->assertSame(3, DB::table('company_policies')->count());
            $entry = DB::table('company_policies')->where('company_id', $company->id)->first();
            $this->assertNotNull($entry);
            $this->assertSame('Current PDF policy', $entry->name);
            $this->assertEquals(2, $entry->current_policy_version_id);
            $this->assertEquals(8, $entry->draft_revision);
            $this->assertSame('Unpublished replacement', $entry->draft_title);
            $this->assertSame('', $entry->draft_content);
            $this->assertSame('company-policies/'.$company->id.'/draft.pdf', $entry->draft_pdf_path);
            $this->assertSame('Replacement.pdf', $entry->draft_pdf_name);
            $this->assertEquals(4096, $entry->draft_pdf_size);
            $this->assertSame(str_repeat('b', 64), $entry->draft_pdf_sha256);
            $this->assertSame([1, 2], DB::table('company_policy_versions')->where('company_policy_id', $entry->id)->orderBy('id')->pluck('id')->all());
            $other = DB::table('company_policies')->where('company_id', $otherId)->first();
            $this->assertSame([3], DB::table('company_policy_versions')->where('company_policy_id', $other->id)->pluck('id')->all());
            $draftOnly = DB::table('company_policies')->where('company_id', $draftOnlyId)->first();
            $this->assertNull($draftOnly->current_policy_version_id);
            $this->assertSame('Never published', $draftOnly->draft_title);
            $this->assertSame('Keep draft', $draftOnly->draft_content);
            $this->assertEquals(3, $draftOnly->draft_revision);
            $this->assertFalse(DB::table('company_policies')->where('company_id', $emptyId)->exists());
            $this->assertFalse(DB::table('company_policy_versions')->whereNull('company_policy_id')->exists());
            $this->assertSame(0, DB::table('alerts')->count());
        } finally {
            DB::setDefaultConnection($originalConnection);
            Schema::swap($originalSchema);
            DB::purge('library_upgrade');
        }
    }
}
