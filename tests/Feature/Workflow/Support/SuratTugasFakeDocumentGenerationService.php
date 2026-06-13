<?php

namespace Tests\Feature\Workflow\Support;

use App\Models\SuratTugasApplication;
use App\Services\SuratTugasDocumentGenerationService;
use Illuminate\Support\Facades\Storage;

/**
 * Test double that writes a real private DOCX without touching the template
 * engine, so the canonical SuratTugasPreviewGenerationService can produce a
 * genuine READY artifact + PDF (via SuratTugasFakeDocumentConverter) on the
 * faked local disk. Autoloaded (PSR-4 Tests\) so it is safe to use regardless
 * of which test file triggers the run.
 */
class SuratTugasFakeDocumentGenerationService extends SuratTugasDocumentGenerationService
{
    public int $calls = 0;

    public function __construct()
    {
    }

    public function generateDocumentForPhase(
        SuratTugasApplication $application,
        string $phase,
        array $overrides = [],
    ): string {
        $this->calls++;

        $path = 'letter-document-artifacts/'
            . SuratTugasApplication::LETTER_TYPE
            . '/'
            . $application->getKey()
            . '/'
            . $phase
            . '/source_fake_'
            . $this->calls
            . '.docx';
        Storage::disk('local')->put($path, 'fake surat tugas docx');

        return $path;
    }
}
