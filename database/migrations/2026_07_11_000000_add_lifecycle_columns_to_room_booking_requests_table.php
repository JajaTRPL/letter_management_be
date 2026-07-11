<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_booking_requests', function (Blueprint $table) {
            // Monotonic counter of authoritative workflow mutations
            // (revision/resubmit/approve/reject/cancel). Reads never bump it.
            $table->unsignedBigInteger('workflow_version')
                ->default(1)
                ->after('status');

            // 1 = initial submission; incremented only by an authoritative
            // resubmit (Ajukan Ulang), never by in-revision form edits.
            $table->unsignedInteger('submission_iteration')
                ->default(1)
                ->after('workflow_version');
        });
    }

    public function down(): void
    {
        Schema::table('room_booking_requests', function (Blueprint $table) {
            $table->dropColumn(['workflow_version', 'submission_iteration']);
        });
    }
};
