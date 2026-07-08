<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_booking_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_booking_request_id')
                ->constrained('room_booking_requests')
                ->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(
                ['room_booking_request_id', 'created_at'],
                'rbsh_request_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_booking_status_histories');
    }
};
