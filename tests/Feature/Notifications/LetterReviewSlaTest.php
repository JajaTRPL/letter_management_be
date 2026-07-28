<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Models\AppNotification;
use App\Models\SuratKeteranganAktifApplication as Ska;
use App\Models\User;
use App\Models\WorkflowReviewSlaPolicy;
use App\Services\LetterAssignmentService;
use App\Services\Notifications\LetterReviewSlaScanner;
use App\Services\Notifications\WorkflowReviewSlaPolicyService;
use App\Support\LetterWorkflowStatus as LS;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * C11 letter review-SLA: the letter twin of the room-booking SLA, on the same
 * governance policy (scope=letter) and backbone. Disabled-by-default safety,
 * per-stage reviewer targeting, warning→overdue supersession, SuperAdmin
 * escalation, idempotency, and resolution when the letter leaves the stage.
 */
class LetterReviewSlaTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const TYPE = 'surat-keterangan-aktif';

    private const SCOPE = WorkflowReviewSlaPolicyService::SCOPE_LETTER;

    private LetterReviewSlaScanner $scanner;

    private Carbon $submittedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = app(LetterReviewSlaScanner::class);
        $this->submittedAt = Carbon::parse('2026-06-01 09:00:00', config('app.timezone'));
    }

    public function test_disabled_policy_emits_no_sla_notifications(): void
    {
        $this->tendikPersuratan([self::TYPE]);
        $this->submitLetterAt($this->mahasiswa());

        $result = $this->scanner->scan($this->at(10 * 24 * 60));
        $this->assertFalse($result['enabled']);
        $this->assertSame(0, $this->slaCount());
    }

    public function test_warning_then_overdue_supersedes_for_the_persuratan_reviewer(): void
    {
        $officer = $this->tendikPersuratan([self::TYPE]);
        $this->enablePolicy();
        $this->submitLetterAt($this->mahasiswa());

        $this->scanner->scan($this->at(90));  // warning band [60,120)
        $this->scanner->scan($this->at(95));  // idempotent re-run

        $warning = AppNotification::where('recipient_user_id', $officer->id)
            ->where('dedup_key', 'like', 'letter-review-sla-warning:%')->get();
        $this->assertCount(1, $warning);
        $this->assertSame(NotificationCategory::Reminder, $warning->first()->category);

        $this->scanner->scan($this->at(150)); // overdue band [120,180)
        $overdue = AppNotification::where('recipient_user_id', $officer->id)
            ->where('dedup_key', 'like', 'letter-review-sla-overdue:%')->first();
        $this->assertNotNull($overdue);
        $this->assertSame(NotificationCategory::ActionRequired, $overdue->category);
        $this->assertNotNull($warning->first()->fresh()->superseded_by_id);
    }

    public function test_escalation_reaches_superadmin(): void
    {
        $this->tendikPersuratan([self::TYPE]);
        $admin = $this->primarySuperAdmin();
        $this->enablePolicy();
        $this->submitLetterAt($this->mahasiswa());

        $this->scanner->scan($this->at(200)); // >= 180 escalation

        $this->assertSame(1, AppNotification::where('recipient_user_id', $admin->id)
            ->where('dedup_key', 'like', 'letter-review-sla-escalation:%')
            ->where('category', NotificationCategory::System->value)->count());
    }

    public function test_prodi_stage_targets_the_matching_academic_reviewers(): void
    {
        $student = $this->mahasiswa();
        $prodiId = (int) $student->study_program_id;
        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $prodiId]);
        $foreign = $this->akademik('kaprodi', ['study_program_id' => $this->studyProgram()->id]);
        $this->tendikPersuratan([self::TYPE]);
        $this->enablePolicy();

        $app = $this->submitLetterAt($student);
        // Advance into the prodi stage; the clock restarts at this stage.
        Carbon::setTestNow($this->at(10));
        $this->transition($app, LS::APPROVED_TENDIK);
        Carbon::setTestNow();

        $this->scanner->scan($this->at(10 + 150)); // 150 min into the prodi stage

        $this->assertSame(1, AppNotification::where('recipient_user_id', $kaprodi->id)
            ->where('dedup_key', 'like', 'letter-review-sla-overdue:%')->count());
        $this->assertSame(0, AppNotification::where('recipient_user_id', $foreign->id)
            ->where('dedup_key', 'like', 'letter-review-sla-%')->count());
    }

    public function test_leaving_the_stage_resolves_open_sla_notifications(): void
    {
        $student = $this->mahasiswa();
        $this->akademik('kaprodi', ['study_program_id' => (int) $student->study_program_id]);
        $officer = $this->tendikPersuratan([self::TYPE]);
        $this->enablePolicy();
        $app = $this->submitLetterAt($student);

        $this->scanner->scan($this->at(150)); // persuratan overdue exists
        $this->assertNull(AppNotification::where('recipient_user_id', $officer->id)
            ->where('dedup_key', 'like', 'letter-review-sla-overdue:%')->first()->resolved_at);

        // Advancing out of the Submitted stage retires the persuratan SLA.
        $this->transition($app, LS::APPROVED_TENDIK);
        $this->assertNotNull(AppNotification::where('recipient_user_id', $officer->id)
            ->where('dedup_key', 'like', 'letter-review-sla-overdue:%')->first()->resolved_at);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function at(int $minutes): Carbon
    {
        return $this->submittedAt->copy()->addMinutes($minutes);
    }

    private function slaCount(): int
    {
        return AppNotification::where('dedup_key', 'like', 'letter-review-sla-%')->count();
    }

    private function enablePolicy(int $warning = 60, int $overdue = 120, int $escalation = 180): void
    {
        WorkflowReviewSlaPolicy::create([
            'scope' => self::SCOPE,
            'enabled' => true,
            'warning_minutes' => $warning,
            'overdue_minutes' => $overdue,
            'escalation_minutes' => $escalation,
        ]);
    }

    private function submitLetterAt(User $student): Ska
    {
        Carbon::setTestNow($this->submittedAt);
        $app = $this->draft($student);
        $this->submit($app);
        Carbon::setTestNow();

        return $app->fresh();
    }

    // Letter fixtures (mirrors LetterNotificationTest — the shared trait provides
    // completeMahasiswa/aktifApplication; the submit/transition seams are local).

    private function mahasiswa(): User
    {
        [$student] = $this->completeMahasiswa();

        return $student->fresh(['studyProgram']);
    }

    private function draft(User $student, array $attributes = []): Ska
    {
        return $this->aktifApplication($student, array_merge(['status' => LS::DRAFT], $attributes));
    }

    private function submit(Ska $app): void
    {
        $app->update(['status' => LS::SUBMITTED, 'submitted_at' => $app->submitted_at ?? now()]);
        app(LetterAssignmentService::class)->assignToEligibleTendik($app->fresh(), self::TYPE);
    }

    private function transition(Ska $app, string $to): void
    {
        $app->update(['status' => $to]);
    }
}
