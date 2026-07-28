<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingCancellationStatus;
use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingCancellationRequest;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class RoomBookingCancellationTest extends RoomBookingApiTestCase
{
    public function test_reviewed_submitted_booking_creates_one_pending_request_without_releasing_status(): void
    {
        $student = $this->student();
        $reviewer = $this->reviewerUser('sarpras');
        $booking = $this->roomBooking($this->classroom(), $student);
        $booking->forceFill([
            'review_started_at' => now(),
            'review_started_by' => $reviewer->id,
        ])->save();
        $this->actingAsUser($student);

        $payload = [
            'reason' => 'Kegiatan tidak dapat dilaksanakan.',
            'expected_workflow_version' => 1,
            'idempotency_key' => 'cancel-request-reviewed-01',
        ];
        $first = $this->postJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/cancellation-requests"),
            $payload,
        )->assertCreated()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.stored_status', 'submitted')
            ->assertJsonPath('data.workflow_version', 2)
            ->assertJsonPath('data.cancellation_pending', true)
            ->assertJsonPath('data.cancellation_request.status', 'pending')
            ->assertJsonPath(
                'data.cancellation_request.available_applicant_action',
                'withdraw_cancellation_request',
            );

        $requestId = $first->json('data.cancellation_request.id');
        $this->assertDatabaseHas('room_booking_cancellation_requests', [
            'id' => $requestId,
            'room_booking_request_id' => $booking->id,
            'status' => RoomBookingCancellationStatus::Pending->value,
            'booking_status_snapshot' => RoomBookingStatus::Submitted->value,
            'booking_workflow_version_at_request' => 1,
            'active_pending_guard' => true,
        ]);
        $this->assertDatabaseHas('room_booking_workflow_events', [
            'room_booking_request_id' => $booking->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUESTED,
            'workflow_version_before' => 1,
            'workflow_version_after' => 2,
        ]);
        $this->assertDatabaseCount('room_booking_status_histories', 0);

        $this->actingAsUser($student);
        $this->postJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/cancellation-requests"),
            $payload,
        )->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertDatabaseCount('room_booking_cancellation_requests', 1);

        $this->postJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/cancellation-requests"),
            [
                'reason' => 'Permintaan kedua.',
                'expected_workflow_version' => 2,
                'idempotency_key' => 'cancel-request-duplicate',
            ],
        )->assertConflict()->assertJsonPath('code', 'pending_cancellation_request');
    }

    public function test_under_cutoff_revision_and_approved_bookings_can_request_cancellation(): void
    {
        $student = $this->student();
        $this->actingAsUser($student);
        $cases = [
            $this->roomBooking(
                $this->classroom(),
                $student,
                startAt: '2026-06-19 08:59:59',
                endAt: '2026-06-19 10:59:59',
            ),
            $this->roomBooking(
                $this->classroom(),
                $student,
                RoomBookingStatus::RevisionRequested,
            ),
            $this->roomBooking(
                $this->classroom(),
                $student,
                RoomBookingStatus::Approved,
            ),
        ];

        foreach ($cases as $index => $booking) {
            $this->postJson(
                $this->mahasiswaUrl("/requests/{$booking->id}/cancellation-requests"),
                [
                    'reason' => 'Perlu pembatalan melalui peninjau.',
                    'expected_workflow_version' => 1,
                    'idempotency_key' => "cancel-request-case-{$index}",
                ],
            )->assertCreated()
                ->assertJsonPath('data.stored_status', $booking->status->value)
                ->assertJsonPath('data.cancellation_pending', true);
        }
    }

    public function test_started_or_expired_booking_cannot_request_cancellation(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            startAt: '2026-06-18 09:00:00',
            endAt: '2026-06-18 11:00:00',
        );
        $this->actingAsUser($student);

        $this->postJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/cancellation-requests"),
            [
                'reason' => 'Sudah terlambat.',
                'expected_workflow_version' => 1,
                'idempotency_key' => 'cancel-request-expired-01',
            ],
        )->assertConflict()
            ->assertJsonPath('code', 'cancellation_request_not_allowed');
        $this->assertDatabaseCount('room_booking_cancellation_requests', 0);
    }

    public function test_revision_edit_is_blocked_while_cancellation_request_is_pending(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $booking = $this->roomBooking($room, $student, RoomBookingStatus::RevisionRequested);
        $this->createPendingRequest($student, $booking, 'edit-block-pending-01');
        $booking = $booking->fresh();
        $eventsBefore = RoomBookingWorkflowEvent::query()->count();

        $this->actingAsUser($student);
        $response = $this->putJson(
            $this->mahasiswaUrl("/requests/{$booking->id}"),
            $this->validBookingPayload($room, [
                'purpose' => 'Percobaan ubah saat pembatalan menunggu.',
            ]),
        );

        $response
            ->assertConflict()
            ->assertJsonPath('code', 'pending_cancellation_request')
            // Capability parity: the refreshed 409 state shows the same rule
            // the mutation enforced under lock.
            ->assertJsonPath('data.capabilities.can_edit', false)
            ->assertJsonPath('data.capabilities.can_resubmit', false);

        $fresh = $booking->fresh();
        $this->assertSame('Test-only booking purpose.', $fresh->purpose);
        $this->assertSame(2, $fresh->workflow_version);
        $this->assertSame(1, $fresh->submission_iteration);
        $this->assertSame($eventsBefore, RoomBookingWorkflowEvent::query()->count());
        $this->assertDatabaseCount('room_booking_submission_snapshots', 0);
        $this->assertDatabaseCount('room_booking_status_histories', 0);
    }

    public function test_revision_edit_orderings_are_deterministic(): void
    {
        // Edit first, then cancellation-request creation: both succeed.
        $student = $this->student();
        $room = $this->classroom();
        $editFirst = $this->roomBooking($room, $student, RoomBookingStatus::RevisionRequested);
        $this->actingAsUser($student);
        $this->putJson(
            $this->mahasiswaUrl("/requests/{$editFirst->id}"),
            $this->validBookingPayload($room, ['purpose' => 'Diubah sebelum permohonan.']),
        )->assertOk();
        $this->postJson(
            $this->mahasiswaUrl("/requests/{$editFirst->id}/cancellation-requests"),
            [
                'reason' => 'Dibatalkan setelah perbaikan.',
                'expected_workflow_version' => 1,
                'idempotency_key' => 'order-edit-first-01',
            ],
        )->assertCreated();

        // Cancellation-request creation first: the later edit loses with 409.
        $requestFirst = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::RevisionRequested,
        );
        $this->createPendingRequest($student, $requestFirst, 'order-request-first-01');
        $this->actingAsUser($student);
        $this->putJson(
            $this->mahasiswaUrl("/requests/{$requestFirst->id}"),
            $this->validBookingPayload($this->classroom(), ['purpose' => 'Kalah urutan.']),
        )->assertConflict()->assertJsonPath('code', 'pending_cancellation_request');
        $this->assertSame('Test-only booking purpose.', $requestFirst->fresh()->purpose);
    }

    public function test_revision_edit_enforces_optional_expected_workflow_version(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $booking = $this->roomBooking($room, $student, RoomBookingStatus::RevisionRequested);
        $this->actingAsUser($student);

        // Omitted version stays compatible.
        $this->putJson(
            $this->mahasiswaUrl("/requests/{$booking->id}"),
            $this->validBookingPayload($room, ['purpose' => 'Tanpa versi.']),
        )->assertOk();

        // Matching current version succeeds; editing never increments it.
        $this->putJson(
            $this->mahasiswaUrl("/requests/{$booking->id}"),
            $this->validBookingPayload($room, [
                'purpose' => 'Dengan versi saat ini.',
                'expected_workflow_version' => 1,
            ]),
        )->assertOk();
        $this->assertSame(1, $booking->fresh()->workflow_version);

        // Stale version is rejected without persisting anything.
        $this->putJson(
            $this->mahasiswaUrl("/requests/{$booking->id}"),
            $this->validBookingPayload($room, [
                'purpose' => 'Dengan versi basi.',
                'expected_workflow_version' => 99,
            ]),
        )->assertConflict()->assertJsonPath('code', 'stale_workflow_version');
        $this->assertSame('Dengan versi saat ini.', $booking->fresh()->purpose);
    }

    public function test_locked_revision_edit_rejects_stale_status_and_non_owner(): void
    {
        $student = $this->student();
        $room = $this->classroom();

        // The service guard (not only the controller fast-path) refuses a
        // booking whose status left revision_requested before the reread.
        $approved = $this->roomBooking($room, $student, RoomBookingStatus::Approved);
        try {
            app(\App\Services\RoomBookingTransitionService::class)->updateRevision(
                $approved,
                $student,
                ['purpose' => 'Tidak boleh tersimpan.'],
            );
            $this->fail('Expected invalid transition from the locked guard.');
        } catch (\App\Services\RoomBookingDomainException $exception) {
            $this->assertSame(
                \App\Services\RoomBookingDomainException::INVALID_TRANSITION,
                $exception->reason,
            );
        }
        $this->assertSame('Test-only booking purpose.', $approved->fresh()->purpose);

        // Object hiding for a different student remains 404.
        $revision = $this->roomBooking($room, $student, RoomBookingStatus::RevisionRequested);
        $this->actingAsUser($this->student());
        $this->putJson(
            $this->mahasiswaUrl("/requests/{$revision->id}"),
            $this->validBookingPayload($room, ['purpose' => 'Bukan milik saya.']),
        )->assertNotFound();
    }

    public function test_pending_request_withdrawal_after_start_reports_booking_start_passed(): void
    {
        $student = $this->student();
        // Inside the H-24 cutoff so the reviewed-cancellation path (not
        // direct withdrawal) is the one available to the applicant.
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            startAt: '2026-06-19 08:00:00',
            endAt: '2026-06-19 10:00:00',
        );
        $request = $this->createPendingRequest($student, $booking, 'withdraw-start-passed-01');
        $eventsBefore = RoomBookingWorkflowEvent::query()->count();
        $withdrawUrl = $this->mahasiswaUrl(
            "/requests/{$booking->id}/cancellation-requests/{$request->id}/withdraw",
        );

        // Exactly at activity start: truthful code, request stays pending.
        Carbon::setTestNow(Carbon::parse('2026-06-19 08:00:00', config('app.timezone')));
        $this->actingAsUser($student);
        $this->patchJson($withdrawUrl, [
            'expected_workflow_version' => 2,
            'idempotency_key' => 'withdraw-at-start-01',
        ])->assertConflict()
            ->assertJsonPath('code', 'booking_start_passed')
            ->assertJsonPath(
                'message',
                'Permohonan pembatalan tidak dapat ditarik karena waktu kegiatan sudah dimulai atau terlewati.',
            );

        // One hour after start: same truthful code.
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:00', config('app.timezone')));
        $this->patchJson($withdrawUrl, [
            'expected_workflow_version' => 2,
            'idempotency_key' => 'withdraw-after-start-01',
        ])->assertConflict()->assertJsonPath('code', 'booking_start_passed');

        $freshRequest = $request->fresh();
        $this->assertSame(RoomBookingCancellationStatus::Pending, $freshRequest->status);
        $this->assertTrue((bool) $freshRequest->active_pending_guard);
        $fresh = $booking->fresh();
        $this->assertSame(RoomBookingStatus::Submitted, $fresh->status);
        $this->assertSame(2, $fresh->workflow_version);
        $this->assertSame($eventsBefore, RoomBookingWorkflowEvent::query()->count());
        $this->assertDatabaseMissing('room_booking_workflow_events', [
            'room_booking_request_id' => $booking->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUEST_WITHDRAWN,
        ]);

        // A genuinely resolved request keeps the already-resolved code.
        $freshRequest->forceFill([
            'status' => RoomBookingCancellationStatus::Withdrawn,
            'active_pending_guard' => null,
            'decided_at' => now(config('app.timezone')),
        ])->save();
        $this->patchJson($withdrawUrl, [
            'expected_workflow_version' => 2,
            'idempotency_key' => 'withdraw-resolved-01',
        ])->assertConflict()
            ->assertJsonPath('code', 'cancellation_request_already_resolved');
    }

    public function test_applicant_withdraws_own_pending_cancellation_request(): void
    {
        [$student, $booking, $request] = $this->pendingApprovedCancellation();
        $this->actingAsUser($student);

        $this->patchJson(
            $this->mahasiswaUrl(
                "/requests/{$booking->id}/cancellation-requests/{$request->id}/withdraw",
            ),
            [
                'reason' => 'Kegiatan kembali dilaksanakan.',
                'expected_workflow_version' => 2,
                'idempotency_key' => 'cancel-request-withdraw-01',
            ],
        )->assertOk()
            ->assertJsonPath('data.stored_status', 'approved')
            ->assertJsonPath('data.workflow_version', 3)
            ->assertJsonPath('data.cancellation_request.status', 'withdrawn')
            ->assertJsonPath('data.cancellation_request.available_applicant_action', null)
            ->assertJsonPath('data.cancellation_pending', false);

        $this->assertDatabaseHas('room_booking_cancellation_requests', [
            'id' => $request->id,
            'status' => RoomBookingCancellationStatus::Withdrawn->value,
            'active_pending_guard' => null,
        ]);
        $this->assertDatabaseHas('room_booking_workflow_events', [
            'event_type' => RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUEST_WITHDRAWN,
            'workflow_version_before' => 2,
            'workflow_version_after' => 3,
        ]);
    }

    public function test_sarpras_approves_classroom_cancellation_and_releases_approved_status(): void
    {
        [, $booking, $request] = $this->pendingApprovedCancellation();
        $sarpras = $this->reviewerUser('sarpras');
        $this->actingAsUser($sarpras);

        $this->patchJson(
            "/api/tendik/peminjaman-ruangan/cancellation-requests/{$request->id}/approve",
            [
                'decision_note' => 'Disetujui karena kegiatan dibatalkan.',
                'expected_workflow_version' => 2,
                'idempotency_key' => 'cancel-decision-approve-01',
            ],
        )->assertOk()
            ->assertJsonPath('data.stored_status', 'cancelled')
            ->assertJsonPath('data.workflow_version', 3)
            ->assertJsonPath('data.cancellation_request.status', 'approved')
            ->assertJsonPath('data.booking.cancellation_source', 'request_approved');

        $this->assertDatabaseHas('room_booking_requests', [
            'id' => $booking->id,
            'status' => RoomBookingStatus::Cancelled->value,
            'workflow_version' => 3,
            'cancellation_source' => 'request_approved',
            'cancelled_by_role_snapshot' => 'sarpras',
        ]);
        $this->assertDatabaseHas('room_booking_status_histories', [
            'room_booking_request_id' => $booking->id,
            'from_status' => RoomBookingStatus::Approved->value,
            'to_status' => RoomBookingStatus::Cancelled->value,
        ]);
        $this->assertDatabaseHas('room_booking_workflow_events', [
            'room_booking_request_id' => $booking->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_CANCELLATION_APPROVED,
        ]);
        $this->assertDatabaseMissing('room_booking_workflow_events', [
            'room_booking_request_id' => $booking->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_BOOKING_CANCELLED,
        ]);
    }

    public function test_kepala_lab_rejects_own_lab_request_and_cross_scope_is_hidden(): void
    {
        $lab = $this->bookingLaboratory();
        $student = $this->student();
        $booking = $this->roomBooking(
            $this->laboratoryRoom($lab),
            $student,
            RoomBookingStatus::Approved,
        );
        $request = $this->createPendingRequest($student, $booking, 'cancel-lab-request-01');

        $otherLab = $this->bookingLaboratory('02');
        $this->actingAsUser($this->reviewerUser('kepala_lab', $otherLab));
        $this->patchJson(
            "/api/tendik/peminjaman-ruangan/cancellation-requests/{$request->id}/reject",
            [
                'decision_note' => 'Tidak berwenang.',
                'expected_workflow_version' => 2,
                'idempotency_key' => 'cancel-cross-lab-01',
            ],
        )->assertNotFound();

        $this->actingAsUser($this->reviewerUser('kepala_lab', $lab));
        $this->patchJson(
            "/api/tendik/peminjaman-ruangan/cancellation-requests/{$request->id}/reject",
            [
                'decision_note' => 'Jadwal laboratorium tetap digunakan.',
                'expected_workflow_version' => 2,
                'idempotency_key' => 'cancel-lab-reject-01',
            ],
        )->assertOk()
            ->assertJsonPath('data.stored_status', 'approved')
            ->assertJsonPath('data.workflow_version', 3)
            ->assertJsonPath('data.cancellation_request.status', 'rejected');

        $this->assertDatabaseMissing('room_booking_status_histories', [
            'room_booking_request_id' => $booking->id,
            'to_status' => RoomBookingStatus::Cancelled->value,
        ]);
        $this->assertDatabaseHas('room_booking_workflow_events', [
            'room_booking_request_id' => $booking->id,
            'event_type' => RoomBookingWorkflowEvent::EVENT_CANCELLATION_REJECTED,
        ]);
    }

    public function test_pending_request_blocks_normal_reviewer_and_legacy_mutations(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $booking->forceFill(['review_started_at' => now()])->save();
        $this->createPendingRequest($student, $booking, 'cancel-blockers-01');

        $this->actingAsUser($this->reviewerUser('sarpras'));
        foreach (['approve' => [], 'revise' => ['note' => 'Perbaiki.'], 'reject' => ['reason' => 'Tolak.']] as $action => $body) {
            $this->patchJson($this->reviewerUrl("/{$booking->id}/{$action}"), $body)
                ->assertConflict()
                ->assertJsonPath('code', 'pending_cancellation_request');
        }

        $this->actingAsUser($student);
        $this->postJson($this->mahasiswaUrl("/requests/{$booking->id}/withdraw"), [
            'reason' => 'Withdrawal attempt.',
            'expected_workflow_version' => (int) $booking->fresh()->workflow_version,
            'idempotency_key' => 'withdraw-pending-cancel',
        ])->assertConflict()->assertJsonPath('code', 'pending_cancellation_request');
    }

    public function test_expired_capabilities_and_reviewer_closure_follow_frozen_policy(): void
    {
        $student = $this->student();
        $submitted = $this->roomBooking(
            $this->classroom(),
            $student,
            startAt: '2026-06-18 08:00:00',
            endAt: '2026-06-18 08:30:00',
        );
        $revision = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::RevisionRequested,
            startAt: '2026-06-18 08:00:00',
            endAt: '2026-06-18 08:30:00',
        );
        $this->actingAsUser($student);
        $this->getJson($this->mahasiswaUrl("/requests/{$submitted->id}"))
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_edit', false)
            ->assertJsonPath('data.capabilities.can_withdraw', false)
            ->assertJsonPath('data.capabilities.can_request_cancellation', false);
        $this->getJson($this->mahasiswaUrl("/requests/{$revision->id}"))
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_edit', true)
            ->assertJsonPath('data.capabilities.can_resubmit', false)
            ->assertJsonPath('data.capabilities.can_withdraw', false);

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->getJson($this->reviewerUrl("/{$submitted->id}"))
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_approve', false)
            ->assertJsonPath('data.capabilities.can_request_revision', false)
            ->assertJsonPath('data.capabilities.can_reject', true);
        $this->patchJson($this->reviewerUrl("/{$submitted->id}/revise"), ['note' => 'Late.'])
            ->assertConflict()->assertJsonPath('code', 'booking_expired');
        $this->patchJson($this->reviewerUrl("/{$revision->id}/reject"), ['reason' => 'Ditutup administratif.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_decision_endpoint_denies_non_authoritative_roles_and_requires_reject_reason(): void
    {
        [, , $classroomRequest] = $this->pendingApprovedCancellation();
        $decisionUrl = "/api/tendik/peminjaman-ruangan/cancellation-requests/{$classroomRequest->id}/reject";

        $this->actingAsUser($this->reviewerUser('laboran', $this->bookingLaboratory()));
        $this->patchJson($decisionUrl, [
            'decision_note' => 'Forged.',
            'expected_workflow_version' => 2,
            'idempotency_key' => 'deny-laboran-decision',
        ])->assertNotFound();

        $this->actingAsUser($this->persuratan());
        $this->patchJson($decisionUrl, [
            'decision_note' => 'Forged.',
            'expected_workflow_version' => 2,
            'idempotency_key' => 'deny-persuratan-decision',
        ])->assertNotFound();

        $this->actingAsUser($this->superAdmin());
        $this->patchJson($decisionUrl, [
            'decision_note' => 'Forged.',
            'expected_workflow_version' => 2,
            'idempotency_key' => 'deny-admin-decision',
        ])->assertForbidden();

        $this->actingAsUser($this->bookingUser(['role' => 'akademik']));
        $this->patchJson($decisionUrl, [
            'decision_note' => 'Forged.',
            'expected_workflow_version' => 2,
            'idempotency_key' => 'deny-akademik-decision',
        ])->assertForbidden();

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->patchJson($decisionUrl, [
            'expected_workflow_version' => 2,
            'idempotency_key' => 'missing-reject-reason',
        ])->assertUnprocessable()->assertJsonValidationErrors('decision_note');
    }

    public function test_cancellation_decision_replay_is_single_effect_and_changed_payload_conflicts(): void
    {
        [, $booking, $request] = $this->pendingApprovedCancellation();
        $this->actingAsUser($this->reviewerUser('sarpras'));
        $url = "/api/tendik/peminjaman-ruangan/cancellation-requests/{$request->id}/approve";
        $payload = [
            'decision_note' => 'Approved once.',
            'expected_workflow_version' => 2,
            'idempotency_key' => 'decision-replay-approve',
        ];

        $first = $this->patchJson($url, $payload)
            ->assertOk()->assertHeader('Idempotent-Replay', 'false');
        $this->patchJson($url, $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.workflow_version', 3)
            ->assertJsonPath('data.correlation_id', $first->json('data.correlation_id'));

        $this->patchJson($url, array_merge($payload, [
            'decision_note' => 'Changed.',
        ]))->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');

        $this->assertSame(3, $booking->fresh()->workflow_version);
        $this->assertSame(1, RoomBookingWorkflowEvent::query()
            ->where('room_booking_request_id', $booking->id)
            ->where('event_type', RoomBookingWorkflowEvent::EVENT_CANCELLATION_APPROVED)
            ->count());
    }

    public function test_pending_request_blocks_edit_and_resubmit_and_cross_owner_ids_are_hidden(): void
    {
        $owner = $this->student();
        $room = $this->classroom();
        $booking = $this->roomBooking(
            $room,
            $owner,
            RoomBookingStatus::RevisionRequested,
        );
        $this->createSuratPeminjamanAttachment($booking, $owner);
        $request = $this->createPendingRequest($owner, $booking, 'pending-edit-block-01');

        $this->actingAsUser($owner);
        $this->putJson(
            $this->mahasiswaUrl("/requests/{$booking->id}"),
            $this->validBookingPayload($room),
        )->assertConflict()->assertJsonPath('code', 'pending_cancellation_request');
        $this->patchJson($this->mahasiswaUrl("/requests/{$booking->id}/submit"))
            ->assertConflict()->assertJsonPath('code', 'pending_cancellation_request');

        $this->actingAsUser($this->student());
        $this->getJson($this->mahasiswaUrl("/requests/{$booking->id}/cancellation-request"))
            ->assertNotFound();
        $this->patchJson(
            $this->mahasiswaUrl(
                "/requests/{$booking->id}/cancellation-requests/{$request->id}/withdraw",
            ),
            [
                'expected_workflow_version' => 2,
                'idempotency_key' => 'guessed-cancel-request',
            ],
        )->assertNotFound();
    }

    public function test_expired_revision_edit_to_future_restores_resubmit_capability(): void
    {
        $student = $this->student();
        $room = $this->classroom();
        $booking = $this->roomBooking(
            $room,
            $student,
            RoomBookingStatus::RevisionRequested,
            startAt: '2026-06-18 08:00:00',
            endAt: '2026-06-18 08:30:00',
        );
        $this->actingAsUser($student);

        $this->putJson(
            $this->mahasiswaUrl("/requests/{$booking->id}"),
            $this->validBookingPayload($room, [
                'start_at' => '2026-06-20T10:00:00+07:00',
                'end_at' => '2026-06-20T12:00:00+07:00',
            ]),
        )->assertOk()
            ->assertJsonPath('data.is_expired', false)
            ->assertJsonPath('data.capabilities.can_edit', true)
            ->assertJsonPath('data.capabilities.can_resubmit', true);
    }

    public function test_database_unique_guard_prevents_two_pending_requests_for_one_booking(): void
    {
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student);
        $attributes = [
            'room_booking_request_id' => $booking->id,
            'requested_by' => $student->id,
            'requester_name_snapshot' => $student->name,
            'requester_role_snapshot' => 'mahasiswa',
            'reason' => 'Test database guard.',
            'status' => RoomBookingCancellationStatus::Pending,
            'booking_status_snapshot' => RoomBookingStatus::Submitted,
            'booking_workflow_version_at_request' => 1,
            'requested_at' => now(),
            'active_pending_guard' => true,
        ];
        RoomBookingCancellationRequest::create($attributes);

        $this->expectException(QueryException::class);
        RoomBookingCancellationRequest::create($attributes);
    }

    /** @return array{0: User, 1: RoomBookingRequest, 2: RoomBookingCancellationRequest} */
    private function pendingApprovedCancellation(): array
    {
        $student = $this->student();
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::Approved,
        );
        $request = $this->createPendingRequest($student, $booking, 'cancel-approved-request-01');

        return [$student, $booking->fresh(), $request];
    }

    private function createPendingRequest(
        $student,
        $booking,
        string $key,
    ): RoomBookingCancellationRequest {
        $this->actingAsUser($student);
        $response = $this->postJson(
            $this->mahasiswaUrl("/requests/{$booking->id}/cancellation-requests"),
            [
                'reason' => 'Pemohon meminta pembatalan.',
                'expected_workflow_version' => max(1, (int) $booking->workflow_version),
                'idempotency_key' => $key,
            ],
        )->assertCreated();

        return RoomBookingCancellationRequest::findOrFail(
            $response->json('data.cancellation_request.id'),
        );
    }

    public function test_cancellation_request_actions_are_actor_and_time_specific(): void
    {
        [$student, $booking, $request] = $this->pendingApprovedCancellation();

        $this->actingAsUser($student);
        $this->getJson($this->mahasiswaUrl("/requests/{$booking->id}/cancellation-request"))
            ->assertOk()
            ->assertJsonPath(
                'data.cancellation_request.available_applicant_action',
                'withdraw_cancellation_request',
            );

        $this->actingAsUser($this->reviewerUser('sarpras'));
        $this->getJson(
            "/api/tendik/peminjaman-ruangan/cancellation-requests/{$request->id}",
        )->assertOk()
            ->assertJsonPath('data.cancellation_request.available_applicant_action', null)
            ->assertJsonPath('data.capabilities.can_decide_cancellation', true);

        $booking->forceFill(['start_at' => now()->subMinute()])->save();
        $this->actingAsUser($student);
        $this->getJson($this->mahasiswaUrl("/requests/{$booking->id}/cancellation-request"))
            ->assertOk()
            ->assertJsonPath('data.cancellation_request.available_applicant_action', null);
    }

    public function test_all_new_cancellation_endpoints_require_expected_workflow_version(): void
    {
        $student = $this->student();
        $reviewer = $this->reviewerUser('sarpras');
        $reviewed = $this->roomBooking($this->classroom(), $student);
        $reviewed->forceFill([
            'review_started_at' => now(),
            'review_started_by' => $reviewer->id,
        ])->save();

        $this->actingAsUser($student);
        $this->postJson(
            $this->mahasiswaUrl("/requests/{$reviewed->id}/cancellation-requests"),
            ['reason' => 'Missing version.', 'idempotency_key' => 'missing-version-create'],
        )->assertUnprocessable()->assertJsonValidationErrors('expected_workflow_version');

        [$owner, $booking, $request] = $this->pendingApprovedCancellation();
        $this->actingAsUser($owner);
        $this->patchJson(
            $this->mahasiswaUrl(
                "/requests/{$booking->id}/cancellation-requests/{$request->id}/withdraw",
            ),
            ['idempotency_key' => 'missing-version-withdraw-request'],
        )->assertUnprocessable()->assertJsonValidationErrors('expected_workflow_version');

        $this->actingAsUser($this->reviewerUser('sarpras'));
        foreach (['approve', 'reject'] as $action) {
            $this->patchJson(
                "/api/tendik/peminjaman-ruangan/cancellation-requests/{$request->id}/{$action}",
                [
                    'decision_note' => 'Missing version.',
                    'idempotency_key' => "missing-version-{$action}",
                ],
            )->assertUnprocessable()->assertJsonValidationErrors('expected_workflow_version');
        }
    }
}
