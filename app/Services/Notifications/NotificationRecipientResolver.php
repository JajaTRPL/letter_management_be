<?php

namespace App\Services\Notifications;

use App\Enums\RoomType;
use App\Enums\UserStatus;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves the EXACT set of authorized recipients for each matrix family from
 * real service, stage, prodi, department, and laboratory scope. Never
 * broadcasts to an entire role. Role context on the resulting notification is
 * presentation/routing only — the deep-link target re-authorizes on arrival.
 */
class NotificationRecipientResolver
{
    /** Classroom bookings are handled by Sarpras; lab bookings by the lab's Kepala Lab. */
    public function bookingApprover(RoomBookingRequest $booking): ?User
    {
        $room = $this->roomFor($booking);
        if (! $room) {
            return null;
        }

        if ($room->type === RoomType::Classroom) {
            return $this->activeSarpras();
        }

        return $this->activeKepalaLab($room);
    }

    /** The classroom-scope Sarpras user (single operational owner). */
    public function activeSarpras(): ?User
    {
        return User::query()
            ->where('role', 'tendik')
            ->where('tendik_role', 'sarpras')
            ->where('status', UserStatus::Active)
            ->orderBy('id')
            ->first();
    }

    public function activeKepalaLab(Room $room): ?User
    {
        if ($room->type !== RoomType::Laboratory || ! $room->owning_laboratory_id) {
            return null;
        }

        return User::query()
            ->where('role', 'tendik')
            ->where('tendik_role', 'kepala_lab')
            ->where('laboratory_id', $room->owning_laboratory_id)
            ->where('status', UserStatus::Active)
            ->orderBy('id')
            ->first();
    }

    public function activeLaboran(Room $room): ?User
    {
        if ($room->type !== RoomType::Laboratory || ! $room->owning_laboratory_id) {
            return null;
        }

        return User::query()
            ->where('role', 'tendik')
            ->where('tendik_role', 'laboran')
            ->where('laboratory_id', $room->owning_laboratory_id)
            ->where('status', UserStatus::Active)
            ->orderBy('id')
            ->first();
    }

    /**
     * The operational owner for key/return actions on a room: Sarpras for
     * classrooms, the lab's Laboran for laboratories.
     */
    public function operationalOwner(Room $room): ?User
    {
        return $room->type === RoomType::Classroom
            ? $this->activeSarpras()
            : $this->activeLaboran($room);
    }

    /** The applicant who owns a booking, only if still an active account. */
    public function bookingApplicant(RoomBookingRequest $booking): ?User
    {
        $applicant = $booking->relationLoaded('requester')
            ? $booking->requester
            : $booking->requester()->first();

        return $applicant && $applicant->status === UserStatus::Active ? $applicant : null;
    }

    /**
     * Persuratan officers scoped to a letter. The current schema assigns a
     * concrete officer (`assigned_to`); when present that single officer is the
     * recipient, otherwise the active Persuratan pool (still never the whole
     * role beyond the Persuratan specialization).
     *
     * @return Collection<int, User>
     */
    public function letterPersuratan(?int $assignedTo): Collection
    {
        if ($assignedTo !== null) {
            $assigned = User::query()
                ->whereKey($assignedTo)
                ->where('status', UserStatus::Active)
                ->get();
            if ($assigned->isNotEmpty()) {
                return $assigned;
            }
        }

        return User::query()
            ->where('role', 'tendik')
            ->where('tendik_role', 'persuratan')
            ->where('status', UserStatus::Active)
            ->get();
    }

    /**
     * Academic approvers at an exact decision stage, scoped by sub_role plus the
     * prodi/department the stage belongs to. Never all academic users.
     *
     * @param  list<string>  $subRoles  e.g. ['kaprodi','sekprodi'] or ['kadep','sekdep']
     * @return Collection<int, User>
     */
    public function academicApprovers(
        array $subRoles,
        ?int $studyProgramId,
        ?int $departmentId,
    ): Collection {
        $query = User::query()
            ->where('role', 'akademik')
            ->whereIn('sub_role', $subRoles)
            ->where('status', UserStatus::Active);

        // Prodi-level stage: bind to the study program. Department-level stage:
        // bind to the department. A stage must carry the matching scope id.
        if (in_array('kaprodi', $subRoles, true) || in_array('sekprodi', $subRoles, true)) {
            if ($studyProgramId === null) {
                return collect();
            }
            $query->where('study_program_id', $studyProgramId);
        } elseif (in_array('kadep', $subRoles, true) || in_array('sekdep', $subRoles, true)) {
            if ($departmentId === null) {
                return collect();
            }
            $query->where('department_id', $departmentId);
        }

        return $query->get();
    }

    /** Active SuperAdmins receive system-health anomalies only. */
    public function superAdmins(): Collection
    {
        return User::query()
            ->where('role', 'super_admin')
            ->where('status', UserStatus::Active)
            ->get();
    }

    private function roomFor(RoomBookingRequest $booking): ?Room
    {
        return $booking->relationLoaded('room')
            ? $booking->room
            : $booking->room()->first();
    }
}
