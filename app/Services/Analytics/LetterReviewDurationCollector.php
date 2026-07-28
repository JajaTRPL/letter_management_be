<?php

namespace App\Services\Analytics;

use App\Services\AcademicRoutingService;
use App\Services\Notifications\LetterReviewSlaScanner;
use App\Services\Notifications\WorkflowReviewSlaPolicyService as Sla;
use App\Support\LetterWorkflowStatus as LS;
use App\Support\Workflow\LetterReviewStageClock as Stage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Letter samples, drawn from the per-stage approval timestamp columns.
 *
 * MEASUREMENT LIMITATION, stated here and published to the UI in `basis`:
 * letters have no workflow ledger (unlike room bookings), so the timestamp
 * columns are last-write-wins and only the MOST RECENT review cycle of each file
 * is measurable. A letter approved by Tendik, later revised, and resubmitted
 * overwrites `submitted_at` while keeping the stale `tendik_approved_at` — which
 * is why negative spans are real here and are counted rather than hidden.
 * Closing this gap needs a letter_workflow_events table; until then the honest
 * move is to say so on the page.
 */
final class LetterReviewDurationCollector implements ReviewDurationCollector
{
    private const STAGE_LABELS = [
        Stage::STAGE_PERSURATAN => 'Tendik Persuratan',
        Stage::STAGE_PRODI => 'Program Studi (Kaprodi/Sekprodi)',
        Stage::STAGE_DEPARTEMEN => 'Departemen (Kadep/Sekdep)',
    ];

    private const STAGE_DIMENSIONS = [
        Stage::STAGE_PERSURATAN => ReviewScope::DIMENSION_GLOBAL,
        Stage::STAGE_PRODI => ReviewScope::DIMENSION_STUDY_PROGRAM,
        Stage::STAGE_DEPARTEMEN => ReviewScope::DIMENSION_DEPARTMENT,
    ];

    /** @var array<string,int> */
    private array $discarded = ['negative' => 0, 'outlier' => 0];

    public function __construct(
        private AcademicRoutingService $routing,
        private ReviewSampleConfidencePolicy $confidence,
    ) {}

    public function scope(): string
    {
        return Sla::SCOPE_LETTER;
    }

    public function stages(): array
    {
        return self::STAGE_LABELS;
    }

    public function unitDimensionFor(string $stage): string
    {
        return self::STAGE_DIMENSIONS[$stage] ?? ReviewScope::DIMENSION_GLOBAL;
    }

    public function discarded(): array
    {
        return $this->discarded;
    }

    public function collect(Carbon $from, Carbon $to): Collection
    {
        $this->discarded = ['negative' => 0, 'outlier' => 0];
        $samples = collect();

        foreach (LetterReviewSlaScanner::LETTER_MODELS as $modelClass) {
            /** @var class-string<Model> $modelClass */
            $rows = $modelClass::query()
                ->with('user.studyProgram')
                ->where(function ($query) use ($from, $to) {
                    foreach (['tendik_approved_at', 'kaprodi_approved_at', 'kadep_approved_at', 'revised_at'] as $column) {
                        $query->orWhereBetween($column, [$from, $to]);
                    }
                })
                ->get();

            foreach ($rows as $application) {
                $samples = $samples->merge($this->samplesFor($application, $from, $to));
            }
        }

        return $samples->values();
    }

    public function waitingNow(int $overdueMinutes, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now(config('app.timezone')))->copy();
        $waiting = [];
        foreach (array_keys(self::STAGE_LABELS) as $stage) {
            $waiting[$stage] = ['count' => 0, 'over_overdue_count' => 0];
        }

        foreach (LetterReviewSlaScanner::LETTER_MODELS as $modelClass) {
            /** @var class-string<Model> $modelClass */
            $rows = $modelClass::query()
                ->whereIn('status', Stage::REVIEW_STATUSES)
                ->get();

            foreach ($rows as $application) {
                $status = (string) $application->getAttribute('status');
                $stage = Stage::stageKeyFor($status);
                if (! $stage) {
                    continue;
                }
                $waiting[$stage]['count']++;

                $since = Stage::waitingSince($application, $status);
                if ($since && intdiv($now->getTimestamp() - $since->getTimestamp(), 60) >= $overdueMinutes) {
                    $waiting[$stage]['over_overdue_count']++;
                }
            }
        }

