<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_survives_a_new_filesystem_instance(): void
    {
        $root = sys_get_temp_dir().'/tasksure-proof-'.bin2hex(random_bytes(8));
        config(['filesystems.disks.evidence.root' => $root]);
        Storage::forgetDisk('evidence');
        $u = User::create(['name' => 'Employee', 'email' => 'proof@test.test', 'role' => 'employee', 'active' => true, 'password' => 'TestPassword123!']);
        $c = Category::create(['name' => 'Safety']);
        $t = Task::create(['employee_id' => $u->id, 'creator_id' => $u->id, 'category_id' => $c->id, 'title' => 'Inspect exits', 'instructions' => 'Check', 'area' => 'Exit', 'priority' => 'normal', 'required_evidence' => ['photo'], 'due_at' => now()->addHour()]);
        $this->actingAs($u)->post('/tasks/'.$t->id.'/uploads', ['file' => UploadedFile::fake()->image('proof.jpg')])->assertRedirect();
        $a = $t->attachments()->firstOrFail();
        $hash = hash_file('sha256', $root.'/'.$a->path);
        Storage::forgetDisk('evidence');
        $this->get('/attachments/'.$a->id)->assertOk();
        $this->assertEquals($hash, hash_file('sha256', Storage::disk('evidence')->path($a->path)));
        Storage::disk('evidence')->deleteDirectory('evidence');
        rmdir($root);
    }
}
