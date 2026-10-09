<?php

/**
 * UK GDPR retention / minimisation (Phase 4).
 * Set a value to 0 to disable pruning for that category.
 * Hub database backups use separate retention (section 6B) — not controlled here.
 * Compliance audit events are NOT pruned here (FCA-style accountability).
 */
return [

    'retention' => [
        // Operational API activity_logs (who/what/IP). Default 1 year.
        'activity_logs_days' => (int) env('GDPR_RETENTION_ACTIVITY_LOGS_DAYS', 365),

        // One-time auth tokens
        'login_otp_hours' => (int) env('GDPR_RETENTION_LOGIN_OTP_HOURS', 24),
        'email_verification_days' => (int) env('GDPR_RETENTION_EMAIL_VERIFICATION_DAYS', 7),
        'password_reset_days' => (int) env('GDPR_RETENTION_PASSWORD_RESET_DAYS', 2),

        // DB session rows (Sanctum SPA sessions table)
        'sessions_days' => (int) env('GDPR_RETENTION_SESSIONS_DAYS', 30),

        // Stored Excel files on advisor import batches (metadata rows kept)
        'advisor_import_files_days' => (int) env('GDPR_RETENTION_ADVISOR_IMPORT_FILES_DAYS', 90),

        // Closed / resolved support tickets (+ comments / attachments)
        'closed_support_tickets_days' => (int) env('GDPR_RETENTION_CLOSED_SUPPORT_TICKETS_DAYS', 365),
    ],

];
