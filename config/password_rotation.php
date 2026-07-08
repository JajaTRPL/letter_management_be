<?php

return [
    'token_expiry_minutes' => (int) env('PASSWORD_ROTATION_TOKEN_EXPIRY_MINUTES', 15),
    'max_attempts_per_minute' => (int) env('PASSWORD_ROTATION_MAX_ATTEMPTS_PER_MINUTE', 6),
];
