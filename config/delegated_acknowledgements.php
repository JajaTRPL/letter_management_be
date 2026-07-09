<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Delegated Activity Acknowledgement SLA
    |--------------------------------------------------------------------------
    |
    | SLA is intentionally configuration-driven so controllers never hardcode
    | due-date policy. Urgent and normal priorities use a Monday-Friday working
    | day approximation. Public holidays are not handled in C6B.
    |
    */
    'sla' => [
        'default_urgency' => 'normal',

        'urgencies' => [
            'urgent' => [
                'amount' => 1,
                'unit' => 'working_day',
            ],
            'normal' => [
                'amount' => 3,
                'unit' => 'working_day',
            ],
            'low_risk' => [
                'amount' => 7,
                'unit' => 'calendar_day',
            ],
        ],

        'activity_types' => [
            // Example for later phases:
            // 'lab_facility_changed' => ['urgency' => 'normal'],
        ],
    ],
];
