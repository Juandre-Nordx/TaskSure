<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['companies' => 'policy_draft_pdf_', 'company_policy_versions' => 'pdf_'] as $table => $prefix) {
            Schema::table($table, function (Blueprint $table) use ($prefix) {
                $table->string($prefix.'path')->nullable();
                $table->string($prefix.'name', 180)->nullable();
                $table->unsignedBigInteger($prefix.'size')->nullable();
                $table->string($prefix.'sha256', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Published policy PDFs and acknowledgement history must be preserved. Use a reviewed forward migration.');
    }
};
