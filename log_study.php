<?php
include 'db.php';

// 1. Get parameters from the Email link
$user_id = (int)($_GET['uid'] ?? 0);
$status_param = $_GET['status'] ?? '';
$today = date('Y-m-d');

if (!$user_id) { 
    die("Error: Invalid Access."); 
}

/* ==========================================================
   DEBUG LOGGING FUNCTION
   ========================================================== */
function write_debug($msg) {
    $log_file = __DIR__ . '/study_tracker_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $msg" . PHP_EOL, FILE_APPEND);
}

/* ==========================================================
   2. CHECK IF ALREADY RESPONDED TODAY
   ========================================================== */
$check_query = $conn->query("SELECT status, subject_name FROM daily_study_logs WHERE user_id = $user_id AND study_date = '$today'");
$existing_record = $check_query->fetch_assoc();

// If the status is already 'studied' OR the user explicitly clicked 'not_studied' earlier
if ($existing_record && ($existing_record['status'] === 'studied' || ($existing_record['status'] === 'not_studied' && isset($_GET['already_submitted'])))) {
    write_debug("User $user_id tried to re-access form, but already responded today.");
    echo "
    <div style='text-align:center; padding:50px; font-family:sans-serif; background:#f4f4f9; min-height:100vh;'>
        <div style='background:white; display:inline-block; padding:30px; border-radius:15px; shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);'>
            <h1 style='color:#3b82f6;'>Already Responded! ✅</h1>
            <p style='color:#64748b;'>You have already logged your progress for today ($today).</p>
            " . ($existing_record['subject_name'] ? "<p>Logged: <b>{$existing_record['subject_name']}</b></p>" : "") . "
            <br>
            <a href='user_dashboard.php' style='color:#2563eb; text-decoration:none; font-weight:bold;'>Go to Dashboard →</a>
        </div>
    </div>";
    exit;
}

/* ==========================================================
   3. HANDLE FORM SUBMISSION
   ========================================================== */
if (isset($_POST['selected_subjects']) && is_array($_POST['selected_subjects'])) {
    $subject_ids = $_POST['selected_subjects'];
    $subject_names = [];

    $ids_string = implode(',', array_map('intval', $subject_ids));
    $sub_query = $conn->query("SELECT subject_name FROM subjects WHERE subject_id IN ($ids_string)");
    
    while($row = $sub_query->fetch_assoc()) {
        $subject_names[] = $row['subject_name'];
    }

    $final_subjects = implode(', ', $subject_names);

    $stmt = $conn->prepare("UPDATE daily_study_logs SET status = 'studied', subject_name = ? WHERE user_id = ? AND study_date = ?");
    $stmt->bind_param("sis", $final_subjects, $user_id, $today);
    
    if($stmt->execute()) {
        write_debug("User $user_id successfully logged subjects: $final_subjects");
        // Redirect to same page with a flag to show 'Success'
        header("Location: log_study.php?uid=$user_id&already_submitted=1");
        exit;
    }
}

/* ==========================================================
   4. HANDLE "NOT STUDIED" INITIAL CLICK
   ========================================================== */
if ($status_param === 'not_studied') {
    $stmt = $conn->prepare("UPDATE daily_study_logs SET status = 'not_studied', subject_name = NULL WHERE user_id = ? AND study_date = ?");
    $stmt->bind_param("is", $user_id, $today);
    $stmt->execute();
    write_debug("User $user_id clicked 'Not Studied'");
    header("Location: log_study.php?uid=$user_id&already_submitted=1");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Study Tracker</title>
    <script src="<?= CDN_TAILWIND ?>"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">

    <div class="bg-white rounded-2xl shadow-xl p-6 w-full max-w-md">
        <div class="text-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Study Check-in</h2>
            <p class="text-gray-500">Date: <?= date('M d, Y') ?></p>
        </div>

        <form method="POST" class="space-y-3">
            <p class="font-medium text-gray-700 mb-2">What subjects did you tackle today?</p>
            <div class="max-h-72 overflow-y-auto border-y border-gray-100 py-2">
                <?php
                $subjects = $conn->query("SELECT * FROM subjects ORDER BY subject_name ASC");
                while($s = $subjects->fetch_assoc()):
                ?>
                    <label class="flex items-center p-3 hover:bg-blue-50 rounded-lg cursor-pointer transition-colors">
                        <input type="checkbox" name="selected_subjects[]" value="<?= $s['subject_id'] ?>" class="w-5 h-5 text-blue-600 rounded">
                        <span class="ml-3 text-gray-700"><?= htmlspecialchars($s['subject_name']) ?></span>
                    </label>
                <?php endwhile; ?>
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-xl shadow-lg transition-all mt-4 transform active:scale-95">
                Save Daily Progress
            </button>
        </form>
    </div>

</body>
</html>