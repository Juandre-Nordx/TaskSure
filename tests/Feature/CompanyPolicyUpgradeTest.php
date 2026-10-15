<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyPolicyUpgradeTest extends TestCase
{
    public function test_policy_upgrade_preserves_pre_existing_accounts_tasks_evidence_and_sign_ins(): void
    {
        // A separate in-memory database represents an installed application before this feature.
        // It never alters the suite's main database or a developer/production database.
        $originalConnection = DB::getDefaultConnection();
        $originalSchema = Schema::getFacadeRoot();
        config(['database.connections.policy_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('policy_upgrade');
        Schema::swap(DB::connection()->getSchemaBuilder());
        try {
            $policyPath = database_path('migrations/2026_10_14_000002_create_company_policies.php');
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if ($path !== $policyPath) {
                    (require $path)->up();
                }
            }
            $time = '2026-10-01 08:00:00';
            foreach (['admin', 'manager', 'employee'] as $index => $role) {
                DB::table('users')->insert(['id' => $index + 1, 'name' => 'Existing '.$role, 'email' => $role.'@upgrade.test', 'password' => 'existing-hash-preserve-verbatim', 'role' => $role, 'active' => $role !== 'manager', 'remember_token' => 'existing-remember-token', 'created_at' => $time, 'updated_at' => $time]);
            }
            DB::table('manager_employee')->insert(['manager_id' => 2, 'employee_id' => 3]);
            DB::table('categories')->insert(['id' => 1, 'name' => 'Existing category']);
            DB::table('tasks')->insert(['id' => 1, 'employee_id' => 3, 'creator_id' => 1, 'category_id' => 1, 'title' => 'Existing completed task', 'instructions' => 'Preserve instructions', 'area' => 'Stockroom', 'priority' => 'normal', 'required_evidence' => '["photo","note"]', 'status' => 'completed', 'due_at' => $time, 'completed_at' => $time, 'completed_by_id' => 3, 'created_at' => $time, 'updated_at' => $time]);
            DB::table('submissions')->insert(['id' => 1, 'task_id' => 1, 'employee_id' => 3, 'note' => 'Original note', 'submitted_at' => $time, 'due_at' => $time]);
            DB::table('attachments')->insert(['id' => 1, 'task_id' => 1, 'submission_id' => 1, 'user_id' => 3, 'path' => 'existing/private-photo.jpg', 'original_name' => 'proof.jpg', 'mime' => 'image/jpeg', 'size' => 1024, 'kind' => 'photo']);
            DB::table('personal_access_tokens')->insert(['id' => 1, 'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => 3, 'name' => 'Existing phone', 'token' => str_repeat('a', 64), 'abilities' => '["employee"]']);
            DB::table('sessions')->insert(['id' => 'existing-session', 'user_id' => 3, 'payload' => 'preserve-session', 'last_activity' => 1]);

            $userColumns = Schema::getColumnListing('users');
            $usersBefore = DB::table('users')->select($userColumns)->orderBy('id')->get()->toJson();
            $snapshots = [];
            foreach (['manager_employee', 'categories', 'tasks', 'submissions', 'attachments', 'personal_access_tokens', 'sessions'] as $table) {
                $snapshots[$table] = DB::table($table)->get()->toJson();
            }
            (require $policyPath)->up();

            $this->assertSame($usersBefore, DB::table('users')->select($userColumns)->orderBy('id')->get()->toJson());
            foreach ($snapshots as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->get()->toJson(), $table.' changed during policy upgrade');
            }
            $company = DB::table('companies')->sole();
            $this->assertTrue((bool) $company->is_primary);
            $this->assertNull($company->current_policy_version_id);
            $this->assertSame(3, DB::table('users')->where('company_id', $company->id)->count());
            $this->assertSame(0, DB::table('company_policy_versions')->count());
            $this->assertSame(0, DB::table('company_policy_acknowledgements')->count());

            // Account creation by the previous release omits company_id during deployment.
            DB::table('users')->insert(['name' => 'Staff created during release', 'email' => 'release@upgrade.test', 'password' => 'existing-hash', 'role' => 'employee']);
            $this->assertSame($company->id, DB::table('users')->where('email', 'release@upgrade.test')->value('company_id'));
        } finally {
            DB::setDefaultConnection($originalConnection);
            Schema::swap($originalSchema);
            DB::purge('policy_upgrade');
        }
    }
}
