<?php

namespace App\Http\Controllers;

use App\Models\ProsesLuarNegeriApplication;
use App\Services\LetterDocumentAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProsesLuarNegeriFinalDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        ProsesLuarNegeriApplication $application,
        LetterDocumentAccessService $documentAccess,
    ): BinaryFileResponse|JsonResponse {
        $decision = $documentAccess->finalDownload(
            $application,
            $request->user(),
            ProsesLuarNegeriApplication::LETTER_TYPE,
        );

        if (!$decision->allowedToDownload() || $decision->absolutePath() === null) {
            return $this->error($decision->message(), $decision->reason(), $decision->status());
        }

        $response = response()->download(
            $decision->absolutePath(),
            $this->filename($application),
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );

        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function error(string $message, string $reason, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'reason' => $reason,
        ], $status);
    }

    private function filename(ProsesLuarNegeriApplication $application): string
    {
        return 'proses-luar-negeri-' . $application->id . '.pdf';
    }
}
