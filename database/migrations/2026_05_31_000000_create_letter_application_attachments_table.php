<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_application_attachments', function (Blueprint $table) {
            $table->id();

            // Each letter type has its own application table. Keep the same explicit
            // canonical type + application id convention as letter_document_artifacts.
            $table->string('letter_type', 64);
            $table->unsignedBigInteger('application_id');
            $table->string('document_key', 64);

            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('storage_disk', 32);
            $table->string('storage_path');
            $table->char('checksum_sha256', 64)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['letter_type', 'application_id', 'document_key'],
                'laa_letter_app_key_unique'
            );
            $table->index(['letter_type', 'application_id'], 'laa_letter_app_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_application_attachments');
    }
};
