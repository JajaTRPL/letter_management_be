<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class BeasiswaGeneratedPreviewAccessService
{
    public const AUDIENCE_TENDIK = 'tendik';
    public const AUDIENCE_AKADEMIK = 'akademik';
    public const AUDIENCE_MAHASISWA = 'mahasiswa';

    /**
     * Fallback order for off-path statuses. The most advanced available
     * artifact wins; we never fabricate or generate from this read path.
     */
    private const FALLBACK_PHASES = [
        LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
    ];

    private const MAHASISWA_ALLOWED_STATUSES = [
        ScholarshipApplication::STATUS_DRAFT,
        ScholarshipApplication::STATUS_REVISION,
        ScholarshipApplication::STATUS_REJECTED,
        ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ScholarshipApplication::STATUS_COMPLETED,
    ];

    public function __construct(
        private BeasiswaPhaseResolver $phaseResolver,
        private LetterDocumentArtifactService $artifactService,
        private LetterAssignmentService $assignmentService,
        private AcademicRoutingService $academicRoutingService,
    ) {
    }

    public function canAccess(User $user, ScholarshipApplication $application, string $audience): bool
    {
        return match ($audience) {
            self::AUDIENCE_TENDIK => $user->role === 'tendik'
                && $this->assignmentService->canHandle($user, ScholarshipApplication::LETTER_TYPE),
            self::AUDIENCE_AKADEMIK => $user->role === 'akademik'
                && $this->academicRoutingService->canViewDetail($user, $application),
            self::AUDIENCE_MAHASISWA => $user->role === 'mahasiswa'
                && (int) $application->user_id === (int) $user->id
                && in_array($application->status, self::MAHASISWA_ALLOWED_STATUSES, true),
            default => false,
        };
    }

    public function resolve(ScholarshipApplication $application): BeasiswaGeneratedPreviewResult
    {
        $phase = $this->phaseResolver->phaseFor($application);
        if ($phase) {
            return $this->resolveCurrentPhase($application, $phase);
        }

        if (in_array($application->status, [
            ScholarshipApplication::STATUS_REVISION,
            ScholarshipApplication::STATUS_REJECTED,
        ], true)) {
            return $this->resolveFallbackPhase($application);
        }

        return $this->unavailable();
    }

    private function resolveCurrentPhase(
        ScholarshipApplication $application,
        string $phase,
    ): BeasiswaGeneratedPreviewResult {
        $artifact = $this->artifactService->latestArtifact(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            $phase,
        );

        if (!$artifact) {
            return $this->unavailable($phase);
        }

        return $this->resultForArtifact($artifact, $phase);
    }

    private function resolveFallbackPhase(ScholarshipApplication $application): BeasiswaGeneratedPreviewResult
    {
        foreach (self::FALLBACK_PHASES as $phase) {
            $artifact = $this->artifactService->latestReadyArtifact(
                ScholarshipApplication::LETTER_TYPE,
                $application->id,
                $phase,
            );

            if ($artifact) {
                return $this->resultForArtifact($artifact, $phase);
            }
        }

        foreach (self::FALLBACK_PHASES as $phase) {
            $artifact = $this->artifactService->latestArtifact(
                ScholarshipApplication::LETTER_TYPE,
                $application->id,
                $phase,
            );

            if ($artifact) {
                return $this->resultForArtifact($artifact, $phase);
            }
        }

        return $this->unavailable();
    }

    private function resultForArtifact(LetterDocumentArtifact $artifact, string $phase): BeasiswaGeneratedPreviewResult
    {
        if ($artifact->status === LetterDocumentArtifact::STATUS_GENERATING) {
            return new BeasiswaGeneratedPreviewResult(
                BeasiswaGeneratedPreviewResult::STATE_GENERATING,
                409,
                'Dokumen pratinjau masih sedang dibuat. Silakan coba lagi beberapa saat.',
                'artifact_generating',
                $phase,
                $artifact,
            );
        }

        if ($artifact->status === LetterDocumentArtifact::STATUS_FAILED) {
            return new BeasiswaGeneratedPreviewResult(
                BeasiswaGeneratedPreviewResult::STATE_FAILED,
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

        return new BeasiswaGeneratedPreviewResult(
            BeasiswaGeneratedPreviewResult::STATE_READY,
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
    ): BeasiswaGeneratedPreviewResult {
        return new BeasiswaGeneratedPreviewResult(
            BeasiswaGeneratedPreviewResult::STATE_UNAVAILABLE,
            404,
            'Dokumen pratinjau belum tersedia.',
            'artifact_unavailable',
            $phase,
            $artifact,
        );
    }
}
