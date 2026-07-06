<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master-data audit for room management (photos/facilities/templates/
     * room info). Separate from room_booking_audit_logs, which requires a
     * booking FK and covers the booking document workflow only.
     */
    public function up(): void
    {
        Schema::create('room_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('laboratory_id')->nullable()->constrained('laboratories')->nullOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('action', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('details', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['room_id', 'created_at'], 'room_audit_room_time_idx');
            $table->index(['laboratory_id', 'created_at'], 'room_audit_lab_time_idx');
            $table->index(['subject_type', 'subject_id'], 'room_audit_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_audit_logs');
    }
};
