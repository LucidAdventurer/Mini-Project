<?php
/* ========================================
   PTA LOGIN HANDLER - FIXED VERSION
   File: login.php
   
   Purpose: Authenticates users and creates sessions
   
   Database Tables Used:
   - users: Main authentication
   - login_activity: Security logging
   
   FIXES APPLIED:
   1. Session started BEFORE including config.php to avoid ini_set warnings
   2. Fixed field name mismatch (uid vs user_id)
   3. Fixed session variable names to match dashboard expectations
   4. Added proper error logging
   5. Fixed boolean handling in database operations
   ======================================== */

// Start session FIRST before including config (which also tries to start session)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration
require_once "config.php";

// Regenerate session ID to prevent session fixation attacks
session_regenerate_id(true);

/* ========================================
   HELPER FUNCTIONS
   ======================================== */

/**
 * Redirect with error message
 * @param string $error Error code for frontend display
 */
function redirectWithError($error) {
    error_log("Login failed: " . $error);
    header("Location: index.html?error=" . urlencode($error));
    exit;
}

/**
 * Log login activity to database
 * @param mysqli $conn Database connection
 * @param int $userId User ID (or null for failed attempts)
 * @param string $email Attempted email
 * @param bool $success Whether login succeeded
 * @param string|null $failureReason Reason for failure
 */
function logLoginActivity($conn, $userId, $email, $success, $failureReason = null) {
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // Check if login_activity table exists first
    if (!tableExists($conn, 'login_activity')) {
        error_log("login_activity table does not exist - skipping activity logging");
        return;
    }
    
    // Prepare statement for login_activity table
    $stmt = $conn->prepare(
        "INSERT INTO login_activity 
        (user_id, ip_address, user_agent, is_success, failure_reason) 
        VALUES (?, ?, ?, ?, ?)"
    );
    
    if ($stmt) {
        // Convert userId to null if 0 or less
        $userIdForLog = ($userId > 0) ? $userId : null;
        $isSuccessInt = $success ? 1 : 0; // Convert boolean to integer
        
        $stmt->bind_param("issis", $userIdForLog, $ipAddress, $userAgent, $isSuccessInt, $failureReason);
        
        if (!$stmt->execute()) {
            error_log("Failed to log login activity: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("Failed to prepare login activity statement: " . $conn->error);
    }
}

/**
 * Update last login timestamp
 * @param mysqli $conn Database connection
 * @param int $userId User ID
 */
function updateLastLogin($conn, $userId) {
    $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE uid = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) {
            error_log("Failed to update last login: " . $stmt->error);
        }
        $stmt->close();
    }
}

/**
 * Check if account is locked due to failed attempts
 * @param mysqli $conn Database connection
 * @param string $email Email address
 * @return bool True if account is locked
 */
