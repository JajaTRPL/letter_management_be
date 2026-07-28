<?php

namespace App\Services\Analytics;

use App\Models\Department;
use App\Models\Laboratory;
use App\Models\StudyProgram;
use App\Services\Notifications\WorkflowReviewSlaPolicyService;
use App\Services\SuratAnalyticsService;
use App\Support\Analytics\ReviewAnalyticsPeriod;
use App\Support\DurationHumanizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The façade every analytics surface talks to. It computes nothing itself —
 * collectors gather, the aggregator does maths, the confidence policy decides
 * what may be claimed, and the SLA policy supplies the only threshold in the
 * system. This class orchestrates and caches, which is why it stays small.
 *
 * Computation is on the fly rather than from a snapshot table. For a single
 * department the twelve-month universe is roughly thirteen thousand narrow rows,
 * which is milliseconds in PHP; a snapshot would instead introduce a second
 * source of truth able to disagree with the live SLA scanner — precisely the
 * failure this design exists to prevent. Cache keys are deliberately
 * snapshot-shaped so materialising later is a drop-in with no contract change.
 * Revisit above roughly 100k samples per window.
 */
final class ReviewPerformanceService
{
    private const CACHE_PREFIX = 'review-perf:v1';

    /** @param iterable<ReviewDurationCollector> $collectors */
    public function __construct(
        private iterable $collectors,
        private ReviewDurationAggregator $aggregator,
        private ReviewSampleConfidencePolicy $confidence,
        private WorkflowReviewSlaPolicyService $policies,
    ) {}

    /** SuperAdmin summary: every scope, every stage, with period comparison. */
    public function summary(ReviewAnalyticsPeriod $period): array
    {
        return $this->remember("summary:{$period->key}", fn () => [
            'period' => $period->toArray(),
            'previous_period' => $period->previous()->toArray(),
            'generated_at' => Carbon::now(config('app.timezone'))->toIso8601String(),
            // Published so the UI can say "diperbarui N menit lalu" instead of
            // silently re-fetching a cached figure every 30 seconds.
            'cache_ttl_seconds' => SuratAnalyticsService::CACHE_TTL,
            'basis' => $this->basis(),
            'scopes' => $this->scopePayloads($period),
        ]);
    }

    /** Per-unit drill-down for one stage. */
    public function breakdown(string $scope, string $stage, ReviewAnalyticsPeriod $period): ?array
    {
        $collector = $this->collectorFor($scope);
        if (! $collector || ! array_key_exists($stage, $collector->stages())) {
            return null;
        }

        return $this->remember("breakdown:{$scope}:{$stage}:{$period->key}", function () use ($collector, $scope, $stage, $period) {
            $policy = $this->policies->current($scope);
            $samples = $collector->collect($period->start, $period->end)
                ->filter(fn (ReviewDurationSample $s) => $s->stage === $stage);

            $dimension = $collector->unitDimensionFor($stage);
            $grouped = $this->aggregator->byUnit($samples);

            $units = [];
            $unassigned = 0;
            foreach ($grouped as $key => $group) {
                $unitId = $group->first()->unitId;
                if ($unitId === null && $dimension !== ReviewScope::DIMENSION_GLOBAL) {
                    // Never fold "we don't know whose this is" into a real unit's
                    // score — that would put someone else's delay on their row.
                    $unassigned += $group->count();

                    continue;
                }
                $units[] = [
                    'unit_id' => $unitId,
                    'unit_label' => $this->unitLabel($dimension, $unitId),
                    'metric' => $this->confidence->present($stage, $this->aggregator->summarise($group), $policy),
                ];
            }

            // Volume descending is the default on purpose: a table sorted by
            // duration is a leaderboard no matter what the heading calls it.
            usort($units, fn ($a, $b) => $b['metric']['count'] <=> $a['metric']['count']);

            return [
                'scope' => $scope,
                'scope_label' => $this->scopeLabel($scope),
                'stage' => $stage,
                'stage_label' => $collector->stages()[$stage],
                'unit_dimension' => $dimension,
                'unit_dimension_label' => ReviewScope::DIMENSION_LABELS[$dimension] ?? '',
                'period' => $period->toArray(),
                'sort' => 'volume_desc',
                'units' => $units,
                'unassigned' => [
                    'count' => $unassigned,
                    'note' => 'Pengajuan yang belum terhubung ke prodi/lab',
                ],
            ];
        });
    }

