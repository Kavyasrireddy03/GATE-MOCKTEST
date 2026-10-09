<?php
/*
 * Copy this file to config.php and fill in your own values.
 * config.php is listed in .gitignore and must never be committed.
 */
if (defined('GATE_CONFIG_LOADED')) {
    return;
}
define('GATE_CONFIG_LOADED', true);

// ---------- Database ----------
$servername = "localhost";
$username   = "DB_USERNAME";
$password   = "DB_PASSWORD";
$dbname     = "DB_NAME";

// ---------- Site ----------
define('SITE_URL', 'https://your-domain.example');   // no trailing slash
define('ADMIN_EMAIL', 'admin@your-domain.example');  // receives the daily study report
define('APP_TIMEZONE', 'Asia/Kolkata');

// ---------- Exam branding (change these each GATE year) ----------
define('EXAM_NAME', 'GATE 2027');
define('EXAM_FULL_NAME', 'Graduate Aptitude Test in Engineering (GATE 2027)');
define('ORGANIZING_INSTITUTE', 'Indian Institute of Technology Madras');
define('INSTITUTE_SHORT_NAME', 'IIT Madras');
define('EXAM_LOGO_URL', 'https://gate2027.iitm.ac.in/static/img/logos/GATE27_logo.svg');
define('INSTITUTE_LOGO_URL', 'https://gate2027.iitm.ac.in/static/img/logos/iitm-logo.png');
define('EXAM_BANNER_URL', 'https://goaps.iitm.ac.in/_next/static/media/banner.0wisxdqn7yy1h.jpg');

// ---------- SMTP (PHPMailer) ----------
define('SMTP_HOST', 'mail.your-domain.example');
define('SMTP_USER', 'noreply@your-domain.example');
define('SMTP_PASS', 'SMTP_PASSWORD');
define('SMTP_PORT', 465);
define('MAIL_FROM_NAME', 'GATE Mock Tests');        // sender name on result and reminder emails
define('REPORT_FROM_NAME', 'GATE System Bot');      // sender name on the daily admin report

// ---------- Telegram (tg.php) ----------
define('TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_TOKEN');
define('TELEGRAM_CHAT_ID', 'TELEGRAM_CHAT_ID');

// ---------- Google Apps Script upload endpoint (admin.php, up.php) ----------
define('APPS_SCRIPT_URL', 'https://script.google.com/macros/s/YOUR_DEPLOYMENT_ID/exec');

// ---------- Front-end libraries (CDN) ----------
define('CDN_TAILWIND', 'https://cdn.tailwindcss.com');
define('CDN_JQUERY', 'https://d502jbuhuh9wk.cloudfront.net/resources/js/jquery-2.0.3.min.js');
define('CDN_JQUERY_UI', 'https://d502jbuhuh9wk.cloudfront.net/resources/js/jquery-ui.min.js');
define('CDN_CHARTJS', 'https://cdn.jsdelivr.net/npm/chart.js');
define('CDN_CROPPER_CSS', 'https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css');
define('CDN_CROPPER_JS', 'https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js');
define('CDN_FACE_API', 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/dist/face-api.js');
define('FACE_API_MODEL_URL', 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/model');

// Compilers for coding questions (C, C++, Python 3, JavaScript). They run in the candidate's browser,
// so no online compiler API is used. Default: the copies shipped in vendor/compilers on this site.
define('CODE_COMPILERS_URL', 'vendor/compilers');

date_default_timezone_set(APP_TIMEZONE);

// Pages that don't need the database (e.g. ErrorPage.php) define SKIP_DB first.
if (defined('SKIP_DB')) {
    return;
}

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
