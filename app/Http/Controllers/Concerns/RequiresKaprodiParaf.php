<?php

namespace App\Http\Controllers\Concerns;

use App\Services\AcademicSignatoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * The Prodi paraf printed on a letter is the paraf the Kaprodi of the
 * student's own study program uploaded in Profil. There is no fallback image:
 * until that Kaprodi uploads one, approvals whose document carries the paraf
 * are refused with an actionable 422 instead of a generic generation error.
 */
trait RequiresKaprodiParaf
{
    protected function missingKaprodiParafResponse(Model $application): ?JsonResponse
    {
        /** @var AcademicSignatoryService $signatoryService */
        $signatoryService = app(AcademicSignatoryService::class);

        $kaprodi = $signatoryService->officialKaprodiForApplication($application);
        if (!$kaprodi) {
            return response()->json([
                'message' => 'Kaprodi aktif untuk program studi mahasiswa ini belum ditetapkan, sehingga paraf Prodi belum tersedia. Mohon hubungi administrator untuk menetapkan Kaprodi, lalu Kaprodi mengunggah paraf di menu Profil.',
                'reason' => 'missing_official_kaprodi',
            ], 422);
        }

        if ($signatoryService->kaprodiParafPublicPath($application) === null) {
            $programName = trim((string) ($kaprodi->studyProgram?->name ?? ''));
            $programLabel = $programName !== '' ? ' Program Studi ' . $programName : '';

            return response()->json([
                'message' => 'Paraf Kaprodi' . $programLabel . ' belum diunggah. Kaprodi perlu mengunggah paraf terlebih dahulu di menu Profil sebelum pengajuan ini dapat disetujui.',
                'reason' => 'missing_kaprodi_paraf',
            ], 422);
        }

        return null;
    }
}
