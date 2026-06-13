<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratTugasApplication;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only access and phase resolution for Surat Tugas generated-preview PDFs.
 * Generation remains owned by workflow transitions; this service never mutates
 * state and never exposes public/raw paths. Mirrors the canonical Magang
 * generated-preview access pattern.
 */
class SuratTugasGeneratedPreviewAccessService
{
    public const AUDIENCE_TENDIK = 'tendik';
    public const AUDIENCE_AKADEMIK = 'akademik';
    public const AUDIENCE_MAHASISWA = 'mahasiswa';

    private const FALLBACK_PHASES = [
        LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
        LetterDocumentArtifact::PHASE_PRODI_REVIEW,
        LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
    ];

    private const MAHASISWA_ALLOWED_STATUSES = [
        SuratTugasApplication::STATUS_DRAFT,
        SuratTugasApplication::STATUS_REVISION,
        SuratTugasApplication::STATUS_REJECTED,
        SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        SuratTugasApplication::STATUS_COMPLETED,
    ];

    public function __construct(
        private SuratTugasPhaseResolver $phaseResolver,
        private LetterDocumentArtifactService $artifactService,
        private LetterAssignmentService $assignmentService,
        private AcademicRoutingService $academicRoutingService,
    ) {
    }

    public function canAccess(User $user, SuratTugasApplication $application, string $audience): bool
    {
        return match ($audience) {
            self::AUDIENCE_TENDIK => $user->role === 'tendik'
                && $this->assignmentService->canHandle($user, SuratTugasApplication::LETTER_TYPE),
            self::AUDIENCE_AKADEMIK => $user->role === 'akademik'
                && $this->academicRoutingService->canViewDetail($user, $application),
            self::AUDIENCE_MAHASISWA => $user->role === 'mahasiswa'
                && (int) $application->user_id === (int) $user->id
                && in_array($application->status, self::MAHASISWA_ALLOWED_STATUSES, true),
            default => false,
        };
    }

    public function resolve(SuratTugasApplication $application): SuratTugasGeneratedPreviewResult
    {
        $phase = $this->phaseResolver->phaseFor($application);
        if ($phase) {
            return $this->resolveCurrentPhase($application, $phase);
        }

        if (in_array($application->status, [
            SuratTugasApplication::STATUS_REVISION,
            SuratTugasApplication::STATUS_REJECTED,
        ], true)) {
            return $this->resolveFallbackPhase($application);
        }

        return $this->unavailable();
    }

    private function resolveCurrentPhase(
        SuratTugasApplication $application,
        string $phase,
    ): SuratTugasGeneratedPreviewResult {
        $artifact = $this->artifactService->latestArtifact(
            SuratTugasApplication::LETTER_TYPE,
            (int) $application->getKey(),
            $phase,
        );

        if (!$artifact) {
            return $this->unavailable($phase);
        }

        return $this->resultForArtifact($artifact, $phase);
    }

    private function resolveFallbackPhase(
        SuratTugasApplication $application,
    ): SuratTugasGeneratedPreviewResult {
        foreach (self::FALLBACK_PHASES as $phase) {
            $artifact = $this->artifactService->latestReadyArtifact(
                SuratTugasApplication::LETTER_TYPE,
                (int) $application->getKey(),
                $phase,
            );

            if ($artifact) {
                return $this->resultForArtifact($artifact, $phase);
            }
        }

        foreach (self::FALLBACK_PHASES as $phase) {
            $artifact = $this->artifactService->latestArtifact(
                SuratTugasApplication::LETTER_TYPE,
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
    ): SuratTugasGeneratedPreviewResult {
        if ($artifact->status === LetterDocumentArtifact::STATUS_GENERATING) {
            return new SuratTugasGeneratedPreviewResult(
                SuratTugasGeneratedPreviewResult::STATE_GENERATING,
                409,
                'Dokumen pratinjau masih sedang dibuat. Silakan coba lagi beberapa saat.',
                'artifact_generating',
                $phase,
                $artifact,
            );
        }

        if ($artifact->status === LetterDocumentArtifact::STATUS_FAILED) {
            return new SuratTugasGeneratedPreviewResult(
                SuratTugasGeneratedPreviewResult::STATE_FAILED,
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

        return new SuratTugasGeneratedPreviewResult(
            SuratTugasGeneratedPreviewResult::STATE_READY,
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
    ): SuratTugasGeneratedPreviewResult {
        return new SuratTugasGeneratedPreviewResult(
            SuratTugasGeneratedPreviewResult::STATE_UNAVAILABLE,
            404,
            'Dokumen pratinjau belum tersedia.',
            'artifact_unavailable',
            $phase,
            $artifact,
        );
    }
}
