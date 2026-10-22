<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\RoomBookingStatus;
use App\Enums\RoomType;
use App\Models\RoomBookingOccurrence;
use App\Models\RoomBookingRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Idempotent reminder scanner. Walks the currently-relevant approved
 * occurrences and emits phase notifications whose target moment is inside the
 * live window, so a scanner that was down does NOT resurrect stale reminders —
 * only actions that still matter right now are emitted. Every phase has a
 * stable per-occurrence dedup key, so repeated runs never duplicate.
 *
 * Timezone-safe: every comparison is against now(config('app.timezone')), which
 * the caller may freeze for deterministic tests.
 */
class RoomBookingReminderScanner
{
    public function __construct(
        private NotificationWriter $writer,
        private NotificationRecipientResolver $recipients,
    ) {}

    /** @return array{emitted:int,scanned:int} */
    public function scan(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now(config('app.timezone')))->copy();
        $reminders = config('notifications.reminders');
        $catchUp = (int) $reminders['catch_up_window_minutes'];
        $emitted = 0;

        // Only approved bookings own operational reminders. rejected / cancelled
        // bookings are excluded at the query; completed (accepted return) and
        // out-of-window occurrences are filtered per-phase below.
        $occurrences = RoomBookingOccurrence::query()
            ->with(['booking.room.owningLaboratory', 'booking.requester', 'activeReturnRequest', 'acceptedReturnRequest'])
            ->whereHas('booking', fn ($q) => $q->where('status', RoomBookingStatus::Approved->value))
            ->where('end_at', '>=', $now->copy()->subDays(3)) // bound the scan window
            ->orderBy('start_at')
            ->get();

        $scanned = $occurrences->count();

        foreach ($occurrences as $occurrence) {
            $emitted += $this->project($occurrence, $now, $reminders, $catchUp);
        }

