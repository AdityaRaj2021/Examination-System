<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
api_start();
$admin = require_api_role('admin');
$pdo = db();
$action = $_GET['action'] ?? '';
$b = body();

function student_json(array $s): array
{
    return ['id' => (int) $s['id'], 'fullName' => $s['full_name'], 'email' => $s['email'], 'studentId' => $s['student_code'],
            'course' => $s['course'], 'year' => $s['year_label'], 'status' => $s['status'], 'createdAt' => iso($s['created_at'])];
}

switch ($action) {
    // ------------------------------------------------------------------ own password
    case 'admin_password': {
        require_method('POST');
        $cur = (string) ($b['currentPassword'] ?? '');
        $new = (string) ($b['newPassword'] ?? '');
        $st = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $st->execute([$admin['id']]);
        $hash = $st->fetchColumn();
        if (!$hash || !password_verify($cur, (string) $hash)) fail('Current password is incorrect.', 403);
        if (strlen($new) < 8) fail('New password must be at least 8 characters.');
        if (strlen($new) > 72) fail('New password must be at most 72 characters.');
        if (hash_equals($cur, $new)) fail('New password must be different from the current password.');
        $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        session_regenerate_id(true);
        json_out(['ok' => true, 'csrf' => csrf_token()]);
    }

    // ------------------------------------------------------------------ overview
    case 'overview': {
        $c = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
        $pending = $pdo->query("SELECT * FROM students WHERE status = 'Pending verification' ORDER BY created_at DESC LIMIT 5")->fetchAll();
        json_out([
            'examiners' => $c('SELECT COUNT(*) FROM examiners'),
            'students' => $c('SELECT COUNT(*) FROM students'),
            'pending' => $c("SELECT COUNT(*) FROM students WHERE status = 'Pending verification'"),
            'tests' => $c('SELECT COUNT(*) FROM exams'),
            'results' => $c('SELECT COUNT(*) FROM results'),
            'inProgress' => $c("SELECT COUNT(*) FROM exam_attempts WHERE status = 'in_progress'"),
            'pendingStudents' => array_map('student_json', $pending),
        ]);
    }

    // ------------------------------------------------------------------ examiners
    case 'examiners': {
        $rows = $pdo->query("SELECT x.*, (SELECT COUNT(*) FROM exams e WHERE e.examiner_id = x.id) AS exam_count FROM examiners x ORDER BY x.created_at DESC, x.id DESC")->fetchAll();
        json_out(array_map(fn($x) => ['id' => (int) $x['id'], 'fullName' => $x['full_name'], 'email' => $x['email'], 'status' => $x['status'],
            'createdAt' => iso($x['created_at']), 'lastLoginAt' => iso($x['last_login_at']), 'examCount' => (int) $x['exam_count']], $rows));
    }
    case 'examiner_create': {
        require_method('POST');
        $name = str_in($b, 'fullName', 120, false, 'Name');
        $email = strtolower(str_in($b, 'email', 190, false, 'Email'));
        $pass = (string) ($b['password'] ?? '');
        if ($name === '' || $email === '' || $pass === '') fail('Name, email, and temporary password are required.');
        if (!valid_email($email)) fail('Enter a valid email address.');
        if (strlen($pass) < 6) fail('Temporary password must be at least 6 characters.');
        if (strlen($pass) > 72) fail('Password must be at most 72 characters.');
        $dup = $pdo->prepare('SELECT 1 FROM examiners WHERE email = ?');
        $dup->execute([$email]);
        if ($dup->fetchColumn()) fail('An examiner account with this email already exists.', 409);
        try {
            $pdo->prepare('INSERT INTO examiners (full_name,email,password_hash) VALUES (?,?,?)')->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') fail('An examiner account with this email already exists.', 409);
            throw $ex;
        }
        json_out(['ok' => true, 'examiner' => ['id' => (int) $pdo->lastInsertId(), 'fullName' => $name, 'email' => $email, 'status' => 'Active']], 201);
    }
    case 'examiner_update': {   // enable/disable and/or reset password
        require_method('POST');
        $id = pos_id($b['id'] ?? null, 'examiner');
        $st = $pdo->prepare('SELECT id FROM examiners WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetchColumn()) fail('Examiner not found.', 404);
        if (isset($b['status'])) {
            if (!in_array($b['status'], ['Active', 'Disabled'], true)) fail('Invalid status.');
            $pdo->prepare('UPDATE examiners SET status = ? WHERE id = ?')->execute([$b['status'], $id]);
        }
        if (isset($b['password']) && $b['password'] !== '') {
            $p = (string) $b['password'];
            if (strlen($p) < 6 || strlen($p) > 72) fail('Password must be 6-72 characters.');
            $pdo->prepare('UPDATE examiners SET password_hash = ? WHERE id = ?')->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
        }
        json_out(['ok' => true]);
    }
    case 'examiner_delete': {
        require_method('POST');
        $id = pos_id($b['id'] ?? null, 'examiner');
        $n = $pdo->prepare('SELECT COUNT(*) FROM exams WHERE examiner_id = ?');
        $n->execute([$id]);
        if ((int) $n->fetchColumn() > 0) fail('This examiner owns examinations. Disable the account instead of deleting it.', 409);
        $pdo->prepare('DELETE FROM examiners WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
    }

    // ------------------------------------------------------------------ students
    case 'students': {
        $rows = $pdo->query('SELECT * FROM students ORDER BY created_at DESC, id DESC')->fetchAll();
        json_out(array_map('student_json', $rows));
    }
    case 'student_status': {   // original: PATCH /api/students/:id/verify
        require_method('POST');
        $id = pos_id($b['id'] ?? null, 'student');
        $status = (string) ($b['status'] ?? '');
        if (!in_array($status, ['Verified', 'Rejected', 'Pending verification'], true)) fail('Invalid status.');
        $st = $pdo->prepare('UPDATE students SET status = ?, reviewed_at = ? WHERE id = ?');
        $st->execute([$status, utc_now(), $id]);
        $q = $pdo->prepare('SELECT * FROM students WHERE id = ?');
        $q->execute([$id]);
        $s = $q->fetch();
        if (!$s) fail('Student not found.', 404);
        json_out(student_json($s));
    }
    case 'student_password': {
        require_method('POST');
        $id = pos_id($b['id'] ?? null, 'student');
        $p = (string) ($b['password'] ?? '');
        if (strlen($p) < 6 || strlen($p) > 72) fail('Password must be 6-72 characters.');
        $st = $pdo->prepare('UPDATE students SET password_hash = ? WHERE id = ?');
        $st->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
        json_out(['ok' => true]);
    }

    // ------------------------------------------------------------------ examinations / monitoring
    case 'exams': {
        $fe = !empty($_GET['examiner_id']) ? (int) $_GET['examiner_id'] : null;
        json_out(exam_list($pdo, null, $fe));
    }
    case 'exam_monitor': {
        $id = pos_id($_GET['id'] ?? null, 'exam');
        $st = $pdo->prepare(EXAM_SELECT . ' WHERE e.id = ?');
        $st->execute([$id]);
        $e = $st->fetch();
        if (!$e) fail('Examination not found.', 404);
        json_out(['exam' => format_exam($e), 'attempts' => attempt_rows($pdo, $id),
                  'incidents' => incident_rows($pdo, ['exam_id' => $id], null)]);
    }

    // ------------------------------------------------------------------ results
    case 'results':
        json_out(results_rows($pdo, $_GET, null));
    case 'incidents':
        json_out(incident_rows($pdo, $_GET, null));
    case 'export': {
        $fmt = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        send_export($pdo, $_GET, null, $fmt);
    }
    default:
        fail('Unknown action.', 404);
}
