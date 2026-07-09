<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingRequest;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RoomBookingConflictService
{
    private const CONFLICT_NONE = 'none';
    private const CONFLICT_APPROVED_OVERLAP = 'approved_overlap';
    private const CONFLICT_PENDING_OVERLAP = 'pending_overlap';

    private const LEVEL_NONE = 'none';
    private const LEVEL_BLOCKING = 'blocking';
    private const LEVEL_WARNING = 'warning';

    public function overlappingApprovedQuery(
        int $roomId,
        DateTimeInterface $startAt,
        DateTimeInterface $endAt,
        ?int $ignoreBookingId = null,
    ): Builder {
        return RoomBookingRequest::query()
            ->where('room_id', $roomId)
            ->where('status', RoomBookingStatus::Approved->value)
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->when(
                $ignoreBookingId !== null,
                fn (Builder $query) => $query->whereKeyNot($ignoreBookingId),
            );
    }

    public function hasConflict(
        int $roomId,
        DateTimeInterface $startAt,
        DateTimeInterface $endAt,
        ?int $ignoreBookingId = null,
    ): bool {
        return $this->overlappingApprovedQuery(
            $roomId,
            $startAt,
            $endAt,
            $ignoreBookingId,
        )->exists();
    }

    /**
     * Reviewer-safe summary. Requester identity and private purpose are omitted.
     *
     * @return Collection<int, array{
     *     booking_id: int,
     *     room_id: int,
     *     start_at: string,
     *     end_at: string,
     *     status: string
     * }>
     */
    public function conflictingSummary(
        int $roomId,
        DateTimeInterface $startAt,
        DateTimeInterface $endAt,
        ?int $ignoreBookingId = null,
    ): Collection {
        return $this->overlappingApprovedQuery(
            $roomId,
            $startAt,
            $endAt,
            $ignoreBookingId,
        )
            ->orderBy('start_at')
            ->get(['id', 'room_id', 'start_at', 'end_at', 'status'])
            ->map(fn (RoomBookingRequest $booking) => [
                'booking_id' => (int) $booking->id,
                'room_id' => (int) $booking->room_id,
                'start_at' => $booking->start_at->toIso8601String(),
                'end_at' => $booking->end_at->toIso8601String(),
                'status' => $booking->status->value,
            ]);
    }

    /**
     * @return array{
     *     conflict_status: string,
     *     has_conflict: bool,
     *     conflict_level: string,
     *     conflict_message: string|null,
     *     conflicts: array<int, array<string, mixed>>
     * }
     */
    public function conflictMetadata(
        RoomBookingRequest $booking,
        bool $includeRequester = false,
        bool $includeActivity = false,
        bool $includePurpose = false,
    ): array {
        if (! $booking->room_id || ! $booking->start_at || ! $booking->end_at) {
            return $this->emptyConflictMetadata();
        }

        $approvedConflicts = $this->overlappingApprovedQuery(
            (int) $booking->room_id,
            $booking->start_at,
            $booking->end_at,
            (int) $booking->id,
        )
            ->with(['room:id,name', 'requester:id,name'])
            ->orderBy('start_at')
            ->get();

        if ($approvedConflicts->isNotEmpty()) {
            return $this->metadataFromConflicts(
                self::CONFLICT_APPROVED_OVERLAP,
                self::LEVEL_BLOCKING,
                'Pengajuan ini bentrok dengan peminjaman yang sudah disetujui.',
                $approvedConflicts,
                $includeRequester,
                $includeActivity,
                $includePurpose,
            );
        }

        $pendingConflicts = $this->overlappingPendingQuery(
            (int) $booking->room_id,
            $booking->start_at,
            $booking->end_at,
            (int) $booking->id,
        )
            ->with(['room:id,name', 'requester:id,name'])
            ->orderBy('start_at')
            ->get();

        if ($pendingConflicts->isNotEmpty()) {
            return $this->metadataFromConflicts(
                self::CONFLICT_PENDING_OVERLAP,
                self::LEVEL_WARNING,
                'Ada pengajuan lain pada ruang dan waktu yang sama.',
                $pendingConflicts,
                $includeRequester,
                $includeActivity,
                $includePurpose,
            );
        }

        return $this->emptyConflictMetadata();
    }

    private function overlappingPendingQuery(
        int $roomId,
        DateTimeInterface $startAt,
        DateTimeInterface $endAt,
        ?int $ignoreBookingId = null,
    ): Builder {
        return RoomBookingRequest::query()
            ->where('room_id', $roomId)
            ->whereIn('status', [
                RoomBookingStatus::Submitted->value,
                RoomBookingStatus::RevisionRequested->value,
            ])
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->when(
                $ignoreBookingId !== null,
                fn (Builder $query) => $query->whereKeyNot($ignoreBookingId),
            );
    }

    /**
     * @param  Collection<int, RoomBookingRequest>  $conflicts
     * @return array{
     *     conflict_status: string,
     *     has_conflict: bool,
     *     conflict_level: string,
     *     conflict_message: string,
     *     conflicts: array<int, array<string, mixed>>
     * }
     */
    private function metadataFromConflicts(
        string $status,
        string $level,
        string $message,
        Collection $conflicts,
        bool $includeRequester,
        bool $includeActivity,
        bool $includePurpose,
    ): array {
        return [
            'conflict_status' => $status,
            'has_conflict' => true,
            'conflict_level' => $level,
            'conflict_message' => $message,
            'conflicts' => $conflicts
                ->map(fn (RoomBookingRequest $booking) => $this->conflictSummaryPayload(
                    $booking,
                    $includeRequester,
                    $includeActivity,
                    $includePurpose,
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     conflict_status: string,
     *     has_conflict: bool,
     *     conflict_level: string,
     *     conflict_message: null,
     *     conflicts: array<int, array<string, mixed>>
     * }
     */
    private function emptyConflictMetadata(): array
    {
        return [
            'conflict_status' => self::CONFLICT_NONE,
            'has_conflict' => false,
            'conflict_level' => self::LEVEL_NONE,
            'conflict_message' => null,
            'conflicts' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conflictSummaryPayload(
        RoomBookingRequest $booking,
        bool $includeRequester,
        bool $includeActivity,
        bool $includePurpose,
    ): array {
        $payload = [
            'booking_id' => (int) $booking->id,
            'room_id' => (int) $booking->room_id,
            'room_name' => $booking->room?->name,
            'start_at' => $booking->start_at->toIso8601String(),
            'end_at' => $booking->end_at->toIso8601String(),
            'status' => $booking->status->value,
        ];

        if ($includeRequester) {
            $payload['requester_name'] = $booking->requester?->name;
        }

        if ($includeActivity) {
            $payload['activity_name'] = $booking->activity_name;
        }

        if ($includePurpose) {
            $payload['purpose'] = $booking->purpose;
        }

        return $payload;
    }
}
