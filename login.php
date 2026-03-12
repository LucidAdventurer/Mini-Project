<?php
/* ========================================
   PTA LOGIN HANDLER
   File: login.php

   ① FORM POST  (application/x-www-form-urlencoded)
      Used by: index.html — student / teacher
      On error  → redirect index.html?error=<code>
      On success → redirect <role>-dashboard.php

   ② JSON POST  (application/json)
      Used by: admin-login.html — admin only
      On error  → { success:false, error, remaining_attempts?, lockout_until? }
      On success → { success:true, csrf_token, redirect, user }

   SECURITY:
   1. Plain-text password fallback removed entirely.
   2. Remember-me token stored (hashed) in DB.
   3. CSRF token seeded into session on successful login.
   4. session_regenerate_id() called exactly once, after auth confirmed.
   ======================================== */

// Output buffer: catches any stray echo/warning from included files
// so they never corrupt the JSON response or trigger "headers already sent".
ob_start();

// config.php handles session_start(). Do NOT call session_start() here.
require_once "config.php";

// Discard anything config.php may have printed.
ob_clean();


// ════════════════════════════════════════
// DETECT REQUEST MODE
// ════════════════════════════════════════

$contentType = trim($_SERVER['CONTENT_TYPE'] ?? '');
$isJson      = (stripos($contentType, 'application/json') !== false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    if ($isJson) {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    } else {
        header('Location: index.html?error=invalid_request');
    }
    exit;
}


// ════════════════════════════════════════
// PARSE INPUT
// ════════════════════════════════════════

if ($isJson) {
    $rawBody = file_get_contents('php://input');
    $body    = json_decode($rawBody, true);
    if (!is_array($body)) {
        ob_end_clean();
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
        exit;
    }
    $identifier = trim($body['username'] ?? '');
    $password   = $body['password'] ?? '';
    $role       = 'admin';
    $remember   = !empty($body['remember']);
} else {
    $identifier = trim($_POST['email']    ?? '');
    $password   = $_POST['password']      ?? '';
    $role       = trim($_POST['role']     ?? '');
    $remember   = isset($_POST['remember']);
}


// ════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════

function failLogin(
    bool   $isJson,
    string $code,
    string $message = '',
    int    $status  = 401,
    array  $extra   = []
): never {
    error_log("Login failed: $code");
    ob_end_clean();
    if ($isJson) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    } else {
        header('Location: index.html?error=' . urlencode($code));
    }
    exit;
}

function logLoginAttempt(
    mysqli  $conn,
    ?int    $userId,
    string  $ip,
    string  $ua,
    bool    $success,
    ?string $reason
): void {
    if ($userId === null) return;
    if (!tableExists($conn, 'login_activity')) return;
    $stmt = $conn->prepare(
        "INSERT INTO login_activity (user_id, ip_address, user_agent, is_success, failure_reason)
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) return;
    $ok = (int) $success;
    $stmt->bind_param("issis", $userId, $ip, $ua, $ok, $reason);
    $stmt->execute();
    $stmt->close();
}

function updateLastLogin(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
    if (!$stmt) return;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
}

function recentFailCount(mysqli $conn, int $userId, int $windowMinutes): array {
    if (!tableExists($conn, 'login_activity')) {
        return ['count' => 0, 'last_fail' => null];
    }
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt, MAX(created_at) AS last_fail
         FROM login_activity
         WHERE user_id = ? AND is_success = 0
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    if (!$stmt) return ['count' => 0, 'last_fail' => null];
    $stmt->bind_param("ii", $userId, $windowMinutes);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['count' => (int)($row['cnt'] ?? 0), 'last_fail' => $row['last_fail'] ?? null];
}

function storeRememberToken(mysqli $conn, int $userId): ?string {
    $selector  = bin2hex(random_bytes(12));
    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);
    $stmt = $conn->prepare(
        "INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)"
    );
    if (!$stmt) {
        error_log("Failed to prepare remember_tokens insert: " . $conn->error);
        return null;
    }
    $stmt->bind_param("isss", $userId, $selector, $tokenHash, $expiresAt);
    if (!$stmt->execute()) {
        error_log("Failed to insert remember token: " . $stmt->error);
        $stmt->close();
        return null;
    }
    $stmt->close();
    return $selector . ':' . $token;
}


// ════════════════════════════════════════
// INPUT VALIDATION
// ════════════════════════════════════════

