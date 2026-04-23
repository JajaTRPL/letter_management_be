<?php

namespace App\Services;

use App\Models\ScholarshipApplication;
use App\Models\User;
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
        // Search for a Tendik that has "Beasiswa" in their assigned_tasks JSON
        $assignedTendik = User::where('role', 'tendik')
            ->where('status', 'Active')
            ->whereJsonContains('assigned_tasks', 'Beasiswa')
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
            // Fetch Template ID from .env or use a placeholder
            $templateId = env('TEMPLATE_BEASISWA_ID', 'placeholder_id'); 
            
            if ($templateId === 'placeholder_id') {
                Log::warning("Scholarship template ID is not configured in .env (TEMPLATE_BEASISWA_ID)");
                return false;
            }

            $url = "https://docs.google.com/document/d/{$templateId}/export?format=docx";
            
            // Download the template
            $options = [
                'http' => [
                    'follow_location' => true,
                    'max_redirects' => 5,
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
                ]
            ];
            $context = stream_context_create($options);
            $templateContent = @file_get_contents($url, false, $context);

            if ($templateContent === false) {
                Log::error("Failed to download Google Doc template for scholarship #{$application->id}");
                return false;
            }

            // Save temporarily to process
            $tempPath = storage_path('app/temp_template_' . $application->id . '.docx');
            file_put_contents($tempPath, $templateContent);

            // Load relations
            $application->load(['mahasiswaProfile.keluarga', 'user']);
            $profile = $application->mahasiswaProfile;
            $family = $profile->keluarga;

            $templateProcessor = new TemplateProcessor($tempPath);

            // 1. Data Utama & Akademik
            $templateProcessor->setValue('nama', $profile->nama_lengkap ?? $application->user->name);
            $templateProcessor->setValue('nim', $profile->nim ?? $application->user->name);
            $templateProcessor->setValue('fakultas', $profile->fakultas);
            $templateProcessor->setValue('prodi', $profile->program_studi);
            $templateProcessor->setValue('email', $application->user->email);
            
            // Aliases for IP and SKS
            $ipValue = $application->gpa_last_2_semesters ?? '-';
            $templateProcessor->setValue('ip_last_2', $ipValue);
            $templateProcessor->setValue('ip_2', $ipValue);
            
            $templateProcessor->setValue('ipk', $application->ipk ?? '-');
            
            $sksValue = $application->sks_last_2_semesters ?? '-';
            $templateProcessor->setValue('sks_last_2', $sksValue);
            $templateProcessor->setValue('sks_2', $sksValue);
            
            $templateProcessor->setValue('sks_total', $application->total_sks_passed ?? '-');
            
            // Aliases for Cuti and Skripsi
            $cutiValue = $application->on_leave ?? 'Belum';
            $templateProcessor->setValue('cuti', $cutiValue);
            $templateProcessor->setValue('cuti_status', $cutiValue);
            
            $templateProcessor->setValue('cuti_smt', $application->leave_semester ?? '-');
            
            $skripsiValue = $application->thesis_status ?? 'Belum';
            $templateProcessor->setValue('skripsi', $skripsiValue);
            $templateProcessor->setValue('skripsi_status', $skripsiValue);
            
            $templateProcessor->setValue('rencana_ujian', $application->exam_plan_date 
                ? date('d F Y', strtotime($application->exam_plan_date)) 
                : '-');
            $templateProcessor->setValue('beasiswa_nama', $application->scholarship_name);
            $templateProcessor->setValue('semester', $application->current_semester);
            $templateProcessor->setValue('jenjang', $application->study_level ?? 'D4');
            $templateProcessor->setValue('angkatan', $profile->tahun_masuk ?? '2023');
            $templateProcessor->setValue('tanggungan', $application->family_dependents);

            // Additional Academic Aliases
            $templateProcessor->setValue('sksk', $application->total_sks_passed ?? '-');
            $templateProcessor->setValue('ipk_total', $application->ipk ?? '-');

            // 2. Detail Profil (TTL, Gender, Alamat)
            $templateProcessor->setValue('tempat_lahir', $profile->tempat_lahir ?? '-');
            $templateProcessor->setValue('tanggal_lahir', $profile->tanggal_lahir ? date('d F Y', strtotime($profile->tanggal_lahir)) : '-');
            $templateProcessor->setValue('jenis_kelamin', $profile->jenis_kelamin ?? '-');
            $templateProcessor->setValue('no_hp', $profile->no_hp ?? '-');
            $templateProcessor->setValue('alamat_asal', $profile->alamat_asal ?? '-');
            $templateProcessor->setValue('alamat_domisili', $profile->alamat_domisili ?? '-');

            // 3. Riwayat Beasiswa (Opsi A: 1 Riwayat)
            $templateProcessor->setValue('h_status_teks', $application->has_scholarship_history ? 'Pernah' : 'Belum Pernah');
            $templateProcessor->setValue('h_sumber', $application->history_source ?? '-');
            $templateProcessor->setValue('h_periode', $application->history_period ?? '-');
            $templateProcessor->setValue('h_nominal', $application->history_amount ? number_format($application->history_amount, 0, ',', '.') : '-');
            $templateProcessor->setValue('h_masih', $application->history_status ?? '-');

            // 4. Data Keluarga (Ayah, Ibu, Wali)
            $this->mapFamilyData($templateProcessor, $family);

            // 5. Data Saudara (Dynamic Table Rows)
            $siblings = $family->where('jenis_relasi', 'saudara');
            if ($siblings->count() > 0) {
                $templateProcessor->cloneRow('s_nama', $siblings->count());
                $i = 1;
                foreach ($siblings as $sib) {
                    $templateProcessor->setValue('s_no#' . $i, $i);
                    $templateProcessor->setValue('s_nama#' . $i, $sib->nama_lengkap);
                    $templateProcessor->setValue('s_kerja#' . $i, $sib->pekerjaan ?? '-');
                    $templateProcessor->setValue('s_status#' . $i, $sib->status_kawin ?? '-');
                    $templateProcessor->setValue('s_ket#' . $i, $sib->keterangan ?? '-');
                    $i++;
                }
            } else {
                // Jika tidak ada saudara, isi baris placeholder dengan strip
                $templateProcessor->setValue('s_no', '-');
                $templateProcessor->setValue('s_nama', '-');
                $templateProcessor->setValue('s_kerja', '-');
                $templateProcessor->setValue('s_status', '-');
                $templateProcessor->setValue('s_ket', '-');
            }

            // 6. Gambar (Foto & Tanda Tangan)
            $this->mapImages($templateProcessor, $profile);

            // Save final document
            $filename = 'scholarship_application_' . $application->id . '_' . time() . '.docx';
            $publicPath = 'scholarships/' . $filename;
            
            if (!Storage::disk('public')->exists('scholarships')) {
                Storage::disk('public')->makeDirectory('scholarships');
            }

            $savePath = Storage::disk('public')->path($publicPath);
            $templateProcessor->saveAs($savePath);

            // Clean up temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            // Update application record
            $application->generated_docx_path = $publicPath;
            $application->save();

            return $publicPath;

        } catch (\Exception $e) {
            Log::error("Error generating scholarship document: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Map detailed family data for Ayah, Ibu, and Wali.
     */
    private function mapFamilyData(TemplateProcessor $templateProcessor, $family)
    {
        $relations = ['ayah', 'ibu', 'wali'];
        
        foreach ($relations as $rel) {
            $data = $family->where('jenis_relasi', $rel)->first();
            
            if ($data) {
                $templateProcessor->setValue($rel . '_nama', $data->nama_lengkap ?? '-');
                $templateProcessor->setValue($rel . '_kerja', $data->pekerjaan ?? '-');
                
                $gaji = $data->penghasilan ? number_format($data->penghasilan, 0, ',', '.') : '-';
                $templateProcessor->setValue($rel . '_gaji', $gaji);
                $templateProcessor->setValue($rel . '_penghasilan', $gaji);
                
                $templateProcessor->setValue($rel . '_status', $data->status_hidup ? ucfirst($data->status_hidup) : '-');
                
                $tgl = $data->tanggal_meninggal ? date('d F Y', strtotime($data->tanggal_meninggal)) : '-';
                $templateProcessor->setValue($rel . '_tgl', $tgl);
                $templateProcessor->setValue($rel . ' tgl', $tgl); // Alias with space
            } else {
                $templateProcessor->setValue($rel . '_nama', '-');
                $templateProcessor->setValue($rel . '_kerja', '-');
                $templateProcessor->setValue($rel . '_gaji', '-');
                $templateProcessor->setValue($rel . '_penghasilan', '-');
                $templateProcessor->setValue($rel . '_status', '-');
                $templateProcessor->setValue($rel . '_tgl', '-');
                $templateProcessor->setValue($rel . ' tgl', '-'); // Alias with space
            }
        }
    }

    /**
     * Map Image values for Pas Foto and Tanda Tangan.
     */
    private function mapImages(TemplateProcessor $templateProcessor, $profile)
    {
        // Helper to normalize path from DB (strip /storage/ prefix)
        $normalizePath = function($path) {
            if (!$path) return null;
            return ltrim(str_replace('/storage/', '', $path), '/');
        };

        // 1. Pas Foto
        $fotoPath = $normalizePath($profile->pas_foto_path);
        if ($fotoPath && Storage::disk('public')->exists($fotoPath)) {
            $path = Storage::disk('public')->path($fotoPath);
            $templateProcessor->setImageValue('foto', [
                'path' => $path,
                'width' => 110,
                'height' => 150,
                'ratio' => false
            ]);
        } else {
            $templateProcessor->setValue('foto', '(Foto Tidak Ada)');
        }

        // 2. Tanda Tangan
        $signPath = $normalizePath($profile->tanda_tangan_path);
        if ($signPath && Storage::disk('public')->exists($signPath)) {
            $path = Storage::disk('public')->path($signPath);
            $templateProcessor->setImageValue('tanda_tangan', [
                'path' => $path,
                'width' => 150,
                'height' => 90,
                'ratio' => true
            ]);
        } else {
            $templateProcessor->setValue('tanda_tangan', '(Tanda Tangan Tidak Ada)');
        }
    }
}
