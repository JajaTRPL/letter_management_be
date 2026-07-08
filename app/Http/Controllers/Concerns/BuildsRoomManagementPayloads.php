<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Room;
use App\Models\RoomAuditLog;
use App\Models\RoomDocumentTemplate;
use App\Models\RoomFacility;
use App\Models\RoomPhoto;
use App\Models\User;
use App\Services\RoomPermissionResolver;

/**
 * Response shapes for Room Management + the mahasiswa catalog. Storage
 * disks/paths never appear here — photos and templates are referenced only
 * by their authenticated delivery endpoints.
 */
trait BuildsRoomManagementPayloads
{
    /** @return array<string, mixed> */
    protected function roomSummaryPayload(Room $room, ?User $user = null): array
    {
        $room->loadMissing([
            'owningLaboratory:id,code,name',
            'coverPhoto',
            'facilityItems.facilityType:id,name,slug',
        ]);

        $payload = [
            'id' => (int) $room->id,
            'code' => $room->code,
            'name' => $room->name,
            'type' => $room->type->value,
            'capacity' => (int) $room->capacity,
            'location' => $room->location,
            'description' => $room->description,
            'rules' => $room->rules,
            'is_active' => (bool) $room->is_active,
            'owning_laboratory' => $room->owningLaboratory ? [
                'id' => (int) $room->owningLaboratory->id,
                'code' => $room->owningLaboratory->code,
                'name' => $room->owningLaboratory->name,
            ] : null,
            'cover_photo' => $room->coverPhoto ? $this->roomPhotoPayload($room->coverPhoto) : null,
            'facilities_summary' => [
                'count' => $room->facilityItems->count(),
                'items' => $room->facilityItems
                    ->take(4)
                    ->map(fn (RoomFacility $facility) => $facility->facilityType?->name)
                    ->filter()
                    ->values()
                    ->all(),
            ],
            'has_active_template' => $room->activeDocumentTemplate() !== null,
        ];

        if ($user !== null) {
            $payload['management_flags'] = $this->roomFlagsFor($user, $room);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    protected function roomPhotoPayload(RoomPhoto $photo): array
    {
        $base = "/api/rooms/{$photo->room_id}/photos/{$photo->id}";

        return [
            'id' => (int) $photo->id,
            'thumb_url' => "{$base}/thumb",
            'display_url' => "{$base}/display",
            'full_url' => $photo->full_path ? "{$base}/full" : null,
            'width' => (int) $photo->width,
            'height' => (int) $photo->height,
            'is_cover' => (bool) $photo->is_cover,
            'sort_order' => (int) $photo->sort_order,
            'original_name' => $photo->original_name,
            'created_at' => $photo->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    protected function roomTemplatePayload(RoomDocumentTemplate $template, ?Room $room = null): array
    {
        return [
            'id' => (int) $template->id,
            'scope' => $template->scope,
            'laboratory_id' => $template->laboratory_id !== null ? (int) $template->laboratory_id : null,
            'version' => (int) $template->version,
            'original_name' => $template->original_name,
            'mime' => $template->mime,
            'size_bytes' => (int) $template->size_bytes,
            'is_active' => (bool) $template->is_active,
            'notes' => $template->notes,
            'created_at' => $template->created_at?->toIso8601String(),
            'download_url' => $room
                ? "/api/room-management/rooms/{$room->id}/templates/{$template->id}/download"
                : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function roomFacilityPayload(RoomFacility $facility): array
    {
        $facility->loadMissing('facilityType:id,name,slug');

        return [
            'facility_type_id' => (int) $facility->facility_type_id,
            'name' => $facility->facilityType?->name,
            'slug' => $facility->facilityType?->slug,
            'quantity' => $facility->quantity !== null ? (int) $facility->quantity : null,
            'condition' => $facility->condition,
            'notes' => $facility->notes,
        ];
    }

    /** @return array<string, mixed> */
    protected function roomAuditPayload(RoomAuditLog $log): array
    {
        return [
            'id' => (int) $log->id,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id !== null ? (int) $log->subject_id : null,
            'action' => $log->action,
            'actor' => $log->actor?->name,
            'details' => $log->details,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, bool> */
    protected function roomFlagsFor(User $user, Room $room): array
    {
        $resolver = app(RoomPermissionResolver::class);

        return array_merge($resolver->roomManagementFlags($user, $room), [
            'can_create' => $resolver->canCreateRoom($user, $room->type->value, $room->owning_laboratory_id),
            'can_activate' => $resolver->canDeactivateRoom($user, $room),
        ]);
    }
}
