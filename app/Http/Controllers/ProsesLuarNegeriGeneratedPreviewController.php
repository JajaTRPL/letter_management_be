<?php

namespace App\Http\Controllers;

use App\Models\ProsesLuarNegeriApplication;
use App\Services\ProsesLuarNegeriGeneratedPreviewAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only generated-preview endpoint for PLN private artifact PDFs.
 */
class ProsesLuarNegeriGeneratedPreviewController extends Controller
{
    public function tendik(
        Request $request,
        ProsesLuarNegeriApplication $application,
        ProsesLuarNegeriGeneratedPreviewAccessService $previewAccessService,
    ): BinaryFileResponse|JsonResponse {
        return $this->streamForAudience(
            $request,
            $application,
            $previewAccessService,
            ProsesLuarNegeriGeneratedPreviewAccessService::AUDIENCE_TENDIK,
        );
    }

    public function akademik(
        Request $request,
        ProsesLuarNegeriApplication $application,
        ProsesLuarNegeriGeneratedPreviewAccessService $previewAccessService,
    ): BinaryFileResponse|JsonResponse {
        return $this->streamForAudience(
            $request,
            $application,
            $previewAccessService,
            ProsesLuarNegeriGeneratedPreviewAccessService::AUDIENCE_AKADEMIK,
        );
    }

    public function mahasiswa(
        Request $request,
        ProsesLuarNegeriApplication $application,
        ProsesLuarNegeriGeneratedPreviewAccessService $previewAccessService,
    ): BinaryFileResponse|JsonResponse {
        return $this->streamForAudience(
            $request,
            $application,
            $previewAccessService,
            ProsesLuarNegeriGeneratedPreviewAccessService::AUDIENCE_MAHASISWA,
        );
    }

    private function streamForAudience(
        Request $request,
        ProsesLuarNegeriApplication $application,
        ProsesLuarNegeriGeneratedPreviewAccessService $previewAccessService,
        string $audience,
    ): BinaryFileResponse|JsonResponse {
        $user = $request->user();
        abort_unless(
            $user && $previewAccessService->canAccess($user, $application, $audience),
            403,
            'Tidak berwenang melihat pratinjau dokumen ini.',
        );

        $result = $previewAccessService->resolve($application);
        if (!$result->isReady()) {
            return response()->json([
                'message' => $result->message,
                'reason' => $result->reason,
            ], $result->httpStatus);
        }

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $this->filename($application, $result->phase) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($this->isPdfjsPreviewRequest($request)) {
            $headers = [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Vary' => 'X-DTEDI-PDFJS-Preview, X-Requested-With, Accept',
            ];
        }

        $response = response()->file($result->absolutePath, $headers);

        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function isPdfjsPreviewRequest(Request $request): bool
    {
        return $request->headers->get('X-DTEDI-PDFJS-Preview') === '1';
    }

    private function filename(ProsesLuarNegeriApplication $application, ?string $phase): string
    {
        return sprintf(
            'proses-luar-negeri-%d-%s.pdf',
            $application->id,
            $phase ?: 'preview',
        );
    }
}
