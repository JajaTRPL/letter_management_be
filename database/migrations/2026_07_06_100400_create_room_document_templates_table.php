<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Downloadable booking templates (PDF/DOCX), versioned per scope.
     * scope = classroom|laboratory; laboratory_id NULL = category-wide,
     * set = per-lab override. "One active per scope+lab" is enforced by the
     * service layer inside a transaction, not by a DB constraint.
     * version + checksum are deliberately kept for the future generated
     * letter flow (which must record the template version it rendered from).
     */
    public function up(): void
    {
        Schema::create('room_document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 32);
            $table->foreignId('laboratory_id')->nullable()->constrained('laboratories')->nullOnDelete();
            $table->string('storage_disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope', 'laboratory_id', 'is_active'], 'room_templates_scope_lab_active_idx');
            $table->index('checksum_sha256', 'room_templates_checksum_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_document_templates');
    }
};
