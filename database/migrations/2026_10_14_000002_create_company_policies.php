<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_primary')->default(false);
            $table->text('policy_draft_title')->nullable();
            $table->longText('policy_draft_content')->nullable();
            $table->unsignedInteger('policy_draft_revision')->default(0);
            $table->timestamps();
        });
        $companyId = DB::table('companies')->insertGetId(['name' => config('app.name', 'TaskSure'), 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        Schema::table('users', function (Blueprint $table) use ($companyId) {
            // Old web processes may still create staff during the pre-deploy window.
            $table->foreignId('company_id')->nullable()->default($companyId)->index()->constrained();
        });
        // Existing deployments represent one business. Preserve all existing accounts and data.
        DB::table('users')->whereNull('company_id')->update(['company_id' => $companyId]);
        Schema::create('company_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->unsignedInteger('version');
            $table->string('title', 180);
            $table->longText('content');
            $table->foreignId('published_by_id')->constrained('users');
            $table->dateTime('published_at');
            $table->unique(['company_id', 'version']);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('current_policy_version_id')->nullable()->constrained('company_policy_versions');
        });
        Schema::create('company_policy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('policy_version_id')->constrained('company_policy_versions');
            $table->dateTime('acknowledged_at');
            $table->unique(['user_id', 'policy_version_id'], 'policy_ack_user_version_unique');
            $table->index(['company_id', 'policy_version_id'], 'policy_ack_company_version_index');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Company policies and acknowledgement history must be preserved. Use a reviewed forward migration or a coordinated backup restore.');
    }
};
