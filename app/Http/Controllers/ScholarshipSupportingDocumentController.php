<?php

namespace App\Http\Controllers;

use App\Models\ScholarshipApplication;
use App\Services\LetterAttachmentAccessService;
use App\Services\LetterAttachmentAuthorizationService;
use App\Services\LetterAttachmentPreviewResponseService;
use Illuminate\Support\Facades\Auth;

class ScholarshipSupportingDocumentController extends Controller
{
    public function __construct(
        private LetterAttachmentAccessService $accessService,
        private LetterAttachmentAuthorizationService $authorizationService,
        private LetterAttachmentPreviewResponseService $previewResponseService,
    ) {
    }

    public function preview(ScholarshipApplication $application, string $field)
    {
        if (!$this->accessService->supports(ScholarshipApplication::LETTER_TYPE, $field)) {
            abort(404);
        }

        $user = Auth::user();
        if (!$user || !$this->authorizationService->canPreview($user, $application, ScholarshipApplication::LETTER_TYPE)) {
            abort(403);
        }

        $attachment = $this->accessService->resolve($application, ScholarshipApplication::LETTER_TYPE, $field);
        if (!$attachment) {
            abort(404);
        }

        return $this->previewResponseService->make($attachment);
    }
}
