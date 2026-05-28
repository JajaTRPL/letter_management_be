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

class MissingKadepGuardTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_beasiswa_private_mahasiswa_review_generation_returns_false_without_current_kadep(): void
    {
        Storage::fake('public');
        Storage::fake('local');

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

        $result = $service->generateDocumentForPhase(
            $application,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        $this->assertFalse($result, 'Beasiswa private phase generation must not produce a document when no current Kadep exists');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles('scholarships'));
    }

    private function minimalScholarshipTemplate(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Nomor rekomendasi: ${nomor_surat_rekomendasi}');
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
