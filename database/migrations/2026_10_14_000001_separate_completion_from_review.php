<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stop before altering anything if a deployment has statuses this release cannot interpret.
        $unknown = DB::table('tasks')->whereNotIn('status', ['assigned', 'in_progress', 'submitted', 'approved', 'changes_requested', 'cancelled', 'completed'])->distinct()->pluck('status');
        if ($unknown->isNotEmpty()) {
            throw new RuntimeException('Unrecognised task statuses: '.$unknown->implode(', ').'. Review these records before migrating.');
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dateTime('completed_at')->nullable()->index();
            $table->foreignId('completed_by_id')->nullable()->constrained('users');
            $table->foreignId('current_completion_id')->nullable()->constrained('submissions');
            $table->string('review_status')->default('not_reviewed')->index();
            $table->dateTime('reviewed_at')->nullable()->index();
            $table->foreignId('reviewer_id')->nullable()->constrained('users');
        });
        Schema::table('submissions', function (Blueprint $table) {
            $table->dateTime('started_at')->nullable();
        });
        Schema::create('task_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained();
            $table->foreignId('submission_id')->nullable()->constrained();
            $table->foreignId('reviewer_id')->nullable()->constrained('users');
            $table->string('action');
            $table->text('note')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->timestamps();
        });

        $this->migrateLegacyTasks();
    }

    public function migrateLegacyTasks(): void
    {
        DB::table('tasks')->whereIn('status', ['submitted', 'approved', 'changes_requested'])->orderBy('id')->chunkById(100, function ($tasks) {
            foreach ($tasks as $task) {
                DB::transaction(function () use ($task) {
                    $latest = DB::table('submissions')->where('task_id', $task->id)->orderByDesc('id')->first();
                    $approval = $task->status === 'approved'
                        ? DB::table('submissions')->where('task_id', $task->id)->where('review_status', 'approved')->orderByDesc('id')->first()
                        : null;
                    $reviewedAt = $approval?->reviewed_at ?? ($task->status === 'approved' ? $task->approved_at : null);
                    $reviewerId = $approval?->reviewer_id;
                    $reviewed = $task->status === 'approved' && ($reviewedAt !== null || $reviewerId !== null);
                    $completed = in_array($task->status, ['submitted', 'approved']);
                    DB::table('tasks')->where('id', $task->id)->update([
                        'status' => $completed ? 'completed' : 'assigned',
                        'completed_at' => $completed ? $latest?->submitted_at : null,
                        'completed_by_id' => $completed ? $latest?->employee_id : null,
                        'current_completion_id' => $completed ? $latest?->id : null,
                        'review_status' => $reviewed ? 'reviewed' : 'not_reviewed',
                        'reviewed_at' => $reviewedAt,
                        'reviewer_id' => $reviewerId,
                    ]);
                    if ($reviewed) {
                        DB::table('task_reviews')->insert([
                            'task_id' => $task->id, 'submission_id' => $approval?->id,
                            'reviewer_id' => $reviewerId, 'action' => 'legacy_approval',
                            'note' => $approval?->review_reason, 'occurred_at' => $reviewedAt,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    DB::table('task_activities')->insert([
                        'task_id' => $task->id, 'user_id' => null, 'type' => 'workflow_migrated',
                        'body' => 'Legacy task moved to the completion and optional review workflow. Original attempt and approval records retained.',
                        'data' => json_encode(['previous_status' => $task->status, 'status' => $completed ? 'completed' : 'assigned', 'completion_details_unknown' => $completed && ! $latest]),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                });
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('This data-preserving workflow migration is forward-only. Restore a verified backup for a coordinated rollback.');
    }
};
