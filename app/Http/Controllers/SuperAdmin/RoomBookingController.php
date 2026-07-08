<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\RoomBookingStatus;
use App\Http\Controllers\Concerns\HandlesRoomBookingApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\BookingListRequest;
use App\Http\Requests\Peminjaman\RoomListRequest;
use App\Http\Requests\Peminjaman\StoreRoomRequest;
use App\Http\Requests\Peminjaman\UpdateRoomRequest;
use App\Models\Laboratory;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingDomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RoomBookingController extends Controller
{
    use HandlesRoomBookingApi;

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
            ),
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
