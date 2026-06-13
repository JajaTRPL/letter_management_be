<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Models\SuratTugasApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Read-only Surat Tugas generated-preview endpoint: audience authorization,
 * private READY artifact streaming, safe generating/failed/unavailable
 * responses, and the guarantee that GET never generates an artifact.
 */
class SuratTugasGeneratedPreviewEndpointTest extends TestCase
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

    public function test_assigned_tendik_streams_ready_preview_and_get_is_read_only(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);
        $this->seedReadyArtifact($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $response = $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-tugas/{$application->id}/generated-preview");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));

        // GET is read-only: calling again does not create a new artifact.
        $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-tugas/{$application->id}/generated-preview")
            ->assertOk();
        $this->assertSame(1, LetterDocumentArtifact::query()->count());
    }

    public function test_unassigned_tendik_is_forbidden(): void
    {
        $unassigned = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);
        $this->seedReadyArtifact($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $this->actingAs($unassigned, 'sanctum')
            ->get("/api/tendik/surat-tugas/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_in_scope_kaprodi_streams_but_out_of_scope_is_forbidden(): void
    {
        $department = $this->department(['name' => 'DTEDI']);
        $prodi = $this->studyProgram($department, ['name' => 'TRPL']);
        $otherProdi = $this->studyProgram($this->department(['name' => 'Other']), ['name' => 'Other Program']);
        [$inScope] = $this->completeMahasiswa([], [], $prodi);
        [$outScope] = $this->completeMahasiswa([], [], $otherProdi);

        $inApp = $this->suratTugasApplication($inScope, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);
        $outApp = $this->suratTugasApplication($outScope, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);
        $this->seedReadyArtifact($inApp, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->seedReadyArtifact($outApp, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $prodi->id]);

        $this->actingAs($kaprodi, 'sanctum')
            ->get("/api/akademik/surat-tugas/{$inApp->id}/generated-preview")
            ->assertOk();

        $this->actingAs($kaprodi, 'sanctum')
            ->get("/api/akademik/surat-tugas/{$outApp->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_owner_mahasiswa_streams_ready_review_pdf_but_non_owner_forbidden(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$other] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($owner, ['status' => SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW]);
        $this->seedReadyArtifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($owner, 'sanctum')
            ->get("/api/mahasiswa/surat-tugas/{$application->id}/generated-preview")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($other, 'sanctum')
            ->get("/api/mahasiswa/surat-tugas/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_generating_failed_and_missing_artifacts_return_safe_statuses(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);

        $generating = $this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);
        $this->seedArtifact($generating, LetterDocumentArtifact::PHASE_PRODI_REVIEW, LetterDocumentArtifact::STATUS_GENERATING, null);
        $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-tugas/{$generating->id}/generated-preview")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'artifact_generating');

        $failed = $this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);
        $this->seedArtifact($failed, LetterDocumentArtifact::PHASE_PRODI_REVIEW, LetterDocumentArtifact::STATUS_FAILED, null);
        $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-tugas/{$failed->id}/generated-preview")
            ->assertStatus(503)
            ->assertJsonPath('reason', 'artifact_failed');

        $missing = $this->suratTugasApplication(null, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);
        $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-tugas/{$missing->id}/generated-preview")
            ->assertStatus(404)
            ->assertJsonPath('reason', 'artifact_unavailable');
    }

    private function seedReadyArtifact(SuratTugasApplication $application, string $phase): LetterDocumentArtifact
    {
        $pdfPath = 'letter-document-artifacts/' . SuratTugasApplication::LETTER_TYPE
            . '/' . $application->id . '/' . $phase . '/preview_seed_' . uniqid('', true) . '.pdf';
        Storage::disk('local')->put($pdfPath, '%PDF-1.4 seed surat tugas');

        return $this->seedArtifact($application, $phase, LetterDocumentArtifact::STATUS_READY, $pdfPath);
    }

    private function seedArtifact(
        SuratTugasApplication $application,
        string $phase,
        string $status,
        ?string $pdfPath,
    ): LetterDocumentArtifact {
        return LetterDocumentArtifact::create([
            'letter_type' => SuratTugasApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => 1,
            'docx_path' => null,
            'pdf_path' => $pdfPath,
            'source_hash' => hash('sha256', $application->id . $phase . $status),
            'status' => $status,
            'error_message' => null,
            'generated_by' => null,
            'generated_at' => now(),
        ]);
    }
}
