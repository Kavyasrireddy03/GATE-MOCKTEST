<?php
// Shared helpers for the admin pages (admin.php, paper_builder.php)

$DRIVE_ERROR = '';

// Uploads one $_FILES-style entry to Google Drive through the Apps Script web app.
// Returns the file URL, or null (the reason is left in $DRIVE_ERROR).
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

function id_or_null($v) {
    return (int)$v > 0 ? (int)$v : null;
}

// Google Drive share links do not load in <img>; use the thumbnail address (same as exam_app.js)
function img_src($url) {
    $url = (string)$url;
    if (preg_match('/id=([a-zA-Z0-9_-]+)/', $url, $m) || preg_match('#/d/([a-zA-Z0-9_-]+)/view#', $url, $m)) {
        return 'https://drive.google.com/thumbnail?id=' . $m[1] . '&sz=s800';
    }
    return $url;
}
