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
use App\Services\RoomFacilityDelegatedAcknowledgementService;
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
        private RoomFacilityDelegatedAcknowledgementService $delegatedAcknowledgements,
    ) {
    }

    /**
     * Facility type dictionary. `?active=1` returns only active types (room
     * assignment dropdown); otherwise all types with is_active + usage_count
     * for the master management view.
     */
    public function facilityTypes(Request $request): JsonResponse
    {
        $query = FacilityType::query()->withCount('roomFacilities');
        if ($request->boolean('active')) {
            $query->active();
        }

        $types = $query->orderByDesc('is_predefined')->orderBy('name')->get()
            ->map(fn (FacilityType $type) => $this->facilityTypePayload($type));

        return response()->json([
            'message' => 'Daftar jenis fasilitas berhasil diambil',
            'data' => $types,
        ]);
    }

    /** @return array<string, mixed> */
    private function facilityTypePayload(FacilityType $type): array
    {
        return [
            'id' => (int) $type->id,
            'name' => $type->name,
            'slug' => $type->slug,
            'is_predefined' => (bool) $type->is_predefined,
            'is_active' => (bool) $type->is_active,
            'usage_count' => (int) ($type->room_facilities_count ?? 0),
        ];
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
            'data' => $this->facilityTypePayload($type->loadCount('roomFacilities')),
        ], 201);
    }

    /**
     * Rename and/or activate/deactivate a facility type (Master Fasilitas).
     * Deactivation is the soft-remove: the type stays on rooms that already
     * use it but is hidden from new assignments.
     */
    public function updateFacilityType(
        \App\Http\Requests\RoomManagement\UpdateFacilityTypeRequest $request,
        FacilityType $facilityType,
    ): JsonResponse {
        if (($response = $this->assertFacilityDictionaryManager($request)) !== null) {
            return $response;
        }

        $data = [];
        $changes = [];

        if ($request->has('name')) {
            $name = trim((string) $request->validated('name'));
            $slug = $request->slugValue();
            if (FacilityType::where('slug', $slug)->whereKeyNot($facilityType->id)->exists()) {
                return response()->json([
                    'message' => 'Fasilitas dengan nama serupa sudah ada.',
                ], 422);
            }
            $data['name'] = $name;
            $data['slug'] = $slug;
            $changes[] = "nama menjadi \"{$name}\"";
        }

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
            $changes[] = $data['is_active'] ? 'diaktifkan' : 'dinonaktifkan';
        }

        if ($data === []) {
            return response()->json([
                'message' => 'Tidak ada perubahan yang dikirim.',
            ], 422);
        }

        $facilityType->update($data);

        $this->audit->record(
            null,
            RoomAuditLog::SUBJECT_FACILITY,
            $facilityType->id,
            'type_updated',
            $request->user(),
            "Jenis fasilitas diperbarui: " . implode(', ', $changes) . '.',
            $request->ip(),
        );

        return response()->json([
            'message' => 'Jenis fasilitas berhasil diperbarui',
            'data' => $this->facilityTypePayload($facilityType->loadCount('roomFacilities')),
        ]);
    }

    /**
     * Delete a facility type (Master Fasilitas). Enterprise delete policy:
     *   - unused type (bawaan or custom) → hard delete.
     *   - type already used by rooms → NOT deleted; the caller must archive it
     *     (PATCH is_active=false) so existing room data is preserved.
     * SuperAdmin only: there is no created_by/scope ownership model, so scoped
     * Tendik deletion cannot be authorized safely (reported as follow-up).
     */
    public function destroyFacilityType(Request $request, FacilityType $facilityType): JsonResponse
    {
        if (($response = $this->assertFacilityDictionaryManager($request)) !== null) {
            return $response;
        }

        $usage = $facilityType->roomFacilities()->count();
        if ($usage > 0) {
            return response()->json([
                'message' => "Fasilitas ini sedang digunakan oleh {$usage} ruangan dan tidak dapat dihapus. "
                    . 'Arsipkan (nonaktifkan) fasilitas agar tidak muncul pada pilihan baru tanpa menghapus data ruangan.',
                'code' => 'facility_in_use',
            ], 409);
        }

        $name = $facilityType->name;
        $id = $facilityType->id;
        $facilityType->delete();

        $this->audit->record(
            null,
            RoomAuditLog::SUBJECT_FACILITY,
            $id,
            'type_deleted',
            $request->user(),
            "Jenis fasilitas \"{$name}\" dihapus.",
            $request->ip(),
        );

        return response()->json([
            'message' => 'Jenis fasilitas berhasil dihapus',
        ]);
    }

    /**
     * Rooms currently using a facility type — powers the Master Fasilitas
     * "Lihat Penggunaan" drawer so SuperAdmin can see exactly which rooms
     * depend on a type before archiving/deleting it. Returns only the fields
     * the drawer needs plus counts by room type. SuperAdmin-only, matching the
     * facility-dictionary surface (the room list payload carries a truncated,
     * name-only facilities summary, so this cannot be derived client-side).
     */
    public function rooms(Request $request, FacilityType $facilityType): JsonResponse
    {
        if (($response = $this->assertFacilityDictionaryManager($request)) !== null) {
            return $response;
        }

        $rooms = Room::query()
            ->whereHas('facilityItems', fn ($query) => $query->where('facility_type_id', $facilityType->id))
            ->with([
                'owningLaboratory:id,code,name',
                'facilityItems' => fn ($query) => $query->where('facility_type_id', $facilityType->id),
            ])
            ->orderBy('code')
            ->get();

        $classroom = $rooms->filter(fn (Room $room) => $room->type->value === 'classroom')->count();
        $laboratory = $rooms->filter(fn (Room $room) => $room->type->value === 'laboratory')->count();
        $total = $rooms->count();

        return response()->json([
            'message' => 'Penggunaan fasilitas berhasil diambil',
            'data' => [
                'facility_type' => $this->facilityTypePayload($facilityType->loadCount('roomFacilities')),
                'summary' => [
                    'total' => $total,
                    'classroom' => $classroom,
                    'laboratory' => $laboratory,
                    'other' => max(0, $total - $classroom - $laboratory),
                ],
                'rooms' => $rooms->map(function (Room $room) {
                    $assignment = $room->facilityItems->first();

                    return [
                        'id' => (int) $room->id,
                        'code' => $room->code,
                        'name' => $room->name,
                        'type' => $room->type->value,
                        'is_active' => (bool) $room->is_active,
                        'owning_laboratory' => $room->owningLaboratory ? [
                            'id' => (int) $room->owningLaboratory->id,
                            'code' => $room->owningLaboratory->code,
                            'name' => $room->owningLaboratory->name,
                        ] : null,
                        'quantity' => $assignment && $assignment->quantity !== null ? (int) $assignment->quantity : null,
                        'condition' => $assignment?->condition,
                    ];
                })->values()->all(),
            ],
        ]);
    }

    /**
     * Managing the global facility dictionary (rename/archive/delete) is
     * SuperAdmin-only. The route group also admits Tendik (for assigning +
     * creating types during room management), so the restriction is enforced
     * here. Returns a 403 JsonResponse when denied, or null when allowed.
     */
    private function assertFacilityDictionaryManager(Request $request): ?JsonResponse
    {
        if ($request->user()?->role === 'super_admin') {
            return null;
        }

        return response()->json([
            'message' => 'Hanya Super Admin yang dapat mengubah, mengarsipkan, atau menghapus jenis fasilitas.',
        ], 403);
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
        $beforeState = $this->delegatedAcknowledgements->facilityState($room);

        DB::transaction(function () use ($room, $entries, $request, $beforeState) {
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

            $afterState = $this->delegatedAcknowledgements->facilityState($room);
            $this->delegatedAcknowledgements->recordLaboranFacilitySyncIfNeeded(
                $room,
                $request->user(),
                $beforeState,
                $afterState,
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
