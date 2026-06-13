<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\User;
use App\Support\LetterWorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

class LetterDocumentAccessService
{
    public function __construct(
        private readonly LetterDocumentArtifactService $artifacts,
        private readonly LetterRetentionSummaryService $retentionSummary,
    ) {
    }

    public function ensureOwner(Model $application, User $user): void
    {
        abort_unless((int) $application->getAttribute('user_id') === (int) $user->id, 403);
    }

    public function canComplete(Model $application): bool
    {
        return $application->getAttribute('status') === LetterWorkflowStatus::READY_FOR_STUDENT_REVIEW;
    }

    public function finalDownload(Model $application, ?User $user, string $letterType): LetterFinalDownloadAccessResult
    {
        if (!$user || $user->role !== 'mahasiswa' || (int) $application->getAttribute('user_id') !== (int) $user->id) {
            return LetterFinalDownloadAccessResult::denied(
                'Tidak berwenang mengunduh dokumen final ini.',
                'forbidden',
                403,
            );
        }

        if ($application->getAttribute('status') !== LetterWorkflowStatus::COMPLETED) {
            return LetterFinalDownloadAccessResult::denied(
                'Dokumen final hanya tersedia setelah pengajuan selesai.',
                'application_not_completed',
                403,
            );
        }

        $artifact = $this->artifacts->latestArtifact(
            $letterType,
            (int) $application->getKey(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        if (!$artifact) {
            return $this->unavailable();
        }

        if ($artifact->status === LetterDocumentArtifact::STATUS_GENERATING) {
            return LetterFinalDownloadAccessResult::denied(
                'Dokumen final masih sedang dibuat. Silakan coba lagi beberapa saat.',
                'artifact_generating',
                409,
            );
        }

        if ($artifact->status === LetterDocumentArtifact::STATUS_FAILED) {
            return LetterFinalDownloadAccessResult::denied(
                'Dokumen final belum dapat tersedia karena proses pembuatan terakhir gagal. Silakan coba lagi nanti.',
                'artifact_failed',
                503,
            );
        }

        if ($artifact->status !== LetterDocumentArtifact::STATUS_READY) {
            return $this->unavailable();
        }

        $path = $this->normalizeArtifactPdfPath($artifact->pdf_path, $letterType, (int) $application->getKey());
        if (!$path) {
            return $this->unavailable();
        }

        try {
            $finalDownloadAvailable = ($this->retentionSummary->forApplication($application, $letterType)['final_download_available'] ?? false) === true;
        } catch (Throwable) {
            return $this->unavailable();
        }

        if (!$finalDownloadAvailable) {
            return $this->unavailable();
        }

        try {
            $disk = Storage::disk('local');
            if (!$disk->exists($path)) {
                return $this->unavailable();
            }

            return LetterFinalDownloadAccessResult::allowed($disk->path($path));
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    private function unavailable(): LetterFinalDownloadAccessResult
    {
        return LetterFinalDownloadAccessResult::denied(
            'Dokumen final PDF belum tersedia.',
            'artifact_unavailable',
            404,
        );
    }

    private function normalizeArtifactPdfPath(?string $stored, string $letterType, int $applicationId): ?string
    {
        if (!is_string($stored) || trim($stored) === '') {
            return null;
        }

        $path = str_replace('\\', '/', trim($stored, '/'));
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));

        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            return null;
        }

        return str_starts_with($path, 'letter-document-artifacts/' . $letterType . '/' . $applicationId . '/')
            && str_ends_with(strtolower($path), '.pdf')
                ? $path
                : null;
    }
}
