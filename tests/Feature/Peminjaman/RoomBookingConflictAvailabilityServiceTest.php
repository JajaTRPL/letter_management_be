<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Services\RoomAvailabilityService;
use App\Services\RoomBookingConflictService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RoomBookingConflictAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;
    use RoomBookingTestHelpers;

    private RoomBookingConflictService $conflicts;

    private RoomAvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conflicts = app(RoomBookingConflictService::class);
        $this->availability = app(RoomAvailabilityService::class);
    }

    public function test_approved_overlap_is_conflict_and_adjacent_booking_is_allowed(): void
    {
        $room = $this->classroom();
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Approved,
            startAt: '2026-06-20 10:00:00',
            endAt: '2026-06-20 12:00:00',
        );

        $this->assertTrue($this->conflicts->hasConflict(
            $room->id,
            Carbon::parse('2026-06-20 11:00:00'),
            Carbon::parse('2026-06-20 13:00:00'),
        ));
        $this->assertFalse($this->conflicts->hasConflict(
            $room->id,
            Carbon::parse('2026-06-20 12:00:00'),
            Carbon::parse('2026-06-20 14:00:00'),
        ));
    }

    public function test_non_approved_statuses_do_not_block_availability(): void
    {
        $room = $this->classroom();

        foreach ([
            RoomBookingStatus::Submitted,
            RoomBookingStatus::RevisionRequested,
            RoomBookingStatus::Rejected,
            RoomBookingStatus::Cancelled,
        ] as $status) {
            $this->roomBooking($room, status: $status);
        }

        $this->assertFalse($this->conflicts->hasConflict(
            $room->id,
            Carbon::parse('2026-06-20 11:00:00'),
            Carbon::parse('2026-06-20 13:00:00'),
        ));
    }

    public function test_conflict_check_can_ignore_current_booking(): void
    {
        $room = $this->classroom();
        $booking = $this->roomBooking($room, status: RoomBookingStatus::Approved);

        $this->assertTrue($this->conflicts->hasConflict(
            $room->id,
            $booking->start_at,
            $booking->end_at,
        ));
        $this->assertFalse($this->conflicts->hasConflict(
            $room->id,
            $booking->start_at,
            $booking->end_at,
            $booking->id,
        ));
    }

    public function test_availability_projection_returns_only_approved_safe_fields(): void
    {
        $classroom = $this->classroom();
        $laboratory = $this->bookingLaboratory();
        $labRoom = $this->laboratoryRoom($laboratory);
        $requester = $this->bookingUser();

        $approvedClassroom = $this->roomBooking(
            $classroom,
            $requester,
            RoomBookingStatus::Approved,
        );
        $this->roomBooking(
            $labRoom,
            $requester,
            RoomBookingStatus::Approved,
            '2026-06-21 10:00:00',
            '2026-06-21 12:00:00',
        );
        $this->roomBooking(
            $classroom,
            $requester,
            RoomBookingStatus::Submitted,
            '2026-06-20 13:00:00',
            '2026-06-20 15:00:00',
        );

        $projection = $this->availability->projection(
            Carbon::parse('2026-06-20 00:00:00'),
            Carbon::parse('2026-06-21 00:00:00'),
            roomType: RoomType::Classroom,
        );

        $this->assertCount(1, $projection);
        $item = $projection->first();

        $this->assertSame($approvedClassroom->id, $item['booking_id']);
        $this->assertSame(RoomType::Classroom->value, $item['room']['type']);
        $this->assertSame(RoomBookingStatus::Approved->value, $item['status']);
        $this->assertSame(
            ['booking_id', 'room', 'start_at', 'end_at', 'status'],
            array_keys($item),
        );
        $this->assertArrayNotHasKey('requester_id', $item);
        $this->assertArrayNotHasKey('activity_name', $item);
        $this->assertArrayNotHasKey('purpose', $item);
    }

    public function test_availability_can_filter_by_room_and_date_range(): void
    {
        $firstRoom = $this->classroom();
        $secondRoom = $this->classroom();
        $expected = $this->roomBooking(
            $firstRoom,
            status: RoomBookingStatus::Approved,
            startAt: '2026-06-20 10:00:00',
            endAt: '2026-06-20 12:00:00',
        );
        $this->roomBooking(
            $secondRoom,
            status: RoomBookingStatus::Approved,
            startAt: '2026-06-20 10:00:00',
            endAt: '2026-06-20 12:00:00',
        );
        $this->roomBooking(
            $firstRoom,
            status: RoomBookingStatus::Approved,
            startAt: '2026-06-22 10:00:00',
            endAt: '2026-06-22 12:00:00',
        );

        $projection = $this->availability->projection(
            Carbon::parse('2026-06-20 00:00:00'),
            Carbon::parse('2026-06-21 00:00:00'),
            roomId: $firstRoom->id,
        );

        $this->assertSame([$expected->id], $projection->pluck('booking_id')->all());
    }
}
