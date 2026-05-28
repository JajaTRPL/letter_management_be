<?php

namespace Tests\Feature\Workflow;

use App\Services\SuratPengantarMagangService;
use ReflectionClass;
use Tests\TestCase;

class AdministrativePdfEnterpriseContextTest extends TestCase
{
    public function test_magang_assignment_service_contains_no_legacy_pdf_generation_runtime(): void
    {
        $reflection = new ReflectionClass(SuratPengantarMagangService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertNotFalse($source);
        $this->assertFalse($reflection->hasMethod('generateDocument'));
        $this->assertFalse($reflection->hasMethod('generatedPdfStoragePath'));
        $this->assertStringNotContainsString('Barryvdh', $source);
        $this->assertStringNotContainsString('Pdf::', $source);
        $this->assertStringNotContainsString('generated_pdf_path', $source);
        $this->assertStringNotContainsString('surat-pengantar-magang/generated', $source);
    }
}
