<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\RoomBookingStatus;
use App\Models\AppNotification;
use App\Models\RoomBookingOccurrence;
use App\Models\User;
use App\Services\Notifications\RoomBookingReminderScanner;
use App\Services\RoomBookingOccurrenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Peminjaman\RoomBookingApiTestCase;

/**
 * Idempotent reminder scheduler behaviour: phase timing, cancellation
 * suppression, downtime catch-up, escalation, and Sarpras-vs-Laboran scope.
 */
class RoomBookingReminderNotificationTest extends RoomBookingApiTestCase
{
    private RoomBookingReminderScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = app(RoomBookingReminderScanner::class);
    }

    public function test_t_minus_24_reminder_fires_in_window_for_applicant_and_is_idempotent(): void
    {
        $student = $this->student();
        $occurrence = $this->occurrence($this->classroom(), $student, '2026-06-20 10:00:00', '2026-06-20 12:00:00');

        // 24h before start: inside the T-24 window.
        $now = Carbon::parse('2026-06-19 10:00:00', config('app.timezone'));
        $this->scanner->scan($now);
        $this->scanner->scan($now); // re-run must not duplicate

        $this->assertSame(
            1,
            AppNotification::where('recipient_user_id', $student->id)
                ->where('dedup_key', "occurrence-t24:{$occurrence->public_id}")->count(),
        );
        $this->assertSame(
            NotificationCategory::Reminder,
            AppNotification::where('recipient_user_id', $student->id)->first()->category,
        );
    }

    public function test_reminder_does_not_flood_after_scheduler_downtime(): void
    {
        $student = $this->student();
        $this->occurrence($this->classroom(), $student, '2026-06-20 10:00:00', '2026-06-20 12:00:00');

        // The scanner was down for days and only runs now — long after every
        // window for this occurrence has passed. No stale reminder is emitted.
        $now = Carbon::parse('2026-06-25 09:00:00', config('app.timezone'));
        $this->scanner->scan($now);

        $this->assertSame(0, AppNotification::where('recipient_user_id', $student->id)->count());
    }

    public function test_cancelled_booking_is_skipped_by_the_scanner(): void
    {
        $student = $this->student();
        $occurrence = $this->occurrence($this->classroom(), $student, '2026-06-20 10:00:00', '2026-06-20 12:00:00');
        $occurrence->booking->forceFill(['status' => RoomBookingStatus::Cancelled])->save();

        $now = Carbon::parse('2026-06-19 10:00:00', config('app.timezone'));
        $result = $this->scanner->scan($now);

        $this->assertSame(0, $result['emitted']);
        $this->assertSame(0, AppNotification::count());
    }

    public function test_return_due_is_suppressed_when_key_not_issued_and_fires_when_issued(): void
    {
        $student = $this->student();
        $occurrence = $this->occurrence($this->classroom(), $student, '2026-06-20 10:00:00', '2026-06-20 12:00:00');

        // At usage end, but the key was never issued → no return-due action.
        $end = Carbon::parse('2026-06-20 12:00:00', config('app.timezone'));
        $this->scanner->scan($end);
        $this->assertSame(0, AppNotification::where('dedup_key', 'like', 'return-due:%')->count());

        // Issue the key, scan again in-window → the return-due reminder fires.
        $occurrence->forceFill(['key_issued_at' => Carbon::parse('2026-06-20 09:30:00', config('app.timezone'))])->save();
        $this->scanner->scan($end->copy()->addMinutes(5));
        $this->assertSame(
            1,
            AppNotification::where('recipient_user_id', $student->id)
                ->where('dedup_key', "return-due:{$occurrence->public_id}")->count(),
        );
    }

    public function test_overdue_notifies_applicant_and_classroom_sarpras(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $occurrence = $this->occurrence($this->classroom(), $student, '2026-06-20 10:00:00', '2026-06-20 12:00:00');
        $occurrence->forceFill(['key_issued_at' => Carbon::parse('2026-06-20 09:30:00', config('app.timezone'))])->save();

        // Past the return-due grace, still no accepted return.
        $now = Carbon::parse('2026-06-20 13:00:00', config('app.timezone'));
        $this->scanner->scan($now);

        $this->assertDatabaseHas('app_notifications', [
            'recipient_user_id' => $student->id,
            'dedup_key' => "overdue:{$occurrence->public_id}",
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'recipient_user_id' => $sarpras->id,
            'dedup_key' => "overdue-owner:{$occurrence->public_id}",
        ]);
    }

    public function test_escalation_reaches_kepala_lab_without_routine_laboran_noise(): void
    {
        $lab = $this->bookingLaboratory('ESC');
        $kalab = $this->reviewerUser('kepala_lab', $lab);
        $laboran = $this->reviewerUser('laboran', $lab);
        $student = $this->student();
        $occurrence = $this->occurrence($this->laboratoryRoom($lab), $student, '2026-06-20 10:00:00', '2026-06-20 12:00:00');
        $occurrence->forceFill(['key_issued_at' => Carbon::parse('2026-06-20 09:30:00', config('app.timezone'))])->save();

        // Well beyond the escalation threshold (24h) after return_due_at.
        $now = Carbon::parse('2026-06-22 13:00:00', config('app.timezone'));
        $this->scanner->scan($now);

        // Escalation goes to the decision authority (Kepala Lab) …
        $this->assertDatabaseHas('app_notifications', [
            'recipient_user_id' => $kalab->id,
            'dedup_key' => "escalation:{$occurrence->public_id}",
            'category' => NotificationCategory::ActionRequired->value,
        ]);
        // … and the routine overdue-owner item goes to the Laboran, not an
        // escalation — Kepala Lab is spared routine per-item noise.
        $this->assertSame(
            0,
            AppNotification::where('recipient_user_id', $laboran->id)
                ->where('dedup_key', "escalation:{$occurrence->public_id}")->count(),
        );
        $this->assertDatabaseHas('app_notifications', [
            'recipient_user_id' => $laboran->id,
            'dedup_key' => "overdue-owner:{$occurrence->public_id}",
        ]);
    }

    public function test_lab_t24_owner_reminder_targets_own_lab_laboran_only(): void
    {
        $lab = $this->bookingLaboratory('OWN');
        $otherLab = $this->bookingLaboratory('OTHER');
        $laboran = $this->reviewerUser('laboran', $lab);
        $otherLaboran = $this->reviewerUser('laboran', $otherLab);
        $student = $this->student();
        $occurrence = $this->occurrence($this->laboratoryRoom($lab), $student, '2026-06-20 10:00:00', '2026-06-20 12:00:00');

        $now = Carbon::parse('2026-06-19 10:00:00', config('app.timezone'));
        $this->scanner->scan($now);

        $this->assertDatabaseHas('app_notifications', [
            'recipient_user_id' => $laboran->id,
            'dedup_key' => "occurrence-t24-owner:{$occurrence->public_id}",
        ]);
        $this->assertSame(0, AppNotification::where('recipient_user_id', $otherLaboran->id)->count());
    }

    public function test_command_records_a_last_run_heartbeat(): void
    {
        Cache::forget('notifications:reminders:last_run_at');
        $this->artisan('notifications:room-booking-reminders')->assertSuccessful();
        $this->assertNotNull(Cache::get('notifications:reminders:last_run_at'));
    }

    private function occurrence(
        $room,
        User $student,
        string $start,
        string $end,
    ): RoomBookingOccurrence {
        $booking = $this->roomBooking($room, $student, RoomBookingStatus::Approved, $start, $end);

        return app(RoomBookingOccurrenceService::class)->ensureLegacyOccurrence($booking)
            ->load('booking.room');
    }
}
