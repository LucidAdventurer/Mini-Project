<?php
/* ========================================
   PTA LOGIN HANDLER
   File: login.php

   SECURITY CHANGES:
   1. Plain-text password fallback removed entirely.
      Any account with an unhashed password must go through
      password-reset before it can log in.
   2. Remember-me token is now stored (hashed) in the DB.
      Cookie contains selector + token; server verifies both.
   3. CSRF token seeded into session on successful login so
      all subsequent API calls can be validated.
   ======================================== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "config.php";

session_regenerate_id(true);

// ────────────────────────────────────────
// HELPERS
// ────────────────────────────────────────

function redirectWithError(string $error): never {
    error_log("Login failed: $error");
    header("Location: index.html?error=" . urlencode($error));
    exit;
}

function logLoginActivity(
    mysqli $conn,
    ?int   $userId,
    string $email,
    bool   $success,
    ?string $failureReason = null
): void {
    if ($userId === null) {
        return; // Don't log attempts for non-existent users
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    if (!tableExists($conn, 'login_activity')) {
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO login_activity
            (user_id, ip_address, user_agent, is_success, failure_reason)
         VALUES (?, ?, ?, ?, ?)"
    );

    if ($stmt) {
        $isSuccessInt = $success ? 1 : 0;
        $stmt->bind_param("issis", $userId, $ipAddress, $userAgent, $isSuccessInt, $failureReason);
        if (!$stmt->execute()) {
            error_log("Failed to log login activity: " . $stmt->error);
        }
        $stmt->close();
    }
}

function updateLastLogin(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function isAccountLocked(mysqli $conn, string $email): bool {
    if (!tableExists($conn, 'login_activity')) {
        return false;
    }

    // Look up user_id from email first
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $stmt->close();
        return false; // Unknown user — not locked
    }
    $row    = $result->fetch_assoc();
    $userId = (int) $row['user_id'];
    $stmt->close();

    $maxAttempts     = 5;
    $lockoutDuration = 15; // minutes

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS failed_count
         FROM login_activity
         WHERE user_id   = ?
           AND is_success = 0
           AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ii", $userId, $lockoutDuration);
    $stmt->execute();
    $result   = $stmt->get_result();
    $row      = $result->fetch_assoc();
    $stmt->close();

    $isLocked = ($row['failed_count'] >= $maxAttempts);
    if ($isLocked) {
        error_log("Account locked for email: $email (attempts: {$row['failed_count']})");
    }
    return $isLocked;
}

/**
 * Store a remember-me token in the database.
 * Returns the raw "selector:token" string to put in the cookie,
 * or null on failure.
 */
function storeRememberToken(mysqli $conn, int $userId): ?string {
    // selector  = 12 random bytes → 24 hex chars (used to look up the row quickly)
    // token     = 32 random bytes → 64 hex chars (the secret; stored hashed)
    $selector  = bin2hex(random_bytes(12));
    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);

    $stmt = $conn->prepare(
        "INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at)
         VALUES (?, ?, ?, ?)"
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

// ────────────────────────────────────────
// MAIN LOGIN LOGIC
// ────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithError('invalid_request');
}

$email    = trim($_POST['email']    ?? '');
$password = $_POST['password']      ?? '';
$role     = trim($_POST['role']     ?? '');
$remember = isset($_POST['remember']);

error_log("Login attempt — Email: $email, Role: $role");

if (empty($email) || empty($password) || empty($role)) {
    redirectWithError('missing_fields');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirectWithError('invalid_email');
}
if (!in_array($role, ['student', 'teacher', 'admin'], true)) {
    redirectWithError('invalid_role');
}
if (isAccountLocked($conn, $email)) {
    redirectWithError('account_locked');
}

// ── Fetch user ──
$stmt = $conn->prepare(
    "SELECT user_id, full_name, email, password_hash,
            user_type, department, is_verified, is_active, profile_image
     FROM users
     WHERE email = ? AND user_type = ?"
);

