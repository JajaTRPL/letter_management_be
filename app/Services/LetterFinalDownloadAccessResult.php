<?php

namespace App\Services;

class LetterFinalDownloadAccessResult
{
    private function __construct(
        private readonly bool $allowed,
        private readonly ?string $absolutePath,
        private readonly string $message,
        private readonly string $reason,
        private readonly int $status,
    ) {
    }

    public static function allowed(string $absolutePath): self
    {
        return new self(true, $absolutePath, '', '', 200);
    }

    public static function denied(string $message, string $reason, int $status): self
    {
        return new self(false, null, $message, $reason, $status);
    }

    public function allowedToDownload(): bool
    {
        return $this->allowed;
    }

    public function absolutePath(): ?string
    {
        return $this->absolutePath;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function status(): int
    {
        return $this->status;
    }
}
