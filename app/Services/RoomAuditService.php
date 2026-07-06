<?php

namespace App\Services;

use App\Models\Laboratory;
use App\Models\Room;
use App\Models\RoomAuditLog;
use App\Models\User;

/**
 * Thin writer for room master-data audit rows (photos/facilities/templates/
 * room info). Details are truncated to the column limit and must never
 * contain PII or storage paths — callers pass short human summaries only.
 */
class RoomAuditService
{
    private const MAX_DETAILS = 500;

    public function record(
        ?Room $room,
        string $subjectType,
        ?int $subjectId,
        string $action,
        ?User $actor,
        ?string $details = null,
        ?string $ip = null,
        ?Laboratory $laboratory = null,
    ): RoomAuditLog {
        return RoomAuditLog::create([
            'room_id' => $room?->id,
            'laboratory_id' => $laboratory?->id
                ?? ($room?->owning_laboratory_id),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => mb_substr($action, 0, 32),
            'actor_id' => $actor?->id,
            'details' => $details !== null ? mb_substr($details, 0, self::MAX_DETAILS) : null,
            'ip_address' => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'created_at' => now(),
        ]);
    }
}
