<?php
// ============================================================
// api/profile/update-profile.php
// Any logged-in user. Updates own profile fields.
//
// POST JSON { full_name, department?, registration_number? }
// Returns   { success, user{} }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId = (int)$currentUser['user_id'];
$role   = $currentUser['user_type'];

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

$fullName  = trim($body['full_name']           ?? '');
$dept      = trim($body['department']          ?? '');
$regNum    = trim($body['registration_number'] ?? '');

if ($fullName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Full name is required.']);
    exit;
}
if (mb_strlen($fullName) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Full name must not exceed 100 characters.']);
    exit;
}

// registration_number unique check (only for students, skip if unchanged)
if ($role === 'student' && $regNum !== '') {
    $check = safePreparedQuery($conn,
        "SELECT user_id FROM users WHERE registration_number = ? AND user_id != ?",
        "si", [$regNum, $userId]);
    if ($check['success'] && $check['result'] && $check['result']->num_rows > 0) {
        $check['result']->free();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Registration number already in use.']);
        exit;
    }
    if ($check['result']) $check['result']->free();
}

$r = safePreparedQuery($conn,
    "UPDATE users SET full_name = ?, department = ?, registration_number = ? WHERE user_id = ?",
    "sssi",
    [$fullName, $dept ?: null, ($role === 'student' && $regNum !== '') ? $regNum : null, $userId]
);

if (!$r['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed. Please try again.']);
    exit;
}

// Audit
safePreparedQuery($conn,
    "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent)
     VALUES (?, 'update_profile', 'user', ?, ?, ?)",
    "iiss",
    [$userId, $userId, $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]
);

// Return fresh user row
$ru = safePreparedQuery($conn,
    "SELECT user_id, full_name, email, user_type, department,
            registration_number, is_verified, profile_image
     FROM users WHERE user_id = ?",
    "i", [$userId]);
$user = null;
if ($ru['success'] && $ru['result']) {
    $user = $ru['result']->fetch_assoc();
    $ru['result']->free();
}

echo json_encode(['success' => true, 'user' => $user]);
