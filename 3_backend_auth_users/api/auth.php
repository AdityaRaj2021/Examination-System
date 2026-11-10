<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
api_start();
require_method('POST');
$pdo = db();
$action = $_GET['action'] ?? '';
$b = body();

function login_ok(string $role, array $session, array $publicUser): void
{
    auth_login($role, $session);
    json_out(['ok' => true, 'csrf' => csrf_token(), 'user' => $publicUser]);
}

switch ($action) {
    case 'admin_login': {
        login_throttle_check();
        $u = str_in($b, 'username', 60, true, 'Username');
        $p = (string) ($b['password'] ?? '');
        $st = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
        $st->execute([$u]);
        $a = $st->fetch();
        if (!verify_password_or_dummy($p, $a['password_hash'] ?? null)) { login_throttle_fail(); fail('Incorrect username or password.', 401); }
        $pdo->prepare('UPDATE admins SET last_login_at = ? WHERE id = ?')->execute([utc_now(), $a['id']]);
        login_ok('admin', ['id' => (int) $a['id']], ['name' => $a['full_name']]);
    }
    case 'examiner_login': {
        login_throttle_check();
        $email = strtolower(str_in($b, 'email', 190, true, 'Email'));
        $p = (string) ($b['password'] ?? '');
        $st = $pdo->prepare('SELECT * FROM examiners WHERE email = ?');
        $st->execute([$email]);
        $x = $st->fetch();
        if (!verify_password_or_dummy($p, $x['password_hash'] ?? null)) {
            login_throttle_fail();
            fail('Incorrect email or password. Ask the administrator to create your account if you do not have one.', 401);
        }
        if ($x['status'] !== 'Active') fail('This examiner account has been disabled. Contact the administrator.', 403);
        $pdo->prepare('UPDATE examiners SET last_login_at = ? WHERE id = ?')->execute([utc_now(), $x['id']]);
        login_ok('examiner', ['id' => (int) $x['id']], ['id' => (int) $x['id'], 'fullName' => $x['full_name'], 'email' => $x['email']]);
    }
    case 'student_login': {
        login_throttle_check();
        $email = strtolower(str_in($b, 'email', 190, true, 'Email'));
        $p = (string) ($b['password'] ?? '');
        $st = $pdo->prepare('SELECT * FROM students WHERE email = ?');
        $st->execute([$email]);
        $s = $st->fetch();
        if (!verify_password_or_dummy($p, $s['password_hash'] ?? null)) { login_throttle_fail(); fail('Incorrect email or password.', 401); }
        if ($s['status'] === 'Pending verification') fail('Your profile is awaiting administrator verification.', 403);
        if ($s['status'] !== 'Verified') fail('Your profile was not approved. Please contact the administrator.', 403);
        $pdo->prepare('UPDATE students SET last_login_at = ? WHERE id = ?')->execute([utc_now(), $s['id']]);
        login_ok('student', ['id' => (int) $s['id']], [
            'id' => (int) $s['id'], 'fullName' => $s['full_name'], 'email' => $s['email'], 'studentId' => $s['student_code'],
            'course' => $s['course'], 'year' => $s['year_label'], 'status' => $s['status'],
        ]);
    }
    case 'student_signup': {
        login_throttle_check();
        $name = str_in($b, 'fullName', 120, true, 'Full name');
        $email = strtolower(str_in($b, 'email', 190, true, 'Email'));
        if (!valid_email($email)) fail('Enter a valid email address.');
        $pass = (string) ($b['password'] ?? '');
        if (strlen($pass) < 6) fail('Password must be at least 6 characters.');
        if (strlen($pass) > 72) fail('Password must be at most 72 characters.');
        $code = str_in($b, 'studentId', 60, true, 'Student ID');
        $course = str_in($b, 'course', 120, true, 'Course');
        $year = str_in($b, 'year', 30, true, 'Year');
        if (!in_array($year, ['1st year', '2nd year', '3rd year', '4th year', 'Other'], true)) fail('Choose a valid year.');
        try {
            $pdo->prepare('INSERT INTO students (full_name,email,student_code,course,year_label,password_hash) VALUES (?,?,?,?,?,?)')
                ->execute([$name, $email, $code, $course, $year, password_hash($pass, PASSWORD_DEFAULT)]);
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $dupEmail = $pdo->prepare('SELECT 1 FROM students WHERE email = ?');
                $dupEmail->execute([$email]);
                fail($dupEmail->fetchColumn() ? 'An account with this email already exists.' : 'This Student ID is already registered.', 409);
            }
            throw $ex;
        }
        login_throttle_fail();   // count sign-ups per IP too, to limit bulk registration
        json_out(['ok' => true, 'message' => 'Profile submitted. An administrator must verify it before you can sign in.'], 201);
    }
    case 'logout': {
        $role = (string) ($b['role'] ?? '');
        if (!in_array($role, ROLES, true)) fail('Invalid role.');
        auth_logout($role);
        json_out(['ok' => true]);
    }
    default:
        fail('Unknown action.', 404);
}
