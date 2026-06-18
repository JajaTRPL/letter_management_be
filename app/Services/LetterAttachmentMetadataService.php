<?php

namespace App\Services;

use App\Models\LetterApplicationAttachment;
use App\Support\LetterAttachmentDefinitionRegistry;
use App\Support\LetterTypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use stdClass;

class LetterAttachmentMetadataService
{
    /**
     * @return array<string, array{exists: bool, original_filename: string|null, mime_type: string|null, size_bytes: int|null, preview_available: bool}>|stdClass
     */
    public function forApplication(Model $application, string $letterType): array|stdClass
    {
        if (!$application->exists || $application->getKey() === null) {
            return $this->emptyMetadata($letterType);
        }

        return $this->forApplicationId($letterType, (int) $application->getKey());
    }

    /**
     * @return array<string, array{exists: bool, original_filename: string|null, mime_type: string|null, size_bytes: int|null, preview_available: bool}>|stdClass
     */
    public function forApplicationId(string $letterType, int $applicationId): array|stdClass
    {
        $canonicalType = LetterTypeRegistry::canonicalize($letterType);
        $letter = LetterAttachmentDefinitionRegistry::forLetter($letterType);
        $documents = is_array($letter) ? ($letter['documents'] ?? []) : [];

        if (!$canonicalType || $documents === []) {
            return new stdClass();
        }

        $metadata = [];
        foreach (array_keys($documents) as $documentKey) {
            $metadata[$documentKey] = $this->missingDocument();
        }

        if (!Schema::hasTable('letter_application_attachments')) {
            return $metadata;
        }

        $attachments = LetterApplicationAttachment::query()
            ->where('letter_type', $canonicalType)
            ->where('application_id', $applicationId)
            ->whereIn('document_key', array_keys($documents))
            ->get()
            ->keyBy('document_key');

        foreach ($documents as $documentKey => $definition) {
            $attachment = $attachments->get($documentKey);
            if (!$attachment instanceof LetterApplicationAttachment) {
                continue;
            }

            if (!$this->isPreviewableRegistryAttachment($attachment, $definition)) {
                continue;
            }

            $metadata[$documentKey] = [
                'exists' => true,
                'original_filename' => $attachment->original_filename,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'preview_available' => true,
            ];
        }

        return $metadata;
    }

    /**
     * @return array<string, array{exists: bool, original_filename: string|null, mime_type: string|null, size_bytes: int|null, preview_available: bool}>|stdClass
     */
    private function emptyMetadata(string $letterType): array|stdClass
    {
        $letter = LetterAttachmentDefinitionRegistry::forLetter($letterType);
        $documents = is_array($letter) ? ($letter['documents'] ?? []) : [];

        $metadata = [];
        foreach (array_keys($documents) as $documentKey) {
            $metadata[$documentKey] = $this->missingDocument();
        }

        return $metadata === [] ? new stdClass() : $metadata;
    }

    /**
     * @return array{exists: false, original_filename: null, mime_type: null, size_bytes: null, preview_available: false}
     */
    private function missingDocument(): array
    {
        return [
            'exists' => false,
            'original_filename' => null,
            'mime_type' => null,
            'size_bytes' => null,
            'preview_available' => false,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function isPreviewableRegistryAttachment(LetterApplicationAttachment $attachment, array $definition): bool
    {
        $expectedDisk = $definition['storage_disk'] ?? null;
        $expectedPrefix = $definition['storage_prefix'] ?? null;
        $mimeTypes = $definition['mime_types'] ?? [];

        if (!is_string($expectedDisk) || !is_string($expectedPrefix)) {
            return false;
        }

        if ($attachment->storage_disk !== $expectedDisk) {
            return false;
        }

        if ($attachment->mime_type && !in_array($attachment->mime_type, $mimeTypes, true)) {
            return false;
        }

        $path = $this->normalizeStoredPath($attachment->storage_path);

        return $path !== null
            && str_starts_with($path, $expectedPrefix)
            && Storage::disk($expectedDisk)->exists($path);
    }

    private function normalizeStoredPath(?string $stored): ?string
    {
        if (!is_string($stored) || trim($stored) === '') {
            return null;
        }

        $path = str_replace('\\', '/', trim($stored));
        if (str_contains($path, "\0") || str_contains($path, '..')) {
            return null;
        }

        return ltrim($path, '/');
    }
}
