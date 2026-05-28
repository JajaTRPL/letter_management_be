<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;

/**
 * Maps a PLN application's workflow status to the artifact phase that should
 * represent its current generated preview. Draft/Revision/Rejected are
 * intentionally unavailable; later read endpoints can choose fallback behavior.
 */
class ProsesLuarNegeriPhaseResolver
{
    public function phaseFor(ProsesLuarNegeriApplication $application): ?string
    {
        return match ($application->getAttribute('status')) {
            ProsesLuarNegeriApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ProsesLuarNegeriApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            default => null,
        };
    }

    /**
     * @return array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool}
     */
    public function phaseFlagsFor(ProsesLuarNegeriApplication $application, string $phase): array
    {
        return match ($phase) {
            LetterDocumentArtifact::PHASE_PRODI_REVIEW => [
                'include_nomor_surat' => true,
                'include_prodi_paraf' => false,
                'include_kadep_signature' => false,
            ],
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW => [
                'include_nomor_surat' => true,
                'include_prodi_paraf' => true,
                'include_kadep_signature' => false,
            ],
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW => [
                'include_nomor_surat' => true,
                'include_prodi_paraf' => true,
                'include_kadep_signature' => true,
            ],
            default => [
                'include_nomor_surat' => false,
                'include_prodi_paraf' => false,
                'include_kadep_signature' => false,
            ],
        };
    }
}
