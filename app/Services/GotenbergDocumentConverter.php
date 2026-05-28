<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Throwable;

class GotenbergDocumentConverter implements DocumentConverter
{
    /**
     * @param string $baseUrl                Gotenberg base URL (e.g. http://localhost:3000).
     * @param int    $timeoutSeconds         Per-request timeout ceiling for the conversion call.
     * @param int    $connectTimeoutSeconds  TCP connect timeout (fail-fast on bad URL).
     */
    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        private int $timeoutSeconds = 60,
        private int $connectTimeoutSeconds = 5,
    ) {
    }

    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void
    {
        if (!is_file($sourceDocxAbsolutePath) || !is_readable($sourceDocxAbsolutePath)) {
            throw new DocumentConverterException(
                'Source DOCX is not readable.',
                ['driver' => 'gotenberg', 'source' => $sourceDocxAbsolutePath],
            );
        }

        $destDir = dirname($destPdfAbsolutePath);
        if (!is_dir($destDir)) {
            throw new DocumentConverterException(
                'Destination directory does not exist.',
                ['driver' => 'gotenberg', 'destination' => $destPdfAbsolutePath],
            );
        }

        $endpoint = rtrim($this->baseUrl, '/') . '/forms/libreoffice/convert';

        try {
            $response = $this->http
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->asMultipart()
                ->attach(
                    'files',
                    fopen($sourceDocxAbsolutePath, 'r'),
                    basename($sourceDocxAbsolutePath),
                )
                ->post($endpoint);
        } catch (ConnectionException $e) {
            throw new DocumentConverterException(
                'Document converter connection failed.',
                ['driver' => 'gotenberg', 'endpoint' => $endpoint, 'previous' => $e->getMessage()],
                $e,
            );
        } catch (Throwable $e) {
            // Catch-all for transport-level failures (DNS, socket, TLS, etc.).
            throw new DocumentConverterException(
                'Document converter transport error.',
                ['driver' => 'gotenberg', 'endpoint' => $endpoint, 'previous' => $e->getMessage()],
                $e,
            );
        }

        if (!$response->successful()) {
            throw new DocumentConverterException(
                'Document converter returned non-success status.',
                [
                    'driver' => 'gotenberg',
                    'endpoint' => $endpoint,
                    'http_status' => $response->status(),
                ],
            );
        }

        $body = $response->body();
        if ($body === '' || strncmp($body, '%PDF', 4) !== 0) {
            throw new DocumentConverterException(
                'Document converter returned an empty or non-PDF body.',
                [
                    'driver' => 'gotenberg',
                    'endpoint' => $endpoint,
                    'http_status' => $response->status(),
                    'body_size' => strlen($body),
                ],
            );
        }

        $bytes = file_put_contents($destPdfAbsolutePath, $body);
        if ($bytes === false || $bytes !== strlen($body)) {
            // Clean up a partial write before throwing.
            if (is_file($destPdfAbsolutePath)) {
                @unlink($destPdfAbsolutePath);
            }
            throw new DocumentConverterException(
                'Failed to write converted PDF to destination.',
                ['driver' => 'gotenberg', 'destination' => $destPdfAbsolutePath, 'written_bytes' => (int) $bytes],
            );
        }
    }
}
