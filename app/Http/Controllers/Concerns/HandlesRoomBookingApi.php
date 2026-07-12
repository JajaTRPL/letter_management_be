<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Room;
use App\Models\RoomBookingCancellationRequest;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingAttachmentService;
use App\Services\RoomBookingConflictService;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingIdempotencyOutcome;
use App\Services\RoomBookingLifecycleCapabilityResolver;
use App\Services\RoomBookingWithdrawalPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

trait HandlesRoomBookingApi
{
    protected function roomBookingDomainResponse(
        RoomBookingDomainException $exception,
        ?RoomBookingRequest $booking = null,
        bool $includeRequester = false,
    ): JsonResponse {
        $status = match ($exception->reason) {
            RoomBookingDomainException::BOOKING_CONFLICT,
            RoomBookingDomainException::INVALID_TRANSITION,
            RoomBookingDomainException::BOOKING_START_PASSED,
            RoomBookingDomainException::PROTECTED_BUSINESS_RECORD,
            RoomBookingDomainException::STALE_WORKFLOW_VERSION,
            RoomBookingDomainException::PENDING_CANCELLATION_REQUEST,
            RoomBookingDomainException::REVIEW_ALREADY_STARTED,
            RoomBookingDomainException::WITHDRAWAL_CUTOFF_PASSED,
            RoomBookingDomainException::REVISION_ALREADY_REQUESTED,
            RoomBookingDomainException::REQUIRES_CANCELLATION_REVIEW,
            RoomBookingDomainException::FINAL_BOOKING_STATE,
            RoomBookingDomainException::BOOKING_EXPIRED,
            RoomBookingDomainException::CANCELLATION_REQUEST_NOT_ALLOWED,
            RoomBookingDomainException::CANCELLATION_REQUEST_ALREADY_RESOLVED,
            RoomBookingDomainException::IDEMPOTENCY_KEY_REUSED,
            RoomBookingDomainException::INACTIVE_ROOM => 409,
            RoomBookingDomainException::UNAUTHORIZED_ACTION => 403,
            default => 422,
        };

        $payload = [
            'message' => $exception->getMessage(),
            'code' => $exception->reason,
        ];

        $data = $exception->context;
        if ($booking?->exists) {
            $fresh = RoomBookingRequest::query()->find($booking->id);
            if ($fresh) {
                $data = array_merge($data, $this->bookingMutationData(
                    $fresh,
                    includeRequester: $includeRequester,
                ));
            }
        }

        if ($data !== []) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    /**
     * @return array<string, mixed>
     */
    protected function roomPayload(Room $room): array
    {
        $room->loadMissing('owningLaboratory:id,code,name');

        return [
            'id' => (int) $room->id,
            'code' => $room->code,
            'name' => $room->name,
            'type' => $room->type->value,
            'capacity' => (int) $room->capacity,
            'location' => $room->location,
            'description' => $room->description,
            'is_active' => (bool) $room->is_active,
            'owning_laboratory' => $room->owningLaboratory ? [
                'id' => (int) $room->owningLaboratory->id,
                'code' => $room->owningLaboratory->code,
                'name' => $room->owningLaboratory->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function bookingPayload(
        RoomBookingRequest $booking,
        bool $includeRequester = false,
        bool $includeHistory = false,
        bool $includeConflicts = false,
    ): array {
        $booking->loadMissing([
            'room.owningLaboratory:id,code,name',
            'reviewer:id,name,email',
            'suratPeminjamanAttachment',
            'activeCancellationRequest',
            'revisionRequestHistory',
        ]);

        if ($includeRequester) {
            $booking->loadMissing('requester:id,name,email');
        }

        if ($includeHistory) {
            $booking->loadMissing('statusHistories.actor:id,name,email');
        }

        $payload = [
            'id' => (int) $booking->id,
            'room' => $this->roomPayload($booking->room),
            'activity_name' => $booking->activity_name,
            'purpose' => $booking->purpose,
            'participant_count' => (int) $booking->participant_count,
            'start_at' => $booking->start_at->toIso8601String(),
            'end_at' => $booking->end_at->toIso8601String(),
            'status' => $booking->status->value,
            'stored_status' => $booking->status->value,
            // C7B1 additive lifecycle projection: the stored five-status
            // contract above is unchanged; these fields are read-only
            // derivations plus the server-authoritative capability object.
            'workflow_version' => (int) ($booking->workflow_version ?? 1),
            'submission_iteration' => (int) ($booking->submission_iteration ?? 1),
            'effective_status' => $booking->effectiveStatus(),
            'is_expired' => $booking->isExpired(),
            'is_completed' => $booking->isCompleted(),
            'review_started_at' => $booking->review_started_at?->toIso8601String(),
            'cancellation_pending' => $booking->hasPendingCancellationRequest(),
            'cancellation_request' => $this->cancellationRequestPayload(
                $booking->activeCancellationRequest,
                $booking,
            ),
            'capabilities' => app(RoomBookingLifecycleCapabilityResolver::class)
                ->capabilitiesFor(auth()->user(), $booking),
            'reviewer' => $booking->reviewer ? [
                'id' => (int) $booking->reviewer->id,
                'name' => $booking->reviewer->name,
            ] : null,
            'reviewed_at' => $booking->reviewed_at?->toIso8601String(),
            'revision_note' => $booking->revision_note,
            'rejection_reason' => $booking->rejection_reason,
            'cancellation_reason' => $booking->cancellation_reason,
            'cancellation_source' => $booking->cancellation_source,
            'cancelled_by_role_snapshot' => $booking->cancelled_by_role_snapshot,
            'created_at' => $booking->created_at?->toIso8601String(),
            'updated_at' => $booking->updated_at?->toIso8601String(),
            'surat_peminjaman_pdf' => app(RoomBookingAttachmentService::class)
                ->publicMetadata($booking),
        ];

        if ($includeRequester) {
            $payload['requester'] = $booking->requester ? [
                'id' => (int) $booking->requester->id,
                'name' => $booking->requester->name,
                'email' => $booking->requester->email,
            ] : null;
        }

        if ($includeHistory) {
            $payload['status_histories'] = $booking->statusHistories
                ->sortBy('created_at')
                ->values()
                ->map(fn ($history) => [
                    'id' => (int) $history->id,
                    'from_status' => $history->from_status?->value,
                    'to_status' => $history->to_status->value,
                    'actor' => $history->actor ? [
                        'id' => (int) $history->actor->id,
                        'name' => $history->actor->name,
                    ] : null,
                    'note' => $history->note,
                    'created_at' => $history->created_at?->toIso8601String(),
                ])
                ->all();
        }

        if ($includeConflicts) {
            $payload = array_merge(
                $payload,
                app(RoomBookingConflictService::class)->conflictMetadata(
                    $booking,
                    includeRequester: $includeRequester,
                    includeActivity: true,
                    includePurpose: true,
                ),
            );
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function cancellationRequestPayload(
        ?RoomBookingCancellationRequest $request,
        ?RoomBookingRequest $booking = null,
    ): ?array {
        if (! $request) {
            return null;
        }

        $booking ??= $request->booking;
        $actor = auth()->user();

        return [
            'id' => (int) $request->id,
            'status' => $request->status->value,
            'reason' => $request->reason,
            'requested_at' => $request->requested_at?->toIso8601String(),
            'decision_note' => $request->decided_at ? $request->decision_note : null,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'responsible_role' => $booking?->room?->type?->value === 'classroom'
                ? 'sarpras'
                : 'kepala_lab',
            'available_applicant_action' => app(RoomBookingWithdrawalPolicy::class)
                ->canWithdrawCancellationRequest($actor, $booking, $request)
                ? 'withdraw_cancellation_request'
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function bookingMutationData(
        RoomBookingRequest $booking,
        ?RoomBookingCancellationRequest $cancellationRequest = null,
        ?string $correlationId = null,
        bool $includeRequester = false,
    ): array {
        $booking->loadMissing(['room', 'activeCancellationRequest']);
        $cancellationRequest ??= $booking->activeCancellationRequest;

        return [
            'booking' => $this->bookingPayload(
                $booking,
                includeRequester: $includeRequester,
                includeHistory: true,
                includeConflicts: $includeRequester,
            ),
            'stored_status' => $booking->status->value,
            'effective_status' => $booking->effectiveStatus(),
            'workflow_version' => (int) ($booking->workflow_version ?? 1),
            'capabilities' => app(RoomBookingLifecycleCapabilityResolver::class)
                ->capabilitiesFor(auth()->user(), $booking),
            'cancellation_request' => $this->cancellationRequestPayload(
                $cancellationRequest,
                $booking,
            ),
            'cancellation_pending' => $booking->hasPendingCancellationRequest(),
            'notification_state' => 'not_implemented',
            'correlation_id' => $correlationId ?? $this->roomBookingCorrelationId(),
        ];
    }

    protected function roomBookingInfrastructureResponse(
        Throwable $exception,
        ?RoomBookingRequest $booking = null,
    ): JsonResponse {
        $correlationId = $this->roomBookingCorrelationId();
        Log::error('Unexpected room-booking infrastructure failure.', [
            'correlation_id' => $correlationId,
            'actor_id' => auth()->id(),
            'booking_id' => $booking?->id,
            'exception' => $exception,
        ]);

        return response()->json([
            'message' => 'Terjadi gangguan saat memproses peminjaman ruangan. Silakan coba lagi.',
            'code' => 'infrastructure_error',
            'correlation_id' => $correlationId,
        ], 500);
    }

    protected function roomBookingOutcomeResponse(
        RoomBookingIdempotencyOutcome $outcome,
    ): JsonResponse {
        return response()->json(
            $outcome->body,
            $outcome->statusCode,
        )->header(
            'Idempotent-Replay',
            $outcome->replayed ? 'true' : 'false',
        );
    }

    /**
     * Build the response once inside the idempotency transaction. The exact
     * allowlisted body returned here is persisted and reused verbatim.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function roomBookingMutationResponseBody(
        array $result,
        bool $includeRequester = false,
    ): array {
        $booking = RoomBookingRequest::query()->findOrFail($result['booking_id']);
        $cancellationRequest = isset($result['cancellation_request_id'])
            ? RoomBookingCancellationRequest::query()->find($result['cancellation_request_id'])
            : null;

        $data = $this->bookingMutationData(
            $booking,
            $cancellationRequest,
            $result['correlation_id'] ?? null,
            $includeRequester,
        );
        foreach (['stored_status', 'effective_status', 'workflow_version'] as $field) {
            if (array_key_exists($field, $result)) {
                $data[$field] = $result[$field];
            }
        }

        return [
            'message' => $result['message'],
            'data' => $data,
        ];
    }

    protected function roomBookingCorrelationId(): string
    {
        $request = request();
        $cached = $request?->attributes->get('room_booking_correlation_id');
        if (is_string($cached) && Str::isUuid($cached)) {
            return $cached;
        }

        $header = $request?->header('X-Request-Id');
        $correlationId = is_string($header) && Str::isUuid($header)
            ? $header
            : (string) Str::uuid();
        $request?->attributes->set('room_booking_correlation_id', $correlationId);

        return $correlationId;
    }

    /**
     * @return array<string, int>
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
