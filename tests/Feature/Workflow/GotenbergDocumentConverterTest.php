<?php

namespace Tests\Feature\Workflow;

use App\Services\DocumentConverterException;
use App\Services\GotenbergDocumentConverter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GotenbergDocumentConverterTest extends TestCase
{
    private string $tempDir;
    private string $sourceDocx;
    private string $destPdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/dtedi-converter-tests-' . uniqid('', true);
        @mkdir($this->tempDir, 0775, true);

        $this->sourceDocx = $this->tempDir . '/source.docx';
        $this->destPdf = $this->tempDir . '/out.pdf';

        // Minimal DOCX placeholder — the converter doesn't parse the bytes;
        // it just streams them. Real DOCX content is exercised end-to-end in
        // separate sandbox spike scripts, not unit tests.
        file_put_contents($this->sourceDocx, "PK\x03\x04 fake docx payload for unit test");
    }

    protected function tearDown(): void
    {
        @unlink($this->sourceDocx);
        @unlink($this->destPdf);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function converter(): GotenbergDocumentConverter
    {
        return new GotenbergDocumentConverter(
            $this->app->make(HttpFactory::class),
            'http://gotenberg.test',
            60,
            5,
        );
    }

    public function test_successful_conversion_writes_pdf_payload(): void
    {
        Http::fake([
            'gotenberg.test/forms/libreoffice/convert' => Http::response('%PDF-1.4 fake body', 200),
        ]);

        $this->converter()->convertDocxToPdf($this->sourceDocx, $this->destPdf);

        $this->assertFileExists($this->destPdf);
        $this->assertSame('%PDF-1.4 fake body', file_get_contents($this->destPdf));
    }

    public function test_non_2xx_response_throws_and_does_not_write_pdf(): void
    {
        Http::fake([
            'gotenberg.test/forms/libreoffice/convert' => Http::response('boom', 502),
        ]);

        try {
            $this->converter()->convertDocxToPdf($this->sourceDocx, $this->destPdf);
            $this->fail('Expected DocumentConverterException');
        } catch (DocumentConverterException $e) {
            $this->assertStringContainsString('non-success', $e->getMessage());
            $this->assertSame(502, $e->context['http_status'] ?? null);
        }

        $this->assertFileDoesNotExist($this->destPdf);
    }

    public function test_empty_or_non_pdf_body_throws(): void
    {
        Http::fake([
            'gotenberg.test/forms/libreoffice/convert' => Http::response('this is not a pdf', 200),
        ]);

        try {
            $this->converter()->convertDocxToPdf($this->sourceDocx, $this->destPdf);
            $this->fail('Expected DocumentConverterException');
        } catch (DocumentConverterException $e) {
            $this->assertStringContainsString('empty or non-PDF', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->destPdf);
    }

    public function test_connection_exception_is_wrapped(): void
    {
        Http::fake([
            'gotenberg.test/forms/libreoffice/convert' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        try {
            $this->converter()->convertDocxToPdf($this->sourceDocx, $this->destPdf);
            $this->fail('Expected DocumentConverterException');
        } catch (DocumentConverterException $e) {
            $this->assertStringContainsString('connection failed', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->destPdf);
    }

    public function test_missing_source_file_throws_before_any_http(): void
    {
        Http::fake();

        $missing = $this->tempDir . '/does-not-exist.docx';

        try {
            $this->converter()->convertDocxToPdf($missing, $this->destPdf);
            $this->fail('Expected DocumentConverterException');
        } catch (DocumentConverterException $e) {
            $this->assertStringContainsString('Source DOCX', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_missing_destination_directory_throws_before_any_http(): void
    {
        Http::fake();
        $bogus = $this->tempDir . '/nope-' . uniqid() . '/out.pdf';

        try {
            $this->converter()->convertDocxToPdf($this->sourceDocx, $bogus);
            $this->fail('Expected DocumentConverterException');
        } catch (DocumentConverterException $e) {
            $this->assertStringContainsString('Destination directory', $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