        return ['emitted' => $emitted, 'scanned' => $scanned];
    }

    /**
     * Project reminders for one booking right away (e.g. the moment it is
     * approved), so a booking approved inside a reminder window — a same-day
     * booking approved minutes before its start — does not wait for the next
     * scheduled scan. Dedup keys are shared with scan(), so nothing doubles.
     */
    public function scanBooking(RoomBookingRequest $booking, ?Carbon $now = null): int
    {
        $now = ($now ?? Carbon::now(config('app.timezone')))->copy();
        $reminders = config('notifications.reminders');
        $catchUp = (int) $reminders['catch_up_window_minutes'];
        $emitted = 0;

        $occurrences = $booking->occurrences()
            ->with(['booking.room.owningLaboratory', 'booking.requester', 'activeReturnRequest', 'acceptedReturnRequest'])
            ->where('end_at', '>=', $now)
            ->orderBy('start_at')
            ->get();

        foreach ($occurrences as $occurrence) {
            $emitted += $this->project($occurrence, $now, $reminders, $catchUp);
        }

        return $emitted;
    }

    /** @param array<string,int> $r */
    private function project(RoomBookingOccurrence $occurrence, Carbon $now, array $r, int $catchUp): int
    {
        $booking = $occurrence->booking;
        $applicant = $this->recipients->bookingApplicant($booking);
        $room = $booking->room;
        $owner = $this->recipients->operationalOwner($room);
        $start = $occurrence->start_at->copy();
        $end = $occurrence->end_at->copy();
        $returnDue = $occurrence->return_due_at->copy();
        $keyIssued = $occurrence->key_issued_at !== null;
        $accepted = $occurrence->acceptedReturnRequest !== null;
        $pendingReturn = $occurrence->activeReturnRequest !== null;
        $emitted = 0;

        // Phase T-24h before start: applicant + (lab) laboran upcoming heads-up.
        if ($this->inWindow($now, $start->copy()->subMinutes($r['occurrence_lead_minutes']), $start, $catchUp)) {
            if ($applicant) {
                $emitted += $this->emit($applicant, $occurrence, 'occurrence_t24_reminder',
                    NotificationCategory::Reminder, NotificationPriority::Normal,
                    'Jadwal peminjaman mendatang',
                    $this->line($occurrence).' akan berlangsung dalam 24 jam.',
                    "occurrence-t24:{$occurrence->public_id}",
                    NotificationActionRoute::MAHASISWA_BOOKING_OCCURRENCE, 'Lihat Jadwal', $start);
            }
            if ($owner && $room->type === RoomType::Laboratory) {
                $emitted += $this->emit($owner, $occurrence, 'occurrence_t24_reminder',
                    NotificationCategory::Reminder, NotificationPriority::Normal,
                    'Penggunaan lab mendatang',
                    $this->line($occurrence).' dijadwalkan dalam 24 jam.',
                    "occurrence-t24-owner:{$occurrence->public_id}",
                    $this->opsRoute($room), 'Lihat Operasional', $start);
            }
        }

        // Phase key handover shortly before start (owner), only if not yet issued.
        if (! $keyIssued && $owner
            && $this->inWindow($now, $start->copy()->subMinutes($r['key_handover_lead_minutes']), $start, $catchUp)) {
            $emitted += $this->emit($owner, $occurrence, 'key_handover_reminder',
                NotificationCategory::Reminder, NotificationPriority::High,
                'Penyerahan kunci akan jatuh tempo',
                $this->line($occurrence).' segera dimulai; kunci belum diserahkan.',
                "key-handover:{$occurrence->public_id}",
                $this->opsRoute($room), 'Serahkan Kunci', $start);
        }

        // Phase usage started but the key is still not marked as handed over
        // (owner): maybe it was given without clicking. Live until usage ends;
        // after that the usage closes as "kunci tidak diserahkan".
        if (! $keyIssued && $owner && $this->inWindow($now, $start, $end, $catchUp)
            && $now->lessThan($end)) {
            $emitted += $this->emit($owner, $occurrence, 'key_not_issued_reminder',
                NotificationCategory::ActionRequired, NotificationPriority::High,
                'Peminjaman dimulai, kunci belum ditandai diserahkan',
                $this->line($occurrence).' sudah dimulai. Jika kunci sudah diberikan, tandai "Serahkan Kunci" sebelum pukul '
                    .$end->copy()->setTimezone(config('app.timezone'))->format('H.i').' WIB.',
                "key-not-issued:{$occurrence->public_id}",
                $this->opsRoute($room), 'Serahkan Kunci', $start);
        }

        // Phase usage ending soon (applicant). Never before usage has started:
        // for a booking shorter than the lead time the window opens at start.
        $endingSoonFrom = $end->copy()->subMinutes($r['ending_soon_minutes'])->max($start);
        if ($applicant && $this->inWindow($now, $endingSoonFrom, $end, $catchUp)) {
            $emitted += $this->emit($applicant, $occurrence, 'ending_soon_reminder',
                NotificationCategory::Reminder, NotificationPriority::Normal,
                'Penggunaan ruangan akan berakhir',
                $this->line($occurrence).' akan berakhir pukul '
                    .$end->copy()->setTimezone(config('app.timezone'))->format('H.i').' WIB.',
                "ending-soon:{$occurrence->public_id}",
                NotificationActionRoute::MAHASISWA_BOOKING_OCCURRENCE, 'Lihat Jadwal', $end);
        }

        // Phase return due at usage end (applicant): only when key issued and no
        // return yet submitted/accepted (required action still open).
        if ($applicant && $keyIssued && ! $accepted && ! $pendingReturn
            && $this->inWindow($now, $end, $returnDue, $catchUp)) {
            $emitted += $this->emit($applicant, $occurrence, 'return_due_reminder',
                NotificationCategory::Reminder, NotificationPriority::High,
                'Pengembalian kunci jatuh tempo',
                $this->line($occurrence).' — segera kirim bukti pengembalian kunci.',
                "return-due:{$occurrence->public_id}",
                NotificationActionRoute::MAHASISWA_BOOKING_OCCURRENCE, 'Kirim Bukti', $returnDue);
        }

        // Past the 1-hour window with no accepted return: the usage is closed
        // as "tidak mengembalikan kunci". One final notice each — the applicant,
        // and the owner with the applicant's contact. No escalation: nothing is
        // left to do in the system.
        if (! $accepted && $now->greaterThan($returnDue) && ($keyIssued || $pendingReturn)) {
            if ($applicant && ! $pendingReturn) {
                $emitted += $this->emit($applicant, $occurrence, 'return_overdue_reminder',
                    NotificationCategory::Update, NotificationPriority::High,
                    'Kunci tidak dikembalikan',
                    $this->line($occurrence).' melewati batas pengembalian 1 jam dan tercatat tidak mengembalikan kunci. Petugas dapat menghubungi Anda.',
                    "overdue:{$occurrence->public_id}",
                    NotificationActionRoute::MAHASISWA_BOOKING_OCCURRENCE, 'Lihat Detail', $returnDue);
            }
            if ($owner && ! $pendingReturn) {
                $emitted += $this->emit($owner, $occurrence, 'return_overdue_reminder',
                    NotificationCategory::Update, NotificationPriority::High,
                    'Peminjam tidak mengembalikan kunci',
                    $this->line($occurrence).' tidak dikembalikan dalam 1 jam. Hubungi peminjam: '
                        .$this->applicantContactLine($occurrence).'.',
                    "overdue-owner:{$occurrence->public_id}",
                    $this->opsRoute($room), 'Lihat Kontak', $returnDue);
            }
        }

        return $emitted;
    }

    /** True when `now` falls inside [target-window .. deadline], bounded by catch-up. */
    private function inWindow(Carbon $now, Carbon $windowStart, Carbon $deadline, int $catchUp): bool
    {
        // Enter the window at windowStart, stay until the deadline, but never
        // fire for a deadline already more than catchUp minutes in the past.
        return $now->greaterThanOrEqualTo($windowStart)
            && $now->lessThanOrEqualTo($deadline)
            && $now->lessThanOrEqualTo($deadline->copy()->addMinutes($catchUp));
    }

    private function emit(
        User $recipient,
        RoomBookingOccurrence $occurrence,
        string $eventType,
        NotificationCategory $category,
        NotificationPriority $priority,
        string $title,
        string $body,
        string $dedupKey,
        string $route,
        string $label,
        Carbon $occurredAt,
    ): int {
        $before = $this->writer->write(new NotificationIntent(
            recipient: $recipient,
            eventType: $eventType,
            category: $category,
            priority: $priority,
            title: $title,
            body: $body,
            dedupKey: $dedupKey,
            subjectType: 'occurrence',
            subjectPublicId: $occurrence->public_id,
            actionRouteKey: $route,
            actionLabel: $label,
            occurredAt: $occurredAt,
        ));

        // Count only freshly-created rows (idempotent re-runs return the existing
        // row with the same timestamps).
        return $before->wasRecentlyCreated ? 1 : 0;
    }

    private function opsRoute($room): string
    {
        return $room->type === RoomType::Classroom
            ? NotificationActionRoute::SARPRAS_OPERATIONS
            : NotificationActionRoute::LABORAN_OPERATIONS;
    }

    private function applicantContactLine(RoomBookingOccurrence $occurrence): string
    {
        $occurrence->loadMissing('booking.requester.mahasiswaProfile');
        $requester = $occurrence->booking->requester;
        $phone = $requester?->mahasiswaProfile?->no_hp;

        return trim(($requester?->name ?? 'peminjam').($phone ? ", {$phone}" : ''));
    }

    private function line(RoomBookingOccurrence $occurrence): string
    {
        return trim("{$occurrence->booking->room->code} · {$occurrence->occurrence_date->toDateString()}");
    }
}
