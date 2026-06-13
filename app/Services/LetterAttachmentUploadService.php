<?php

namespace App\Services;

use App\Models\LetterApplicationAttachment;
use App\Support\LetterAttachmentDefinitionRegistry;
use App\Support\LetterTypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Shared private-disk write path for letter supporting documents (D2B).
 *
 * Single responsibility: take a validated PDF for a registry-defined document
 * key, persist it to the PRIVATE local disk, and create/update the canonical
 * letter_application_attachments registry row transactionally — with orphan-safe
 * failure handling and after-commit replacement cleanup.
 *
 * Workflow-specific submit rules stay in the per-letter controllers; this
 * service never mutates application status or workflow columns and never
 * touches legacy public originals.
 */
class LetterAttachmentUploadService
{
    /** Always private. New writes never go to the public disk. */
    private const WRITE_DISK = 'local';

    /**
     * Store (or replace) a supporting document for a given application.
     *
     * Lifecycle: validate definition + file → write new private file → compute
     * metadata/checksum → transaction (lock existing row, upsert) → commit →
     * delete the replaced registry-managed private file (prefix-guarded).
     *
     * @throws RuntimeException when the definition/file is invalid or persistence fails.
     */
    public function store(
        Model $application,
        string $letterType,
        string $documentKey,
        UploadedFile $file,
        ?int $uploadedBy,
    ): LetterAttachmentUploadResult {
        $canonicalType = LetterTypeRegistry::canonicalize($letterType);
        $letter = LetterAttachmentDefinitionRegistry::forLetter($letterType);
        $definition = LetterAttachmentDefinitionRegistry::document($letterType, $documentKey);

        if (!$canonicalType || !$letter || !$definition) {
            throw new RuntimeException("Unknown supporting-document definition: {$letterType}/{$documentKey}.");
        }

        if (!$this->matchesApplication($application, $letter)) {
            throw new RuntimeException('Application does not match the supporting-document definition.');
        }

        $disk = $definition['storage_disk'] ?? null;
        if ($disk !== self::WRITE_DISK) {
            // Defensive: the registry is the contract, and every active document
            // targets the private local disk. Refuse to write anywhere else.
            throw new RuntimeException('Supporting documents must be written to the private local disk.');
        }

        $this->validateFile($file, $definition);

        $prefix = $this->normalizedPrefix($definition);
        $newPath = $this->writePrivateFile($file, $prefix, $application->getKey());

        try {
            $metadata = $this->metadata($file, $newPath);
        } catch (Throwable $exception) {
            $this->safeDeleteWrittenFile($newPath, $prefix);
            throw new RuntimeException('Failed to read uploaded file metadata.', 0, $exception);
        }

        try {
            [$attachment, $replacedPath] = DB::transaction(function () use (
                $canonicalType,
                $application,
                $documentKey,
                $newPath,
                $metadata,
                $uploadedBy,
            ) {
                $existing = LetterApplicationAttachment::query()
                    ->where('letter_type', $canonicalType)
                    ->where('application_id', $application->getKey())
                    ->where('document_key', $documentKey)
                    ->lockForUpdate()
                    ->first();

                $replacedPath = null;
                if ($existing && $existing->storage_disk === self::WRITE_DISK) {
                    $replacedPath = $existing->storage_path;
                }

                $attachment = LetterApplicationAttachment::query()->updateOrCreate(
                    [
                        'letter_type' => $canonicalType,
                        'application_id' => $application->getKey(),
                        'document_key' => $documentKey,
                    ],
                    [
                        'original_filename' => $metadata['original_filename'],
                        'mime_type' => $metadata['mime_type'],
                        'size_bytes' => $metadata['size_bytes'],
                        'storage_disk' => self::WRITE_DISK,
                        'storage_path' => $newPath,
                        'checksum_sha256' => $metadata['checksum_sha256'],
                        'uploaded_by' => $uploadedBy,
                    ],
                );

                return [$attachment, $replacedPath];
            });
        } catch (Throwable $exception) {
            // DB persistence failed: drop the just-written file so it does not
            // become an orphan, and leave any pre-existing row/file untouched.
            $this->safeDeleteWrittenFile($newPath, $prefix);
            throw new RuntimeException('Failed to persist supporting-document registry row.', 0, $exception);
        }

        // After-commit cleanup of the replaced registry-managed private file.
        // Prefix-guarded, and never deletes the file we just persisted. A
        // failure here must NOT roll back the valid new row/file.
        if ($replacedPath && $replacedPath !== $newPath) {
            $this->cleanupReplacedFile($replacedPath, $prefix);
        }

        return new LetterAttachmentUploadResult($attachment);
    }

