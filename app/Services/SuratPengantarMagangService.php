<?php

namespace App\Services;

use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SuratPengantarMagangService
{
    public function __construct(
        private LetterAssignmentService $assignmentService,
        private MahasiswaProfileDataService $profileDataService,
        private AcademicSignatoryService $signatoryService
    )
    {
    }

    /**
     * Assign the application to an active Tendik Persuratan responsible for magang letters.
     */
    public function assignApplication(SuratPengantarMagangApplication $application): ?User
    {
        return $this->assignmentService->assignToEligibleTendik($application, SuratPengantarMagangApplication::LETTER_TYPE);
    }

    /**
     * Generate the final student-review PDF after department approval.
     * The optional user is the approval actor; visible signatories are resolved from current official role holders.
     */
    public function generateDocument(SuratPengantarMagangApplication $application, ?User $finalApprover = null): string
    {
        $application->loadMissing([
            'user.studyProgram.department.faculty',
            'user.department.faculty',
            'mahasiswaProfile',
            'assignedTendik',
        ]);

        $existingPath = $this->generatedPdfStoragePath($application);
        if ($existingPath) {
            return Storage::url($existingPath);
        }

        if (!$application->nomor_surat) {
            throw new RuntimeException('Nomor surat belum tersedia untuk dokumen Magang.');
        }

        if (!$this->signatoryService->officialKadepForApplication($application)) {
            throw new RuntimeException('Dokumen Magang tidak dapat dibuat: belum ada Ketua Departemen aktif untuk program studi mahasiswa.');
        }

        $directory = 'surat-pengantar-magang/generated';
        if (!Storage::disk('public')->exists($directory)) {
            Storage::disk('public')->makeDirectory($directory);
        }

        $tempDirectory = $directory . '/tmp';
        if (!Storage::disk('public')->exists($tempDirectory)) {
            Storage::disk('public')->makeDirectory($tempDirectory);
        }

        $filename = sprintf(
            'surat-pengantar-magang-%d-%s-%s.pdf',
            $application->id,
            now()->format('YmdHis'),
            Str::random(8)
        );
        $path = $directory . '/' . $filename;
        $tempPath = $tempDirectory . '/' . $filename;
        $finalFileCreated = false;

        try {
            $html = $this->buildDocumentHtml($application, $finalApprover);
            $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
            Storage::disk('public')->put($tempPath, $pdf->output());

            if (!Storage::disk('public')->exists($tempPath)) {
                throw new RuntimeException('Gagal menyimpan dokumen sementara PDF Magang.');
            }

            if (!Storage::disk('public')->move($tempPath, $path)) {
                throw new RuntimeException('Gagal memindahkan dokumen PDF Magang.');
            }
            $finalFileCreated = true;

            if (!Storage::disk('public')->exists($path)) {
                throw new RuntimeException('Gagal menyimpan dokumen PDF Magang.');
            }

            $application->update([
                'generated_pdf_path' => Storage::url($path),
            ]);

            return Storage::url($path);
        } catch (Throwable $exception) {
            if (Storage::disk('public')->exists($tempPath)) {
                Storage::disk('public')->delete($tempPath);
            }

            if ($finalFileCreated && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }
    }

    public function generatedPdfStoragePath(SuratPengantarMagangApplication $application): ?string
    {
        $path = $this->publicDiskPath($application->generated_pdf_path);

        if (!$path || !str_starts_with($path, 'surat-pengantar-magang/generated/')) {
            return null;
        }

        return $path;
    }

    private function buildDocumentHtml(SuratPengantarMagangApplication $application, ?User $finalApprover): string
    {
        $studentData = $this->profileDataService->forApplication($application);
        $departmentDisplay = $studentData['department_display'] !== '-' ? $studentData['department_display'] : 'Departemen';
        $facultyDisplay = $studentData['fakultas_display'] !== '-' ? $studentData['fakultas_display'] : 'Universitas Gadjah Mada';
        $officialKadep = $this->signatoryService->officialKadepForApplication($application);
        $finalSignature = $this->signatoryService->publicImageDataUri($officialKadep?->signature_path);
        $parafImage = $this->signatoryService->globalParafDataUri();
        $finalApproverName = $officialKadep?->name ?: 'Kadep';
        $finalApproverRole = $officialKadep?->akademik_label ?: 'Kadep';
        $finalApproverNip = $this->signatoryService->nipLikeValue($officialKadep);

        return '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 42px 48px; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111827; font-size: 12px; line-height: 1.55; }
        .header { text-align: center; border-bottom: 3px solid #111827; padding-bottom: 14px; margin-bottom: 28px; }
        .header h1 { font-size: 17px; margin: 0 0 4px; letter-spacing: .2px; }
        .header p { margin: 1px 0; font-size: 11px; }
        .meta { margin-bottom: 24px; }
        .meta table, .data table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 2px 0; vertical-align: top; }
        .label { width: 145px; color: #374151; }
        .colon { width: 12px; text-align: center; }
        .section-title { font-weight: bold; margin: 18px 0 8px; font-size: 13px; }
        .data td { padding: 5px 0; vertical-align: top; }
        .body-copy { margin-top: 18px; text-align: justify; }
        .signature-grid { width: 100%; margin-top: 42px; border-collapse: collapse; }
        .signature-grid td { width: 50%; vertical-align: top; text-align: center; padding: 0 20px; }
        .approval-box { min-height: 95px; display: flex; align-items: center; justify-content: center; flex-direction: column; }
        .paraf-img { max-width: 80px; max-height: 45px; margin: 0 auto 6px; display: block; }
        .signature-img { max-width: 150px; max-height: 70px; margin: 0 auto 6px; display: block; }
        .muted { color: #6b7280; font-size: 10px; }
        .name { font-weight: bold; text-decoration: underline; margin-top: 4px; }
        .footer { position: fixed; bottom: -18px; left: 0; right: 0; text-align: center; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>' . $this->escape($departmentDisplay) . '</h1>
        <p>' . $this->escape($facultyDisplay) . '</p>
        <p>Universitas Gadjah Mada</p>
    </div>

    <div class="meta">
        <table>
            <tr><td class="label">Nomor</td><td class="colon">:</td><td>' . $this->escape($application->nomor_surat) . '</td></tr>
            <tr><td class="label">Hal</td><td class="colon">:</td><td>Surat Pengantar Magang</td></tr>
            <tr><td class="label">Tanggal</td><td class="colon">:</td><td>' . $this->escape($this->formatDate(now())) . '</td></tr>
        </table>
    </div>

    <p>Yth. ' . $this->escape($application->nama_penerima) . '</p>
    <p>
        ' . $this->escape($application->nama_perusahaan) . '<br>
        ' . nl2br($this->escape($application->alamat_perusahaan)) . '
    </p>

    <div class="body-copy">
        <p>Dengan hormat,</p>
        <p>
            Bersama surat ini kami menerangkan bahwa mahasiswa berikut mengajukan permohonan pengantar magang
            untuk pelaksanaan kegiatan pada instansi/perusahaan yang Bapak/Ibu pimpin.
        </p>
    </div>

    <div class="section-title">Data Mahasiswa</div>
    <div class="data">
        <table>
            <tr><td class="label">Nama Lengkap</td><td class="colon">:</td><td>' . $this->escape($studentData['name']) . '</td></tr>
            <tr><td class="label">NIM</td><td class="colon">:</td><td>' . $this->escape($studentData['nim']) . '</td></tr>
            <tr><td class="label">Program Studi</td><td class="colon">:</td><td>' . $this->escape($studentData['program_studi_display']) . '</td></tr>
            <tr><td class="label">Departemen</td><td class="colon">:</td><td>' . $this->escape($studentData['department_display']) . '</td></tr>
            <tr><td class="label">Fakultas</td><td class="colon">:</td><td>' . $this->escape($studentData['fakultas_display']) . '</td></tr>
            <tr><td class="label">Email</td><td class="colon">:</td><td>' . $this->escape($studentData['email']) . '</td></tr>
        </table>
    </div>

    <div class="section-title">Detail Pengajuan Magang</div>
    <div class="data">
        <table>
            <tr><td class="label">Nama Perusahaan</td><td class="colon">:</td><td>' . $this->escape($application->nama_perusahaan) . '</td></tr>
            <tr><td class="label">Alamat Perusahaan</td><td class="colon">:</td><td>' . nl2br($this->escape($application->alamat_perusahaan)) . '</td></tr>
            <tr><td class="label">Peran</td><td class="colon">:</td><td>' . $this->escape($application->peran) . '</td></tr>
            <tr><td class="label">Rentang Tanggal</td><td class="colon">:</td><td>' . $this->escape($application->rentang_tanggal) . '</td></tr>
            <tr><td class="label">Dosen Pembimbing/DPA</td><td class="colon">:</td><td>' . $this->escape($application->dosen_pembimbing_dpa) . '</td></tr>
            <tr><td class="label">Proposal Kegiatan Magang</td><td class="colon">:</td><td>' . $this->escape($this->fileNameFromPath($application->proposal_kegiatan_magang_path)) . '</td></tr>
        </table>
    </div>

    <div class="body-copy">
        <p>
            Demikian surat pengantar ini dibuat untuk dapat dipergunakan sebagaimana mestinya.
            Atas perhatian dan kerja sama Bapak/Ibu, kami ucapkan terima kasih.
        </p>
    </div>

    <table class="signature-grid">
        <tr>
            <td>
                <p>Paraf Kaprodi/Sekprodi</p>
                <div class="approval-box">
                    ' . ($parafImage ? '<img class="paraf-img" src="' . $parafImage . '" alt="Paraf">' : '<div class="muted">Paraf belum tersedia</div>') . '
                    <div class="muted">' . $this->escape($this->formatDate($application->kaprodi_approved_at)) . '</div>
                </div>
            </td>
            <td>
                <p>Tanda Tangan Kadep</p>
                <div class="approval-box">
                    ' . ($finalSignature ? '<img class="signature-img" src="' . $finalSignature . '" alt="Tanda tangan">' : '<div class="muted">Ditandatangani secara elektronik</div>') . '
                    <div class="name">' . $this->escape($finalApproverName) . '</div>
                    <div class="muted">' . $this->escape($finalApproverRole) . '</div>
                    <div class="muted">NIP. ' . $this->escape($finalApproverNip) . '</div>
                    <div class="muted">' . $this->escape($this->formatDate($application->kadep_approved_at)) . '</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">Dokumen ini dihasilkan oleh Sistem Persuratan.</div>
</body>
</html>';
    }

    private function publicDiskPath(?string $filePath): ?string
    {
        if (!$filePath) {
            return null;
        }

        $path = parse_url($filePath, PHP_URL_PATH) ?: $filePath;
        $path = str_replace('\\', '/', $path);
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

    private function formatDate($value): string
    {
        if (!$value) {
            return '-';
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('d/m/Y H:i')
            : (string) $value;
    }

    private function fileNameFromPath(?string $path): string
    {
        if (!$path) {
            return '-';
        }

        $cleanPath = parse_url($path, PHP_URL_PATH) ?: $path;

        return basename($cleanPath) ?: '-';
    }

    private function escape(?string $value): string
    {
        $value = trim((string) $value);

        return htmlspecialchars($value !== '' ? $value : '-', ENT_QUOTES, 'UTF-8');
    }
}
