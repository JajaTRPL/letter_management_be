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
            'key'                      => 'surat-permohonan-beasiswa',
            'name'                     => 'Surat Permohonan Beasiswa',
            'category'                 => 'Surat Beasiswa',
            'source_type'              => 'google_docs',
            'can_refresh'              => true,
            'template_id_config_key'   => 'template_beasiswa_id',
            'cache_path_config_key'    => 'template_beasiswa_cache_path',
        ],
        'surat-keterangan-aktif' => [
            'key'                      => 'surat-keterangan-aktif',
            'name'                     => 'Surat Keterangan Aktif',
            'category'                 => 'Surat Keaktifan',
            'source_type'              => 'google_docs',
            'can_refresh'              => true,
            'template_id_config_key'   => 'template_surat_keterangan_aktif_id',
            'cache_path_config_key'    => 'template_surat_keterangan_aktif_cache_path',
        ],
        'proses-luar-negeri' => [
            'key'                      => 'proses-luar-negeri',
            'name'                     => 'Proses Luar Negeri',
            'category'                 => 'Surat Luar Negeri',
            'source_type'              => 'google_docs',
            'can_refresh'              => true,
            'template_id_config_key'   => 'template_proses_luar_negeri_id',
            'cache_path_config_key'    => 'template_proses_luar_negeri_cache_path',
        ],
        'surat-pengantar-magang' => [
            'key'                      => 'surat-pengantar-magang',
            'name'                     => 'Surat Pengantar Magang',
            'category'                 => 'Surat Magang',
            'source_type'              => 'google_docs',
            'can_refresh'              => true,
            'template_id_config_key'   => 'template_surat_pengantar_magang_id',
            'cache_path_config_key'    => 'template_surat_pengantar_magang_cache_path',
        ],
        'surat-tugas' => [
            'key'                      => 'surat-tugas',
            'name'                     => 'Surat Tugas',
            'category'                 => 'Surat Tugas',
            'source_type'              => 'google_docs',
            'can_refresh'              => true,
            'template_id_config_key'   => 'template_surat_tugas_id',
            'cache_path_config_key'    => 'template_surat_tugas_cache_path',
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

        return $this->refreshTemplate($key);
    }

    private function buildTemplateInfo(string $key, array $meta): array
    {
        $cachePath   = config('surat.' . $meta['cache_path_config_key']);
        $cacheExists = $cachePath && is_file($cachePath) && is_readable($cachePath);
        $cachedAt    = $cacheExists ? date('Y-m-d H:i:s', filemtime($cachePath)) : null;
        $sizeBytes   = $cacheExists ? filesize($cachePath) : null;
        $templateId  = config('surat.' . $meta['template_id_config_key'], '');

        $maskedId = $templateId ? ('...' . substr($templateId, -8)) : null;

        return array_merge($meta, [
            'template_id_masked' => $maskedId,
            'cache_path_display' => $cachePath ? 'storage/app/templates/' . basename((string) $cachePath) : null,
            'cache_exists'       => $cacheExists,
            'cached_at'          => $cachedAt,
            'size_bytes'         => $sizeBytes,
        ]);
    }

    private function refreshTemplate(string $key): JsonResponse
    {
        $meta = self::MANAGED_TEMPLATES[$key];
        $templateId = config('surat.' . $meta['template_id_config_key']);
        $cachePath  = config('surat.' . $meta['cache_path_config_key']);

        if (!$templateId) {
            return response()->json(['message' => 'Template ID belum dikonfigurasi'], 422);
        }

        if (!$cachePath) {
            return response()->json(['message' => 'Cache path belum dikonfigurasi'], 422);
        }

        $content = $this->fetchFromGoogleDocx((string) $templateId);

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

        $tempFile = tempnam(sys_get_temp_dir(), str_replace('-', '_', $key) . '_refresh_') . '.docx';
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

        Log::info('Template cache refreshed', [
            'key'  => $key,
            'size' => strlen($content),
            'by'   => auth()->id(),
        ]);

        return response()->json([
            'message'    => 'Cache template berhasil diperbarui',
            'cached_at'  => date('Y-m-d H:i:s', filemtime((string) $cachePath)),
            'size_bytes' => strlen($content),
        ]);
    }

    protected function fetchFromGoogleDocx(string $templateId): string|false
    {
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
