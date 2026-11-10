<?php
declare(strict_types=1);

// Three independent login namespaces live in one PHP session:
//   $_SESSION['auth']['admin'|'examiner'|'student']
// so signing in to one portal never logs out another one in the same browser.

const ROLES = ['admin', 'examiner', 'student'];

function auth_user(string $role): ?array
{
    $u = $_SESSION['auth'][$role] ?? null;
    if (!is_array($u)) return null;
    if (($u['exp'] ?? 0) < time()) {
        unset($_SESSION['auth'][$role]);
        return null;
    }
    $_SESSION['auth'][$role]['exp'] = time() + (int) app_cfg('session_ttl', 28800);   // sliding expiry
    return $u;
}

function auth_login(string $role, array $user): void
{
    session_regenerate_id(true);               // prevent session fixation
    unset($_SESSION['csrf']);                  // fresh CSRF token after login
    $_SESSION['auth'][$role] = $user + ['role' => $role];
    $_SESSION['auth'][$role]['exp'] = time() + (int) app_cfg('session_ttl', 28800);
}

function auth_logout(string $role): void
{
    unset($_SESSION['auth'][$role]);
    if (empty($_SESSION['auth'])) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    } else {
        session_regenerate_id(true);
        unset($_SESSION['csrf']);
    }
}

/** Ends every portal login (admin, examiner, student) and destroys the session. */
function auth_logout_all(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

/**
 * Returns the freshly validated current user for $role or null.
 * Re-checks the database so disabled examiners / un-verified students lose access immediately.
 */
function auth_current(string $role): ?array
{
    $u = auth_user($role);
    if (!$u) return null;
    $pdo = db();
    if ($role === 'admin') {
        $st = $pdo->prepare('SELECT id, username, full_name FROM admins WHERE id = ?');
        $st->execute([$u['id']]);
        $r = $st->fetch();
        if (!$r) { unset($_SESSION['auth']['admin']); return null; }
        return ['id' => (int) $r['id'], 'name' => $r['full_name'], 'username' => $r['username'], 'role' => 'admin'];
    }
    if ($role === 'examiner') {
        $st = $pdo->prepare('SELECT id, full_name, email, status FROM examiners WHERE id = ?');
        $st->execute([$u['id']]);
        $r = $st->fetch();
        if (!$r || $r['status'] !== 'Active') { unset($_SESSION['auth']['examiner']); return null; }
        return ['id' => (int) $r['id'], 'name' => $r['full_name'], 'email' => $r['email'], 'role' => 'examiner'];
    }
    $st = $pdo->prepare('SELECT id, full_name, email, student_code, course, year_label, status FROM students WHERE id = ?');
    $st->execute([$u['id']]);
    $r = $st->fetch();
    if (!$r || $r['status'] !== 'Verified') { unset($_SESSION['auth']['student']); return null; }
    return ['id' => (int) $r['id'], 'name' => $r['full_name'], 'email' => $r['email'], 'student_code' => $r['student_code'],
            'course' => $r['course'], 'year' => $r['year_label'], 'role' => 'student'];
}

/** API guard: 401 when not signed in as $role. */
function require_api_role(string $role): array
{
    $u = auth_current($role);
    if (!$u) fail('Please sign in again.', 401);
    return $u;
}

/** Page guard: redirect to the portal's sign-in page when not signed in. */
function require_page_role(string $role, string $redirectTo): array
{
    $u = auth_current($role);
    if (!$u) {
        header('Location: ' . url($redirectTo));
        exit;
    }
    return $u;
}

// ------------------------------------------------------------- sign-in throttling
function login_throttle_check(): void
{
    $pdo = db();
    $win = (int) app_cfg('login_window_seconds', 900);
    $max = (int) app_cfg('login_max_failures', 20);
    $since = gmdate('Y-m-d H:i:s', time() - $win);
    if (mt_rand(1, 50) === 1) {   // occasional cleanup
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$since]);
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
    $st->execute([client_ip(), $since]);
    if ((int) $st->fetchColumn() >= $max) {
        fail('Too many failed sign-in attempts. Try again in 15 minutes.', 429);
    }
}

function login_throttle_fail(): void
{
    db()->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([client_ip(), utc_now()]);
}

/** Constant-time-ish verification that still burns CPU when the account does not exist. */
function verify_password_or_dummy(string $password, ?string $hash): bool
{
    static $dummy = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $ok = password_verify($password, $hash ?: $dummy);
    return $hash ? $ok : false;
}
