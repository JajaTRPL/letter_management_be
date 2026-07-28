<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$schema = (string) getenv('C7B3_PG_SCHEMA');
$barrier = (string) getenv('C7B3_BARRIER_DIR');
$worker = (string) getenv('C7B3_WORKER_ID');
$resultPath = $barrier."/result-{$worker}.json";
$uploadPath = $barrier."/upload-{$worker}.pdf";

try {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.search_path' => $schema,
        'filesystems.disks.local.root' => (string) getenv('C7B3_STORAGE_ROOT').'/private',
    ]);
    DB::purge('pgsql');

    file_put_contents($uploadPath, (string) getenv('C7B3_PDF_BYTES'));
    file_put_contents($barrier."/ready-{$worker}", 'ready');

    $deadline = microtime(true) + 15;
    while (! is_file($barrier.'/go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrency barrier timed out.');
        }
        usleep(10_000);
    }

    $request = Request::create(
        '/api/mahasiswa/peminjaman-ruangan/requests',
        'POST',
        [
            'idempotency_key' => (string) getenv('C7B3_IDEMPOTENCY_KEY'),
            'room_id' => (int) getenv('C7B3_ROOM_ID'),
            'activity_name' => 'PostgreSQL concurrent submission',
            'purpose' => 'Deterministic overlap proof.',
            'participant_count' => 10,
            'start_at' => (string) getenv('C7B3_START_AT'),
            'end_at' => (string) getenv('C7B3_END_AT'),
        ],
        [],
        [
            'surat_peminjaman_pdf' => new UploadedFile(
                $uploadPath,
                'surat-peminjaman.pdf',
                'application/pdf',
                UPLOAD_ERR_OK,
                true,
            ),
        ],
        [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.getenv('C7B3_AUTH_TOKEN'),
            'REMOTE_ADDR' => '127.0.0.1',
        ],
    );

    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    file_put_contents($resultPath, json_encode([
        'status' => $response->getStatusCode(),
        'replay' => $response->headers->get('Idempotent-Replay'),
        'body' => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'worker_error' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
} finally {
    if (is_file($uploadPath)) {
        unlink($uploadPath);
    }
}
