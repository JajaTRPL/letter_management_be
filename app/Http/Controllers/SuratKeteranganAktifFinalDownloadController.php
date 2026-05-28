<?php

namespace App\Http\Controllers;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use App\Services\LetterDocumentArtifactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SuratKeteranganAktifFinalDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        SuratKeteranganAktifApplication $application,
        LetterDocumentArtifactService $artifactService,
    ): BinaryFileResponse|JsonResponse {
        $user = $request->user();
        if (!$user || $user->role !== 'mahasiswa' || (int) $application->user_id !== (int) $user->id) {
            return $this->error(
                'Tidak berwenang mengunduh dokumen final ini.',
                'forbidden',
                403,
            );
        }

        if ($application->status !== SuratKeteranganAktifApplication::STATUS_COMPLETED) {
            return $this->error(
                'Dokumen final hanya tersedia setelah pengajuan selesai.',
                'application_not_completed',
                403,
            );
        }

        $artifact = $artifactService->latestArtifact(
            SuratKeteranganAktifApplication::LETTER_TYPE,
            (int) $application->getKey(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        if (!$artifact) {
            return $this->unavailable();
        }

        if ($artifact->status === LetterDocumentArtifact::STATUS_GENERATING) {
            return $this->error(
                'Dokumen final masih sedang dibuat. Silakan coba lagi beberapa saat.',
                'artifact_generating',
                409,
            );
        }

        if ($artifact->status === LetterDocumentArtifact::STATUS_FAILED) {
            return $this->error(
                'Dokumen final belum dapat tersedia karena proses pembuatan terakhir gagal. Silakan coba lagi nanti.',
                'artifact_failed',
                503,
            );
        }

        if (
            $artifact->status !== LetterDocumentArtifact::STATUS_READY
            || !$this->isExpectedPrivatePdfPath($artifact->pdf_path)
            || !Storage::disk('local')->exists($artifact->pdf_path)
        ) {
            return $this->unavailable();
        }

        $response = response()->download(
            Storage::disk('local')->path($artifact->pdf_path),
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

    private function unavailable(): JsonResponse
    {
        return $this->error(
            'Dokumen final PDF belum tersedia.',
            'artifact_unavailable',
            404,
        );
    }

    private function error(string $message, string $reason, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'reason' => $reason,
        ], $status);
    }

    private function isExpectedPrivatePdfPath(?string $path): bool
    {
        if (!is_string($path) || trim($path) === '') {
            return false;
        }

        $path = str_replace('\\', '/', trim($path));

        return !str_contains($path, '..')
            && str_starts_with(
                $path,
                'letter-document-artifacts/' . SuratKeteranganAktifApplication::LETTER_TYPE . '/',
            )
            && str_ends_with(strtolower($path), '.pdf');
    }

    private function filename(SuratKeteranganAktifApplication $application): string
    {
        return 'surat-keterangan-aktif-' . $application->id . '.pdf';
    }
}
