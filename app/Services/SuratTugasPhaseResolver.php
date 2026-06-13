<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratTugasApplication;

/**
 * Maps a Surat Tugas application's status to its artifact phase, and the phase
 * to the document section gates. Surat Tugas is a single-section letter whose
 * own section IS the "tugas" section, so it carries the tugas-named flags
 * (number / paraf / Kadep TTD), turned on progressively across phases — the
 * same semantics the other artifact-backed letters use.
 */
class SuratTugasPhaseResolver
{
    public function phaseFor(SuratTugasApplication $application): ?string
    {
        return match ($application->getAttribute('status')) {
            SuratTugasApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            SuratTugasApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            SuratTugasApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            SuratTugasApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            default => null,
        };
    }

    /**
     * @return array{
     *     include_nomor_tugas: bool,
     *     include_paraf_tugas: bool,
     *     include_kadep_ttd_tugas: bool
     * }
     */
    public function phaseFlagsFor(SuratTugasApplication $application, string $phase): array
    {
        return match ($phase) {
            LetterDocumentArtifact::PHASE_PRODI_REVIEW => [
                'include_nomor_tugas' => true,
                'include_paraf_tugas' => false,
                'include_kadep_ttd_tugas' => false,
            ],
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW => [
                'include_nomor_tugas' => true,
                'include_paraf_tugas' => true,
                'include_kadep_ttd_tugas' => false,
            ],
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW => [
                'include_nomor_tugas' => true,
                'include_paraf_tugas' => true,
                'include_kadep_ttd_tugas' => true,
            ],
            default => [
                'include_nomor_tugas' => false,
                'include_paraf_tugas' => false,
                'include_kadep_ttd_tugas' => false,
            ],
        };
    }
}
