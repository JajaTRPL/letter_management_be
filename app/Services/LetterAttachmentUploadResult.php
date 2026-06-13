<?php

namespace App\Services;

use App\Models\LetterApplicationAttachment;

/**
 * Outcome of a successful supporting-document upload via
 * LetterAttachmentUploadService. Deliberately exposes no internal disk or
 * storage path to controllers/responses; only the persisted registry row is
 * surfaced.
 */
final class LetterAttachmentUploadResult
{
    public function __construct(
        private LetterApplicationAttachment $attachment,
    ) {
    }

    public function attachment(): LetterApplicationAttachment
    {
        return $this->attachment;
    }
}
