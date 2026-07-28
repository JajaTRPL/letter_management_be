<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\ReviewDurationAggregator;
use App\Services\Analytics\ReviewDurationSample;
use App\Services\Analytics\ReviewSampleConfidencePolicy;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The aggregator and the confidence policy are pure — no database, no clock, no
 * config — so their behaviour is pinned here directly rather than inferred from
 * fixtures three layers away.
 */
class ReviewDurationAggregatorTest extends TestCase
{
    private ReviewDurationAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aggregator = new ReviewDurationAggregator();
    }

    public function test_empty_input_reports_nothing_rather_than_zero(): void
    {
        $stats = $this->aggregator->summarise(collect());

        $this->assertSame(0, $stats['count']);
        // Null, never 0 — a zeroed duration reads as "approved instantly", which
        // is the exact misreading this feature exists to stop.
        $this->assertNull($stats['median_seconds']);
        $this->assertNull($stats['average_seconds']);
        $this->assertNull($stats['p90_seconds']);
    }

    public function test_median_of_an_odd_count_is_the_middle_value(): void
    {
        $stats = $this->aggregator->summarise($this->samples([100, 300, 200]));

        $this->assertSame(200, $stats['median_seconds']);
    }

    public function test_median_of_an_even_count_averages_the_middle_pair(): void
    {
        $stats = $this->aggregator->summarise($this->samples([100, 200, 300, 400]));

        $this->assertSame(250, $stats['median_seconds']);
    }

    public function test_median_resists_a_single_abandoned_outlier_while_the_mean_does_not(): void
    {
        // Nine files decided in about an hour, one forgotten over a semester break.
        $durations = array_merge(array_fill(0, 9, 3600), [30 * 86400]);
        $stats = $this->aggregator->summarise($this->samples($durations));

        $this->assertSame(3600, $stats['median_seconds'], 'The median describes the typical file.');
        $this->assertGreaterThan(
            10 * $stats['median_seconds'],
            $stats['average_seconds'],
            'The mean is dragged an order of magnitude away — why it is not the headline.',
        );
    }

    public function test_p90_is_a_real_observed_duration_not_an_interpolation(): void
    {
        $durations = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
        $stats = $this->aggregator->summarise($this->samples($durations));

        $this->assertContains($stats['p90_seconds'], $durations);
        $this->assertSame(90, $stats['p90_seconds']);
    }

    public function test_revisions_are_counted_separately_but_still_measured(): void
    {
        $samples = collect([
            $this->sample(100, ReviewDurationSample::DECISION_APPROVED),
            $this->sample(200, ReviewDurationSample::DECISION_REVISION),
            $this->sample(300, ReviewDurationSample::DECISION_REJECTED),
        ]);

        $stats = $this->aggregator->summarise($samples);

        $this->assertSame(3, $stats['count'], 'Returning a file is still a review.');
        $this->assertSame(1, $stats['revision_count']);
    }

    public function test_delta_is_null_unless_both_windows_have_a_median(): void
    {
        $this->assertNull($this->aggregator->delta(null, 100));
        $this->assertNull($this->aggregator->delta(100, null));
        $this->assertSame(-50, $this->aggregator->delta(100, 150));
    }

    public function test_a_sample_cannot_be_built_from_a_backwards_span(): void
    {
        $at = Carbon::parse('2026-06-01 10:00:00');

        $this->assertNull(ReviewDurationSample::make(
            'letter', 'prodi', 'global', null,
            $at, $at->copy()->subHour(), ReviewDurationSample::DECISION_APPROVED,
        ), 'The stale-timestamp hazard must not become a negative duration.');

        $this->assertNull(ReviewDurationSample::make(
            'letter', 'prodi', 'global', null,
            $at, $at, ReviewDurationSample::DECISION_APPROVED,
        ), 'A zero-second review is not a measurement.');
    }

    // ── confidence policy ───────────────────────────────────────────────────

    public function test_source_has_three_distinct_states_around_the_sample_floor(): void
    {
        $policy = new ReviewSampleConfidencePolicy();
        $floor = $policy->minSample();

        $this->assertSame(ReviewSampleConfidencePolicy::SOURCE_NONE, $policy->sourceFor(0));
        $this->assertSame(ReviewSampleConfidencePolicy::SOURCE_FALLBACK, $policy->sourceFor(1));
        $this->assertSame(ReviewSampleConfidencePolicy::SOURCE_FALLBACK, $policy->sourceFor($floor - 1));
        $this->assertSame(ReviewSampleConfidencePolicy::SOURCE_DYNAMIC, $policy->sourceFor($floor));
    }

    public function test_a_below_floor_stage_shows_an_estimate_and_withholds_the_number(): void
    {
        $policy = new ReviewSampleConfidencePolicy();
        $stats = $this->aggregator->summarise($this->samples([3600, 7200]));

        $presented = $policy->present('prodi', $stats, $this->enabledPolicy());

        $this->assertSame(ReviewSampleConfidencePolicy::SOURCE_FALLBACK, $presented['source']);
        $this->assertNull($presented['median_seconds'], 'An unreliable average must not be shown as a figure.');
        $this->assertNull($presented['median_label']);
        $this->assertNotNull($presented['estimate_label']);
        $this->assertStringContainsString('Baru 2 dari 5', $presented['sample_note']);
        $this->assertSame(ReviewSampleConfidencePolicy::STATUS_UNKNOWN, $presented['status']);
    }

    public function test_an_empty_stage_offers_no_estimate_at_all(): void
    {
        $policy = new ReviewSampleConfidencePolicy();

        $presented = $policy->present('prodi', $this->aggregator->summarise(collect()), $this->enabledPolicy());

        $this->assertSame(ReviewSampleConfidencePolicy::SOURCE_NONE, $presented['source']);
        $this->assertNull($presented['estimate_label'], 'An estimate for a period with no activity is noise.');
        $this->assertSame('Belum ada pengajuan yang selesai di periode ini.', $presented['sample_note']);
    }

    public function test_a_disabled_deadline_yields_no_judgement(): void
    {
        $policy = new ReviewSampleConfidencePolicy();
        $stats = $this->aggregator->summarise($this->samples(array_fill(0, 6, 40 * 86400 / 40)));

        $presented = $policy->present('prodi', $stats, [
            'enabled' => false, 'warning_minutes' => 60, 'overdue_minutes' => 120,
        ]);

        $this->assertSame(ReviewSampleConfidencePolicy::STATUS_UNRATED, $presented['status']);
        $this->assertSame('Batas waktu belum diaktifkan', $presented['status_label']);
    }

    public function test_status_bands_follow_the_configured_thresholds_only(): void
    {
        $policy = new ReviewSampleConfidencePolicy();
        $thresholds = $this->enabledPolicy(); // warning 60 min, overdue 120 min

        $within = $policy->present('prodi', $this->aggregator->summarise($this->samples(array_fill(0, 5, 30 * 60))), $thresholds);
        $approaching = $policy->present('prodi', $this->aggregator->summarise($this->samples(array_fill(0, 5, 90 * 60))), $thresholds);
        $beyond = $policy->present('prodi', $this->aggregator->summarise($this->samples(array_fill(0, 5, 180 * 60))), $thresholds);

        $this->assertSame(ReviewSampleConfidencePolicy::STATUS_WITHIN, $within['status']);
        $this->assertSame(ReviewSampleConfidencePolicy::STATUS_APPROACHING, $approaching['status']);
        $this->assertSame(ReviewSampleConfidencePolicy::STATUS_BEYOND, $beyond['status']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param list<int> $durations */
    private function samples(array $durations)
    {
        return collect($durations)->map(fn (int $d) => $this->sample($d));
    }

    private function sample(int $seconds, string $decision = ReviewDurationSample::DECISION_APPROVED): ReviewDurationSample
    {
        $start = Carbon::parse('2026-06-01 09:00:00');

        return ReviewDurationSample::make(
            'letter', 'prodi', 'global', null,
            $start, $start->copy()->addSeconds($seconds), $decision,
        );
    }

    private function enabledPolicy(): array
    {
        return ['enabled' => true, 'warning_minutes' => 60, 'overdue_minutes' => 120];
    }
}
