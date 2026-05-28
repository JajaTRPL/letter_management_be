<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Models\User;
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

class BeasiswaDepartmentApprovePreviewArtifactTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-21 13:10:20'));
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

    public function test_same_department_akademik_approve_creates_ready_mahasiswa_review_artifact_without_public_docx_pointer(): void
    {
        [$automation, $converter] = $this->bindPreviewStack();
        [$application, $sekdep, $officialKadep] = $this->departmentStageApplication();

        $this->actingAs($sekdep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW, $fresh->status);
        $this->assertSame('2026-05-21 13:10:20', $fresh->kadep_approved_at?->toDateTimeString());
        $this->assertSame($sekdep->id, $fresh->kadep_approved_by);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $artifact = $this->readyMahasiswaArtifact($application);
        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->status);
        $this->assertSame($sekdep->id, $artifact->generated_by);
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/mahasiswa_review/',
            $artifact->docx_path,
        );
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/mahasiswa_review/',
            $artifact->pdf_path,
        );
        $this->assertTrue(Storage::disk('local')->exists($artifact->docx_path));
        $this->assertTrue(Storage::disk('local')->exists($artifact->pdf_path));
        $this->assertSame('%PDF', substr(Storage::disk('local')->get($artifact->pdf_path), 0, 4));
        $this->assertSame(1, $automation->generatePhaseCalls);
        $this->assertSame(0, $automation->generateDocumentCalls);
        $this->assertSame(1, $converter->calls);
        $this->assertSame(LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, $automation->lastPhase);
        $this->assertSame(ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW, $automation->lastOverrides['status']);
        $this->assertSame('2026-05-21 13:10:20', $automation->lastOverrides['kadep_approved_at']->toDateTimeString());
        $this->assertSame($sekdep->id, $automation->lastOverrides['kadep_approved_by']);
        $this->assertTrue($officialKadep->is($automation->lastOverrides['official_kadep']));
        $this->assertSame('2026-05-20 09:00:00', $automation->lastOverrides['tanggal_surat']->toDateTimeString());
    }

    public function test_existing_mahasiswa_review_artifact_supports_completion_with_null_compatibility_response(): void
    {
        $this->bindPreviewStack();
        [$application, $sekdep, , $student] = $this->departmentStageApplication();

        $this->actingAs($sekdep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.generated_docx_path', null);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/generated-preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/complete")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.generated_docx_path', null);

    }

    public function test_preview_conversion_failure_blocks_department_approve_mutations_and_legacy_doc_generation(): void
    {
        [$automation, $converter] = $this->bindPreviewStack(converterFails: true);
        [$application, $sekdep] = $this->departmentStageApplication();

        $this->actingAs($sekdep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Dokumen pratinjau review mahasiswa belum dapat dibuat. Silakan coba lagi.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertNull($fresh->kadep_approved_at);
        $this->assertNull($fresh->kadep_approved_by);
        $this->assertSame(1, $automation->generatePhaseCalls);
        $this->assertSame(0, $automation->generateDocumentCalls);
        $this->assertSame(1, $converter->calls);
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, LetterDocumentArtifact::query()->firstOrFail()->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_department_approve_never_invokes_legacy_public_docx_generation_after_artifact_success(): void
    {
        [$automation, $converter] = $this->bindPreviewStack(legacyDocFails: true);
        [$application, $sekdep] = $this->departmentStageApplication();

        $this->actingAs($sekdep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW, $fresh->status);
        $this->assertSame('2026-05-21 13:10:20', $fresh->kadep_approved_at?->toDateTimeString());
        $this->assertSame($sekdep->id, $fresh->kadep_approved_by);
        $this->assertSame(1, $automation->generatePhaseCalls);
        $this->assertSame(0, $automation->generateDocumentCalls);
        $this->assertSame(1, $converter->calls);
        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $this->readyMahasiswaArtifact($application)->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_different_department_akademik_gets_forbidden_and_no_artifact_generation(): void
    {
        [$application] = $this->departmentStageApplication();
        $wrongKadep = $this->akademik('kadep', ['department_id' => $this->department()->id]);
        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')->never();
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($wrongKadep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertNull($fresh->kadep_approved_at);
        $this->assertNull($fresh->kadep_approved_by);
        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_non_department_actionable_status_returns_422_without_artifact_generation(): void
    {
        [$application, $sekdep] = $this->departmentStageApplication([
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            'kaprodi_approved_at' => null,
            'kaprodi_approved_by' => null,
        ]);
        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')->never();
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($sekdep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pengajuan tidak berada pada tahap persetujuan Kadep/Sekdep.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertNull($fresh->kadep_approved_at);
        $this->assertNull($fresh->kadep_approved_by);
        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_missing_official_kadep_guard_runs_before_artifact_generation(): void
    {
        [$application, $sekdep] = $this->departmentStageApplication(createOfficialKadep: false);
        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')->never();
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($sekdep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'missing_official_kadep');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertNull($fresh->kadep_approved_at);
        $this->assertNull($fresh->kadep_approved_by);
        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_department_approve_reuses_existing_mahasiswa_review_ready_artifact_without_legacy_docx_generation(): void
    {
        [$preAutomation, $preConverter] = $this->bindPreviewStack();
        [$application, $sekdep, $officialKadep] = $this->departmentStageApplication();

        $ready = app(BeasiswaPreviewGenerationService::class)->generateForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            [
                'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                'kadep_approved_at' => Carbon::now(),
                'kadep_approved_by' => $sekdep->id,
                'official_kadep' => $officialKadep,
                'tanggal_surat' => Carbon::parse('2026-05-20 09:00:00'),
            ],
            $sekdep->id,
        );
        $this->assertSame(1, $preAutomation->generatePhaseCalls);
        $this->assertSame(1, $preConverter->calls);

        [$approveAutomation, $approveConverter] = $this->bindPreviewStack();

        $this->actingAs($sekdep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW, $fresh->status);
        $this->assertSame(0, $approveAutomation->generatePhaseCalls);
        $this->assertSame(0, $approveConverter->calls);
        $this->assertSame(0, $approveAutomation->generateDocumentCalls);
        $this->assertSame(1, LetterDocumentArtifact::query()
            ->where('source_hash', $ready->source_hash)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_department_approve_recheck_does_not_overwrite_status_changed_after_artifact_generation(): void
    {
        [$application, $sekdep] = $this->departmentStageApplication();
        $automation = new Phase2C4DepartmentApproveFakeScholarshipAutomationService();
        $this->app->instance(ScholarshipAutomationService::class, $automation);
        $previewService = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $previewService->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function () use ($application): LetterDocumentArtifact {
                $application->update(['status' => ScholarshipApplication::STATUS_REJECTED]);

                return LetterDocumentArtifact::make([
                    'phase' => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(BeasiswaPreviewGenerationService::class, $previewService);

        $this->actingAs($sekdep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pengajuan sudah berubah dan tidak dapat disetujui ulang oleh Departemen.');

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_REJECTED, $fresh->status);
        $this->assertNull($fresh->kadep_approved_at);
        $this->assertNull($fresh->kadep_approved_by);
        $this->assertSame(0, $automation->generateDocumentCalls);
        $this->assertSame([], $automation->deletedDocuments);
        $this->assertFalse(Storage::disk('public')->exists('scholarships/final_department_fake_1.docx'));
    }

    /**
     * @param array<string, mixed> $applicationAttributes
     * @return array{0: ScholarshipApplication, 1: User, 2: ?User, 3: User}
     */
    private function departmentStageApplication(array $applicationAttributes = [], bool $createOfficialKadep = true): array
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $sekdep = $this->akademik('sekdep', ['department_id' => $department->id]);
        $officialKadep = $createOfficialKadep
            ? $this->akademik('kadep', [
                'department_id' => $department->id,
                'name' => 'Official Kadep',
                'nip' => '197512122005011002',
                'signature_path' => '/storage/signatures/kadep.png',
            ])
            : null;

        $application = $this->scholarshipApplication($student, array_merge([
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'BEA-DEPT-STAGE-001',
            'submitted_at' => Carbon::parse('2026-05-19 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-20 09:00:00'),
            'tendik_approved_by' => $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE])->id,
            'kaprodi_approved_at' => Carbon::parse('2026-05-20 10:00:00'),
            'kaprodi_approved_by' => $this->akademik('sekprodi', ['study_program_id' => $program->id])->id,
            'kadep_approved_at' => null,
            'kadep_approved_by' => null,
        ], $applicationAttributes));

        return [$application, $sekdep, $officialKadep, $student];
    }

    /**
     * @return array{0: Phase2C4DepartmentApproveFakeScholarshipAutomationService, 1: Phase2C4DepartmentApproveFakeDocumentConverter}
     */
    private function bindPreviewStack(bool $converterFails = false, bool $legacyDocFails = false): array
    {
        $automation = new Phase2C4DepartmentApproveFakeScholarshipAutomationService();
        $automation->legacyDocFails = $legacyDocFails;
        $converter = new Phase2C4DepartmentApproveFakeDocumentConverter();
        $converter->fail = $converterFails;

        $this->app->instance(ScholarshipAutomationService::class, $automation);
        $this->app->instance(DocumentConverter::class, $converter);
        $this->app->forgetInstance(BeasiswaPreviewGenerationService::class);

        return [$automation, $converter];
    }

    private function readyMahasiswaArtifact(ScholarshipApplication $application): LetterDocumentArtifact
    {
        return LetterDocumentArtifact::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->where('phase', LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->firstOrFail();
    }
}

class Phase2C4DepartmentApproveFakeScholarshipAutomationService extends ScholarshipAutomationService
{
    public int $generatePhaseCalls = 0;
    public int $generateDocumentCalls = 0;
    public ?string $lastPhase = null;
    public bool $legacyDocFails = false;

    /** @var array<string, mixed> */
    public array $lastOverrides = [];

    /** @var list<string> */
    public array $deletedDocuments = [];

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
            . '/source_department_fake_'
            . $this->generatePhaseCalls
            . '.docx';
        Storage::disk('local')->put($path, 'fake docx');

        return $path;
    }

    public function generateDocument(ScholarshipApplication $application, ?User $finalApprover = null): string|false
    {
        $this->generateDocumentCalls++;

        if ($this->legacyDocFails) {
            return false;
        }

        $path = 'scholarships/final_department_fake_' . $this->generateDocumentCalls . '.docx';
        Storage::disk('public')->put($path, 'docx test');

        return $path;
    }

    public function deleteGeneratedDocument(?string $path): void
    {
        if (!$path) {
            return;
        }

        $publicPath = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($publicPath, 'storage/')) {
            $publicPath = substr($publicPath, strlen('storage/'));
        }

        $this->deletedDocuments[] = $publicPath;
        if (Storage::disk('public')->exists($publicPath)) {
            Storage::disk('public')->delete($publicPath);
        }
    }
}

class Phase2C4DepartmentApproveFakeDocumentConverter implements DocumentConverter
{
    public int $calls = 0;
    public bool $fail = false;

    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void
    {
        $this->calls++;

        if ($this->fail) {
            throw new DocumentConverterException('fake department approve conversion failure');
        }

        file_put_contents($destPdfAbsolutePath, "%PDF-1.4\nfake");
    }
}
