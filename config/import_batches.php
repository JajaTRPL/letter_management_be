<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Import batch row retention purge
    |--------------------------------------------------------------------------
    | Row-level import data (names/emails/NIMs and error payloads) is kept
    | until import_batches.expires_at, then removed by import-batches:purge.
    | Scheduler activation is config-driven, matching letter_retention:
    | enable in production via IMPORT_BATCH_PURGE_ENABLED=true.
    */
    'purge' => [
        'enabled' => (bool) env('IMPORT_BATCH_PURGE_ENABLED', false),
        'time' => env('IMPORT_BATCH_PURGE_TIME', '03:15'),
    ],
];
