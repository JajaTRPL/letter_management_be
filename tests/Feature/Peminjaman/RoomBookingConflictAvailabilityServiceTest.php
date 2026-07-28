<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomBookingCancellationStatus;
use App\Enums\RoomType;
use App\Models\RoomBookingCancellationRequest;
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

        Carbon::setTestNow(Carbon::parse('2026-06-19 08:00:00', config('app.timezone')));

        $this->conflicts = app(RoomBookingConflictService::class);
        $this->availability = app(RoomAvailabilityService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    public function test_approved_booking_with_pending_cancellation_remains_blocking_and_visible(): void
    {
        $room = $this->classroom();
        $requester = $this->bookingUser();
        $booking = $this->roomBooking(
            $room,
            $requester,
            RoomBookingStatus::Approved,
        );
        RoomBookingCancellationRequest::create([
            'room_booking_request_id' => $booking->id,
            'requested_by' => $requester->id,
            'requester_name_snapshot' => $requester->name,
            'requester_role_snapshot' => 'mahasiswa',
            'reason' => 'Perubahan kegiatan.',
            'status' => RoomBookingCancellationStatus::Pending,
            'booking_status_snapshot' => RoomBookingStatus::Approved,
            'booking_workflow_version_at_request' => $booking->workflow_version ?? 1,
            'requested_at' => now(),
            'active_pending_guard' => true,
        ]);

        $this->assertTrue($this->conflicts->hasConflict(
            $room->id,
            Carbon::parse('2026-06-20 11:00:00'),
            Carbon::parse('2026-06-20 13:00:00'),
        ));

        $projection = $this->availability->projection(
            Carbon::parse('2026-06-20 00:00:00'),
            Carbon::parse('2026-06-21 00:00:00'),
            roomId: $room->id,
        );

        $this->assertCount(1, $projection);
        $this->assertSame('approved', $projection->first()['lifecycle_category']);
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

    public function test_availability_projection_returns_approved_and_active_pending_safe_fields(): void
    {
        $classroom = $this->classroom();
        $laboratory = $this->bookingLaboratory();
        $labRoom = $this->laboratoryRoom($laboratory);
        $requester = $this->bookingUser();

        $this->roomBooking(
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
            ['activity_name' => "  <b>Diskusi</b>\n  Riset  "],
        );

        $projection = $this->availability->projection(
            Carbon::parse('2026-06-20 00:00:00'),
            Carbon::parse('2026-06-21 00:00:00'),
            roomType: RoomType::Classroom,
        );

        $this->assertCount(2, $projection);
        $item = $projection->first();

        $this->assertSame(RoomType::Classroom->value, $item['room']['type']);
        $this->assertSame('approved', $item['lifecycle_category']);
        $this->assertSame(
            ['room', 'start_at', 'end_at', 'lifecycle_category', 'activity_titles', 'request_count'],
            array_keys($item),
        );
        $this->assertSame('pending', $projection->last()['lifecycle_category']);
        $this->assertSame(['Diskusi Riset'], $projection->last()['activity_titles']);
        $this->assertSame(1, $projection->last()['request_count']);
        $this->assertArrayNotHasKey('booking_id', $item);
        $this->assertArrayNotHasKey('requester_id', $item);
        $this->assertArrayNotHasKey('activity_name', $item);
        $this->assertArrayNotHasKey('purpose', $item);
    }

    public function test_availability_can_filter_by_room_and_date_range(): void
    {
        $firstRoom = $this->classroom();
        $secondRoom = $this->classroom();
        $this->roomBooking(
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

        $this->assertCount(1, $projection);
        $this->assertSame($firstRoom->id, $projection->first()['room']['id']);
        $this->assertSame('2026-06-20T10:00:00+07:00', $projection->first()['start_at']);
    }

    public function test_identical_pending_slots_are_aggregated_without_private_data(): void
    {
        $room = $this->classroom();
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Submitted,
            attributes: [
                'activity_name' => '',
                'purpose' => 'Rahasia pertama',
                'review_started_by' => $this->bookingUser()->id,
                'review_started_at' => now(),
            ],
        );
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Submitted,
            attributes: [
                'activity_name' => str_repeat('A', 140),
                'purpose' => 'Rahasia kedua',
            ],
        );

        $projection = $this->availability->projection(
            Carbon::parse('2026-06-20 00:00:00'),
            Carbon::parse('2026-06-21 00:00:00'),
            roomId: $room->id,
        );

        $this->assertCount(1, $projection);
        $item = $projection->first();
        $this->assertSame('pending', $item['lifecycle_category']);
        $this->assertSame(2, $item['request_count']);
        $this->assertSame('Pengajuan sedang diproses', $item['activity_titles'][0]);
        $this->assertLessThanOrEqual(123, mb_strlen($item['activity_titles'][1]));
        $this->assertStringNotContainsString('Rahasia', json_encode($item, JSON_THROW_ON_ERROR));
    }

    public function test_expired_completed_and_inactive_lifecycle_records_are_excluded(): void
    {
        $room = $this->classroom();
        foreach ([
            RoomBookingStatus::RevisionRequested,
            RoomBookingStatus::Rejected,
            RoomBookingStatus::Cancelled,
        ] as $status) {
            $this->roomBooking($room, status: $status);
        }
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-19 06:00:00',
            endAt: '2026-06-19 07:00:00',
        );
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Approved,
            startAt: '2026-06-19 06:00:00',
            endAt: '2026-06-19 07:00:00',
        );

        $projection = $this->availability->projection(
            Carbon::parse('2026-06-19 00:00:00'),
            Carbon::parse('2026-06-21 00:00:00'),
            roomId: $room->id,
        );

        $this->assertEmpty($projection);
    }
}
