<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LetterAttachmentPreviewResponseService
{
    public function make(LetterAttachmentAccessResult $attachment): StreamedResponse
    {
        return response()->stream(
            function () use ($attachment): void {
                $stream = Storage::disk($attachment->disk())->readStream($attachment->path());
                if (!is_resource($stream)) {
                    return;
                }

                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
