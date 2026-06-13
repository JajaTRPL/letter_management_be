<?php

namespace App\Services;

use App\Enums\LetterAttachmentBackfillClassification as State;
use App\Models\LetterApplicationAttachment;
use App\Support\LetterAttachmentDefinitionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * D2C backfill planner/executor for legacy supporting documents.
 *
 * The PLAN path (dry-run) is pure-read: it classifies every legacy candidate,
 * verifies source safety/existence/MIME, and computes a SHA-256 checksum,
 * without touching the DB or storage. The EXECUTE path copies READY_TO_COPY
 * sources to the private registry prefix, verifies the destination checksum,
 * and persists the registry row transactionally — never deleting or mutating
 * the legacy source/column. Execution is gated by the Artisan command; this
 * service never runs itself.
 */
class LetterAttachmentBackfillService
{
    private const MARKER_SCHEME = 'attachment://';
    private const PRIVATE_DISK = 'local';

    /**
     * Build the dry-run plan. Read-only.
     *
     * @param array{letter_type?: string|null, application_id?: int|null} $filters
     * @return list<LetterAttachmentBackfillPlanItem>
     */
    public function plan(array $filters = []): array
    {
        $items = [];

        foreach ($this->definitions($filters['letter_type'] ?? null) as $letterType => $letter) {
            $modelClass = $letter['application_model'] ?? null;
            if (!is_string($modelClass) || !is_a($modelClass, Model::class, true)) {
                continue;
            }

            /** @var array<string, array<string, mixed>> $documents */
            $documents = $letter['documents'] ?? [];
            if ($documents === []) {
                continue; // SKA / PLN have no documents — nothing to plan.
            }

            $query = $modelClass::query();
            if (!empty($filters['application_id'])) {
                $query->whereKey($filters['application_id']);
            }

            $query->orderBy((new $modelClass)->getKeyName())->chunkById(200, function ($applications) use (
                &$items,
                $letterType,
                $documents,
            ): void {
                foreach ($applications as $application) {
                    foreach ($documents as $documentKey => $definition) {
                        $items[] = $this->classify($application, $letterType, (string) $documentKey, $definition);
                    }
                }
            });
        }

        return $items;
    }

