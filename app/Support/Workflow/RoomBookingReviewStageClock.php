<?php

namespace App\Support\Workflow;

use App\Enums\RoomType;
use App\Models\Room;
use App\Models\RoomBookingWorkflowEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE room-booking review clock, read from the append-only workflow ledger.
 *
 * `currentWaitingSince()` is extracted verbatim from RoomBookingReviewSlaScanner
 * so reminders and analytics measure from the identical instant — see the note on
 * LetterReviewStageClock for why that matters.
 *
 * Bookings are the easier of the two domains: because room_booking_workflow_events
 * is immutable and every row carries `submission_iteration`, a booking that was
 * submitted → revised → resubmitted → approved yields TWO complete review cycles
 * with two independent clocks. Resubmission handling is therefore structural
 * rather than a special case. Letters have no such ledger and can only report
 * their most recent cycle.
 */
final class RoomBookingReviewStageClock
{
    public const STAGE_SARPRAS = 'sarpras';

    public const STAGE_KALAB = 'kalab';

    public const STAGES = [self::STAGE_SARPRAS, self::STAGE_KALAB];

    /** Events that START a review wait. A resubmit restarts the clock. */
    public const ENTRY_EVENTS = [
        RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED,
        RoomBookingWorkflowEvent::EVENT_BOOKING_RESUBMITTED,
    ];

    /**
     * Events that end a wait WITH a reviewer decision.
     *
     * Deliberately excluded:
     *  - cancelled / withdrawn: the wait ends, but nobody reviewed anything.
     *    Counting them would credit reviewers for applicants giving up.
     *  - review_started: means "a reviewer opened the file", not "decided". The
     *    SLA scanner ignores it too, and measuring from it would hide exactly the
     *    queue latency this feature exists to reveal.
     */
    public const DECISION_EVENTS = [
        RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED,
        RoomBookingWorkflowEvent::EVENT_BOOKING_REJECTED,
        RoomBookingWorkflowEvent::EVENT_REVISION_REQUESTED,
    ];

    /**
     * Entry-into-current-review timestamp for bookings still waiting = the latest
     * submit/resubmit event. Falls back to created_at at the CALL SITE only if the
     * ledger has no submit event (legacy rows).
     *
     * @param  list<int>  $bookingIds
     * @return array<int, Carbon>
     */
    public static function currentWaitingSince(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        return RoomBookingWorkflowEvent::query()
            ->select('room_booking_request_id')
            ->selectRaw('MAX(occurred_at) as waiting_since')
            ->whereIn('room_booking_request_id', $bookingIds)
            ->whereIn('event_type', self::ENTRY_EVENTS)
            ->groupBy('room_booking_request_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->room_booking_request_id => Carbon::parse($row->waiting_since, config('app.timezone')),
            ])
            ->all();
    }

    /**
     * Every CLOSED review cycle decided inside the window, one row per
     * (booking, submission_iteration).
     *
     * A cycle belongs to the period in which it was DECIDED, not submitted — the
     * question being answered is "how fast were decisions made this month", so a
     * file submitted in June and decided in July belongs to July.
     *
     * No date arithmetic in SQL: the window is a plain `between` on a timestamp
     * column and all pairing happens in PHP, so this runs identically on sqlite
     * (tests) and Postgres (production).
     *
     * @return Collection<int, array{booking_id:int, iteration:int, entry:Carbon, exit:Carbon, decision:string}>
     */
    public static function closedCycles(Carbon $from, Carbon $to): Collection
    {
        $decisions = RoomBookingWorkflowEvent::query()
            ->select(['room_booking_request_id', 'submission_iteration', 'event_type', 'occurred_at'])
            ->whereIn('event_type', self::DECISION_EVENTS)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->get();

        if ($decisions->isEmpty()) {
            return collect();
        }

        // FIRST decision per cycle: a later cancellation or a second decision on
        // the same iteration must not stretch the measured duration.
        $exits = [];
        foreach ($decisions as $event) {
            $key = self::cycleKey($event);
            if (! isset($exits[$key])) {
                $exits[$key] = $event;
            }
        }

        $entries = self::entriesFor($decisions->pluck('room_booking_request_id')->unique()->all());

        return collect($exits)
            ->map(function ($event, string $key) use ($entries) {
                $entry = $entries[$key] ?? null;
                $exit = self::toCarbon($event->occurred_at);
                if (! $entry || ! $exit || $exit->lessThan($entry)) {
                    // No submit event for this iteration (legacy row), or a clock
                    // that runs backwards. Never guess a start time — the caller
                    // counts these as discarded rather than inventing a duration.
                    return null;
                }

                return [
                    'booking_id' => (int) $event->room_booking_request_id,
                    'iteration' => (int) ($event->submission_iteration ?? 1),
                    'entry' => $entry,
                    'exit' => $exit,
                    'decision' => (string) $event->event_type,
                ];
            })
            ->filter()
            ->values();
    }

    public static function stageKeyFor(?Room $room): string
    {
        return $room?->type === RoomType::Classroom ? self::STAGE_SARPRAS : self::STAGE_KALAB;
    }

    /**
     * Latest entry event per (booking, iteration) — "latest" because a cycle's
     * clock starts at its most recent submit, matching currentWaitingSince().
     *
     * @param  list<int>  $bookingIds
     * @return array<string, Carbon>
     */
    private static function entriesFor(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $entries = [];
        RoomBookingWorkflowEvent::query()
            ->select(['room_booking_request_id', 'submission_iteration', 'occurred_at'])
            ->whereIn('room_booking_request_id', $bookingIds)
            ->whereIn('event_type', self::ENTRY_EVENTS)
            ->orderBy('occurred_at')
            ->get()
            ->each(function ($event) use (&$entries) {
                $at = self::toCarbon($event->occurred_at);
                if ($at) {
                    $entries[self::cycleKey($event)] = $at;
                }
            });

        return $entries;
    }

    private static function cycleKey(object $event): string
    {
        return $event->room_booking_request_id.':'.((int) ($event->submission_iteration ?? 1));
    }

    private static function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return $value ? Carbon::parse($value, config('app.timezone')) : null;
    }
}
