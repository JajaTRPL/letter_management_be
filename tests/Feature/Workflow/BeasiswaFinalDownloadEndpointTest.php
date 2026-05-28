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

class BeasiswaFinalDownloadEndpointTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

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

    public function test_owner_can_download_completed_final_pdf_from_latest_ready_mahasiswa_artifact(): void
    {
        $mock = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $mock->shouldNotReceive('generateForCurrentPhase');
        $mock->shouldNotReceive('generateForPhase');
        $this->app->instance(BeasiswaPreviewGenerationService::class, $mock);

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'completed_at' => Carbon::now(),
            'nomor_surat' => '001/SPB/2026',
        ]);

        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, 1, "%PDF-1.4\nold");
        $latestArtifact = $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, 2, "%PDF-1.4\nlatest");

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

        foreach (['surat-permohonan-beasiswa', 'scholarship'] as $prefix) {
            $response = $this->actingAs($student, 'sanctum')
                ->get("/api/mahasiswa/{$prefix}/{$application->id}/final-download");

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('attachment', strtolower((string) $response->headers->get('Content-Disposition')));
            $this->assertStringContainsString(
                'surat-permohonan-beasiswa-' . $application->id . '.pdf',
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
        }

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

    public function test_owner_cannot_download_final_pdf_before_completed(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => '001/SPB/2026',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/final-download")
            ->assertForbidden();
    }

    public function test_non_owner_cannot_download_final_pdf(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$otherStudent] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($owner, [
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'completed_at' => Carbon::now(),
            'nomor_surat' => '001/SPB/2026',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($otherStudent, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/final-download")
            ->assertForbidden();
    }

    public function test_missing_mahasiswa_review_pdf_artifact_returns_not_found_without_path_leak(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'completed_at' => Carbon::now(),
            'nomor_surat' => '001/SPB/2026',
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            status: LetterDocumentArtifact::STATUS_FAILED,
        );

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/final-download")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertStringNotContainsString('letter-document-artifacts', $response->getContent());
        $this->assertStringNotContainsString('source_hash', $response->getContent());
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, LetterDocumentArtifact::query()
            ->where('application_id', $application->id)
            ->where('phase', LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW)
            ->firstOrFail()
            ->status);
    }

    private function artifact(
        ScholarshipApplication $application,
        string $phase,
        int $version = 1,
        string $bytes = "%PDF-1.4\nfake",
        string $status = LetterDocumentArtifact::STATUS_READY,
    ): LetterDocumentArtifact {
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
            Storage::disk('local')->put($pdfPath, $bytes);
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
            'error_message' => $status === LetterDocumentArtifact::STATUS_FAILED ? 'private converter detail' : null,
            'generated_at' => Carbon::now(),
        ]);
    }
}
