<?php

namespace App\Services;

use App\Enums\LetterAttachmentBackfillClassification;

/**
 * Immutable plan row for one legacy supporting-document candidate produced by
 * LetterAttachmentBackfillService. Carries only safe, relative/redacted data —
 * never an absolute filesystem path and never a public or signed URL.
 */
final class LetterAttachmentBackfillPlanItem
{
    public function __construct(
        public readonly string $letterType,
        public readonly int $applicationId,
        public readonly string $documentKey,
        public readonly LetterAttachmentBackfillClassification $classification,
        public readonly ?string $sourceDisk,
        public readonly ?string $sourcePathRelative,
        public readonly bool $sourceExists,
        public readonly ?string $sourceMime,
        public readonly ?int $sourceSizeBytes,
        public readonly ?string $sourceChecksumSha256,
        public readonly ?int $existingRegistryId,
        public readonly ?string $existingRegistryChecksum,
        public readonly ?string $targetDisk,
        public readonly ?string $targetPrefix,
        public readonly string $message,
    ) {
    }

    /**
     * Ordered, machine-readable report row. Keys mirror the documented D2C
     * report contract. No absolute paths; the source path is kept relative.
     *
     * @return array<string, scalar|null>
     */
    public function toReportRow(): array
    {
        return [
            'letter_type' => $this->letterType,
            'application_id' => $this->applicationId,
            'document_key' => $this->documentKey,
            'classification' => $this->classification->value,
            'source_disk' => $this->sourceDisk,
            'source_path_redacted_or_relative' => $this->sourcePathRelative,
            'source_exists' => $this->sourceExists,
            'source_mime' => $this->sourceMime,
            'source_size_bytes' => $this->sourceSizeBytes,
            'source_checksum_sha256' => $this->sourceChecksumSha256,
            'existing_registry_id' => $this->existingRegistryId,
            'existing_registry_checksum' => $this->existingRegistryChecksum,
            'target_disk' => $this->targetDisk,
            'target_prefix' => $this->targetPrefix,
            'message' => $this->message,
        ];
    }
}
