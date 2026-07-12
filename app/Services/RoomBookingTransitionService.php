<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingStatusHistory;
use App\Models\RoomBookingSubmissionSnapshot;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RoomBookingTransitionService
{
    public function __construct(
        private RoomBookingConflictService $conflictService,
        private RoomBookingReviewerResolver $reviewerResolver,
        private RoomBookingWorkflowAuditService $workflowAudit,
        private RoomBookingSubmissionSnapshotService $submissionSnapshots,
        private RoomBookingWithdrawalPolicy $withdrawalPolicy,
        private RoomBookingIdempotencyService $idempotency,
    ) {}

    public function submit(
        RoomBookingRequest $booking,
        User $actor,
        ?int $expectedWorkflowVersion = null,
    ): RoomBookingRequest {
        if (! $booking->exists) {
            return $this->submitNew($booking, $actor);
        }

        return DB::transaction(function () use ($booking, $actor, $expectedWorkflowVersion) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertExpectedWorkflowVersion($lockedBooking, $expectedWorkflowVersion);
            $this->assertNoPendingCancellationRequest($lockedBooking);
            $this->assertTransition(
                $lockedBooking,
                RoomBookingStatus::RevisionRequested,
                RoomBookingStatus::Submitted,
            );
            $this->assertOwner($actor, $lockedBooking, resubmission: true);

            $room = $this->room($lockedBooking);
            $this->validateBookingDetails($lockedBooking, $room, requireFutureStart: true);

            $resubmitted = $this->persistTransition(
                $lockedBooking,
                $actor,
                RoomBookingStatus::Submitted,
                RoomBookingWorkflowEvent::EVENT_BOOKING_RESUBMITTED,
                [
                    'reviewer_id' => null,
                    'reviewed_at' => null,
                    'revision_note' => null,
                    'rejection_reason' => null,
                    'cancellation_reason' => null,
                ],
                submissionIteration: (int) $lockedBooking->submission_iteration + 1,
            );

            // The resubmitted payload becomes authoritative now, so its
            // immutable evidence is written in the same transaction. The
            // attachment already exists at this point (enforced by the
            // resubmit endpoint before this service is called).
            $this->submissionSnapshots->capture(
                $resubmitted,
                $actor,
                RoomBookingSubmissionSnapshot::PROVENANCE_NATIVE_RESUBMISSION,
            );

            return $resubmitted;
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

    /**
     * Authoritative in-revision edit. All guards run against the LOCKED
     * booking so this serializes with cancellation-request creation and every
     * lifecycle transition: whichever operation locks first wins, the loser
     * gets a domain 409 instead of committing against stale state.
     *
     * Deliberately does NOT increment workflow_version, write history/events,
     * or capture a snapshot — an in-progress revision edit only becomes
     * authoritative at resubmit (frozen C7B1 behavior).
     *
     * @param  array<string, mixed>  $attributes validated business fields only
     */
    public function updateRevision(
        RoomBookingRequest $booking,
        User $actor,
        array $attributes,
        ?int $expectedWorkflowVersion = null,
    ): RoomBookingRequest {
        return DB::transaction(function () use ($booking, $actor, $attributes, $expectedWorkflowVersion) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertExpectedWorkflowVersion($lockedBooking, $expectedWorkflowVersion);
            $this->assertOwner($actor, $lockedBooking, resubmission: true);
            $this->assertActiveActor($actor);

            if ($lockedBooking->status !== RoomBookingStatus::RevisionRequested) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::INVALID_TRANSITION,
                    'Pengajuan hanya dapat diubah saat berstatus revision_requested.',
                );
            }

            $this->assertNoPendingCancellationRequest($lockedBooking);

            $lockedBooking->fill($attributes);
            $lockedBooking->unsetRelation('room');

            // Documented lock order: booking first, then room.
            $room = Room::query()
                ->lockForUpdate()
                ->findOrFail($lockedBooking->room_id);
            $lockedBooking->setRelation('room', $room);
            $this->validateBookingDetails($lockedBooking, $room, requireFutureStart: true);

            if ($this->conflictService->hasConflict(
                (int) $lockedBooking->room_id,
                $lockedBooking->start_at,
                $lockedBooking->end_at,
                $lockedBooking->id,
            )) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::BOOKING_CONFLICT,
                    'Ruangan telah memiliki peminjaman disetujui pada waktu yang bertabrakan.',
                    [
                        'conflicts' => $this->conflictService->conflictingSummary(
                            (int) $lockedBooking->room_id,
                            $lockedBooking->start_at,
                            $lockedBooking->end_at,
                            $lockedBooking->id,
                        )->all(),
                    ],
                );
            }

            $lockedBooking->save();

            return $lockedBooking->fresh();
        });
    }

    private function assertActiveActor(User $actor): void
    {
        $actor->refresh();

        if ($actor->status !== \App\Enums\UserStatus::Active) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::UNAUTHORIZED_ACTION,
                'Akun Anda tidak aktif dan tidak dapat melakukan tindakan ini.',
            );
        }
    }

    public function requestRevision(
        RoomBookingRequest $booking,
        User $actor,
        string $note,
        ?int $expectedWorkflowVersion = null,
    ): RoomBookingRequest {
        $note = trim($note);
        if ($note === '') {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::NOTE_REQUIRED,
                'Catatan revisi wajib diisi.',
            );
        }

        return DB::transaction(function () use ($booking, $actor, $note, $expectedWorkflowVersion) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertExpectedWorkflowVersion($lockedBooking, $expectedWorkflowVersion);
            $this->assertNoPendingCancellationRequest($lockedBooking);
            $this->assertTransition(
                $lockedBooking,
                RoomBookingStatus::Submitted,
                RoomBookingStatus::RevisionRequested,
            );
            $this->assertApprover($actor, $lockedBooking);

            if ($lockedBooking->isExpired()) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::BOOKING_EXPIRED,
                    'Pengajuan yang sudah melewati waktu mulai tidak dapat diminta revisi.',
                );
            }

            return $this->persistTransition(
                $lockedBooking,
                $actor,
                RoomBookingStatus::RevisionRequested,
                RoomBookingWorkflowEvent::EVENT_REVISION_REQUESTED,
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

    public function approve(
        RoomBookingRequest $booking,
        User $actor,
        ?int $expectedWorkflowVersion = null,
    ): RoomBookingRequest {
        return DB::transaction(function () use ($booking, $actor, $expectedWorkflowVersion) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertExpectedWorkflowVersion($lockedBooking, $expectedWorkflowVersion);
            $this->assertNoPendingCancellationRequest($lockedBooking);
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

            // A pending request whose activity has already started can no
            // longer become an official reservation. Server time only; no
            // status change, no history, no event.
            if (
                $lockedBooking->start_at === null
                || ! $lockedBooking->start_at->greaterThan($this->now())
            ) {
                throw new RoomBookingDomainException(
                    RoomBookingDomainException::BOOKING_START_PASSED,
                    'Pengajuan tidak dapat disetujui karena waktu kegiatan sudah dimulai atau terlewati.',
                    [
                        'start_at' => $lockedBooking->start_at?->toIso8601String(),
                    ],
                );
            }

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
                RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED,
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
        ?int $expectedWorkflowVersion = null,
    ): RoomBookingRequest {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::REASON_REQUIRED,
                'Alasan penolakan wajib diisi.',
            );
        }

        return DB::transaction(function () use ($booking, $actor, $reason, $expectedWorkflowVersion) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertExpectedWorkflowVersion($lockedBooking, $expectedWorkflowVersion);
            $this->assertNoPendingCancellationRequest($lockedBooking);

            $rejectable = $lockedBooking->status === RoomBookingStatus::Submitted
                || (
                    $lockedBooking->status === RoomBookingStatus::RevisionRequested
                    && $lockedBooking->isExpired()
                );
            if (! $rejectable) {
                $this->throwInvalidTransition($lockedBooking, RoomBookingStatus::Rejected);
            }
            $this->assertApprover($actor, $lockedBooking);

            return $this->persistTransition(
                $lockedBooking,
                $actor,
                RoomBookingStatus::Rejected,
                RoomBookingWorkflowEvent::EVENT_BOOKING_REJECTED,
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

    /**
     * Deprecated compatibility method. It now performs only an eligible
     * direct requester withdrawal; it never creates a cancellation request.
     */
    public function cancel(
        RoomBookingRequest $booking,
        User $actor,
        string $reason,
        ?int $expectedWorkflowVersion = null,
    ): RoomBookingRequest {
        return $this->legacyWithdraw($booking, $actor, $reason, $expectedWorkflowVersion);
    }

    public function legacyWithdraw(
        RoomBookingRequest $booking,
        User $actor,
        string $reason,
        ?int $expectedWorkflowVersion = null,
    ): RoomBookingRequest {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::REASON_REQUIRED,
                'Alasan pembatalan wajib diisi.',
            );
        }

        return DB::transaction(function () use ($booking, $actor, $reason, $expectedWorkflowVersion) {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertExpectedWorkflowVersion($lockedBooking, $expectedWorkflowVersion);

            return $this->performDirectWithdrawalLocked($lockedBooking, $actor, $reason);
        });
    }

    public function withdraw(
        RoomBookingRequest $booking,
        User $actor,
        string $reason,
        int $expectedWorkflowVersion,
        string $idempotencyKey,
        ?callable $responseBody = null,
    ): RoomBookingIdempotencyOutcome {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::REASON_REQUIRED,
                'Alasan penarikan pengajuan wajib diisi.',
            );
        }

        return $this->idempotency->execute(
            actor: $actor,
            booking: $booking,
            action: RoomBookingWorkflowEvent::EVENT_BOOKING_WITHDRAWN,
            subjectKey: 'booking:'.$booking->id,
            idempotencyKey: $idempotencyKey,
            canonicalPayload: [
                'expected_workflow_version' => $expectedWorkflowVersion,
                'reason' => $reason,
            ],
            operation: function (string $correlationId) use (
                $booking,
                $actor,
                $reason,
                $expectedWorkflowVersion,
            ) {
                $lockedBooking = $this->lockBooking($booking);
                $actor->refresh();
                $this->assertExpectedWorkflowVersion($lockedBooking, $expectedWorkflowVersion);
                $withdrawn = $this->performDirectWithdrawalLocked(
                    $lockedBooking,
                    $actor,
                    $reason,
                    $correlationId,
                );

                return [
                    'status_code' => 200,
                    'payload' => $this->safeActionResult(
                        'Pengajuan peminjaman ruangan berhasil ditarik',
                        $withdrawn,
                    ),
                ];
            },
            responseBody: $responseBody,
        );
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
            $booking->workflow_version = 1;
            $booking->submission_iteration = 1;
            $booking->reviewer_id = null;
            $booking->reviewed_at = null;
            $booking->save();

            $this->recordHistory(
                $booking,
                null,
                RoomBookingStatus::Submitted,
                $actor,
            );

            $this->workflowAudit->record(
                $booking,
                RoomBookingWorkflowEvent::EVENT_BOOKING_SUBMITTED,
                $actor,
                null,
                RoomBookingStatus::Submitted->value,
                null,
                1,
                1,
                null,
                [
                    'room_id' => (int) $booking->room_id,
                    'start_at' => $booking->start_at?->toIso8601String(),
                    'end_at' => $booking->end_at?->toIso8601String(),
                ],
            );

            return $booking->fresh();
        });
    }

    /**
     * Persists one authoritative transition: status + attribute changes,
     * exactly one workflow_version increment, the legacy status-history row,
     * and one immutable workflow event — all inside the caller's transaction.
     */
    private function persistTransition(
        RoomBookingRequest $booking,
        User $actor,
        RoomBookingStatus $toStatus,
        string $eventType,
        array $attributes,
        ?string $historyNote = null,
        ?int $submissionIteration = null,
        array $safeMetadata = [],
        ?string $correlationId = null,
    ): RoomBookingRequest {
        $fromStatus = $booking->status;
        $versionBefore = (int) ($booking->workflow_version ?? 1);
        $versionAfter = $versionBefore + 1;

        // The attributes are constructed by trusted domain methods only.
        // Request-derived arrays are never forwarded to forceFill.
        $booking->forceFill(array_merge(
            $attributes,
            [
                'status' => $toStatus,
                'workflow_version' => $versionAfter,
            ],
            $submissionIteration !== null
                ? ['submission_iteration' => $submissionIteration]
                : [],
        ));
        $booking->save();

        $this->recordHistory(
            $booking,
            $fromStatus,
            $toStatus,
            $actor,
            $historyNote,
        );

        $this->workflowAudit->record(
            $booking,
            $eventType,
            $actor,
            $fromStatus?->value,
            $toStatus->value,
            $versionBefore,
            $versionAfter,
            max(1, (int) ($booking->submission_iteration ?? 1)),
            $historyNote,
            array_merge([
                'room_id' => (int) $booking->room_id,
                'start_at' => $booking->start_at?->toIso8601String(),
                'end_at' => $booking->end_at?->toIso8601String(),
            ], $safeMetadata),
            $correlationId,
        );

        return $booking->fresh();
    }

    public function recordSameStatusMutationLocked(
        RoomBookingRequest $booking,
        User $actor,
        string $eventType,
        array $attributes = [],
        ?string $publicNote = null,
        array $safeMetadata = [],
        ?string $correlationId = null,
    ): RoomBookingRequest {
        $allowed = [
            RoomBookingWorkflowEvent::EVENT_REVIEW_STARTED,
            RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUESTED,
            RoomBookingWorkflowEvent::EVENT_CANCELLATION_REQUEST_WITHDRAWN,
            RoomBookingWorkflowEvent::EVENT_CANCELLATION_REJECTED,
        ];
        if (! in_array($eventType, $allowed, true)) {
            throw new \LogicException('Unsupported same-status room booking mutation.');
        }

        $versionBefore = (int) ($booking->workflow_version ?? 1);
        $versionAfter = $versionBefore + 1;
        $booking->forceFill(array_merge($attributes, [
            'workflow_version' => $versionAfter,
        ]))->save();

        $this->workflowAudit->record(
            $booking,
            $eventType,
            $actor,
            $booking->status->value,
            $booking->status->value,
            $versionBefore,
            $versionAfter,
            max(1, (int) ($booking->submission_iteration ?? 1)),
            $publicNote,
            $safeMetadata,
            $correlationId,
        );

        return $booking->fresh();
    }

    public function approveCancellationLocked(
        RoomBookingRequest $booking,
        User $actor,
        string $reason,
        int $cancellationRequestId,
        ?string $correlationId = null,
    ): RoomBookingRequest {
        return $this->persistTransition(
            $booking,
            $actor,
            RoomBookingStatus::Cancelled,
            RoomBookingWorkflowEvent::EVENT_CANCELLATION_APPROVED,
            [
                'cancellation_reason' => $reason,
                'cancellation_source' => 'request_approved',
                'cancelled_by_role_snapshot' => $this->actorRoleSnapshot($actor),
            ],
            $reason,
            safeMetadata: [
                'cancellation_request_id' => $cancellationRequestId,
                'cancellation_source' => 'request_approved',
            ],
            correlationId: $correlationId,
        );
    }

    public function assertExpectedWorkflowVersion(
        RoomBookingRequest $booking,
        ?int $expectedWorkflowVersion,
    ): void {
        if ($expectedWorkflowVersion === null) {
            return;
        }

        $current = max(1, (int) ($booking->workflow_version ?? 1));
        if ($current !== $expectedWorkflowVersion) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::STALE_WORKFLOW_VERSION,
                'Versi pengajuan sudah berubah. Muat ulang data sebelum melanjutkan.',
                [
                    'expected_workflow_version' => $expectedWorkflowVersion,
                    'current_workflow_version' => $current,
                ],
            );
        }
    }

    public function assertNoPendingCancellationRequest(RoomBookingRequest $booking): void
    {
        if ($booking->hasPendingCancellationRequest()) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::PENDING_CANCELLATION_REQUEST,
                'Permohonan pembatalan masih menunggu keputusan.',
            );
        }
    }

    private function performDirectWithdrawalLocked(
        RoomBookingRequest $booking,
        User $actor,
        string $reason,
        ?string $correlationId = null,
    ): RoomBookingRequest {
        $decision = $this->withdrawalPolicy->directWithdrawalDecision($actor, $booking);
        if (! $decision['allowed']) {
            $this->throwWithdrawalBlocked((string) $decision['block_reason']);
        }

        return $this->persistTransition(
            $booking,
            $actor,
            RoomBookingStatus::Cancelled,
            RoomBookingWorkflowEvent::EVENT_BOOKING_WITHDRAWN,
            [
                'cancellation_reason' => $reason,
                'cancellation_source' => 'requester_withdrawal',
                'cancelled_by_role_snapshot' => $this->actorRoleSnapshot($actor),
            ],
            $reason,
            safeMetadata: ['cancellation_source' => 'requester_withdrawal'],
            correlationId: $correlationId,
        );
    }

    private function throwWithdrawalBlocked(string $reason): never
    {
        $message = match ($reason) {
            RoomBookingDomainException::PENDING_CANCELLATION_REQUEST => 'Permohonan pembatalan masih menunggu keputusan.',
            RoomBookingDomainException::REVIEW_ALREADY_STARTED => 'Pengajuan sudah mulai ditinjau dan harus melalui permohonan pembatalan.',
            RoomBookingDomainException::WITHDRAWAL_CUTOFF_PASSED => 'Batas waktu penarikan langsung telah lewat. Ajukan permohonan pembatalan.',
            RoomBookingDomainException::REVISION_ALREADY_REQUESTED => 'Pengajuan yang pernah diminta revisi harus melalui permohonan pembatalan.',
            RoomBookingDomainException::REQUIRES_CANCELLATION_REVIEW => 'Peminjaman yang sudah disetujui harus melalui permohonan pembatalan.',
            RoomBookingDomainException::BOOKING_EXPIRED => 'Pengajuan yang sudah melewati waktu mulai tidak dapat ditarik.',
            RoomBookingDomainException::FINAL_BOOKING_STATE => 'Pengajuan sudah berada pada status akhir.',
            default => 'Pengajuan tidak dapat ditarik oleh akun ini.',
        };

        throw new RoomBookingDomainException($reason, $message);
    }

    /** @return array<string, mixed> */
    private function safeActionResult(string $message, RoomBookingRequest $booking): array
    {
        return [
            'message' => $message,
            'booking_id' => (int) $booking->id,
            'workflow_version' => (int) $booking->workflow_version,
            'stored_status' => $booking->status->value,
            'effective_status' => $booking->effectiveStatus(),
        ];
    }

    private function actorRoleSnapshot(User $actor): string
    {
        return $actor->role === 'tendik' && $actor->tendik_role
            ? $actor->tendik_role
            : (string) $actor->role;
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
