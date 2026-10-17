<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoreUpgradeTest extends TestCase
{
    public function test_existing_installation_retains_accounts_policy_evidence_history_and_tokens_during_store_upgrade(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $originalSchema = Schema::getFacadeRoot();
        config(['database.connections.store_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('store_upgrade');
        Schema::swap(DB::connection()->getSchemaBuilder());
        try {
            $migration = database_path('migrations/2026_10_16_000001_add_store_workspaces.php');
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if ($path < $migration) {
                    (require $path)->up();
                }
            }
            $company = DB::table('companies')->first();
            $time = '2026-10-01 08:00:00';
            foreach (['admin', 'manager', 'employee'] as $i => $role) {
                DB::table('users')->insert(['id' => $i + 1, 'name' => 'Original '.$role, 'email' => $role.'@store-upgrade.test', 'password' => Hash::make('ExistingPassword123!'), 'role' => $role, 'active' => true, 'company_id' => $company->id, 'remember_token' => 'original-remember', 'created_at' => $time, 'updated_at' => $time]);
            }
            DB::table('manager_employee')->insert(['manager_id' => 2, 'employee_id' => 3]);
            DB::table('categories')->insert(['id' => 1, 'name' => 'Original stock']);
            DB::table('settings')->insert(['key' => 'reminder_minutes', 'value' => '90']);
            DB::table('task_templates')->insert(['id' => 1, 'creator_id' => 1, 'employee_id' => 3, 'category_id' => 1, 'title' => 'Original recurring', 'instructions' => 'Original instructions', 'area' => 'Cold room', 'priority' => 'high', 'required_evidence' => '["photo"]', 'frequency' => 'monthly', 'anchor_day' => 31, 'next_date' => '2026-10-31', 'due_time' => '17:00', 'active' => true]);
            DB::table('tasks')->insert(['id' => 1, 'creator_id' => 1, 'employee_id' => 3, 'category_id' => 1, 'task_template_id' => 1, 'occurrence_date' => '2026-09-30', 'title' => 'Original completed task', 'instructions' => 'Original instructions', 'area' => 'Cold room', 'priority' => 'high', 'required_evidence' => '["photo"]', 'status' => 'completed', 'review_status' => 'reviewed', 'due_at' => $time, 'completed_at' => $time, 'completed_by_id' => 3, 'reviewed_at' => $time, 'reviewer_id' => 2, 'created_at' => $time, 'updated_at' => $time]);
            DB::table('submissions')->insert(['id' => 1, 'task_id' => 1, 'employee_id' => 3, 'note' => 'Original proof note', 'submitted_at' => $time, 'due_at' => $time, 'review_status' => 'approved', 'reviewer_id' => 2, 'reviewed_at' => $time]);
            DB::table('attachments')->insert(['id' => 1, 'task_id' => 1, 'submission_id' => 1, 'user_id' => 3, 'path' => 'original/private-photo.jpg', 'original_name' => 'proof.jpg', 'mime' => 'image/jpeg', 'size' => 1234, 'kind' => 'photo']);
            DB::table('task_activities')->insert(['task_id' => 1, 'user_id' => 3, 'type' => 'completed', 'body' => 'Original completion', 'created_at' => $time]);
            DB::table('deadline_changes')->insert(['task_id' => 1, 'user_id' => 1, 'old_due_at' => $time, 'new_due_at' => $time, 'reason' => 'Original change']);
            DB::table('company_policy_versions')->insert(['id' => 1, 'company_id' => $company->id, 'version' => 1, 'title' => 'Original policy', 'content' => 'Original policy content', 'published_by_id' => 1, 'published_at' => $time]);
            DB::table('companies')->where('id', $company->id)->update(['current_policy_version_id' => 1]);
            DB::table('company_policy_acknowledgements')->insert(['company_id' => $company->id, 'user_id' => 3, 'policy_version_id' => 1, 'acknowledged_at' => $time]);
            DB::table('personal_access_tokens')->insert(['id' => 1, 'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => 3, 'name' => 'Original phone', 'token' => hash('sha256', 'original-phone-token'), 'abilities' => '["mobile","employee"]', 'expires_at' => now()->addDays(10)]);
            DB::table('push_devices')->insert(['id' => 1, 'user_id' => 3, 'personal_access_token_id' => 1, 'platform' => 'android', 'token_hash' => hash('sha256', 'original-device'), 'token' => 'original-encrypted-device-value']);
            DB::table('alerts')->insert(['id' => 1, 'user_id' => 3, 'task_id' => 1, 'type' => 'assigned', 'message' => 'Original notification', 'dedupe_key' => 'original-assigned']);
            DB::table('push_deliveries')->insert(['alert_id' => 1, 'push_device_id' => 1, 'status' => 'sent', 'sent_at' => $time]);
            DB::table('sessions')->insert(['id' => 'original-session', 'user_id' => 1, 'payload' => 'original-session-payload', 'last_activity' => now()->timestamp]);
            DB::table('account_audits')->insert(['actor_id' => 1, 'subject_id' => 3, 'action' => 'original_account_audit', 'data' => '{"original":true}']);
            $snapshots = [];
            foreach (['users', 'companies', 'manager_employee', 'categories', 'settings', 'tasks', 'task_templates', 'submissions', 'attachments', 'task_activities', 'deadline_changes', 'company_policy_versions', 'company_policy_acknowledgements', 'personal_access_tokens', 'push_devices', 'push_deliveries', 'alerts', 'sessions', 'account_audits'] as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $snapshots[$table] = DB::table($table)->select($columns[$table])->get()->toJson();
            }
            (require $migration)->up();
            foreach ($snapshots as $table => $before) {
                $this->assertSame($before, DB::table($table)->select($columns[$table])->get()->toJson(), 'Original fields changed in '.$table);
            }
            $store = DB::table('stores')->sole();
            $this->assertSame($company->id, $store->company_id);
            $this->assertSame(1, $store->owner_id);
            $this->assertSame(3, DB::table('store_user')->where('store_id', $store->id)->count());
            $this->assertDatabaseHas('store_manager_employee', ['store_id' => $store->id, 'manager_id' => 2, 'employee_id' => 3]);
            $this->assertDatabaseHas('store_settings', ['store_id' => $store->id, 'key' => 'reminder_minutes', 'value' => '90']);
            $this->assertDatabaseHas('category_store', ['store_id' => $store->id, 'category_id' => 1]);
            foreach (['tasks', 'task_templates', 'personal_access_tokens', 'push_devices', 'alerts'] as $table) {
                $this->assertSame($store->id, DB::table($table)->where('id', 1)->value('store_id'));
            }
            $this->assertSame(0, DB::table('users')->where('role', 'super_admin')->count());
            (require database_path('migrations/2026_10_16_000002_add_company_policy_pdfs.php'))->up();
            (require database_path('migrations/2026_10_17_000001_create_company_policy_library.php'))->up();
            $this->actingAs(User::findOrFail(1))->get('/')->assertOk();
            $this->app['auth']->forgetGuards();
            $this->withHeader('Authorization', 'Bearer 1|original-phone-token')->getJson('/api/mobile/v1/tasks')->assertOk()->assertJsonPath('data.0.title', 'Original completed task');
        } finally {
            DB::setDefaultConnection($originalConnection);
            Schema::swap($originalSchema);
            DB::purge('store_upgrade');
        }
    }
}
