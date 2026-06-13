<?php

namespace App\Services;

use App\Models\LetterApplicationAttachment;
use App\Support\LetterAttachmentDefinitionRegistry;
use App\Support\LetterTypeRegistry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class LetterAttachmentRequirementService
{
    /**
     * @return list<string>
     */
    public function requiredDocumentKeys(string $letterType): array
    {
        $letter = LetterAttachmentDefinitionRegistry::forLetter($letterType);
        $documents = is_array($letter) ? ($letter['documents'] ?? []) : [];

        $required = [];
        foreach ($documents as $documentKey => $definition) {
            if (($definition['required_on_submit'] ?? false) === true) {
                $required[] = $documentKey;
            }
        }

        return $required;
    }

    /**
     * @return list<string>
     */
    public function missingRequiredDocumentKeys(string $letterType, int $applicationId): array
    {
        $canonicalType = LetterTypeRegistry::canonicalize($letterType);
        $requiredKeys = $this->requiredDocumentKeys($letterType);

        if (!$canonicalType || $requiredKeys === []) {
            return [];
        }

        if (!Schema::hasTable('letter_application_attachments')) {
            return $requiredKeys;
        }

        $attachments = LetterApplicationAttachment::query()
            ->where('letter_type', $canonicalType)
            ->where('application_id', $applicationId)
            ->whereIn('document_key', $requiredKeys)
            ->get()
            ->keyBy('document_key');

        $missing = [];
        foreach ($requiredKeys as $documentKey) {
            $definition = LetterAttachmentDefinitionRegistry::document($canonicalType, $documentKey);
            $attachment = $attachments->get($documentKey);

            if (!$definition || !$attachment instanceof LetterApplicationAttachment || !$this->isAvailableRegistryAttachment($attachment, $definition)) {
                $missing[] = $documentKey;
            }
        }

        return $missing;
    }

    public function legacyValidationAttribute(string $letterType, string $documentKey): string
    {
        $definition = LetterAttachmentDefinitionRegistry::document($letterType, $documentKey);
        $legacy = is_array($definition) ? ($definition['legacy'] ?? null) : null;
        $attribute = is_array($legacy) ? ($legacy['attribute'] ?? null) : null;

        return is_string($attribute) && $attribute !== '' ? $attribute : $documentKey;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function isAvailableRegistryAttachment(LetterApplicationAttachment $attachment, array $definition): bool
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