if (empty($identifier) || empty($password) || empty($role)) {
    failLogin($isJson, 'missing_fields', 'Username and password are required.', 400);
}
if (!in_array($role, ['student', 'teacher', 'admin'], true)) {
    failLogin($isJson, 'invalid_role', 'Invalid role.', 400);
}
// Admin must use the JSON path (admin-login.html), not the HTML form
if (!$isJson && $role === 'admin') {
    failLogin($isJson, 'invalid_role', 'Invalid role.', 400);
}
if (!$isJson && !filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
    failLogin($isJson, 'invalid_email', 'Invalid email address.', 400);
}

$clientIp  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Read lockout settings safely — $settings may be null if system-settings failed to load
$maxAttempts    = ($settings !== null) ? (int) $settings->get('max_login_attempts',       5)  : 5;
$lockoutMinutes = ($settings !== null) ? (int) $settings->get('lockout_duration_minutes', 30) : 30;


// ════════════════════════════════════════
// LOOK UP USER
// ════════════════════════════════════════

if ($isJson) {
    // Admin login: accept email OR registration_number
    $stmt = $conn->prepare(
        "SELECT user_id, full_name, email, password_hash,
                role, department, registration_number,
                is_verified, is_active, profile_image
         FROM users
         WHERE (email = ? OR registration_number = ?) AND role = 'admin'
         LIMIT 1"
    );
    if (!$stmt) failLogin($isJson, 'database_error', 'Database error.', 500);
    $stmt->bind_param("ss", $identifier, $identifier);
} else {
    $stmt = $conn->prepare(
        "SELECT user_id, full_name, email, password_hash,
                role, department, registration_number,
                is_verified, is_active, profile_image
         FROM users
         WHERE email = ? AND role = ?
         LIMIT 1"
    );
    if (!$stmt) failLogin($isJson, 'database_error', 'Database error.', 500);
    $stmt->bind_param("ss", $identifier, $role);
}

if (!$stmt->execute()) {
    error_log("Login query execute failed: " . $stmt->error);
    $stmt->close();
    failLogin($isJson, 'database_error', 'Database error.', 500);
}

$user = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();


// ════════════════════════════════════════
// LOCKOUT CHECK
// ════════════════════════════════════════

if ($user) {
    $fail = recentFailCount($conn, (int) $user['user_id'], $lockoutMinutes);
    if ($fail['count'] >= $maxAttempts) {
        logLoginAttempt($conn, (int) $user['user_id'], $clientIp, $userAgent, false, 'account_locked');
        if ($isJson) {
            $until = $fail['last_fail']
                ? date('Y-m-d H:i:s', strtotime($fail['last_fail']) + ($lockoutMinutes * 60))
                : null;
            failLogin($isJson, 'account_locked',
                'Account temporarily locked due to too many failed attempts.',
                423, $until ? ['lockout_until' => $until] : []);
        }
        failLogin($isJson, 'account_locked', '');
    }
}


// ════════════════════════════════════════
// ACCOUNT STATUS
// ════════════════════════════════════════

if (!$user) {
    // Run a probe to distinguish "wrong role" from "user not found" for the form path
    $reason = 'user_not_found';
    if (!$isJson) {
        $probe = $conn->prepare("SELECT role FROM users WHERE email = ? LIMIT 1");
        if ($probe) {
            $probe->bind_param("s", $identifier);
            $probe->execute();
            if ($probe->get_result()->fetch_assoc()) $reason = 'role_mismatch';
            $probe->close();
        }
    }
    logLoginAttempt($conn, null, $clientIp, $userAgent, false, $reason);
    failLogin($isJson, ($reason === 'role_mismatch') ? 'role_mismatch' : 'invalid_credentials',
        'Invalid credentials.');
}

if (!$user['is_active']) {
    logLoginAttempt($conn, (int) $user['user_id'], $clientIp, $userAgent, false, 'account_inactive');
    failLogin($isJson, 'account_inactive',
        'This account has been deactivated. Contact the system administrator.', 403);
}

if (!$user['is_verified']) {
    logLoginAttempt($conn, (int) $user['user_id'], $clientIp, $userAgent, false, 'email_not_verified');
    failLogin($isJson, 'email_not_verified',
        $isJson ? 'Account email is not verified. Contact the system administrator.' : '', 403);
}


