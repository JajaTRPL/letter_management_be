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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BeasiswaSubmitPreviewArtifactTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:15:30'));
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_draft_submit_creates_ready_tendik_review_artifact_before_transition(): void
    {
        [$automation, $converter] = $this->bindPreviewStack();
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
        ]);
        $this->attachBeasiswaRequiredDocuments($application);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [
                'declaration_accepted' => true,
            ])
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertSame('2026-05-21 10:15:30', $fresh->submitted_at?->toDateTimeString());
        $this->assertSame($tendik->id, $fresh->assigned_to);

        $artifact = $this->readyTendikArtifact($application);
        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->status);
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/tendik_review/',
            $artifact->docx_path,
        );
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/tendik_review/',
            $artifact->pdf_path,
        );
        $this->assertTrue(Storage::disk('local')->exists($artifact->docx_path));
        $this->assertTrue(Storage::disk('local')->exists($artifact->pdf_path));
        $this->assertSame('%PDF', substr(Storage::disk('local')->get($artifact->pdf_path), 0, 4));
        $this->assertSame($student->id, $artifact->generated_by);
        $this->assertSame(1, $automation->generatePhaseCalls);
        $this->assertSame(1, $automation->assignCalls);
        $this->assertSame(1, $converter->calls);
        $this->assertSame('2026-05-21 10:15:30', $automation->lastOverrides['tanggal_surat']->toDateTimeString());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_revision_resubmit_creates_tendik_review_artifact_and_preserves_revision_fields(): void
    {
        $this->bindPreviewStack();
        $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_REVISION,
            'submitted_at' => Carbon::parse('2026-05-18 09:00:00'),
            'revision_note' => 'Perbaiki data keluarga.',
            'revised_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);
        $this->attachBeasiswaRequiredDocuments($application);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [
                'declaration_accepted' => true,
            ])
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertSame('2026-05-21 10:15:30', $fresh->submitted_at?->toDateTimeString());
        $this->assertSame('Perbaiki data keluarga.', $fresh->revision_note);
        $this->assertSame('2026-05-20 09:00:00', $fresh->revised_at?->toDateTimeString());
        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $this->readyTendikArtifact($application)->status);
    }

    public function test_preview_generation_failure_blocks_submit_mutations(): void
    {
        [$automation, $converter] = $this->bindPreviewStack(converterFails: true);
        $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
        ]);
        $this->attachBeasiswaRequiredDocuments($application);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [
                'declaration_accepted' => true,
            ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Dokumen pratinjau pengajuan belum dapat dibuat. Silakan coba lagi.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->assigned_to);
        $this->assertSame(1, $automation->generatePhaseCalls);
        $this->assertSame(0, $automation->assignCalls);
        $this->assertSame(1, $converter->calls);
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, LetterDocumentArtifact::query()->firstOrFail()->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_submit_recheck_does_not_overwrite_status_changed_after_artifact_generation(): void
    {
        $automation = new Phase2C1SubmitFakeScholarshipAutomationService();
        $this->app->instance(ScholarshipAutomationService::class, $automation);

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
        ]);
        $this->attachBeasiswaRequiredDocuments($application);

        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function () use ($application): LetterDocumentArtifact {
                $application->update(['status' => ScholarshipApplication::STATUS_REJECTED]);

                return LetterDocumentArtifact::make([
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [
                'declaration_accepted' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pengajuan sudah berubah dan tidak dapat dikirim ulang.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_REJECTED, $fresh->status);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->assigned_to);
        $this->assertSame(0, $automation->assignCalls);
    }

    public function test_submit_reuses_existing_tendik_review_ready_artifact_cache(): void
    {
        [$preAutomation, $preConverter] = $this->bindPreviewStack();
        $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
        ]);
        $this->attachBeasiswaRequiredDocuments($application);

        $pendingSubmittedAt = Carbon::now();
        $ready = app(BeasiswaPreviewGenerationService::class)->generateForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            [
                'status' => ScholarshipApplication::STATUS_SUBMITTED,
                'submitted_at' => $pendingSubmittedAt,
                'tanggal_surat' => $pendingSubmittedAt,
            ],
            $student->id,
        );
        $this->assertSame(1, $preAutomation->generatePhaseCalls);
        $this->assertSame(1, $preConverter->calls);

        [$submitAutomation, $submitConverter] = $this->bindPreviewStack();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [
                'declaration_accepted' => true,
            ])
            ->assertOk();

        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $application->fresh()->status);
        $this->assertSame(0, $submitAutomation->generatePhaseCalls);
        $this->assertSame(1, $submitAutomation->assignCalls);
        $this->assertSame(0, $submitConverter->calls);
        $this->assertSame(1, LetterDocumentArtifact::query()
            ->where('source_hash', $ready->source_hash)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->count());
    }

    /**
     * @return array{0: Phase2C1SubmitFakeScholarshipAutomationService, 1: Phase2C1SubmitFakeDocumentConverter}
     */
    private function bindPreviewStack(bool $converterFails = false): array
    {
        $automation = new Phase2C1SubmitFakeScholarshipAutomationService();
        $converter = new Phase2C1SubmitFakeDocumentConverter();
        $converter->fail = $converterFails;

        $this->app->instance(ScholarshipAutomationService::class, $automation);
        $this->app->instance(DocumentConverter::class, $converter);
        $this->app->forgetInstance(BeasiswaPreviewGenerationService::class);

        return [$automation, $converter];
    }

    private function readyTendikArtifact(ScholarshipApplication $application): LetterDocumentArtifact
    {
        return LetterDocumentArtifact::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->where('phase', LetterDocumentArtifact::PHASE_TENDIK_REVIEW)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->firstOrFail();
    }
}

class Phase2C1SubmitFakeScholarshipAutomationService extends ScholarshipAutomationService
{
    public int $generatePhaseCalls = 0;
    public int $assignCalls = 0;

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
        $this->lastOverrides = $pendingOverrides;

        $path = 'letter-document-artifacts/'
            . ScholarshipApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/source_submit_fake_'
            . $this->generatePhaseCalls
            . '.docx';
        Storage::disk('local')->put($path, 'fake docx');

        return $path;
    }

    public function assignApplication(ScholarshipApplication $application): ?\App\Models\User
    {
        $this->assignCalls++;

        return app(LetterAssignmentService::class)->assignToEligibleTendik(
            $application,
            ScholarshipApplication::LETTER_TYPE,
        );
    }
}

class Phase2C1SubmitFakeDocumentConverter implements DocumentConverter
{
    public int $calls = 0;
    public bool $fail = false;

    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void
    {
        $this->calls++;

        if ($this->fail) {
            throw new DocumentConverterException('fake submit conversion failure');
        }

        file_put_contents($destPdfAbsolutePath, "%PDF-1.4\nfake");
    }
}
