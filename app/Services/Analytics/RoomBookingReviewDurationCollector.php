<?php

namespace App\Services\Analytics;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Services\Notifications\WorkflowReviewSlaPolicyService as Sla;
use App\Support\Workflow\RoomBookingReviewStageClock as Stage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Room-booking samples, drawn from the append-only workflow ledger.
 *
 * Strictly better data than the letter side: because every event carries
 * `submission_iteration`, each review cycle is measured independently, so a
 * booking that bounced twice contributes two honest samples instead of one
 * smeared average. The applicant's own revision time falls between cycles and is
 * charged to nobody — which is the behaviour reviewers will check first.
 */
final class RoomBookingReviewDurationCollector implements ReviewDurationCollector
{
    private const STAGE_LABELS = [
        Stage::STAGE_SARPRAS => 'Sarana & Prasarana (Ruang Kelas)',
        Stage::STAGE_KALAB => 'Kepala Laboratorium',
    ];

    private const DECISION_MAP = [
        RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED => ReviewDurationSample::DECISION_APPROVED,
        RoomBookingWorkflowEvent::EVENT_BOOKING_REJECTED => ReviewDurationSample::DECISION_REJECTED,
        RoomBookingWorkflowEvent::EVENT_REVISION_REQUESTED => ReviewDurationSample::DECISION_REVISION,
    ];

    /** @var array<string,int> */
    private array $discarded = ['negative' => 0, 'outlier' => 0];

    public function __construct(private ReviewSampleConfidencePolicy $confidence) {}

    public function scope(): string
    {
        return Sla::SCOPE_ROOM_BOOKING;
    }

    public function stages(): array
    {
        return self::STAGE_LABELS;
    }

    public function unitDimensionFor(string $stage): string
    {
        // Sarpras is one faculty-wide team; laboratories each have their own head.
        return $stage === Stage::STAGE_KALAB
            ? ReviewScope::DIMENSION_LABORATORY
            : ReviewScope::DIMENSION_GLOBAL;
    }

    public function discarded(): array
    {
        return $this->discarded;
    }

    public function collect(Carbon $from, Carbon $to): Collection
    {
        $this->discarded = ['negative' => 0, 'outlier' => 0];

        $cycles = Stage::closedCycles($from, $to);
        if ($cycles->isEmpty()) {
            return collect();
        }

        $bookings = RoomBookingRequest::query()
            ->with('room')
            ->whereIn('id', $cycles->pluck('booking_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return $cycles
            ->map(function (array $cycle) use ($bookings) {
                $booking = $bookings->get($cycle['booking_id']);
                if (! $booking) {
                    return null;
                }

                $stage = Stage::stageKeyFor($booking->room);
                $dimension = $this->unitDimensionFor($stage);

                $sample = ReviewDurationSample::make(
                    $this->scope(),
                    $stage,
                    $dimension,
                    $dimension === ReviewScope::DIMENSION_LABORATORY
                        ? ($booking->room?->owning_laboratory_id !== null ? (int) $booking->room->owning_laboratory_id : null)
                        : null,
                    $cycle['entry'],
                    $cycle['exit'],
                    self::DECISION_MAP[$cycle['decision']] ?? ReviewDurationSample::DECISION_APPROVED,
                );

                if (! $sample) {
                    $this->discarded['negative']++;

                    return null;
                }
                if ($sample->seconds > $this->confidence->maxDurationSeconds()) {
                    $this->discarded['outlier']++;

                    return null;
                }

                return $sample;
            })
            ->filter()
            ->values();
    }

    public function waitingNow(int $overdueMinutes, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now(config('app.timezone')))->copy();
        $waiting = [];
        foreach (array_keys(self::STAGE_LABELS) as $stage) {
            $waiting[$stage] = ['count' => 0, 'over_overdue_count' => 0];
        }

        $bookings = RoomBookingRequest::query()
            ->with('room')
            ->where('status', RoomBookingStatus::Submitted->value)
            ->get();

        $waitingSince = Stage::currentWaitingSince($bookings->pluck('id')->all());

        foreach ($bookings as $booking) {
            $stage = Stage::stageKeyFor($booking->room);
            $waiting[$stage]['count']++;

            $since = $waitingSince[$booking->id] ?? $booking->created_at;
            if ($since && intdiv($now->getTimestamp() - $since->getTimestamp(), 60) >= $overdueMinutes) {
                $waiting[$stage]['over_overdue_count']++;
            }
        }

        return $waiting;
    }
}