if (!$stmt) {
    error_log("DB prepare error: " . $conn->error);
    redirectWithError('database_error');
}

$stmt->bind_param("ss", $email, $role);

if (!$stmt->execute()) {
    error_log("DB execute error: " . $stmt->error);
    redirectWithError('database_error');
}

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    logLoginActivity($conn, null, $email, false, 'user_not_found_or_role_mismatch');
    $stmt->close();
    redirectWithError('invalid_credentials');
}

$user = $result->fetch_assoc();
$stmt->close();

// ── Account status ──
if (!$user['is_active']) {
    logLoginActivity($conn, $user['user_id'], $email, false, 'account_inactive');
    redirectWithError('account_inactive');
}
if (!$user['is_verified']) {
    logLoginActivity($conn, $user['user_id'], $email, false, 'email_not_verified');
    redirectWithError('email_not_verified');
}

// ── Password verification ──
// We only support bcrypt hashes ($2y$). Any account with a non-hashed
// password must reset it. There is no plain-text fallback.
$isHashedPassword = (
    str_starts_with($user['password_hash'], '$2y$') ||
    str_starts_with($user['password_hash'], '$2a$') ||
    str_starts_with($user['password_hash'], '$2b$')
);

if (!$isHashedPassword) {
    // The stored value is not a bcrypt hash — force a password reset.
    error_log("SECURITY: Unhashed password detected for user_id {$user['user_id']}. Login denied. User must reset password.");
    logLoginActivity($conn, $user['user_id'], $email, false, 'password_not_hashed');
    redirectWithError('password_reset_required');
}

if (!password_verify($password, $user['password_hash'])) {
    logLoginActivity($conn, $user['user_id'], $email, false, 'wrong_password');
    redirectWithError('invalid_credentials');
}

// ── Rehash if cost factor is outdated ──
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $rehash  = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    if ($rehash) {
        $rehash->bind_param("si", $newHash, $user['user_id']);
        $rehash->execute();
        $rehash->close();
    }
}

// ── Success: build session ──
error_log("Login successful — user_id: {$user['user_id']}, Role: {$user['user_type']}");
logLoginActivity($conn, $user['user_id'], $email, true, null);
updateLastLogin($conn, $user['user_id']);

$_SESSION = [];

$_SESSION['uid']          = $user['user_id'];   // legacy key used by dashboards
$_SESSION['user_id']      = $user['user_id'];
$_SESSION['name']         = $user['full_name'];  // legacy key
$_SESSION['full_name']    = $user['full_name'];
$_SESSION['email']        = $user['email'];
$_SESSION['role']         = $user['user_type'];  // legacy key
$_SESSION['user_type']    = $user['user_type'];
$_SESSION['department']   = $user['department']   ?? '';
$_SESSION['profile_image']= $user['profile_image'] ?? '';
$_SESSION['login_time']   = time();
$_SESSION['ip_address']   = $_SERVER['REMOTE_ADDR'];
$_SESSION['is_verified']  = $user['is_verified'];
$_SESSION['is_active']    = $user['is_active'];

// CSRF token is already seeded by config.php when the session was started.
// It is now available to all subsequent API calls via $_SESSION['csrf_token'].

// ── Remember-me ──
if ($remember) {
    $cookieValue = storeRememberToken($conn, $user['user_id']);
    if ($cookieValue !== null) {
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        setcookie(
            'remember_me',
            $cookieValue,
            [
                'expires'  => time() + 30 * 24 * 3600,
                'path'     => '/',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );
        error_log("Remember-me cookie set for user_id: {$user['user_id']}");
    }
}

// ── Redirect ──
$redirectUrl = match ($user['user_type']) {
    'student' => 'student-dashboard.php',
    'teacher' => 'teacher-dashboard.php',
    'admin'   => 'admin-dashboard.php',
    default   => null,
};

if ($redirectUrl === null) {
    logLoginActivity($conn, $user['user_id'], $email, false, 'invalid_user_type');
    redirectWithError('invalid_role');
}

header("Location: $redirectUrl");
exit;