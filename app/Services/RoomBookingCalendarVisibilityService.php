<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingRequest;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RoomBookingCalendarVisibilityService
{
    public const SCOPE_ACTIVE = 'active';

    public const SCOPE_HISTORY = 'history';

    public function apply(Builder $query, ?string $scope, ?Carbon $now = null): void
    {
        $scope = $scope ?: self::SCOPE_ACTIVE;
        $now ??= now(config('app.timezone'));

        if ($scope === self::SCOPE_ACTIVE) {
            $query->where(function (Builder $activeQuery) use ($now) {
                $activeQuery
                    ->where(function (Builder $approvedQuery) use ($now) {
                        $approvedQuery
                            ->where('status', RoomBookingStatus::Approved->value);
                        $this->applyOccurrenceOrLegacyBoundary($approvedQuery, 'end_at', '>', $now);
                    })
                    ->orWhere(function (Builder $pendingQuery) use ($now) {
                        $pendingQuery
                            ->where('status', RoomBookingStatus::Submitted->value);
                        $this->applyOccurrenceOrLegacyBoundary($pendingQuery, 'start_at', '>', $now);
                    });
            });

            return;
        }

        if ($scope === self::SCOPE_HISTORY) {
            $query->where(function (Builder $historyQuery) use ($now) {
                $historyQuery
                    ->where(function (Builder $completedQuery) use ($now) {
                        $completedQuery
                            ->where('status', RoomBookingStatus::Approved->value);
                        $this->applyOccurrenceOrLegacyBoundary($completedQuery, 'end_at', '<=', $now);
                    })
                    ->orWhere(function (Builder $expiredQuery) use ($now) {
                        $expiredQuery
                            ->whereIn('status', [
                                RoomBookingStatus::Submitted->value,
                                RoomBookingStatus::RevisionRequested->value,
                            ]);
                        $this->applyOccurrenceOrLegacyBoundary($expiredQuery, 'start_at', '<=', $now);
                    });
            });

            return;
        }

        $query->where('status', $scope);
    }

    public function applyRange(
        Builder $query,
        DateTimeInterface $rangeStart,
        DateTimeInterface $rangeEndExclusive,
    ): void {
        $query->where(function (Builder $rangeQuery) use ($rangeStart, $rangeEndExclusive) {
            $rangeQuery
                ->whereHas('occurrences', function (Builder $occurrence) use ($rangeStart, $rangeEndExclusive) {
                    $occurrence
                        ->where('start_at', '<', $rangeEndExclusive)
                        ->where('end_at', '>', $rangeStart);
                })
                ->orWhere(function (Builder $legacy) use ($rangeStart, $rangeEndExclusive) {
                    $legacy
                        ->whereDoesntHave('occurrences')
                        ->where('start_at', '<', $rangeEndExclusive)
                        ->where('end_at', '>', $rangeStart);
                });
        });
    }

    /** @return Collection<int, array{start_at: Carbon, end_at: Carbon}> */
    public function slotRanges(RoomBookingRequest $booking): Collection
    {
        if ($booking->relationLoaded('occurrences') && $booking->occurrences->isNotEmpty()) {
            return $booking->occurrences->map(fn ($occurrence) => [
                'start_at' => $occurrence->start_at,
                'end_at' => $occurrence->end_at,
            ])->values();
        }

        return collect([[
            'start_at' => $booking->start_at,
            'end_at' => $booking->end_at,
        ]]);
    }

    public function includesSlot(
        RoomBookingStatus $status,
        DateTimeInterface $startAt,
        DateTimeInterface $endAt,
        ?string $scope,
        ?Carbon $now = null,
    ): bool {
        $scope = $scope ?: self::SCOPE_ACTIVE;
        $now ??= now(config('app.timezone'));
        $start = Carbon::instance($startAt);
        $end = Carbon::instance($endAt);

        if ($scope === self::SCOPE_ACTIVE) {
            return ($status === RoomBookingStatus::Approved && $end->greaterThan($now))
                || ($status === RoomBookingStatus::Submitted && $start->greaterThan($now));
        }

        if ($scope === self::SCOPE_HISTORY) {
            return ($status === RoomBookingStatus::Approved && $end->lessThanOrEqualTo($now))
                || (in_array($status, [
                    RoomBookingStatus::Submitted,
                    RoomBookingStatus::RevisionRequested,
                ], true) && $start->lessThanOrEqualTo($now));
        }

        return $status->value === $scope;
    }

    /**
     * @param  Collection<int, RoomBookingRequest>  $bookings
     * @return array{counts_by_status: array<string, int>, active_total: int, history_total: int}
     */
    public function summarize(Collection $bookings): array
    {
        $summary = [
            'counts_by_status' => [],
            'active_total' => 0,
            'history_total' => 0,
        ];
        $now = now(config('app.timezone'));

        foreach ($bookings as $booking) {
            foreach ($this->slotRanges($booking) as $slot) {
                $status = $booking->status->value;
                $summary['counts_by_status'][$status] = ($summary['counts_by_status'][$status] ?? 0) + 1;
                if ($this->includesSlot($booking->status, $slot['start_at'], $slot['end_at'], self::SCOPE_ACTIVE, $now)) {
                    $summary['active_total']++;
                }
                if ($this->includesSlot($booking->status, $slot['start_at'], $slot['end_at'], self::SCOPE_HISTORY, $now)) {
                    $summary['history_total']++;
                }
            }
        }

        return $summary;
    }

    private function applyOccurrenceOrLegacyBoundary(
        Builder $query,
        string $column,
        string $operator,
        DateTimeInterface $boundary,
    ): void {
        $query->where(function (Builder $temporalQuery) use ($column, $operator, $boundary) {
            $temporalQuery
                ->whereHas('occurrences', fn (Builder $occurrence) => $occurrence
                    ->where($column, $operator, $boundary))
                ->orWhere(fn (Builder $legacy) => $legacy
                    ->whereDoesntHave('occurrences')
                    ->where($column, $operator, $boundary));
        });
    }
}
