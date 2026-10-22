<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingOccurrence;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;

class RoomBookingKeyService
{
    public function __construct(
        private RoomBookingIdempotencyService $idempotency,
        private RoomBookingOccurrenceAuthorizationService $authorization,
        private RoomBookingOccurrenceEventService $events,
    ) {}

    public function issue(
        RoomBookingOccurrence $occurrence,
        User $actor,
        int $expectedVersion,
        ?string $note,
        string $idempotencyKey,
        callable $responseBody,
    ): RoomBookingIdempotencyOutcome {
        $occurrence->loadMissing('booking.room');
        if (! $this->authorization->canIssueOrReceive($actor, $occurrence)) {
            abort(404);
        }

        return $this->idempotency->execute(
            actor: $actor,
            booking: $occurrence->booking,
            action: RoomBookingWorkflowEvent::EVENT_KEY_ISSUED,
            subjectKey: 'occurrence:'.$occurrence->public_id,
            idempotencyKey: $idempotencyKey,
            canonicalPayload: [
                'occurrence_ref' => $occurrence->public_id,
                'expected_version' => $expectedVersion,
                'note' => trim((string) $note),
            ],
            operation: function () use ($occurrence, $actor, $expectedVersion, $note): array {
                $locked = RoomBookingOccurrence::query()
                    ->with('booking.room')
                    ->lockForUpdate()
                    ->findOrFail($occurrence->id);
                if ((int) $locked->version !== $expectedVersion) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::STALE_OCCURRENCE_VERSION,
                        'Data penggunaan telah berubah. Muat ulang sebelum melanjutkan.',
                    );
                }
                if ($locked->booking->status !== RoomBookingStatus::Approved) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::INVALID_TRANSITION,
                        'Kunci hanya dapat diserahkan untuk peminjaman yang telah disetujui.',
                    );
                }
                if ($locked->key_issued_at !== null) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::INVALID_TRANSITION,
                        'Kunci untuk penggunaan ini sudah diserahkan.',
                    );
                }

                $locked->forceFill([
                    'key_issued_at' => now(config('app.timezone')),
                    'key_issued_by' => $actor->id,
                    'key_issued_by_name' => $actor->name,
                    'key_issued_by_role' => $actor->tendik_role,
                    'key_issue_note' => trim((string) $note) ?: null,
                    'version' => $locked->version + 1,
                ])->save();
                $this->events->record(
                    $locked,
                    RoomBookingWorkflowEvent::EVENT_KEY_ISSUED,
                    $actor,
                    'Kunci ruangan telah diserahkan.',
                    ['issued_at' => $locked->key_issued_at->toIso8601String()],
                    (int) $locked->booking->requester_id,
                    'mahasiswa',
                );

                return [
                    'status_code' => 200,
                    'payload' => [
                        'message' => 'Kunci ruangan berhasil diserahkan.',
                        'booking_id' => (int) $locked->room_booking_request_id,
                        'stored_status' => $locked->booking->status->value,
                        'effective_status' => $locked->booking->effectiveStatus(),
                        'workflow_version' => (int) $locked->booking->workflow_version,
                    ],
                ];
            },
            responseBody: $responseBody,
        );
    }
}
