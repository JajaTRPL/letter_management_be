<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Services\Notifications\LetterReviewSlaScanner;
use App\Services\Notifications\NotificationProjector;
use App\Services\Notifications\RoomBookingReminderScanner;
use App\Services\Notifications\RoomBookingReviewSlaScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Idempotent reminder + housekeeping scanner for room-booking notifications.
 * Safe to run repeatedly and on any cadence; withoutOverlapping/onOneServer are
 * applied at the schedule registration. A dispatcher failure is captured as a
 * SuperAdmin health notification rather than surfaced as an unhandled error.
 */
class DispatchRoomBookingReminders extends Command
{
    protected $signature = 'notifications:room-booking-reminders {--purge : also purge expired/old resolved notifications}';

    protected $description = 'Emit due room-booking reminder notifications and record dispatcher health.';

    public function handle(
        RoomBookingReminderScanner $scanner,
        RoomBookingReviewSlaScanner $reviewSla,
        LetterReviewSlaScanner $letterSla,
        NotificationProjector $projector,
    ): int {
        $now = Carbon::now(config('app.timezone'));

        try {
            $result = $scanner->scan($now);
            // Review-SLA governance (both domains) runs in the same idempotent
            // pass; each self-gates to a no-op when its SuperAdmin policy is
            // disabled. A failure here must not lose the operational reminder run
            // above, so it is isolated.
            $slaResult = $reviewSla->scan($now);
            $letterSlaResult = $letterSla->scan($now);
        } catch (\Throwable $e) {
            // The scanner itself failed — this IS a SuperAdmin health anomaly.
            $projector->healthAlert(
                'reminder-dispatcher-failure',
                'Penjadwal pengingat gagal',
                'Pemindaian pengingat peminjaman gagal dijalankan. Periksa log sistem.',
            );
            $this->error('Reminder scan failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // Heartbeat: record the last successful run so a separate health check
        // can detect a stale/silent scheduler.
        Cache::forever('notifications:reminders:last_run_at', $now->toIso8601String());

        if ($this->option('purge')) {
            $this->purge($now);
        }

        $this->info("Reminders scanned={$result['scanned']} emitted={$result['emitted']}");
        $this->info("Room-booking review-SLA enabled={$slaResult['enabled']} scanned={$slaResult['scanned']} emitted={$slaResult['emitted']}");
        $this->info("Letter review-SLA enabled={$letterSlaResult['enabled']} scanned={$letterSlaResult['scanned']} emitted={$letterSlaResult['emitted']}");

        return self::SUCCESS;
    }

    private function purge(Carbon $now): void
    {
        $cutoff = $now->copy()->subDays((int) config('notifications.retention_days', 90));

        // Only resolved or expired notifications are ever purged; unresolved and
        // unread state is retained regardless of age.
        AppNotification::query()
            ->where(function ($query) use ($cutoff): void {
                $query->whereNotNull('resolved_at')->where('resolved_at', '<', $cutoff);
            })
            ->orWhere(function ($query) use ($now): void {
                $query->whereNotNull('expires_at')->where('expires_at', '<', $now);
            })
            ->delete();
    }
}
