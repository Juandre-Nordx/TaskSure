<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('employee');
            $t->boolean('active')->default(true);
        });
        Schema::create('manager_employee', function (Blueprint $t) {
            $t->foreignId('manager_id')->constrained('users');
            $t->foreignId('employee_id')->constrained('users');
            $t->primary(['manager_id', 'employee_id']);
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('task_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('creator_id')->constrained('users');
            $t->foreignId('employee_id')->constrained('users');
            $t->foreignId('category_id')->constrained();
            $t->string('title');
            $t->text('instructions');
            $t->string('area');
            $t->string('priority');
            $t->json('required_evidence');
            $t->unsignedTinyInteger('anchor_day')->nullable();
            $t->string('frequency');
            $t->date('next_date');
            $t->string('due_time');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('users');
            $t->foreignId('creator_id')->constrained('users');
            $t->foreignId('category_id')->constrained();
            $t->foreignId('task_template_id')->nullable()->constrained();
            $t->date('occurrence_date')->nullable();
            $t->string('title');
            $t->text('instructions');
            $t->string('area');
            $t->string('priority');
            $t->json('required_evidence');
            $t->string('status')->default('assigned');
            $t->dateTime('scheduled_at')->nullable();
            $t->dateTime('due_at')->index();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();
            $t->unique(['task_template_id', 'occurrence_date']);
        });
        Schema::create('submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained();
            $t->foreignId('employee_id')->constrained('users');
            $t->text('note')->nullable();
            $t->dateTime('submitted_at');
            $t->dateTime('due_at');
            $t->string('review_status')->default('pending');
            $t->foreignId('reviewer_id')->nullable()->constrained('users');
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained();
            $t->foreignId('submission_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('path');
            $t->string('original_name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('kind');
            $t->timestamps();
        });
        Schema::create('task_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('type');
            $t->text('body');
            $t->json('data')->nullable();
            $t->timestamps();
        });
        Schema::create('deadline_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->dateTime('old_due_at');
            $t->dateTime('new_due_at');
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('task_id')->nullable()->constrained();
            $t->string('type');
            $t->string('message');
            $t->string('dedupe_key', 191)->unique();
            $t->dateTime('read_at')->nullable();
            $t->string('email_status')->default('disabled');
            $t->dateTime('emailed_at')->nullable();
            $t->text('delivery_error')->nullable();
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
        });
        Schema::create('account_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->foreignId('subject_id')->nullable()->constrained('users');
            $t->string('action');
            $t->json('data')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['account_audits', 'settings', 'alerts', 'deadline_changes', 'task_activities', 'attachments', 'submissions', 'tasks', 'task_templates', 'categories', 'manager_employee'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active']));
    }
};
