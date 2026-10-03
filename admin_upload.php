<?php
include 'db.php';

// Fetch subjects
$subjects = $conn->query("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upload Question</title>
<link rel="stylesheet" href="<?= CDN_CROPPER_CSS ?>">
<style>
body { font-family: Arial; background: #f7f7f7; margin: 20px; }
.card { background: #fff; padding: 20px; border-radius: 10px; max-width: 800px; margin: auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1);}
label { font-weight: bold; display:block; margin-top:12px; }
select,input,button { width:100%; padding:8px; margin-top:5px; border-radius:6px; border:1px solid #ccc; }
button { background:#007bff; color:#fff; border:none; cursor:pointer; margin-top:15px; }
button:hover { background:#0056b3; }
.preview { margin-top:8px; width:100%; max-height:250px; object-fit:contain; border:1px solid #ddd; border-radius:5px; }
#status { margin-top:15px; font-weight:bold; }
</style>
</head>
<body>
<div class="card">
<h2>Upload Question</h2>
<form id="uploadForm">
    <label>Subject</label>
    <select id="subject" required>
        <option value="">-- Select Subject --</option>
        <?php while($row = $subjects->fetch_assoc()): ?>
            <option value="<?= $row['subject_id'] ?>"><?= htmlspecialchars($row['subject_name']) ?></option>
        <?php endwhile; ?>
    </select>

    <label>Question Type</label>
    <select id="type" required>
        <option value="MCQ">MCQ</option>
        <option value="MSQ">MSQ</option>
        <option value="NAT">NAT</option>
    </select>

    <label>Correct Answer(s)</label>
    <input type="text" id="correct" placeholder="e.g. A or A,B or numeric for NAT">

    <label>Question Image</label>
    <input type="file" id="qImage" accept="image/*" required>
    <img id="qPreview" class="preview">

    <label>Option A Image</label>
    <input type="file" id="optA" accept="image/*">
    <img id="aPreview" class="preview">

    <label>Option B Image</label>
    <input type="file" id="optB" accept="image/*">
    <img id="bPreview" class="preview">

    <label>Option C Image</label>
    <input type="file" id="optC" accept="image/*">
    <img id="cPreview" class="preview">

    <label>Option D Image</label>
    <input type="file" id="optD" accept="image/*">
    <img id="dPreview" class="preview">

    <label>Solution Image (optional)</label>
    <input type="file" id="sol" accept="image/*">
    <img id="solPreview" class="preview">

    <button type="submit">Upload & Save</button>
</form>
<div id="status"></div>
</div>

<script src="<?= CDN_CROPPER_JS ?>"></script>
<script>
const scriptURL = "YOUR_GOOGLE_APPS_SCRIPT_URL"; // Replace with your Apps Script Web App URL
let croppers = {};

function setupCropper(inputId, previewId){
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    input.addEventListener("change", e => {
        const file = e.target.files[0];
        if(!file) return;
        const reader = new FileReader();
        reader.onload = ()=>{
            preview.src = reader.result;
            if(croppers[inputId]) croppers[inputId].destroy();
            croppers[inputId] = new Cropper(preview,{aspectRatio: NaN, viewMode: 1});
        };
        reader.readAsDataURL(file);
    });
}

["qImage","optA","optB","optC","optD","sol"].forEach(id => setupCropper(id,id.replace("Image","Preview").replace("opt","").replace("sol","solPreview")));

document.getElementById("uploadForm").addEventListener("submit", async e=>{
    e.preventDefault();
    const status = document.getElementById("status");
    status.innerHTML = "⏳ Uploading images...";

    async function uploadImage(id){
        if(!croppers[id]) return "";
        const blob = await new Promise(resolve=>croppers[id].getCroppedCanvas().toBlob(resolve,"image/png"));
        const formData = new FormData();
        formData.append("file", blob, id+".png");
        const res = await fetch(scriptURL,{method:"POST",body:formData});
        const data = await res.json();
        return data.url || "";
    }

    const [qUrl,aUrl,bUrl,cUrl,dUrl,solUrl] = await Promise.all([
        uploadImage("qImage"),
        uploadImage("optA"),
        uploadImage("optB"),
        uploadImage("optC"),
        uploadImage("optD"),
        uploadImage("sol")
    ]);

    status.innerHTML = "✅ Images uploaded. Saving question...";

    const fd = new FormData();
    fd.append("subject_id", document.getElementById("subject").value);
    fd.append("type", document.getElementById("type").value);
    fd.append("correct", document.getElementById("correct").value);
    fd.append("qUrl", qUrl);
    fd.append("aUrl", aUrl);
    fd.append("bUrl", bUrl);
    fd.append("cUrl", cUrl);
    fd.append("dUrl", dUrl);
    fd.append("solUrl", solUrl);

    const response = await fetch("save_question.php",{method:"POST",body:fd});
    const result = await response.json();
    status.innerHTML = result.status === "success" ? "✅ "+result.message : "❌ "+result.message;
});
</script>
</body>
</html>
