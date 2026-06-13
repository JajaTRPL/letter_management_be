<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratPengantarMagangApplication;
use App\Services\DocumentConverter;
use App\Services\DocumentConverterException;
use App\Services\LetterDocumentArtifactService;
use App\Services\LetterDocumentSourceHashService;
use App\Services\SuratPengantarMagangDocumentGenerationService;
use App\Services\SuratPengantarMagangPhaseResolver;
use App\Services\SuratPengantarMagangPreviewGenerationException;
use App\Services\SuratPengantarMagangPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\TemplatePlaceholderAssertions;
use Tests\TestCase;

class SuratPengantarMagangPreviewGenerationServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-25 10:00:00'));
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

    public function test_generates_ready_artifact_for_current_phase_without_legacy_side_effects(): void
    {
        $application = $this->previewApplication(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat_pengantar' => 'MAG/P/001/2026',
            'nomor_surat_tugas' => 'MAG/T/001/2026',
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $generator = new PhaseMagang3FakeDocumentGenerationService();
        $converter = new PhaseMagang3FakeDocumentConverter();
        $actor = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);

        $artifact = $this->service($generator, $converter)->generateForCurrentPhase($application->fresh(), [], $actor->id);

        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->status);
        $this->assertSame(1, $artifact->version);
        $this->assertSame(SuratPengantarMagangApplication::LETTER_TYPE, $artifact->letter_type);
        $this->assertSame($application->id, $artifact->application_id);
        $this->assertSame(LetterDocumentArtifact::PHASE_PRODI_REVIEW, $artifact->phase);
        $this->assertSame($actor->id, $artifact->generated_by);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact->source_hash);
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . SuratPengantarMagangApplication::LETTER_TYPE . '/' . $application->id . '/prodi_review/source_',
            $artifact->docx_path,
        );
        $this->assertStringStartsWith(
            'letter-document-artifacts/' . SuratPengantarMagangApplication::LETTER_TYPE . '/' . $application->id . '/prodi_review/preview_',
            $artifact->pdf_path,
        );
        $this->assertTrue(Storage::disk('local')->exists($artifact->docx_path));
        $this->assertTrue(Storage::disk('local')->exists($artifact->pdf_path));
        $this->assertSame(1, $generator->calls);
        $this->assertSame(1, $converter->calls);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('MAG/P/001/2026', $fresh->nomor_surat_pengantar);
        $this->assertSame('MAG/T/001/2026', $fresh->nomor_surat_tugas);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_existing_ready_artifact_with_same_source_hash_is_returned_without_regeneration(): void
    {
        $application = $this->previewApplication(SuratPengantarMagangApplication::STATUS_SUBMITTED);
        $ready = $this->service()->generateForCurrentPhase($application->fresh());
        $generator = new PhaseMagang3FakeDocumentGenerationService();
        $converter = new PhaseMagang3FakeDocumentConverter();

        $artifact = $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());

        $this->assertTrue($ready->is($artifact));
        $this->assertSame(0, $generator->calls);
        $this->assertSame(0, $converter->calls);
        $this->assertSame(1, LetterDocumentArtifact::query()->count());
    }

    public function test_explicit_final_source_changes_create_new_hashes_and_artifacts(): void
    {
        $application = $this->previewApplication(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat_pengantar' => 'MAG/P/001/2026',
            'nomor_surat_tugas' => 'MAG/T/001/2026',
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $service = $this->service();
        $previous = $service->generateForCurrentPhase($application->fresh());

        // Magang Pengantar-only: nomor_surat_tugas, dpa (dosen_pembimbing_dpa) and
        // posisi (peran) no longer feed the Magang hash, so they are intentionally
        // excluded from the "changes create a new artifact" set (their hash-
        // stability is covered by a dedicated regression in the source-hash suite).
        foreach ([
            'nomor_surat_pengantar' => 'MAG/P/002/2026',
            'alamat_jalan' => 'Jl. Perubahan No. 2',
            'tgl_mulai' => '2026-06-02',
            'tgl_selesai' => '2026-09-01',
        ] as $field => $value) {
            $application->update([$field => $value]);
            $next = $service->generateForCurrentPhase($application->fresh());

            $this->assertFalse($previous->is($next), "{$field} should generate a new Magang artifact.");
            $this->assertNotSame($previous->source_hash, $next->source_hash, "{$field} should change the source hash.");
            $previous = $next;
        }

        $this->assertSame(5, LetterDocumentArtifact::query()->count());
    }

    public function test_legacy_aggregate_changes_do_not_create_new_artifact_when_explicit_contract_is_unchanged(): void
    {
        $application = $this->previewApplication(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat_pengantar' => 'MAG/P/001/2026',
            'nomor_surat_tugas' => 'MAG/T/001/2026',
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $service = $this->service();
        $first = $service->generateForCurrentPhase($application->fresh());

        $application->update([
            'nomor_surat' => 'LEGACY/CHANGED/001',
            'nama_penerima' => 'Legacy Changed',
            'alamat_perusahaan' => 'Legacy Full Address Changed',
            'rentang_tanggal' => 'Legacy Date Range Changed',
        ]);
        $second = $service->generateForCurrentPhase($application->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame($first->source_hash, $second->source_hash);
        $this->assertSame(1, LetterDocumentArtifact::query()->count());
    }

    public function test_ready_cache_is_rechecked_after_lock_before_generating(): void
    {
        $application = $this->previewApplication(SuratPengantarMagangApplication::STATUS_SUBMITTED);
        $ready = LetterDocumentArtifact::create([
            'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            'version' => 1,
            'docx_path' => 'letter-document-artifacts/recheck/source.docx',
            'pdf_path' => 'letter-document-artifacts/recheck/preview.pdf',
            'source_hash' => 'ready-after-lock',
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => Carbon::now(),
        ]);
        $artifactService = new PhaseMagang3ReadyOnSecondLookupArtifactService($ready);
        $generator = new PhaseMagang3FakeDocumentGenerationService();
        $converter = new PhaseMagang3FakeDocumentConverter();

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
            SuratPengantarMagangApplication::STATUS_DRAFT,
            SuratPengantarMagangApplication::STATUS_REVISION,
            SuratPengantarMagangApplication::STATUS_REJECTED,
        ] as $status) {
            $application = $this->previewApplication($status);

            try {
                $this->service()->generateForCurrentPhase($application->fresh());
                $this->fail("Expected unavailable Magang phase for {$status}.");
            } catch (SuratPengantarMagangPreviewGenerationException $exception) {
                $this->assertStringContainsString('phase is unavailable', $exception->getMessage());
            }
        }

        $this->assertSame(0, LetterDocumentArtifact::query()->count());
    }

    public function test_docx_generation_exception_marks_artifact_failed_without_application_mutation(): void
    {
        $application = $this->previewApplication(SuratPengantarMagangApplication::STATUS_SUBMITTED);
        $generator = new PhaseMagang3FakeDocumentGenerationService();
        $generator->fail = true;
        $converter = new PhaseMagang3FakeDocumentConverter();

        try {
            $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());
            $this->fail('Expected Magang preview generation exception.');
        } catch (SuratPengantarMagangPreviewGenerationException $exception) {
            $this->assertStringContainsString('artifact generation failed', $exception->getMessage());
        }

        $artifact = LetterDocumentArtifact::query()->firstOrFail();
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, $artifact->status);
        $this->assertNull($artifact->docx_path);
        $this->assertNull($artifact->pdf_path);
        $this->assertSame(0, $converter->calls);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $fresh->status);
    }

    public function test_converter_exception_marks_artifact_failed_and_deletes_partial_pdf(): void
    {
        $application = $this->previewApplication(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat_pengantar' => 'MAG/P/001/2026',
            'nomor_surat_tugas' => 'MAG/T/001/2026',
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $generator = new PhaseMagang3FakeDocumentGenerationService();
        $converter = new PhaseMagang3FakeDocumentConverter();
        $converter->fail = true;
        $converter->writePartialBeforeFail = true;

        try {
            $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());
            $this->fail('Expected Magang preview generation exception.');
        } catch (SuratPengantarMagangPreviewGenerationException $exception) {
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
        $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, $fresh->status);
    }

    public function test_pending_dual_numbers_affect_hash_and_rendered_docx_without_db_mutation(): void
    {
        $this->requireMagangTemplateCache();
        [$application, $kadep] = $this->realRenderApplication();
        $converter = new PhaseMagang3FakeDocumentConverter();
        $service = $this->service(app(SuratPengantarMagangDocumentGenerationService::class), $converter);

        $first = $service->generateForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, [
            'nomor_surat_pengantar' => 'MAG/P/PENDING/P3-001',
            'nomor_surat_tugas' => 'MAG/T/PENDING/P3-001',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);

        $xml = implode("\n", TemplatePlaceholderAssertions::wordXmlEntries(Storage::disk('local')->path($first->docx_path)));
        $text = html_entity_decode((string) preg_replace('/<[^>]+>/', '', $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('MAG/P/PENDING/P3-001', $text);
        // S1 (Magang standalone): tugas number is no longer rendered into the Magang doc.
        $this->assertStringNotContainsString('MAG/T/PENDING/P3-001', $text);

        $second = $service->generateForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, [
            'nomor_surat_pengantar' => 'MAG/P/PENDING/P3-002',
            'nomor_surat_tugas' => 'MAG/T/PENDING/P3-002',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);

        $this->assertNotSame($first->source_hash, $second->source_hash);
        $this->assertNull($application->fresh()->nomor_surat_pengantar);
        $this->assertNull($application->fresh()->nomor_surat_tugas);
    }

    public function test_status_mapping_uses_magang_phase_resolver(): void
    {
        foreach ([
            SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            SuratPengantarMagangApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        ] as $status => $expectedPhase) {
            $application = $this->previewApplication($status, $this->phaseAttributes($status));
            $generator = new PhaseMagang3FakeDocumentGenerationService();

            $artifact = $this->service($generator)->generateForCurrentPhase($application->fresh());

            $this->assertSame($expectedPhase, $artifact->phase);
            $this->assertSame($expectedPhase, $generator->lastPhase);
        }
    }

    public function test_tanggal_surat_policy_is_stable_and_uses_tendik_approval_date_for_later_phase(): void
    {
        $submitted = $this->previewApplication(SuratPengantarMagangApplication::STATUS_SUBMITTED, [
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ]);
        $submittedGenerator = new PhaseMagang3FakeDocumentGenerationService();
        $service = $this->service($submittedGenerator);

        $first = $service->generateForCurrentPhase($submitted->fresh());
        Carbon::setTestNow(Carbon::parse('2026-05-28 10:00:00'));
        $second = $service->generateForCurrentPhase($submitted->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $submittedGenerator->calls);
        $this->assertSame('2026-05-20', $submittedGenerator->lastOverrides['tanggal_surat']->toDateString());

        $approved = $this->previewApplication(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat_pengantar' => 'MAG/P/001/2026',
            'nomor_surat_tugas' => 'MAG/T/001/2026',
            'submitted_at' => Carbon::parse('2026-05-19 08:00:00'),
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $approvedGenerator = new PhaseMagang3FakeDocumentGenerationService();

        $this->service($approvedGenerator)->generateForCurrentPhase($approved->fresh());

        $this->assertSame('2026-05-21', $approvedGenerator->lastOverrides['tanggal_surat']->toDateString());
    }

    public function test_phase_lock_is_scoped_and_reports_timeout(): void
    {
        $application = $this->previewApplication(SuratPengantarMagangApplication::STATUS_SUBMITTED);
        $service = $this->service(lockWaitSeconds: 0);
        $key = $service->lockKeyFor($application, LetterDocumentArtifact::PHASE_TENDIK_REVIEW);
        $this->assertStringContainsString(SuratPengantarMagangApplication::LETTER_TYPE, $key);
        $this->assertStringContainsString((string) $application->id, $key);
        $this->assertStringContainsString(LetterDocumentArtifact::PHASE_TENDIK_REVIEW, $key);

        $lock = Cache::lock($key, 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(SuratPengantarMagangPreviewGenerationException::class);
            $this->expectExceptionMessage('already in progress');
            $service->generateForCurrentPhase($application->fresh());
        } finally {
            $lock->release();
        }
    }

    private function service(
        SuratPengantarMagangDocumentGenerationService|PhaseMagang3FakeDocumentGenerationService|null $generator = null,
        ?PhaseMagang3FakeDocumentConverter $converter = null,
        ?LetterDocumentArtifactService $artifactService = null,
        int $lockWaitSeconds = 10,
    ): SuratPengantarMagangPreviewGenerationService {
        return new SuratPengantarMagangPreviewGenerationService(
            app(SuratPengantarMagangPhaseResolver::class),
            app(LetterDocumentSourceHashService::class),
            $artifactService ?? app(LetterDocumentArtifactService::class),
            $generator ?? new PhaseMagang3FakeDocumentGenerationService(),
            $converter ?? new PhaseMagang3FakeDocumentConverter(),
            120,
            $lockWaitSeconds,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function previewApplication(string $status, array $attributes = []): SuratPengantarMagangApplication
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep Magang Preview',
            'nip' => '196501011990031001',
        ]);

        return $this->magangApplication($student, array_merge([
            'status' => $status,
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ], $attributes));
    }

    /**
     * @return array{0: SuratPengantarMagangApplication, 1: \App\Models\User}
     */
    private function realRenderApplication(): array
    {
        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi UGM']);
        $program = $this->studyProgram($department, [
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
            'code' => 'TRPL',
        ]);
        [$student] = $this->completeMahasiswa([
            'name' => 'Mahasiswa Magang Preview',
        ], [
            'nim' => '22/493038/SV/20654',
            'nama_lengkap' => 'Mahasiswa Magang Preview',
        ], $program);
        $kadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep Magang Preview',
            'nip' => '196501011990031001',
        ]);
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat_pengantar' => null,
            'nomor_surat_tugas' => null,
            'jabatan_penerima' => 'Direktur Operasional',
            'nama_perusahaan' => 'PT Magang Preview',
            'alamat_jalan' => 'Jl. Preview No. 1',
            'alamat_kelurahan' => 'Caturtunggal',
            'alamat_kecamatan' => 'Depok',
            'alamat_kota_kabupaten' => 'Sleman',
            'alamat_provinsi' => 'Daerah Istimewa Yogyakarta',
            'kode_pos' => '55281',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'dosen_pembimbing_dpa' => 'Dr. DPA Preview',
            'peran' => 'Backend Engineer Intern',
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
            SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            SuratPengantarMagangApplication::STATUS_COMPLETED,
        ], true)) {
            return [
                'nomor_surat_pengantar' => 'MAG/P/001/2026',
                'nomor_surat_tugas' => 'MAG/T/001/2026',
                'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
            ];
        }

        return [];
    }

    private function requireMagangTemplateCache(): string
    {
        $cachePath = config('surat.template_surat_pengantar_magang_cache_path');
        if (!is_string($cachePath) || !is_file($cachePath)) {
            $this->markTestSkipped('Magang template cache is not present in this environment.');
        }

        $header = file_get_contents($cachePath, false, null, 0, 2);
        $this->assertSame('PK', $header, 'Magang template cache must be a DOCX ZIP archive.');

        return $cachePath;
    }
}

