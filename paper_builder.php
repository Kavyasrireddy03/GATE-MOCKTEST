<?php
// paper_builder.php - build one test (subject + set) section by section.
// Each section holds standalone questions and question groups
// (a passage with its sub-questions, shown passage-left / question-right in the exam).
session_start();
include 'config.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("Database Connection failed: " . $conn->connect_error); }
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/modules/admin_helpers.php';
require_once __DIR__ . '/modules/sections.php';
require_once __DIR__ . '/modules/coding.php';
ensure_sections_schema($conn);
ensure_coding_schema($conn);

$subject_id = (int)($_REQUEST['subject'] ?? 0);
$set_no     = (int)($_REQUEST['set'] ?? 0);

// Back to the open test, optionally scrolled to an anchor
function back($msg, $anchor = '') {
    global $subject_id, $set_no;
    $_SESSION['pb_message'] = $msg;
    header("Location: paper_builder.php?subject=$subject_id&set=$set_no" . ($anchor ? "#$anchor" : ''));
    exit();
}

// Section of a group = the group's section; its questions always follow it
function passage_section($conn, $passage_id) {
    $r = $conn->query("SELECT section_id FROM passages WHERE passage_id = " . (int)$passage_id)->fetch_row();
    return $r ? id_or_null($r[0]) : null;
}

// ======================= ACTIONS =======================
$action = $_POST['pb_action'] ?? '';

// ---- Coding questions on/off for candidates (whole site) ----
if ($action === 'toggle_coding') {
    $on = !empty($_POST['coding_on']);
    set_coding_enabled($conn, $on);
    back($on ? 'Coding questions are ON: candidates now see them in the exam.' : 'Coding questions are OFF: candidates do not see them.');
}
if ($action !== '' && ($subject_id <= 0 || $set_no <= 0)) back('Open a test (subject and set number) first.');

// ---- Save one question (new or edit), standalone or inside a group ----
if ($action === 'save_question') {
    $id = (int)($_POST['id'] ?? 0);
    $passage_id = id_or_null($_POST['passage_id'] ?? null);
    $section_id = $passage_id ? passage_section($conn, $passage_id) : id_or_null($_POST['section_id'] ?? null);
    $q_type = in_array($_POST['q_type'] ?? '', ['MCQ', 'MSQ', 'NAT'], true) ? $_POST['q_type'] : 'MCQ';
    $marks = (float)($_POST['marks'] ?? 1);
    $explanation = trim($_POST['explanation'] ?? '');
    $anchor = $passage_id ? "group-$passage_id" : "section-" . ($section_id ?: 0);

    $q_text_url = trim($_POST['existing_q_url'] ?? '');
    if (!empty($_FILES['q_file']['name'])) {
        $url = uploadToGoogleDrive($_FILES['q_file']);
        if (!$url) back("Question image upload failed. $DRIVE_ERROR", $anchor);
        $q_text_url = $url;
    }
    if ($q_text_url === '') back('A question image is required.', $anchor);

    if (!empty($_FILES['exp_file']['name'])) {
        $url = uploadToGoogleDrive($_FILES['exp_file']);
        if ($url) $explanation = $url;
    }

    $options = []; $correct = [];
    $range_min = null; $range_max = null;
    if ($q_type === 'NAT') {
        $range_min = num_or_null($_POST['range_min'] ?? null);
        $range_max = num_or_null($_POST['range_max'] ?? null);
        if ($range_min === null || $range_max === null) back('NAT questions need both a minimum and a maximum answer.', $anchor);
        if ($range_min > $range_max) [$range_min, $range_max] = [$range_max, $range_min];
    } else {
        $texts = $_POST['option_text'] ?? [];
        ksort($texts);
        $picked = array_map('strval', $_POST['correct_idx'] ?? []);
        foreach ($texts as $k => $text) {
            $text = trim($text);
            $img = trim($_POST['existing_option_url'][$k] ?? '');
            if (!empty($_FILES['option_files']['name'][$k])) {
                $url = uploadToGoogleDrive([
                    'name' => $_FILES['option_files']['name'][$k],
                    'tmp_name' => $_FILES['option_files']['tmp_name'][$k],
                    'error' => $_FILES['option_files']['error'][$k],
                ]);
                if (!$url) back("Option image upload failed. $DRIVE_ERROR", $anchor);
                $img = $url;
            }
            if ($text === '' && $img === '') continue;
            if ($text === '') $text = 'Option ' . chr(65 + count($options));
            $options[] = ['text' => $text, 'image' => $img];
            if (in_array((string)$k, $picked, true)) $correct[] = $text;
        }
        if (count($options) < 2) back('MCQ and MSQ questions need at least two options.', $anchor);
        if (!$correct) back('Mark the correct option(s).', $anchor);
        if ($q_type === 'MCQ' && count($correct) > 1) $correct = [$correct[0]];
    }

    $options_json = json_encode($options);
    $correct_json = json_encode(array_values($correct));

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE questions SET section_id=?, passage_id=?, q_type=?, q_text_url=?, options_json=?, correct_answer_json=?, explanation=?, marks=?, range_min=?, range_max=? WHERE id=? AND subject_id=? AND set_no=?");
        $stmt->bind_param("iisssssdddiii", $section_id, $passage_id, $q_type, $q_text_url, $options_json, $correct_json, $explanation, $marks, $range_min, $range_max, $id, $subject_id, $set_no);
    } else {
        $stmt = $conn->prepare("INSERT INTO questions (subject_id, set_no, section_id, passage_id, q_type, q_text_url, options_json, correct_answer_json, explanation, marks, range_min, range_max) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiisssssddd", $subject_id, $set_no, $section_id, $passage_id, $q_type, $q_text_url, $options_json, $correct_json, $explanation, $marks, $range_min, $range_max);
    }
    if (!$stmt->execute()) back('Database error: ' . $stmt->error, $anchor);
    back($id > 0 ? 'Question saved.' : 'Question added.', $anchor);
}

// ---- Take a question out of this test (it goes back to the question bank) ----
if ($action === 'remove_question') {
    $id = (int)$_POST['id'];
    $conn->query("UPDATE questions SET set_no = 0, passage_id = NULL WHERE id = $id AND subject_id = $subject_id AND set_no = $set_no");
    back('Question removed from this test. It is still in the question bank.');
}

if ($action === 'delete_question') {
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM questions WHERE id = $id AND subject_id = $subject_id AND set_no = $set_no");
    if ($conn->affected_rows > 0) $conn->query("DELETE FROM coding_problems WHERE question_id = $id");
    back('Question deleted permanently.');
}

