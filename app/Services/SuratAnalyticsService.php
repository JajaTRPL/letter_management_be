<?php

namespace App\Services;

use App\Models\ScholarshipApplication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SuratAnalyticsService
{
    /**
     * Minimum number of completed surat required to use dynamic duration.
     */
    private const MIN_SAMPLE_SIZE = 5;

    /**
     * Maximum duration in days to consider valid (outlier filter).
     */
    private const MAX_DURATION_DAYS = 30;

    /**
     * Cache TTL in seconds (10 minutes).
     */
    private const CACHE_TTL = 600;

    /**
     * Static fallback labels when insufficient data.
     */
    private const FALLBACK_LABELS = [
        'beasiswa'    => '3–7 Hari Kerja',
        'aktif'       => '1–3 Hari Kerja',
        'magang'      => '2–5 Hari Kerja',
        'luar_negeri' => '2–4 Hari Kerja',
    ];

    /**
     * Get average duration per surat type with progressive fallback.
     *
     * @return array<string, array{value: float|null, source: string, label: string|null}>
     */
    public function getAverageDurationByType(): array
    {
        return Cache::remember('surat_average_durations', self::CACHE_TTL, function () {
            return $this->calculateDurations();
        });
    }

    /**
     * Calculate durations from completed scholarship applications.
     *
     * Uses a two-pass approach:
     * 1. First try last 30 days of data (time-relevant)
     * 2. For types with < MIN_SAMPLE_SIZE, fall back to ALL historical data
     */
    private function calculateDurations(): array
    {
        // Pass 1: Query last 30 days
        $recentResults = $this->queryCompletedDurations(now()->subDays(30));

        // Pass 2: Query ALL history (only used as fallback)
        $allResults = null;

        // Build response with fallback logic
        $response = [];

        foreach (self::FALLBACK_LABELS as $type => $fallbackLabel) {
            $group = $recentResults->get($type);
            $count = $group ? $group->count() : 0;

            // If recent data is sufficient, use it
            if ($count >= self::MIN_SAMPLE_SIZE) {
                $avgDuration = round($group->avg('duration'), 1);
                $response[$type] = [
                    'value'  => $avgDuration,
                    'source' => 'dynamic',
                    'label'  => null,
                    'count'  => $count,
                ];
                continue;
            }

            // Fallback: try ALL historical data for this type
            if ($allResults === null) {
                $allResults = $this->queryCompletedDurations(null);
            }

            $allGroup = $allResults->get($type);
            $allCount = $allGroup ? $allGroup->count() : 0;

            if ($allCount >= self::MIN_SAMPLE_SIZE) {
                $avgDuration = round($allGroup->avg('duration'), 1);
                $response[$type] = [
                    'value'  => $avgDuration,
                    'source' => 'dynamic',
                    'label'  => null,
                    'count'  => $allCount,
                ];
            } else {
                $response[$type] = [
                    'value'  => null,
                    'source' => 'fallback',
                    'label'  => $fallbackLabel,
                    'count'  => $allCount,
                ];
            }
        }

        return $response;
    }

    /**
     * Query completed applications and return grouped durations.
     *
     * @param \Carbon\Carbon|null $since  If provided, only include records completed after this date.
     * @return \Illuminate\Support\Collection  Grouped by normalized type key.
     */
    private function queryCompletedDurations($since)
    {
        $query = ScholarshipApplication::whereNotNull('submitted_at')
            ->whereIn('status', ['Completed', 'Approved_Kadep'])
            ->where(function ($q) {
                $q->whereNotNull('kadep_approved_at')
                  ->orWhereNotNull('kaprodi_approved_at');
            });

        if ($since) {
            $query->where(function ($q) use ($since) {
                $q->where('kadep_approved_at', '>=', $since)
                  ->orWhere(function ($q2) use ($since) {
                      $q2->whereNull('kadep_approved_at')
                         ->where('kaprodi_approved_at', '>=', $since);
                  });
            });
        }

        return $query
            ->select('scholarship_name', 'submitted_at', 'kadep_approved_at', 'kaprodi_approved_at')
            ->get()
            ->map(function ($app) {
                $completedAt = $app->kadep_approved_at ?? $app->kaprodi_approved_at;
                $durationDays = $app->submitted_at->diffInSeconds($completedAt) / 86400;

                return [
                    'type'     => $this->normalizeType($app->scholarship_name),
                    'duration' => $durationDays,
                ];
            })
            ->filter(fn ($item) => $item['duration'] > 0 && $item['duration'] <= self::MAX_DURATION_DAYS)
            ->groupBy('type');
    }

    /**
     * Normalize scholarship_name to a consistent type key.
     */
    private function normalizeType(?string $name): string
    {
        if (!$name) return 'beasiswa';

        $name = strtolower($name);

        if (str_contains($name, 'beasiswa')) return 'beasiswa';
        if (str_contains($name, 'magang') || str_contains($name, 'kerja praktik')) return 'magang';
        if (str_contains($name, 'aktif') || str_contains($name, 'keaktifan')) return 'aktif';
        if (str_contains($name, 'luar negeri') || str_contains($name, 'visa')) return 'luar_negeri';

        return 'beasiswa'; // default fallback
    }
}
