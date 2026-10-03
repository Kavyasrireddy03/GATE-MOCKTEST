<?php
header('Content-Type: application/json');
include 'db.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Helper function to send JSON response
function send_response($status, $message) {
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

// Validate POST data
$required = ['subject_id','type','correct','qUrl'];
foreach ($required as $field) {
    if (!isset($_POST[$field]) || empty($_POST[$field])) {
        send_response('error', "Missing field: $field");
    }
}

// Collect data
$subject_id = $_POST['subject_id'];
$type       = $_POST['type'];
$correct    = $_POST['correct'];
$qUrl       = $_POST['qUrl'];
$aUrl       = $_POST['aUrl'] ?? '';
$bUrl       = $_POST['bUrl'] ?? '';
$cUrl       = $_POST['cUrl'] ?? '';
$dUrl       = $_POST['dUrl'] ?? '';
$solUrl     = $_POST['solUrl'] ?? '';

// Prepare SQL statement
$stmt = $conn->prepare("
    INSERT INTO questions (
        subject_id, question_type, correct_answers,
        question_image_url, optionA_url, optionB_url, optionC_url, optionD_url, solution_url
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    send_response('error', "DB Prepare failed: " . $conn->error);
}

// Bind parameters and execute
$stmt->bind_param("issssssss", $subject_id, $type, $correct, $qUrl, $aUrl, $bUrl, $cUrl, $dUrl, $solUrl);

if ($stmt->execute()) {
    send_response('success', "Question saved successfully!");
} else {
    send_response('error', "DB Insert failed: " . $stmt->error);
}

$stmt->close();
$conn->close();
