<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only access + resolution gateway for SKA generated-preview artifacts.
 *
 * Mirrors the Beasiswa baseline: never calls the preview generation service,
 * never creates/updates artifact rows, never mutates the application or its
 * generated_pdf_path. Returns a typed result the controller maps to HTTP.
 */
class SuratKeteranganAktifGeneratedPreviewAccessService
{
    public const AUDIENCE_TENDIK = 'tendik';
    public const AUDIENCE_AKADEMIK = 'akademik';
    public const AUDIENCE_MAHASISWA = 'mahasiswa';

    /**
     * Fallback order for off-path statuses (Revision/Rejected). Most advanced
     * READY artifact wins; the read path never fabricates or generates.
     */
    private const FALLBACK_PHASES = [
        LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
    ];

    private const MAHASISWA_ALLOWED_STATUSES = [
        SuratKeteranganAktifApplication::STATUS_DRAFT,
        SuratKeteranganAktifApplication::STATUS_REVISION,
        SuratKeteranganAktifApplication::STATUS_REJECTED,
        SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        SuratKeteranganAktifApplication::STATUS_COMPLETED,
    ];

    public function __construct(
        private SuratKeteranganAktifPhaseResolver $phaseResolver,
        private LetterDocumentArtifactService $artifactService,
        private LetterAssignmentService $assignmentService,
        private AcademicRoutingService $academicRoutingService,
    ) {
    }

    public function canAccess(User $user, SuratKeteranganAktifApplication $application, string $audience): bool
    {
        return match ($audience) {
            self::AUDIENCE_TENDIK => $user->role === 'tendik'
                && $this->assignmentService->canHandle($user, SuratKeteranganAktifApplication::LETTER_TYPE),
            self::AUDIENCE_AKADEMIK => $user->role === 'akademik'
                && $this->academicRoutingService->canViewDetail($user, $application),
            self::AUDIENCE_MAHASISWA => $user->role === 'mahasiswa'
                && (int) $application->user_id === (int) $user->id
                && in_array($application->status, self::MAHASISWA_ALLOWED_STATUSES, true),
            default => false,
        };
    }

    public function resolve(SuratKeteranganAktifApplication $application): SuratKeteranganAktifGeneratedPreviewResult
    {
        $phase = $this->phaseResolver->phaseFor($application);
        if ($phase) {
            return $this->resolveCurrentPhase($application, $phase);
        }

        if (in_array($application->status, [
            SuratKeteranganAktifApplication::STATUS_REVISION,
            SuratKeteranganAktifApplication::STATUS_REJECTED,
        ], true)) {
            return $this->resolveFallbackPhase($application);
        }

        return $this->unavailable();
    }

    private function resolveCurrentPhase(
        SuratKeteranganAktifApplication $application,
        string $phase,
    ): SuratKeteranganAktifGeneratedPreviewResult {
        $artifact = $this->artifactService->latestArtifact(
            SuratKeteranganAktifApplication::LETTER_TYPE,
            (int) $application->getKey(),
            $phase,
        );

        if (!$artifact) {
            return $this->unavailable($phase);
        }

        return $this->resultForArtifact($artifact, $phase);
    }

    private function resolveFallbackPhase(
        SuratKeteranganAktifApplication $application,
    ): SuratKeteranganAktifGeneratedPreviewResult {
        foreach (self::FALLBACK_PHASES as $phase) {
            $artifact = $this->artifactService->latestReadyArtifact(
                SuratKeteranganAktifApplication::LETTER_TYPE,
                (int) $application->getKey(),
                $phase,
            );

            if ($artifact) {
                return $this->resultForArtifact($artifact, $phase);
            }
        }

        foreach (self::FALLBACK_PHASES as $phase) {
            $artifact = $this->artifactService->latestArtifact(
                SuratKeteranganAktifApplication::LETTER_TYPE,
                (int) $application->getKey(),
                $phase,
            );

            if ($artifact) {
                return $this->resultForArtifact($artifact, $phase);
            }
        }

        return $this->unavailable();
    }

    private function resultForArtifact(
        LetterDocumentArtifact $artifact,
        string $phase,
    ): SuratKeteranganAktifGeneratedPreviewResult {
        if ($artifact->status === LetterDocumentArtifact::STATUS_GENERATING) {
            return new SuratKeteranganAktifGeneratedPreviewResult(
                SuratKeteranganAktifGeneratedPreviewResult::STATE_GENERATING,
                409,
                'Dokumen pratinjau masih sedang dibuat. Silakan coba lagi beberapa saat.',
                'artifact_generating',
                $phase,
                $artifact,
            );
        }

        if ($artifact->status === LetterDocumentArtifact::STATUS_FAILED) {
            return new SuratKeteranganAktifGeneratedPreviewResult(
                SuratKeteranganAktifGeneratedPreviewResult::STATE_FAILED,
                503,
                'Dokumen pratinjau belum dapat tersedia karena proses pembuatan terakhir gagal. Silakan coba lagi nanti.',
                'artifact_failed',
                $phase,
                $artifact,
            );
        }

        if ($artifact->status !== LetterDocumentArtifact::STATUS_READY || !$artifact->pdf_path) {
            return $this->unavailable($phase, $artifact);
        }

        if (!Storage::disk('local')->exists($artifact->pdf_path)) {
            return $this->unavailable($phase, $artifact);
        }

        return new SuratKeteranganAktifGeneratedPreviewResult(
            SuratKeteranganAktifGeneratedPreviewResult::STATE_READY,
            200,
            '',
            'artifact_ready',
            $phase,
            $artifact,
            Storage::disk('local')->path($artifact->pdf_path),
        );
    }

    private function unavailable(
        ?string $phase = null,
        ?LetterDocumentArtifact $artifact = null,
    ): SuratKeteranganAktifGeneratedPreviewResult {
        return new SuratKeteranganAktifGeneratedPreviewResult(
            SuratKeteranganAktifGeneratedPreviewResult::STATE_UNAVAILABLE,
            404,
            'Dokumen pratinjau belum tersedia.',
            'artifact_unavailable',
            $phase,
            $artifact,
        );
    }
}
