<?php

namespace App\Http\Controllers\RoomManagement;

use App\Http\Controllers\Concerns\BuildsRoomManagementPayloads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\StoreRoomRequest;
use App\Http\Requests\Peminjaman\UpdateRoomRequest;
use App\Models\Room;
use App\Models\RoomAuditLog;
use App\Services\RoomAuditService;
use App\Services\RoomPermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    use BuildsRoomManagementPayloads;

    public function __construct(
        private RoomPermissionResolver $resolver,
        private RoomAuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $rooms = $this->resolver->manageableRoomsQuery($request->user())
            ->with([
                'owningLaboratory:id,code,name',
                'coverPhoto',
                'facilityItems.facilityType:id,name,slug',
            ])
            ->orderBy('code')
            ->get();

        return response()->json([
            'message' => 'Daftar ruangan berhasil diambil',
            'count' => $rooms->count(),
            'data' => $rooms
                ->map(fn (Room $room) => $this->roomSummaryPayload($room, $request->user()))
                ->all(),
        ]);
    }

    public function show(Request $request, Room $room): JsonResponse
    {
        abort_unless($this->resolver->canReadRoomManagement($request->user(), $room), 404);

        $payload = $this->roomSummaryPayload($room, $request->user());
        $payload['photos'] = $room->photos->map(fn ($photo) => $this->roomPhotoPayload($photo))->all();
        $payload['facilities'] = $room->facilityItems
            ->map(fn ($facility) => $this->roomFacilityPayload($facility))
            ->all();

        $activeTemplate = $room->activeDocumentTemplate();
        $payload['active_template'] = $activeTemplate
            ? $this->roomTemplatePayload($activeTemplate, $room)
            : null;

        return response()->json([
            'message' => 'Detail ruangan berhasil diambil',
            'data' => $payload,
        ]);
    }

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! $this->resolver->canCreateRoom(
            $request->user(),
            (string) $validated['type'],
            $validated['owning_laboratory_id'] ?? null,
        )) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk menambah ruangan jenis ini.',
            ], 403);
        }

        $room = Room::create($validated);

        $this->audit->record(
            $room,
            RoomAuditLog::SUBJECT_ROOM,
            $room->id,
            'created',
            $request->user(),
            "Ruangan {$room->code} dibuat.",
            $request->ip(),
        );

        return response()->json([
            'message' => 'Ruangan berhasil ditambahkan',
            'data' => $this->roomSummaryPayload($room, $request->user()),
        ], 201);
    }

    public function update(UpdateRoomRequest $request, Room $room): JsonResponse
    {
        abort_unless($this->resolver->canReadRoomManagement($request->user(), $room), 404);

        if (! $this->resolver->canManageRoomInfo($request->user(), $room)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah ruangan ini.',
            ], 403);
        }

        $room->update($request->validated());

        $this->audit->record(
            $room,
            RoomAuditLog::SUBJECT_ROOM,
            $room->id,
            'updated',
            $request->user(),
            "Data ruangan {$room->code} diperbarui.",
            $request->ip(),
        );

        return response()->json([
            'message' => 'Ruangan berhasil diperbarui',
            'data' => $this->roomSummaryPayload($room->fresh(), $request->user()),
        ]);
    }

    public function activate(Request $request, Room $room): JsonResponse
    {
        return $this->setActive($request, $room, true);
    }

    public function deactivate(Request $request, Room $room): JsonResponse
    {
        return $this->setActive($request, $room, false);
    }

    private function setActive(Request $request, Room $room, bool $active): JsonResponse
    {
        abort_unless($this->resolver->canReadRoomManagement($request->user(), $room), 404);

        if (! $this->resolver->canDeactivateRoom($request->user(), $room)) {
            return response()->json([
                'message' => 'Hanya Super Admin (atau Sarpras untuk ruang kelas) yang dapat mengubah status ruangan.',
            ], 403);
        }

        $room->update(['is_active' => $active]);

        $this->audit->record(
            $room,
            RoomAuditLog::SUBJECT_ROOM,
            $room->id,
            $active ? 'activated' : 'deactivated',
            $request->user(),
            $active ? "Ruangan {$room->code} diaktifkan." : "Ruangan {$room->code} dinonaktifkan.",
            $request->ip(),
        );

        return response()->json([
            'message' => $active ? 'Ruangan berhasil diaktifkan' : 'Ruangan berhasil dinonaktifkan',
            'data' => $this->roomSummaryPayload($room->fresh(), $request->user()),
        ]);
    }
}
