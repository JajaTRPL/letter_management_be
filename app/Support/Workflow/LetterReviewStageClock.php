<?php

namespace App\Support\Workflow;

use App\Support\LetterWorkflowStatus as LS;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * THE letter review clock: which stage owns a letter, when that stage's wait
 * began, and which column closes it.
 *
 * Extracted verbatim from LetterReviewSlaScanner so the SLA notifications and the
 * review analytics read the SAME clock. If these two ever diverge, the analytics
 * dashboard will contradict the notification telling a reviewer their file is
 * overdue — the worst possible failure for a governance feature, and one that
 * would surface as an argument between people rather than as a bug report.
 *
 * ReviewStageClockLockstepTest enforces this by comparing the clock against the
 * timestamp the scanner actually writes into AppNotification::occurred_at.
 *
 * The three stages, in workflow order:
 *   Submitted        → Tendik Persuratan   (waiting since submitted_at)
 *   Approved_Tendik  → Kaprodi/Sekprodi    (waiting since tendik_approved_at)
 *   Approved_Kaprodi → Kadep/Sekdep        (waiting since kaprodi_approved_at)
 */
final class LetterReviewStageClock
{
    public const STAGE_PERSURATAN = 'persuratan';

    public const STAGE_PRODI = 'prodi';

    public const STAGE_DEPARTEMEN = 'departemen';

    /** Review stages in workflow order. */
    public const STAGES = [self::STAGE_PERSURATAN, self::STAGE_PRODI, self::STAGE_DEPARTEMEN];

    /** The statuses in which a letter is WAITING at a review stage. */
    public const REVIEW_STATUSES = [LS::SUBMITTED, LS::APPROVED_TENDIK, LS::APPROVED_KAPRODI];

    /** Status a letter sits in while the stage owns it. */
    private const STAGE_BY_STATUS = [
        LS::SUBMITTED => self::STAGE_PERSURATAN,
        LS::APPROVED_TENDIK => self::STAGE_PRODI,
        LS::APPROVED_KAPRODI => self::STAGE_DEPARTEMEN,
    ];

    /** Column recording when the stage's wait STARTED. */
    private const ENTRY_BY_STAGE = [
        self::STAGE_PERSURATAN => 'submitted_at',
        self::STAGE_PRODI => 'tendik_approved_at',
        self::STAGE_DEPARTEMEN => 'kaprodi_approved_at',
    ];

    /** Column recording when the stage's reviewer APPROVED and handed on. */
    private const EXIT_BY_STAGE = [
        self::STAGE_PERSURATAN => 'tendik_approved_at',
        self::STAGE_PRODI => 'kaprodi_approved_at',
        self::STAGE_DEPARTEMEN => 'kadep_approved_at',
    ];

    /** Null for any status that is not a review-stage wait. */
    public static function stageKeyFor(string $status): ?string
    {
        return self::STAGE_BY_STATUS[$status] ?? null;
    }

    public static function entryAttributeFor(string $status): ?string
    {
        $stage = self::stageKeyFor($status);

        return $stage ? self::ENTRY_BY_STAGE[$stage] : null;
    }

    public static function entryAttributeForStage(string $stage): ?string
    {
        return self::ENTRY_BY_STAGE[$stage] ?? null;
    }

    public static function exitAttributeForStage(string $stage): ?string
    {
        return self::EXIT_BY_STAGE[$stage] ?? null;
    }

    /**
     * When the current stage's wait began.
     *
     * Falls back to updated_at so a legacy row with a null stage timestamp still
     * yields a clock instead of silently dropping out of the scan. Analytics
     * deliberately does NOT use this fallback — an approximate start would
     * corrupt a measured average, whereas for a reminder it only shifts when the
     * nudge fires.
     */
    public static function waitingSince(Model $application, string $status): ?Carbon
    {
        $attribute = self::entryAttributeFor($status);
        $value = $attribute ? $application->getAttribute($attribute) : null;
        $value ??= $application->getAttribute('updated_at');

        return self::toCarbon($value);
    }

    public static function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return $value ? Carbon::parse($value, config('app.timezone')) : null;
    }
}
