<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomPhoto;
use App\Services\RoomMediaService;
use App\Services\RoomPermissionResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated room photo delivery. Photos are addressed by id + variant
 * only — storage paths never appear in URLs or payloads. Active-room photos
 * are visible to any authenticated user (catalog content); inactive rooms
 * only to their managers.
 */
class RoomMediaController extends Controller
{
    public function __construct(
        private RoomPermissionResolver $resolver,
        private RoomMediaService $media,
    ) {
    }

    public function show(Request $request, Room $room, RoomPhoto $photo, string $variant): StreamedResponse
    {
        abort_unless((int) $photo->room_id === (int) $room->id, 404);
        abort_unless(in_array($variant, RoomPhoto::VARIANTS, true), 404);
        abort_unless($this->resolver->canViewRoomMedia($request->user(), $room), 404);

        return $this->media->variantResponse($photo, $variant);
    }
}
