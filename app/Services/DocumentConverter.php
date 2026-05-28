<?php

namespace App\Services;

interface DocumentConverter
{
    /**
     * Convert a DOCX file to PDF.
     *
     * @param string $sourceDocxAbsolutePath  Existing readable DOCX file on the local filesystem.
     * @param string $destPdfAbsolutePath     Filesystem path where the rendered PDF will be written.
     *                                         The destination directory must exist; the file will be
     *                                         overwritten only after a successful conversion.
     *
     * @throws DocumentConverterException on any non-success outcome (connect/timeout/HTTP error/
     *                                    empty body/non-PDF body/write error). The destination file
     *                                    MUST NOT contain partial output if this exception is thrown.
     */
    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void;
}
