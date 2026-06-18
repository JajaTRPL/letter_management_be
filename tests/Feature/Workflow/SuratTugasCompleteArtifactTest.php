<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratTugasApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Surat Tugas complete gate: owner-only, requires Ready_For_Student_Review and
 * a READY mahasiswa_review PDF artifact, never regenerates, and sets completion
 * timestamps only on success.
 */
class SuratTugasCompleteArtifactTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-25 09:10:20'));
        Cache::flush();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_complete_succeeds_with_ready_review_artifact_and_does_not_regenerate(): void
    {
        [$owner] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);
        $this->seedReadyArtifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/mahasiswa/surat-tugas/{$application->id}/complete")
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_COMPLETED);

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNotNull($fresh->student_reviewed_at);
        // No regeneration.
        $this->assertSame(1, LetterDocumentArtifact::query()->count());
    }

    public function test_complete_requires_ready_for_student_review_status(): void
    {
        [$owner] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI,
        ]);
        $this->seedReadyArtifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/mahasiswa/surat-tugas/{$application->id}/complete")
            ->assertStatus(422);

        $this->assertSame(SuratTugasApplication::STATUS_APPROVED_KAPRODI, $application->fresh()->status);
    }

    public function test_complete_blocked_when_review_artifact_missing_generating_or_failed(): void
    {
        [$owner] = $this->completeMahasiswa();

        $missing = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/mahasiswa/surat-tugas/{$missing->id}/complete")
            ->assertStatus(404)
            ->assertJsonPath('reason', 'artifact_unavailable');
        $this->assertSame(SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW, $missing->fresh()->status);

        $generating = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);
        $this->seedArtifact($generating, LetterDocumentArtifact::STATUS_GENERATING, null);
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/mahasiswa/surat-tugas/{$generating->id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'artifact_generating');

        $failed = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);
        $this->seedArtifact($failed, LetterDocumentArtifact::STATUS_FAILED, null);
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/mahasiswa/surat-tugas/{$failed->id}/complete")
            ->assertStatus(503)
            ->assertJsonPath('reason', 'artifact_failed');
        $this->assertSame(SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW, $failed->fresh()->status);
    }

    public function test_non_owner_cannot_complete(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$other] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);
        $this->seedReadyArtifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/mahasiswa/surat-tugas/{$application->id}/complete")
            ->assertForbidden();
        $this->assertSame(SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW, $application->fresh()->status);
    }

    private function seedReadyArtifact(SuratTugasApplication $application, string $phase): void
    {
        $pdfPath = 'letter-document-artifacts/' . SuratTugasApplication::LETTER_TYPE
            . '/' . $application->id . '/' . $phase . '/preview_seed_' . uniqid('', true) . '.pdf';
        Storage::disk('local')->put($pdfPath, '%PDF-1.4 seed surat tugas');

        LetterDocumentArtifact::create([
            'letter_type' => SuratTugasApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => 1,
            'docx_path' => null,
            'pdf_path' => $pdfPath,
            'source_hash' => hash('sha256', $application->id . $phase),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'error_message' => null,
            'generated_by' => null,
            'generated_at' => now(),
        ]);
    }

    private function seedArtifact(SuratTugasApplication $application, string $status, ?string $pdfPath): void
    {
        LetterDocumentArtifact::create([
            'letter_type' => SuratTugasApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            'version' => 1,
            'docx_path' => null,
            'pdf_path' => $pdfPath,
            'source_hash' => hash('sha256', $application->id . $status),
            'status' => $status,
            'error_message' => $status === LetterDocumentArtifact::STATUS_FAILED ? 'boom' : null,
            'generated_by' => null,
            'generated_at' => now(),
        ]);
    }
}
