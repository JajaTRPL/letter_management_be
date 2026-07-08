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

if (config('letter_retention.enabled')) {
    $event = Schedule::command('letters:retention --execute --manifest')
        ->dailyAt((string) config('letter_retention.scheduler.time', '02:30'))
        ->withoutOverlapping();

    if (config('letter_retention.scheduler.on_one_server') && config('cache.default') !== 'array') {
        $event->onOneServer();
    }
}
