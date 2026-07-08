<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingStatusHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RoomBookingTransitionService
{
    public function __construct(
        private RoomBookingConflictService $conflictService,
        private RoomBookingReviewerResolver $reviewerResolver,
    ) {}

    public function submit(RoomBookingRequest $booking, User $actor): RoomBookingRequest
    {
        if (! $booking->exists) {
            return $this->submitNew($booking, $actor);
        }

        return DB::transaction(function () use ($booking, $actor) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertTransition(
                $lockedBooking,
                RoomBookingStatus::RevisionRequested,
                RoomBookingStatus::Submitted,
            );
            $this->assertOwner($actor, $lockedBooking, resubmission: true);

            $room = $this->room($lockedBooking);
            $this->validateBookingDetails($lockedBooking, $room, requireFutureStart: true);

            return $this->persistTransition(
                $lockedBooking,
                $actor,
                RoomBookingStatus::Submitted,
                [
                    'reviewer_id' => null,
                    'reviewed_at' => null,
                    'revision_note' => null,
                    'rejection_reason' => null,
                    'cancellation_reason' => null,
                ],
            );
        });
    }

    public function validateForSubmission(RoomBookingRequest $booking): void
    {
        $this->validateBookingDetails(
            $booking,
            $this->room($booking),
            requireFutureStart: true,
        );
    }

    public function requestRevision(
        RoomBookingRequest $booking,
        User $actor,
        string $note,
    ): RoomBookingRequest {
        $note = trim($note);
        if ($note === '') {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::NOTE_REQUIRED,
                'Catatan revisi wajib diisi.',
            );
        }

        return DB::transaction(function () use ($booking, $actor, $note) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertTransition(
                $lockedBooking,
                RoomBookingStatus::Submitted,
                RoomBookingStatus::RevisionRequested,
            );
            $this->assertApprover($actor, $lockedBooking);

            return $this->persistTransition(
                $lockedBooking,
                $actor,
                RoomBookingStatus::RevisionRequested,
                [
                    'reviewer_id' => $actor->id,
                    'reviewed_at' => $this->now(),
                    'revision_note' => $note,
                    'rejection_reason' => null,
                ],
                $note,
            );
        });
    }

    public function approve(RoomBookingRequest $booking, User $actor): RoomBookingRequest
    {
        return DB::transaction(function () use ($booking, $actor) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertTransition(
                $lockedBooking,
                RoomBookingStatus::Submitted,
                RoomBookingStatus::Approved,
            );

            $room = Room::query()
                ->lockForUpdate()
                ->findOrFail($lockedBooking->room_id);

            $lockedBooking->setRelation('room', $room);
            $this->assertApprover($actor, $lockedBooking);
            $this->validateBookingDetails($lockedBooking, $room, requireFutureStart: false);

            if ($this->conflictService->hasConflict(
                $room->id,
                $lockedBooking->start_at,
                $lockedBooking->end_at,
                $lockedBooking->id,
            )) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::BOOKING_CONFLICT,
                    'Ruangan sudah memiliki peminjaman disetujui pada waktu yang bertabrakan.',
                    [
                        'conflicts' => $this->conflictService->conflictingSummary(
                            $room->id,
                            $lockedBooking->start_at,
                            $lockedBooking->end_at,
                            $lockedBooking->id,
                        )->all(),
                    ],
                );
            }

            return $this->persistTransition(
                $lockedBooking,
                $actor,
                RoomBookingStatus::Approved,
                [
                    'reviewer_id' => $actor->id,
                    'reviewed_at' => $this->now(),
                    'revision_note' => null,
                    'rejection_reason' => null,
                    'cancellation_reason' => null,
                ],
            );
        });
    }

    public function reject(
        RoomBookingRequest $booking,
        User $actor,
        string $reason,
    ): RoomBookingRequest {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::REASON_REQUIRED,
                'Alasan penolakan wajib diisi.',
            );
        }

        return DB::transaction(function () use ($booking, $actor, $reason) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertTransition(
                $lockedBooking,
                RoomBookingStatus::Submitted,
                RoomBookingStatus::Rejected,
            );
            $this->assertApprover($actor, $lockedBooking);

            return $this->persistTransition(
                $lockedBooking,
                $actor,
                RoomBookingStatus::Rejected,
                [
                    'reviewer_id' => $actor->id,
                    'reviewed_at' => $this->now(),
                    'revision_note' => null,
                    'rejection_reason' => $reason,
                ],
                $reason,
            );
        });
    }

    public function cancel(
        RoomBookingRequest $booking,
        User $actor,
        string $reason,
    ): RoomBookingRequest {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::REASON_REQUIRED,
                'Alasan pembatalan wajib diisi.',
            );
        }

        return DB::transaction(function () use ($booking, $actor, $reason) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertOwner($actor, $lockedBooking);

            if (! in_array($lockedBooking->status, [
                RoomBookingStatus::Submitted,
                RoomBookingStatus::RevisionRequested,
                RoomBookingStatus::Approved,
            ], true)) {
                $this->throwInvalidTransition($lockedBooking, RoomBookingStatus::Cancelled);
            }

            if (
                $lockedBooking->status === RoomBookingStatus::Approved
                && $lockedBooking->start_at->lessThanOrEqualTo($this->now())
            ) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::INVALID_TRANSITION,
                    'Peminjaman yang sudah disetujui tidak dapat dibatalkan setelah jadwal dimulai.',
                    [
                        'from_status' => $lockedBooking->status->value,
                        'to_status' => RoomBookingStatus::Cancelled->value,
                    ],
                );
            }

            return $this->persistTransition(
                $lockedBooking,
                $actor,
                RoomBookingStatus::Cancelled,
                ['cancellation_reason' => $reason],
                $reason,
            );
        });
    }

    private function submitNew(
        RoomBookingRequest $booking,
        User $actor,
    ): RoomBookingRequest {
        $this->assertOwner($actor, $booking);

        return DB::transaction(function () use ($booking, $actor) {
            $room = $this->room($booking);
            $this->validateBookingDetails($booking, $room, requireFutureStart: true);

            $booking->status = RoomBookingStatus::Submitted;
            $booking->reviewer_id = null;
            $booking->reviewed_at = null;
            $booking->save();

            $this->recordHistory(
                $booking,
                null,
                RoomBookingStatus::Submitted,
                $actor,
            );

            return $booking->fresh();
        });
    }

    private function persistTransition(
        RoomBookingRequest $booking,
        User $actor,
        RoomBookingStatus $toStatus,
        array $attributes,
        ?string $historyNote = null,
    ): RoomBookingRequest {
        $fromStatus = $booking->status;

        $booking->fill(array_merge($attributes, ['status' => $toStatus]));
        $booking->save();

        $this->recordHistory(
            $booking,
            $fromStatus,
            $toStatus,
            $actor,
            $historyNote,
        );

        return $booking->fresh();
    }

    private function recordHistory(
        RoomBookingRequest $booking,
        ?RoomBookingStatus $fromStatus,
        RoomBookingStatus $toStatus,
        User $actor,
        ?string $note = null,
    ): void {
        RoomBookingStatusHistory::create([
            'room_booking_request_id' => $booking->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actor->id,
            'note' => $note,
        ]);
    }

    private function assertTransition(
        RoomBookingRequest $booking,
        RoomBookingStatus $expectedFrom,
        RoomBookingStatus $toStatus,
    ): void {
        if ($booking->status !== $expectedFrom) {
            $this->throwInvalidTransition($booking, $toStatus);
        }
    }

    private function throwInvalidTransition(
        RoomBookingRequest $booking,
        RoomBookingStatus $toStatus,
    ): never {
        throw new RoomBookingDomainException(
            RoomBookingDomainException::INVALID_TRANSITION,
            'Status pengajuan saat ini tidak memungkinkan tindakan tersebut.',
            [
                'from_status' => $booking->status?->value,
                'to_status' => $toStatus->value,
            ],
        );
    }

    private function assertOwner(
        User $actor,
        RoomBookingRequest $booking,
        bool $resubmission = false,
    ): void {
        $allowed = $resubmission
            ? $this->reviewerResolver->canResubmit($actor, $booking)
            : $this->reviewerResolver->canCancel($actor, $booking);

        if (! $allowed) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::UNAUTHORIZED_ACTION,
                'Hanya pemohon yang dapat melakukan tindakan ini.',
            );
        }
    }

    private function assertApprover(User $actor, RoomBookingRequest $booking): void
    {
        if (! $this->reviewerResolver->canActAsApprover($actor, $booking)) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::UNAUTHORIZED_ACTION,
                'Anda tidak berwenang meninjau pengajuan ini.',
            );
        }
    }

    private function validateBookingDetails(
        RoomBookingRequest $booking,
        Room $room,
        bool $requireFutureStart,
    ): void {
        $startAt = $booking->start_at;
        $endAt = $booking->end_at;

        if (! $startAt || ! $endAt || ! $startAt->lessThan($endAt)) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::INVALID_TIME_RANGE,
                'Jam selesai harus lebih dari jam mulai.',
            );
        }

        $timezone = config('app.timezone');
        $localStart = $startAt->copy()->setTimezone($timezone);
        $localEnd = $endAt->copy()->setTimezone($timezone);

        if ($localStart->toDateString() !== $localEnd->toDateString()) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::CROSS_MIDNIGHT,
                'Kegiatan harus selesai di hari yang sama. Untuk kegiatan yang melewati tengah malam, ajukan jadwal terpisah.',
            );
        }

        if ($requireFutureStart && ! $localStart->greaterThan($this->now())) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::START_NOT_FUTURE,
                'Jadwal peminjaman harus dimulai setelah waktu saat ini.',
            );
        }

        if (! $room->is_active) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::INACTIVE_ROOM,
                'Ruangan ini sedang tidak aktif dan tidak dapat dipinjam.',
            );
        }

        if (
            $room->type === RoomType::Laboratory
            && $room->owning_laboratory_id === null
        ) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::MISSING_LABORATORY_OWNERSHIP,
                'Ruang laboratorium ini belum memiliki laboratorium pengelola. Silakan hubungi admin.',
            );
        }

        if ((int) $booking->participant_count > (int) $room->capacity) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::CAPACITY_EXCEEDED,
                'Jumlah peserta melebihi kapasitas ruangan.',
                [
                    'participant_count' => (int) $booking->participant_count,
                    'room_capacity' => (int) $room->capacity,
                ],
            );
        }
    }

    private function lockBooking(RoomBookingRequest $booking): RoomBookingRequest
    {
        return RoomBookingRequest::query()
            ->lockForUpdate()
            ->findOrFail($booking->id);
    }

    private function room(RoomBookingRequest $booking): Room
    {
        if ($booking->relationLoaded('room') && $booking->room) {
            return $booking->room;
        }

        return Room::query()->findOrFail($booking->room_id);
    }

    private function now(): Carbon
    {
        return Carbon::now(config('app.timezone'));
    }
}
