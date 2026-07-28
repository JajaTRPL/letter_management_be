<?php

return [
    // Email-based password reset is an EMAIL-DEPENDENT feature. It ships DISABLED
    // because the deployment has no stakeholder-approved SMTP/provider yet
    // (MAIL_MAILER=log delivers nothing to real users) — leaving it "on" would be
    // a dead end: a user requests a code that never arrives. Enable it only once a
    // real mail path exists, via PASSWORD_RESET_ENABLED=true. When disabled, the
    // reset endpoints return a clear "unavailable" response and the login UI hides
    // the "Lupa Kata Sandi" affordance rather than offering a broken flow.
    'enabled' => filter_var(env('PASSWORD_RESET_ENABLED', false), FILTER_VALIDATE_BOOL),

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
