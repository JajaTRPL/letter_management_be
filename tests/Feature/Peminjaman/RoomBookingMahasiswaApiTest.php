<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;

class RoomBookingMahasiswaApiTest extends RoomBookingApiTestCase
{
    public function test_mahasiswa_can_create_own_request_and_initial_history(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);

        $response = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', RoomBookingStatus::Submitted->value)
            ->assertJsonPath('data.status_histories.0.from_status', null)
            ->assertJsonPath(
                'data.status_histories.0.to_status',
                RoomBookingStatus::Submitted->value,
            );

        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $response->json('data.id'),
            'requester_id' => $student->id,
            'room_id' => $room->id,
            'status' => RoomBookingStatus::Submitted->value,
        ]);
        $this->assertDatabaseHas('room_booking_status_histories', [
            'room_booking_request_id' => $response->json('data.id'),
            'actor_id' => $student->id,
            'to_status' => RoomBookingStatus::Submitted->value,
        ]);
        $this->assertDatabaseHas('room_booking_attachments', [
            'room_booking_request_id' => $response->json('data.id'),
            'document_type' => 'surat_peminjaman',
            'storage_disk' => 'local',
        ]);
        $this->assertDatabaseHas('room_booking_audit_logs', [
            'room_booking_request_id' => $response->json('data.id'),
            'actor_id' => $student->id,
            'action' => 'upload',
            'document_type' => 'surat_peminjaman',
        ]);
        $this->assertTrue($response->json('data.surat_peminjaman_pdf.exists'));
        $this->assertArrayNotHasKey('storage_path', $response->json('data.surat_peminjaman_pdf'));
    }

    public function test_create_requires_surat_peminjaman_pdf(): void
    {
        $room = $this->classroom();
        $this->actingAsUser($this->student());

        $this->postJson(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayload($room),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('surat_peminjaman_pdf');
    }

    public function test_create_rejects_inactive_room_and_capacity_overflow_as_validation_errors(): void
    {
        $inactive = $this->classroom(['is_active' => false]);
        $small = $this->classroom(['capacity' => 5]);
        $this->actingAsUser($this->student());

        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($inactive),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');

        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($small, ['participant_count' => 6]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('participant_count');
    }

    public function test_create_rejects_approved_conflict_with_409(): void
    {
        $room = $this->classroom();
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Approved,
        );
        $this->actingAsUser($this->student());

        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room, [
                'start_at' => '2026-06-20T11:00:00+07:00',
                'end_at' => '2026-06-20T13:00:00+07:00',
            ]),
        )
            ->assertConflict()
            ->assertJsonPath('code', 'booking_conflict')
            ->assertJsonStructure(['data' => ['conflicts']]);
    }

    public function test_create_allows_adjacent_approved_booking(): void
    {
        $room = $this->classroom();
        $this->roomBooking(
            $room,
            status: RoomBookingStatus::Approved,
        );
        $this->actingAsUser($this->student());

        $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room, [
                'start_at' => '2026-06-20T12:00:00+07:00',
                'end_at' => '2026-06-20T13:00:00+07:00',
            ]),
        )->assertCreated();
    }

    public function test_mahasiswa_lists_only_own_requests_and_cannot_see_another_request(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $own = $this->roomBooking($room, $student);
        $other = $this->roomBooking(
            $room,
            $this->student(['email' => 'other-booking@example.test']),
            startAt: '2026-06-20 13:00:00',
            endAt: '2026-06-20 14:00:00',
        );
        $this->actingAsUser($student);

        $this->getJson($this->mahasiswaUrl('/requests'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonMissing(['id' => $other->id]);

        $this->getJson($this->mahasiswaUrl("/requests/{$other->id}"))
            ->assertNotFound();
    }

    public function test_mahasiswa_can_edit_only_revision_requested_booking(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $revision = $this->markRevisionRequested($room, $student);
        $submitted = $this->roomBooking(
            $room,
            $student,
            startAt: '2026-06-21 10:00:00',
            endAt: '2026-06-21 12:00:00',
        );
        $this->actingAsUser($student);
        $payload = $this->validBookingPayload($room, [
            'activity_name' => 'Updated Revision Activity',
            'start_at' => '2026-06-22T10:00:00+07:00',
            'end_at' => '2026-06-22T12:00:00+07:00',
        ]);

        $this->putJson(
            $this->mahasiswaUrl("/requests/{$revision->id}"),
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('data.activity_name', 'Updated Revision Activity')
            ->assertJsonPath(
                'data.status',
                RoomBookingStatus::RevisionRequested->value,
            );

        $this->putJson(
            $this->mahasiswaUrl("/requests/{$submitted->id}"),
            $payload,
        )
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_transition');
    }

    public function test_mahasiswa_can_resubmit_revision_but_not_non_revision(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $revision = $this->markRevisionRequested($room, $student, [
            'revision_note' => 'Please adjust the request.',
        ]);
        $this->createSuratPeminjamanAttachment($revision, $student);
        $submitted = $this->roomBooking(
            $room,
            $student,
            startAt: '2026-06-21 10:00:00',
            endAt: '2026-06-21 12:00:00',
        );
        $this->actingAsUser($student);

        $this->patchJson(
            $this->mahasiswaUrl("/requests/{$revision->id}/submit"),
        )
            ->assertOk()
            ->assertJsonPath('data.status', RoomBookingStatus::Submitted->value)
            ->assertJsonPath('data.revision_note', null)
            ->assertJsonPath(
                'data.status_histories.0.to_status',
                RoomBookingStatus::Submitted->value,
            );

        $this->patchJson(
            $this->mahasiswaUrl("/requests/{$submitted->id}/submit"),
        )
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_transition');
    }

    public function test_mahasiswa_resubmit_revision_requires_existing_attachment(): void
    {
        $student = $this->student();
        $revision = $this->markRevisionRequested($this->classroom(), $student, [
            'revision_note' => 'Please upload the signed surat peminjaman.',
        ]);
        $this->actingAsUser($student);

        $this->patchJson(
            $this->mahasiswaUrl("/requests/{$revision->id}/submit"),
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'attachment_required');
    }

    public function test_mahasiswa_can_cancel_submitted_and_revision_requested_bookings(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $submitted = $this->roomBooking($room, $student);
        $revision = $this->markRevisionRequested(
            $room,
            $student,
            ['start_at' => '2026-06-21 10:00:00', 'end_at' => '2026-06-21 12:00:00'],
        );
        $this->actingAsUser($student);

        foreach ([$submitted, $revision] as $booking) {
            $this->patchJson(
                $this->mahasiswaUrl("/requests/{$booking->id}/cancel"),
                ['reason' => 'Activity cancelled by requester.'],
            )
                ->assertOk()
                ->assertJsonPath(
                    'data.status',
                    RoomBookingStatus::Cancelled->value,
                )
                ->assertJsonPath(
                    'data.status_histories.0.to_status',
                    RoomBookingStatus::Cancelled->value,
                );
        }
    }

    public function test_mahasiswa_cannot_cancel_approved_booking_at_start_time(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::Approved,
            '2026-06-18 09:00:00',
            '2026-06-18 11:00:00',
        );
        $this->actingAsUser($student);

        $this->patchJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/cancel"),
            ['reason' => 'Too late to cancel.'],
        )
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_transition');
    }
}
