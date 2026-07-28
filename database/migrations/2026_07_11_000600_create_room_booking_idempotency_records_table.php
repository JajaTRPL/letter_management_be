<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_booking_idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_identity_snapshot', 128);
            $table->foreignId('room_booking_request_id')
                ->nullable()
                ->constrained('room_booking_requests')
                ->restrictOnDelete();
            $table->string('action', 64);
            $table->string('subject_key', 128);
            $table->char('idempotency_key_hash', 64);
            $table->char('payload_hash', 64);
            $table->unsignedSmallInteger('result_status_code')->nullable();
            $table->unsignedSmallInteger('response_schema_version')->nullable();
            $table->json('safe_response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['actor_identity_snapshot', 'action', 'subject_key', 'idempotency_key_hash'],
                'rbir_actor_action_subject_key_unique',
            );
            $table->index('expires_at', 'rbir_expires_at_idx');
            $table->index(['room_booking_request_id', 'created_at'], 'rbir_booking_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_booking_idempotency_records');
    }
};
