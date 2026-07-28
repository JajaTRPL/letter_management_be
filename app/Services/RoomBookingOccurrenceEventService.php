<?php

namespace App\Services;

use App\Models\RoomBookingOccurrence;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use Illuminate\Support\Str;

class RoomBookingOccurrenceEventService
{
    /** @param array<string, scalar|null> $safeMetadata */
    public function record(
        RoomBookingOccurrence $occurrence,
        string $eventType,
        ?User $actor,
        ?string $publicNote = null,
        array $safeMetadata = [],
        ?int $recipientUserId = null,
        ?string $recipientRole = null,
    ): RoomBookingWorkflowEvent {
        $booking = $occurrence->booking;

        return RoomBookingWorkflowEvent::create([
            'room_booking_request_id' => $booking->id,
            'room_booking_occurrence_id' => $occurrence->id,
            'event_type' => $eventType,
            'actor_id' => $actor?->id,
            'actor_name_snapshot' => $actor?->name ?? 'Sistem',
            'actor_role_snapshot' => $actor?->role ?? 'system',
            'actor_subrole_snapshot' => $actor?->tendik_role,
            'actor_scope_type' => $actor?->laboratory_id ? 'laboratory' : null,
            'actor_scope_id' => $actor?->laboratory_id,
            'recipient_user_id' => $recipientUserId,
            'recipient_role' => $recipientRole,
            'previous_status' => $booking->status->value,
            'resulting_status' => $booking->status->value,
            'workflow_version_before' => $booking->workflow_version,
            'workflow_version_after' => $booking->workflow_version,
            'submission_iteration' => $booking->submission_iteration,
            'public_note' => $publicNote,
            'internal_note' => null,
            'safe_metadata' => array_merge([
                'occurrence_public_id' => $occurrence->public_id,
                'occurrence_sequence' => (int) $occurrence->sequence,
            ], $safeMetadata),
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(config('app.timezone')),
        ]);
    }
}
