<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name', 180)->default('Company policy');
            $table->foreignId('current_policy_version_id')->nullable()->constrained('company_policy_versions');
            $table->string('draft_title', 180)->nullable();
            $table->longText('draft_content')->nullable();
            $table->unsignedInteger('draft_revision')->default(0);
            $table->string('draft_pdf_path')->nullable();
            $table->string('draft_pdf_name', 180)->nullable();
            $table->unsignedBigInteger('draft_pdf_size')->nullable();
            $table->string('draft_pdf_sha256', 64)->nullable();
            $table->timestamps();
        });
        Schema::table('company_policy_versions', function (Blueprint $table) {
            $table->foreignId('company_policy_id')->nullable()->constrained('company_policies');
        });

        // An installation previously had one policy per company. Keep its existing
        // version IDs/numbers, PDF references, draft and acknowledgement rows intact.
        DB::table('companies')->orderBy('id')->chunkById(100, function ($companies) {
            foreach ($companies as $company) {
                $versions = DB::table('company_policy_versions')->where('company_id', $company->id);
                if (! $versions->exists() && $company->policy_draft_title === null) {
                    continue;
                }
                $current = $company->current_policy_version_id ? (clone $versions)->where('id', $company->current_policy_version_id)->first() : null;
                if ($company->current_policy_version_id && ! $current) {
                    throw new RuntimeException('A company policy reference is inconsistent. Review company '.$company->id.' before retrying the library migration.');
                }
                $fields = [
                    'company_id' => $company->id,
                    'name' => $current?->title ?? $company->policy_draft_title ?? (clone $versions)->orderByDesc('version')->value('title') ?? 'Company policy',
                    'current_policy_version_id' => $company->current_policy_version_id,
                    'draft_revision' => $company->policy_draft_revision,
                    'draft_title' => $company->policy_draft_title,
                    'draft_content' => $company->policy_draft_content,
                    'created_at' => now(), 'updated_at' => now(),
                ];
                foreach (['path', 'name', 'size', 'sha256'] as $field) {
                    $fields['draft_pdf_'.$field] = $company->{'policy_draft_pdf_'.$field};
                }
                $id = DB::table('company_policies')->insertGetId($fields);
                $versions->update(['company_policy_id' => $id]);
            }
        });
        Schema::table('company_policy_versions', function (Blueprint $table) {
            $table->index('company_id', 'policy_versions_company_index');
            $table->dropUnique(['company_id', 'version']);
            $table->unique(['company_policy_id', 'version'], 'company_policy_version_unique');
            $table->unsignedBigInteger('company_policy_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Company policies, versions and acknowledgements must be preserved. Use a reviewed forward migration.');
    }
};
