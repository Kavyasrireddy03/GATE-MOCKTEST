<?php
// TCS iON NQT style assessment page and report.
// assessment.php shows one test (subject + set): duration, Start Assessment, passing marks,
// start/end date, attempts taken and "My Attempts" with a View / Download Report link.
// report.php builds the NQT style performance report for one attempt.
// Passing marks and the attempt limit are set per test in tpdf.php (set_time.pass_marks,
// set_time.max_attempts). The exam saves time spent, final status, response changes and
// question order for every question (attempt_answers), which the report uses.

function ensure_assessment_schema(mysqli $conn): void
{
    $add = [
        'set_time'        => ['pass_marks' => 'DECIMAL(8,2) NULL DEFAULT NULL',
                              'max_attempts' => 'INT NOT NULL DEFAULT 0'],
        'attempt_answers' => ['time_spent_sec' => 'INT NULL DEFAULT NULL',
                              'q_status' => 'VARCHAR(30) NULL DEFAULT NULL',
                              'change_json' => 'VARCHAR(255) NULL DEFAULT NULL',
                              'q_order' => 'INT NULL DEFAULT NULL'],
    ];
    foreach ($add as $table => $columns) {
        foreach ($columns as $column => $definition) {
            try {
                $col = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                if ($col && $col->num_rows === 0) {
                    $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                }
            } catch (mysqli_sql_exception $ex) {
                // table not created yet; it gets the column the next time a page loads
            }
        }
    }
}

// Runs a prepared statement and returns all rows as arrays. Uses bind_result rather than
// get_result, which needs mysqlnd (not available on every host).
function assessment_fetch_all(mysqli_stmt $stmt): array
{
    $stmt->execute();
    $meta = $stmt->result_metadata();
    if (!$meta) { $stmt->close(); return []; }
    $row = []; $refs = [];
    foreach ($meta->fetch_fields() as $f) { $row[$f->name] = null; $refs[] = &$row[$f->name]; }
    $stmt->bind_result(...$refs);
    $rows = [];
    while ($stmt->fetch()) {
        $copy = [];
        foreach ($row as $k => $v) $copy[$k] = $v;
        $rows[] = $copy;
    }
    $stmt->close();
    return $rows;
}

// Set times are shown the same way as on the schedule page (index.php): stored time + 5:30.
function assessment_set_time_label(?string $dt): string
{
    if (!$dt) return 'N/A';
    return (new DateTime($dt))->modify('+5 hours 30 minutes')->format('d M Y | h:i A');
}

// One test (subject + set) with its schedule, passing marks, attempt limit and total marks.
function assessment_set_info(mysqli $conn, int $set_no, int $subject_id): ?array
{
    $stmt = $conn->prepare("
        SELECT s.duration_minutes, s.start_time, s.attempt_till, s.pass_marks, s.max_attempts,
               subj.subject_name, sd.topic_name, COALESCE(s.set_name, CONCAT('Set ', s.set_no)) AS set_label,
               (s.start_time IS NOT NULL AND s.attempt_till IS NOT NULL AND NOW() BETWEEN s.start_time AND s.attempt_till) AS is_open,
               (s.start_time IS NOT NULL AND NOW() < s.start_time) AS not_started
        FROM set_time s
        JOIN subjects subj ON subj.subject_id = s.subject_id
        LEFT JOIN set_definitions sd ON sd.subject_id = s.subject_id AND sd.set_no = s.set_no
        WHERE s.set_no = ? AND s.subject_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $set_no, $subject_id);
    $row = assessment_fetch_all($stmt)[0] ?? null;
    if (!$row) return null;

    $filter = function_exists('coding_filter_sql') ? coding_filter_sql($conn) : '';
    $stmt = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(q.marks), 0) FROM questions q WHERE q.set_no = ? AND q.subject_id = ?$filter");
    $stmt->bind_param("ii", $set_no, $subject_id);
    $stmt->execute();
    $stmt->bind_result($q_count, $total_marks);
    $stmt->fetch();
    $stmt->close();

    $row['set_no'] = $set_no;
    $row['subject_id'] = $subject_id;
    $row['question_count'] = (int)$q_count;
    $row['total_marks'] = (float)$total_marks;
    $row['max_attempts'] = (int)$row['max_attempts'];
    $row['pass_marks'] = $row['pass_marks'] === null ? null : (float)$row['pass_marks'];
    $row['title'] = $row['subject_name'] . ' - ' . $row['set_label'] . (!empty($row['topic_name']) ? ' (' . $row['topic_name'] . ')' : '') . ' Online Assessment';
    return $row;
}

