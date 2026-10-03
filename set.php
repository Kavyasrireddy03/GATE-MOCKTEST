<?php
include 'config.php';

$message = '';

// --- Fetch all subjects ---
$subjects = mysqli_query($conn, "SELECT subject_id, subject_name FROM subjects ORDER BY subject_name ASC");
$subjectOptions = [];
while ($row = mysqli_fetch_assoc($subjects)) {
    $subjectOptions[$row['subject_id']] = $row['subject_name'];
}

// --- Get selected subject ---
$selected_subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

// --- Create table if not exists ---
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS set_time (
    set_no INT,
    subject_id INT,
    duration_minutes INT NOT NULL DEFAULT 30,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (set_no, subject_id)
)
");

// --- Handle Save (Durations & Subject) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_no'])) {
    $stmt = mysqli_prepare($conn, "
        INSERT INTO set_time (set_no, subject_id, duration_minutes)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            duration_minutes = VALUES(duration_minutes)
    ");
    foreach ($_POST['set_no'] as $i => $set_no) {
        $set_no_val = intval($set_no);
        $subject_id = intval($_POST['subject_id']);
        $duration = intval($_POST['duration_minutes'][$i]);
        mysqli_stmt_bind_param($stmt, "iii", $set_no_val, $subject_id, $duration);
        mysqli_stmt_execute($stmt);
    }
    mysqli_stmt_close($stmt);
    $message = "<p style='color:green;'>✅ Set durations saved successfully!</p>";
}

// --- Handle assigning unassigned questions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_sets') {
    if (!empty($_POST['question_id'])) {
        $stmt = mysqli_prepare($conn, "UPDATE questions SET set_no = ? WHERE id = ?");
        $update_count = 0;
        foreach ($_POST['question_id'] as $i => $q_id) {
            $new_set_no_val = $_POST['new_set_no'][$i];
            if (!empty($new_set_no_val) && intval($new_set_no_val) > 0) {
                $new_set_no = intval($new_set_no_val);
                $q_id = intval($q_id);
                mysqli_stmt_bind_param($stmt, "ii", $new_set_no, $q_id);
                if (mysqli_stmt_execute($stmt)) $update_count++;
            }
        }
        mysqli_stmt_close($stmt);
        if ($update_count > 0) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?subject_id=" . $selected_subject_id . "&assign_success=" . $update_count);
            exit;
        }
    }
}

// --- Fetch Sets with Statistics ---
$sets_data = [];
$total_subject_marks = 0;
$total_subject_qs = 0;

