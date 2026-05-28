<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Services\BeasiswaPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BeasiswaGeneratedPreviewEndpointTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    /** @var array<string, int> */
    private array $artifactVersions = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00'));
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_tendik_preview_uses_strict_assignment_and_current_status_phase(): void
    {
        $application = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => '001/SPB/2026',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($application, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $assignedTendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $unassignedTendik = $this->tendikPersuratan([]);

        $legacyResponse = $this->actingAs($assignedTendik, 'sanctum')
            ->get("/api/tendik/scholarship/{$application->id}/generated-preview");

        $this->assertPdfResponse($legacyResponse, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $canonicalResponse = $this->actingAs($assignedTendik, 'sanctum')
            ->get("/api/tendik/surat-permohonan-beasiswa/{$application->id}/generated-preview");

        $this->assertPdfResponse($canonicalResponse, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $this->actingAs($unassignedTendik, 'sanctum')
            ->getJson("/api/tendik/scholarship/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_akademik_preview_uses_detail_scope_not_approve_stage_gate(): void
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => '001/SPB/2026',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $sameProdi = $this->akademik('kaprodi', ['study_program_id' => $program->id]);
        $differentProdi = $this->akademik('kaprodi', ['study_program_id' => $this->studyProgram()->id]);
        $sameDepartment = $this->akademik('kadep', ['department_id' => $department->id]);
        $differentDepartment = $this->akademik('kadep', ['department_id' => $this->department()->id]);

        $this->assertPdfResponse(
            $this->actingAs($sameProdi, 'sanctum')
                ->get("/api/akademik/surat-permohonan-beasiswa/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $this->actingAs($differentProdi, 'sanctum')
            ->getJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/generated-preview")
            ->assertForbidden();

        $this->assertPdfResponse(
            $this->actingAs($sameDepartment, 'sanctum')
                ->get("/api/akademik/scholarship/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $this->actingAs($differentDepartment, 'sanctum')
            ->getJson("/api/akademik/scholarship/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_mahasiswa_owner_policy_allows_final_and_fallback_but_not_in_flight_phases(): void
    {
        [$student] = $this->completeMahasiswa();
        $readyApplication = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => '001/SPB/2026',
        ]);
        $this->artifact($readyApplication, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/surat-permohonan-beasiswa/{$readyApplication->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        [$otherStudent] = $this->completeMahasiswa();
        $this->actingAs($otherStudent, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$readyApplication->id}/generated-preview")
            ->assertForbidden();

        $submittedApplication = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);
        $this->artifact($submittedApplication, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$submittedApplication->id}/generated-preview")
            ->assertForbidden();

        $draftApplication = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$draftApplication->id}/generated-preview")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');
    }

    public function test_status_phase_mapping_serves_current_artifact_not_stale_prior_phase(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $cases = [
            ScholarshipApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            ScholarshipApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ScholarshipApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        ];

        foreach ($cases as $status => $expectedPhase) {
            $application = $this->scholarshipApplication(null, [
                'status' => $status,
                'nomor_surat' => '001/SPB/2026',
            ]);
            $this->artifact($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
            $this->artifact($application, $expectedPhase);

            $this->assertPdfResponse(
                $this->actingAs($tendik, 'sanctum')
                    ->get("/api/tendik/surat-permohonan-beasiswa/{$application->id}/generated-preview"),
                $expectedPhase,
            );
        }
    }

    public function test_revision_and_rejected_fallback_use_most_advanced_available_artifact(): void
    {
        [$student] = $this->completeMahasiswa();
        $revisionApplication = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_REVISION,
        ]);
        $this->artifact($revisionApplication, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($revisionApplication, LetterDocumentArtifact::PHASE_PRODI_REVIEW);
        $this->artifact($revisionApplication, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/scholarship/{$revisionApplication->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        );

        $rejectedApplication = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_REJECTED,
        ]);
        $this->artifact($rejectedApplication, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->artifact($rejectedApplication, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/scholarship/{$rejectedApplication->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $failedHigherPhaseApplication = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_REVISION,
        ]);
        $this->artifact(
            $failedHigherPhaseApplication,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            LetterDocumentArtifact::STATUS_FAILED,
        );
        $this->artifact($failedHigherPhaseApplication, LetterDocumentArtifact::PHASE_PRODI_REVIEW);

        $this->assertPdfResponse(
            $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/scholarship/{$failedHigherPhaseApplication->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        );
    }

    public function test_artifact_error_responses_do_not_leak_private_details(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $missingApplication = $this->scholarshipApplication();
        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-permohonan-beasiswa/{$missingApplication->id}/generated-preview")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $generatingApplication = $this->scholarshipApplication();
        $this->artifact(
            $generatingApplication,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_GENERATING,
        );
        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-permohonan-beasiswa/{$generatingApplication->id}/generated-preview")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'artifact_generating');

        $failedApplication = $this->scholarshipApplication();
        $this->artifact(
            $failedApplication,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            LetterDocumentArtifact::STATUS_FAILED,
            errorMessage: 'C:\\private\\letter-document-artifacts\\converter stack trace',
        );
        $failedResponse = $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-permohonan-beasiswa/{$failedApplication->id}/generated-preview")
            ->assertStatus(503)
            ->assertJsonPath('reason', 'artifact_failed');

        $this->assertStringNotContainsString('letter-document-artifacts', $failedResponse->getContent());
        $this->assertStringNotContainsString('source_hash', $failedResponse->getContent());
        $this->assertStringNotContainsString('converter stack trace', $failedResponse->getContent());

        $missingFileApplication = $this->scholarshipApplication();
        $this->artifact(
            $missingFileApplication,
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            writeFile: false,
        );
        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-permohonan-beasiswa/{$missingFileApplication->id}/generated-preview")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');
    }

    public function test_generated_preview_get_is_read_only_and_preview_route_remains_unavailable(): void
    {
        $mock = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $mock->shouldNotReceive('generateForCurrentPhase');
        $mock->shouldNotReceive('generateForPhase');
        $this->app->instance(BeasiswaPreviewGenerationService::class, $mock);

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => '001/SPB/2026',
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
                ->get("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/generated-preview"),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $this->assertSame($artifactCount, LetterDocumentArtifact::query()->count());
        $this->assertSame($before, $application->fresh()->only([
            'status',
            'student_reviewed_at',
            'completed_at',
        ]));

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/preview")
            ->assertNotFound();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW, $fresh->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_pdfjs_preview_request_returns_bytes_without_download_disposition(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => '001/SPB/2026',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $before = $application->fresh()->only([
            'status',
            'student_reviewed_at',
            'completed_at',
        ]);
        $artifactCount = LetterDocumentArtifact::query()->count();

        $response = $this->actingAs($student, 'sanctum')
            ->withHeaders([
                'Accept' => 'application/pdf',
                'X-Requested-With' => 'XMLHttpRequest',
                'X-DTEDI-PDFJS-Preview' => '1',
            ])
            ->get("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/generated-preview");

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertNull($response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('X-DTEDI-PDFJS-Preview', (string) $response->headers->get('Vary'));
        $this->assertSame($artifactCount, LetterDocumentArtifact::query()->count());
        $this->assertSame($before, $application->fresh()->only([
            'status',
            'student_reviewed_at',
            'completed_at',
        ]));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function artifact(
        ScholarshipApplication $application,
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
                . ScholarshipApplication::LETTER_TYPE
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
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => $version,
            'docx_path' => 'letter-document-artifacts/'
                . ScholarshipApplication::LETTER_TYPE
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
            'inline; filename="surat-permohonan-beasiswa-',
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
