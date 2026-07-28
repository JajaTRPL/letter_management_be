<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\UserStatus;
use App\Models\Room;
use App\Models\RoomBookingOccurrence;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent as Ev;
use App\Models\User;
use App\Services\AcademicRoutingService;
use App\Support\LetterTypeRegistry;
use App\Support\LetterWorkflowStatus as LS;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The single centralized notification projector. It consumes committed domain
 * events (primarily the persisted RoomBookingWorkflowEvent ledger) and applies
 * the C7N1 event→recipient matrix, producing an ACTIONABLE projection — never
 * one notification per raw workflow hop.
 *
 * Every public entrypoint is failure-isolated via safely(): a notification
 * defect can never turn a successful workflow mutation into a false HTTP 500.
 * Idempotency comes from NotificationWriter's (recipient, dedup_key) uniqueness,
 * so re-processing the same committed event is a no-op.
 */
class NotificationProjector
{
    public function __construct(
        private NotificationWriter $writer,
        private NotificationRecipientResolver $recipients,
    ) {}

    /**
     * Run a projection closure, swallowing and logging any failure so the
     * caller's workflow transaction is never affected. Returns whether it
     * succeeded (the scheduler uses this to record dispatcher health).
     */
    public function safely(callable $projection): bool
    {
        try {
            $projection();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Notification projection failed', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ── Room booking / occurrence / return matrix ──────────────────────────

    /**
     * Project one committed room-booking workflow event. Called from the
     * RoomBookingWorkflowEvent observer, synchronously inside the mutation
     * transaction and wrapped in safely(): atomic with the mutation, yet a
     * projection failure never rolls it back.
     */
    public function projectRoomBookingEvent(Ev $event): void
    {
        $this->safely(function () use ($event): void {
            match ($event->event_type) {
                Ev::EVENT_BOOKING_SUBMITTED,
                Ev::EVENT_BOOKING_RESUBMITTED => $this->onBookingNeedsReview($event),
                Ev::EVENT_REVISION_REQUESTED => $this->onBookingRevisionRequested($event),
                Ev::EVENT_BOOKING_APPROVED => $this->onBookingApproved($event),
                Ev::EVENT_BOOKING_REJECTED => $this->onBookingRejected($event),
                Ev::EVENT_BOOKING_CANCELLED => $this->onBookingCancelled($event),
                Ev::EVENT_BOOKING_WITHDRAWN => $this->onBookingWithdrawn($event),
                Ev::EVENT_CANCELLATION_REQUESTED => $this->onCancellationRequested($event),
                Ev::EVENT_CANCELLATION_REQUEST_WITHDRAWN,
                Ev::EVENT_CANCELLATION_REJECTED => $this->onCancellationClosedWithoutCancel($event),
                Ev::EVENT_CANCELLATION_APPROVED => $this->onCancellationApproved($event),
                Ev::EVENT_RETURN_SUBMITTED,
                Ev::EVENT_RETURN_RESUBMITTED => $this->onReturnSubmitted($event),
                Ev::EVENT_RETURN_REVISION_REQUESTED => $this->onReturnRevisionRequested($event),
                Ev::EVENT_RETURN_ACCEPTED => $this->onReturnAccepted($event),
                Ev::EVENT_RETURN_REJECTED => $this->onReturnRejected($event),
                Ev::EVENT_RETURN_WITHDRAWN => $this->onReturnWithdrawn($event),
                Ev::EVENT_KEY_ISSUED => $this->onKeyIssued($event),
                // review_started, occurrence_created, usage_*, return_due/overdue
                // (reminder phases), key_received_time_adjusted, legacy import →
                // intentionally produce no notification here.
                default => null,
            };
        });
    }

    private function onBookingNeedsReview(Ev $event): void
    {
        $booking = $this->booking($event);
        $approver = $this->recipients->bookingApprover($booking);
        if (! $approver) {
            $this->healthMissingRecipient(
                'booking_approver',
                'booking',
                (string) $booking->id,
                'Tidak ada penanggung jawab aktif untuk meninjau pengajuan peminjaman.',
            );

            return;
        }

        $iteration = max(1, (int) ($event->submission_iteration ?? 1));
        $isResubmission = $event->event_type === Ev::EVENT_BOOKING_RESUBMITTED;

        // A resubmission resolves the applicant's revision action.
        if ($isResubmission) {
            $this->writer->resolveBySubject('booking', (string) $booking->id, [Ev::EVENT_REVISION_REQUESTED]);
        }

        $this->writer->write(new NotificationIntent(
            recipient: $approver,
            eventType: $event->event_type,
            category: NotificationCategory::ActionRequired,
            priority: NotificationPriority::High,
            title: $isResubmission ? 'Pengajuan peminjaman diajukan ulang' : 'Pengajuan peminjaman baru',
            body: $this->bookingLine($booking).' menunggu peninjauan Anda.',
            dedupKey: "booking-review:{$booking->id}:iter:{$iteration}",
            subjectType: 'booking',
            subjectPublicId: (string) $booking->id,
            actionRouteKey: $this->reviewRouteFor($booking),
            actionLabel: 'Tinjau Pengajuan',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onBookingRevisionRequested(Ev $event): void
    {
        $booking = $this->booking($event);
        $applicant = $this->recipients->bookingApplicant($booking);
        // Requesting revision retires the approver's review action.
        $this->writer->resolveBySubject('booking', (string) $booking->id, [
            Ev::EVENT_BOOKING_SUBMITTED, Ev::EVENT_BOOKING_RESUBMITTED,
            ...RoomBookingReviewSlaScanner::EVENT_TYPES,
        ]);
        if (! $applicant) {
            return;
        }

        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: $event->event_type,
            category: NotificationCategory::ActionRequired,
            priority: NotificationPriority::High,
            title: 'Pengajuan peminjaman perlu direvisi',
            body: $this->bookingLine($booking).' memerlukan perbaikan sebelum dapat dilanjutkan.',
            dedupKey: "booking-revision:{$booking->id}:iter:".max(1, (int) ($event->submission_iteration ?? 1)),
            subjectType: 'booking',
            subjectPublicId: (string) $booking->id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_DETAIL,
            actionLabel: 'Perbaiki Pengajuan',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onBookingApproved(Ev $event): void
    {
        $booking = $this->booking($event);
        $this->writer->resolveBySubject('booking', (string) $booking->id, [
            Ev::EVENT_BOOKING_SUBMITTED, Ev::EVENT_BOOKING_RESUBMITTED,
            ...RoomBookingReviewSlaScanner::EVENT_TYPES,
        ]);
        $applicant = $this->recipients->bookingApplicant($booking);
        if (! $applicant) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: $event->event_type,
            category: NotificationCategory::Update,
            priority: NotificationPriority::High,
            title: 'Peminjaman disetujui',
            body: $this->bookingLine($booking).' telah disetujui.',
            dedupKey: "booking-approved:{$booking->id}",
            subjectType: 'booking',
            subjectPublicId: (string) $booking->id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_DETAIL,
            actionLabel: 'Lihat Detail',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onBookingRejected(Ev $event): void
    {
        $booking = $this->booking($event);
        $this->writer->resolveBySubject('booking', (string) $booking->id, [
            Ev::EVENT_BOOKING_SUBMITTED, Ev::EVENT_BOOKING_RESUBMITTED,
            ...RoomBookingReviewSlaScanner::EVENT_TYPES,
        ]);
        $applicant = $this->recipients->bookingApplicant($booking);
        if (! $applicant) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: $event->event_type,
            category: NotificationCategory::Update,
            priority: NotificationPriority::High,
            title: 'Peminjaman ditolak',
            body: $this->bookingLine($booking).' tidak dapat disetujui.',
            dedupKey: "booking-rejected:{$booking->id}",
            subjectType: 'booking',
            subjectPublicId: (string) $booking->id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_DETAIL,
            actionLabel: 'Lihat Detail',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onBookingCancelled(Ev $event): void
    {
        $booking = $this->booking($event);
        // A cancelled booking retires EVERY pending action on it — the approver's
        // review, reminders, cancellation review, everything.
        $this->writer->resolveBySubject('booking', (string) $booking->id);
        $applicant = $this->recipients->bookingApplicant($booking);
        if (! $applicant) {
            return;
        }
        // Suppress the applicant's own direct withdrawal self-confirmation: only
        // notify when the cancellation was not the applicant's immediate action.
        if ((int) $event->actor_id === (int) $booking->requester_id) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: $event->event_type,
            category: NotificationCategory::Update,
            priority: NotificationPriority::Normal,
            title: 'Jadwal peminjaman dibatalkan',
            body: $this->bookingLine($booking).' telah dibatalkan.',
            dedupKey: "booking-cancelled:{$booking->id}",
            subjectType: 'booking',
            subjectPublicId: (string) $booking->id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_DETAIL,
            actionLabel: 'Lihat Detail',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onBookingWithdrawn(Ev $event): void
    {
        $booking = $this->booking($event);
        // The item the approver had is gone; retire their review action.
        $this->writer->resolveBySubject('booking', (string) $booking->id, [
            Ev::EVENT_BOOKING_SUBMITTED, Ev::EVENT_BOOKING_RESUBMITTED,
            ...RoomBookingReviewSlaScanner::EVENT_TYPES,
        ]);
        // The applicant withdrew it themselves and already saw confirmation.
    }

    private function onCancellationRequested(Ev $event): void
    {
        $booking = $this->booking($event);
        $owner = $this->recipients->bookingApprover($booking);
        $requestId = (string) ($event->safe_metadata['cancellation_request_id'] ?? 'x');
        if (! $owner) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $owner,
            eventType: $event->event_type,
            category: NotificationCategory::ActionRequired,
            priority: NotificationPriority::High,
            title: 'Permintaan pembatalan peminjaman',
            body: $this->bookingLine($booking).' memiliki permintaan pembatalan yang menunggu keputusan.',
            dedupKey: "booking-cancel-review:{$booking->id}:req:{$requestId}",
            subjectType: 'booking',
            subjectPublicId: (string) $booking->id,
            actionRouteKey: $this->reviewRouteFor($booking),
            actionLabel: 'Tinjau Pembatalan',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onCancellationClosedWithoutCancel(Ev $event): void
    {
        $booking = $this->booking($event);
        // Decision made (rejected) or request withdrawn → retire the owner's
        // cancellation-review action.
        $this->writer->resolveBySubject('booking', (string) $booking->id, [
            Ev::EVENT_CANCELLATION_REQUESTED,
        ]);
        if ($event->event_type !== Ev::EVENT_CANCELLATION_REJECTED) {
            return;
        }
        $applicant = $this->recipients->bookingApplicant($booking);
        if (! $applicant) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: $event->event_type,
            category: NotificationCategory::Update,
            priority: NotificationPriority::Normal,
            title: 'Permintaan pembatalan ditolak',
            body: 'Permintaan pembatalan untuk '.$this->bookingLine($booking).' tidak disetujui.',
            dedupKey: "booking-cancel-decision:{$booking->id}:rejected",
            subjectType: 'booking',
            subjectPublicId: (string) $booking->id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_DETAIL,
            actionLabel: 'Lihat Detail',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onCancellationApproved(Ev $event): void
    {
        $booking = $this->booking($event);
        // Booking is now cancelled — retire everything, then inform applicant.
        $this->writer->resolveBySubject('booking', (string) $booking->id, [
            Ev::EVENT_CANCELLATION_REQUESTED,
            Ev::EVENT_BOOKING_SUBMITTED, Ev::EVENT_BOOKING_RESUBMITTED,
        ]);
        $applicant = $this->recipients->bookingApplicant($booking);
        if (! $applicant) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: $event->event_type,
            category: NotificationCategory::Update,
            priority: NotificationPriority::Normal,
            title: 'Pembatalan peminjaman disetujui',
            body: 'Permintaan pembatalan untuk '.$this->bookingLine($booking).' telah disetujui.',
            dedupKey: "booking-cancel-decision:{$booking->id}:approved",
            subjectType: 'booking',
            subjectPublicId: (string) $booking->id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_DETAIL,
            actionLabel: 'Lihat Detail',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onReturnSubmitted(Ev $event): void
    {
        $occurrence = $this->occurrence($event);
        if (! $occurrence) {
            return;
        }
        $booking = $occurrence->booking;
        $owner = $this->recipients->operationalOwner($this->roomOf($booking));
        $ref = $this->returnRef($occurrence);

        // The applicant took the return action — retire their due/overdue/revision.
        $this->writer->resolveBySubject('occurrence', $occurrence->public_id, [
            'return_due_reminder', 'return_overdue_reminder', Ev::EVENT_RETURN_REVISION_REQUESTED,
        ]);
        if (! $owner) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $owner,
            eventType: Ev::EVENT_RETURN_SUBMITTED,
            category: NotificationCategory::ActionRequired,
            priority: NotificationPriority::Normal,
            title: 'Bukti pengembalian kunci masuk',
            body: $this->occurrenceLine($occurrence).' menunggu verifikasi pengembalian.',
            dedupKey: "return-verify:{$occurrence->public_id}:{$ref}",
            subjectType: 'occurrence',
            subjectPublicId: $occurrence->public_id,
            actionRouteKey: $this->operationsRouteFor($this->roomOf($booking)),
            actionLabel: 'Verifikasi Pengembalian',
            occurredAt: $event->occurred_at,
            supersedesDedupPrefixes: ["return-verify:{$occurrence->public_id}"],
        ));
    }

    private function onReturnRevisionRequested(Ev $event): void
    {
        $occurrence = $this->occurrence($event);
        if (! $occurrence) {
            return;
        }
        // Owner decided → retire their verification action.
        $this->writer->resolveBySubject('occurrence', $occurrence->public_id, [Ev::EVENT_RETURN_SUBMITTED]);
        $applicant = $this->recipients->bookingApplicant($occurrence->booking);
        if (! $applicant) {
            return;
        }
        $ref = $this->returnRef($occurrence);
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: $event->event_type,
            category: NotificationCategory::ActionRequired,
            priority: NotificationPriority::High,
            title: 'Bukti pengembalian perlu diperbaiki',
            body: $this->occurrenceLine($occurrence).' memerlukan perbaikan bukti pengembalian.',
            dedupKey: "return-revision:{$occurrence->public_id}:{$ref}",
            subjectType: 'occurrence',
            subjectPublicId: $occurrence->public_id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_OCCURRENCE,
            actionLabel: 'Perbaiki Bukti',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onReturnAccepted(Ev $event): void
    {
        $occurrence = $this->occurrence($event);
        if (! $occurrence) {
            return;
        }
        // Accepted return retires owner verification AND applicant due/overdue/revision.
        $this->writer->resolveBySubject('occurrence', $occurrence->public_id, [
            Ev::EVENT_RETURN_SUBMITTED, Ev::EVENT_RETURN_REVISION_REQUESTED,
            'return_due_reminder', 'return_overdue_reminder', 'escalation',
        ]);
        $applicant = $this->recipients->bookingApplicant($occurrence->booking);
        if (! $applicant) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: $event->event_type,
            category: NotificationCategory::Update,
            priority: NotificationPriority::Normal,
            title: 'Pengembalian kunci diterima',
            body: 'Pengembalian kunci untuk '.$this->occurrenceLine($occurrence).' telah diverifikasi.',
            dedupKey: "return-accepted:{$occurrence->public_id}",
            subjectType: 'occurrence',
            subjectPublicId: $occurrence->public_id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_OCCURRENCE,
            actionLabel: 'Lihat Detail',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onReturnRejected(Ev $event): void
    {
        $occurrence = $this->occurrence($event);
        if (! $occurrence) {
            return;
        }
        $this->writer->resolveBySubject('occurrence', $occurrence->public_id, [Ev::EVENT_RETURN_SUBMITTED]);
        $applicant = $this->recipients->bookingApplicant($occurrence->booking);
        if (! $applicant) {
            return;
        }
        $ref = $this->returnRef($occurrence);
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: $event->event_type,
            category: NotificationCategory::Update,
            priority: NotificationPriority::High,
            title: 'Bukti pengembalian ditolak',
            body: $this->occurrenceLine($occurrence).' — bukti pengembalian ditolak.',
            dedupKey: "return-rejected:{$occurrence->public_id}:{$ref}",
            subjectType: 'occurrence',
            subjectPublicId: $occurrence->public_id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_OCCURRENCE,
            actionLabel: 'Lihat Detail',
            occurredAt: $event->occurred_at,
        ));
    }

    private function onReturnWithdrawn(Ev $event): void
    {
        $occurrence = $this->occurrence($event);
        if (! $occurrence) {
            return;
        }
        // Applicant withdrew their own evidence — retire the owner's verification.
        $this->writer->resolveBySubject('occurrence', $occurrence->public_id, [Ev::EVENT_RETURN_SUBMITTED]);
    }

    private function onKeyIssued(Ev $event): void
    {
        $occurrence = $this->occurrence($event);
        if (! $occurrence) {
            return;
        }
        // Key handed over — the owner's handover reminder is done.
        $this->writer->resolveBySubject('occurrence', $occurrence->public_id, ['key_handover_reminder']);
    }

    // ── Administrative letter matrix ───────────────────────────────────────

    /** Event types for the letter families (stable, used for resolution). */
    public const LETTER_PERSURATAN_REVIEW = 'letter_persuratan_review';

    public const LETTER_PRODI_REVIEW = 'letter_prodi_review';

    public const LETTER_DEPARTMENT_REVIEW = 'letter_department_review';

    public const LETTER_REVISION_REQUESTED = 'letter_revision_requested';

    public const LETTER_REJECTED = 'letter_rejected';

    public const LETTER_READY_FOR_STUDENT = 'letter_ready_for_student';

    /**
     * Persuratan queue item for a (re)submitted letter, targeting the CONCRETE
     * assigned officer resolved by the domain's own assignment. Emitted from the
     * shared assignment seam (LetterAssignmentService::assignToEligibleTendik),
     * so it covers every letter type and both first submission and resubmission
     * with one call. A missing eligible officer is a SuperAdmin health anomaly.
     */
    public function projectLetterAssigned(Model $application, string $letterType, ?User $assignee): void
    {
        $this->safely(function () use ($application, $letterType, $assignee): void {
            $id = (string) $application->getKey();
            if (! $assignee) {
                $this->healthMissingRecipient(
                    'letter_persuratan',
                    $letterType,
                    $id,
                    'Tidak ada Tendik Persuratan aktif yang dapat menangani pengajuan surat.',
                );

                return;
            }

            $this->writer->write(new NotificationIntent(
                recipient: $assignee,
                eventType: self::LETTER_PERSURATAN_REVIEW,
                category: NotificationCategory::ActionRequired,
                priority: NotificationPriority::High,
                title: 'Perlu tindakan: tinjau pengajuan surat',
                body: LetterTypeRegistry::labelFor($letterType).' menunggu verifikasi Anda.',
                dedupKey: "letter-review:persuratan:{$letterType}:{$id}:{$this->letterEpoch($application)}",
                subjectType: $letterType,
                subjectPublicId: $id,
                actionRouteKey: NotificationActionRoute::PERSURATAN_LETTER_QUEUE,
                actionLabel: 'Tinjau Pengajuan',
            ));
        });
    }

    /**
     * Project a persisted letter status transition. Called from the shared
     * letter-application observer, synchronously inside the controller's
     * mutation transaction (atomic) and failure-isolated via safely(). Applies
     * the applicant + academic matrix and resolves the action each transition
     * satisfies. Internal approval hops never notify the applicant.
     */
    public function projectLetterTransition(Model $application, string $letterType, ?string $from, string $to): void
    {
        $this->safely(function () use ($application, $letterType, $from, $to): void {
            $id = (string) $application->getKey();

            // Any transition ends the stage the letter was waiting in, so its
            // open review-SLA obligations (warning/overdue/escalation) resolve;
            // the scanner re-emits the NEW stage's clock fresh on its next run.
            $this->writer->resolveBySubject($letterType, $id, WorkflowReviewSlaPolicyService::EVENT_TYPES);

            match ($to) {
                LS::APPROVED_TENDIK => $this->onLetterProdiStage($application, $letterType, $id),
                LS::APPROVED_KAPRODI => $this->onLetterDepartmentStage($application, $letterType, $id),
                LS::READY_FOR_STUDENT_REVIEW => $this->onLetterReady($application, $letterType, $id),
                LS::REVISION => $this->onLetterRevision($application, $letterType, $id, $from),
                LS::REJECTED => $this->onLetterRejected($application, $letterType, $id, $from),
                // Resubmission after a revision retires the applicant's revision action.
                LS::SUBMITTED => $from === LS::REVISION
                    ? $this->writer->resolveBySubject($letterType, $id, [self::LETTER_REVISION_REQUESTED])
                    : null,
                // The student issued/collected the ready document — their own action.
                LS::COMPLETED => $this->writer->resolveBySubject($letterType, $id, [self::LETTER_READY_FOR_STUDENT]),
                default => null,
            };
        });
    }

    private function onLetterProdiStage(Model $application, string $letterType, string $id): void
    {
        // Advancing out of the Persuratan stage retires that review action.
        $this->writer->resolveBySubject($letterType, $id, [self::LETTER_PERSURATAN_REVIEW]);

        $routing = app(AcademicRoutingService::class);
        $studyProgramId = $routing->studentStudyProgramId($application);
        $recipients = $this->recipients->academicApprovers(['kaprodi', 'sekprodi'], $studyProgramId, null);
        if ($recipients->isEmpty()) {
            $this->healthMissingRecipient('letter_prodi_approver', $letterType, $id,
                'Tidak ada Kaprodi/Sekprodi aktif untuk prodi pemohon surat.');

            return;
        }
        foreach ($recipients as $approver) {
            $this->writer->write($this->letterReviewIntent(
                $approver, self::LETTER_PRODI_REVIEW, $letterType, $id, $application,
                'menunggu persetujuan Prodi.', "letter-review:prodi:{$letterType}:{$id}:{$this->letterEpoch($application)}",
            ));
        }
    }

    private function onLetterDepartmentStage(Model $application, string $letterType, string $id): void
    {
        $this->writer->resolveBySubject($letterType, $id, [self::LETTER_PRODI_REVIEW]);

        $routing = app(AcademicRoutingService::class);
        $departmentId = $routing->studentDepartmentId($application);
        $recipients = $this->recipients->academicApprovers(['kadep', 'sekdep'], null, $departmentId);
        if ($recipients->isEmpty()) {
            $this->healthMissingRecipient('letter_department_approver', $letterType, $id,
                'Tidak ada Kadep/Sekdep aktif untuk departemen pemohon surat.');

            return;
        }
        foreach ($recipients as $approver) {
            $this->writer->write($this->letterReviewIntent(
                $approver, self::LETTER_DEPARTMENT_REVIEW, $letterType, $id, $application,
                'menunggu persetujuan Departemen.', "letter-review:dept:{$letterType}:{$id}:{$this->letterEpoch($application)}",
            ));
        }
    }

    private function onLetterReady(Model $application, string $letterType, string $id): void
    {
        // The department decision retires the department review action.
        $this->writer->resolveBySubject($letterType, $id, [self::LETTER_DEPARTMENT_REVIEW]);
        $applicant = $this->letterApplicant($application);
        if (! $applicant) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: self::LETTER_READY_FOR_STUDENT,
            category: NotificationCategory::Update,
            priority: NotificationPriority::High,
            title: 'Dokumen surat siap',
            body: LetterTypeRegistry::labelFor($letterType).' telah selesai dan siap ditindaklanjuti.',
            dedupKey: "letter-ready:{$letterType}:{$id}:{$this->letterEpoch($application)}",
            subjectType: $letterType,
            subjectPublicId: $id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_LETTER_DETAIL,
            actionLabel: 'Lihat Dokumen',
        ));
    }

    private function onLetterRevision(Model $application, string $letterType, string $id, ?string $from): void
    {
        $this->resolveLetterStageReviewer($letterType, $id, $from);
        $applicant = $this->letterApplicant($application);
        if (! $applicant) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: self::LETTER_REVISION_REQUESTED,
            category: NotificationCategory::ActionRequired,
            priority: NotificationPriority::High,
            title: 'Pengajuan surat perlu diperbaiki',
            body: LetterTypeRegistry::labelFor($letterType).' memerlukan perbaikan sebelum dapat dilanjutkan.',
            dedupKey: "letter-revision:{$letterType}:{$id}:{$this->letterEpoch($application)}",
            subjectType: $letterType,
            subjectPublicId: $id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_LETTER_DETAIL,
            actionLabel: 'Perbaiki Pengajuan',
        ));
    }

    private function onLetterRejected(Model $application, string $letterType, string $id, ?string $from): void
    {
        $this->resolveLetterStageReviewer($letterType, $id, $from);
        $applicant = $this->letterApplicant($application);
        if (! $applicant) {
            return;
        }
        $this->writer->write(new NotificationIntent(
            recipient: $applicant,
            eventType: self::LETTER_REJECTED,
            category: NotificationCategory::Update,
            priority: NotificationPriority::High,
            title: 'Pengajuan surat ditolak',
            body: LetterTypeRegistry::labelFor($letterType).' tidak dapat disetujui.',
            dedupKey: "letter-rejected:{$letterType}:{$id}:{$this->letterEpoch($application)}",
            subjectType: $letterType,
            subjectPublicId: $id,
            actionRouteKey: NotificationActionRoute::MAHASISWA_LETTER_DETAIL,
            actionLabel: 'Lihat Detail',
        ));
    }

    /** A revision/rejection retires whichever stage reviewer had the item. */
    private function resolveLetterStageReviewer(string $letterType, string $id, ?string $from): void
    {
        $event = match ($from) {
            LS::SUBMITTED => self::LETTER_PERSURATAN_REVIEW,
            LS::APPROVED_TENDIK => self::LETTER_PRODI_REVIEW,
            LS::APPROVED_KAPRODI => self::LETTER_DEPARTMENT_REVIEW,
            default => null,
        };
        if ($event !== null) {
            $this->writer->resolveBySubject($letterType, $id, [$event]);
        }
    }

    private function letterReviewIntent(
        User $recipient,
        string $eventType,
        string $letterType,
        string $id,
        Model $application,
        string $stageText,
        string $dedupKey,
    ): NotificationIntent {
        return new NotificationIntent(
            recipient: $recipient,
            eventType: $eventType,
            category: NotificationCategory::ActionRequired,
            priority: NotificationPriority::High,
            title: 'Perlu tindakan: tinjau pengajuan surat',
            body: LetterTypeRegistry::labelFor($letterType).' '.$stageText,
            dedupKey: $dedupKey,
            subjectType: $letterType,
            subjectPublicId: $id,
            actionRouteKey: NotificationActionRoute::AKADEMIK_LETTER_QUEUE,
            actionLabel: 'Tinjau Pengajuan',
        );
    }

    private function letterApplicant(Model $application): ?User
    {
        $applicant = $application->relationLoaded('user')
            ? $application->getRelationValue('user')
            : (method_exists($application, 'user') ? $application->user()->first() : null);

        return $applicant instanceof User && $applicant->status === UserStatus::Active
            ? $applicant
            : null;
    }

    /** Stable per-submission-cycle identity — changes only on (re)submission. */
    private function letterEpoch(Model $application): string
    {
        $submittedAt = $application->getAttribute('submitted_at');
        $updatedAt = $application->getAttribute('updated_at');

        return (string) ($submittedAt?->timestamp ?? $updatedAt?->timestamp ?? 0);
    }

    // ── SuperAdmin health ──────────────────────────────────────────────────

    /**
     * Emit a system-health anomaly to every active SuperAdmin. Deduplicated per
     * (anomaly key) so a recurring condition does not storm the inbox.
     */
    public function healthAlert(
        string $anomalyKey,
        string $title,
        string $body,
        NotificationPriority $priority = NotificationPriority::High,
    ): void {
        $this->safely(function () use ($anomalyKey, $title, $body, $priority): void {
            foreach ($this->recipients->superAdmins() as $admin) {
                $this->writer->write(new NotificationIntent(
                    recipient: $admin,
                    eventType: 'system_health',
                    category: NotificationCategory::System,
                    priority: $priority,
                    title: $title,
                    body: $body,
                    dedupKey: "health:{$anomalyKey}",
                    subjectType: 'system',
                    subjectPublicId: $anomalyKey,
                    actionRouteKey: NotificationActionRoute::SUPERADMIN_HEALTH,
                    actionLabel: 'Tinjau Kesehatan Sistem',
                ));
            }
        });
    }

    private function healthMissingRecipient(string $kind, string $subjectType, string $subjectId, string $body): void
    {
        $this->healthAlert(
            "missing-recipient:{$kind}:{$subjectType}:{$subjectId}",
            'Penerima alur kerja tidak tersedia',
            $body,
            NotificationPriority::Urgent,
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function booking(Ev $event): RoomBookingRequest
    {
        return $event->relationLoaded('booking') && $event->booking
            ? $event->booking->loadMissing('room')
            : RoomBookingRequest::with('room')->findOrFail($event->room_booking_request_id);
    }

    private function occurrence(Ev $event): ?RoomBookingOccurrence
    {
        if (! $event->room_booking_occurrence_id) {
            return null;
        }

        return RoomBookingOccurrence::with(['booking.room.owningLaboratory', 'booking.requester', 'activeReturnRequest'])
            ->find($event->room_booking_occurrence_id);
    }

    /**
     * A stable-but-per-attempt reference for return notifications. Each return
     * attempt is a distinct RoomBookingReturnRequest with its own public_id, so
     * replaying the same event dedups while a genuine resubmission (a new
     * request) mints a new key that supersedes the old verification item. Falls
     * back to the occurrence version when no active request is loaded.
     */
    private function returnRef(RoomBookingOccurrence $occurrence): string
    {
        return $occurrence->activeReturnRequest?->public_id
            ?? 'v'.(int) $occurrence->version;
    }

    private function roomOf(RoomBookingRequest $booking): Room
    {
        return $booking->relationLoaded('room') && $booking->room
            ? $booking->room
            : $booking->room()->firstOrFail();
    }

    private function reviewRouteFor(RoomBookingRequest $booking): string
    {
        $room = $this->roomOf($booking);

        return $room->type->value === 'classroom'
            ? NotificationActionRoute::SARPRAS_BOOKING_REVIEW
            : NotificationActionRoute::KALAB_BOOKING_REVIEW;
    }

    private function operationsRouteFor(Room $room): string
    {
        return $room->type->value === 'classroom'
            ? NotificationActionRoute::SARPRAS_OPERATIONS
            : NotificationActionRoute::LABORAN_OPERATIONS;
    }

    private function bookingLine(RoomBookingRequest $booking): string
    {
        $room = $this->roomOf($booking);

        return trim("{$room->code} · {$booking->activity_name}");
    }

    private function occurrenceLine(RoomBookingOccurrence $occurrence): string
    {
        $room = $this->roomOf($occurrence->booking);

        return trim("{$room->code} · {$occurrence->occurrence_date->toDateString()}");
    }
}
