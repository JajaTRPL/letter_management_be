<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingLifecycleCapabilityResolver;
use App\Services\RoomBookingTransitionService;

/**
 * C7B1 lifecycle foundation: monotonic workflow_version, submission
 * iterations, derived effective statuses, the past-start approval guard,
 * and the server-authoritative capability projection.
 */
class RoomBookingLifecycleFoundationTest extends RoomBookingApiTestCase
{
    public function test_initial_submission_starts_at_version_one_iteration_one(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);

        $response = $this->post(
            $this->mahasiswaUrl('/requests'),
            $this->validBookingPayloadWithPdf($room),
        )->assertCreated();

        $response
            ->assertJsonPath('data.workflow_version', 1)
            ->assertJsonPath('data.submission_iteration', 1)
            ->assertJsonPath('data.effective_status', 'submitted')
            ->assertJsonPath('data.is_expired', false)
            ->assertJsonPath('data.is_completed', false)
            ->assertJsonPath('data.capabilities.can_cancel', true)
            ->assertJsonPath('data.capabilities.can_edit', false)
            ->assertJsonPath('data.capabilities.can_approve', false);

        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $response->json('data.id'),
            'workflow_version' => 1,
            'submission_iteration' => 1,
        ]);
    }

    public function test_each_authoritative_mutation_increments_version_exactly_once(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $reviewer = $this->reviewerUser('sarpras');
        $service = app(RoomBookingTransitionService::class);

        $booking = new RoomBookingRequest([
            'requester_id' => $student->id,
            'room_id' => $room->id,
            'activity_name' => 'Version Ladder',
            'purpose' => 'Version accounting test.',
            'participant_count' => 5,
            'start_at' => '2026-06-20T10:00:00+07:00',
            'end_at' => '2026-06-20T12:00:00+07:00',
        ]);
        $booking = $service->submit($booking, $student);
        $this->assertSame(1, $booking->workflow_version);

        $booking = $service->requestRevision($booking, $reviewer, 'Perbaiki tujuan.');
        $this->assertSame(2, $booking->workflow_version);

        $booking = $service->submit($booking, $student);
        $this->assertSame(3, $booking->workflow_version);
        $this->assertSame(2, $booking->submission_iteration);

        $booking = $service->approve($booking, $reviewer);
        $this->assertSame(4, $booking->workflow_version);

        try {
            $service->cancel($booking, $student, 'Kegiatan dibatalkan panitia.');
            $this->fail('Approved booking must use reviewed cancellation.');
        } catch (RoomBookingDomainException $exception) {
            $this->assertSame(
                RoomBookingDomainException::REQUIRES_CANCELLATION_REVIEW,
                $exception->reason,
            );
        }
        $this->assertSame(4, $booking->fresh()->workflow_version);
    }

    public function test_reject_increments_version_once(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $reviewer = $this->reviewerUser('sarpras');

        $rejected = app(RoomBookingTransitionService::class)
            ->reject($booking, $reviewer, 'Ruangan dipakai kegiatan fakultas.');

        $this->assertSame(2, $rejected->workflow_version);
    }

    public function test_failed_mutation_does_not_increment_version_or_append_event(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::Approved,
        );
        $reviewer = $this->reviewerUser('sarpras');

        try {
            app(RoomBookingTransitionService::class)
                ->requestRevision($booking, $reviewer, 'Terlambat.');
            $this->fail('Expected invalid transition.');
        } catch (RoomBookingDomainException $exception) {
            $this->assertSame(RoomBookingDomainException::INVALID_TRANSITION, $exception->reason);
        }

        $this->assertSame(1, $booking->fresh()->workflow_version);
        $this->assertSame(0, RoomBookingWorkflowEvent::query()
            ->where('room_booking_request_id', $booking->id)
            ->count());
    }

    public function test_in_revision_form_edit_does_not_touch_version_or_iteration(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $booking = $this->roomBooking(
            $room,
            $student,
            RoomBookingStatus::RevisionRequested,
            attributes: ['workflow_version' => 2],
        );
        $this->actingAsUser($student);

        $this->putJson(
            $this->mahasiswaUrl("/requests/{$booking->id}"),
            $this->validBookingPayload($room, ['purpose' => 'Tujuan yang sudah diperbaiki.']),
        )->assertOk();

        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $booking->id,
            'workflow_version' => 2,
            'submission_iteration' => 1,
        ]);
    }

    public function test_client_payload_cannot_set_lifecycle_fields(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $this->actingAsUser($student);

        // Tampered create payload: server-owned fields are ignored.
        $response = $this->post(
            $this->mahasiswaUrl('/requests'),
            array_merge($this->validBookingPayloadWithPdf($room), [
                'workflow_version' => 99,
                'submission_iteration' => 7,
            ]),
        )->assertCreated();

        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $response->json('data.id'),
            'workflow_version' => 1,
            'submission_iteration' => 1,
        ]);

        // Tampered update payload on a revision booking: fields untouched.
        $revisionBooking = $this->roomBooking(
            $room,
            $student,
            RoomBookingStatus::RevisionRequested,
            startAt: '2026-06-22 10:00:00',
            endAt: '2026-06-22 12:00:00',
            attributes: ['workflow_version' => 2],
        );

        $this->putJson(
            $this->mahasiswaUrl("/requests/{$revisionBooking->id}"),
            array_merge(
                $this->validBookingPayload($room, [
                    'start_at' => '2026-06-22T10:00:00+07:00',
                    'end_at' => '2026-06-22T12:00:00+07:00',
                ]),
                ['workflow_version' => 55, 'submission_iteration' => 9],
            ),
        )->assertOk();

        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $revisionBooking->id,
            'workflow_version' => 2,
            'submission_iteration' => 1,
        ]);

        // Model-level guard: mass assignment drops the fields entirely.
        $model = new RoomBookingRequest;
        $model->fill(['workflow_version' => 9, 'submission_iteration' => 9]);
        $this->assertNull($model->workflow_version);
        $this->assertNull($model->submission_iteration);
    }

    public function test_trusted_service_still_owns_lifecycle_fields_after_guarding(): void
    {
        // The forceFill path: revision + resubmit still move version and
        // iteration exactly as before the mass-assignment hardening.
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $reviewer = $this->reviewerUser('sarpras');
        $service = app(RoomBookingTransitionService::class);

        $booking = $service->requestRevision($booking, $reviewer, 'Perbaiki jadwal.');
        $this->assertSame(2, $booking->workflow_version);
        $this->assertSame(1, $booking->submission_iteration);

        $booking = $service->submit($booking, $student);
        $this->assertSame(3, $booking->workflow_version);
        $this->assertSame(2, $booking->submission_iteration);
    }

    public function test_expired_revision_booking_does_not_advertise_resubmit(): void
    {
        $student = $this->student();
        $expiredRevision = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::RevisionRequested,
            startAt: '2026-06-17 08:00:00',
            endAt: '2026-06-17 10:00:00',
        );
        $this->actingAsUser($student);

        $updatedAtBefore = $expiredRevision->fresh()->updated_at;

        // Capability parity: resubmit is not advertised, editing stays
        // available so the applicant can move the schedule forward first.
        $this->getJson($this->mahasiswaUrl("/requests/{$expiredRevision->id}"))
            ->assertOk()
            ->assertJsonPath('data.is_expired', true)
            ->assertJsonPath('data.capabilities.can_resubmit', false)
            ->assertJsonPath('data.capabilities.can_edit', true);

        // The endpoint the capability mirrors keeps rejecting.
        $this->patchJson($this->mahasiswaUrl("/requests/{$expiredRevision->id}/submit"))
            ->assertUnprocessable();

        // Capability calculation mutated nothing.
        $fresh = $expiredRevision->fresh();
        $this->assertSame(RoomBookingStatus::RevisionRequested, $fresh->status);
        $this->assertSame(1, $fresh->workflow_version);
        $this->assertTrue($updatedAtBefore->equalTo($fresh->updated_at));

        // Non-expired revision keeps advertising resubmit (parity intact).
        $futureRevision = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::RevisionRequested,
        );
        $this->getJson($this->mahasiswaUrl("/requests/{$futureRevision->id}"))
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_resubmit', true);
    }

    public function test_approval_is_blocked_at_the_exact_start_boundary(): void
    {
        // setUp freezes the authoritative clock at 2026-06-18 09:00:00 WIB.
        $reviewer = $this->reviewerUser('sarpras');
        $this->actingAsUser($reviewer);

        // start_at == now → blocked.
        $atBoundary = $this->roomBooking(
            $this->classroom(),
            $this->student(),
            startAt: '2026-06-18 09:00:00',
            endAt: '2026-06-18 11:00:00',
        );
        $this->patchJson($this->reviewerUrl("/{$atBoundary->id}/approve"))
            ->assertConflict()
            ->assertJsonPath('code', RoomBookingDomainException::BOOKING_START_PASSED);

        $freshBoundary = $atBoundary->fresh();
        $this->assertSame(RoomBookingStatus::Submitted, $freshBoundary->status);
        $this->assertSame(1, $freshBoundary->workflow_version);
        $this->assertDatabaseMissing('room_booking_status_histories', [
            'room_booking_request_id' => $atBoundary->id,
            'to_status' => RoomBookingStatus::Approved->value,
        ]);
        $this->assertDatabaseMissing('room_booking_workflow_events', [
            'room_booking_request_id' => $atBoundary->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED,
        ]);

        // start_at one second in the past → blocked.
        $justPast = $this->roomBooking(
            $this->classroom(),
            $this->student(),
            startAt: '2026-06-18 08:59:59',
            endAt: '2026-06-18 11:00:00',
        );
        $this->patchJson($this->reviewerUrl("/{$justPast->id}/approve"))
            ->assertConflict()
            ->assertJsonPath('code', RoomBookingDomainException::BOOKING_START_PASSED);

        // start_at one second in the future → approvable.
        $justFuture = $this->roomBooking(
            $this->classroom(),
            $this->student(),
            startAt: '2026-06-18 09:00:01',
            endAt: '2026-06-18 11:00:00',
        );
        $this->patchJson($this->reviewerUrl("/{$justFuture->id}/approve"))
            ->assertOk()
            ->assertJsonPath('data.status', RoomBookingStatus::Approved->value);
    }

    public function test_approval_is_blocked_after_activity_start(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            startAt: '2026-06-17 08:00:00',
            endAt: '2026-06-17 10:00:00',
        );
        $reviewer = $this->reviewerUser('sarpras');
        $this->actingAsUser($reviewer);

        $this->patchJson($this->reviewerUrl("/{$booking->id}/approve"))
            ->assertConflict()
            ->assertJsonPath('code', RoomBookingDomainException::BOOKING_START_PASSED);

        $fresh = $booking->fresh();
        $this->assertSame(RoomBookingStatus::Submitted, $fresh->status);
        $this->assertSame(1, $fresh->workflow_version);
        $this->assertDatabaseMissing('room_booking_status_histories', [
            'room_booking_request_id' => $booking->id,
            'to_status' => RoomBookingStatus::Approved->value,
        ]);
        $this->assertDatabaseMissing('room_booking_workflow_events', [
            'room_booking_request_id' => $booking->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED,
        ]);
    }

    public function test_approval_before_start_still_succeeds(): void
    {
        $booking = $this->roomBooking($this->classroom(), $this->student());
        $this->actingAsUser($this->reviewerUser('sarpras'));

        $this->patchJson($this->reviewerUrl("/{$booking->id}/approve"))
            ->assertOk()
            ->assertJsonPath('data.status', RoomBookingStatus::Approved->value)
            ->assertJsonPath('data.workflow_version', 2);
    }

    public function test_effective_status_is_derived_not_stored(): void
    {
        $student = $this->student();

        $expired = $this->roomBooking(
            $this->classroom(),
            $student,
            startAt: '2026-06-17 08:00:00',
            endAt: '2026-06-17 10:00:00',
        );
        $completed = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::Approved,
            startAt: '2026-06-16 08:00:00',
            endAt: '2026-06-16 10:00:00',
        );

        $this->assertTrue($expired->isExpired());
        $this->assertSame('expired', $expired->effectiveStatus());
        $this->assertTrue($completed->isCompleted());
        $this->assertSame('completed', $completed->effectiveStatus());

        // Stored status column is untouched by the projections.
        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $expired->id,
            'status' => RoomBookingStatus::Submitted->value,
        ]);
        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $completed->id,
            'status' => RoomBookingStatus::Approved->value,
        ]);

        $this->actingAsUser($student);
        $this->getJson($this->mahasiswaUrl("/requests/{$expired->id}"))
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.effective_status', 'expired')
            ->assertJsonPath('data.is_expired', true);
    }

    public function test_capability_matrix_follows_role_scope_and_state(): void
    {
        $resolver = app(RoomBookingLifecycleCapabilityResolver::class);
        $student = $this->student();
        $laboratory = $this->bookingLaboratory();
        $otherLaboratory = $this->bookingLaboratory('02');
        $classroomBooking = $this->roomBooking($this->classroom(), $student);
        $labBooking = $this->roomBooking($this->laboratoryRoom($laboratory), $student);

        // Owner on a submitted booking: legacy cancel only.
        $owner = $resolver->capabilitiesFor($student, $classroomBooking);
        $this->assertTrue($owner['can_cancel']);
        $this->assertTrue($owner['can_view_attachment']);
        $this->assertFalse($owner['can_edit']);
        $this->assertFalse($owner['can_approve']);

        // Another student: nothing.
        $stranger = $resolver->capabilitiesFor($this->student(), $classroomBooking);
        $this->assertSame([], array_keys(array_filter($stranger)));

        // Sarpras: classroom only.
        $sarpras = $this->reviewerUser('sarpras');
        $this->assertTrue($resolver->capabilitiesFor($sarpras, $classroomBooking)['can_approve']);
        $this->assertFalse($resolver->capabilitiesFor($sarpras, $labBooking)['can_approve']);
        $this->assertFalse($resolver->capabilitiesFor($sarpras, $labBooking)['can_view_attachment']);

        // Kepala Lab: own laboratory only.
        $kalab = $this->reviewerUser('kepala_lab', $laboratory);
        $otherKalab = $this->reviewerUser('kepala_lab', $otherLaboratory);
        $this->assertTrue($resolver->capabilitiesFor($kalab, $labBooking)['can_approve']);
        $this->assertFalse($resolver->capabilitiesFor($otherKalab, $labBooking)['can_approve']);
        $this->assertFalse($resolver->capabilitiesFor($kalab, $classroomBooking)['can_approve']);

        // Laboran: read-only (attachment in own lab scope, no decisions).
        $laboran = $this->reviewerUser('laboran', $laboratory);
        $laboranCaps = $resolver->capabilitiesFor($laboran, $labBooking);
        $this->assertTrue($laboranCaps['can_view_attachment']);
        $this->assertFalse($laboranCaps['can_review']);
        $this->assertFalse($laboranCaps['can_approve']);
        $this->assertFalse($laboranCaps['can_request_revision']);
        $this->assertFalse($laboranCaps['can_reject']);

        // Persuratan: no booking capabilities at all.
        $this->assertSame([], array_keys(array_filter(
            $resolver->capabilitiesFor($this->persuratan(), $classroomBooking),
        )));

        // SuperAdmin: monitoring-only — may view attachment, mutates nothing.
        $adminCaps = $resolver->capabilitiesFor($this->superAdmin(), $classroomBooking);
        $this->assertTrue($adminCaps['can_view_attachment']);
        $this->assertFalse($adminCaps['can_approve']);
        $this->assertFalse($adminCaps['can_cancel']);
        $this->assertFalse($adminCaps['can_reject']);
    }

    public function test_expired_and_completed_capability_projection(): void
    {
        $student = $this->student();
        $reviewer = $this->reviewerUser('sarpras');
        $resolver = app(RoomBookingLifecycleCapabilityResolver::class);

        $expired = $this->roomBooking(
            $this->classroom(),
            $student,
            startAt: '2026-06-17 08:00:00',
            endAt: '2026-06-17 10:00:00',
        );
        $reviewerCaps = $resolver->capabilitiesFor($reviewer, $expired);
        $this->assertFalse($reviewerCaps['can_approve']);
        // Reviewers may still close the loop on an expired pending request.
        $this->assertTrue($reviewerCaps['can_reject']);
        $this->assertFalse($reviewerCaps['can_request_revision']);

        $completed = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::Approved,
            startAt: '2026-06-16 08:00:00',
            endAt: '2026-06-16 10:00:00',
        );
        $ownerCaps = $resolver->capabilitiesFor($student, $completed);
        $reviewerCompletedCaps = $resolver->capabilitiesFor($reviewer, $completed);
        foreach (['can_edit', 'can_resubmit', 'can_cancel', 'can_review', 'can_approve', 'can_request_revision', 'can_reject'] as $capability) {
            $this->assertFalse($ownerCaps[$capability], "owner {$capability}");
            $this->assertFalse($reviewerCompletedCaps[$capability], "reviewer {$capability}");
        }
        $this->assertTrue($ownerCaps['can_view_attachment']);
    }

    public function test_revision_owner_capabilities_enable_edit_and_resubmit(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::RevisionRequested,
        );
        $this->actingAsUser($student);

        $this->getJson($this->mahasiswaUrl("/requests/{$booking->id}"))
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_edit', true)
            ->assertJsonPath('data.capabilities.can_resubmit', true)
            ->assertJsonPath('data.capabilities.can_cancel', false)
            ->assertJsonPath('data.capabilities.can_request_cancellation', true)
            ->assertJsonPath('data.capabilities.can_approve', false);
    }
}
