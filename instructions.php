<?php
// ----------------------------------------------------
// 1. CONFIGURATION, CONNECTION, AND SESSION
// ----------------------------------------------------

include 'config.php';
session_start(); 

// Security check: Kick them to login if session is empty
if (!isset($_SESSION['user_id'])) {
    header("Location: exam_login.php");
    exit();
}

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database Connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/modules/sections.php';
ensure_sections_schema($conn);

$user_id = $_SESSION['user_id'];

$user_info_query = $conn->query("SELECT name FROM users WHERE user_id='$user_id'");
$user_info = $user_info_query->fetch_assoc();
$display_name = $user_info['name'] ?? 'Student';
$candidateName = $display_name;

// Fetch image directly from session (Processed at login time!)
$display_image = $_SESSION['display_image'] ?? 'user.png';

// Fetch exact counts of 1-mark and 2-mark questions dynamically
$q_1_mark = 0;
$q_2_mark = 0;
$total_questions = 0;
$total_marks_val = 0;
$duration_minutes = 180; // Default
$section_rows = [];       // section name => ['one' => 1-mark count, 'two' => 2-mark count]

$selected = $_GET['set_no'] ?? null;

// Connect to DB and count the questions and fetch duration for the selected set
if ($selected && strpos($selected, '|') !== false) {
    [$selected_set, $selected_subject] = array_map('intval', explode('|', $selected));
    
    // 1. Fetch Duration
    $stmt_time = $conn->prepare("SELECT duration_minutes FROM set_time WHERE set_no = ? AND subject_id = ? LIMIT 1");
    if ($stmt_time) {
        $stmt_time->bind_param("ii", $selected_set, $selected_subject);
        $stmt_time->execute();
        $stmt_time->bind_result($db_duration);
        if ($stmt_time->fetch() && $db_duration) {
            $duration_minutes = (int)$db_duration;
        }
        $stmt_time->close();
    }

    // 2. Fetch Marks, per section (questions without a section count under the subject name)
    $stmt = $conn->prepare("SELECT q.marks, sec.section_name, subj.subject_name
                            FROM questions q
                            LEFT JOIN sections sec ON sec.section_id = q.section_id
                            LEFT JOIN subjects subj ON subj.subject_id = q.subject_id
                            WHERE q.set_no = ? AND q.subject_id = ?
                            ORDER BY COALESCE(sec.sort_order, 2147483647), q.section_id");
    if ($stmt) {
        $stmt->bind_param("ii", $selected_set, $selected_subject);
        $stmt->execute();
        $stmt->bind_result($marks, $sec_name, $subj_name);
        while ($stmt->fetch()) {
            $total_questions++;
            $total_marks_val += (float)$marks;
            if ((float)$marks == 1) $q_1_mark++;
            if ((float)$marks == 2) $q_2_mark++;
            $name = $sec_name ?: ($subj_name ?: 'Subject-specific section');
            $section_rows[$name] = $section_rows[$name] ?? ['one' => 0, 'two' => 0];
            if ((float)$marks == 1) $section_rows[$name]['one']++;
            if ((float)$marks == 2) $section_rows[$name]['two']++;
        }
        $stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Instructions - <?= htmlspecialchars(EXAM_NAME) ?></title>
    <style>
        /* --- CORE LAYOUT --- */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; background: #fff; overflow: hidden; }

        /* --- THIN HEADER BANNER --- */
        #header-banner { height: 45px; width: 100%; background: #ffffff; border-bottom: 1px solid #7691a2; overflow: hidden; }
        #header-banner .logo-wrapper { width: 100%; height: 100%; display: flex; justify-content: center; align-items: center; }
        #header-banner img { height: 100%; width: 100%; object-fit: contain; display: block; }

        /* --- SECONDARY TITLE BAR --- */
        #title-bar { background: #CDE6F5; color: #547A96; font-size: 16px; font-weight: bold; padding: 8px 15px; border-bottom: 1px solid #a3c8de; }

        /* --- MAIN CONTENT AREA --- */
        #main-container { display: flex; height: calc(100vh - 45px - 36px - 22px); width: 100%; }

        /* --- LEFT PANEL --- */
        #left-panel { flex: 1; display: flex; flex-direction: column; background: #fff; height: 100%; overflow: hidden; }
        
        .page-wrapper { display: flex; flex-direction: column; height: 100%; }
        
        .instructions-scroll-area { flex: 1; overflow-y: auto; padding: 20px 40px; font-size: 13px; line-height: 1.6; color: #333; }
        .instructions-scroll-area h3 { text-align: center; font-size: 16px; margin-bottom: 20px; color: #000; }
        .instructions-scroll-area h4 { font-size: 14px; margin-top: 20px; margin-bottom: 10px; color: #000; font-weight: bold; }
        .instructions-scroll-area p, .instructions-scroll-area ol { margin-bottom: 15px; }
        .instructions-scroll-area ol { margin-left: 15px; padding-left: 15px; }
        .instructions-scroll-area li { margin-bottom: 10px; padding-left: 5px; }
        .instructions-scroll-area .alpha-list { list-style-type: lower-alpha; margin-left: 15px; padding-left: 15px; }

        /* --- EXACT PALETTE BUTTON DESIGNS --- */
        .palette-table { width: 80%; border-collapse: collapse; margin-bottom: 20px; border: 1px solid #ccc; font-size: 13px; }
        .palette-table td { border: 1px solid #ccc; padding: 8px; vertical-align: middle; }
        .palette-table td:first-child { width: 65px; text-align: center; }
        
        .shortcut { width:32px; height:32px; display:inline-block; font-size:13px; font-weight:bold; line-height:22px; text-align:center; color:#fff; user-select:none; position: relative; border: 1px solid #999; }
        .shortcut::before { content:attr(data-num); display:inline-block; line-height:32px; }
        .up { clip-path: polygon(0% 20%, 33.333% 0%, 66.667% 0%, 100% 20%, 100% 100%, 0% 100%); }
        .down { clip-path: polygon(0% 0%, 100% 0%, 100% 80%, 66.667% 100%, 33.333% 100%, 0% 80%); }
        .answered { background:#6DB825; border-color:#5ca01f;}
        .unanswered { background:#FF5252; border-color:#e64a4a;}
        .review { background:#755197; clip-path:none; border-radius: 50%; border-color:#654682;}
        .unvisited { background:#D9D9D9; color:#000; border-color:#999;}
        .answered-marked-dot::after { content: ''; position: absolute; bottom: 0px; right: 0px; width: 10px; height: 10px; background: #6DB825; border-radius: 50%; border: 1px solid white; }

        .marks-table { width: 60%; border-collapse: collapse; margin: 15px auto; border: 1px solid #000; font-size: 13px; text-align: center; }
        .marks-table th, .marks-table td { border: 1px solid #000; padding: 6px; }
        .marks-table th { font-weight: bold; }

        /* --- PINNED CHECKBOX AREA --- */
        .checkbox-area { border-top: 1px solid #ccc; padding: 15px 20px; background: #fff; }
        .disclaimer-row { display: flex; align-items: flex-start; font-size: 11.5px; color: #333; cursor: pointer; line-height: 1.4; }
        .disclaimer-row input { margin-right: 10px; margin-top: 2px; cursor: pointer; }

        /* --- ACTION BAR (Next / Ready Buttons) --- */
        .action-bar { border-top: 1px solid #ccc; padding: 10px 20px; display: flex; background: #fff; align-items: center; }
        .btn-white { background: #fff; border: 1px solid #ccc; padding: 6px 18px; font-size: 13px; font-weight: bold; cursor: pointer; border-radius: 2px; color: #333; transition: 0.2s; }
        .btn-white:hover { background: #e6e6e6; }
        
        .btn-ready { padding: 8px 24px; font-size: 14px; color: #fff; border: none; border-radius: 2px; transition: 0.2s; }
        .btn-ready.disabled { background: #88acc2; cursor: not-allowed; }
        .btn-ready.active { background: #287baf; cursor: pointer; }
        .btn-ready.active:hover { background: #1f6491; }

        /* --- RIGHT PANEL: PROFILE --- */
        #right-panel { width: 230px; border-left: 2px solid #a3c8de; background: #fff; display: flex; flex-direction: column; align-items: center; padding: 30px 10px; }
        .profile-pic { width: 105px; height: 125px; background: #f5f5f5; border: 1px solid #999; margin-bottom: 15px; overflow: hidden; }
        .profile-pic img { width: 100%; height: 100%; object-fit: cover; }
        .profile-name { font-size: 14px; font-weight: bold; color: #4F6887; text-align: center; }

        /* --- FOOTER --- */
        #footer { position: fixed; bottom: 0; width: 100%; height: 22px; background: #617B8C; color: #fff; line-height: 22px; text-align: center; font-size: 11px; z-index: 1000; }
    </style>
</head>
<body onselectstart="return false;" ondragstart="return false;">

    <div id="header-banner">
        <div class="logo-wrapper">
            <img src="gatelogo.png" alt="<?= htmlspecialchars(EXAM_NAME) ?>">
        </div>
    </div>

    <div id="title-bar">Instructions</div>

    <div id="main-container">
        
        <div id="left-panel">
            
            <div id="page-1" class="page-wrapper">
                <div class="instructions-scroll-area">
                    <?php include __DIR__ . '/modules/general_instructions.php'; ?>
                </div>
                <div class="action-bar" style="justify-content: flex-end;">
                    <button class="btn-white" onclick="showPage2()">Next ></button>
                </div>
            </div>

            <div id="page-2" class="page-wrapper" style="display: none;">
                
                <div class="instructions-scroll-area">
                    <h3>Paper-specific instructions</h3>
                    <p><b>Please read the following carefully.</b></p>
                    
                    <p>This question paper has <b><?php echo $total_questions; ?></b> questions for a total of <b><?php echo $total_marks_val; ?></b> marks. It consists of <?php echo max(1, count($section_rows)); ?> section<?php echo count($section_rows) > 1 ? 's' : ''; ?>. The marks distribution is as follows:</p>
                    
                    <table class="marks-table">
                        <tr>
                            <th>Section</th>
                            <th>1-mark questions</th>
                            <th>2-mark questions</th>
                        </tr>
                        <?php foreach ($section_rows as $sec_label => $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sec_label); ?></td>
                            <td><?php echo $row['one']; ?></td>
                            <td><?php echo $row['two']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    
                    <p style="text-align: center;">The 1-mark questions are followed by the 2-mark questions in each section.</p>
                </div>

                <div class="checkbox-area">
                    <label class="disclaimer-row">
                        <input type="checkbox" id="disclaimer-checkbox" onclick="toggleReadyButton()">
                        <span>I have read and understood the instructions. All computer hardware allotted to me are in proper working condition. I declare that I am not in possession of / not wearing / not carrying any prohibited gadget like mobile phone, Bluetooth devices etc. /any prohibited material with me into the Examination Hall. I agree that in case of not adhering to the instructions, I shall be liable to be debarred from this Test and/or to disciplinary action, which may include ban from future Tests/Examinations.</span>
                    </label>
                </div>

                <div class="action-bar">
                    <div style="flex: 1;">
                        <button class="btn-white" onclick="showPage1()">< Previous</button>
                    </div>
                    <div style="flex: 1; display: flex; justify-content: center;">
                        <button id="ready-btn" class="btn-ready disabled" disabled="disabled" onclick="launchSecureAssessment('<?php echo htmlspecialchars($selected ?? '', ENT_QUOTES); ?>')">I am ready to begin</button>
                    </div>
                    <div style="flex: 1;"></div> 
                </div>
            </div>

        </div>

        <div id="right-panel">
            <div class="profile-pic">
                <img src="<?php echo htmlspecialchars($display_image); ?>" alt="Candidate Photo" onerror="this.onerror=null; this.src='user.png';">
            </div>
            <div class="profile-name"><?php echo htmlspecialchars($candidateName); ?></div>
        </div>

    </div>

    <div id="footer">Version : 17.07.00</div>

    <script>
        function showPage2() {
            document.getElementById('page-1').style.display = 'none';
            document.getElementById('page-2').style.display = 'flex';
            document.getElementById('title-bar').innerText = 'Other Important Instructions';
        }

        function showPage1() {
            document.getElementById('page-2').style.display = 'none';
            document.getElementById('page-1').style.display = 'flex';
            document.getElementById('title-bar').innerText = 'Instructions';
        }

        function toggleReadyButton() {
            var checkBox = document.getElementById("disclaimer-checkbox");
            var btn = document.getElementById("ready-btn");
            
            if (checkBox.checked) {
                btn.disabled = false;
                btn.className = "btn-ready active";
            } else {
                btn.disabled = true;
                btn.className = "btn-ready disabled";
            }
        }

        // 🔥 THE MAGIC FIX: This wipes memory and opens the secure popup
        function launchSecureAssessment(selectedParams) {
            
            // Wipe any old saved time/answers before opening the new test!
            localStorage.removeItem('gate_mock_autosave_v1');

            const examUrl = 'exam.php?set_no=' + selectedParams; 

            // Create window features for a full-screen, URL-hidden popup
            const windowFeatures = `width=${window.screen.availWidth},height=${window.screen.availHeight},top=0,left=0,toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes`;
            
            const examWindow = window.open(examUrl, "SecureExam", windowFeatures);

            if (!examWindow) {
                alert("Popup blocker prevented the assessment from opening. Please allow popups for this site in your browser URL bar and try again.");
                return;
            }

            // Replace the current instructions page with a locked dashboard
            document.body.innerHTML = `
                <div style='display:flex; flex-direction:column; justify-content:center; align-items:center; height:100vh; background:#f0f4f8; font-family:Arial;'>
                    <h1 style='color:#1F4E79; font-size:32px; margin-bottom:10px;'>Assessment Launched Successfully</h1>
                    <p style='color:#333; font-size:16px;'>Your assessment is running in a secure full-screen window.</p>
                    <p style='color:#d9534f; font-size:14px; margin-top:5px; font-weight:bold;'>Do not close the secure window until you have submitted your test.</p>
                    <button onclick="window.location.href='exam_login.php'" style="margin-top:25px; padding:12px 24px; background:#287baf; font-weight:bold; color:white; border:none; border-radius:4px; cursor:pointer;">Return to Dashboard</button>
                </div>
            `;
        }

        // ==========================================
        // BRUTALLY DISABLE PHYSICAL KEYBOARD
        // ==========================================
        ['keydown', 'keyup', 'keypress'].forEach(function(eventType) {
            window.addEventListener(eventType, function(e) {
                e.preventDefault();   // Stops the key from doing anything
                e.stopPropagation();  // Stops the key press from reaching the browser
                return false;
            }, true); // The 'true' ensures this runs before any other scripts
        });
        // ==========================================
    </script>

</body>
</html>