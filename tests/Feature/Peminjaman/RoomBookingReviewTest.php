<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingWorkflowEvent;

class RoomBookingReviewTest extends RoomBookingApiTestCase
{
    public function test_start_review_requires_expected_workflow_version(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $this->actingAsUser($this->reviewerUser('sarpras'));

        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), [
            'idempotency_key' => 'review-missing-version',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_workflow_version');
    }

    public function test_sarpras_starts_non_exclusive_classroom_review_once(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $sarpras = $this->reviewerUser('sarpras');
        $this->actingAsUser($sarpras);

        $this->getJson($this->reviewerUrl("/{$booking->id}"))
            ->assertOk();
        $this->assertNull($booking->fresh()->review_started_at);
        $this->assertDatabaseCount('room_booking_workflow_events', 0);

        $payload = [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'review-start-0001',
        ];
        $first = $this->patchJson(
            $this->reviewerUrl("/{$booking->id}/start-review"),
            $payload,
        )->assertOk()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.stored_status', RoomBookingStatus::Submitted->value)
            ->assertJsonPath('data.effective_status', 'under_review')
            ->assertJsonPath('data.workflow_version', 2)
            ->assertJsonPath('data.booking.review_started_at', fn ($value) => is_string($value));

        $correlationId = $first->json('data.correlation_id');
        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $booking->id,
            'status' => RoomBookingStatus::Submitted->value,
            'workflow_version' => 2,
            'review_started_by' => $sarpras->id,
        ]);
        $this->assertDatabaseHas('room_booking_workflow_events', [
            'room_booking_request_id' => $booking->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_REVIEW_STARTED,
            'workflow_version_before' => 1,
            'workflow_version_after' => 2,
            'correlation_id' => $correlationId,
        ]);
        $this->assertDatabaseCount('room_booking_status_histories', 0);

        $this->patchJson(
            $this->reviewerUrl("/{$booking->id}/start-review"),
            $payload,
        )->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.workflow_version', 2)
            ->assertJsonPath('data.correlation_id', $correlationId);

        $this->assertSame(1, RoomBookingWorkflowEvent::query()
            ->where('room_booking_request_id', $booking->id)
            ->count());
    }

    public function test_new_attempt_after_review_started_returns_conflict(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $this->actingAsUser($this->reviewerUser('sarpras'));

        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'review-start-0002',
        ])->assertOk();

        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), [
            'expected_workflow_version' => 2,
            'idempotency_key' => 'review-start-0003',
        ])->assertConflict()
            ->assertJsonPath('code', 'review_already_started')
            ->assertJsonPath('data.workflow_version', 2);
    }

    public function test_kepala_lab_may_start_own_lab_review_but_other_roles_cannot(): void
    {
        $lab = $this->bookingLaboratory();
        $booking = $this->roomBooking($this->laboratoryRoom($lab));
        $kalab = $this->reviewerUser('kepala_lab', $lab);
        $this->actingAsUser($kalab);

        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'review-lab-own-01',
        ])->assertOk();

        $otherLab = $this->bookingLaboratory('02');
        $otherBooking = $this->roomBooking($this->laboratoryRoom($otherLab));
        $this->patchJson($this->reviewerUrl("/{$otherBooking->id}/start-review"), [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'review-lab-other',
        ])->assertNotFound();

        $laboran = $this->reviewerUser('laboran', $otherLab);
        $this->actingAsUser($laboran);
        $this->patchJson($this->reviewerUrl("/{$otherBooking->id}/start-review"), [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'review-laboran-1',
        ])->assertNotFound();
    }

    public function test_expired_booking_cannot_start_review(): void
    {
        $booking = $this->roomBooking(
            $this->classroom(),
            startAt: '2026-06-18 09:00:00',
            endAt: '2026-06-18 10:00:00',
        );
        $this->actingAsUser($this->reviewerUser('sarpras'));

        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'review-expired-01',
        ])->assertConflict()
            ->assertJsonPath('code', 'booking_expired');

        $this->assertSame(1, $booking->fresh()->workflow_version);
        $this->assertDatabaseCount('room_booking_workflow_events', 0);
    }

    public function test_review_marker_is_non_exclusive_and_another_authorized_reviewer_may_decide(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->patchJson($this->reviewerUrl("/{$booking->id}/start-review"), [
            'expected_workflow_version' => 1,
            'idempotency_key' => 'non-exclusive-review-1',
        ])->assertOk();

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->patchJson($this->reviewerUrl("/{$booking->id}/approve"), [
            'expected_workflow_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.workflow_version', 3);

        $this->assertSame(1, RoomBookingWorkflowEvent::query()
            ->where('room_booking_request_id', $booking->id)
            ->where('event_type', RoomBookingWorkflowEvent::EVENT_REVIEW_STARTED)
            ->count());
        $this->assertSame(1, RoomBookingWorkflowEvent::query()
            ->where('room_booking_request_id', $booking->id)
            ->where('event_type', RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED)
            ->count());
    }
}
