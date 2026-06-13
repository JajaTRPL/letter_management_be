<?php

namespace App\Services;

use App\Models\LetterApplicationAttachment;
use App\Models\LetterDocumentArtifact;
use App\Models\LetterRetentionAction;
use App\Support\LetterAttachmentDefinitionRegistry;
use App\Support\LetterTypeRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LetterRetentionService
{
    public const CATEGORY_SUPPORTING_DOCUMENT = 'supporting_document';
    public const CATEGORY_INTERMEDIATE_ARTIFACT = 'intermediate_artifact';
    public const CATEGORY_FINAL_OFFICIAL_PDF = 'final_official_pdf';
    public const CATEGORY_ARCHIVED_FINAL_PDF = 'archived_final_pdf';

    public const CATEGORIES = [
        self::CATEGORY_SUPPORTING_DOCUMENT,
        self::CATEGORY_INTERMEDIATE_ARTIFACT,
        self::CATEGORY_FINAL_OFFICIAL_PDF,
        self::CATEGORY_ARCHIVED_FINAL_PDF,
    ];

    public function __construct(
        private readonly LetterRetentionApplicationRepository $applications,
        private readonly LetterRetentionArchiveService $archives,
        private readonly LetterRetentionManifestWriter $manifests,
        private readonly LetterRetentionPolicyService $policies,
    ) {
    }

    private ?LetterRetentionOptions $activeOptions = null;

    public function run(LetterRetentionOptions $options): LetterRetentionRunResult
    {
        $this->activeOptions = $options;
        $now = $options->now ?? Carbon::now();
        $manifestPath = $options->manifest ? $this->manifests->newPath($now) : null;

        if (!$this->schemaReady()) {
            $result = new LetterRetentionRunResult(
                schemaReady: false,
                execute: $options->execute,
                manifestPath: $manifestPath,
                errorCode: 'retention_schema_not_ready',
            );
            if ($manifestPath) {
                $this->manifests->write($result, $manifestPath);
            }

            return $result;
        }

        $actions = [];
        $this->collectSupportingDocuments($options, $now, $manifestPath, $actions);
        $this->collectIntermediateArtifacts($options, $now, $manifestPath, $actions);
        $this->collectFinalOfficialPdfs($options, $now, $manifestPath, $actions);
        $this->collectArchivedFinalPdfs($options, $now, $manifestPath, $actions);

        $result = new LetterRetentionRunResult(
            schemaReady: true,
            execute: $options->execute,
            actions: $actions,
            manifestPath: $manifestPath,
        );
        if ($manifestPath) {
            $this->manifests->write($result, $manifestPath);
        }

        return $result;
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('letter_application_attachments')
            && Schema::hasTable('letter_document_artifacts')
            && Schema::hasTable('letter_retention_actions')
            && Schema::hasColumn('letter_application_attachments', 'retention_deleted_at')
            && Schema::hasColumn('letter_document_artifacts', 'archived_at')
            && Schema::hasColumn('letter_document_artifacts', 'archive_purged_at');
    }

    /**
     * @param list<LetterRetentionActionResult> $actions
     */
    private function collectSupportingDocuments(
        LetterRetentionOptions $options,
        Carbon $now,
        ?string $manifestPath,
        array &$actions,
    ): void {
        if (!$this->categoryMatches($options, self::CATEGORY_SUPPORTING_DOCUMENT) || $this->batchFull($options, $actions)) {
            return;
        }

        $query = LetterApplicationAttachment::query()
            ->whereNull('retention_deleted_at')
            ->orderBy('id');
        $this->applyCommonFilters($query, $options);
        if (!$this->applySubjectFilter($query, $options, 'attachment')) {
            return;
        }

        foreach ($query->get() as $attachment) {
            if ($this->batchFull($options, $actions)) {
                return;
            }

            $completedAt = $this->applications->completedAt($attachment->letter_type, (int) $attachment->application_id);
            if (!$completedAt) {
                continue;
            }

            $eligibleAt = $completedAt->copy()->addDays($this->days('supporting_document_retention_days', 14));
            if ($now->lt($eligibleAt)) {
                continue;
            }

            $actions[] = $this->handleSupportingDocument($attachment, $eligibleAt, $now, $options, $manifestPath);
        }
    }

    private function handleSupportingDocument(
        LetterApplicationAttachment $attachment,
        Carbon $eligibleAt,
        Carbon $now,
        LetterRetentionOptions $options,
        ?string $manifestPath,
    ): LetterRetentionActionResult {
        $definition = LetterAttachmentDefinitionRegistry::document($attachment->letter_type, $attachment->document_key);
        $disk = is_array($definition) ? ($definition['storage_disk'] ?? null) : null;
        $prefix = is_array($definition) ? ($definition['storage_prefix'] ?? null) : null;
        $path = $this->normalizePath($attachment->storage_path);
        $pathHash = $path ? hash('sha256', $path) : null;

        if (!$options->execute) {
            return $this->plannedAction(
                $attachment->letter_type,
                (int) $attachment->application_id,
                self::CATEGORY_SUPPORTING_DOCUMENT,
                'delete',
                'attachment',
                (int) $attachment->id,
                $eligibleAt,
                $attachment->checksum_sha256,
                $attachment->storage_disk,
                $pathHash,
            );
        }

        if (!$this->applications->completedAt($attachment->letter_type, (int) $attachment->application_id)) {
            return $this->blockedSupporting($attachment, $eligibleAt, 'application_not_completed', $pathHash, $manifestPath, $now);
        }

        if (!is_string($disk) || !is_string($prefix) || $attachment->storage_disk !== $disk || !$path || !$this->pathWithin($path, $prefix)) {
            return $this->blockedSupporting($attachment, $eligibleAt, 'invalid_path', $pathHash, $manifestPath, $now);
        }

        $storage = Storage::disk($disk);
        if (!$storage->exists($path)) {
            $attachment->forceFill([
                'retention_deleted_at' => $now,
                'retention_status' => 'already_missing',
                'retention_manifest_path' => $manifestPath,
            ])->save();

            return $this->persistAction(
                $attachment->letter_type,
                (int) $attachment->application_id,
                self::CATEGORY_SUPPORTING_DOCUMENT,
                'delete',
                'attachment',
                (int) $attachment->id,
                'already_missing',
                $eligibleAt,
                null,
                $attachment->checksum_sha256,
                $disk,
                $pathHash,
                $manifestPath,
                $now,
            );
        }

        $contents = $storage->get($path);
        $actualChecksum = is_string($contents) ? hash('sha256', $contents) : null;
        if (
            is_string($attachment->checksum_sha256)
            && $attachment->checksum_sha256 !== ''
            && $actualChecksum !== $attachment->checksum_sha256
        ) {
            return $this->blockedSupporting($attachment, $eligibleAt, 'checksum_mismatch', $pathHash, $manifestPath, $now);
        }

        if (!$storage->delete($path)) {
            return $this->failedSupporting($attachment, $eligibleAt, 'delete_failed', $pathHash, $manifestPath, $now);
        }

        $attachment->forceFill([
            'retention_deleted_at' => $now,
            'retention_status' => 'deleted',
            'retention_manifest_path' => $manifestPath,
        ])->save();

        return $this->persistAction(
            $attachment->letter_type,
            (int) $attachment->application_id,
            self::CATEGORY_SUPPORTING_DOCUMENT,
            'delete',
            'attachment',
            (int) $attachment->id,
            'completed',
            $eligibleAt,
            null,
            $actualChecksum,
            $disk,
            $pathHash,
            $manifestPath,
            $now,
        );
    }

    /**
     * @param list<LetterRetentionActionResult> $actions
     */
    private function collectIntermediateArtifacts(
        LetterRetentionOptions $options,
        Carbon $now,
        ?string $manifestPath,
        array &$actions,
    ): void {
        if (!$this->categoryMatches($options, self::CATEGORY_INTERMEDIATE_ARTIFACT) || $this->batchFull($options, $actions)) {
            return;
        }

        $query = LetterDocumentArtifact::query()
            ->whereNull('retention_deleted_at')
            ->orderBy('id');
        $this->applyCommonFilters($query, $options);
        if (!$this->applySubjectFilter($query, $options, 'artifact')) {
            return;
        }

        foreach ($query->get() as $artifact) {
            if ($this->batchFull($options, $actions)) {
                return;
            }
            if ($this->isFinalOfficialArtifact($artifact)) {
                continue;
            }

            $completedAt = $this->applications->completedAt($artifact->letter_type, (int) $artifact->application_id);
            if (!$completedAt) {
                continue;
            }

            $eligibleAt = $completedAt->copy()->addDays($this->days('intermediate_artifact_retention_days', 14));
            if ($now->lt($eligibleAt)) {
                continue;
            }

            $actions[] = $this->handleIntermediateArtifact($artifact, $eligibleAt, $now, $options, $manifestPath);
        }
    }

    private function handleIntermediateArtifact(
        LetterDocumentArtifact $artifact,
        Carbon $eligibleAt,
        Carbon $now,
        LetterRetentionOptions $options,
        ?string $manifestPath,
    ): LetterRetentionActionResult {
        $paths = array_values(array_filter([
            $this->normalizePath($artifact->docx_path),
            $this->normalizePath($artifact->pdf_path),
        ]));
        $pathHash = $paths === [] ? null : hash('sha256', implode('|', $paths));

        if (!$options->execute) {
            return $this->plannedAction(
                $artifact->letter_type,
                (int) $artifact->application_id,
                self::CATEGORY_INTERMEDIATE_ARTIFACT,
                'delete',
                'artifact',
                (int) $artifact->id,
                $eligibleAt,
                $artifact->source_hash,
                'local',
                $pathHash,
            );
        }

        foreach ($paths as $path) {
            if (!$this->isArtifactPath($artifact, $path)) {
                return $this->persistArtifactAction($artifact, self::CATEGORY_INTERMEDIATE_ARTIFACT, 'delete', 'blocked', $eligibleAt, 'invalid_path', $pathHash, $manifestPath, $now);
            }
        }

        $deletedAny = false;
        foreach ($paths as $path) {
            $disk = Storage::disk('local');
            if (!$disk->exists($path)) {
                continue;
            }
            if (!$disk->delete($path)) {
                return $this->persistArtifactAction($artifact, self::CATEGORY_INTERMEDIATE_ARTIFACT, 'delete', 'failed', $eligibleAt, 'delete_failed', $pathHash, $manifestPath, $now);
            }
            $deletedAny = true;
        }

        $status = $deletedAny ? 'deleted' : 'already_missing';
        $artifact->forceFill([
            'retention_deleted_at' => $now,
            'retention_status' => $status,
            'retention_manifest_path' => $manifestPath,
        ])->save();

        return $this->persistArtifactAction(
            $artifact,
            self::CATEGORY_INTERMEDIATE_ARTIFACT,
            'delete',
            $deletedAny ? 'completed' : 'already_missing',
            $eligibleAt,
            null,
            $pathHash,
            $manifestPath,
            $now,
        );
    }

    /**
     * @param list<LetterRetentionActionResult> $actions
     */
    private function collectFinalOfficialPdfs(
        LetterRetentionOptions $options,
        Carbon $now,
        ?string $manifestPath,
        array &$actions,
    ): void {
        if (!$this->categoryMatches($options, self::CATEGORY_FINAL_OFFICIAL_PDF) || $this->batchFull($options, $actions)) {
            return;
        }

        $query = LetterDocumentArtifact::query()
            ->where('phase', LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->whereNull('archived_at')
            ->orderBy('id');
        $this->applyCommonFilters($query, $options);
        if (!$this->applySubjectFilter($query, $options, 'artifact')) {
            return;
        }

        foreach ($query->get() as $artifact) {
            if ($this->batchFull($options, $actions)) {
                return;
            }
            if (!$this->isFinalOfficialArtifact($artifact)) {
                continue;
            }

            $completedAt = $this->applications->completedAt($artifact->letter_type, (int) $artifact->application_id);
            if (!$completedAt) {
                continue;
            }

            $eligibleAt = $completedAt->copy()->addDays($this->days('final_pdf_active_days', 30));
            if ($now->lt($eligibleAt)) {
                continue;
            }

            $actions[] = $this->handleFinalOfficialPdf($artifact, $eligibleAt, $now, $options, $manifestPath);
        }
    }

    private function handleFinalOfficialPdf(
        LetterDocumentArtifact $artifact,
        Carbon $eligibleAt,
        Carbon $now,
        LetterRetentionOptions $options,
        ?string $manifestPath,
    ): LetterRetentionActionResult {
        $path = $this->normalizePath($artifact->pdf_path);
        $pathHash = $path ? hash('sha256', $path) : null;

        if (!$options->execute) {
            return $this->plannedAction(
                $artifact->letter_type,
                (int) $artifact->application_id,
                self::CATEGORY_FINAL_OFFICIAL_PDF,
                'archive',
                'artifact',
                (int) $artifact->id,
                $eligibleAt,
                null,
                'local',
                $pathHash,
            );
        }

        $archive = $this->archives->archiveFinalPdf($artifact);
        if ($archive['status'] !== 'completed') {
            return $this->persistArtifactAction(
                $artifact,
                self::CATEGORY_FINAL_OFFICIAL_PDF,
                'archive',
                $archive['status'],
                $eligibleAt,
                $archive['error_code'],
                $pathHash,
                $manifestPath,
                $now,
                $archive['checksum_sha256'],
            );
        }

        $local = Storage::disk('local');
        if (!$path || !$local->delete($path)) {
            return $this->persistArtifactAction($artifact, self::CATEGORY_FINAL_OFFICIAL_PDF, 'archive', 'failed', $eligibleAt, 'active_delete_failed', $pathHash, $manifestPath, $now, $archive['checksum_sha256']);
        }

        $artifact->forceFill([
            'retention_status' => 'archived',
            'retention_manifest_path' => $manifestPath,
            'archive_disk' => $archive['archive_disk'],
            'archive_path' => $archive['archive_path'],
            'archive_checksum_sha256' => $archive['checksum_sha256'],
            'archived_at' => $now,
        ])->save();

        return $this->persistArtifactAction(
            $artifact,
            self::CATEGORY_FINAL_OFFICIAL_PDF,
            'archive',
            'completed',
            $eligibleAt,
            null,
            $pathHash,
            $manifestPath,
            $now,
            $archive['checksum_sha256'],
        );
    }

    /**
     * @param list<LetterRetentionActionResult> $actions
     */
    private function collectArchivedFinalPdfs(
        LetterRetentionOptions $options,
        Carbon $now,
        ?string $manifestPath,
        array &$actions,
    ): void {
        if (!$this->categoryMatches($options, self::CATEGORY_ARCHIVED_FINAL_PDF) || $this->batchFull($options, $actions)) {
            return;
        }

        $query = LetterDocumentArtifact::query()
            ->whereNotNull('archived_at')
            ->whereNull('archive_purged_at')
            ->orderBy('id');
        $this->applyCommonFilters($query, $options);
        if (!$this->applySubjectFilter($query, $options, 'artifact')) {
            return;
        }

        foreach ($query->get() as $artifact) {
            if ($this->batchFull($options, $actions)) {
                return;
            }

            $archivedAt = $artifact->archived_at;
            if (!$archivedAt instanceof Carbon) {
                continue;
            }

            $eligibleAt = $archivedAt->copy()->addDays($this->days('archive.retention_days', 365));
            if ($now->lt($eligibleAt)) {
                continue;
            }

            $actions[] = $this->handleArchivedFinalPdf($artifact, $eligibleAt, $now, $options, $manifestPath);
        }
    }

    private function handleArchivedFinalPdf(
        LetterDocumentArtifact $artifact,
        Carbon $eligibleAt,
        Carbon $now,
        LetterRetentionOptions $options,
        ?string $manifestPath,
    ): LetterRetentionActionResult {
        $pathHash = is_string($artifact->archive_path) ? hash('sha256', $artifact->archive_path) : null;

        if (!$options->execute) {
            return $this->plannedAction(
                $artifact->letter_type,
                (int) $artifact->application_id,
                self::CATEGORY_ARCHIVED_FINAL_PDF,
                'purge',
                'artifact',
                (int) $artifact->id,
                $eligibleAt,
                $artifact->archive_checksum_sha256,
                $artifact->archive_disk,
                $pathHash,
            );
        }

        $purge = $this->archives->purgeArchivedFinalPdf($artifact);
        if (in_array($purge['status'], ['completed', 'already_missing'], true)) {
            $artifact->forceFill([
                'retention_status' => 'archive_purged',
                'retention_manifest_path' => $manifestPath,
                'archive_purged_at' => $now,
            ])->save();
        }

        return $this->persistArtifactAction(
            $artifact,
            self::CATEGORY_ARCHIVED_FINAL_PDF,
            'purge',
            $purge['status'],
            $eligibleAt,
            $purge['error_code'],
            $pathHash,
            $manifestPath,
            $now,
            $artifact->archive_checksum_sha256,
            $artifact->archive_disk,
        );
    }

    private function plannedAction(
        string $letterType,
        int $applicationId,
        string $category,
        string $action,
        string $subjectType,
        ?int $subjectId,
        Carbon $eligibleAt,
        ?string $checksum,
        ?string $disk,
        ?string $pathHash,
    ): LetterRetentionActionResult {
        return new LetterRetentionActionResult(
            $letterType,
            $applicationId,
            $category,
            $action,
            $subjectType,
            $subjectId,
            'dry_run',
            $eligibleAt,
            checksumSha256: $checksum,
            storageDisk: $disk,
            storagePathHash: $pathHash,
        );
    }

    private function blockedSupporting(
        LetterApplicationAttachment $attachment,
        Carbon $eligibleAt,
        string $errorCode,
        ?string $pathHash,
        ?string $manifestPath,
        Carbon $now,
    ): LetterRetentionActionResult {
        return $this->persistAction(
            $attachment->letter_type,
            (int) $attachment->application_id,
            self::CATEGORY_SUPPORTING_DOCUMENT,
            'delete',
            'attachment',
            (int) $attachment->id,
            'blocked',
            $eligibleAt,
            $errorCode,
            $attachment->checksum_sha256,
            $attachment->storage_disk,
            $pathHash,
            $manifestPath,
            $now,
        );
    }

    private function failedSupporting(
        LetterApplicationAttachment $attachment,
        Carbon $eligibleAt,
        string $errorCode,
        ?string $pathHash,
        ?string $manifestPath,
        Carbon $now,
    ): LetterRetentionActionResult {
        return $this->persistAction(
            $attachment->letter_type,
            (int) $attachment->application_id,
            self::CATEGORY_SUPPORTING_DOCUMENT,
            'delete',
            'attachment',
            (int) $attachment->id,
            'failed',
            $eligibleAt,
            $errorCode,
            $attachment->checksum_sha256,
            $attachment->storage_disk,
            $pathHash,
            $manifestPath,
            $now,
        );
    }

    private function persistArtifactAction(
        LetterDocumentArtifact $artifact,
        string $category,
        string $action,
        string $status,
        Carbon $eligibleAt,
        ?string $errorCode,
        ?string $pathHash,
        ?string $manifestPath,
        Carbon $now,
        ?string $checksum = null,
        ?string $disk = 'local',
    ): LetterRetentionActionResult {
        return $this->persistAction(
            $artifact->letter_type,
            (int) $artifact->application_id,
            $category,
            $action,
            'artifact',
            (int) $artifact->id,
            $status,
            $eligibleAt,
            $errorCode,
            $checksum ?? $artifact->source_hash,
            $disk,
            $pathHash,
            $manifestPath,
            $now,
        );
    }

    private function persistAction(
        string $letterType,
        int $applicationId,
        string $category,
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $status,
        Carbon $eligibleAt,
        ?string $errorCode,
        ?string $checksum,
        ?string $disk,
        ?string $pathHash,
        ?string $manifestPath,
        Carbon $now,
    ): LetterRetentionActionResult {
        $keyParts = [
            $letterType,
            $applicationId,
            $category,
            $action,
            $subjectType,
            $subjectId ?? 'none',
            $eligibleAt->toIso8601String(),
            $pathHash ?? 'none',
        ];
        if ($this->activeOptions?->trigger === 'manual') {
            $keyParts[] = (string) Str::uuid();
        }

        $key = hash('sha256', implode('|', $keyParts));

        $metadata = [
            'schema_version' => 1,
            'trigger' => $this->activeOptions?->trigger ?? 'system',
        ];
        if ($this->activeOptions?->actorId !== null) {
            $metadata['actor_id'] = $this->activeOptions->actorId;
        }
        if ($this->activeOptions?->reason !== null && $this->activeOptions->reason !== '') {
            $metadata['reason'] = $this->activeOptions->reason;
        }

        LetterRetentionAction::query()->updateOrCreate(
            ['action_key' => $key],
            [
                'letter_type' => $letterType,
                'application_id' => $applicationId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'category' => $category,
                'action' => $action,
                'status' => $status,
                'storage_disk' => $disk,
                'storage_path_hash' => $pathHash,
                'checksum_sha256' => $checksum,
                'eligible_at' => $eligibleAt,
                'executed_at' => $now,
                'error_code' => $errorCode,
                'manifest_reference' => $manifestPath,
                'metadata' => $metadata,
            ],
        );

        return new LetterRetentionActionResult(
            $letterType,
            $applicationId,
            $category,
            $action,
            $subjectType,
            $subjectId,
            $status,
            $eligibleAt,
            $errorCode,
            $checksum,
            $disk,
            $pathHash,
        );
    }

    private function isFinalOfficialArtifact(LetterDocumentArtifact $artifact): bool
    {
        if ($artifact->phase !== LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW) {
            return false;
        }

        $latest = LetterDocumentArtifact::query()
            ->forApplication($artifact->letter_type, (int) $artifact->application_id)
            ->forPhase(LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW)
            ->ready()
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();

        return $latest && (int) $latest->id === (int) $artifact->id;
    }

    private function applyCommonFilters($query, LetterRetentionOptions $options): void
    {
        if ($options->letterType) {
            $canonical = LetterTypeRegistry::canonicalize($options->letterType);
            $query->where('letter_type', $canonical ?? $options->letterType);
        }

        if ($options->applicationId) {
            $query->where('application_id', $options->applicationId);
        }
    }

    private function applySubjectFilter($query, LetterRetentionOptions $options, string $subjectType): bool
    {
        if ($options->subjectType !== null && $options->subjectType !== $subjectType) {
            return false;
        }

        if ($options->subjectId !== null) {
            $query->whereKey($options->subjectId);
        }

        return true;
    }

    /**
     * @param list<LetterRetentionActionResult> $actions
     */
    private function batchFull(LetterRetentionOptions $options, array $actions): bool
    {
        return count($actions) >= max(1, $options->batch);
    }

    private function categoryMatches(LetterRetentionOptions $options, string $category): bool
    {
        return $options->category === null || $options->category === $category;
    }

    private function days(string $key, int $default): int
    {
        if ($key === 'archive.retention_days') {
            return $this->policies->value('archive_retention_days', $default);
        }

        return $this->policies->value($key, $default);
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

    private function pathWithin(string $path, string $prefix): bool
    {
        return str_starts_with($path, rtrim(str_replace('\\', '/', $prefix), '/') . '/');
    }

    private function isArtifactPath(LetterDocumentArtifact $artifact, string $path): bool
    {
        return str_starts_with(
            $path,
            'letter-document-artifacts/' . $artifact->letter_type . '/' . $artifact->application_id . '/',
        ) && (str_ends_with(strtolower($path), '.pdf') || str_ends_with(strtolower($path), '.docx'));
    }
}
