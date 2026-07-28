<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingIdempotencyRecord;
use App\Models\RoomBookingStatusHistory;

class RoomBookingApplicantPrivacyProjectionTest extends RoomBookingApiTestCase
{
    public function test_applicant_list_and_detail_remove_internal_identity_fields(): void
    {
        $student = $this->student();
        $reviewer = $this->reviewerUser('sarpras');
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::Cancelled,
            attributes: [
                'reviewer_id' => $reviewer->id,
                'cancelled_by_role_snapshot' => 'sarpras',
                'cancellation_source' => 'request_approved',
            ],
        );
        RoomBookingStatusHistory::create([
            'room_booking_request_id' => $booking->id,
            'from_status' => RoomBookingStatus::Approved,
            'to_status' => RoomBookingStatus::Cancelled,
            'actor_id' => $reviewer->id,
            'note' => 'Pembatalan disetujui.',
        ]);

        $this->actingAsUser($student);
        $list = $this->getJson($this->mahasiswaUrl('/requests'))
            ->assertOk()
            ->json('data.0');
        $detail = $this->getJson($this->mahasiswaUrl("/requests/{$booking->id}"))
            ->assertOk()
            ->json('data');

        $this->assertSame(['name' => $reviewer->name], $list['reviewer']);
        $this->assertArrayNotHasKey('cancelled_by_role_snapshot', $list);
        $this->assertSame(['name' => $reviewer->name], $detail['reviewer']);
        $this->assertArrayNotHasKey('cancelled_by_role_snapshot', $detail);
        $this->assertSame(
            ['name' => $reviewer->name],
            $detail['status_histories'][0]['actor'],
        );
        $this->assertArrayNotHasKey('requester', $detail);
    }

    public function test_authorized_staff_projection_keeps_operational_identity_fields(): void
    {
        $student = $this->student();
        $reviewer = $this->reviewerUser('sarpras');
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::Cancelled,
            attributes: [
                'reviewer_id' => $reviewer->id,
                'cancelled_by_role_snapshot' => 'sarpras',
                'cancellation_source' => 'request_approved',
            ],
        );
        RoomBookingStatusHistory::create([
            'room_booking_request_id' => $booking->id,
            'from_status' => RoomBookingStatus::Approved,
            'to_status' => RoomBookingStatus::Cancelled,
            'actor_id' => $reviewer->id,
            'note' => 'Pembatalan disetujui.',
        ]);

        $this->actingAsUser($reviewer);
        $data = $this->getJson($this->reviewerUrl("/{$booking->id}"))
            ->assertOk()
            ->json('data');

        $this->assertSame($reviewer->id, $data['reviewer']['id']);
        $this->assertSame($reviewer->id, $data['status_histories'][0]['actor']['id']);
        $this->assertSame('sarpras', $data['cancelled_by_role_snapshot']);
        $this->assertSame($student->id, $data['requester']['id']);
        $this->assertSame($student->email, $data['requester']['email']);
    }

    public function test_legacy_applicant_replay_is_minimized_before_return(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $key = 'legacy-applicant-projection-001';
        $this->actingAsUser($student);

        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room, ['idempotency_key' => $key]),
        )->assertCreated();

        $record = RoomBookingIdempotencyRecord::query()->firstOrFail();
        $legacyBody = $record->safe_response_body;
        $legacyBody['data']['reviewer'] = ['id' => 999, 'name' => 'Reviewer Publik'];
        $legacyBody['data']['cancelled_by_role_snapshot'] = 'sarpras';
        $legacyBody['data']['status_histories'][0]['actor']['id'] = $student->id;
        $record->forceFill([
            'response_schema_version' => 1,
            'safe_response_body' => $legacyBody,
        ])->save();

        $data = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room, ['idempotency_key' => $key]),
        )->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->json('data');

        $this->assertSame(['name' => 'Reviewer Publik'], $data['reviewer']);
        $this->assertArrayNotHasKey('id', $data['status_histories'][0]['actor']);
        $this->assertArrayNotHasKey('cancelled_by_role_snapshot', $data);
        $this->assertSame(2, $record->fresh()->response_schema_version);
    }
}
