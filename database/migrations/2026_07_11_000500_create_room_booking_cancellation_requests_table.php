<?php

use App\Enums\RoomBookingCancellationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_booking_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_booking_request_id')
                ->constrained('room_booking_requests')
                ->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name_snapshot');
            $table->string('requester_role_snapshot', 64);
            $table->text('reason');
            $table->enum('status', RoomBookingCancellationStatus::values())
                ->default(RoomBookingCancellationStatus::Pending->value);
            $table->string('booking_status_snapshot', 32);
            $table->unsignedBigInteger('booking_workflow_version_at_request');
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_actor_name_snapshot')->nullable();
            $table->string('decision_actor_role_snapshot', 64)->nullable();
            $table->string('decision_actor_scope_type', 64)->nullable();
            $table->unsignedBigInteger('decision_actor_scope_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->boolean('active_pending_guard')->nullable();
            $table->timestamps();

            // Cross-database invariant: true is used only by pending rows;
            // resolved rows set the guard to null, so history remains unlimited.
            $table->unique(
                ['room_booking_request_id', 'active_pending_guard'],
                'rbcr_booking_active_pending_unique',
            );
            $table->index(['status', 'requested_at'], 'rbcr_status_requested_idx');
            $table->index(['room_booking_request_id', 'requested_at'], 'rbcr_booking_requested_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_booking_cancellation_requests');
    }
};
