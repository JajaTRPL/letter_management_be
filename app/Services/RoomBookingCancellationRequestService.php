<?php

namespace App\Services;

use App\Enums\RoomBookingCancellationStatus;
use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingCancellationRequest;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

class RoomBookingCancellationRequestService
{
    public function __construct(
        private RoomBookingIdempotencyService $idempotency,
        private RoomBookingReviewerResolver $reviewerResolver,
        private RoomBookingTransitionService $transitions,
        private RoomBookingWithdrawalPolicy $withdrawalPolicy,
    ) {}

    public function create(
        RoomBookingRequest $booking,
        User $actor,
        string $reason,
        int $expectedWorkflowVersion,
        string $idempotencyKey,
        ?callable $responseBody = null,
    ): RoomBookingIdempotencyOutcome {
        $reason = $this->requiredText($reason, 'Alasan permohonan pembatalan wajib diisi.');

        return $this->idempotency->execute(
            actor: $actor,
            booking: $booking,
            action: RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUESTED,
            subjectKey: 'booking:'.$booking->id,
            idempotencyKey: $idempotencyKey,
            canonicalPayload: [
                'expected_workflow_version' => $expectedWorkflowVersion,
                'reason' => $reason,
            ],
            operation: function (string $correlationId) use (
                $booking,
                $actor,
                $reason,
                $expectedWorkflowVersion,
            ) {
                $lockedBooking = RoomBookingRequest::query()
                    ->with(['activeCancellationRequest', 'revisionRequestHistory'])
                    ->lockForUpdate()
                    ->findOrFail($booking->id);
                $actor->refresh();
                $this->transitions->assertExpectedWorkflowVersion(
                    $lockedBooking,
                    $expectedWorkflowVersion,
                );

                if ($lockedBooking->hasPendingCancellationRequest()) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::PENDING_CANCELLATION_REQUEST,
                        'Permohonan pembatalan masih menunggu keputusan.',
                    );
                }

                if (! $this->withdrawalPolicy->canRequestCancellation($actor, $lockedBooking)) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::CANCELLATION_REQUEST_NOT_ALLOWED,
                        'Pengajuan ini tidak dapat diajukan untuk pembatalan saat ini.',
                    );
                }

                $versionBefore = max(1, (int) ($lockedBooking->workflow_version ?? 1));
                $request = RoomBookingCancellationRequest::create([
                    'room_booking_request_id' => $lockedBooking->id,
                    'requested_by' => $actor->id,
                    'requester_name_snapshot' => (string) $actor->name,
                    'requester_role_snapshot' => (string) $actor->role,
                    'reason' => $reason,
                    'status' => RoomBookingCancellationStatus::Pending,
                    'booking_status_snapshot' => $lockedBooking->status,
                    'booking_workflow_version_at_request' => $versionBefore,
                    'requested_at' => $this->now(),
                    'active_pending_guard' => true,
                ]);

                $mutated = $this->transitions->recordSameStatusMutationLocked(
                    $lockedBooking,
                    $actor,
                    RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUESTED,
                    publicNote: $reason,
                    safeMetadata: ['cancellation_request_id' => $request->id],
                    correlationId: $correlationId,
                );

                return $this->result(
                    'Permohonan pembatalan berhasil diajukan',
                    $mutated,
                    $request,
                    201,
                );
            },
            responseBody: $responseBody,
        );
    }

    public function withdraw(
        RoomBookingRequest $booking,
        RoomBookingCancellationRequest $cancellationRequest,
        User $actor,
        ?string $reason,
        int $expectedWorkflowVersion,
        string $idempotencyKey,
        ?callable $responseBody = null,
    ): RoomBookingIdempotencyOutcome {
        $reason = $this->optionalText($reason);

        return $this->idempotency->execute(
            actor: $actor,
            booking: $booking,
            action: RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUEST_WITHDRAWN,
            subjectKey: 'cancellation-request:'.$cancellationRequest->id,
            idempotencyKey: $idempotencyKey,
            canonicalPayload: [
                'expected_workflow_version' => $expectedWorkflowVersion,
                'reason' => $reason,
            ],
            operation: function (string $correlationId) use (
                $booking,
                $cancellationRequest,
                $actor,
                $reason,
                $expectedWorkflowVersion,
            ) {
                $lockedBooking = RoomBookingRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($booking->id);
                $lockedRequest = RoomBookingCancellationRequest::query()
                    ->lockForUpdate()
                    ->where('room_booking_request_id', $lockedBooking->id)
                    ->findOrFail($cancellationRequest->id);
                $actor->refresh();
                $this->transitions->assertExpectedWorkflowVersion(
                    $lockedBooking,
                    $expectedWorkflowVersion,
                );

                if (! $this->withdrawalPolicy->canWithdrawCancellationRequest(
                    $actor,
                    $lockedBooking,
                    $lockedRequest,
                )) {
                    // Truthful failure classification: a request that is
                    // still pending has NOT been resolved — when only the
                    // activity clock blocks the withdrawal, say so.
                    if (
                        $lockedRequest->isPending()
                        && (int) $lockedRequest->requested_by === (int) $actor->id
                        && (
                            $lockedBooking->start_at === null
                            || ! $lockedBooking->start_at->greaterThan($this->now())
                        )
                    ) {
                        throw new RoomBookingDomainException(
                            RoomBookingDomainException::BOOKING_START_PASSED,
                            'Permohonan pembatalan tidak dapat ditarik karena waktu kegiatan sudah dimulai atau terlewati.',
                        );
                    }

                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::CANCELLATION_REQUEST_ALREADY_RESOLVED,
                        'Permohonan pembatalan tidak lagi dapat ditarik.',
                    );
                }

                $lockedRequest->forceFill([
                    'status' => RoomBookingCancellationStatus::Withdrawn,
                    'active_pending_guard' => null,
                    'decided_at' => $this->now(),
                    'decision_note' => $reason,
                ])->save();

                $mutated = $this->transitions->recordSameStatusMutationLocked(
                    $lockedBooking,
                    $actor,
                    RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUEST_WITHDRAWN,
                    publicNote: $reason,
                    safeMetadata: ['cancellation_request_id' => $lockedRequest->id],
                    correlationId: $correlationId,
                );

                return $this->result(
                    'Permohonan pembatalan berhasil ditarik',
                    $mutated,
                    $lockedRequest->fresh(),
                );
            },
            responseBody: $responseBody,
        );
    }

    public function approve(
        RoomBookingCancellationRequest $cancellationRequest,
        User $actor,
        ?string $decisionNote,
        int $expectedWorkflowVersion,
        string $idempotencyKey,
        ?callable $responseBody = null,
    ): RoomBookingIdempotencyOutcome {
        return $this->decide(
            $cancellationRequest,
            $actor,
            RoomBookingCancellationStatus::Approved,
            $this->optionalText($decisionNote),
            $expectedWorkflowVersion,
            $idempotencyKey,
            $responseBody,
        );
    }

    public function reject(
        RoomBookingCancellationRequest $cancellationRequest,
        User $actor,
        string $decisionNote,
        int $expectedWorkflowVersion,
        string $idempotencyKey,
        ?callable $responseBody = null,
    ): RoomBookingIdempotencyOutcome {
        return $this->decide(
            $cancellationRequest,
            $actor,
            RoomBookingCancellationStatus::Rejected,
            $this->requiredText($decisionNote, 'Alasan penolakan pembatalan wajib diisi.'),
            $expectedWorkflowVersion,
            $idempotencyKey,
            $responseBody,
        );
    }

    private function decide(
        RoomBookingCancellationRequest $cancellationRequest,
        User $actor,
        RoomBookingCancellationStatus $decision,
        ?string $decisionNote,
        int $expectedWorkflowVersion,
        string $idempotencyKey,
        ?callable $responseBody,
    ): RoomBookingIdempotencyOutcome {
        $booking = $cancellationRequest->booking()->firstOrFail();
        $action = $decision === RoomBookingCancellationStatus::Approved
            ? RoomBookingWorkflowEvent::EVENT_CANCELLATION_APPROVED
            : RoomBookingWorkflowEvent::EVENT_CANCELLATION_REJECTED;

        return $this->idempotency->execute(
            actor: $actor,
            booking: $booking,
            action: $action,
            subjectKey: 'cancellation-request:'.$cancellationRequest->id,
            idempotencyKey: $idempotencyKey,
            canonicalPayload: [
                'decision_note' => $decisionNote,
                'expected_workflow_version' => $expectedWorkflowVersion,
            ],
            operation: function (string $correlationId) use (
                $booking,
                $cancellationRequest,
                $actor,
                $decision,
                $decisionNote,
                $expectedWorkflowVersion,
                $action,
            ) {
                $lockedBooking = RoomBookingRequest::query()
                    ->with('room')
                    ->lockForUpdate()
                    ->findOrFail($booking->id);
                $lockedRequest = RoomBookingCancellationRequest::query()
                    ->lockForUpdate()
                    ->where('room_booking_request_id', $lockedBooking->id)
                    ->findOrFail($cancellationRequest->id);
                $actor->refresh();
                $this->transitions->assertExpectedWorkflowVersion(
                    $lockedBooking,
                    $expectedWorkflowVersion,
                );

                if (! $this->reviewerResolver->canActAsApprover($actor, $lockedBooking)) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::UNAUTHORIZED_ACTION,
                        'Anda tidak berwenang memutus permohonan pembatalan ini.',
                    );
                }

                if (! $lockedRequest->isPending()) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::CANCELLATION_REQUEST_ALREADY_RESOLVED,
                        'Permohonan pembatalan sudah diputus atau ditarik.',
                    );
                }

                if (
                    $lockedBooking->isCompleted()
                    || $lockedBooking->start_at === null
                    || ! $lockedBooking->start_at->greaterThan($this->now())
                ) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::BOOKING_START_PASSED,
                        'Permohonan pembatalan tidak dapat diputus setelah kegiatan dimulai.',
                    );
                }

                if (! in_array($lockedBooking->status, [
                    RoomBookingStatus::Submitted,
                    RoomBookingStatus::RevisionRequested,
                    RoomBookingStatus::Approved,
                ], true)) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::FINAL_BOOKING_STATE,
                        'Peminjaman sudah berada pada status akhir.',
                    );
                }

                $this->markDecision($lockedRequest, $actor, $decision, $decisionNote);

                if ($decision === RoomBookingCancellationStatus::Approved) {
                    $mutated = $this->transitions->approveCancellationLocked(
                        $lockedBooking,
                        $actor,
                        $lockedRequest->reason,
                        $lockedRequest->id,
                        $correlationId,
                    );
                    $message = 'Permohonan pembatalan berhasil disetujui';
                } else {
                    $mutated = $this->transitions->recordSameStatusMutationLocked(
                        $lockedBooking,
                        $actor,
                        $action,
                        publicNote: $decisionNote,
                        safeMetadata: ['cancellation_request_id' => $lockedRequest->id],
                        correlationId: $correlationId,
                    );
                    $message = 'Permohonan pembatalan berhasil ditolak';
                }

                return $this->result($message, $mutated, $lockedRequest->fresh());
            },
            responseBody: $responseBody,
        );
    }

    private function markDecision(
        RoomBookingCancellationRequest $request,
        User $actor,
        RoomBookingCancellationStatus $status,
        ?string $note,
    ): void {
        $request->forceFill([
            'status' => $status,
            'active_pending_guard' => null,
            'decided_by' => $actor->id,
            'decision_actor_name_snapshot' => (string) $actor->name,
            'decision_actor_role_snapshot' => (string) ($actor->tendik_role ?: $actor->role),
            'decision_actor_scope_type' => $actor->laboratory_id !== null
                ? 'laboratory'
                : 'room_type',
            'decision_actor_scope_id' => $actor->laboratory_id,
            'decided_at' => $this->now(),
            'decision_note' => $note,
        ])->save();
    }

    /**
     * @return array{status_code: int, payload: array<string, mixed>}
     */
    private function result(
        string $message,
        RoomBookingRequest $booking,
        RoomBookingCancellationRequest $request,
        int $statusCode = 200,
    ): array {
        return [
            'status_code' => $statusCode,
            'payload' => [
                'message' => $message,
                'booking_id' => (int) $booking->id,
                'workflow_version' => (int) $booking->workflow_version,
                'stored_status' => $booking->status->value,
                'effective_status' => $booking->effectiveStatus(),
                'cancellation_request_id' => (int) $request->id,
                'cancellation_request_status' => $request->status->value,
            ],
        ];
    }

    private function requiredText(?string $value, string $message): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::REASON_REQUIRED,
                $message,
            );
        }

        return $value;
    }

    private function optionalText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function now(): Carbon
    {
        return Carbon::now(config('app.timezone'));
    }
}
