<?php
// admin.php - Google Drive upload + single question form + bulk folder/file upload
session_start();
include 'config.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("Database Connection failed: " . $conn->connect_error); }
$conn->set_charset('utf8mb4');

$DRIVE_ERROR = '';

// --- GOOGLE DRIVE UPLOAD FUNCTION ---
function uploadToGoogleDrive($file_array) {
    global $DRIVE_ERROR;
    $webAppUrl = APPS_SCRIPT_URL;

    if (!isset($file_array) || $file_array['error'] !== UPLOAD_ERR_OK) {
        $DRIVE_ERROR = 'PHP upload error code ' . ($file_array['error'] ?? 'none');
        return null;
    }

    $fileName = $file_array['name'];
    $fileData = base64_encode(file_get_contents($file_array['tmp_name']));
    $postData = http_build_query(['name' => $fileName, 'data' => $fileData]);

    $ch = curl_init($webAppUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    if ($response === false) { $DRIVE_ERROR = 'cURL: ' . curl_error($ch); curl_close($ch); return null; }
    curl_close($ch);

    $data = json_decode($response, true);
    if ($data && ($data['status'] ?? '') === 'success') return $data['url'];
    $DRIVE_ERROR = 'Drive response: ' . substr(strip_tags((string)$response), 0, 200);
    return null;
}

function fetch_subjects($conn) {
    $result = $conn->query("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name");
    $subjects = [];
    if ($result) { while ($row = $result->fetch_assoc()) { $subjects[] = $row; } }
    return $subjects;
}

function num_or_null($v) {
    return ($v === null || $v === '' || !is_numeric($v)) ? null : (float)$v;
}

// ================= BULK: ONE QUESTION PER AJAX REQUEST =================
// (one request per question keeps us under PHP max_file_uploads = 20)
if (($_POST['action'] ?? '') === 'bulk_one') {
    header('Content-Type: application/json');
    $fail = function ($msg) { echo json_encode(['ok' => false, 'error' => $msg]); exit(); };

    $subject_id = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
    $q_type = in_array($_POST['q_type'] ?? '', ['MCQ', 'MSQ', 'NAT'], true) ? $_POST['q_type'] : 'MCQ';
    $marks = (float)($_POST['marks'] ?? 1);
    $explanation = $_POST['explanation'] ?? '';

    if (empty($_FILES['q_file']['name'])) $fail('Question image missing');
    $q_text_url = uploadToGoogleDrive($_FILES['q_file']);
    if (!$q_text_url) $fail('Question image upload failed. ' . $DRIVE_ERROR);

    $options = [];
    $correct = [];
    $range_min = null; $range_max = null;

    if ($q_type === 'NAT') {
        $range_min = num_or_null($_POST['range_min'] ?? null);
        $range_max = num_or_null($_POST['range_max'] ?? null);
        if ($range_min === null || $range_max === null) $fail('NAT range missing');
    } else {
        $letters = array_filter(array_map('trim', explode(',', strtoupper($_POST['correct'] ?? ''))));
        for ($i = 0; isset($_FILES["opt_$i"]); $i++) {
            $letter = chr(65 + $i);
            $text = "Option $letter";
            $url = uploadToGoogleDrive($_FILES["opt_$i"]);
            if (!$url) $fail("Option $letter upload failed. " . $DRIVE_ERROR);
            $options[] = ['text' => $text, 'image' => $url];
            if (in_array($letter, $letters, true)) $correct[] = $text;
        }
        if (!$options) $fail('No option images');
        if (!$correct) $fail('Correct answer missing / does not match options');
    }

    if (!empty($_FILES['exp_file']['name'])) {
        $expUrl = uploadToGoogleDrive($_FILES['exp_file']);
        if ($expUrl) $explanation = $expUrl;
    }

    $options_json = json_encode($options);
    $correct_json = json_encode($correct);

    $stmt = $conn->prepare("INSERT INTO questions (subject_id, q_type, q_text_url, options_json, correct_answer_json, explanation, marks, range_min, range_max, set_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
    if (!$stmt) $fail('DB prepare: ' . $conn->error);
    $stmt->bind_param("isssssddd", $subject_id, $q_type, $q_text_url, $options_json, $correct_json, $explanation, $marks, $range_min, $range_max);
    if (!$stmt->execute()) $fail('DB insert: ' . $stmt->error);

    echo json_encode(['ok' => true, 'id' => $conn->insert_id]);
    exit();
}

// Handle Subject add
if (isset($_POST['add_subject'])) {
    $subject_name = trim($_POST['new_subject_name']);
    if (!empty($subject_name)) {
        $stmt = $conn->prepare("INSERT INTO subjects (subject_name) VALUES (?)");
        $stmt->bind_param("s", $subject_name);
        $stmt->execute();
    }
    header("Location: admin.php"); exit();
}

// --- HANDLE SINGLE QUESTION CRUD ---
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($action === 'delete' && $id > 0) {
        $conn->query("DELETE FROM questions WHERE id=$id");
        $_SESSION['message'] = "Question deleted!";
        header("Location: admin.php"); exit();
    }

    if ($action === 'create' || $action === 'update') {
        $subject_id = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
        $q_type = $_POST['q_type'];
        $marks = (float)$_POST['marks'];
        $explanation = $_POST['explanation'] ?? '';

        $q_text_url = $_POST['existing_q_url'] ?? '';
        if (!empty($_FILES['q_file']['name'])) {
            $driveUrl = uploadToGoogleDrive($_FILES['q_file']);
            if ($driveUrl) $q_text_url = $driveUrl;
        }

        if (!empty($_FILES['exp_file']['name'])) {
            $driveUrlExp = uploadToGoogleDrive($_FILES['exp_file']);
            if ($driveUrlExp) $explanation = $driveUrlExp;
        }

        $options = [];
        $correct_answers = $_POST['correct_answers'] ?? [];
        $range_min = num_or_null($_POST['range_min'] ?? null);
        $range_max = num_or_null($_POST['range_max'] ?? null);

        if ($q_type !== 'NAT') {
            foreach (($_POST['option_text'] ?? []) as $index => $text) {
                $opt_img = $_POST['existing_option_url'][$index] ?? '';
                if (!empty($_FILES['option_files']['name'][$index])) {
                    $file_data = [
                        'name' => $_FILES['option_files']['name'][$index],
                        'tmp_name' => $_FILES['option_files']['tmp_name'][$index],
                        'error' => $_FILES['option_files']['error'][$index]
                    ];
                    $driveUrlOpt = uploadToGoogleDrive($file_data);
                    if ($driveUrlOpt) $opt_img = $driveUrlOpt;
                }
                $options[] = ['text' => $text, 'image' => $opt_img];
            }
        }

        $options_json = json_encode($options);
        $correct_answer_json = json_encode(is_array($correct_answers) ? array_values($correct_answers) : [$correct_answers]);

        if ($action === 'create') {
            $stmt = $conn->prepare("INSERT INTO questions (subject_id, q_type, q_text_url, options_json, correct_answer_json, explanation, marks, range_min, range_max, set_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->bind_param("isssssddd", $subject_id, $q_type, $q_text_url, $options_json, $correct_answer_json, $explanation, $marks, $range_min, $range_max);
        } else {
            $stmt = $conn->prepare("UPDATE questions SET subject_id=?, q_type=?, q_text_url=?, options_json=?, correct_answer_json=?, explanation=?, marks=?, range_min=?, range_max=? WHERE id=?");
            $stmt->bind_param("isssssdddi", $subject_id, $q_type, $q_text_url, $options_json, $correct_answer_json, $explanation, $marks, $range_min, $range_max, $id);
        }

        $_SESSION['message'] = $stmt->execute() ? "Saved to database and Google Drive." : "DB error: " . $stmt->error;
        header("Location: admin.php"); exit();
    }
}

// Fetch subjects and questions
$subjects = fetch_subjects($conn);
$q_res = $conn->query("SELECT q.*, s.subject_name FROM questions q LEFT JOIN subjects s ON q.subject_id = s.subject_id ORDER BY q.id DESC LIMIT 15");
$questions = [];
while ($row = $q_res->fetch_assoc()) {
    $row['options_json'] = json_decode($row['options_json'], true) ?? [];
    $row['correct_answer_json'] = json_decode($row['correct_answer_json'], true) ?? [];
    $questions[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drive Admin | GATE Mock Test</title>
    <script src="<?= CDN_TAILWIND ?>"></script>
    <style>
        .loading-overlay { display: none; position: fixed; inset: 0; background: rgba(255,255,255,0.8); z-index: 1000; justify-content: center; align-items: center; flex-direction: column; }
        .thumb { max-height: 48px; max-width: 160px; object-fit: contain; border: 1px solid #e5e7eb; background: #fff; }
    </style>
</head>
<body class="p-8 bg-gray-100">

<div id="loader" class="loading-overlay">
    <div class="animate-spin rounded-full h-16 w-16 border-b-4 border-blue-600"></div>
    <p class="mt-4 font-bold text-blue-600">Uploading to Google Drive... Please wait.</p>
</div>

<div class="max-w-6xl mx-auto">
    <h1 class="text-3xl font-extrabold mb-8 border-b-4 border-blue-600 pb-2">GATE Admin (Google Drive Direct)</h1>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="bg-green-500 text-white p-4 rounded mb-4"><?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?></div>
    <?php endif; ?>

    <!-- Mode tabs -->
    <div class="flex space-x-2 mb-4">
        <button id="tab-single" onclick="showTab('single')" class="px-5 py-2 rounded-t font-bold bg-white text-blue-700 shadow">Single question</button>
        <button id="tab-bulk" onclick="showTab('bulk')" class="px-5 py-2 rounded-t font-bold bg-gray-300 text-gray-700">Folder / bulk upload</button>
    </div>

    <!-- ================= SINGLE ================= -->
    <div id="panel-single" class="bg-white p-6 rounded-xl shadow-lg mb-8">
        <h2 class="text-xl font-bold mb-4">Add/Edit Question</h2>
        <form id="q-form" method="POST" enctype="multipart/form-data" onsubmit="showLoader()">
            <input type="hidden" name="action" id="form-action" value="create">
            <input type="hidden" name="id" id="q-id" value="0">
            <input type="hidden" name="existing_q_url" id="existing_q_url" value="">

            <div class="grid grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm">Question Type</label>
                    <select name="q_type" id="q_type" class="w-full border p-2 rounded" onchange="renderUI(this.value)">
                        <option value="MCQ">MCQ</option>
                        <option value="MSQ">MSQ</option>
                        <option value="NAT">NAT</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm">Subject</label>
                    <select name="subject_id" id="subject_id" class="w-full border p-2 rounded">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?php echo $s['subject_id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm">Marks</label>
                    <input type="number" step="0.5" name="marks" id="marks" value="1.0" class="w-full border p-2 rounded">
                </div>
            </div>

            <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded">
                <label class="block font-bold text-blue-700">Question Image (Upload to Drive)</label>
                <input type="file" name="q_file" id="q_file" accept="image/*" class="w-full mt-1">
                <p id="q_url_display" class="text-xs text-gray-500 mt-1 italic"></p>
            </div>

            <div id="dynamic-container"></div>

            <div class="mt-4 p-4 border rounded">
                <label class="block font-bold">Solution/Explanation</label>
                <input type="file" name="exp_file" accept="image/*" class="mb-2">
                <textarea name="explanation" id="explanation" class="w-full border p-2 rounded" rows="2" placeholder="Text explanation..."></textarea>
            </div>

            <div class="mt-6 flex justify-end space-x-4">
                <button type="button" id="cancel-btn" class="bg-gray-500 text-white px-6 py-2 rounded hidden" onclick="resetForm()">Cancel Edit</button>
                <button type="submit" class="bg-blue-600 text-white px-8 py-2 rounded font-bold shadow-lg">Save Question</button>
            </div>
        </form>

        <form method="POST" class="mt-6 pt-4 border-t flex space-x-2">
            <input type="text" name="new_subject_name" placeholder="New subject name" class="border p-2 rounded flex-grow">
            <button name="add_subject" value="1" class="bg-gray-700 text-white px-4 py-2 rounded">Add subject</button>
        </form>
    </div>

    <!-- ================= BULK ================= -->
    <div id="panel-bulk" class="bg-white p-6 rounded-xl shadow-lg mb-8 hidden">
        <h2 class="text-xl font-bold mb-2">Folder / bulk upload</h2>
        <p class="text-sm text-gray-600 mb-4">
            File names: <code>Q01_question.png</code>, <code>Q01_option1.png</code> … <code>Q01_option4.png</code>,
            optional <code>Q01_explanation.png</code>. Optional <code>answer_key.csv</code> with columns
            <code>Q,Correct,Marks</code> (Correct like <code>B</code>, <code>A;C</code> for MSQ, or <code>3.77:3.79</code> for NAT).
        </p>

        <div class="grid grid-cols-3 gap-4 mb-4">
            <div class="p-4 bg-blue-50 border border-blue-200 rounded">
                <label class="block font-bold text-blue-700 mb-1">Select folder</label>
                <input type="file" id="bulk-folder" webkitdirectory directory multiple class="w-full text-sm">
            </div>
            <div class="p-4 bg-blue-50 border border-blue-200 rounded">
                <label class="block font-bold text-blue-700 mb-1">Or select files</label>
                <input type="file" id="bulk-files" multiple accept="image/*,.csv" class="w-full text-sm">
            </div>
            <div class="p-4 bg-gray-50 border rounded">
                <label class="block font-bold mb-1">Subject for all</label>
                <select id="bulk-subject" class="w-full border p-2 rounded">
                    <option value="">Select Subject</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?php echo $s['subject_id']; ?>"><?php echo htmlspecialchars($s['subject_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="block text-sm mt-2">Default marks (if no CSV)</label>
                <input type="number" step="0.5" id="bulk-marks" value="1" class="w-full border p-2 rounded">
            </div>
        </div>

        <div id="bulk-summary" class="text-sm text-gray-700 mb-2"></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm border hidden" id="bulk-table">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-2 border">Q</th>
                        <th class="p-2 border">Question</th>
                        <th class="p-2 border">Options</th>
                        <th class="p-2 border">Type</th>
                        <th class="p-2 border">Correct</th>
                        <th class="p-2 border">Marks</th>
                        <th class="p-2 border">Status</th>
                    </tr>
                </thead>
                <tbody id="bulk-body"></tbody>
            </table>
        </div>

        <div class="mt-4 flex justify-end space-x-3">
            <button id="bulk-reload" onclick="location.reload()" class="bg-gray-500 text-white px-6 py-2 rounded hidden">Refresh list</button>
            <button id="bulk-start" onclick="startBulk()" disabled class="bg-blue-600 text-white px-8 py-2 rounded font-bold shadow-lg disabled:opacity-40">Upload all</button>
        </div>
    </div>

    <!-- ================= LIST ================= -->
    <div class="bg-white p-6 rounded-xl shadow-lg">
        <h2 class="text-xl font-bold mb-4">Questions List (latest 15)</h2>
        <div class="space-y-4">
            <?php foreach ($questions as $q): ?>
            <div class="border p-4 rounded-lg flex justify-between items-center bg-white shadow-sm">
                <div class="flex items-center space-x-4">
                    <img src="<?php echo htmlspecialchars($q['q_text_url']); ?>" class="h-16 w-16 object-contain border rounded bg-gray-50">
                    <div>
                        <div class="font-bold text-blue-700">#<?php echo $q['id']; ?> (<?php echo $q['q_type']; ?>)</div>
                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($q['subject_name'] ?? ''); ?> | <?php echo $q['marks']; ?> Marks</div>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button onclick='editQ(<?php echo json_encode($q, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="bg-yellow-500 text-white px-3 py-1 rounded">Edit</button>
                    <form method="POST" onsubmit="return confirm('Delete?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $q['id']; ?>">
                        <button class="bg-red-500 text-white px-3 py-1 rounded">Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
const container = document.getElementById('dynamic-container');
const loader = document.getElementById('loader');

function showLoader() { loader.style.display = 'flex'; }

function showTab(t) {
    const single = t === 'single';
    document.getElementById('panel-single').classList.toggle('hidden', !single);
    document.getElementById('panel-bulk').classList.toggle('hidden', single);
    document.getElementById('tab-single').className = 'px-5 py-2 rounded-t font-bold ' + (single ? 'bg-white text-blue-700 shadow' : 'bg-gray-300 text-gray-700');
    document.getElementById('tab-bulk').className = 'px-5 py-2 rounded-t font-bold ' + (!single ? 'bg-white text-blue-700 shadow' : 'bg-gray-300 text-gray-700');
}

function renderUI(type, data = null) {
    container.innerHTML = '';
    if (type === 'NAT') {
        container.innerHTML = `
        <div class="grid grid-cols-2 gap-4 p-4 bg-yellow-50 border rounded">
            <input type="number" step="any" name="range_min" value="${data?.range_min ?? ''}" placeholder="Min Value" class="border p-2" required>
            <input type="number" step="any" name="range_max" value="${data?.range_max ?? ''}" placeholder="Max Value" class="border p-2" required>
        </div>`;
        return;
    }
    let opts = (data && data.options_json.length) ? data.options_json : [{text:'Option A'}, {text:'Option B'}, {text:'Option C'}, {text:'Option D'}];
    let ans = data ? data.correct_answer_json : [];
    let inputType = type === 'MCQ' ? 'radio' : 'checkbox';
    opts.forEach((o) => {
        let isChecked = ans.includes(o.text) ? 'checked' : '';
        let img = o.image ? `<img src="${o.image}" class="thumb">` : '';
        container.innerHTML += `
        <div class="flex items-center space-x-2 mb-2 p-2 border rounded bg-gray-50">
            <input type="${inputType}" name="correct_answers[]" value="${o.text}" ${isChecked}>
            <input type="text" name="option_text[]" value="${o.text}" class="border p-1 flex-grow">
            ${img}
            <input type="file" name="option_files[]" accept="image/*" class="text-xs w-40">
            <input type="hidden" name="existing_option_url[]" value="${o.image || ''}">
        </div>`;
    });
}

function editQ(q) {
    showTab('single');
    document.getElementById('form-action').value = 'update';
    document.getElementById('q-id').value = q.id;
    document.getElementById('q_type').value = q.q_type;
    document.getElementById('subject_id').value = q.subject_id ?? '';
    document.getElementById('marks').value = q.marks;
    document.getElementById('explanation').value = q.explanation ?? '';
    document.getElementById('existing_q_url').value = q.q_text_url;
    document.getElementById('q_url_display').innerText = "Current Drive URL: " + q.q_text_url;
    document.getElementById('cancel-btn').classList.remove('hidden');
    renderUI(q.q_type, q);
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function resetForm() { location.reload(); }

// ================= BULK LOGIC =================
let bulkQs = [];

document.getElementById('bulk-folder').addEventListener('change', e => handleBulkFiles(e.target.files));
document.getElementById('bulk-files').addEventListener('change', e => handleBulkFiles(e.target.files));

function parseCSV(text) {
    const lines = text.replace(/\r/g, '').split('\n').filter(l => l.trim());
    if (!lines.length) return {};
    const head = lines[0].split(',').map(h => h.trim().toLowerCase());
    const iQ = head.indexOf('q'), iC = head.indexOf('correct'), iM = head.indexOf('marks');
    const out = {};
    lines.slice(1).forEach(l => {
        const c = l.split(',').map(x => x.trim());
        const n = parseInt(c[iQ]);
        if (!isNaN(n)) out[n] = { correct: iC >= 0 ? c[iC] : '', marks: iM >= 0 ? c[iM] : '' };
    });
    return out;
}

async function handleBulkFiles(fileList) {
    const files = [...fileList];
    const map = {};
    let keyFile = null, ignored = 0;

    files.forEach(f => {
        const name = f.name;
        if (/answer[_ -]?key.*\.csv$/i.test(name) || /\.csv$/i.test(name)) { keyFile = f; return; }
        const m = name.match(/^Q0*(\d+)[_-](question|explanation|solution|option\s*(\d+)|opt\s*(\d+))\.(png|jpe?g|webp|gif)$/i);
        if (!m) { ignored++; return; }
        const n = parseInt(m[1]);
        map[n] = map[n] || { n, q: null, opts: [], exp: null };
        const kind = m[2].toLowerCase();
        if (kind === 'question') map[n].q = f;
        else if (kind === 'explanation' || kind === 'solution') map[n].exp = f;
        else map[n].opts.push({ idx: parseInt(m[3] || m[4]), file: f });
    });

    const key = keyFile ? parseCSV(await keyFile.text()) : {};
    const defMarks = document.getElementById('bulk-marks').value || '1';

    bulkQs = Object.values(map).sort((a, b) => a.n - b.n).map(q => {
        q.opts.sort((a, b) => a.idx - b.idx);
        const k = key[q.n] || {};
        let correct = (k.correct || '').toUpperCase();
        let type = 'MCQ';
        if (!q.opts.length) type = 'NAT';
        else if (/[;,|]/.test(correct)) type = 'MSQ';
        correct = type === 'NAT' ? (k.correct || '') : correct.replace(/[;|\s]+/g, ',');
        return { ...q, type, correct, marks: k.marks || defMarks, status: 'pending' };
    });

    renderBulkTable();
    document.getElementById('bulk-summary').innerText =
        `${bulkQs.length} questions found` + (keyFile ? `, answer key: ${keyFile.name}` : ', no answer key (fill Correct manually)') +
        (ignored ? `, ${ignored} files skipped (name not matching)` : '');
}

function renderBulkTable() {
    const body = document.getElementById('bulk-body');
    body.innerHTML = '';
    bulkQs.forEach((q, i) => {
        const optThumbs = q.opts.map(o => `<img class="thumb inline-block m-0.5" src="${URL.createObjectURL(o.file)}">`).join('');
        const qThumb = q.q ? `<img class="thumb" src="${URL.createObjectURL(q.q)}">` : '<span class="text-red-600">missing</span>';
        body.innerHTML += `
        <tr id="brow-${i}">
            <td class="p-2 border font-bold">${q.n}</td>
            <td class="p-2 border">${qThumb}</td>
            <td class="p-2 border">${optThumbs || '<span class="text-gray-400">none</span>'}</td>
            <td class="p-2 border">
                <select onchange="bulkQs[${i}].type=this.value" class="border p-1 rounded">
                    ${['MCQ','MSQ','NAT'].map(t => `<option ${t === q.type ? 'selected' : ''}>${t}</option>`).join('')}
                </select>
            </td>
            <td class="p-2 border"><input value="${q.correct}" oninput="bulkQs[${i}].correct=this.value" placeholder="${q.type === 'NAT' ? 'min:max' : 'A or A,C'}" class="border p-1 w-24 rounded"></td>
            <td class="p-2 border"><input type="number" step="0.5" value="${q.marks}" oninput="bulkQs[${i}].marks=this.value" class="border p-1 w-16 rounded"></td>
            <td class="p-2 border" id="bstat-${i}">${statusLabel(q)}</td>
        </tr>`;
    });
    document.getElementById('bulk-table').classList.toggle('hidden', !bulkQs.length);
    document.getElementById('bulk-start').disabled = !bulkQs.length;
}

function statusLabel(q) {
    if (q.status === 'done') return `<span class="text-green-600 font-bold">Saved #${q.id}</span>`;
    if (q.status === 'uploading') return '<span class="text-blue-600">Uploading…</span>';
    if (q.status === 'error') return `<span class="text-red-600">${q.error}</span>`;
    return '<span class="text-gray-500">Pending</span>';
}

function setStatus(i) { document.getElementById('bstat-' + i).innerHTML = statusLabel(bulkQs[i]); }

async function startBulk() {
    const subject = document.getElementById('bulk-subject').value;
    if (!subject && !confirm('No subject selected. Continue without subject?')) return;

    const btn = document.getElementById('bulk-start');
    btn.disabled = true;
    let ok = 0, bad = 0;

    for (let i = 0; i < bulkQs.length; i++) {
        const q = bulkQs[i];
        if (q.status === 'done') continue; // re-click retries only the failed ones
        if (!q.q) { q.status = 'error'; q.error = 'No question image'; setStatus(i); bad++; continue; }

        const fd = new FormData();
        fd.append('action', 'bulk_one');
        fd.append('subject_id', subject);
        fd.append('q_type', q.type);
        fd.append('marks', q.marks);
        fd.append('q_file', q.q, q.q.name);
        if (q.exp) fd.append('exp_file', q.exp, q.exp.name);

        if (q.type === 'NAT') {
            const parts = String(q.correct).split(':').map(s => s.trim());
            fd.append('range_min', parts[0] ?? '');
            fd.append('range_max', parts[1] ?? parts[0] ?? '');
        } else {
            fd.append('correct', String(q.correct).toUpperCase().replace(/[;|\s]+/g, ','));
            q.opts.forEach((o, j) => fd.append('opt_' + j, o.file, o.file.name));
        }

        q.status = 'uploading'; setStatus(i);
        try {
            const res = await fetch('admin.php', { method: 'POST', body: fd });
            const txt = await res.text();
            let data;
            try { data = JSON.parse(txt); } catch { throw new Error('Bad response: ' + txt.slice(0, 120)); }
            if (!data.ok) throw new Error(data.error);
            q.status = 'done'; q.id = data.id; ok++;
        } catch (err) {
            q.status = 'error'; q.error = err.message; bad++;
        }
        setStatus(i);
    }

    document.getElementById('bulk-summary').innerText = `Finished: ${ok} saved, ${bad} failed.` + (bad ? ' Fix and click "Upload all" again to retry failed ones.' : '');
    btn.disabled = false;
    document.getElementById('bulk-reload').classList.remove('hidden');
}

// Init
renderUI('MCQ');
</script>
</body>
</html>