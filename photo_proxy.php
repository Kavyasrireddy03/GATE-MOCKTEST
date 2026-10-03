<?php
// photo_proxy.php
// Serves the logged-in candidate's registered photo from the SAME origin,
// so face-api.js can read its pixels (Google Drive images have no CORS headers).
// Only ever serves $_SESSION['display_image'] of the current user -> not an open proxy.
ob_start();
session_start();

function fail($code, $msg) {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: text/plain');
    exit($msg);
}

if (!isset($_SESSION['user_id'])) fail(403, 'Not logged in');

$src = $_SESSION['display_image'] ?? '';
if ($src === '' || $src === 'user.png') fail(404, 'No registered photo');

$body = null;
$ctype = '';

if (preg_match('~^https?://~i', $src)) {
    $ch = curl_init($src);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: image/jpeg,image/png,image/webp,image/*;q=0.8'],
    ]);
    $body  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $code !== 200) fail(502, 'Could not fetch registered photo');
    if (stripos($ctype, 'image/') !== 0) {
        // Drive returns an HTML page when the file is not shared as "Anyone with the link"
        fail(502, 'Registered photo is not publicly viewable');
    }
} else {
    $path = realpath(__DIR__ . '/' . ltrim($src, '/'));
    if ($path === false || strpos($path, realpath(__DIR__)) !== 0 || !is_file($path)) fail(404, 'Photo file missing');
    $info = @getimagesize($path);
    if (!$info) fail(415, 'Not an image');
    $ctype = $info['mime'];
    $body  = file_get_contents($path);
}

ob_end_clean();
header('Content-Type: ' . $ctype);
header('Content-Length: ' . strlen($body));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $body;
