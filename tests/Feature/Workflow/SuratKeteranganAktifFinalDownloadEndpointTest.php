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

class SuratKeteranganAktifFinalDownloadEndpointTest extends TestCase
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

    public function test_owner_can_download_completed_final_pdf_from_latest_ready_mahasiswa_artifact(): void
    {
        $previewGeneration = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $previewGeneration->shouldNotReceive('generateForCurrentPhase');
        $previewGeneration->shouldNotReceive('generateForPhase');
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $previewGeneration);

        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_COMPLETED,
            'student_reviewed_at' => Carbon::parse('2026-05-23 09:00:00'),
            'completed_at' => Carbon::now(),
            'nomor_surat' => 'AKT/001/2026',
        ]);

        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, bytes: "%PDF-1.4\nold");
        $latestArtifact = $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, bytes: "%PDF-1.4\nlatest");
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
            ->get("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/final-download");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', strtolower((string) $response->headers->get('Content-Disposition')));
        $this->assertStringContainsString(
            'surat-keterangan-aktif-' . $application->id . '.pdf',
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

        // Legacy /preview is retired and final-download does not revive it.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/preview")
            ->assertNotFound();
    }

    public function test_non_owner_cannot_download_final_pdf(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$otherStudent] = $this->completeMahasiswa();
        $application = $this->completedApplication($owner);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($otherStudent, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/final-download")
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden');
    }

    public function test_owner_cannot_download_before_completed(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => 'AKT/001/2026',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);
        $artifactCount = LetterDocumentArtifact::query()->count();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/final-download")
            ->assertForbidden()
            ->assertJsonPath('reason', 'application_not_completed');

        $this->assertSame($artifactCount, LetterDocumentArtifact::query()->count());
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW, $application->fresh()->status);
    }

    public function test_completed_application_with_no_artifact_returns_not_found_without_path_leak(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->completedApplication($student);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/final-download")
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
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/final-download")
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
            errorMessage: 'C:\\private\\letter-document-artifacts\\stack trace source_hash',
        );

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/final-download")
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
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/final-download")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertNoPrivateDetailsLeaked($response->getContent());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function completedApplication($student, array $attributes = []): SuratKeteranganAktifApplication
    {
        return $this->aktifApplication($student, array_merge([
            'status' => SuratKeteranganAktifApplication::STATUS_COMPLETED,
            'completed_at' => Carbon::now(),
            'student_reviewed_at' => Carbon::parse('2026-05-23 09:00:00'),
            'nomor_surat' => 'AKT/001/2026',
        ], $attributes));
    }

    private function artifact(
        SuratKeteranganAktifApplication $application,
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
                . SuratKeteranganAktifApplication::LETTER_TYPE
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

    private function assertNoPrivateDetailsLeaked(string $content): void
    {
        $this->assertStringNotContainsString('letter-document-artifacts', $content);
        $this->assertStringNotContainsString('source_hash', $content);
        $this->assertStringNotContainsString('generated_pdf_path', $content);
        $this->assertStringNotContainsString('storage/', $content);
        $this->assertStringNotContainsString('C:\\', $content);
    }
}
