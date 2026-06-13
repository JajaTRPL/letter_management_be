<?php

namespace App\Services;

use Illuminate\Support\Carbon;

final class LetterRetentionActionResult
{
    public function __construct(
        public readonly string $letterType,
        public readonly int $applicationId,
        public readonly string $category,
        public readonly string $action,
        public readonly string $subjectType,
        public readonly ?int $subjectId,
        public readonly string $status,
        public readonly ?Carbon $eligibleAt = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $checksumSha256 = null,
        public readonly ?string $storageDisk = null,
        public readonly ?string $storagePathHash = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toManifestArray(): array
    {
        return [
            'letter_type' => $this->letterType,
            'application_id' => $this->applicationId,
            'category' => $this->category,
            'action' => $this->action,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'status' => $this->status,
            'eligible_at' => $this->eligibleAt?->toIso8601String(),
            'error_code' => $this->errorCode,
            'checksum_sha256' => $this->checksumSha256,
            'storage_disk' => $this->storageDisk,
            'storage_path_hash' => $this->storagePathHash,
        ];
    }
}
