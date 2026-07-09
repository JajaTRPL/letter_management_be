<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\RoomBookingStatus;
use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\BookingListRequest;
use App\Http\Requests\Peminjaman\RoomBookingCalendarRequest;
use App\Http\Requests\Peminjaman\RoomListRequest;
use App\Http\Requests\Peminjaman\StoreRoomRequest;
use App\Http\Requests\Peminjaman\UpdateRoomRequest;
use App\Models\Laboratory;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingConflictService;
use App\Services\RoomBookingDomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RoomBookingController extends Controller
{
    use HandlesRoomBookingApi;

    public function __construct(
        private RoomBookingConflictService $conflictService,
    ) {}

    public function laboratories(): JsonResponse
    {
        return response()->json(
            Laboratory::query()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        );
    }

    public function rooms(RoomListRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = Room::query()
            ->with('owningLaboratory:id,code,name')
            ->orderBy('code');

        if (array_key_exists('active', $filters)) {
            $query->where('is_active', (bool) $filters['active']);
        }

        $this->applyRoomFilters($query, $filters);
        $rooms = $query->get();

        return response()->json([
            'message' => 'Daftar ruangan berhasil diambil',
            'count' => $rooms->count(),
            'data' => $rooms->map(fn (Room $room) => $this->roomPayload($room))->all(),
        ]);
    }

    public function storeRoom(StoreRoomRequest $request): JsonResponse
    {
        try {
            $room = Room::create($request->validated());

            return response()->json([
                'message' => 'Ruangan berhasil dibuat',
                'data' => $this->roomPayload($room->fresh()),
            ], 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function showRoom(Room $room): JsonResponse
    {
        return response()->json([
            'message' => 'Detail ruangan berhasil diambil',
            'data' => $this->roomPayload($room),
        ]);
    }

    public function updateRoom(UpdateRoomRequest $request, Room $room): JsonResponse
    {
        try {
            $room->update($request->validated());

            return response()->json([
                'message' => 'Ruangan berhasil diperbarui',
                'data' => $this->roomPayload($room->fresh()),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function activateRoom(Room $room): JsonResponse
    {
        $room->update(['is_active' => true]);

        return response()->json([
            'message' => 'Ruangan berhasil diaktifkan',
            'data' => $this->roomPayload($room->fresh()),
        ]);
    }

    public function deactivateRoom(Room $room): JsonResponse
    {
        try {
            $room = DB::transaction(function () use ($room) {
                $lockedRoom = Room::query()->lockForUpdate()->findOrFail($room->id);

                $hasFutureApprovedBooking = RoomBookingRequest::query()
                    ->where('room_id', $lockedRoom->id)
                    ->where('status', RoomBookingStatus::Approved->value)
                    ->where('start_at', '>', Carbon::now(config('app.timezone')))
                    ->exists();

                if ($hasFutureApprovedBooking) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::BOOKING_CONFLICT,
                        'Ruangan tidak dapat dinonaktifkan karena memiliki peminjaman disetujui yang akan datang.',
                    );
                }

                $lockedRoom->update(['is_active' => false]);

                return $lockedRoom->fresh();
            });

            return response()->json([
                'message' => 'Ruangan berhasil dinonaktifkan',
                'data' => $this->roomPayload($room),
            ]);
        } catch (RoomBookingDomainException $exception) {
            return $this->roomBookingDomainResponse($exception);
        }
    }

    public function requests(BookingListRequest $request): JsonResponse
    {
        $query = RoomBookingRequest::query()
            ->with([
                'room.owningLaboratory:id,code,name',
                'requester:id,name,email',
                'reviewer:id,name,email',
            ])
            ->orderByDesc('created_at');

        $this->applyBookingFilters($query, $request->validated());
        $paginator = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'message' => 'Monitoring peminjaman ruangan berhasil diambil',
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

    public function showRequest(RoomBookingRequest $booking): JsonResponse
    {
        return response()->json([
            'message' => 'Detail monitoring peminjaman ruangan berhasil diambil',
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
        $filters = $request->validated();
        [$rangeStart, $rangeEndExclusive, $month] = $this->calendarRange($filters);

        $baseQuery = RoomBookingRequest::query()
            ->where('start_at', '<', $rangeEndExclusive)
            ->where('end_at', '>', $rangeStart);

        $query = (clone $baseQuery)
            ->with([
                'room.owningLaboratory:id,code,name',
                'requester:id,name,email',
            ])
            ->orderBy('start_at');

        $this->applyBookingFilters($query, $filters);

        $items = $query
            ->get()
            ->map(fn (RoomBookingRequest $booking) => $this->calendarItemPayload($booking))
            ->values();

        $summaryQuery = clone $baseQuery;
        $this->applyBookingFilters($summaryQuery, $filters, includeStatus: false);
        $countsByStatus = $summaryQuery
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        return response()->json([
            'message' => 'Kalender peminjaman ruangan berhasil diambil',
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

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyBookingFilters(Builder $query, array $filters, bool $includeStatus = true): void
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
    private function calendarItemPayload(RoomBookingRequest $booking): array
    {
        $room = $booking->room;

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
            'start_at' => $booking->start_at->toIso8601String(),
            'end_at' => $booking->end_at->toIso8601String(),
            'can_view' => true,
            'can_review' => false,
            'can_approve' => false,
            'can_reject' => false,
            'can_request_revision' => false,
            'can_cancel' => false,
            'can_manage_room' => true,
        ], $this->conflictService->conflictMetadata(
            $booking,
            includeRequester: true,
            includeActivity: true,
            includePurpose: true,
        ));
    }
}
