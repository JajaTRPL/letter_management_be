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
 * Completed-only, owner-only Surat Tugas final download of the private
 * mahasiswa_review PDF. No regeneration, private headers, no raw path leak.
 */
class SuratTugasFinalDownloadTest extends TestCase
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

    public function test_owner_downloads_completed_review_pdf_without_regeneration(): void
    {
        [$owner] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_COMPLETED,
            'completed_at' => now(),
            'student_reviewed_at' => now(),
        ]);
        $this->seedReadyArtifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $response = $this->actingAs($owner, 'sanctum')
            ->get("/api/mahasiswa/surat-tugas/{$application->id}/final-download");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // No regeneration: artifact count unchanged.
        $this->assertSame(1, LetterDocumentArtifact::query()->count());
    }

    public function test_non_owner_and_non_completed_are_blocked(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$other] = $this->completeMahasiswa();

        $completed = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        $this->seedReadyArtifact($completed, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($other, 'sanctum')
            ->get("/api/mahasiswa/surat-tugas/{$completed->id}/final-download")
            ->assertForbidden();

        $notCompleted = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);
        $this->seedReadyArtifact($notCompleted, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($owner, 'sanctum')
            ->get("/api/mahasiswa/surat-tugas/{$notCompleted->id}/final-download")
            ->assertForbidden()
            ->assertJsonPath('reason', 'application_not_completed');
    }

    public function test_missing_or_failed_artifact_returns_safe_status(): void
    {
        [$owner] = $this->completeMahasiswa();

        $noArtifact = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        $this->actingAs($owner, 'sanctum')
            ->get("/api/mahasiswa/surat-tugas/{$noArtifact->id}/final-download")
            ->assertStatus(404)
            ->assertJsonPath('reason', 'artifact_unavailable');

        $failed = $this->suratTugasApplication($owner, [
            'status' => SuratTugasApplication::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        LetterDocumentArtifact::create([
            'letter_type' => SuratTugasApplication::LETTER_TYPE,
            'application_id' => $failed->id,
            'phase' => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            'version' => 1,
            'docx_path' => null,
            'pdf_path' => null,
            'source_hash' => hash('sha256', 'failed' . $failed->id),
            'status' => LetterDocumentArtifact::STATUS_FAILED,
            'error_message' => 'boom',
            'generated_by' => null,
            'generated_at' => now(),
        ]);
        $this->actingAs($owner, 'sanctum')
            ->get("/api/mahasiswa/surat-tugas/{$failed->id}/final-download")
            ->assertStatus(503)
            ->assertJsonPath('reason', 'artifact_failed');
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
}
