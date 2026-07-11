<?php

namespace App\Services;

use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Appends one immutable ledger event per successful business transition.
 * Called inside the same database transaction as the transition itself, so
 * a failed mutation never leaves an event and a recorded event never lacks
 * its mutation. Validation failures and unauthorized attempts do NOT belong
 * here — they stay in the application/security activity logs.
 */
class RoomBookingWorkflowAuditService
{
    /**
     * Metadata keys each event type may carry. Anything else is dropped so
     * arbitrary request payloads can never leak into the ledger.
     */
    private const METADATA_ALLOWLIST = [
        RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED => ['room_id', 'start_at', 'end_at'],
        RoomBookingWorkflowEvent::EVENT_BOOKING_RESUBMITTED => ['room_id', 'start_at', 'end_at'],
        RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED => ['room_id', 'start_at', 'end_at'],
        RoomBookingWorkflowEvent::EVENT_REVISION_REQUESTED => [],
        RoomBookingWorkflowEvent::EVENT_BOOKING_REJECTED => [],
        RoomBookingWorkflowEvent::EVENT_BOOKING_CANCELLED => [],
        RoomBookingWorkflowEvent::EVENT_LEGACY_BASELINE_IMPORTED => [],
    ];

    /**
     * @param  array<string, mixed>  $safeMetadata
     */
    public function record(
        RoomBookingRequest $booking,
        string $eventType,
        User $actor,
        ?string $previousStatus,
        string $resultingStatus,
        ?int $workflowVersionBefore,
        int $workflowVersionAfter,
        ?int $submissionIteration = null,
        ?string $publicNote = null,
        array $safeMetadata = [],
    ): RoomBookingWorkflowEvent {
        return RoomBookingWorkflowEvent::create([
            'room_booking_request_id' => $booking->id,
            'event_type' => $eventType,
            'actor_id' => $actor->id,
            'actor_name_snapshot' => (string) $actor->name,
            'actor_role_snapshot' => (string) $actor->role,
            'actor_subrole_snapshot' => $actor->tendik_role,
            'actor_scope_type' => $actor->laboratory_id !== null ? 'laboratory' : null,
            'actor_scope_id' => $actor->laboratory_id,
            'previous_status' => $previousStatus,
            'resulting_status' => $resultingStatus,
            'workflow_version_before' => $workflowVersionBefore,
            'workflow_version_after' => $workflowVersionAfter,
            'submission_iteration' => $submissionIteration,
            'public_note' => $publicNote,
            'internal_note' => null,
            'safe_metadata' => $this->filterMetadata($eventType, $safeMetadata),
            'correlation_id' => $this->correlationId(),
            'occurred_at' => Carbon::now(config('app.timezone')),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function filterMetadata(string $eventType, array $metadata): ?array
    {
        $allowed = self::METADATA_ALLOWLIST[$eventType] ?? [];
        $filtered = array_intersect_key($metadata, array_flip($allowed));

        return $filtered === [] ? null : $filtered;
    }

    private function correlationId(): string
    {
        $header = request()?->header('X-Request-Id');

        return is_string($header) && Str::isUuid($header)
            ? $header
            : (string) Str::uuid();
    }
}
