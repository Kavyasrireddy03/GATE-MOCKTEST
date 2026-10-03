<?php
include 'db.php';
require 'dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;


// --- TELEGRAM CONFIGURATION ---
$botToken = TELEGRAM_BOT_TOKEN;
$chatId = TELEGRAM_CHAT_ID;

// 1. Check for Today's Data
$reportDate = date('Y-m-d');
$query = "
    SELECT u.name, l.study_date, l.status, l.subject_name 
    FROM daily_study_logs l
    JOIN users u ON l.user_id = u.user_id
    WHERE l.study_date = '$reportDate'
    ORDER BY u.name ASC
";
$result = $conn->query($query);

// 2. Fallback to Yesterday if Today is Empty
if ($result->num_rows == 0) {
    $reportDate = date('Y-m-d', strtotime("-1 day"));
    $query = "
        SELECT u.name, l.study_date, l.status, l.subject_name 
        FROM daily_study_logs l
        JOIN users u ON l.user_id = u.user_id
        WHERE l.study_date = '$reportDate'
        ORDER BY u.name ASC
    ";
    $result = $conn->query($query);
}

if ($result->num_rows == 0) {
    die("No data found for today or yesterday.");
}

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

/* ==========================================================
   3. GENERATE CSV CONTENT
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
   4. GENERATE PDF CONTENT
   ========================================================== */
$options = new Options();
$options->set('defaultFont', 'Helvetica');
$dompdf = new Dompdf($options);

$html = "
<h2 style='text-align:center; font-family:sans-serif;'>GATE Progress Report: $reportDate</h2>
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
   5. SEND TO TELEGRAM
   ========================================================== */
function sendTelegramDoc($token, $chatId, $content, $filename, $caption) {
    $url = "https://api.telegram.org/bot$token/sendDocument";
    $tmpFile = tempnam(sys_get_temp_dir(), 'tg');
    file_put_contents($tmpFile, $content);

    $postFields = [
        'chat_id'  => $chatId,
        'document' => new CURLFile($tmpFile, 'application/octet-stream', $filename),
        'caption'  => $caption,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
    unlink($tmpFile);
}

$isYesterday = ($reportDate == date('Y-m-d', strtotime("-1 day"))) ? " (Yesterday)" : "";
$caption = "<b>📊 Study Progress: $reportDate$isYesterday</b>\n\n"
         . "✅ Studied: <b>{$stats['studied']}</b>\n"
         . "❌ Didn't Study: <b>{$stats['not_studied']}</b>";

sendTelegramDoc($botToken, $chatId, $csv_content, "Report_$reportDate.csv", $caption);
sendTelegramDoc($botToken, $chatId, $pdf_content, "Report_$reportDate.pdf", "");

echo "✅ Report for $reportDate sent to Telegram!";
?>