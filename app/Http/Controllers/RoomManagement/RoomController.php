<?php

namespace App\Http\Controllers\RoomManagement;

use App\Http\Controllers\Concerns\BuildsRoomManagementPayloads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Peminjaman\StoreRoomRequest;
use App\Http\Requests\Peminjaman\UpdateRoomRequest;
use App\Http\Requests\RoomManagement\BulkDeleteRoomsRequest;
use App\Models\Room;
use App\Models\RoomAuditLog;
use App\Services\RoomAuditService;
use App\Services\RoomMediaService;
use App\Services\RoomPermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    use BuildsRoomManagementPayloads;

    public function __construct(
        private RoomPermissionResolver $resolver,
        private RoomAuditService $audit,
        private RoomMediaService $media,
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

    /**
     * Bulk remove selected rooms. Enterprise-safe, per-room policy:
     *   - Room with booking history  → ARCHIVE (is_active=false). A hard delete
     *     is blocked by room_booking_requests.room_id (RESTRICT) and would
     *     destroy historical bookings, so we deactivate to hide it from the
     *     active catalog and new bookings while preserving history.
     *   - Room without any booking   → HARD DELETE. room_photos / room_facilities
     *     / room_audit_logs rows cascade; photo FILES are purged after commit;
     *     document templates are scoped by type/lab (not owned by the room) and
     *     are left untouched.
     * Authority is canDeactivateRoom (SuperAdmin: all; Sarpras: classrooms).
     * The batch fails closed (403) if any selected room is outside scope.
     */
    public function bulkDestroy(BulkDeleteRoomsRequest $request): JsonResponse
    {
        $user = $request->user();
        $rooms = Room::whereIn('id', $request->roomIds())->get();

        foreach ($rooms as $room) {
            if (! $this->resolver->canDeactivateRoom($user, $room)) {
                return response()->json([
                    'message' => 'Anda tidak memiliki akses untuk menghapus salah satu ruangan yang dipilih.',
                ], 403);
            }
        }

        $deleted = [];
        $archived = [];
        $purgePhotoDirs = [];

        DB::transaction(function () use ($rooms, $user, $request, &$deleted, &$archived, &$purgePhotoDirs) {
            foreach ($rooms as $room) {
                if ($room->roomBookingRequests()->exists()) {
                    if ($room->is_active) {
                        $room->update(['is_active' => false]);
                    }

                    $this->audit->record(
                        $room,
                        RoomAuditLog::SUBJECT_ROOM,
                        $room->id,
                        'archived',
                        $user,
                        "Ruangan {$room->code} diarsipkan (memiliki riwayat peminjaman).",
                        $request->ip(),
                    );

                    $archived[] = [
                        'id' => (int) $room->id,
                        'code' => $room->code,
                        'reason' => 'Memiliki riwayat peminjaman',
                    ];

                    continue;
                }

                $roomId = (int) $room->id;
                $code = $room->code;
                $laboratory = $room->owningLaboratory;
                $purgePhotoDirs[] = $roomId;
                $room->delete();

                // room_id is null so this log survives the room_audit_logs cascade.
                $this->audit->record(
                    null,
                    RoomAuditLog::SUBJECT_ROOM,
                    $roomId,
                    'deleted',
                    $user,
                    "Ruangan {$code} dihapus permanen.",
                    $request->ip(),
                    $laboratory,
                );

                $deleted[] = ['id' => $roomId, 'code' => $code];
            }
        });

        // Purge files only after commit so a rollback never orphans a room's photos.
        foreach ($purgePhotoDirs as $roomId) {
            $this->media->purgeRoomPhotoStorage($roomId);
        }

        $message = $archived === []
            ? 'Ruangan terpilih berhasil dihapus.'
            : ($deleted === []
                ? 'Ruangan terpilih diarsipkan karena memiliki riwayat peminjaman.'
                : 'Sebagian ruangan dihapus; sebagian diarsipkan karena memiliki riwayat peminjaman.');

        return response()->json([
            'message' => $message,
            'data' => [
                'deleted' => $deleted,
                'archived' => $archived,
                'summary' => [
                    'deleted' => count($deleted),
                    'archived' => count($archived),
                    'total' => count($deleted) + count($archived),
                ],
            ],
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
