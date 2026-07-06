<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;

class RoomBookingSharedApiTest extends RoomBookingApiTestCase
{
    public function test_authenticated_mahasiswa_can_list_only_active_rooms(): void
    {
        $active = $this->classroom(['code' => 'API-ACTIVE']);
        $inactive = $this->classroom([
            'code' => 'API-INACTIVE',
            'is_active' => false,
        ]);
        $this->actingAsUser($this->student());

        $response = $this->getJson($this->mahasiswaUrl('/rooms'));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonMissing(['id' => $inactive->id]);
    }

    public function test_availability_returns_only_approved_bookings_with_privacy_safe_shape(): void
    {
        $room = $this->classroom(['code' => 'API-AVAIL']);
        $reviewer = $this->reviewerUser('sarpras');
        $approved = $this->roomBooking(
            $room,
            status: RoomBookingStatus::Approved,
            attributes: [
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'purpose' => 'Private approved purpose.',
                'revision_note' => 'Private revision note.',
            ],
        );
        $submitted = $this->roomBooking(
            $room,
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-20 13:00:00',
            endAt: '2026-06-20 14:00:00',
        );
        $this->actingAsUser($this->student());

        $response = $this->getJson($this->mahasiswaUrl(
            '/availability?from=2026-06-20&to=2026-06-20',
        ));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking_id', $approved->id)
            ->assertJsonPath('data.0.status', RoomBookingStatus::Approved->value)
            ->assertJsonMissing(['booking_id' => $submitted->id]);

        $this->assertSame(
            ['booking_id', 'room', 'start_at', 'end_at', 'status'],
            array_keys($response->json('data.0')),
        );
        $this->assertSame(
            ['id', 'code', 'name', 'type'],
            array_keys($response->json('data.0.room')),
        );
    }

    public function test_availability_validates_date_range(): void
    {
        $this->actingAsUser($this->student());

        $this->getJson($this->mahasiswaUrl(
            '/availability?from=2026-06-21&to=2026-06-20',
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson($this->mahasiswaUrl(
            '/availability?from=2026-06-20&to=2026-09-01',
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_availability_filters_by_room_and_type(): void
    {
        $target = $this->classroom(['code' => 'API-TARGET']);
        $otherClassroom = $this->classroom(['code' => 'API-OTHER']);
        $laboratory = $this->bookingLaboratory('FILTER');
        $labRoom = $this->laboratoryRoom($laboratory, ['code' => 'API-LAB']);

        $targetBooking = $this->roomBooking(
            $target,
            status: RoomBookingStatus::Approved,
        );
        $this->roomBooking(
            $otherClassroom,
            status: RoomBookingStatus::Approved,
        );
        $this->roomBooking(
            $labRoom,
            status: RoomBookingStatus::Approved,
        );
        $this->actingAsUser($this->student());

        $response = $this->getJson($this->mahasiswaUrl(
            "/availability?from=2026-06-20&to=2026-06-20&type=classroom&room_id={$target->id}",
        ));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking_id', $targetBooking->id)
            ->assertJsonPath('data.0.room.id', $target->id)
            ->assertJsonPath('data.0.room.type', 'classroom');
    }
}
