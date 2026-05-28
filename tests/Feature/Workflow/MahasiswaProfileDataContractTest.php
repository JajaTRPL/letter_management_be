<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\MahasiswaProfile;
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

class MahasiswaProfileDataContractTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_service_prefers_canonical_academic_data_and_derives_angkatan(): void
    {
        $department = $this->department([
            'code' => 'DTEDI',
            'name' => 'Departemen Teknik Elektro dan Informatika',
        ]);
        $program = $this->studyProgram($department, [
            'code' => 'TRPL',
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
        ]);
        [$user] = $this->completeMahasiswa([], [
            'nim' => '22/493038/SV/20654',
        ], $program);

        $data = app(MahasiswaProfileDataService::class)->forUser($user->fresh());

        $this->assertSame($program->id, $data['study_program_id']);
        $this->assertSame('TRPL', $data['study_program_code']);
        $this->assertSame('Teknologi Rekayasa Perangkat Lunak', $data['program_studi_display']);
        $this->assertSame('Departemen Teknik Elektro dan Informatika', $data['department_display']);
        $this->assertSame($department->faculty->name, $data['fakultas_display']);
        $this->assertSame('2022', $data['angkatan']);
    }

    public function test_service_returns_dashes_when_no_canonical_academic_data(): void
    {
        $user = $this->activeUser([
            'role' => 'mahasiswa',
            'study_program_id' => null,
            'department_id' => null,
        ]);
        MahasiswaProfile::create([
            'user_id' => $user->id,
            'nim' => '24/535278/SV/12345',
        ]);

        $data = app(MahasiswaProfileDataService::class)->forUser($user->fresh());

        $this->assertNull($data['study_program_id']);
        $this->assertSame('-', $data['program_studi_display']);
        $this->assertSame('-', $data['department_display']);
        $this->assertSame('-', $data['fakultas_display']);
        $this->assertSame('2024', $data['angkatan']);
    }

    public function test_normalized_academic_context_fields_default_to_null_without_academic_period(): void
    {
        // Bundle 7A defers active-academic-period math to the Academic Period bundle.
        // Until then, the normalized payload must expose null defaults for those keys
        // so callers depending on the contract shape do not break.
        $program = $this->studyProgram(null, [
            'code' => 'TRPL',
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
        ]);
        [$user] = $this->completeMahasiswa([], [
            'nim' => '22/493038/SV/20654',
        ], $program);

        $data = app(MahasiswaProfileDataService::class)->forUser($user->fresh());

        $this->assertNull($data['academic_period_id']);
        $this->assertNull($data['current_academic_year']);
        $this->assertNull($data['current_semester_type']);
        $this->assertNull($data['current_semester_order']);
        $this->assertNull($data['current_semester']);
    }

    public function test_beasiswa_generator_uses_normalized_academic_data(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $program = $this->studyProgram(null, [
            'code' => 'TRPL',
            'name' => 'Canonical Prodi',
        ]);
        $program->department->update(['name' => 'Canonical Department']);
        $program->department->faculty->update(['name' => 'Canonical Faculty']);
        [$student] = $this->completeMahasiswa([], [
            'nim' => '22/493038/SV/20654',
        ], $program);
        $this->akademik('kadep', ['department_id' => $program->department_id]);
        $application = $this->scholarshipApplication($student, [
            'current_semester' => 6,
        ]);

        $service = new TestScholarshipAutomationService(
            app(LetterAssignmentService::class),
            app(AcademicSignatoryService::class),
            app(MahasiswaProfileDataService::class)
        );
        $service->templateContent = $this->docxTemplateContent('${fakultas} ${prodi} ${angkatan}');

        $path = $service->generateDocumentForPhase(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $this->assertNotFalse($path);
        $xml = $this->docxDocumentXml(Storage::disk('local')->path($path));
        $this->assertStringContainsString('Canonical Faculty', $xml);
        $this->assertStringContainsString('Canonical Prodi', $xml);
        $this->assertStringContainsString('2022', $xml);
    }

    private function docxTemplateContent(string $text): string
    {
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText($text);
        $path = tempnam(sys_get_temp_dir(), 'template') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function docxDocumentXml(string $path): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertIsString($xml);

        return $xml;
    }
}

class TestScholarshipAutomationService extends ScholarshipAutomationService
{
    public string $templateContent = '';

    protected function fetchTemplateContent(): string|false
    {
        return $this->templateContent;
    }
}
