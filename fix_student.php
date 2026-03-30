<?php
require_once 'config.php';

$hash = password_hash('Studentone@123', PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "UPDATE users SET password_hash=?, is_verified=1, is_active=1 WHERE email='studentone@gmail.com'"
);
$stmt->bind_param("s", $hash);
$stmt->execute();

echo "Done! Rows affected: " . $stmt->affected_rows . "<br>";
echo "Hash used: " . $hash;

$stmt->close();
$conn->close();
