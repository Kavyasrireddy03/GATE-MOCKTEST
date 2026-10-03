<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT attempt_id, created_at, score, total_marks, report_file
    FROM test_attempts
    WHERE user_id = ?
    ORDER BY created_at DESC
");

if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();

/* ✅ FIX: use bind_result instead of get_result */
$stmt->bind_result(
    $attempt_id,
    $created_at,
    $score,
    $total_marks,
    $report_file
);
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Attempts</title>
    <style>
        body { font-family: Arial; background:#f4f6f8; padding:30px; }
        table { border-collapse: collapse; width:100%; background:#fff; }
        th, td { padding:12px; border:1px solid #ddd; text-align:center; }
        th { background:#2563eb; color:#fff; }
        tr:nth-child(even) { background:#f9fafb; }
        a { color:#2563eb; font-weight:bold; }
    </style>
</head>
<body>

<h2>📄 My Test Attempts</h2>
<p>Logged in as <b><?= htmlspecialchars($_SESSION['username']) ?></b></p>

<table>
    <tr>
        <th>Attempt ID</th>
        <th>Date</th>
        <th>Score</th>
        <th>Report</th>
    </tr>

<?php
$hasRows = false;
while ($stmt->fetch()):
    $hasRows = true;
?>
    <tr>
        <td>#<?= $attempt_id ?></td>
        <td><?= $created_at ?></td>
        <td><?= $score ?> / <?= $total_marks ?></td>
        <td>
            <?php if ($report_file && file_exists("attempt_reports/".$report_file)): ?>
                <a href="attempt_reports/<?= htmlspecialchars($report_file) ?>" target="_blank">
                    View Report
                </a>
            <?php else: ?>
                <span style="color:red;">Missing</span>
            <?php endif; ?>
        </td>
    </tr>
<?php endwhile; ?>

<?php if (!$hasRows): ?>
    <tr>
        <td colspan="4">No attempts found</td>
    </tr>
<?php endif; ?>

</table>

<p style="margin-top:20px;">
    <a href="exam.php">⬅ Back to Exam</a> |
    <a href="auth.php?logout=1">Logout</a>
</p>

</body>
</html>

<?php
$stmt->close();
$conn->close();
