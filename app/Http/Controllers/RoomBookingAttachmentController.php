<?php

namespace App\Http\Controllers;

use App\Enums\RoomBookingStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Models\RoomBookingRequest;
use App\Models\User;
use App\Services\RoomBookingAttachmentService;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingLifecycleCapabilityResolver;
use App\Services\RoomBookingTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RoomBookingAttachmentController extends Controller
{
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomBookingAttachmentService $attachments,
        private RoomBookingLifecycleCapabilityResolver $capabilityResolver,
        private RoomBookingTransitionService $transitions,
    ) {}

    public function replace(Request $request, RoomBookingRequest $booking): JsonResponse
    {
        abort_unless((int) $booking->requester_id === (int) $request->user()->id, 404);

        try {
            // Fast-path denial only; the authoritative recheck runs inside
            // the storage transaction against the locked booking.
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
                'expected_workflow_version' => ['nullable', 'integer', 'min:1'],
            ]);

            $file = $validated[RoomBookingAttachmentService::INPUT_SURAT_PEMINJAMAN] ?? null;
            if (! $file instanceof UploadedFile) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::ATTACHMENT_REQUIRED,
                    'Surat peminjaman wajib diunggah.',
                );
            }

            $actor = $request->user();
            $expectedWorkflowVersion = $validated['expected_workflow_version'] ?? null;

            $action = $this->attachments->hasSuratPeminjaman($booking) ? 'replacement' : 'upload';
            $this->attachments->storeSuratPeminjaman(
                $booking,
                $file,
                $actor,
                $action,
                $request,
                lockedGuard: function (RoomBookingRequest $lockedBooking) use ($actor, $expectedWorkflowVersion): void {
                    $this->assertReplaceableUnderLock(
                        $lockedBooking,
                        $actor,
                        $expectedWorkflowVersion === null ? null : (int) $expectedWorkflowVersion,
                    );
                },
            );

            return response()->json([
                'message' => 'Surat peminjaman berhasil disimpan',
                'data' => $this->bookingPayload($booking->fresh(), includeHistory: true),
            ]);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception, $booking);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $booking);
        }
    }

    /**
     * Authoritative replacement guard, evaluated against the locked booking:
     * ownership, active account, revision status, no pending cancellation
     * request, and optional workflow-version expectation. Runs before any
     * metadata is written, so a denial leaves the previous attachment (row
     * and file) fully intact.
     */
    private function assertReplaceableUnderLock(
        RoomBookingRequest $lockedBooking,
        User $actor,
        ?int $expectedWorkflowVersion,
    ): void {
        $actor->refresh();

        if (
            $actor->role !== 'mahasiswa'
            || (int) $lockedBooking->requester_id !== (int) $actor->id
            || $actor->status !== UserStatus::Active
        ) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::UNAUTHORIZED_ACTION,
                'Anda tidak berwenang mengganti surat peminjaman ini.',
            );
        }

        $this->transitions->assertExpectedWorkflowVersion($lockedBooking, $expectedWorkflowVersion);

        if ($lockedBooking->status !== RoomBookingStatus::RevisionRequested) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::INVALID_TRANSITION,
                'Surat peminjaman hanya dapat diganti saat pengajuan berstatus revision_requested.',
            );
        }

        $this->transitions->assertNoPendingCancellationRequest($lockedBooking);
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
        // Same policy source as the capabilities payload (C7B1): the
        // endpoint and the projected can_view_attachment flag cannot drift.
        return $this->capabilityResolver->canViewAttachment($request->user(), $booking);
    }
}
