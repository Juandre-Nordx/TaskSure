<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyPolicyPdfUpgradeTest extends TestCase
{
    public function test_pdf_upgrade_keeps_existing_policies_acknowledgements_accounts_and_task_records_unchanged(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $originalSchema = Schema::getFacadeRoot();
        config(['database.connections.pdf_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('pdf_upgrade');
        Schema::swap(DB::connection()->getSchemaBuilder());
        try {
            $migration = database_path('migrations/2026_10_16_000002_add_company_policy_pdfs.php');
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
            DB::table('companies')->where('id', $company->id)->update(['current_policy_version_id' => 1, 'policy_draft_title' => 'Unpublished written changes', 'policy_draft_content' => 'Keep the saved draft', 'policy_draft_revision' => 8]);
            DB::table('company_policy_acknowledgements')->insert(['company_id' => $company->id, 'user_id' => 2, 'policy_version_id' => 1, 'acknowledged_at' => $time]);
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
            $this->assertNull(DB::table('companies')->value('policy_draft_pdf_path'));
            $this->assertNull(DB::table('company_policy_versions')->value('pdf_path'));
            $this->assertSame(0, DB::table('alerts')->count());
        } finally {
            DB::setDefaultConnection($originalConnection);
            Schema::swap($originalSchema);
            DB::purge('pdf_upgrade');
        }
    }
}
