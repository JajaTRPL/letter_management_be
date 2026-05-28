<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratPengantarMagangApplication;
use App\Services\DocumentConverter;
use App\Services\SuratPengantarMagangDocumentGenerationService;
use App\Services\SuratPengantarMagangPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Read-only contract for the Magang generated-preview endpoint.
 *
 * GET /generated-preview only streams existing private READY artifacts. It
 * does not invoke document or preview generation.
 */
class SuratPengantarMagangGeneratedPreviewEndpointTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    /** @var array<string, int> */
    private array $artifactVersions = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-25 10:00:00'));
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mahasiswa_owner_can_stream_ready_final_artifact_while_non_owner_and_in_flight_owner_cannot(): void
    {
        [$student] = $this->completeMahasiswa();
        [$otherStudent] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/surat-pengantar-magang/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $this->actingAs($otherStudent, 'sanctum')
            ->get("/api/mahasiswa/surat-pengantar-magang/{$application->id}/generated-preview")
            ->assertForbidden();

        $inFlight = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
        ]);
        $this->artifact($inFlight, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-pengantar-magang/{$inFlight->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_assigned_tendik_serves_current_prodi_phase_not_stale_tendik_phase_and_unassigned_is_forbidden(): void
    {
        $application = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $assignedTendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $unassignedTendik = $this->tendikPersuratan([]);

        $this->assertPdfResponse(
            $this->actingAs($assignedTendik, 'sanctum')
                ->get("/api/tendik/surat-pengantar-magang/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        );

        $this->actingAs($unassignedTendik, 'sanctum')
            ->get("/api/tendik/surat-pengantar-magang/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_akademik_same_scope_serves_departemen_phase_while_wrong_scope_is_forbidden(): void
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $kaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $program->id,
            'department_id' => $department->id,
        ]);
        $kadep = $this->akademik('kadep', ['department_id' => $department->id]);

        $otherDepartment = $this->department();
        $otherProgram = $this->studyProgram($otherDepartment);
        $wrongKaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $otherProgram->id,
            'department_id' => $otherDepartment->id,
        ]);
        $wrongKadep = $this->akademik('kadep', ['department_id' => $otherDepartment->id]);

        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->artifact($application, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($kaprodi, 'sanctum')
                ->get("/api/akademik/surat-pengantar-magang/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );
        $this->assertPdfResponse(
            $this->actingAs($kadep, 'sanctum')
                ->get("/api/akademik/surat-pengantar-magang/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );

        $this->actingAs($wrongKaprodi, 'sanctum')
            ->get("/api/akademik/surat-pengantar-magang/{$application->id}/generated-preview")
            ->assertForbidden();
        $this->actingAs($wrongKadep, 'sanctum')
            ->get("/api/akademik/surat-pengantar-magang/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_revision_and_rejected_use_most_advanced_ready_fallback_artifact(): void
    {
        [$student] = $this->completeMahasiswa();

        $revision = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_REVISION,
        ]);
        $this->artifact($revision, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($revision, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->artifact($revision, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/surat-pengantar-magang/{$revision->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );

        $rejected = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_REJECTED,
        ]);
        $this->artifact($rejected, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($rejected, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/surat-pengantar-magang/{$rejected->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );
    }

    public function test_draft_and_missing_current_artifact_return_unavailable_without_generation(): void
    {
        $this->bindGenerationServicesThatMustNotRun();

        [$student] = $this->completeMahasiswa();
        $draft = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
        ]);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-pengantar-magang/{$draft->id}/generated-preview")
            ->assertStatus(404)
            ->assertJsonPath('reason', 'artifact_unavailable');

        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $submitted = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-pengantar-magang/{$submitted->id}/generated-preview")
            ->assertStatus(404)
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_latest_generating_and_failed_artifacts_return_safe_status_errors(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);

        $generating = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact(
            $generating,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_GENERATING,
            writeFile: false,
        );

        $generatingResponse = $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-pengantar-magang/{$generating->id}/generated-preview");
        $generatingResponse->assertStatus(409)
            ->assertJsonPath('reason', 'artifact_generating');

        $failed = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact(
            $failed,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_FAILED,
            writeFile: false,
            errorMessage: 'D:\\private\\letter-document-artifacts\\source_hash\\stack trace',
        );

        $failedResponse = $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-pengantar-magang/{$failed->id}/generated-preview");
        $failedResponse->assertStatus(503)
            ->assertJsonPath('reason', 'artifact_failed');

        foreach ([$generatingResponse, $failedResponse] as $response) {
            $this->assertStringNotContainsString('letter-document-artifacts', $response->getContent());
            $this->assertStringNotContainsString('source_hash', $response->getContent());
            $this->assertStringNotContainsString('stack trace', $response->getContent());
        }
    }

    public function test_ready_artifact_with_missing_pdf_file_returns_safe_not_found(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_READY,
            writeFile: false,
        );

        $response = $this->actingAs($tendik, 'sanctum')
            ->get("/api/tendik/surat-pengantar-magang/{$application->id}/generated-preview");

        $response->assertStatus(404)
            ->assertJsonPath('reason', 'artifact_unavailable');
        $this->assertStringNotContainsString('letter-document-artifacts', $response->getContent());
        $this->assertStringNotContainsString('source_hash', $response->getContent());
    }

    public function test_generated_preview_get_is_read_only_and_does_not_call_generation_services(): void
    {
        $this->bindGenerationServicesThatMustNotRun();

        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
            'completed_at' => null,
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
                ->get("/api/mahasiswa/surat-pengantar-magang/{$application->id}/generated-preview"),
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

    public function test_pdfjs_request_streams_octet_bytes_without_content_disposition(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $response = $this->actingAs($student, 'sanctum')
            ->withHeaders([
                'Accept' => 'application/pdf',
                'X-Requested-With' => 'XMLHttpRequest',
                'X-DTEDI-PDFJS-Preview' => '1',
            ])
            ->get("/api/mahasiswa/surat-pengantar-magang/{$application->id}/generated-preview");

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
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-pengantar-magang/{$application->id}/preview")
            ->assertNotFound();
    }

    private function artifact(
        SuratPengantarMagangApplication $application,
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
                . SuratPengantarMagangApplication::LETTER_TYPE
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
            'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => $version,
            'docx_path' => 'letter-document-artifacts/'
                . SuratPengantarMagangApplication::LETTER_TYPE
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
            'inline; filename="surat-pengantar-magang-',
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
        $preview = Mockery::mock(SuratPengantarMagangPreviewGenerationService::class);
        $preview->shouldNotReceive('generateForPhase');
        $preview->shouldNotReceive('generateForCurrentPhase');
        $this->app->instance(SuratPengantarMagangPreviewGenerationService::class, $preview);

        $document = Mockery::mock(SuratPengantarMagangDocumentGenerationService::class);
        $document->shouldNotReceive('generateDocumentForPhase');
        $this->app->instance(SuratPengantarMagangDocumentGenerationService::class, $document);

        $converter = Mockery::mock(DocumentConverter::class);
        $converter->shouldNotReceive('convertDocxToPdf');
        $this->app->instance(DocumentConverter::class, $converter);
    }
}