    /**
     * Execute the backfill for READY_TO_COPY candidates only. Caller (command)
     * is responsible for the --execute/--confirm guard; this method itself never
     * runs from the dry-run path. Returns the per-item results after attempting
     * the copy+verify+persist for actionable rows.
     *
     * @param array{letter_type?: string|null, application_id?: int|null} $filters
     * @return list<LetterAttachmentBackfillPlanItem>
     */
    public function execute(array $filters = []): array
    {
        $results = [];
        foreach ($this->plan($filters) as $item) {
            if (!$item->classification->isActionable()) {
                $results[] = $item;
                continue;
            }
            $results[] = $this->copyAndPersist($item);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function classify(
        Model $application,
        string $letterType,
        string $documentKey,
        array $definition,
    ): LetterAttachmentBackfillPlanItem {
        $legacy = is_array($definition['legacy'] ?? null) ? $definition['legacy'] : [];
        $legacyAttribute = $legacy['attribute'] ?? null;
        $legacyDisk = $legacy['disk'] ?? null;
        $legacyPrefix = $legacy['prefix'] ?? null;
        $targetDisk = self::PRIVATE_DISK;
        $targetPrefix = is_string($definition['storage_prefix'] ?? null) ? $definition['storage_prefix'] : null;

        $registry = $this->existingRegistryRow($letterType, (int) $application->getKey(), $documentKey);

        $make = fn (State $state, string $message, array $extra = []): LetterAttachmentBackfillPlanItem =>
            new LetterAttachmentBackfillPlanItem(
                letterType: $letterType,
                applicationId: (int) $application->getKey(),
                documentKey: $documentKey,
                classification: $state,
                sourceDisk: $extra['source_disk'] ?? (is_string($legacyDisk) ? $legacyDisk : null),
                sourcePathRelative: $extra['source_path'] ?? null,
                sourceExists: $extra['source_exists'] ?? false,
                sourceMime: $extra['source_mime'] ?? null,
                sourceSizeBytes: $extra['source_size'] ?? null,
                sourceChecksumSha256: $extra['source_checksum'] ?? null,
                existingRegistryId: $registry?->id,
                existingRegistryChecksum: $registry?->checksum_sha256,
                targetDisk: $targetDisk,
                targetPrefix: $targetPrefix,
                message: $message,
            );

        if (! is_string($legacyAttribute) || $legacyAttribute === '') {
            return $make(State::UNKNOWN_DEFINITION, 'Legacy mapping is incomplete for this definition.');
        }

        if (! Schema::hasColumn($application->getTable(), $legacyAttribute)) {
            return $make(State::RETIRED_COLUMN_ABSENT, 'Legacy column has been retired from this schema; nothing to back-fill.');
        }

        $rawValue = $application->getAttribute($legacyAttribute);

        // Marker handling first: a D2B marker is never a filesystem path.
        if (is_string($rawValue) && str_starts_with($rawValue, self::MARKER_SCHEME)) {
            return $registry
                ? $make(State::MARKER_BACKED_REGISTRY_OK, 'D2B marker backed by a valid registry row.')
                : $make(State::MARKER_WITHOUT_REGISTRY_BLOCKER, 'D2B marker present without a backing registry row.');
        }

        if (!is_string($rawValue) || trim($rawValue) === '') {
            return $make(State::LEGACY_VALUE_EMPTY, 'Legacy column is empty; nothing to back-fill.');
        }

        if (!is_string($legacyDisk) || !is_string($legacyPrefix)) {
            return $make(State::UNKNOWN_DEFINITION, 'Legacy mapping is incomplete for this definition.');
        }

        $normalized = $this->normalizeStoredPath($rawValue);
        if ($normalized === null) {
            return $make(State::SOURCE_PATH_UNSAFE, 'Legacy path is unsafe (traversal, null byte, or empty).');
        }
        if (!str_starts_with($normalized, $legacyPrefix)) {
            return $make(State::SOURCE_PREFIX_INVALID, 'Legacy path is outside the expected prefix.', [
                'source_path' => $normalized,
            ]);
        }

        $disk = Storage::disk($legacyDisk);
        if (!$disk->exists($normalized)) {
            return $make(State::SOURCE_FILE_MISSING, 'Legacy source file does not exist.', [
                'source_path' => $normalized,
            ]);
        }

        $contents = $disk->get($normalized);
        if (!is_string($contents)) {
            return $make(State::SOURCE_FILE_MISSING, 'Legacy source file could not be read.', [
                'source_path' => $normalized,
                'source_exists' => true,
            ]);
        }

        $mime = $this->detectMime($disk, $normalized);
        $allowedMimes = is_array($definition['mime_types'] ?? null) ? $definition['mime_types'] : ['application/pdf'];
        if (!in_array($mime, $allowedMimes, true)) {
            return $make(State::SOURCE_MIME_INVALID, 'Legacy source MIME is not an allowed PDF.', [
                'source_path' => $normalized,
                'source_exists' => true,
                'source_mime' => $mime,
                'source_size' => strlen($contents),
            ]);
        }

        $checksum = hash('sha256', $contents);
        $extra = [
            'source_path' => $normalized,
            'source_exists' => true,
            'source_mime' => $mime,
            'source_size' => strlen($contents),
            'source_checksum' => $checksum,
        ];

        if ($registry) {
            if ($registry->checksum_sha256 === null || $registry->checksum_sha256 === '') {
                return $make(State::DESTINATION_CONFLICT, 'Registry row exists without a checksum to compare.', $extra);
            }

            return $registry->checksum_sha256 === $checksum
                ? $make(State::ALREADY_BACKFILLED_MATCH, 'Registry row already matches the legacy source checksum.', $extra)
                : $make(State::REGISTRY_CONFLICT, 'Registry row checksum disagrees with the legacy source.', $extra);
        }

        return $make(State::READY_TO_COPY, 'Legacy source is valid and ready to copy into the private registry.', $extra);
    }

    /**
     * EXECUTE-only: copy a READY_TO_COPY source into the private registry prefix,
     * verify the destination checksum, and persist the registry row. Never
     * deletes or mutates the legacy source/column. On any failure, the freshly
     * copied destination is removed so it does not orphan.
     */
    private function copyAndPersist(LetterAttachmentBackfillPlanItem $item): LetterAttachmentBackfillPlanItem
    {
        $sourceDisk = Storage::disk((string) $item->sourceDisk);
        $contents = $sourceDisk->get((string) $item->sourcePathRelative);
        if (!is_string($contents)) {
            return $this->withState($item, State::SOURCE_FILE_MISSING, 'Source disappeared before copy.');
        }

        $targetPrefix = rtrim((string) $item->targetPrefix, '/') . '/';
        $targetPath = $targetPrefix . $item->applicationId . '/' . Str::uuid() . '.pdf';
        $target = Storage::disk(self::PRIVATE_DISK);

        if ($target->exists($targetPath)) {
            return $this->withState($item, State::DESTINATION_CONFLICT, 'Generated target path already exists.');
        }

        if (!$target->put($targetPath, $contents)) {
            return $this->withState($item, State::SOURCE_FILE_MISSING, 'Failed to write private target file.');
        }

        // Verify the destination matches the source byte-for-byte.
        $copied = $target->get($targetPath);
        if (!is_string($copied) || hash('sha256', $copied) !== $item->sourceChecksumSha256) {
            $this->safeDelete($target, $targetPath, $targetPrefix);

            return $this->withState($item, State::REGISTRY_CONFLICT, 'Destination checksum mismatch; copy discarded.');
        }

        try {
            DB::transaction(function () use ($item, $targetPath): void {
                LetterApplicationAttachment::query()->create([
                    'letter_type' => $item->letterType,
                    'application_id' => $item->applicationId,
                    'document_key' => $item->documentKey,
                    'original_filename' => basename((string) $item->sourcePathRelative),
                    'mime_type' => $item->sourceMime,
                    'size_bytes' => $item->sourceSizeBytes,
                    'storage_disk' => self::PRIVATE_DISK,
                    'storage_path' => $targetPath,
                    'checksum_sha256' => $item->sourceChecksumSha256,
                    'uploaded_by' => null,
                ]);
            });
        } catch (Throwable $exception) {
            $this->safeDelete($target, $targetPath, $targetPrefix);
            Log::warning('Backfill persistence failed; copied destination removed.', [
                'letter_type' => $item->letterType,
                'application_id' => $item->applicationId,
                'document_key' => $item->documentKey,
                'exception' => $exception->getMessage(),
            ]);

            return $this->withState($item, State::REGISTRY_CONFLICT, 'Registry persistence failed; copy discarded.');
        }

        return $this->withState($item, State::ALREADY_BACKFILLED_MATCH, 'Backfilled and verified.');
    }

    /**
     * @param string|null $letterTypeFilter
     * @return array<string, array<string, mixed>>
     */
    private function definitions(?string $letterTypeFilter): array
    {
        $all = LetterAttachmentDefinitionRegistry::all();
        if ($letterTypeFilter === null || $letterTypeFilter === '') {
            return $all;
        }

        $canonical = \App\Support\LetterTypeRegistry::canonicalize($letterTypeFilter);

        return $canonical !== null && isset($all[$canonical]) ? [$canonical => $all[$canonical]] : [];
    }

    private function existingRegistryRow(string $letterType, int $applicationId, string $documentKey): ?LetterApplicationAttachment
    {
        return LetterApplicationAttachment::query()
            ->where('letter_type', $letterType)
            ->where('application_id', $applicationId)
            ->where('document_key', $documentKey)
            ->first();
    }

    /** @param \Illuminate\Contracts\Filesystem\Filesystem $disk */
    private function detectMime($disk, string $path): ?string
    {
        try {
            $mime = $disk->mimeType($path);

            return is_string($mime) && $mime !== '' ? $mime : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function withState(
        LetterAttachmentBackfillPlanItem $item,
        State $state,
        string $message,
    ): LetterAttachmentBackfillPlanItem {
        return new LetterAttachmentBackfillPlanItem(
            letterType: $item->letterType,
            applicationId: $item->applicationId,
            documentKey: $item->documentKey,
            classification: $state,
            sourceDisk: $item->sourceDisk,
            sourcePathRelative: $item->sourcePathRelative,
            sourceExists: $item->sourceExists,
            sourceMime: $item->sourceMime,
            sourceSizeBytes: $item->sourceSizeBytes,
            sourceChecksumSha256: $item->sourceChecksumSha256,
            existingRegistryId: $item->existingRegistryId,
            existingRegistryChecksum: $item->existingRegistryChecksum,
            targetDisk: $item->targetDisk,
            targetPrefix: $item->targetPrefix,
            message: $message,
        );
    }

    /** @param \Illuminate\Contracts\Filesystem\Filesystem $disk */
    private function safeDelete($disk, string $path, string $prefix): void
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '' || str_contains($normalized, '..') || !str_starts_with($normalized, $prefix)) {
            return;
        }
        try {
            if ($disk->exists($normalized)) {
                $disk->delete($normalized);
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to clean up backfill destination after error.', [
                'path' => $normalized,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Mirrors LetterAttachmentAccessService::normalizeStoredPath safety rules,
     * but returns null on any unsafe input so the caller can classify precisely.
     */
    private function normalizeStoredPath(?string $stored): ?string
    {
        if (!is_string($stored) || trim($stored) === '') {
            return null;
        }

        $decoded = $stored;
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        if (str_contains($decoded, "\0")) {
            return null;
        }

        $path = parse_url($decoded, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $decoded;
        $path = str_replace('\\', '/', trim($path, '/'));
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));

        if ($path === '' || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }
        if (str_starts_with($path, 'api/storage/')) {
            $path = substr($path, strlen('api/storage/'));
        }

        $path = ltrim($path, '/');

        return $path !== '' ? $path : null;
    }
}
