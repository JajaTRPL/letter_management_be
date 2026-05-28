<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Models\SuratPengantarMagangApplication;
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

class BeasiswaTendikApprovePreviewArtifactTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-21 11:20:30'));
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

    public function test_submitted_beasiswa_tendik_approve_creates_ready_prodi_review_artifact_before_transition(): void
    {
        [$automation, $converter] = $this->bindPreviewStack();
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'nomor_surat' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve", [
                'nomor_surat' => 'BEA-PRODI-001',
            ])
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('BEA-PRODI-001', $fresh->nomor_surat);
        $this->assertSame('2026-05-21 11:20:30', $fresh->tendik_approved_at?->toDateTimeString());
        $this->assertSame($tendik->id, $fresh->tendik_approved_by);
        $this->assertSame($tendik->id, $fresh->assigned_to);

        $artifact = $this->readyProdiArtifact($application);
        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->status);
        $this->assertSame($tendik->id, $artifact->generated_by);
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/prodi_review/',
            $artifact->docx_path,
        );
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/prodi_review/',
            $artifact->pdf_path,
        );
        $this->assertTrue(Storage::disk('local')->exists($artifact->docx_path));
        $this->assertTrue(Storage::disk('local')->exists($artifact->pdf_path));
        $this->assertSame('%PDF', substr(Storage::disk('local')->get($artifact->pdf_path), 0, 4));
        $this->assertSame(1, $automation->generatePhaseCalls);
        $this->assertSame(1, $converter->calls);
        $this->assertSame(LetterDocumentArtifact::PHASE_PRODI_REVIEW, $automation->lastPhase);
        $this->assertSame('BEA-PRODI-001', $automation->lastOverrides['nomor_surat']);
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_TENDIK, $automation->lastOverrides['status']);
        $this->assertSame('2026-05-21 11:20:30', $automation->lastOverrides['tanggal_surat']->toDateTimeString());
        $this->assertSame('2026-05-21 11:20:30', $automation->lastOverrides['tendik_approved_at']->toDateTimeString());
        $this->assertSame($tendik->id, $automation->lastOverrides['tendik_approved_by']);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_preview_generation_failure_blocks_tendik_approve_mutations(): void
    {
        [$automation, $converter] = $this->bindPreviewStack(converterFails: true);
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve", [
                'nomor_surat' => 'BEA-PRODI-FAIL',
            ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Dokumen pratinjau verifikasi belum dapat dibuat. Silakan coba lagi.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->nomor_surat);
        $this->assertNull($fresh->tendik_approved_at);
        $this->assertNull($fresh->tendik_approved_by);
        $this->assertSame($tendik->id, $fresh->assigned_to);
        $this->assertSame(1, $automation->generatePhaseCalls);
        $this->assertSame(1, $converter->calls);
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, LetterDocumentArtifact::query()->firstOrFail()->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_tendik_without_beasiswa_assignment_gets_forbidden_and_no_artifact_generation(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
        ]);
        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')->never();
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve", [
                'nomor_surat' => 'BEA-FORBIDDEN',
            ])
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->nomor_surat);
        $this->assertNull($fresh->tendik_approved_at);
        $this->assertNull($fresh->tendik_approved_by);
        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_non_submitted_status_returns_422_without_artifact_generation(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'ORIGINAL-BEA',
            'tendik_approved_at' => Carbon::parse('2026-05-20 09:00:00'),
            'tendik_approved_by' => $tendik->id,
        ]);
        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')->never();
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve", [
                'nomor_surat' => 'SHOULD-NOT-APPLY',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pengajuan tidak berada pada tahap verifikasi Tendik.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('ORIGINAL-BEA', $fresh->nomor_surat);
        $this->assertSame('2026-05-20 09:00:00', $fresh->tendik_approved_at?->toDateTimeString());
        $this->assertSame($tendik->id, $fresh->tendik_approved_by);
        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_tendik_approve_reuses_existing_prodi_review_ready_artifact_cache(): void
    {
        [$preAutomation, $preConverter] = $this->bindPreviewStack();
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'nomor_surat' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $approvedAt = Carbon::now();
        $ready = app(BeasiswaPreviewGenerationService::class)->generateForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            [
                'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
                'nomor_surat' => 'BEA-CACHE-001',
                'tendik_approved_at' => $approvedAt,
                'tendik_approved_by' => $tendik->id,
                'tanggal_surat' => $approvedAt,
            ],
            $tendik->id,
        );
        $this->assertSame(1, $preAutomation->generatePhaseCalls);
        $this->assertSame(1, $preConverter->calls);

        [$approveAutomation, $approveConverter] = $this->bindPreviewStack();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve", [
                'nomor_surat' => 'BEA-CACHE-001',
            ])
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('BEA-CACHE-001', $fresh->nomor_surat);
        $this->assertSame(0, $approveAutomation->generatePhaseCalls);
        $this->assertSame(0, $approveConverter->calls);
        $this->assertSame(1, LetterDocumentArtifact::query()
            ->where('source_hash', $ready->source_hash)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->count());
    }

    public function test_approve_recheck_does_not_overwrite_status_changed_after_artifact_generation(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function () use ($application): LetterDocumentArtifact {
                $application->update(['status' => ScholarshipApplication::STATUS_REJECTED]);

                return LetterDocumentArtifact::make([
                    'phase' => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve", [
                'nomor_surat' => 'BEA-RACE-001',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pengajuan sudah berubah dan tidak dapat diverifikasi ulang.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_REJECTED, $fresh->status);
        $this->assertNull($fresh->nomor_surat);
        $this->assertNull($fresh->tendik_approved_at);
        $this->assertNull($fresh->tendik_approved_by);
        $this->assertSame($tendik->id, $fresh->assigned_to);
    }

    /**
     * @return array{0: Phase2C2ApproveFakeScholarshipAutomationService, 1: Phase2C2ApproveFakeDocumentConverter}
     */
    private function bindPreviewStack(bool $converterFails = false): array
    {
        $automation = new Phase2C2ApproveFakeScholarshipAutomationService();
        $converter = new Phase2C2ApproveFakeDocumentConverter();
        $converter->fail = $converterFails;

        $this->app->instance(ScholarshipAutomationService::class, $automation);
        $this->app->instance(DocumentConverter::class, $converter);
        $this->app->forgetInstance(BeasiswaPreviewGenerationService::class);

        return [$automation, $converter];
    }

    private function readyProdiArtifact(ScholarshipApplication $application): LetterDocumentArtifact
    {
        return LetterDocumentArtifact::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->where('phase', LetterDocumentArtifact::PHASE_PRODI_REVIEW)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->firstOrFail();
    }
}

class Phase2C2ApproveFakeScholarshipAutomationService extends ScholarshipAutomationService
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
            . '/source_approve_fake_'
            . $this->generatePhaseCalls
            . '.docx';
        Storage::disk('local')->put($path, 'fake docx');

        return $path;
    }
}

class Phase2C2ApproveFakeDocumentConverter implements DocumentConverter
{
    public int $calls = 0;
    public bool $fail = false;

    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void
    {
        $this->calls++;

        if ($this->fail) {
            throw new DocumentConverterException('fake approve conversion failure');
        }

        file_put_contents($destPdfAbsolutePath, "%PDF-1.4\nfake");
    }
}
