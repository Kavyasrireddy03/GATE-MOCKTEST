<?php
/* ================= DATABASE CONNECTION ================= */
require_once __DIR__ . '/config.php'; // provides $conn

/* ================= UPDATE QUESTION ================= */
if (isset($_POST['update_question'])) {

    $id = intval($_POST['id']);

    $q_text_url = $conn->real_escape_string($_POST['q_text_url']);
    $options_json = $conn->real_escape_string($_POST['options_json']);
    $correct_answer_json = $conn->real_escape_string($_POST['correct_answer_json']);
    $marks = floatval($_POST['marks']);

    $range_min = ($_POST['range_min'] === '' ? "NULL" : floatval($_POST['range_min']));
    $range_max = ($_POST['range_max'] === '' ? "NULL" : floatval($_POST['range_max']));

    $sql = "UPDATE questions SET
        q_text_url='$q_text_url',
        options_json='$options_json',
        correct_answer_json='$correct_answer_json',
        marks='$marks',
        range_min=$range_min,
        range_max=$range_max
        WHERE id=$id";

    if ($conn->query($sql)) {
        echo "<p style='color:green'>✅ Question updated successfully</p>";
    } else {
        echo "<p style='color:red'>❌ Update failed</p>";
    }
}

/* ================= FETCH QUESTION FOR EDIT ================= */
$edit_row = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $res = $conn->query("SELECT * FROM questions WHERE id=$id");
    $edit_row = $res->fetch_assoc();
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Question Manager</title>
<style>
body{font-family:Arial;background:#f4f6f8;padding:20px}
.card{background:#fff;padding:15px;margin-bottom:20px;border-radius:8px}
img{max-width:450px;border:1px solid #ccc}
textarea,input,select{width:100%;padding:7px;margin:6px 0}
button{padding:8px 16px;background:#007bff;color:#fff;border:none;border-radius:5px;cursor:pointer}
a{color:#007bff;text-decoration:none}
hr{border:none;border-top:1px solid #ddd}
</style>
</head>
<body>

<h2>🧠 Question Management</h2>

<!-- ================= FILTER ================= -->
<form method="GET" class="card">
    <h3>Filter Questions</h3>

    <select name="subject_id">
        <option value="">-- Subject --</option>
        <?php
        $sub = $conn->query("SELECT * FROM subjects");
        while ($s = $sub->fetch_assoc()) {
            $sel = ($_GET['subject_id'] ?? '') == $s['subject_id'] ? 'selected' : '';
            echo "<option value='{$s['subject_id']}' $sel>{$s['subject_name']}</option>";
        }
        ?>
    </select>

    <select name="set_no">
        <option value="">-- Set --</option>
        <?php for ($i=1; $i<=10; $i++) {
            $sel = ($_GET['set_no'] ?? '') == $i ? 'selected' : '';
            echo "<option $sel>$i</option>";
        } ?>
    </select>

    <select name="q_type">
        <option value="">-- Question Type --</option>
        <?php foreach (['MCQ','MSQ','NAT'] as $t) {
            $sel = ($_GET['q_type'] ?? '') == $t ? 'selected' : '';
            echo "<option $sel>$t</option>";
        } ?>
    </select>

    <button type="submit">Apply Filter</button>
</form>

<!-- ================= EDIT FORM ================= -->
<?php if ($edit_row) { ?>
<div class="card">
<h3>✏ Edit Question (ID <?= $edit_row['id'] ?>)</h3>

<form method="POST">
<input type="hidden" name="id" value="<?= $edit_row['id'] ?>">

<label>Question Image URL</label>
<input name="q_text_url" value="<?= htmlspecialchars($edit_row['q_text_url']) ?>">

<label>Options JSON</label>
<textarea name="options_json" rows="6"><?= htmlspecialchars($edit_row['options_json']) ?></textarea>

<label>Correct Answer JSON</label>
<input name="correct_answer_json" value="<?= htmlspecialchars($edit_row['correct_answer_json']) ?>">

<label>Marks</label>
<input type="number" step="0.5" name="marks" value="<?= $edit_row['marks'] ?>">

<label>Range Min (NAT)</label>
<input name="range_min" value="<?= $edit_row['range_min'] ?>">

<label>Range Max (NAT)</label>
<input name="range_max" value="<?= $edit_row['range_max'] ?>">

<button name="update_question">Update Question</button>
<a href="admin_questions.php">Cancel</a>
</form>
</div>
<?php } ?>

<!-- ================= QUESTION LIST ================= -->
<?php
$where = [];

if (!empty($_GET['subject_id'])) $where[] = "subject_id=" . intval($_GET['subject_id']);
if (!empty($_GET['set_no']))     $where[] = "set_no=" . intval($_GET['set_no']);
if (!empty($_GET['q_type']))     $where[] = "q_type='" . $conn->real_escape_string($_GET['q_type']) . "'";

$sql = "SELECT * FROM questions";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY id DESC";

$q = $conn->query($sql);

while ($row = $q->fetch_assoc()) {
    echo "<div class='card'>";
    echo "<b>ID:</b> {$row['id']} | <b>Type:</b> {$row['q_type']} | <b>Marks:</b> {$row['marks']}<br><br>";

    if ($row['q_text_url']) {
        echo "<img src='{$row['q_text_url']}'><br><br>";
    }

    $opts = json_decode($row['options_json'], true);
    if ($opts) {
        echo "<b>Options:</b><ul>";
        foreach ($opts as $o) {
            echo "<li>{$o['text']}</li>";
        }
        echo "</ul>";
    }

    echo "<b>Correct:</b> {$row['correct_answer_json']}<br>";
    echo "<a href='?edit={$row['id']}'>✏ Edit</a>";
    echo "</div>";
}
?>

</body>
</html>
