<?php
// One-time import of the old Node.js prototype's data.json into MySQL.
//
//   C:\xampp\php\php.exe database\migrate_json.php "C:\path\to\data.json" [--dry-run]
//
// Safe to run twice: every imported record carries its old id in `legacy_id`, so nothing is duplicated.
// Writes a report to database/migration_report.txt (and database/migration_credentials.csv when
// temporary passwords had to be generated).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this script from the command line.\n"); }

define('EXAMPRO_ROOT', dirname(__DIR__, 2));
require EXAMPRO_ROOT . '/3_backend_auth_users/includes/paths.php';
require EXAMPRO_ROOT . '/' . DIR_BE_AUTH . '/includes/functions.php';
$GLOBALS['EXAMPRO_CFG'] = require EXAMPRO_ROOT . '/' . DIR_DB_DEPLOY . '/config/app.php';
date_default_timezone_set('UTC');

$file = $argv[1] ?? '';
$dry = in_array('--dry-run', $argv, true);
if ($file === '' || !is_file($file)) exit("Usage: php migrate_json.php path/to/data.json [--dry-run]\n");
$data = json_decode((string) file_get_contents($file), true);
if (!is_array($data)) exit("data.json is not valid JSON.\n");

$pdo = db();
$report = []; $creds = [['Role', 'Email', 'Temporary password']];
$log = function (string $m) use (&$report) { $report[] = $m; echo $m . "\n"; };

/** Old passwords were scrypt hashes ("scrypt$salt$hash") which PHP cannot verify: issue a temporary password. */
function new_password($old, array &$creds, string $role, string $email): string
{
    if (is_string($old) && $old !== '' && !str_starts_with($old, 'scrypt$')) return password_hash($old, PASSWORD_DEFAULT);   // legacy plain text
    $tmp = substr(bin2hex(random_bytes(6)), 0, 10) . 'A1';
    $creds[] = [$role, $email, $tmp];
    return password_hash($tmp, PASSWORD_DEFAULT);
}
$dt = fn($v) => $v ? gmdate('Y-m-d H:i:s', is_numeric($v) ? (int) ($v > 1e12 ? $v / 1000 : $v) : strtotime((string) $v)) : utc_now();

