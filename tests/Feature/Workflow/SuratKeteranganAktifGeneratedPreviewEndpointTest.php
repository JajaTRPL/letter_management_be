<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use App\Services\SuratKeteranganAktifPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Read-only contract for the SKA generated-preview endpoint.
 *
 * The endpoint MUST NOT invoke SuratKeteranganAktifPreviewGenerationService
 * or any artifact write path; only workflow transitions produce artifacts.
 */
class SuratKeteranganAktifGeneratedPreviewEndpointTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    /** @var array<string, int> */
    private array $artifactVersions = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-22 10:00:00'));
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Audience access matrix
    // ------------------------------------------------------------------

    public function test_assigned_tendik_can_stream_current_phase_artifact_and_unassigned_cannot(): void
    {
        $application = $this->aktifApplication(null, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'AKT-EP-001',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $assignedTendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $unassignedTendik = $this->tendikPersuratan([]);

        $this->assertPdfResponse(
            $this->actingAs($assignedTendik, 'sanctum')
                ->get("/api/tendik/surat-keterangan-aktif/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        );

        $this->actingAs($unassignedTendik, 'sanctum')
            ->get("/api/tendik/surat-keterangan-aktif/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_akademik_uses_detail_scope_not_approve_stage_gate(): void
    {
        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);

        $otherDept = $this->department();
        $otherProgram = $this->studyProgram($otherDept);

        $kaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $studentProgram->id,
            'department_id' => $studentDept->id,
        ]);
        $kadep = $this->akademik('kadep', ['department_id' => $studentDept->id]);
        $wrongKaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $otherProgram->id,
            'department_id' => $otherDept->id,
        ]);
        $wrongKadep = $this->akademik('kadep', ['department_id' => $otherDept->id]);

        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'AKT-EP-002',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->artifact($application, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($kaprodi, 'sanctum')
                ->get("/api/akademik/surat-keterangan-aktif/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );
        $this->assertPdfResponse(
            $this->actingAs($kadep, 'sanctum')
                ->get("/api/akademik/surat-keterangan-aktif/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );
        $this->actingAs($wrongKaprodi, 'sanctum')
            ->get("/api/akademik/surat-keterangan-aktif/{$application->id}/generated-preview")
            ->assertForbidden();
        $this->actingAs($wrongKadep, 'sanctum')
            ->get("/api/akademik/surat-keterangan-aktif/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_mahasiswa_owner_policy_allows_final_and_fallback_but_not_in_flight_phases(): void
    {
        [$student] = $this->completeMahasiswa();
        [$otherStudent] = $this->completeMahasiswa();

        $readyApp = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => 'AKT-EP-003',
        ]);
        $this->artifact($readyApp, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/surat-keterangan-aktif/{$readyApp->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $this->actingAs($otherStudent, 'sanctum')
            ->get("/api/mahasiswa/surat-keterangan-aktif/{$readyApp->id}/generated-preview")
            ->assertForbidden();

        // In-flight statuses (Submitted, Approved_Tendik, Approved_Kaprodi) are
        // owner-forbidden — mirrors the Beasiswa contract.
        $inFlight = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact($inFlight, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-keterangan-aktif/{$inFlight->id}/generated-preview")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Phase resolution / status mapping
    // ------------------------------------------------------------------

    public function test_status_phase_mapping_serves_current_artifact_not_stale_prior_phase(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);

        // Approved_Tendik should serve prodi_review even if tendik_review exists.
        $atTendik = $this->aktifApplication(null, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'AKT-EP-004',
        ]);
        $this->artifact($atTendik, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($atTendik, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($tendik, 'sanctum')
                ->get("/api/tendik/surat-keterangan-aktif/{$atTendik->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        );

        // Approved_Kaprodi must serve departemen_review.
        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);
        $kaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $studentProgram->id,
            'department_id' => $studentDept->id,
        ]);

        $atKaprodi = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'AKT-EP-005',
        ]);
        $this->artifact($atKaprodi, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->artifact($atKaprodi, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($kaprodi, 'sanctum')
                ->get("/api/akademik/surat-keterangan-aktif/{$atKaprodi->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );
    }

    public function test_revision_and_rejected_fallback_use_most_advanced_available_artifact(): void
    {
        [$student] = $this->completeMahasiswa();

        $revised = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_REVISION,
            'nomor_surat' => 'AKT-EP-006',
        ]);
        $this->artifact($revised, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($revised, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->artifact($revised, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/surat-keterangan-aktif/{$revised->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );

        $rejected = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_REJECTED,
            'nomor_surat' => 'AKT-EP-007',
        ]);
        $this->artifact($rejected, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/surat-keterangan-aktif/{$rejected->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
        );
    }

    public function test_draft_owner_returns_unavailable_without_generation(): void
    {
        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldNotReceive('generateForPhase');
        $mock->shouldNotReceive('generateForCurrentPhase');
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);

        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/generated-preview");

        $response->assertStatus(404)
            ->assertJsonPath('reason', 'artifact_unavailable');
        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    // ------------------------------------------------------------------
    // Artifact lifecycle error responses
    // ------------------------------------------------------------------

    public function test_generating_artifact_returns_409_without_leaking_paths(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, [
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_GENERATING,
            writeFile: false,
        );

        $response = $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-keterangan-aktif/{$application->id}/generated-preview");

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'artifact_generating');
        $this->assertStringNotContainsString('letter-document-artifacts', $response->getContent());
        $this->assertStringNotContainsString('source_hash', $response->getContent());
    }

    public function test_failed_artifact_returns_503_without_leaking_paths(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, [
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_FAILED,
            writeFile: false,
            errorMessage: 'C:\\private\\letter-document-artifacts\\stack trace',
        );

        $response = $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-keterangan-aktif/{$application->id}/generated-preview");

        $response->assertStatus(503)
            ->assertJsonPath('reason', 'artifact_failed');
        $this->assertStringNotContainsString('letter-document-artifacts', $response->getContent());
        $this->assertStringNotContainsString('source_hash', $response->getContent());
        $this->assertStringNotContainsString('stack trace', $response->getContent());
    }

    public function test_missing_pdf_file_returns_404_even_when_artifact_row_says_ready(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, [
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_READY,
            writeFile: false,
        );

        $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-keterangan-aktif/{$application->id}/generated-preview")
            ->assertStatus(404)
            ->assertJsonPath('reason', 'artifact_unavailable');
    }

    // ------------------------------------------------------------------
    // Read-only invariant
    // ------------------------------------------------------------------

    public function test_generated_preview_get_does_not_call_preview_generation_service_or_mutate_anything(): void
    {
        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldNotReceive('generateForPhase');
        $mock->shouldNotReceive('generateForCurrentPhase');
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);

        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => 'AKT-EP-008',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $before = $application->fresh()->only([
            'status',
            'student_reviewed_at',
            'completed_at',
        ]);
        $artifactCount = LetterDocumentArtifact::query()->count();

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $this->assertSame($artifactCount, LetterDocumentArtifact::query()->count());
        $this->assertSame($before, $application->fresh()->only([
            'status',
            'student_reviewed_at',
            'completed_at',
        ]));
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Legacy /preview is retired; generated-preview is the SKA preview path.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/preview")
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // PDF.js anti-download-manager response
    // ------------------------------------------------------------------

    public function test_pdfjs_preview_request_returns_bytes_without_download_disposition(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => 'AKT-EP-009',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $response = $this->actingAs($student, 'sanctum')
            ->withHeaders([
                'Accept' => 'application/pdf',
                'X-Requested-With' => 'XMLHttpRequest',
                'X-DTEDI-PDFJS-Preview' => '1',
            ])
            ->get("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/generated-preview");

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertNull($response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function artifact(
        SuratKeteranganAktifApplication $application,
        string $phase,
        string $status = LetterDocumentArtifact::STATUS_READY,
        bool $writeFile = true,
        ?string $errorMessage = null,
    ): LetterDocumentArtifact {
        $key = $application->id . '|' . $phase;
        $version = ($this->artifactVersions[$key] ?? 0) + 1;
        $this->artifactVersions[$key] = $version;

        $pdfPath = null;
        if ($status === LetterDocumentArtifact::STATUS_READY) {
            $pdfPath = 'letter-document-artifacts/'
                . SuratKeteranganAktifApplication::LETTER_TYPE
                . '/'
                . $application->id
                . '/'
                . $phase
                . '/preview_'
                . $version
                . '.pdf';

            if ($writeFile) {
                Storage::disk('local')->put($pdfPath, "%PDF-1.4\n{$phase}");
            }
        }

        return LetterDocumentArtifact::create([
            'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => $version,
            'docx_path' => 'letter-document-artifacts/'
                . SuratKeteranganAktifApplication::LETTER_TYPE
                . '/'
                . $application->id
                . '/'
                . $phase
                . '/source_'
                . $version
                . '.docx',
            'pdf_path' => $pdfPath,
            'source_hash' => hash('sha256', $application->id . '|' . $phase . '|' . $version . '|' . $status),
            'status' => $status,
            'error_message' => $errorMessage,
            'generated_at' => Carbon::now(),
        ]);
    }

    private function assertPdfResponse($response, string $expectedPhase): void
    {
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'inline; filename="surat-keterangan-aktif-',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString(
            $expectedPhase,
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
