<?php
// ----------------------------------------------------
// 1. DATABASE CONFIGURATION & CONNECTION
// ----------------------------------------------------
require_once __DIR__ . '/config.php'; // DB settings
$host = $servername;
$db   = $dbname;
$user = $username;
$pass = $password;
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Fetch subjects for filter from the subjects table
$subjects = $pdo->query("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name ASC")->fetchAll();

// ----------------------------------------------------
// 2. AJAX ENDPOINT (Returns random question by type)
// ----------------------------------------------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $subject_id = $_GET['subject_id'] ?? 'all';
    
    if ($subject_id === 'all') {
        $stmt = $pdo->query("SELECT * FROM `questions` ORDER BY RAND() LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `questions` WHERE subject_id = ? ORDER BY RAND() LIMIT 1");
        $stmt->execute([(int)$subject_id]);
    }
    
    $question = $stmt->fetch();
    if ($question) {
        $question['options_json'] = json_decode($question['options_json'], true);
        $question['correct_answer_json'] = json_decode($question['correct_answer_json'], true);
        echo json_encode(['success' => true, 'data' => $question]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No questions found for this subject.']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GATE Random Question Picker</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; display: flex; justify-content: center; padding: 20px; }
        .quiz-card { background: white; width: 100%; max-width: 850px; padding: 30px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.1); }
        
        /* Header and Meta */
        .header-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .q-type-badge { background: #e7f3ff; color: #1877f2; padding: 4px 10px; border-radius: 6px; font-weight: 800; font-size: 12px; }
        
        /* Layout */
        .q-image-container { display: flex; justify-content: center; margin-bottom: 25px; }
        .q-image { max-width: 100%; border-radius: 8px; border: 1px solid #ddd; }
        
        /* Options (Centered for consistency with source images) */
        .option-btn { display: flex; align-items: center; justify-content: center; width: 100%; padding: 20px; margin: 12px 0; border: 2px solid #e4e6eb; border-radius: 10px; background: white; cursor: pointer; min-height: 80px; transition: 0.2s; }
        .option-btn.selected { border-color: #1877f2; background: #f0f7ff; box-shadow: inset 0 0 0 1px #1877f2; }
        .option-img { max-width: 95%; height: auto; }
        
        /* NAT Input */
        .nat-input-container { padding: 30px; text-align: center; background: #f8fafc; border-radius: 10px; border: 2px dashed #cbd5e1; margin: 20px 0; }
        #natAnswer { padding: 12px; width: 250px; font-size: 24px; font-weight: bold; border: 2px solid #94a3b8; border-radius: 8px; text-align: center; }
        
        /* Feedback Styling */
        .correct { background: #e6fcf5 !important; border-color: #20c997 !important; color: #099268 !important; }
        .wrong { background: #fff5f5 !important; border-color: #ff6b6b !important; color: #c92a2a !important; }
        
        /* Explanation and Actions */
        #explanationBox { margin-top: 25px; padding: 20px; background: #fafff0; border-left: 5px solid #82c91e; border-radius: 4px; display: none; }
        .footer-actions { display: flex; justify-content: space-between; margin-top: 20px; }
        .btn-check { background: #20c997; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn-next { background: #1877f2; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        
        #loading { text-align: center; padding: 40px; color: #6b7280; font-style: italic; }
        .option-logic-text { display: none; }
    </style>
</head>
<body>

<div class="quiz-card">
    <div class="header-meta">
        <div>
            <select id="subjectSelect" onchange="loadQuestion()" style="padding: 8px; border-radius: 5px; border: 1px solid #ddd;">
                <option value="all">-- All Subjects (Mixed) --</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= $s['subject_id'] ?>"><?= htmlspecialchars($s['subject_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <span id="qTypeBadge" class="q-type-badge">TYPE</span>
            <span id="qMarks" style="font-weight: 600;"></span>
        </div>
    </div>
    
    <div id="loading">Picking a random question...</div>
    
    <div id="content" style="display:none;">
        <div class="q-image-container"><img id="questionImg" class="q-image" src=""></div>
        <div id="optionsContainer"></div>
        <div id="explanationBox">
            <strong style="color: #4a7a06;">Solution Explanation:</strong> 
            <div id="expText" style="margin-top: 8px;"></div>
        </div>
        <div class="footer-actions">
            <button id="checkBtn" class="btn-check" onclick="processSelection()">Check Answer</button>
            <button class="btn-next" onclick="loadQuestion()">Next Question ➔</button>
        </div>
    </div>
</div>

<script>
    let currentQ = null;
    let selectedOptions = [];

    const getSafeImageUrl = (url) => {
        if (!url) return '';
        const match = url.match(/id=([a-zA-Z0-9_-]+)/) || url.match(/\/d\/([a-zA-Z0-9_-]+)/);
        return match ? `https://drive.google.com/thumbnail?id=${match[1]}&sz=s1600` : url;
    };

    async function loadQuestion() {
        const subjectId = document.getElementById('subjectSelect').value;
        document.getElementById('content').style.display = 'none';
        document.getElementById('explanationBox').style.display = 'none';
        document.getElementById('loading').style.display = 'block';
        selectedOptions = [];

        try {
            const res = await fetch(`?ajax=1&subject_id=${subjectId}`);
            const result = await res.json();
            if (result.success) {
                currentQ = result.data;
                document.getElementById('qTypeBadge').innerText = currentQ.q_type;
                document.getElementById('qMarks').innerText = "Marks: " + parseFloat(currentQ.marks).toFixed(2);
                document.getElementById('questionImg').src = getSafeImageUrl(currentQ.q_text_url);
                document.getElementById('expText').innerText = currentQ.explanation || "No explanation provided.";

                const container = document.getElementById('optionsContainer');
                container.innerHTML = '';
                
                if (currentQ.q_type === 'NAT') {
                    // Inject Input Box for NAT questions
                    container.innerHTML = `
                        <div class="nat-input-container">
                            <p style="margin-bottom: 10px; color: #64748b;">Type your numerical answer below:</p>
                            <input type="number" id="natAnswer" placeholder="0.00" step="any">
                        </div>`;
                } else {
                    currentQ.options_json.forEach(opt => {
                        const btn = document.createElement('button');
                        btn.className = 'option-btn';
                        // Logic span is hidden to avoid redundant labels
                        btn.innerHTML = `<span class="option-logic-text">${opt.text}</span>` + 
                                       (opt.image ? `<img src="${getSafeImageUrl(opt.image)}" class="option-img">` : opt.text);
                        btn.onclick = () => toggleOption(btn, opt.text);
                        container.appendChild(btn);
                    });
                }
                document.getElementById('loading').style.display = 'none';
                document.getElementById('content').style.display = 'block';
            }
        } catch (e) { alert("Error loading question."); }
    }

    function toggleOption(btn, val) {
        if (currentQ.q_type === 'MCQ') {
            document.querySelectorAll('.option-btn').forEach(b => b.classList.remove('selected'));
            selectedOptions = [val];
            btn.classList.add('selected');
        } else if (currentQ.q_type === 'MSQ') {
            // Allow Multiple Selection for MSQ
            if (selectedOptions.includes(val)) {
                selectedOptions = selectedOptions.filter(i => i !== val);
                btn.classList.remove('selected');
            } else {
                selectedOptions.push(val);
                btn.classList.add('selected');
            }
        }
    }

    function processSelection() {
        const expBox = document.getElementById('explanationBox');
        let isCorrect = false;

        if (currentQ.q_type === 'NAT') {
            const userVal = parseFloat(document.getElementById('natAnswer').value);
            const min = parseFloat(currentQ.range_min);
            const max = parseFloat(currentQ.range_max);
            
            // Numerical validation logic based on your range requirements
            isCorrect = (!isNaN(userVal) && userVal >= min && userVal <= max);
            const input = document.getElementById('natAnswer');
            input.classList.remove('correct', 'wrong');
            input.classList.add(isCorrect ? 'correct' : 'wrong');
            
            // Show accepted range in explanation if wrong
            if (!isCorrect) {
                document.getElementById('expText').innerHTML += `<br><b style="color:red">Correct Range: ${min} to ${max}</b>`;
            }
        } else {
            const correctSet = currentQ.correct_answer_json;
            isCorrect = (correctSet.length === selectedOptions.length && correctSet.every(v => selectedOptions.includes(v)));
            
            document.querySelectorAll('.option-btn').forEach((b, idx) => {
                const btnVal = currentQ.options_json[idx].text;
                if (correctSet.includes(btnVal)) b.classList.add('correct');
                else if (selectedOptions.includes(btnVal)) b.classList.add('wrong');
                b.disabled = true;
            });
        }
        expBox.style.display = 'block';
    }

    // Load first question on page start
    loadQuestion();
</script>
</body>
</html>