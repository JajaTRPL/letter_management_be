<?php

namespace App\Services;

final class LetterAttachmentAccessResult
{
    public const SOURCE_REGISTRY = 'registry';
    public const SOURCE_LEGACY = 'legacy';

    public function __construct(
        private string $documentKey,
        private string $disk,
        private string $path,
        private string $mimeType,
        private string $filename,
        private string $source,
    ) {
    }

    public function disk(): string
    {
        return $this->disk;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * Safe for future descriptor responses. Internal disk and path stay private.
     *
     * @return array{document_key: string, filename: string, mime_type: string}
     */
    public function publicMetadata(): array
    {
        return [
            'document_key' => $this->documentKey,
            'filename' => $this->filename,
            'mime_type' => $this->mimeType,
        ];
    }
}
