<?php

namespace App\Services;

use App\Enums\RoomBookingReturnStatus;
use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingAuditLog;
use App\Models\RoomBookingOccurrence;
use App\Models\RoomBookingReturnRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RoomBookingReturnService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private RoomBookingIdempotencyService $idempotency,
        private RoomBookingOccurrenceAuthorizationService $authorization,
        private RoomBookingOccurrenceEventService $events,
    ) {}

    public function submit(
        RoomBookingOccurrence $occurrence,
        User $actor,
        UploadedFile $file,
        int $expectedOccurrenceVersion,
        string $idempotencyKey,
        callable $responseBody,
    ): RoomBookingIdempotencyOutcome {
        $occurrence->loadMissing([
            'booking.room', 'activeReturnRequest', 'acceptedReturnRequest',
        ]);
        if ((int) $occurrence->booking->requester_id !== (int) $actor->id) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::UNAUTHORIZED_ACTION,
                'Penggunaan ruangan tidak ditemukan.',
            );
        }

        $image = $this->validatedImage($file);
        $isResubmission = $occurrence->activeReturnRequest?->status
            === RoomBookingReturnStatus::RevisionRequested;
        $eventType = $isResubmission
            ? RoomBookingWorkflowEvent::EVENT_RETURN_RESUBMITTED
            : RoomBookingWorkflowEvent::EVENT_RETURN_SUBMITTED;
        $attemptPath = null;

        try {
            return $this->idempotency->execute(
                actor: $actor,
                booking: $occurrence->booking,
                action: $eventType,
                subjectKey: 'occurrence:'.$occurrence->public_id,
                idempotencyKey: $idempotencyKey,
                canonicalPayload: [
                    'occurrence_ref' => $occurrence->public_id,
                    'expected_version' => $expectedOccurrenceVersion,
                    'image_sha256' => $image['checksum'],
                    'image_size' => strlen($image['bytes']),
                    'image_mime' => $image['mime'],
                ],
                operation: function () use (
                    $occurrence,
                    $actor,
                    $expectedOccurrenceVersion,
                    $image,
                    $eventType,
                    &$attemptPath,
                ): array {
                    $locked = RoomBookingOccurrence::query()
                        ->with(['booking.room', 'activeReturnRequest', 'acceptedReturnRequest'])
                        ->lockForUpdate()->findOrFail($occurrence->id);
                    $this->assertOccurrenceVersion($locked, $expectedOccurrenceVersion);
                    $this->assertCanSubmit($locked);

                    $previous = $locked->activeReturnRequest;
                    if ($previous?->status === RoomBookingReturnStatus::RevisionRequested) {
                        $previous->forceFill(['active_pending_guard' => null])->save();
                    } elseif ($previous) {
                        throw new RoomBookingDomainException(
                            RoomBookingDomainException::RETURN_ALREADY_ACTIVE,
                            'Pengembalian ini sudah memiliki permintaan aktif.',
                        );
                    }

                    $attemptPath = 'room-booking-returns/'.$locked->booking->id
                        .'/'.$locked->public_id.'/'.Str::uuid().'.'.$image['extension'];
                    if (! Storage::disk('local')->put($attemptPath, $image['bytes'])) {
                        throw new \RuntimeException('Return evidence storage write failed.');
                    }

                    $return = new RoomBookingReturnRequest;
                    $return->forceFill([
                        'room_booking_occurrence_id' => $locked->id,
                        'requester_id' => $actor->id,
                        'supersedes_id' => $previous?->id,
                        'status' => RoomBookingReturnStatus::Pending->value,
                        'active_pending_guard' => true,
                        'evidence_disk' => 'local',
                        'evidence_path' => $attemptPath,
                        'evidence_original_name' => $image['original_name'],
                        'evidence_mime' => $image['mime'],
                        'evidence_size_bytes' => strlen($image['bytes']),
                        'evidence_checksum_sha256' => $image['checksum'],
                        'submitted_at' => now(config('app.timezone')),
                    ])->save();
                    $locked->forceFill(['version' => $locked->version + 1])->save();
                    RoomBookingAuditLog::create([
                        'room_booking_request_id' => $locked->booking->id,
                        'actor_id' => $actor->id,
                        'action' => $eventType,
                        'document_type' => 'key_return_evidence',
                        'original_name' => $image['original_name'],
                        'size_bytes' => strlen($image['bytes']),
                        'checksum_sha256' => $image['checksum'],
                        'storage_path_hash' => hash('sha256', $attemptPath),
                        'ip_address' => request()?->ip(),
                        'user_agent' => Str::limit((string) request()?->userAgent(), 1000, ''),
                    ]);
                    $this->events->record(
                        $locked,
                        $eventType,
                        $actor,
                        $eventType === RoomBookingWorkflowEvent::EVENT_RETURN_RESUBMITTED
                            ? 'Bukti pengembalian diperbaiki dan dikirim ulang.'
                            : 'Bukti pengembalian telah dikirim.',
                        ['submitted_at' => $return->submitted_at->toIso8601String()],
                        null,
                        $this->responsibleRole($locked),
                    );

                    return $this->mutationResult($locked, 'Bukti pengembalian berhasil dikirim.');
                },
                responseBody: $responseBody,
                transactionAttempts: 1,
            );
        } catch (Throwable $exception) {
            if ($attemptPath && Storage::disk('local')->exists($attemptPath)) {
                Storage::disk('local')->delete($attemptPath);
            }
            throw $exception;
        }
    }

    public function withdraw(
        RoomBookingOccurrence $occurrence,
        User $actor,
        int $expectedOccurrenceVersion,
        int $expectedReturnVersion,
        string $idempotencyKey,
        callable $responseBody,
    ): RoomBookingIdempotencyOutcome {
        $occurrence->loadMissing('booking.room');
        if ((int) $occurrence->booking->requester_id !== (int) $actor->id) abort(404);

        return $this->idempotency->execute(
            actor: $actor,
            booking: $occurrence->booking,
            action: RoomBookingWorkflowEvent::EVENT_RETURN_WITHDRAWN,
            subjectKey: 'occurrence:'.$occurrence->public_id,
            idempotencyKey: $idempotencyKey,
            canonicalPayload: [
                'occurrence_ref' => $occurrence->public_id,
                'occurrence_version' => $expectedOccurrenceVersion,
                'return_version' => $expectedReturnVersion,
            ],
            operation: function () use ($occurrence, $actor, $expectedOccurrenceVersion, $expectedReturnVersion): array {
                $locked = RoomBookingOccurrence::query()
                    ->with(['booking.room', 'activeReturnRequest'])
                    ->lockForUpdate()->findOrFail($occurrence->id);
                $this->assertOccurrenceVersion($locked, $expectedOccurrenceVersion);
                $return = $locked->activeReturnRequest;
                if (! $return || $return->status !== RoomBookingReturnStatus::Pending) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::INVALID_TRANSITION,
                        'Hanya bukti yang masih menunggu verifikasi yang dapat ditarik.',
                    );
                }
                $this->assertReturnVersion($return, $expectedReturnVersion);
                $return->forceFill([
                    'status' => RoomBookingReturnStatus::Withdrawn,
                    'active_pending_guard' => null,
                    'version' => $return->version + 1,
                ])->save();
                $locked->forceFill(['version' => $locked->version + 1])->save();
                $this->events->record(
                    $locked,
                    RoomBookingWorkflowEvent::EVENT_RETURN_WITHDRAWN,
                    $actor,
                    'Pengajuan bukti pengembalian ditarik.',
                );

                return $this->mutationResult($locked, 'Pengajuan bukti pengembalian berhasil ditarik.');
            },
            responseBody: $responseBody,
        );
    }

    public function decide(
        RoomBookingOccurrence $occurrence,
        User $actor,
        string $decision,
        int $expectedOccurrenceVersion,
        int $expectedReturnVersion,
        ?string $note,
        ?string $receivedAt,
        ?string $receivedTimeReason,
        string $idempotencyKey,
        callable $responseBody,
    ): RoomBookingIdempotencyOutcome {
        $occurrence->loadMissing('booking.room');
        if (! $this->authorization->canIssueOrReceive($actor, $occurrence)) abort(404);
        $eventType = match ($decision) {
            'accept' => RoomBookingWorkflowEvent::EVENT_RETURN_ACCEPTED,
            'revise' => RoomBookingWorkflowEvent::EVENT_RETURN_REVISION_REQUESTED,
            'reject' => RoomBookingWorkflowEvent::EVENT_RETURN_REJECTED,
            default => throw new \InvalidArgumentException('Unknown return decision.'),
        };
        $note = trim((string) $note);
        if (in_array($decision, ['revise', 'reject'], true) && $note === '') {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::NOTE_REQUIRED,
                'Catatan keputusan wajib diisi.',
            );
        }
        $effectiveReceivedAt = $decision === 'accept'
            ? ($receivedAt ? Carbon::parse($receivedAt)->setTimezone(config('app.timezone')) : now(config('app.timezone')))
            : null;
        if ($effectiveReceivedAt?->greaterThan(now(config('app.timezone')))) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::INVALID_TIME_RANGE,
                'Waktu kunci diterima tidak boleh berada di masa depan.',
            );
        }
        if (
            $decision === 'accept'
            && $receivedAt
            && abs($effectiveReceivedAt->diffInSeconds(now(config('app.timezone')), false)) > 60
            && trim((string) $receivedTimeReason) === ''
        ) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::REASON_REQUIRED,
                'Alasan perubahan waktu penerimaan kunci wajib diisi.',
            );
        }

        return $this->idempotency->execute(
            actor: $actor,
            booking: $occurrence->booking,
            action: $eventType,
            subjectKey: 'occurrence:'.$occurrence->public_id,
            idempotencyKey: $idempotencyKey,
            canonicalPayload: [
                'occurrence_ref' => $occurrence->public_id,
                'occurrence_version' => $expectedOccurrenceVersion,
                'return_version' => $expectedReturnVersion,
                'note' => $note,
                'key_received_at' => $effectiveReceivedAt?->toIso8601String(),
                'received_time_reason' => trim((string) $receivedTimeReason),
            ],
            operation: function () use (
                $occurrence, $actor, $decision, $eventType,
                $expectedOccurrenceVersion, $expectedReturnVersion,
                $note, $effectiveReceivedAt, $receivedTimeReason,
            ): array {
                $locked = RoomBookingOccurrence::query()
                    ->with(['booking.room', 'activeReturnRequest'])
                    ->lockForUpdate()->findOrFail($occurrence->id);
                $this->assertOccurrenceVersion($locked, $expectedOccurrenceVersion);
                $return = $locked->activeReturnRequest;
                if (! $return || $return->status !== RoomBookingReturnStatus::Pending) {
                    throw new RoomBookingDomainException(
                        RoomBookingDomainException::INVALID_TRANSITION,
                        'Bukti pengembalian tidak lagi menunggu keputusan.',
                    );
                }
                $this->assertReturnVersion($return, $expectedReturnVersion);
                $status = match ($decision) {
                    'accept' => RoomBookingReturnStatus::Accepted,
                    'revise' => RoomBookingReturnStatus::RevisionRequested,
                    default => RoomBookingReturnStatus::Rejected,
                };
                $return->forceFill([
                    'status' => $status,
                    'active_pending_guard' => $status === RoomBookingReturnStatus::RevisionRequested ? true : null,
                    'version' => $return->version + 1,
                    'decided_by' => $actor->id,
                    'decided_by_name' => $actor->name,
                    'decided_by_role' => $actor->tendik_role,
                    'decision_note' => $note ?: null,
                    'key_received_at' => $effectiveReceivedAt,
                    'received_time_change_reason' => trim((string) $receivedTimeReason) ?: null,
                    'verified_at' => now(config('app.timezone')),
                ])->save();
                $locked->forceFill(['version' => $locked->version + 1])->save();
                $this->events->record(
                    $locked,
                    $eventType,
                    $actor,
                    match ($decision) {
                        'accept' => 'Pengembalian kunci telah diverifikasi.',
                        'revise' => 'Perbaikan bukti pengembalian diperlukan.',
                        default => 'Bukti pengembalian ditolak.',
                    },
                    $effectiveReceivedAt ? ['key_received_at' => $effectiveReceivedAt->toIso8601String()] : [],
                    (int) $locked->booking->requester_id,
                    'mahasiswa',
                );
                if ($decision === 'accept' && trim((string) $receivedTimeReason) !== '') {
                    $this->events->record(
                        $locked,
                        RoomBookingWorkflowEvent::EVENT_KEY_RECEIVED_TIME_ADJUSTED,
                        $actor,
                        'Waktu penerimaan fisik kunci dicatat dengan alasan audit.',
                    );
                }

                return $this->mutationResult($locked, 'Keputusan pengembalian berhasil disimpan.');
            },
            responseBody: $responseBody,
        );
    }

    /** @return array{bytes:string,mime:string,extension:string,checksum:string,original_name:string} */
    public function validatedImage(UploadedFile $file): array
    {
        $raw = file_get_contents($file->getRealPath());
        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw new RoomBookingDomainException(RoomBookingDomainException::INVALID_ATTACHMENT, 'Bukti harus berupa gambar maksimal 5 MiB.');
        }
        $info = @getimagesizefromstring($raw);
        $mime = is_array($info) ? ($info['mime'] ?? null) : null;
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        $clientExtension = strtolower($file->getClientOriginalExtension());
        if (! $extension || ! in_array($clientExtension, [$extension, $extension === 'jpg' ? 'jpeg' : $extension], true)) {
            throw new RoomBookingDomainException(RoomBookingDomainException::INVALID_ATTACHMENT, 'Bukti harus berupa JPG, PNG, atau WebP yang valid.');
        }
        $image = @imagecreatefromstring($raw);
        if (! $image) {
            throw new RoomBookingDomainException(RoomBookingDomainException::INVALID_ATTACHMENT, 'Isi berkas gambar tidak valid.');
        }
        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 90),
            'image/png' => imagepng($image, null, 6),
            'image/webp' => imagewebp($image, null, 90),
        };
        $clean = ob_get_clean();
        imagedestroy($image);
        if (! is_string($clean) || $clean === '') throw new \RuntimeException('Image normalization failed.');

        return [
            'bytes' => $clean,
            'mime' => $mime,
            'extension' => $extension,
            'checksum' => hash('sha256', $clean),
            'original_name' => Str::limit(preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($file->getClientOriginalName())) ?: 'bukti.'.$extension, 255, ''),
        ];
    }

    private function assertCanSubmit(RoomBookingOccurrence $occurrence): void
    {
        if ($occurrence->booking->status !== RoomBookingStatus::Approved) {
            throw new RoomBookingDomainException(RoomBookingDomainException::INVALID_TRANSITION, 'Pengembalian hanya tersedia untuk peminjaman disetujui.');
        }
        if (! $occurrence->key_issued_at) {
            throw new RoomBookingDomainException(RoomBookingDomainException::KEY_NOT_ISSUED, 'Kunci belum diserahkan untuk penggunaan ini.');
        }
        if (now(config('app.timezone'))->lessThan($occurrence->end_at)) {
            throw new RoomBookingDomainException(RoomBookingDomainException::OCCURRENCE_NOT_READY, 'Bukti pengembalian dapat dikirim setelah waktu penggunaan berakhir.');
        }
        if ($occurrence->acceptedReturnRequest) {
            throw new RoomBookingDomainException(RoomBookingDomainException::RETURN_ALREADY_ACCEPTED, 'Pengembalian ini sudah diterima.');
        }
    }

    private function assertOccurrenceVersion(RoomBookingOccurrence $occurrence, int $expected): void
    {
        if ((int) $occurrence->version !== $expected) {
            throw new RoomBookingDomainException(RoomBookingDomainException::STALE_OCCURRENCE_VERSION, 'Data penggunaan telah berubah. Muat ulang sebelum melanjutkan.');
        }
    }

    private function assertReturnVersion(RoomBookingReturnRequest $return, int $expected): void
    {
        if ((int) $return->version !== $expected) {
            throw new RoomBookingDomainException(RoomBookingDomainException::STALE_RETURN_VERSION, 'Data pengembalian telah berubah. Muat ulang sebelum melanjutkan.');
        }
    }

    private function responsibleRole(RoomBookingOccurrence $occurrence): string
    {
        return $occurrence->booking->room->type->value === 'classroom' ? 'sarpras' : 'laboran';
    }

    private function mutationResult(RoomBookingOccurrence $occurrence, string $message): array
    {
        return [
            'status_code' => 200,
            'payload' => [
                'message' => $message,
                'booking_id' => (int) $occurrence->room_booking_request_id,
                'stored_status' => $occurrence->booking->status->value,
                'effective_status' => $occurrence->booking->effectiveStatus(),
                'workflow_version' => (int) $occurrence->booking->workflow_version,
            ],
        ];
    }
}
