<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\User;
use App\Services\SuratTugasDocumentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FinalTemplatePlaceholderContracts;
use Tests\Support\TemplatePlaceholderAssertions;
use Tests\TestCase;

class SuratTugasPhaseDocumentGenerationTest extends TestCase
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

    public function test_active_cached_surat_tugas_template_matches_final_placeholder_contract(): void
    {
        $cachePath = $this->requireSuratTugasTemplateCache();
        $analysis = TemplatePlaceholderAssertions::analyzeDocx($cachePath);

        $this->assertSame([], $analysis['syntax_errors']);

        $violations = TemplatePlaceholderAssertions::contractViolations(
            $analysis['placeholders'],
            FinalTemplatePlaceholderContracts::SURAT_TUGAS,
            FinalTemplatePlaceholderContracts::SURAT_TUGAS,
            FinalTemplatePlaceholderContracts::FORBIDDEN_FINAL_PLACEHOLDERS,
        );

        $this->assertSame([], $violations['unknown']);
        $this->assertSame([], $violations['missing']);
        $this->assertSame([], $violations['forbidden']);
        $this->assertContains('nomor_surat_tugas', $analysis['placeholders']);
        $this->assertNotContains('nomor_surat', $analysis['placeholders']);
    }

    public function test_phase_generation_renders_private_docx_with_number_gate(): void
    {
        $this->requireSuratTugasTemplateCache();
        [$application, $kadep] = $this->phaseApplication();
        $service = $this->service();

        // Prodi phase: number rendered.
        $prodiPath = $service->generateDocumentForPhase($application->fresh(), LetterDocumentArtifact::PHASE_PRODI_REVIEW, [
            'nomor_surat_tugas' => 'ST/PENDING/001',
            'tanggal_surat' => '2026-05-21',
            'official_kadep' => $kadep,
        ]);

        $this->assertStringStartsWith(
            'letter-document-artifacts/surat-tugas/' . $application->id . '/' . LetterDocumentArtifact::PHASE_PRODI_REVIEW . '/source_',
            $prodiPath,
        );
        $this->assertTrue(Storage::disk('local')->exists($prodiPath));

        $text = $this->plainText($this->docxXml($prodiPath));
        $this->assertStringContainsString('ST/PENDING/001', $text);
        $this->assertStringContainsString('PT Final Tugas', $text);
        $this->assertStringContainsString('Kerja Praktik Industri', $text);
        $this->assertStringContainsString('Backend Engineer Intern', $text);
        $this->assertStringContainsString('Dr. Pembimbing Resmi', $text);
        $this->assertStringContainsString('Mahasiswa Tugas Phase', $text);
        $this->assertStringContainsString('22/493038/SV/20654', $text);
        $this->assertStringContainsString('Teknologi Rekayasa Perangkat Lunak', $text);
        $this->assertStringContainsString('Teknik Elektro dan Informatika', $text);
        $this->assertStringContainsString('Sekolah Vokasi', $text);
        $this->assertStringContainsString('01 Juni 2026', $text);
        $this->assertStringContainsString('31 Agustus 2026', $text);
        $this->assertStringContainsString('Kadep Tugas Phase', $text);
        $this->assertStringContainsString('196501011990031001', $text);
        $this->assertSame([], TemplatePlaceholderAssertions::unresolvedPlaceholdersInXml($this->docxXml($prodiPath)));

        // Tendik phase: number NOT rendered (no number gate).
        $tendikPath = $service->generateDocumentForPhase($application->fresh(), LetterDocumentArtifact::PHASE_TENDIK_REVIEW, [
            'tanggal_surat' => '2026-05-20',
            'official_kadep' => $kadep,
        ]);
        $tendikText = $this->plainText($this->docxXml($tendikPath));
        $this->assertStringNotContainsString('ST/PENDING/001', $tendikText);
        $this->assertSame([], TemplatePlaceholderAssertions::unresolvedPlaceholdersInXml($this->docxXml($tendikPath)));

        $this->assertFalse(Storage::disk('public')->exists('surat-tugas/generated'));
        $this->assertDatabaseCount('letter_document_artifacts', 0);
    }

    public function test_tendik_phase_does_not_require_number_or_signature(): void
    {
        $this->requireSuratTugasTemplateCache();
        [$application, $kadep] = $this->phaseApplication(['signature_path' => null]);
        config(['surat.global_paraf_path' => storage_path('app/testing/missing-st-paraf.png')]);

        $path = $this->service()->generateDocumentForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            ['tanggal_surat' => '2026-05-20', 'official_kadep' => $kadep],
        );

        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame([], TemplatePlaceholderAssertions::unresolvedPlaceholdersInXml($this->docxXml($path)));
    }

    public function test_mahasiswa_phase_requires_kadep_signature(): void
    {
        $this->requireSuratTugasTemplateCache();
        [$application, $kadep] = $this->phaseApplication(['signature_path' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tanda tangan Kadep belum tersedia');

        $this->service()->generateDocumentForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ['nomor_surat_tugas' => 'ST/001/2026', 'tanggal_surat' => '2026-05-21', 'official_kadep' => $kadep],
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function phaseApplication(array $kadepAttributes = []): array
    {
        Storage::fake('local');
        Storage::fake('public');

        Storage::disk('public')->put('profiles/signatures/st-kadep.png', $this->pngBytes());
        $this->tempParafPath = tempnam(sys_get_temp_dir(), 'st_paraf_') . '.png';
        file_put_contents($this->tempParafPath, $this->pngBytes());
        config(['surat.global_paraf_path' => $this->tempParafPath]);

        $department = $this->department(['name' => 'Departemen Teknik Elektro dan Informatika']);
        $department->faculty()->update(['name' => 'Sekolah Vokasi UGM']);
        $program = $this->studyProgram($department, [
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
            'code' => 'TRPL',
        ]);
        [$student] = $this->completeMahasiswa([
            'name' => 'Mahasiswa Tugas Phase',
        ], [
            'nim' => '22/493038/SV/20654',
            'nama_lengkap' => 'Mahasiswa Tugas Phase',
        ], $program);

        $kadep = $this->akademik('kadep', array_merge([
            'department_id' => $department->id,
            'name' => 'Kadep Tugas Phase',
            'nip' => '196501011990031001',
            'signature_path' => 'profiles/signatures/st-kadep.png',
        ], $kadepAttributes));

        $application = $this->suratTugasApplication($student, [
            'nomor_surat_tugas' => null,
            'nama_perusahaan' => 'PT Final Tugas',
            'kegiatan' => 'Kerja Praktik Industri',
            'posisi' => 'Backend Engineer Intern',
            'dosen_pembimbing_dpa' => 'Dr. Pembimbing Resmi',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'submitted_at' => '2026-05-20 09:00:00',
        ]);

        return [$application, $kadep];
    }

    private function service(): SuratTugasDocumentGenerationService
    {
        return $this->app->make(SuratTugasDocumentGenerationService::class);
    }

    private function requireSuratTugasTemplateCache(): string
    {
        $cachePath = config('surat.template_surat_tugas_cache_path');
        if (!is_string($cachePath) || !is_file($cachePath)) {
            $this->markTestSkipped('Surat Tugas template cache is not present in this environment.');
        }

        $header = file_get_contents($cachePath, false, null, 0, 2);
        $this->assertSame('PK', $header, 'Surat Tugas template cache must be a DOCX ZIP archive.');

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
}
