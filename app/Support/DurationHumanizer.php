<?php

namespace App\Support;

/**
 * ONE owner for how a span of time is spoken in Indonesian.
 *
 * Two forms, because two audiences read durations differently:
 *  - coarse()  = configured thresholds, always whole units ("7 hari").
 *  - precise() = measured averages, two most significant units ("1 hari 19 jam").
 *
 * Both live here so SLA governance ("batas waktu 7 hari") and review analytics
 * ("rata-rata 1 hari 19 jam") can never drift into describing the same span with
 * different wording.
 */
final class DurationHumanizer
{
    private const MINUTES_PER_DAY = 24 * 60;

    /**
     * Threshold wording. Moved verbatim from WorkflowReviewSlaController::humanize().
     * Thresholds are configured in whole days/hours, so the coarse form is exact
     * for them and reads the way a policy is written.
     */
    public static function coarse(int $minutes): string
    {
        if ($minutes % self::MINUTES_PER_DAY === 0) {
            return ($minutes / self::MINUTES_PER_DAY).' hari';
        }
        if ($minutes % 60 === 0) {
            return ($minutes / 60).' jam';
        }

        return $minutes.' menit';
    }

    /**
     * Measured wording. A measured average is almost never a whole unit, and
     * three units read as false precision — so at most the two most significant
     * ones are shown.
     *
     * A sub-minute span renders "kurang dari 1 menit", never "0 menit": a zeroed
     * clock reads as "approved instantly", which is the single most misleading
     * output this feature could produce.
     */
    public static function precise(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $hours > 0 ? "{$days} hari {$hours} jam" : "{$days} hari";
        }
        if ($hours > 0) {
            return $minutes > 0 ? "{$hours} jam {$minutes} menit" : "{$hours} jam";
        }
        if ($minutes > 0) {
            return "{$minutes} menit";
        }

        return 'kurang dari 1 menit';
    }
}