if ($selected_subject_id > 0) {
    $sets_sql = "
        SELECT 
            q.set_no,
            COUNT(q.id) as total_qs,
            SUM(q.marks) as total_set_marks,
            SUM(CASE WHEN q.q_type = 'MCQ' THEN 1 ELSE 0 END) as mcq_count,
            SUM(CASE WHEN q.q_type = 'MSQ' THEN 1 ELSE 0 END) as msq_count,
            SUM(CASE WHEN q.q_type = 'NAT' THEN 1 ELSE 0 END) as nat_count,
            SUM(CASE WHEN q.marks = 1 THEN 1 ELSE 0 END) as marks_1_count,
            SUM(CASE WHEN q.marks = 2 THEN 1 ELSE 0 END) as marks_2_count,
            st.duration_minutes
        FROM questions q
        LEFT JOIN set_time st ON q.set_no = st.set_no AND q.subject_id = st.subject_id
        WHERE q.subject_id = $selected_subject_id 
          AND q.set_no IS NOT NULL AND q.set_no != 0 
        GROUP BY q.set_no
        ORDER BY q.set_no ASC";
    
    $sets_result = mysqli_query($conn, $sets_sql);
    while ($row = mysqli_fetch_assoc($sets_result)) {
        $sets_data[] = $row;
        $total_subject_marks += $row['total_set_marks'];
        $total_subject_qs += $row['total_qs'];
    }

    $unassigned_sql = "SELECT id, q_text_url FROM questions WHERE subject_id = $selected_subject_id AND (set_no IS NULL OR set_no = 0 OR set_no = '0' OR TRIM(set_no) = '') ORDER BY id ASC";
    $unassigned_result = mysqli_query($conn, $unassigned_sql);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>📚 Manage Sets & Marks</title>
    <style>
        body { font-family: Arial; margin: 25px; background: #f8f9fa; }
        h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 5px; }
        select, input[type="number"] { padding: 6px; border: 1px solid #ccc; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; background: white; margin-top: 15px; }
        th, td { padding: 12px; border: 1px solid #ccc; text-align: left; }
        th { background: #343a40; color: white; font-size: 0.85em; text-transform: uppercase; }
        .stats-badge { display: inline-block; padding: 2px 8px; background: #f1f1f1; border-radius: 12px; font-size: 0.8em; margin-right: 4px; border: 1px solid #ddd; font-weight: bold; }
        .mark-total { font-size: 1.1em; color: #28a745; font-weight: bold; }
        .summary-footer { background: #e9ecef; font-weight: bold; }
        button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>

<h2>📚 Set Management & Mark Tracker</h2>
<div class="message"><?= $message ?></div>

<form method="GET">
    <select name="subject_id" onchange="this.form.submit()" required>
        <option value="">-- Select Subject --</option>
        <?php foreach ($subjectOptions as $id => $name): ?>
            <option value="<?= $id ?>" <?= ($id == $selected_subject_id ? 'selected' : '') ?>><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if ($selected_subject_id > 0): ?>
    <h3>📘 Stats for <?= htmlspecialchars($subjectOptions[$selected_subject_id]) ?></h3>

    <?php if (empty($sets_data)): ?>
        <p>No sets assigned yet.</p>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="subject_id" value="<?= $selected_subject_id ?>">
            <table>
                <thead>
                    <tr>
                        <th>Set</th>
                        <th>Type Breakdown</th>
                        <th>Mark Distribution</th>
                        <th>Total Marks</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sets_data as $row): ?>
                <tr>
                    <td><b>Set <?= $row['set_no'] ?></b><br><small><?= $row['total_qs'] ?> Questions</small>
                        <input type="hidden" name="set_no[]" value="<?= $row['set_no'] ?>">
                    </td>
                    <td>
                        <span class="stats-badge">MCQ: <?= $row['mcq_count'] ?></span>
                        <span class="stats-badge">MSQ: <?= $row['msq_count'] ?></span>
                        <span class="stats-badge">NAT: <?= $row['nat_count'] ?></span>
                    </td>
                    <td>
                        <span class="stats-badge" style="border-left: 3px solid #ffc107;">1M: <?= $row['marks_1_count'] ?></span>
                        <span class="stats-badge" style="border-left: 3px solid #17a2b8;">2M: <?= $row['marks_2_count'] ?></span>
                    </td>
                    <td class="mark-total"><?= $row['total_set_marks'] ?> pts</td>
                    <td><input type="number" name="duration_minutes[]" value="<?= $row['duration_minutes'] ?? 30 ?>" style="width:60px;"> min</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="summary-footer">
                        <td colspan="3" style="text-align:right;">Subject Totals:</td>
                        <td class="mark-total"><?= $total_subject_marks ?> pts</td>
                        <td><?= $total_subject_qs ?> Qs</td>
                    </tr>
                </tfoot>
            </table>
            <br><button type="submit">💾 Update Durations</button>
        </form>
    <?php endif; ?>

    <hr>
    <h3>📝 Pending Questions (<?= mysqli_num_rows($unassigned_result) ?>)</h3>
    <?php if ($unassigned_result && mysqli_num_rows($unassigned_result) > 0): ?>
        <form method="POST">
            <input type="hidden" name="action" value="assign_sets">
            <table>
                <tr><th>ID</th><th>Preview</th><th>Assign Set</th></tr>
                <?php while ($row = mysqli_fetch_assoc($unassigned_result)): ?>
                <tr>
                    <td><?= $row['id'] ?><input type="hidden" name="question_id[]" value="<?= $row['id'] ?>"></td>
                    <td><small><?= htmlspecialchars(substr($row['q_text_url'], 0, 50)) ?>...</small></td>
                    <td><input type="number" name="new_set_no[]" placeholder="Set #" style="width:60px;"></td>
                </tr>
                <?php endwhile; ?>
            </table>
            <button type="submit">Assign Selected</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>