<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit("Not logged in");
}

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    exit("Invalid JSON");
}

$user_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Unknown';

/* ---------- INSERT ATTEMPT ---------- */
$stmt = $conn->prepare("
INSERT INTO test_attempts
(user_id, set_no, subject_id, total_questions, attempted, unattempted,
 score, total_marks, total_time_seconds)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$total_q = count($data['questions']);

$stmt->bind_param(
    "iiiiiiidd",
    $user_id,
    $data['set_no'],
    $data['subject_id'],
    $total_q,
    $data['attempted'],
    $data['unattempted'],
    $data['score'],
    $data['totalMarks'],
    $data['totalTime']
);

if (!$stmt->execute()) {
    die("Attempt insert failed: " . $stmt->error);
}

$attempt_id = $stmt->insert_id;
$stmt->close();

/* ---------- INSERT QUESTION DATA ---------- */
$qstmt = $conn->prepare("
INSERT INTO test_attempt_questions
(attempt_id, question_id, user_answer, correct_answer,
 is_correct, marks_obtained, time_spent_seconds)
VALUES (?, ?, ?, ?, ?, ?, ?)
");

foreach ($data['questions'] as $q) {
    $userAns = is_array($q['user_answer']) ? json_encode($q['user_answer']) : (string)$q['user_answer'];
    $correctAns = json_encode($q['correct_answer']);

    $qstmt->bind_param(
        "iisssdi",
        $attempt_id,
        $q['question_id'],
        $userAns,
        $correctAns,
        $q['is_correct'],
        $q['marks_obtained'],
        $q['time_spent']
    );

    if (!$qstmt->execute()) {
        die("Question insert failed: " . $qstmt->error);
    }
}
$qstmt->close();

/* ---------- CREATE REPORT FOLDER ---------- */
$reportDir = __DIR__ . "/attempt_reports";
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0777, true);
}

/* ---------- GENERATE HTML FILE ---------- */
$filename = "attempt_" . $attempt_id . ".html";
$filepath = $reportDir . "/" . $filename;

$html = "<!DOCTYPE html>
<html><head><title>Attempt {$attempt_id}</title>
<style>
body{font-family:Arial;background:#f4f6f8;padding:20px}
.box{background:#fff;padding:20px;border-radius:8px}
.time{color:#7c3aed;font-weight:bold}
</style>
</head>
<body>
<div class='box'>
<h2>Test Attempt #{$attempt_id}</h2>
<p><b>User:</b> {$username}</p>
<p><b>Score:</b> {$data['score']} / {$data['totalMarks']}</p>
<p><b>Total Time:</b> <span class='time'>{$data['totalTime']} sec</span></p>
<hr>";

foreach ($data['questions'] as $i => $q) {
    $html .= "
    <p><b>Question ".($i+1)."</b></p>
    <p>Status: {$q['is_correct']}</p>
    <p>Time: {$q['time_spent']} sec</p>
    <hr>";
}

$html .= "</div></body></html>";

if (file_put_contents($filepath, $html) === false) {
    die("Failed to write report file");
}

/* ---------- UPDATE REPORT FILE ---------- */
$u = $conn->prepare("UPDATE test_attempts SET report_file=? WHERE attempt_id=?");
$u->bind_param("si", $filename, $attempt_id);
$u->execute();
$u->close();

$conn->close();

echo json_encode(["status" => "success"]);