    /** Time series for one stage, optionally narrowed to one unit. */
    public function trend(string $scope, string $stage, ReviewAnalyticsPeriod $period, ?int $unitId = null): ?array
    {
        $collector = $this->collectorFor($scope);
        if (! $collector || ! array_key_exists($stage, $collector->stages())) {
            return null;
        }

        return $this->remember("trend:{$scope}:{$stage}:{$period->key}:".($unitId ?? 'all'), function () use ($collector, $scope, $stage, $period, $unitId) {
            $bucket = $period->trendBucket();
            $samples = $collector->collect($period->start, $period->end)
                ->filter(fn (ReviewDurationSample $s) => $s->stage === $stage
                    && ($unitId === null || $s->unitId === $unitId));

            $points = $samples
                ->groupBy(fn (ReviewDurationSample $s) => $bucket === 'day'
                    ? $s->decidedAt->format('Y-m-d')
                    : $s->decidedAt->format('Y-m'))
                ->map(function (Collection $group, string $key) use ($bucket) {
                    $stats = $this->aggregator->summarise($group);
                    $source = $this->confidence->sourceFor($stats['count']);
                    // A bucket below the sample floor reports its count but no
                    // duration, so the chart can leave a gap. Interpolating a
                    // line through a two-file month invents a trend.
                    $measured = $source === ReviewSampleConfidencePolicy::SOURCE_DYNAMIC;

                    return [
                        'key' => $key,
                        'label' => $this->bucketLabel($key, $bucket),
                        'count' => $stats['count'],
                        'median_seconds' => $measured ? $stats['median_seconds'] : null,
                        'median_label' => $measured ? DurationHumanizer::precise($stats['median_seconds']) : null,
                        'source' => $source,
                    ];
                })
                ->sortKeys()
                ->values()
                ->all();

            return [
                'scope' => $scope,
                'stage' => $stage,
                'stage_label' => $collector->stages()[$stage],
                'period' => $period->toArray(),
                'bucket' => $bucket,
                'points' => $points,
            ];
        });
    }

    /**
     * A reviewer's own stage. Returns only their scope's figures — there is no
     * parameter by which another unit's numbers could be requested, and none are
     * ever included in the payload.
     */
    public function selfView(ReviewScope $scope, ReviewAnalyticsPeriod $period): ?array
    {
        $collector = $this->collectorFor($scope->scope);
        if (! $collector) {
            return null;
        }

        $key = "self:{$scope->scope}:{$scope->stage}:{$scope->unitType}:".($scope->unitId ?? 'all').":{$period->key}";

        return $this->remember($key, function () use ($collector, $scope, $period) {
            $policy = $this->policies->current($scope->scope);
            $current = $collector->collect($period->start, $period->end)
                ->filter(fn (ReviewDurationSample $s) => $scope->matches($s));
            $stats = $this->aggregator->summarise($current);

            $previousWindow = $period->previous();
            $previous = $collector->collect($previousWindow->start, $previousWindow->end)
                ->filter(fn (ReviewDurationSample $s) => $scope->matches($s));

            $waiting = $collector->waitingNow($policy['overdue_minutes'])[$scope->stage]
                ?? ['count' => 0, 'over_overdue_count' => 0];

            return [
                'eligible' => true,
                'scope' => $scope->scope,
                'scope_label' => $this->scopeLabel($scope->scope),
                'stage' => $scope->stage,
                'stage_label' => $collector->stages()[$scope->stage] ?? $scope->stage,
                'unit_label' => $scope->isNarrowed()
                    ? $this->unitLabel($scope->unitType, $scope->unitId)
                    : $this->scopeLabel($scope->scope),
                'period' => $period->toArray(),
                'metric' => $this->confidence->present($scope->stage, $stats, $policy, $collector->discarded()),
                'comparison' => $this->comparison($stats, $this->aggregator->summarise($previous)),
                'waiting_now' => $waiting + ['action_label' => 'Lihat Antrean'],
                'note' => 'Ringkasan tahap ini, bukan penilaian per orang.',
            ];
        });
    }

