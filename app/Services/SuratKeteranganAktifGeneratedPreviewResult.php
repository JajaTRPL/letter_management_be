<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;

final class SuratKeteranganAktifGeneratedPreviewResult
{
    public const STATE_READY = 'ready';
    public const STATE_UNAVAILABLE = 'unavailable';
    public const STATE_GENERATING = 'generating';
    public const STATE_FAILED = 'failed';

    public function __construct(
        public readonly string $state,
        public readonly int $httpStatus,
        public readonly string $message,
        public readonly string $reason,
        public readonly ?string $phase = null,
        public readonly ?LetterDocumentArtifact $artifact = null,
        public readonly ?string $absolutePath = null,
    ) {
    }

    public function isReady(): bool
    {
        return $this->state === self::STATE_READY;
    }
}
