<?php

namespace App\Services;

use App\Enums\RoomType;
use App\Enums\UserStatus;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class RoomBookingReviewerResolver
{
    public function canReadReviewQueue(User $user): bool
    {
        return $this->isActive($user)
            && ($user->isTendikSarpras() || $user->isKalab() || $user->isLaboran());
    }

    public function canReview(User $user, RoomBookingRequest $booking): bool
    {
        return $this->canRead($user, $booking);
    }

    public function canRead(User $user, RoomBookingRequest $booking): bool
    {
        if (! $this->canReadReviewQueue($user)) {
            return false;
        }

        $room = $this->roomFor($booking);
        if (! $room) {
            return false;
        }

        if ($user->isTendikSarpras()) {
            return $room->type === RoomType::Classroom;
        }

        return $this->isScopedLaboratoryRoom($user, $room);
    }

    public function canActAsApprover(User $user, RoomBookingRequest $booking): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        $room = $this->roomFor($booking);
        if (! $room) {
            return false;
        }

        if ($user->isTendikSarpras()) {
            return $room->type === RoomType::Classroom;
        }

        return $user->isKalab() && $this->isScopedLaboratoryRoom($user, $room);
    }

    public function canCancel(User $user, RoomBookingRequest $booking): bool
    {
        return $user->role === 'mahasiswa'
            && (int) $booking->requester_id === (int) $user->id;
    }

    public function canResubmit(User $user, RoomBookingRequest $booking): bool
    {
        return $this->canCancel($user, $booking);
    }

    public function scopeReviewableBookings(Builder $query, User $user): Builder
    {
        if (! $this->canReadReviewQueue($user)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isTendikSarpras()) {
            return $query->whereHas(
                'room',
                fn (Builder $roomQuery) => $roomQuery->where('type', RoomType::Classroom->value),
            );
        }

        if (! $user->laboratory_id) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('room', function (Builder $roomQuery) use ($user) {
            $roomQuery
                ->where('type', RoomType::Laboratory->value)
                ->where('owning_laboratory_id', $user->laboratory_id);
        });
    }

    public function findActiveKepalaLab(Room $room): ?User
    {
        if ($room->type !== RoomType::Laboratory || ! $room->owning_laboratory_id) {
            return null;
        }

        return User::query()
            ->where('role', 'tendik')
            ->where('tendik_role', 'kepala_lab')
            ->where('laboratory_id', $room->owning_laboratory_id)
            ->where('status', UserStatus::Active)
            ->first();
    }

    private function isActive(User $user): bool
    {
        return $user->status === UserStatus::Active;
    }

    private function roomFor(RoomBookingRequest $booking): ?Room
    {
        if ($booking->relationLoaded('room')) {
            return $booking->room;
        }

        return $booking->room()->first();
    }

    private function isScopedLaboratoryRoom(User $user, Room $room): bool
    {
        return $room->type === RoomType::Laboratory
            && $user->laboratory_id !== null
            && (int) $room->owning_laboratory_id === (int) $user->laboratory_id;
    }
}
