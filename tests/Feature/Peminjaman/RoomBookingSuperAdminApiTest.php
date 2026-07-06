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
