<?php
declare(strict_types=1);

class ApiError extends RuntimeException
{
    public int $status;
    public array $extra;
    public function __construct(string $message, int $status = 400, array $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->extra = $extra;
    }
}

function app_cfg(string $key, $default = null)
{
    $c = $GLOBALS['EXAMPRO_CFG'] ?? [];
    return array_key_exists($key, $c) && $c[$key] !== null ? $c[$key] : $default;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** URL path prefix of the app, e.g. "/ExamPro" ("" when served from the web root). */
function base_path(): string
{
    static $base = null;
    if ($base !== null) return $base;
    $forced = app_cfg('base_path');
    if (is_string($forced)) return $base = rtrim($forced, '/');
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $root = realpath(EXAMPRO_ROOT);
    if ($doc && $root) {
        $doc = str_replace('\\', '/', $doc);
        $root = str_replace('\\', '/', $root);
        if (stripos($root, $doc) === 0) return $base = rtrim(substr($root, strlen($doc)), '/');
    }
    return $base = '/ExamPro';
}

/**
 * Logical app path -> real URL. Pages keep using url('admin/'), url('api/auth.php') etc.;
 * this table is the only place that knows which of the five folders serves what.
 */
function url(string $path = ''): string
{
    static $map = [
        'admin'            => DIR_FE_ADMIN . '/admin',
        'examiner'         => DIR_FE_ADMIN . '/examiner',
        'student'          => DIR_FE_STUDENT . '/student',
        'api/auth.php'     => DIR_BE_AUTH . '/api/auth.php',
        'api/admin.php'    => DIR_BE_AUTH . '/api/admin.php',
        'api/student.php'  => DIR_BE_EXAM . '/api/student.php',
        'api/examiner.php' => DIR_BE_EXAM . '/api/examiner.php',
        'samples'          => DIR_BE_EXAM . '/samples',
    ];
    $p = ltrim($path, '/');
    if ($p === '') return base_path() . '/' . DIR_FE_STUDENT . '/';   // home page
    foreach ($map as $k => $v) {
        if ($p === $k || str_starts_with($p, $k . '/')) return base_path() . '/' . $v . substr($p, strlen($k));
    }
    return base_path() . '/' . $p;
}

/** HTML-escape for output. */
function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = require EXAMPRO_ROOT . '/' . DIR_DB_DEPLOY . '/config/database.php';
    $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");   // everything is stored and compared in UTC
    return $pdo;
}

function utc_now(): string { return gmdate('Y-m-d H:i:s'); }

/** "2026-01-31 10:00:00" (UTC) -> "2026-01-31T10:00:00Z" for JavaScript. */
function iso(?string $dt): ?string
{
    if ($dt === null || $dt === '') return null;
    return str_replace(' ', 'T', $dt) . 'Z';
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function num($v): float|int
{
    $f = (float) $v;
    return $f == floor($f) ? (int) $f : round($f, 2);
}

// ------------------------------------------------------------------ API plumbing
function json_out($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $message, int $status = 400, array $extra = []): void
{
    throw new ApiError($message, $status, $extra);
}

/** Call first in every API file: JSON headers, error handling, CSRF for state-changing requests. */
function api_start(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    set_exception_handler(function (Throwable $t) {
        if ($t instanceof ApiError) {
            json_out(['error' => $t->getMessage()] + $t->extra, $t->status);
        }
        error_log('[ExamPro] ' . get_class($t) . ': ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
        $msg = 'Something went wrong on the server. Please try again.';
        if ($t instanceof PDOException) $msg = 'Database error. Check that MySQL is running and the database is imported.';
        $out = ['error' => $msg];
        if (app_cfg('debug')) $out['debug'] = $t->getMessage();
        json_out($out, 500);
    });
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        csrf_check();
    }
}

function require_method(string $m): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $m) fail('Method not allowed.', 405);
}

/** JSON request body (or form fields) as an array. */
function body(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 2 * 1024 * 1024) fail('Request too large.', 413);
        $d = json_decode($raw ?: '[]', true);
        return $b = is_array($d) ? $d : [];
    }
    return $b = $_POST;
}

// ------------------------------------------------------------------ validation helpers
function str_in(array $src, string $key, int $max, bool $required = true, string $label = ''): string
{
    $label = $label ?: $key;
    $v = $src[$key] ?? '';
    if (!is_scalar($v)) $v = '';
    $v = trim((string) $v);
    if ($required && $v === '') fail("$label is required.");
    if (mb_strlen($v) > $max) fail("$label must be at most $max characters.");
    return $v;
}

function int_in(array $src, string $key, int $min, int $max, string $label = ''): int
{
    $label = $label ?: $key;
    $v = $src[$key] ?? null;
    if (is_string($v)) $v = trim($v);
    if (is_float($v) && floor($v) == $v) $v = (int) $v;
    if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d+$/', $v))) fail("$label must be a whole number.");
    $i = (int) $v;
    if ($i < $min || $i > $max) fail("$label must be between $min and $max.");
    return $i;
}

function valid_email(string $e): bool
{
    return (bool) filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 190;
}

function pos_id($v, string $what = 'id'): int
{
    if (!is_numeric($v) || (int) $v < 1 || (string) (int) $v !== trim((string) $v)) fail("Invalid $what.", 400);
    return (int) $v;
}
