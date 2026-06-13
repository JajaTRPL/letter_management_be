<?php

namespace App\Http\Controllers;

use App\Models\SuratPengantarMagangApplication;
use App\Services\LetterAttachmentAccessService;
use App\Services\LetterAttachmentAuthorizationService;
use App\Services\LetterAttachmentPreviewResponseService;
use Illuminate\Support\Facades\Auth;

/**
 * Dedicated, preview-compatible access to Surat Pengantar Magang supporting
 * documents (currently the uploaded proposal PDF).
 *
 * Mirrors ScholarshipSupportingDocumentController exactly: same authorization
 * matrix, same safe path normalization, and the same inline (NON-attachment)
 * response headers so the frontend protected PDF.js viewer can render the
 * bytes without triggering a browser download. It deliberately does NOT reuse
 * the generic /api/storage route, which is download-oriented
 * (response()->download() sets Content-Disposition: attachment).
 */
class MagangSupportingDocumentController extends Controller
{
    public function __construct(
        private LetterAttachmentAccessService $accessService,
        private LetterAttachmentAuthorizationService $authorizationService,
        private LetterAttachmentPreviewResponseService $previewResponseService,
    ) {
    }

    public function preview(SuratPengantarMagangApplication $application, string $field)
    {
        if (!$this->accessService->supports(SuratPengantarMagangApplication::LETTER_TYPE, $field)) {
            abort(404);
        }

        $user = Auth::user();
        if (!$user || !$this->authorizationService->canPreview($user, $application, SuratPengantarMagangApplication::LETTER_TYPE)) {
            abort(403);
        }

        $attachment = $this->accessService->resolve($application, SuratPengantarMagangApplication::LETTER_TYPE, $field);
        if (!$attachment) {
            abort(404);
        }

        return $this->previewResponseService->make($attachment);
    }
}
