<?php

namespace Tests\Feature\Workflow\Support;

use App\Services\DocumentConverter;

/**
 * Writes a real (fake-content) PDF so READY artifacts exist on the faked local
 * disk for preview/download/complete tests. Autoloaded (PSR-4 Tests\).
 */
class SuratTugasFakeDocumentConverter implements DocumentConverter
{
    public int $calls = 0;

    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void
    {
        $this->calls++;
        file_put_contents($destPdfAbsolutePath, '%PDF-1.4 fake surat tugas pdf');
    }
}
