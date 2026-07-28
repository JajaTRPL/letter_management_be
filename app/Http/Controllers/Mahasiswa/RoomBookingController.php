<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Http\Controllers\Concerns\BuildsRoomManagementPayloads;
use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\AvailabilityRequest;
use App\Http\Requests\Peminjaman\RoomListRequest;
use App\Http\Requests\Peminjaman\StoreRoomBookingRequest;
use App\Http\Requests\Peminjaman\SubmitRoomBookingRequest;
use App\Http\Requests\Peminjaman\UpdateRoomBookingRequest;
use App\Http\Requests\Peminjaman\WithdrawRoomBookingRequest;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Services\RoomAvailabilityService;
use App\Services\RoomBookingAttachmentService;
use App\Services\RoomBookingConflictService;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingInitialSubmissionService;
use App\Services\RoomBookingTransitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Throwable;

class RoomBookingController extends Controller
{
    use BuildsRoomManagementPayloads;
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomAvailabilityService $availabilityService,
        private RoomBookingAttachmentService $attachmentService,
        private RoomBookingConflictService $conflictService,
        private RoomBookingTransitionService $transitionService,
        private RoomBookingInitialSubmissionService $initialSubmissions,
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
            $idempotencyKey = $validated['idempotency_key'];
            unset($validated[RoomBookingAttachmentService::INPUT_SURAT_PEMINJAMAN]);
            unset($validated['idempotency_key']);

            if (! $file instanceof UploadedFile) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::ATTACHMENT_REQUIRED,
                    'Surat peminjaman wajib diunggah.',
                );
            }

            $outcome = $this->initialSubmissions->submit(
                $request->user(),
                $validated,
                $file,
                $idempotencyKey,
                $request,
                fn (array $result): array => $this->roomBookingInitialSubmissionResponseBody(
                    $result,
                ),
            );

            return $this->roomBookingOutcomeResponse($outcome);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception);
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception);
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
            // Every guard (status, pending cancellation, version, schedule,
            // conflict) is re-evaluated by the service under the booking
            // lock; nothing here is the final authority.
            $booking = $this->transitionService->updateRevision(
                $booking,
                $request->user(),
                $request->safe()->except(['expected_workflow_version']),
                $request->validated('expected_workflow_version'),
            );

            return response()->json([
                'message' => 'Perbaikan pengajuan peminjaman berhasil disimpan',
                'data' => $this->bookingPayload($booking, includeHistory: true),
            ]);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception, $booking);
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $booking);
        }
    }

    public function submit(
        SubmitRoomBookingRequest $request,
        RoomBookingRequest $booking,
    ): JsonResponse {
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
            $booking = $this->transitionService->submit(
                $booking,
                $request->user(),
                $request->validated('expected_workflow_version'),
            );

            return response()->json([
                'message' => 'Pengajuan peminjaman berhasil dikirim ulang',
                'data' => $this->bookingPayload($booking, includeHistory: true),
            ]);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception, $booking);
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $booking);
        }
    }

    public function withdraw(
        WithdrawRoomBookingRequest $request,
        RoomBookingRequest $booking,
    ): JsonResponse {
        $this->assertOwned($request, $booking);

        try {
            $outcome = $this->transitionService->withdraw(
                $booking,
                $request->user(),
                $request->validated('reason'),
                $request->integer('expected_workflow_version'),
                $request->validated('idempotency_key'),
                fn (array $result): array => $this->roomBookingMutationResponseBody($result),
            );

            return $this->roomBookingOutcomeResponse($outcome);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception, $booking);
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $booking);
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
