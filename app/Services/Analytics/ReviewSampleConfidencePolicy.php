<?php

namespace App\Services\Analytics;

use App\Services\SuratAnalyticsService;
use App\Support\DurationHumanizer;
use App\Support\Workflow\LetterReviewStageClock as LetterStage;
use App\Support\Workflow\RoomBookingReviewStageClock as BookingStage;

/**
 * The honesty layer: decides whether a number may be presented as a measurement,
 * and what to say instead when it may not.
 *
 * Three states, kept distinct because they mean genuinely different things to
 * whoever is reading the dashboard:
 *
 *   dynamic  — n >= MIN_SAMPLE_SIZE. A real measurement.
 *   fallback — 1 <= n < MIN_SAMPLE_SIZE. Some activity, not enough to average.
 *              Shows the published service expectation, badged "Estimasi".
 *   none     — n = 0. Nothing happened in this window. Shows NO estimate at all:
 *              an estimate for a period with zero activity is noise dressed as
 *              information, and it is exactly how the current card ends up
 *              claiming "00 Hari 00 Jam" for stages that never ran.
 *
 * Thresholds come from SuratAnalyticsService so that "how much data is enough"
 * is one number product-wide — the badge a student sees on Administrasi Surat
 * and the figure a Kadep sees on the governance dashboard must not disagree
 * about whether a duration is trustworthy.
 */
final class ReviewSampleConfidencePolicy
{
    public const SOURCE_DYNAMIC = 'dynamic';

    public const SOURCE_FALLBACK = 'fallback';

    public const SOURCE_NONE = 'none';

    public const STATUS_WITHIN = 'within';

    public const STATUS_APPROACHING = 'approaching';

    public const STATUS_BEYOND = 'beyond';

    /** Not enough data to judge — never coloured as good or bad. */
    public const STATUS_UNKNOWN = 'unknown';

    /** SuperAdmin has not switched the review deadline on, so nothing to judge against. */
    public const STATUS_UNRATED = 'unrated';

    /**
     * Published service expectations per stage, used ONLY while a stage has too
     * few samples to measure.
     *
     * These are not invented: the booking figures are the same ones already shown
     * to students on the Peminjaman Ruangan cards, and the letter figures sum to
     * the 1–7 working-day ranges already published per letter type in
     * SuratAnalyticsService::FALLBACK_LABELS. Each is replaced automatically by
     * the live median the moment the stage reaches MIN_SAMPLE_SIZE.
     */
    private const ESTIMATE_LABELS = [
        LetterStage::STAGE_PERSURATAN => '1–2 hari kerja',
        LetterStage::STAGE_PRODI => '1–3 hari kerja',
        LetterStage::STAGE_DEPARTEMEN => '1–2 hari kerja',
        BookingStage::STAGE_SARPRAS => '1–2 hari kerja',
        BookingStage::STAGE_KALAB => '2–3 hari kerja',
    ];

    public function minSample(): int
    {
        return SuratAnalyticsService::MIN_SAMPLE_SIZE;
    }

    public function maxDurationDays(): int
    {
        return SuratAnalyticsService::MAX_DURATION_DAYS;
    }

    public function maxDurationSeconds(): int
    {
        return $this->maxDurationDays() * 86400;
    }

    public function sourceFor(int $count): string
    {
        if ($count === 0) {
            return self::SOURCE_NONE;
        }

        return $count >= $this->minSample() ? self::SOURCE_DYNAMIC : self::SOURCE_FALLBACK;
    }

    public function estimateLabelFor(string $stage): ?string
    {
        return self::ESTIMATE_LABELS[$stage] ?? null;
    }

    /**
     * Build the render-ready metric block. The frontend receives finished
     * Indonesian sentences and never re-derives a judgement from raw numbers —
     * that keeps SuperAdmin wording and reviewer wording literally identical.
     *
     * @param  array{count:int, revision_count:int, median_seconds:?int, average_seconds:?int, p90_seconds:?int}  $stats
     * @param  array{enabled:bool, warning_minutes:int, overdue_minutes:int}  $policy
     * @param  array<string,int>  $discarded
     */
    public function present(string $stage, array $stats, array $policy, array $discarded = []): array
    {
        $source = $this->sourceFor($stats['count']);
        $measured = $source === self::SOURCE_DYNAMIC;
        $median = $measured ? $stats['median_seconds'] : null;

        [$status, $statusLabel] = $this->judge($median, $policy);

        return [
            'source' => $source,
            'count' => $stats['count'],
            'revision_count' => $stats['revision_count'],
            'discarded' => [
                'negative' => $discarded['negative'] ?? 0,
                'outlier' => $discarded['outlier'] ?? 0,
            ],
            'median_seconds' => $median,
            'median_label' => $median !== null ? DurationHumanizer::precise($median) : null,
            'average_seconds' => $measured ? $stats['average_seconds'] : null,
            'average_label' => $measured && $stats['average_seconds'] !== null
                ? DurationHumanizer::precise($stats['average_seconds'])
                : null,
            'p90_seconds' => $measured ? $stats['p90_seconds'] : null,
            'p90_label' => $measured && $stats['p90_seconds'] !== null
                ? DurationHumanizer::precise($stats['p90_seconds'])
                : null,
            'estimate_label' => $source === self::SOURCE_FALLBACK ? $this->estimateLabelFor($stage) : null,
            'sample_note' => $this->sampleNote($source, $stats['count']),
            'status' => $status,
            'status_label' => $statusLabel,
        ];
    }

    /**
     * @param  array{enabled:bool, warning_minutes:int, overdue_minutes:int}  $policy
     * @return array{0:string, 1:string}
     */
    private function judge(?int $medianSeconds, array $policy): array
    {
        if (! ($policy['enabled'] ?? false)) {
            // Colouring a stage against a deadline nobody has agreed to would be
            // an accusation the institution never actually made.
            return [self::STATUS_UNRATED, 'Batas waktu belum diaktifkan'];
        }
        if ($medianSeconds === null) {
            return [self::STATUS_UNKNOWN, 'Belum bisa dihitung'];
        }
        if ($medianSeconds >= $policy['overdue_minutes'] * 60) {
            return [self::STATUS_BEYOND, 'Melebihi batas waktu'];
        }
        if ($medianSeconds >= $policy['warning_minutes'] * 60) {
            return [self::STATUS_APPROACHING, 'Mendekati batas waktu'];
        }

        return [self::STATUS_WITHIN, 'Dalam batas waktu'];
    }

    private function sampleNote(string $source, int $count): ?string
    {
        return match ($source) {
            self::SOURCE_FALLBACK => "Baru {$count} dari {$this->minSample()} pengajuan — angka pasti muncul setelah cukup data.",
            self::SOURCE_NONE => 'Belum ada pengajuan yang selesai di periode ini.',
            default => null,
        };
    }
}
