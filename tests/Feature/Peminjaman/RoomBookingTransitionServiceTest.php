<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RoomBookingTransitionServiceTest extends TestCase
{
    use RefreshDatabase;
    use RoomBookingTestHelpers;

    private RoomBookingTransitionService $transitions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(
            '2026-06-18 09:00:00',
            config('app.timezone'),
        ));
        $this->transitions = app(RoomBookingTransitionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_booking_submission_validates_and_writes_initial_history(): void
    {
        $requester = $this->bookingUser();
        $room = $this->classroom();
        $booking = new RoomBookingRequest([
            'requester_id' => $requester->id,
            'room_id' => $room->id,
            'activity_name' => 'Test Initial Submission',
            'purpose' => 'Test-only purpose.',
            'participant_count' => 20,
            'start_at' => Carbon::parse('2026-06-20 10:00:00'),
            'end_at' => Carbon::parse('2026-06-20 12:00:00'),
        ]);

        $submitted = $this->transitions->submit($booking, $requester);

        $this->assertTrue($submitted->exists);
        $this->assertSame(RoomBookingStatus::Submitted, $submitted->status);
        $this->assertDatabaseHas('room_booking_status_histories', [
            'room_booking_request_id' => $submitted->id,
            'from_status' => null,
            'to_status' => RoomBookingStatus::Submitted->value,
            'actor_id' => $requester->id,
        ]);
    }

    public function test_submitted_booking_can_be_approved_by_valid_reviewer_with_history(): void
    {
        $room = $this->classroom();
        $booking = $this->roomBooking($room);
        $sarpras = $this->reviewerUser('sarpras');

        $approved = $this->transitions->approve($booking, $sarpras);

        $this->assertSame(RoomBookingStatus::Approved, $approved->status);
        $this->assertSame($sarpras->id, $approved->reviewer_id);
        $this->assertTrue($approved->reviewed_at->equalTo(Carbon::now()));
        $this->assertDatabaseHas('room_booking_status_histories', [
            'room_booking_request_id' => $booking->id,
            'from_status' => RoomBookingStatus::Submitted->value,
            'to_status' => RoomBookingStatus::Approved->value,
            'actor_id' => $sarpras->id,
        ]);
    }

    public function test_approval_rechecks_approved_overlap_and_leaves_booking_unchanged(): void
    {
        $room = $this->classroom();
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Approved,
            startAt: '2026-06-20 10:00:00',
            endAt: '2026-06-20 12:00:00',
        );
        $candidate = $this->roomBooking(
            $room,
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-20 11:00:00',
            endAt: '2026-06-20 13:00:00',
        );

        $exception = $this->captureDomainException(
            fn () => $this->transitions->approve(
                $candidate,
                $this->reviewerUser('sarpras'),
            ),
        );

        $this->assertSame(RoomBookingDomainException::BOOKING_CONFLICT, $exception->reason);
        $this->assertNotEmpty($exception->context['conflicts']);
        $this->assertSame(RoomBookingStatus::Submitted, $candidate->fresh()->status);
        $this->assertDatabaseMissing('room_booking_status_histories', [
            'room_booking_request_id' => $candidate->id,
            'to_status' => RoomBookingStatus::Approved->value,
        ]);
    }

    public function test_revision_request_requires_note_and_owner_can_resubmit(): void
    {
        $requester = $this->bookingUser();
        $booking = $this->roomBooking($this->classroom(), $requester);
        $sarpras = $this->reviewerUser('sarpras');

        $missingNote = $this->captureDomainException(
            fn () => $this->transitions->requestRevision($booking, $sarpras, ' '),
        );
        $this->assertSame(RoomBookingDomainException::NOTE_REQUIRED, $missingNote->reason);

        $revision = $this->transitions->requestRevision(
            $booking,
            $sarpras,
            'Adjust the requested time.',
        );
        $this->assertSame(RoomBookingStatus::RevisionRequested, $revision->status);
        $this->assertSame('Adjust the requested time.', $revision->revision_note);

        $resubmitted = $this->transitions->submit($revision, $requester);
        $this->assertSame(RoomBookingStatus::Submitted, $resubmitted->status);
        $this->assertNull($resubmitted->revision_note);
        $this->assertNull($resubmitted->reviewer_id);
        $this->assertDatabaseCount('room_booking_status_histories', 2);
    }

    public function test_rejection_requires_reason_and_persists_reason_history(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $sarpras = $this->reviewerUser('sarpras');

        $missingReason = $this->captureDomainException(
            fn () => $this->transitions->reject($booking, $sarpras, ''),
        );
        $this->assertSame(RoomBookingDomainException::REASON_REQUIRED, $missingReason->reason);

        $rejected = $this->transitions->reject(
            $booking,
            $sarpras,
            'Room maintenance is scheduled.',
        );

        $this->assertSame(RoomBookingStatus::Rejected, $rejected->status);
        $this->assertSame('Room maintenance is scheduled.', $rejected->rejection_reason);
        $this->assertDatabaseHas('room_booking_status_histories', [
            'room_booking_request_id' => $booking->id,
            'to_status' => RoomBookingStatus::Rejected->value,
            'note' => 'Room maintenance is scheduled.',
        ]);
    }

    public function test_owner_can_directly_withdraw_only_eligible_submitted_booking(): void
    {
        $missingReasonBooking = $this->roomBooking($this->classroom());
        $missingReason = $this->captureDomainException(
            fn () => $this->transitions->cancel(
                $missingReasonBooking,
                $missingReasonBooking->requester,
                ' ',
            ),
        );
        $this->assertSame(RoomBookingDomainException::REASON_REQUIRED, $missingReason->reason);

        $requester = $this->bookingUser();
        $submitted = $this->roomBooking($this->classroom(), $requester);
        $cancelled = $this->transitions->cancel(
            $submitted,
            $requester,
            'The activity was cancelled.',
        );
        $this->assertSame(RoomBookingStatus::Cancelled, $cancelled->status);
        $this->assertSame('requester_withdrawal', $cancelled->cancellation_source);

        $revision = $this->roomBooking(
            $this->classroom(),
            $requester,
            RoomBookingStatus::RevisionRequested,
        );
        $exception = $this->captureDomainException(fn () => $this->transitions->cancel(
            $revision,
            $requester,
            'Must be reviewed.',
        ));
        $this->assertSame(RoomBookingDomainException::REVISION_ALREADY_REQUESTED, $exception->reason);
        $this->assertSame(RoomBookingStatus::RevisionRequested, $revision->fresh()->status);
    }

    public function test_approved_owner_cancellation_always_requires_review(): void
    {
        $requester = $this->bookingUser();
        $beforeStart = $this->roomBooking(
            $this->classroom(),
            $requester,
            RoomBookingStatus::Approved,
            '2026-06-20 10:00:00',
            '2026-06-20 12:00:00',
        );

        $beforeException = $this->captureDomainException(fn () => $this->transitions->cancel(
            $beforeStart,
            $requester,
            'The approved activity was cancelled.',
        ));
        $this->assertSame(
            RoomBookingDomainException::REQUIRES_CANCELLATION_REVIEW,
            $beforeException->reason,
        );

        $atStart = $this->roomBooking(
            $this->classroom(),
            $requester,
            RoomBookingStatus::Approved,
            '2026-06-18 09:00:00',
            '2026-06-18 11:00:00',
        );
        $exception = $this->captureDomainException(
            fn () => $this->transitions->cancel(
                $atStart,
                $requester,
                'Too late.',
            ),
        );

        $this->assertSame(
            RoomBookingDomainException::REQUIRES_CANCELLATION_REVIEW,
            $exception->reason,
        );
        $this->assertSame(RoomBookingStatus::Approved, $atStart->fresh()->status);
    }

    public function test_invalid_transition_and_unauthorized_reviewer_are_rejected(): void
    {
        $approved = $this->roomBooking(
            $this->classroom(),
            status: RoomBookingStatus::Approved,
        );
        $invalidTransition = $this->captureDomainException(
            fn () => $this->transitions->approve(
                $approved,
                $this->reviewerUser('sarpras'),
            ),
        );
        $this->assertSame(
            RoomBookingDomainException::INVALID_TRANSITION,
            $invalidTransition->reason,
        );

        $submitted = $this->roomBooking($this->classroom());
        $unauthorized = $this->captureDomainException(
            fn () => $this->transitions->approve(
                $submitted,
                $this->reviewerUser('persuratan'),
            ),
        );
        $this->assertSame(
            RoomBookingDomainException::UNAUTHORIZED_ACTION,
            $unauthorized->reason,
        );
    }

    public function test_capacity_and_inactive_room_are_rechecked_on_approval(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $smallRoom = $this->classroom(['capacity' => 5]);
        $overCapacity = $this->roomBooking(
            $smallRoom,
            attributes: ['participant_count' => 6],
        );
        $capacityException = $this->captureDomainException(
            fn () => $this->transitions->approve($overCapacity, $sarpras),
        );
        $this->assertSame(
            RoomBookingDomainException::CAPACITY_EXCEEDED,
            $capacityException->reason,
        );

        $inactiveRoom = $this->classroom(['is_active' => false]);
        $inactiveBooking = $this->roomBooking($inactiveRoom);
        $inactiveException = $this->captureDomainException(
            fn () => $this->transitions->approve($inactiveBooking, $sarpras),
        );
        $this->assertSame(
            RoomBookingDomainException::INACTIVE_ROOM,
            $inactiveException->reason,
        );
    }

    public function test_new_submission_rejects_invalid_cross_midnight_and_non_future_times(): void
    {
        $requester = $this->bookingUser();
        $room = $this->classroom();

        $invalidRange = $this->newBooking(
            $requester->id,
            $room->id,
            '2026-06-20 12:00:00',
            '2026-06-20 10:00:00',
        );
        $this->assertSame(
            RoomBookingDomainException::INVALID_TIME_RANGE,
            $this->captureDomainException(
                fn () => $this->transitions->submit($invalidRange, $requester),
            )->reason,
        );

        $crossMidnight = $this->newBooking(
            $requester->id,
            $room->id,
            '2026-06-20 23:00:00',
            '2026-06-21 01:00:00',
        );
        $this->assertSame(
            RoomBookingDomainException::CROSS_MIDNIGHT,
            $this->captureDomainException(
                fn () => $this->transitions->submit($crossMidnight, $requester),
            )->reason,
        );

        $past = $this->newBooking(
            $requester->id,
            $room->id,
            '2026-06-18 08:00:00',
            '2026-06-18 08:30:00',
        );
        $this->assertSame(
            RoomBookingDomainException::START_NOT_FUTURE,
            $this->captureDomainException(
                fn () => $this->transitions->submit($past, $requester),
            )->reason,
        );
    }

    private function newBooking(
        int $requesterId,
        int $roomId,
        string $startAt,
        string $endAt,
    ): RoomBookingRequest {
        return new RoomBookingRequest([
            'requester_id' => $requesterId,
            'room_id' => $roomId,
            'activity_name' => 'Test New Booking',
            'purpose' => 'Test-only purpose.',
            'participant_count' => 10,
            'start_at' => Carbon::parse($startAt),
            'end_at' => Carbon::parse($endAt),
        ]);
    }

    private function captureDomainException(callable $action): RoomBookingDomainException
    {
        try {
            $action();
        } catch (RoomBookingDomainException $exception) {
            return $exception;
        }

        $this->fail('Expected RoomBookingDomainException was not thrown.');
    }
}
