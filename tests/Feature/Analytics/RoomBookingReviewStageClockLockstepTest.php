<?php

namespace Tests\Feature\Analytics;

use App\Enums\RoomBookingStatus;
use App\Models\AppNotification;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use App\Models\WorkflowReviewSlaPolicy;
use App\Services\Notifications\RoomBookingReviewSlaScanner;
use App\Services\Notifications\WorkflowReviewSlaPolicyService;
use App\Support\Workflow\RoomBookingReviewStageClock;
use Illuminate\Support\Carbon;
use Tests\Feature\Peminjaman\RoomBookingApiTestCase;

/**
 * The booking half of the clock guard (see LetterReviewStageClockLockstepTest for
 * why this exists at all).
 *
 * Bookings additionally prove the resubmission rule: because every ledger event
 * carries `submission_iteration`, a booking that was revised and resubmitted must
 * be measured from its LATEST submission, never from `created_at`. Measuring from
 * created_at would charge the reviewer for the days the applicant spent fixing
 * their own paperwork — the single most unfair thing this feature could do.
 */
class RoomBookingReviewStageClockLockstepTest extends RoomBookingApiTestCase
{
    private RoomBookingReviewSlaScanner $scanner;

    private Carbon $submittedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = app(RoomBookingReviewSlaScanner::class);
        $this->submittedAt = Carbon::parse('2026-06-01 09:00:00', config('app.timezone'));
    }

    public function test_notification_is_stamped_with_the_ledger_clock_instant(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $this->enablePolicy();
        $booking = $this->submittedBooking($this->student());
        $this->ledgerEvent($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);

        $this->scanner->scan($this->at(150));

        $stamped = AppNotification::where('recipient_user_id', $sarpras->id)
            ->where('dedup_key', 'like', 'review-sla-%')
            ->firstOrFail()
            ->occurred_at;

        $clock = RoomBookingReviewStageClock::currentWaitingSince([$booking->id])[$booking->id] ?? null;

        $this->assertNotNull($clock);
        $this->assertSame($clock->getTimestamp(), $stamped->getTimestamp());
        $this->assertSame($this->submittedAt->getTimestamp(), $clock->getTimestamp());
    }

    public function test_resubmission_restarts_the_clock_for_both_surfaces(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $this->enablePolicy();
        $booking = $this->submittedBooking($this->student());

        $this->ledgerEvent($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);
        $resubmittedAt = $this->at(5 * 24 * 60); // applicant took five days to fix it
        $this->ledgerEvent($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_RESUBMITTED, $resubmittedAt, 2);
        $booking->update(['submission_iteration' => 2]);

        $this->scanner->scan($resubmittedAt->copy()->addMinutes(150));

        $stamped = AppNotification::where('recipient_user_id', $sarpras->id)
            ->where('dedup_key', 'like', 'review-sla-%')
            ->firstOrFail()
            ->occurred_at;

        $clock = RoomBookingReviewStageClock::currentWaitingSince([$booking->id])[$booking->id];

        $this->assertSame($clock->getTimestamp(), $stamped->getTimestamp());
        $this->assertSame(
            $resubmittedAt->getTimestamp(),
            $clock->getTimestamp(),
            'The reviewer must not be charged for the applicant\'s revision time.',
        );
    }

    public function test_closed_cycles_yields_one_sample_per_submission_iteration(): void
    {
        $booking = $this->submittedBooking($this->student());

        // Cycle 1: submitted → revision requested after 2 hours.
        $this->ledgerEvent($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);
        $this->ledgerEvent($booking, RoomBookingWorkflowEvent::EVENT_REVISION_REQUESTED, $this->at(120), 1);
        // Applicant fixes it over five days, then cycle 2: resubmitted → approved after 1 hour.
        $resubmittedAt = $this->at(5 * 24 * 60);
        $this->ledgerEvent($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_RESUBMITTED, $resubmittedAt, 2);
        $this->ledgerEvent($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED, $resubmittedAt->copy()->addMinutes(60), 2);

        $cycles = RoomBookingReviewStageClock::closedCycles(
            $this->submittedAt->copy()->subDay(),
            $this->at(30 * 24 * 60),
        );

        $this->assertCount(2, $cycles, 'Each submission iteration is its own review cycle.');

        $byIteration = $cycles->keyBy('iteration');
        $this->assertSame(120 * 60, $byIteration[1]['exit']->getTimestamp() - $byIteration[1]['entry']->getTimestamp());
        $this->assertSame(60 * 60, $byIteration[2]['exit']->getTimestamp() - $byIteration[2]['entry']->getTimestamp());
        // The five days of applicant revision time belong to NEITHER cycle.
        $this->assertSame(RoomBookingWorkflowEvent::EVENT_REVISION_REQUESTED, $byIteration[1]['decision']);
        $this->assertSame(RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED, $byIteration[2]['decision']);
    }

    public function test_cancellation_is_not_counted_as_a_review_decision(): void
    {
        $booking = $this->submittedBooking($this->student());
        $this->ledgerEvent($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);
        $this->ledgerEvent($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_CANCELLED, $this->at(60), 1);

        $cycles = RoomBookingReviewStageClock::closedCycles(
            $this->submittedAt->copy()->subDay(),
            $this->at(30 * 24 * 60),
        );

        $this->assertCount(0, $cycles, 'An applicant giving up is not a reviewer decision.');
    }

    public function test_a_cycle_without_a_submit_event_is_dropped_not_guessed(): void
    {
        $booking = $this->submittedBooking($this->student());
        // Legacy row: a decision exists but the ledger has no submission event.
        $this->ledgerEvent($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED, $this->at(60), 1);

        $cycles = RoomBookingReviewStageClock::closedCycles(
            $this->submittedAt->copy()->subDay(),
            $this->at(30 * 24 * 60),
        );

        $this->assertCount(0, $cycles, 'Never invent a start time to manufacture a duration.');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function at(int $minutes): Carbon
    {
        return $this->submittedAt->copy()->addMinutes($minutes);
    }

    private function enablePolicy(int $warning = 60, int $overdue = 120, int $escalation = 180): void
    {
        WorkflowReviewSlaPolicy::create([
            'scope' => WorkflowReviewSlaPolicyService::SCOPE_ROOM_BOOKING,
            'enabled' => true,
            'warning_minutes' => $warning,
            'overdue_minutes' => $overdue,
            'escalation_minutes' => $escalation,
        ]);
    }

    private function submittedBooking(User $requester): RoomBookingRequest
    {
        Carbon::setTestNow($this->submittedAt);
        $booking = $this->roomBooking(
            $this->classroom(),
            $requester,
            RoomBookingStatus::Submitted,
            '2026-08-20 10:00:00',
            '2026-08-20 12:00:00',
        );
        Carbon::setTestNow();

        return $booking;
    }

    private function ledgerEvent(
        RoomBookingRequest $booking,
        string $eventType,
        Carbon $occurredAt,
        int $iteration,
    ): void {
        RoomBookingWorkflowEvent::create([
            'room_booking_request_id' => $booking->id,
            'event_type' => $eventType,
            'actor_id' => null,
            'actor_name_snapshot' => 'Sistem',
            'actor_role_snapshot' => 'system',
            'resulting_status' => RoomBookingStatus::Submitted->value,
            'workflow_version_after' => 1,
            'submission_iteration' => $iteration,
            'occurred_at' => $occurredAt,
        ]);
    }
}
