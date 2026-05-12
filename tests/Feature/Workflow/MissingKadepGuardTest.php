<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Services\AcademicSignatoryService;
use App\Services\LetterAssignmentService;
use App\Services\MahasiswaProfileDataService;
use App\Services\ProsesLuarNegeriService;
use App\Services\ScholarshipAutomationService;
use App\Services\SuratKeteranganAktifService;
use App\Services\SuratPengantarMagangService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use Tests\TestCase;

class MissingKadepGuardTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_magang_generate_document_blocks_when_no_current_kadep(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'MAG-GUARD-001',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ketua Departemen aktif');

        app(SuratPengantarMagangService::class)->generateDocument($application);

        $this->assertSame([], Storage::disk('public')->allFiles('surat-pengantar-magang/generated'));
    }

    public function test_aktif_generate_document_blocks_when_no_current_kadep(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'SKA-GUARD-001',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ketua Departemen aktif');

        app(SuratKeteranganAktifService::class)->generateDocument($application);

        $this->assertSame([], Storage::disk('public')->allFiles('surat-keterangan-aktif/generated'));
    }

    public function test_pln_generate_document_blocks_when_no_current_kadep(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'PLN-GUARD-001',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ketua Departemen aktif');

        app(ProsesLuarNegeriService::class)->generateDocument($application);

        $this->assertSame([], Storage::disk('public')->allFiles('proses-luar-negeri/generated'));
    }

    public function test_beasiswa_generate_document_returns_false_and_does_not_create_file_when_no_current_kadep(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'BEA-GUARD-001',
        ]);

        $service = new GuardTestScholarshipAutomationService(
            app(LetterAssignmentService::class),
            app(AcademicSignatoryService::class),
            app(MahasiswaProfileDataService::class),
        );
        $service->templateContent = $this->minimalScholarshipTemplate();

        $result = $service->generateDocument($application);

        $this->assertFalse($result, 'Beasiswa generateDocument must not produce a document when no current Kadep exists');
        $this->assertSame([], Storage::disk('public')->allFiles('scholarships'));
    }

    public function test_magang_generate_document_succeeds_once_official_kadep_is_seeded(): void
    {
        Storage::fake('public');

        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $this->akademik('kadep', ['department_id' => $department->id]);

        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'MAG-OK-001',
        ]);

        $result = app(SuratPengantarMagangService::class)->generateDocument($application);

        $this->assertStringStartsWith('/storage/surat-pengantar-magang/generated/', $result);
    }

    private function minimalScholarshipTemplate(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Nomor: ${nomor_surat}');
        $section->addText('Nama: ${nama}');

        $tempPath = tempnam(sys_get_temp_dir(), 'beasiswa_guard_') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        return $content;
    }
}

/**
 * Subclass that injects a known template, so the guard test reaches the Kadep check
 * instead of failing earlier on Google fetch.
 */
class GuardTestScholarshipAutomationService extends ScholarshipAutomationService
{
    public string $templateContent = '';

    protected function fetchTemplateContent(): string|false
    {
        return $this->templateContent;
    }
}
