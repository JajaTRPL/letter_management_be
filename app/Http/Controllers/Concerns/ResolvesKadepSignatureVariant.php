<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\AcademicSignatoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Lets the Kadep/Sekdep approval choose which Kadep signature image is
 * embedded in the final document: the regular one ("default") or the one
 * with the department stamp ("stamped").
 *
 * The choice travels as the optional `signature_variant` request field and is
 * applied only to the render overrides of that single generation call, so no
 * column is needed. Omitting the field (or sending "default") keeps the
 * previous behaviour byte-for-byte: no override is added.
 */
trait ResolvesKadepSignatureVariant
{
    /**
     * @return array<string, mixed>|JsonResponse Render overrides to merge into the
     *     generateForPhase() overrides, or an error response to return as-is.
     */
    protected function kadepSignatureVariantOverrides(Model $application, ?User $officialKadep = null): array|JsonResponse
    {
        $rawVariant = request()->input('signature_variant');
        if ($rawVariant !== null && !in_array($rawVariant, AcademicSignatoryService::SIGNATURE_VARIANTS, true)) {
            return response()->json([
                'message' => 'Pilihan tanda tangan tidak valid.',
                'errors' => [
                    'signature_variant' => ['Pilihan tanda tangan harus "default" atau "stamped".'],
                ],
            ], 422);
        }

        /** @var AcademicSignatoryService $signatoryService */
        $signatoryService = app(AcademicSignatoryService::class);
        $variant = $signatoryService->normalizeSignatureVariant($rawVariant);
        if ($variant === AcademicSignatoryService::SIGNATURE_VARIANT_DEFAULT) {
            return [];
        }

        $officialKadep ??= $signatoryService->officialKadepForApplication($application);
        if (!$officialKadep) {
            return response()->json([
                'message' => 'Konfigurasi Ketua Departemen aktif belum tersedia untuk departemen mahasiswa. Mohon hubungi administrator untuk menetapkan Ketua Departemen aktif sebelum dokumen final dapat dibuat.',
                'reason' => 'missing_official_kadep',
            ], 422);
        }

        $renderKadep = $signatoryService->kadepForSignatureVariant($officialKadep, $variant);
        if (!$renderKadep) {
            return response()->json([
                'message' => 'Tanda tangan dengan cap Ketua Departemen belum diunggah. Unggah terlebih dahulu di menu Profil (bagian "Tanda Tangan dengan Cap"), atau pilih tanda tangan biasa.',
                'reason' => 'missing_stamped_signature',
                'errors' => [
                    'signature_variant' => ['Tanda tangan dengan cap belum tersedia.'],
                ],
            ], 422);
        }

        return ['official_kadep' => $renderKadep];
    }
}