    /**
     * @param array<string, mixed> $letter
     */
    private function matchesApplication(Model $application, array $letter): bool
    {
        $modelClass = $letter['application_model'] ?? null;

        return is_string($modelClass)
            && $application instanceof $modelClass
            && $application->exists
            && $application->getKey() !== null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function validateFile(UploadedFile $file, array $definition): void
    {
        if (!$file->isValid()) {
            throw new RuntimeException('Uploaded supporting document is not a valid file.');
        }

        $mimeTypes = $definition['mime_types'] ?? ['application/pdf'];
        $maxKb = (int) ($definition['max_kb'] ?? 2048);

        // Defense-in-depth: controllers already validate, but the shared writer
        // re-checks the registry PDF policy so no caller can bypass it. Trust the
        // SERVER-guessed (content/finfo-based) MIME, not the client-supplied one,
        // since the client value is attacker-controlled. The extension is an
        // additional gate, not the source of truth.
        $guessedMime = $file->getMimeType();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (!in_array($guessedMime, $mimeTypes, true) || $extension !== 'pdf') {
            throw new RuntimeException('Supporting document must be a PDF.');
        }

        if ($maxKb > 0 && $file->getSize() !== false && $file->getSize() > $maxKb * 1024) {
            throw new RuntimeException('Supporting document exceeds the maximum allowed size.');
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function normalizedPrefix(array $definition): string
    {
        $prefix = $definition['storage_prefix'] ?? null;
        if (!is_string($prefix) || trim($prefix) === '') {
            throw new RuntimeException('Supporting-document definition is missing a private storage prefix.');
        }

        return rtrim(str_replace('\\', '/', $prefix), '/') . '/';
    }

    private function writePrivateFile(UploadedFile $file, string $prefix, mixed $applicationId): string
    {
        // Namespaced, collision-safe internal path under the registry prefix.
        // Per-application segment keeps files grouped; UUID prevents collisions
        // and avoids leaking the original client filename into the path.
        $directory = $prefix . $applicationId;
        $filename = (string) Str::uuid() . '.pdf';

        $storedPath = $file->storeAs($directory, $filename, self::WRITE_DISK);
        if (!is_string($storedPath) || $storedPath === '') {
            throw new RuntimeException('Failed to write supporting document to private storage.');
        }

        return str_replace('\\', '/', $storedPath);
    }

    /**
     * @return array{original_filename: string, mime_type: string, size_bytes: int, checksum_sha256: string}
     */
    private function metadata(UploadedFile $file, string $storedPath): array
    {
        $contents = Storage::disk(self::WRITE_DISK)->get($storedPath);
        if (!is_string($contents)) {
            throw new RuntimeException('Stored supporting document could not be read back for hashing.');
        }

        $originalFilename = $this->sanitizeFilename($file->getClientOriginalName());

        return [
            'original_filename' => $originalFilename,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
        ];
    }

    /** Keep the registry filename well under varchar(255) storage columns. */
    private const MAX_FILENAME_LENGTH = 180;

    private function sanitizeFilename(?string $original): string
    {
        $candidate = is_string($original) && trim($original) !== '' ? $original : 'document.pdf';

        // Strip any directory components a client may have sent (both separators).
        $base = basename(str_replace('\\', '/', $candidate));

        // Remove control characters and path/query/fragment separators before
        // storing the client filename in registry metadata.
        $base = preg_replace('/[\x00-\x1F\x7F]/u', '', $base) ?? '';
        $base = str_replace(['/', '\\', '?', '#'], '_', $base);
        $base = trim($base);

        if ($base === '' || $base === '.' || $base === '..') {
            return 'document.pdf';
        }

        // Bound the length, preserving a trailing .pdf where present.
        if (mb_strlen($base) > self::MAX_FILENAME_LENGTH) {
            $hasPdf = str_ends_with(strtolower($base), '.pdf');
            $stem = $hasPdf ? mb_substr($base, 0, -4) : $base;
            $budget = self::MAX_FILENAME_LENGTH - ($hasPdf ? 4 : 0);
            $base = mb_substr($stem, 0, max(1, $budget)) . ($hasPdf ? '.pdf' : '');
        }

        return $base;
    }

    /**
     * Delete a file we just wrote on a failure path. Prefix-guarded so a bad
     * path can never escape the document's namespace.
     */
    private function safeDeleteWrittenFile(string $path, string $prefix): void
    {
        if (!$this->isWithinPrefix($path, $prefix)) {
            return;
        }

        try {
            if (Storage::disk(self::WRITE_DISK)->exists($path)) {
                Storage::disk(self::WRITE_DISK)->delete($path);
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to clean up supporting-document upload after error.', [
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function cleanupReplacedFile(string $path, string $prefix): void
    {
        $normalized = str_replace('\\', '/', trim($path));
        if (!$this->isWithinPrefix($normalized, $prefix)) {
            // Not a registry-managed private file under this document's prefix
            // (e.g. an old legacy public path). Never delete it in D2B.
            return;
        }

        try {
            if (Storage::disk(self::WRITE_DISK)->exists($normalized)) {
                Storage::disk(self::WRITE_DISK)->delete($normalized);
            }
        } catch (Throwable $exception) {
            // Keep the valid new row/file; replacement is already committed.
            Log::warning('Failed to delete replaced supporting-document file after commit.', [
                'path' => $normalized,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function isWithinPrefix(string $path, string $prefix): bool
    {
        $normalized = str_replace('\\', '/', trim($path));

        return $normalized !== ''
            && !str_contains($normalized, '..')
            && str_starts_with($normalized, $prefix);
    }
}
