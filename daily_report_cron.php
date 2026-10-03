<?php
include 'db.php';
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'dompdf/autoload.inc.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dompdf\Dompdf;
use Dompdf\Options;

$today = date('Y-m-d'); // Changed to today's date

// 1. Fetch Data for Today
$query = "
    SELECT u.name, l.study_date, l.status, l.subject_name 
    FROM daily_study_logs l
    JOIN users u ON l.user_id = u.user_id
    WHERE l.study_date = '$today'
    ORDER BY u.name ASC
";
$result = $conn->query($query);
$data = [];
$stats = ['studied' => 0, 'not_studied' => 0];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
    if ($row['status'] == 'studied') {
        $stats['studied']++;
    } else {
        $stats['not_studied']++;
    }
}

if (empty($data)) {
    die("No data to report for today ($today) yet.");
}

/* ==========================================================
   2. GENERATE CSV CONTENT
   ========================================================== */
$csv_file = fopen('php://temp', 'r+');
fputcsv($csv_file, ['Student Name', 'Date', 'Status', 'Subjects Studied']);
foreach ($data as $row) {
    fputcsv($csv_file, $row);
}
rewind($csv_file);
$csv_content = stream_get_contents($csv_file);
fclose($csv_file);

/* ==========================================================
   3. GENERATE PDF CONTENT
   ========================================================== */
$options = new Options();
$options->set('defaultFont', 'Helvetica');
$dompdf = new Dompdf($options);

$html = "
<h2 style='text-align:center; font-family:sans-serif;'>GATE Daily Progress: $today</h2>
<table border='1' cellspacing='0' cellpadding='8' style='width:100%; border-collapse:collapse; font-family:sans-serif; font-size:12px;'>
    <thead>
        <tr style='background:#f2f2f2;'>
            <th>Student Name</th>
            <th>Date</th>
            <th>Status</th>
            <th>Subjects</th>
        </tr>
    </thead>
    <tbody>";

foreach ($data as $row) {
    $statusColor = ($row['status'] == 'studied') ? 'color:#10b981;' : 'color:#f43f5e;';
    $html .= "<tr>
        <td>{$row['name']}</td>
        <td>{$row['study_date']}</td>
        <td style='font-weight:bold; $statusColor'>" . ucfirst($row['status']) . "</td>
        <td>" . ($row['subject_name'] ?? 'N/A') . "</td>
    </tr>";
}
$html .= "</tbody></table>";

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pdf_content = $dompdf->output();

/* ==========================================================
   4. SEND EMAIL WITH SUMMARY & ATTACHMENTS
   ========================================================== */
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS; 
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(SMTP_USER, REPORT_FROM_NAME);
    $mail->addAddress(ADMIN_EMAIL);

    $mail->isHTML(true);
    $mail->Subject = "Live Study Report - $today";
    
    // Quick summary table for the body
    $mail->Body    = "
        <div style='font-family:sans-serif; color:#333;'>
            <h2>Daily Study Snapshot ($today)</h2>
            <table cellpadding='10' cellspacing='0' style='border:1px solid #ddd; border-radius:10px;'>
                <tr style='background:#f9f9f9;'>
                    <td>✅ <b>Studied Today</b></td>
                    <td style='color:#10b981;'><b>{$stats['studied']} Students</b></td>
                </tr>
                <tr>
                    <td>❌ <b>Didn't Study</b></td>
                    <td style='color:#f43f5e;'><b>{$stats['not_studied']} Students</b></td>
                </tr>
            </table>
            <p>Please find the detailed CSV and PDF reports attached below.</p>
            <br>
            <i>Regards,<br>GATE Portal Bot</i>
        </div>";

    $mail->addStringAttachment($csv_content, "Report_$today.csv");
    $mail->addStringAttachment($pdf_content, "Report_$today.pdf");

    $mail->send();
    echo "✅ Today's Report Sent Successfully!";
} catch (Exception $e) {
    echo "❌ Mailer Error: {$mail->ErrorInfo}";
}
?>