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
     *     include_paraf_pengantar: bool,
     *     include_kadep_ttd_pengantar: bool
     * }
     */
    public function phaseFlagsFor(SuratPengantarMagangApplication $application, string $phase): array
    {
        // Surat Pengantar Magang is Pengantar-only (Surat Tugas split out in S1;
        // it becomes its own letter type in S2). Only Pengantar flags exist now —
        // tugas-only flags were removed. Legacy tugas DB columns are retained for
        // compatibility but never drive the Magang document/hash.
        return match ($phase) {
            LetterDocumentArtifact::PHASE_PRODI_REVIEW => [
                'include_nomor_pengantar' => true,
                'include_paraf_pengantar' => false,
                'include_kadep_ttd_pengantar' => false,
            ],
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW => [
                'include_nomor_pengantar' => true,
                'include_paraf_pengantar' => true,
                'include_kadep_ttd_pengantar' => false,
            ],
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW => [
                'include_nomor_pengantar' => true,
                'include_paraf_pengantar' => true,
                'include_kadep_ttd_pengantar' => true,
            ],
            default => [
                'include_nomor_pengantar' => false,
                'include_paraf_pengantar' => false,
                'include_kadep_ttd_pengantar' => false,
            ],
        };
    }
}
