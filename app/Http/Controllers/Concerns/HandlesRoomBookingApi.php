<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingAttachmentService;
use App\Services\RoomBookingConflictService;
use App\Services\RoomBookingDomainException;
use Illuminate\Http\JsonResponse;

trait HandlesRoomBookingApi
{
    protected function roomBookingDomainResponse(
        RoomBookingDomainException $exception,
    ): JsonResponse {
        $status = match ($exception->reason) {
            RoomBookingDomainException::BOOKING_CONFLICT,
            RoomBookingDomainException::INVALID_TRANSITION,
            RoomBookingDomainException::INACTIVE_ROOM => 409,
            RoomBookingDomainException::UNAUTHORIZED_ACTION => 403,
            default => 422,
        };

        $payload = [
            'message' => $exception->getMessage(),
            'code' => $exception->reason,
        ];

        if ($exception->context !== []) {
            $payload['data'] = $exception->context;
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
            'reviewer' => $booking->reviewer ? [
                'id' => (int) $booking->reviewer->id,
                'name' => $booking->reviewer->name,
                'email' => $booking->reviewer->email,
            ] : null,
            'reviewed_at' => $booking->reviewed_at?->toIso8601String(),
            'revision_note' => $booking->revision_note,
            'rejection_reason' => $booking->rejection_reason,
            'cancellation_reason' => $booking->cancellation_reason,
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
                        'email' => $history->actor->email,
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
