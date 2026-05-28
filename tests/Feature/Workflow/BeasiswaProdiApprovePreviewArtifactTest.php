<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Services\AcademicSignatoryService;
use App\Services\BeasiswaPreviewGenerationService;
use App\Services\DocumentConverter;
use App\Services\DocumentConverterException;
use App\Services\LetterAssignmentService;
use App\Services\MahasiswaProfileDataService;
use App\Services\ScholarshipAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BeasiswaProdiApprovePreviewArtifactTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:20:30'));
        Cache::flush();
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_same_prodi_akademik_approve_creates_ready_departemen_review_artifact_before_transition(): void
    {
        [$automation, $converter] = $this->bindPreviewStack();
        [$application, $sekprodi] = $this->prodiStageApplication();

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertSame('BEA-PRODI-STAGE-001', $fresh->nomor_surat);
        $this->assertSame('2026-05-21 12:20:30', $fresh->kaprodi_approved_at?->toDateTimeString());
        $this->assertSame($sekprodi->id, $fresh->kaprodi_approved_by);

        $artifact = $this->readyDepartemenArtifact($application);
        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->status);
        $this->assertSame($sekprodi->id, $artifact->generated_by);
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/departemen_review/',
            $artifact->docx_path,
        );
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/departemen_review/',
            $artifact->pdf_path,
        );
        $this->assertTrue(Storage::disk('local')->exists($artifact->docx_path));
        $this->assertTrue(Storage::disk('local')->exists($artifact->pdf_path));
        $this->assertSame('%PDF', substr(Storage::disk('local')->get($artifact->pdf_path), 0, 4));
        $this->assertSame(1, $automation->generatePhaseCalls);
        $this->assertSame(1, $converter->calls);
        $this->assertSame(LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, $automation->lastPhase);
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_KAPRODI, $automation->lastOverrides['status']);
        $this->assertSame('2026-05-21 12:20:30', $automation->lastOverrides['kaprodi_approved_at']->toDateTimeString());
        $this->assertSame($sekprodi->id, $automation->lastOverrides['kaprodi_approved_by']);
        $this->assertSame('2026-05-20 09:00:00', $automation->lastOverrides['tanggal_surat']->toDateTimeString());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_preview_generation_failure_blocks_prodi_approve_mutations(): void
    {
        [$automation, $converter] = $this->bindPreviewStack(converterFails: true);
        [$application, $sekprodi] = $this->prodiStageApplication();

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Dokumen pratinjau persetujuan Prodi belum dapat dibuat. Silakan coba lagi.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertNull($fresh->kaprodi_approved_at);
        $this->assertNull($fresh->kaprodi_approved_by);
        $this->assertSame(1, $automation->generatePhaseCalls);
        $this->assertSame(1, $converter->calls);
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, LetterDocumentArtifact::query()->firstOrFail()->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_different_prodi_akademik_gets_forbidden_and_no_artifact_generation(): void
    {
        [$application] = $this->prodiStageApplication();
        $otherProgram = $this->studyProgram($this->department());
        $wrongKaprodi = $this->akademik('kaprodi', ['study_program_id' => $otherProgram->id]);
        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')->never();
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($wrongKaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertNull($fresh->kaprodi_approved_at);
        $this->assertNull($fresh->kaprodi_approved_by);
        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_non_prodi_actionable_status_returns_422_without_artifact_generation(): void
    {
        [$application, $sekprodi] = $this->prodiStageApplication([
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'kaprodi_approved_at' => Carbon::parse('2026-05-20 10:00:00'),
            'kaprodi_approved_by' => null,
        ]);
        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')->never();
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pengajuan tidak berada pada tahap persetujuan Kaprodi/Sekprodi.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertSame('2026-05-20 10:00:00', $fresh->kaprodi_approved_at?->toDateTimeString());
        $this->assertNull($fresh->kaprodi_approved_by);
        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_prodi_approve_reuses_existing_departemen_review_ready_artifact_cache(): void
    {
        [$preAutomation, $preConverter] = $this->bindPreviewStack();
        [$application, $sekprodi] = $this->prodiStageApplication();

        $approvedAt = Carbon::now();
        $ready = app(BeasiswaPreviewGenerationService::class)->generateForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            [
                'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
                'kaprodi_approved_at' => $approvedAt,
                'kaprodi_approved_by' => $sekprodi->id,
                'tanggal_surat' => Carbon::parse('2026-05-20 09:00:00'),
            ],
            $sekprodi->id,
        );
        $this->assertSame(1, $preAutomation->generatePhaseCalls);
        $this->assertSame(1, $preConverter->calls);

        [$approveAutomation, $approveConverter] = $this->bindPreviewStack();

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertSame(0, $approveAutomation->generatePhaseCalls);
        $this->assertSame(0, $approveConverter->calls);
        $this->assertSame(1, LetterDocumentArtifact::query()
            ->where('source_hash', $ready->source_hash)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->count());
    }

    public function test_prodi_approve_recheck_does_not_overwrite_status_changed_after_artifact_generation(): void
    {
        [$application, $sekprodi] = $this->prodiStageApplication();
        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function () use ($application): LetterDocumentArtifact {
                $application->update(['status' => ScholarshipApplication::STATUS_REJECTED]);

                return LetterDocumentArtifact::make([
                    'phase' => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pengajuan sudah berubah dan tidak dapat disetujui ulang oleh Prodi.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_REJECTED, $fresh->status);
        $this->assertNull($fresh->kaprodi_approved_at);
        $this->assertNull($fresh->kaprodi_approved_by);
    }

    /**
     * @param array<string, mixed> $applicationAttributes
     * @return array{0: ScholarshipApplication, 1: \App\Models\User}
     */
    private function prodiStageApplication(array $applicationAttributes = []): array
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $sekprodi = $this->akademik('sekprodi', ['study_program_id' => $program->id]);

        $application = $this->scholarshipApplication($student, array_merge([
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'BEA-PRODI-STAGE-001',
            'submitted_at' => Carbon::parse('2026-05-19 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-20 09:00:00'),
            'tendik_approved_by' => $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE])->id,
            'kaprodi_approved_at' => null,
            'kaprodi_approved_by' => null,
        ], $applicationAttributes));

        return [$application, $sekprodi];
    }

    /**
     * @return array{0: Phase2C3ProdiApproveFakeScholarshipAutomationService, 1: Phase2C3ProdiApproveFakeDocumentConverter}
     */
    private function bindPreviewStack(bool $converterFails = false): array
    {
        $automation = new Phase2C3ProdiApproveFakeScholarshipAutomationService();
        $converter = new Phase2C3ProdiApproveFakeDocumentConverter();
        $converter->fail = $converterFails;

        $this->app->instance(ScholarshipAutomationService::class, $automation);
        $this->app->instance(DocumentConverter::class, $converter);
        $this->app->forgetInstance(BeasiswaPreviewGenerationService::class);

        return [$automation, $converter];
    }

    private function readyDepartemenArtifact(ScholarshipApplication $application): LetterDocumentArtifact
    {
        return LetterDocumentArtifact::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->where('phase', LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->firstOrFail();
    }
}

class Phase2C3ProdiApproveFakeScholarshipAutomationService extends ScholarshipAutomationService
{
    public int $generatePhaseCalls = 0;
    public ?string $lastPhase = null;

    /** @var array<string, mixed> */
    public array $lastOverrides = [];

    public function __construct()
    {
        parent::__construct(
            app(LetterAssignmentService::class),
            app(AcademicSignatoryService::class),
            app(MahasiswaProfileDataService::class),
        );
    }

    public function generateDocumentForPhase(
        ScholarshipApplication $application,
        string $phase,
        array $pendingOverrides = [],
    ): string|false {
        $this->generatePhaseCalls++;
        $this->lastPhase = $phase;
        $this->lastOverrides = $pendingOverrides;

        $path = 'letter-document-artifacts/'
            . ScholarshipApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/source_prodi_fake_'
            . $this->generatePhaseCalls
            . '.docx';
        Storage::disk('local')->put($path, 'fake docx');

        return $path;
    }
}

class Phase2C3ProdiApproveFakeDocumentConverter implements DocumentConverter
{
    public int $calls = 0;
    public bool $fail = false;

    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void
    {
        $this->calls++;

        if ($this->fail) {
            throw new DocumentConverterException('fake prodi approve conversion failure');
        }

        file_put_contents($destPdfAbsolutePath, "%PDF-1.4\nfake");
    }
}
