<?php
session_start();
include 'config.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("DB Failed");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name  = $_POST['full_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $pass  = $_POST['password'];

    $hash = password_hash($pass, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("INSERT INTO users(full_name,email,phone,password_hash) VALUES(?,?,?,?)");
    $stmt->bind_param("ssss", $name, $email, $phone, $hash);

    if ($stmt->execute()) {
        header("Location: login.php");
        exit();
    } else {
        $error = "Registration failed!";
    }
    $stmt->close();
}
$conn->close();
?>

<!DOCTYPE html><html>
<head><script src="<?= CDN_TAILWIND ?>"></script></head>
<body class="bg-gray-100 flex justify-center items-center h-screen">
<form method="POST" class="bg-white p-6 rounded shadow w-80">
    <h2 class="text-xl font-bold mb-4">Register</h2>
    <?php if(isset($error)) echo "<p class='text-red-500'>$error</p>"; ?>
    <input type="text" name="full_name" placeholder="Full Name" class="w-full border p-2 mb-3 rounded" required>
    <input type="text" name="email" placeholder="Email" class="w-full border p-2 mb-3 rounded" required>
    <input type="text" name="phone" placeholder="Phone" class="w-full border p-2 mb-3 rounded" required>
    <input type="password" name="password" placeholder="Password" class="w-full border p-2 mb-3 rounded" required>
    <button class="bg-green-600 text-white w-full py-2 rounded">Register</button>
</form>
</body>
</html>
