<?php

return [
    'otp_expiry_minutes' => (int) env('PASSWORD_RESET_OTP_EXPIRY_MINUTES', 10),
    'reset_token_expiry_minutes' => (int) env('PASSWORD_RESET_TOKEN_EXPIRY_MINUTES', 10),
    'max_attempts' => (int) env('PASSWORD_RESET_MAX_ATTEMPTS', 5),
    'resend_cooldown_seconds' => (int) env('PASSWORD_RESET_RESEND_COOLDOWN_SECONDS', 60),
    'request_window_seconds' => (int) env('PASSWORD_RESET_REQUEST_WINDOW_SECONDS', 600),
    'email_max_requests' => (int) env('PASSWORD_RESET_EMAIL_MAX_REQUESTS', 5),
    'ip_max_requests' => (int) env('PASSWORD_RESET_IP_MAX_REQUESTS', 20),

    // Plaintext OTP exposure is permitted only when this explicit flag is true
    // and APP_ENV=local. It remains disabled by default and cannot activate in
    // testing, staging, or production without changing application code.
    'simulation' => filter_var(
        env('PASSWORD_RESET_SIMULATION', false),
        FILTER_VALIDATE_BOOL
    ),
];
