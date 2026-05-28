<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Services\DocumentConverter;
use App\Services\ProsesLuarNegeriDocumentGenerationService;
use App\Services\ProsesLuarNegeriPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Read-only contract for the PLN generated-preview endpoint.
 *
 * GET /generated-preview only streams existing private READY artifacts. It must
 * not call the PLN artifact/document/converter pipeline.
 */
class ProsesLuarNegeriGeneratedPreviewEndpointTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    /** @var array<string, int> */
    private array $artifactVersions = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-24 10:00:00'));
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mahasiswa_owner_can_stream_ready_mahasiswa_review_artifact_and_non_owner_forbidden(): void
    {
        [$student] = $this->completeMahasiswa();
        [$otherStudent] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => 'PLN-GP-001',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $this->actingAs($otherStudent, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_assigned_tendik_can_stream_current_phase_artifact_and_unassigned_cannot(): void
    {
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'PLN-GP-002',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $assignedTendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $unassignedTendik = $this->tendikPersuratan([]);

        $this->assertPdfResponse(
            $this->actingAs($assignedTendik, 'sanctum')
                ->get("/api/tendik/proses-luar-negeri/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        );

        $this->actingAs($unassignedTendik, 'sanctum')
            ->get("/api/tendik/proses-luar-negeri/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_akademik_same_scope_can_stream_and_wrong_scope_forbidden(): void
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

        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'PLN-GP-003',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->artifact($application, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($kaprodi, 'sanctum')
                ->get("/api/akademik/proses-luar-negeri/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );
        $this->assertPdfResponse(
            $this->actingAs($kadep, 'sanctum')
                ->get("/api/akademik/proses-luar-negeri/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );
        $this->actingAs($wrongKaprodi, 'sanctum')
            ->get("/api/akademik/proses-luar-negeri/{$application->id}/generated-preview")
            ->assertForbidden();
        $this->actingAs($wrongKadep, 'sanctum')
            ->get("/api/akademik/proses-luar-negeri/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_status_phase_mapping_serves_current_artifact_not_stale_prior_phase(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);

        $approvedTendik = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'PLN-GP-004',
        ]);
        $this->artifact($approvedTendik, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($approvedTendik, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($tendik, 'sanctum')
                ->get("/api/tendik/proses-luar-negeri/{$approvedTendik->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        );

        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);
        $kaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $studentProgram->id,
            'department_id' => $studentDept->id,
        ]);

        $approvedKaprodi = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'PLN-GP-005',
        ]);
        $this->artifact($approvedKaprodi, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->artifact($approvedKaprodi, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($kaprodi, 'sanctum')
                ->get("/api/akademik/proses-luar-negeri/{$approvedKaprodi->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );
    }

    public function test_revision_and_rejected_fallback_use_most_advanced_ready_artifact(): void
    {
        [$student] = $this->completeMahasiswa();

        $revised = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_REVISION,
            'nomor_surat' => 'PLN-GP-006',
        ]);
        $this->artifact($revised, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($revised, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->artifact($revised, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/proses-luar-negeri/{$revised->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );

        $rejected = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_REJECTED,
            'nomor_surat' => 'PLN-GP-007',
        ]);
        $this->artifact($rejected, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/proses-luar-negeri/{$rejected->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
        );
    }

    public function test_draft_owner_returns_unavailable_without_generation(): void
    {
        $this->bindGenerationServicesThatMustNotRun();

        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/generated-preview");

        $response->assertStatus(404)
            ->assertJsonPath('reason', 'artifact_unavailable');
        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_generating_latest_artifact_returns_409_without_leaking_paths(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_GENERATING,
            writeFile: false,
        );

        $response = $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/proses-luar-negeri/{$application->id}/generated-preview");

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'artifact_generating');
        $this->assertStringNotContainsString('letter-document-artifacts', $response->getContent());
        $this->assertStringNotContainsString('source_hash', $response->getContent());
    }

    public function test_failed_latest_artifact_returns_503_without_leaking_paths(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_FAILED,
            writeFile: false,
            errorMessage: 'D:\\private\\letter-document-artifacts\\source_hash\\stack trace',
        );

        $response = $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/proses-luar-negeri/{$application->id}/generated-preview");

        $response->assertStatus(503)
            ->assertJsonPath('reason', 'artifact_failed');
        $this->assertStringNotContainsString('letter-document-artifacts', $response->getContent());
        $this->assertStringNotContainsString('source_hash', $response->getContent());
        $this->assertStringNotContainsString('stack trace', $response->getContent());
    }

    public function test_missing_pdf_file_returns_404_even_when_artifact_row_says_ready(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_READY,
            writeFile: false,
        );

        $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/proses-luar-negeri/{$application->id}/generated-preview")
            ->assertStatus(404)
            ->assertJsonPath('reason', 'artifact_unavailable');
    }

    public function test_generated_preview_get_is_read_only_and_does_not_call_generation_services(): void
    {
        $this->bindGenerationServicesThatMustNotRun();

        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
            'completed_at' => null,
            'nomor_surat' => 'PLN-GP-008',
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
                ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $this->assertSame($artifactCount, LetterDocumentArtifact::query()->count());
        $this->assertSame($before, $application->fresh()->only([
            'status',
            'student_reviewed_at',
            'completed_at',
        ]));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_pdfjs_preview_request_returns_octet_stream_without_content_disposition(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => 'PLN-GP-009',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $response = $this->actingAs($student, 'sanctum')
            ->withHeaders([
                'Accept' => 'application/pdf',
                'X-Requested-With' => 'XMLHttpRequest',
                'X-DTEDI-PDFJS-Preview' => '1',
            ])
            ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/generated-preview");

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertNull($response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString(
            'X-DTEDI-PDFJS-Preview',
            (string) $response->headers->get('Vary'),
        );
    }

    public function test_preview_route_remains_retired_without_artifact_dependency(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => 'PLN-GP-010',
        ]);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/preview")
            ->assertNotFound();
    }

    private function artifact(
        ProsesLuarNegeriApplication $application,
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
                . ProsesLuarNegeriApplication::LETTER_TYPE
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
            'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => $version,
            'docx_path' => 'letter-document-artifacts/'
                . ProsesLuarNegeriApplication::LETTER_TYPE
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
            'inline; filename="proses-luar-negeri-',
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

    private function bindGenerationServicesThatMustNotRun(): void
    {
        $preview = Mockery::mock(ProsesLuarNegeriPreviewGenerationService::class);
        $preview->shouldNotReceive('generateForPhase');
        $preview->shouldNotReceive('generateForCurrentPhase');
        $this->app->instance(ProsesLuarNegeriPreviewGenerationService::class, $preview);

        $document = Mockery::mock(ProsesLuarNegeriDocumentGenerationService::class);
        $document->shouldNotReceive('generateDocumentForPhase');
        $this->app->instance(ProsesLuarNegeriDocumentGenerationService::class, $document);

        $converter = Mockery::mock(DocumentConverter::class);
        $converter->shouldNotReceive('convertDocxToPdf');
        $this->app->instance(DocumentConverter::class, $converter);
    }
}
