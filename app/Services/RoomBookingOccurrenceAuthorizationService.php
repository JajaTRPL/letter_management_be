<?php

namespace App\Services;

use App\Enums\RoomType;
use App\Enums\UserStatus;
use App\Models\RoomBookingOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class RoomBookingOccurrenceAuthorizationService
{
    public function canRead(User $actor, RoomBookingOccurrence $occurrence): bool
    {
        if ($actor->status !== UserStatus::Active) return false;
        $booking = $occurrence->booking;
        if ($actor->role === 'mahasiswa') {
            return (int) $booking->requester_id === (int) $actor->id;
        }
        if ($actor->role === 'super_admin') return true;
        if ($actor->role !== 'tendik') return false;

        $room = $booking->room;
        if ($actor->isTendikSarpras()) return $room->type === RoomType::Classroom;
        if (! ($actor->isLaboran() || $actor->isKalab())) return false;

        return $room->type === RoomType::Laboratory
            && $actor->laboratory_id !== null
            && (int) $room->owning_laboratory_id === (int) $actor->laboratory_id;
    }

    public function canIssueOrReceive(User $actor, RoomBookingOccurrence $occurrence): bool
    {
        if (! $this->canRead($actor, $occurrence) || $actor->role !== 'tendik') return false;
        $room = $occurrence->booking->room;

        return ($room->type === RoomType::Classroom && $actor->isTendikSarpras())
            || ($room->type === RoomType::Laboratory && $actor->isLaboran());
    }

    public function scopeOperational(Builder $query, User $actor): Builder
    {
        if ($actor->role === 'super_admin') return $query;
        if ($actor->role !== 'tendik' || $actor->status !== UserStatus::Active) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('booking.room', function (Builder $room) use ($actor): void {
            if ($actor->isTendikSarpras()) {
                $room->where('type', RoomType::Classroom->value);
            } elseif (($actor->isLaboran() || $actor->isKalab()) && $actor->laboratory_id) {
                $room->where('type', RoomType::Laboratory->value)
                    ->where('owning_laboratory_id', $actor->laboratory_id);
            } else {
                $room->whereRaw('1 = 0');
            }
        });
    }
}
