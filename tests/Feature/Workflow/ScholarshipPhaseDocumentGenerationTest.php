<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Services\AcademicSignatoryService;
use App\Services\LetterAssignmentService;
use App\Services\MahasiswaProfileDataService;
use App\Services\ScholarshipAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;
use ZipArchive;

class ScholarshipPhaseDocumentGenerationTest extends TestCase
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

    public function test_phase_aware_generation_renders_phase_specific_nomor_and_images_without_mutating_application(): void
    {
        [$application] = $this->phaseApplication();
        $service = $this->phaseService();

        $expectations = [
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW => [
                'expected_nomor_rekomendasi' => 'Nomor rekomendasi: -',
                'unexpected_nomor' => 'PENDING-NOMOR-001',
                'media_count' => 1,
                'includes_paraf' => false,
                'includes_kadep_signature' => false,
                'overrides' => [],
            ],
            LetterDocumentArtifact::PHASE_PRODI_REVIEW => [
                'expected_nomor_rekomendasi' => 'Nomor rekomendasi: PENDING-NOMOR-001',
                'unexpected_nomor' => null,
                'media_count' => 1,
                'includes_paraf' => false,
                'includes_kadep_signature' => false,
                'overrides' => ['nomor_surat' => 'PENDING-NOMOR-001'],
            ],
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW => [
                'expected_nomor_rekomendasi' => 'Nomor rekomendasi: PENDING-NOMOR-001',
                'unexpected_nomor' => null,
                'media_count' => 2,
                'includes_paraf' => true,
                'includes_kadep_signature' => false,
                'overrides' => ['nomor_surat' => 'PENDING-NOMOR-001'],
            ],
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW => [
                'expected_nomor_rekomendasi' => 'Nomor rekomendasi: PENDING-NOMOR-001',
                'unexpected_nomor' => null,
                'media_count' => 3,
                'includes_paraf' => true,
                'includes_kadep_signature' => true,
                'overrides' => ['nomor_surat' => 'PENDING-NOMOR-001'],
            ],
        ];

        foreach ($expectations as $phase => $expected) {
            $path = $service->generateDocumentForPhase($application->fresh(), $phase, $expected['overrides']);

            $this->assertNotFalse($path, "Expected {$phase} DOCX generation to succeed.");
            $this->assertStringStartsWith(
                'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->id . '/' . $phase . '/',
                $path,
            );
            $this->assertTrue(Storage::disk('local')->exists($path));

            $xml = $this->docxEntry($path, 'word/document.xml');
            $this->assertStringContainsString($expected['expected_nomor_rekomendasi'], $xml);
            if ($expected['unexpected_nomor']) {
                $this->assertStringNotContainsString($expected['unexpected_nomor'], $xml);
            }
            $this->assertStringNotContainsString('${nomor_surat}', $xml);
            $this->assertStringNotContainsString('${nomor_surat_rekomendasi}', $xml);
            $this->assertStringNotContainsString('${paraf}', $xml);
            $this->assertStringNotContainsString('${ttd_kadep}', $xml);
            $this->assertStringNotContainsString('${paraf_formulir}', $xml);
            $this->assertStringNotContainsString('${paraf_rekomendasi}', $xml);
            $this->assertStringNotContainsString('${ttd_kadep_formulir}', $xml);
            $this->assertStringNotContainsString('${ttd_kadep_rekomendasi}', $xml);
            $this->assertStringNotContainsString('${', $xml);
            $this->assertStringContainsString('Jabatan: Ketua Departemen Test', $xml);
            $this->assertStringContainsString('Rekomendasi: Ketua Departemen Test', $xml);
            $this->assertStringNotContainsString('Ketua Departemen Departemen', $xml);
            $this->assertStringNotContainsString('Sekretaris Departemen Departemen', $xml);
            $this->assertStringNotContainsString('Ketua Program Studi Program Studi', $xml);
            if ($expected['includes_paraf']) {
                $this->assertStringContainsString('height:24px', $xml);
                $this->assertSame(2, substr_count($xml, 'height:24px'), "{$phase} should render both explicit paraf positions.");
                $this->assertStringNotContainsString('height:45px', $xml);
            } else {
                $this->assertSame(0, substr_count($xml, 'height:24px'), "{$phase} should not render Prodi paraf.");
            }
            $this->assertSame(
                $expected['includes_kadep_signature'] ? 3 : 1,
                substr_count($xml, 'height:80px'),
                "{$phase} Kadep TTD position count mismatch.",
            );
            $this->assertSame($expected['media_count'], $this->mediaCount($path), "{$phase} media count mismatch.");
        }

        $fresh = $application->fresh();
        $this->assertNull($fresh->nomor_surat);
        $this->assertSame([], Storage::disk('public')->files('scholarships'));
    }

    public function test_public_docx_runtime_bridge_is_removed_from_automation_service(): void
    {
        $this->assertFalse(method_exists(ScholarshipAutomationService::class, 'generateDocument'));
        $this->assertFalse(method_exists(ScholarshipAutomationService::class, 'deleteGeneratedDocument'));
    }

    /**
     * @param array<string, mixed> $applicationAttributes
     * @return array{0: ScholarshipApplication}
     */
    private function phaseApplication(array $applicationAttributes = []): array
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('public')->put('profiles/signatures/student.png', $this->pngBytes());
        Storage::disk('public')->put('profiles/signatures/kadep.png', $this->pngBytes());

        $this->tempParafPath = tempnam(sys_get_temp_dir(), 'beasiswa_paraf_') . '.png';
        file_put_contents($this->tempParafPath, $this->pngBytes());
        config(['surat.global_paraf_path' => $this->tempParafPath]);

        $department = $this->department(['name' => 'Departemen Test']);
        $program = $this->studyProgram($department, ['name' => 'Program Test']);
        [$student, $profile] = $this->completeMahasiswa([], [
            'nama_lengkap' => 'Mahasiswa Phase Test',
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'jenis_kelamin' => 'L',
            'no_hp' => '081234567890',
            'alamat_asal' => 'Alamat Asal',
            'alamat_domisili' => 'Alamat Domisili',
            'tanda_tangan_path' => 'profiles/signatures/student.png',
        ], $program);
        $profile->keluarga()->create([
            'jenis_relasi' => 'ayah',
            'nama_lengkap' => 'Ayah Phase',
            'pekerjaan' => 'Pegawai',
            'status_hidup' => 'hidup',
        ]);

        $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Ketua Departemen Test',
            'nip' => '197001011999031001',
            'signature_path' => 'profiles/signatures/kadep.png',
        ]);

        $application = $this->scholarshipApplication($student, array_merge([
            'nomor_surat' => null,
            'scholarship_name' => 'Beasiswa Phase',
            'study_level' => 'D4',
            'current_semester' => 4,
            'family_dependents' => 2,
            'gpa_last_2_semesters' => '3.80',
            'ipk' => '3.85',
            'sks_last_2_semesters' => 42,
            'total_sks_passed' => 90,
            'total_sks_required' => 144,
            'on_leave' => 'Belum',
            'thesis_status' => 'Belum',
        ], $applicationAttributes));

        return [$application];
    }

    private function phaseService(?string $templateContent = null): PhaseTemplateScholarshipAutomationService
    {
        return new PhaseTemplateScholarshipAutomationService(
            app(LetterAssignmentService::class),
            app(AcademicSignatoryService::class),
            app(MahasiswaProfileDataService::class),
            $templateContent ?? $this->templateBytes(),
        );
    }

    private function templateBytes(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Nomor rekomendasi: ${nomor_surat_rekomendasi}');
        $section->addText('Nama: ${nama}');
        $section->addText('Tanggal: ${tanggal_surat}');
        $section->addText('Tanda tangan mahasiswa: ${tanda_tangan}');
        $section->addText('Paraf formulir: ${paraf_formulir}');
        $section->addText('Paraf rekomendasi: ${paraf_rekomendasi}');
        $section->addText('Kadep: ${nama_kadep}');
        $section->addText('NIP: ${nip_kadep}');
        $section->addText('Jabatan: ${jabatan_kadep} ${departemen}');
        $section->addText('Rekomendasi: ${jabatan_kadep} ${departemen}, ${fakultas}');
        $section->addText('TTD kadep formulir: ${ttd_kadep_formulir}');
        $section->addText('TTD kadep rekomendasi: ${ttd_kadep_rekomendasi}');
        $section->addText('Foto: ${foto}');

        $tempPath = tempnam(sys_get_temp_dir(), 'beasiswa_phase_template_') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        return $content;
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }

    private function docxEntry(string $path, string $entry): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($path)) === true);
        $contents = $zip->getFromName($entry);
        $zip->close();

        $this->assertIsString($contents);

        return $contents;
    }

    private function mediaCount(string $path): int
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($path)) === true);

        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && preg_match('#^word/media/#', $name)) {
                $count++;
            }
        }

        $zip->close();

        return $count;
    }
}

class PhaseTemplateScholarshipAutomationService extends ScholarshipAutomationService
{
    public function __construct(
        LetterAssignmentService $assignmentService,
        AcademicSignatoryService $signatoryService,
        MahasiswaProfileDataService $profileDataService,
        private string $templateContent,
    ) {
        parent::__construct($assignmentService, $signatoryService, $profileDataService);
    }

    protected function fetchTemplateContent(): string|false
    {
        return $this->templateContent;
    }
}
