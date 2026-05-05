<?php

namespace App\Services;

use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ScholarshipAutomationService
{
    public function __construct(private LetterAssignmentService $assignmentService)
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
     */
    public function generateDocument(ScholarshipApplication $application): string|false
    {
        $tempTemplatePath = null;
        $tempOutputPath = null;
        $publicPath = null;

        try {
            $templateId = env('TEMPLATE_BEASISWA_ID', '1wnQYvwVO45M3LDDLEitsfjMFgkwj9S7f'); 
            $url = "https://docs.google.com/document/d/{$templateId}/export?format=docx";
            
            $options = [
                'http' => [
                    'follow_location' => true,
                    'max_redirects' => 5,
                    'header' => "User-Agent: Mozilla/5.0\r\n"
                ]
            ];
            $context = stream_context_create($options);
            $templateContent = @file_get_contents($url, false, $context);

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
            $application->load(['mahasiswaProfile.keluarga', 'mahasiswaProfile.scholarshipHistories', 'user']);
            $profile = $application->mahasiswaProfile;
            $family = $profile->keluarga;

            $templateProcessor = new TemplateProcessor($tempTemplatePath);

            // Fetch all variables to fix potential split tags issue (common in Google Docs exports)
            // TemplateProcessor handles this internally but sometimes needs explicit values.

            // 1. Data Utama & Akademik
            $templateProcessor->setValue('nama', $profile->nama_lengkap ?? $application->user->name);
            $templateProcessor->setValue('nim', $profile->nim ?? $application->user->name);
            $templateProcessor->setValue('fakultas', $profile->fakultas ?? '-');
            $templateProcessor->setValue('prodi', $profile->program_studi ?? '-');
            $templateProcessor->setValue('email', $application->user->email);
            
            $templateProcessor->setValue('ipk', (string)($application->ipk ?? '-'));
            $templateProcessor->setValue('ipk_total', (string)($application->ipk ?? '-'));
            $templateProcessor->setValue('ip_2', (string)($application->gpa_last_2_semesters ?? '-'));
            $templateProcessor->setValue('sks_2', (string)($application->sks_last_2_semesters ?? '-'));
            $templateProcessor->setValue('sksk', (string)($application->total_sks_passed ?? '-'));
            $templateProcessor->setValue('sks_total', (string)($application->total_sks_passed ?? '-'));
            
            $templateProcessor->setValue('semester', (string)($application->current_semester ?? '-'));
            $templateProcessor->setValue('jenjang', $application->study_level ?? 'D4');
            $templateProcessor->setValue('angkatan', $profile->tahun_masuk ?? '-');
            $templateProcessor->setValue('tanggungan', (string)($application->family_dependents ?? '-'));

            $templateProcessor->setValue('cuti_status', $application->on_leave ?? 'Belum');
            $templateProcessor->setValue('cuti', $application->on_leave ?? 'Belum');
            $templateProcessor->setValue('skripsi_status', $application->thesis_status ?? 'Belum');
            $templateProcessor->setValue('rencana_ujian', $application->exam_plan_date 
                ? date('d F Y', strtotime($application->exam_plan_date)) 
                : '-');
            $templateProcessor->setValue('beasiswa_nama', $application->scholarship_name ?? '-');

            // 2. TTL & Alamat
            $templateProcessor->setValue('tempat_lahir', $profile->tempat_lahir ?? '-');
            $templateProcessor->setValue('tanggal_lahir', $profile->tanggal_lahir ? date('d F Y', strtotime($profile->tanggal_lahir)) : '-');
            $templateProcessor->setValue('jenis_kelamin', $profile->jenis_kelamin === 'L' ? 'Laki-laki' : ($profile->jenis_kelamin === 'P' ? 'Perempuan' : '-'));
            $templateProcessor->setValue('no_hp', $profile->no_hp ?? '-');
            $templateProcessor->setValue('alamat_asal', $profile->alamat_asal ?? '-');
            $templateProcessor->setValue('alamat_domisili', $profile->alamat_domisili ?? '-');

            // 3. Riwayat Beasiswa
            $hCount = $profile->scholarshipHistories->count();
            $templateProcessor->setValue('h_status_teks', $hCount > 0 ? 'Pernah' : 'Belum Pernah');
            if ($hCount > 0) {
                $h = $profile->scholarshipHistories->first();
                $templateProcessor->setValue('h_sumber', $h->nama_beasiswa ?? '-');
                $templateProcessor->setValue('h_periode', $h->periode ?? '-');
                $templateProcessor->setValue('h_nominal', is_numeric($h->jumlah) ? number_format((float)$h->jumlah, 0, ',', '.') : $h->jumlah);
                $templateProcessor->setValue('h_masih', $h->status ?? '-');
            } else {
                foreach(['h_sumber', 'h_periode', 'h_nominal', 'h_masih'] as $key) $templateProcessor->setValue($key, '-');
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
            $this->mapImages($templateProcessor, $profile);

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
                $templateProcessor->setValue($rel . '_tgl', $data->tanggal_meninggal ? date('d F Y', strtotime($data->tanggal_meninggal)) : '-');
            } else {
                foreach(['nama', 'kerja', 'gaji', 'status', 'tgl'] as $k) $templateProcessor->setValue($rel . '_' . $k, '-');
            }
        }
    }

    private function mapImages(TemplateProcessor $templateProcessor, $profile)
    {
        $normalizePath = fn($path) => $path ? ltrim(str_replace('/storage/', '', $path), '/') : null;

        // Foto
        $fotoPath = $normalizePath($profile->pas_foto_path);
        if ($fotoPath && Storage::disk('public')->exists($fotoPath)) {
            $templateProcessor->setImageValue('foto', ['path' => Storage::disk('public')->path($fotoPath), 'width' => 110, 'height' => 150, 'ratio' => false]);
        } else {
            $templateProcessor->setValue('foto', '(Tidak Ada)');
        }

        // Tanda Tangan
        $signPath = $normalizePath($profile->tanda_tangan_path);
        if ($signPath && Storage::disk('public')->exists($signPath)) {
            $templateProcessor->setImageValue('tanda_tangan', ['path' => Storage::disk('public')->path($signPath), 'width' => 150, 'height' => 80, 'ratio' => true]);
        } else {
            $templateProcessor->setValue('tanda_tangan', '(Tidak Ada)');
        }
    }
}