// The candidate's attempts at one test, oldest first (Attempt 1, 2, ...).
function assessment_attempts(mysqli $conn, int $user_id, int $set_no, int $subject_id): array
{
    $stmt = $conn->prepare("
        SELECT attempt_id, score, total_marks, start_time, time_taken_seconds
        FROM test_attempts
        WHERE user_id = ? AND set_no = ? AND subject_id = ?
        ORDER BY attempt_id ASC
    ");
    $stmt->bind_param("iii", $user_id, $set_no, $subject_id);
    $out = [];
    foreach (assessment_fetch_all($stmt) as $row) {
        $start = $row['start_time'] ? new DateTime($row['start_time']) : null;
        $row['start_dt'] = $start;
        $row['submit_dt'] = $start ? (clone $start)->modify('+' . (int)$row['time_taken_seconds'] . ' seconds') : null;
        $out[] = $row;
    }
    return $out;
}

function assessment_attempt_count(mysqli $conn, int $user_id, int $set_no, int $subject_id): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM test_attempts WHERE user_id = ? AND set_no = ? AND subject_id = ?");
    $stmt->bind_param("iii", $user_id, $set_no, $subject_id);
    $stmt->execute();
    $stmt->bind_result($n);
    $stmt->fetch();
    $stmt->close();
    return (int)$n;
}

// Pass / Fail once the test has passing marks; "Not Available" otherwise (like the NQT page).
function assessment_status(float $score, ?float $pass_marks): array
{
    if ($pass_marks === null) return ['code' => 'N/A', 'label' => 'Not Available'];
    return $score >= $pass_marks ? ['code' => 'PASS', 'label' => 'Pass'] : ['code' => 'FAIL', 'label' => 'Fail'];
}

// NQT performance categories, by percentage of the maximum marks
const PERF_CATEGORIES = [
    'E' => ['name' => 'Excellent', 'min' => 91, 'color' => '#2fb344', 'range' => '91% to 100% of Max Marks',
            'text' => 'Outstanding level of performance indicates that the candidate has done excellent work and mastered the concepts.'],
    'H' => ['name' => 'High',      'min' => 81, 'color' => '#33b5e5', 'range' => '81% to 90% of Max Marks',
            'text' => 'High level of performance indicates that the candidate has done above average work and mastered almost all the concepts.'],
    'M' => ['name' => 'Moderate',  'min' => 61, 'color' => '#f39c12', 'range' => '61% to 80% of Max Marks',
            'text' => 'Acceptable level of performance indicates that the candidate has done average work and has mastered many of the concepts.'],
    'L' => ['name' => 'Low',       'min' => 0,  'color' => '#e74c3c', 'range' => 'Below 60% of Max Marks',
            'text' => 'Needs improvement in performance indicates that the candidate has done and mastered very few or none of the concepts.'],
];

function perf_category(float $percent): string
{
    foreach (PERF_CATEGORIES as $code => $c) {
        if ($percent >= $c['min']) return $code;
    }
    return 'L';
}

