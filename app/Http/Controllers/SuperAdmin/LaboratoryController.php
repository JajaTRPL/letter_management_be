<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLaboratoryRequest;
use App\Http\Requests\UpdateLaboratoryRequest;
use App\Models\Laboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Kelola Laboratorium" (Master Laboratorium) — SuperAdmin CRUD for the
 * `laboratories` dictionary. This is distinct from
 * RoomBookingController::laboratories(), which stays a lightweight
 * read-only id/code/name feed for dropdowns elsewhere and must not be
 * touched here.
 *
 * Route group in routes/api.php is wrapped in `role:super_admin`
 * middleware, so every method below is already unreachable by non-super
 * admins before it runs.
 */
class LaboratoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $laboratories = Laboratory::query()
            ->with('department:id,name')
            ->withCount(['users', 'rooms'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => 'Daftar laboratorium berhasil diambil',
            'data' => $laboratories->map(fn (Laboratory $laboratory) => $this->laboratoryPayload($laboratory))->all(),
        ]);
    }

    public function store(StoreLaboratoryRequest $request): JsonResponse
    {
        $laboratory = Laboratory::create([
            'name' => trim((string) $request->validated('name')),
            'code' => trim((string) $request->validated('code')),
            'department_id' => $request->validated('department_id'),
        ]);

        $laboratory->load('department:id,name')->loadCount(['users', 'rooms']);

        return response()->json([
            'message' => 'Laboratorium berhasil ditambahkan',
            'data' => $this->laboratoryPayload($laboratory),
        ], 201);
    }

    public function update(UpdateLaboratoryRequest $request, Laboratory $laboratory): JsonResponse
    {
        $laboratory->update([
            'name' => trim((string) $request->validated('name')),
            'code' => trim((string) $request->validated('code')),
            'department_id' => $request->validated('department_id'),
        ]);

        $laboratory->load('department:id,name')->loadCount(['users', 'rooms']);

        return response()->json([
            'message' => 'Laboratorium berhasil diperbarui',
            'data' => $this->laboratoryPayload($laboratory),
        ]);
    }

    /**
     * Deletion is blocked when the laboratory still has users assigned to it
     * (Kepala Lab / Laboran via `laboratory_id`) or rooms owned by it (via
     * `owning_laboratory_id`), mirroring the in-use guard in
     * RoomFacilityController::destroyFacilityType(). There is no
     * archive/deactivate concept for Laboratory, so a blocked delete simply
     * stays blocked until the dependent users/rooms are reassigned.
     */
    public function destroy(Request $request, Laboratory $laboratory): JsonResponse
    {
        $usersCount = $laboratory->users()->count();
        $roomsCount = $laboratory->rooms()->count();

        if ($usersCount > 0 || $roomsCount > 0) {
            $reasons = [];
            if ($usersCount > 0) {
                $reasons[] = "{$usersCount} user (Kepala Lab/Laboran)";
            }
            if ($roomsCount > 0) {
                $reasons[] = "{$roomsCount} ruangan";
            }
            $reasonText = implode(' dan ', $reasons);

            return response()->json([
                'message' => "Laboratorium ini masih digunakan oleh {$reasonText} dan tidak dapat dihapus. "
                    . 'Pindahkan atau lepaskan keterkaitan tersebut terlebih dahulu sebelum menghapus laboratorium.',
                'code' => 'laboratory_in_use',
            ], 409);
        }

        $name = $laboratory->name;
        $laboratory->delete();

        return response()->json([
            'message' => "Laboratorium \"{$name}\" berhasil dihapus",
        ]);
    }

    /** @return array<string, mixed> */
    private function laboratoryPayload(Laboratory $laboratory): array
    {
        return [
            'id' => (int) $laboratory->id,
            'name' => $laboratory->name,
            'code' => $laboratory->code,
            'department' => $laboratory->department ? [
                'id' => (int) $laboratory->department->id,
                'name' => $laboratory->department->name,
            ] : null,
            'department_id' => $laboratory->department_id !== null ? (int) $laboratory->department_id : null,
            'users_count' => (int) ($laboratory->users_count ?? 0),
            'rooms_count' => (int) ($laboratory->rooms_count ?? 0),
        ];
    }
}
