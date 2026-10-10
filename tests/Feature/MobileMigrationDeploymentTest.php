<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileMigrationDeploymentTest extends TestCase
{
    // Deliberately no RefreshDatabase/DatabaseMigrations: forward-only migration
    // into unused test tables, without resetting any database or running seeders.
    public function test_pending_mobile_migration_preserves_existing_records_and_restores_employee_login(): void
    {
        $settings = config('database.connections.'.config('database.default'));
        if ($settings['driver'] === 'sqlite') {
            $settings['database'] = ':memory:';
        } else {
            $this->assertStringEndsWith('_test', $settings['database'], 'This check requires an isolated database ending in _test.');
            $this->assertEmpty($settings['url'] ?? null, 'Do not use a database URL override for this test.');
        }
        // Keep generated Sanctum index names within MySQL's 64-character limit.
        $settings['prefix'] = 'm'.bin2hex(random_bytes(3)).'_';
        config(['database.connections.mobile_migration_test' => $settings, 'database.default' => 'mobile_migration_test', 'app.debug' => false, 'mobile.push_enabled' => false]);
        $this->assertFalse(Schema::hasTable('users'));
        $mobile = 'database/migrations/2026_10_10_000001_create_mobile_access_tables.php';
        $legacy = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) !== basename($mobile)));
        $this->artisan('migrate', ['--force' => true, '--path' => $legacy, '--realpath' => true])->assertExitCode(0);

        $employee = User::create(['name' => 'Existing employee', 'email' => 'existing.employee@migration.test', 'role' => 'employee', 'active' => true, 'password' => 'ExistingPassword123!']);
        $manager = User::create(['name' => 'Existing manager', 'email' => 'existing.manager@migration.test', 'role' => 'manager', 'active' => true, 'password' => 'ExistingPassword123!']);
        $manager->employees()->attach($employee);
        $category = Category::create(['name' => 'Existing category']);
        $task = Task::create(['employee_id' => $employee->id, 'creator_id' => $manager->id, 'category_id' => $category->id, 'title' => 'Existing task', 'instructions' => 'Keep task history', 'area' => 'Store', 'priority' => 'normal', 'required_evidence' => ['photo'], 'status' => 'changes_requested', 'due_at' => now()->addHour()]);
        $submission = $task->submissions()->create(['employee_id' => $employee->id, 'note' => 'Existing immutable attempt', 'submitted_at' => now(), 'due_at' => $task->due_at, 'review_status' => 'changes_requested', 'reviewer_id' => $manager->id, 'reviewed_at' => now(), 'review_reason' => 'Existing feedback']);
        Storage::fake('evidence');
        $image = UploadedFile::fake()->image('old-proof.jpg');
        $bytes = file_get_contents($image->getRealPath());
        Storage::disk('evidence')->put('old-proof.jpg', $bytes);
        $attachment = $task->attachments()->create(['submission_id' => $submission->id, 'user_id' => $employee->id, 'path' => 'old-proof.jpg', 'original_name' => 'old-proof.jpg', 'mime' => 'image/jpeg', 'kind' => 'photo', 'size' => strlen($bytes)]);
        Alert::create(['user_id' => $employee->id, 'task_id' => $task->id, 'type' => 'assigned', 'message' => 'Existing alert', 'dedupe_key' => 'existing-alert']);

        $legacyTables = ['users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'manager_employee', 'categories', 'task_templates', 'tasks', 'submissions', 'attachments', 'task_activities', 'deadline_changes', 'alerts', 'settings', 'account_audits'];
        $snapshot = fn () => collect($legacyTables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all()])->all();
        $before = $snapshot();
        $this->assertSame(1, Artisan::call('tasksure:mobile-schema', ['--json' => true]));
        $this->assertFalse(json_decode(Artisan::output(), true)['ready']);
        $login = ['email' => $employee->email, 'password' => 'ExistingPassword123!', 'device_name' => 'Android migration test'];
        $this->postJson('/api/mobile/v1/login', $login)->assertStatus(500);

        $this->artisan('migrate', ['--force' => true, '--pretend' => true, '--path' => [$mobile]])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('personal_access_tokens'));
        $this->assertSame($before, $snapshot());
        $this->artisan('migrate', ['--force' => true, '--path' => [$mobile]])->assertExitCode(0);
        $this->assertSame($before, $snapshot());
        $this->assertSame($bytes, Storage::disk('evidence')->get('old-proof.jpg'));
        $this->assertSame(0, Artisan::call('tasksure:mobile-schema', ['--json' => true]));
        $report = json_decode(Artisan::output(), true);
        $this->assertTrue($report['ready']);
        $this->assertTrue($report['migration_recorded']);
        $this->assertSame($settings['driver'], $report['driver']);

        $token = $this->postJson('/api/mobile/v1/login', $login)->assertOk()->assertJsonPath('user.id', $employee->id)->json('token');
        $this->assertNotEmpty($token);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token);
        $this->getJson('/api/mobile/v1/me')->assertOk()->assertJsonPath('user.role', 'employee');
        $this->getJson('/api/mobile/v1/tasks')->assertOk()->assertJsonPath('data.0.id', $task->id);
        $download = $this->getJson('/api/mobile/v1/attachments/'.$attachment->id.'?preview=1')->assertOk();
        $this->assertSame($bytes, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->postJson('/api/mobile/v1/devices', ['platform' => 'android', 'token' => 'migration-test-device'])->assertOk();
        $this->postJson('/api/mobile/v1/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/mobile/v1/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('push_devices', 0);
        $this->postJson('/api/mobile/v1/login', array_replace($login, ['email' => $manager->email]))->assertForbidden();
        $this->assertSame($before, $snapshot());

        $this->artisan('migrate', ['--force' => true, '--path' => [$mobile]])->assertExitCode(0);
        $this->assertSame(1, DB::table('migrations')->where('migration', basename($mobile, '.php'))->count());
        $this->assertSame($before, $snapshot());
    }
}
