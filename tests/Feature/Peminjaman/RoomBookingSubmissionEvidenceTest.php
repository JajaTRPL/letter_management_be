<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingAttachment;
use App\Models\RoomBookingCancellationRequest;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingSubmissionSnapshot;
use App\Models\RoomBookingWorkflowEvent;
use App\Services\RoomBookingCancellationRequestService;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingSubmissionSnapshotService;
use App\Services\RoomBookingTransitionService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * C7B1 evidence layer: immutable submission snapshots and the append-only
 * workflow event ledger.
 */
class RoomBookingSubmissionEvidenceTest extends RoomBookingApiTestCase
{
    public function test_initial_submission_writes_one_canonical_snapshot(): void
    {
        $student = $this->student();
        $laboratory = $this->bookingLaboratory();
        $room = $this->laboratoryRoom($laboratory);
        $this->actingAsUser($student);

        $bookingId = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room),
        )->assertCreated()->json('data.id');

        $snapshots = RoomBookingSubmissionSnapshot::query()
            ->where('room_booking_request_id', $bookingId)
            ->get();
        $this->assertCount(1, $snapshots);

        $snapshot = $snapshots->first();
        $this->assertSame(1, $snapshot->submission_iteration);
        $this->assertSame(
            RoomBookingSubmissionSnapshot::PROVENANCE_NATIVE_SUBMISSION,
            $snapshot->provenance,
        );

        // Allowlisted canonical payload only, in deterministic key order.
        $this->assertSame([
            'activity_name',
            'attachment_checksum_sha256',
            'attachment_document_type',
            'end_at',
            'participant_count',
            'purpose',
            'room_id',
            'schema_version',
            'start_at',
        ], array_keys($snapshot->payload));

        // Deterministic checksum over the canonical JSON encoding.
        $expectedChecksum = hash('sha256', json_encode(
            $snapshot->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $this->assertSame($expectedChecksum, $snapshot->payload_checksum);

        // Attachment evidence matches the stored attachment.
        $this->assertSame(
            hash('sha256', self::VALID_PDF_BYTES),
            $snapshot->attachment_checksum,
        );
        $this->assertNotNull($snapshot->attachment_id);

        // Requester/room/lab identity snapshots.
        $this->assertSame($student->name, $snapshot->requester_name_snapshot);
        $this->assertSame('mahasiswa', $snapshot->requester_role_snapshot);
        $this->assertSame($room->id, $snapshot->room_id_snapshot);
        $this->assertSame($room->name, $snapshot->room_name_snapshot);
        $this->assertSame('laboratory', $snapshot->room_type_snapshot);
        $this->assertSame($laboratory->id, $snapshot->laboratory_id_snapshot);
        $this->assertSame($laboratory->name, $snapshot->laboratory_name_snapshot);

        // No secrets or storage internals anywhere in the snapshot payload.
        $encoded = json_encode($snapshot->payload);
        $this->assertStringNotContainsString('/storage/', $encoded);
        $this->assertStringNotContainsString('room-booking-attachments', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('token', $encoded);
    }

    public function test_resubmission_creates_iteration_two_and_keeps_iteration_one_intact(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);

        $bookingId = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room),
        )->assertCreated()->json('data.id');

        $firstSnapshot = RoomBookingSubmissionSnapshot::query()
            ->where('room_booking_request_id', $bookingId)
            ->firstOrFail();
        $firstChecksum = $firstSnapshot->payload_checksum;

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->patchJson($this->reviewerUrl("/{$bookingId}/revise"), [
            'note' => 'Perjelas tujuan kegiatan.',
        ])->assertOk();

        $this->actingAsUser($student);
        $this->putJson(
            $this->mahasiswaUrl("/requests/{$bookingId}"),
            $this->validBookingPayload($room, ['purpose' => 'Tujuan kegiatan yang diperjelas.']),
        )->assertOk();
        $this->patchJson($this->mahasiswaUrl("/requests/{$bookingId}/submit"))
            ->assertOk()
            ->assertJsonPath('data.submission_iteration', 2);

        $snapshots = RoomBookingSubmissionSnapshot::query()
            ->where('room_booking_request_id', $bookingId)
            ->orderBy('submission_iteration')
            ->get();
        $this->assertCount(2, $snapshots);

        $this->assertSame($firstChecksum, $snapshots[0]->payload_checksum);
        $this->assertSame('API contract test purpose.', $snapshots[0]->payload['purpose']);
        $this->assertSame(
            RoomBookingSubmissionSnapshot::PROVENANCE_NATIVE_RESUBMISSION,
            $snapshots[1]->provenance,
        );
        $this->assertSame(2, $snapshots[1]->submission_iteration);
        $this->assertSame('Tujuan kegiatan yang diperjelas.', $snapshots[1]->payload['purpose']);
    }

    public function test_snapshot_retry_is_idempotent_and_conflicting_payload_is_refused(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $service = app(RoomBookingSubmissionSnapshotService::class);

        $first = $service->capture(
            $booking,
            $student,
            RoomBookingSubmissionSnapshot::PROVENANCE_NATIVE_SUBMISSION,
        );
        $retry = $service->capture(
            $booking->fresh(),
            $student,
            RoomBookingSubmissionSnapshot::PROVENANCE_NATIVE_SUBMISSION,
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, RoomBookingSubmissionSnapshot::query()
            ->where('room_booking_request_id', $booking->id)
            ->count());

        // Same iteration, different canonical payload: refused, evidence kept.
        $booking->forceFill(['purpose' => 'Changed after evidence was recorded.'])->save();

        $this->expectException(RuntimeException::class);
        $service->capture(
            $booking->fresh(),
            $student,
            RoomBookingSubmissionSnapshot::PROVENANCE_NATIVE_SUBMISSION,
        );
    }

    public function test_snapshots_and_events_are_immutable_models(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $snapshot = app(RoomBookingSubmissionSnapshotService::class)->capture(
            $booking,
            $student,
            RoomBookingSubmissionSnapshot::PROVENANCE_LEGACY_CURRENT_STATE,
        );
        app(RoomBookingTransitionService::class)
            ->reject($booking, $this->reviewerUser('sarpras'), 'Tidak sesuai ketentuan.');
        $event = RoomBookingWorkflowEvent::query()->firstOrFail();

        try {
            $snapshot->update(['provenance' => 'tampered']);
            $this->fail('Snapshot update should be refused.');
        } catch (RuntimeException) {
        }

        try {
            $snapshot->delete();
            $this->fail('Snapshot delete should be refused.');
        } catch (RuntimeException) {
        }

        try {
            $event->update(['public_note' => 'tampered']);
            $this->fail('Event update should be refused.');
        } catch (RuntimeException) {
        }

        try {
            $event->delete();
            $this->fail('Event delete should be refused.');
        } catch (RuntimeException) {
        }

        $this->assertDatabaseHas('room_booking_submission_snapshots', [
            'id' => $snapshot->id,
            'provenance' => RoomBookingSubmissionSnapshot::PROVENANCE_LEGACY_CURRENT_STATE,
        ]);
        $this->assertDatabaseHas('room_booking_workflow_events', [
            'id' => $event->id,
            'public_note' => 'Tidak sesuai ketentuan.',
        ]);
    }

    public function test_full_lifecycle_appends_exactly_one_event_per_transition(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $reviewer = $this->reviewerUser('sarpras');
        $service = app(RoomBookingTransitionService::class);

        $booking = new RoomBookingRequest([
            'requester_id' => $student->id,
            'room_id' => $room->id,
            'activity_name' => 'Ledger Trail',
            'purpose' => 'Event ledger test.',
            'participant_count' => 5,
            'start_at' => '2026-06-21T10:00:00+07:00',
            'end_at' => '2026-06-21T12:00:00+07:00',
        ]);
        $booking = $service->submit($booking, $student);
        $booking = $service->requestRevision($booking, $reviewer, 'Lengkapi data.');
        $booking = $service->submit($booking, $student);
        $booking = $service->approve($booking, $reviewer);
        $cancellations = app(RoomBookingCancellationRequestService::class);
        $requested = $cancellations->create(
            $booking,
            $student,
            'Kegiatan batal.',
            4,
            'evidence-cancel-request',
        );
        $cancellationRequest = RoomBookingCancellationRequest::findOrFail(
            $requested->body['data']['cancellation_request_id'],
        );
        $cancellations->approve(
            $cancellationRequest,
            $reviewer,
            'Disetujui.',
            5,
            'evidence-cancel-approve',
        );

        $events = RoomBookingWorkflowEvent::query()
            ->where('room_booking_request_id', $booking->id)
            ->orderBy('id')
            ->get();

        $this->assertSame([
            RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED,
            RoomBookingWorkflowEvent::EVENT_REVISION_REQUESTED,
            RoomBookingWorkflowEvent::EVENT_BOOKING_RESUBMITTED,
            RoomBookingWorkflowEvent::EVENT_OCCURRENCE_CREATED,
            RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED,
            RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUESTED,
            RoomBookingWorkflowEvent::EVENT_CANCELLATION_APPROVED,
        ], $events->pluck('event_type')->all());

        $submitted = $events[0];
        $this->assertNull($submitted->previous_status);
        $this->assertSame('submitted', $submitted->resulting_status);
        $this->assertNull($submitted->workflow_version_before);
        $this->assertSame(1, $submitted->workflow_version_after);
        $this->assertSame($student->name, $submitted->actor_name_snapshot);
        $this->assertSame('mahasiswa', $submitted->actor_role_snapshot);
        $this->assertSame(
            ['room_id', 'start_at', 'end_at'],
            array_keys($submitted->safe_metadata),
        );
        $this->assertNotNull($submitted->correlation_id);

        $revision = $events[1];
        $this->assertSame('submitted', $revision->previous_status);
        $this->assertSame('revision_requested', $revision->resulting_status);
        $this->assertSame(1, $revision->workflow_version_before);
        $this->assertSame(2, $revision->workflow_version_after);
        $this->assertSame('tendik', $revision->actor_role_snapshot);
        $this->assertSame('sarpras', $revision->actor_subrole_snapshot);
        $this->assertSame('Lengkapi data.', $revision->public_note);
        // revision_requested allows no extra metadata.
        $this->assertNull($revision->safe_metadata);

        $resubmitted = $events[2];
        $this->assertSame(2, $resubmitted->submission_iteration);
        $this->assertSame(3, $resubmitted->workflow_version_after);

        $requestedCancellation = $events[5];
        $this->assertSame('approved', $requestedCancellation->previous_status);
        $this->assertSame('approved', $requestedCancellation->resulting_status);
        $this->assertSame(5, $requestedCancellation->workflow_version_after);

        $cancelled = $events[6];
        $this->assertSame('approved', $cancelled->previous_status);
        $this->assertSame('cancelled', $cancelled->resulting_status);
        $this->assertSame('Kegiatan batal.', $cancelled->public_note);
    }

    public function test_failed_post_attachment_step_rolls_back_rows_and_removes_file(): void
    {
        // A pre-existing attachment for an unrelated booking must survive the
        // compensation untouched.
        $existingBooking = $this->roomBooking($this->classroom(), $this->student());
        $existingAttachment = $this->createSuratPeminjamanAttachment($existingBooking);
        $this->assertTrue(Storage::disk('local')->exists($existingAttachment->storage_path));

        // Force a failure AFTER the attachment is persisted but before the
        // outer transaction commits. Any post-attachment collaborator works;
        // the snapshot service is the first one in the sequence.
        $this->mock(RoomBookingSubmissionSnapshotService::class, function ($mock) {
            $mock->shouldReceive('capture')
                ->andThrow(new RuntimeException('Snapshot pipeline unavailable.'));
        });

        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);

        $bookingsBefore = RoomBookingRequest::query()->count();

        $response = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room),
        );

        $response->assertStatus(500)
            ->assertJsonPath('code', 'infrastructure_error');
        // Infrastructure details remain private; no raw collaborator text or path leaks.
        $this->assertStringNotContainsString(
            'Snapshot pipeline unavailable',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            'room-booking-attachments',
            $response->getContent(),
        );

        // Every database row of the failed attempt rolled back.
        $this->assertSame($bookingsBefore, RoomBookingRequest::query()->count());
        $this->assertSame(0, RoomBookingRequest::query()
            ->where('requester_id', $student->id)->count());
        $this->assertDatabaseMissing('room_booking_attachments', [
            'uploaded_by' => $student->id,
        ]);
        $this->assertDatabaseMissing('room_booking_status_histories', [
            'actor_id' => $student->id,
        ]);
        $this->assertDatabaseMissing('room_booking_submission_snapshots', [
            'submitted_by' => $student->id,
        ]);
        $this->assertDatabaseMissing('room_booking_workflow_events', [
            'actor_id' => $student->id,
        ]);

        // The newly written physical file is gone; the unrelated pre-existing
        // attachment file is untouched.
        $this->assertSame(
            [$existingAttachment->storage_path],
            Storage::disk('local')->allFiles('room-booking-attachments/surat-peminjaman'),
        );
    }

    public function test_successful_submission_keeps_the_physical_file(): void
    {
        $student = $this->student();
        $this->actingAsUser($student);

        $bookingId = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($this->classroom()),
        )->assertCreated()->json('data.id');

        $attachment = RoomBookingAttachment::query()
            ->where('room_booking_request_id', $bookingId)
            ->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($attachment->storage_path));
    }

    public function test_failed_transition_appends_no_event(): void
    {
        $booking = $this->roomBooking($this->classroom(), $this->student());
        $reviewer = $this->reviewerUser('sarpras');

        try {
            app(RoomBookingTransitionService::class)
                ->requestRevision($booking, $reviewer, '   ');
            $this->fail('Expected note_required.');
        } catch (RoomBookingDomainException $exception) {
            $this->assertSame(RoomBookingDomainException::NOTE_REQUIRED, $exception->reason);
        }

        $this->assertSame(0, RoomBookingWorkflowEvent::query()->count());
    }

    public function test_booking_payload_does_not_leak_ledger_internals(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);

        $response = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room),
        )->assertCreated();

        $data = $response->json('data');
        $this->assertArrayNotHasKey('safe_metadata', $data);
        $this->assertArrayNotHasKey('internal_note', $data);
        $this->assertArrayNotHasKey('payload_checksum', $data);
        $this->assertStringNotContainsString('room-booking-attachments/', $response->getContent());
    }

    public function test_status_transition_states_remain_correct_after_ledger_integration(): void
    {
        // Regression companion: the ledger work must not change the stored
        // status ladder the FE relies on.
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);
        $reviewer = $this->reviewerUser('sarpras');
        $service = app(RoomBookingTransitionService::class);

        $this->assertSame(
            RoomBookingStatus::RevisionRequested,
            $service->requestRevision($booking, $reviewer, 'Cek kapasitas.')->status,
        );
        $this->assertSame(
            RoomBookingStatus::Submitted,
            $service->submit($booking->fresh(), $student)->status,
        );
        $this->assertSame(
            RoomBookingStatus::Approved,
            $service->approve($booking->fresh(), $reviewer)->status,
        );
    }
}
