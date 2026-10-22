<?php

return [
    'self_withdrawal_cutoff_hours' => (int) env('ROOM_BOOKING_SELF_WITHDRAWAL_CUTOFF_HOURS', 24),
    'idempotency_retention_hours' => (int) env('ROOM_BOOKING_IDEMPOTENCY_RETENTION_HOURS', 168),
    'mutation_rate_limit_per_minute' => (int) env('ROOM_BOOKING_MUTATION_RATE_LIMIT_PER_MINUTE', 20),
    'reason_max_length' => 2000,
    'decision_note_max_length' => 2000,
    // Provisional DTEDI department policy; confirm before production rollout.
    // Time after usage ends to hand the key back before it counts as late.
    'return_grace_minutes' => (int) env('ROOM_BOOKING_RETURN_GRACE_MINUTES', 60),
    // Minimum gap between two bookings of the same room, so the next borrower
    // only starts once the previous key is due back.
    'turnover_buffer_minutes' => (int) env('ROOM_BOOKING_TURNOVER_BUFFER_MINUTES', 60),
    'maximum_consecutive_days' => (int) env('ROOM_BOOKING_MAXIMUM_CONSECUTIVE_DAYS', 14),
];
