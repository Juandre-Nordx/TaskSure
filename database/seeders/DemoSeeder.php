<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('Demo data is allowed only with APP_ENV=local.');
        }$password = env('DEMO_PASSWORD');
        if (strlen($password ?? '') < 12) {
            throw new \RuntimeException('Set a local-only DEMO_PASSWORD of at least 12 characters.');
        }$this->call(DatabaseSeeder::class);
        $owner = User::firstOrCreate(['email' => 'owner@tasksure.test'], ['name' => 'Alex Morgan', 'role' => 'admin', 'password' => $password]);
        $manager = User::firstOrCreate(['email' => 'manager@tasksure.test'], ['name' => 'Thandi Mokoena', 'role' => 'manager', 'password' => $password]);
        $names = ['Sipho Dlamini', 'Lerato Jacobs', 'Mia Daniels', 'Noah Petersen', 'Aisha Khan', 'Daniel Botha', 'Naledi Nkosi', 'Ethan Williams', 'Zanele Mthembu', 'James Adams'];
        $employees = [];
        for ($i = 0; $i < 50; $i++) {
            $employees[] = User::firstOrCreate(['email' => 'employee'.($i + 1).'@tasksure.test'], ['name' => $names[$i % 10].($i >= 10 ? ' '.(intdiv($i, 10) + 1) : ''), 'role' => 'employee', 'password' => $password]);
        }$manager->employees()->syncWithoutDetaching(array_map(fn ($e) => $e->id, array_slice($employees, 0, 25)));
        $duties = [['Restock beer fridges', 'Restocking', 'Beer fridges', 'Move cold stock forward, refill gaps, and photograph the completed fridge.'], ['Check wine expiry and damaged stock', 'Stock control', 'Wine aisle', 'Inspect the selected stock and record any damaged packaging.'], ['Clean the spirits aisle', 'Store presentation', 'Spirits aisle', 'Sweep the aisle and wipe shelf edges. Leave all walkways clear.'], ['Check the afternoon delivery', 'Deliveries', 'Receiving bay', 'Count cartons against the delivery slip and record discrepancies.'], ['Complete opening safety checks', 'Opening & closing', 'Front of store', 'Check emergency exits, signage, and opening readiness.'], ['Count selected whisky stock', 'Stock control', 'Premium spirits', 'Count the selected shelf and record the count in your completion note.']];
        foreach ($employees as $i => $e) {
            $d = $duties[$i % 6];
            $date = now()->timezone('Africa/Johannesburg')->startOfDay()->addDays(($i % 5) - 1)->setTime(17, 0);
            $t = Task::firstOrCreate(['title' => $d[0], 'employee_id' => $e->id, 'creator_id' => $owner->id], ['instructions' => $d[3], 'category_id' => Category::where('name', $d[1])->value('id'), 'area' => $d[2], 'priority' => ['normal', 'high', 'normal', 'urgent', 'low'][$i % 5], 'required_evidence' => ['photo', 'note'], 'due_at' => $date->utc()]);
            if ($t->wasRecentlyCreated) {
                $t->activities()->create(['user_id' => $owner->id, 'type' => 'assigned', 'body' => 'Task assigned for the store shift.']);
                app(AlertService::class)->send($e, $t, 'assigned', 'You were assigned '.$t->title, 'assigned:'.$t->id);
                if ($i % 5 === 2) {
                    $t->update(['status' => 'in_progress', 'started_at' => now()->subHour()]);
                }if ($i % 5 === 3) {
                    $s = $t->submissions()->create(['employee_id' => $e->id, 'note' => 'Demo record: shift checklist completed.', 'submitted_at' => now()->subMinutes(20), 'due_at' => $t->due_at]);
                    $t->update(['status' => 'submitted']);
                }if ($i % 5 === 4) {
                    $s = $t->submissions()->create(['employee_id' => $e->id, 'note' => 'Demo record: count completed.', 'submitted_at' => now()->subHour(), 'due_at' => $t->due_at, 'review_status' => 'approved', 'reviewer_id' => $owner->id, 'reviewed_at' => now()]);
                    $t->update(['status' => 'approved', 'approved_at' => now()]);
                }
            }
        }

        // Clearly labelled synthetic local fixtures; never actual employee proof.
        foreach (Task::where('creator_id', $owner->id)->with('submissions.attachments')->get() as $task) {
            foreach ($task->submissions as $submission) {
                if (! str_starts_with($submission->note ?? '', 'Demo record:') || $submission->attachments->isNotEmpty()) {
                    continue;
                }
                $image = imagecreatetruecolor(480, 280);
                $background = imagecolorallocate($image, 233, 239, 223);
                $ink = imagecolorallocate($image, 21, 62, 53);
                imagefill($image, 0, 0, $background);
                imagestring($image, 5, 25, 40, 'TASKSURE - FICTIONAL DEMO EVIDENCE', $ink);
                imagestring($image, 4, 25, 90, substr($task->title, 0, 48), $ink);
                imagestring($image, 4, 25, 140, 'Illustrative fixture. Not real proof of work.', $ink);
                ob_start();
                imagepng($image);
                $bytes = ob_get_clean();
                imagedestroy($image);
                $path = 'evidence/'.Str::uuid().'.png';
                Storage::disk('evidence')->put($path, $bytes);
                $submission->attachments()->create(['task_id' => $task->id, 'user_id' => $submission->employee_id, 'path' => $path, 'original_name' => 'fictional-demo-evidence.png', 'mime' => 'image/png', 'size' => strlen($bytes), 'kind' => 'photo']);
            }
        }
        TaskTemplate::firstOrCreate(['title' => 'Daily opening checks'], ['creator_id' => $owner->id, 'employee_id' => $employees[0]->id, 'category_id' => Category::where('name', 'Opening & closing')->value('id'), 'instructions' => 'Check exits and confirm readiness for opening.', 'area' => 'Front of store', 'priority' => 'high', 'required_evidence' => ['note'], 'frequency' => 'daily', 'next_date' => now()->timezone('Africa/Johannesburg')->addDay()->toDateString(), 'anchor_day' => 1, 'due_time' => '09:00', 'active' => true]);
    }
}
