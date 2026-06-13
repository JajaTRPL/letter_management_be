<?php

namespace App\Services;

use App\Models\LetterApplicationAttachment;
use App\Models\LetterDocumentArtifact;
use App\Support\LetterAttachmentDefinitionRegistry;
use App\Support\LetterTypeRegistry;
use App\Support\LetterWorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class LetterRetentionSummaryService
{
    public function __construct(
        private readonly LetterRetentionPolicyService $policies,
        private readonly LetterDocumentArtifactService $artifacts,
    ) {
    }

    /**
     * @return array{
     *     completed_at: string|null,
     *     final_download_available: bool,
     *     final_download_expires_at: string|null,
     *     final_download_state: string,
     *     supporting_documents_state: string,
     *     intermediate_artifacts_state: string
     * }
     */
    public function forApplication(Model $application, string $letterType): array
    {
        $completedAt = $this->completedAt($application);
        $final = $this->finalDownloadSummary($application, $letterType, $completedAt);

        return [
            'completed_at' => $completedAt?->toIso8601String(),
            'final_download_available' => $final['available'],
            'final_download_expires_at' => $final['expires_at']?->toIso8601String(),
            'final_download_state' => $final['state'],
            'supporting_documents_state' => $this->supportingDocumentsState($application, $letterType, $completedAt),
            'intermediate_artifacts_state' => $this->intermediateArtifactsState($application, $letterType, $completedAt),
        ];
    }

    private function completedAt(Model $application): ?Carbon
    {
        if ($application->getAttribute('status') !== LetterWorkflowStatus::COMPLETED) {
            return null;
        }

        $completedAt = $application->getAttribute('completed_at');
        if ($completedAt instanceof Carbon) {
            return $completedAt;
        }

        if (is_string($completedAt) && trim($completedAt) !== '') {
            try {
                return Carbon::parse($completedAt);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array{available: bool, expires_at: Carbon|null, state: string}
     */
    private function finalDownloadSummary(Model $application, string $letterType, ?Carbon $completedAt): array
    {
        if (!$completedAt) {
            return [
                'available' => false,
                'expires_at' => null,
                'state' => 'not_started',
            ];
        }

        $expiresAt = $completedAt->copy()->addDays($this->policies->value('final_pdf_active_days', 30));
        $artifact = $this->artifacts->latestArtifact(
            $letterType,
            (int) $application->getKey(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        if (!$artifact) {
            return [
                'available' => false,
                'expires_at' => $expiresAt,
                'state' => 'unavailable',
            ];
        }

        if ($artifact->archive_purged_at) {
            return [
                'available' => false,
                'expires_at' => $expiresAt,
                'state' => 'archive_purged',
            ];
        }

        if ($artifact->archived_at) {
            return [
                'available' => false,
                'expires_at' => $expiresAt,
                'state' => 'archived',
            ];
        }

        if (Carbon::now()->greaterThanOrEqualTo($expiresAt)) {
            return [
                'available' => false,
                'expires_at' => $expiresAt,
                'state' => 'expired',
            ];
        }

        $available = $artifact->status === LetterDocumentArtifact::STATUS_READY
            && $this->hasReadableActivePdf($artifact, $letterType, (int) $application->getKey());

        return [
            'available' => $available,
            'expires_at' => $expiresAt,
            'state' => $available ? 'active' : 'unavailable',
        ];
    }

    private function supportingDocumentsState(Model $application, string $letterType, ?Carbon $completedAt): string
    {
        $letter = LetterAttachmentDefinitionRegistry::forLetter($letterType);
        $documents = is_array($letter) ? ($letter['documents'] ?? []) : [];
        if ($documents === []) {
            return 'not_applicable';
        }

        if (!$completedAt) {
            return 'not_started';
        }

        if (!Schema::hasTable('letter_application_attachments') || !Schema::hasColumn('letter_application_attachments', 'retention_deleted_at')) {
            return 'unknown';
        }

        $rows = LetterApplicationAttachment::query()
            ->where('letter_type', LetterTypeRegistry::canonicalize($letterType) ?? $letterType)
            ->where('application_id', (int) $application->getKey())
            ->whereIn('document_key', array_keys($documents))
            ->get();

        if ($rows->isEmpty()) {
            return 'unavailable';
        }

        $deleted = $rows->filter(fn (LetterApplicationAttachment $row): bool => $row->retention_deleted_at !== null)->count();
        if ($deleted === 0) {
            return 'retained';
        }

        return $deleted === $rows->count() ? 'deleted' : 'partially_deleted';
    }

    private function intermediateArtifactsState(Model $application, string $letterType, ?Carbon $completedAt): string
    {
        if (!$completedAt) {
            return 'not_started';
        }

        if (!Schema::hasTable('letter_document_artifacts') || !Schema::hasColumn('letter_document_artifacts', 'retention_deleted_at')) {
            return 'unknown';
        }

        $rows = LetterDocumentArtifact::query()
            ->forApplication($letterType, (int) $application->getKey())
            ->where('phase', '!=', LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW)
            ->get();

        if ($rows->isEmpty()) {
            return 'not_applicable';
        }

        $deleted = $rows->filter(fn (LetterDocumentArtifact $row): bool => $row->retention_deleted_at !== null)->count();
        if ($deleted === 0) {
            return 'retained';
        }

        return $deleted === $rows->count() ? 'deleted' : 'partially_deleted';
    }

    private function hasReadableActivePdf(LetterDocumentArtifact $artifact, string $letterType, int $applicationId): bool
    {
        $path = $this->normalizePath($artifact->pdf_path);

        return $path !== null
            && str_starts_with($path, 'letter-document-artifacts/' . $letterType . '/' . $applicationId . '/')
            && str_ends_with(strtolower($path), '.pdf')
            && Storage::disk('local')->exists($path);
    }

    private function normalizePath(?string $stored): ?string
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

        return $path;
    }
}