// Everything the report needs for one attempt of this user (null if it is not theirs).
function assessment_report_data(mysqli $conn, int $attempt_id, int $user_id): ?array
{
    $stmt = $conn->prepare("
        SELECT t.attempt_id, t.user_id, t.subject_id, t.set_no, t.score, t.total_marks, t.start_time,
               t.time_taken_seconds, t.attempted_questions, u.name, u.email, u.REG_ID, subj.subject_name
        FROM test_attempts t
        JOIN users u ON u.user_id = t.user_id
        JOIN subjects subj ON subj.subject_id = t.subject_id
        WHERE t.attempt_id = ? AND t.user_id = ?
    ");
    $stmt->bind_param("ii", $attempt_id, $user_id);
    $meta = assessment_fetch_all($stmt)[0] ?? null;
    if (!$meta) return null;

    $set = assessment_set_info($conn, (int)$meta['set_no'], (int)$meta['subject_id']);
    $meta['pass_marks'] = $set['pass_marks'] ?? null;
    $meta['duration_minutes'] = $set['duration_minutes'] ?? null;
    $meta['title'] = $set['title'] ?? ($meta['subject_name'] . ' - Set ' . $meta['set_no']);

    // Attempt number (1, 2, ...) of this attempt for this test
    $stmt = $conn->prepare("SELECT COUNT(*) FROM test_attempts WHERE user_id = ? AND subject_id = ? AND set_no = ? AND attempt_id <= ?");
    $stmt->bind_param("iiii", $user_id, $meta['subject_id'], $meta['set_no'], $attempt_id);
    $stmt->execute();
    $stmt->bind_result($attempt_no);
    $stmt->fetch();
    $stmt->close();
    $meta['attempt_no'] = (int)$attempt_no;

    $stmt = $conn->prepare("
        SELECT q.id, q.q_type, q.q_text_url, q.options_json, q.correct_answer_json, q.marks, q.range_min, q.range_max,
               COALESCE(sec.section_name, '') AS section_name, COALESCE(sec.sort_order, 2147483647) AS sec_order,
               a.selected_answer, a.is_correct, a.time_spent_sec, a.q_status, a.change_json, a.q_order
        FROM attempt_answers a
        JOIN questions q ON q.id = a.question_id
        LEFT JOIN sections sec ON sec.section_id = q.section_id
        WHERE a.attempt_id = ?
        ORDER BY (a.q_order IS NULL), a.q_order, sec_order, q.section_id, q.id
    ");
    $stmt->bind_param("i", $attempt_id);
    $questions = [];
    $seen = [];
    foreach (assessment_fetch_all($stmt) as $r) {
        if (isset($seen[$r['id']])) continue;   // one row per question
        $seen[$r['id']] = true;
        $questions[] = assessment_grade_row($conn, $r, $meta['subject_name']);
    }

    // Group by section, in question order
    $sections = [];
    foreach ($questions as $i => $q) {
        $questions[$i]['number'] = $i + 1;
        $name = $q['section'];
        if (!isset($sections[$name])) {
            $sections[$name] = ['name' => $name, 'questions' => [], 'max' => 0, 'score' => 0, 'time' => 0, 'time_known' => false,
                                'correct' => 0, 'incorrect' => 0, 'unanswered' => 0, 'marked' => 0, 'attempted' => 0,
                                'neg_lost' => 0, 'changes' => array_fill_keys(array_keys(ASSESSMENT_CHANGE_TYPES), 0)];
        }
        $s = &$sections[$name];
        $s['questions'][] = $questions[$i];
        $s['max'] += $q['marks'];
        $s['score'] += $q['obtained'];
        if ($q['time'] !== null) { $s['time'] += $q['time']; $s['time_known'] = true; }
        if ($q['result'] === 'correct') $s['correct']++;
        elseif ($q['result'] === 'incorrect' || $q['result'] === 'partial') $s['incorrect']++;
        elseif ($q['result'] === 'marked') $s['marked']++;
        else $s['unanswered']++;
        if ($q['answered']) $s['attempted']++;
        if ($q['obtained'] < 0) $s['neg_lost'] += -$q['obtained'];
        foreach ($q['changes'] as $k => $v) $s['changes'][$k] += $v;
        unset($s);
    }
    foreach ($sections as &$s) {
        $s['percent'] = $s['max'] > 0 ? max(0, $s['score']) / $s['max'] * 100 : 0;
        $s['accuracy'] = $s['attempted'] > 0 ? $s['correct'] / $s['attempted'] * 100 : 0;
        $s['category'] = perf_category($s['percent']);
    }
    unset($s);

    $max = array_sum(array_column($sections, 'max')) ?: (float)$meta['total_marks'];
    $score = (float)$meta['score'];
    $meta['max'] = $max;
    $meta['percent'] = $max > 0 ? max(0, $score) / $max * 100 : 0;
    $meta['category'] = perf_category($meta['percent']);

    return ['meta' => $meta, 'sections' => array_values($sections), 'questions' => $questions];
}

// Kinds of response change, recorded by the exam (modules/exam_app.js)
const ASSESSMENT_CHANGE_TYPES = [
    'CI' => 'Correct to Incorrect', 'IC' => 'Incorrect to Correct', 'II' => 'Incorrect to Incorrect',
    'CU' => 'Correct to Unanswered', 'IU' => 'Incorrect to Unanswered',
    'UC' => 'Unanswered to Correct', 'UI' => 'Unanswered to Incorrect',
];

// Marks and result of one question, with the same rules as the exam's scoring:
// MCQ wrong = -marks/3, MSQ / NAT wrong = 0, coding = marks x passed / total.
function assessment_grade_row(mysqli $conn, array $r, string $subject_name): array
{
    $type = $r['q_type'] ?: 'MCQ';
    $marks = (float)$r['marks'];
    $raw = $r['selected_answer'];
    $sel = ($raw === null || $raw === '' || $raw === 'null') ? null : json_decode($raw, true);
    if ($sel === null && $raw !== null && $raw !== '' && $raw !== 'null') $sel = [$raw];

    $q = [
        'id' => (int)$r['id'], 'type' => $type, 'marks' => $marks, 'image' => (string)$r['q_text_url'],
        'section' => $r['section_name'] !== '' ? $r['section_name'] : $subject_name,
        'options' => json_decode((string)$r['options_json'], true) ?: [],
        'correct' => json_decode((string)$r['correct_answer_json'], true) ?: [],
        'range' => [$r['range_min'], $r['range_max']],
        'selected' => [], 'answered' => false, 'result' => 'unanswered', 'obtained' => 0.0,
        'time' => $r['time_spent_sec'] !== null ? (int)$r['time_spent_sec'] : null,
        'status' => (string)$r['q_status'], 'code' => null, 'raw' => $raw,
        'changes' => array_fill_keys(array_keys(ASSESSMENT_CHANGE_TYPES), 0),
    ];
    $changes = json_decode((string)$r['change_json'], true);
    if (is_array($changes)) {
        foreach ($q['changes'] as $k => $v) $q['changes'][$k] = (int)($changes[$k] ?? 0);
    }

    if ($type === 'CODE') {
        if (is_array($sel) && isset($sel['total'])) {
            $passed = (int)($sel['passed'] ?? 0);
            $total = max(1, (int)$sel['total']);
            $q['answered'] = true;
            $q['obtained'] = round($marks * $passed / $total, 2);
            $q['result'] = $passed === $total ? 'correct' : ($passed > 0 ? 'partial' : 'incorrect');
            if ($q['time'] === null && isset($sel['timeSpentSec'])) $q['time'] = (int)$sel['timeSpentSec'];
            $p = function_exists('fetch_coding_problems') ? (fetch_coding_problems($conn, [$q['id']])[$q['id']] ?? null) : null;
            $q['code'] = ['title' => $p['title'] ?? 'Coding question', 'statement' => $p['statement'] ?? '',
                          'lang' => $sel['lang'] ?? '', 'source' => $sel['code'] ?? '', 'passed' => $passed, 'total' => $total,
                          'compileError' => !empty($sel['compileError'])];
        } else {
            $p = function_exists('fetch_coding_problems') ? (fetch_coding_problems($conn, [$q['id']])[$q['id']] ?? null) : null;
            $q['code'] = ['title' => $p['title'] ?? 'Coding question', 'statement' => $p['statement'] ?? '', 'lang' => '', 'source' => '',
                          'passed' => 0, 'total' => count($p['tests'] ?? []), 'compileError' => false];
        }
    } elseif (is_array($sel) && count(array_filter($sel, fn($v) => $v !== null && $v !== '')) > 0) {
        $q['answered'] = true;
        $q['selected'] = array_map('strval', array_values($sel));
        if ($type === 'NAT') {
            $v = $q['selected'][0];
            $ok = is_numeric($v) && (float)$v >= (float)$r['range_min'] && (float)$v <= (float)$r['range_max'];
        } else {
            $ok = (int)$r['is_correct'] === 1;
        }
        $q['result'] = $ok ? 'correct' : 'incorrect';
        $q['obtained'] = $ok ? $marks : ($type === 'MCQ' ? -round($marks / 3, 2) : 0.0);
    }
    if (!$q['answered'] && $q['status'] === 'marked_for_review') $q['result'] = 'marked';
    return $q;
}

function assessment_mmss(?int $sec): string
{
    if ($sec === null) return '--';
    return sprintf('%02d:%02d', intdiv($sec, 60), $sec % 60);
}
