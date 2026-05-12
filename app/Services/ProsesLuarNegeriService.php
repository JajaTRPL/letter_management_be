<?php

namespace App\Services;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProsesLuarNegeriService
{
    public function __construct(
        private LetterAssignmentService $assignmentService,
        private MahasiswaProfileDataService $profileDataService,
        private AcademicSignatoryService $signatoryService
    )
    {
    }

    public function assignApplication(ProsesLuarNegeriApplication $application): ?User
    {
        return $this->assignmentService->assignToEligibleTendik($application, ProsesLuarNegeriApplication::LETTER_TYPE);
    }

    public function generateDocument(ProsesLuarNegeriApplication $application, ?User $finalApprover = null): string
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
            throw new RuntimeException('Nomor surat belum tersedia untuk dokumen Proses Luar Negeri.');
        }

        if (!$this->signatoryService->officialKadepForApplication($application)) {
            throw new RuntimeException('Dokumen Proses Luar Negeri tidak dapat dibuat: belum ada Ketua Departemen aktif untuk program studi mahasiswa.');
        }

        $directory = 'proses-luar-negeri/generated';
        if (!Storage::disk('public')->exists($directory)) {
            Storage::disk('public')->makeDirectory($directory);
        }

        $tempDirectory = $directory . '/tmp';
        if (!Storage::disk('public')->exists($tempDirectory)) {
            Storage::disk('public')->makeDirectory($tempDirectory);
        }

        $filename = sprintf(
            'proses-luar-negeri-%d-%s-%s.pdf',
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
                throw new RuntimeException('Gagal menyimpan dokumen sementara PDF Proses Luar Negeri.');
            }

            if (!Storage::disk('public')->move($tempPath, $path)) {
                throw new RuntimeException('Gagal memindahkan dokumen PDF Proses Luar Negeri.');
            }
            $finalFileCreated = true;

            if (!Storage::disk('public')->exists($path)) {
                throw new RuntimeException('Gagal menyimpan dokumen PDF Proses Luar Negeri.');
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

    public function generatedPdfStoragePath(ProsesLuarNegeriApplication $application): ?string
    {
        $path = $this->publicDiskPath($application->generated_pdf_path);

        if (!$path || !str_starts_with($path, 'proses-luar-negeri/generated/')) {
            return null;
        }

        return $path;
    }

    private function buildDocumentHtml(ProsesLuarNegeriApplication $application, ?User $finalApprover): string
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
        .meta table, .data table { width: 100%; border-collapse: collapse; }
        .meta { margin-bottom: 24px; }
        .meta td, .data td { padding: 3px 0; vertical-align: top; }
        .label { width: 170px; color: #374151; }
        .colon { width: 12px; text-align: center; }
        .section-title { font-weight: bold; margin: 18px 0 8px; font-size: 13px; }
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
            <tr><td class="label">Hal</td><td class="colon">:</td><td>Proses Luar Negeri</td></tr>
            <tr><td class="label">Tanggal</td><td class="colon">:</td><td>' . $this->escape($this->formatDate(now())) . '</td></tr>
        </table>
    </div>

    <div class="body-copy">
        <p>Yang bertanda tangan di bawah ini menerangkan data mahasiswa untuk keperluan proses luar negeri:</p>
    </div>

    <div class="section-title">Data Mahasiswa</div>
    <div class="data">
        <table>
            <tr><td class="label">Nama Lengkap</td><td class="colon">:</td><td>' . $this->escape($studentData['name']) . '</td></tr>
            <tr><td class="label">NIM</td><td class="colon">:</td><td>' . $this->escape($studentData['nim']) . '</td></tr>
            <tr><td class="label">Tempat, Tanggal Lahir</td><td class="colon">:</td><td>' . $this->escape($application->tempat_lahir . ', ' . $this->formatDate($application->tanggal_lahir, false)) . '</td></tr>
            <tr><td class="label">Jenis Kelamin</td><td class="colon">:</td><td>' . $this->escape($application->jenis_kelamin) . '</td></tr>
            <tr><td class="label">Semester</td><td class="colon">:</td><td>' . $this->escape((string) $application->semester) . '</td></tr>
            <tr><td class="label">Nomor Paspor</td><td class="colon">:</td><td>' . $this->escape($application->nomor_paspor) . '</td></tr>
            <tr><td class="label">Program Studi</td><td class="colon">:</td><td>' . $this->escape($studentData['program_studi_display']) . '</td></tr>
            <tr><td class="label">Departemen</td><td class="colon">:</td><td>' . $this->escape($studentData['department_display']) . '</td></tr>
            <tr><td class="label">Fakultas</td><td class="colon">:</td><td>' . $this->escape($studentData['fakultas_display']) . '</td></tr>
            <tr><td class="label">Email</td><td class="colon">:</td><td>' . $this->escape($studentData['email']) . '</td></tr>
        </table>
    </div>

    <div class="body-copy">
        <p>
            Surat ini dibuat untuk keperluan: ' . $this->escape($application->keperluan) . '.
        </p>
        <p>Demikian surat ini dibuat untuk dapat dipergunakan sebagaimana mestinya.</p>
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

    private function formatDate($value, bool $includeTime = true): string
    {
        if (!$value) {
            return '-';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($includeTime ? 'd/m/Y H:i' : 'd/m/Y');
        }

        return (string) $value;
    }

    private function escape(?string $value): string
    {
        $value = trim((string) $value);

        return htmlspecialchars($value !== '' ? $value : '-', ENT_QUOTES, 'UTF-8');
    }
}
