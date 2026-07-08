<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Models\RoomBookingRequest;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RoomAvailabilityService
{
    public function approvedBookingsQuery(
        DateTimeInterface $rangeStart,
        DateTimeInterface $rangeEnd,
        ?int $roomId = null,
        ?RoomType $roomType = null,
    ): Builder {
        return RoomBookingRequest::query()
            ->with('room:id,code,name,type')
            ->where('status', RoomBookingStatus::Approved->value)
            ->where('start_at', '<', $rangeEnd)
            ->where('end_at', '>', $rangeStart)
            ->when(
                $roomId !== null,
                fn (Builder $query) => $query->where('room_id', $roomId),
            )
            ->when(
                $roomType !== null,
                fn (Builder $query) => $query->whereHas(
                    'room',
                    fn (Builder $roomQuery) => $roomQuery->where('type', $roomType->value),
                ),
            );
    }

    /**
     * Public calendar projection. It intentionally excludes requester,
     * reviewer, purpose, notes, and non-approved workflow records.
     *
     * @return Collection<int, array{
     *     booking_id: int,
     *     room: array{id: int, code: string, name: string, type: string},
     *     start_at: string,
     *     end_at: string,
     *     status: string
     * }>
     */
    public function projection(
        DateTimeInterface $rangeStart,
        DateTimeInterface $rangeEnd,
        ?int $roomId = null,
        ?RoomType $roomType = null,
    ): Collection {
        return $this->approvedBookingsQuery(
            $rangeStart,
            $rangeEnd,
            $roomId,
            $roomType,
        )
            ->orderBy('start_at')
            ->get(['id', 'room_id', 'start_at', 'end_at', 'status'])
            ->map(fn (RoomBookingRequest $booking) => [
                'booking_id' => (int) $booking->id,
                'room' => [
                    'id' => (int) $booking->room->id,
                    'code' => $booking->room->code,
                    'name' => $booking->room->name,
                    'type' => $booking->room->type->value,
                ],
                'start_at' => $booking->start_at->toIso8601String(),
                'end_at' => $booking->end_at->toIso8601String(),
                'status' => $booking->status->value,
            ]);
    }
}
