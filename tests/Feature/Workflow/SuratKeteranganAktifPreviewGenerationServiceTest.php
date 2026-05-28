<?php

namespace Tests\Feature\Workflow;

use App\Models\AcademicPeriod;
use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use App\Services\DocumentConverter;
use App\Services\DocumentConverterException;
use App\Services\LetterDocumentArtifactService;
use App\Services\LetterDocumentSourceHashService;
use App\Services\SuratKeteranganAktifDocumentGenerationService;
use App\Services\SuratKeteranganAktifPhaseResolver;
use App\Services\SuratKeteranganAktifPreviewGenerationException;
use App\Services\SuratKeteranganAktifPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\TemplatePlaceholderAssertions;
use Tests\TestCase;

class SuratKeteranganAktifPreviewGenerationServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private ?string $tempParafPath = null;

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

        if ($this->tempParafPath && is_file($this->tempParafPath)) {
            @unlink($this->tempParafPath);
        }

        parent::tearDown();
    }

    public function test_generates_ready_artifact_for_current_phase_without_legacy_side_effects(): void
    {
        $application = $this->previewApplication(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat' => 'AKT/001/2026',
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $generator = new PhaseS3FakeSkaDocumentGenerationService();
        $converter = new PhaseS3FakeDocumentConverter();
        $actor = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);

        $artifact = $this->service($generator, $converter)->generateForCurrentPhase($application->fresh(), [], $actor->id);

        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->status);
        $this->assertSame(1, $artifact->version);
        $this->assertSame(SuratKeteranganAktifApplication::LETTER_TYPE, $artifact->letter_type);
        $this->assertSame($application->id, $artifact->application_id);
        $this->assertSame(LetterDocumentArtifact::PHASE_PRODI_REVIEW, $artifact->phase);
        $this->assertSame($actor->id, $artifact->generated_by);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact->source_hash);
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . SuratKeteranganAktifApplication::LETTER_TYPE . '/' . $application->id . '/prodi_review/source_',
            $artifact->docx_path,
        );
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . SuratKeteranganAktifApplication::LETTER_TYPE . '/' . $application->id . '/prodi_review/preview_',
            $artifact->pdf_path,
        );
        $this->assertTrue(Storage::disk('local')->exists($artifact->docx_path));
        $this->assertTrue(Storage::disk('local')->exists($artifact->pdf_path));
        $this->assertSame(1, $generator->calls);
        $this->assertSame(1, $converter->calls);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('AKT/001/2026', $fresh->nomor_surat);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_existing_ready_artifact_with_same_source_hash_is_returned_without_regeneration(): void
    {
        $application = $this->previewApplication(SuratKeteranganAktifApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $ready = $this->service()->generateForCurrentPhase($application->fresh());

        $generator = new PhaseS3FakeSkaDocumentGenerationService();
        $converter = new PhaseS3FakeDocumentConverter();
        $artifact = $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());

        $this->assertTrue($ready->is($artifact));
        $this->assertSame(0, $generator->calls);
        $this->assertSame(0, $converter->calls);
        $this->assertSame(1, LetterDocumentArtifact::query()->count());
    }

    public function test_cache_miss_when_source_changes_creates_new_hash_and_artifact(): void
    {
        $application = $this->previewApplication(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat' => 'AKT/001/2026',
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
        $application = $this->previewApplication(SuratKeteranganAktifApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $ready = LetterDocumentArtifact::create([
            'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'version' => 1,
            'docx_path' => 'letter-document-artifacts/recheck/source.docx',
            'pdf_path' => 'letter-document-artifacts/recheck/preview.pdf',
            'source_hash' => 'ready-after-lock',
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => Carbon::now(),
        ]);
        $artifactService = new PhaseS3ReadyOnSecondLookupArtifactService($ready);
        $generator = new PhaseS3FakeSkaDocumentGenerationService();
        $converter = new PhaseS3FakeDocumentConverter();

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
            SuratKeteranganAktifApplication::STATUS_DRAFT,
            SuratKeteranganAktifApplication::STATUS_REVISION,
            SuratKeteranganAktifApplication::STATUS_REJECTED,
        ] as $status) {
            $application = $this->previewApplication($status);

            try {
                $this->service()->generateForCurrentPhase($application->fresh());
                $this->fail("Expected unavailable SKA phase for {$status}.");
            } catch (SuratKeteranganAktifPreviewGenerationException $exception) {
                $this->assertStringContainsString('phase is unavailable', $exception->getMessage());
            }
        }

        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_docx_generation_exception_marks_artifact_failed_without_workflow_mutation(): void
    {
        $application = $this->previewApplication(SuratKeteranganAktifApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $generator = new PhaseS3FakeSkaDocumentGenerationService();
        $generator->fail = true;
        $converter = new PhaseS3FakeDocumentConverter();

        try {
            $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());
            $this->fail('Expected SKA preview generation exception.');
        } catch (SuratKeteranganAktifPreviewGenerationException $exception) {
            $this->assertStringContainsString('artifact generation failed', $exception->getMessage());
        }

        $artifact = LetterDocumentArtifact::query()->firstOrFail();
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, $artifact->status);
        $this->assertNull($artifact->docx_path);
        $this->assertNull($artifact->pdf_path);
        $this->assertSame(0, $converter->calls);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_SUBMITTED, $fresh->status);
    }

    public function test_converter_exception_marks_artifact_failed_and_cleans_partial_pdf(): void
    {
        $application = $this->previewApplication(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat' => 'AKT/001/2026',
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $generator = new PhaseS3FakeSkaDocumentGenerationService();
        $converter = new PhaseS3FakeDocumentConverter();
        $converter->fail = true;
        $converter->writePartialBeforeFail = true;

        try {
            $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());
            $this->fail('Expected SKA preview generation exception.');
        } catch (SuratKeteranganAktifPreviewGenerationException $exception) {
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
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('AKT/001/2026', $fresh->nomor_surat);
    }

    public function test_pending_number_affects_source_hash_and_rendered_docx_without_db_mutation(): void
    {
        $this->requireSkaTemplateCache();
        [$application, $kadep] = $this->realRenderApplication();
        $converter = new PhaseS3FakeDocumentConverter();
        $service = $this->service(app(SuratKeteranganAktifDocumentGenerationService::class), $converter);

        $first = $service->generateForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, [
            'nomor_surat' => 'AKT/PENDING/S3-001',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);

        $xml = implode("\n", TemplatePlaceholderAssertions::wordXmlEntries(Storage::disk('local')->path($first->docx_path)));
        $text = html_entity_decode((string) preg_replace('/<[^>]+>/', '', $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('AKT/PENDING/S3-001', $text);

        $second = $service->generateForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, [
            'nomor_surat' => 'AKT/PENDING/S3-002',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);

        $this->assertNotSame($first->source_hash, $second->source_hash);
        $this->assertNull($application->fresh()->nomor_surat);
    }

    public function test_status_mapping_uses_ska_phase_resolver(): void
    {
        $cases = [
            SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            SuratKeteranganAktifApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        ];

        foreach ($cases as $status => $expectedPhase) {
            $application = $this->previewApplication($status, $this->phaseAttributes($status));
            $generator = new PhaseS3FakeSkaDocumentGenerationService();

            $artifact = $this->service($generator)->generateForCurrentPhase($application->fresh());

            $this->assertSame($expectedPhase, $artifact->phase);
            $this->assertSame($expectedPhase, $generator->lastPhase);
        }
    }

    public function test_tanggal_surat_policy_is_stable_and_uses_tendik_approval_date_after_number_assignment(): void
    {
        $submitted = $this->previewApplication(SuratKeteranganAktifApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $submittedGenerator = new PhaseS3FakeSkaDocumentGenerationService();
        $service = $this->service($submittedGenerator);

        $first = $service->generateForCurrentPhase($submitted->fresh());
        Carbon::setTestNow(Carbon::parse('2026-05-22 10:00:00'));
        $second = $service->generateForCurrentPhase($submitted->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $submittedGenerator->calls);
        $this->assertSame('2026-05-20', $submittedGenerator->lastOverrides['tanggal_surat']->toDateString());

        $approvedTendik = $this->previewApplication(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat' => 'AKT/001/2026',
            'submitted_at' => Carbon::parse('2026-05-19 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $approvedGenerator = new PhaseS3FakeSkaDocumentGenerationService();

        $this->service($approvedGenerator)->generateForCurrentPhase($approvedTendik->fresh());

        $this->assertSame('2026-05-21', $approvedGenerator->lastOverrides['tanggal_surat']->toDateString());
    }

    public function test_phase_lock_is_application_and_phase_scoped_and_reports_timeout(): void
    {
        $application = $this->previewApplication(SuratKeteranganAktifApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $service = $this->service(lockWaitSeconds: 0);
        $key = $service->lockKeyFor($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->assertStringContainsString(SuratKeteranganAktifApplication::LETTER_TYPE, $key);
        $this->assertStringContainsString((string) $application->id, $key);
        $this->assertStringContainsString(LetterDocumentArtifact::PHASE_TENDIK_REVIEW, $key);

        $lock = Cache::lock($key, 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(SuratKeteranganAktifPreviewGenerationException::class);
            $this->expectExceptionMessage('already in progress');
            $service->generateForCurrentPhase($application->fresh());
        } finally {
            $lock->release();
        }
    }

    private function service(
        SuratKeteranganAktifDocumentGenerationService|PhaseS3FakeSkaDocumentGenerationService|null $generator = null,
        ?PhaseS3FakeDocumentConverter $converter = null,
        ?LetterDocumentArtifactService $artifactService = null,
        int $lockWaitSeconds = 10,
    ): SuratKeteranganAktifPreviewGenerationService {
        return new SuratKeteranganAktifPreviewGenerationService(
            app(SuratKeteranganAktifPhaseResolver::class),
            app(LetterDocumentSourceHashService::class),
            $artifactService ?? app(LetterDocumentArtifactService::class),
            $generator ?? new PhaseS3FakeSkaDocumentGenerationService(),
            $converter ?? new PhaseS3FakeDocumentConverter(),
            120,
            $lockWaitSeconds,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function previewApplication(string $status, array $attributes = []): SuratKeteranganAktifApplication
    {
        $this->activeAcademicPeriod();
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep SKA Preview',
            'nip' => '196501011990031001',
        ]);

        return $this->aktifApplication($student, array_merge([
            'status' => $status,
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ], $attributes));
    }

    /**
     * @return array{0: SuratKeteranganAktifApplication, 1: \App\Models\User}
     */
    private function realRenderApplication(): array
    {
        Storage::disk('public')->put('profiles/signatures/ska-kadep.png', $this->pngBytes());

        $this->tempParafPath = tempnam(sys_get_temp_dir(), 'ska_preview_paraf_') . '.png';
        file_put_contents($this->tempParafPath, $this->pngBytes());
        config(['surat.global_paraf_path' => $this->tempParafPath]);

        $this->activeAcademicPeriod('2025/2026', AcademicPeriod::SEMESTER_TYPE_GENAP);
        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi UGM']);
        $program = $this->studyProgram($department, ['name' => 'Teknologi Rekayasa Perangkat Lunak']);
        [$student] = $this->completeMahasiswa([
            'name' => 'Mahasiswa SKA Preview',
        ], [
            'nim' => '22/493038/SV/20654',
            'nama_lengkap' => 'Mahasiswa SKA Preview',
        ], $program);

        $kadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep SKA Preview',
            'nip' => '196501011990031001',
            'signature_path' => 'profiles/signatures/ska-kadep.png',
        ]);

        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => null,
            'keperluan' => 'Keperluan preview SKA',
            'nama_orang_tua_wali' => 'Orang Tua SKA Preview',
            'pekerjaan_orang_tua_wali' => 'Pegawai Negeri',
            'nip_orang_tua_wali' => '197001012000011001',
            'pangkat_gol_orang_tua_wali' => 'IV/a',
            'instansi_orang_tua_wali' => 'Instansi SKA',
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
            SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            SuratKeteranganAktifApplication::STATUS_COMPLETED,
        ], true)) {
            return [
                'nomor_surat' => 'AKT/001/2026',
                'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
            ];
        }

        return [];
    }

    private function activeAcademicPeriod(
        string $academicYear = '2025/2026',
        string $semesterType = AcademicPeriod::SEMESTER_TYPE_GENAP,
        string $startDate = '2026-01-01',
    ): AcademicPeriod {
        [$yearStart] = explode('/', $academicYear);

        return AcademicPeriod::create([
            'academic_year' => $academicYear,
            'year_start' => (int) $yearStart,
            'semester_type' => $semesterType,
            'semester_order' => AcademicPeriod::SEMESTER_ORDER_MAP[$semesterType],
            'start_date' => $startDate,
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
    }

    private function requireSkaTemplateCache(): string
    {
        $cachePath = config('surat.template_surat_keterangan_aktif_cache_path');
        if (!is_string($cachePath) || !is_file($cachePath)) {
            $this->markTestSkipped('SKA template cache is not present in this environment.');
        }

        $header = file_get_contents($cachePath, false, null, 0, 2);
        $this->assertSame('PK', $header, 'SKA template cache must be a DOCX ZIP archive.');

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

class PhaseS3FakeSkaDocumentGenerationService extends SuratKeteranganAktifDocumentGenerationService
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
        SuratKeteranganAktifApplication $application,
        string $phase,
        array $overrides = [],
    ): string {
        $this->calls++;
        $this->lastPhase = $phase;
        $this->lastOverrides = $overrides;

        if ($this->fail) {
            throw new RuntimeException('fake SKA DOCX generation failed');
        }

        $path = 'letter-document-artifacts/'
            . SuratKeteranganAktifApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/source_fake_'
            . $this->calls
            . '.docx';
        Storage::disk('local')->put($path, 'fake SKA docx');

        return $path;
    }
}

class PhaseS3FakeDocumentConverter implements DocumentConverter
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

            throw new DocumentConverterException('fake SKA conversion failed');
        }

        file_put_contents($destPdfAbsolutePath, "%PDF-1.4\nfake");
    }
}

class PhaseS3ReadyOnSecondLookupArtifactService extends LetterDocumentArtifactService
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
