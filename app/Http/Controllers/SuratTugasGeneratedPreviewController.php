<?php

namespace App\Http\Controllers;

use App\Models\SuratTugasApplication;
use App\Services\SuratTugasGeneratedPreviewAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only generated-preview endpoint for Surat Tugas private artifact PDFs.
 * Mirrors the canonical Magang generated-preview controller; generation is
 * owned by workflow transitions, never triggered here.
 */
class SuratTugasGeneratedPreviewController extends Controller
{
    public function tendik(
        Request $request,
        SuratTugasApplication $application,
        SuratTugasGeneratedPreviewAccessService $previewAccessService,
    ): BinaryFileResponse|JsonResponse {
        return $this->streamForAudience(
            $request,
            $application,
            $previewAccessService,
            SuratTugasGeneratedPreviewAccessService::AUDIENCE_TENDIK,
        );
    }

    public function akademik(
        Request $request,
        SuratTugasApplication $application,
        SuratTugasGeneratedPreviewAccessService $previewAccessService,
    ): BinaryFileResponse|JsonResponse {
        return $this->streamForAudience(
            $request,
            $application,
            $previewAccessService,
            SuratTugasGeneratedPreviewAccessService::AUDIENCE_AKADEMIK,
        );
    }

    public function mahasiswa(
        Request $request,
        SuratTugasApplication $application,
        SuratTugasGeneratedPreviewAccessService $previewAccessService,
    ): BinaryFileResponse|JsonResponse {
        return $this->streamForAudience(
            $request,
            $application,
            $previewAccessService,
            SuratTugasGeneratedPreviewAccessService::AUDIENCE_MAHASISWA,
        );
    }

    private function streamForAudience(
        Request $request,
        SuratTugasApplication $application,
        SuratTugasGeneratedPreviewAccessService $previewAccessService,
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

    private function filename(SuratTugasApplication $application, ?string $phase): string
    {
        return sprintf(
            'surat-tugas-%d-%s.pdf',
            $application->id,
            $phase ?: 'preview',
        );
    }
}
