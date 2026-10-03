<?php
// face_log.php
// Receives proctoring events from modules/face_proctor.js and stores them
// in `proctor_events` (auto-created). No images are received or stored:
// the candidate's photo stays in their own browser.
ob_start();
session_start();

function out($arr, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}

if (!isset($_SESSION['user_id'])) out(['success' => false, 'message' => 'Not logged in'], 403);

include 'config.php';   // gives $conn
if (!isset($conn) || $conn->connect_error) out(['success' => false, 'message' => 'DB error'], 500);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) out(['success' => false, 'message' => 'Invalid JSON'], 400);

$allowed = ['start_photo', 'multiple_faces', 'no_face', 'identity_mismatch', 'camera_off', 'violation'];
$event = $data['event'] ?? '';
if (!in_array($event, $allowed, true)) out(['success' => false, 'message' => 'Unknown event'], 400);

$conn->query("
    CREATE TABLE IF NOT EXISTS proctor_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        subject_id INT NULL,
        set_no INT NULL,
        event_type VARCHAR(40) NOT NULL,
        face_count TINYINT NULL,
        match_distance DECIMAL(6,3) NULL,
        warning_no TINYINT NULL,
        message VARCHAR(255) NULL,
        ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_user (user_id),
        INDEX idx_set (subject_id, set_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$user_id    = (int)$_SESSION['user_id'];
$subject_id = isset($data['subject_id']) ? (int)$data['subject_id'] : null;
$set_no     = isset($data['set_no']) ? (int)$data['set_no'] : null;
$face_count = isset($data['face_count']) ? (int)$data['face_count'] : null;
$distance   = isset($data['distance']) ? round((float)$data['distance'], 3) : null;
$warning_no = isset($data['warning_no']) ? (int)$data['warning_no'] : null;
$message    = isset($data['message']) ? mb_substr((string)$data['message'], 0, 255) : null;
$ip         = $_SERVER['REMOTE_ADDR'] ?? null;
$now        = date('Y-m-d H:i:s');

/* ---------- Insert ---------- */
$stmt = $conn->prepare("
    INSERT INTO proctor_events
    (user_id, subject_id, set_no, event_type, face_count, match_distance, warning_no, message, ip, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
if (!$stmt) out(['success' => false, 'message' => $conn->error], 500);

$stmt->bind_param('iiisidisss', $user_id, $subject_id, $set_no, $event, $face_count, $distance,
                  $warning_no, $message, $ip, $now);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

out(['success' => $ok]);
