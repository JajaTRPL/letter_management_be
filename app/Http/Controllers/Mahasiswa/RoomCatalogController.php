<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Concerns\BuildsRoomManagementPayloads;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomFacility;
use App\Models\RoomPhoto;
use App\Services\RoomTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mahasiswa room catalog detail + template download. Active rooms only —
 * inactive rooms are invisible here regardless of URL guessing.
 */
class RoomCatalogController extends Controller
{
    use BuildsRoomManagementPayloads;

    public function __construct(
        private RoomTemplateService $templates,
    ) {
    }

    public function show(Request $request, Room $room): JsonResponse
    {
        abort_unless((bool) $room->is_active, 404);

        $payload = $this->roomSummaryPayload($room);
        $payload['photos'] = $room->photos
            ->map(fn (RoomPhoto $photo) => $this->roomPhotoPayload($photo))
            ->all();
        $payload['facilities'] = $room->facilityItems()->with('facilityType:id,name,slug')->get()
            ->map(fn (RoomFacility $facility) => $this->roomFacilityPayload($facility))
            ->all();

        $template = $room->activeDocumentTemplate();
        $payload['template'] = $template ? [
            'original_name' => $template->original_name,
            'mime' => $template->mime,
            'size_bytes' => (int) $template->size_bytes,
            'version' => (int) $template->version,
            'download_url' => "/api/mahasiswa/peminjaman-ruangan/rooms/{$room->id}/template",
        ] : null;

        return response()->json([
            'message' => 'Detail ruangan berhasil diambil',
            'data' => $payload,
        ]);
    }

    public function template(Request $request, Room $room): StreamedResponse|JsonResponse
    {
        abort_unless((bool) $room->is_active, 404);

        $template = $room->activeDocumentTemplate();
        if (! $template) {
            return response()->json([
                'message' => 'Template peminjaman belum tersedia untuk ruangan ini. Silakan hubungi pengelola ruangan.',
            ], 404);
        }

        return $this->templates->downloadResponse($template);
    }
}
