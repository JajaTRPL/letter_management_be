<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingRequest;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RoomBookingConflictService
{
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
}
