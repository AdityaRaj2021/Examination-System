<?php
declare(strict_types=1);
// Shared read-side queries for Admin (all data) and Examiner (own data only).
// The $scopeExaminer argument is ALWAYS taken from the server session for examiners, never from the request.

function format_exam(array $e): array
{
    return [
        'id' => (int) $e['id'], 'title' => $e['title'], 'description' => $e['description'],
        'duration' => (int) $e['duration_minutes'], 'status' => $e['status'],
        'questionCount' => (int) ($e['qn'] ?? 0), 'totalMarks' => num($e['tm'] ?? 0),
        'assigned' => (int) ($e['assigned'] ?? 0), 'attempts' => (int) ($e['attempts'] ?? 0), 'inProgress' => (int) ($e['live'] ?? 0),
        'negativeMarking' => (int) $e['negative_marking'] === 1, 'negativeFraction' => num($e['negative_fraction']),
        'maxViolations' => (int) $e['max_violations'], 'passPercentage' => num($e['pass_percentage']),
        'showResults' => (int) $e['show_results'] === 1,
        'examinerId' => (int) $e['examiner_id'], 'examinerName' => $e['examiner_name'] ?? '', 'examinerEmail' => $e['examiner_email'] ?? '',
        'createdAt' => iso($e['created_at']),
    ];
}

const EXAM_SELECT = "SELECT e.*, x.full_name AS examiner_name, x.email AS examiner_email,
        (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS qn,
        (SELECT COALESCE(SUM(q.marks),0) FROM questions q WHERE q.exam_id = e.id) AS tm,
        (SELECT COUNT(*) FROM exam_assignments a WHERE a.exam_id = e.id) AS assigned,
        (SELECT COUNT(*) FROM exam_attempts t WHERE t.exam_id = e.id) AS attempts,
        (SELECT COUNT(*) FROM exam_attempts t WHERE t.exam_id = e.id AND t.status = 'in_progress') AS live
   FROM exams e JOIN examiners x ON x.id = e.examiner_id";

function exam_list(PDO $pdo, ?int $scopeExaminer, ?int $filterExaminer = null): array
{
    $sql = EXAM_SELECT; $args = []; $w = [];
    if ($scopeExaminer !== null) { $w[] = 'e.examiner_id = ?'; $args[] = $scopeExaminer; }
    elseif ($filterExaminer !== null) { $w[] = 'e.examiner_id = ?'; $args[] = $filterExaminer; }
    if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
    $st = $pdo->prepare($sql . ' ORDER BY e.created_at DESC, e.id DESC');
    $st->execute($args);
    return array_map('format_exam', $st->fetchAll());
}

/** Result filters: exam_id, student_id, q (+ examiner_id for admins). */
function results_where(array $f, ?int $scopeExaminer): array
{
    $w = []; $a = [];
    if ($scopeExaminer !== null) { $w[] = 'r.examiner_id = ?'; $a[] = $scopeExaminer; }
    elseif (!empty($f['examiner_id'])) { $w[] = 'r.examiner_id = ?'; $a[] = (int) $f['examiner_id']; }
    if (!empty($f['exam_id']))    { $w[] = 'r.exam_id = ?';    $a[] = (int) $f['exam_id']; }
    if (!empty($f['student_id'])) { $w[] = 'r.student_id = ?'; $a[] = (int) $f['student_id']; }
    if (isset($f['q']) && trim((string) $f['q']) !== '') {
        $like = '%' . trim((string) $f['q']) . '%';
        $w[] = '(s.full_name LIKE ? OR s.student_code LIKE ? OR e.title LIKE ?)';
        array_push($a, $like, $like, $like);
    }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $a];
}

function results_rows(PDO $pdo, array $f, ?int $scopeExaminer): array
{
    finalize_expired_attempts($pdo, null, null);
    [$where, $args] = results_where($f, $scopeExaminer);
    $st = $pdo->prepare(
        "SELECT r.*, s.full_name AS student_name, s.student_code, s.email AS student_email, e.title AS exam_title,
                x.full_name AS examiner_name, x.email AS examiner_email,
                (SELECT COUNT(*) FROM exam_incidents i WHERE i.attempt_id = r.attempt_id AND i.counted = 1) AS violations,
                (SELECT COUNT(*) FROM exam_incidents i WHERE i.attempt_id = r.attempt_id) AS incident_count
           FROM results r
           JOIN students s ON s.id = r.student_id
           JOIN exams e ON e.id = r.exam_id
           JOIN examiners x ON x.id = r.examiner_id
           $where ORDER BY r.submitted_at DESC, r.id DESC LIMIT 5000"
    );
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = format_result($r) + [
            'studentId' => (int) $r['student_id'], 'studentName' => $r['student_name'], 'studentCode' => $r['student_code'],
            'studentEmail' => $r['student_email'], 'examTitle' => $r['exam_title'],
            'examinerId' => (int) $r['examiner_id'], 'examinerName' => $r['examiner_name'], 'examinerEmail' => $r['examiner_email'],
            'incidentCount' => (int) $r['incident_count'], 'violations' => (int) $r['violations'],
        ];
    }
    return $out;
}

