<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Services\AcademicSignatoryService;
use App\Services\LetterAssignmentService;
use App\Services\MahasiswaProfileDataService;
use App\Services\ScholarshipAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use ReflectionMethod;
use Tests\TestCase;

class BeasiswaTemplateCacheTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private string $tempCachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCachePath = sys_get_temp_dir() . '/beasiswa_cache_test_' . uniqid('', true) . '.docx';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempCachePath)) {
            @unlink($this->tempCachePath);
        }
        parent::tearDown();
    }

    // ── Config ───────────────────────────────────────────────────────────────

    public function test_cache_path_config_key_exists_and_is_non_empty(): void
    {
        $cachePath = config('surat.template_beasiswa_cache_path');

        $this->assertNotNull($cachePath);
        $this->assertIsString($cachePath);
        $this->assertNotEmpty($cachePath);
        $this->assertArrayHasKey('template_beasiswa_cache_path', config('surat'));
    }

    public function test_service_source_reads_cache_path_from_config_not_hardcoded(): void
    {
        $source = file_get_contents(app_path('Services/ScholarshipAutomationService.php'));

        $this->assertStringContainsString("config('surat.template_beasiswa_cache_path')", $source);
    }

    public function test_cache_path_default_is_not_under_public_storage(): void
    {
        $cachePath = config('surat.template_beasiswa_cache_path');

        // Must not be under storage/app/public (that would be web-accessible)
        $this->assertStringNotContainsString('storage/app/public', str_replace('\\', '/', $cachePath));
        $this->assertStringNotContainsString('public/storage', str_replace('\\', '/', $cachePath));
    }

    // ── Cache-hit behavior ───────────────────────────────────────────────────

    public function test_fetch_template_content_returns_cache_when_file_exists(): void
    {
        $fakeDocx = $this->minimalDocxBytes();
        file_put_contents($this->tempCachePath, $fakeDocx);

        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $service = $this->makeGoogleBlockedService();
        $result = $this->callFetchTemplateContent($service);

        $this->assertNotFalse($result, 'fetchTemplateContent should return cached bytes');
        $this->assertEquals($fakeDocx, $result, 'Returned content should match the cached file');
    }

    public function test_google_fetch_not_called_when_cache_exists(): void
    {
        $fakeDocx = $this->minimalDocxBytes();
        file_put_contents($this->tempCachePath, $fakeDocx);

        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $service = $this->makeFetchTrackingService();
        $this->callFetchTemplateContent($service);

        $this->assertFalse($service->googleFetchCalled, 'Google fetch must not be called when cache file exists');
    }

    // ── Cache-miss + Google down ─────────────────────────────────────────────

    public function test_fetch_template_returns_false_when_cache_missing_and_google_down(): void
    {
        // Point config at a path that does not exist — no cache
        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $service = $this->makeGoogleBlockedService();
        $result = $this->callFetchTemplateContent($service);

        $this->assertFalse($result, 'Should return false when cache absent and Google unreachable');
    }

    // ── Full generation using cache ───────────────────────────────────────────

    public function test_generation_succeeds_from_cache_even_when_google_is_down(): void
    {
        Storage::fake('public');

        $department = $this->department(['name' => 'Test Department']);
        $program = $this->studyProgram($department);
        [$student, $profile] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student, [
            'status'      => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'BEA-CACHE-001',
        ]);
        $officialKadep = $this->akademik('kadep', ['department_id' => $department->id]);

        // Place minimal template at cache path
        $fakeDocx = $this->minimalScholarshipTemplate();
        file_put_contents($this->tempCachePath, $fakeDocx);
        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $service = $this->makeGoogleBlockedService();
        $result = $service->generateDocument($application, $officialKadep);

        $this->assertNotFalse($result, 'generateDocument should succeed using cached template');
        $this->assertTrue(
            Storage::disk('public')->exists($result),
            'Generated DOCX should be saved to public storage'
        );
    }

    public function test_generation_fails_gracefully_when_cache_absent_and_google_down(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student);

        // No cache file at config path
        config(['surat.template_beasiswa_cache_path' => $this->tempCachePath]);

        $service = $this->makeGoogleBlockedService();
        $result = $service->generateDocument($application);

        $this->assertFalse($result, 'generateDocument should return false when cache absent and Google down');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeGoogleBlockedService(): GoogleBlockedScholarshipAutomationService
    {
        return new GoogleBlockedScholarshipAutomationService(
            app(LetterAssignmentService::class),
            app(AcademicSignatoryService::class),
            app(MahasiswaProfileDataService::class),
        );
    }

    private function makeFetchTrackingService(): FetchTrackingScholarshipAutomationService
    {
        return new FetchTrackingScholarshipAutomationService(
            app(LetterAssignmentService::class),
            app(AcademicSignatoryService::class),
            app(MahasiswaProfileDataService::class),
        );
    }

    private function callFetchTemplateContent(ScholarshipAutomationService $service): string|false
    {
        $method = new ReflectionMethod($service, 'fetchTemplateContent');
        $method->setAccessible(true);
        return $method->invoke($service);
    }

    private function minimalDocxBytes(): string
    {
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText('Minimal cache test template');

        $tempPath = tempnam(sys_get_temp_dir(), 'beasiswa_cache_minimal_') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        return $content;
    }

    private function minimalScholarshipTemplate(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Nomor: ${nomor_surat}');
        $section->addText('Nama: ${nama}');
        $section->addText('Kadep: ${nama_kadep}');
        $section->addText('NIP: ${nip_kadep}');

        $tempPath = tempnam(sys_get_temp_dir(), 'beasiswa_cache_full_') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        return $content;
    }
}

/**
 * Subclass that blocks all Google fetches — simulates network unavailability.
 */
class GoogleBlockedScholarshipAutomationService extends ScholarshipAutomationService
{
    protected function fetchFromGoogle(): string|false
    {
        return false;
    }
}

/**
 * Subclass that records whether Google fetch was attempted.
 */
class FetchTrackingScholarshipAutomationService extends ScholarshipAutomationService
{
    public bool $googleFetchCalled = false;

    protected function fetchFromGoogle(): string|false
    {
        $this->googleFetchCalled = true;
        return false;
    }
}
