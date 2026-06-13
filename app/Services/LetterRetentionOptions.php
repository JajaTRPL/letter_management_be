<?php

namespace App\Services;

use Illuminate\Support\Carbon;

final class LetterRetentionOptions
{
    public function __construct(
        public readonly bool $execute = false,
        public readonly ?string $letterType = null,
        public readonly ?int $applicationId = null,
        public readonly ?string $category = null,
        public readonly int $batch = 100,
        public readonly bool $manifest = false,
        public readonly ?Carbon $now = null,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly string $trigger = 'system',
        public readonly ?int $actorId = null,
        public readonly ?string $reason = null,
    ) {
    }
}
