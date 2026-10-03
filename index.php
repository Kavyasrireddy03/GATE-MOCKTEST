<?php
session_start(); //
include 'config.php'; // Using your second file's config

/* ==========================
    SESSION AND LOGOUT LOGIC
========================== */
if (isset($_GET['logout'])) { //
    session_unset(); //
    session_destroy(); //
    header("Location: login.php"); //
    exit(); //
}

if (!isset($_SESSION['user_id'])) { //
    header("Location: login.php"); //
    exit(); //
}

$user_id = $_SESSION['user_id']; //

/* ==========================
    FETCH USER PROFILE
========================== */
$user_info_query = $conn->query("SELECT name FROM users WHERE user_id='$user_id'"); //
$user_info = $user_info_query->fetch_assoc(); //
$display_name = $user_info['name'] ?? 'Student'; //

/* ==========================
    FETCH EXAM SCHEDULE
========================== */
$query = "
    SELECT subj.subject_name, s.subject_id, s.set_no, sd.topic_name,
           s.start_time, s.attempt_till, s.duration_minutes
    FROM set_time s
    JOIN subjects subj ON s.subject_id = subj.subject_id
    LEFT JOIN set_definitions sd 
        ON s.subject_id = sd.subject_id 
        AND s.set_no = sd.set_no
    ORDER BY subj.subject_name ASC, s.set_no ASC
"; //

$result = $conn->query($query); //

$schedule = []; //
while($row = $result->fetch_assoc()){ //
    $schedule[$row['subject_name']][] = $row; //
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Exam Schedule</title>
<script src="<?= CDN_TAILWIND ?>"></script>
<style>
body { font-family: 'Segoe UI', Arial; background:#f2f4f7; padding:20px 40px; }
h1 { color:#1e88e5; border-bottom:3px solid #1e88e5; padding-bottom:8px; margin-top: 0; }
table { width:100%; border-collapse:collapse; margin-top:20px; margin-bottom:50px; background:white; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
th,td { padding:15px; border:1px solid #ccc; text-align:center; }
th { background:#2f3b3f; color:white; }
tr:nth-child(even){ background:#f9f9f9; }
.subject-title{ margin-top:30px; font-size:24px; color:#1976d2; font-weight: bold; }

.btn {
    padding:8px 16px;
    border-radius:6px;
    text-decoration:none;
    font-weight:bold;
    font-size:14px;
    background:#00b894;
    color:white;
}
.btn:hover{ background:#019875; }
.duration-badge {
    background:#e3f2fd;
    color:#1565c0;
    padding:6px 12px;
    border-radius:20px;
    font-weight:bold;
}
</style>
</head>
<body>

<div class="flex justify-between items-center mb-10 bg-white p-6 rounded-xl shadow-sm border">
    <div>
        <h1 class="text-3xl font-bold text-gray-800 border-none pb-0">👋 Hello, <?= htmlspecialchars($display_name) ?>!</h1>
        <a href="user_dashboard.php" class="inline-block mt-3 bg-blue-600 text-white px-5 py-2 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md text-sm">📊 BACK TO DASHBOARD</a>
    </div>
    <div class="flex flex-col items-end gap-2">
        <span class="text-xs font-bold text-gray-400 uppercase">Current Session</span>
        <a href="?logout=true" class="bg-red-500 text-white px-5 py-2 rounded-lg font-semibold hover:bg-red-600 transition text-sm">🚪 Logout</a>
    </div>
</div>

<h2 class="text-2xl font-black text-blue-800 mb-2 uppercase tracking-tight">Available Test Sets</h2>

<?php if(empty($schedule)): ?>
    <div class="bg-white p-10 rounded-xl text-center shadow-sm">
        <p class="text-gray-400 text-lg">No exam schedules are currently available.</p>
    </div>
<?php else: ?>
    <?php foreach($schedule as $subject => $sets): ?>

    <div class="subject-title">
        <?= htmlspecialchars($subject) ?> Schedule
    </div>

    <table>
    <thead>
        <tr>
            <th>Set</th>
            <th>Topic Name</th>
            <th>Start Time</th>
            <th>Duration</th>
            <th>Deadline</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach($sets as $s): ?>
    <tr>
        <td>Set <?= $s['set_no'] ?></td>
        <td><?= htmlspecialchars($s['topic_name'] ?? 'General') ?></td>
        <td><?= $s['start_time'] ? (new DateTime($s['start_time']))->modify('+5 hours 30 minutes')->format('d M, h:i A') : 'N/A' ?></td>
        <td>
            <span class="duration-badge">
                <?= $s['duration_minutes'] ?> Minutes
            </span>
        </td>
        <td><?= $s['attempt_till'] ? (new DateTime($s['attempt_till']))->modify('+5 hours 30 minutes')->format('d M, h:i A') : 'N/A' ?></td>
        <td>
            <a class="btn"
               href="instructions.php?set_no=<?= urlencode($s['set_no'] . '|' . $s['subject_id']) ?>">
                Start Test
            </a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>

    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>