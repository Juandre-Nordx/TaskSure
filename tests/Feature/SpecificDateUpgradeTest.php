<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpecificDateUpgradeTest extends TestCase
{
    public function test_upgrade_preserves_existing_interval_schedules_tasks_accounts_and_proof(): void
    {
        $original = DB::getDefaultConnection();
        $schema = Schema::getFacadeRoot();
        config(['database.connections.dates_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('dates_upgrade');
        Schema::swap(DB::connection()->getSchemaBuilder());
        try {
            $migration = database_path('migrations/2026_10_17_000002_add_specific_date_schedules.php');
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if ($path < $migration) {
                    (require $path)->up();
                }
            }
            $company = DB::table('companies')->first();
            $store = DB::table('stores')->first();
            $time = '2026-10-01 15:00:00';
            foreach (['admin', 'employee'] as $i => $role) {
                DB::table('users')->insert(['id' => $i + 1, 'company_id' => $company->id, 'name' => 'Original '.$role, 'email' => $role.'@dates-upgrade.test', 'role' => $role, 'password' => 'original-hash', 'active' => true]);
                DB::table('store_user')->insert(['user_id' => $i + 1, 'store_id' => $store->id, 'active' => true]);
            }
            DB::table('categories')->insert(['id' => 1, 'name' => 'Original stock']);
            foreach (['daily', 'weekly', 'monthly'] as $i => $frequency) {
                DB::table('task_templates')->insert(['id' => $i + 1, 'store_id' => $store->id, 'creator_id' => 1, 'employee_id' => 2, 'category_id' => 1, 'title' => 'Original '.$frequency, 'instructions' => 'Keep this routine', 'area' => 'Beer', 'priority' => 'normal', 'required_evidence' => '["photo","note"]', 'frequency' => $frequency, 'next_date' => '2026-10-31', 'anchor_day' => 31, 'due_time' => '17:00', 'ends_on' => '2026-12-31', 'scheduled_offset_minutes' => 480, 'active' => true]);
            }
            DB::table('tasks')->insert(['id' => 1, 'task_template_id' => 1, 'occurrence_date' => '2026-10-01', 'store_id' => $store->id, 'creator_id' => 1, 'employee_id' => 2, 'category_id' => 1, 'title' => 'Original completed task', 'instructions' => 'Keep task', 'area' => 'Beer', 'priority' => 'normal', 'required_evidence' => '["photo","note"]', 'due_at' => $time, 'status' => 'completed', 'completed_at' => $time, 'completed_by_id' => 2]);
            DB::table('submissions')->insert(['id' => 1, 'task_id' => 1, 'employee_id' => 2, 'note' => 'Original completion note', 'submitted_at' => $time, 'due_at' => $time]);
            DB::table('attachments')->insert(['id' => 1, 'task_id' => 1, 'submission_id' => 1, 'user_id' => 2, 'path' => 'original/private.jpg', 'original_name' => 'Private.jpg', 'mime' => 'image/jpeg', 'size' => 100, 'kind' => 'photo']);
            DB::table('sessions')->insert(['id' => 'original-session', 'user_id' => 2, 'payload' => 'original', 'last_activity' => 1]);
            DB::table('personal_access_tokens')->insert(['tokenable_type' => 'App\\Models\\User', 'tokenable_id' => 2, 'name' => 'Original phone', 'token' => str_repeat('a', 64), 'abilities' => '["mobile","employee"]', 'store_id' => $store->id]);
            $before = [];
            $columns = [];
            foreach (['companies', 'stores', 'users', 'store_user', 'task_templates', 'tasks', 'submissions', 'attachments', 'sessions', 'personal_access_tokens'] as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $before[$table] = DB::table($table)->select($columns[$table])->get()->toJson();
            }
            (require $migration)->up();
            foreach ($before as $table => $snapshot) {
                $this->assertSame($snapshot, DB::table($table)->select($columns[$table])->get()->toJson(), 'Original fields changed in '.$table);
            }
            foreach (DB::table('task_templates')->get() as $plan) {
                $this->assertNull($plan->specific_dates);
                $this->assertNull($plan->specific_due_times);
                $this->assertNull($plan->creation_key);
                $this->assertEquals(0, $plan->schedule_revision);
            }
            $this->assertFalse((bool) DB::table('tasks')->value('schedule_removed'));
            $this->assertSame(0, DB::table('alerts')->count());
        } finally {
            DB::setDefaultConnection($original);
            Schema::swap($schema);
            DB::purge('dates_upgrade');
        }
    }
}
