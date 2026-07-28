<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

class RoomBookingReviewService
{
    public function __construct(
        private RoomBookingIdempotencyService $idempotency,
        private RoomBookingReviewerResolver $reviewerResolver,
        private RoomBookingTransitionService $transitions,
    ) {}

    public function start(
        RoomBookingRequest $booking,
        User $actor,
        int $expectedWorkflowVersion,
        string $idempotencyKey,
        ?callable $responseBody = null,
    ): RoomBookingIdempotencyOutcome {
        return $this->idempotency->execute(
            actor: $actor,
            booking: $booking,
            action: RoomBookingWorkflowEvent::EVENT_REVIEW_STARTED,
            subjectKey: 'booking:'.$booking->id,
            idempotencyKey: $idempotencyKey,
            canonicalPayload: ['expected_workflow_version' => $expectedWorkflowVersion],
            operation: function (string $correlationId) use (
                $booking,
                $actor,
                $expectedWorkflowVersion,
            ) {
                $lockedBooking = RoomBookingRequest::query()
                    ->with('room')
                    ->lockForUpdate()
                    ->findOrFail($booking->id);
                $actor->refresh();

                $this->transitions->assertExpectedWorkflowVersion(
                    $lockedBooking,
                    $expectedWorkflowVersion,
                );
                $this->transitions->assertNoPendingCancellationRequest($lockedBooking);

                if (! $this->reviewerResolver->canActAsApprover($actor, $lockedBooking)) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::UNAUTHORIZED_ACTION,
                        'Anda tidak berwenang memulai tinjauan pengajuan ini.',
                    );
                }

                if ($lockedBooking->status !== RoomBookingStatus::Submitted) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::INVALID_TRANSITION,
                        'Tinjauan hanya dapat dimulai untuk pengajuan berstatus submitted.',
                    );
                }

                if ($lockedBooking->isExpired()) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::BOOKING_EXPIRED,
                        'Pengajuan yang sudah melewati waktu mulai tidak dapat mulai ditinjau.',
                    );
                }

                if ($lockedBooking->review_started_at !== null) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::REVIEW_ALREADY_STARTED,
                        'Tinjauan pengajuan ini sudah dimulai.',
                    );
                }

                $started = $this->transitions->recordSameStatusMutationLocked(
                    $lockedBooking,
                    $actor,
                    RoomBookingWorkflowEvent::EVENT_REVIEW_STARTED,
                    [
                        'review_started_at' => Carbon::now(config('app.timezone')),
                        'review_started_by' => $actor->id,
                    ],
                    correlationId: $correlationId,
                );

                return [
                    'status_code' => 200,
                    'payload' => [
                        'message' => 'Tinjauan pengajuan peminjaman ruangan berhasil dimulai',
                        'booking_id' => (int) $started->id,
                        'workflow_version' => (int) $started->workflow_version,
                        'stored_status' => $started->status->value,
                        'effective_status' => $started->effectiveStatus(),
                    ],
                ];
            },
            responseBody: $responseBody,
        );
    }
}
