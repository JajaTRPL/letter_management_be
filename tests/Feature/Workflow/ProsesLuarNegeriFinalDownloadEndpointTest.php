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

class ProsesLuarNegeriFinalDownloadEndpointTest extends TestCase
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

    public function test_owner_can_download_completed_final_pdf_from_latest_ready_mahasiswa_artifact(): void
    {
        $this->bindGenerationServicesThatMustNotRun();

        [$student] = $this->completeMahasiswa();
        $application = $this->completedApplication($student);

        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, bytes: "%PDF-1.4\nold");
        $latestArtifact = $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            bytes: "%PDF-1.4\nlatest",
        );
        $this->artifact($application, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, bytes: "%PDF-1.4\ndepartemen");

        $beforeApplication = $application->fresh()->only([
            'status',
            'student_reviewed_at',
            'completed_at',
        ]);
        $beforeArtifact = $latestArtifact->fresh()->only([
            'status',
            'docx_path',
            'pdf_path',
            'version',
            'source_hash',
            'error_message',
        ]);
        $artifactCount = LetterDocumentArtifact::query()->count();

        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/final-download");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', strtolower((string) $response->headers->get('Content-Disposition')));
        $this->assertStringContainsString(
            'proses-luar-negeri-' . $application->id . '.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith(
            '%PDF',
            file_get_contents($response->baseResponse->getFile()->getPathname()),
        );
        $this->assertStringContainsString(
            'latest',
            file_get_contents($response->baseResponse->getFile()->getPathname()),
        );

        $this->assertSame($artifactCount, LetterDocumentArtifact::query()->count());
        $this->assertSame($beforeArtifact, $latestArtifact->fresh()->only([
            'status',
            'docx_path',
            'pdf_path',
            'version',
            'source_hash',
            'error_message',
        ]));
        $this->assertEquals($beforeApplication, $application->fresh()->only([
            'status',
            'student_reviewed_at',
            'completed_at',
        ]));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_non_owner_cannot_download_final_pdf(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$otherStudent] = $this->completeMahasiswa();
        $application = $this->completedApplication($owner);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($otherStudent, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/final-download")
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden');
    }

    public function test_owner_cannot_download_before_completed(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => Carbon::parse('2026-05-24 09:00:00'),
            'completed_at' => null,
            'nomor_surat' => 'PLN/001/2026',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);
        $artifactCount = LetterDocumentArtifact::query()->count();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/final-download")
            ->assertForbidden()
            ->assertJsonPath('reason', 'application_not_completed');

        $this->assertSame($artifactCount, LetterDocumentArtifact::query()->count());
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW, $application->fresh()->status);
    }

    public function test_completed_application_with_no_artifact_returns_not_found_without_generated_path_fallback(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->completedApplication($student);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/final-download")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertNoPrivateDetailsLeaked($response->getContent());
    }

    public function test_generating_latest_mahasiswa_artifact_returns_conflict_without_fallback(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->completedApplication($student);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, bytes: "%PDF-1.4\nready");
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            LetterDocumentArtifact::STATUS_GENERATING,
            writeFile: false,
        );

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/final-download")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'artifact_generating');

        $this->assertNoPrivateDetailsLeaked($response->getContent());
    }

    public function test_failed_latest_mahasiswa_artifact_returns_service_unavailable_without_fallback(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->completedApplication($student);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, bytes: "%PDF-1.4\nready");
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            LetterDocumentArtifact::STATUS_FAILED,
            writeFile: false,
            errorMessage: 'D:\\private\\letter-document-artifacts\\source_hash\\stack trace',
        );

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/final-download")
            ->assertStatus(503)
            ->assertJsonPath('reason', 'artifact_failed');

        $this->assertNoPrivateDetailsLeaked($response->getContent());
        $this->assertStringNotContainsString('stack trace', $response->getContent());
    }

    public function test_ready_artifact_with_missing_pdf_file_returns_not_found(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->completedApplication($student);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            LetterDocumentArtifact::STATUS_READY,
            writeFile: false,
        );

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/final-download")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertNoPrivateDetailsLeaked($response->getContent());
    }

    public function test_non_mahasiswa_review_artifact_is_not_used_for_final_download(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->completedApplication($student);
        $this->artifact($application, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/final-download")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertNoPrivateDetailsLeaked($response->getContent());
    }

    public function test_preview_route_remains_retired_without_artifact_dependency(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => 'PLN/LEGACY/2026',
        ]);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/preview")
            ->assertNotFound();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function completedApplication($student, array $attributes = []): ProsesLuarNegeriApplication
    {
        return $this->prosesLuarNegeriApplication($student, array_merge([
            'status' => ProsesLuarNegeriApplication::STATUS_COMPLETED,
            'completed_at' => Carbon::now(),
            'student_reviewed_at' => Carbon::parse('2026-05-24 09:00:00'),
            'nomor_surat' => 'PLN/001/2026',
        ], $attributes));
    }

    private function artifact(
        ProsesLuarNegeriApplication $application,
        string $phase,
        string $status = LetterDocumentArtifact::STATUS_READY,
        bool $writeFile = true,
        string $bytes = "%PDF-1.4\nfake",
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
                Storage::disk('local')->put($pdfPath, $bytes);
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

    private function assertNoPrivateDetailsLeaked(string $content): void
    {
        $this->assertStringNotContainsString('letter-document-artifacts', $content);
        $this->assertStringNotContainsString('source_hash', $content);
        $this->assertStringNotContainsString('generated_pdf_path', $content);
        $this->assertStringNotContainsString('storage/', $content);
        $this->assertStringNotContainsString('D:\\', $content);
    }
}
