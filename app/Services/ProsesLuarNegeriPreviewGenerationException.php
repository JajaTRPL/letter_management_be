<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class ProsesLuarNegeriPreviewGenerationException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, public readonly array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
