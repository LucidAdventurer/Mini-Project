<?php
require "config.php";
session_start();

$email    = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';
$role     = $_POST['role'] ?? '';

if (!$email || !$password || !$role) {
    die("INVALID REQUEST");
}

$stmt = $conn->prepare(
    "SELECT uid, password, user_type 
     FROM users 
     WHERE email = ? AND user_type = ?"
);

$stmt->bind_param("ss", $email, $role);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("USER NOT FOUND OR ROLE MISMATCH");
}

$user = $result->fetch_assoc();

if (!password_verify($password, $user['password'])) {
    die("WRONG PASSWORD");
}

/* ✅ LOGIN SUCCESS */
$_SESSION['uid']  = $user['uid'];
$_SESSION['role'] = $user['user_type'];

/* 🔁 Redirect */
if ($user['user_type'] === 'student') {
    header("Location: student-dashboard.php");
    exit;
}

if ($user['user_type'] === 'teacher') {
    header("Location: teacher-dashboard.php");
    exit;
}

header("Location: index.html");
exit;
