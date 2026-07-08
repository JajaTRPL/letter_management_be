<?php

namespace App\Http\Controllers\Tendik;

use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\BookingListRequest;
use App\Http\Requests\Peminjaman\RejectRoomBookingRequest;
use App\Http\Requests\Peminjaman\ReviseRoomBookingRequest;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingReviewerResolver;
use App\Services\RoomBookingTransitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RoomBookingController extends Controller
{
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomBookingReviewerResolver $reviewerResolver,
        private RoomBookingTransitionService $transitionService,
    ) {}

    public function index(BookingListRequest $request): JsonResponse
    {
        abort_unless($this->reviewerResolver->canReadReviewQueue($request->user()), 403);

        $query = RoomBookingRequest::query()
            ->with([
                'room.owningLaboratory:id,code,name',
                'requester:id,name,email',
                'reviewer:id,name,email',
            ])
            ->orderByDesc('created_at');

        $this->reviewerResolver->scopeReviewableBookings($query, $request->user());
        $this->applyBookingFilters($query, $request->validated());
        $paginator = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'message' => 'Daftar review peminjaman ruangan berhasil diambil',
            'data' => collect($paginator->items())
                ->map(fn (RoomBookingRequest $booking) => $this->bookingPayload(
                    $booking,
                    includeRequester: true,
                ))
                ->all(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    public function show(Request $request, RoomBookingRequest $booking): JsonResponse
    {
        abort_unless($this->reviewerResolver->canRead($request->user(), $booking), 404);

        return response()->json([
            'message' => 'Detail review peminjaman ruangan berhasil diambil',
            'data' => $this->bookingPayload(
                $booking,
                includeRequester: true,
                includeHistory: true,
            ),
        ]);
    }

    public function approve(Request $request, RoomBookingRequest $booking): JsonResponse
    {
        return $this->transitionResponse(
            fn () => $this->transitionService->approve($booking, $request->user()),
            'Pengajuan peminjaman ruangan berhasil disetujui',
        );
    }

    public function revise(
        ReviseRoomBookingRequest $request,
        RoomBookingRequest $booking,
    ): JsonResponse {
        return $this->transitionResponse(
            fn () => $this->transitionService->requestRevision(
                $booking,
                $request->user(),
                $request->validated('note'),
            ),
            'Permintaan revisi peminjaman ruangan berhasil dikirim',
        );
    }

    public function reject(
        RejectRoomBookingRequest $request,
        RoomBookingRequest $booking,
    ): JsonResponse {
        return $this->transitionResponse(
            fn () => $this->transitionService->reject(
                $booking,
                $request->user(),
                $request->validated('reason'),
            ),
            'Pengajuan peminjaman ruangan berhasil ditolak',
        );
    }

    private function transitionResponse(callable $transition, string $message): JsonResponse
    {
        try {
            $booking = $transition();

            return response()->json([
                'message' => $message,
                'data' => $this->bookingPayload(
                    $booking,
                    includeRequester: true,
                    includeHistory: true,
                ),
            ]);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyBookingFilters(Builder $query, array $filters): void
    {
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        }

        if (isset($filters['room_type'])) {
            $query->whereHas(
                'room',
                fn (Builder $roomQuery) => $roomQuery->where('type', $filters['room_type']),
            );
        }

        if (isset($filters['date_from'])) {
            $query->where(
                'start_at',
                '>=',
                Carbon::createFromFormat(
                    'Y-m-d',
                    $filters['date_from'],
                    config('app.timezone'),
                )->startOfDay(),
            );
        }

        if (isset($filters['date_to'])) {
            $query->where(
                'start_at',
                '<',
                Carbon::createFromFormat(
                    'Y-m-d',
                    $filters['date_to'],
                    config('app.timezone'),
                )->addDay()->startOfDay(),
            );
        }
    }
}
