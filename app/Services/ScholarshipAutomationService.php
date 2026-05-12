<?php

namespace App\Services;

use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ScholarshipAutomationService
{
    public function __construct(
        private LetterAssignmentService $assignmentService,
        private AcademicSignatoryService $signatoryService,
        private MahasiswaProfileDataService $profileDataService
    )
    {
    }

    /**
     * Assign the application to an active Tendik Persuratan responsible for Beasiswa letters.
     */
    public function assignApplication(ScholarshipApplication $application): ?User
    {
        return $this->assignmentService->assignToEligibleTendik($application, ScholarshipApplication::LETTER_TYPE);
    }

    /**
     * Generate populated DOCX from Google Docs template.
     * The optional user is the approval actor; visible signatories are resolved from current official role holders.
     */
    public function generateDocument(ScholarshipApplication $application, ?User $finalApprover = null): string|false
    {
        $tempTemplatePath = null;
        $tempOutputPath = null;
        $publicPath = null;

        try {
            $templateContent = $this->fetchTemplateContent();

            if ($templateContent === false) {
                Log::error("Failed to download template from Google Docs for application #{$application->id}");
                return false;
            }

            $tempDirectory = storage_path('app/temp/scholarships');
            if (!is_dir($tempDirectory) && !mkdir($tempDirectory, 0775, true) && !is_dir($tempDirectory)) {
                throw new RuntimeException("Unable to create temporary scholarship directory.");
            }

            $tempTemplatePath = $tempDirectory . DIRECTORY_SEPARATOR . 'template_' . $application->id . '_' . uniqid('', true) . '.docx';
            if (file_put_contents($tempTemplatePath, $templateContent) === false) {
                throw new RuntimeException("Unable to write temporary scholarship template.");
            }

            // Load relations
            $application->load([
                'mahasiswaProfile.keluarga',
                'mahasiswaProfile.scholarshipHistories',
                'user.studyProgram.department.faculty',
                'user.department.faculty',
            ]);
            $studentData = $this->profileDataService->forApplication($application);
            $profile = $application->mahasiswaProfile;
            $family = $profile->keluarga;
            $officialKadep = $this->signatoryService->officialKadepForApplication($application);

            if (!$officialKadep) {
                throw new RuntimeException('Dokumen Beasiswa tidak dapat dibuat: belum ada Ketua Departemen aktif untuk program studi mahasiswa.');
            }

            $templateProcessor = new TemplateProcessor($tempTemplatePath);

            // Fetch all variables to fix potential split tags issue (common in Google Docs exports)
            // TemplateProcessor handles this internally but sometimes needs explicit values.

            // 1. Data Utama & Akademik
            $templateProcessor->setValue('nama', $studentData['name']);
            $templateProcessor->setValue('nim', $studentData['nim']);
            $templateProcessor->setValue('fakultas', $studentData['fakultas_display']);
            $templateProcessor->setValue('prodi', $studentData['program_studi_display']);
            $templateProcessor->setValue('email', $studentData['email']);
            
            $templateProcessor->setValue('ipk', (string)($application->ipk ?? '-'));
            $templateProcessor->setValue('ipk_total', (string)($application->ipk ?? '-'));
            $templateProcessor->setValue('ip_2', (string)($application->gpa_last_2_semesters ?? '-'));
            $templateProcessor->setValue('sks_2', (string)($application->sks_last_2_semesters ?? '-'));
            $templateProcessor->setValue('sksk', (string)($application->total_sks_passed ?? '-'));
            $templateProcessor->setValue('sks_total', (string)($application->total_sks_required ?? '-'));
            
            $templateProcessor->setValue('semester', (string)($application->current_semester ?? '-'));
            $templateProcessor->setValue('jenjang', $application->study_level ?? 'D4');
            $templateProcessor->setValue('angkatan', $studentData['angkatan']);
            $templateProcessor->setValue('tanggungan', (string)($application->family_dependents ?? '-'));

            $templateProcessor->setValue('cuti_status', $application->on_leave ?? 'Belum');
            $templateProcessor->setValue('cuti', $application->on_leave ?? 'Belum');
            $templateProcessor->setValue('skripsi_status', $application->thesis_status ?? 'Belum');
            $templateProcessor->setValue('rencana_ujian', $application->exam_plan_date
                ? $this->indonesianDate($application->exam_plan_date)
                : '-');
            $templateProcessor->setValue('beasiswa_nama', $application->scholarship_name ?? '-');
            $templateProcessor->setValue('nomor_surat', $application->nomor_surat ?: '-');
            $templateProcessor->setValue('nama_kadep', $officialKadep?->name ?? '-');
            $templateProcessor->setValue('nip_kadep', $this->signatoryService->nipLikeValue($officialKadep));
            $templateProcessor->setValue('jabatan_kadep', 'Ketua Departemen');
            $templateProcessor->setValue('departemen', $studentData['department_display'] ?? '-');
            $templateProcessor->setValue('tanggal_surat', $this->indonesianDate());

            // 2. TTL & Alamat
            $templateProcessor->setValue('tempat_lahir', $studentData['tempat_lahir'] ?? '-');
            $templateProcessor->setValue('tanggal_lahir', $studentData['tanggal_lahir'] ? $this->indonesianDate($studentData['tanggal_lahir']) : '-');
            $templateProcessor->setValue('jenis_kelamin', $studentData['jenis_kelamin'] === 'L' ? 'Laki-laki' : ($studentData['jenis_kelamin'] === 'P' ? 'Perempuan' : '-'));
            $templateProcessor->setValue('no_hp', $studentData['no_hp'] ?? '-');
            $templateProcessor->setValue('alamat_asal', $studentData['alamat_asal'] ?? '-');
            $templateProcessor->setValue('alamat_domisili', $studentData['alamat_domisili'] ?? '-');

            // 3. Riwayat Beasiswa
            $histories = $profile->scholarshipHistories;
            $hCount = $histories->count();
            $templateProcessor->setValue('h_status_teks', $hCount > 0 ? 'Pernah' : 'Belum Pernah');
            if ($hCount > 0) {
                $templateProcessor->cloneRow('h_sumber', $hCount);
                $i = 1;
                foreach ($histories as $h) {
                    $templateProcessor->setValue('h_no#' . $i, $i . '.');
                    $templateProcessor->setValue('h_sumber#' . $i, $h->nama_beasiswa ?? '-');
                    $templateProcessor->setValue('h_periode#' . $i, $h->periode ?? '-');
                    $templateProcessor->setValue('h_nominal#' . $i, is_numeric($h->jumlah) ? number_format((float)$h->jumlah, 0, ',', '.') : ($h->jumlah ?? '-'));
                    $templateProcessor->setValue('h_masih#' . $i, $h->status ?? '-');
                    $i++;
                }
            } else {
                $templateProcessor->setValue('h_no', '-');
                foreach (['h_sumber', 'h_periode', 'h_nominal', 'h_masih'] as $key) {
                    $templateProcessor->setValue($key, '-');
                }
            }

            // 4. Data Keluarga
            $this->mapFamilyData($templateProcessor, $family);

            // 5. Data Saudara
            $siblings = $family->where('jenis_relasi', 'saudara');
            if ($siblings->count() > 0) {
                $templateProcessor->cloneRow('s_nama', $siblings->count());
                $i = 1;
                foreach ($siblings as $sib) {
                    $templateProcessor->setValue('s_no#' . $i, (string)$i);
                    $templateProcessor->setValue('s_nama#' . $i, $sib->nama_lengkap);
                    $templateProcessor->setValue('s_kerja#' . $i, $sib->pekerjaan ?? '-');
                    $templateProcessor->setValue('s_status#' . $i, $sib->status_kawin ?? '-');
                    $templateProcessor->setValue('s_ket#' . $i, $sib->keterangan ?? '-');
                    $i++;
                }
            } else {
                foreach(['s_no', 's_nama', 's_kerja', 's_status', 's_ket'] as $key) $templateProcessor->setValue($key, '-');
            }

            // 6. Gambar
            $this->mapImages($templateProcessor, $profile, $officialKadep);

            // 7. Save
            $filename = 'scholarship_application_' . $application->id . '_' . time() . '_' . str_replace('.', '', uniqid('', true)) . '.docx';
            $publicPath = 'scholarships/' . $filename;
            
            if (!Storage::disk('public')->exists('scholarships')) {
                Storage::disk('public')->makeDirectory('scholarships');
            }

            $tempOutputPath = $tempDirectory . DIRECTORY_SEPARATOR . 'generated_' . $application->id . '_' . uniqid('', true) . '.docx';
            $templateProcessor->saveAs($tempOutputPath);

            if (!file_exists($tempOutputPath) || filesize($tempOutputPath) <= 0) {
                throw new RuntimeException("Generated scholarship document is empty or missing.");
            }

            $savePath = Storage::disk('public')->path($publicPath);
            if (!@rename($tempOutputPath, $savePath)) {
                if (!@copy($tempOutputPath, $savePath)) {
                    throw new RuntimeException("Unable to move generated scholarship document into public storage.");
                }
                @unlink($tempOutputPath);
            }

            if (!Storage::disk('public')->exists($publicPath)) {
                throw new RuntimeException("Generated scholarship document was not saved.");
            }

            return $publicPath;

        } catch (\Exception $e) {
            if ($publicPath && Storage::disk('public')->exists($publicPath)) {
                Storage::disk('public')->delete($publicPath);
            }

            Log::error("Error generating document: " . $e->getMessage());
            return false;
        } finally {
            foreach ([$tempTemplatePath, $tempOutputPath] as $tempPath) {
                if ($tempPath && file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }
    }

    public function deleteGeneratedDocument(?string $path): void
    {
        $publicPath = $this->normalizeGeneratedDocumentPath($path);
        if ($publicPath && Storage::disk('public')->exists($publicPath)) {
            Storage::disk('public')->delete($publicPath);
        }
    }

    protected function fetchTemplateContent(): string|false
    {
        $cachePath = config('surat.template_beasiswa_cache_path');

        // Use local cache when available — avoids live Google dependency on every generation
        if ($cachePath && is_file($cachePath) && is_readable($cachePath)) {
            $cached = file_get_contents($cachePath);
            if ($cached !== false && strlen($cached) > 0) {
                return $cached;
            }
        }

        // Cache miss — fetch from Google Docs
        $content = $this->fetchFromGoogle();

        if ($content === false || strlen($content) === 0) {
            return false;
        }

        // Basic DOCX validation: DOCX is a ZIP archive that starts with "PK"
        if (!str_starts_with($content, 'PK')) {
            Log::warning('Template fetched from Google Docs does not appear to be a valid DOCX (missing PK header).');
            return false;
        }

        // Persist to cache for future generations
        if ($cachePath) {
            $cacheDir = dirname($cachePath);
            if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
                Log::warning("Unable to create template cache directory: {$cacheDir}");
            } else {
                @file_put_contents($cachePath, $content);
            }
        }

        return $content;
    }

    protected function fetchFromGoogle(): string|false
    {
        $templateId = config('surat.template_beasiswa_id', '1wnQYvwVO45M3LDDLEitsfjMFgkwj9S7f');
        $url = "https://docs.google.com/document/d/{$templateId}/export?format=docx";

        $options = [
            'http' => [
                'follow_location' => true,
                'max_redirects' => 5,
                'header' => "User-Agent: Mozilla/5.0\r\n",
            ],
        ];
        $context = stream_context_create($options);

        return @file_get_contents($url, false, $context);
    }

    private function indonesianDate(mixed $date = null): string
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret',    4 => 'April',
            5 => 'Mei',     6 => 'Juni',      7 => 'Juli',     8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        if ($date instanceof \DateTimeInterface) {
            $d = (int) $date->format('d');
            $m = (int) $date->format('n');
            $y = (int) $date->format('Y');
        } elseif ($date !== null) {
            // Use PHP native date() so strtotime and formatting share the same timezone.
            $timestamp = strtotime((string) $date);
            if ($timestamp === false) {
                return '-';
            }
            $d = (int) date('d', $timestamp);
            $m = (int) date('n', $timestamp);
            $y = (int) date('Y', $timestamp);
        } else {
            // No-arg: use Carbon::now() so Carbon::setTestNow() works in tests.
            $now = Carbon::now();
            $d   = (int) $now->format('d');
            $m   = (int) $now->month;
            $y   = (int) $now->format('Y');
        }

        return sprintf('%02d', $d) . ' ' . $months[$m] . ' ' . $y;
    }

    private function normalizeGeneratedDocumentPath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'api/storage/')) {
            $path = substr($path, strlen('api/storage/'));
        }

        if (
            $path === ''
            || str_contains($path, '..')
            || !str_starts_with($path, 'scholarships/')
            || !str_ends_with(strtolower($path), '.docx')
        ) {
            return null;
        }

        return $path;
    }

    private function mapFamilyData(TemplateProcessor $templateProcessor, $family)
    {
        $relations = ['ayah', 'ibu', 'wali'];
        foreach ($relations as $rel) {
            $data = $family->where('jenis_relasi', $rel)->first();
            if ($data) {
                $templateProcessor->setValue($rel . '_nama', $data->nama_lengkap ?? '-');
                $templateProcessor->setValue($rel . '_kerja', $data->pekerjaan ?? '-');
                $templateProcessor->setValue($rel . '_gaji', $data->penghasilan ? number_format($data->penghasilan, 0, ',', '.') : '-');
                $templateProcessor->setValue($rel . '_status', ucfirst($data->status_hidup ?? '-'));
                $templateProcessor->setValue($rel . '_tgl', $data->tanggal_meninggal ? $this->indonesianDate($data->tanggal_meninggal) : '-');
            } else {
                foreach(['nama', 'kerja', 'gaji', 'status', 'tgl'] as $k) $templateProcessor->setValue($rel . '_' . $k, '-');
            }
        }
    }

    private function mapImages(TemplateProcessor $templateProcessor, $profile, ?User $officialKadep = null)
    {
        $this->setImageOrFallback($templateProcessor, 'foto', $profile->pas_foto_path, [
            'width' => 110,
            'height' => 150,
            'ratio' => false,
        ], '(Tidak Ada)');

        $this->setImageOrFallback($templateProcessor, 'tanda_tangan', $profile->tanda_tangan_path, [
            'width' => 150,
            'height' => 80,
            'ratio' => true,
        ], '(Tidak Ada)');

        $this->setImageOrFallback($templateProcessor, 'ttd_kadep', $officialKadep?->signature_path, [
            'width' => 150,
            'height' => 80,
            'ratio' => true,
        ], '(Tanda tangan belum tersedia)');

        $this->setLocalImageOrFallback($templateProcessor, 'paraf', $this->signatoryService->globalParafFilePath(), [
            'width' => 80,
            'height' => 45,
            'ratio' => true,
        ], '(Paraf belum tersedia)');
    }

    private function setImageOrFallback(
        TemplateProcessor $templateProcessor,
        string $placeholder,
        ?string $path,
        array $dimensions,
        string $fallback
    ): void {
        $publicPath = $this->normalizePublicStoragePath($path);

        if (!$publicPath || !Storage::disk('public')->exists($publicPath)) {
            $templateProcessor->setValue($placeholder, $fallback);
            return;
        }

        try {
            $templateProcessor->setImageValue($placeholder, array_merge([
                'path' => Storage::disk('public')->path($publicPath),
            ], $dimensions));
        } catch (\Throwable $exception) {
            Log::warning("Unable to insert scholarship image placeholder {$placeholder}: " . $exception->getMessage());
            $templateProcessor->setValue($placeholder, $fallback);
        }
    }

    private function setLocalImageOrFallback(
        TemplateProcessor $templateProcessor,
        string $placeholder,
        ?string $path,
        array $dimensions,
        string $fallback
    ): void {
        if (!$path || !is_file($path)) {
            $templateProcessor->setValue($placeholder, $fallback);
            return;
        }

        try {
            $templateProcessor->setImageValue($placeholder, array_merge([
                'path' => $path,
            ], $dimensions));
        } catch (\Throwable $exception) {
            Log::warning("Unable to insert scholarship image placeholder {$placeholder}: " . $exception->getMessage());
            $templateProcessor->setValue($placeholder, $fallback);
        }
    }

    private function normalizePublicStoragePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'api/storage/')) {
            $path = substr($path, strlen('api/storage/'));
        }

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
