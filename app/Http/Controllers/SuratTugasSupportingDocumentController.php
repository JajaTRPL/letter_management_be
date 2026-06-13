<?php

namespace App\Http\Controllers;

use App\Models\SuratTugasApplication;
use App\Services\LetterAttachmentAccessService;
use App\Services\LetterAttachmentAuthorizationService;
use App\Services\LetterAttachmentPreviewResponseService;
use Illuminate\Support\Facades\Auth;

/**
 * Dedicated, preview-compatible access to Surat Tugas supporting documents:
 * the uploaded proposal PDF and the uploaded Surat Pengantar Magang PDF
 * (S2 minimal = uploaded files; the linked Completed-Magang selector is deferred).
 *
 * SECURITY (S2c-0): unlike the legacy Magang/Beasiswa supporting-doc controllers
 * that read the PUBLIC disk (storage/app/public is symlinked to public/storage,
 * so those uploads are raw web-accessible — inherited debt), Surat Tugas stores
 * its supporting PDFs on the PRIVATE `local` disk under `surat-tugas/supporting/`.
 * They are never symlinked / never raw-accessible and are served ONLY through
 * this authenticated endpoint as inline application/pdf (no attachment, no
 * /api/storage, no raw path leak).
 */
class SuratTugasSupportingDocumentController extends Controller
{
    public function __construct(
        private LetterAttachmentAccessService $accessService,
        private LetterAttachmentAuthorizationService $authorizationService,
        private LetterAttachmentPreviewResponseService $previewResponseService,
    ) {
    }

    public function preview(SuratTugasApplication $application, string $field)
    {
        if (!$this->accessService->supports(SuratTugasApplication::LETTER_TYPE, $field)) {
            abort(404);
        }

        $user = Auth::user();
        if (!$user || !$this->authorizationService->canPreview($user, $application, SuratTugasApplication::LETTER_TYPE)) {
            abort(403);
        }

        $attachment = $this->accessService->resolve($application, SuratTugasApplication::LETTER_TYPE, $field);
        if (!$attachment) {
            abort(404);
        }

        return $this->previewResponseService->make($attachment);
    }
}
