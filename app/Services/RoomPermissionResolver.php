<?php

namespace App\Services;

use App\Enums\RoomType;
use App\Enums\UserStatus;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Room MASTER-DATA permissions (info, photos, facilities, templates).
 *
 * Deliberately separate from RoomBookingReviewerResolver: managing a room's
 * data and approving bookings are different authorities —
 *   SuperAdmin  : manages everything, approves nothing.
 *   Sarpras     : manages classrooms (incl. create/deactivate).
 *   Kepala Lab  : edits own-lab rooms; no create/deactivate.
 *   Laboran     : edits ALL laboratory rooms' data; no create/deactivate,
 *                 and (unchanged) no booking approval.
 *   Mahasiswa   : reads the active catalog only.
 */
class RoomPermissionResolver
{
    public function canManageAnyRoom(User $user): bool
    {
        return $this->isActive($user) && $user->role === 'super_admin';
    }

    public function canManageClassroom(User $user): bool
    {
        return $this->canManageAnyRoom($user)
            || ($this->isActive($user) && $user->isTendikSarpras());
    }

    public function canManageRoomInfo(User $user, Room $room): bool
    {
        if ($room->type === RoomType::Classroom) {
            return $this->canManageClassroom($user);
        }

        return $this->canManageLaboratoryRoom($user, $room);
    }

    public function canManageRoomMedia(User $user, Room $room): bool
    {
        return $this->canManageRoomInfo($user, $room);
    }

    public function canManageRoomFacilities(User $user, Room $room): bool
    {
        return $this->canManageRoomInfo($user, $room);
    }

    public function canManageRoomTemplates(User $user, Room $room): bool
    {
        return $this->canManageRoomInfo($user, $room);
    }

    /**
     * Room lifecycle (create) stays with SuperAdmin, plus Sarpras for
     * classrooms. Kepala Lab/Laboran edit existing rooms only.
     */
    public function canCreateRoom(User $user, string $type, ?int $laboratoryId = null): bool
    {
        if ($this->canManageAnyRoom($user)) {
            return true;
        }

        return $type === RoomType::Classroom->value && $this->canManageClassroom($user);
    }

    public function canDeactivateRoom(User $user, Room $room): bool
    {
        if ($this->canManageAnyRoom($user)) {
            return true;
        }

        return $room->type === RoomType::Classroom && $this->canManageClassroom($user);
    }

    public function canReadRoomManagement(User $user, Room $room): bool
    {
        return $this->canManageRoomInfo($user, $room);
    }

    /**
     * Rooms this user may see in the management surface.
     */
    public function manageableRoomsQuery(User $user): Builder
    {
        $query = Room::query();

        if ($this->canManageAnyRoom($user)) {
            return $query;
        }

        if (! $this->isActive($user)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isTendikSarpras()) {
            return $query->where('type', RoomType::Classroom->value);
        }

        if ($user->isLaboran()) {
            return $query->where('type', RoomType::Laboratory->value);
        }

        if ($user->isKalab() && $user->laboratory_id) {
            return $query
                ->where('type', RoomType::Laboratory->value)
                ->where('owning_laboratory_id', $user->laboratory_id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Per-room capability flags for API payloads, so the frontend renders
     * actions from data instead of duplicating role logic.
     *
     * @return array<string, bool>
     */
    public function roomManagementFlags(User $user, Room $room): array
    {
        return [
            'can_edit_info' => $this->canManageRoomInfo($user, $room),
            'can_manage_media' => $this->canManageRoomMedia($user, $room),
            'can_manage_facilities' => $this->canManageRoomFacilities($user, $room),
            'can_manage_templates' => $this->canManageRoomTemplates($user, $room),
            'can_deactivate' => $this->canDeactivateRoom($user, $room),
        ];
    }

    private function canManageLaboratoryRoom(User $user, Room $room): bool
    {
        if ($room->type !== RoomType::Laboratory) {
            return false;
        }

        if ($this->canManageAnyRoom($user)) {
            return true;
        }

        if (! $this->isActive($user)) {
            return false;
        }

        // Laboran helps maintain data across ALL laboratories (product
        // decision); approval authority is untouched and stays with the
        // Kepala Lab of each laboratory.
        if ($user->isLaboran()) {
            return true;
        }

        return $user->isKalab()
            && $user->laboratory_id !== null
            && (int) $room->owning_laboratory_id === (int) $user->laboratory_id;
    }

    private function isActive(User $user): bool
    {
        return $user->status === UserStatus::Active;
    }
}
