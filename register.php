<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/config.php";

/* 
   Only run registration logic
   if form is submitted
*/
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    die("Invalid request");
}

// Safe extraction
$name      = $_POST['name']      ?? '';
$email     = $_POST['email']     ?? '';
$password  = $_POST['password']  ?? '';
$user_type = $_POST['user_type'] ?? '';

// Basic validation
if ($name === '' || $email === '' || $password === '' || $user_type === '') {
    die("All fields are required");
}

$hashed = password_hash($password, PASSWORD_DEFAULT);


$stmt = $conn->prepare(
  "INSERT INTO users (name, email, password, user_type)
   VALUES (?, ?, ?, ?)"
);
$stmt->bind_param("ssss", $name, $email, $hashed, $user_type);

if ($stmt->execute()) {
    header("Location: index.html");
    exit();
} else {
    echo "REGISTER FAILED";
}