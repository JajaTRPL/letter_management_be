<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;

/**
 * Pure math over samples. No database, no Eloquent, no config, no clock — which
 * is what lets the statistical behaviour be pinned by fast unit tests instead of
 * inferred from fixtures.
 *
 * The headline figure is the MEDIAN, not the mean, and that is a product
 * decision as much as a statistical one: one request abandoned over a semester
 * break drags a mean into absurdity and makes an entire study program look
 * negligent for a whole reporting period. The mean is still reported alongside,
 * because the gap between them is itself informative — a mean far above the
 * median means a few files are stuck, which is a different problem from a stage
 * that is uniformly slow.
 */
final class ReviewDurationAggregator
{
    /**
     * @param  Collection<int, ReviewDurationSample>  $samples
     * @return array{count:int, revision_count:int, median_seconds:?int, average_seconds:?int, p90_seconds:?int}
     */
    public function summarise(Collection $samples): array
    {
        $count = $samples->count();
        if ($count === 0) {
            return [
                'count' => 0,
                'revision_count' => 0,
                'median_seconds' => null,
                'average_seconds' => null,
                'p90_seconds' => null,
            ];
        }

        $seconds = $samples->map(fn (ReviewDurationSample $s) => $s->seconds)->sort()->values()->all();

        return [
            'count' => $count,
            'revision_count' => $samples->filter(fn (ReviewDurationSample $s) => $s->isRevision())->count(),
            'median_seconds' => $this->median($seconds),
            'average_seconds' => (int) round(array_sum($seconds) / $count),
            'p90_seconds' => $this->percentile($seconds, 0.9),
        ];
    }

    /**
     * Group by organisational unit for the drill-down.
     *
     * @param  Collection<int, ReviewDurationSample>  $samples
     * @return Collection<string, Collection<int, ReviewDurationSample>>
     */
    public function byUnit(Collection $samples): Collection
    {
        return $samples->groupBy(fn (ReviewDurationSample $s) => $s->unitKey());
    }

    /**
     * @param  Collection<int, ReviewDurationSample>  $samples
     * @return Collection<string, Collection<int, ReviewDurationSample>>
     */
    public function byStage(Collection $samples): Collection
    {
        return $samples->groupBy(fn (ReviewDurationSample $s) => $s->stage);
    }

    /**
     * Signed change against the previous comparable window, in seconds.
     * Negative = faster now. Null when either side has nothing to compare.
     */
    public function delta(?int $currentSeconds, ?int $previousSeconds): ?int
    {
        if ($currentSeconds === null || $previousSeconds === null) {
            return null;
        }

        return $currentSeconds - $previousSeconds;
    }

    /** @param list<int> $sorted */
    private function median(array $sorted): int
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? $sorted[$mid]
            : (int) round(($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }

    /**
     * Nearest-rank percentile — no interpolation, so every reported value is a
     * duration that actually happened rather than one synthesised between two
     * real files.
     *
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $fraction): int
    {
        $n = count($sorted);
        $rank = (int) ceil($fraction * $n);

        return $sorted[max(0, min($n - 1, $rank - 1))];
    }
}
