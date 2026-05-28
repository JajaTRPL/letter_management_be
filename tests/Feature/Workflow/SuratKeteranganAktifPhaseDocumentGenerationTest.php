<?php

namespace Tests\Feature\Workflow;

use App\Models\AcademicPeriod;
use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use App\Services\SuratKeteranganAktifDocumentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FinalTemplatePlaceholderContracts;
use Tests\Support\TemplatePlaceholderAssertions;
use Tests\TestCase;
use ZipArchive;

class SuratKeteranganAktifPhaseDocumentGenerationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private ?string $tempParafPath = null;

    protected function tearDown(): void
    {
        if ($this->tempParafPath && is_file($this->tempParafPath)) {
            @unlink($this->tempParafPath);
        }

        parent::tearDown();
    }

    public function test_active_cached_ska_template_matches_final_placeholder_contract(): void
    {
        $cachePath = $this->requireSkaTemplateCache();
        $analysis = TemplatePlaceholderAssertions::analyzeDocx($cachePath);

        $this->assertSame([], $analysis['syntax_errors']);

        $violations = TemplatePlaceholderAssertions::contractViolations(
            $analysis['placeholders'],
            FinalTemplatePlaceholderContracts::SKA,
            FinalTemplatePlaceholderContracts::SKA,
            FinalTemplatePlaceholderContracts::FORBIDDEN_FINAL_PLACEHOLDERS,
        );

        $this->assertSame(['unknown' => [], 'missing' => [], 'forbidden' => []], $violations);
        $this->assertContains('nomor_surat_aktif', $analysis['placeholders']);
        $this->assertNotContains('nomor_surat', $analysis['placeholders']);
        $this->assertNotContains('ttd_kadep', $analysis['placeholders']);
        $this->assertNotContains('paraf', $analysis['placeholders']);
        $this->assertNotContains('stempel_kadep', $analysis['placeholders']);
    }

    public function test_phase_generation_renders_private_docx_with_phase_specific_number_and_images(): void
    {
        $this->requireSkaTemplateCache();
        [$application, $kadep] = $this->phaseApplication();
        $service = $this->service();

        $expectations = [
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW => [
                'overrides' => ['tanggal_surat' => '2026-05-20'],
                'includes_number' => false,
                'includes_paraf' => false,
                'includes_signature' => false,
            ],
            LetterDocumentArtifact::PHASE_PRODI_REVIEW => [
                'overrides' => [
                    'nomor_surat' => 'AKT/PENDING/001',
                    'tanggal_surat' => '2026-05-21',
                    'official_kadep' => $kadep,
                ],
                'includes_number' => true,
                'includes_paraf' => false,
                'includes_signature' => false,
            ],
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW => [
                'overrides' => [
                    'nomor_surat' => 'AKT/PENDING/001',
                    'tanggal_surat' => '2026-05-21',
                    'official_kadep' => $kadep,
                ],
                'includes_number' => true,
                'includes_paraf' => true,
                'includes_signature' => false,
            ],
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW => [
                'overrides' => [
                    'nomor_surat' => 'AKT/PENDING/001',
                    'tanggal_surat' => '2026-05-21',
                    'official_kadep' => $kadep,
                ],
                'includes_number' => true,
                'includes_paraf' => true,
                'includes_signature' => true,
            ],
        ];

        $mediaCounts = [];

        foreach ($expectations as $phase => $expected) {
            $path = $service->generateDocumentForPhase($application->fresh(), $phase, $expected['overrides']);

            $this->assertStringStartsWith(
                'letter-document-artifacts/' . SuratKeteranganAktifApplication::LETTER_TYPE . '/' . $application->id . '/' . $phase . '/source_',
                $path,
            );
            $this->assertStringEndsWith('.docx', $path);
            $this->assertTrue(Storage::disk('local')->exists($path));

            $xml = $this->docxXml($path);
            $text = $this->plainText($xml);
            $mediaCounts[$phase] = $this->mediaCount($path);

            if ($expected['includes_number']) {
                $this->assertStringContainsString('AKT/PENDING/001', $text);
            } else {
                $this->assertStringNotContainsString('AKT/PENDING/001', $text);
            }

            $this->assertStringContainsString('Ketua Departemen', $text);
            $this->assertStringContainsString('Teknik Elektro dan Informatika', $text);
            $this->assertStringNotContainsString('Departemen Departemen', $text);
            $this->assertStringContainsString('Sekolah Vokasi', $text);
            $this->assertStringNotContainsString('Sekolah Vokasi UGM UGM', $text);
            $this->assertStringContainsString('Mahasiswa SKA Phase', $text);
            $this->assertStringContainsString('22/493038/SV/20654', $text);
            $this->assertStringContainsString('Teknologi Rekayasa Perangkat Lunak', $text);
            $this->assertStringContainsString('Orang Tua SKA', $text);
            $this->assertStringContainsString('Pegawai Negeri', $text);
            $this->assertStringContainsString('197001012000011001', $text);
            $this->assertStringContainsString('IV/a', $text);
            $this->assertStringContainsString('Instansi SKA', $text);
            $this->assertStringContainsString('Semester Genap Tahun Akademik 2025/2026', $text);
            $this->assertStringContainsString('Keperluan beasiswa perusahaan', $text);

            $this->assertSame([], TemplatePlaceholderAssertions::unresolvedPlaceholdersInXml($xml));
            $this->assertStringNotContainsString('${nomor_surat}', $xml);
            $this->assertStringNotContainsString('${nomor_surat_aktif}', $xml);
            $this->assertStringNotContainsString('${ttd_kadep}', $xml);
            $this->assertStringNotContainsString('${ttd_kadep_aktif}', $xml);
            $this->assertStringNotContainsString('${paraf}', $xml);
            $this->assertStringNotContainsString('${paraf_aktif}', $xml);
            $this->assertStringNotContainsString('${stempel_kadep}', $xml);
        }

        $this->assertSame(
            $mediaCounts[LetterDocumentArtifact::PHASE_PRODI_REVIEW],
            $mediaCounts[LetterDocumentArtifact::PHASE_TENDIK_REVIEW],
            'Tendik and Prodi phases should not add SKA paraf or Kadep TTD images.',
        );
        $this->assertGreaterThan(
            $mediaCounts[LetterDocumentArtifact::PHASE_PRODI_REVIEW],
            $mediaCounts[LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW],
            'Departemen phase should add the paraf image.',
        );
        $this->assertGreaterThan(
            $mediaCounts[LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW],
            $mediaCounts[LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW],
            'Mahasiswa phase should add the Kadep TTD image.',
        );

        $fresh = $application->fresh();
        $this->assertNull($fresh->nomor_surat);
        $this->assertDatabaseCount('letter_document_artifacts', 0);
    }

    public function test_missing_kadep_signature_fails_only_when_phase_requires_it(): void
    {
        $this->requireSkaTemplateCache();
        [$application, $kadep] = $this->phaseApplication(['signature_path' => null]);
        $service = $this->service();

        $prodiPath = $service->generateDocumentForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, [
            'nomor_surat' => 'AKT/PENDING/001',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);
        $this->assertTrue(Storage::disk('local')->exists($prodiPath));

        $departemenPath = $service->generateDocumentForPhase($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, [
            'nomor_surat' => 'AKT/PENDING/001',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);
        $this->assertTrue(Storage::disk('local')->exists($departemenPath));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tanda tangan Kadep');

        $service->generateDocumentForPhase($application->fresh(), LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, [
            'nomor_surat' => 'AKT/PENDING/001',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);
    }

    public function test_missing_paraf_fails_only_when_phase_requires_it(): void
    {
        $this->requireSkaTemplateCache();
        [$application, $kadep] = $this->phaseApplication();
        config(['surat.global_paraf_path' => storage_path('app/testing/missing-paraf.png')]);
        $service = $this->service();

        $prodiPath = $service->generateDocumentForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, [
            'nomor_surat' => 'AKT/PENDING/001',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);
        $this->assertTrue(Storage::disk('local')->exists($prodiPath));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('paraf');

        $service->generateDocumentForPhase($application->fresh(), LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW, [
            'nomor_surat' => 'AKT/PENDING/001',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);
    }

    /**
     * @param array<string, mixed> $kadepAttributes
     * @return array{0: SuratKeteranganAktifApplication, 1: \App\Models\User}
     */
    private function phaseApplication(array $kadepAttributes = []): array
    {
        Storage::fake('local');
        Storage::fake('public');

        Storage::disk('public')->put('profiles/signatures/ska-kadep.png', $this->pngBytes());

        $this->tempParafPath = tempnam(sys_get_temp_dir(), 'ska_paraf_') . '.png';
        file_put_contents($this->tempParafPath, $this->pngBytes());
        config(['surat.global_paraf_path' => $this->tempParafPath]);

        $this->activeAcademicPeriod('2025/2026', AcademicPeriod::SEMESTER_TYPE_GENAP);
        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi UGM']);
        $program = $this->studyProgram($department, ['name' => 'Teknologi Rekayasa Perangkat Lunak']);
        [$student] = $this->completeMahasiswa([
            'name' => 'Mahasiswa SKA Phase',
        ], [
            'nim' => '22/493038/SV/20654',
            'nama_lengkap' => 'Mahasiswa SKA Phase',
        ], $program);

        $kadep = $this->akademik('kadep', array_merge([
            'department_id' => $department->id,
            'name' => 'Kadep SKA Phase',
            'nip' => '196501011990031001',
            'signature_path' => 'profiles/signatures/ska-kadep.png',
        ], $kadepAttributes));

        $application = $this->aktifApplication($student, [
            'nomor_surat' => null,
            'keperluan' => 'Keperluan beasiswa perusahaan',
            'nama_orang_tua_wali' => 'Orang Tua SKA',
            'pekerjaan_orang_tua_wali' => 'Pegawai Negeri',
            'nip_orang_tua_wali' => '197001012000011001',
            'pangkat_gol_orang_tua_wali' => 'IV/a',
            'instansi_orang_tua_wali' => 'Instansi SKA',
            'submitted_at' => '2026-05-20 09:00:00',
        ]);

        return [$application, $kadep];
    }

    private function service(): SuratKeteranganAktifDocumentGenerationService
    {
        return $this->app->make(SuratKeteranganAktifDocumentGenerationService::class);
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

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }

    private function docxXml(string $localPath): string
    {
        return implode("\n", TemplatePlaceholderAssertions::wordXmlEntries(Storage::disk('local')->path($localPath)));
    }

    private function plainText(string $xml): string
    {
        return html_entity_decode((string) preg_replace('/<[^>]+>/', '', $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function mediaCount(string $localPath): int
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($localPath)) === true);

        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && preg_match('#^word/media/#', $name) === 1) {
                $count++;
            }
        }

        $zip->close();

        return $count;
    }
}
