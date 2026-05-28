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

class BeasiswaPasFotoNormalizationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private ?string $tempParafPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tempParafPath && is_file($this->tempParafPath)) {
            @unlink($this->tempParafPath);
        }

        parent::tearDown();
    }

    public function test_pas_foto_embedded_in_docx_is_bounded_to_normalized_dimensions(): void
    {
        $application = $this->seedApplicationWithOversizedPasFoto(width: 4000, height: 3000);
        $service = $this->phaseService();

        $docxPath = $service->generateDocumentForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ['nomor_surat' => 'PAS-NORM-001'],
        );

        $this->assertNotFalse($docxPath);

        [$width, $height, $bytes] = $this->extractPasFotoMedia($docxPath);

        $this->assertSame(600, $width, 'Embedded pas foto width must equal normalized target.');
        $this->assertSame(800, $height, 'Embedded pas foto height must equal normalized target.');
        $this->assertLessThan(300_000, $bytes, 'Normalized pas foto JPEG should be well under 300KB.');
    }

    public function test_pas_foto_normalization_does_not_mutate_original_uploaded_file(): void
    {
        $application = $this->seedApplicationWithOversizedPasFoto(width: 4000, height: 3000);
        $profile = $application->mahasiswaProfile;
        $originalDiskPath = $profile->pas_foto_path;
        $absolute = Storage::disk('public')->path($this->stripPrefix($originalDiskPath));
        $hashBefore = md5_file($absolute);
        $sizeBefore = filesize($absolute);

        $service = $this->phaseService();
        $service->generateDocumentForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            [],
        );

        $this->assertSame($hashBefore, md5_file($absolute), 'Original pas foto must not be modified.');
        $this->assertSame($sizeBefore, filesize($absolute));
    }

    public function test_pas_foto_temp_derivative_is_cleaned_up_after_generation(): void
    {
        $application = $this->seedApplicationWithOversizedPasFoto(width: 1200, height: 1600);
        $service = $this->phaseService();
        $tempDir = storage_path('app/temp/scholarships');

        $before = $this->listPasFotoTempFiles($tempDir);
        $service->generateDocumentForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ['nomor_surat' => 'PAS-CLEAN-001'],
        );
        $after = $this->listPasFotoTempFiles($tempDir);

        $this->assertSame($before, $after, 'Temp pas foto derivative must be cleaned up after generation.');
    }

    private function seedApplicationWithOversizedPasFoto(int $width, int $height): ScholarshipApplication
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('public')->put('profiles/signatures/student.png', $this->pngBytes());
        Storage::disk('public')->put('profiles/signatures/kadep.png', $this->pngBytes());

        $pasFotoBytes = $this->jpegBytes($width, $height);
        Storage::disk('public')->put('profiles/fotos/legacy_oversized.jpg', $pasFotoBytes);

        $this->tempParafPath = tempnam(sys_get_temp_dir(), 'beasiswa_paraf_') . '.png';
        file_put_contents($this->tempParafPath, $this->pngBytes());
        config(['surat.global_paraf_path' => $this->tempParafPath]);

        $department = $this->department(['name' => 'Departemen Test']);
        $program = $this->studyProgram($department, ['name' => 'Program Test']);
        [$student] = $this->completeMahasiswa([], [
            'nama_lengkap' => 'Mahasiswa Pas Foto',
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'jenis_kelamin' => 'L',
            'no_hp' => '081234567890',
            'alamat_asal' => 'Alamat Asal',
            'alamat_domisili' => 'Alamat Domisili',
            'pas_foto_path' => 'profiles/fotos/legacy_oversized.jpg',
            'tanda_tangan_path' => 'profiles/signatures/student.png',
        ], $program);

        $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Ketua Departemen Test',
            'nip' => '197001011999031001',
            'signature_path' => 'profiles/signatures/kadep.png',
        ]);

        return $this->scholarshipApplication($student, [
            'nomor_surat' => null,
            'scholarship_name' => 'Beasiswa Pas Foto',
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
        ]);
    }

    private function phaseService(): PhaseTemplateScholarshipAutomationService
    {
        return new PhaseTemplateScholarshipAutomationService(
            app(LetterAssignmentService::class),
            app(AcademicSignatoryService::class),
            app(MahasiswaProfileDataService::class),
            $this->templateBytes(),
        );
    }

    private function templateBytes(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Nomor rekomendasi: ${nomor_surat_rekomendasi}');
        $section->addText('Nama: ${nama}');
        $section->addText('Tanggal: ${tanggal_surat}');
        $section->addText('Tanda tangan: ${tanda_tangan}');
        $section->addText('Paraf formulir: ${paraf_formulir}');
        $section->addText('Paraf rekomendasi: ${paraf_rekomendasi}');
        $section->addText('Kadep: ${nama_kadep}');
        $section->addText('NIP: ${nip_kadep}');
        $section->addText('Jabatan: ${jabatan_kadep} ${departemen}');
        $section->addText('TTD kadep formulir: ${ttd_kadep_formulir}');
        $section->addText('TTD kadep rekomendasi: ${ttd_kadep_rekomendasi}');
        $section->addText('Foto: ${foto}');

        $tempPath = tempnam(sys_get_temp_dir(), 'beasiswa_pasfoto_template_') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        return $content;
    }

    private function jpegBytes(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, 220, 220, 240);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);
        ob_start();
        imagejpeg($img, null, 92);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function extractPasFotoMedia(string $docxPath): array
    {
        $absolute = Storage::disk('local')->path($docxPath);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($absolute) === true);

        $largest = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || !preg_match('#^word/media/.*\.(jpe?g|png)$#i', $name)) {
                continue;
            }
            $bytes = $zip->getFromIndex($i);
            if (!is_string($bytes)) {
                continue;
            }
            if ($largest === null || strlen($bytes) > strlen($largest)) {
                $largest = $bytes;
            }
        }
        $zip->close();

        $this->assertNotNull($largest, 'Expected at least one media entry in DOCX.');

        $info = getimagesizefromstring($largest);
        $this->assertNotFalse($info);

        return [(int) $info[0], (int) $info[1], strlen($largest)];
    }

    private function listPasFotoTempFiles(string $tempDir): array
    {
        if (!is_dir($tempDir)) {
            return [];
        }

        return array_values(array_filter(
            scandir($tempDir) ?: [],
            fn ($entry) => is_string($entry) && str_starts_with($entry, 'pas_foto_'),
        ));
    }

    private function stripPrefix(?string $publicPath): string
    {
        $path = (string) $publicPath;
        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        foreach (['storage/', 'api/storage/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }
        }
        return $path;
    }
}
