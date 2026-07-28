<?php

namespace App\Services\Analytics;

/**
 * WHAT a caller is allowed to see: one stage, optionally narrowed to one
 * organisational unit.
 *
 * The point of making this a type rather than three loose arguments is that
 * ReviewPerformanceService accepts ONLY this object. A request parameter can
 * never become a scope by accident — it has to pass through
 * ReviewScopeResolver, which is the single place authorisation is decided.
 *
 * There is deliberately no `reviewer` dimension. Not "not implemented yet" —
 * there is no way to express one, so no future endpoint can quietly add
 * per-person reporting without changing this type first.
 */
final class ReviewScope
{
    public const DIMENSION_GLOBAL = 'global';

    public const DIMENSION_STUDY_PROGRAM = 'study_program';

    public const DIMENSION_DEPARTMENT = 'department';

    public const DIMENSION_LABORATORY = 'laboratory';

    public const DIMENSION_LABELS = [
        self::DIMENSION_GLOBAL => 'Seluruh unit',
        self::DIMENSION_STUDY_PROGRAM => 'Program Studi',
        self::DIMENSION_DEPARTMENT => 'Departemen',
        self::DIMENSION_LABORATORY => 'Laboratorium',
    ];

    private function __construct(
        public readonly string $scope,
        public readonly string $stage,
        public readonly string $unitType,
        public readonly ?int $unitId,
    ) {}

    /** Whole stage, every unit — SuperAdmin only. */
    public static function wholeStage(string $scope, string $stage, string $unitType): self
    {
        return new self($scope, $stage, $unitType, null);
    }

    /** One unit of one stage — what a reviewer's self-view resolves to. */
    public static function unit(string $scope, string $stage, string $unitType, ?int $unitId): self
    {
        return new self($scope, $stage, $unitType, $unitId);
    }

    public function isNarrowed(): bool
    {
        return $this->unitId !== null;
    }

    /** True when a sample belongs to this scope. */
    public function matches(ReviewDurationSample $sample): bool
    {
        if ($sample->scope !== $this->scope || $sample->stage !== $this->stage) {
            return false;
        }

        return ! $this->isNarrowed() || $sample->unitId === $this->unitId;
    }

    public function dimensionLabel(): string
    {
        return self::DIMENSION_LABELS[$this->unitType] ?? self::DIMENSION_LABELS[self::DIMENSION_GLOBAL];
    }
}
