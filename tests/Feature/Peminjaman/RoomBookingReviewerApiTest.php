<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;

class RoomBookingReviewerApiTest extends RoomBookingApiTestCase
{
    public function test_tendik_calendar_requires_auth_and_supported_reviewer_role(): void
    {
        $this->getJson($this->reviewerCalendarUrl('?month=2026-06'))
            ->assertUnauthorized();

        $this->actingAsUser($this->superAdmin());
        $this->getJson($this->reviewerCalendarUrl('?month=2026-06'))
            ->assertForbidden();

        $this->actingAsUser($this->persuratan());
        $this->getJson($this->reviewerCalendarUrl('?month=2026-06'))
            ->assertForbidden();

        $this->actingAsUser($this->reviewerUser('arsip'));
        $this->getJson($this->reviewerCalendarUrl('?month=2026-06'))
            ->assertForbidden();
    }

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

    public function test_sarpras_calendar_is_classroom_scoped_and_counts_ignore_status_filter(): void
    {
        $classroom = $this->classroom(['code' => 'SAR-CAL-01']);
        $approved = $this->roomBooking(
            $classroom,
            status: RoomBookingStatus::Approved,
            startAt: '2026-06-20 09:00:00',
            endAt: '2026-06-20 10:00:00',
        );
        $submitted = $this->roomBooking(
            $classroom,
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-21 09:00:00',
            endAt: '2026-06-21 10:00:00',
        );
        $laboratory = $this->bookingLaboratory('SAR-CAL');
        $labBooking = $this->roomBooking(
            $this->laboratoryRoom($laboratory),
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-22 09:00:00',
            endAt: '2026-06-22 10:00:00',
        );

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $response = $this->getJson($this->reviewerCalendarUrl('?month=2026-06&status=approved'));

        $response
            ->assertOk()
            ->assertJsonPath('month', '2026-06')
            ->assertJsonPath('range.start', '2026-06-01')
            ->assertJsonPath('range.end', '2026-06-30')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $approved->id)
            ->assertJsonPath('items.0.room_type', 'classroom')
            ->assertJsonPath('items.0.can_view', true)
            ->assertJsonPath('items.0.can_approve', false)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.counts_by_status.approved', 1)
            ->assertJsonPath('summary.counts_by_status.submitted', 1)
            ->assertJsonMissingPath('summary.counts_by_status.diproses');