class PhaseMagang3FakeDocumentGenerationService extends SuratPengantarMagangDocumentGenerationService
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
        SuratPengantarMagangApplication $application,
        string $phase,
        array $overrides = [],
    ): string {
        $this->calls++;
        $this->lastPhase = $phase;
        $this->lastOverrides = $overrides;

        if ($this->fail) {
            throw new RuntimeException('fake Magang DOCX generation failed');
        }

        $path = 'letter-document-artifacts/'
            . SuratPengantarMagangApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/source_fake_'
            . $this->calls
            . '.docx';
        Storage::disk('local')->put($path, 'fake Magang docx');

        return $path;
    }
}

class PhaseMagang3FakeDocumentConverter implements DocumentConverter
{
    public int $calls = 0;
    public bool $fail = false;
    public bool $writePartialBeforeFail = false;
    public ?string $lastDestination = null;

    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void
    {
        $this->calls++;
        $this->lastDestination = $destPdfAbsolutePath;

        if ($this->fail) {
            if ($this->writePartialBeforeFail) {
                file_put_contents($destPdfAbsolutePath, 'partial');
            }

            throw new DocumentConverterException('fake Magang conversion failed');
        }

        file_put_contents($destPdfAbsolutePath, "%PDF-1.4\nfake");
    }
}

class PhaseMagang3ReadyOnSecondLookupArtifactService extends LetterDocumentArtifactService
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
