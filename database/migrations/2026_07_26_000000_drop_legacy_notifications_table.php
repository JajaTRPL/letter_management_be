<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C7N4 legacy retirement: drop the generic Laravel `notifications` morph table.
 *
 * It is fully dead after the C7N1/C7N2 consolidation: `app_notifications` is the
 * single in-app backbone, the only two remaining producers deliver via the
 * `mail` channel only, and an exhaustive search found no consumer of this table
 * (`$user->notifications`, `unreadNotifications`, `markAsRead`, or any API read).
 *
 * Its 190 historical rows (165 ScholarshipStatus + 25 ScholarshipSubmitted,
 * 2026-05-18 .. 2026-07-13, all unread) were ARCHIVED before this migration was
 * written — both a restorable `pg_dump` and a human-readable JSONL export live in
 * D:\Magang\db-backups\legacy_notifications_*  — so applying this destroys no
 * unpreserved history. `down()` recreates the original schema exactly (the
 * archived rows can be reloaded from the pg_dump if ever needed).
 *
 * Left Pending for a deliberate, backed-up apply (repo convention); do not fold
 * into an unrelated blanket migrate without confirming the archive exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('notifications');
    }

    public function down(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
};
