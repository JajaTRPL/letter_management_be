<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratPengantarMagangApplication;
use App\Services\SuratPengantarMagangDocumentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FinalTemplatePlaceholderContracts;
use Tests\Support\TemplatePlaceholderAssertions;
use Tests\TestCase;
use ZipArchive;

class SuratPengantarMagangPhaseDocumentGenerationTest extends TestCase
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

    public function test_active_cached_magang_template_matches_final_placeholder_contract(): void
    {
        $cachePath = $this->requireMagangTemplateCache();
        $analysis = TemplatePlaceholderAssertions::analyzeDocx($cachePath);

        $this->assertSame([], $analysis['syntax_errors']);

        $violations = TemplatePlaceholderAssertions::contractViolations(
            $analysis['placeholders'],
            FinalTemplatePlaceholderContracts::MAGANG,
            FinalTemplatePlaceholderContracts::MAGANG,
            FinalTemplatePlaceholderContracts::FORBIDDEN_FINAL_PLACEHOLDERS,
        );

        // Refreshed Pengantar-only template (source of truth): strict contract
        // match — no unknown, no missing, no forbidden placeholders.
        $this->assertSame([], $violations['unknown']);
        $this->assertSame([], $violations['missing']);
        $this->assertSame([], $violations['forbidden']);
        $this->assertContains('nomor_surat_pengantar', $analysis['placeholders']);
        // Surat Tugas placeholders must remain absent after the split.
        $this->assertNotContains('nomor_surat_tugas', $analysis['placeholders']);
        $this->assertNotContains('ttd_kadep_tugas', $analysis['placeholders']);
        $this->assertNotContains('paraf_tugas', $analysis['placeholders']);
        $this->assertNotContains('nomor_surat', $analysis['placeholders']);
        $this->assertNotContains('ttd_kadep', $analysis['placeholders']);
        $this->assertNotContains('paraf', $analysis['placeholders']);
        $this->assertNotContains('stempel_kadep', $analysis['placeholders']);
    }

    public function test_phase_generation_renders_private_docx_with_two_section_number_and_image_gates(): void
    {
        $this->requireMagangTemplateCache();
        [$application, $kadep] = $this->phaseApplication();
        $service = $this->service();
        $expectations = [
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW => [
                'overrides' => [
                    'tanggal_surat' => '2026-05-20',
                    'official_kadep' => $kadep,
                ],
                'numbers' => false,
            ],
            LetterDocumentArtifact::PHASE_PRODI_REVIEW => [
                'overrides' => $this->numberedOverrides($kadep),
                'numbers' => true,
            ],
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW => [
                'overrides' => $this->numberedOverrides($kadep),
                'numbers' => true,
            ],
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW => [
                'overrides' => $this->numberedOverrides($kadep),
                'numbers' => true,
            ],
        ];
        $mediaCounts = [];

        foreach ($expectations as $phase => $expected) {
            $path = $service->generateDocumentForPhase($application->fresh(), $phase, $expected['overrides']);

            $this->assertStringStartsWith(
                'letter-document-artifacts/' . SuratPengantarMagangApplication::LETTER_TYPE . '/' . $application->id . '/' . $phase . '/source_',
                $path,
            );
            $this->assertStringEndsWith('.docx', $path);
            $this->assertTrue(Storage::disk('local')->exists($path));

            $xml = $this->docxXml($path);
            $text = $this->plainText($xml);
            $mediaCounts[$phase] = $this->mediaCount($path);

            if ($expected['numbers']) {
                $this->assertStringContainsString('MAG/PENGANTAR/PENDING/001', $text);
            } else {
                $this->assertStringNotContainsString('MAG/PENGANTAR/PENDING/001', $text);
            }
            // S1 (Magang standalone): the tugas number is never rendered into the
            // Magang document anymore (Surat Tugas is a separate letter). The
            // leftover template placeholder is mapped to '' until the Doc is edited.
            $this->assertStringNotContainsString('MAG/TUGAS/PENDING/001', $text);

            $this->assertStringContainsString('Kepala Operasional Mitra', $text);
            $this->assertStringContainsString('PT Final Magang', $text);
            $this->assertStringContainsString('Jl. Kaliurang No. 10', $text);
            $this->assertStringContainsString('Caturtunggal', $text);
            $this->assertStringContainsString('Depok', $text);
            $this->assertStringContainsString('Sleman', $text);
            $this->assertStringContainsString('Daerah Istimewa Yogyakarta', $text);
            $this->assertStringContainsString('55281', $text);
            $this->assertStringContainsString('01 Juni 2026', $text);
            $this->assertStringContainsString('31 Agustus 2026', $text);
            // fakultas / dpa / posisi placeholders were intentionally removed from
            // the refreshed Pengantar-only Magang template, so the rendered DOCX no
            // longer contains 'Sekolah Vokasi', 'Dr. Pembimbing Resmi', or
            // 'Backend Engineer Intern'. They are not part of the Magang contract.
            $this->assertStringContainsString('Mahasiswa Magang Phase', $text);
            $this->assertStringContainsString('22/493038/SV/20654', $text);
            $this->assertStringContainsString('Teknologi Rekayasa Perangkat Lunak', $text);
            $this->assertStringContainsString('TRPL', $text);
            $this->assertStringContainsString('Teknik Elektro dan Informatika', $text);
            $this->assertStringNotContainsString('Departemen Departemen', $text);
            $this->assertStringContainsString('Ketua Departemen', $text);
            $this->assertStringContainsString('Kadep Magang Phase', $text);
            $this->assertStringContainsString('196501011990031001', $text);
            $this->assertStringNotContainsString('Legacy Recipient', $text);
            $this->assertStringNotContainsString('Legacy Full Address', $text);
            $this->assertStringNotContainsString('LEGACY/NUMBER/2026', $text);
            $this->assertSame([], TemplatePlaceholderAssertions::unresolvedPlaceholdersInXml($xml));
        }

        $this->assertSame(
            $mediaCounts[LetterDocumentArtifact::PHASE_PRODI_REVIEW],
            $mediaCounts[LetterDocumentArtifact::PHASE_TENDIK_REVIEW],
            'Tendik and Prodi phases must not add paraf or Kadep TTD images.',
        );
        $this->assertGreaterThan(
            $mediaCounts[LetterDocumentArtifact::PHASE_PRODI_REVIEW],
            $mediaCounts[LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW],
            'Departemen phase must add the pengantar paraf placement (tugas removed in S1).',
        );
        $this->assertGreaterThan(
            $mediaCounts[LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW],
            $mediaCounts[LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW],
            'Mahasiswa phase must add the pengantar Kadep TTD placement (tugas removed in S1).',
        );

        $fresh = $application->fresh();
        $this->assertNull($fresh->nomor_surat_pengantar);
        $this->assertNull($fresh->nomor_surat_tugas);
        $this->assertFalse(Storage::disk('public')->exists('surat-pengantar-magang/generated'));
        $this->assertDatabaseCount('letter_document_artifacts', 0);
    }

    public function test_tendik_phase_does_not_require_numbers_paraf_or_kadep_signature(): void
    {
        $this->requireMagangTemplateCache();
        [$application, $kadep] = $this->phaseApplication(['signature_path' => null]);
        config(['surat.global_paraf_path' => storage_path('app/testing/missing-magang-paraf.png')]);

        $path = $this->service()->generateDocumentForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            ['tanggal_surat' => '2026-05-20', 'official_kadep' => $kadep],
        );

        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame([], TemplatePlaceholderAssertions::unresolvedPlaceholdersInXml($this->docxXml($path)));
    }

    public function test_prodi_phase_requires_only_pengantar_number_and_tugas_is_not_required(): void
    {
        // S1 (Magang standalone): the Magang document no longer requires a tugas
        // number. Prodi-phase generation must succeed with the pengantar number
        // alone and render without unresolved placeholders.
        $this->requireMagangTemplateCache();
        [$application, $kadep] = $this->phaseApplication();

        $path = $this->service()->generateDocumentForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            [
                'nomor_surat_pengantar' => 'MAG/PENGANTAR/PENDING/001',
                'tanggal_surat' => '2026-05-21',
                'official_kadep' => $kadep,
            ],
        );

        $this->assertTrue(Storage::disk('local')->exists($path));
        $xml = $this->docxXml($path);
        $this->assertSame([], TemplatePlaceholderAssertions::unresolvedPlaceholdersInXml($xml));
        $this->assertStringContainsString('MAG/PENGANTAR/PENDING/001', $this->plainText($xml));
        $this->assertStringNotContainsString('MAG/TUGAS', $this->plainText($xml));
    }

    public function test_departemen_phase_requires_global_paraf(): void
    {
        $this->requireMagangTemplateCache();
        [$application, $kadep] = $this->phaseApplication();
        config(['surat.global_paraf_path' => storage_path('app/testing/missing-magang-paraf.png')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('paraf belum tersedia');

        $this->service()->generateDocumentForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            $this->numberedOverrides($kadep),
        );
    }

    public function test_mahasiswa_phase_requires_kadep_signature(): void
    {
        $this->requireMagangTemplateCache();
        [$application, $kadep] = $this->phaseApplication(['signature_path' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tanda tangan Kadep belum tersedia');

        $this->service()->generateDocumentForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            $this->numberedOverrides($kadep),
        );
    }

    public function test_required_explicit_field_is_not_filled_from_legacy_alias(): void
    {
        $this->requireMagangTemplateCache();
        [$application, $kadep] = $this->phaseApplication();
        $application->update([
            'jabatan_penerima' => null,
            'nama_penerima' => 'Legacy Position Must Not Render',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('jabatan penerima wajib tersedia');

        $this->service()->generateDocumentForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            ['tanggal_surat' => '2026-05-20', 'official_kadep' => $kadep],
        );
    }

    public function test_changing_legacy_aggregates_does_not_change_rendered_final_text(): void
    {
        $this->requireMagangTemplateCache();
        [$application, $kadep] = $this->phaseApplication();
        $service = $this->service();
        $overrides = $this->numberedOverrides($kadep);

        $beforePath = $service->generateDocumentForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, $overrides);
        $beforeText = $this->plainText($this->docxXml($beforePath));

        $application->update([
            'nomor_surat' => 'LEGACY/CHANGED/999',
            'nama_penerima' => 'Legacy Recipient Changed',
            'alamat_perusahaan' => 'Legacy Full Address Changed',
            'rentang_tanggal' => 'Legacy Range Changed',
        ]);

        $afterPath = $service->generateDocumentForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, $overrides);
        $afterText = $this->plainText($this->docxXml($afterPath));

        $this->assertSame($beforeText, $afterText);
        $this->assertStringNotContainsString('LEGACY/CHANGED/999', $afterText);
        $this->assertStringNotContainsString('Legacy Recipient Changed', $afterText);
        $this->assertStringNotContainsString('Legacy Full Address Changed', $afterText);
        $this->assertStringNotContainsString('Legacy Range Changed', $afterText);
    }

    /**
     * @param array<string, mixed> $kadepAttributes
     * @return array{0: SuratPengantarMagangApplication, 1: \App\Models\User}
     */
    private function phaseApplication(array $kadepAttributes = []): array
    {
        Storage::fake('local');
        Storage::fake('public');

        Storage::disk('public')->put('profiles/signatures/magang-kadep.png', $this->pngBytes());
        $this->tempParafPath = tempnam(sys_get_temp_dir(), 'magang_paraf_') . '.png';
        file_put_contents($this->tempParafPath, $this->pngBytes());
        config(['surat.global_paraf_path' => $this->tempParafPath]);

        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi UGM']);
        $program = $this->studyProgram($department, [
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
            'code' => 'TRPL',
        ]);
        [$student] = $this->completeMahasiswa([
            'name' => 'Mahasiswa Magang Phase',
        ], [
            'nim' => '22/493038/SV/20654',
            'nama_lengkap' => 'Mahasiswa Magang Phase',
        ], $program);

        $kadep = $this->akademik('kadep', array_merge([
            'department_id' => $department->id,
            'name' => 'Kadep Magang Phase',
            'nip' => '196501011990031001',
            'signature_path' => 'profiles/signatures/magang-kadep.png',
        ], $kadepAttributes));

        $application = $this->magangApplication($student, [
            'nomor_surat' => 'LEGACY/NUMBER/2026',
            'nomor_surat_pengantar' => null,
            'nomor_surat_tugas' => null,
            'nama_penerima' => 'Legacy Recipient',
            'jabatan_penerima' => 'Kepala Operasional Mitra',
            'nama_perusahaan' => 'PT Final Magang',
            'alamat_perusahaan' => 'Legacy Full Address',
            'alamat_jalan' => 'Jl. Kaliurang No. 10',
            'alamat_kelurahan' => 'Caturtunggal',
            'alamat_kecamatan' => 'Depok',
            'alamat_kota_kabupaten' => 'Sleman',
            'alamat_provinsi' => 'Daerah Istimewa Yogyakarta',
            'kode_pos' => '55281',
            'rentang_tanggal' => 'Legacy Range',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'dosen_pembimbing_dpa' => 'Dr. Pembimbing Resmi',
            'peran' => 'Backend Engineer Intern',
            'submitted_at' => '2026-05-20 09:00:00',
        ]);

        return [$application, $kadep];
    }

    /**
     * @return array<string, mixed>
     */
    private function numberedOverrides(\App\Models\User $kadep): array
    {
        return [
            'nomor_surat_pengantar' => 'MAG/PENGANTAR/PENDING/001',
            'nomor_surat_tugas' => 'MAG/TUGAS/PENDING/001',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ];
    }

    private function service(): SuratPengantarMagangDocumentGenerationService
    {
        return $this->app->make(SuratPengantarMagangDocumentGenerationService::class);
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
