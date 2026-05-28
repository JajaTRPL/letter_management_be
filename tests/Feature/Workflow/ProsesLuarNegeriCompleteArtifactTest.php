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

class ProsesLuarNegeriCompleteArtifactTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    /** @var array<string, int> */
    private array $artifactVersions = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-25 14:00:00'));
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_complete_ready_application_with_private_mahasiswa_artifact(): void
    {
        $this->assertGenerationServicesAreNotCalled();

        [$student] = $this->completeMahasiswa();
        $application = $this->readyApplication($student);
        $artifact = $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);
        $artifactCount = LetterDocumentArtifact::query()->count();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_COMPLETED);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_COMPLETED, $fresh->status);
        $this->assertTrue($fresh->student_reviewed_at->equalTo(Carbon::now()));
        $this->assertTrue($fresh->completed_at->equalTo(Carbon::now()));
        $this->assertSame($artifactCount, LetterDocumentArtifact::query()->count());
        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->fresh()->status);
        Storage::disk('local')->assertExists($artifact->pdf_path);
    }

    public function test_missing_mahasiswa_artifact_blocks_completion_without_state_change(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->readyApplication($student);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertBlockedApplicationUnchanged($application);
        $this->assertSame(0, LetterDocumentArtifact::query()->count());
        $this->assertNoPrivateDetailsLeaked($response->getContent());
    }

    public function test_ready_mahasiswa_artifact_with_missing_pdf_blocks_completion(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->readyApplication($student);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            LetterDocumentArtifact::STATUS_READY,
            writeFile: false,
        );

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertBlockedApplicationUnchanged($application);
        $this->assertNoPrivateDetailsLeaked($response->getContent());
    }

    public function test_generating_latest_mahasiswa_artifact_returns_conflict_without_completion(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->readyApplication($student);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            LetterDocumentArtifact::STATUS_GENERATING,
            writeFile: false,
        );

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'artifact_generating');

        $this->assertBlockedApplicationUnchanged($application);
        $this->assertNoPrivateDetailsLeaked($response->getContent());
    }

    public function test_failed_latest_mahasiswa_artifact_returns_unavailable_without_completion(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->readyApplication($student);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            LetterDocumentArtifact::STATUS_FAILED,
            writeFile: false,
            errorMessage: 'D:\\private\\letter-document-artifacts\\source_hash\\trace',
        );

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertStatus(503)
            ->assertJsonPath('reason', 'artifact_failed');

        $this->assertBlockedApplicationUnchanged($application);
        $this->assertNoPrivateDetailsLeaked($response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
    }

    public function test_non_owner_cannot_complete_even_when_artifact_exists(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$otherStudent] = $this->completeMahasiswa();
        $application = $this->readyApplication($owner);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($otherStudent, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertForbidden();

        $this->assertBlockedApplicationUnchanged($application);
    }

    public function test_wrong_status_cannot_complete_even_when_artifact_exists(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'nomor_surat' => 'PLN/001/2026',
            'student_reviewed_at' => null,
            'completed_at' => null,
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pengajuan belum berada pada tahap review mahasiswa.');

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->student_reviewed_at);
        $this->assertNull($fresh->completed_at);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function readyApplication($student, array $attributes = []): ProsesLuarNegeriApplication
    {
        return $this->prosesLuarNegeriApplication($student, array_merge([
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'nomor_surat' => 'PLN/001/2026',
            'student_reviewed_at' => null,
            'completed_at' => null,
        ], $attributes));
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

    private function assertGenerationServicesAreNotCalled(): void
    {
        $previewGeneration = Mockery::mock(ProsesLuarNegeriPreviewGenerationService::class);
        $previewGeneration->shouldNotReceive('generateForCurrentPhase');
        $previewGeneration->shouldNotReceive('generateForPhase');
        $this->app->instance(ProsesLuarNegeriPreviewGenerationService::class, $previewGeneration);

        $document = Mockery::mock(ProsesLuarNegeriDocumentGenerationService::class);
        $document->shouldNotReceive('generateDocumentForPhase');
        $this->app->instance(ProsesLuarNegeriDocumentGenerationService::class, $document);

        $converter = Mockery::mock(DocumentConverter::class);
        $converter->shouldNotReceive('convertDocxToPdf');
        $this->app->instance(DocumentConverter::class, $converter);

    }

    private function assertBlockedApplicationUnchanged(ProsesLuarNegeriApplication $application): void
    {
        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW, $fresh->status);
        $this->assertNull($fresh->student_reviewed_at);
        $this->assertNull($fresh->completed_at);
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
