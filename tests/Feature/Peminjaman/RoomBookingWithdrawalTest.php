<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingStatusHistory;
use App\Models\RoomBookingSubmissionSnapshot;
use App\Models\RoomBookingWorkflowEvent;
use App\Services\RoomBookingSubmissionSnapshotService;

class RoomBookingWithdrawalTest extends RoomBookingApiTestCase
{
    public function test_direct_withdrawal_requires_expected_workflow_version(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $this->actingAsUser($student);

        $this->postJson($this->mahasiswaUrl("/requests/{$booking->id}/withdraw"), [
            'reason' => 'Missing version.',
            'idempotency_key' => 'withdraw-missing-version',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_workflow_version');
    }

    public function test_exact_twenty_four_hour_boundary_allows_direct_withdrawal_and_preserves_evidence(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            startAt: '2026-06-19 09:00:00',
            endAt: '2026-06-19 11:00:00',
        );
        $attachment = $this->createSuratPeminjamanAttachment($booking, $student);
        app(RoomBookingSubmissionSnapshotService::class)->capture(
            $booking,
            $student,
            RoomBookingSubmissionSnapshot::PROVENANCE_NATIVE_SUBMISSION,
        );
        RoomBookingStatusHistory::create([
            'room_booking_request_id' => $booking->id,
            'from_status' => null,
            'to_status' => RoomBookingStatus::Submitted,
            'actor_id' => $student->id,
        ]);
        $this->actingAsUser($student);

        $payload = [
            'reason' => 'Kegiatan dibatalkan sebelum diproses.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'withdraw-boundary-001',
        ];
        $response = $this->postJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/withdraw"),
            $payload,
        )->assertOk()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.stored_status', 'cancelled')
            ->assertJsonPath('data.workflow_version', 2)
            ->assertJsonPath('data.booking.cancellation_source', 'requester_withdrawal');

        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $booking->id,
            'status' => RoomBookingStatus::Cancelled->value,
            'workflow_version' => 2,
            'cancellation_source' => 'requester_withdrawal',
            'cancelled_by_role_snapshot' => 'mahasiswa',
        ]);
        $this->assertDatabaseHas('room_booking_workflow_events', [
            'room_booking_request_id' => $booking->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_BOOKING_WITHDRAWN,
            'workflow_version_before' => 1,
            'workflow_version_after' => 2,
        ]);
        $this->assertDatabaseMissing('room_booking_workflow_events', [
            'room_booking_request_id' => $booking->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_BOOKING_CANCELLED,
        ]);
        $this->assertDatabaseHas('room_booking_attachments', ['id' => $attachment->id]);
        $this->assertDatabaseCount('room_booking_submission_snapshots', 1);
        $this->assertDatabaseCount('room_booking_status_histories', 2);

        $this->postJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/withdraw"),
            $payload,
        )->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.correlation_id', $response->json('data.correlation_id'));

        $this->assertSame(2, $booking->fresh()->workflow_version);
        $this->assertSame(1, RoomBookingWorkflowEvent::query()
            ->where('room_booking_request_id', $booking->id)
            ->where('event_type', RoomBookingWorkflowEvent::EVENT_BOOKING_WITHDRAWN)
            ->count());
    }

    public function test_one_second_inside_cutoff_is_denied_and_offers_reviewed_cancellation(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            startAt: '2026-06-19 08:59:59',
            endAt: '2026-06-19 10:59:59',
        );
        $this->actingAsUser($student);

        $this->getJson($this->mahasiswaUrl("/requests/{$booking->id}"))
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_withdraw', false)
            ->assertJsonPath('data.capabilities.can_cancel', false)
            ->assertJsonPath('data.capabilities.can_request_cancellation', true)
            ->assertJsonPath('data.capabilities.withdrawal_block_reason', 'cutoff_passed');

        $this->postJson($this->mahasiswaUrl("/requests/{$booking->id}/withdraw"), [
            'reason' => 'Terlalu dekat dengan jadwal.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'withdraw-cutoff-001',
        ])->assertConflict()
            ->assertJsonPath('code', 'cutoff_passed')
            ->assertJsonPath('data.capabilities.can_request_cancellation', true);

        $this->assertSame(RoomBookingStatus::Submitted, $booking->fresh()->status);
        $this->assertDatabaseCount('room_booking_workflow_events', 0);
    }

    public function test_review_start_revision_history_and_approved_state_deny_direct_withdrawal(): void
    {
        $student = $this->student();
        $this->actingAsUser($student);

        $reviewed = $this->roomBooking($this->classroom(), $student);
        $reviewed->forceFill([
            'review_started_at' => now(),
            'review_started_by' => $this->reviewerUser('sarpras')->id,
        ])->save();
        $this->postJson($this->mahasiswaUrl("/requests/{$reviewed->id}/withdraw"), [
            'reason' => 'Tidak jadi.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'withdraw-reviewed-01',
        ])->assertConflict()->assertJsonPath('code', 'review_already_started');

        $revised = $this->roomBooking($this->classroom(), $student);
        RoomBookingStatusHistory::create([
            'room_booking_request_id' => $revised->id,
            'from_status' => RoomBookingStatus::Submitted,
            'to_status' => RoomBookingStatus::RevisionRequested,
            'actor_id' => $this->reviewerUser('sarpras')->id,
        ]);
        $this->postJson($this->mahasiswaUrl("/requests/{$revised->id}/withdraw"), [
            'reason' => 'Tidak jadi.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'withdraw-revised-001',
        ])->assertConflict()->assertJsonPath('code', 'revision_already_requested');

        $approved = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::Approved,
        );
        $this->postJson($this->mahasiswaUrl("/requests/{$approved->id}/withdraw"), [
            'reason' => 'Tidak jadi.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'withdraw-approved-01',
        ])->assertConflict()->assertJsonPath('code', 'requires_cancellation_review');
    }

    public function test_owner_and_expected_version_are_enforced_and_changed_replay_payload_conflicts(): void
    {
        $owner = $this->student();
        $booking = $this->roomBooking($this->classroom(), $owner);
        $this->actingAsUser($this->student());
        $this->postJson($this->mahasiswaUrl("/requests/{$booking->id}/withdraw"), [
            'reason' => 'Forged.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'withdraw-forged-001',
        ])->assertNotFound();

        $this->actingAsUser($owner);
        $this->postJson($this->mahasiswaUrl("/requests/{$booking->id}/withdraw"), [
            'reason' => 'Stale.',
            'expected_workflow_version' => 99,
            'idempotency_key' => 'withdraw-stale-0001',
        ])->assertConflict()->assertJsonPath('code', 'stale_workflow_version');

        $key = 'withdraw-replay-change';
        $this->postJson($this->mahasiswaUrl("/requests/{$booking->id}/withdraw"), [
            'reason' => 'Original reason.',
            'expected_workflow_version' => 1,
            'idempotency_key' => $key,
        ])->assertOk();
        $this->postJson($this->mahasiswaUrl("/requests/{$booking->id}/withdraw"), [
            'reason' => 'Changed reason.',
            'expected_workflow_version' => 1,
            'idempotency_key' => $key,
        ])->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');
    }

    public function test_direct_withdrawal_uses_direct_policy_and_never_creates_review_request(): void
    {
        $student = $this->student();
        $this->actingAsUser($student);
        $eligible = $this->roomBooking($this->classroom(), $student);

        $this->postJson($this->mahasiswaUrl("/requests/{$eligible->id}/withdraw"), [
            'reason' => 'Direct withdrawal still eligible.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'withdraw-direct-eligible',
        ])->assertOk()
            ->assertJsonPath('data.effective_status', 'cancelled')
            ->assertJsonPath('data.booking.status', 'cancelled');
        $this->assertDatabaseHas('room_booking_workflow_events', [
            'room_booking_request_id' => $eligible->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_BOOKING_WITHDRAWN,
        ]);

        $approved = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::Approved,
        );
        $this->postJson($this->mahasiswaUrl("/requests/{$approved->id}/withdraw"), [
            'reason' => 'Must be reviewed.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'withdraw-approved-review',
        ])->assertConflict()
            ->assertJsonPath('code', 'requires_cancellation_review')
            ->assertJsonPath('data.capabilities.can_request_cancellation', true);

        $this->assertDatabaseCount('room_booking_cancellation_requests', 0);
    }

    public function test_reason_is_required_and_cutoff_is_configuration_driven(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $this->actingAsUser($student);

        $this->postJson($this->mahasiswaUrl("/requests/{$booking->id}/withdraw"), [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'withdraw-no-reason',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        config(['room_booking.self_withdrawal_cutoff_hours' => 72]);
        $this->postJson($this->mahasiswaUrl("/requests/{$booking->id}/withdraw"), [
            'reason' => 'Cutoff from config should block this.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'withdraw-config-cutoff',
        ])->assertConflict()->assertJsonPath('code', 'cutoff_passed');
    }
}
