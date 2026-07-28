<?php

namespace Tests\Feature\Analytics;

use App\Models\SuratKeteranganAktifApplication as Ska;
use App\Models\User;
use App\Services\Analytics\LetterReviewDurationCollector;
use App\Services\Analytics\ReviewDurationSample;
use App\Support\LetterWorkflowStatus as LS;
use App\Support\Workflow\LetterReviewStageClock as Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * What the letter collector may and may not claim.
 *
 * The cases that matter most are the ones where it REFUSES to produce a number:
 * a stale timestamp, an abandoned file, a revision already counted in an earlier
 * window. Each is discarded and tallied, never silently dropped — an invisible
 * undercount would make every figure on the dashboard unfalsifiable.
 */
class LetterReviewDurationCollectorTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private LetterReviewDurationCollector $collector;

    private Carbon $submittedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = app(LetterReviewDurationCollector::class);
        $this->submittedAt = Carbon::parse('2026-06-01 09:00:00', config('app.timezone'));
    }

    public function test_a_letter_through_all_three_stages_yields_three_samples(): void
    {
        $student = $this->mahasiswa();
        $this->letter($student, [
            'status' => LS::COMPLETED,
            'submitted_at' => $this->submittedAt,
            'tendik_approved_at' => $this->at(60),      // 1 hour at Persuratan
            'kaprodi_approved_at' => $this->at(60 + 120), // 2 hours at Prodi
            'kadep_approved_at' => $this->at(60 + 120 + 30), // 30 min at Departemen
        ]);

        $samples = $this->collect();

        $this->assertCount(3, $samples);
        $byStage = $samples->keyBy('stage');
        $this->assertSame(3600, $byStage[Stage::STAGE_PERSURATAN]->seconds);
        $this->assertSame(7200, $byStage[Stage::STAGE_PRODI]->seconds);
        $this->assertSame(1800, $byStage[Stage::STAGE_DEPARTEMEN]->seconds);
        // Each stage is charged only its own leg — the times sum to the total.
        $this->assertSame(
            $this->at(210)->getTimestamp() - $this->submittedAt->getTimestamp(),
            $samples->sum('seconds'),
        );
    }

    public function test_a_stale_approval_timestamp_is_discarded_and_counted(): void
    {
        // The real hazard: revision does not clear tendik_approved_at, and
        // resubmission moves submitted_at forward past it.
        $this->letter($this->mahasiswa(), [
            'status' => LS::SUBMITTED,
            'tendik_approved_at' => $this->at(60),
            'submitted_at' => $this->at(600), // resubmitted long after that approval
        ]);

        $samples = $this->collect();

        $this->assertCount(0, $samples, 'A backwards span is never reported as a duration.');
        $this->assertSame(1, $this->collector->discarded()['negative'], 'and the gap is visible, not silent.');
    }

    public function test_a_file_abandoned_beyond_the_outlier_ceiling_is_excluded_and_counted(): void
    {
        $this->letter($this->mahasiswa(), [
            'status' => LS::APPROVED_TENDIK,
            'submitted_at' => $this->submittedAt,
            'tendik_approved_at' => $this->submittedAt->copy()->addDays(45),
        ]);

        $samples = $this->collect($this->submittedAt->copy()->subDay(), $this->submittedAt->copy()->addDays(90));

        $this->assertCount(0, $samples);
        $this->assertSame(1, $this->collector->discarded()['outlier']);
    }

    public function test_a_revision_is_a_review_and_is_charged_to_the_stage_that_returned_it(): void
    {
        // Tendik approved, then Prodi sent it back three hours later.
        $this->letter($this->mahasiswa(), [
            'status' => LS::REVISION,
            'submitted_at' => $this->submittedAt,
            'tendik_approved_at' => $this->at(60),
            'revised_at' => $this->at(60 + 180),
        ]);

        $samples = $this->collect();
        $revision = $samples->firstWhere('decision', ReviewDurationSample::DECISION_REVISION);

        $this->assertNotNull($revision, 'Returning a file is work; excluding it would reward rubber-stamping.');
        $this->assertSame(Stage::STAGE_PRODI, $revision->stage);
        $this->assertSame(3 * 3600, $revision->seconds);
    }

    public function test_a_revision_already_superseded_by_resubmission_is_not_recounted(): void
    {
        // Returned at +240, then the student resubmitted at +600. The revision
        // belongs to the earlier cycle and was counted then.
        $this->letter($this->mahasiswa(), [
            'status' => LS::REVISION,
            'submitted_at' => $this->at(600),
            'revised_at' => $this->at(240),
        ]);

        $revisions = $this->collect()->where('decision', ReviewDurationSample::DECISION_REVISION);

        $this->assertCount(0, $revisions);
    }

    public function test_a_draft_is_never_sampled(): void
    {
        $this->letter($this->mahasiswa(), ['status' => LS::DRAFT, 'submitted_at' => null]);

        $this->assertCount(0, $this->collect());
    }

    public function test_prodi_samples_carry_the_students_study_program(): void
    {
        $student = $this->mahasiswa();
        $this->letter($student, [
            'status' => LS::APPROVED_KAPRODI,
            'submitted_at' => $this->submittedAt,
            'tendik_approved_at' => $this->at(60),
            'kaprodi_approved_at' => $this->at(120),
        ]);

        $prodiSample = $this->collect()->firstWhere('stage', Stage::STAGE_PRODI);

        $this->assertNotNull($prodiSample);
        $this->assertSame('study_program', $prodiSample->unitType);
        $this->assertSame((int) $student->study_program_id, $prodiSample->unitId);
        // Persuratan is one faculty-wide team, so it carries no unit.
        $this->assertNull($this->collect()->firstWhere('stage', Stage::STAGE_PERSURATAN)->unitId);
    }

    public function test_only_decisions_inside_the_window_are_sampled(): void
    {
        $this->letter($this->mahasiswa(), [
            'status' => LS::APPROVED_TENDIK,
            'submitted_at' => $this->submittedAt,
            'tendik_approved_at' => $this->at(60),
        ]);

        $later = $this->collect($this->at(1000), $this->at(2000));

        $this->assertCount(0, $later, 'A cycle belongs to the period it was decided in.');
    }

    public function test_waiting_now_counts_the_live_queue_per_stage(): void
    {
        $this->letter($this->mahasiswa(), ['status' => LS::SUBMITTED, 'submitted_at' => $this->submittedAt]);
        $this->letter($this->mahasiswa(), [
            'status' => LS::APPROVED_TENDIK,
            'submitted_at' => $this->submittedAt,
            'tendik_approved_at' => $this->submittedAt,
        ]);

        Carbon::setTestNow($this->submittedAt->copy()->addDays(10));
        $waiting = $this->collector->waitingNow(7 * 24 * 60);
        Carbon::setTestNow();

        $this->assertSame(1, $waiting[Stage::STAGE_PERSURATAN]['count']);
        $this->assertSame(1, $waiting[Stage::STAGE_PERSURATAN]['over_overdue_count']);
        $this->assertSame(1, $waiting[Stage::STAGE_PRODI]['count']);
        $this->assertSame(0, $waiting[Stage::STAGE_DEPARTEMEN]['count']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function at(int $minutes): Carbon
    {
        return $this->submittedAt->copy()->addMinutes($minutes);
    }

    private function collect(?Carbon $from = null, ?Carbon $to = null)
    {
        return $this->collector->collect(
            $from ?? $this->submittedAt->copy()->subDay(),
            $to ?? $this->submittedAt->copy()->addDays(30),
        );
    }

    private function letter(User $student, array $attributes): Ska
    {
        return $this->aktifApplication($student, $attributes);
    }

    private function mahasiswa(): User
    {
        [$student] = $this->completeMahasiswa();

        return $student->fresh(['studyProgram']);
    }
}
