<?php

namespace App\Services;

use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Enums\UserStatus;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Log;

class ScholarshipAutomationService
{
    /**
     * Assign the application to a Tendik based on "Beasiswa" task.
     */
    public function assignApplication(ScholarshipApplication $application)
    {
        // Search for a Tendik that has any task containing "Beasiswa" in their assigned_tasks JSON
        $assignedTendik = User::where('role', 'tendik')
            ->where('status', UserStatus::Active)
            ->where('assigned_tasks', 'LIKE', '%Beasiswa%')
            ->first();

        if ($assignedTendik) {
            $application->assigned_to = $assignedTendik->id;
            $application->save();
            return $assignedTendik;
        }

        return null;
    }

    /**
     * Generate populated DOCX from Google Docs template.
     */
    public function generateDocument(ScholarshipApplication $application)
    {
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

            // Save temporarily
            $tempPath = storage_path('app/temp_template_' . $application->id . '.docx');
            file_put_contents($tempPath, $templateContent);

            // Load relations
            $application->load(['mahasiswaProfile.keluarga', 'mahasiswaProfile.scholarshipHistories', 'user']);
            $profile = $application->mahasiswaProfile;
            $family = $profile->keluarga;

            $templateProcessor = new TemplateProcessor($tempPath);

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
            $filename = 'scholarship_application_' . $application->id . '_' . time() . '.docx';
            $publicPath = 'scholarships/' . $filename;
            
            if (!Storage::disk('public')->exists('scholarships')) {
                Storage::disk('public')->makeDirectory('scholarships');
            }

            $savePath = Storage::disk('public')->path($publicPath);
            $templateProcessor->saveAs($savePath);

            if (file_exists($tempPath)) unlink($tempPath);

            $application->generated_docx_path = $publicPath;
            $application->save();

            return $publicPath;

        } catch (\Exception $e) {
            Log::error("Error generating document: " . $e->getMessage());
            return false;
        }
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
