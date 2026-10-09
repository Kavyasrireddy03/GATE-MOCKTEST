<?php
session_start();
include 'db.php';

// Load dompdf (Ensure this path matches your server structure)
require_once 'dompdf/autoload.inc.php'; 
use Dompdf\Dompdf;
use Dompdf\Options;
require_once __DIR__ . '/modules/coding.php';

/* ==========================
   ADMIN ACCESS PROTECTION
========================== */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$check_role = $conn->query("SELECT role FROM users WHERE user_id='$user_id'");
$user_data = $check_role->fetch_assoc();

if ($user_data['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

/* ==========================================================
   HELPER: CONVERT GOOGLE DRIVE LINKS TO EMBEDDABLE IMAGES
   ========================================================== */
function getEmbeddableImageUrl($url) {
    if (empty($url)) return '';
    if (preg_match('/id=([a-zA-Z0-9_-]+)/', $url, $matches)) {
        $file_id = $matches[1];
        return "https://drive.google.com/thumbnail?id=" . $file_id . "&sz=s1000";
    }
    return $url; 
}

/* ==========================================================
   EXPORT TEST RESULT AS STANDALONE HTML (WITH NAT RANGE CHECK)
   ========================================================== */
if (isset($_POST['export_responses'])) {
    $attempt_id = $_POST['attempt_id'];
    
    // 1. Get attempt metadata
    $meta_query = "SELECT u.name, s.subject_name, t.subject_id, t.set_no, t.start_time, t.score, t.total_marks, t.time_taken_seconds, t.attempted_questions 
                   FROM test_attempts t 
                   JOIN users u ON t.user_id = u.user_id 
                   JOIN subjects s ON t.subject_id = s.subject_id 
                   WHERE t.attempt_id = '$attempt_id'";
    $meta = $conn->query($meta_query)->fetch_assoc();

    $subject_id = $meta['subject_id'];
    $set_no = $meta['set_no'];

    // 2. Fetch ALL questions for this Set
    $questions_query = "SELECT * FROM questions WHERE subject_id = '$subject_id' AND set_no = '$set_no' ORDER BY id ASC";
    $questions_result = $conn->query($questions_query);

    // 3. Build HTML report
    $html = "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Result - {$meta['name']}</title><style>
                body { font-family: -apple-system, system-ui, sans-serif; margin: 0; padding: 2rem; background-color: #f9f9f9; color: #333; line-height: 1.5; }
                .container { max-width: 850px; margin: auto; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
                .header, .summary { padding: 1.5rem; border-bottom: 1px solid #eee; }
                .header h1 { margin: 0; font-size: 1.6rem; color: #111; }
                .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; text-align: center; }
                .summary-item { background: #f0f4f8; padding: 1rem; border-radius: 8px; }
                .summary-item h3 { margin: 0 0 0.4rem 0; color: #555; font-size: 0.75rem; text-transform: uppercase; }
                .summary-item p { margin: 0; font-size: 1.3rem; font-weight: bold; }
                .question-block { padding: 2rem; border-bottom: 1px solid #eee; }
                .q-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #ccc; padding-bottom: 0.75rem; margin-bottom: 1.2rem; }
                .status { font-weight: bold; padding: 0.3rem 0.8rem; border-radius: 4px; color: #fff; font-size: 0.85rem; }
                .status-correct { background-color: #22c55e; }
                .status-wrong { background-color: #ef4444; }
                .status-skipped { background-color: #94a3b8; }
                .q-body img { max-width: 100%; height: auto; border: 1px solid #eee; border-radius: 4px; margin-bottom: 20px; display: block; }
                .opt-box { margin-bottom: 0.8rem; padding: 12px; border: 1px solid #f1f1f1; border-radius: 6px; background: #fafafa; }
                .opt-label { font-weight: bold; font-size: 0.9rem; margin-bottom: 5px; display: block; }
                .opt-img { max-width: 100%; height: auto; display: block; margin-top: 5px; }
                .answer-result { background: #f8fafc; padding: 1.2rem; border-radius: 8px; margin-top: 1.5rem; border-left: 4px solid #cbd5e1; }
                .correct-text { color: #16a34a; font-weight: bold; }
            </style></head><body><div class='container'><div class='header'><h1>Mock Result: {$meta['subject_name']} (Set {$set_no})</h1><p><strong>Student:</strong> {$meta['name']} | <strong>Date:</strong> {$meta['start_time']}</p></div><div class='summary'><div class='summary-grid'>
                    <div class='summary-item'><h3>Your Score</h3><p style='color:#2563eb;'>{$meta['score']}</p></div>
                    <div class='summary-item'><h3>Total Marks</h3><p>{$meta['total_marks']}</p></div>
                    <div class='summary-item'><h3>Attempted</h3><p>{$meta['attempted_questions']}</p></div>
                    <div class='summary-item'><h3>Total Time</h3><p style='color:#7c3aed;'>".round($meta['time_taken_seconds']/60, 1)."m</p></div>
                </div></div>";

    $q_num = 1;
    while ($q_row = $questions_result->fetch_assoc()) {
        $q_id = $q_row['id'];
        $q_type = $q_row['q_type'];
        
        // Map user answers
        $ans_query = "SELECT selected_answer, is_correct FROM attempt_answers WHERE attempt_id = '$attempt_id' AND question_id = '$q_id'";
        $ans_res = $conn->query($ans_query);
        $user_ans = $ans_res->fetch_assoc();

        // Coding questions: problem, code, time spent and a tick / cross per test case
        if ($q_type === 'CODE') {
            $html .= coding_report_html($conn, $q_row, $q_num, $meta['subject_name'], $user_ans['selected_answer'] ?? null);
            $q_num++;
            continue;
        }

        if (!$user_ans || empty($user_ans['selected_answer']) || $user_ans['selected_answer'] == 'null') {
            $statusClass = 'status-skipped';
            $statusText = 'Skipped';
            $selected = '— (Not Attempted)';
        } else {
            $selected = $user_ans['selected_answer'];
            $statusClass = ($user_ans['is_correct'] == 1) ? 'status-correct' : 'status-wrong';
            $statusText = ($user_ans['is_correct'] == 1) ? 'Correct' : 'Wrong';

            // --- NAT RANGE EVALUATION LOGIC ---
            if ($q_type === 'NAT') {
                $decoded = json_decode($selected, true);
                $user_val = is_array($decoded) ? $decoded[0] : $selected;
                
                if (is_numeric($user_val)) {
                    $user_num = floatval($user_val);
                    $min = floatval($q_row['range_min']);
                    $max = floatval($q_row['range_max']);
                    
                    if ($user_num >= $min && $user_num <= $max) {
                        $statusClass = 'status-correct';
                        $statusText = 'Correct';
                    } else {
                        $statusClass = 'status-wrong';
                        $statusText = 'Wrong';
                    }
                }
            }
        }

        // Format the "Correct Answer" Display
        $correct_ans_display = htmlspecialchars($q_row['correct_answer_json']);
        if ($q_type === 'NAT') {
            $min = $q_row['range_min'];
            $max = $q_row['range_max'];
            if ($min !== null && $max !== null) {
                $correct_ans_display = ($min == $max) ? $min : "$min to $max";
            }
        }
        
        $html .= "<div class='question-block'>
                    <div class='q-header'><h2>Question {$q_num} (ID: {$q_id})</h2><span class='status $statusClass'>$statusText</span></div>
                    <div class='q-body'>
                        <p>Question Image:</p>";
        
        // Convert Question URL to working Thumbnail
        $q_image_url = getEmbeddableImageUrl($q_row['q_text_url']);
        if(!empty($q_image_url)) {
            $html .= "<img src='{$q_image_url}' alt='Question Image'>";
        }
        $html .= "</div>";

        // Render Options
        $options_list = json_decode($q_row['options_json'], true);
        if ($q_type !== 'NAT' && !empty($options_list)) {
            $html .= "<div class='options-sec'><h4>Options:</h4>";
            foreach ($options_list as $opt) {
                $html .= "<div class='opt-box'><span class='opt-label'>{$opt['text']}:</span>";
                
                $opt_image_url = getEmbeddableImageUrl($opt['image']);
                if (!empty($opt_image_url)) {
                    $html .= "<img src='{$opt_image_url}' class='opt-img' alt='Option Image'>";
                }
                $html .= "</div>";
            }
            $html .= "</div>";
        }

        $html .= "<div class='answer-result'>
                    <p><strong>Your Answer:</strong> " . htmlspecialchars($selected) . "</p>
                    <p><strong>Correct Answer:</strong> <span class='correct-text'>{$correct_ans_display}</span></p>
                  </div></div>";
        $q_num++;
    }

    $html .= "</div><center style='margin-top:20px; color:#aaa; font-size:12px;'>Generated by GATE Portal Admin</center></body></html>";

    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="Mock_Result_'.$meta['name'].'_Attempt_'.$attempt_id.'.html"');
    echo $html;
    exit();
}

/* ==========================================================
   EXPORT DAILY PROGRESS (CSV & PDF) - FIXED GROUPING
   ========================================================== */
if (isset($_POST['export_report'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $format = $_POST['export_format']; 

    $filename = "study_report_" . $start_date . "_to_" . $end_date;
    
    $export_query = "
        SELECT u.name, l.study_date, l.status, l.subject_name 
        FROM daily_study_logs l
        JOIN users u ON l.user_id = u.user_id
        WHERE l.study_date BETWEEN '$start_date' AND '$end_date'
        ORDER BY l.study_date DESC, u.name ASC
    ";
    
    $export_result = $conn->query($export_query);

    if ($export_result && $export_result->num_rows > 0) {
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $filename . '.csv');
            $output = fopen('php://output', 'w');
            fputcsv($output, array('Student Name', 'Date', 'Status', 'Subjects Studied'));
            while ($row = $export_result->fetch_assoc()) {
                fputcsv($output, $row);
            }
            fclose($output);
            exit();
        } else if ($format === 'pdf') {
            $options = new Options();
            $options->set('defaultFont', 'Helvetica');
            $dompdf = new Dompdf($options);

            $html = "
            <style>
                body { font-family: sans-serif; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { border: 1px solid #ddd; padding: 8px; font-size: 11px; }
                .date-header { background-color: #f1f5f9; font-weight: bold; color: #1e293b; text-align: left; padding: 10px; border-bottom: 2px solid #cbd5e1; }
                .status-studied { color: #10b981; font-weight: bold; }
                .status-missed { color: #f43f5e; font-weight: bold; }
                h2 { text-align: center; margin-bottom: 5px; }
                .period { text-align: center; font-size: 12px; margin-bottom: 20px; color: #666; }
            </style>
            <h2>Daily Study Progress Report</h2>
            <p class='period'>Period: $start_date to $end_date</p>
            <table>
                <thead>
                    <tr style='background:#2f3b3f; color:white;'>
                        <th>Student Name</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Subjects</th>
                    </tr>
                </thead>
                <tbody>";
            
            $currentDate = ''; 
            while ($row = $export_result->fetch_assoc()) {
                if ($row['study_date'] !== $currentDate) {
                    $currentDate = $row['study_date'];
                    $displayDate = date('l, d M Y', strtotime($currentDate));
                    $html .= "<tr><td colspan='4' class='date-header'>SECTION: $displayDate</td></tr>";
                }

                $statusText = ucfirst(str_replace('_', ' ', $row['status']));
                $statusClass = ($row['status'] == 'studied') ? 'status-studied' : 'status-missed';
                
                $html .= "<tr>
                    <td style='padding-left: 15px;'>{$row['name']}</td>
                    <td style='color:#777;'>{$row['study_date']}</td>
                    <td class='$statusClass'>$statusText</td>
                    <td>" . ($row['subject_name'] ?? 'N/A') . "</td>
                </tr>";
            }
            $html .= "</tbody></table>";

            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $dompdf->stream($filename . ".pdf", array("Attachment" => 1));
            exit();
        }
    } else {
        $error_msg = "No study logs found for the selected range.";
    }
}

/* ==========================
   ADD NEW STUDENT LOGIC
========================== */
$success_msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];

    $check_email = $conn->query("SELECT * FROM users WHERE email='$email'");
    if ($check_email->num_rows > 0) {
        $error_msg = "Email already exists!";
    } else {
        $insert = "INSERT INTO users (name, email, password, role, created_at) VALUES ('$name', '$email', '$password', 'student', NOW())";
        if ($conn->query($insert)) {
            $success_msg = "New Student Created Successfully!";
        } else {
            $error_msg = "Error creating student.";
        }
    }
}

/* ==========================
   SEARCH & STATS LOGIC
========================== */
$search_query = "";
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
    $search_query = " WHERE u.name LIKE '%$search%' ";
}

$stats_query = "
    SELECT 
        SUM(t.score) AS total_score, 
        SUM(t.total_marks) AS total_possible, 
        COUNT(t.attempt_id) AS total_attempts,
        SUM(t.correct_answers) AS chart_correct,
        SUM(t.attempted_questions - t.correct_answers) AS chart_wrong
    FROM test_attempts t
    JOIN users u ON t.user_id = u.user_id
    $search_query
";
$stats_result = $conn->query($stats_query);
$stats_data = $stats_result->fetch_assoc();

$total_score = $stats_data['total_score'] ?? 0;
$total_possible = $stats_data['total_possible'] ?? 0;
$total_attempts = $stats_data['total_attempts'] ?? 0;
$chart_correct = $stats_data['chart_correct'] ?? 0;
$chart_wrong = $stats_data['chart_wrong'] ?? 0;
$overall_percentage = ($total_possible > 0) ? round(($total_score / $total_possible) * 100, 1) : 0;

$query = "
    SELECT t.attempt_id, u.name AS student_name, s.subject_name, t.set_no, t.score, t.total_marks, t.start_time, t.attempted_questions, t.correct_answers, t.time_taken_seconds
    FROM test_attempts t
    JOIN users u ON t.user_id = u.user_id
    JOIN subjects s ON t.subject_id = s.subject_id
    $search_query
    ORDER BY t.attempt_id DESC
";

$result = $conn->query($query);
$attempts_list = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $attempts_list[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | GATE Portal</title>
    <script src="<?= CDN_TAILWIND ?>"></script>
    <script src="<?= CDN_CHARTJS ?>"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-50 p-6 md:p-10">

<div class="max-w-7xl mx-auto">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">👑 Admin Dashboard</h1>
        <a href="dashboard.php" class="bg-gray-200 px-4 py-2 rounded-lg font-bold text-sm hover:bg-gray-300">Back to Portal</a>
    </div>

    <?php if($success_msg): ?>
        <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded shadow-sm mb-6"><?= $success_msg ?></div>
    <?php endif; ?>

    <div class="grid md:grid-cols-2 gap-6 mb-10">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200">
            <h2 class="text-lg font-bold mb-4 text-gray-700">➕ Add New Student</h2>
            <form method="POST" class="space-y-4">
                <input type="text" name="name" placeholder="Full Name" required class="w-full border p-3 rounded-xl outline-none focus:ring-2 focus:ring-blue-500">
                <input type="email" name="email" placeholder="Email Address" required class="w-full border p-3 rounded-xl outline-none focus:ring-2 focus:ring-blue-500">
                <input type="password" name="password" placeholder="Password" required class="w-full border p-3 rounded-xl outline-none focus:ring-2 focus:ring-blue-500">
                <button type="submit" name="add_admin" class="w-full bg-blue-600 text-white py-3 rounded-xl font-bold hover:bg-blue-700">Register Student</button>
            </form>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200">
            <h2 class="text-lg font-bold mb-4 text-gray-700">📊 Export Study Reports</h2>
            <form method="POST" class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[10px] font-bold text-gray-400 uppercase">From</label>
                        <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" class="w-full border p-2.5 rounded-xl text-sm">
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-gray-400 uppercase">To</label>
                        <input type="date" name="end_date" value="<?= date('Y-m-d') ?>" class="w-full border p-2.5 rounded-xl text-sm">
                    </div>
                </div>
                <input type="hidden" name="export_format" id="export_format" value="csv">
                <div class="flex gap-2">
                    <button type="submit" name="export_report" onclick="document.getElementById('export_format').value='csv'" class="flex-1 bg-emerald-600 text-white py-3 rounded-xl font-bold">CSV</button>
                    <button type="submit" name="export_report" onclick="document.getElementById('export_format').value='pdf'" class="flex-1 bg-rose-600 text-white py-3 rounded-xl font-bold">PDF</button>
                </div>
            </form>
        </div>
    </div>

    <form method="GET" class="flex mb-8 gap-3">
        <input type="text" name="search" placeholder="Search students..." value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>" class="border p-3 rounded-xl w-full">
        <button class="bg-gray-900 text-white px-8 rounded-xl font-bold">Filter</button>
    </form>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
        <div class="bg-white p-6 rounded-2xl shadow-sm border flex flex-col items-center">
            <h3 class="text-xs font-bold text-gray-500 uppercase">Average Accuracy</h3>
            <div class="text-5xl font-black text-blue-600"><?= $overall_percentage ?>%</div>
        </div>
        <div class="bg-white p-6 rounded-2xl shadow-sm border flex flex-col items-center">
            <h3 class="text-xs font-bold text-gray-500 uppercase">Total Marks</h3>
            <div class="text-4xl font-extrabold text-gray-800"><?= $total_score ?> / <?= $total_possible ?></div>
        </div>
        <div class="bg-white p-6 rounded-2xl shadow-sm border flex flex-col items-center">
            <canvas id="statsChart" style="max-height: 100px;"></canvas>
            <p class="text-[10px] font-bold mt-2 text-gray-400">CORRECT VS WRONG</p>
        </div>
    </div>

    <div class="bg-white shadow-sm rounded-2xl overflow-hidden border">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-gray-500 uppercase font-bold text-[11px]">
                <tr>
                    <th class="p-5">Student</th>
                    <th class="p-5">Subject</th>
                    <th class="p-5 text-center">Score</th>
                    <th class="p-5 text-center">Accuracy</th>
                    <th class="p-5 text-center">TIME TAKEN</th>
                    <th class="p-5">Date</th>
                    <th class="p-5 text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if ($total_attempts > 0): foreach($attempts_list as $row): 
                    $acc = ($row['attempted_questions'] > 0) ? round(($row['correct_answers'] / $row['attempted_questions']) * 100, 1) : 0;
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="p-5 font-bold"><?= htmlspecialchars($row['student_name']) ?></td>
                    <td class="p-5"><?= htmlspecialchars($row['subject_name']) ?> <span class="text-xs text-blue-500 block">Set <?= $row['set_no'] ?></span></td>
                    <td class="p-5 text-center font-bold"><?= $row['score'] ?> / <?= $row['total_marks'] ?></td>
                    <td class="p-5 text-center font-bold <?= $acc >= 75 ? 'text-green-600' : 'text-red-600' ?>"><?= $acc ?>%</td>
                    <td class="p-5 text-center font-bold"><?= round($row['time_taken_seconds']/60,1) ?> mins</td>
                    <td class="p-5 text-xs text-gray-400"><?= $row['start_time'] ?></td>
                    <td class="p-5 text-center">
                        <form method="POST">
                            <input type="hidden" name="attempt_id" value="<?= $row['attempt_id'] ?>">
                            <button type="submit" name="export_responses" class="bg-blue-600 text-white px-3 py-1 rounded text-xs font-bold hover:bg-blue-700">Download</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="p-10 text-center text-gray-400">No attempts found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const ctx = document.getElementById('statsChart');
    if(ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Correct', 'Wrong'],
                datasets: [{
                    data: [<?= $chart_correct ?>, <?= $chart_wrong ?>],
                    backgroundColor: ['#10b981', '#f43f5e'],
                    borderWidth: 0
                }]
            },
            options: { cutout: '80%', plugins: { legend: { display: false } } }
        });
    }
</script>
</body>
</html>