<?php

namespace App\Http\Controllers\RoomManagement;

use App\Http\Controllers\Concerns\BuildsRoomManagementPayloads;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoomManagement\StoreFacilityTypeRequest;
use App\Http\Requests\RoomManagement\SyncRoomFacilitiesRequest;
use App\Models\FacilityType;
use App\Models\Room;
use App\Models\RoomAuditLog;
use App\Models\RoomFacility;
use App\Services\RoomAuditService;
use App\Services\RoomPermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomFacilityController extends Controller
{
    use BuildsRoomManagementPayloads;

    public function __construct(
        private RoomPermissionResolver $resolver,
        private RoomAuditService $audit,
    ) {
    }

    public function facilityTypes(): JsonResponse
    {
        return response()->json([
            'message' => 'Daftar jenis fasilitas berhasil diambil',
            'data' => FacilityType::query()
                ->orderByDesc('is_predefined')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'is_predefined']),
        ]);
    }

    public function storeFacilityType(StoreFacilityTypeRequest $request): JsonResponse
    {
        $slug = $request->slugValue();

        if (FacilityType::where('slug', $slug)->exists()) {
            return response()->json([
                'message' => 'Fasilitas dengan nama serupa sudah ada.',
            ], 422);
        }

        $type = FacilityType::create([
            'name' => trim((string) $request->validated('name')),
            'slug' => $slug,
            'is_predefined' => false,
        ]);

        $this->audit->record(
            null,
            RoomAuditLog::SUBJECT_FACILITY,
            $type->id,
            'type_created',
            $request->user(),
            "Jenis fasilitas \"{$type->name}\" ditambahkan.",
            $request->ip(),
        );

        return response()->json([
            'message' => 'Jenis fasilitas berhasil ditambahkan',
            'data' => $type->only(['id', 'name', 'slug', 'is_predefined']),
        ], 201);
    }

    public function index(Request $request, Room $room): JsonResponse
    {
        abort_unless($this->resolver->canReadRoomManagement($request->user(), $room), 404);

        return response()->json([
            'message' => 'Fasilitas ruangan berhasil diambil',
            'data' => $room->facilityItems()->with('facilityType:id,name,slug')->get()
                ->map(fn (RoomFacility $facility) => $this->roomFacilityPayload($facility))
                ->all(),
        ]);
    }

    /**
     * Full transactional sync: entries absent from the payload are removed,
     * present ones are created/updated.
     */
    public function sync(SyncRoomFacilitiesRequest $request, Room $room): JsonResponse
    {
        abort_unless($this->resolver->canManageRoomFacilities($request->user(), $room), 404);

        $entries = collect($request->validated('facilities'));

        DB::transaction(function () use ($room, $entries, $request) {
            $room->facilityItems()
                ->whereNotIn('facility_type_id', $entries->pluck('facility_type_id'))
                ->delete();

            foreach ($entries as $entry) {
                RoomFacility::updateOrCreate(
                    [
                        'room_id' => $room->id,
                        'facility_type_id' => $entry['facility_type_id'],
                    ],
                    [
                        'quantity' => $entry['quantity'] ?? null,
                        'condition' => $entry['condition'] ?? null,
                        'notes' => $entry['notes'] ?? null,
                    ],
                );
            }

            $this->audit->record(
                $room,
                RoomAuditLog::SUBJECT_FACILITY,
                null,
                'synced',
                $request->user(),
                'Fasilitas ruangan diperbarui (' . $entries->count() . ' fasilitas).',
                $request->ip(),
            );
        });

        return response()->json([
            'message' => 'Fasilitas ruangan berhasil diperbarui',
            'data' => $room->facilityItems()->with('facilityType:id,name,slug')->get()
                ->map(fn (RoomFacility $facility) => $this->roomFacilityPayload($facility))
                ->all(),
        ]);
    }
}