    /** @return list<array<string,mixed>> */
    private function scopePayloads(ReviewAnalyticsPeriod $period): array
    {
        $payloads = [];

        foreach ($this->collectors as $collector) {
            $scope = $collector->scope();
            $policy = $this->policies->current($scope);

            $current = $collector->collect($period->start, $period->end);
            $discarded = $collector->discarded();
            $previousWindow = $period->previous();
            $previous = $collector->collect($previousWindow->start, $previousWindow->end);

            $currentByStage = $this->aggregator->byStage($current);
            $previousByStage = $this->aggregator->byStage($previous);
            $waiting = $collector->waitingNow($policy['overdue_minutes']);

            $stages = [];
            foreach ($collector->stages() as $stage => $label) {
                $stats = $this->aggregator->summarise($currentByStage->get($stage) ?? collect());
                $priorStats = $this->aggregator->summarise($previousByStage->get($stage) ?? collect());

                $stages[] = [
                    'stage' => $stage,
                    'stage_label' => $label,
                    'unit_dimension' => $collector->unitDimensionFor($stage),
                    'metric' => $this->confidence->present($stage, $stats, $policy, $discarded),
                    'comparison' => $this->comparison($stats, $priorStats),
                    'waiting_now' => $waiting[$stage] ?? ['count' => 0, 'over_overdue_count' => 0],
                ];
            }

            $payloads[] = [
                'scope' => $scope,
                'scope_label' => $this->scopeLabel($scope),
                'sla' => [
                    'enabled' => (bool) $policy['enabled'],
                    'warning_label' => DurationHumanizer::coarse((int) $policy['warning_minutes']),
                    'overdue_label' => DurationHumanizer::coarse((int) $policy['overdue_minutes']),
                ],
                'stages' => $stages,
            ];
        }

        return $payloads;
    }

    /**
     * Phrased as a fact about the stage, never as praise or blame. "lebih cepat"
     * and "lebih lama" both read neutrally; "membaik"/"memburuk" would not.
     */
    private function comparison(array $current, array $previous): ?array
    {
        $delta = $this->aggregator->delta($current['median_seconds'] ?? null, $previous['median_seconds'] ?? null);
        if ($delta === null
            || $current['count'] < $this->confidence->minSample()
            || $previous['count'] < $this->confidence->minSample()) {
            return null;
        }

        if (abs($delta) < 60) {
            return ['direction' => 'steady', 'label' => 'Setara dengan periode sebelumnya'];
        }

        $faster = $delta < 0;

        return [
            'direction' => $faster ? 'faster' : 'slower',
            'label' => DurationHumanizer::precise(abs($delta)).($faster ? ' lebih cepat' : ' lebih lama').' dari periode sebelumnya',
        ];
    }

    private function basis(): array
    {
        return [
            'measures' => 'Dihitung dari pemeriksaan terakhir tiap pengajuan.',
            'excludes' => 'Waktu mahasiswa merevisi pengajuannya tidak ikut dihitung.',
            'min_sample' => $this->confidence->minSample(),
            'max_duration_days' => $this->confidence->maxDurationDays(),
        ];
    }

    private function collectorFor(string $scope): ?ReviewDurationCollector
    {
        foreach ($this->collectors as $collector) {
            if ($collector->scope() === $scope) {
                return $collector;
            }
        }

        return null;
    }

    private function scopeLabel(string $scope): string
    {
        return $scope === WorkflowReviewSlaPolicyService::SCOPE_LETTER
            ? 'Surat Administrasi'
            : 'Peminjaman Ruangan';
    }

    private function unitLabel(string $dimension, ?int $unitId): string
    {
        if ($unitId === null) {
            return 'Seluruh unit';
        }

        $model = match ($dimension) {
            ReviewScope::DIMENSION_STUDY_PROGRAM => StudyProgram::find($unitId),
            ReviewScope::DIMENSION_DEPARTMENT => Department::find($unitId),
            ReviewScope::DIMENSION_LABORATORY => Laboratory::find($unitId),
            default => null,
        };

        if (! $model) {
            return 'Unit tidak dikenal';
        }

        return trim(($model->code ? $model->code.' — ' : '').$model->name);
    }

    private function bucketLabel(string $key, string $bucket): string
    {
        $format = $bucket === 'day' ? 'Y-m-d' : 'Y-m';
        $date = Carbon::createFromFormat($format, $key, config('app.timezone'));

        return $bucket === 'day'
            ? $date->translatedFormat('d M')
            : $date->translatedFormat('M Y');
    }

    private function remember(string $key, callable $callback): array
    {
        return Cache::remember(self::CACHE_PREFIX.':'.$key, SuratAnalyticsService::CACHE_TTL, $callback);
    }
}
