<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key-return follow-up:
 * - requester_note: the applicant's note sent with the return photo.
 * - is_forced: a return Sarpras/Laboran closed by hand after contacting the
 *   applicant (rejected or overdue return). It has no photo, so the evidence
 *   columns become nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_booking_return_requests', function (Blueprint $table): void {
            $table->text('requester_note')->nullable()->after('requester_id');
            $table->boolean('is_forced')->default(false)->after('status');
            $table->string('evidence_disk', 32)->nullable()->change();
            $table->string('evidence_path')->nullable()->change();
            $table->string('evidence_original_name')->nullable()->change();
            $table->string('evidence_mime', 64)->nullable()->change();
            $table->unsignedBigInteger('evidence_size_bytes')->nullable()->change();
            $table->char('evidence_checksum_sha256', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('room_booking_return_requests', function (Blueprint $table): void {
            $table->dropColumn(['requester_note', 'is_forced']);
        });
    }
};
