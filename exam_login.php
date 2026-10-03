<?php
session_start();
include 'config.php';

$error = "";

// Process the login when the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) die("DB Failed");

    $raw_input = trim($_POST['email']);
    
    // Auto-append @gmail.com if they didn't type an '@'
    if (strpos($raw_input, '@') === false) {
        $email = $raw_input . "@gmail.com";
    } else {
        $email = $raw_input;
    }

    // UPDATED: Added IMAGE_URL to the SELECT query
    $stmt = $conn->prepare("SELECT user_id, name, IMAGE_URL FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->bind_result($id, $name, $image_url);

    if ($stmt->fetch()) {
        // SUCCESS! Email found. Bypass password check and save data to session. 
        $_SESSION['user_id'] = $id;
        $_SESSION['name'] = $name;
        
        // Process Google Drive image URL to thumbnail viewable link (Handles .avif and other formats)
        $display_image = 'user.png'; // Default fallback
        if (!empty($image_url)) {
            // Extract ID and use the thumbnail endpoint (sz=w500 sets the width to 500px)
            if (preg_match('/d\/([a-zA-Z0-9_-]+)/', $image_url, $matches)) {
                $display_image = 'https://drive.google.com/thumbnail?id=' . $matches[1] . '&sz=w500';
            } else {
                $display_image = $image_url;
            }
        }
        $_SESSION['display_image'] = $display_image;

    } else {
        $error = "User not found! (Searched for: $email)";
    }
    
    $stmt->close();
    $conn->close();
}