$pdo->beginTransaction();
try {
    $exId = []; $stId = []; $testId = []; $testMeta = [];
    // ---- examiners
    foreach ($data['examiners'] ?? [] as $x) {
        $email = strtolower(trim((string) ($x['email'] ?? '')));
        $q = $pdo->prepare('SELECT id FROM examiners WHERE legacy_id = ? OR email = ?'); $q->execute([$x['id'] ?? '', $email]);
        if ($id = $q->fetchColumn()) { $exId[$x['id']] = (int) $id; $log("examiner exists: $email"); continue; }
        $pdo->prepare('INSERT INTO examiners (full_name,email,password_hash,legacy_id,created_at) VALUES (?,?,?,?,?)')
            ->execute([$x['fullName'] ?? $email, $email, new_password($x['password'] ?? '', $creds, 'examiner', $email), $x['id'] ?? null, $dt($x['createdAt'] ?? null)]);
        $exId[$x['id']] = (int) $pdo->lastInsertId(); $log("examiner imported: $email");
    }
    // placeholder owner for tests that had no examiner recorded
    $legacyOwner = function () use ($pdo, &$exId, &$creds): int {
        if (isset($exId['__legacy'])) return $exId['__legacy'];
        $q = $pdo->prepare("SELECT id FROM examiners WHERE email = 'legacy-import@example.invalid'"); $q->execute();
        if (!($id = $q->fetchColumn())) {
            $pdo->prepare("INSERT INTO examiners (full_name,email,password_hash,status) VALUES ('Legacy import','legacy-import@example.invalid',?, 'Disabled')")->execute([password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]);
            $id = $pdo->lastInsertId();
        }
        return $exId['__legacy'] = (int) $id;
    };
    // ---- students
    foreach ($data['students'] ?? [] as $s) {
        $email = strtolower(trim((string) ($s['email'] ?? '')));
        $q = $pdo->prepare('SELECT id FROM students WHERE legacy_id = ? OR email = ?'); $q->execute([$s['id'] ?? '', $email]);
        if ($id = $q->fetchColumn()) { $stId[$s['id']] = (int) $id; $log("student exists: $email"); continue; }
        $code = trim((string) ($s['studentId'] ?? '')) ?: ('LEGACY-' . substr((string) ($s['id'] ?? uniqid()), 0, 8));
        $c = $pdo->prepare('SELECT 1 FROM students WHERE student_code = ?'); $c->execute([$code]);
        if ($c->fetchColumn()) { $new = $code . '-' . substr((string) ($s['id'] ?? uniqid()), 0, 4); $log("WARNING duplicate Student ID '$code' for $email -> stored as '$new'"); $code = $new; }
        $status = in_array($s['status'] ?? '', ['Verified', 'Rejected', 'Pending verification'], true) ? $s['status'] : 'Pending verification';
        $pdo->prepare('INSERT INTO students (full_name,email,student_code,course,year_label,password_hash,status,legacy_id,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$s['fullName'] ?? $email, $email, $code, $s['course'] ?? '-', $s['year'] ?? 'Other', new_password($s['password'] ?? '', $creds, 'student', $email), $status, $s['id'] ?? null, $dt($s['createdAt'] ?? null)]);
        $stId[$s['id']] = (int) $pdo->lastInsertId(); $log("student imported: $email");
    }
    // ---- tests / questions / options
    foreach ($data['tests'] ?? [] as $t) {
        $q = $pdo->prepare('SELECT id FROM exams WHERE legacy_id = ?'); $q->execute([$t['id'] ?? '']);
        if ($id = $q->fetchColumn()) { $testId[$t['id']] = (int) $id; $log("exam exists: {$t['title']}"); continue; }
        $owner = isset($exId[$t['createdByExaminerId'] ?? '']) ? $exId[$t['createdByExaminerId']] : $legacyOwner();
        if (!isset($exId[$t['createdByExaminerId'] ?? ''])) $log("NOTE exam '{$t['title']}' had no known examiner -> owned by 'Legacy import'");
        $pdo->prepare("INSERT INTO exams (examiner_id,title,description,duration_minutes,status,legacy_id,created_at) VALUES (?,?,?,?,?,?,?)")
            ->execute([$owner, mb_substr((string) $t['title'], 0, 100), mb_substr((string) ($t['description'] ?? ''), 0, 500), max(1, (int) ($t['duration'] ?? 30)), 'Published', $t['id'] ?? null, $dt($t['createdAt'] ?? null)]);
        $eid = (int) $pdo->lastInsertId(); $testId[$t['id']] = $eid; $testMeta[$eid] = ['n' => count($t['questions'] ?? []), 'max' => 0.0, 'owner' => $owner];
        foreach (array_values($t['questions'] ?? []) as $i => $qq) {
            $pdo->prepare('INSERT INTO questions (exam_id,position,question_text,marks) VALUES (?,?,?,?)')->execute([$eid, $i + 1, $qq['question'], (float) ($qq['marks'] ?? 1)]);
            $qid = (int) $pdo->lastInsertId(); $testMeta[$eid]['max'] += (float) ($qq['marks'] ?? 1);
            foreach (array_values($qq['options'] ?? []) as $j => $text) {
                $L = 'ABCD'[$j] ?? null; if (!$L) continue;
                $pdo->prepare('INSERT INTO options (question_id,label,option_text,is_correct) VALUES (?,?,?,?)')->execute([$qid, $L, $text, strtoupper((string) ($qq['answer'] ?? '')) === $L ? 1 : 0]);
            }
        }
        $log("exam imported: {$t['title']} (" . count($t['questions'] ?? []) . ' questions)');
    }
    // ---- assignments
    foreach ($data['assignments'] ?? [] as $a) {
        if (!isset($testId[$a['testId'] ?? '']) || !isset($stId[$a['studentId'] ?? ''])) { $log('SKIPPED assignment (unknown test or student): ' . json_encode($a)); continue; }
        $pdo->prepare('INSERT IGNORE INTO exam_assignments (exam_id,student_id,assigned_by,assigned_at) VALUES (?,?,?,?)')->execute([$testId[$a['testId']], $stId[$a['studentId']], null, $dt($a['assignedAt'] ?? null)]);
    }
    // ---- results (+ incidents) -> one submitted attempt + result row each
    foreach ($data['results'] ?? [] as $r) {
        $e = $testId[$r['testId'] ?? ''] ?? null; $s = $stId[$r['studentId'] ?? ''] ?? null;
        if (!$e || !$s) { $log('NOT MIGRATED result ' . ($r['id'] ?? '?') . ' (' . ($r['studentName'] ?? 'unknown') . ' / ' . ($r['testTitle'] ?? '?') . '): no matching registered student or test (e.g. a demo/preview attempt).'); continue; }
        $dup = $pdo->prepare('SELECT 1 FROM exam_attempts WHERE exam_id = ? AND student_id = ?'); $dup->execute([$e, $s]);
        if ($dup->fetchColumn()) { $log("result already present for exam $e / student $s"); continue; }
        $when = $dt($r['submittedAt'] ?? null);
        $reason = in_array($r['submissionReason'] ?? '', ['time_limit', 'fullscreen_exit'], true) ? $r['submissionReason'] : 'migrated';
        $pdo->prepare("INSERT INTO exam_attempts (exam_id,student_id,started_at,deadline_at,submitted_at,status,submission_reason) VALUES (?,?,?,?,?,'submitted',?)")->execute([$e, $s, $when, $when, $when, $reason]);
        $aid = (int) $pdo->lastInsertId();
        $max = (float) ($r['totalMarks'] ?? $testMeta[$e]['max'] ?? 0); $score = (float) ($r['score'] ?? 0);
        $pct = isset($r['percentage']) ? (float) $r['percentage'] : ($max > 0 ? round($score / $max * 100, 2) : 0);
        $owner = $testMeta[$e]['owner'] ?? (int) $pdo->query("SELECT examiner_id FROM exams WHERE id = $e")->fetchColumn();
        $n = $testMeta[$e]['n'] ?? (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE exam_id = $e")->fetchColumn();
        $pdo->prepare('INSERT INTO results (attempt_id,exam_id,student_id,examiner_id,total_questions,max_marks,obtained_marks,percentage,result_status,submission_reason,submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$aid, $e, $s, $owner, $n, $max, $score, $pct, $pct >= 40 ? 'Pass' : 'Fail', $reason, $when]);
        foreach ($r['incidents'] ?? [] as $i) {
            $pdo->prepare('INSERT INTO exam_incidents (attempt_id,exam_id,student_id,incident_type,description,counted,occurred_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$aid, $e, $s, mb_substr((string) ($i['type'] ?? 'student_report'), 0, 40), mb_substr((string) ($i['reason'] ?? ''), 0, 500), ($i['type'] ?? '') === 'fullscreen_exit' ? 1 : 0, $dt($i['occurredAt'] ?? null)]);
        }
        $log("result imported: " . ($r['studentName'] ?? $s) . ' / ' . ($r['testTitle'] ?? $e) . ' (correct/incorrect counts and per-question answers were never stored in data.json)');
    }
    $dry ? $pdo->rollBack() : $pdo->commit();
} catch (Throwable $t) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    exit('Migration failed and was rolled back: ' . $t->getMessage() . "\n");
}
$log($dry ? 'DRY RUN - nothing was written.' : 'Migration committed.');
$log('Not migrated by design: browser tokens/sessions; per-question answers of old results (never stored); the old "demo attempt" results without a registered student.');
if (!$dry) {
    file_put_contents(__DIR__ . '/migration_report.txt', implode("\n", $report) . "\n");
    if (count($creds) > 1) {
        $h = fopen(__DIR__ . '/migration_credentials.csv', 'w'); foreach ($creds as $c) fputcsv($h, $c, ',', '"', ''); fclose($h);
        echo "Temporary passwords written to database/migration_credentials.csv - hand them out, then DELETE that file.\n";
    }
}
