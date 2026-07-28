<?php

namespace Tests\Feature\Analytics;

use App\Models\AppNotification;
use App\Models\SuratKeteranganAktifApplication as Ska;
use App\Models\User;
use App\Models\WorkflowReviewSlaPolicy;
use App\Services\LetterAssignmentService;
use App\Services\Notifications\LetterReviewSlaScanner;
use App\Services\Notifications\WorkflowReviewSlaPolicyService;
use App\Support\LetterWorkflowStatus as LS;
use App\Support\Workflow\LetterReviewStageClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * THE guard that keeps review analytics honest.
 *
 * The SLA scanner stamps the instant a stage's wait began into the notification
 * it sends (`occurred_at`). Analytics reads the same instant from
 * LetterReviewStageClock. If anyone ever re-forks the clock — copies the match
 * expression back into the scanner, "optimises" one side, adds a column to one
 * and not the other — these assertions fail.
 *
 * Why this matters more than a normal unit test: a drift here would not crash.
 * It would quietly produce a dashboard that says a stage averages two days while
 * the notification tells the same reviewer their file is nine days overdue. That
 * surfaces as an argument between people, not as a bug report.
 */
class LetterReviewStageClockLockstepTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const TYPE = 'surat-keterangan-aktif';

    private LetterReviewSlaScanner $scanner;

    private Carbon $submittedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = app(LetterReviewSlaScanner::class);
        $this->submittedAt = Carbon::parse('2026-06-01 09:00:00', config('app.timezone'));
    }

    public function test_persuratan_stage_notification_is_stamped_with_the_clock_instant(): void
    {
        $officer = $this->tendikPersuratan([self::TYPE]);
        $this->enablePolicy();
        $app = $this->submitLetterAt($this->mahasiswa());

        $this->scanner->scan($this->at(150)); // overdue band

        $stamped = AppNotification::where('recipient_user_id', $officer->id)
            ->where('dedup_key', 'like', 'letter-review-sla-%')
            ->firstOrFail()
            ->occurred_at;

        $clock = LetterReviewStageClock::waitingSince($app->fresh(), LS::SUBMITTED);

        $this->assertNotNull($clock);
        $this->assertSame(
            $clock->getTimestamp(),
            $stamped->getTimestamp(),
            'The analytics clock and the SLA notification must measure from the same instant.',
        );
        // And that instant is the submission, not row creation or "now".
        $this->assertSame($this->submittedAt->getTimestamp(), $clock->getTimestamp());
    }

    public function test_prodi_stage_clock_restarts_at_the_tendik_approval(): void
    {
        $student = $this->mahasiswa();
        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => (int) $student->study_program_id]);
        $this->tendikPersuratan([self::TYPE]);
        $this->enablePolicy();

        $app = $this->submitLetterAt($student);
        $approvedAt = $this->at(600);
        $app->update(['status' => LS::APPROVED_TENDIK, 'tendik_approved_at' => $approvedAt]);

        $this->scanner->scan($approvedAt->copy()->addMinutes(150));

        $stamped = AppNotification::where('recipient_user_id', $kaprodi->id)
            ->where('dedup_key', 'like', 'letter-review-sla-%')
            ->firstOrFail()
            ->occurred_at;

        $clock = LetterReviewStageClock::waitingSince($app->fresh(), LS::APPROVED_TENDIK);

        $this->assertSame($clock->getTimestamp(), $stamped->getTimestamp());
        // The prodi stage is NOT charged for the time the file spent at Tendik.
        $this->assertSame($approvedAt->getTimestamp(), $clock->getTimestamp());
    }

    public function test_stage_vocabulary_matches_the_scanner_dedup_keys(): void
    {
        $officer = $this->tendikPersuratan([self::TYPE]);
        $this->enablePolicy();
        $this->submitLetterAt($this->mahasiswa());

        $this->scanner->scan($this->at(150));

        $key = AppNotification::where('recipient_user_id', $officer->id)
            ->where('dedup_key', 'like', 'letter-review-sla-%')
            ->firstOrFail()
            ->dedup_key;

        $this->assertStringContainsString(
            ':'.LetterReviewStageClock::STAGE_PERSURATAN.':',
            $key,
            'Analytics stage keys must be the same tokens the scanner persists in dedup keys.',
        );
    }

    public function test_entry_and_exit_columns_chain_across_the_three_stages(): void
    {
        // Each stage's exit is the next stage's entry — the property that makes a
        // letter's total time the sum of its stage times, with no gap or overlap.
        $stages = LetterReviewStageClock::STAGES;

        foreach ([0, 1] as $i) {
            $this->assertSame(
                LetterReviewStageClock::exitAttributeForStage($stages[$i]),
                LetterReviewStageClock::entryAttributeForStage($stages[$i + 1]),
                "Stage {$stages[$i]} must hand off to {$stages[$i + 1]} on the same column.",
            );
        }

        $this->assertSame('submitted_at', LetterReviewStageClock::entryAttributeForStage('persuratan'));
        $this->assertSame('kadep_approved_at', LetterReviewStageClock::exitAttributeForStage('departemen'));
        $this->assertNull(LetterReviewStageClock::stageKeyFor(LS::COMPLETED));
    }

    // ── helpers (mirrors LetterReviewSlaTest) ───────────────────────────────

    private function at(int $minutes): Carbon
    {
        return $this->submittedAt->copy()->addMinutes($minutes);
    }

    private function enablePolicy(int $warning = 60, int $overdue = 120, int $escalation = 180): void
    {
        WorkflowReviewSlaPolicy::create([
            'scope' => WorkflowReviewSlaPolicyService::SCOPE_LETTER,
            'enabled' => true,
            'warning_minutes' => $warning,
            'overdue_minutes' => $overdue,
            'escalation_minutes' => $escalation,
        ]);
    }

    private function submitLetterAt(User $student): Ska
    {
        Carbon::setTestNow($this->submittedAt);
        $app = $this->aktifApplication($student, ['status' => LS::DRAFT]);
        $app->update(['status' => LS::SUBMITTED, 'submitted_at' => $this->submittedAt]);
        app(LetterAssignmentService::class)->assignToEligibleTendik($app->fresh(), self::TYPE);
        Carbon::setTestNow();

        return $app->fresh();
    }

    private function mahasiswa(): User
    {
        [$student] = $this->completeMahasiswa();

        return $student->fresh(['studyProgram']);
    }
}
