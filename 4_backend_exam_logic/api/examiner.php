<?php
declare(strict_types=1);
require __DIR__ . '/../../3_backend_auth_users/includes/bootstrap.php';
require_once __DIR__ . '/../includes/spreadsheet.php';
api_start();
$me = require_api_role('examiner');
$meId = $me['id'];
$pdo = db();
$action = $_GET['action'] ?? '';
$b = body();

/** Ownership check: an examiner can only ever load their own exam. Another examiner's id looks like "not found". */
function owned_exam(PDO $pdo, $examId, int $examinerId): array
{
    $id = pos_id($examId, 'examination');
    $st = $pdo->prepare('SELECT * FROM exams WHERE id = ? AND examiner_id = ?');
    $st->execute([$id, $examinerId]);
    $e = $st->fetch();
    if (!$e) fail('Test not found.', 404);
    return $e;
}

function attempt_count(PDO $pdo, int $examId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ?');
    $st->execute([$examId]);
    return (int) $st->fetchColumn();
}

function exam_detail(PDO $pdo, int $examId): array
{
    $st = $pdo->prepare(EXAM_SELECT . ' WHERE e.id = ?');
    $st->execute([$examId]);
    return ['exam' => format_exam($st->fetch()), 'questions' => load_questions($pdo, $examId, true)];
}

function validate_settings(array $b): array
{
    $neg = !empty($b['negativeMarking']);
    $frac = 0.0;
    if ($neg) {
        $f = $b['negativeFraction'] ?? 0.25;
        if (!is_numeric($f) || (float) $f <= 0 || (float) $f > 1) fail('Negative marking must deduct between 0.01 and 1 times the question marks.');
        $frac = round((float) $f, 2);
    }
    $pass = $b['passPercentage'] ?? 40;
    if (!is_numeric($pass) || (float) $pass < 0 || (float) $pass > 100) fail('Pass percentage must be between 0 and 100.');
    return [
        'title' => str_in($b, 'title', 100, true, 'Test title'),
        'description' => str_in($b, 'description', 500, false, 'Description'),
        'duration' => int_in($b, 'duration', 1, 360, 'Duration'),
        'negative' => $neg ? 1 : 0, 'fraction' => $frac,
        'maxViolations' => int_in(['v' => $b['maxViolations'] ?? 1], 'v', 1, 20, 'Violation limit'),
        'pass' => round((float) $pass, 2),
        'showResults' => array_key_exists('showResults', $b) ? (!empty($b['showResults']) ? 1 : 0) : 1,
    ];
}

function validate_questions($qs): array
{
    if (!is_array($qs) || !count($qs)) fail('Add at least one valid question.');
    $max = (int) app_cfg('max_questions_per_exam', 1000);
    if (count($qs) > $max) fail("A test can contain at most $max questions.");
    $out = [];
    foreach (array_values($qs) as $i => $q) {
        $n = $i + 1;
        if (!is_array($q)) fail("Question $n is invalid.");
        $text = trim((string) ($q['question'] ?? ''));
        if ($text === '') fail("Question $n: question text is required.");
        if (mb_strlen($text) > 2000) fail("Question $n: text is too long (max 2000 characters).");
        $opts = $q['options'] ?? null;
        if (!is_array($opts) || count($opts) !== 4) fail("Question $n: provide exactly four options (A-D).");
        $opts = array_map(fn($o) => trim((string) $o), array_values($opts));
        foreach ($opts as $o) {
            if ($o === '') fail("Question $n: every option (A-D) needs text.");
            if (mb_strlen($o) > 500) fail("Question $n: an option is too long (max 500 characters).");
        }
        $ans = strtoupper(trim((string) ($q['answer'] ?? '')));
        if (!in_array($ans, ['A', 'B', 'C', 'D'], true)) fail("Question $n: choose the correct answer (A, B, C or D).");
        $marks = $q['marks'] ?? 1;
        if (!is_numeric($marks) || (float) $marks <= 0 || (float) $marks > 1000) fail("Question $n: marks must be a positive number.");
        $out[] = ['question' => $text, 'options' => $opts, 'answer' => $ans, 'marks' => round((float) $marks, 2)];
    }
    return $out;
}

function save_questions(PDO $pdo, int $examId, array $questions): void
{
    $pdo->prepare('DELETE FROM questions WHERE exam_id = ?')->execute([$examId]);   // options cascade
    $iq = $pdo->prepare('INSERT INTO questions (exam_id, position, question_text, marks) VALUES (?,?,?,?)');
    $io = $pdo->prepare('INSERT INTO options (question_id, label, option_text, is_correct) VALUES (?,?,?,?)');
    foreach ($questions as $i => $q) {
        $iq->execute([$examId, $i + 1, $q['question'], $q['marks']]);
        $qid = (int) $pdo->lastInsertId();
        foreach ($q['options'] as $j => $text) {
            $label = 'ABCD'[$j];
            $io->execute([$qid, $label, $text, $label === $q['answer'] ? 1 : 0]);
        }
    }
}