// ---- Save a coding question (new or edit) ----
if ($action === 'save_coding') {
    $id = (int)($_POST['id'] ?? 0);
    $section_id = id_or_null($_POST['section_id'] ?? null);
    $anchor = "section-" . ($section_id ?: 0);
    $marks = (float)($_POST['marks'] ?? 10);
    $title = trim($_POST['title'] ?? '');
    $statement = trim($_POST['statement'] ?? '');
    $input_format = trim($_POST['input_format'] ?? '');
    $output_format = trim($_POST['output_format'] ?? '');
    $constraints = trim($_POST['constraints_text'] ?? '');
    $time_limit_ms = (int)round(max(0.5, min(10, (float)($_POST['time_limit'] ?? 2))) * 1000);
    $languages = array_values(array_intersect(array_keys(CODING_LANGUAGES), (array)($_POST['languages'] ?? [])));
    if ($title === '' || $statement === '') back('A coding question needs a title and a problem statement.', $anchor);
    if (!$languages) back('Allow at least one language.', $anchor);

    $starter = [];
    foreach ($languages as $lang) {
        $code = str_replace("\r\n", "\n", (string)($_POST['starter'][$lang] ?? ''));
        if (trim($code) !== '' && $code !== CODING_STARTER[$lang]) $starter[$lang] = $code;
    }

    $tests = [];
    $inputs = $_POST['test_input'] ?? [];
    ksort($inputs);
    foreach ($inputs as $k => $in) {
        $in = str_replace("\r\n", "\n", (string)$in);
        $out = str_replace("\r\n", "\n", (string)($_POST['test_output'][$k] ?? ''));
        if (trim($in) === '' && trim($out) === '') continue;
        $tests[] = ['input' => $in, 'output' => $out, 'sample' => !empty($_POST['test_sample'][$k])];
    }
    if (!$tests) back('Add at least one test case.', $anchor);

    // Optional picture (a figure or table) shown under the statement
    $q_text_url = !empty($_POST['remove_image']) ? '' : trim($_POST['existing_q_url'] ?? '');
    if (!empty($_FILES['q_file']['name'])) {
        $url = uploadToGoogleDrive($_FILES['q_file']);
        if (!$url) back("Image upload failed. $DRIVE_ERROR", $anchor);
        $q_text_url = $url;
    }

    $type = 'CODE'; $empty = '[]';
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE questions SET section_id=?, passage_id=NULL, q_type=?, q_text_url=?, options_json=?, correct_answer_json=?, marks=?, range_min=NULL, range_max=NULL WHERE id=? AND subject_id=? AND set_no=?");
        $stmt->bind_param("issssdiii", $section_id, $type, $q_text_url, $empty, $empty, $marks, $id, $subject_id, $set_no);
        if (!$stmt->execute() ) back('Database error: ' . $stmt->error, $anchor);
    } else {
        $stmt = $conn->prepare("INSERT INTO questions (subject_id, set_no, section_id, q_type, q_text_url, options_json, correct_answer_json, explanation, marks) VALUES (?, ?, ?, ?, ?, ?, ?, '', ?)");
        $stmt->bind_param("iiissssd", $subject_id, $set_no, $section_id, $type, $q_text_url, $empty, $empty, $marks);
        if (!$stmt->execute()) back('Database error: ' . $stmt->error, $anchor);
        $id = $conn->insert_id;
    }
    $langs = implode(',', $languages);
    $starter_json = json_encode((object)$starter);
    $tests_json = json_encode($tests);
    $stmt = $conn->prepare("REPLACE INTO coding_problems (question_id, title, statement, input_format, output_format, constraints_text, languages, starter_json, tests_json, time_limit_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssssi", $id, $title, $statement, $input_format, $output_format, $constraints, $langs, $starter_json, $tests_json, $time_limit_ms);
    if (!$stmt->execute()) back('Database error: ' . $stmt->error, $anchor);
    back((int)($_POST['id'] ?? 0) > 0 ? 'Coding question saved.' : 'Coding question added.', $anchor);
}