// ════════════════════════════════════════
// PASSWORD VERIFICATION
// ════════════════════════════════════════

// Reject any account whose password isn't a bcrypt hash — force a reset
$isHashed = str_starts_with($user['password_hash'], '$2y$')
         || str_starts_with($user['password_hash'], '$2a$')
         || str_starts_with($user['password_hash'], '$2b$');

if (!$isHashed) {
    error_log("SECURITY: Unhashed password for user_id {$user['user_id']}. Login denied.");
    logLoginAttempt($conn, (int) $user['user_id'], $clientIp, $userAgent, false, 'password_not_hashed');
    failLogin($isJson, 'password_reset_required',
        'Your password needs to be reset. Contact the administrator.', 403);
}

if (!password_verify($password, $user['password_hash'])) {
    logLoginAttempt($conn, (int) $user['user_id'], $clientIp, $userAgent, false, 'wrong_password');
    if ($isJson) {
        $fail      = recentFailCount($conn, (int) $user['user_id'], $lockoutMinutes);
        $remaining = max(0, $maxAttempts - $fail['count']);
        failLogin($isJson, 'invalid_credentials', 'Invalid credentials.',
            401, ['remaining_attempts' => $remaining]);
    }
    failLogin($isJson, 'invalid_credentials', 'Invalid credentials.');
}


// ════════════════════════════════════════
// REHASH IF OUTDATED
// ════════════════════════════════════════

if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $rehash  = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    if ($rehash) {
        $userId = (int) $user['user_id'];
        $rehash->bind_param("si", $newHash, $userId);
        $rehash->execute();
        $rehash->close();
    }
}


// ════════════════════════════════════════
// SUCCESS — BUILD SESSION
// ════════════════════════════════════════

// Regenerate session ID exactly once, here after auth is confirmed
session_regenerate_id(true);

error_log("Login successful — user_id: {$user['user_id']}, Role: {$user['role']}");
logLoginAttempt($conn, (int) $user['user_id'], $clientIp, $userAgent, true, null);
updateLastLogin($conn, $user['user_id']);

$_SESSION = [];

$_SESSION['authenticated'] = true;
$_SESSION['uid']           = (int) $user['user_id'];
$_SESSION['user_id']       = (int) $user['user_id'];
$_SESSION['name']          = $user['full_name'];
$_SESSION['full_name']     = $user['full_name'];
$_SESSION['email']         = $user['email'];
$_SESSION['role']          = $user['role'];
$_SESSION['user_type']     = $user['role'];   // legacy alias for any code still reading user_type
$_SESSION['department']    = $user['department']         ?? '';
$_SESSION['profile_image'] = $user['profile_image']      ?? '';
$_SESSION['login_time']    = time();
$_SESSION['ip_address']    = $clientIp;
$_SESSION['is_verified']   = (bool) $user['is_verified'];
$_SESSION['is_active']     = (bool) $user['is_active'];

// session_regenerate_id() cleared the old CSRF token — seed a fresh one
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));


// ════════════════════════════════════════
// REMEMBER-ME
// ════════════════════════════════════════

if ($remember) {
    $cookieValue = storeRememberToken($conn, (int) $user['user_id']);
    if ($cookieValue !== null) {
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        setcookie('remember_me', $cookieValue, [
            'expires'  => time() + 30 * 24 * 3600,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        error_log("Remember-me cookie set for user_id: {$user['user_id']}");
    }
}


// ════════════════════════════════════════
// RESPOND
// ════════════════════════════════════════

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

$redirectMap = [
    'student' => $base . '/student-dashboard.php',
    'teacher' => $base . '/teacher-dashboard.php',
    'admin'   => $base . '/admin-dashboard.html',
];
$redirectUrl = $redirectMap[$user['role']] ?? null;

if ($redirectUrl === null) {
    logLoginAttempt($conn, (int) $user['user_id'], $clientIp, $userAgent, false, 'invalid_role');
    failLogin($isJson, 'invalid_role', 'Invalid role.', 403);
}

ob_end_clean();

if ($isJson) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'    => true,
        'csrf_token' => $_SESSION['csrf_token'],
        'redirect'   => $redirectUrl,
        'user'       => [
            'user_id'   => (int) $user['user_id'],
            'full_name' => $user['full_name'],
            'email'     => $user['email'],
        ],
    ]);
} else {
    header('Location: ' . $redirectUrl);
}
exit;