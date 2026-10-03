<?php
session_start();
include 'db.php';

$msg = "";

/* ---------------- LOGOUT ---------------- */
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: auth.php");
    exit;
}

/* ---------------- LOGIN ---------------- */
if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT user_id, password FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($uid, $dbpass);

    if ($stmt->fetch() && $password === $dbpass) {
        $_SESSION['user_id'] = $uid;
        $_SESSION['username'] = $username;
        header("Location: auth.php");
        exit;
    } else {
        $msg = "❌ Invalid username or password";
    }
    $stmt->close();
}

/* ---------------- CHANGE PASSWORD ---------------- */
if (isset($_POST['change_password']) && isset($_SESSION['user_id'])) {
    $old = $_POST['old_password'];
    $new = $_POST['new_password'];

    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($dbpass);
    $stmt->fetch();
    $stmt->close();

    if ($old !== $dbpass) {
        $msg = "❌ Old password is wrong";
    } else {
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $new, $_SESSION['user_id']);
        $stmt->execute();
        $stmt->close();
        $msg = "✅ Password changed successfully";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login / Change Password</title>
    <style>
        body { font-family: Arial; background:#f4f6f8; }
        .box { width:360px; margin:80px auto; background:#fff; padding:25px; border-radius:8px; box-shadow:0 0 10px #ccc; }
        input { width:100%; padding:10px; margin:8px 0; }
        button { padding:10px; width:100%; background:#2563eb; color:#fff; border:none; border-radius:5px; cursor:pointer; }
        .msg { margin:10px 0; color:#d97706; font-weight:bold; text-align:center; }
        a { display:block; text-align:center; margin-top:10px; }
    </style>
</head>
<body>

<div class="box">
<?php if (!isset($_SESSION['user_id'])): ?>

    <h2>🔐 Login</h2>
    <form method="post">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <button name="login">Login</button>
    </form>

<?php else: ?>

    <h2>👤 Welcome, <?= htmlspecialchars($_SESSION['username']) ?></h2>

    <h3>🔁 Change Password</h3>
    <form method="post">
        <input type="password" name="old_password" placeholder="Old Password" required>
        <input type="password" name="new_password" placeholder="New Password" required>
        <button name="change_password">Update Password</button>
    </form>

    <a href="?logout=1">🚪 Logout</a>

<?php endif; ?>

    <div class="msg"><?= $msg ?></div>
</div>

</body>
</html>
