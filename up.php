<?php
require_once __DIR__ . '/config.php';
$webAppUrl = APPS_SCRIPT_URL;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $fileName = $_FILES['file']['name'];
    $fileData = base64_encode(file_get_contents($_FILES['file']['tmp_name']));

    $postData = http_build_query(['name' => $fileName, 'data' => $fileData]);

    $ch = curl_init($webAppUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_FOLLOWLOCATION => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    echo "<strong>HTTP Status Code:</strong> $httpCode<br>";
    echo "<strong>Raw Response:</strong><pre>$response</pre>";

    if ($err) exit("cURL Error: $err");

    $data = json_decode($response, true);
    if ($data && $data['status'] === 'success') {
        echo "<h3>✅ Upload Successful!</h3>";
        echo "File Name: {$data['fileName']}<br>";
        echo "File URL: <a href='{$data['url']}' target='_blank'>{$data['url']}</a>";
    } else {
        echo "<h3>❌ Upload Failed:</h3>" . ($data['message'] ?? 'Unknown error');
    }
    exit;
}
?>
<!doctype html>
<html>
<body>
<h2>Upload Image to Google Drive</h2>
<form method="post" enctype="multipart/form-data">
  <input type="file" name="file" required>
  <button type="submit">Upload</button>
</form>
</body>
</html>
