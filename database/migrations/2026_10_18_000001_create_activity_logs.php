<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at')->index();
            // Snapshot identifiers intentionally have no cascading foreign keys.
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name', 255);
            $table->string('actor_email', 255)->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->string('attribution', 20);
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('company_name', 180)->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('store_name', 180)->nullable();
            $table->string('category', 30);
            $table->string('action', 80);
            $table->string('result', 20);
            $table->string('source', 20);
            $table->string('record_type', 40);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('record_label', 220);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('details')->nullable();
            $table->text('search_text');
            $table->string('origin_key', 180)->nullable()->unique();
            $table->index(['company_id', 'occurred_at']);
            $table->index(['store_id', 'occurred_at']);
            $table->index(['category', 'action', 'occurred_at']);
            $table->index(['result', 'occurred_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Activity history must be retained. Revert application code without dropping the activity_logs table.');
    }
};
