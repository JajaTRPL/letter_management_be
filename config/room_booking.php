<?php

return [
    'self_withdrawal_cutoff_hours' => (int) env('ROOM_BOOKING_SELF_WITHDRAWAL_CUTOFF_HOURS', 24),
    'idempotency_retention_hours' => (int) env('ROOM_BOOKING_IDEMPOTENCY_RETENTION_HOURS', 168),
    'mutation_rate_limit_per_minute' => (int) env('ROOM_BOOKING_MUTATION_RATE_LIMIT_PER_MINUTE', 20),
    'reason_max_length' => 2000,
    'decision_note_max_length' => 2000,
    // Provisional DTEDI department policy; confirm before production rollout.
    'return_grace_minutes' => (int) env('ROOM_BOOKING_RETURN_GRACE_MINUTES', 30),
    'maximum_consecutive_days' => (int) env('ROOM_BOOKING_MAXIMUM_CONSECUTIVE_DAYS', 14),
];
