<?php
// Application settings.
return [
    // Show technical error details in API responses (keep false outside development).
    'debug' => false,

    // Cookie name for the PHP session.
    'session_name' => 'EXAMPRO_SID',

    // Sliding session lifetime in seconds (8 hours, same as the original prototype).
    'session_ttl' => 8 * 3600,

    // Question-file upload limit (original: 10 MB).
    'max_upload_bytes' => 10 * 1024 * 1024,
    'max_questions_per_exam' => 1000,

    // Seconds of network grace after the server-side deadline during which a last in-flight
    // answer/submit from the student's browser is still accepted.
    'deadline_grace_seconds' => 5,

    // Sign-in throttling: failed attempts per IP within the window (original: 20 per 15 minutes).
    'login_max_failures'   => 20,
    'login_window_seconds' => 900,

    // Optional: force the URL base path (e.g. '/ExamPro'). Leave null for auto-detection.
    'base_path' => null,
];
