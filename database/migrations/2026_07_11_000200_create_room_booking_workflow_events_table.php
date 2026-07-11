<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_booking_workflow_events', function (Blueprint $table) {
            $table->id();
            // Append-only business ledger: bookings must not cascade it away.
            $table->foreignId('room_booking_request_id')
                ->constrained('room_booking_requests')
                ->restrictOnDelete();
            $table->string('event_type', 64);
            // Actor FK may null out on account deletion; the snapshot columns
            // keep the business evidence.
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('actor_name_snapshot');
            $table->string('actor_role_snapshot', 64);
            $table->string('actor_subrole_snapshot', 64)->nullable();
            $table->string('actor_scope_type', 64)->nullable();
            $table->unsignedBigInteger('actor_scope_id')->nullable();
            $table->string('previous_status', 32)->nullable();
            $table->string('resulting_status', 32);
            $table->unsignedBigInteger('workflow_version_before')->nullable();
            $table->unsignedBigInteger('workflow_version_after');
            $table->unsignedInteger('submission_iteration')->nullable();
            $table->text('public_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->json('safe_metadata')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(
                ['room_booking_request_id', 'occurred_at'],
                'rbwe_booking_occurred_idx',
            );
            $table->index(['event_type', 'occurred_at'], 'rbwe_event_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_booking_workflow_events');
    }
};
