<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Services\DocumentConverter;
use App\Services\DocumentConverterException;
use App\Services\LetterDocumentArtifactService;
use App\Services\LetterDocumentSourceHashService;
use App\Services\ProsesLuarNegeriDocumentGenerationService;
use App\Services\ProsesLuarNegeriPhaseResolver;
use App\Services\ProsesLuarNegeriPreviewGenerationException;
use App\Services\ProsesLuarNegeriPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\TemplatePlaceholderAssertions;
use Tests\TestCase;

class ProsesLuarNegeriPreviewGenerationServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private ?string $tempParafPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-22 10:00:00'));
        Storage::fake('local');
        Storage::fake('public');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        if ($this->tempParafPath && is_file($this->tempParafPath)) {
            @unlink($this->tempParafPath);
        }

        parent::tearDown();
    }

    public function test_generates_ready_artifact_for_current_phase_without_legacy_side_effects(): void
    {
        $application = $this->previewApplication(ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat' => 'PLN/001/2026',
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $generator = new PhasePln3FakeDocumentGenerationService();
        $converter = new PhasePln3FakeDocumentConverter();
        $actor = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);

        $artifact = $this->service($generator, $converter)->generateForCurrentPhase($application->fresh(), [], $actor->id);

        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->status);
        $this->assertSame(1, $artifact->version);
        $this->assertSame(ProsesLuarNegeriApplication::LETTER_TYPE, $artifact->letter_type);
        $this->assertSame($application->id, $artifact->application_id);
        $this->assertSame(LetterDocumentArtifact::PHASE_PRODI_REVIEW, $artifact->phase);
        $this->assertSame($actor->id, $artifact->generated_by);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact->source_hash);
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ProsesLuarNegeriApplication::LETTER_TYPE . '/' . $application->id . '/prodi_review/source_',
            $artifact->docx_path,
        );
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . ProsesLuarNegeriApplication::LETTER_TYPE . '/' . $application->id . '/prodi_review/preview_',
            $artifact->pdf_path,
        );
        $this->assertTrue(Storage::disk('local')->exists($artifact->docx_path));
        $this->assertTrue(Storage::disk('local')->exists($artifact->pdf_path));
        $this->assertSame(1, $generator->calls);
        $this->assertSame(1, $converter->calls);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('PLN/001/2026', $fresh->nomor_surat);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_existing_ready_artifact_with_same_source_hash_is_returned_without_regeneration(): void
    {
        $application = $this->previewApplication(ProsesLuarNegeriApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $ready = $this->service()->generateForCurrentPhase($application->fresh());

        $generator = new PhasePln3FakeDocumentGenerationService();
        $converter = new PhasePln3FakeDocumentConverter();
        $artifact = $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());

        $this->assertTrue($ready->is($artifact));
        $this->assertSame(0, $generator->calls);
        $this->assertSame(0, $converter->calls);
        $this->assertSame(1, LetterDocumentArtifact::query()->count());
    }

    public function test_cache_miss_when_source_changes_creates_new_hash_and_artifact(): void
    {
        $application = $this->previewApplication(ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat' => 'PLN/001/2026',
            'keperluan' => 'Keperluan awal',
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $service = $this->service();

        $first = $service->generateForCurrentPhase($application->fresh());
        $application->update(['keperluan' => 'Keperluan berubah']);
        $second = $service->generateForCurrentPhase($application->fresh());

        $this->assertFalse($first->is($second));
        $this->assertNotSame($first->source_hash, $second->source_hash);
        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame(2, LetterDocumentArtifact::query()->count());
    }

    public function test_ready_cache_is_rechecked_after_lock_before_generating(): void
    {
        $application = $this->previewApplication(ProsesLuarNegeriApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $ready = LetterDocumentArtifact::create([
            'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'version' => 1,
            'docx_path' => 'letter-document-artifacts/recheck/source.docx',
            'pdf_path' => 'letter-document-artifacts/recheck/preview.pdf',
            'source_hash' => 'ready-after-lock',
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => Carbon::now(),
        ]);
        $artifactService = new PhasePln3ReadyOnSecondLookupArtifactService($ready);
        $generator = new PhasePln3FakeDocumentGenerationService();
        $converter = new PhasePln3FakeDocumentConverter();

        $artifact = $this->service($generator, $converter, $artifactService)->generateForCurrentPhase($application->fresh());

        $this->assertTrue($ready->is($artifact));
        $this->assertSame(2, $artifactService->findCalls);
        $this->assertFalse($artifactService->createGeneratingCalled);
        $this->assertSame(0, $generator->calls);
        $this->assertSame(0, $converter->calls);
    }

    public function test_unavailable_statuses_throw_controlled_exception_without_artifact(): void
    {
        foreach ([
            ProsesLuarNegeriApplication::STATUS_DRAFT,
            ProsesLuarNegeriApplication::STATUS_REVISION,
            ProsesLuarNegeriApplication::STATUS_REJECTED,
        ] as $status) {
            $application = $this->previewApplication($status);

            try {
                $this->service()->generateForCurrentPhase($application->fresh());
                $this->fail("Expected unavailable PLN phase for {$status}.");
            } catch (ProsesLuarNegeriPreviewGenerationException $exception) {
                $this->assertStringContainsString('phase is unavailable', $exception->getMessage());
            }
        }

        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_docx_generation_exception_marks_artifact_failed_without_workflow_mutation(): void
    {
        $application = $this->previewApplication(ProsesLuarNegeriApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $generator = new PhasePln3FakeDocumentGenerationService();
        $generator->fail = true;
        $converter = new PhasePln3FakeDocumentConverter();

        try {
            $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());
            $this->fail('Expected PLN preview generation exception.');
        } catch (ProsesLuarNegeriPreviewGenerationException $exception) {
            $this->assertStringContainsString('artifact generation failed', $exception->getMessage());
        }

        $artifact = LetterDocumentArtifact::query()->firstOrFail();
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, $artifact->status);
        $this->assertNull($artifact->docx_path);
        $this->assertNull($artifact->pdf_path);
        $this->assertSame(0, $converter->calls);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_SUBMITTED, $fresh->status);
    }

    public function test_converter_exception_marks_artifact_failed_and_cleans_partial_pdf(): void
    {
        $application = $this->previewApplication(ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat' => 'PLN/001/2026',
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $generator = new PhasePln3FakeDocumentGenerationService();
        $converter = new PhasePln3FakeDocumentConverter();
        $converter->fail = true;
        $converter->writePartialBeforeFail = true;

        try {
            $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());
            $this->fail('Expected PLN preview generation exception.');
        } catch (ProsesLuarNegeriPreviewGenerationException $exception) {
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
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('PLN/001/2026', $fresh->nomor_surat);
    }

    public function test_pending_number_affects_source_hash_and_rendered_docx_without_db_mutation(): void
    {
        $this->requirePlnTemplateCache();
        [$application, $kadep] = $this->realRenderApplication();
        $converter = new PhasePln3FakeDocumentConverter();
        $service = $this->service(app(ProsesLuarNegeriDocumentGenerationService::class), $converter);

        $first = $service->generateForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, [
            'nomor_surat' => 'PLN/PENDING/P3-001',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);

        $xml = implode("\n", TemplatePlaceholderAssertions::wordXmlEntries(Storage::disk('local')->path($first->docx_path)));
        $text = html_entity_decode((string) preg_replace('/<[^>]+>/', '', $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('PLN/PENDING/P3-001', $text);

        $second = $service->generateForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, [
            'nomor_surat' => 'PLN/PENDING/P3-002',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);

        $this->assertNotSame($first->source_hash, $second->source_hash);
        $this->assertNull($application->fresh()->nomor_surat);
    }

    public function test_status_mapping_uses_pln_phase_resolver(): void
    {
        $cases = [
            ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ProsesLuarNegeriApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        ];

        foreach ($cases as $status => $expectedPhase) {
            $application = $this->previewApplication($status, $this->phaseAttributes($status));
            $generator = new PhasePln3FakeDocumentGenerationService();

            $artifact = $this->service($generator)->generateForCurrentPhase($application->fresh());

            $this->assertSame($expectedPhase, $artifact->phase);
            $this->assertSame($expectedPhase, $generator->lastPhase);
        }
    }

    public function test_tanggal_surat_policy_is_stable_and_uses_tendik_approval_date_after_number_assignment(): void
    {
        $submitted = $this->previewApplication(ProsesLuarNegeriApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $submittedGenerator = new PhasePln3FakeDocumentGenerationService();
        $service = $this->service($submittedGenerator);

        $first = $service->generateForCurrentPhase($submitted->fresh());
        Carbon::setTestNow(Carbon::parse('2026-05-24 10:00:00'));
        $second = $service->generateForCurrentPhase($submitted->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $submittedGenerator->calls);
        $this->assertSame('2026-05-20', $submittedGenerator->lastOverrides['tanggal_surat']->toDateString());

        $approvedTendik = $this->previewApplication(ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat' => 'PLN/001/2026',
            'submitted_at' => Carbon::parse('2026-05-19 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $approvedGenerator = new PhasePln3FakeDocumentGenerationService();

        $this->service($approvedGenerator)->generateForCurrentPhase($approvedTendik->fresh());

        $this->assertSame('2026-05-21', $approvedGenerator->lastOverrides['tanggal_surat']->toDateString());
    }

    public function test_phase_lock_is_application_and_phase_scoped_and_reports_timeout(): void
    {
        $application = $this->previewApplication(ProsesLuarNegeriApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $service = $this->service(lockWaitSeconds: 0);
        $key = $service->lockKeyFor($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->assertStringContainsString(ProsesLuarNegeriApplication::LETTER_TYPE, $key);
        $this->assertStringContainsString((string) $application->id, $key);
        $this->assertStringContainsString(LetterDocumentArtifact::PHASE_TENDIK_REVIEW, $key);

        $lock = Cache::lock($key, 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(ProsesLuarNegeriPreviewGenerationException::class);
            $this->expectExceptionMessage('already in progress');
            $service->generateForCurrentPhase($application->fresh());
        } finally {
            $lock->release();
        }
    }

    private function service(
        ProsesLuarNegeriDocumentGenerationService|PhasePln3FakeDocumentGenerationService|null $generator = null,
        ?PhasePln3FakeDocumentConverter $converter = null,
        ?LetterDocumentArtifactService $artifactService = null,
        int $lockWaitSeconds = 10,
    ): ProsesLuarNegeriPreviewGenerationService {
        return new ProsesLuarNegeriPreviewGenerationService(
            app(ProsesLuarNegeriPhaseResolver::class),
            app(LetterDocumentSourceHashService::class),
            $artifactService ?? app(LetterDocumentArtifactService::class),
            $generator ?? new PhasePln3FakeDocumentGenerationService(),
            $converter ?? new PhasePln3FakeDocumentConverter(),
            120,
            $lockWaitSeconds,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function previewApplication(string $status, array $attributes = []): ProsesLuarNegeriApplication
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep PLN Preview',
            'nip' => '196501011990031001',
        ]);

        return $this->prosesLuarNegeriApplication($student, array_merge([
            'status' => $status,
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ], $attributes));
    }

    /**
     * @return array{0: ProsesLuarNegeriApplication, 1: \App\Models\User}
     */
    private function realRenderApplication(): array
    {
        Storage::disk('public')->put('profiles/signatures/pln-kadep.png', $this->pngBytes());

        $this->tempParafPath = tempnam(sys_get_temp_dir(), 'pln_preview_paraf_') . '.png';
        file_put_contents($this->tempParafPath, $this->pngBytes());
        config(['surat.global_paraf_path' => $this->tempParafPath]);

        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi UGM']);
        $program = $this->studyProgram($department, [
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
            'code' => 'TRPL',
        ]);
        [$student] = $this->completeMahasiswa([
            'name' => 'Mahasiswa PLN Preview',
        ], [
            'nim' => '22/493038/SV/20654',
            'nama_lengkap' => 'Mahasiswa PLN Preview',
        ], $program);

        $kadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep PLN Preview',
            'nip' => '196501011990031001',
            'signature_path' => 'profiles/signatures/pln-kadep.png',
        ]);

        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => null,
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'jenis_kelamin' => 'Laki-laki',
            'semester' => 4,
            'nomor_paspor' => 'AB1234567',
            'keperluan' => 'Keperluan preview PLN',
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);

        return [$application, $kadep];
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseAttributes(string $status): array
    {
        if (in_array($status, [
            ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            ProsesLuarNegeriApplication::STATUS_COMPLETED,
        ], true)) {
            return [
                'nomor_surat' => 'PLN/001/2026',
                'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
            ];
        }

        return [];
    }

    private function requirePlnTemplateCache(): string
    {
        $cachePath = config('surat.template_proses_luar_negeri_cache_path');
        if (!is_string($cachePath) || !is_file($cachePath)) {
            $this->markTestSkipped('PLN template cache is not present in this environment.');
        }

        $header = file_get_contents($cachePath, false, null, 0, 2);
        $this->assertSame('PK', $header, 'PLN template cache must be a DOCX ZIP archive.');

        return $cachePath;
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }
}

class PhasePln3FakeDocumentGenerationService extends ProsesLuarNegeriDocumentGenerationService
{
    public int $calls = 0;
    public bool $fail = false;
    public ?string $lastPhase = null;

    /** @var array<string, mixed> */
    public array $lastOverrides = [];

    public function __construct()
    {
    }

    public function generateDocumentForPhase(
        ProsesLuarNegeriApplication $application,
        string $phase,
        array $overrides = [],
    ): string {
        $this->calls++;
        $this->lastPhase = $phase;
        $this->lastOverrides = $overrides;

        if ($this->fail) {
            throw new RuntimeException('fake PLN DOCX generation failed');
        }

        $path = 'letter-document-artifacts/'
            . ProsesLuarNegeriApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/source_fake_'
            . $this->calls
            . '.docx';
        Storage::disk('local')->put($path, 'fake PLN docx');

        return $path;
    }
}

class PhasePln3FakeDocumentConverter implements DocumentConverter
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

            throw new DocumentConverterException('fake PLN conversion failed');
        }

        file_put_contents($destPdfAbsolutePath, "%PDF-1.4\nfake");
    }
}

class PhasePln3ReadyOnSecondLookupArtifactService extends LetterDocumentArtifactService
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
        throw new RuntimeException('createGenerating should not be called when ready artifact appears after lock.');
    }
}