        $ids = collect($response->json('items'))->pluck('id')->all();
        $this->assertNotContains($submitted->id, $ids);
        $this->assertNotContains($labBooking->id, $ids);
    }

    public function test_sarpras_calendar_laboratory_filters_do_not_expand_scope(): void
    {
        $this->roomBooking(
            $this->classroom(),
            status: RoomBookingStatus::Submitted,
        );
        $laboratory = $this->bookingLaboratory('SAR-FILTER');
        $labRoom = $this->laboratoryRoom($laboratory);
        $this->roomBooking(
            $labRoom,
            status: RoomBookingStatus::Submitted,
        );

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $query = sprintf(
            '?month=2026-06&room_type=laboratory&laboratory_id=%d&room_id=%d',
            $laboratory->id,
            $labRoom->id,
        );

        $this->getJson($this->reviewerCalendarUrl($query))
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('summary.total', 0);
    }

    public function test_sarpras_calendar_returns_action_capabilities_for_submitted_classroom_bookings(): void
    {
        $booking = $this->roomBooking(
            $this->classroom(['code' => 'SAR-ACTION']),
            status: RoomBookingStatus::Submitted,
        );
        $this->createSuratPeminjamanAttachment($booking);
        $this->actingAsUser($this->reviewerUser('sarpras'));

        $response = $this->getJson($this->reviewerCalendarUrl('?month=2026-06&status=submitted'));

        $response
            ->assertOk()
            ->assertJsonPath('items.0.id', $booking->id)
            ->assertJsonPath('items.0.can_review', true)
            ->assertJsonPath('items.0.can_approve', true)
            ->assertJsonPath('items.0.can_reject', true)
            ->assertJsonPath('items.0.can_request_revision', true)
            ->assertJsonPath('items.0.can_cancel', false)
            ->assertJsonPath('items.0.can_manage_room', true)
            ->assertJsonPath('items.0.can_update_readiness', false)
            ->assertJsonPath('items.0.can_resolve_conflict', false)
            ->assertJsonPath('items.0.can_relocate_booking', false)
            ->assertJsonMissingPath('items.0.surat_peminjaman_pdf')
            ->assertJsonMissingPath('items.0.storage_path');

        $content = $response->getContent();
        $this->assertStringNotContainsString('/storage/', $content);
        $this->assertStringNotContainsString('room-booking-attachments', $content);
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

    public function test_kepala_lab_calendar_is_own_lab_scoped_and_filters_cannot_expand_scope(): void
    {
        $ownLaboratory = $this->bookingLaboratory('KALAB-CAL-OWN');
        $otherLaboratory = $this->bookingLaboratory('KALAB-CAL-OTHER');
        $ownRoom = $this->laboratoryRoom($ownLaboratory, ['code' => 'KALAB-OWN']);
        $otherRoom = $this->laboratoryRoom($otherLaboratory, ['code' => 'KALAB-OTHER']);
        $ownBooking = $this->roomBooking(
            $ownRoom,
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-20 09:00:00',
            endAt: '2026-06-20 10:00:00',
        );
        $otherBooking = $this->roomBooking(
            $otherRoom,
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-21 09:00:00',
            endAt: '2026-06-21 10:00:00',
        );

        $this->actingAsUser($this->reviewerUser('kepala_lab', $ownLaboratory));

        $response = $this->getJson($this->reviewerCalendarUrl('?month=2026-06'));
        $response
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $ownBooking->id)
            ->assertJsonPath('items.0.laboratory_id', $ownLaboratory->id)
            ->assertJsonPath('items.0.can_approve', true)
            ->assertJsonPath('summary.counts_by_status.submitted', 1);

        $this->assertNotContains(
            $otherBooking->id,
            collect($response->json('items'))->pluck('id')->all(),
        );

        $this->getJson($this->reviewerCalendarUrl(sprintf(
            '?month=2026-06&laboratory_id=%d',
            $otherLaboratory->id,
        )))
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('summary.total', 0);

        $this->getJson($this->reviewerCalendarUrl(sprintf(
            '?month=2026-06&room_id=%d',
            $otherRoom->id,
        )))
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('summary.total', 0);
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

    public function test_laboran_calendar_is_all_lab_read_only_schedule_scope(): void
    {
        $ownLaboratory = $this->bookingLaboratory('LABORAN-CAL-OWN');
        $otherLaboratory = $this->bookingLaboratory('LABORAN-CAL-OTHER');
        $ownBooking = $this->roomBooking(
            $this->laboratoryRoom($ownLaboratory, ['code' => 'LABORAN-OWN']),
            status: RoomBookingStatus::Approved,
        );
        $otherBooking = $this->roomBooking(
            $this->laboratoryRoom($otherLaboratory, ['code' => 'LABORAN-OTHER']),
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-21 10:00:00',
            endAt: '2026-06-21 12:00:00',
        );
        $classroomBooking = $this->roomBooking(
            $this->classroom(['code' => 'LABORAN-CLASS']),
            status: RoomBookingStatus::Submitted,
            startAt: '2026-06-22 10:00:00',
            endAt: '2026-06-22 12:00:00',
        );

        $this->actingAsUser($this->reviewerUser('laboran', $ownLaboratory));
        $response = $this->getJson($this->reviewerCalendarUrl('?month=2026-06'));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.can_view', true)
            ->assertJsonPath('items.0.can_review', false)
            ->assertJsonPath('items.0.can_approve', false)
            ->assertJsonPath('items.0.can_reject', false)
            ->assertJsonPath('items.0.can_request_revision', false)
            ->assertJsonPath('items.0.can_cancel', false)
            ->assertJsonPath('items.0.can_manage_room', true)
            ->assertJsonPath('items.0.can_update_readiness', false)
            ->assertJsonPath('items.0.can_resolve_conflict', false)
            ->assertJsonPath('items.0.can_relocate_booking', false)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.counts_by_status.approved', 1)
            ->assertJsonPath('summary.counts_by_status.submitted', 1);

        $ids = collect($response->json('items'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$ownBooking->id, $otherBooking->id], $ids);
        $this->assertNotContains($classroomBooking->id, $ids);

        $filteredResponse = $this->getJson($this->reviewerCalendarUrl(sprintf(
            '?month=2026-06&laboratory_id=%d',
            $ownLaboratory->id,
        )));

        $filteredResponse
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $ownBooking->id)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.counts_by_status.approved', 1);

        $this->getJson($this->reviewerCalendarUrl('?month=2026-06&room_type=classroom'))
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('summary.total', 0);
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

    public function test_tendik_calendar_preserves_ninety_day_range_limit(): void
    {
        $booking = $this->roomBooking(
            $this->classroom(['code' => 'CAL-90-TENDIK']),
            status: RoomBookingStatus::Approved,
            startAt: '2026-09-15 09:00:00',
            endAt: '2026-09-15 11:00:00',
        );
        $this->actingAsUser($this->reviewerUser('sarpras'));

        $this->getJson($this->reviewerCalendarUrl('?from=2026-06-18&to=2026-09-15'))
            ->assertOk()
            ->assertJsonPath('range.start', '2026-06-18')
            ->assertJsonPath('range.end', '2026-09-15')
            ->assertJsonPath('items.0.id', $booking->id);

        $this->getJson($this->reviewerCalendarUrl('?from=2026-06-18&to=2026-09-16'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
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
