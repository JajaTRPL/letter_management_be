<?php

namespace App\Services\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The ONE seam where domain knowledge enters the metrics engine.
 *
 * Everything downstream — aggregation, confidence, SLA comparison, payload
 * shaping — operates on ReviewDurationSample and knows nothing about letters or
 * bookings. Adding a third reviewable domain means writing one implementation of
 * this interface and tagging it in AppServiceProvider; no other file changes.
 */
interface ReviewDurationCollector
{
    /** WorkflowReviewSlaPolicyService scope this collector reports under. */
    public function scope(): string;

    /** Ordered stage key => Indonesian label, as the UI should show them. */
    public function stages(): array;

    /** Unit dimension for a stage: see ReviewScope::DIMENSION_*. */
    public function unitDimensionFor(string $stage): string;

    /**
     * Every review cycle DECIDED within the window.
     *
     * A cycle belongs to the period in which it was decided, not submitted: the
     * question is "how fast were decisions made this month", so a file submitted
     * in June and decided in July counts toward July.
     *
     * @return Collection<int, ReviewDurationSample>
     */
    public function collect(Carbon $from, Carbon $to): Collection;

    /**
     * Discard tallies from the most recent collect(), keyed by reason
     * (`negative`, `outlier`). Surfaced to SuperAdmin as a data-quality signal —
     * a silent undercount would make the whole metric untrustworthy.
     *
     * @return array<string, int>
     */
    public function discarded(): array;

    /**
     * Files WAITING at each stage right now, and how many are past the overdue
     * threshold. This is the actionable half: an average is history, a queue is
     * something a reviewer can still do something about today.
     *
     * @return array<string, array{count:int, over_overdue_count:int}>
     */
    public function waitingNow(int $overdueMinutes, ?Carbon $now = null): array;
}
