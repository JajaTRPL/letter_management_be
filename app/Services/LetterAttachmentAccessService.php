<?php

namespace App\Services;

use App\Models\LetterApplicationAttachment;
use App\Support\LetterAttachmentDefinitionRegistry;
use App\Support\LetterTypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class LetterAttachmentAccessService
{
    public function supports(string $letterType, string $documentKey): bool
    {
        return LetterAttachmentDefinitionRegistry::document($letterType, $documentKey) !== null;
    }

    public function resolve(Model $application, string $letterType, string $documentKey): ?LetterAttachmentAccessResult
    {
        $canonicalType = LetterTypeRegistry::canonicalize($letterType);
        $letter = LetterAttachmentDefinitionRegistry::forLetter($letterType);
        $definition = LetterAttachmentDefinitionRegistry::document($letterType, $documentKey);

        if (!$canonicalType || !$letter || !$definition || !$this->matchesApplication($application, $letter)) {
            return null;
        }

        if (!Schema::hasTable('letter_application_attachments')) {
            return null;
        }

        $attachment = LetterApplicationAttachment::query()
            ->where('letter_type', $canonicalType)
            ->where('application_id', $application->getKey())
            ->where('document_key', $documentKey)
            ->first();

        if (!$attachment) {
            return null;
        }

        return $this->resolveRegistryAttachment($attachment, $documentKey, $definition);
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
    private function resolveRegistryAttachment(
        LetterApplicationAttachment $attachment,
        string $documentKey,
        array $definition,
    ): ?LetterAttachmentAccessResult {
        $expectedDisk = $definition['storage_disk'] ?? null;
        $expectedPrefix = $definition['storage_prefix'] ?? null;
        $mimeTypes = $definition['mime_types'] ?? [];

        if (
            !is_string($expectedDisk)
            || !is_string($expectedPrefix)
            || $attachment->storage_disk !== $expectedDisk
            || ($attachment->mime_type && !in_array($attachment->mime_type, $mimeTypes, true))
        ) {
            return null;
        }

        $path = $this->normalizeStoredPath($attachment->storage_path);
        if (!$path || !str_starts_with($path, $expectedPrefix) || !Storage::disk($expectedDisk)->exists($path)) {
            return null;
        }

        return new LetterAttachmentAccessResult(
            $documentKey,
            $expectedDisk,
            $path,
            $attachment->mime_type ?: 'application/pdf',
            $this->filename($attachment->original_filename, $path),
            LetterAttachmentAccessResult::SOURCE_REGISTRY,
        );
    }

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

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $decoded) === 1) {
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

    private function filename(?string $originalFilename, string $path): string
    {
        $candidate = is_string($originalFilename) && $originalFilename !== ''
            ? str_replace('\\', '/', $originalFilename)
            : $path;

        return basename($candidate);
    }
}