function isAccountLocked($conn, $email) {
    // Get system settings for lockout rules
    $settings = SystemSettings::getInstance();
    $maxAttempts = $settings->get('max_login_attempts', 5);
    $lockoutDuration = $settings->get('lockout_duration_minutes', 30);
    
    // Check if login_activity table exists
    if (!tableExists($conn, 'login_activity')) {
        return false; // Can't check if table doesn't exist
    }
    
    // Count recent failed attempts
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as failed_count 
         FROM login_activity la
         JOIN users u ON la.user_id = u.uid
         WHERE u.email = ? 
         AND la.is_success = 0
         AND la.created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    
    if (!$stmt) {
        error_log("Failed to prepare account lock check: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("si", $email, $lockoutDuration);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $isLocked = ($row['failed_count'] >= $maxAttempts);
    if ($isLocked) {
        error_log("Account locked for email: $email (attempts: {$row['failed_count']})");
    }
    
    return $isLocked;
}

/* ========================================
   MAIN LOGIN LOGIC
   ======================================== */

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Non-POST request to login.php");
    redirectWithError('invalid_request');
}

// Get and sanitize POST data
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$role = trim($_POST['role'] ?? '');
$remember = isset($_POST['remember']);

error_log("Login attempt - Email: $email, Role: $role");

// Validate required fields
if (empty($email) || empty($password) || empty($role)) {
    error_log("Missing required fields");
    redirectWithError('missing_fields');
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    error_log("Invalid email format: $email");
    redirectWithError('invalid_email');
}

// Validate role (must be 'student' or 'teacher')
if (!in_array($role, ['student', 'teacher', 'admin'])) {
    error_log("Invalid role: $role");
    redirectWithError('invalid_role');
}

// Check if account is locked
if (isAccountLocked($conn, $email)) {
    redirectWithError('account_locked');
}

/* ========================================
   DATABASE QUERY
   
   IMPORTANT: Using 'uid' as the primary key field name
   based on student-dashboard.php which uses $_SESSION['uid']
   ======================================== */

$stmt = $conn->prepare(
    "SELECT 
        uid,
        name,
        email,
        password,
        user_type,
        department,
        is_verified,
        is_active,
        profile_image
     FROM users 
     WHERE email = ? AND user_type = ?"
);

if (!$stmt) {
    error_log("Database prepare error: " . $conn->error);
    redirectWithError('database_error');
}

$stmt->bind_param("ss", $email, $role);

if (!$stmt->execute()) {
    error_log("Database execute error: " . $stmt->error);
    redirectWithError('database_error');
}

$result = $stmt->get_result();

// Check if user exists with matching role
if ($result->num_rows !== 1) {
    error_log("User not found or role mismatch - Email: $email, Role: $role");
    // Log failed attempt (user_id = 0 for non-existent users)
    logLoginActivity($conn, 0, $email, false, 'user_not_found_or_role_mismatch');
    $stmt->close();
    redirectWithError('invalid_credentials');
}

$user = $result->fetch_assoc();
$stmt->close();

error_log("User found - UID: {$user['uid']}, Name: {$user['name']}, Verified: {$user['is_verified']}, Active: {$user['is_active']}");

/* ========================================
   ACCOUNT STATUS CHECKS
   ======================================== */

// Check if account is active (users.is_active)
if (!$user['is_active']) {
    error_log("Account inactive - UID: {$user['uid']}");
    logLoginActivity($conn, $user['uid'], $email, false, 'account_inactive');
    redirectWithError('account_inactive');
}

// Check if email is verified (users.is_verified)
// OPTIONAL: Comment out these lines if you want to allow unverified login during development
if (!$user['is_verified']) {
    error_log("Email not verified - UID: {$user['uid']}");
    logLoginActivity($conn, $user['uid'], $email, false, 'email_not_verified');
    redirectWithError('email_not_verified');
}

/* ========================================
   PASSWORD VERIFICATION
   
   NOTE: The database field is 'password' (not 'password_hash')
   ======================================== */

// Check if password is already hashed (starts with $2y$ for bcrypt)
$isHashed = (strpos($user['password'], '$2y$') === 0 || strpos($user['password'], '$2a$') === 0);

$passwordValid = false;

if ($isHashed) {
    // Password is hashed, use password_verify
    $passwordValid = password_verify($password, $user['password']);
    error_log("Using password_verify - Result: " . ($passwordValid ? 'true' : 'false'));
} else {
    // Password is plain text (not recommended, but handling it)
    $passwordValid = ($password === $user['password']);
    error_log("WARNING: Password stored in plain text! Using direct comparison - Result: " . ($passwordValid ? 'true' : 'false'));
}

if (!$passwordValid) {
    error_log("Password verification failed - UID: {$user['uid']}");
    // Log failed login attempt
    logLoginActivity($conn, $user['uid'], $email, false, 'wrong_password');
    redirectWithError('invalid_credentials');
}

/* ========================================
   LOGIN SUCCESS
   Create session and redirect
   ======================================== */

error_log("Login successful - UID: {$user['uid']}, Email: $email, Role: {$user['user_type']}");

// Log successful login
logLoginActivity($conn, $user['uid'], $email, true, null);

// Update last login timestamp
updateLastLogin($conn, $user['uid']);

// Clear any existing session data
$_SESSION = array();

// IMPORTANT: Match session variable names with what student-dashboard.php expects
$_SESSION['uid'] = $user['uid'];  // student-dashboard.php checks for 'uid'
$_SESSION['user_id'] = $user['uid'];  // Also set user_id for compatibility
$_SESSION['name'] = $user['name'];  // student-dashboard.php uses 'name'
$_SESSION['full_name'] = $user['name'];  // Also set full_name for compatibility
$_SESSION['email'] = $user['email'];
$_SESSION['role'] = $user['user_type'];  // student-dashboard.php checks for 'role'
$_SESSION['user_type'] = $user['user_type'];  // Also set user_type for compatibility
$_SESSION['department'] = $user['department'] ?? '';
$_SESSION['profile_image'] = $user['profile_image'] ?? '';
$_SESSION['login_time'] = time();
$_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
$_SESSION['is_verified'] = $user['is_verified'];
$_SESSION['is_active'] = $user['is_active'];

// Log session creation
error_log("Session created - Session ID: " . session_id() . ", UID: {$_SESSION['uid']}, Role: {$_SESSION['role']}");

// Handle "Remember Me" functionality
if ($remember) {
    // Set cookie for 30 days
    $cookieValue = base64_encode(json_encode([
        'user_id' => $user['uid'],
        'token' => bin2hex(random_bytes(32)) // Generate secure token
    ]));
    
    setcookie(
        'remember_me',
        $cookieValue,
        time() + (30 * 24 * 60 * 60), // 30 days
        '/',
        '',
        isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // Secure (HTTPS only)
        true  // HttpOnly
    );
    
    error_log("Remember me cookie set for user: {$user['uid']}");
}

/* ========================================
   REDIRECT TO APPROPRIATE DASHBOARD
   Based on user_type from database
   ======================================== */

$redirectUrl = '';

switch ($user['user_type']) {
    case 'student':
        $redirectUrl = 'student-dashboard.php';
        break;
    
    case 'teacher':
        $redirectUrl = 'teacher-dashboard.php';
        break;
    
    case 'admin':
        $redirectUrl = 'admin-dashboard.php';
        break;
    
    default:
        // Should never reach here due to earlier validation
        error_log("Invalid user type after login: {$user['user_type']}");
        logLoginActivity($conn, $user['uid'], $email, false, 'invalid_user_type');
        redirectWithError('invalid_role');
}

error_log("Redirecting to: $redirectUrl");

// Use absolute redirect to ensure it works
header("Location: $redirectUrl");
exit;

/* ========================================
   DATABASE SCHEMA NOTES
   
   Based on student-dashboard.php, the actual schema uses:
   - uid (not user_id) as the primary key
   - name (not full_name) for the user's name
   - password (not password_hash) for the password field
   - user_type for the role
   
   Session variables expected by student-dashboard.php:
   - $_SESSION['uid']
   - $_SESSION['role']
   - Also fetches 'name' and 'email' from database
   
   RECOMMENDATION FOR TEAM:
   1. Standardize field names across all files
   2. Always hash passwords with password_hash()
   3. Ensure all dashboards check the same session variables
   4. Create the login_activity table if it doesn't exist
   ======================================== */
?>