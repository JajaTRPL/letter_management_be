<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('letter_retention.enabled')) {
    $event = Schedule::command('letters:retention --execute --manifest')
        ->dailyAt((string) config('letter_retention.scheduler.time', '02:30'))
        ->withoutOverlapping();

    if (config('letter_retention.scheduler.on_one_server') && config('cache.default') !== 'array') {
        $event->onOneServer();
    }
}
