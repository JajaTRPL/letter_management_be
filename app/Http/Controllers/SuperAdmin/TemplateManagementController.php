<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class TemplateManagementController extends Controller
{
    private const MANAGED_TEMPLATES = [
        'surat-permohonan-beasiswa' => [
            'key'         => 'surat-permohonan-beasiswa',
            'name'        => 'Surat Permohonan Beasiswa',
            'source_type' => 'google_docs',
            'can_refresh' => true,
        ],
    ];

    public function index(): JsonResponse
    {
        $templates = [];

        foreach (self::MANAGED_TEMPLATES as $key => $meta) {
            $templates[] = $this->buildTemplateInfo($key, $meta);
        }

        return response()->json([
            'message' => 'Daftar template berhasil diambil',
            'data'    => $templates,
        ]);
    }

    public function refresh(string $key): JsonResponse
    {
        if (!isset(self::MANAGED_TEMPLATES[$key])) {
            return response()->json(['message' => 'Template tidak ditemukan'], 404);
        }

        if ($key === 'surat-permohonan-beasiswa') {
            return $this->refreshBeasiswaTemplate();
        }

        return response()->json(['message' => 'Template ini tidak dapat di-refresh melalui sistem'], 422);
    }

    private function buildTemplateInfo(string $key, array $meta): array
    {
        $cachePath   = config('surat.template_beasiswa_cache_path');
        $cacheExists = $cachePath && is_file($cachePath) && is_readable($cachePath);
        $cachedAt    = $cacheExists ? date('Y-m-d H:i:s', filemtime($cachePath)) : null;
        $sizeBytes   = $cacheExists ? filesize($cachePath) : null;
        $templateId  = config('surat.template_beasiswa_id', '');

        $maskedId = $templateId ? ('...' . substr($templateId, -8)) : null;

        return array_merge($meta, [
            'template_id_masked' => $maskedId,
            'cache_path_display' => $cachePath ? 'storage/app/templates/' . basename((string) $cachePath) : null,
            'cache_exists'       => $cacheExists,
            'cached_at'          => $cachedAt,
            'size_bytes'         => $sizeBytes,
        ]);
    }

    private function refreshBeasiswaTemplate(): JsonResponse
    {
        $templateId = config('surat.template_beasiswa_id');
        $cachePath  = config('surat.template_beasiswa_cache_path');

        if (!$templateId) {
            return response()->json(['message' => 'Template ID belum dikonfigurasi'], 422);
        }

        if (!$cachePath) {
            return response()->json(['message' => 'Cache path belum dikonfigurasi'], 422);
        }

        $content = $this->fetchFromGoogleDocx();

        if ($content === false || strlen($content) === 0) {
            return response()->json([
                'message' => 'Gagal mengambil template dari Google Docs. Pastikan dokumen dapat diakses publik.',
            ], 502);
        }

        if (!str_starts_with($content, 'PK')) {
            return response()->json([
                'message' => 'File yang diunduh bukan DOCX yang valid (PK header tidak ditemukan).',
            ], 422);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'beasiswa_refresh_') . '.docx';
        file_put_contents($tempFile, $content);

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            return response()->json([
                'message' => 'File yang diunduh tidak dapat dibuka sebagai ZIP/DOCX.',
            ], 422);
        }
        $zip->close();

        $cacheDir = dirname((string) $cachePath);
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            @unlink($tempFile);
            return response()->json(['message' => 'Gagal membuat direktori cache'], 500);
        }

        if (@rename($tempFile, (string) $cachePath) === false) {
            if (@copy($tempFile, (string) $cachePath) === false) {
                @unlink($tempFile);
                return response()->json(['message' => 'Gagal menyimpan file cache'], 500);
            }
            @unlink($tempFile);
        }

        Log::info('Beasiswa template cache refreshed', [
            'size' => strlen($content),
            'by'   => auth()->id(),
        ]);

        return response()->json([
            'message'    => 'Cache template berhasil diperbarui',
            'cached_at'  => date('Y-m-d H:i:s', filemtime((string) $cachePath)),
            'size_bytes' => strlen($content),
        ]);
    }

    protected function fetchFromGoogleDocx(): string|false
    {
        $templateId = config('surat.template_beasiswa_id');
        if (!$templateId) {
            return false;
        }

        $url     = "https://docs.google.com/document/d/{$templateId}/export?format=docx";
        $options = [
            'http' => [
                'follow_location' => true,
                'max_redirects'   => 5,
                'header'          => "User-Agent: Mozilla/5.0\r\n",
                'timeout'         => 30,
            ],
        ];

        return @file_get_contents($url, false, stream_context_create($options));
    }
}
