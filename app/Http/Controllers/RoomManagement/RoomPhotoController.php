<?php

namespace App\Http\Controllers\RoomManagement;

use App\Http\Controllers\Concerns\BuildsRoomManagementPayloads;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoomManagement\ReorderRoomPhotosRequest;
use App\Http\Requests\RoomManagement\StoreRoomPhotoRequest;
use App\Models\Room;
use App\Models\RoomPhoto;
use App\Services\RoomMediaService;
use App\Services\RoomPermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RoomPhotoController extends Controller
{
    use BuildsRoomManagementPayloads;

    public function __construct(
        private RoomPermissionResolver $resolver,
        private RoomMediaService $media,
    ) {
    }

    public function index(Request $request, Room $room): JsonResponse
    {
        abort_unless($this->resolver->canReadRoomManagement($request->user(), $room), 404);

        return response()->json([
            'message' => 'Daftar foto ruangan berhasil diambil',
            'data' => $room->photos->map(fn (RoomPhoto $photo) => $this->roomPhotoPayload($photo))->all(),
        ]);
    }

    public function store(StoreRoomPhotoRequest $request, Room $room): JsonResponse
    {
        abort_unless($this->resolver->canManageRoomMedia($request->user(), $room), 404);

        try {
            $photo = $this->media->storePhoto(
                $room,
                $request->file('photo'),
                $request->user(),
                $request->ip(),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Foto ruangan berhasil diunggah',
            'data' => $this->roomPhotoPayload($photo),
        ], 201);
    }

    public function destroy(Request $request, Room $room, RoomPhoto $photo): JsonResponse
    {
        abort_unless($this->resolver->canManageRoomMedia($request->user(), $room), 404);
        abort_unless((int) $photo->room_id === (int) $room->id, 404);

        $this->media->deletePhoto($room, $photo, $request->user(), $request->ip());

        return response()->json(['message' => 'Foto ruangan berhasil dihapus']);
    }

    public function setCover(Request $request, Room $room, RoomPhoto $photo): JsonResponse
    {
        abort_unless($this->resolver->canManageRoomMedia($request->user(), $room), 404);
        abort_unless((int) $photo->room_id === (int) $room->id, 404);

        $this->media->setCover($room, $photo, $request->user(), $request->ip());

        return response()->json([
            'message' => 'Foto sampul berhasil diganti',
            'data' => $this->roomPhotoPayload($photo->fresh()),
        ]);
    }

    public function reorder(ReorderRoomPhotosRequest $request, Room $room): JsonResponse
    {
        abort_unless($this->resolver->canManageRoomMedia($request->user(), $room), 404);

        try {
            $this->media->reorder(
                $room,
                array_map('intval', $request->validated('photo_ids')),
                $request->user(),
                $request->ip(),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Urutan foto berhasil diperbarui',
            'data' => $room->photos()->get()->map(fn (RoomPhoto $photo) => $this->roomPhotoPayload($photo))->all(),
        ]);
    }
}
