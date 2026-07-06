<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_booking_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms');
            $table->string('activity_name');
            $table->text('purpose');
            $table->unsignedInteger('participant_count');
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->string('status', 32)->default('submitted');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('revision_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(
                ['room_id', 'status', 'start_at', 'end_at'],
                'rbr_room_status_window_idx'
            );
            $table->index(['requester_id', 'status'], 'rbr_requester_status_idx');
            $table->index(['status', 'created_at'], 'rbr_status_created_idx');
            $table->index(['room_id', 'start_at'], 'rbr_room_start_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_booking_requests');
    }
};
