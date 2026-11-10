<?php
declare(strict_types=1);

const SUBMISSION_LABELS = [
    'student_submit'  => 'Normal submission',
    'time_limit'      => 'Auto-submitted: Time limit',
    'fullscreen_exit' => 'Auto-submitted: Full-screen exit',
    'tab_switch'      => 'Auto-submitted: Tab switch',
    'violation_limit' => 'Auto-submitted: Violation limit',
    'migrated'        => 'Migrated from prototype',
];

const INCIDENT_LABELS = [
    'fullscreen_exit' => 'Full-screen exit',
    'tab_switch'      => 'Tab switch / page hidden',
    'window_blur'     => 'Window lost focus',
    'exam_resumed'    => 'Exam resumed after reload',
    'student_report'  => 'Student report',
    'fullscreen_unavailable' => 'Full-screen unavailable',
];

/**
 * Grades and closes an attempt. Safe to call repeatedly and concurrently:
 * the attempt row is locked (SELECT ... FOR UPDATE) so a second call simply returns the stored result.
 * Everything happens in one transaction, so a result row can never exist without the attempt being closed.
 */
function finalize_attempt(PDO $pdo, int $attemptId, string $reason): array
{
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM exam_attempts WHERE id = ? FOR UPDATE');
        $st->execute([$attemptId]);
        $att = $st->fetch();
        if (!$att) throw new ApiError('Attempt not found.', 404);

        if ($att['status'] === 'submitted') {
            $res = fetch_result_by_attempt($pdo, $attemptId);
            if ($own) $pdo->commit();
            return $res;
        }

        $ex = $pdo->prepare('SELECT * FROM exams WHERE id = ?');
        $ex->execute([$att['exam_id']]);
        $exam = $ex->fetch();

        $qs = $pdo->prepare(
            'SELECT q.id, q.marks,
                    (SELECT o.id FROM options o WHERE o.question_id = q.id AND o.is_correct = 1 ORDER BY o.label LIMIT 1) AS correct_option_id
               FROM questions q WHERE q.exam_id = ? ORDER BY q.position'
        );
        $qs->execute([$att['exam_id']]);
        $questions = $qs->fetchAll();

        $as = $pdo->prepare('SELECT question_id, chosen_option_id FROM submitted_answers WHERE attempt_id = ?');
        $as->execute([$attemptId]);
        $chosen = [];
        foreach ($as->fetchAll() as $a) $chosen[(int) $a['question_id']] = $a['chosen_option_id'] === null ? null : (int) $a['chosen_option_id'];

        $neg = (int) $exam['negative_marking'] === 1 ? (float) $exam['negative_fraction'] : 0.0;
        $max = 0.0; $got = 0.0; $correct = 0; $wrong = 0; $skipped = 0;
        $upd = $pdo->prepare('UPDATE submitted_answers SET is_correct = ?, marks_awarded = ? WHERE attempt_id = ? AND question_id = ?');
        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $marks = (float) $q['marks'];
            $max += $marks;
            $pick = $chosen[$qid] ?? null;
            if ($pick === null) { $skipped++; continue; }
            if ($q['correct_option_id'] !== null && $pick === (int) $q['correct_option_id']) {
                $correct++; $got += $marks; $award = $marks; $ok = 1;
            } else {
                $wrong++; $award = -round($marks * $neg, 2); $got += $award; $ok = 0;
            }
            $upd->execute([$ok, $award, $attemptId, $qid]);
        }
        $got = max(0.0, round($got, 2));        // total never drops below zero
        $pct = $max > 0 ? round($got / $max * 100, 2) : 0.0;
        $status = $pct >= (float) $exam['pass_percentage'] ? 'Pass' : 'Fail';

        $submittedAt = utc_now();
        if ($reason === 'time_limit' && $submittedAt > $att['deadline_at']) $submittedAt = $att['deadline_at'];

        $pdo->prepare(
            'INSERT INTO results (attempt_id, exam_id, student_id, examiner_id, total_questions, correct_count, incorrect_count,
                                  unattempted_count, max_marks, obtained_marks, percentage, result_status, submission_reason, submitted_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$attemptId, $att['exam_id'], $att['student_id'], $exam['examiner_id'], count($questions), $correct, $wrong,
                    $skipped, round($max, 2), $got, $pct, $status, $reason, $submittedAt]);

        $pdo->prepare("UPDATE exam_attempts SET status = 'submitted', submitted_at = ?, submission_reason = ? WHERE id = ?")
            ->execute([$submittedAt, $reason, $attemptId]);

        $res = fetch_result_by_attempt($pdo, $attemptId);
        if ($own) $pdo->commit();
        return $res;
    } catch (Throwable $t) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $t;
    }
}

