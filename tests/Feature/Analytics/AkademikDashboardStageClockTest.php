<?php

namespace Tests\Feature\Analytics;

use App\Models\SuratKeteranganAktifApplication as Ska;
use App\Models\User;
use App\Services\LetterTaskFeedService;
use App\Support\LetterWorkflowStatus as LS;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * Two defects the Akademik dashboard shipped with, both about measuring the
 * wrong thing:
 *
 *  1. "24 jam tertunda" was computed from `created_at` — when the STUDENT
 *     created their draft. A file drafted last week and approved by Tendik five
 *     minutes ago greeted the Kaprodi as already overdue.
 *  2. "Selesai Bulan Ini" counted every ScholarshipApplication in the faculty,
 *     unscoped, by `updated_at`. Every Kaprodi and Kadep saw the same number,
 *     mostly other people's work.
 */
class AkademikDashboardStageClockTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_overdue_is_measured_from_stage_arrival_not_draft_creation(): void
    {
        $student = $this->mahasiswa();
        $draftedAt = Carbon::parse('2026-06-01 09:00:00', config('app.timezone'));
        $arrivedAt = $draftedAt->copy()->addDays(7);

        Carbon::setTestNow($draftedAt);
        $app = $this->aktifApplication($student, ['status' => LS::DRAFT]);
        Carbon::setTestNow();

        // Drafted a week ago, but it only reached Prodi one hour ago.
        $app->update([
            'status' => LS::APPROVED_TENDIK,
            'submitted_at' => $draftedAt->copy()->addHour(),
            'tendik_approved_at' => $arrivedAt,
        ]);

        Carbon::setTestNow($arrivedAt->copy()->addHour());
        $row = app(LetterTaskFeedService::class)->akademikAktifRow($app->fresh());
        Carbon::setTestNow();

        $this->assertFalse(
            $row['is_overdue'],
            'One hour at the Prodi stage is not a backlog, however old the draft is.',
        );
    }

    public function test_a_file_genuinely_stuck_at_the_stage_is_still_flagged(): void
    {
        $student = $this->mahasiswa();
        $arrivedAt = Carbon::parse('2026-06-01 09:00:00', config('app.timezone'));
        $app = $this->aktifApplication($student, [
            'status' => LS::APPROVED_TENDIK,
            'submitted_at' => $arrivedAt->copy()->subHour(),
            'tendik_approved_at' => $arrivedAt,
        ]);

        Carbon::setTestNow($arrivedAt->copy()->addDays(3));
        $row = app(LetterTaskFeedService::class)->akademikAktifRow($app->fresh());
        Carbon::setTestNow();

        $this->assertTrue($row['is_overdue'], 'Three days waiting at this stage is a real backlog.');
    }

    public function test_finished_this_month_counts_only_the_reviewers_own_program(): void
    {
        $mine = $this->defaultStudyProgram();
        $theirs = $this->studyProgram();
        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $mine->id]);

        $approvedAt = now(config('app.timezone'))->startOfMonth()->addDays(2);
        $this->aktifApplication($this->mahasiswaIn($mine), [
            'status' => LS::APPROVED_KAPRODI,
            'kaprodi_approved_at' => $approvedAt,
        ]);
        $this->aktifApplication($this->mahasiswaIn($theirs), [
            'status' => LS::APPROVED_KAPRODI,
            'kaprodi_approved_at' => $approvedAt,
        ]);

        Sanctum::actingAs($kaprodi);
        $stats = $this->getJson('/api/akademik/dashboard/tasks')->assertOk()->json('stats');

        $this->assertSame(
            1,
            $stats['finished_this_month'],
            'Another program\'s approvals must not inflate this reviewer\'s figure.',
        );
    }

    public function test_finished_this_month_ignores_letters_this_reviewer_never_signed(): void
    {
        $program = $this->defaultStudyProgram();
        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $program->id]);

        // Sitting at the Prodi stage, not yet approved by them.
        $this->aktifApplication($this->mahasiswaIn($program), [
            'status' => LS::APPROVED_TENDIK,
            'tendik_approved_at' => now(config('app.timezone')),
            'kaprodi_approved_at' => null,
        ]);

        Sanctum::actingAs($kaprodi);
        $stats = $this->getJson('/api/akademik/dashboard/tasks')->assertOk()->json('stats');

        $this->assertSame(0, $stats['finished_this_month']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function mahasiswa(): User
    {
        [$student] = $this->completeMahasiswa();

        return $student->fresh(['studyProgram']);
    }

    private function mahasiswaIn($program): User
    {
        [$student] = $this->completeMahasiswa([], [], $program);

        return $student->fresh(['studyProgram']);
    }

    private function aktif(User $student, array $attributes): Ska
    {
        return $this->aktifApplication($student, $attributes);
    }
}
