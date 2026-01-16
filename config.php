<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '102005');
define('DB_NAME', 'pta');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}