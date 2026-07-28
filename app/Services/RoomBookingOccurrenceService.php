<?php

namespace App\Services;

use App\Enums\RoomBookingReturnStatus;
use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Models\RoomBookingOccurrence;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingReturnRequest;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RoomBookingOccurrenceService
{
    public function __construct(
        private RoomBookingOccurrenceEventService $events,
        private RoomBookingOccurrenceAuthorizationService $authorization,
    ) {}

    /** @return list<array{sequence:int,date:string,start_at:Carbon,end_at:Carbon,return_due_at:Carbon}> */
    public function rangesFromAttributes(array $attributes): array
    {
        $timezone = config('app.timezone');
        $start = Carbon::parse($attributes['start_at'])->setTimezone($timezone);
        $overallEnd = Carbon::parse($attributes['end_at'])->setTimezone($timezone);
        $mode = $attributes['booking_mode'] ?? 'single_day';
        $lastDate = $mode === 'consecutive_days'
            ? Carbon::parse($attributes['occurrence_end_date'], $timezone)->startOfDay()
            : $start->copy()->startOfDay();
        $firstDate = $start->copy()->startOfDay();
        $days = (int) $firstDate->diffInDays($lastDate, false) + 1;
        $overnight = $overallEnd->format('H:i:s') <= $start->format('H:i:s');
        $grace = max(0, (int) config('room_booking.return_grace_minutes', 30));
        $ranges = [];

        for ($index = 0; $index < $days; $index++) {
            $date = $firstDate->copy()->addDays($index);
            $occurrenceStart = $date->copy()->setTimeFromTimeString($start->format('H:i:s'));
            $occurrenceEnd = $date->copy()
                ->addDays($overnight ? 1 : 0)
                ->setTimeFromTimeString($overallEnd->format('H:i:s'));
            $ranges[] = [
                'sequence' => $index + 1,
                'date' => $date->toDateString(),
                'start_at' => $occurrenceStart,
                'end_at' => $occurrenceEnd,
                'return_due_at' => $occurrenceEnd->copy()->addMinutes($grace),
            ];
        }

        return $ranges;
    }

    public function createForBooking(RoomBookingRequest $booking, User $actor): Collection
    {
        $ranges = $this->rangesFromAttributes($booking->only([
            'start_at', 'end_at', 'booking_mode', 'occurrence_end_date',
        ]));
        $created = collect();
        foreach ($ranges as $range) {
            $occurrence = $booking->occurrences()->create([
                'sequence' => $range['sequence'],
                'occurrence_date' => $range['date'],
                'start_at' => $range['start_at'],
                'end_at' => $range['end_at'],
                'return_due_at' => $range['return_due_at'],
            ]);
            $occurrence->setRelation('booking', $booking);
            $this->events->record(
                $occurrence,
                RoomBookingWorkflowEvent::EVENT_OCCURRENCE_CREATED,
                $actor,
                'Jadwal penggunaan dibuat.',
                [
                    'start_at' => $range['start_at']->toIso8601String(),
                    'end_at' => $range['end_at']->toIso8601String(),
                    'return_due_at' => $range['return_due_at']->toIso8601String(),
                ],
                (int) $booking->requester_id,
                'mahasiswa',
            );
            $created->push($occurrence);
        }

        return $created;
    }

    public function ensureLegacyOccurrence(RoomBookingRequest $booking): RoomBookingOccurrence
    {
        $existing = $booking->occurrences()->first();
        if ($existing) {
            return $existing;
        }
        $booking->booking_mode ??= 'single_day';
        $booking->occurrence_end_date ??= $booking->start_at->toDateString();
        $range = $this->rangesFromAttributes($booking->only([
            'start_at', 'end_at', 'booking_mode', 'occurrence_end_date',
        ]))[0];

        return $booking->occurrences()->create([
            'sequence' => $range['sequence'],
            'occurrence_date' => $range['date'],
            'start_at' => $range['start_at'],
            'end_at' => $range['end_at'],
            'return_due_at' => $range['return_due_at'],
        ]);
    }

    public function replaceForBooking(RoomBookingRequest $booking, User $actor): Collection
    {
        $hasOperationalState = $booking->occurrences()
            ->where(function ($query): void {
                $query->whereNotNull('key_issued_at')->orWhereHas('returnRequests');
            })->exists();
        if ($hasOperationalState) {
            throw new RoomBookingDomainException(
                RoomBookingDomainException::INVALID_TRANSITION,
                'Jadwal penggunaan tidak dapat diubah setelah proses kunci dimulai.',
            );
        }
        $booking->occurrences()->delete();

        return $this->createForBooking($booking, $actor);
    }

    public function operationalStatus(
        RoomBookingOccurrence $occurrence,
        ?Carbon $now = null,
    ): string {
        $now ??= now(config('app.timezone'));
        $booking = $occurrence->booking;
        if ($booking->status === RoomBookingStatus::Cancelled) return 'cancelled';
        // Only an approved booking has an operational life. Everything else
        // (submitted, revision_requested, rejected) owns an occurrence row for
        // compatibility/audit but is read-only — reporting it as "scheduled"
        // would promise a key handover that can never happen.
        if ($booking->status !== RoomBookingStatus::Approved) return 'not_actionable';

        $accepted = $occurrence->acceptedReturnRequest;
        if ($accepted?->key_received_at) {
            return $accepted->key_received_at->lessThanOrEqualTo($occurrence->return_due_at)
                ? 'returned_on_time'
                : 'returned_late';
        }

        $active = $occurrence->activeReturnRequest;
        if ($active?->status === RoomBookingReturnStatus::RevisionRequested) return 'revision_required';
        if ($active?->status === RoomBookingReturnStatus::Pending) return 'awaiting_verification';
        if ($now->greaterThan($occurrence->return_due_at)) return 'overdue';
        if ($occurrence->key_issued_at) {
            if ($now->lessThan($occurrence->start_at)) return 'key_issued';
            if ($now->lessThan($occurrence->end_at)) return 'in_use';
            return 'return_due';
        }

        return 'scheduled';
    }

    public function payload(RoomBookingOccurrence $occurrence, bool $staff = false): array
    {
        $occurrence->loadMissing([
            'booking.room.owningLaboratory:id,code,name',
            'activeReturnRequest',
            'acceptedReturnRequest',
            'returnRequests',
        ]);
        $accepted = $occurrence->acceptedReturnRequest;
        $active = $occurrence->activeReturnRequest;
        $status = $this->operationalStatus($occurrence);
        $returnRequest = $active ?? $accepted;

        $payload = [
            'occurrence_ref' => $occurrence->public_id,
            'sequence' => (int) $occurrence->sequence,
            'date' => $occurrence->occurrence_date->toDateString(),
            'start_at' => $occurrence->start_at->toIso8601String(),
            'end_at' => $occurrence->end_at->toIso8601String(),
            'return_due_at' => $occurrence->return_due_at->toIso8601String(),
            'version' => (int) $occurrence->version,
            'operational_status' => $status,
            'key_issuance' => [
                'issued' => $occurrence->key_issued_at !== null,
                'issued_at' => $occurrence->key_issued_at?->toIso8601String(),
                'issued_by' => $occurrence->key_issued_at ? [
                    'name' => $occurrence->key_issued_by_name,
                    'role' => $occurrence->key_issued_by_role,
                ] : null,
            ],
            'return' => $returnRequest ? $this->returnPayload($returnRequest, $staff) : null,
            'capabilities' => [
                'can_submit_return' => $this->canSubmitReturn($occurrence),
                'can_withdraw_return' => $this->isOperationallyActionable($occurrence)
                    && $active?->status === RoomBookingReturnStatus::Pending,
                'can_resubmit_return' => $this->isOperationallyActionable($occurrence)
                    && $active?->status === RoomBookingReturnStatus::RevisionRequested,
            ],
            'event_hooks' => $this->eventHooks($occurrence),
        ];

        if ($staff) {
            $payload['return_history'] = $occurrence->returnRequests
                ->map(fn (RoomBookingReturnRequest $request) => $this->returnPayload($request, true))
                ->values()->all();
        }

        return $payload;
    }

    /**
     * The single operational-eligibility gate every capability hangs off: the
     * parent booking must be stored-status `approved` (which also means the
     * occurrence is not cancelled — a cancelled booking is never approved).
     */
    public function isOperationallyActionable(RoomBookingOccurrence $occurrence): bool
    {
        return $occurrence->booking->status === RoomBookingStatus::Approved;
    }

    /**
     * Key issuance requires: approved parent booking, a non-cancelled
     * occurrence, no key already issued, an occurrence state that still permits
     * handover (a return already accepted closes it out), and an authenticated
     * actor whose role and room/lab scope allow it. The service mutation guard
     * (RoomBookingKeyService::issue) remains the authority; this only decides
     * whether the action is offered.
     */
    public function canIssueKey(?User $actor, RoomBookingOccurrence $occurrence): bool
    {
        return $actor !== null
            && $this->isOperationallyActionable($occurrence)
            && $occurrence->key_issued_at === null
            && $occurrence->acceptedReturnRequest === null
            && $this->authorization->canIssueOrReceive($actor, $occurrence);
    }

    /** Verification is only for a return that is actually awaiting a decision. */
    public function canVerifyReturn(?User $actor, RoomBookingOccurrence $occurrence): bool
    {
        return $actor !== null
            && $this->isOperationallyActionable($occurrence)
            && $occurrence->activeReturnRequest?->status === RoomBookingReturnStatus::Pending
            && $this->authorization->canIssueOrReceive($actor, $occurrence);
    }

    /**
     * Who owns the key/return step for this occurrence — Sarpras for classrooms,
     * Laboran for laboratories, exactly as canIssueOrReceive() decides.
     *
     * Lives here, next to the capability checks, because it is the SAME rule
     * phrased for a human. Every surface that shows an occurrence a user cannot
     * act on reads this one string, so the dashboard's awareness panel and the
     * Peminjaman operations tab can never explain the situation differently.
     */
    public function responsibleLabelFor(RoomBookingOccurrence $occurrence): string
    {
        return $occurrence->booking?->room?->type === RoomType::Classroom
            ? 'Menunggu Sarpras'
            : 'Menunggu Laboran';
    }

    public function canSubmitReturn(RoomBookingOccurrence $occurrence): bool
    {
        return $this->isOperationallyActionable($occurrence)
            && $occurrence->key_issued_at !== null
            && now(config('app.timezone'))->greaterThanOrEqualTo($occurrence->end_at)
            && $occurrence->acceptedReturnRequest === null
            && ($occurrence->activeReturnRequest === null
                || $occurrence->activeReturnRequest->status === RoomBookingReturnStatus::RevisionRequested);
    }

    private function returnPayload(RoomBookingReturnRequest $request, bool $staff): array
    {
        $payload = [
            'return_ref' => $request->public_id,
            'status' => $request->status->value,
            'version' => (int) $request->version,
            'submitted_at' => $request->submitted_at->toIso8601String(),
            'decision_note' => $request->decision_note,
            'key_received_at' => $request->key_received_at?->toIso8601String(),
            'verified_at' => $request->verified_at?->toIso8601String(),
            'evidence' => [
                'original_name' => $request->evidence_original_name,
                'mime' => $request->evidence_mime,
                'size_bytes' => (int) $request->evidence_size_bytes,
                'preview_url' => "/api/peminjaman-ruangan/returns/{$request->public_id}/evidence/preview",
                'download_url' => "/api/peminjaman-ruangan/returns/{$request->public_id}/evidence/download",
            ],
        ];
        if ($staff) {
            $payload['verified_by'] = $request->decided_by ? [
                'name' => $request->decided_by_name,
                'role' => $request->decided_by_role,
            ] : null;
            $payload['received_time_change_reason'] = $request->received_time_change_reason;
        }

        return $payload;
    }

    private function eventHooks(RoomBookingOccurrence $occurrence): array
    {
        return [
            ['type' => 'usage_started', 'at' => $occurrence->start_at->toIso8601String()],
            ['type' => 'usage_ended', 'at' => $occurrence->end_at->toIso8601String()],
            ['type' => 'return_due', 'at' => $occurrence->return_due_at->toIso8601String()],
            ['type' => 'return_overdue', 'at' => $occurrence->return_due_at->toIso8601String()],
        ];
    }
}
