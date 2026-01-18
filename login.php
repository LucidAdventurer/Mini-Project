<?php
/* ========================================
   PTA LOGIN HANDLER
   File: login.php
   
   Purpose: Authenticates users and creates sessions
   
   Database Tables Used:
   - users: Main authentication
   - login_activity: Security logging
   
   Flow:
   1. Receive POST data from index.html
   2. Validate inputs
   3. Query users table
   4. Verify password hash
   5. Check account status
   6. Log login attempt
   7. Create session
   8. Redirect to dashboard
   
   Security Features:
   - Password hashing with password_verify()
   - SQL injection prevention with prepared statements
   - Session hijacking prevention
   - Login attempt logging
   - Account status verification
   ======================================== */

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
    header("Location: index.html?error=" . urlencode($error));
    exit;
}

/**
 * Log login activity to database
 * @param mysqli $conn Database connection
 * @param int $userId User ID (or 0 for failed attempts)
 * @param string $email Attempted email
 * @param bool $success Whether login succeeded
 * @param string|null $failureReason Reason for failure
 */
function logLoginActivity($conn, $userId, $email, $success, $failureReason = null) {
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // Prepare statement for login_activity table
    $stmt = $conn->prepare(
        "INSERT INTO login_activity 
        (user_id, ip_address, user_agent, is_success, failure_reason) 
        VALUES (?, ?, ?, ?, ?)"
    );
    
    if ($stmt) {
        // FIX: Convert userId to null if 0, and ensure is_success is integer (0 or 1)
        $userIdForLog = $userId > 0 ? $userId : null;
        $isSuccessInt = $success ? 1 : 0; // Convert boolean to integer
        
        $stmt->bind_param("issis", $userIdForLog, $ipAddress, $userAgent, $isSuccessInt, $failureReason);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Update last login timestamp
 * @param mysqli $conn Database connection
 * @param int $userId User ID
 */
function updateLastLogin($conn, $userId) {
    $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
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
    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings 
         WHERE setting_key IN ('max_login_attempts', 'lockout_duration_minutes')"
    );
    
    if (!$stmt) return false;
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $maxAttempts = 5; // Default
    $lockoutDuration = 30; // Default in minutes
    
    // FIX: Properly check if result exists and has the expected keys
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // FIX: Check if keys exist before accessing
            if (isset($row['setting_key']) && isset($row['setting_value'])) {
                if ($row['setting_key'] === 'max_login_attempts') {
                    $maxAttempts = (int)$row['setting_value'];
                }
                if ($row['setting_key'] === 'lockout_duration_minutes') {
                    $lockoutDuration = (int)$row['setting_value'];
                }
            }
        }
    }
    $stmt->close();
    
    // Count recent failed attempts
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as failed_count 
         FROM login_activity la
         JOIN users u ON la.user_id = u.user_id
         WHERE u.email = ? 
         AND la.is_success = 0
         AND la.created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    
    if (!$stmt) return false;
    
    $stmt->bind_param("si", $email, $lockoutDuration);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return ($row['failed_count'] >= $maxAttempts);
}

/* ========================================
   MAIN LOGIN LOGIC
   ======================================== */

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithError('invalid_request');
}

// Get and sanitize POST data
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$role = trim($_POST['role'] ?? '');
$remember = isset($_POST['remember']);

// Validate required fields
if (empty($email) || empty($password) || empty($role)) {
    redirectWithError('missing_fields');
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirectWithError('invalid_email');
}

// Validate role (must be 'student' or 'teacher')
if (!in_array($role, ['student', 'teacher', 'admin'])) {
    redirectWithError('invalid_role');
}

// Check if account is locked
if (isAccountLocked($conn, $email)) {
    redirectWithError('account_locked');
}

/* ========================================
   DATABASE QUERY
   Query users table with email and role match
   
   Fields retrieved:
   - user_id: For session storage
   - full_name: For personalization
   - email: For display
   - password_hash: For verification
   - user_type: For role validation
   - department: For access control
   - is_verified: Email verification status
   - is_active: Account status
   ======================================== */

