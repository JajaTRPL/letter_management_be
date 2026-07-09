<?php

namespace App\Http\Controllers\Tendik;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\BookingListRequest;
use App\Http\Requests\Peminjaman\RejectRoomBookingRequest;
use App\Http\Requests\Peminjaman\RoomBookingCalendarRequest;
use App\Http\Requests\Peminjaman\ReviseRoomBookingRequest;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingReviewerResolver;
use App\Services\RoomBookingTransitionService;
use App\Services\RoomPermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RoomBookingController extends Controller
{
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomBookingReviewerResolver $reviewerResolver,
        private RoomBookingTransitionService $transitionService,
        private RoomPermissionResolver $roomPermissionResolver,
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

    public function calendar(RoomBookingCalendarRequest $request): JsonResponse
    {
        abort_unless($this->reviewerResolver->canReadReviewQueue($request->user()), 403);

        $filters = $request->validated();
        [$rangeStart, $rangeEndExclusive, $month] = $this->calendarRange($filters);

        $baseQuery = RoomBookingRequest::query()
            ->where('start_at', '<', $rangeEndExclusive)
            ->where('end_at', '>', $rangeStart);

        $this->scopeCalendarBookings($baseQuery, $request);

        $query = (clone $baseQuery)
            ->with([
                'room.owningLaboratory:id,code,name',
                'requester:id,name,email',
            ])
            ->orderBy('start_at');

        $this->applyCalendarFilters($query, $filters);

        $items = $query
            ->get()
            ->map(fn (RoomBookingRequest $booking) => $this->calendarItemPayload(
                $booking,
                $request,
            ))
            ->values();

        $summaryQuery = clone $baseQuery;
        $this->applyCalendarFilters($summaryQuery, $filters, includeStatus: false);
        $countsByStatus = $summaryQuery
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        return response()->json([
            'message' => 'Kalender review peminjaman ruangan berhasil diambil',
            'month' => $month,
            'range' => [
                'start' => $rangeStart->toDateString(),
                'end' => $rangeEndExclusive->copy()->subDay()->toDateString(),
            ],
            'items' => $items->all(),
            'summary' => [
                'total' => array_sum($countsByStatus),
                'counts_by_status' => $countsByStatus,
            ],
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

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyCalendarFilters(Builder $query, array $filters, bool $includeStatus = true): void
    {
        if ($includeStatus && isset($filters['status'])) {
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

        if (isset($filters['laboratory_id'])) {
            $query->whereHas(
                'room',
                fn (Builder $roomQuery) => $roomQuery->where('owning_laboratory_id', $filters['laboratory_id']),
            );
        }
    }

    private function scopeCalendarBookings(Builder $query, Request $request): void
    {
        $user = $request->user();

        if ($user->isLaboran()) {
            $query->whereHas(
                'room',
                fn (Builder $roomQuery) => $roomQuery->where('type', RoomType::Laboratory->value),
            );

            return;
        }

        $this->reviewerResolver->scopeReviewableBookings($query, $user);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function calendarRange(array $filters): array
    {
        $timezone = config('app.timezone');

        if (isset($filters['month'])) {
            $start = Carbon::createFromFormat('Y-m', $filters['month'], $timezone)->startOfMonth();

            return [
                $start,
                $start->copy()->addMonthNoOverflow()->startOfMonth(),
                $start->format('Y-m'),
            ];
        }

        $start = Carbon::createFromFormat('Y-m-d', $filters['from'], $timezone)->startOfDay();
        $endExclusive = Carbon::createFromFormat('Y-m-d', $filters['to'], $timezone)
            ->addDay()
            ->startOfDay();

        return [$start, $endExclusive, $start->format('Y-m')];
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarItemPayload(RoomBookingRequest $booking, Request $request): array
    {
        $room = $booking->room;
        $canTakeReviewerAction = $booking->status === RoomBookingStatus::Submitted
            && $this->reviewerResolver->canActAsApprover($request->user(), $booking);

        return [
            'id' => (int) $booking->id,
            'room_id' => (int) $room->id,
            'room_code' => $room->code,
            'room_name' => $room->name,
            'room_type' => $room->type->value,
            'laboratory_id' => $room->owningLaboratory ? (int) $room->owningLaboratory->id : null,
            'laboratory_name' => $room->owningLaboratory?->name,
            'requester_name' => $booking->requester?->name,
            'requester_identifier' => $booking->requester?->email,
            'activity_name' => $booking->activity_name,
            'purpose' => $booking->purpose,
            'status' => $booking->status->value,
            'start_at' => $booking->start_at->toIso8601String(),
            'end_at' => $booking->end_at->toIso8601String(),
            'can_view' => $this->canViewCalendarBooking($booking, $request),
            'can_review' => $canTakeReviewerAction,
            'can_approve' => $canTakeReviewerAction,
            'can_reject' => $canTakeReviewerAction,
            'can_request_revision' => $canTakeReviewerAction,
            'can_cancel' => false,
            'can_manage_room' => $this->roomPermissionResolver->canReadRoomManagement(
                $request->user(),
                $room,
            ),
            'can_update_readiness' => false,
            'can_resolve_conflict' => false,
            'can_relocate_booking' => false,
        ];
    }

    private function canViewCalendarBooking(RoomBookingRequest $booking, Request $request): bool
    {
        if ($request->user()->isLaboran()) {
            return $booking->room->type === RoomType::Laboratory;
        }

        return $this->reviewerResolver->canRead($request->user(), $booking);
    }
}
