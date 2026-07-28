<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_booking_requests', function (Blueprint $table): void {
            $table->string('booking_mode', 24)->default('single_day')->after('end_at');
            $table->date('occurrence_end_date')->nullable()->after('booking_mode');
        });

        Schema::create('room_booking_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('room_booking_request_id')
                ->constrained('room_booking_requests')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->date('occurrence_date');
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->timestampTz('return_due_at');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('key_issued_at')->nullable();
            $table->foreignId('key_issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('key_issued_by_name')->nullable();
            $table->string('key_issued_by_role', 64)->nullable();
            $table->text('key_issue_note')->nullable();
            $table->timestampsTz();

            $table->unique(['room_booking_request_id', 'sequence'], 'room_booking_occurrence_sequence_unique');
            $table->unique(['room_booking_request_id', 'occurrence_date'], 'room_booking_occurrence_date_unique');
            $table->index(['start_at', 'end_at']);
            $table->index('return_due_at');
        });

        $grace = max(0, (int) config('room_booking.return_grace_minutes', 30));
        DB::table('room_booking_requests')->orderBy('id')->chunkById(200, function ($bookings) use ($grace): void {
            $rows = [];
            foreach ($bookings as $booking) {
                $endAt = Carbon::parse($booking->end_at);
                $rows[] = [
                    'public_id' => (string) Str::uuid(),
                    'room_booking_request_id' => $booking->id,
                    'sequence' => 1,
                    'occurrence_date' => Carbon::parse($booking->start_at)->toDateString(),
                    'start_at' => $booking->start_at,
                    'end_at' => $booking->end_at,
                    'return_due_at' => $endAt->addMinutes($grace),
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($rows !== []) DB::table('room_booking_occurrences')->insert($rows);
        });

        Schema::create('room_booking_return_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('room_booking_occurrence_id')
                ->constrained('room_booking_occurrences')->cascadeOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('room_booking_return_requests')->nullOnDelete();
            $table->string('status', 32);
            $table->boolean('active_pending_guard')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->string('evidence_disk', 32);
            $table->string('evidence_path');
            $table->string('evidence_original_name');
            $table->string('evidence_mime', 64);
            $table->unsignedBigInteger('evidence_size_bytes');
            $table->char('evidence_checksum_sha256', 64);
            $table->timestampTz('submitted_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->string('decided_by_role', 64)->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampTz('key_received_at')->nullable();
            $table->text('received_time_change_reason')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['room_booking_occurrence_id', 'active_pending_guard'],
                'room_booking_return_active_unique',
            );
            $table->index(['status', 'submitted_at']);
        });

        Schema::table('room_booking_workflow_events', function (Blueprint $table): void {
            $table->foreignId('room_booking_occurrence_id')->nullable()
                ->after('room_booking_request_id')
                ->constrained('room_booking_occurrences')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()
                ->after('actor_scope_id')->constrained('users')->nullOnDelete();
            $table->string('recipient_role', 64)->nullable()->after('recipient_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('room_booking_workflow_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropColumn('recipient_role');
            $table->dropConstrainedForeignId('room_booking_occurrence_id');
        });
        Schema::dropIfExists('room_booking_return_requests');
        Schema::dropIfExists('room_booking_occurrences');
        Schema::table('room_booking_requests', function (Blueprint $table): void {
            $table->dropColumn(['booking_mode', 'occurrence_end_date']);
        });
    }
};
