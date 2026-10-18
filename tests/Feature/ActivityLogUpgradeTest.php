<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActivityLogUpgradeTest extends TestCase
{
    public function test_installing_activity_tracking_preserves_all_original_rows_and_private_evidence(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $originalSchema = Schema::getFacadeRoot();
        config(['database.connections.activity_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::purge('activity_upgrade');
        DB::setDefaultConnection('activity_upgrade');
        Schema::swap(DB::connection('activity_upgrade')->getSchemaBuilder());
        try {
            $new = database_path('migrations/2026_10_18_000001_create_activity_logs.php');
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if ($path !== $new) {
                    (require $path)->up();
                }
            }
            $store = DB::table('stores')->first();
            $at = '2026-10-01 08:00:00';
            $userId = DB::table('users')->insertGetId(['name' => 'Existing actor', 'email' => 'existing@upgrade.test', 'password' => 'Preserved historical hash', 'role' => 'employee', 'active' => true, 'company_id' => $store->company_id, 'created_at' => $at, 'updated_at' => $at]);
            DB::table('store_user')->insert(['store_id' => $store->id, 'user_id' => $userId, 'active' => true]);
            $categoryId = DB::table('categories')->insertGetId(['name' => 'Existing category', 'created_at' => $at, 'updated_at' => $at]);
            $taskId = DB::table('tasks')->insertGetId(['title' => 'Existing task', 'instructions' => 'Preserve original instructions', 'area' => 'Wine', 'priority' => 'high', 'employee_id' => $userId, 'creator_id' => $userId, 'category_id' => $categoryId, 'required_evidence' => '["note"]', 'due_at' => $at, 'store_id' => $store->id, 'created_at' => $at, 'updated_at' => $at]);
            DB::table('account_audits')->insert(['actor_id' => $userId, 'subject_id' => $userId, 'action' => 'updated', 'data' => '{"before":{"name":"Old"},"after":{"name":"Existing actor"}}', 'store_id' => $store->id, 'company_id' => $store->company_id, 'created_at' => $at, 'updated_at' => $at]);
            DB::table('task_activities')->insert(['task_id' => $taskId, 'user_id' => $userId, 'type' => 'assigned', 'body' => 'Original history', 'created_at' => $at, 'updated_at' => $at]);
            Storage::fake('evidence');
            Storage::disk('evidence')->put('existing-proof.jpg', 'Original private evidence bytes');
            DB::table('attachments')->insert(['task_id' => $taskId, 'user_id' => $userId, 'path' => 'existing-proof.jpg', 'original_name' => 'proof.jpg', 'mime' => 'image/jpeg', 'kind' => 'photo', 'size' => 31, 'created_at' => $at, 'updated_at' => $at]);
            $hash = hash('sha256', Storage::disk('evidence')->get('existing-proof.jpg'));
            $tables = array_column(Schema::getTables(), 'name');
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->get()->toJson();
            }
            (require $new)->up();
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->get()->toJson(), 'Original rows changed in '.$table);
            }
            $this->assertSame($hash, hash('sha256', Storage::disk('evidence')->get('existing-proof.jpg')));
            $this->assertSame(0, ActivityLog::count());
            $log = app(ActivityLogger::class)->write('task.created', Task::findOrFail($taskId), actor: User::findOrFail($userId));
            $this->assertSame($userId, $log->actor_id);
            $this->assertSame($store->id, $log->store_id);
            $this->assertSame(1, ActivityLog::count());
        } finally {
            DB::disconnect('activity_upgrade');
            DB::purge('activity_upgrade');
            DB::setDefaultConnection($originalConnection);
            Schema::swap($originalSchema);
        }
    }
}
