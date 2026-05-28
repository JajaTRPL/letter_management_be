<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DOCX -> PDF converter driver
    |--------------------------------------------------------------------------
    |
    | Selects the implementation bound to App\Services\DocumentConverter in
    | AppServiceProvider. Only "gotenberg" is implemented today.
    |
    */
    'driver' => env('DOCUMENT_CONVERTER_DRIVER', 'gotenberg'),

    /*
    |--------------------------------------------------------------------------
    | Gotenberg endpoint and HTTP timing
    |--------------------------------------------------------------------------
    |
    | The Gotenberg LibreOffice route lives at "/forms/libreoffice/convert".
    | Override DOCUMENT_CONVERTER_URL per environment (local docker, staging,
    | production). Connect-timeout is short so a misconfigured URL fails fast;
    | the request-timeout is the per-conversion ceiling.
    |
    */
    'url' => env('DOCUMENT_CONVERTER_URL', 'http://localhost:3000'),
    'timeout_seconds' => (int) env('DOCUMENT_CONVERTER_TIMEOUT_SECONDS', 60),
    'connect_timeout_seconds' => (int) env('DOCUMENT_CONVERTER_CONNECT_TIMEOUT_SECONDS', 5),
];
