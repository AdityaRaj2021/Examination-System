<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Verifies the X-CSRF-Token header (or a csrf_token form field). Used for every non-GET API request. */
function csrf_check(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    $have = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($have) || $have === '' || !hash_equals($have, $sent)) {
        fail('Your session security token is missing or expired. Reload the page and try again.', 419);
    }
}

function csrf_meta(): string
{
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}
