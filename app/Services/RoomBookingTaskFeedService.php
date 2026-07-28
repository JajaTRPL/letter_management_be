<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingOccurrence;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The room-booking counterpart to LetterTaskFeedService — what a Sarpras,
 * Kepala Lab or Laboran actually has to DO, versus what they can merely SEE.
 *
 * Every scope and every capability here is DELEGATED to the services that
 * already own those rules. Nothing is re-derived:
 *   - who may approve  → RoomBookingReviewerResolver
 *   - who may hand over a key / verify a return → RoomBookingOccurrenceService
 *   - what is even visible → the same scopes the list endpoints use
 *
 * That delegation is the whole point. The dashboard must never offer an action
 * the backend would reject, and the three roles differ in a way that is easy to
 * get wrong from memory:
 *
 *   Sarpras     approves classrooms   AND does their keys/returns
 *   Kepala Lab  approves its own lab  but does NO keys/returns at all
 *   Laboran     approves nothing      but does its lab's keys/returns
 *
 * So a Kepala Lab's operational rows come back as AWARENESS (can_act false,
 * responsible party named), never as a queue of buttons they cannot press.
 */
final class RoomBookingTaskFeedService
{
    /** Operational states that are genuinely someone's move right now. */
    private const ACTIONABLE_OPERATIONAL = ['scheduled', 'awaiting_verification', 'overdue'];

    public const KIND_APPROVAL = 'approval';

    public const KIND_KEY_HANDOVER = 'key_handover';

    public const KIND_RETURN_VERIFICATION = 'return_verification';

    private const KIND_LABELS = [
        self::KIND_APPROVAL => 'Persetujuan Pengajuan',
        self::KIND_KEY_HANDOVER => 'Serah Terima Kunci',
        self::KIND_RETURN_VERIFICATION => 'Verifikasi Pengembalian',
    ];

    private const ACTION_LABELS = [
        self::KIND_APPROVAL => 'Tinjau Pengajuan',
        self::KIND_KEY_HANDOVER => 'Serahkan Kunci',
        self::KIND_RETURN_VERIFICATION => 'Verifikasi Pengembalian',
    ];

    public function __construct(
        private RoomBookingReviewerResolver $reviewers,
        private RoomBookingOccurrenceService $occurrences,
        private RoomBookingOccurrenceAuthorizationService $occurrenceAuth,
    ) {}

    /**
     * Everything one dashboard needs, in one pass. Counts are derived from the
     * same scoped lists that are returned, so a stat card can never disagree
     * with the table underneath it.
     */
    public function dashboardFor(User $user, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now(config('app.timezone')))->copy();

        $approvals = $this->approvalRows($user, $now);
        [$operationalActionable, $awareness] = $this->operationalRows($user, $now);

        $actionable = $approvals->concat($operationalActionable)
            ->sortBy(fn (array $row) => $row['sort_at'])
            ->values();

