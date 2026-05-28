<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;

/**
 * Maps an SKA application's workflow status to the artifact phase that should
 * represent its current generated preview. Revision/Rejected/Draft are
 * intentionally unavailable here; a later read endpoint can fall back to the
 * latest READY artifact in mahasiswa -> departemen -> prodi -> tendik order.
 */
class SuratKeteranganAktifPhaseResolver
{
    public function phaseFor(SuratKeteranganAktifApplication $application): ?string
    {
        return match ($application->getAttribute('status')) {
            SuratKeteranganAktifApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            SuratKeteranganAktifApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            default => null,
        };
    }

    /**
     * @return array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool}
     */
    public function phaseFlagsFor(SuratKeteranganAktifApplication $application, string $phase): array
    {
        return match ($phase) {
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW => [
                'include_nomor_surat' => !empty($application->getAttribute('nomor_surat')),
                'include_prodi_paraf' => false,
                'include_kadep_signature' => false,
            ],
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
