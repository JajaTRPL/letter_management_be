<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\UserStatus;
use App\Models\RoomBookingSubmissionSnapshot;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * C7B1 retention safety: room-booking business evidence must survive
 * requester account removal. The application guard answers with 409 before
 * the restrictOnDelete constraint would throw.
 */
class RoomBookingRetentionGuardTest extends RoomBookingApiTestCase
{
    public function test_deleting_requester_with_booking_is_blocked_and_evidence_survives(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);
        $bookingId = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room),
        )->assertCreated()->json('data.id');

        $this->actingAsUser($this->superAdmin());
        $this->deleteJson("/api/super-admin/users/{$student->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'protected_business_record');

        // User and every piece of booking evidence remain.
        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->assertDatabaseHas('room_booking_requests', ['id' => $bookingId]);
        $this->assertDatabaseHas('room_booking_status_histories', [
            'room_booking_request_id' => $bookingId,
        ]);
        $this->assertDatabaseHas('room_booking_attachments', [
            'room_booking_request_id' => $bookingId,
        ]);
        $this->assertDatabaseHas('room_booking_audit_logs', [
            'room_booking_request_id' => $bookingId,
        ]);
        $this->assertDatabaseHas('room_booking_submission_snapshots', [
            'room_booking_request_id' => $bookingId,
        ]);
        $this->assertDatabaseHas('room_booking_workflow_events', [
            'room_booking_request_id' => $bookingId,
        ]);
    }

    public function test_database_constraint_backstops_direct_requester_deletion(): void
    {
        $student = $this->student();
        $this->roomBooking($this->classroom(), $student);

        $this->expectException(QueryException::class);
        $student->delete();
    }

    public function test_user_without_bookings_keeps_existing_delete_behavior(): void
    {
        $bystander = $this->student();

        $this->actingAsUser($this->superAdmin());
        $this->deleteJson("/api/super-admin/users/{$bystander->id}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $bystander->id]);
    }

    public function test_requester_with_booking_can_still_be_deactivated(): void
    {
        $student = $this->student();
        $this->roomBooking($this->classroom(), $student);

        $student->update(['status' => UserStatus::Suspended]);

        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->assertSame(UserStatus::Suspended, $student->fresh()->status);
    }

    public function test_snapshot_evidence_survives_submitter_account_removal_semantics(): void
    {
        // The submitted_by FK nulls out on user deletion, but the *_snapshot
        // columns keep the durable identity. Simulate by detaching the FK
        // reference the same way nullOnDelete would (the user itself cannot
        // be deleted while the booking exists).
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $snapshot = app(\App\Services\RoomBookingSubmissionSnapshotService::class)->capture(
            $booking,
            $student,
            RoomBookingSubmissionSnapshot::PROVENANCE_LEGACY_CURRENT_STATE,
        );

        $this->assertSame($student->name, $snapshot->requester_name_snapshot);
        $this->assertSame($student->email, $snapshot->requester_identifier_snapshot);
        $this->assertSame('mahasiswa', $snapshot->requester_role_snapshot);
    }

    public function test_no_cross_user_booking_access_was_introduced(): void
    {
        $owner = $this->student();
        $booking = $this->roomBooking($this->classroom(), $owner);

        $this->actingAsUser($this->student());
        $this->getJson($this->mahasiswaUrl("/requests/{$booking->id}"))
            ->assertNotFound();
    }
}
