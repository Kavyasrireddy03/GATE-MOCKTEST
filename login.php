<?php
session_start();
include 'config.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("DB Failed");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $pass  = $_POST['password'];

    // Updated: Using 'name' and 'password' to match your DESC users; schema
    $stmt = $conn->prepare("SELECT user_id, name, password FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->bind_result($id, $name, $db_pass);

    if ($stmt->fetch()) {
        // Checking password. Use password_verify($pass, $db_pass) if you use hashes.
        if ($pass === $db_pass || password_verify($pass, $db_pass)) {
            $_SESSION['user_id'] = $id;
            $_SESSION['name'] = $name;
            
            // Redirecting to your test file
            header("Location: user_dashboard.php");
            exit();
        } else {
            $error = "Invalid password!";
        }
    } else {
        $error = "User not found!";
    }
    $stmt->close();
}
$conn->close();
?>

<!DOCTYPE html>
<html>
<head><script src="<?= CDN_TAILWIND ?>"></script></head>
<body class="bg-gray-100 flex justify-center items-center h-screen">
<form method="POST" class="bg-white p-6 rounded shadow w-80">
    <h2 class="text-xl font-bold mb-4 text-blue-700 border-b pb-2">Student Login</h2>
    <?php if(isset($error)) echo "<p class='text-red-500 text-sm mb-3'>$error</p>"; ?>
    
    <label class="block text-sm font-medium text-gray-700">Email Address</label>
    <input type="email" name="email" class="w-full border p-2 mb-3 rounded" required>
    
    <label class="block text-sm font-medium text-gray-700">Password</label>
    <input type="password" name="password" class="w-full border p-2 mb-4 rounded" required>
    
    <button class="bg-blue-600 hover:bg-blue-700 text-white w-full py-2 rounded font-bold transition">
        Enter Dashboard
    </button>
</form>
</body>
</html>