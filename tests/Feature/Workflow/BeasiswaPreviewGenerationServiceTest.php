<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Services\AcademicSignatoryService;
use App\Services\BeasiswaPhaseResolver;
use App\Services\BeasiswaPreviewGenerationException;
use App\Services\BeasiswaPreviewGenerationService;
use App\Services\DocumentConverter;
use App\Services\DocumentConverterException;
use App\Services\LetterAssignmentService;
use App\Services\LetterDocumentArtifactService;
use App\Services\LetterDocumentSourceHashService;
use App\Services\MahasiswaProfileDataService;
use App\Services\ScholarshipAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BeasiswaPreviewGenerationServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00'));
        Storage::fake('local');
        Storage::fake('public');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_existing_ready_artifact_with_same_source_hash_is_returned_without_regeneration(): void
    {
        $application = $this->previewApplication(ScholarshipApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $ready = $this->service()->generateForCurrentPhase($application->fresh());

        $automation = new Phase2BFakeScholarshipAutomationService();
        $converter = new Phase2BFakeDocumentConverter();
        $artifact = $this->service($automation, $converter)->generateForCurrentPhase($application->fresh());

        $this->assertTrue($ready->is($artifact));
        $this->assertSame(0, $automation->calls);
        $this->assertSame(0, $converter->calls);
    }

    public function test_cache_miss_creates_converts_and_marks_artifact_ready(): void
    {
        $application = $this->previewApplication(ScholarshipApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $automation = new Phase2BFakeScholarshipAutomationService();
        $converter = new Phase2BFakeDocumentConverter();
        $actor = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $artifact = $this->service($automation, $converter)->generateForCurrentPhase($application->fresh(), [], $actor->id);

        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->status);
        $this->assertSame(1, $artifact->version);
        $this->assertSame(ScholarshipApplication::LETTER_TYPE, $artifact->letter_type);
        $this->assertSame($application->id, $artifact->application_id);
        $this->assertSame(LetterDocumentArtifact::PHASE_TENDIK_REVIEW, $artifact->phase);
        $this->assertSame($actor->id, $artifact->generated_by);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact->source_hash);
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/tendik_review/source_',
            $artifact->docx_path,
        );
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/tendik_review/preview_',
            $artifact->pdf_path,
        );
        $this->assertTrue(Storage::disk('local')->exists($artifact->docx_path));
        $this->assertTrue(Storage::disk('local')->exists($artifact->pdf_path));
        $this->assertSame(1, $automation->calls);
        $this->assertSame(1, $converter->calls);

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_docx_generation_failure_marks_artifact_failed_without_workflow_mutation(): void
    {
        $application = $this->previewApplication(ScholarshipApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $automation = new Phase2BFakeScholarshipAutomationService();
        $automation->fail = true;
        $converter = new Phase2BFakeDocumentConverter();

        try {
            $this->service($automation, $converter)->generateForCurrentPhase($application->fresh());
            $this->fail('Expected preview generation exception.');
        } catch (BeasiswaPreviewGenerationException $exception) {
            $this->assertStringContainsString('DOCX generation failed', $exception->getMessage());
        }

        $artifact = LetterDocumentArtifact::query()->firstOrFail();
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, $artifact->status);
        $this->assertNull($artifact->docx_path);
        $this->assertNull($artifact->pdf_path);
        $this->assertSame(0, $converter->calls);

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_converter_failure_marks_failed_and_cleans_partial_pdf(): void
    {
        $application = $this->previewApplication(ScholarshipApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat' => '001/SPB/2026',
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $automation = new Phase2BFakeScholarshipAutomationService();
        $converter = new Phase2BFakeDocumentConverter();
        $converter->fail = true;
        $converter->writePartialBeforeFail = true;

        try {
            $this->service($automation, $converter)->generateForCurrentPhase($application->fresh());
            $this->fail('Expected preview generation exception.');
        } catch (BeasiswaPreviewGenerationException $exception) {
            $this->assertStringContainsString('artifact generation failed', $exception->getMessage());
        }

        $artifact = LetterDocumentArtifact::query()->firstOrFail();
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, $artifact->status);
        $this->assertNotNull($artifact->docx_path);
        $this->assertNull($artifact->pdf_path);
        $this->assertSame(1, $converter->calls);
        $this->assertNotNull($converter->lastDestination);
        $this->assertFileDoesNotExist($converter->lastDestination);

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('001/SPB/2026', $fresh->nomor_surat);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_attempt_does_not_satisfy_ready_lookup_and_retry_uses_next_version(): void
    {
        $application = $this->previewApplication(ScholarshipApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $firstAutomation = new Phase2BFakeScholarshipAutomationService();
        $firstAutomation->fail = true;

        try {
            $this->service($firstAutomation, new Phase2BFakeDocumentConverter())->generateForCurrentPhase($application->fresh());
            $this->fail('Expected preview generation exception.');
        } catch (BeasiswaPreviewGenerationException) {
            // Expected failed version 1.
        }

        $secondAutomation = new Phase2BFakeScholarshipAutomationService();
        $artifact = $this->service($secondAutomation, new Phase2BFakeDocumentConverter())->generateForCurrentPhase($application->fresh());

        $this->assertSame(2, $artifact->version);
        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->status);
        $this->assertSame(2, LetterDocumentArtifact::query()->count());
        $this->assertSame(2, app(LetterDocumentArtifactService::class)
            ->latestReadyArtifact(ScholarshipApplication::LETTER_TYPE, $application->id, LetterDocumentArtifact::PHASE_TENDIK_REVIEW)
            ?->version);
    }

    public function test_ready_cache_is_rechecked_after_lock_before_generating(): void
    {
        $application = $this->previewApplication(ScholarshipApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $ready = LetterDocumentArtifact::create([
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'version' => 1,
            'docx_path' => 'letter-document-artifacts/recheck/source.docx',
            'pdf_path' => 'letter-document-artifacts/recheck/preview.pdf',
            'source_hash' => 'ready-after-lock',
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => Carbon::now(),
        ]);
        $artifactService = new Phase2BReadyOnSecondLookupArtifactService($ready);
        $automation = new Phase2BFakeScholarshipAutomationService();
        $converter = new Phase2BFakeDocumentConverter();

        $artifact = $this->service($automation, $converter, $artifactService)->generateForCurrentPhase($application->fresh());

        $this->assertTrue($ready->is($artifact));
        $this->assertSame(2, $artifactService->findCalls);
        $this->assertFalse($artifactService->createGeneratingCalled);
        $this->assertSame(0, $automation->calls);
        $this->assertSame(0, $converter->calls);
    }

    public function test_phase_lock_is_application_and_phase_scoped_and_reports_timeout(): void
    {
        $application = $this->previewApplication(ScholarshipApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $service = $this->service(lockWaitSeconds: 0);
        $key = $service->lockKeyFor($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->assertStringContainsString(ScholarshipApplication::LETTER_TYPE, $key);
        $this->assertStringContainsString((string) $application->id, $key);
        $this->assertStringContainsString(LetterDocumentArtifact::PHASE_TENDIK_REVIEW, $key);

        $lock = Cache::lock($key, 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(BeasiswaPreviewGenerationException::class);
            $this->expectExceptionMessage('already in progress');
            $service->generateForCurrentPhase($application->fresh());
        } finally {
            $lock->release();
        }
    }

    public function test_status_mapping_uses_current_phase_resolver(): void
    {
        $cases = [
            ScholarshipApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            ScholarshipApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ScholarshipApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        ];

        foreach ($cases as $status => $expectedPhase) {
            $application = $this->previewApplication($status, $this->phaseAttributes($status));
            $automation = new Phase2BFakeScholarshipAutomationService();

            $artifact = $this->service($automation)->generateForCurrentPhase($application->fresh());

            $this->assertSame($expectedPhase, $artifact->phase);
            $this->assertSame($expectedPhase, $automation->lastPhase);
        }
    }

    public function test_unavailable_statuses_throw_controlled_exception_without_artifact(): void
    {
        foreach ([
            ScholarshipApplication::STATUS_DRAFT,
            ScholarshipApplication::STATUS_REVISION,
            ScholarshipApplication::STATUS_REJECTED,
        ] as $status) {
            $application = $this->previewApplication($status);

            try {
                $this->service()->generateForCurrentPhase($application->fresh());
                $this->fail("Expected unavailable phase for {$status}.");
            } catch (BeasiswaPreviewGenerationException $exception) {
                $this->assertStringContainsString('phase is unavailable', $exception->getMessage());
            }
        }

        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_tanggal_surat_policy_is_stable_and_uses_nomor_assignment_date_after_tendik(): void
    {
        $submitted = $this->previewApplication(ScholarshipApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $submittedAutomation = new Phase2BFakeScholarshipAutomationService();
        $service = $this->service($submittedAutomation);

        $first = $service->generateForCurrentPhase($submitted->fresh());
        Carbon::setTestNow(Carbon::parse('2026-05-22 10:00:00'));
        $second = $service->generateForCurrentPhase($submitted->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $submittedAutomation->calls);
        $this->assertSame('2026-05-20', $submittedAutomation->lastOverrides['tanggal_surat']->toDateString());

        $approvedTendik = $this->previewApplication(ScholarshipApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat' => '001/SPB/2026',
            'submitted_at' => Carbon::parse('2026-05-19 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $approvedAutomation = new Phase2BFakeScholarshipAutomationService();

        $this->service($approvedAutomation)->generateForCurrentPhase($approvedTendik->fresh());

        $this->assertSame('2026-05-21', $approvedAutomation->lastOverrides['tanggal_surat']->toDateString());
    }

    private function service(
        ?Phase2BFakeScholarshipAutomationService $automation = null,
        ?Phase2BFakeDocumentConverter $converter = null,
        ?LetterDocumentArtifactService $artifactService = null,
        int $lockWaitSeconds = 10,
    ): BeasiswaPreviewGenerationService {
        return new BeasiswaPreviewGenerationService(
            app(BeasiswaPhaseResolver::class),
            app(LetterDocumentSourceHashService::class),
            $artifactService ?? app(LetterDocumentArtifactService::class),
            $automation ?? new Phase2BFakeScholarshipAutomationService(),
            $converter ?? new Phase2BFakeDocumentConverter(),
            120,
            $lockWaitSeconds,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function previewApplication(string $status, array $attributes = []): ScholarshipApplication
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $this->akademik('kadep', ['department_id' => $department->id]);

        return $this->scholarshipApplication($student, array_merge([
            'status' => $status,
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseAttributes(string $status): array
    {
        if (in_array($status, [
            ScholarshipApplication::STATUS_APPROVED_TENDIK,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            ScholarshipApplication::STATUS_COMPLETED,
        ], true)) {
            return [
                'nomor_surat' => '001/SPB/2026',
                'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
            ];
        }

        return [];
    }
}

class Phase2BFakeScholarshipAutomationService extends ScholarshipAutomationService
{
    public int $calls = 0;
    public bool $fail = false;
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
        $this->calls++;
        $this->lastPhase = $phase;
        $this->lastOverrides = $pendingOverrides;

        if ($this->fail) {
            return false;
        }

        $path = 'letter-document-artifacts/'
            . ScholarshipApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/source_fake_'
            . $this->calls
            . '.docx';
        Storage::disk('local')->put($path, 'fake docx');

        return $path;
    }
}

class Phase2BFakeDocumentConverter implements DocumentConverter
{
    public int $calls = 0;
    public bool $fail = false;
    public bool $writePartialBeforeFail = false;
    public ?string $lastSource = null;
    public ?string $lastDestination = null;

    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void
    {
        $this->calls++;
        $this->lastSource = $sourceDocxAbsolutePath;
        $this->lastDestination = $destPdfAbsolutePath;

        if ($this->fail) {
            if ($this->writePartialBeforeFail) {
                file_put_contents($destPdfAbsolutePath, 'partial');
            }

            throw new DocumentConverterException('fake conversion failed');
        }

        file_put_contents($destPdfAbsolutePath, "%PDF-1.4\nfake");
    }
}

class Phase2BReadyOnSecondLookupArtifactService extends LetterDocumentArtifactService
{
    public int $findCalls = 0;
    public bool $createGeneratingCalled = false;

    public function __construct(private LetterDocumentArtifact $readyArtifact)
    {
    }

    public function findReadyBySourceHash(
        string $letterType,
        int $applicationId,
        string $phase,
        string $sourceHash,
    ): ?LetterDocumentArtifact {
        $this->findCalls++;

        return $this->findCalls >= 2 ? $this->readyArtifact : null;
    }

    public function createGenerating(
        string $letterType,
        int $applicationId,
        string $phase,
        string $sourceHash,
        ?int $generatedBy = null,
        ?string $docxPath = null,
    ): LetterDocumentArtifact {
        $this->createGeneratingCalled = true;
        throw new \RuntimeException('createGenerating should not be called when ready artifact appears after lock.');
    }
}
