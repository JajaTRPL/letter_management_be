<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use App\Support\Workflow\RoomBookingReviewStageClock;
use Illuminate\Support\Carbon;

/**
 * Review-SLA scanner (C10). Emits governance notifications for bookings that
 * have been WAITING FOR REVIEW too long — a submission-stage obligation the
 * operational reminder scanner (which only walks approved occurrences) never
 * covered.
 *
 * Ships inert: if the SuperAdmin policy for this scope is disabled it emits
 * nothing, so installing C10 changes no behavior until a SuperAdmin turns it on.
 *
 * Phases are keyed on ELAPSED-since-submission ranges, so they are naturally
 * catch-up safe: after scanner/scheduler downtime a booking that jumped past a
 * threshold gets the CURRENT phase, never a backlog of stale earlier ones. Every
 * phase has a stable per-booking, per-submission-iteration dedup key, so
 * repeated runs never duplicate. Only `submitted` bookings are scanned; once a
 * booking is approved / rejected / revised / withdrawn / cancelled it leaves the
 * query AND the projector resolves any open review-SLA notifications — so the
 * obligation stops the moment it is no longer real.
 */
class RoomBookingReviewSlaScanner
{
    // Canonical review-SLA event types live on the governance service; aliased
    // here so existing references (and the projector resolution) stay stable.
    public const EVENT_WARNING = WorkflowReviewSlaPolicyService::EVENT_WARNING;

    public const EVENT_OVERDUE = WorkflowReviewSlaPolicyService::EVENT_OVERDUE;

    public const EVENT_ESCALATION = WorkflowReviewSlaPolicyService::EVENT_ESCALATION;

    /** @var list<string> */
    public const EVENT_TYPES = WorkflowReviewSlaPolicyService::EVENT_TYPES;

    public function __construct(
        private NotificationWriter $writer,
        private NotificationRecipientResolver $recipients,
        private WorkflowReviewSlaPolicyService $policies,
    ) {}

    /** @return array{emitted:int,scanned:int,enabled:bool} */
    public function scan(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now(config('app.timezone')))->copy();
        $policy = $this->policies->current(WorkflowReviewSlaPolicyService::SCOPE_ROOM_BOOKING);

        if (! $policy['enabled']) {
            return ['emitted' => 0, 'scanned' => 0, 'enabled' => false];
        }

        $bookings = RoomBookingRequest::query()
            ->with('room.owningLaboratory')
            ->where('status', RoomBookingStatus::Submitted->value)
            ->get();

        $waitingSince = $this->waitingSinceByBooking($bookings->pluck('id')->all());
        $emitted = 0;

        foreach ($bookings as $booking) {
            $since = $waitingSince[$booking->id] ?? $booking->created_at;
            if (! $since) {
                continue;
            }
            $emitted += $this->project($booking, $since->copy(), $now, $policy);
        }

