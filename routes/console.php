<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Row-level import data (PII) retention. Scheduler activation is
// config-driven (IMPORT_BATCH_PURGE_ENABLED), same as letter retention.
if (config('import_batches.purge.enabled')) {
    Schedule::command('import-batches:purge')
        ->dailyAt((string) config('import_batches.purge.time', '03:15'))
        ->withoutOverlapping();
}

// Registered unconditionally so the SuperAdmin UI switch (DB automation flag)
// is the real ON/OFF control, not LETTER_RETENTION_ENABLED. The command checks
// the database automation state at runtime and exits safely without mutating
// anything when the switch is OFF.
$event = Schedule::command('letters:retention --execute --manifest')
    ->dailyAt((string) config('letter_retention.scheduler.time', '02:30'))
    ->withoutOverlapping();

if (config('letter_retention.scheduler.on_one_server') && config('cache.default') !== 'array') {
    $event->onOneServer();
}

// C7N1 room-booking reminders: idempotent, safe to run every ten minutes. The
// scanner only emits actions inside their live window, so a run after downtime
// catches up without flooding. Overlap-guarded; single-server where the cache
// driver supports it. Daily purge of resolved/expired history is folded in at
// a quiet hour via the --purge flag on the 00:05 run.
$reminderEvent = Schedule::command('notifications:room-booking-reminders')
    ->everyTenMinutes()
    ->withoutOverlapping();

$purgeEvent = Schedule::command('notifications:room-booking-reminders --purge')
    ->dailyAt('00:05')
    ->withoutOverlapping();

if (config('cache.default') !== 'array') {
    $reminderEvent->onOneServer();
    $purgeEvent->onOneServer();
}
