<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 32)->unique();
            $table->unsignedSmallInteger('supporting_document_retention_days')->default(14);
            $table->unsignedSmallInteger('intermediate_artifact_retention_days')->default(14);
            $table->unsignedSmallInteger('final_pdf_active_days')->default(30);
            $table->unsignedSmallInteger('archive_retention_days')->default(365);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_retention_policies');
    }
};
