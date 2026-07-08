<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;

class RoomBookingReviewerApiTest extends RoomBookingApiTestCase
{
    public function test_sarpras_can_list_and_approve_classroom_booking(): void
    {
        $classroomBooking = $this->roomBooking($this->classroom());
        $laboratory = $this->bookingLaboratory('SARPRAS-LAB');
        $laboratoryBooking = $this->roomBooking(
            $this->laboratoryRoom($laboratory),
        );
        $sarpras = $this->reviewerUser('sarpras');
        $this->actingAsUser($sarpras);

        $this->getJson($this->reviewerUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $classroomBooking->id)
            ->assertJsonMissing(['id' => $laboratoryBooking->id]);

        $this->patchJson(
            $this->reviewerUrl("/{$classroomBooking->id}/approve"),
        )
            ->assertOk()
            ->assertJsonPath('data.status', RoomBookingStatus::Approved->value)
            ->assertJsonPath('data.reviewer.id', $sarpras->id)
            ->assertJsonPath(
                'data.status_histories.0.to_status',
                RoomBookingStatus::Approved->value,
            );
    }

    public function test_sarpras_cannot_action_laboratory_booking(): void
    {
        $laboratory = $this->bookingLaboratory('SARPRAS-DENIED');
        $booking = $this->roomBooking($this->laboratoryRoom($laboratory));
        $this->actingAsUser($this->reviewerUser('sarpras'));

        $this->patchJson($this->reviewerUrl("/{$booking->id}/approve"))
            ->assertForbidden()
            ->assertJsonPath('code', 'unauthorized_action');
    }

    public function test_kepala_lab_can_list_and_action_only_own_laboratory(): void
    {
        $ownLaboratory = $this->bookingLaboratory('KALAB-OWN');
        $otherLaboratory = $this->bookingLaboratory('KALAB-OTHER');
        $ownBooking = $this->roomBooking(
            $this->laboratoryRoom($ownLaboratory),
        );
        $otherBooking = $this->roomBooking(
            $this->laboratoryRoom($otherLaboratory),
        );
        $kepalaLab = $this->reviewerUser('kepala_lab', $ownLaboratory);
        $this->actingAsUser($kepalaLab);

        $this->getJson($this->reviewerUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownBooking->id)
            ->assertJsonMissing(['id' => $otherBooking->id]);

        $this->patchJson($this->reviewerUrl("/{$ownBooking->id}/approve"))
            ->assertOk()
            ->assertJsonPath('data.status', RoomBookingStatus::Approved->value);

        $this->patchJson($this->reviewerUrl("/{$otherBooking->id}/approve"))
            ->assertForbidden()
            ->assertJsonPath('code', 'unauthorized_action');

        $this->getJson($this->reviewerUrl("/{$otherBooking->id}"))
            ->assertNotFound();
    }

    public function test_laboran_can_list_and_read_scoped_lab_but_cannot_take_actions(): void
    {
        $laboratory = $this->bookingLaboratory('LABORAN');
        $booking = $this->roomBooking($this->laboratoryRoom($laboratory));
        $laboran = $this->reviewerUser('laboran', $laboratory);
        $this->actingAsUser($laboran);

        $this->getJson($this->reviewerUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $booking->id);

        $this->getJson($this->reviewerUrl("/{$booking->id}"))
            ->assertOk()
            ->assertJsonPath('data.id', $booking->id);

        $this->patchJson($this->reviewerUrl("/{$booking->id}/approve"))
            ->assertForbidden()
            ->assertJsonPath('code', 'unauthorized_action');
        $this->patchJson(
            $this->reviewerUrl("/{$booking->id}/revise"),
            ['note' => 'Laboran must not revise.'],
        )
            ->assertForbidden()
            ->assertJsonPath('code', 'unauthorized_action');
        $this->patchJson(
            $this->reviewerUrl("/{$booking->id}/reject"),
            ['reason' => 'Laboran must not reject.'],
        )
            ->assertForbidden()
            ->assertJsonPath('code', 'unauthorized_action');
    }

    public function test_persuratan_tendik_cannot_access_reviewer_endpoints(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $this->actingAsUser($this->persuratan());

        $this->getJson($this->reviewerUrl())->assertForbidden();
        $this->patchJson($this->reviewerUrl("/{$booking->id}/approve"))
            ->assertForbidden()
            ->assertJsonPath('code', 'unauthorized_action');
    }

    public function test_reviewer_approve_maps_conflict_to_409(): void
    {
        $room = $this->classroom();
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Approved,
        );
        $candidate = $this->roomBooking(
            $room,
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-20 11:00:00',
            endAt: '2026-06-20 13:00:00',
        );
        $this->actingAsUser($this->reviewerUser('sarpras'));

        $this->patchJson($this->reviewerUrl("/{$candidate->id}/approve"))
            ->assertConflict()
            ->assertJsonPath('code', 'booking_conflict')
            ->assertJsonStructure(['data' => ['conflicts']]);
    }

    public function test_revise_requires_note_and_reject_requires_reason(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $this->actingAsUser($this->reviewerUser('sarpras'));

        $this->patchJson($this->reviewerUrl("/{$booking->id}/revise"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->patchJson($this->reviewerUrl("/{$booking->id}/reject"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_reviewer_invalid_transition_maps_to_409(): void
    {
        $booking = $this->roomBooking(
            $this->classroom(),
            status: RoomBookingStatus::Approved,
        );
        $this->actingAsUser($this->reviewerUser('sarpras'));

        $this->patchJson($this->reviewerUrl("/{$booking->id}/approve"))
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_transition');
    }
}
