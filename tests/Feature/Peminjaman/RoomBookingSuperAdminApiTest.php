<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;

class RoomBookingSuperAdminApiTest extends RoomBookingApiTestCase
{
    public function test_super_admin_can_create_update_and_list_rooms(): void
    {
        $this->actingAsUser($this->superAdmin());

        $created = $this->postJson($this->adminUrl('/rooms'), [
            'code' => 'API-ADMIN-01',
            'name' => 'Admin Created Room',
            'type' => 'classroom',
            'capacity' => 40,
            'location' => 'Building A',
            'description' => 'Created by API test.',
            'owning_laboratory_id' => null,
        ]);

        $created
            ->assertCreated()
            ->assertJsonPath('data.code', 'API-ADMIN-01')
            ->assertJsonPath('data.type', 'classroom');

        $roomId = $created->json('data.id');
        $this->putJson($this->adminUrl("/rooms/{$roomId}"), [
            'code' => 'API-ADMIN-01',
            'name' => 'Admin Updated Room',
            'type' => 'classroom',
            'capacity' => 55,
            'location' => 'Building B',
            'description' => 'Updated by API test.',
            'owning_laboratory_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Admin Updated Room')
            ->assertJsonPath('data.capacity', 55);

        $this->getJson($this->adminUrl('/rooms'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $roomId);
    }

    public function test_room_code_unique_validation(): void
    {
        $this->classroom(['code' => 'API-DUPLICATE']);
        $this->actingAsUser($this->superAdmin());

        $this->postJson($this->adminUrl('/rooms'), [
            'code' => 'API-DUPLICATE',
            'name' => 'Duplicate Room',
            'type' => 'classroom',
            'capacity' => 20,
            'location' => 'Building C',
            'owning_laboratory_id' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_room_ownership_validation_enforces_classroom_and_laboratory_rules(): void
    {
        $laboratory = $this->bookingLaboratory('ADMIN-OWNERSHIP');
        $this->actingAsUser($this->superAdmin());
        $basePayload = [
            'name' => 'Ownership Validation Room',
            'capacity' => 20,
            'location' => 'Building D',
        ];

        $this->postJson($this->adminUrl('/rooms'), array_merge($basePayload, [
            'code' => 'API-CLASS-WITH-LAB',
            'type' => 'classroom',
            'owning_laboratory_id' => $laboratory->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('owning_laboratory_id');

        $this->postJson($this->adminUrl('/rooms'), array_merge($basePayload, [
            'code' => 'API-LAB-WITHOUT-OWNER',
            'type' => 'laboratory',
            'owning_laboratory_id' => null,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('owning_laboratory_id');
    }

    public function test_super_admin_can_activate_and_deactivate_room(): void
    {
        $room = $this->classroom(['is_active' => true]);
        $this->actingAsUser($this->superAdmin());

        $this->patchJson($this->adminUrl("/rooms/{$room->id}/deactivate"))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertFalse($room->fresh()->is_active);

        $this->patchJson($this->adminUrl("/rooms/{$room->id}/activate"))
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
        $this->assertTrue($room->fresh()->is_active);
    }

    public function test_super_admin_cannot_deactivate_room_with_future_approved_booking(): void
    {
        $room = $this->classroom();
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Approved,
        );
        $this->actingAsUser($this->superAdmin());

        $this->patchJson($this->adminUrl("/rooms/{$room->id}/deactivate"))
            ->assertConflict()
            ->assertJsonPath('code', 'booking_conflict');
        $this->assertTrue($room->fresh()->is_active);
    }

    public function test_super_admin_can_monitor_all_bookings(): void
    {
        $room = $this->classroom();
        $first = $this->roomBooking($room);
        $second = $this->roomBooking(
            $room,
            startAt: '2026-06-21 10:00:00',
            endAt: '2026-06-21 12:00:00',
        );
        $this->actingAsUser($this->superAdmin());

        $response = $this->getJson($this->adminUrl('/requests'));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
        $this->assertNotNull($response->json('data.0.requester.id'));
    }

    public function test_super_admin_calendar_returns_matching_items_summary_and_capability_flags(): void
    {
        $room = $this->classroom(['code' => 'CAL-101', 'name' => 'Calendar Room']);
        $booking = $this->roomBooking(
            $room,
            status: RoomBookingStatus::Approved,
            startAt: '2026-06-22 09:00:00',
            endAt: '2026-06-22 11:00:00',
            attributes: [
                'activity_name' => 'Calendar Activity',
                'purpose' => 'Calendar monitoring purpose.',
            ],
        );
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-23 09:00:00',
            endAt: '2026-06-23 11:00:00',
        );
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Submitted,
            startAt: '2026-07-01 09:00:00',
            endAt: '2026-07-01 11:00:00',
        );
        $this->actingAsUser($this->superAdmin());

        $response = $this->getJson($this->adminUrl('/calendar?month=2026-06&status=approved'));

        $response
            ->assertOk()
            ->assertJsonPath('month', '2026-06')
            ->assertJsonPath('range.start', '2026-06-01')
            ->assertJsonPath('range.end', '2026-06-30')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $booking->id)
            ->assertJsonPath('items.0.room_code', 'CAL-101')
            ->assertJsonPath('items.0.room_name', 'Calendar Room')
            ->assertJsonPath('items.0.status', RoomBookingStatus::Approved->value)
            ->assertJsonPath('items.0.can_view', true)
            ->assertJsonPath('items.0.can_review', false)
            ->assertJsonPath('items.0.can_approve', false)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.counts_by_status.approved', 1)
            ->assertJsonPath('summary.counts_by_status.submitted', 1)
            ->assertJsonMissingPath('summary.counts_by_status.diproses');
    }

    public function test_super_admin_calendar_filters_by_room_type_room_and_laboratory(): void
    {
        $targetLaboratory = $this->bookingLaboratory('CAL-A');
        $otherLaboratory = $this->bookingLaboratory('CAL-B');
        $targetRoom = $this->laboratoryRoom($targetLaboratory, ['code' => 'LAB-CAL-01']);
        $otherLabRoom = $this->laboratoryRoom($otherLaboratory, ['code' => 'LAB-CAL-02']);
        $classroom = $this->classroom(['code' => 'CLS-CAL-01']);
        $targetBooking = $this->roomBooking(
            $targetRoom,
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-23 09:00:00',
            endAt: '2026-06-23 11:00:00',
        );
        $this->roomBooking($otherLabRoom, status: RoomBookingStatus::Submitted);
        $this->roomBooking($classroom, status: RoomBookingStatus::Submitted);
        $this->actingAsUser($this->superAdmin());

        $response = $this->getJson($this->adminUrl(sprintf(
            '/calendar?from=2026-06-01&to=2026-06-30&status=submitted&room_type=laboratory&laboratory_id=%d&room_id=%d',
            $targetLaboratory->id,
            $targetRoom->id,
        )));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $targetBooking->id)
            ->assertJsonPath('items.0.room_id', $targetRoom->id)
            ->assertJsonPath('items.0.room_type', 'laboratory')
            ->assertJsonPath('items.0.laboratory_id', $targetLaboratory->id)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.counts_by_status.submitted', 1);
    }

    public function test_super_admin_calendar_accepts_upcoming_ninety_day_range(): void
    {
        $booking = $this->roomBooking(
            $this->classroom(['code' => 'CAL-90']),
            status: RoomBookingStatus::Approved,
            startAt: '2026-09-15 09:00:00',
            endAt: '2026-09-15 11:00:00',
        );
        $this->actingAsUser($this->superAdmin());

        $this->getJson($this->adminUrl('/calendar?from=2026-06-18&to=2026-09-15'))
            ->assertOk()
            ->assertJsonPath('range.start', '2026-06-18')
            ->assertJsonPath('range.end', '2026-09-15')
            ->assertJsonPath('items.0.id', $booking->id)
            ->assertJsonPath('summary.counts_by_status.approved', 1);
    }

    public function test_super_admin_calendar_is_read_only_role_scoped_and_hides_storage_details(): void
    {
        $booking = $this->roomBooking($this->classroom(), status: RoomBookingStatus::RevisionRequested);
        $this->createSuratPeminjamanAttachment($booking);

        $this->actingAsUser($this->persuratan());
        $this->getJson($this->adminUrl('/calendar?month=2026-06'))
            ->assertForbidden();

        $this->actingAsUser($this->superAdmin());
        $response = $this->getJson($this->adminUrl('/calendar?month=2026-06'));

        $response
            ->assertOk()
            ->assertJsonPath('items.0.id', $booking->id)
            ->assertJsonPath('items.0.can_review', false)
            ->assertJsonPath('items.0.can_request_revision', false)
            ->assertJsonMissingPath('items.0.surat_peminjaman_pdf')
            ->assertJsonMissingPath('items.0.storage_path');

        $content = $response->getContent();
        $this->assertStringNotContainsString('/storage/', $content);
        $this->assertStringNotContainsString('room-booking-attachments', $content);
        $this->assertSame(RoomBookingStatus::RevisionRequested, $booking->fresh()->status);
    }

    public function test_super_admin_cannot_approve_through_tendik_reviewer_route(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $this->actingAsUser($this->superAdmin());

        $this->patchJson($this->reviewerUrl("/{$booking->id}/approve"))
            ->assertForbidden();
        $this->assertSame(
            RoomBookingStatus::Submitted,
            $booking->fresh()->status,
        );
    }

    public function test_laboratories_endpoint_is_authenticated_super_admin_only_and_minimal(): void
    {
        $laboratory = $this->bookingLaboratory('API-LIST');

        $this->getJson('/api/laboratories')->assertUnauthorized();

        $this->actingAsUser($this->persuratan());
        $this->getJson('/api/laboratories')->assertForbidden();

        $this->actingAsUser($this->superAdmin());
        $response = $this->getJson('/api/laboratories');

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $laboratory->id)
            ->assertJsonPath('0.code', $laboratory->code)
            ->assertJsonPath('0.name', $laboratory->name);
        $this->assertSame(
            ['id', 'code', 'name'],
            array_keys($response->json('0')),
        );
    }
}
