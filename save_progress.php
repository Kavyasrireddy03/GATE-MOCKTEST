<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include 'db.php';

// Import PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

header('Content-Type: application/json');

/* ==============================
    1️⃣ SESSION SECURITY CHECK
============================== */
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Login session expired.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || ($data['action'] ?? '') !== 'submit') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$today = date('Y-m-d'); 

/* ==============================
    2️⃣ SAFE DATA EXTRACTION
============================== */
$subject_id  = (int)($data['subject_id'] ?? 0);
$set_no      = (int)($data['set_no'] ?? 0);
$score       = (float)($data['score'] ?? 0);
$total_marks = (float)($data['total_marks'] ?? 0);
$attempted   = (int)($data['attempted'] ?? 0);
$correct     = (int)($data['correct'] ?? 0);
$wrong       = (int)($data['wrong'] ?? 0);
$start_time  = $data['start_time'] ?? date('Y-m-d H:i:s');
$duration    = (int)($data['test_duration_minutes'] ?? 0);
$time_taken  = (int)($data['time_taken_seconds'] ?? 0);

/* ==============================
    3️⃣ SAFETY CHECKS
============================== */
if ($time_taken < 0) $time_taken = 0;
if ($duration > 0 && $time_taken > ($duration * 60)) {
    $time_taken = $duration * 60;
}

/* ==============================
    4️⃣ INSERT INTO test_attempts
============================== */
$stmt = $conn->prepare("
    INSERT INTO test_attempts (
        user_id, subject_id, set_no, score, total_marks, 
        attempted_questions, correct_answers, wrong_answers, 
        start_time, test_duration_minutes, time_taken_seconds
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => $conn->error]);
    exit;
}

$stmt->bind_param("iiidiiiisii", $user_id, $subject_id, $set_no, $score, $total_marks, $attempted, $correct, $wrong, $start_time, $duration, $time_taken);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
    exit;
}

$attempt_id = $stmt->insert_id;
$stmt->close();

/* ==============================
    5️⃣ INSERT INTO attempt_answers
============================== */
if (isset($data['details']) && is_array($data['details'])) {
    $stmt2 = $conn->prepare("INSERT INTO attempt_answers (attempt_id, question_id, selected_answer, is_correct) VALUES (?, ?, ?, ?)");
    foreach ($data['details'] as $row) {
        $q_id = (int)($row['question_id'] ?? 0);
        $ans  = $row['selected_answer'] ?? '';
        $is_c = (int)($row['is_correct'] ?? 0);
        $stmt2->bind_param("iisi", $attempt_id, $q_id, $ans, $is_c);
        $stmt2->execute();
    }
    $stmt2->close();
}

/* ==============================
    5.5️⃣ AUTO-MARK DAILY STUDY & SUBJECT
============================== */
// Fetch subject_name to update the daily log correctly
$sub_query = $conn->query("SELECT subject_name FROM subjects WHERE subject_id = $subject_id");
$sub_data = $sub_query->fetch_assoc();
$current_subject = $sub_data['subject_name'] ?? 'General';

$study_stmt = $conn->prepare("
    INSERT INTO daily_study_logs (user_id, study_date, status, subject_name) 
    VALUES (?, ?, 'studied', ?) 
    ON DUPLICATE KEY UPDATE status='studied', subject_name=?
");
$study_stmt->bind_param("isss", $user_id, $today, $current_subject, $current_subject);
$study_stmt->execute();
$study_stmt->close();

/* ==============================
    6️⃣ SEND SUCCESS EMAIL TO USER
============================== */
$email_status = "Not Sent";

$info_query = $conn->query("
    SELECT u.name, u.email, s.subject_name 
    FROM users u 
    JOIN subjects s ON s.subject_id = $subject_id 
    WHERE u.user_id = $user_id
");
$user_info = $info_query->fetch_assoc();

if ($user_info && !empty($user_info['email'])) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(SMTP_USER, MAIL_FROM_NAME);
        $mail->addAddress($user_info['email'], $user_info['name']);

        $accuracy = ($attempted > 0) ? round(($correct / $attempted) * 100, 2) : 0;
        $mail->isHTML(true);
        $mail->Subject = "Exam Result: " . $user_info['subject_name'] . " - Set " . $set_no;
        $mail->Body    = "
            <div style='font-family: sans-serif; max-width: 600px; border: 1px solid #eee; padding: 20px;'>
                <h2 style='color: #2563eb;'>Well Done, " . htmlspecialchars($user_info['name']) . "! 🎉</h2>
                <p>Performance summary for <b>" . $user_info['subject_name'] . "</b>:</p>
                <table style='width: 100%; background: #f9f9f9; padding: 15px; border-radius: 8px;'>
                    <tr><td><b>Score:</b></td><td>" . $score . " / " . $total_marks . "</td></tr>
                    <tr><td><b>Accuracy:</b></td><td>" . $accuracy . "%</td></tr>
                </table>
                <p>Check your dashboard for details.</p>
                <a href='" . SITE_URL . "/user_dashboard.php' style='display: inline-block; padding: 10px 20px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 5px;'>Dashboard</a>
            </div>";

        $mail->send();
        $email_status = "Sent";
    } catch (Exception $e) { $email_status = "Error"; }
}

echo json_encode(['success' => true, 'attempt_id' => $attempt_id, 'email' => $email_status]);
exit;
?>