        return [
            'actionable' => $actionable->map(fn (array $row) => $this->strip($row))->all(),
            'awareness' => $awareness->map(fn (array $row) => $this->strip($row))->all(),
            'today' => $this->todayRows($user, $now)->all(),
            'history' => $this->historyRows($user)->all(),
            'stats' => [
                'actionable' => $actionable->count(),
                'overdue' => $actionable->where('is_overdue', true)->count(),
                'finished_this_month' => $this->finishedThisMonth($user, $now),
            ],
        ];
    }

    /**
     * Bookings waiting for a decision this user can actually make.
     *
     * `scopeReviewableBookings` admits Laboran too (they may READ the queue), so
     * each row is then filtered through `canActAsApprover` — which is what keeps
     * a Laboran from being handed approvals they cannot grant.
     *
     * @return Collection<int, array<string,mixed>>
     */
    private function approvalRows(User $user, Carbon $now): Collection
    {
        $query = RoomBookingRequest::query()
            ->with(['room.owningLaboratory:id,code,name', 'requester:id,name'])
            ->where('status', RoomBookingStatus::Submitted->value);
        $this->reviewers->scopeReviewableBookings($query, $user);

        return $query->orderBy('created_at')->limit(100)->get()
            ->filter(fn (RoomBookingRequest $booking) => $booking->room
                && $this->reviewers->canActAsApprover($user, $booking))
            ->map(fn (RoomBookingRequest $booking) => [
                'kind' => self::KIND_APPROVAL,
                'kind_label' => self::KIND_LABELS[self::KIND_APPROVAL],
                'booking_id' => (int) $booking->id,
                'occurrence_ref' => null,
                'title' => (string) $booking->activity_name,
                'requester_name' => $booking->requester?->name,
                'room_label' => $this->roomLabel($booking),
                'schedule_label' => $this->scheduleLabel($booking->start_at, $booking->end_at),
                'status_label' => 'Diajukan',
                'status_tone' => 'info',
                'waiting_label' => $this->waitingLabel($booking->created_at, $now),
                'is_overdue' => $this->isOverdue($booking->created_at, $now),
                'can_act' => true,
                'action_label' => self::ACTION_LABELS[self::KIND_APPROVAL],
                'responsible_label' => null,
                'sort_at' => $booking->created_at?->getTimestamp() ?? 0,
            ])
            ->values();
    }

    /**
     * Key handovers and return verifications, split by whether THIS user may act.
     *
     * @return array{0: Collection<int, array<string,mixed>>, 1: Collection<int, array<string,mixed>>}
     */
    private function operationalRows(User $user, Carbon $now): array
    {
        $query = RoomBookingOccurrence::query()
            ->with(['booking.room.owningLaboratory:id,code,name', 'booking.requester:id,name', 'activeReturnRequest', 'acceptedReturnRequest'])
            ->operationallyActionable();
        $this->occurrenceAuth->scopeOperational($query, $user);

        $actionable = collect();
        $awareness = collect();

        foreach ($query->orderBy('start_at')->limit(200)->get() as $occurrence) {
            $status = $this->occurrences->operationalStatus($occurrence);
            if (! in_array($status, self::ACTIONABLE_OPERATIONAL, true)) {
                continue;
            }

            $canIssueKey = $this->occurrences->canIssueKey($user, $occurrence);
            $canVerify = $this->occurrences->canVerifyReturn($user, $occurrence);
            $kind = $canVerify || $status === 'awaiting_verification'
                ? self::KIND_RETURN_VERIFICATION
                : self::KIND_KEY_HANDOVER;
            $canAct = $canIssueKey || $canVerify;

            $row = [
                'kind' => $kind,
                'kind_label' => self::KIND_LABELS[$kind],
                'booking_id' => (int) $occurrence->booking->id,
                'occurrence_ref' => (string) $occurrence->occurrence_ref,
                'title' => (string) $occurrence->booking->activity_name,
                'requester_name' => $occurrence->booking->requester?->name,
                'room_label' => $this->roomLabel($occurrence->booking),
                'schedule_label' => $this->scheduleLabel($occurrence->start_at, $occurrence->end_at),
                'status_label' => $this->operationalLabel($status),
                'status_tone' => $status === 'overdue' ? 'danger' : ($status === 'awaiting_verification' ? 'warning' : 'neutral'),
                'waiting_label' => $this->waitingLabel($occurrence->start_at, $now),
                'is_overdue' => $status === 'overdue',
                'can_act' => $canAct,
                'action_label' => $canAct ? self::ACTION_LABELS[$kind] : null,
                // Naming the responsible party is what stops a Kepala Lab from
                // hunting for a button that will never exist for them.
                'responsible_label' => $canAct ? null : $this->occurrences->responsibleLabelFor($occurrence),
                'sort_at' => $occurrence->start_at?->getTimestamp() ?? 0,
            ];

            $canAct ? $actionable->push($row) : $awareness->push($row);
        }

        return [$actionable->values(), $awareness->sortBy('sort_at')->values()];
    }

    /** @return Collection<int, array<string,mixed>> */
    private function todayRows(User $user, Carbon $now): Collection
    {
        $query = RoomBookingOccurrence::query()
            ->with(['booking.room.owningLaboratory:id,code,name', 'booking.requester:id,name', 'activeReturnRequest', 'acceptedReturnRequest'])
            ->operationallyActionable()
            ->whereDate('occurrence_date', $now->toDateString());
        $this->occurrenceAuth->scopeOperational($query, $user);

        return $query->orderBy('start_at')->limit(50)->get()->map(fn (RoomBookingOccurrence $occurrence) => [
            'room_label' => $this->roomLabel($occurrence->booking),
            'title' => (string) $occurrence->booking->activity_name,
            'requester_name' => $occurrence->booking->requester?->name,
            'time_label' => $this->timeRangeLabel($occurrence->start_at, $occurrence->end_at),
            'status_label' => $this->operationalLabel($this->occurrences->operationalStatus($occurrence)),
        ])->values();
    }

    /**
     * What this user has actually done, from the append-only ledger. Reading
     * `actor_id` means history is "my decisions", not "things that happened near
     * me" — and the ledger already carries the role snapshot for audit.
     *
     * @return Collection<int, array<string,mixed>>
     */
    private function historyRows(User $user): Collection
    {
        return RoomBookingWorkflowEvent::query()
            ->with(['booking.room.owningLaboratory:id,code,name'])
            ->where('actor_id', $user->id)
            ->whereIn('event_type', $this->historyEventTypes())
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get()
            ->map(fn (RoomBookingWorkflowEvent $event) => [
                'acted_at_label' => $event->occurred_at?->copy()
                    ->setTimezone(config('app.timezone'))->translatedFormat('d M Y, H.i') ?? '-',
                'action_label' => $this->eventLabel((string) $event->event_type),
                'status_tone' => $this->eventTone((string) $event->event_type),
                'title' => (string) ($event->booking?->activity_name ?? '-'),
                'room_label' => $event->booking ? $this->roomLabel($event->booking) : '-',
            ])
            ->values();
    }

    private function finishedThisMonth(User $user, Carbon $now): int
    {
        return RoomBookingWorkflowEvent::query()
            ->where('actor_id', $user->id)
            ->whereIn('event_type', $this->historyEventTypes())
            ->whereBetween('occurred_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->count();
    }

    /** @return list<string> */
    private function historyEventTypes(): array
    {
        return [
            RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED,
            RoomBookingWorkflowEvent::EVENT_BOOKING_REJECTED,
            RoomBookingWorkflowEvent::EVENT_REVISION_REQUESTED,
            RoomBookingWorkflowEvent::EVENT_KEY_ISSUED,
            RoomBookingWorkflowEvent::EVENT_RETURN_ACCEPTED,
            RoomBookingWorkflowEvent::EVENT_RETURN_REJECTED,
            RoomBookingWorkflowEvent::EVENT_RETURN_REVISION_REQUESTED,
        ];
    }

    private function eventLabel(string $eventType): string
    {
        return match ($eventType) {
            RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED => 'Disetujui',
            RoomBookingWorkflowEvent::EVENT_BOOKING_REJECTED => 'Ditolak',
            RoomBookingWorkflowEvent::EVENT_REVISION_REQUESTED => 'Diminta revisi',
            RoomBookingWorkflowEvent::EVENT_KEY_ISSUED => 'Kunci diserahkan',
            RoomBookingWorkflowEvent::EVENT_RETURN_ACCEPTED => 'Pengembalian diterima',
            RoomBookingWorkflowEvent::EVENT_RETURN_REJECTED => 'Pengembalian ditolak',
            RoomBookingWorkflowEvent::EVENT_RETURN_REVISION_REQUESTED => 'Bukti diminta diperbaiki',
            default => 'Tindakan',
        };
    }

    private function eventTone(string $eventType): string
    {
        return match ($eventType) {
            RoomBookingWorkflowEvent::EVENT_BOOKING_APPROVED,
            RoomBookingWorkflowEvent::EVENT_RETURN_ACCEPTED,
            RoomBookingWorkflowEvent::EVENT_KEY_ISSUED => 'success',
            RoomBookingWorkflowEvent::EVENT_BOOKING_REJECTED,
            RoomBookingWorkflowEvent::EVENT_RETURN_REJECTED => 'danger',
            default => 'warning',
        };
    }

    private function operationalLabel(string $status): string
    {
        return match ($status) {
            'scheduled' => 'Menunggu serah kunci',
            'key_issued' => 'Kunci telah diserahkan',
            'in_use' => 'Sedang digunakan',
            'return_due' => 'Menunggu pengembalian',
            'awaiting_verification' => 'Menunggu verifikasi',
            'revision_required' => 'Menunggu perbaikan bukti',
            'returned_on_time' => 'Selesai tepat waktu',
            'returned_late' => 'Selesai terlambat',
            'overdue' => 'Melewati batas pengembalian',
            default => 'Belum berlaku',
        };
    }

    private function roomLabel(RoomBookingRequest $booking): string
    {
        $room = $booking->room;

        return $room ? trim(($room->code ? $room->code.' · ' : '').$room->name) : '-';
    }

    private function scheduleLabel(?Carbon $start, ?Carbon $end): string
    {
        if (! $start) {
            return '-';
        }
        $day = $start->copy()->setTimezone(config('app.timezone'))->translatedFormat('d M Y');

        return trim($day.' · '.$this->timeRangeLabel($start, $end));
    }

    private function timeRangeLabel(?Carbon $start, ?Carbon $end): string
    {
        if (! $start) {
            return '-';
        }
        $tz = config('app.timezone');
        $from = $start->copy()->setTimezone($tz)->format('H.i');
        $to = $end?->copy()->setTimezone($tz)->format('H.i');

        return $to ? "{$from}–{$to} WIB" : "{$from} WIB";
    }

    private function waitingLabel(?Carbon $since, Carbon $now): ?string
    {
        if (! $since) {
            return null;
        }
        $hours = intdiv($now->getTimestamp() - $since->getTimestamp(), 3600);
        if ($hours < 1) {
            return 'Baru masuk';
        }
        if ($hours < 24) {
            return "Menunggu {$hours} jam";
        }

        return 'Menunggu '.intdiv($hours, 24).' hari';
    }

    /** Mirrors the 24-hour convention the letter queues already display. */
    private function isOverdue(?Carbon $since, Carbon $now): bool
    {
        return $since !== null && ($now->getTimestamp() - $since->getTimestamp()) > 24 * 3600;
    }

    /** Drop the internal sort key before the row leaves the service. */
    private function strip(array $row): array
    {
        unset($row['sort_at']);

        return $row;
    }
}
