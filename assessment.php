<?php
// Assessment page for one test (TCS iON NQT style): title, duration, Start Assessment,
// passing marks, start / end date, attempts taken, and My Attempts with the report link.
// Opened from the exam schedule (index.php) and after "Exit Assessment" at the end of the exam.
session_start();
include 'config.php';
require_once __DIR__ . '/modules/sections.php';
require_once __DIR__ . '/modules/coding.php';
require_once __DIR__ . '/modules/assessment.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = (int)$_SESSION['user_id'];

ensure_sections_schema($conn);
ensure_coding_schema($conn);
ensure_assessment_schema($conn);

$param = $_GET['set_no'] ?? '';
if (strpos($param, '|') === false) { header("Location: index.php"); exit(); }
[$set_no, $subject_id] = array_map('intval', explode('|', $param));

$set = assessment_set_info($conn, $set_no, $subject_id);
if (!$set) { header("Location: index.php"); exit(); }
$attempts = assessment_attempts($conn, $user_id, $set_no, $subject_id);
$taken = count($attempts);
$max = $set['max_attempts'];

$can_start = true;
$block_msg = '';
if ($max > 0 && $taken >= $max) {
    $can_start = false;
    $block_msg = "You have used all $max attempts of this assessment.";
} elseif (!$set['is_open']) {
    $can_start = false;
    $block_msg = $set['not_started']
        ? 'This assessment opens on ' . assessment_set_time_label($set['start_time']) . '.'
        : 'This assessment is closed.';
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
// 7 -> 7.0, 6.67 -> 6.67 (the NQT page shows one decimal place)
$fmt = fn($n) => abs(round((float)$n, 1) - (float)$n) < 0.001 ? number_format((float)$n, 1, '.', '') : number_format((float)$n, 2, '.', '');
$active = isset($_GET['attempt']) ? (int)$_GET['attempt'] : ($taken ? $attempts[$taken - 1]['attempt_id'] : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $e($set['title']) ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,400;0,600;0,700;1,400&display=swap">
<style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Open Sans', Arial, sans-serif; background: #f5f6fa; color: #2b2b2b; }
    .wrap { max-width: 1180px; margin: 0 auto; padding: 28px 20px 110px; }
    h1 { text-align: center; font-weight: 400; font-size: 34px; margin: 6px 0 6px; color: #222; }
    .duration { text-align: center; color: #444; font-size: 16px; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .note { text-align: center; font-style: italic; color: #555; font-size: 15px; margin: 22px 0 18px; }
    .start-row { text-align: center; }
    .btn-start { display: inline-flex; align-items: center; gap: 10px; padding: 13px 38px; border-radius: 30px; border: 0;
                 background: linear-gradient(90deg, #1b3c9c, #2453c6); color: #fff; font: 700 17px 'Open Sans', Arial, sans-serif;
                 letter-spacing: .3px; text-decoration: none; cursor: pointer; box-shadow: 0 3px 8px rgba(27,60,156,.3); }
    .btn-start:hover { background: linear-gradient(90deg, #15307f, #1d47ad); }
    .btn-start.disabled { background: #9aa6c4; box-shadow: none; cursor: not-allowed; }
    .after-msg { text-align: center; font-style: italic; color: #444; font-size: 15px; margin: 10px 0 0; }
    .block-msg { text-align: center; color: #c0392b; font-size: 14px; margin: 8px 0 0; }
    .cards { display: flex; gap: 40px; margin: 26px 0 0; align-items: stretch; flex-wrap: wrap; }
    .card { background: #fff; border: 1px solid #ddd; border-top: 6px solid #1e9e4a; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,.08);
            display: flex; align-items: center; padding: 14px 18px; min-height: 104px; }
    .card .lbl { font-size: 14px; color: #555; text-align: center; }
    .card .val { font-size: 30px; font-weight: 600; text-align: center; color: #222; }
    .card .val .of { color: #5b8fc7; }
    .card-pass { flex: 1 1 240px; gap: 14px; }
    .card-dates { flex: 2 1 460px; padding: 0; }
    .card-dates .half { flex: 1; padding: 14px 18px; text-align: center; }
    .card-dates .half + .half { border-left: 1px solid #ddd; }
    .card-dates .val { font-size: 20px; white-space: nowrap; }
    .card-att { flex: 1 1 200px; border-top-color: #1b8f8a; gap: 14px; }
    .card-att .val { color: #2f5f8f; }
    .ico { color: #666; flex: 0 0 auto; }
    .attempts { background: #fff; border: 1px solid #ddd; border-radius: 8px; margin-top: 30px; padding: 22px 22px 26px; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
    .attempts h2 { font-size: 20px; font-weight: 600; margin: 0 0 18px; }
    .tabs { background: #e9ecef; border-radius: 4px; display: flex; gap: 6px; padding: 0 40px; overflow-x: auto; }
    .tab { padding: 12px 22px 10px; color: #333; text-decoration: none; font-weight: 600; font-size: 15px; border-bottom: 3px solid transparent; white-space: nowrap; }
    .tab.on { border-bottom-color: #e53935; }
    .att-cards { display: grid; grid-template-columns: repeat(6, 1fr); gap: 18px; margin-top: 22px; }
    .ac { border: 1px solid #ddd; border-radius: 6px; background: #fff; text-align: center; padding: 22px 10px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
    .ac .circle { width: 56px; height: 56px; border-radius: 50%; background: #4a4f55; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; }
    .ac .circle.pass { background: #1e9e4a; } .ac .circle.fail { background: #d93025; }
    .ac .t { margin-top: 12px; font-size: 15px; color: #555; }
    .ac .v { font-size: 20px; font-weight: 600; color: #222; margin-top: 2px; }
    .ac .s { font-size: 14px; color: #555; }
    .ac a { color: #1f3f8f; text-decoration: none; }
    .ac a:hover .v { text-decoration: underline; }
    .empty { color: #777; font-style: italic; padding: 18px 4px 0; }
    .nav { position: fixed; left: 0; right: 0; bottom: 0; display: flex; justify-content: space-between; padding: 12px 20px; pointer-events: none; }
    .nav a { pointer-events: auto; background: linear-gradient(90deg, #1b3c9c, #2453c6); color: #fff; text-decoration: none; padding: 11px 26px;
             border-radius: 26px; font-weight: 600; font-size: 16px; box-shadow: 0 3px 8px rgba(0,0,0,.2); }
    @media (max-width: 900px) {
        .att-cards { grid-template-columns: repeat(2, 1fr); }
        .cards { gap: 16px; }
        h1 { font-size: 26px; }
    }
</style>
</head>
<body>
<div class="wrap">
    <h1><?= $e($set['title']) ?></h1>
    <div class="duration">
        <svg class="ico" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 7v5l3 2"/></svg>
        Duration: <?= (int)$set['duration_minutes'] ?> Minutes
    </div>
    <div class="note">Note: Assessment analysis is available in the report of each attempt.</div>

    <div class="start-row">
        <?php if ($can_start): ?>
            <a class="btn-start" href="instructions.php?set_no=<?= urlencode($set_no . '|' . $subject_id) ?>">START ASSESSMENT <span>&#8250;</span></a>
        <?php else: ?>
            <span class="btn-start disabled">START ASSESSMENT <span>&#8250;</span></span>
        <?php endif; ?>
    </div>
    <?php if ($taken > 0): ?>
        <div class="after-msg">You have successfully submitted the assessment. Your report is available under My Attempts.</div>
    <?php endif; ?>
    <?php if ($block_msg): ?><div class="block-msg"><?= $e($block_msg) ?></div><?php endif; ?>

    <div class="cards">
        <div class="card card-pass">
            <svg class="ico" width="44" height="52" viewBox="0 0 44 52" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="4" y="7" width="36" height="42" rx="3"/><rect x="14" y="2" width="16" height="9" rx="2" fill="#fff"/><path d="M12 30l7 7 13-14"/></svg>
            <div style="flex:1">
                <div class="lbl">Passing Marks</div>
                <div class="val"><?= $set['pass_marks'] === null ? '--' : $fmt($set['pass_marks']) ?> / <span class="of"><?= $fmt($set['total_marks']) ?></span></div>
            </div>
        </div>
        <div class="card card-dates">
            <div class="half">
                <div class="lbl">Start Date/Time</div>
                <div class="val"><?= $e(assessment_set_time_label($set['start_time'])) ?></div>
            </div>
            <div class="half">
                <div class="lbl">End Date/Time</div>
                <div class="val"><?= $e(assessment_set_time_label($set['attempt_till'])) ?></div>
            </div>
        </div>
        <div class="card card-att">
            <svg class="ico" width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="22" cy="26" r="18"/><circle cx="22" cy="26" r="11"/><circle cx="22" cy="26" r="4"/><path d="M22 26L42 6M36 6h6v6"/></svg>
            <div style="flex:1">
                <div class="lbl">Attempts Taken</div>
                <div class="val"><?= $taken ?> / <?= $max > 0 ? $max : '&infin;' ?></div>
            </div>
        </div>
    </div>

    <div class="attempts">
        <h2>My Attempts</h2>
        <?php if (!$attempts): ?>
            <div class="empty">You have not attempted this assessment yet.</div>
        <?php else: ?>
            <div class="tabs">
                <?php foreach ($attempts as $i => $a): ?>
                    <a class="tab <?= (int)$a['attempt_id'] === $active ? 'on' : '' ?>" href="?set_no=<?= urlencode($set_no . '|' . $subject_id) ?>&attempt=<?= (int)$a['attempt_id'] ?>">Attempt <?= $i + 1 ?></a>
                <?php endforeach; ?>
            </div>
            <?php foreach ($attempts as $a): if ((int)$a['attempt_id'] !== $active) continue;
                $st = assessment_status((float)$a['score'], $set['pass_marks']); ?>
            <div class="att-cards">
                <div class="ac">
                    <span class="circle"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><rect x="3" y="5" width="15" height="14" rx="2"/><path d="M3 9h15M7 3v4M14 3v4"/><circle cx="18" cy="17" r="4" fill="#4a4f55"/><path d="M18 15v2l1 1"/></svg></span>
                    <div class="t">Attempt Date/Time</div>
                    <div class="v"><?= $a['start_dt'] ? $a['start_dt']->format('d M Y') : '--' ?></div>
                    <div class="s"><?= $a['start_dt'] ? $a['start_dt']->format('h:i A') : '' ?></div>
                </div>
                <div class="ac">
                    <span class="circle"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/></svg></span>
                    <div class="t">Submission Date/Time</div>
                    <div class="v"><?= $a['submit_dt'] ? $a['submit_dt']->format('d M Y') : '--' ?></div>
                    <div class="s"><?= $a['submit_dt'] ? $a['submit_dt']->format('h:i A') : '' ?></div>
                </div>
                <div class="ac">
                    <span class="circle"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><rect x="5" y="4" width="14" height="17" rx="2"/><rect x="9" y="2" width="6" height="4" rx="1" fill="#4a4f55"/><path d="M9 13l2 2 4-4"/></svg></span>
                    <div class="t">Marks Obtained</div>
                    <div class="v"><?= $fmt($a['score']) ?></div>
                </div>
                <div class="ac">
                    <span class="circle <?= $st['code'] === 'PASS' ? 'pass' : ($st['code'] === 'FAIL' ? 'fail' : '') ?>"><?= $st['code'] === 'N/A' ? 'N/A' : ($st['code'] === 'PASS' ? '&#10004;' : '&#10008;') ?></span>
                    <div class="t">Status</div>
                    <div class="v"><?= $e($st['label']) ?></div>
                </div>
                <div class="ac">
                    <a href="report.php?attempt_id=<?= (int)$a['attempt_id'] ?>" target="_blank">
                        <svg width="46" height="56" viewBox="0 0 46 56" fill="none" stroke="#444" stroke-width="2"><path d="M4 2h28l10 10v42H4z"/><path d="M32 2v10h10"/><path d="M10 16h20M10 22h26M10 28h14"/><circle cx="26" cy="40" r="8"/><path d="M26 32v8h8" fill="#444"/></svg>
                        <div class="t">View / Download</div>
                        <div class="v">Report</div>
                    </a>
                </div>
                <div class="ac">
                    <a href="response_sheet.php?attempt_id=<?= (int)$a['attempt_id'] ?>" target="_blank">
                        <svg width="46" height="56" viewBox="0 0 46 56" fill="none" stroke="#444" stroke-width="2"><path d="M4 2h28l10 10v42H4z"/><path d="M32 2v10h10"/><path d="M10 18l3 3 5-6M22 19h14M10 30l3 3 5-6M22 31h14M10 42l3 3 5-6M22 43h14"/></svg>
                        <div class="t">View / Download</div>
                        <div class="v">Response Sheet</div>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<div class="nav">
    <a href="index.php">&#8249; Exam Schedule</a>
    <a href="user_dashboard.php">Dashboard &#8250;</a>
</div>
</body>
</html>
