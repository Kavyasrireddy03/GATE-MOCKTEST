<?php
// Coding questions (TCS NQT style): problem statement, code editor, Compile and Submit Code.
// A coding question is a row in `questions` with q_type = 'CODE' plus one row in
// `coding_problems` (statement, allowed languages, starter code, test cases).
// Code is compiled and run in the candidate's browser (modules/coderun/), never on this server
// and never through an online compiler API.
// Coding questions show to candidates only while the admin has switched them on
// (app_settings.coding_enabled, toggled in paper_builder.php).

const CODING_LANGUAGES = [
    'c'          => 'C',
    'cpp'        => 'C++',
    'python'     => 'Python 3',
    'javascript' => 'JavaScript',
];

const CODING_STARTER = [
    'c'          => "#include <stdio.h>\n\nint main() {\n    // Read input from STDIN, print output to STDOUT\n\n    return 0;\n}\n",
    'cpp'        => "#include <bits/stdc++.h>\nusing namespace std;\n\nint main() {\n    // Read input from STDIN, print output to STDOUT\n\n    return 0;\n}\n",
    'python'     => "# Read input from STDIN, print output to STDOUT\n",
    'javascript' => "// Read a line with readline(), print with console.log()\n",
];

function ensure_coding_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key   VARCHAR(64) PRIMARY KEY,
            setting_value TEXT NULL
        )
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS coding_problems (
            question_id    INT PRIMARY KEY,
            title          VARCHAR(200) NOT NULL DEFAULT '',
            statement      MEDIUMTEXT NULL,
            input_format   TEXT NULL,
            output_format  TEXT NULL,
            constraints_text TEXT NULL,
            languages      VARCHAR(100) NOT NULL DEFAULT 'c,cpp,python,javascript',
            starter_json   MEDIUMTEXT NULL,
            tests_json     MEDIUMTEXT NULL,
            time_limit_ms  INT NOT NULL DEFAULT 2000
        )
    ");
    // Older databases define questions.q_type as ENUM('MCQ','MSQ','NAT') or a short VARCHAR,
    // which silently stores '' for 'CODE'. Widen it so coding questions keep their type.
    $col = $conn->query("SHOW COLUMNS FROM questions LIKE 'q_type'");
    $info = $col ? $col->fetch_assoc() : null;
    $wide_enough = $info && preg_match('/^varchar\((\d+)\)/i', $info['Type'], $m) && (int)$m[1] >= 10;
    if ($info && !$wide_enough) {
        $conn->query("ALTER TABLE questions MODIFY q_type VARCHAR(10) NULL DEFAULT NULL");
    }
    // Repair coding questions saved before the column was widened
    $conn->query("UPDATE questions q JOIN coding_problems cp ON cp.question_id = q.id
                  SET q.q_type = 'CODE' WHERE COALESCE(q.q_type, '') <> 'CODE'");
    ensure_answer_column($conn);
}

function coding_enabled(mysqli $conn): bool
{
    $r = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'coding_enabled'");
    $row = $r ? $r->fetch_row() : null;
    return $row && $row[0] === '1';
}

