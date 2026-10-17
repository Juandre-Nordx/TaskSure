<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->json('specific_dates')->nullable();
            $table->json('specific_due_times')->nullable();
            $table->unsignedInteger('schedule_revision')->default(0);
            $table->uuid('creation_key')->nullable()->unique();
            $table->string('creation_fingerprint', 64)->nullable();
        });
        Schema::table('tasks', fn (Blueprint $table) => $table->boolean('schedule_removed')->default(false));
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve dated schedules and their occurrence history. Use a reviewed forward migration.');
    }
};
