<?php

namespace Tests\Feature\Analytics;

use App\Enums\RoomBookingStatus;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use App\Services\Analytics\ReviewDurationSample;
use App\Services\Analytics\RoomBookingReviewDurationCollector;
use App\Support\Workflow\RoomBookingReviewStageClock as Stage;
use Illuminate\Support\Carbon;
use Tests\Feature\Peminjaman\RoomBookingApiTestCase;

/**
 * The booking collector's job is to turn the ledger into per-cycle samples and to
 * route each one to the right reviewing body — Sarpras for classrooms, the
 * owning laboratory's head for labs.
 */
class RoomBookingReviewDurationCollectorTest extends RoomBookingApiTestCase
{
    private RoomBookingReviewDurationCollector $collector;

    private Carbon $submittedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = app(RoomBookingReviewDurationCollector::class);
        $this->submittedAt = Carbon::parse('2026-06-01 09:00:00', config('app.timezone'));
    }

    public function test_a_classroom_booking_is_attributed_to_sarpras_with_no_unit(): void
    {
        $booking = $this->booking($this->classroom());
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED, $this->at(90), 1);

        $samples = $this->collect();

        $this->assertCount(1, $samples);
        $this->assertSame(Stage::STAGE_SARPRAS, $samples->first()->stage);
        $this->assertSame('global', $samples->first()->unitType);
        $this->assertNull($samples->first()->unitId, 'Sarpras is one faculty-wide team.');
        $this->assertSame(90 * 60, $samples->first()->seconds);
    }

    public function test_a_laboratory_booking_is_attributed_to_its_owning_laboratory(): void
    {
        $lab = $this->laboratoryRoom($this->bookingLaboratory());
        $booking = $this->booking($lab);
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED, $this->at(30), 1);

        $sample = $this->collect()->first();

        $this->assertSame(Stage::STAGE_KALAB, $sample->stage);
        $this->assertSame('laboratory', $sample->unitType);
        $this->assertSame((int) $lab->owning_laboratory_id, $sample->unitId);
    }

    public function test_each_submission_iteration_is_measured_independently(): void
    {
        $booking = $this->booking($this->classroom());
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_REVISION_REQUESTED, $this->at(60), 1);
        // The applicant takes five days to fix it — nobody is charged for that.
        $resubmitted = $this->at(5 * 24 * 60);
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_RESUBMITTED, $resubmitted, 2);
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED, $resubmitted->copy()->addMinutes(30), 2);

        $samples = $this->collect();

        $this->assertCount(2, $samples);
        $this->assertEqualsCanonicalizing([3600, 1800], $samples->pluck('seconds')->all());
        $this->assertSame(
            1,
            $samples->where('decision', ReviewDurationSample::DECISION_REVISION)->count(),
            'Sending a booking back is a decision, and it is measured.',
        );
    }

    public function test_an_applicant_cancelling_is_not_credited_as_a_review(): void
    {
        $booking = $this->booking($this->classroom());
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_CANCELLED, $this->at(15), 1);

        $this->assertCount(0, $this->collect());
    }

    public function test_an_abandoned_booking_beyond_the_ceiling_is_discarded_and_counted(): void
    {
        $booking = $this->booking($this->classroom());
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);
        $this->event($booking, RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED, $this->submittedAt->copy()->addDays(45), 1);

        $samples = $this->collect($this->submittedAt->copy()->subDay(), $this->submittedAt->copy()->addDays(90));

        $this->assertCount(0, $samples);
        $this->assertSame(1, $this->collector->discarded()['outlier']);
    }

    public function test_waiting_now_splits_the_live_queue_by_room_type(): void
    {
        $classroomBooking = $this->booking($this->classroom());
        $this->event($classroomBooking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);
        $labBooking = $this->booking($this->laboratoryRoom($this->bookingLaboratory()));
        $this->event($labBooking, RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED, $this->submittedAt, 1);

        Carbon::setTestNow($this->submittedAt->copy()->addDays(10));
        $waiting = $this->collector->waitingNow(7 * 24 * 60);
        Carbon::setTestNow();

        $this->assertSame(1, $waiting[Stage::STAGE_SARPRAS]['count']);
        $this->assertSame(1, $waiting[Stage::STAGE_SARPRAS]['over_overdue_count']);
        $this->assertSame(1, $waiting[Stage::STAGE_KALAB]['count']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function at(int $minutes): Carbon
    {
        return $this->submittedAt->copy()->addMinutes($minutes);
    }

    private function collect(?Carbon $from = null, ?Carbon $to = null)
    {
        return $this->collector->collect(
            $from ?? $this->submittedAt->copy()->subDay(),
            $to ?? $this->submittedAt->copy()->addDays(30),
        );
    }

    private function booking(Room $room, ?User $requester = null): RoomBookingRequest
    {
        Carbon::setTestNow($this->submittedAt);
        $booking = $this->roomBooking(
            $room,
            $requester ?? $this->student(),
            RoomBookingStatus::Submitted,
            '2026-08-20 10:00:00',
            '2026-08-20 12:00:00',
        );
        Carbon::setTestNow();

        return $booking;
    }

    private function event(RoomBookingRequest $booking, string $type, Carbon $at, int $iteration): void
    {
        RoomBookingWorkflowEvent::create([
            'room_booking_request_id' => $booking->id,
            'event_type' => $type,
            'actor_id' => null,
            'actor_name_snapshot' => 'Sistem',
            'actor_role_snapshot' => 'system',
            'resulting_status' => RoomBookingStatus::Submitted->value,
            'workflow_version_after' => 1,
            'submission_iteration' => $iteration,
            'occurred_at' => $at,
        ]);
    }
}
