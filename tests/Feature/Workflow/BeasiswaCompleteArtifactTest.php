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

class BeasiswaCompleteArtifactTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    /** @var array<string, int> */
    private array $artifactVersions = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-26 10:00:00'));
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_complete_ready_application_with_private_mahasiswa_artifact_and_null_compatibility_field(): void
    {
        $this->assertGenerationServicesAreNotCalled();

        [$student] = $this->completeMahasiswa();
        $application = $this->readyApplication($student);
        $artifact = $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/complete")
            ->assertOk()
            ->assertJsonPath('application.status', ScholarshipApplication::STATUS_COMPLETED)
            ->assertJsonPath('application.generated_docx_path', null);

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_COMPLETED, $fresh->status);
        $this->assertTrue($fresh->student_reviewed_at->equalTo(Carbon::now()));
        $this->assertTrue($fresh->completed_at->equalTo(Carbon::now()));
        Storage::disk('local')->assertExists($artifact->pdf_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_missing_mahasiswa_artifact_blocks_completion_without_private_path_leak(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->readyApplication($student);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/complete")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertBlockedApplicationUnchanged($application);
        $this->assertNoPrivateDetailsLeaked($response->getContent());
    }

    public function test_generating_latest_mahasiswa_artifact_returns_conflict_without_completion(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->readyApplication($student);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            LetterDocumentArtifact::STATUS_GENERATING,
            writeFile: false,
        );

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'artifact_generating');

        $this->assertBlockedApplicationUnchanged($application);
        $this->assertNoPrivateDetailsLeaked($response->getContent());
    }

    public function test_failed_latest_mahasiswa_artifact_returns_unavailable_without_completion(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->readyApplication($student);
        $this->artifact(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            LetterDocumentArtifact::STATUS_FAILED,
            writeFile: false,
            errorMessage: 'C:\\private\\letter-document-artifacts\\source_hash\\trace',
        );

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/complete")
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
            ->postJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/complete")
            ->assertForbidden();

        $this->assertBlockedApplicationUnchanged($application);
    }

    public function test_wrong_status_cannot_complete_even_when_artifact_exists(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'student_reviewed_at' => null,
            'completed_at' => null,
        ]);
        $this->artifact($application, LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/complete")
            ->assertForbidden()
            ->assertJsonPath('message', 'Pengajuan tidak berada pada tahap review mahasiswa.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->student_reviewed_at);
        $this->assertNull($fresh->completed_at);
    }

    private function readyApplication($student, array $attributes = []): ScholarshipApplication
    {
        return $this->scholarshipApplication($student, array_merge([
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
            'completed_at' => null,
        ], $attributes));
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

    private function assertGenerationServicesAreNotCalled(): void
    {
        $previewGeneration = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewGeneration->shouldNotReceive('generateForCurrentPhase');
        $previewGeneration->shouldNotReceive('generateForPhase');
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewGeneration);
    }

    private function assertBlockedApplicationUnchanged(ScholarshipApplication $application): void
    {
        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW, $fresh->status);
        $this->assertNull($fresh->student_reviewed_at);
        $this->assertNull($fresh->completed_at);
    }

    private function assertNoPrivateDetailsLeaked(string $content): void
    {
        $this->assertStringNotContainsString('letter-document-artifacts', $content);
        $this->assertStringNotContainsString('source_hash', $content);
        $this->assertStringNotContainsString('generated_docx_path', $content);
        $this->assertStringNotContainsString('storage/', $content);
        $this->assertStringNotContainsString('C:\\', $content);
    }
}