// ---- Create or edit a question group (passage) ----
if ($action === 'save_group') {
    $pid = (int)($_POST['passage_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $text = trim($_POST['passage_text'] ?? '');
    $section_id = id_or_null($_POST['section_id'] ?? null);
    $img = !empty($_POST['remove_image']) ? '' : trim($_POST['existing_image_url'] ?? '');
    if (!empty($_FILES['passage_file']['name'])) {
        $url = uploadToGoogleDrive($_FILES['passage_file']);
        if (!$url) back("Passage image upload failed. $DRIVE_ERROR");
        $img = $url;
    }
    if ($title === '') $title = 'Passage';
    if ($text === '' && $img === '') back('A group needs passage text, a passage image, or both.');

    if ($pid > 0) {
        $stmt = $conn->prepare("UPDATE passages SET title=?, passage_text=?, passage_image_url=?, section_id=?, subject_id=?, set_no=? WHERE passage_id=?");
        $stmt->bind_param("sssiiii", $title, $text, $img, $section_id, $subject_id, $set_no, $pid);
        $stmt->execute();
        // Sub-questions always sit in the group's section
        $stmt = $conn->prepare("UPDATE questions SET section_id=? WHERE passage_id=?");
        $stmt->bind_param("ii", $section_id, $pid);
        $stmt->execute();
        back('Group saved.', "group-$pid");
    }
    $stmt = $conn->prepare("INSERT INTO passages (title, passage_text, passage_image_url, section_id, subject_id, set_no) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssiii", $title, $text, $img, $section_id, $subject_id, $set_no);
    if (!$stmt->execute()) back('Database error: ' . $stmt->error);
    $new_id = $conn->insert_id;
    $_SESSION['pb_open_question_for_group'] = $new_id;   // open "add sub-question" straight away
    back('Group created. Now add its sub-questions.', "group-$new_id");
}

// ---- Ungroup: delete the passage, keep its questions as standalone questions ----
if ($action === 'ungroup') {
    $pid = (int)$_POST['passage_id'];
    $conn->query("UPDATE questions SET passage_id = NULL WHERE passage_id = $pid");
    $conn->query("DELETE FROM passages WHERE passage_id = $pid");
    back('Group removed. Its questions are now standalone questions in the same section.');
}

// ---- Delete a group with all its sub-questions ----
if ($action === 'delete_group') {
    $pid = (int)$_POST['passage_id'];
    $conn->query("DELETE FROM questions WHERE passage_id = $pid AND subject_id = $subject_id AND set_no = $set_no");
    $conn->query("UPDATE questions SET passage_id = NULL WHERE passage_id = $pid");
    $conn->query("DELETE FROM passages WHERE passage_id = $pid");
    back('Group and its questions deleted.');
}

// ---- Pull questions from the bank (uploaded but not in any test) into a section or group ----
if ($action === 'add_from_bank') {
    $ids = array_filter(array_map('intval', $_POST['question_ids'] ?? []));
    $passage_id = id_or_null($_POST['passage_id'] ?? null);
    $section_id = $passage_id ? passage_section($conn, $passage_id) : id_or_null($_POST['section_id'] ?? null);
    if (!$ids) back('Tick at least one question.');
    $stmt = $conn->prepare("UPDATE questions SET set_no=?, section_id=?, passage_id=? WHERE id=? AND subject_id=? AND (set_no IS NULL OR set_no = 0)");
    $n = 0;
    foreach ($ids as $qid) {
        $stmt->bind_param("iiiii", $set_no, $section_id, $passage_id, $qid, $subject_id);
        if ($stmt->execute()) $n += $stmt->affected_rows;
    }
    $conn->query("UPDATE questions SET passage_id = NULL WHERE q_type = 'CODE' AND passage_id IS NOT NULL"); // coding questions are never in a group
    back("$n question(s) added to this test.", $passage_id ? "group-$passage_id" : "section-" . ($section_id ?: 0));
}

// ---- Create a section without leaving the page ----
if ($action === 'add_section') {
    $name = trim($_POST['section_name'] ?? '');
    if ($name === '') back('Type a section name.');
    $order = (int)$conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM sections")->fetch_row()[0];
    $stmt = $conn->prepare("INSERT INTO sections (section_name, sort_order) VALUES (?, ?)");
    $stmt->bind_param("si", $name, $order);
    back($stmt->execute() ? "Section \"$name\" created." : 'Could not create section: ' . $stmt->error, 'section-' . $conn->insert_id);
}

// ======================= LOAD =======================
$subjects = fetch_subjects($conn);
$sections = fetch_sections($conn);
$subject_name = '';
foreach ($subjects as $s) { if ((int)$s['subject_id'] === $subject_id) $subject_name = $s['subject_name']; }

// Sets that already exist for the chosen subject
$known_sets = [];
if ($subject_id > 0) {
    $r = $conn->query("SELECT set_no FROM questions WHERE subject_id = $subject_id AND set_no > 0
                       UNION SELECT set_no FROM set_time WHERE subject_id = $subject_id ORDER BY set_no");
    if ($r) while ($row = $r->fetch_row()) $known_sets[] = (int)$row[0];
}

$test_open = $subject_id > 0 && $set_no > 0;
$questions = [];      // id => row
$groups = [];         // passage_id => row + 'questions' => [ids]
$by_section = [];     // section key (0 = none) => ['standalone' => [ids], 'groups' => [passage ids]]
$bank = [];

if ($test_open) {
    $r = $conn->query("SELECT * FROM passages WHERE (subject_id = $subject_id AND set_no = $set_no)
                       OR passage_id IN (SELECT passage_id FROM questions WHERE subject_id = $subject_id AND set_no = $set_no AND passage_id IS NOT NULL)
                       ORDER BY passage_id");
    while ($row = $r->fetch_assoc()) { $row['questions'] = []; $groups[(int)$row['passage_id']] = $row; }

    $r = $conn->query("SELECT id, section_id, passage_id, q_type, q_text_url, options_json, correct_answer_json, explanation, marks, range_min, range_max
                       FROM questions WHERE subject_id = $subject_id AND set_no = $set_no ORDER BY id");
    while ($row = $r->fetch_assoc()) {
        $row['options_json'] = json_decode($row['options_json'] ?? '[]', true) ?: [];
        $row['correct_answer_json'] = json_decode($row['correct_answer_json'] ?? '[]', true) ?: [];
        $questions[(int)$row['id']] = $row;
        if ($row['passage_id'] && isset($groups[(int)$row['passage_id']])) {
            $groups[(int)$row['passage_id']]['questions'][] = (int)$row['id'];
        } else {
            $by_section[(int)$row['section_id']]['standalone'][] = (int)$row['id'];
        }
    }
    foreach (fetch_coding_problems($conn, array_keys($questions)) as $qid => $p) {
        $questions[$qid]['coding'] = $p;
    }
    foreach ($groups as $pid => $g) {
        $sec = (int)($g['section_id'] ?? 0);
        if (!$sec && $g['questions']) $sec = (int)$questions[$g['questions'][0]]['section_id'];
        $by_section[$sec]['groups'][] = $pid;
    }

    $r = $conn->query("SELECT q.id, q.q_type, q.q_text_url, q.marks, cp.title AS coding_title FROM questions q
                       LEFT JOIN coding_problems cp ON cp.question_id = q.id
                       WHERE q.subject_id = $subject_id AND (q.set_no IS NULL OR q.set_no = 0) ORDER BY q.id DESC LIMIT 300");
    while ($row = $r->fetch_assoc()) $bank[] = $row;
}

// Sections to show: every section in order, then "No section" only if it has something
$section_list = [];
foreach ($sections as $s) $section_list[] = ['key' => (int)$s['section_id'], 'name' => $s['section_name']];
if (!empty($by_section[0]) || !$sections) $section_list[] = ['key' => 0, 'name' => 'No section (shown as "' . ($subject_name ?: 'subject') . '")'];

function answer_text($q) {
    if ($q['q_type'] === 'CODE') {
        $tests = $q['coding']['tests'] ?? [];
        $samples = count(array_filter($tests, fn($t) => !empty($t['sample'])));
        return count($tests) . ' test case' . (count($tests) === 1 ? '' : 's') . " ($samples sample, " . (count($tests) - $samples) . ' hidden)';
    }
    if ($q['q_type'] === 'NAT') return $q['range_min'] . ' to ' . $q['range_max'];
    $letters = [];
    foreach ($q['options_json'] as $i => $o) if (in_array($o['text'], $q['correct_answer_json'], true)) $letters[] = chr(65 + $i);
    return $letters ? implode(', ', $letters) : '<span class="text-red-600">not set</span>';
}

$total_q = count($questions);
$total_marks = array_sum(array_map(fn($q) => (float)$q['marks'], $questions));
$message = $_SESSION['pb_message'] ?? ''; unset($_SESSION['pb_message']);
$open_group = $_SESSION['pb_open_question_for_group'] ?? 0; unset($_SESSION['pb_open_question_for_group']);
$qno = 0;   // running question number, in exam order
$coding_on = coding_enabled($conn);
$coding_langs = CODING_LANGUAGES;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Question Paper Builder | <?= htmlspecialchars(EXAM_NAME) ?></title>
    <script src="<?= CDN_TAILWIND ?>"></script>
    <style>
        .thumb { max-height: 56px; max-width: 220px; object-fit: contain; border: 1px solid #e5e7eb; background: #fff; }
        .modal { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 50; display: none; align-items: flex-start; justify-content: center; overflow-y: auto; padding: 40px 16px; }
        .modal.open { display: flex; }
        .loading-overlay { display: none; position: fixed; inset: 0; background: rgba(255,255,255,0.8); z-index: 100; justify-content: center; align-items: center; flex-direction: column; }
    </style>
</head>
<body class="bg-gray-100 p-6">

<div id="loader" class="loading-overlay">
    <div class="animate-spin rounded-full h-16 w-16 border-b-4 border-blue-600"></div>
    <p class="mt-4 font-bold text-blue-600">Saving (uploading images to Google Drive)...</p>
</div>

<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6 border-b-4 border-blue-600 pb-2">
        <h1 class="text-3xl font-extrabold">Question Paper Builder</h1>
        <div class="text-sm space-x-4">
            <a href="admin.php" class="text-blue-700 underline">Admin: bulk upload, sections</a>
            <a href="set.php<?= $subject_id ? '?subject_id=' . $subject_id : '' ?>" class="text-blue-700 underline">Set durations and schedule</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="bg-green-600 text-white p-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Coding questions switch -->
    <form method="POST" class="p-4 rounded-xl shadow mb-6 flex flex-wrap items-center gap-4 <?= $coding_on ? 'bg-green-50 border border-green-300' : 'bg-white' ?>">
        <input type="hidden" name="subject" value="<?= $subject_id ?>"><input type="hidden" name="set" value="<?= $set_no ?>">
        <input type="hidden" name="pb_action" value="toggle_coding">
        <input type="hidden" name="coding_on" value="<?= $coding_on ? '' : '1' ?>">
        <div class="flex-grow">
            <div class="font-bold">Coding questions: <span id="coding-state" class="<?= $coding_on ? 'text-green-700' : 'text-gray-500' ?>"><?= $coding_on ? 'ON' : 'OFF' ?></span></div>
            <div class="text-sm text-gray-600"><?= $coding_on
                ? 'Candidates see coding questions (code editor with Compile and Submit Code). Code runs inside the candidate\'s browser: C, C++, Python 3, JavaScript; no online compiler API.'
                : 'Coding questions are hidden from candidates and you cannot add new ones. Switch on to add coding questions to a section.' ?></div>
        </div>
        <button id="coding-toggle" class="px-5 py-2 rounded font-bold text-white <?= $coding_on ? 'bg-gray-600' : 'bg-green-600' ?>"><?= $coding_on ? 'Turn off' : 'Turn on' ?></button>
    </form>

    <!-- Step 1: choose the test -->
    <form method="GET" class="bg-white p-4 rounded-xl shadow mb-6 flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-sm font-bold mb-1">Subject</label>
            <select name="subject" class="border p-2 rounded min-w-[220px]" onchange="this.form.set.value=''; this.form.submit()">
                <option value="">Select subject</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= $s['subject_id'] ?>" <?= (int)$s['subject_id'] === $subject_id ? 'selected' : '' ?>><?= htmlspecialchars($s['subject_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-bold mb-1">Set number</label>
            <input type="number" min="1" name="set" value="<?= $set_no ?: '' ?>" class="border p-2 rounded w-28" required>
        </div>
        <?php if ($known_sets): ?>
        <div class="text-sm">
            <div class="font-bold mb-1">Existing sets</div>
            <?php foreach ($known_sets as $k): ?>
                <a href="?subject=<?= $subject_id ?>&set=<?= $k ?>" class="inline-block px-3 py-1 mr-1 rounded border <?= $k === $set_no ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-blue-700' ?>">Set <?= $k ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <button class="bg-blue-600 text-white px-6 py-2 rounded font-bold">Open test</button>
        <p class="w-full text-xs text-gray-500">Type a new set number to start a new test. Its duration and schedule are set on the "Set durations and schedule" page.</p>
    </form>

    <?php if ($test_open): ?>

    <!-- Summary -->
    <div class="bg-white p-4 rounded-xl shadow mb-6">
        <div class="text-xl font-bold"><?= htmlspecialchars($subject_name) ?>, Set <?= $set_no ?>:
            <?= $total_q ?> question<?= $total_q === 1 ? '' : 's' ?>, <?= rtrim(rtrim(number_format($total_marks, 2), '0'), '.') ?> marks</div>
        <div class="flex flex-wrap gap-2 mt-2 text-sm">
            <?php foreach ($section_list as $sec):
                $ids = array_merge($by_section[$sec['key']]['standalone'] ?? [], ...array_map(fn($p) => $groups[$p]['questions'], $by_section[$sec['key']]['groups'] ?? []));
                $m = array_sum(array_map(fn($i) => (float)$questions[$i]['marks'], $ids)); ?>
                <a href="#section-<?= $sec['key'] ?>" class="px-3 py-1 rounded-full bg-gray-100 border"><?= htmlspecialchars($sec['name']) ?>: <?= count($ids) ?> Q, <?= $m ?> marks</a>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-gray-500 mt-2">Sections show as tabs in the exam in this order. Inside a section the standalone questions come first (shuffled for each candidate), then each group with its passage.</p>
    </div>

    <!-- Step 2: sections -->
    <?php foreach ($section_list as $sec): $key = $sec['key']; $standalone = $by_section[$key]['standalone'] ?? []; $sec_groups = $by_section[$key]['groups'] ?? []; ?>
    <div id="section-<?= $key ?>" class="bg-white rounded-xl shadow mb-6 overflow-hidden">
        <div class="bg-[#287baf] text-white px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-bold"><?= htmlspecialchars($sec['name']) ?></h2>
            <div class="flex gap-2 text-sm">
                <button onclick="openQuestion(<?= $key ?>, 0, 0)" class="bg-white text-[#287baf] font-bold px-3 py-1 rounded">+ Question</button>
                <button onclick="openGroup(<?= $key ?>, 0)" class="bg-white text-purple-700 font-bold px-3 py-1 rounded">+ Question group (passage)</button>
                <?php if ($coding_on): ?>
                <button onclick="openCoding(<?= $key ?>, 0)" class="bg-white text-green-700 font-bold px-3 py-1 rounded">+ Coding question</button>
                <?php endif; ?>
                <button onclick="openBank(<?= $key ?>, 0)" class="bg-[#1f6491] border border-white px-3 py-1 rounded">+ From question bank</button>
            </div>
        </div>
        <div class="p-4 space-y-3">
            <?php if (!$standalone && !$sec_groups): ?>
                <p class="text-sm text-gray-500 italic">No questions in this section for this test yet.</p>
            <?php endif; ?>

            <?php foreach ($standalone as $qid): $q = $questions[$qid]; $qno++; ?>
                <?php include __DIR__ . '/modules/paper_builder_row.php'; ?>
            <?php endforeach; ?>

            <?php foreach ($sec_groups as $pid): $g = $groups[$pid]; ?>
            <div id="group-<?= $pid ?>" class="border-2 border-purple-300 rounded-lg">
                <div class="bg-purple-50 px-4 py-2 flex flex-wrap items-center justify-between gap-2">
                    <div class="font-bold text-purple-800">Group: <?= htmlspecialchars($g['title']) ?>
                        <span class="font-normal text-sm text-gray-600">(<?= count($g['questions']) ?> sub-question<?= count($g['questions']) === 1 ? '' : 's' ?>)</span></div>
                    <div class="flex gap-2 text-sm">
                        <button onclick="openQuestion(<?= $key ?>, <?= $pid ?>, 0)" class="bg-purple-700 text-white px-3 py-1 rounded font-bold">+ Sub-question</button>
                        <button onclick="openBank(<?= $key ?>, <?= $pid ?>)" class="bg-white border border-purple-700 text-purple-700 px-3 py-1 rounded">+ From bank</button>
                        <button onclick="openGroup(<?= $key ?>, <?= $pid ?>)" class="bg-yellow-500 text-white px-3 py-1 rounded">Edit passage</button>
                        <form method="POST" onsubmit="return confirm('Remove the group? Its questions stay in this section as normal questions.')">
                            <input type="hidden" name="subject" value="<?= $subject_id ?>"><input type="hidden" name="set" value="<?= $set_no ?>">
                            <input type="hidden" name="pb_action" value="ungroup"><input type="hidden" name="passage_id" value="<?= $pid ?>">
                            <button class="bg-gray-500 text-white px-3 py-1 rounded">Ungroup</button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Delete this group AND all its sub-questions permanently?')">
                            <input type="hidden" name="subject" value="<?= $subject_id ?>"><input type="hidden" name="set" value="<?= $set_no ?>">
                            <input type="hidden" name="pb_action" value="delete_group"><input type="hidden" name="passage_id" value="<?= $pid ?>">
                            <button class="bg-red-500 text-white px-3 py-1 rounded">Delete group</button>
                        </form>
                    </div>
                </div>
                <div class="px-4 py-3 border-b text-sm text-gray-700 flex gap-4">
                    <?php if (!empty($g['passage_image_url'])): ?><img src="<?= htmlspecialchars(img_src($g['passage_image_url'])) ?>" class="thumb"><?php endif; ?>
                    <div class="whitespace-pre-line"><?= htmlspecialchars(mb_strimwidth((string)$g['passage_text'], 0, 400, '...')) ?></div>
                </div>
                <div class="p-3 space-y-3">
                    <?php if (!$g['questions']): ?>
                        <p class="text-sm text-red-600">No sub-questions yet. A group without questions does not show in the exam.</p>
                    <?php endif; ?>
                    <?php foreach ($g['questions'] as $qid): $q = $questions[$qid]; $qno++; ?>
                        <?php include __DIR__ . '/modules/paper_builder_row.php'; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <form method="POST" class="bg-white p-4 rounded-xl shadow flex gap-2">
        <input type="hidden" name="subject" value="<?= $subject_id ?>"><input type="hidden" name="set" value="<?= $set_no ?>">
        <input type="hidden" name="pb_action" value="add_section">
        <input name="section_name" placeholder="New section, e.g. General Aptitude" class="border p-2 rounded flex-grow" required>
        <button class="bg-gray-700 text-white px-4 py-2 rounded">Create section</button>
        <a href="admin.php?tab=sections" class="self-center text-sm text-blue-700 underline ml-2">Rename or reorder sections</a>
    </form>

    <!-- ============ QUESTION MODAL ============ -->
    <div id="q-modal" class="modal">
        <form method="POST" enctype="multipart/form-data" onsubmit="return submitQuestion(this)" class="bg-white rounded-xl shadow-2xl w-full max-w-3xl">
            <input type="hidden" name="subject" value="<?= $subject_id ?>"><input type="hidden" name="set" value="<?= $set_no ?>">
            <input type="hidden" name="pb_action" value="save_question">
            <input type="hidden" name="id" id="qm-id">
            <input type="hidden" name="section_id" id="qm-section">
            <input type="hidden" name="passage_id" id="qm-passage">
            <input type="hidden" name="existing_q_url" id="qm-existing-q">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 id="qm-title" class="text-xl font-bold">Add question</h3>
                <button type="button" onclick="closeModal('q-modal')" class="text-2xl leading-none">&times;</button>
            </div>
            <div class="p-6 space-y-4">
                <div id="qm-where" class="text-sm bg-gray-50 border rounded px-3 py-2"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold">Question type</label>
                        <select name="q_type" id="qm-type" class="w-full border p-2 rounded" onchange="renderAnswerUI()">
                            <option value="MCQ">MCQ (one correct option)</option>
                            <option value="MSQ">MSQ (one or more correct options)</option>
                            <option value="NAT">NAT (numerical answer)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold">Marks</label>
                        <input type="number" step="0.5" min="0" name="marks" id="qm-marks" value="1" class="w-full border p-2 rounded">
                    </div>
                </div>
                <div class="p-3 bg-blue-50 border border-blue-200 rounded">
                    <label class="block text-sm font-bold text-blue-800">Question image</label>
                    <div class="flex items-center gap-3 mt-1">
                        <img id="qm-q-thumb" class="thumb hidden">
                        <input type="file" name="q_file" id="qm-q-file" accept="image/*">
                    </div>
                </div>
                <div id="qm-answer"></div>
                <div>
                    <label class="block text-sm font-bold">Solution / explanation (optional)</label>
                    <input type="file" name="exp_file" accept="image/*" class="mb-1 text-sm">
                    <textarea name="explanation" id="qm-exp" rows="2" class="w-full border p-2 rounded" placeholder="Text explanation"></textarea>
                </div>
            </div>
            <div class="px-6 py-4 border-t flex justify-end gap-3">
                <button type="button" onclick="closeModal('q-modal')" class="px-5 py-2 border rounded">Cancel</button>
                <button class="px-6 py-2 bg-blue-600 text-white rounded font-bold">Save question</button>
            </div>
        </form>
    </div>

    <!-- ============ CODING QUESTION MODAL ============ -->
    <div id="c-modal" class="modal">
        <form method="POST" enctype="multipart/form-data" onsubmit="return submitCoding(this)" class="bg-white rounded-xl shadow-2xl w-full max-w-5xl">
            <input type="hidden" name="subject" value="<?= $subject_id ?>"><input type="hidden" name="set" value="<?= $set_no ?>">
            <input type="hidden" name="pb_action" value="save_coding">
            <input type="hidden" name="id" id="cm-id">
            <input type="hidden" name="section_id" id="cm-section">
            <input type="hidden" name="existing_q_url" id="cm-existing-q">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 id="cm-title" class="text-xl font-bold">Add coding question</h3>
                <button type="button" onclick="closeModal('c-modal')" class="text-2xl leading-none">&times;</button>
            </div>
            <div class="p-6 space-y-4">
                <div id="cm-where" class="text-sm bg-gray-50 border rounded px-3 py-2"></div>
                <div class="grid grid-cols-6 gap-4">
                    <div class="col-span-4">
                        <label class="block text-sm font-bold">Title</label>
                        <input name="title" id="cm-name" class="w-full border p-2 rounded" placeholder="e.g. Sum of even numbers" required>
                    </div>
                    <div>
                        <label class="block text-sm font-bold">Marks</label>
                        <input type="number" step="0.5" min="0" name="marks" id="cm-marks" value="10" class="w-full border p-2 rounded">
                    </div>
                    <div>
                        <label class="block text-sm font-bold">Time limit (s)</label>
                        <input type="number" step="0.5" min="0.5" max="10" name="time_limit" id="cm-tl" value="2" class="w-full border p-2 rounded">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold">Problem statement</label>
                    <textarea name="statement" id="cm-statement" rows="6" class="w-full border p-2 rounded" required placeholder="Describe the problem"></textarea>
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <div><label class="block text-sm font-bold">Input format</label><textarea name="input_format" id="cm-in" rows="3" class="w-full border p-2 rounded text-sm"></textarea></div>
                    <div><label class="block text-sm font-bold">Output format</label><textarea name="output_format" id="cm-out" rows="3" class="w-full border p-2 rounded text-sm"></textarea></div>
                    <div><label class="block text-sm font-bold">Constraints</label><textarea name="constraints_text" id="cm-cons" rows="3" class="w-full border p-2 rounded text-sm"></textarea></div>
                </div>
                <div class="p-3 bg-blue-50 border border-blue-200 rounded flex items-center gap-3">
                    <label class="text-sm font-bold text-blue-800">Image under the statement (optional)</label>
                    <img id="cm-thumb" class="thumb hidden">
                    <label id="cm-remove-wrap" class="text-sm hidden"><input type="checkbox" name="remove_image" value="1"> Remove image</label>
                    <input type="file" name="q_file" accept="image/*">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Languages the candidate can use</label>
                    <div class="flex gap-4">
                        <?php foreach ($coding_langs as $lk => $ll): ?>
                        <label class="flex items-center gap-1"><input type="checkbox" name="languages[]" value="<?= $lk ?>" class="cm-lang w-4 h-4" onchange="renderStarterTabs()"> <?= $ll ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <details class="border rounded">
                    <summary class="px-3 py-2 cursor-pointer text-sm font-bold">Starter code (optional; a default template is used when left as is)</summary>
                    <div class="p-3">
                        <div id="cm-starter-tabs" class="flex gap-1 mb-2"></div>
                        <div id="cm-starter-boxes"></div>
                    </div>
                </details>
                <div>
                    <div class="flex items-center justify-between">
                        <label class="block text-sm font-bold">Test cases</label>
                        <span class="text-xs text-gray-500">Sample cases are shown to the candidate with their output; hidden cases are only used for marks.
                            Marks = marks &times; (cases passed &divide; all cases). Trailing spaces and blank lines are ignored.</span>
                    </div>
                    <div id="cm-tests" class="space-y-2 mt-1"></div>
                    <button type="button" onclick="addTest()" class="mt-2 text-sm text-blue-700 font-bold">+ Add test case</button>
                </div>
                <details class="border rounded bg-gray-50" id="cm-solution">
                    <summary class="px-3 py-2 cursor-pointer text-sm font-bold">Fill expected outputs from your own solution (optional, not saved)</summary>
                    <div class="p-3 space-y-2">
                        <div class="flex gap-2 items-center text-sm">
                            <select id="cm-sol-lang" class="border p-1 rounded">
                                <?php foreach ($coding_langs as $lk => $ll): ?><option value="<?= $lk ?>"><?= $ll ?></option><?php endforeach; ?>
                            </select>
                            <button type="button" onclick="runSolution()" class="bg-gray-800 text-white px-3 py-1 rounded">Run on all test inputs and fill outputs</button>
                            <span id="cm-sol-status" class="text-gray-600"></span>
                        </div>
                        <textarea id="cm-sol-code" rows="8" class="w-full border p-2 rounded font-mono text-sm" spellcheck="false" placeholder="Paste a correct solution"></textarea>
                    </div>
                </details>
            </div>
            <div class="px-6 py-4 border-t flex justify-end gap-3">
                <button type="button" onclick="closeModal('c-modal')" class="px-5 py-2 border rounded">Cancel</button>
                <button class="px-6 py-2 bg-green-700 text-white rounded font-bold">Save coding question</button>
            </div>
        </form>
    </div>

    <!-- ============ GROUP MODAL ============ -->
    <div id="g-modal" class="modal">
        <form method="POST" enctype="multipart/form-data" onsubmit="return submitGroup(this)" class="bg-white rounded-xl shadow-2xl w-full max-w-3xl">
            <input type="hidden" name="subject" value="<?= $subject_id ?>"><input type="hidden" name="set" value="<?= $set_no ?>">
            <input type="hidden" name="pb_action" value="save_group">
            <input type="hidden" name="passage_id" id="gm-id">
            <input type="hidden" name="existing_image_url" id="gm-existing-img">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 id="gm-title" class="text-xl font-bold">New question group</h3>
                <button type="button" onclick="closeModal('g-modal')" class="text-2xl leading-none">&times;</button>
            </div>
            <div class="p-6 space-y-4">
                <p class="text-sm text-gray-600">A group is a passage (reading comprehension, common data, a table or figure) shared by several questions.
                    In the exam the passage stays on the left while the candidate answers each sub-question on the right.
                    After saving you add the sub-questions to the group.</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold">Title (only seen in admin)</label>
                        <input name="title" id="gm-name" class="w-full border p-2 rounded" placeholder="e.g. Retail industry passage">
                    </div>
                    <div>
                        <label class="block text-sm font-bold">Section</label>
                        <select name="section_id" id="gm-section" class="w-full border p-2 rounded">
                            <option value="">No section</option>
                            <?php foreach ($sections as $s): ?>
                                <option value="<?= $s['section_id'] ?>"><?= htmlspecialchars($s['section_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold">Passage text</label>
                    <textarea name="passage_text" id="gm-text" rows="8" class="w-full border p-2 rounded" placeholder="Paste the passage here, or upload it as an image below"></textarea>
                </div>
                <div class="p-3 bg-purple-50 border border-purple-200 rounded">
                    <label class="block text-sm font-bold text-purple-800">Passage image (optional)</label>
                    <div class="flex items-center gap-3 mt-1">
                        <img id="gm-thumb" class="thumb hidden">
                        <label id="gm-remove-wrap" class="text-sm hidden"><input type="checkbox" name="remove_image" value="1"> Remove image</label>
                        <input type="file" name="passage_file" id="gm-file" accept="image/*">
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 border-t flex justify-end gap-3">
                <button type="button" onclick="closeModal('g-modal')" class="px-5 py-2 border rounded">Cancel</button>
                <button class="px-6 py-2 bg-purple-700 text-white rounded font-bold">Save group</button>
            </div>
        </form>
    </div>

    <!-- ============ QUESTION BANK MODAL ============ -->
    <div id="b-modal" class="modal">
        <form method="POST" class="bg-white rounded-xl shadow-2xl w-full max-w-4xl">
            <input type="hidden" name="subject" value="<?= $subject_id ?>"><input type="hidden" name="set" value="<?= $set_no ?>">
            <input type="hidden" name="pb_action" value="add_from_bank">
            <input type="hidden" name="section_id" id="bm-section">
            <input type="hidden" name="passage_id" id="bm-passage">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 class="text-xl font-bold">Add from question bank</h3>
                <button type="button" onclick="closeModal('b-modal')" class="text-2xl leading-none">&times;</button>
            </div>
            <div class="p-6">
                <p id="bm-where" class="text-sm bg-gray-50 border rounded px-3 py-2 mb-3"></p>
                <p class="text-sm text-gray-600 mb-3">These are <?= htmlspecialchars($subject_name) ?> questions uploaded on the admin page (single or bulk) that are not in any test yet.</p>
                <?php if (!$bank): ?>
                    <p class="text-gray-500 italic">The question bank for this subject is empty.</p>
                <?php else: ?>
                <div class="max-h-[60vh] overflow-y-auto space-y-2">
                    <?php foreach ($bank as $b): ?>
                    <label class="flex items-center gap-3 border rounded p-2 cursor-pointer hover:bg-blue-50">
                        <input type="checkbox" name="question_ids[]" value="<?= $b['id'] ?>" class="w-5 h-5">
                        <?php if ($b['q_type'] === 'CODE'): ?>
                            <span style="background:#111827;color:#86efac;max-width:220px" class="px-3 py-2 text-sm font-mono">&lt;/&gt; <?= htmlspecialchars($b['coding_title'] ?? 'Coding') ?></span>
                        <?php else: ?>
                            <img src="<?= htmlspecialchars(img_src($b['q_text_url'])) ?>" class="thumb" loading="lazy">
                        <?php endif; ?>
                        <span class="text-sm">#<?= $b['id'] ?>, <?= $b['q_type'] ?>, <?= (float)$b['marks'] ?> mark(s)</span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="px-6 py-4 border-t flex justify-end gap-3">
                <button type="button" onclick="closeModal('b-modal')" class="px-5 py-2 border rounded">Cancel</button>
                <button class="px-6 py-2 bg-blue-600 text-white rounded font-bold" <?= $bank ? '' : 'disabled' ?>>Add ticked questions</button>
            </div>
        </form>
    </div>

    <?php endif; ?>
</div>

<script>window.CODE_COMPILERS_URL = <?= json_encode(defined('CODE_COMPILERS_URL') ? CODE_COMPILERS_URL : 'vendor/compilers') ?>;</script>
<script src="modules/coderun/runner.js?v=1"></script>
<script>
const QUESTIONS = <?= json_encode((object)$questions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_IGNORE) ?>;
const GROUPS = <?= json_encode((object)array_map(fn($g) => array_diff_key($g, ['questions' => 1]), $groups), JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_IGNORE) ?>;
const SECTION_NAMES = <?= json_encode((object)array_column($section_list, 'name', 'key'), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const imgSrc = u => { const m = String(u || '').match(/id=([\w-]+)/) || String(u || '').match(/\/d\/([\w-]+)\/view/); return m ? `https://drive.google.com/thumbnail?id=${m[1]}&sz=s800` : u; };

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function whereText(sectionKey, passageId) {
    const sec = SECTION_NAMES[sectionKey] || 'No section';
    return passageId ? `Group <b>${esc(GROUPS[passageId]?.title)}</b> in section <b>${esc(sec)}</b>` : `Section <b>${esc(sec)}</b>`;
}

// ---------- Question modal ----------
let optionKey = 0;
let editing = null;

function openQuestion(sectionKey, passageId, id) {
    editing = id ? QUESTIONS[id] : null;
    const f = document.getElementById('q-modal').querySelector('form');
    f.reset();
    document.getElementById('qm-title').textContent = editing ? `Edit question #${id}` : (passageId ? 'Add sub-question' : 'Add question');
    document.getElementById('qm-id').value = id || 0;
    document.getElementById('qm-section').value = sectionKey || '';
    document.getElementById('qm-passage').value = passageId || '';
    document.getElementById('qm-where').innerHTML = whereText(sectionKey, passageId);
    document.getElementById('qm-type').value = editing ? editing.q_type : 'MCQ';
    document.getElementById('qm-marks').value = editing ? parseFloat(editing.marks) : 1;
    document.getElementById('qm-exp').value = editing ? (editing.explanation || '') : '';
    document.getElementById('qm-existing-q').value = editing ? editing.q_text_url : '';
    const thumb = document.getElementById('qm-q-thumb');
    thumb.classList.toggle('hidden', !editing);
    if (editing) thumb.src = imgSrc(editing.q_text_url);
    renderAnswerUI();
    openModal('q-modal');
}

function optionRow(type, opt, checked) {
    const k = optionKey++;
    const kind = type === 'MCQ' ? 'radio' : 'checkbox';
    return `<div class="opt-row flex items-center gap-2 p-2 border rounded bg-gray-50">
        <label class="flex items-center gap-1 text-sm whitespace-nowrap"><input type="${kind}" name="correct_idx[]" value="${k}" ${checked ? 'checked' : ''} class="w-4 h-4"> Correct</label>
        <input name="option_text[${k}]" value="${esc(opt.text)}" class="border p-1 rounded flex-grow" placeholder="Option text">
        ${opt.image ? `<img src="${esc(imgSrc(opt.image))}" class="thumb">` : ''}
        <input type="hidden" name="existing_option_url[${k}]" value="${esc(opt.image || '')}">
        <input type="file" name="option_files[${k}]" accept="image/*" class="text-xs w-44">
        <button type="button" onclick="this.closest('.opt-row').remove()" class="text-red-600 font-bold px-2" title="Remove option">&times;</button>
    </div>`;
}

function renderAnswerUI() {
    const type = document.getElementById('qm-type').value;
    const box = document.getElementById('qm-answer');
    if (type === 'NAT') {
        const lo = editing && editing.q_type === 'NAT' ? (editing.range_min ?? '') : '';
        const hi = editing && editing.q_type === 'NAT' ? (editing.range_max ?? '') : '';
        box.innerHTML = `<label class="block text-sm font-bold">Correct answer range (same value twice for an exact answer)</label>
            <div class="grid grid-cols-2 gap-4 p-3 bg-yellow-50 border rounded">
                <input type="number" step="any" name="range_min" value="${esc(lo)}" placeholder="Minimum" class="border p-2 rounded" required>
                <input type="number" step="any" name="range_max" value="${esc(hi)}" placeholder="Maximum" class="border p-2 rounded" required>
            </div>`;
        return;
    }
    const opts = (editing && editing.q_type !== 'NAT' && editing.options_json.length)
        ? editing.options_json
        : ['A', 'B', 'C', 'D'].map(l => ({ text: `Option ${l}`, image: '' }));
    const correct = editing ? editing.correct_answer_json : [];
    box.innerHTML = `<label class="block text-sm font-bold">Options: type the text and/or upload an image, and tick the correct one${type === 'MSQ' ? '(s)' : ''}</label>
        <div id="qm-options" class="space-y-2 mt-1">${opts.map(o => optionRow(type, o, correct.includes(o.text))).join('')}</div>
        <button type="button" onclick="addOption()" class="mt-2 text-sm text-blue-700 font-bold">+ Add option</button>`;
}

function addOption() {
    const type = document.getElementById('qm-type').value;
    const n = document.querySelectorAll('#qm-options .opt-row').length;
    document.getElementById('qm-options').insertAdjacentHTML('beforeend', optionRow(type, { text: `Option ${String.fromCharCode(65 + n)}`, image: '' }, false));
}

function submitQuestion(f) {
    if (!document.getElementById('qm-existing-q').value && !document.getElementById('qm-q-file').files.length) {
        alert('Choose the question image.'); return false;
    }
    if (document.getElementById('qm-type').value !== 'NAT') {
        if (document.querySelectorAll('#qm-options .opt-row').length < 2) { alert('Add at least two options.'); return false; }
        if (!f.querySelector('input[name="correct_idx[]"]:checked')) { alert('Tick the correct option.'); return false; }
    }
    document.getElementById('loader').style.display = 'flex';
    return true;
}

// ---------- Coding question modal ----------
const CODING_LANGS = <?= json_encode($coding_langs) ?>;
const CODING_STARTER = <?= json_encode(CODING_STARTER) ?>;
let testKey = 0;
let codingEditing = null;
let starterCode = {};

function openCoding(sectionKey, id) {
    const q = id ? QUESTIONS[id] : null;
    codingEditing = q && q.coding ? q.coding : null;
    const p = codingEditing || {};
    const f = document.getElementById('c-modal').querySelector('form');
    f.reset();
    document.getElementById('cm-title').textContent = id ? `Edit coding question #${id}` : 'Add coding question';
    document.getElementById('cm-id').value = id || 0;
    document.getElementById('cm-section').value = sectionKey || '';
    document.getElementById('cm-where').innerHTML = whereText(sectionKey, 0);
    document.getElementById('cm-name').value = p.title || '';
    document.getElementById('cm-marks').value = q ? parseFloat(q.marks) : 10;
    document.getElementById('cm-tl').value = p.time_limit_ms ? p.time_limit_ms / 1000 : 2;
    document.getElementById('cm-statement').value = p.statement || '';
    document.getElementById('cm-in').value = p.input_format || '';
    document.getElementById('cm-out').value = p.output_format || '';
    document.getElementById('cm-cons').value = p.constraints_text || '';
    document.getElementById('cm-existing-q').value = q ? (q.q_text_url || '') : '';
    const hasImg = !!(q && q.q_text_url);
    document.getElementById('cm-thumb').classList.toggle('hidden', !hasImg);
    document.getElementById('cm-remove-wrap').classList.toggle('hidden', !hasImg);
    if (hasImg) document.getElementById('cm-thumb').src = imgSrc(q.q_text_url);
    const langs = p.languages || Object.keys(CODING_LANGS);
    document.querySelectorAll('.cm-lang').forEach(cb => cb.checked = langs.includes(cb.value));
    starterCode = {};
    Object.keys(CODING_LANGS).forEach(l => starterCode[l] = (p.starter && p.starter[l]) || CODING_STARTER[l]);
    renderStarterTabs();
    document.getElementById('cm-tests').innerHTML = '';
    const tests = (p.tests && p.tests.length) ? p.tests : [{ input: '', output: '', sample: true }, { input: '', output: '', sample: false }];
    tests.forEach(t => addTest(t));
    document.getElementById('cm-sol-status').textContent = '';
    openModal('c-modal');
}

let starterLang = null;
function renderStarterTabs() {
    const langs = [...document.querySelectorAll('.cm-lang:checked')].map(cb => cb.value);
    document.querySelectorAll('#cm-starter-boxes textarea').forEach(t => starterCode[t.dataset.lang] = t.value);
    if (!langs.includes(starterLang)) starterLang = langs[0] || null;
    document.getElementById('cm-starter-tabs').innerHTML = langs.map(l =>
        `<button type="button" onclick="starterLang='${l}'; renderStarterTabs()" class="px-3 py-1 text-sm rounded-t border ${l === starterLang ? 'bg-gray-800 text-white' : 'bg-white'}">${esc(CODING_LANGS[l])}</button>`).join('');
    document.getElementById('cm-starter-boxes').innerHTML = langs.map(l =>
        `<textarea name="starter[${l}]" data-lang="${l}" rows="8" spellcheck="false" class="w-full border p-2 rounded font-mono text-sm ${l === starterLang ? '' : 'hidden'}">${esc(starterCode[l])}</textarea>`).join('');
}

function addTest(t) {
    t = t || { input: '', output: '', sample: false };
    const k = testKey++;
    const n = document.querySelectorAll('#cm-tests .test-row').length + 1;
    document.getElementById('cm-tests').insertAdjacentHTML('beforeend', `<div class="test-row grid grid-cols-12 gap-2 p-2 border rounded bg-gray-50 items-start">
        <div class="col-span-1 text-sm font-bold pt-2 test-no">#${n}</div>
        <div class="col-span-5"><div class="text-xs text-gray-500">Input</div><textarea name="test_input[${k}]" rows="3" spellcheck="false" class="t-in w-full border p-1 rounded font-mono text-sm">${esc(t.input)}</textarea></div>
        <div class="col-span-4"><div class="text-xs text-gray-500">Expected output</div><textarea name="test_output[${k}]" rows="3" spellcheck="false" class="t-out w-full border p-1 rounded font-mono text-sm">${esc(t.output)}</textarea></div>
        <div class="col-span-2 text-sm pt-5 space-y-1">
            <label class="flex items-center gap-1"><input type="checkbox" name="test_sample[${k}]" value="1" ${t.sample ? 'checked' : ''}> Sample</label>
            <button type="button" onclick="this.closest('.test-row').remove(); renumberTests()" class="text-red-600 text-xs font-bold">Remove</button>
        </div>
    </div>`);
}
function renumberTests() { document.querySelectorAll('#cm-tests .test-no').forEach((el, i) => el.textContent = '#' + (i + 1)); }

async function runSolution() {
    const status = document.getElementById('cm-sol-status');
    const code = document.getElementById('cm-sol-code').value;
    const lang = document.getElementById('cm-sol-lang').value;
    const rows = [...document.querySelectorAll('#cm-tests .test-row')];
    if (!code.trim()) { status.textContent = 'Paste a solution first.'; return; }
    if (!rows.length) { status.textContent = 'Add test inputs first.'; return; }
    const tl = parseFloat(document.getElementById('cm-tl').value) || 2;
    try {
        const r = await CodeRunner.run(lang, code, rows.map(r => r.querySelector('.t-in').value), { timeLimitMs: tl * 1000, onStatus: s => status.textContent = s });
        if (!r.compiled) { status.textContent = 'Compilation error:\n' + r.compileLog; alert('Compilation error:\n\n' + r.compileLog); return; }
        let bad = 0;
        r.results.forEach((res, i) => {
            if (res.error) { bad++; return; }
            rows[i].querySelector('.t-out').value = res.stdout.replace(/\s+$/, '');
        });
        status.textContent = bad ? `Filled ${r.results.length - bad} outputs; ${bad} test case(s) failed (time limit or runtime error).` : `Filled all ${r.results.length} expected outputs.`;
    } catch (e) { status.textContent = 'Could not run: ' + e.message; }
}

function submitCoding(f) {
    if (!f.querySelector('.cm-lang:checked')) { alert('Allow at least one language.'); return false; }
    const rows = [...document.querySelectorAll('#cm-tests .test-row')].filter(r => r.querySelector('.t-in').value.trim() || r.querySelector('.t-out').value.trim());
    if (!rows.length) { alert('Add at least one test case.'); return false; }
    if (rows.some(r => !r.querySelector('.t-out').value.trim()) && !confirm('Some test cases have an empty expected output. Save anyway?')) return false;
    document.getElementById('loader').style.display = 'flex';
    return true;
}

// ---------- Group modal ----------
function openGroup(sectionKey, passageId) {
    const g = passageId ? GROUPS[passageId] : null;
    const f = document.getElementById('g-modal').querySelector('form');
    f.reset();
    document.getElementById('gm-title').textContent = g ? 'Edit passage' : 'New question group';
    document.getElementById('gm-id').value = passageId || 0;
    document.getElementById('gm-name').value = g ? g.title : '';
    document.getElementById('gm-text').value = g ? (g.passage_text || '') : '';
    document.getElementById('gm-section').value = g ? (g.section_id || '') : (sectionKey || '');
    document.getElementById('gm-existing-img').value = g ? (g.passage_image_url || '') : '';
    const hasImg = !!(g && g.passage_image_url);
    document.getElementById('gm-thumb').classList.toggle('hidden', !hasImg);
    document.getElementById('gm-remove-wrap').classList.toggle('hidden', !hasImg);
    if (hasImg) document.getElementById('gm-thumb').src = imgSrc(g.passage_image_url);
    openModal('g-modal');
}

function submitGroup(f) {
    const hasImg = document.getElementById('gm-existing-img').value && !f.remove_image.checked;
    if (!document.getElementById('gm-text').value.trim() && !hasImg && !document.getElementById('gm-file').files.length) {
        alert('Add the passage text or a passage image.'); return false;
    }
    document.getElementById('loader').style.display = 'flex';
    return true;
}

// ---------- Bank modal ----------
function openBank(sectionKey, passageId) {
    document.getElementById('bm-section').value = sectionKey || '';
    document.getElementById('bm-passage').value = passageId || '';
    document.getElementById('bm-where').innerHTML = 'Adding to: ' + whereText(sectionKey, passageId);
    openModal('b-modal');
}

<?php if ($open_group && isset($groups[$open_group])): ?>
openQuestion(<?= (int)($groups[$open_group]['section_id'] ?? 0) ?>, <?= (int)$open_group ?>, 0);
<?php endif; ?>
</script>
</body>
</html>
