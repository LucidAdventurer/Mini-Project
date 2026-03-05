<?php
// ============================================================
// api/admin/add-user.php
//
// Creates a new user (student / teacher / admin).
// Admin only. Password is auto-hashed.
//
// POST JSON {
//   full_name, email, password, user_type,
//   department?, registration_number?, is_verified?
// }
// Returns { success, user_id?, error? }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON.']);
    exit;
}

// ── Required fields ──
$fullName  = trim($body['full_name']  ?? '');
$email     = trim($body['email']      ?? '');
$password  = $body['password']        ?? '';
$userType  = trim($body['user_type']  ?? '');

if ($fullName === '' || $email === '' || $password === '' || $userType === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'full_name, email, password, and user_type are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
    exit;
}

$allowedTypes = ['student', 'teacher', 'admin'];
if (!in_array($userType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user_type.']);
    exit;
}

if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters.']);
    exit;
}

// ── Optional fields ──
$department         = trim($body['department']          ?? '') ?: null;
$registrationNumber = trim($body['registration_number'] ?? '') ?: null;
$isVerified         = isset($body['is_verified']) ? (int)(bool)$body['is_verified'] : 1;

// ── Check email uniqueness ──
$dup = safePreparedQuery($conn,
    "SELECT user_id FROM users WHERE email = ? LIMIT 1",
    "s", [$email]
);
if ($dup['success'] && $dup['result'] && $dup['result']->num_rows > 0) {
    $dup['result']->free();
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'A user with this email already exists.']);
    exit;
}
if ($dup['result']) $dup['result']->free();

// ── Check registration_number uniqueness ──
if ($registrationNumber !== null) {
    $dupReg = safePreparedQuery($conn,
        "SELECT user_id FROM users WHERE registration_number = ? LIMIT 1",
        "s", [$registrationNumber]
    );
    if ($dupReg['success'] && $dupReg['result'] && $dupReg['result']->num_rows > 0) {
        $dupReg['result']->free();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This registration number is already in use.']);
        exit;
    }
    if ($dupReg['result']) $dupReg['result']->free();
}

// ── Hash password & insert ──
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$result = safePreparedQuery($conn,
    "INSERT INTO users
        (full_name, email, password_hash, user_type, department,
         registration_number, is_verified, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
    "ssssssi",
    [$fullName, $email, $passwordHash, $userType, $department,
     $registrationNumber, $isVerified]
);

if ($result['success'] && $result['insert_id'] > 0) {
    // Audit log
    safePreparedQuery($conn,
        "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address)
         VALUES (?, 'create_user', 'user', ?, ?, ?)",
        "iiss",
        [
            (int)$_SESSION['uid'],
            $result['insert_id'],
            json_encode(['email' => $email, 'user_type' => $userType]),
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]
    );

    echo json_encode(['success' => true, 'user_id' => $result['insert_id']]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to create user. Please try again.']);
}
