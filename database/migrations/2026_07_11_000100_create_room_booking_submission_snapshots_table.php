<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_booking_submission_snapshots', function (Blueprint $table) {
            $table->id();
            // Snapshots are business evidence: the booking must never be able
            // to cascade them away.
            $table->foreignId('room_booking_request_id')
                ->constrained('room_booking_requests')
                ->restrictOnDelete();
            $table->unsignedInteger('submission_iteration');
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('payload');
            $table->char('payload_checksum', 64);
            $table->foreignId('attachment_id')
                ->nullable()
                ->constrained('room_booking_attachments')
                ->nullOnDelete();
            $table->char('attachment_checksum', 64)->nullable();
            // The user row may disappear/deactivate; the *_snapshot columns
            // remain the durable evidence of who submitted.
            $table->foreignId('submitted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('requester_name_snapshot');
            $table->string('requester_identifier_snapshot')->nullable();
            $table->string('requester_role_snapshot', 64);
            $table->unsignedBigInteger('room_id_snapshot');
            $table->string('room_name_snapshot');
            $table->string('room_type_snapshot', 32);
            $table->unsignedBigInteger('laboratory_id_snapshot')->nullable();
            $table->string('laboratory_name_snapshot')->nullable();
            $table->timestamp('submitted_at');
            $table->string('provenance', 32);
            $table->timestamps();

            $table->unique(
                ['room_booking_request_id', 'submission_iteration'],
                'rbss_booking_iteration_unique',
            );
            $table->index(['room_booking_request_id', 'submitted_at'], 'rbss_booking_submitted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_booking_submission_snapshots');
    }
};
