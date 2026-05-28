<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;

/**
 * Maps a Beasiswa application's canonical workflow status to the artifact
 * phase that represents its current preview, plus the rendering flags that
 * each phase's filled DOCX should bake.
 *
 * Phase 1 scope: pure mapping. Does not query the artifact table; the
 * Artifact service / future preview endpoints will use the phase identifier
 * to look up the latest READY row.
 *
 * IMPORTANT: this resolver is status-based, NOT caller-role-based. Authorisation
 * (which role can see which phase) is enforced separately at the endpoint layer
 * in a later phase.
 */
class BeasiswaPhaseResolver
{
    /**
     * Canonical mapping from status to current-preview phase. Off-paths
     * (Revision, Rejected) return null — the caller is expected to fall back
     * to the latest READY artifact from a prior phase, NOT to fabricate a
     * preview.
     */
    public function phaseFor(ScholarshipApplication $application): ?string
    {
        return match ($application->getAttribute('status')) {
            ScholarshipApplication::STATUS_SUBMITTED => LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            ScholarshipApplication::STATUS_APPROVED_TENDIK => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI => LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            ScholarshipApplication::STATUS_COMPLETED => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            default => null, // Revision / Rejected / Draft -> caller falls back to latest ready artifact.
        };
    }

    /**
     * Rendering flags for the given phase. These flags tell the document
     * generator which stage-specific surfaces to include in the filled DOCX:
     *  - include_nomor_surat: bake the application's nomor_surat (vs "-")
     *  - include_prodi_paraf: include the Prodi paraf image
     *  - include_kadep_signature: include the Kadep signature image
     *
     * For the tendik_review phase, nomor_surat MAY be present if it has
     * already been assigned (resubmission/revision cycles), but the typical
     * Submitted application has no nomor_surat yet — leaving the generator
     * to substitute "-".
     *
     * @return array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool}
     */
    public function phaseFlagsFor(ScholarshipApplication $application, string $phase): array
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
