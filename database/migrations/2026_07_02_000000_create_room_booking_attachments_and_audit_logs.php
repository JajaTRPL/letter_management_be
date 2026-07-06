<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_booking_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_booking_request_id')
                ->constrained('room_booking_requests')
                ->cascadeOnDelete();
            $table->string('document_type', 64);
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_disk', 32);
            $table->string('storage_path');
            $table->char('checksum_sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['room_booking_request_id', 'document_type'],
                'rba_booking_document_unique'
            );
            $table->index(['document_type', 'created_at'], 'rba_document_created_idx');
        });

        Schema::create('room_booking_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_booking_request_id')
                ->constrained('room_booking_requests')
                ->cascadeOnDelete();
            $table->foreignId('room_booking_attachment_id')
                ->nullable()
                ->constrained('room_booking_attachments')
                ->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->string('document_type', 64);
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->char('storage_path_hash', 64)->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(
                ['room_booking_request_id', 'action', 'created_at'],
                'rbal_booking_action_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_booking_audit_logs');
        Schema::dropIfExists('room_booking_attachments');
    }
};
