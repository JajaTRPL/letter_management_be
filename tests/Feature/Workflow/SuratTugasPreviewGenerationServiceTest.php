<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratTugasApplication;
use App\Services\DocumentConverter;
use App\Services\DocumentConverterException;
use App\Services\LetterDocumentArtifactService;
use App\Services\LetterDocumentSourceHashService;
use App\Services\SuratTugasDocumentGenerationService;
use App\Services\SuratTugasPhaseResolver;
use App\Services\SuratTugasPreviewGenerationException;
use App\Services\SuratTugasPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuratTugasPreviewGenerationServiceTest extends TestCase
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

    public function test_generates_ready_artifact_for_current_phase_without_app_mutation(): void
    {
        $application = $this->previewApplication(SuratTugasApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat_tugas' => 'ST/001/2026',
            'tendik_approved_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        $generator = new FakeSuratTugasDocGen();
        $converter = new FakeSuratTugasConverter();

        $artifact = $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());

        $this->assertSame(LetterDocumentArtifact::STATUS_READY, $artifact->status);
        $this->assertSame(SuratTugasApplication::LETTER_TYPE, $artifact->letter_type);
        $this->assertSame($application->id, $artifact->application_id);
        $this->assertSame(LetterDocumentArtifact::PHASE_PRODI_REVIEW, $artifact->phase);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact->source_hash);
        $this->assertStringStartsWith(
            'letter-document-artifacts/surat-tugas/' . $application->id . '/prodi_review/source_',
            $artifact->docx_path,
        );
        $this->assertStringStartsWith(
            'letter-document-artifacts/surat-tugas/' . $application->id . '/prodi_review/preview_',
            $artifact->pdf_path,
        );
        $this->assertTrue(Storage::disk('local')->exists($artifact->docx_path));
        $this->assertTrue(Storage::disk('local')->exists($artifact->pdf_path));
        $this->assertSame(1, $generator->calls);
        $this->assertSame(1, $converter->calls);

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('ST/001/2026', $fresh->nomor_surat_tugas);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_existing_ready_artifact_with_same_hash_is_returned_without_regeneration(): void
    {
        $application = $this->previewApplication(SuratTugasApplication::STATUS_SUBMITTED);
        $ready = $this->service()->generateForCurrentPhase($application->fresh());

        $generator = new FakeSuratTugasDocGen();
        $converter = new FakeSuratTugasConverter();
        $artifact = $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());

        $this->assertTrue($ready->is($artifact));
        $this->assertSame(0, $generator->calls);
        $this->assertSame(0, $converter->calls);
        $this->assertSame(1, LetterDocumentArtifact::query()->count());
    }

    public function test_converter_failure_marks_failed_cleans_up_and_does_not_mutate_application(): void
    {
        $application = $this->previewApplication(SuratTugasApplication::STATUS_APPROVED_TENDIK, [
            'nomor_surat_tugas' => 'ST/001/2026',
        ]);
        $generator = new FakeSuratTugasDocGen();
        $converter = new FakeSuratTugasConverter();
        $converter->fail = true;
        $converter->writePartialBeforeFail = true;

        try {
            $this->service($generator, $converter)->generateForCurrentPhase($application->fresh());
            $this->fail('Expected SuratTugasPreviewGenerationException.');
        } catch (SuratTugasPreviewGenerationException $e) {
            // expected
        }

        $artifact = LetterDocumentArtifact::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($artifact);
        $this->assertSame(LetterDocumentArtifact::STATUS_FAILED, $artifact->status);
        // Partial PDF cleaned up.
        if ($converter->lastDestination !== null) {
            $this->assertFileDoesNotExist($converter->lastDestination);
        }
        // No application/workflow mutation.
        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame('ST/001/2026', $fresh->nomor_surat_tugas);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function service(
        ?FakeSuratTugasDocGen $generator = null,
        ?FakeSuratTugasConverter $converter = null,
        int $lockWaitSeconds = 10,
    ): SuratTugasPreviewGenerationService {
        return new SuratTugasPreviewGenerationService(
            app(SuratTugasPhaseResolver::class),
            app(LetterDocumentSourceHashService::class),
            app(LetterDocumentArtifactService::class),
            $generator ?? new FakeSuratTugasDocGen(),
            $converter ?? new FakeSuratTugasConverter(),
            120,
            $lockWaitSeconds,
        );
    }

    private function previewApplication(string $status, array $attributes = []): SuratTugasApplication
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $this->akademik('kadep', [
            'department_id' => $department->id,
            'name' => 'Kadep Tugas Preview',
            'nip' => '196501011990031001',
        ]);

        return $this->suratTugasApplication($student, array_merge([
            'status' => $status,
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
        ], $attributes));
    }
}

class FakeSuratTugasDocGen extends SuratTugasDocumentGenerationService
{
    public int $calls = 0;
    public bool $fail = false;

    public function __construct()
    {
    }

    public function generateDocumentForPhase(
        SuratTugasApplication $application,
        string $phase,
        array $overrides = [],
    ): string {
        $this->calls++;
        if ($this->fail) {
            throw new \RuntimeException('fake Surat Tugas DOCX generation failed');
        }

        $path = 'letter-document-artifacts/'
            . SuratTugasApplication::LETTER_TYPE
            . '/' . $application->id . '/' . $phase . '/source_fake_' . $this->calls . '.docx';
        Storage::disk('local')->put($path, 'fake surat tugas docx');

        return $path;
    }
}

class FakeSuratTugasConverter implements DocumentConverter
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
            throw new DocumentConverterException('fake Surat Tugas conversion failed');
        }

        file_put_contents($destPdfAbsolutePath, '%PDF-1.4 fake surat tugas pdf');
    }
}
