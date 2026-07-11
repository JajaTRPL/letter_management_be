<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Http\Controllers\Concerns\BuildsRoomManagementPayloads;
use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\AvailabilityRequest;
use App\Http\Requests\Peminjaman\CancelRoomBookingRequest;
use App\Http\Requests\Peminjaman\RoomListRequest;
use App\Http\Requests\Peminjaman\StoreRoomBookingRequest;
use App\Http\Requests\Peminjaman\UpdateRoomBookingRequest;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingSubmissionSnapshot;
use App\Services\RoomAvailabilityService;
use App\Services\RoomBookingAttachmentService;
use App\Services\RoomBookingConflictService;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingSubmissionSnapshotService;
use App\Services\RoomBookingTransitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RoomBookingController extends Controller
{
    use BuildsRoomManagementPayloads;
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomAvailabilityService $availabilityService,
        private RoomBookingAttachmentService $attachmentService,
        private RoomBookingConflictService $conflictService,
        private RoomBookingTransitionService $transitionService,
        private RoomBookingSubmissionSnapshotService $snapshotService,
    ) {}

    public function rooms(RoomListRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $query = Room::query()
            ->with([
                'owningLaboratory:id,code,name',
                'coverPhoto',
                'facilityItems.facilityType:id,name,slug',
            ])
            ->where('is_active', true)
            ->orderBy('code');

        $this->applyRoomFilters($query, $validated);
        $rooms = $query->get();

        return response()->json([
            'message' => 'Daftar ruangan aktif berhasil diambil',
            'count' => $rooms->count(),
            // roomSummaryPayload is a strict superset of the legacy
            // roomPayload shape: existing FE keeps working, CP3 gains
            // cover photo / facility / template hints.
            'data' => $rooms->map(fn (Room $room) => $this->roomSummaryPayload($room))->all(),
        ]);
    }

    public function availability(AvailabilityRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $timezone = config('app.timezone');
        $from = Carbon::createFromFormat('Y-m-d', $validated['from'], $timezone)->startOfDay();
        $toExclusive = Carbon::createFromFormat('Y-m-d', $validated['to'], $timezone)
            ->addDay()
            ->startOfDay();
        $type = isset($validated['type']) ? RoomType::from($validated['type']) : null;

        return response()->json([
            'message' => 'Ketersediaan ruangan berhasil diambil',
            'data' => $this->availabilityService->projection(
                $from,
                $toExclusive,
                $validated['room_id'] ?? null,
                $type,
            )->values()->all(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $bookings = RoomBookingRequest::query()
            ->with(['room.owningLaboratory:id,code,name', 'reviewer:id,name,email'])
            ->where('requester_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'message' => 'Daftar peminjaman ruangan berhasil diambil',
            'data' => $bookings
                ->map(fn (RoomBookingRequest $booking) => $this->bookingPayload($booking))
                ->all(),
        ]);
    }

    public function store(StoreRoomBookingRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $file = $request->file(RoomBookingAttachmentService::INPUT_SURAT_PEMINJAMAN);
            unset($validated[RoomBookingAttachmentService::INPUT_SURAT_PEMINJAMAN]);

            if (! $file instanceof UploadedFile) {
                throw new RuntimeException('Surat peminjaman wajib diunggah.');
            }

            $booking = new RoomBookingRequest(array_merge(
                $validated,
                ['requester_id' => $request->user()->id],
            ));

            $this->assertNoApprovedConflict($booking);

            // Compensation boundary: filesystem writes are not transactional,
            // so if anything fails AFTER the physical PDF is stored but before
            // the outer transaction commits (e.g. the snapshot write), the
            // database rolls back and the newly written file must be removed
            // explicitly. Only the file created by THIS attempt is tracked;
            // committed submissions and pre-existing attachments are never
            // touched, and the original exception is always rethrown.
            $newAttachment = null;

            try {
                $booking = DB::transaction(function () use ($booking, $file, $request, &$newAttachment) {
                    $submitted = $this->transitionService->submit($booking, $request->user());
                    $newAttachment = $this->attachmentService->storeSuratPeminjaman(
                        $submitted,
                        $file,
                        $request->user(),
                        'upload',
                        $request,
                    );

                    // Immutable iteration-1 evidence, written after the
                    // attachment persists so the snapshot carries its checksum.
                    $this->snapshotService->capture(
                        $submitted->fresh(),
                        $request->user(),
                        RoomBookingSubmissionSnapshot::PROVENANCE_NATIVE_SUBMISSION,
                    );

                    return $submitted->fresh();
                });
            } catch (\Throwable $exception) {
                if ($newAttachment !== null) {
                    $this->attachmentService->cleanupFailedPersistedAttachment($newAttachment);
                }

                throw $exception;
            }

            return response()->json([
                'message' => 'Pengajuan peminjaman ruangan berhasil dikirim',
                'data' => $this->bookingPayload($booking, includeHistory: true),
            ], 201);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function show(Request $request, RoomBookingRequest $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);

        return response()->json([
            'message' => 'Detail peminjaman ruangan berhasil diambil',
            'data' => $this->bookingPayload($booking, includeHistory: true),
        ]);
    }

    public function update(
        UpdateRoomBookingRequest $request,
        RoomBookingRequest $booking,
    ): JsonResponse {
        $this->assertOwned($request, $booking);

        try {
            if ($booking->status !== RoomBookingStatus::RevisionRequested) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::INVALID_TRANSITION,
                    'Pengajuan hanya dapat diubah saat berstatus revision_requested.',
                );
            }

            $booking->fill($request->validated());
            $booking->unsetRelation('room');
            $this->transitionService->validateForSubmission($booking);
            $this->assertNoApprovedConflict($booking, $booking->id);
            $booking->save();

            return response()->json([
                'message' => 'Perbaikan pengajuan peminjaman berhasil disimpan',
                'data' => $this->bookingPayload($booking->fresh(), includeHistory: true),
            ]);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception);
        }
    }

    public function submit(Request $request, RoomBookingRequest $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);

        try {
            if ($booking->status !== RoomBookingStatus::RevisionRequested) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::INVALID_TRANSITION,
                    'Pengajuan tidak berada pada tahap pengiriman ulang.',
                );
            }

            $this->transitionService->validateForSubmission($booking);
            if (! $this->attachmentService->hasSuratPeminjaman($booking)) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::ATTACHMENT_REQUIRED,
                    'Surat peminjaman wajib diunggah sebelum pengajuan dikirim ulang.',
                );
            }

            $this->assertNoApprovedConflict($booking, $booking->id);
            $booking = $this->transitionService->submit($booking, $request->user());

            return response()->json([
                'message' => 'Pengajuan peminjaman berhasil dikirim ulang',
                'data' => $this->bookingPayload($booking, includeHistory: true),
            ]);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception);
        }
    }

    public function cancel(
        CancelRoomBookingRequest $request,
        RoomBookingRequest $booking,
    ): JsonResponse {
        $this->assertOwned($request, $booking);

        try {
            $booking = $this->transitionService->cancel(
                $booking,
                $request->user(),
                $request->validated('reason'),
            );

            return response()->json([
                'message' => 'Pengajuan peminjaman ruangan berhasil dibatalkan',
                'data' => $this->bookingPayload($booking, includeHistory: true),
            ]);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception);
        }
    }

    private function assertOwned(Request $request, RoomBookingRequest $booking): void
    {
        abort_unless((int) $booking->requester_id === (int) $request->user()->id, 404);
    }

    private function assertNoApprovedConflict(
        RoomBookingRequest $booking,
        ?int $ignoreBookingId = null,
    ): void {
        if (! $this->conflictService->hasConflict(
            (int) $booking->room_id,
            $booking->start_at,
            $booking->end_at,
            $ignoreBookingId,
        )) {
            return;
        }

        throw new RoomBookingDomainException(
            RoomBookingDomainException::BOOKING_CONFLICT,
            'Ruangan telah memiliki peminjaman disetujui pada waktu yang bertabrakan.',
            [
                'conflicts' => $this->conflictService->conflictingSummary(
                    (int) $booking->room_id,
                    $booking->start_at,
                    $booking->end_at,
                    $ignoreBookingId,
                )->all(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyRoomFilters(Builder $query, array $filters): void
    {
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['laboratory_id'])) {
            $query->where('owning_laboratory_id', $filters['laboratory_id']);
        }

        if (! empty($filters['search'])) {
            $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $pattern = '%'.$filters['search'].'%';
            $query->where(function (Builder $query) use ($operator, $pattern) {
                $query
                    ->where('code', $operator, $pattern)
                    ->orWhere('name', $operator, $pattern)
                    ->orWhere('location', $operator, $pattern);
            });
        }
    }
}
