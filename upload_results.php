<?php
session_start();
header('Content-Type:application/json');

$uploadDir = "uploaded_results/";
if (!is_dir($uploadDir)) mkdir($uploadDir,0777,true);

$file = $_FILES['file'] ?? null;
if(!$file){
    echo json_encode(["error"=>"No file received"]); exit;
}

$name = basename($file['name']);
$path = $uploadDir.$name;

if(move_uploaded_file($file['tmp_name'],$path)){
    $link = $_SERVER['SERVER_NAME']."/".$path;

    include 'config.php';
    $c = new mysqli($servername,$username,$password,$dbname);
    $uid = $_SESSION['user_id'];
    $stmt = $c->prepare("UPDATE test_attempts SET result_link=? WHERE user_id=? ORDER BY attempt_id DESC LIMIT 1");
    $stmt->bind_param("si",$link,$uid);
    $stmt->execute();
    $c->close();

    echo json_encode(["file_link"=>$link]);
}
?>
