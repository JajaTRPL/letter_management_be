<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratPengantarMagangApplication;

/**
 * Maps a Magang application's status to the phase for its two-section final
 * document contract. Draft/Revision/Rejected have no current phase.
 */
class SuratPengantarMagangPhaseResolver
{
    public function phaseFor(SuratPengantarMagangApplication $application): ?string
    {
        return match ($application->getAttribute('status')) {
            SuratPengantarMagangApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            SuratPengantarMagangApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            default => null,
        };
    }

    /**
     * @return array{
     *     include_nomor_pengantar: bool,
     *     include_nomor_tugas: bool,
     *     include_paraf_pengantar: bool,
     *     include_paraf_tugas: bool,
     *     include_kadep_ttd_pengantar: bool,
     *     include_kadep_ttd_tugas: bool
     * }
     */
    public function phaseFlagsFor(SuratPengantarMagangApplication $application, string $phase): array
    {
        return match ($phase) {
            LetterDocumentArtifact::PHASE_PRODI_REVIEW => [
                'include_nomor_pengantar' => true,
                'include_nomor_tugas' => true,
                'include_paraf_pengantar' => false,
                'include_paraf_tugas' => false,
                'include_kadep_ttd_pengantar' => false,
                'include_kadep_ttd_tugas' => false,
            ],
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW => [
                'include_nomor_pengantar' => true,
                'include_nomor_tugas' => true,
                'include_paraf_pengantar' => true,
                'include_paraf_tugas' => true,
                'include_kadep_ttd_pengantar' => false,
                'include_kadep_ttd_tugas' => false,
            ],
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW => [
                'include_nomor_pengantar' => true,
                'include_nomor_tugas' => true,
                'include_paraf_pengantar' => true,
                'include_paraf_tugas' => true,
                'include_kadep_ttd_pengantar' => true,
                'include_kadep_ttd_tugas' => true,
            ],
            default => [
                'include_nomor_pengantar' => false,
                'include_nomor_tugas' => false,
                'include_paraf_pengantar' => false,
                'include_paraf_tugas' => false,
                'include_kadep_ttd_pengantar' => false,
                'include_kadep_ttd_tugas' => false,
            ],
        };
    }
}