function set_coding_enabled(mysqli $conn, bool $on): void
{
    $v = $on ? '1' : '0';
    $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('coding_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("s", $v);
    $stmt->execute();
}

// SQL condition that hides coding questions while they are switched off
function coding_filter_sql(mysqli $conn, string $alias = 'q'): string
{
    return coding_enabled($conn) ? '' : " AND COALESCE($alias.q_type, '') <> 'CODE'";
}

// question_id => problem row (tests and starter code decoded)
function fetch_coding_problems(mysqli $conn, array $question_ids): array
{
    $ids = array_values(array_filter(array_map('intval', $question_ids)));
    if (!$ids) return [];
    $out = [];
    try {
        $r = $conn->query("SELECT * FROM coding_problems WHERE question_id IN (" . implode(',', $ids) . ")");
    } catch (mysqli_sql_exception $ex) {
        return [];   // table not created yet
    }
    while ($r && $row = $r->fetch_assoc()) {
        $row['languages'] = array_values(array_intersect(array_keys(CODING_LANGUAGES), explode(',', (string)$row['languages'])));
        $row['starter'] = json_decode($row['starter_json'] ?? '{}', true) ?: [];
        $row['tests'] = json_decode($row['tests_json'] ?? '[]', true) ?: [];
        $out[(int)$row['question_id']] = $row;
    }
    return $out;
}

// What the exam page needs for one coding question
function coding_exam_payload(array $p): array
{
    $starter = [];
    foreach ($p['languages'] as $lang) {
        $starter[$lang] = ($p['starter'][$lang] ?? '') !== '' ? $p['starter'][$lang] : CODING_STARTER[$lang];
    }
    return [
        'title'        => (string)$p['title'],
        'statement'    => (string)$p['statement'],
        'inputFormat'  => (string)$p['input_format'],
        'outputFormat' => (string)$p['output_format'],
        'constraints'  => (string)$p['constraints_text'],
        'languages'    => $p['languages'],
        'starter'      => (object)$starter,
        'timeLimitMs'  => (int)$p['time_limit_ms'] ?: 2000,
        'tests'        => array_map(fn($t) => [
            'input'  => (string)($t['input'] ?? ''),
            'output' => (string)($t['output'] ?? ''),
            'sample' => !empty($t['sample']),
        ], $p['tests']),
    ];
}

// Make sure a submitted program fits in attempt_answers.selected_answer (older installs use VARCHAR)
function ensure_answer_column(mysqli $conn): void
{
    try {
        $col = $conn->query("SHOW COLUMNS FROM attempt_answers LIKE 'selected_answer'");
        $info = $col ? $col->fetch_assoc() : null;
        if ($info && !preg_match('/text/i', $info['Type'])) {
            $conn->query("ALTER TABLE attempt_answers MODIFY selected_answer MEDIUMTEXT NULL");
        }
    } catch (mysqli_sql_exception $ex) {
        // attempt_answers is created by the results code; nothing to widen yet
    }
}

// One coding question in the downloadable result (user_dashboard.php / dashboard.php):
// problem, the candidate's code, time spent, and a tick or cross for every test case.
function coding_report_html(mysqli $conn, array $q_row, int $q_num, string $section_label, ?string $selected_json): string
{
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $qid = (int)$q_row['id'];
    $p = fetch_coding_problems($conn, [$qid])[$qid] ?? null;
    $tests = $p['tests'] ?? [];
    $marks = (float)$q_row['marks'];
    $a = json_decode((string)$selected_json, true);
    $submitted = is_array($a) && isset($a['total']);

    $passed = $submitted ? (int)$a['passed'] : 0;
    $total = $submitted ? max(1, (int)$a['total']) : max(1, count($tests));
    $earned = $submitted ? round($marks * $passed / $total, 2) : 0;
    $color = !$submitted ? '#111' : ($passed === $total ? '#16a34a' : ($passed > 0 ? '#ca8a04' : '#dc2626'));
    $lang = $submitted ? (CODING_LANGUAGES[$a['lang'] ?? ''] ?? ($a['lang'] ?? '')) : '--';
    $spent = $submitted && isset($a['timeSpentSec']) ? sprintf('%dm %02ds', intdiv((int)$a['timeSpentSec'], 60), (int)$a['timeSpentSec'] % 60) : '--';

    $h = "<div class='section-header'>Section : {$e($section_label)}</div><div class='q-box'>";
    $h .= "<table class='q-table'><tr><td class='q-num'>Q.{$q_num}</td><td class='q-content'>";
    $h .= "<div style='font-size:16px;font-weight:bold;margin-bottom:6px;'>" . $e($p['title'] ?? 'Coding question') . " (Coding)</div>";
    foreach (['statement' => 'Problem Statement', 'input_format' => 'Input Format', 'output_format' => 'Output Format', 'constraints_text' => 'Constraints'] as $k => $label) {
        if (!empty($p[$k])) $h .= "<div style='font-weight:bold;margin-top:8px;'>{$label}</div><div style='white-space:pre-wrap;'>{$e($p[$k])}</div>";
    }
    $h .= "</td></tr></table>";

    // Summary box, same look as the other questions
    $h .= "<table class='meta-table' align='right'>
        <tr><td class='meta-label'>Question Type :</td><td class='meta-value'>Coding</td></tr>
        <tr><td class='meta-label'>Question ID :</td><td class='meta-value'>{$qid}</td></tr>
        <tr><td class='meta-label'>Status :</td><td class='meta-value'>" . ($submitted ? 'Submitted' : 'Not Submitted') . "</td></tr>
        <tr><td class='meta-label'>Language :</td><td class='meta-value'>{$e($lang)}</td></tr>
        <tr><td class='meta-label'>Time Spent :</td><td class='meta-value'>{$spent}</td></tr>
        <tr><td class='meta-label'>Test Cases :</td><td class='meta-value' style='color:{$color};'>" . ($submitted ? "{$passed} / {$total} passed" : '--') . "</td></tr>
        <tr><td class='meta-label'>Marks :</td><td class='meta-value' style='color:{$color};'>" . ($submitted ? "+{$earned} of {$marks}" : '0') . "</td></tr>
      </table><div style='clear:both;'></div>";

    if ($submitted) {
        $h .= "<div style='font-weight:bold;margin-top:12px;'>Test case results</div>";
        if (!empty($a['compileError'])) {
            $h .= "<div style='color:#dc2626;margin:4px 0;'>&#10008; Compilation error, no test case was run.</div>";
            if (!empty($a['compileLog'])) $h .= "<pre style='background:#fef2f2;border:1px solid #fecaca;padding:8px;white-space:pre-wrap;font-size:12px;'>{$e($a['compileLog'])}</pre>";
        } elseif (!empty($a['results']) && is_array($a['results'])) {
            $h .= "<table style='border-collapse:collapse;margin-top:4px;font-size:13px;'>
                <tr style='background:#f3f4f6;'><th style='border:1px solid #ccc;padding:5px 10px;'>Test case</th><th style='border:1px solid #ccc;padding:5px 10px;'>Type</th><th style='border:1px solid #ccc;padding:5px 10px;'>Result</th><th style='border:1px solid #ccc;padding:5px 10px;'>Run time</th></tr>";
            $total_ms = 0;
            foreach ($a['results'] as $i => $r) {
                $ok = !empty($r['pass']);
                $ms = (int)($r['ms'] ?? 0); $total_ms += $ms;
                $mark = $ok ? "<span style='color:#16a34a;font-weight:bold;'>&#10004; Passed</span>"
                            : "<span style='color:#dc2626;font-weight:bold;'>&#10008; Failed" . (!empty($r['error']) ? ' (' . $e($r['error']) . ')' : '') . "</span>";
                $type = !empty($tests[$i]['sample']) ? 'Sample' : 'Hidden';
                $h .= "<tr><td style='border:1px solid #ccc;padding:5px 10px;'>#" . ($i + 1) . "</td><td style='border:1px solid #ccc;padding:5px 10px;'>{$type}</td><td style='border:1px solid #ccc;padding:5px 10px;'>{$mark}</td><td style='border:1px solid #ccc;padding:5px 10px;'>{$ms} ms</td></tr>";
            }
            $h .= "<tr><td colspan='3' style='border:1px solid #ccc;padding:5px 10px;text-align:right;font-weight:bold;'>Total run time</td><td style='border:1px solid #ccc;padding:5px 10px;font-weight:bold;'>{$total_ms} ms</td></tr></table>";
        } else {
            $h .= "<div style='color:#555;'>{$passed} of {$total} test cases passed (per-test details were not recorded for this attempt).</div>";
        }
        $h .= "<div style='font-weight:bold;margin-top:12px;'>Submitted code ({$e($lang)})</div>
               <pre style='background:#1e1e1e;color:#d4d4d4;padding:10px;white-space:pre-wrap;font-family:Consolas,monospace;font-size:12px;border-radius:4px;'>{$e($a['code'] ?? '')}</pre>";
    }
    return $h . "</div>";
}
