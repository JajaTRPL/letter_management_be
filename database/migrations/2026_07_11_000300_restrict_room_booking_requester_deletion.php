<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bookings (and, through them, status histories, attachments, attachment
     * audit logs, submission snapshots, and workflow events) are business
     * evidence. Deleting the requester account must not erase them, so the
     * original cascadeOnDelete is replaced with restrictOnDelete. The
     * application intercepts protected deletions first (UserController), this
     * constraint is the database backstop.
     */
    public function up(): void
    {
        Schema::table('room_booking_requests', function (Blueprint $table) {
            $table->dropForeign(['requester_id']);
            $table->foreign('requester_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_booking_requests', function (Blueprint $table) {
            $table->dropForeign(['requester_id']);
            $table->foreign('requester_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};