function incident_rows(PDO $pdo, array $f, ?int $scopeExaminer): array
{
    $w = []; $a = [];
    if ($scopeExaminer !== null) { $w[] = 'e.examiner_id = ?'; $a[] = $scopeExaminer; }
    elseif (!empty($f['examiner_id'])) { $w[] = 'e.examiner_id = ?'; $a[] = (int) $f['examiner_id']; }
    if (!empty($f['exam_id']))    { $w[] = 'i.exam_id = ?';    $a[] = (int) $f['exam_id']; }
    if (!empty($f['student_id'])) { $w[] = 'i.student_id = ?'; $a[] = (int) $f['student_id']; }
    if (!empty($f['attempt_id'])) { $w[] = 'i.attempt_id = ?'; $a[] = (int) $f['attempt_id']; }
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $st = $pdo->prepare(
        "SELECT i.*, s.full_name AS student_name, s.student_code, e.title AS exam_title, x.full_name AS examiner_name
           FROM exam_incidents i
           JOIN exams e ON e.id = i.exam_id
           JOIN students s ON s.id = i.student_id
           JOIN examiners x ON x.id = e.examiner_id
           $where ORDER BY i.occurred_at DESC, i.id DESC LIMIT 5000"
    );
    $st->execute($a);
    return array_map(fn($i) => [
        'id' => (int) $i['id'], 'attemptId' => (int) $i['attempt_id'], 'examId' => (int) $i['exam_id'], 'studentId' => (int) $i['student_id'],
        'studentName' => $i['student_name'], 'studentCode' => $i['student_code'], 'examTitle' => $i['exam_title'], 'examinerName' => $i['examiner_name'],
        'type' => $i['incident_type'], 'typeLabel' => INCIDENT_LABELS[$i['incident_type']] ?? $i['incident_type'],
        'reason' => $i['description'], 'counted' => (int) $i['counted'] === 1, 'occurredAt' => iso($i['occurred_at']),
    ], $st->fetchAll());
}

/** Attempts (any status) for one exam; the caller must already have verified access to the exam. */
function attempt_rows(PDO $pdo, int $examId): array
{
    finalize_expired_attempts($pdo, $examId);
    $st = $pdo->prepare(
        "SELECT t.*, s.full_name AS student_name, s.student_code,
                r.obtained_marks, r.max_marks, r.percentage, r.result_status,
                (SELECT COUNT(*) FROM exam_incidents i WHERE i.attempt_id = t.id) AS incident_count
           FROM exam_attempts t
           JOIN students s ON s.id = t.student_id
           LEFT JOIN results r ON r.attempt_id = t.id
          WHERE t.exam_id = ? ORDER BY t.started_at DESC"
    );
    $st->execute([$examId]);
    return array_map(fn($t) => [
        'attemptId' => (int) $t['id'], 'studentId' => (int) $t['student_id'], 'studentName' => $t['student_name'], 'studentCode' => $t['student_code'],
        'status' => $t['status'], 'startedAt' => iso($t['started_at']), 'deadlineAt' => iso($t['deadline_at']), 'submittedAt' => iso($t['submitted_at']),
        'submissionReason' => $t['submission_reason'], 'violations' => (int) $t['violation_count'], 'incidentCount' => (int) $t['incident_count'],
        'score' => $t['obtained_marks'] === null ? null : num($t['obtained_marks']), 'totalMarks' => $t['max_marks'] === null ? null : num($t['max_marks']),
        'percentage' => $t['percentage'] === null ? null : num($t['percentage']), 'resultStatus' => $t['result_status'],
    ], $st->fetchAll());
}

/** Builds export tables. Returns ['Results' => rows, 'Integrity Incidents' => rows] (first row = headers). */
function export_tables(PDO $pdo, array $f, ?int $scopeExaminer): array
{
    $results = results_rows($pdo, $f, $scopeExaminer);
    $incidents = incident_rows($pdo, $f, $scopeExaminer);
    $byAttempt = [];
    foreach ($incidents as $i) $byAttempt[$i['attemptId']][] = $i;

    $r = [['Student Name', 'Student ID', 'Student Email', 'Examination', 'Examiner', 'Examiner Email', 'Total Questions', 'Correct Answers',
           'Incorrect Answers', 'Unattempted', 'Maximum Marks', 'Obtained Marks', 'Percentage', 'Result', 'Submitted At (UTC)',
           'Submission Type', 'Incident Count', 'Incident Details']];
    foreach ($results as $x) {
        $details = implode(' | ', array_map(fn($i) => $i['typeLabel'] . ($i['reason'] !== '' ? ': ' . $i['reason'] : ''), $byAttempt[$x['attemptId']] ?? []));
        $r[] = [$x['studentName'], $x['studentCode'], $x['studentEmail'], $x['examTitle'], $x['examinerName'], $x['examinerEmail'],
                $x['totalQuestions'], $x['correct'] ?? '', $x['incorrect'] ?? '', $x['unattempted'] ?? '', $x['totalMarks'], $x['score'], $x['percentage'],
                $x['status'], str_replace(['T', 'Z'], [' ', ''], (string) $x['submittedAt']), SUBMISSION_LABELS[$x['submissionReason']] ?? $x['submissionReason'],
                $x['incidentCount'], $details];
    }
    $i = [['Student Name', 'Student ID', 'Examination', 'Examiner', 'Incident Type', 'Description', 'Counts Toward Limit', 'Occurred At (UTC)', 'Attempt ID']];
    foreach ($incidents as $x) {
        $i[] = [$x['studentName'], $x['studentCode'], $x['examTitle'], $x['examinerName'], $x['typeLabel'], $x['reason'],
                $x['counted'] ? 'Yes' : 'No', str_replace(['T', 'Z'], [' ', ''], (string) $x['occurredAt']), $x['attemptId']];
    }
    return ['Results' => $r, 'Integrity Incidents' => $i];
}

function send_export(PDO $pdo, array $f, ?int $scopeExaminer, string $format): void
{
    $t = export_tables($pdo, $f, $scopeExaminer);
    $stamp = gmdate('Ymd-His');
    if ($format === 'csv') send_csv($t['Results'], "exampro-results-$stamp.csv");
    send_xlsx($t, "exampro-results-$stamp.xlsx");
}