switch ($action) {
    // ------------------------------------------------------------------ exams
    case 'exams':
        json_out(exam_list($pdo, $meId));

    case 'exam':
        $e = owned_exam($pdo, $_GET['id'] ?? null, $meId);
        json_out(exam_detail($pdo, (int) $e['id']));

    case 'preview_upload': {   // original: POST /api/tests/preview-upload
        require_method('POST');
        $f = $_FILES['questionsFile'] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) fail('Choose an .xlsx or .csv file.');
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > (int) app_cfg('max_upload_bytes')) fail('That file is too large (maximum 10 MB).', 413);
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) fail('The upload failed. Please try again.');
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv', 'xls'], true)) fail('Choose an .xlsx or .csv file.');
        $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 4096);
        if ($ext === 'xlsx' && substr($head, 0, 2) !== 'PK') fail('Could not read this file. Check that it is a valid Excel or CSV file.');
        if ($ext === 'csv' && strpos($head, "\0") !== false) fail('Could not read this file. Check that it is a valid Excel or CSV file.');
        // The file is parsed straight from PHP's temp upload and never stored on disk or executed.
        $res = questions_from_rows(read_spreadsheet($f['tmp_name'], $ext));
        if ($res['errors']) fail('Please fix the spreadsheet rows and upload again.', 400, ['rowErrors' => $res['errors']]);
        if (!$res['questions']) fail('No valid questions found.');
        $total = array_sum(array_column($res['questions'], 'marks'));
        json_out(['fileName' => mb_substr(preg_replace('/[^\p{L}\p{N}\.\-_ ]/u', '', (string) $f['name']) ?? 'file', 0, 120),
                  'questions' => $res['questions'], 'totalMarks' => num($total)]);
    }

    case 'exam_save': {   // create or update (title/settings/questions) in one transaction
        require_method('POST');
        $id = !empty($b['id']) ? (int) $b['id'] : null;
        $wantStatus = ($b['status'] ?? '') === 'Published' ? 'Published' : 'Draft';
        $s = validate_settings($b);
        if ($id === null) {
            $questions = validate_questions($b['questions'] ?? []);
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO exams (examiner_id,title,description,duration_minutes,status,negative_marking,negative_fraction,max_violations,pass_percentage,show_results) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$meId, $s['title'], $s['description'], $s['duration'], $wantStatus, $s['negative'], $s['fraction'], $s['maxViolations'], $s['pass'], $s['showResults']]);
                $id = (int) $pdo->lastInsertId();
                save_questions($pdo, $id, $questions);
                $pdo->commit();
            } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
            json_out(exam_detail($pdo, $id), 201);
        }
        $e = owned_exam($pdo, $id, $meId);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM exams WHERE id = ? FOR UPDATE')->execute([$id]);
            if (attempt_count($pdo, $id) > 0) {
                // Students have already started: scoring-relevant data is frozen, only labels/visibility change.
                $pdo->prepare('UPDATE exams SET title = ?, description = ?, show_results = ? WHERE id = ?')->execute([$s['title'], $s['description'], $s['showResults'], $id]);
            } else {
                $questions = validate_questions($b['questions'] ?? []);
                $status = $e['status'];
                if (isset($b['status']) && in_array($b['status'], ['Draft', 'Published'], true)) $status = $b['status'];
                $pdo->prepare('UPDATE exams SET title=?, description=?, duration_minutes=?, status=?, negative_marking=?, negative_fraction=?, max_violations=?, pass_percentage=?, show_results=? WHERE id=?')
                    ->execute([$s['title'], $s['description'], $s['duration'], $status, $s['negative'], $s['fraction'], $s['maxViolations'], $s['pass'], $s['showResults'], $id]);
                save_questions($pdo, $id, $questions);
            }
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
        json_out(exam_detail($pdo, $id));
    }

    case 'exam_status': {
        require_method('POST');
        $e = owned_exam($pdo, $b['id'] ?? null, $meId);
        $to = (string) ($b['status'] ?? '');
        if (!in_array($to, ['Draft', 'Published', 'Closed'], true)) fail('Invalid status.');
        $from = $e['status'];
        $attempts = attempt_count($pdo, (int) $e['id']);
        if ($to === $from) json_out(['ok' => true, 'status' => $to]);
        if ($to === 'Published') {
            $q = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE exam_id = ?');
            $q->execute([$e['id']]);
            if ((int) $q->fetchColumn() < 1) fail('Add at least one question before publishing.');
        }
        if ($to === 'Draft' && $attempts > 0) fail('Students have already attempted this test, so it cannot go back to draft. Close it instead.', 409);
        if ($to === 'Closed' && $from === 'Draft') fail('Publish the test before closing it.', 409);
        $pdo->prepare('UPDATE exams SET status = ? WHERE id = ?')->execute([$to, $e['id']]);
        json_out(['ok' => true, 'status' => $to]);
    }

    case 'exam_delete': {
        require_method('POST');
        $e = owned_exam($pdo, $b['id'] ?? null, $meId);
        if (attempt_count($pdo, (int) $e['id']) > 0) fail('Students have already attempted this test, so it must be kept for the results record. Close it instead.', 409);
        $pdo->prepare('DELETE FROM exams WHERE id = ? AND examiner_id = ?')->execute([$e['id'], $meId]);
        json_out(['ok' => true]);
    }

    // ------------------------------------------------------------------ students & assignments
    case 'students': {
        $rows = $pdo->query('SELECT * FROM students ORDER BY full_name')->fetchAll();
        $as = $pdo->prepare('SELECT a.student_id, a.exam_id FROM exam_assignments a JOIN exams e ON e.id = a.exam_id WHERE e.examiner_id = ?');
        $as->execute([$meId]);
        $map = [];
        foreach ($as->fetchAll() as $a) $map[(int) $a['student_id']][] = (int) $a['exam_id'];
        json_out(array_map(fn($s) => ['id' => (int) $s['id'], 'fullName' => $s['full_name'], 'email' => $s['email'], 'studentId' => $s['student_code'],
            'course' => $s['course'], 'year' => $s['year_label'], 'status' => $s['status'], 'assignedTestIds' => $map[(int) $s['id']] ?? []], $rows));
    }

    case 'assign': {   // original: POST /api/assignments
        require_method('POST');
        $e = owned_exam($pdo, $b['testId'] ?? null, $meId);
        $ids = $b['studentIds'] ?? null;
        if (!is_array($ids) || !count($ids)) fail('Select at least one student.');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $v = $pdo->prepare("SELECT COUNT(*) FROM students WHERE status = 'Verified' AND id IN ($ph)");
        $v->execute($ids);
        if ((int) $v->fetchColumn() !== count($ids)) fail('Only verified students can be assigned tests.');
        $ins = $pdo->prepare('INSERT IGNORE INTO exam_assignments (exam_id, student_id, assigned_by) VALUES (?,?,?)');
        $added = 0;
        $pdo->beginTransaction();
        try {
            foreach ($ids as $sid) { $ins->execute([$e['id'], $sid, $meId]); $added += $ins->rowCount(); }
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
        json_out(['message' => "Test assigned to $added student(s).", 'added' => $added], 201);
    }

    case 'unassign': {
        require_method('POST');
        $e = owned_exam($pdo, $b['testId'] ?? null, $meId);
        $sid = pos_id($b['studentId'] ?? null, 'student');
        $a = $pdo->prepare('SELECT 1 FROM exam_attempts WHERE exam_id = ? AND student_id = ?');
        $a->execute([$e['id'], $sid]);
        if ($a->fetchColumn()) fail('This student has already started the test, so the assignment cannot be removed.', 409);
        $pdo->prepare('DELETE FROM exam_assignments WHERE exam_id = ? AND student_id = ?')->execute([$e['id'], $sid]);
        json_out(['ok' => true]);
    }

    case 'assignments': {
        $e = owned_exam($pdo, $_GET['exam_id'] ?? null, $meId);
        $st = $pdo->prepare('SELECT s.id, s.full_name, s.student_code, t.status AS attempt_status FROM exam_assignments a JOIN students s ON s.id = a.student_id LEFT JOIN exam_attempts t ON t.exam_id = a.exam_id AND t.student_id = a.student_id WHERE a.exam_id = ? ORDER BY s.full_name');
        $st->execute([$e['id']]);
        json_out(array_map(fn($r) => ['id' => (int) $r['id'], 'fullName' => $r['full_name'], 'studentId' => $r['student_code'], 'attemptStatus' => $r['attempt_status']], $st->fetchAll()));
    }

    // ------------------------------------------------------------------ attempts / incidents / results (own only)
    case 'attempts': {
        $e = owned_exam($pdo, $_GET['exam_id'] ?? null, $meId);
        json_out(attempt_rows($pdo, (int) $e['id']));
    }
    case 'incidents':
        json_out(incident_rows($pdo, $_GET, $meId));
    case 'results':
        json_out(results_rows($pdo, $_GET, $meId));
    case 'attempt_review': {
        $aid = pos_id($_GET['attempt_id'] ?? null, 'attempt');
        $st = $pdo->prepare('SELECT t.*, e.title, s.full_name FROM exam_attempts t JOIN exams e ON e.id = t.exam_id JOIN students s ON s.id = t.student_id WHERE t.id = ? AND e.examiner_id = ?');
        $st->execute([$aid, $meId]);
        $t = $st->fetch();
        if (!$t) fail('Attempt not found.', 404);
        finalize_expired_attempts($pdo, (int) $t['exam_id']);
        $t2 = $pdo->prepare('SELECT status FROM exam_attempts WHERE id = ?');
        $t2->execute([$aid]);
        if ($t2->fetchColumn() !== 'submitted') fail('This attempt is still in progress.', 409);
        json_out(['studentName' => $t['full_name'], 'examTitle' => $t['title'], 'result' => fetch_result_by_attempt($pdo, $aid),
                  'review' => build_review($pdo, $aid, (int) $t['exam_id']), 'incidents' => incident_rows($pdo, ['attempt_id' => $aid], $meId)]);
    }
    case 'export': {
        $fmt = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        send_export($pdo, $_GET, $meId, $fmt);
    }
    default:
        fail('Unknown action.', 404);
}
