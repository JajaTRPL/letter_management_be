<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class DocumentConverterException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context  Diagnostic context (driver, source path, http status, etc.).
     *                                       Safe to log; should not contain raw response bodies that may
     *                                       be large or contain PII.
     */
    public function __construct(string $message, public readonly array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
