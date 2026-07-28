<?php

return [
    // Reminder scheduler lead times (minutes). Timezone-safe: all comparisons
    // are made against now(config('app.timezone')).
    'reminders' => [
        'occurrence_lead_minutes' => (int) env('NOTIFY_OCCURRENCE_LEAD_MINUTES', 24 * 60),
        'key_handover_lead_minutes' => (int) env('NOTIFY_KEY_HANDOVER_LEAD_MINUTES', 60),
        'ending_soon_minutes' => (int) env('NOTIFY_ENDING_SOON_MINUTES', 30),
        // Do not resurrect stale reminders after scheduler downtime: only emit a
        // phase whose target moment is within this many minutes of "now".
        'catch_up_window_minutes' => (int) env('NOTIFY_CATCH_UP_WINDOW_MINUTES', 180),
    ],

    // Escalation: an unresolved overdue return older than this (minutes) raises
    // an escalation notification to the responsible Kepala Lab / Sarpras.
    'escalation' => [
        'unresolved_return_minutes' => (int) env('NOTIFY_UNRESOLVED_RETURN_MINUTES', 24 * 60),
    ],

    // Retention: resolved/expired notifications older than this are purged by
    // the scheduler. Read/unresolved state is never purged.
    'retention_days' => (int) env('NOTIFY_RETENTION_DAYS', 90),

    // Review-SLA governance defaults (C10/C11). SAFE TECHNICAL DEFAULTS, NOT
    // stakeholder-approved policy: the policy ships DISABLED per scope, so no
    // review notification is emitted until a SuperAdmin explicitly enables and
    // tunes it (preserving current behavior exactly, and — critically —
    // preventing a first-scan storm across every already-waiting request).
    //
    // Baseline is 7 DAYS (the overdue threshold), centred so a warning lands 2
    // days before the deadline and an escalation 2 days after. All thresholds
    // are MINUTES from the moment a request entered its current "waiting for
    // review" state (submission/resubmission for bookings; the stage timestamp
    // for letters). Invariant enforced at write time: warning <= overdue <=
    // escalation. Shared by every scope (room_booking, letter); a per-scope row
    // may override these.
    'review_sla' => [
        'enabled' => (bool) env('REVIEW_SLA_ENABLED', false),
        'warning_minutes' => (int) env('REVIEW_SLA_WARNING_MINUTES', 5 * 24 * 60),
        'overdue_minutes' => (int) env('REVIEW_SLA_OVERDUE_MINUTES', 7 * 24 * 60),
        'escalation_minutes' => (int) env('REVIEW_SLA_ESCALATION_MINUTES', 9 * 24 * 60),
        // Never emit an SLA phase whose threshold moment is more than this many
        // minutes in the past — a scanner that was down does not resurrect a
        // storm of stale breaches (mirrors the reminder catch-up window).
        'catch_up_window_minutes' => (int) env('REVIEW_SLA_CATCH_UP_MINUTES', 6 * 60),
    ],

    // Unified email delivery (C7N5). Email is a DELIVERY CHANNEL of the single
    // C7N1 backbone — NOT a parallel notification system. When a durable
    // notification is created, an eligible one also queues an email to the
    // recipient (best-effort, after commit). This replaced the scholarship-only
    // legacy mail bridge with one uniform, scoped contract for every type.
    'mail' => [
        'enabled' => (bool) env('NOTIFY_MAIL_ENABLED', true),
        // Categories always emailed (the "you must act" family).
        'categories' => ['action_required'],
        // `update` outcomes are emailed only at these priorities (important
        // results like approved/rejected/ready) — routine reminders and system
        // health stay in-app only, to avoid inbox+mail fatigue.
        'update_priorities' => ['urgent', 'high'],
    ],
];