// Dynamically fetch the name to display in the header and success box
$candidateName = isset($_SESSION['name']) ? $_SESSION['name'] : "Guest Candidate";
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Login - <?= htmlspecialchars(EXAM_NAME) ?></title>
    
    <style>
        /* --- 1. CORE LAYOUT --- */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; background: #fff; overflow: hidden; }

        /* THIN BANNER FIX */
        #header-banner { height: 49 px; width: 100%; background: #ffffff; border-bottom: 1px solid #7691a2; overflow: hidden; }
        #header-banner .logo-wrapper { width: 100%; height: 100%; display: flex; justify-content: center; align-items: center; }
        #header-banner img { height: 100%; width: 100%; object-fit: contain; display: block; }

        #user-info { background: #444444; height: 149px; display: flex; justify-content: space-between; align-items: center; padding: 15px 25px; border-bottom: 1px solid #000; width: 100%; color: #fff; }
        #user-info .sys-info { flex: 1; display: flex; flex-direction: column; justify-content: flex-start; }
        #user-info .sys-info .label { font-size: 16px; margin-bottom: 5px; }
        #user-info .sys-info .sys-id { color: #ffff00; font-size: 48px; font-weight: bold; line-height: 1; margin-bottom: 10px; }
        #user-info .sys-info .desc { font-size: 11.5px; }

        #user-info .cand-info { display: flex; align-items: flex-start; text-align: right; }
        #user-info .cand-text { margin-right: 20px; margin-top: 5px; }
        #user-info .cand-text div { font-size: 16px; margin-bottom: 5px; }
        #user-info .cand-text span { color: #ffff00; font-weight: bold; margin-left: 5px; }
        
        /* Candidate Photo Box styling */
        #user-info .cand-photo { width: 105px; height: 125px; background: #fff; border: 1px solid #000; overflow: hidden; }
        #user-info .cand-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }

        /* --- 2. LOGIN BOX --- */
        #login-area { width: 100%; display: flex; justify-content: center; margin-top: 50px; }
        .login-box { width: 360px; }
        .login-box .header { background: #DDDDDD; border: 1px solid #dbdbdb; border-radius: 5px 5px 0 0; color: #444; font-size: 14px; font-weight: bold; padding: 10px 20px; }
        .login-box .content { background: #f5f5f5; border: 1px solid #dbdbdb; border-top: none; border-radius: 0 0 5px 5px; padding: 25px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }

        .input-row { display: flex; margin-bottom: 15px; height: 35px; align-items: stretch; background: #fff; border-radius: 3px; }
        .icon-left { width: 49px; background-color: #eee; background-position: center; background-repeat: no-repeat; border: 1px solid #cacaca; border-right: none; border-radius: 3px 0 0 3px; }
        .icon-user { background-image: url('user.png'); } 
        .icon-lock { background-image: url('Lock-26.png'); }
        .input-field { flex: 1; border: 1px solid #cacaca; padding: 0 16px; font-size: 14px; color: #555; outline: none; border-radius: 0; min-width: 0; }
        
        img.keyboardInputInitiator { width: 49px !important; height: 35px !important; border: 1px solid #cacaca !important; border-left: none !important; border-radius: 0 3px 3px 0 !important; background-color: #eee !important; cursor: pointer; padding: 8px 12px !important; box-sizing: border-box !important; display: block !important; object-fit: contain !important; }

        .sign-in-btn { background: #38aae9; border: none; width: 100%; height: 40px; border-radius: 3px; color: #fff; cursor: pointer; margin-top: 25px; font-size: 16px; font-weight: bold; transition: 0.2s; }
        .sign-in-btn:hover { background: #0C7CD5; }
        
        .error-msg { color: #dc2626; font-size: 13px; font-weight: bold; text-align: center; margin-bottom: 15px; }

        #footer { position: fixed; bottom: 0; width: 100%; height: 22px; background: #617B8C; color: #fff; line-height: 22px; text-align: center; font-size: 11px; }

        /* --- 3. VIRTUAL KEYBOARD SIZE AND STYLE FIXES --- */
        #keyboardInputMaster { position: absolute !important; z-index: 999999; background-color: #dddddd; border-top: 1px solid #eeeeee; border-right: 1px solid #888888; border-bottom: 1px solid #444444; border-left: 1px solid #cccccc; border-radius: 0.6em; box-shadow: 0px 2px 10px #444444; padding: 0; }
        #keyboardInputMaster, #keyboardInputMaster * { font-family: Arial, sans-serif; }
        #keyboardInputMaster.keyboardInputSize1, #keyboardInputMaster.keyboardInputSize1 * { font-size: 9px; }
        #keyboardInputMaster.keyboardInputSize2, #keyboardInputMaster.keyboardInputSize2 * { font-size: 11px; }
        #keyboardInputMaster.keyboardInputSize3, #keyboardInputMaster.keyboardInputSize3 * { font-size: 13px; }
        #keyboardInputMaster.keyboardInputSize4, #keyboardInputMaster.keyboardInputSize4 * { font-size: 16px; }
        #keyboardInputMaster.keyboardInputSize5, #keyboardInputMaster.keyboardInputSize5 * { font-size: 20px; }

        #keyboardInputMaster table { border-spacing: 0px; border-collapse: separate; width: auto; margin: 0; }
        
        #keyboardInputMaster thead tr th { display: flex; justify-content: space-between; align-items: center; padding: 0.3em 0.3em 0.1em 0.3em; background-color: #999999; border-radius: 0.6em 0.6em 0 0; }
        #keyboardInputMaster thead tr th div { font-size: 1.3em; font-weight: bold; color: #000; cursor: default; margin: 0; }
        #keyboardInputMaster thead tr th div ol { display: none !important; } 
        
        #keyboardInputMaster thead tr th span, #keyboardInputMaster thead tr th strong, #keyboardInputMaster thead tr th small, #keyboardInputMaster thead tr th big { 
            display: inline-block; padding: 0px 0.4em; height: 1.4em; line-height: 1.4em;
            border-top: 1px solid #e5e5e5; border-right: 1px solid #5d5d5d; border-bottom: 1px solid #5d5d5d; border-left: 1px solid #e5e5e5;
            background-color: #cccccc; border-radius: 0.3em; cursor: pointer; color: #000; margin-left: 0.3em; font-weight: normal; 
        }
        #keyboardInputMaster thead tr th strong { font-weight: bold; }
        
        #keyboardInputMaster tbody tr td table tbody tr td { 
            background-color: #eeeeee; border-top: 1px solid #e5e5e5; border-right: 1px solid #5d5d5d; border-bottom: 1px solid #5d5d5d; border-left: 1px solid #e5e5e5;
            padding: 0px 0.45em; height: 1.8em; text-align: center; border-radius: 0.2em; cursor: pointer; font-family: 'Lucida Console', 'Arial Unicode MS', monospace; color: #000;
        }
        #keyboardInputMaster tbody tr td table tbody tr td:hover { background-color: #cccccc; border-top: 1px solid #d5d5d5; border-right: 1px solid #555555; border-bottom: 1px solid #555555; border-left: 1px solid #d5d5d5; }
        
        #keyboardInputMaster tbody tr td table tbody tr td.space { padding: 0px 4em !important; }
        #keyboardInputMaster tbody tr td table tbody tr td.last { width: 99% !important; }
        #keyboardInputMaster tbody tr td > div > label { display: none !important; } 
    </style>
    
    <script src="jquery-3.6.0.min.js"></script>
    <script src="keyboard.js"></script>
    
    <script>
        if (typeof window.VKI_imageURI !== 'undefined') {
            window.VKI_imageURI = "keyboard.png";
        }
    </script>
</head>

<body onselectstart="return false;" ondragstart="return false;">

    <div id="header-banner">
        <div class="logo-wrapper">
            <img src="<?= EXAM_BANNER_URL ?>" alt="<?= htmlspecialchars(EXAM_NAME) ?>">
        </div>
    </div>

    <div id="user-info">
        <div class="sys-info">
            <div class="label">System Name :</div>
            <div class="sys-id">C001</div>
            <div class="desc">Kindly contact the invigilator if there are any discrepancies in the Name and Photograph displayed on the screen or if the photograph is not yours</div>
        </div>
        <div class="cand-info">
            <div class="cand-text">
                <div>Candidate Name :<span><?php echo htmlspecialchars($candidateName); ?></span></div>
                <div>Subject :<span>Mock Exam</span></div>
            </div>
            
            <div class="cand-photo">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <img src="<?php echo htmlspecialchars($_SESSION['display_image'] ?? 'user.png'); ?>" onerror="this.onerror=null; this.src='user.png';" alt="Candidate Photo">
                <?php else: ?>
                    <img src="user.png" alt="Candidate Photo">
                <?php endif; ?>
            </div>
            
        </div>
    </div>

    <div id="login-area">
        <div class="login-box">
            
            <?php if(!isset($_SESSION['user_id'])): ?>
                <div class="header">Login</div>
                <form class="content" method="POST" action="">
                    
                    <?php if(!empty($error)) echo "<div class='error-msg'>$error</div>"; ?>

                    <div class="input-row">
                        <div class="icon-left icon-user"></div>
                        <input type="text" id="uid" name="email" class="keyboardInput input-field" placeholder="Enrollment ID" readonly>
                    </div>

                    <div class="input-row">
                        <div class="icon-left icon-lock"></div>
                        <input type="password" id="pwd" name="password" class="keyboardInput input-field" placeholder="Enter Password" readonly>
                    </div>

                    <button type="submit" class="sign-in-btn">Sign In</button>
                </form>

<?php else: ?>
<script>
setTimeout(function(){
    window.location.href = "exam.php";
}, 0000);
</script>
<?php endif; ?>

        </div>
    </div>

    <div id="footer">Version 17.05.21</div>

    <script>
        document.onkeydown = function (e) { return false; };
    </script>

</body>
</html>