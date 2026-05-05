<?php

namespace App\Services;

use App\Models\LetterApplication;
use App\Models\User;
use App\Enums\UserStatus;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Log;

class AktifLetterAutomationService
{
    /**
     * Assign the application to a Tendik based on "Aktif" task.
     */
    public function assignApplication(LetterApplication $application)
    {
        $assignedTendik = User::where('role', 'tendik')
            ->where('status', UserStatus::Active)
            ->where('assigned_tasks', 'LIKE', '%Aktif%')
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
    public function generateDocument(LetterApplication $application)
    {
        try {
            // Provided template ID: 1otmJmaBgAPjccVRkFL-nvGzgHlOnSyY6
            $templateId = env('TEMPLATE_AKTIF_ID', '1otmJmaBgAPjccVRkFL-nvGzgHlOnSyY6'); 
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
            $tempPath = storage_path('app/temp_aktif_' . $application->id . '.docx');
            file_put_contents($tempPath, $templateContent);

            // Load relations
            $application->load(['mahasiswaProfile', 'user']);
            $profile = $application->mahasiswaProfile;

            $templateProcessor = new TemplateProcessor($tempPath);

            // 1. Data Utama & Akademik
            $templateProcessor->setValue('nama', $profile->nama_lengkap ?? $application->user->name);
            $templateProcessor->setValue('nim', $profile->nim ?? '-');
            $templateProcessor->setValue('fakultas', $profile->fakultas ?? '-');
            $templateProcessor->setValue('prodi', $profile->program_studi ?? '-');
            $templateProcessor->setValue('email', $application->user->email);
            
            $templateProcessor->setValue('tempat_lahir', $application->pob ?? $profile->tempat_lahir ?? '-');
            $templateProcessor->setValue('tanggal_lahir', $application->dob ? date('d F Y', strtotime($application->dob)) : ($profile->tanggal_lahir ? date('d F Y', strtotime($profile->tanggal_lahir)) : '-'));
            $templateProcessor->setValue('jenis_kelamin', $application->gender === 'L' ? 'Laki-laki' : ($application->gender === 'P' ? 'Perempuan' : '-'));
            
            $templateProcessor->setValue('keperluan', $application->keperluan ?? '-');
            $templateProcessor->setValue('tujuan_surat', $application->tujuan_surat ?? '-');

            // 2. Data Orang Tua
            $templateProcessor->setValue('ot_nama', $application->parent_name ?? '-');
            $templateProcessor->setValue('ot_kerja', $application->parent_job ?? '-');
            $templateProcessor->setValue('ot_tipe', $application->parent_job_type ?? '-');
            $templateProcessor->setValue('ot_nip', $application->parent_nip ?? '-');
            $templateProcessor->setValue('ot_pangkat', $application->parent_rank ?? '-');
            $templateProcessor->setValue('ot_golongan', $application->parent_group ?? '-');
            $templateProcessor->setValue('ot_instansi', $application->parent_institution ?? '-');
            $templateProcessor->setValue('ot_id_karyawan', $application->parent_employee_id ?? '-');
            $templateProcessor->setValue('ot_jabatan', $application->parent_position ?? '-');
            $templateProcessor->setValue('ot_npwp', $application->parent_npwp ?? '-');
            $templateProcessor->setValue('ot_nama_usaha', $application->parent_business_name ?? '-');

            // 3. Metadata
            $templateProcessor->setValue('tgl_aju', date('d F Y', strtotime($application->submitted_at ?? now())));

            // 4. Save
            $filename = 'aktif_letter_' . $application->id . '_' . time() . '.docx';
            $publicPath = 'letters/aktif/' . $filename;
            
            if (!Storage::disk('public')->exists('letters/aktif')) {
                Storage::disk('public')->makeDirectory('letters/aktif');
            }

            $savePath = Storage::disk('public')->path($publicPath);
            $templateProcessor->saveAs($savePath);

            if (file_exists($tempPath)) unlink($tempPath);

            $application->generated_docx_path = $publicPath;
            $application->save();

            return $publicPath;

        } catch (\Exception $e) {
            Log::error("Error generating aktif letter: " . $e->getMessage());
            return false;
        }
    }
}