        return $waiting;
    }

    /** @return Collection<int, ReviewDurationSample> */
    private function samplesFor(Model $application, Carbon $from, Carbon $to): Collection
    {
        $samples = collect();

        foreach (array_keys(self::STAGE_LABELS) as $stage) {
            $sample = $this->approvalSample($application, $stage, $from, $to);
            if ($sample) {
                $samples->push($sample);
            }
        }

        $revision = $this->revisionSample($application, $from, $to);
        if ($revision) {
            $samples->push($revision);
        }

        return $samples;
    }

    private function approvalSample(Model $application, string $stage, Carbon $from, Carbon $to): ?ReviewDurationSample
    {
        $entry = Stage::toCarbon($application->getAttribute(Stage::entryAttributeForStage($stage)));
        $exit = Stage::toCarbon($application->getAttribute(Stage::exitAttributeForStage($stage)));

        if (! $entry || ! $exit || ! $this->inWindow($exit, $from, $to)) {
            return null;
        }

        return $this->build($stage, $entry, $exit, ReviewDurationSample::DECISION_APPROVED, $application);
    }

    /**
     * A reviewer who sends a file back HAS reviewed it. Excluding revisions would
     * make the most diligent reviewers look slowest and would quietly reward
     * rubber-stamping — the opposite of what this metric should encourage.
     *
     * The stage is whichever timestamp the file was sitting on when it was
     * returned. Requiring `revised_at` to be strictly after that timestamp is
     * what rejects a STALE revision: once a file is resubmitted, `submitted_at`
     * moves past the old `revised_at`, and that old revision has already been
     * counted in an earlier window.
     */
    private function revisionSample(Model $application, Carbon $from, Carbon $to): ?ReviewDurationSample
    {
        if ((string) $application->getAttribute('status') !== LS::REVISION) {
            return null;
        }

        $revisedAt = Stage::toCarbon($application->getAttribute('revised_at'));
        if (! $revisedAt || ! $this->inWindow($revisedAt, $from, $to)) {
            return null;
        }

        $entry = null;
        $stage = null;
        foreach (self::STAGE_DIMENSIONS as $candidateStage => $ignored) {
            $candidate = Stage::toCarbon($application->getAttribute(Stage::entryAttributeForStage($candidateStage)));
            if ($candidate && $candidate->lessThan($revisedAt) && (! $entry || $candidate->greaterThan($entry))) {
                $entry = $candidate;
                $stage = $candidateStage;
            }
        }

        if (! $entry || ! $stage) {
            return null;
        }

        return $this->build($stage, $entry, $revisedAt, ReviewDurationSample::DECISION_REVISION, $application);
    }

    private function build(
        string $stage,
        Carbon $entry,
        Carbon $exit,
        string $decision,
        Model $application,
    ): ?ReviewDurationSample {
        $dimension = $this->unitDimensionFor($stage);
        $sample = ReviewDurationSample::make(
            $this->scope(),
            $stage,
            $dimension,
            $this->unitIdFor($dimension, $application),
            $entry,
            $exit,
            $decision,
        );

        if (! $sample) {
            // Decision at or before its own start — the stale-timestamp hazard.
            $this->discarded['negative']++;

            return null;
        }

        if ($sample->seconds > $this->confidence->maxDurationSeconds()) {
            // A file parked over a semester break is real, but averaging it in
            // would describe a month nobody actually worked.
            $this->discarded['outlier']++;

            return null;
        }

        return $sample;
    }

    private function unitIdFor(string $dimension, Model $application): ?int
    {
        return match ($dimension) {
            ReviewScope::DIMENSION_STUDY_PROGRAM => $this->routing->studentStudyProgramId($application),
            ReviewScope::DIMENSION_DEPARTMENT => $this->routing->studentDepartmentId($application),
            default => null,
        };
    }

    private function inWindow(Carbon $at, Carbon $from, Carbon $to): bool
    {
        return $at->betweenIncluded($from, $to);
    }
}
