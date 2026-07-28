<?php

namespace App\Support\Analytics;

use Illuminate\Support\Carbon;

/**
 * ONE owner for the reporting-period vocabulary shared by every analytics
 * surface. The keys (`today` … `12months`) were already the de-facto contract —
 * LetterMonitoring sends them and DashboardController::getMonitoringData matched
 * on them inline — so this class names that contract instead of inventing one.
 *
 * Also resolves the PREVIOUS comparable window, so "lebih cepat dari periode
 * sebelumnya" always compares spans of equal length rather than an arbitrary
 * earlier slice.
 */
final class ReviewAnalyticsPeriod
{
    public const DEFAULT = 'week';

    /** key => Indonesian label, in the order the UI shows them. */
    public const LABELS = [
        'today' => 'Hari Ini',
        'week' => 'Minggu Ini',
        '1month' => '1 Bulan',
        '3months' => '3 Bulan',
        '6months' => '6 Bulan',
        '12months' => '12 Bulan',
    ];

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /** Unknown / absent keys fall back to the default rather than erroring. */
    public static function resolve(?string $key, ?Carbon $now = null): self
    {
        $key = isset(self::LABELS[$key]) ? $key : self::DEFAULT;
        $now = ($now ?? Carbon::now(config('app.timezone')))->copy();
        $end = $now->copy()->endOfDay();

        return new self($key, self::LABELS[$key], self::startFor($key, $now), $end);
    }

    /**
     * The equal-length window immediately before this one. Comparing a 3-month
     * span against a 3-month span is the only comparison that means anything;
     * comparing it against "everything before" would make every period look fast.
     */
    public function previous(): self
    {
        $lengthSeconds = $this->end->getTimestamp() - $this->start->getTimestamp();
        $end = $this->start->copy()->subSecond();
        $start = $end->copy()->subSeconds($lengthSeconds);

        return new self($this->key, $this->label.' sebelumnya', $start, $end);
    }

    /** Month buckets for long windows, day buckets for short ones. */
    public function trendBucket(): string
    {
        return in_array($this->key, ['today', 'week', '1month'], true) ? 'day' : 'month';
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
        ];
    }

    private static function startFor(string $key, Carbon $now): Carbon
    {
        $today = $now->copy()->startOfDay();

        return match ($key) {
            'today' => $today,
            'week' => $today->copy()->subDays(7),
            '1month' => $today->copy()->subMonth(),
            '3months' => $today->copy()->subMonths(3),
            '6months' => $today->copy()->subMonths(6),
            '12months' => $today->copy()->subYear(),
            default => $today->copy()->subDays(7),
        };
    }
}