/** Finalizes attempts whose server-side deadline (plus grace) has passed, e.g. the student closed the browser. */
function finalize_expired_attempts(PDO $pdo, ?int $examId = null, ?int $studentId = null): void
{
    $sql = "SELECT id FROM exam_attempts WHERE status = 'in_progress' AND deadline_at < ?";
    $args = [gmdate('Y-m-d H:i:s', time() - (int) app_cfg('deadline_grace_seconds', 5))];
    if ($examId !== null)    { $sql .= ' AND exam_id = ?';    $args[] = $examId; }
    if ($studentId !== null) { $sql .= ' AND student_id = ?'; $args[] = $studentId; }
    $st = $pdo->prepare($sql . ' LIMIT 200');
    $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try { finalize_attempt($pdo, (int) $id, 'time_limit'); } catch (Throwable $t) { error_log('[ExamPro] finalize expired: ' . $t->getMessage()); }
    }
}

function fetch_result_by_attempt(PDO $pdo, int $attemptId): array
{
    $st = $pdo->prepare('SELECT * FROM results WHERE attempt_id = ?');
    $st->execute([$attemptId]);
    $r = $st->fetch();
    if (!$r) throw new ApiError('Result not found.', 404);
    return format_result($r);
}

function format_result(array $r): array
{
    $n = fn($v) => $v === null ? null : num($v);
    return [
        'id' => (int) $r['id'], 'attemptId' => (int) $r['attempt_id'], 'examId' => (int) $r['exam_id'],
        'totalQuestions' => (int) $r['total_questions'],
        'correct' => $r['correct_count'] === null ? null : (int) $r['correct_count'],
        'incorrect' => $r['incorrect_count'] === null ? null : (int) $r['incorrect_count'],
        'unattempted' => $r['unattempted_count'] === null ? null : (int) $r['unattempted_count'],
        'totalMarks' => $n($r['max_marks']), 'score' => $n($r['obtained_marks']),
        'percentage' => $n($r['percentage']), 'status' => $r['result_status'],
        'submissionReason' => $r['submission_reason'],
        'submittedAt' => iso($r['submitted_at']),
    ];
}

/** Per-question review of a graded attempt (only call after authorisation + show_results check). */
function build_review(PDO $pdo, int $attemptId, int $examId): array
{
    $qs = $pdo->prepare('SELECT id, question_text, marks FROM questions WHERE exam_id = ? ORDER BY position');
    $qs->execute([$examId]);
    $questions = $qs->fetchAll();
    $os = $pdo->prepare('SELECT o.question_id, o.id, o.label, o.option_text, o.is_correct FROM options o JOIN questions q ON q.id = o.question_id WHERE q.exam_id = ? ORDER BY o.question_id, o.label');
    $os->execute([$examId]);
    $opts = [];
    foreach ($os->fetchAll() as $o) $opts[(int) $o['question_id']][] = $o;
    $as = $pdo->prepare('SELECT question_id, chosen_option_id, is_correct, marks_awarded FROM submitted_answers WHERE attempt_id = ?');
    $as->execute([$attemptId]);
    $ans = [];
    foreach ($as->fetchAll() as $a) $ans[(int) $a['question_id']] = $a;

    $out = [];
    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $chosenLabel = ''; $correctLabel = ''; $list = [];
        foreach ($opts[$qid] ?? [] as $o) {
            $list[] = $o['option_text'];
            if ((int) $o['is_correct'] === 1) $correctLabel = $o['label'];
            if (isset($ans[$qid]) && $ans[$qid]['chosen_option_id'] !== null && (int) $ans[$qid]['chosen_option_id'] === (int) $o['id']) $chosenLabel = $o['label'];
        }
        $out[] = [
            'question' => $q['question_text'], 'options' => $list, 'chosen' => $chosenLabel,
            'correctAnswer' => $correctLabel, 'marks' => num($q['marks']),
            'correct' => isset($ans[$qid]) && (int) $ans[$qid]['is_correct'] === 1,
            'awarded' => isset($ans[$qid]) && $ans[$qid]['marks_awarded'] !== null ? num($ans[$qid]['marks_awarded']) : 0,
        ];
    }
    return $out;
}

/**
 * Questions of an exam with their four options.
 * $withAnswers = false is what students get: no correct flag, no answer key.
 */
function load_questions(PDO $pdo, int $examId, bool $withAnswers): array
{
    $qs = $pdo->prepare('SELECT id, question_text, marks FROM questions WHERE exam_id = ? ORDER BY position');
    $qs->execute([$examId]);
    $questions = $qs->fetchAll();
    $os = $pdo->prepare('SELECT o.question_id, o.label, o.option_text, o.is_correct FROM options o JOIN questions q ON q.id = o.question_id WHERE q.exam_id = ? ORDER BY o.question_id, o.label');
    $os->execute([$examId]);
    $opts = [];
    foreach ($os->fetchAll() as $o) $opts[(int) $o['question_id']][] = $o;
    $out = [];
    foreach ($questions as $q) {
        $item = ['id' => (int) $q['id'], 'question' => $q['question_text'], 'marks' => num($q['marks']), 'options' => []];
        $answer = '';
        foreach ($opts[(int) $q['id']] ?? [] as $o) {
            $item['options'][] = $o['option_text'];
            if ((int) $o['is_correct'] === 1) $answer = $o['label'];
        }
        if ($withAnswers) $item['answer'] = $answer;
        $out[] = $item;
    }
    return $out;
}
