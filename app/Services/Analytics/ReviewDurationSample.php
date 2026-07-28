<?php

namespace App\Services\Analytics;

use Illuminate\Support\Carbon;

/**
 * One completed review: a reviewer had a file, and then they decided.
 *
 * Deliberately carries NO actor. There is no reviewer id, name, or foreign key
 * anywhere in this type, so no downstream aggregate can accidentally become a
 * per-person statistic. Attribution stops at the organisational unit — that is a
 * structural guarantee, not a policy someone must remember to enforce.
 */
final class ReviewDurationSample
{
    public const DECISION_APPROVED = 'approved';

    public const DECISION_REVISION = 'revision';

    public const DECISION_REJECTED = 'rejected';

    private function __construct(
        public readonly string $scope,
        public readonly string $stage,
        public readonly string $unitType,
        public readonly ?int $unitId,
        public readonly Carbon $startedAt,
        public readonly Carbon $decidedAt,
        public readonly string $decision,
        public readonly int $seconds,
    ) {}

    /**
     * Null when the span is not measurable — a decision at or before its own
     * start. That happens for real (a letter revised after Tendik approval keeps
     * the stale approval timestamp while resubmission moves `submitted_at`
     * forward), so callers COUNT the nulls as discarded rather than dropping them
     * silently. An undercount that nobody can see is worse than a visible gap.
     */
    public static function make(
        string $scope,
        string $stage,
        string $unitType,
        ?int $unitId,
        Carbon $startedAt,
        Carbon $decidedAt,
        string $decision,
    ): ?self {
        $seconds = $decidedAt->getTimestamp() - $startedAt->getTimestamp();
        if ($seconds <= 0) {
            return null;
        }

        return new self($scope, $stage, $unitType, $unitId, $startedAt, $decidedAt, $decision, $seconds);
    }

    public function isRevision(): bool
    {
        return $this->decision === self::DECISION_REVISION;
    }

    /** Grouping key for per-unit breakdowns. */
    public function unitKey(): string
    {
        return $this->unitType.':'.($this->unitId ?? 'none');
    }
}
