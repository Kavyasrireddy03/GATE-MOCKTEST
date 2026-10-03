<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$today = date('Y-m-d');
$log_file = __DIR__ . '/cron_debug.log';

function write_log($message, $file) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($file, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}

echo "<body style='font-family:sans-serif; background:#f4f4f9; padding:20px;'>";
write_log("--- CRON STARTED (Combined Engagement Logic) ---", $log_file);

/* ==============================
   SMART TARGETING QUERY
   Excludes users who:
   1. Took a test TODAY.
   2. Already logged study TODAY.
============================== */
$query = "
    SELECT u.user_id, u.name, u.email, 
           MAX(t.start_time) as last_test_time,
           (
               SELECT s.subject_name 
               FROM test_attempts ta
               JOIN attempt_answers aa ON ta.attempt_id = aa.attempt_id
               JOIN questions q ON aa.question_id = q.id
               JOIN subjects s ON q.subject_id = s.subject_id
               WHERE ta.user_id = u.user_id
               ORDER BY ta.start_time DESC 
               LIMIT 1
           ) as last_subject
    FROM users u
    LEFT JOIN test_attempts t ON u.user_id = t.user_id
    WHERE u.user_id NOT IN (
        SELECT user_id FROM test_attempts WHERE DATE(start_time) = '$today'
    )
    AND u.user_id NOT IN (
        SELECT user_id FROM daily_study_logs WHERE study_date = '$today'
    )
    GROUP BY u.user_id
    LIMIT 50
";

$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $count = $result->num_rows;
    write_log("Found $count users needing a nudge.", $log_file);
    
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

        while ($row = $result->fetch_assoc()) {
            try {
                $mail->clearAddresses();
                $mail->addAddress($row['email'], $row['name']);
                $mail->isHTML(true);

                $last_test_date = $row['last_test_time'];
                $last_subject = $row['last_subject'] ?? "General GATE Prep";
                $baseUrl = SITE_URL . "/log_study.php?uid=" . $row['user_id'];
                
                $is_long_break = (!$last_test_date || strtotime($last_test_date) < strtotime('-5 days'));

                if ($is_long_break) {
                    $mail->Subject = "Still stuck on $last_subject, {$row['name']}? ";
                    $status_header = "It's been a while!";
                    $status_msg = "We noticed you haven't taken a mock test in over 5 days. Your last focus was on <b>$last_subject</b>.";
                } else {
                    $mail->Subject = "Quick Check: Did you study $last_subject today? ✅";
                    $status_header = "Daily Progress Check";
                    $status_msg = "You haven't attempted any mock tests today. Consistency is the secret to a top rank!";
                }

                $mail->Body = "
                    <div style='max-width:550px; border:1px solid #ddd; padding:25px; border-radius:12px; background:#fff; line-height:1.6;'>
                        <h2 style='color:#1e40af; margin-top:0;'>$status_header</h2>
                        <p>Hi {$row['name']},</p>
                        <p>$status_msg</p>
                        <p><b>Did you spend time studying $last_subject or other topics today?</b></p>
                        <div style='margin:25px 0;'>
                            <a href='{$baseUrl}&status=studied' style='display:inline-block; background:#10b981; color:white; padding:12px 20px; text-decoration:none; border-radius:6px; font-weight:bold; margin-right:10px;'>✅ Yes, I Studied</a>
                            <a href='{$baseUrl}&status=not_studied' style='display:inline-block; background:#6b7280; color:white; padding:12px 20px; text-decoration:none; border-radius:6px; font-weight:bold;'>❌ No Study</a>
                        </div>
                    </div>";

                // Mark as not_studied by default
                $conn->query("INSERT IGNORE INTO daily_study_logs (user_id, study_date, status) VALUES ({$row['user_id']}, '$today', 'not_studied')");

                $mail->send();
                echo "🚀 Sent to: " . $row['name'] . "<br>";
                usleep(500000); 
            } catch (Exception $e) {
                write_log("MAIL ERROR: {$row['email']} - " . $mail->ErrorInfo, $log_file);
            }
        }
    } catch (Exception $e) {
        write_log("FATAL ERROR: " . $e->getMessage(), $log_file);
    }
} else {
    echo "No users meet criteria (they either studied or took a test today).";
}
write_log("--- CRON FINISHED ---", $log_file);
?>