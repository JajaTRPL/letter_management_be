<?php

namespace App\Services;

use App\Models\RoomBookingRequest;
use App\Models\RoomBookingSubmissionSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Writes the immutable evidence row for one authoritative submission
 * (initial submit or resubmit). One snapshot per (booking, iteration);
 * a retry carrying the identical canonical payload returns the existing
 * row, a differing payload for the same iteration is refused.
 */
class RoomBookingSubmissionSnapshotService
{
    public function capture(
        RoomBookingRequest $booking,
        ?User $actor,
        string $provenance,
    ): RoomBookingSubmissionSnapshot {
        $booking->loadMissing(['room.owningLaboratory:id,code,name', 'suratPeminjamanAttachment']);

        $payload = $this->canonicalPayload($booking);
        $checksum = $this->checksum($payload);
        // Model instances created before a refresh may not carry the
        // DB-default column; a missing iteration always means iteration 1.
        $iteration = max(1, (int) ($booking->submission_iteration ?? 1));

        $existing = RoomBookingSubmissionSnapshot::query()
            ->where('room_booking_request_id', $booking->id)
            ->where('submission_iteration', $iteration)
            ->first();

        if ($existing) {
            if ($existing->payload_checksum === $checksum) {
                return $existing;
            }

            throw new RuntimeException(
                'A different submission snapshot already exists for this booking iteration.',
            );
        }

        $attachment = $booking->suratPeminjamanAttachment;
        $requester = $booking->requester ?? $actor;

        return RoomBookingSubmissionSnapshot::create([
            'room_booking_request_id' => $booking->id,
            'submission_iteration' => $iteration,
            'schema_version' => RoomBookingSubmissionSnapshot::SCHEMA_VERSION,
            'payload' => $payload,
            'payload_checksum' => $checksum,
            'attachment_id' => $attachment?->id,
            'attachment_checksum' => $attachment?->checksum_sha256,
            'submitted_by' => $actor?->id ?? $booking->requester_id,
            'requester_name_snapshot' => (string) ($requester?->name ?? '-'),
            'requester_identifier_snapshot' => $requester?->email,
            'requester_role_snapshot' => (string) ($requester?->role ?? 'mahasiswa'),
            'room_id_snapshot' => (int) $booking->room_id,
            'room_name_snapshot' => (string) $booking->room?->name,
            'room_type_snapshot' => (string) $booking->room?->type->value,
            'laboratory_id_snapshot' => $booking->room?->owning_laboratory_id,
            'laboratory_name_snapshot' => $booking->room?->owningLaboratory?->name,
            'submitted_at' => Carbon::now(config('app.timezone')),
            'provenance' => $provenance,
        ]);
    }

    /**
     * Canonical, allowlisted business payload — only what is needed to
     * reproduce the submitted request. No paths, tokens, capability data,
     * or framework metadata.
     *
     * @return array<string, mixed>
     */
    private function canonicalPayload(RoomBookingRequest $booking): array
    {
        $attachment = $booking->suratPeminjamanAttachment;

        $payload = [
            'activity_name' => (string) $booking->activity_name,
            'attachment_checksum_sha256' => $attachment?->checksum_sha256,
            'attachment_document_type' => $attachment?->document_type,
            'end_at' => $booking->end_at?->toIso8601String(),
            'participant_count' => (int) $booking->participant_count,
            'purpose' => (string) $booking->purpose,
            'room_id' => (int) $booking->room_id,
            'schema_version' => RoomBookingSubmissionSnapshot::SCHEMA_VERSION,
            'start_at' => $booking->start_at?->toIso8601String(),
        ];

        ksort($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checksum(array $payload): string
    {
        ksort($payload);

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $encoded);
    }
}
