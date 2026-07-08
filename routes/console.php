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
