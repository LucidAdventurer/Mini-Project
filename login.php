<?php
/* ========================================
   PTA LOGIN HANDLER
   File: login.php

   ① FORM POST  (application/x-www-form-urlencoded)
      Used by: login.html — student / teacher
      On error  → redirect login.html?error=<code>
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

   MIGRATION NOTE (mysqli -> PDO/Postgres):
   - All bind_param()/get_result()/close() calls replaced with
     $stmt->execute([...]) / $stmt->fetch() (PDO style).
   - mysqli type-hints replaced with PDO.
   - MySQL's DATE_SUB(NOW(), INTERVAL ? MINUTE) replaced with
     Postgres interval arithmetic: NOW() - (?::int * INTERVAL '1 minute').
   - Postgres returns boolean columns as 't'/'f' strings via PDO_PGSQL
     (not native PHP bool), so a pgBool() helper normalizes them before
     any truthiness check — without this, !$user['is_active'] would be
     wrong (both 't' and 'f' are non-empty, truthy PHP strings).
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
        header('Location: login.html?error=invalid_request');
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
        header('Location: login.html?error=' . urlencode($code));
    }
    exit;
}

/**
 * Normalizes a Postgres boolean value fetched via PDO into a real PHP bool.
 * PDO_PGSQL returns boolean columns as the strings 't'/'f' (not native
 * PHP true/false), so a plain (bool) cast or truthiness check is unsafe.
 */
function pgBool($val): bool {
    if (is_bool($val)) return $val;
    if (is_int($val))  return $val === 1;
    return in_array($val, ['t', 'true', '1'], true);
}

function logLoginAttempt(
    PDO     $conn,
    ?int    $userId,
    string  $ip,
    string  $ua,
    bool    $success,
    ?string $reason
): void {
    if ($userId === null) return;
    if (!tableExists($conn, 'login_activity')) return;
    try {
        $stmt = $conn->prepare(
            "INSERT INTO login_activity (user_id, ip_address, user_agent, is_success, failure_reason)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $ip, $ua, $success ? 1 : 0, $reason]);
    } catch (PDOException $e) {
        error_log("logLoginAttempt failed: " . $e->getMessage());
    }
}

function updateLastLogin(PDO $conn, int $userId): void {
    try {
        $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        error_log("updateLastLogin failed: " . $e->getMessage());
    }
}

function recentFailCount(PDO $conn, int $userId, int $windowMinutes): array {
    if (!tableExists($conn, 'login_activity')) {
        return ['count' => 0, 'last_fail' => null];
    }
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt, MAX(created_at) AS last_fail
             FROM login_activity
             WHERE user_id = ? AND is_success = FALSE
               AND created_at >= NOW() - (?::int * INTERVAL '1 minute')"
        );
        $stmt->execute([$userId, $windowMinutes]);
        $row = $stmt->fetch();
        return ['count' => (int)($row['cnt'] ?? 0), 'last_fail' => $row['last_fail'] ?? null];
    } catch (PDOException $e) {
        error_log("recentFailCount failed: " . $e->getMessage());
        return ['count' => 0, 'last_fail' => null];
    }
}

function storeRememberToken(PDO $conn, int $userId): ?string {
    $selector  = bin2hex(random_bytes(12));
    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);
    try {
        $stmt = $conn->prepare(
            "INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $selector, $tokenHash, $expiresAt]);
        return $selector . ':' . $token;
    } catch (PDOException $e) {
        error_log("Failed to insert remember token: " . $e->getMessage());
        return null;
    }
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

try {
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
        $stmt->execute([$identifier, $identifier]);
    } else {
        $stmt = $conn->prepare(
            "SELECT user_id, full_name, email, password_hash,
                    role, department, registration_number,
                    is_verified, is_active, profile_image
             FROM users
             WHERE email = ? AND role = ?
             LIMIT 1"
        );
        $stmt->execute([$identifier, $role]);
    }
    $user = $stmt->fetch() ?: null;
} catch (PDOException $e) {
    error_log("Login query execute failed: " . $e->getMessage());
    failLogin($isJson, 'database_error', 'Database error.', 500);
}


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
        try {
            $probe = $conn->prepare("SELECT role FROM users WHERE email = ? LIMIT 1");
            $probe->execute([$identifier]);
            if ($probe->fetch()) $reason = 'role_mismatch';
        } catch (PDOException $e) {
            error_log("Role probe failed: " . $e->getMessage());
        }
    }
    logLoginAttempt($conn, null, $clientIp, $userAgent, false, $reason);
    failLogin($isJson, ($reason === 'role_mismatch') ? 'role_mismatch' : 'invalid_credentials',
        'Invalid credentials.');
}

if (!pgBool($user['is_active'])) {
    logLoginAttempt($conn, (int) $user['user_id'], $clientIp, $userAgent, false, 'account_inactive');
    failLogin($isJson, 'account_inactive',
        'This account has been deactivated. Contact the system administrator.', 403);
}

if (!pgBool($user['is_verified'])) {
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
    try {
        $rehash = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $rehash->execute([$newHash, (int) $user['user_id']]);
    } catch (PDOException $e) {
        error_log("Password rehash failed: " . $e->getMessage());
    }
}


// ════════════════════════════════════════
// SUCCESS — BUILD SESSION
// ════════════════════════════════════════

// Regenerate session ID exactly once, here after auth is confirmed
session_regenerate_id(true);

error_log("Login successful — user_id: {$user['user_id']}, Role: {$user['role']}");
logLoginAttempt($conn, (int) $user['user_id'], $clientIp, $userAgent, true, null);
updateLastLogin($conn, (int) $user['user_id']);

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
$_SESSION['is_verified']   = pgBool($user['is_verified']);
$_SESSION['is_active']     = pgBool($user['is_active']);

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
    'admin'   => $base . '/admin-dashboard.php',
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
