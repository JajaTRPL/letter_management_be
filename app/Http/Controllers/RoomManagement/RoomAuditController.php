<?php

namespace App\Http\Controllers\RoomManagement;

use App\Http\Controllers\Concerns\BuildsRoomManagementPayloads;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomAuditLog;
use App\Services\RoomPermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomAuditController extends Controller
{
    use BuildsRoomManagementPayloads;

    public function __construct(
        private RoomPermissionResolver $resolver,
    ) {
    }

    public function index(Request $request, Room $room): JsonResponse
    {
        abort_unless($this->resolver->canReadRoomManagement($request->user(), $room), 404);

        return response()->json([
            'message' => 'Riwayat perubahan ruangan berhasil diambil',
            'data' => $room->auditLogs()->with('actor:id,name')->limit(50)->get()
                ->map(fn (RoomAuditLog $log) => $this->roomAuditPayload($log))
                ->all(),
        ]);
    }
}
