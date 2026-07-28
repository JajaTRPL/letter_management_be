<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingOccurrence;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
     * Applicant-safe demand projection. Booking IDs and all workflow actors,
     * notes, purpose/document data, and storage metadata are deliberately not
     * selected, so they cannot leak through this endpoint.
     *
     * @return Collection<int, array{
     *     room: array{id: int, code: string, name: string, type: string},
     *     start_at: string,
     *     end_at: string,
     *     lifecycle_category: 'approved'|'pending',
     *     activity_titles: list<string>,
     *     request_count: int
     * }>
     */
    public function projection(
        DateTimeInterface $rangeStart,
        DateTimeInterface $rangeEnd,
        ?int $roomId = null,
        ?RoomType $roomType = null,
    ): Collection {
        $now = now(config('app.timezone'));

        $occurrenceSlots = RoomBookingOccurrence::query()
            ->with('booking.room:id,code,name,type')
            ->where('start_at', '<', $rangeEnd)
            ->where('end_at', '>', $rangeStart)
            ->whereHas('booking', function (Builder $booking) use ($roomId, $roomType): void {
                $booking->whereIn('status', [
                    RoomBookingStatus::Approved->value,
                    RoomBookingStatus::Submitted->value,
                ])->when($roomId !== null, fn (Builder $query) => $query->where('room_id', $roomId))
                    ->when($roomType !== null, fn (Builder $query) => $query->whereHas(
                        'room',
                        fn (Builder $room) => $room->where('type', $roomType->value),
                    ));
            })
            ->where(function (Builder $occurrence) use ($now): void {
                $occurrence->whereHas('booking', fn (Builder $booking) => $booking
                    ->where('status', RoomBookingStatus::Approved->value))
                    ->where('end_at', '>', $now)
                    ->orWhere(function (Builder $pending) use ($now): void {
                        $pending->whereHas('booking', fn (Builder $booking) => $booking
                            ->where('status', RoomBookingStatus::Submitted->value))
                            ->where('start_at', '>', $now);
                    });
            })
            ->orderBy('start_at')
            ->get()
            ->map(fn (RoomBookingOccurrence $occurrence) => $this->slotArray(
                $occurrence->booking,
                $occurrence->start_at,
                $occurrence->end_at,
            ));

        $legacySlots = RoomBookingRequest::query()
            ->with('room:id,code,name,type')
            ->whereDoesntHave('occurrences')
            ->where('start_at', '<', $rangeEnd)
            ->where('end_at', '>', $rangeStart)
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $approved) use ($now): void {
                    $approved
                        ->where('status', RoomBookingStatus::Approved->value)
                        ->where('end_at', '>', $now);
                })->orWhere(function (Builder $pending) use ($now): void {
                    $pending
                        ->where('status', RoomBookingStatus::Submitted->value)
                        ->where('start_at', '>', $now);
                });
            })
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
            )
            ->orderBy('start_at')
            ->get(['room_id', 'activity_name', 'start_at', 'end_at', 'status'])
            ->map(fn (RoomBookingRequest $booking) => $this->slotArray(
                $booking,
                $booking->start_at,
                $booking->end_at,
            ));

        return $occurrenceSlots->concat($legacySlots)
            ->groupBy(fn (array $slot) => implode('|', [
                $slot['room_id'],
                $slot['start_at'],
                $slot['end_at'],
                $slot['category'],
            ]))
            ->map(function (Collection $slot): array {
                $first = $slot->first();
                $category = $first['category'];

                return [
                    'room' => $first['room'],
                    'start_at' => $first['start_at'],
                    'end_at' => $first['end_at'],
                    'lifecycle_category' => $category,
                    'activity_titles' => $slot
                        ->pluck('activity_title')
                        ->values()
                        ->all(),
                    'request_count' => $slot->count(),
                ];
            })
            ->values();
    }

    private function slotArray(
        RoomBookingRequest $booking,
        DateTimeInterface $startAt,
        DateTimeInterface $endAt,
    ): array {
        $category = $booking->status === RoomBookingStatus::Approved ? 'approved' : 'pending';

        return [
            'room_id' => (int) $booking->room_id,
            'room' => [
                'id' => (int) $booking->room->id,
                'code' => $booking->room->code,
                'name' => $booking->room->name,
                'type' => $booking->room->type->value,
            ],
            'start_at' => Carbon::instance($startAt)->toIso8601String(),
            'end_at' => Carbon::instance($endAt)->toIso8601String(),
            'category' => $category,
            'activity_title' => $this->safeActivityTitle($booking->activity_name, $category),
        ];
    }

    private function safeActivityTitle(?string $title, string $category): string
    {
        $sanitized = strip_tags($title ?? '');
        $sanitized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $sanitized) ?? '';
        $sanitized = preg_replace('/\s+/u', ' ', $sanitized) ?? '';
        $sanitized = trim($sanitized);

        if ($sanitized === '') {
            return $category === 'approved'
                ? 'Kegiatan terjadwal'
                : 'Pengajuan sedang diproses';
        }

        return Str::limit($sanitized, 120, '…');
    }
}
