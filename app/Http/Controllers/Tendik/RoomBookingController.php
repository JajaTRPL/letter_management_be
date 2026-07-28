<?php

namespace App\Http\Controllers\Tendik;

use App\Enums\RoomType;
use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\ApproveRoomBookingRequest;
use App\Http\Requests\Peminjaman\BookingListRequest;
use App\Http\Requests\Peminjaman\RejectRoomBookingRequest;
use App\Http\Requests\Peminjaman\ReviseRoomBookingRequest;
use App\Http\Requests\Peminjaman\RoomBookingCalendarRequest;
use App\Http\Requests\Peminjaman\StartRoomBookingReviewRequest;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingConflictService;
use App\Services\RoomBookingDomainException;
use App\Services\RoomBookingLifecycleCapabilityResolver;
use App\Services\RoomBookingCalendarVisibilityService;
use App\Services\RoomBookingReviewerResolver;
use App\Services\RoomBookingReviewService;
use App\Services\RoomBookingTransitionService;
use App\Services\RoomPermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class RoomBookingController extends Controller
{
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomBookingReviewerResolver $reviewerResolver,
        private RoomBookingTransitionService $transitionService,
        private RoomPermissionResolver $roomPermissionResolver,
        private RoomBookingConflictService $conflictService,
        private RoomBookingLifecycleCapabilityResolver $capabilityResolver,
        private RoomBookingReviewService $reviewService,
        private RoomBookingCalendarVisibilityService $calendarVisibility,
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
                    includeConflicts: true,
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
                includeConflicts: true,
            ),
        ]);
    }

    public function calendar(RoomBookingCalendarRequest $request): JsonResponse
    {
        abort_unless($this->reviewerResolver->canReadReviewQueue($request->user()), 403);

        $filters = $request->validated();
        [$rangeStart, $rangeEndExclusive, $month] = $this->calendarRange($filters);

        $baseQuery = RoomBookingRequest::query();
        $this->calendarVisibility->applyRange($baseQuery, $rangeStart, $rangeEndExclusive);

        $this->scopeCalendarBookings($baseQuery, $request);

        $query = (clone $baseQuery)
            ->with([
                'room.owningLaboratory:id,code,name',
                'requester:id,name,email',
                'occurrences' => fn ($occurrence) => $occurrence
                    ->where('start_at', '<', $rangeEndExclusive)
                    ->where('end_at', '>', $rangeStart),
            ])
            ->orderBy('start_at');

        $this->applyCalendarFilters($query, $filters);
        $this->calendarVisibility->apply($query, $filters['status'] ?? null);

        $items = $query
            ->get()
            ->flatMap(fn (RoomBookingRequest $booking) => $this->calendarVisibility
                ->slotRanges($booking)
                ->filter(fn (array $slot) => $this->calendarVisibility->includesSlot(
                    $booking->status,
                    $slot['start_at'],
                    $slot['end_at'],
                    $filters['status'] ?? null,
                ))
                ->map(fn (array $slot) => $this->calendarItemPayload(
                    $booking,
                    $request,
                    $slot['start_at'],
                    $slot['end_at'],
                )))
            ->values();

        $summaryQuery = clone $baseQuery;
        $this->applyCalendarFilters($summaryQuery, $filters);
        $summary = $this->calendarVisibility->summarize($summaryQuery
            ->with(['occurrences' => fn ($occurrence) => $occurrence
                ->where('start_at', '<', $rangeEndExclusive)
                ->where('end_at', '>', $rangeStart)])
            ->get());

        return response()->json([
            'message' => 'Kalender review peminjaman ruangan berhasil diambil',
            'month' => $month,
            'range' => [
                'start' => $rangeStart->toDateString(),
                'end' => $rangeEndExclusive->copy()->subDay()->toDateString(),
            ],
            'items' => $items->all(),
            'summary' => [
                'total' => $items->count(),
                'active_total' => $summary['active_total'],
                'history_total' => $summary['history_total'],
                'counts_by_status' => $summary['counts_by_status'],
            ],
        ]);
    }

    public function approve(
        ApproveRoomBookingRequest $request,
        RoomBookingRequest $booking,
    ): JsonResponse {
        return $this->transitionResponse(
            fn () => $this->transitionService->approve(
                $booking,
                $request->user(),
                $request->validated('expected_workflow_version'),
            ),
            'Pengajuan peminjaman ruangan berhasil disetujui',
            $booking,
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
                $request->validated('expected_workflow_version'),
            ),
            'Permintaan revisi peminjaman ruangan berhasil dikirim',
            $booking,
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
                $request->validated('expected_workflow_version'),
            ),
            'Pengajuan peminjaman ruangan berhasil ditolak',
            $booking,
        );
    }

    public function startReview(
        StartRoomBookingReviewRequest $request,
        RoomBookingRequest $booking,
    ): JsonResponse {
        abort_unless(
            $this->reviewerResolver->canActAsApprover($request->user(), $booking),
            404,
        );

        try {
            $outcome = $this->reviewService->start(
                $booking,
                $request->user(),
                $request->integer('expected_workflow_version'),
                $request->validated('idempotency_key'),
                fn (array $result): array => $this->roomBookingMutationResponseBody(
                    $result,
                    includeRequester: true,
                ),
            );

            return $this->roomBookingOutcomeResponse($outcome);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse(
                $exception,
                $booking,
                includeRequester: true,
            );
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $booking);
        }
    }

    private function transitionResponse(
        callable $transition,
        string $message,
        RoomBookingRequest $subject,
    ): JsonResponse {
        try {
            $booking = $transition();

            return response()->json([
                'message' => $message,
                'data' => $this->bookingPayload(
                    $booking,
                    includeRequester: true,
                    includeHistory: true,
                    includeConflicts: true,
                ),
            ]);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse(
                $exception,
                $subject,
                includeRequester: true,
            );
        } catch (Throwable $exception) {
            return $this->roomBookingInfrastructureResponse($exception, $subject);
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
    private function applyCalendarFilters(Builder $query, array $filters): void
    {
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
    private function calendarItemPayload(
        RoomBookingRequest $booking,
        Request $request,
        Carbon $startAt,
        Carbon $endAt,
    ): array
    {
        $room = $booking->room;
        $capabilities = $this->capabilityResolver->capabilitiesFor(
            $request->user(),
            $booking,
        );

        return array_merge([
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
            'start_at' => $startAt->toIso8601String(),
            'end_at' => $endAt->toIso8601String(),
            'can_view' => $this->canViewCalendarBooking($booking, $request),
            'can_review' => $capabilities['can_review'],
            'can_start_review' => $capabilities['can_start_review'],
            'can_approve' => $capabilities['can_approve'],
            'can_reject' => $capabilities['can_reject'],
            'can_request_revision' => $capabilities['can_request_revision'],
            'can_decide_cancellation' => $capabilities['can_decide_cancellation'],
            'can_cancel' => false,
            'can_manage_room' => $this->roomPermissionResolver->canReadRoomManagement(
                $request->user(),
                $room,
            ),
            'can_update_readiness' => false,
            'can_resolve_conflict' => false,
            'can_relocate_booking' => false,
        ], $this->conflictService->conflictMetadata(
            $booking,
            includeRequester: true,
            includeActivity: true,
            includePurpose: true,
            startAt: $startAt,
            endAt: $endAt,
        ));
    }

    private function canViewCalendarBooking(RoomBookingRequest $booking, Request $request): bool
    {
        if ($request->user()->isLaboran()) {
            return $booking->room->type === RoomType::Laboratory;
        }

        return $this->reviewerResolver->canRead($request->user(), $booking);
    }
}
