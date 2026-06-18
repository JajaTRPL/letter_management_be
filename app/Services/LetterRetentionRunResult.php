<?php

namespace App\Services;

final class LetterRetentionRunResult
{
    /**
     * @param list<LetterRetentionActionResult> $actions
     */
    public function __construct(
        public readonly bool $schemaReady,
        public readonly bool $execute,
        public readonly array $actions = [],
        public readonly ?string $manifestPath = null,
        public readonly ?string $errorCode = null,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $counts = [];
        foreach ($this->actions as $action) {
            $counts[$action->status] = ($counts[$action->status] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    public function total(): int
    {
        return count($this->actions);
    }
}
