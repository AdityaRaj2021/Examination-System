<?php
declare(strict_types=1);
require __DIR__ . '/../../3_backend_auth_users/includes/bootstrap.php';
api_start();
$me = require_api_role('student');
$sid = $me['id'];
$pdo = db();
$action = $_GET['action'] ?? '';
$b = body();
$GRACE = (int) app_cfg('deadline_grace_seconds', 5);

const COUNTED_INCIDENTS  = ['fullscreen_exit', 'tab_switch'];
const CLIENT_INCIDENTS   = ['fullscreen_exit', 'tab_switch', 'window_blur', 'student_report', 'fullscreen_unavailable'];

function remaining_seconds(array $att): int
{
    return max(0, strtotime($att['deadline_at'] . ' UTC') - time());
}

/** Loads one of THIS student's attempts (IDOR-safe). $lock = SELECT ... FOR UPDATE inside a transaction. */
function own_attempt(PDO $pdo, $attemptId, int $sid, bool $lock = false): array
{
    $id = pos_id($attemptId, 'attempt');
    $st = $pdo->prepare('SELECT * FROM exam_attempts WHERE id = ? AND student_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id, $sid]);
    $a = $st->fetch();
    if (!$a) fail('Attempt not found.', 404);
    return $a;
}

function exam_row(PDO $pdo, int $examId): array
{
    $st = $pdo->prepare('SELECT * FROM exams WHERE id = ?');
    $st->execute([$examId]);
    return $st->fetch();
}

/** Result payload for a student. Score and answer key are only revealed after submission AND if the examiner allows it. */
function student_result_payload(PDO $pdo, array $att): array
{
    $exam = exam_row($pdo, (int) $att['exam_id']);
    $res = fetch_result_by_attempt($pdo, (int) $att['id']);
    if ((int) $exam['show_results'] !== 1) {
        return ['submitted' => true, 'hidden' => true, 'examTitle' => $exam['title'], 'reason' => $res['submissionReason'],
                'message' => 'Your answers were submitted. Your examiner has not released the score yet.'];
    }
    return ['submitted' => true, 'hidden' => false, 'examTitle' => $exam['title'], 'reason' => $res['submissionReason'],
            'result' => $res, 'review' => build_review($pdo, (int) $att['id'], (int) $att['exam_id'])];
}

function saved_answers(PDO $pdo, int $attemptId): array
{
    $st = $pdo->prepare('SELECT a.question_id, o.label FROM submitted_answers a JOIN options o ON o.id = a.chosen_option_id WHERE a.attempt_id = ?');
    $st->execute([$attemptId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(string) $r['question_id']] = $r['label'];
    return $out;
}

function log_incident(PDO $pdo, array $att, string $type, string $desc, bool $counted, ?string $clientTime = null): void
{
    $pdo->prepare('INSERT INTO exam_incidents (attempt_id, exam_id, student_id, incident_type, description, counted, occurred_at, client_time) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$att['id'], $att['exam_id'], $att['student_id'], $type, mb_substr($desc, 0, 500), $counted ? 1 : 0, utc_now(), $clientTime !== null ? mb_substr($clientTime, 0, 40) : null]);
}

/** Saves one answer for an in-progress attempt. $qid must belong to the attempt's exam. */
function upsert_answer(PDO $pdo, array $att, int $qid, ?string $label): void
{
    $q = $pdo->prepare('SELECT 1 FROM questions WHERE id = ? AND exam_id = ?');
    $q->execute([$qid, $att['exam_id']]);
    if (!$q->fetchColumn()) fail('Unknown question.', 400);
    $optId = null;
    if ($label !== null && $label !== '') {
        if (!in_array($label, ['A', 'B', 'C', 'D'], true)) fail('Invalid answer choice.');
        $o = $pdo->prepare('SELECT id FROM options WHERE question_id = ? AND label = ?');
        $o->execute([$qid, $label]);
        $optId = $o->fetchColumn();
        if (!$optId) fail('Invalid answer choice.');
    }
    $pdo->prepare('INSERT INTO submitted_answers (attempt_id, question_id, chosen_option_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE chosen_option_id = ?')
        ->execute([$att['id'], $qid, $optId, $optId]);
}

switch ($action) {
    // ------------------------------------------------------------------ dashboard
    case 'me':
        json_out(['id' => $sid, 'fullName' => $me['name'], 'email' => $me['email'], 'studentId' => $me['student_code'], 'course' => $me['course'], 'year' => $me['year'], 'status' => 'Verified']);

    case 'tests': {   // original: GET /api/students/:id/tests (now always the signed-in student)
        finalize_expired_attempts($pdo, null, $sid);
        $st = $pdo->prepare(
            "SELECT e.id, e.title, e.description, e.duration_minutes, e.status AS exam_status, e.show_results,
                    (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS qn,
                    (SELECT COALESCE(SUM(q.marks),0) FROM questions q WHERE q.exam_id = e.id) AS tm,
                    t.id AS attempt_id, t.status AS attempt_status, t.deadline_at,
                    r.obtained_marks, r.max_marks, r.percentage, r.result_status
               FROM exam_assignments a
               JOIN exams e ON e.id = a.exam_id
               LEFT JOIN exam_attempts t ON t.exam_id = e.id AND t.student_id = a.student_id
               LEFT JOIN results r ON r.attempt_id = t.id
              WHERE a.student_id = ? AND (e.status = 'Published' OR t.id IS NOT NULL)
              ORDER BY e.created_at DESC, e.id DESC"
        );
        $st->execute([$sid]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $state = $r['attempt_id'] === null ? 'available' : ($r['attempt_status'] === 'submitted' ? 'submitted' : 'in_progress');
            $show = (int) $r['show_results'] === 1;
            $out[] = [
                'id' => (int) $r['id'], 'title' => $r['title'], 'description' => $r['description'], 'duration' => (int) $r['duration_minutes'],
                'questionCount' => (int) $r['qn'], 'totalMarks' => num($r['tm']), 'status' => $r['exam_status'], 'state' => $state,
                'attemptId' => $r['attempt_id'] === null ? null : (int) $r['attempt_id'],
                'remainingSeconds' => $state === 'in_progress' ? remaining_seconds($r) : null,
                'resultVisible' => $state === 'submitted' && $show,
                'score' => $state === 'submitted' && $show ? num($r['obtained_marks']) : null,
                'totalMarksResult' => $state === 'submitted' && $show ? num($r['max_marks']) : null,
                'percentage' => $state === 'submitted' && $show ? num($r['percentage']) : null,
                'resultStatus' => $state === 'submitted' && $show ? $r['result_status'] : null,
            ];
        }
        json_out($out);
    }

    // ------------------------------------------------------------------ exam engine
    case 'start': {   // starts a new attempt or resumes the in-progress one; the deadline is fixed on the server at first start
        require_method('POST');
        $examId = pos_id($b['examId'] ?? null, 'test');
        finalize_expired_attempts($pdo, $examId, $sid);
        $resumed = false;
        $pdo->beginTransaction();
        try {
            $ex = $pdo->prepare('SELECT e.* FROM exams e JOIN exam_assignments a ON a.exam_id = e.id AND a.student_id = ? WHERE e.id = ?');
            $ex->execute([$sid, $examId]);
            $exam = $ex->fetch();
            if (!$exam) fail('This test is not assigned to you.', 403);

            $ca = $pdo->prepare('SELECT * FROM exam_attempts WHERE exam_id = ? AND student_id = ? FOR UPDATE');
            $ca->execute([$examId, $sid]);
            $att = $ca->fetch();
            if ($att && $att['status'] === 'submitted') fail('You have already submitted this test.', 409);
            if ($att) {
                if (time() > strtotime($att['deadline_at'] . ' UTC') + $GRACE) fail('Time for this test has ended.', 403);
                $resumed = true;
                log_incident($pdo, $att, 'exam_resumed', 'Exam page was reloaded or reopened; the server timer kept running.', false);
            } else {
                if ($exam['status'] !== 'Published') fail('This test is not open for attempts.', 403);
                $qc = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE exam_id = ?');
                $qc->execute([$examId]);
                if ((int) $qc->fetchColumn() < 1) fail('This test has no questions yet.', 403);
                $now = time();
                // INSERT IGNORE + the UNIQUE(exam_id, student_id) key make double-clicks / two tabs harmless.
                $pdo->prepare('INSERT IGNORE INTO exam_attempts (exam_id, student_id, started_at, deadline_at, status) VALUES (?,?,?,?,?)')
                    ->execute([$examId, $sid, gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now + (int) $exam['duration_minutes'] * 60), 'in_progress']);
                $ca->execute([$examId, $sid]);
                $att = $ca->fetch();
            }
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }

        json_out([
            'attemptId' => (int) $att['id'], 'id' => (int) $exam['id'], 'title' => $exam['title'], 'description' => $exam['description'],
            'duration' => (int) $exam['duration_minutes'], 'maxViolations' => (int) $exam['max_violations'],
            'negativeMarking' => (int) $exam['negative_marking'] === 1, 'negativeFraction' => num($exam['negative_fraction']),
            'remainingSeconds' => remaining_seconds($att), 'violations' => (int) $att['violation_count'], 'resumed' => $resumed,
            'questions' => load_questions($pdo, (int) $exam['id'], false),   // never includes the answer key
            'answers' => (object) saved_answers($pdo, (int) $att['id']),
        ]);
    }

    case 'answer': {   // autosave of a single answer
        require_method('POST');
        $attemptId = pos_id($b['attemptId'] ?? null, 'attempt');
        $qid = pos_id($b['questionId'] ?? null, 'question');
        $label = isset($b['answer']) && $b['answer'] !== null ? strtoupper(trim((string) $b['answer'])) : null;
        $pdo->beginTransaction();
        try {
            $att = own_attempt($pdo, $attemptId, $sid, true);
            if ($att['status'] === 'submitted') { $pdo->commit(); json_out(['submitted' => true] + student_result_payload($pdo, $att), 409); }
            if (time() > strtotime($att['deadline_at'] . ' UTC') + $GRACE) {
                finalize_attempt($pdo, $attemptId, 'time_limit');
                $att = own_attempt($pdo, $attemptId, $sid);
                $payload = student_result_payload($pdo, $att);
                $pdo->commit();
                json_out(['expired' => true, 'error' => 'Time for this test has ended. Your answers were submitted.'] + $payload, 409);
            }
            upsert_answer($pdo, $att, $qid, $label);
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
        json_out(['ok' => true, 'remainingSeconds' => remaining_seconds($att)]);
    }

    case 'heartbeat': {   // resync the clock + detect expiry
        require_method('POST');
        $attemptId = pos_id($b['attemptId'] ?? null, 'attempt');
        $att = own_attempt($pdo, $attemptId, $sid);
        if ($att['status'] === 'in_progress' && time() > strtotime($att['deadline_at'] . ' UTC') + $GRACE) {
            finalize_attempt($pdo, $attemptId, 'time_limit');
            $att = own_attempt($pdo, $attemptId, $sid);
        }
        if ($att['status'] === 'submitted') json_out(['submitted' => true] + student_result_payload($pdo, $att));
        json_out(['ok' => true, 'remainingSeconds' => remaining_seconds($att), 'violations' => (int) $att['violation_count']]);
    }

    case 'incident': {   // frontend event detection -> backend record (+ threshold auto-submit)
        require_method('POST');
        $attemptId = pos_id($b['attemptId'] ?? null, 'attempt');
        $type = (string) ($b['type'] ?? '');
        if (!in_array($type, CLIENT_INCIDENTS, true)) fail('Unknown incident type.');
        $detail = trim((string) ($b['detail'] ?? ''));
        $clientTime = isset($b['clientTime']) ? (string) $b['clientTime'] : null;
        $defaults = [
            'fullscreen_exit' => 'Full-screen mode was exited during the exam.',
            'tab_switch' => 'The exam tab or window was hidden (tab switch or minimised window).',
            'window_blur' => 'The exam window lost focus.',
            'student_report' => 'Student reported an incident.',
            'fullscreen_unavailable' => 'Full-screen mode could not be started in this browser.',
        ];
        if ($detail === '') $detail = $defaults[$type];

        $pdo->beginTransaction();
        try {
            $att = own_attempt($pdo, $attemptId, $sid, true);
            if ($att['status'] === 'submitted') { $pdo->commit(); json_out(['submitted' => true, 'autoSubmitted' => false] + student_result_payload($pdo, $att)); }
            if (time() > strtotime($att['deadline_at'] . ' UTC') + $GRACE) {
                finalize_attempt($pdo, $attemptId, 'time_limit');
                $att = own_attempt($pdo, $attemptId, $sid);
                $payload = student_result_payload($pdo, $att);
                $pdo->commit();
                json_out(['submitted' => true, 'autoSubmitted' => true] + $payload);
            }
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM exam_incidents WHERE attempt_id = ?');
            $cnt->execute([$attemptId]);
            if ((int) $cnt->fetchColumn() >= 300) { $pdo->commit(); json_out(['ok' => true, 'violations' => (int) $att['violation_count'], 'throttled' => true]); }

            $exam = exam_row($pdo, (int) $att['exam_id']);
            $counted = in_array($type, COUNTED_INCIDENTS, true);
            if ($counted) {   // one physical action (e.g. Alt+Tab) can raise two browser events: count it once
                $recent = $pdo->prepare('SELECT 1 FROM exam_incidents WHERE attempt_id = ? AND counted = 1 AND occurred_at >= ? LIMIT 1');
                $recent->execute([$attemptId, gmdate('Y-m-d H:i:s', time() - 2)]);
                if ($recent->fetchColumn()) { $counted = false; $detail .= ' (same event as the previous violation)'; }
            }
            log_incident($pdo, $att, $type, $detail, $counted, $clientTime);

            $violations = (int) $att['violation_count'];
            if ($counted) {
                $pdo->prepare('UPDATE exam_attempts SET violation_count = violation_count + 1 WHERE id = ?')->execute([$attemptId]);
                $violations++;
                if ($violations >= (int) $exam['max_violations']) {
                    finalize_attempt($pdo, $attemptId, $type);   // reason = fullscreen_exit | tab_switch
                    $att = own_attempt($pdo, $attemptId, $sid);
                    $payload = student_result_payload($pdo, $att);
                    $pdo->commit();
                    json_out(['submitted' => true, 'autoSubmitted' => true, 'violations' => $violations, 'maxViolations' => (int) $exam['max_violations']] + $payload);
                }
            }
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
        json_out(['ok' => true, 'counted' => $counted, 'violations' => $violations, 'maxViolations' => (int) $exam['max_violations'],
                  'remainingSeconds' => remaining_seconds($att)]);
    }

    case 'submit': {
        require_method('POST');
        $attemptId = pos_id($b['attemptId'] ?? null, 'attempt');
        $claimed = (string) ($b['reason'] ?? 'student_submit');
        $pdo->beginTransaction();
        try {
            $att = own_attempt($pdo, $attemptId, $sid, true);
            if ($att['status'] === 'submitted') {   // duplicate submit: return the stored result, change nothing
                $payload = student_result_payload($pdo, $att);
                $pdo->commit();
                json_out(['duplicate' => true] + $payload);
            }
            $deadline = strtotime($att['deadline_at'] . ' UTC');
            $late = time() > $deadline;
            if (time() <= $deadline + $GRACE && is_array($b['answers'] ?? null)) {   // merge answers the autosave may have missed (offline blips)
                foreach ($b['answers'] as $qid => $label) {
                    if (!is_numeric($qid)) continue;
                    $label = $label === null ? null : strtoupper(trim((string) $label));
                    upsert_answer($pdo, $att, (int) $qid, $label);
                }
            }
            // "time_limit" is only accepted from the client if the server deadline really is (almost) reached.
            $reason = ($late || ($claimed === 'time_limit' && time() >= $deadline - 3)) ? 'time_limit' : 'student_submit';
            finalize_attempt($pdo, $attemptId, $reason);
            $att = own_attempt($pdo, $attemptId, $sid);
            $payload = student_result_payload($pdo, $att);
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
        json_out($payload);
    }

    case 'result': {
        $att = own_attempt($pdo, $_GET['attemptId'] ?? null, $sid);
        if ($att['status'] !== 'submitted') fail('This test has not been submitted yet.', 409);
        json_out(student_result_payload($pdo, $att));
    }
    default:
        fail('Unknown action.', 404);
}
