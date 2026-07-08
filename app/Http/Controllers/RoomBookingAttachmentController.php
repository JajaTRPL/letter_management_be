<?php

namespace App\Http\Controllers;

use App\Enums\RoomBookingStatus;
use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingAttachmentService;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingReviewerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RoomBookingAttachmentController extends Controller
{
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomBookingAttachmentService $attachments,
        private RoomBookingReviewerResolver $reviewerResolver,
    ) {}

    public function replace(Request $request, RoomBookingRequest $booking): JsonResponse
    {
        abort_unless((int) $booking->requester_id === (int) $request->user()->id, 404);

        try {
            if ((string) $booking->getRawOriginal('status') !== RoomBookingStatus::RevisionRequested->value) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::INVALID_TRANSITION,
                    'Surat peminjaman hanya dapat diganti saat pengajuan berstatus revision_requested.',
                );
            }

            $validated = $request->validate([
                RoomBookingAttachmentService::INPUT_SURAT_PEMINJAMAN => [
                    'required',
                    'file',
                    'mimes:pdf',
                    'mimetypes:application/pdf',
                    'max:'.RoomBookingAttachmentService::MAX_KB,
                ],
            ]);

            $file = $validated[RoomBookingAttachmentService::INPUT_SURAT_PEMINJAMAN] ?? null;
            if (! $file instanceof UploadedFile) {
                throw new RuntimeException('Surat peminjaman wajib diunggah.');
            }

            $action = $this->attachments->hasSuratPeminjaman($booking) ? 'replacement' : 'upload';
            $this->attachments->storeSuratPeminjaman(
                $booking,
                $file,
                $request->user(),
                $action,
                $request,
            );

            return response()->json([
                'message' => 'Surat peminjaman berhasil disimpan',
                'data' => $this->bookingPayload($booking->fresh(), includeHistory: true),
            ]);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function preview(Request $request, RoomBookingRequest $booking): StreamedResponse
    {
        abort_unless($this->canReadAttachment($request, $booking), 404);

        $attachment = $this->attachments->suratPeminjamanAttachment($booking);
        abort_unless($attachment, 404);

        return $this->attachments->previewResponse($attachment);
    }

    public function download(Request $request, RoomBookingRequest $booking): StreamedResponse
    {
        abort_unless($this->canReadAttachment($request, $booking), 404);

        $attachment = $this->attachments->suratPeminjamanAttachment($booking);
        abort_unless($attachment, 404);

        return $this->attachments->downloadResponse($attachment);
    }

    private function canReadAttachment(Request $request, RoomBookingRequest $booking): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        if ($user->role === 'super_admin') {
            return true;
        }

        if ($user->role === 'mahasiswa') {
            return (int) $booking->requester_id === (int) $user->id;
        }

        if ($user->role === 'tendik') {
            return $this->reviewerResolver->canRead($user, $booking);
        }

        return false;
    }
}