$stmt = $conn->prepare(
    "SELECT 
        user_id,
        full_name,
        email,
        password_hash,
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
$stmt->execute();
$result = $stmt->get_result();

// Check if user exists with matching role
if ($result->num_rows !== 1) {
    // Log failed attempt (user_id = 0 for non-existent users)
    logLoginActivity($conn, 0, $email, false, 'user_not_found_or_role_mismatch');
    $stmt->close();
    redirectWithError('invalid_credentials');
}

$user = $result->fetch_assoc();
$stmt->close();

/* ========================================
   ACCOUNT STATUS CHECKS
   ======================================== */

// Check if account is active (users.is_active)
if (!$user['is_active']) {
    logLoginActivity($conn, $user['user_id'], $email, false, 'account_inactive');
    redirectWithError('account_inactive');
}

// Check if email is verified (users.is_verified)
// Note: You may want to allow unverified users to login but show a warning
if (!$user['is_verified']) {
    logLoginActivity($conn, $user['user_id'], $email, false, 'email_not_verified');
    redirectWithError('email_not_verified');
}

/* ========================================
   PASSWORD VERIFICATION
   Uses password_verify() for secure comparison
   Never store or compare plain text passwords
   ======================================== */

if (!password_verify($password, $user['password_hash'])) {
    // Log failed login attempt
    logLoginActivity($conn, $user['user_id'], $email, false, 'wrong_password');
    redirectWithError('invalid_credentials');
}

/* ========================================
   LOGIN SUCCESS
   Create session and redirect
   ======================================== */

// Log successful login
logLoginActivity($conn, $user['user_id'], $email, true, null);

// Update last login timestamp
updateLastLogin($conn, $user['user_id']);

// Create session variables
$_SESSION['user_id'] = $user['user_id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email'] = $user['email'];
$_SESSION['user_type'] = $user['user_type'];
$_SESSION['department'] = $user['department'];
$_SESSION['profile_image'] = $user['profile_image'];
$_SESSION['login_time'] = time();
$_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];

// Handle "Remember Me" functionality
if ($remember) {
    // Set cookie for 30 days
    // Note: Store only user_id, not sensitive data
    $cookieValue = base64_encode(json_encode([
        'user_id' => $user['user_id'],
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
    
    // TODO: Store remember token in database for validation
}

/* ========================================
   REDIRECT TO APPROPRIATE DASHBOARD
   Based on user_type from database
   ======================================== */

switch ($user['user_type']) {
    case 'student':
        header("Location: student-dashboard.php");
        exit;
    
    case 'teacher':
        header("Location: teacher-dashboard.php");
        exit;
    
    case 'admin':
        header("Location: admin-dashboard.php");
        exit;
    
    default:
        // Should never reach here due to earlier validation
        logLoginActivity($conn, $user['user_id'], $email, false, 'invalid_user_type');
        redirectWithError('invalid_role');
}

/* ========================================
   DATABASE SCHEMA REFERENCE
   
   For your team's reference:
   
   users table fields used:
   - user_id (INT, PRIMARY KEY, AUTO_INCREMENT)
   - full_name (VARCHAR 100, NOT NULL)
   - email (VARCHAR 100, UNIQUE, NOT NULL)
   - password_hash (VARCHAR 255, NOT NULL) - Use password_hash() to create
   - user_type (ENUM: 'student','teacher','admin', NOT NULL)
   - department (VARCHAR 50)
   - is_verified (BOOLEAN, DEFAULT FALSE) - Email verification status
   - is_active (BOOLEAN, DEFAULT TRUE) - Account enabled/disabled
   - last_login (TIMESTAMP NULL) - Updated on each login
   - profile_image (VARCHAR 255)
   
   login_activity table fields:
   - log_id (INT, PRIMARY KEY, AUTO_INCREMENT)
   - user_id (INT, FOREIGN KEY to users)
   - ip_address (VARCHAR 45) - Supports IPv4 and IPv6
   - user_agent (TEXT) - Browser/device information
   - is_success (TINYINT/BOOLEAN) - Login success/failure (0 or 1)
   - failure_reason (VARCHAR 100) - Why login failed
   - created_at (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
   
   system_settings table fields:
   - setting_key (VARCHAR 50, PRIMARY KEY)
   - setting_value (TEXT)
   - setting_type (ENUM: 'string','number','boolean','json')
   
   TODO for team:
   1. Implement password_reset_otps table functionality
   2. Add email_verification_tokens handling
   3. Create dashboard pages (student-dashboard.php, teacher-dashboard.php)
   4. Implement session timeout based on system_settings
   5. Add CSRF token validation
   6. Implement rate limiting for login attempts
   7. Add two-factor authentication (optional)
   ======================================== */
