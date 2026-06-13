<?php

return [
    'enabled' => (bool) env('LETTER_RETENTION_ENABLED', false),

    'supporting_document_retention_days' => (int) env('SUPPORTING_DOCUMENT_RETENTION_DAYS', 14),
    'intermediate_artifact_retention_days' => (int) env('INTERMEDIATE_ARTIFACT_RETENTION_DAYS', 14),
    'final_pdf_active_days' => (int) env('FINAL_PDF_ACTIVE_DAYS', 30),

    'archive' => [
        'disk' => env('LETTER_ARCHIVE_DISK', 'archive'),
        'retention_days' => (int) env('FINAL_PDF_ARCHIVE_RETENTION_DAYS', 365),
    ],

    'batch_size' => (int) env('LETTER_RETENTION_BATCH_SIZE', 100),

    'scheduler' => [
        'time' => env('LETTER_RETENTION_SCHEDULE_TIME', '02:30'),
        'on_one_server' => (bool) env('LETTER_RETENTION_ON_ONE_SERVER', true),
    ],
];
