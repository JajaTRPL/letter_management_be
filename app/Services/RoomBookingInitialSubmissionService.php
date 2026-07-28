<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingAttachment;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingSubmissionSnapshot;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Atomic initial-submission boundary: idempotency claim, booking transition,
 * private attachment metadata, immutable snapshot/event, and stored outcome.
 */
class RoomBookingInitialSubmissionService
{
    private const SUBJECT_KEY = 'initial-submission';

    public function __construct(
        private RoomBookingIdempotencyService $idempotency,
        private RoomBookingTransitionService $transition,
        private RoomBookingAttachmentService $attachments,
        private RoomBookingSubmissionSnapshotService $snapshots,
        private RoomBookingConflictService $conflicts,
        private RoomBookingOccurrenceService $occurrences,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes Validated booking fields only.
     * @param  callable(array<string, mixed>): array<string, mixed>  $responseBody
     */
    public function submit(
        User $actor,
        array $attributes,
        UploadedFile $file,
        string $idempotencyKey,
        Request $request,
        callable $responseBody,
    ): RoomBookingIdempotencyOutcome {
        $uploadIdentity = $this->attachments->canonicalUploadIdentity($file);
        $attemptAttachment = null;

        try {
            return $this->idempotency->execute(
                actor: $actor,
                // Initial submission has no persisted subject yet. The actual
                // booking is constructed inside the single transaction attempt
                // so a rolled-back model/id can never be reused.
                booking: new RoomBookingRequest,
                action: RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED,
                subjectKey: self::SUBJECT_KEY,
                idempotencyKey: $idempotencyKey,
                canonicalPayload: [
                    'actor' => 'user:'.$actor->id,
                    'action' => RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED,
                    'room_id' => (int) $attributes['room_id'],
                    'activity_name' => (string) $attributes['activity_name'],
                    'purpose' => (string) $attributes['purpose'],
                    'participant_count' => (int) $attributes['participant_count'],
                    'start_at' => Carbon::parse($attributes['start_at'])->toIso8601String(),
                    'end_at' => Carbon::parse($attributes['end_at'])->toIso8601String(),
                    'booking_mode' => (string) ($attributes['booking_mode'] ?? 'single_day'),
                    'occurrence_end_date' => $attributes['occurrence_end_date'] ?? null,
                    'occurrences' => collect($this->occurrences->rangesFromAttributes($attributes))
                        ->map(fn (array $range) => [
                            'start_at' => $range['start_at']->toIso8601String(),
                            'end_at' => $range['end_at']->toIso8601String(),
                        ])->all(),
                    'surat_peminjaman_pdf' => $uploadIdentity,
                ],
                operation: function (string $correlationId) use (
                    $attributes,
                    $actor,
                    $file,
                    $request,
                    &$attemptAttachment,
                ): array {
                    $booking = new RoomBookingRequest(array_merge($attributes, [
                        'requester_id' => $actor->id,
                    ]));
                    $this->assertNoApprovedConflict($booking, $attributes);
                    $submitted = $this->transition->submit($booking, $actor);
                    $this->occurrences->createForBooking($submitted, $actor);
                    $attemptAttachment = $this->attachments->storeSuratPeminjaman(
                        $submitted,
                        $file,
                        $actor,
                        'upload',
                        $request,
                    );
                    $this->snapshots->capture(
                        $submitted->fresh(),
                        $actor,
                        RoomBookingSubmissionSnapshot::PROVENANCE_NATIVE_SUBMISSION,
                    );
                    $submitted = $submitted->fresh();

                    return [
                        'status_code' => 201,
                        'payload' => [
                            'message' => 'Pengajuan peminjaman ruangan berhasil dikirim',
                            'booking_id' => (int) $submitted->id,
                            'stored_status' => RoomBookingStatus::Submitted->value,
                            'effective_status' => $submitted->effectiveStatus(),
                            'workflow_version' => (int) $submitted->workflow_version,
                        ],
                    ];
                },
                responseBody: $responseBody,
                // A framework retry would re-enter with mutable Eloquent and
                // filesystem state. Client retries provide the safe fresh
                // attempt boundary for initial submission instead.
                transactionAttempts: 1,
            );
        } catch (Throwable $exception) {
            if ($attemptAttachment instanceof RoomBookingAttachment) {
                $this->attachments->cleanupFailedPersistedAttachment($attemptAttachment);
            }

            throw $exception;
        }
    }

    private function assertNoApprovedConflict(RoomBookingRequest $booking, array $attributes): void
    {
        $ranges = $this->occurrences->rangesFromAttributes($attributes);
        if (! $this->conflicts->hasConflictForAny(
            (int) $booking->room_id,
            $ranges,
        )) {
            return;
        }

        throw new RoomBookingDomainException(
            RoomBookingDomainException::BOOKING_CONFLICT,
            'Ruangan telah memiliki peminjaman disetujui pada waktu yang bertabrakan.',
            [
                'conflicts' => $this->conflicts->conflictingSummary(
                    (int) $booking->room_id,
                    $booking->start_at,
                    $booking->end_at,
                ),
            ],
        );
    }
}