        return ['emitted' => $emitted, 'scanned' => $bookings->count(), 'enabled' => true];
    }

    /**
     * @param  array{enabled:bool,warning_minutes:int,overdue_minutes:int,escalation_minutes:int}  $policy
     */
    private function project(RoomBookingRequest $booking, Carbon $since, Carbon $now, array $policy): int
    {
        // Unambiguous signed elapsed minutes (avoids Carbon major-version diff
        // sign differences); negative when a clock skew puts submission ahead.
        $elapsed = intdiv($now->getTimestamp() - $since->getTimestamp(), 60);
        if ($elapsed < $policy['warning_minutes']) {
            return 0; // still comfortably inside the review window
        }

        $approver = $this->recipients->bookingApprover($booking);
        $iteration = max(1, (int) ($booking->submission_iteration ?? 1));
        $line = $this->bookingLine($booking);
        $emitted = 0;

        if ($elapsed >= $policy['escalation_minutes']) {
            // Reviewer still owns the action (a persistent overdue), AND the
            // breach is escalated to SuperAdmin governance.
            $emitted += $this->emitOverdue($booking, $approver, $iteration, $line, $since);
            $emitted += $this->emitEscalation($booking, $iteration, $line, $now);
        } elseif ($elapsed >= $policy['overdue_minutes']) {
            $emitted += $this->emitOverdue($booking, $approver, $iteration, $line, $since);
        } else {
            $emitted += $this->emitWarning($booking, $approver, $iteration, $line, $since);
        }

        return $emitted;
    }

    private function emitWarning(RoomBookingRequest $booking, ?User $approver, int $iteration, string $line, Carbon $since): int
    {
        if (! $approver) {
            return 0;
        }

        return $this->write(
            $approver,
            self::EVENT_WARNING,
            NotificationCategory::Reminder,
            NotificationPriority::Normal,
            'Pengajuan menunggu pemeriksaan',
            $line.' sudah menunggu pemeriksaan dan mendekati batas waktu.',
            "review-sla-warning:{$booking->id}:iter:{$iteration}",
            $booking,
            $this->reviewRoute($booking),
            'Tinjau Pengajuan',
            $since,
        );
    }

    private function emitOverdue(RoomBookingRequest $booking, ?User $approver, int $iteration, string $line, Carbon $since): int
    {
        if (! $approver) {
            return 0;
        }

        // The hard overdue supersedes the soft warning for the same reviewer so
        // they see one live obligation, not two.
        return $this->write(
            $approver,
            self::EVENT_OVERDUE,
            NotificationCategory::ActionRequired,
            NotificationPriority::High,
            'Pemeriksaan pengajuan terlambat',
            $line.' telah melewati batas waktu pemeriksaan dan menunggu keputusan Anda.',
            "review-sla-overdue:{$booking->id}:iter:{$iteration}",
            $booking,
            $this->reviewRoute($booking),
            'Tinjau Sekarang',
            $since,
            supersedes: ["review-sla-warning:{$booking->id}:"],
        );
    }

    private function emitEscalation(RoomBookingRequest $booking, int $iteration, string $line, Carbon $now): int
    {
        $emitted = 0;
        foreach ($this->recipients->superAdmins() as $admin) {
            $emitted += $this->write(
                $admin,
                self::EVENT_ESCALATION,
                NotificationCategory::System,
                NotificationPriority::High,
                'Perlu perhatian: pengajuan belum diperiksa',
                $line.' sudah lama menunggu dan belum diperiksa oleh pemeriksa.',
                "review-sla-escalation:{$booking->id}:iter:{$iteration}:admin:{$admin->id}",
                $booking,
                NotificationActionRoute::SUPERADMIN_HEALTH,
                'Tinjau Kesehatan Sistem',
                $now,
            );
        }

        return $emitted;
    }

    /** @param list<string> $supersedes */
    private function write(
        User $recipient,
        string $eventType,
        NotificationCategory $category,
        NotificationPriority $priority,
        string $title,
        string $body,
        string $dedupKey,
        RoomBookingRequest $booking,
        string $route,
        string $label,
        Carbon $occurredAt,
        array $supersedes = [],
    ): int {
        $notification = $this->writer->write(new NotificationIntent(
            recipient: $recipient,
            eventType: $eventType,
            category: $category,
            priority: $priority,
            title: $title,
            body: $body,
            dedupKey: $dedupKey,
            subjectType: 'booking',
            subjectPublicId: (string) $booking->id,
            actionRouteKey: $route,
            actionLabel: $label,
            occurredAt: $occurredAt,
            supersedesDedupPrefixes: $supersedes,
        ));

        return $notification->wasRecentlyCreated ? 1 : 0;
    }

    /**
     * Entry-into-current-review timestamp = the latest submit/resubmit event, so
     * a resubmission correctly restarts the review clock. Falls back to
     * created_at only if the ledger has no submit event (legacy rows).
     *
     * @param  list<int>  $bookingIds
     * @return array<int, Carbon>
     */
    private function waitingSinceByBooking(array $bookingIds): array
    {
        return RoomBookingReviewStageClock::currentWaitingSince($bookingIds);
    }

    private function reviewRoute(RoomBookingRequest $booking): string
    {
        return $booking->room?->type === RoomType::Classroom
            ? NotificationActionRoute::SARPRAS_BOOKING_REVIEW
            : NotificationActionRoute::KALAB_BOOKING_REVIEW;
    }

    private function bookingLine(RoomBookingRequest $booking): string
    {
        $code = $booking->room?->code ?? 'Ruang';
        $start = $booking->start_at?->copy()->setTimezone(config('app.timezone'))->translatedFormat('d M Y');

        return trim("{$code} · {$start}");
    }
}
