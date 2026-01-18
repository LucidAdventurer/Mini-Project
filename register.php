<?php
/* ========================================
   PTA UNIFIED REGISTRATION HANDLER
   File: register.php
   
   Purpose: Handle registration for both students and teachers with role-specific validation
   
   Database Tables Used:
   - users: Store user account information
   - email_verification_tokens: Generate email verification token
   - audit_logs: Log registration attempts
   
   Supports:
   - Student registration (with registration_number, department)
   - Teacher registration (with department, specialization)
   - Role-specific field validation
   
   Flow:
   1. Detect user_type from POST data
   2. Validate common fields (name, email, password)
   3. Validate role-specific fields
   4. Check for duplicates
   5. Create user account
   6. Generate verification token
   7. Queue verification email
   8. Return JSON response
   
   Security Features:
   - Password hashing with PASSWORD_DEFAULT
   - SQL injection prevention with prepared statements
   - Input sanitization and validation
   - Email verification requirement
   - Audit logging
   - Rate limiting ready
   ======================================== */

// Error reporting for development (disable in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set JSON response header
header('Content-Type: application/json');

// Include configuration
require_once "config.php";
require_once "system-settings.php";

/* ========================================
   HELPER FUNCTIONS
   ======================================== */

/**
 * Send JSON response and exit
 * @param bool $success Success status
 * @param string $message Response message
 * @param array $data Additional data
 */
function sendResponse($success, $message, $data = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ], $data));
    exit;
}

/**
 * Sanitize input data
 * @param string $data Input data
 * @return string Sanitized data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Validate email format
 * @param string $email Email address
 * @return bool Valid or not
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate password strength
 * @param string $password Password
 * @return array [bool valid, string message]
 */
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "at least 8 characters";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "one number";
    }
    
    if (count($errors) > 0) {
        return [false, "Password must contain " . implode(', ', $errors)];
    }
    
    return [true, 'Password meets requirements'];
}

/**
 * Check if email already exists
 * @param mysqli $conn Database connection
 * @param string $email Email address
 * @return bool True if exists
 */
function emailExists($conn, $email) {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Check if registration number already exists (students only)
 * @param mysqli $conn Database connection
 * @param string $regNumber Registration number
 * @return bool True if exists
 */
function regNumberExists($conn, $regNumber) {
    if (empty($regNumber)) return false;
    
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE registration_number = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("s", $regNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Generate secure random token
 * @param int $length Token length (must be even)
 * @return string Random token
 */
function generateToken($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Log registration attempt to audit log
 * @param mysqli $conn Database connection
 * @param int $userId User ID (or null if failed)
 * @param string $email Email attempted
 * @param string $userType User type (student/teacher)
 * @param bool $success Success status
 * @param string $reason Failure reason
 */
function logRegistration($conn, $userId, $email, $userType, $success, $reason = null) {
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $action = $success ? 'user_registration_success' : 'user_registration_failed';
    
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs 
        (user_id, action, entity_type, entity_id, new_values, ip_address, user_agent) 
        VALUES (?, ?, 'user', ?, ?, ?, ?)"
    );
    
    if ($stmt) {
        $newValues = json_encode([
            'email' => $email,
            'user_type' => $userType,
            'reason' => $reason,
            'success' => $success
        ]);
        $stmt->bind_param("isiss", $userId, $action, $userId, $newValues, $ipAddress, $userAgent);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Create email verification token
 * @param mysqli $conn Database connection
 * @param int $userId User ID
 * @return string Verification token or null on failure
 */
function createVerificationToken($conn, $userId) {
    $token = generateToken(64);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    $stmt = $conn->prepare(
        "INSERT INTO email_verification_tokens (user_id, token, expires_at) 
        VALUES (?, ?, ?)"
    );
    
    if ($stmt) {
        $stmt->bind_param("iss", $userId, $token, $expiresAt);
        if ($stmt->execute()) {
            $stmt->close();
            return $token;
        }
        $stmt->close();
    }
    
    return null;
}

/**
 * Queue verification email for async sending
 * @param mysqli $conn Database connection
 * @param string $email Recipient email
 * @param string $name Recipient name
 * @param string $token Verification token
 * @param string $userType User type for customized message
 */
function queueVerificationEmail($conn, $email, $name, $token, $userType) {
    $subject = "Verify Your Email - PTA Platform";
    
    // Create verification link
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $verificationLink = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/verify-email.php?token=" . $token;
    
    // Customize message based on user type
    $roleMessage = $userType === 'student' 
        ? "access assessments, training materials, and track your progress"
        : "create assessments, manage students, and access analytics";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #234C6A, #456882); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .button { display: inline-block; padding: 15px 30px; background: #234C6A; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
            .button:hover { background: #456882; }
            .info-box { background: white; padding: 15px; border-left: 4px solid #234C6A; margin: 20px 0; }
            .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Welcome to PTA Platform!</h1>
                <p>Placement & Training Assessment</p>
            </div>
            <div class='content'>
                <h2>Hello {$name},</h2>
                <p>Thank you for registering as a <strong>" . ucfirst($userType) . "</strong> with PTA Platform.</p>
                <p>To complete your registration and activate your account, please verify your email address by clicking the button below:</p>
                <p style='text-align: center;'>
                    <a href='{$verificationLink}' class='button'>Verify Email Address</a>
                </p>
                
                <div class='info-box'>
                    <strong>What's Next?</strong>
                    <p>Once verified, you'll be able to {$roleMessage}.</p>
                </div>
                
                <p>Or copy and paste this link into your browser:</p>
                <p style='word-break: break-all; background: white; padding: 10px; border-radius: 5px; border: 1px solid #ddd;'>{$verificationLink}</p>
                
                <p style='color: #e74c3c;'><strong>Important:</strong> This verification link will expire in 24 hours.</p>
                <p>If you didn't create an account with PTA Platform, please ignore this email.</p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " PTA Platform. All rights reserved.</p>
                <p>This is an automated email. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Queue email in database for async processing
    $stmt = $conn->prepare(
        "INSERT INTO email_queue (recipient_email, recipient_name, subject, body, email_type) 
        VALUES (?, ?, ?, ?, 'verification')"
    );
    
    if ($stmt) {
        $stmt->bind_param("ssss", $email, $name, $subject, $body);
        $stmt->execute();
        $stmt->close();
    }
}

/* ========================================
   MAIN REGISTRATION LOGIC
   ======================================== */

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method. Please use POST.');
}

// Get system settings
$settings = SystemSettings::getInstance();
$allowRegistration = $settings->get('allow_registration', true);

// Check if registration is allowed
if (!$allowRegistration) {
    sendResponse(false, 'Registration is currently disabled. Please contact administrator.');
}

// Get and sanitize POST data - Common fields
$fullName = sanitizeInput($_POST['full_name'] ?? $_POST['name'] ?? '');
$email = sanitizeInput($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$userType = sanitizeInput($_POST['user_type'] ?? '');

// Role-specific fields
$regNumber = sanitizeInput($_POST['registration_number'] ?? '');
$department = sanitizeInput($_POST['department'] ?? '');

/* ========================================
   VALIDATE USER TYPE
   ======================================== */

// Validate user type
if (!in_array($userType, ['student', 'teacher'])) {
    sendResponse(false, 'Invalid user type. Must be student or teacher.');
}

/* ========================================
   COMMON FIELD VALIDATION
   ======================================== */

// Validate required common fields
if (empty($fullName) || empty($email) || empty($password)) {
    sendResponse(false, 'Full name, email, and password are required.');
}

// Validate confirm password if provided
if (!empty($confirmPassword) && $password !== $confirmPassword) {
    sendResponse(false, 'Passwords do not match.');
}

// Validate full name
if (strlen($fullName) < 3) {
    sendResponse(false, 'Full name must be at least 3 characters long.');
}

if (!preg_match('/^[a-zA-Z\s\'.,-]+$/', $fullName)) {
    sendResponse(false, 'Full name contains invalid characters.');
}

// Validate email
if (!isValidEmail($email)) {
    sendResponse(false, 'Invalid email address format.');
}

// Validate password strength
list($isPasswordValid, $passwordMessage) = validatePasswordStrength($password);
if (!$isPasswordValid) {
    sendResponse(false, $passwordMessage);
}

/* ========================================
   ROLE-SPECIFIC VALIDATION
   ======================================== */

// Valid departments (used by both students and teachers)
$validDepartments = [
    'Computer Science',
    'Information Technology',
    'Electronics',
    'Mechanical',
    'Civil',
    'Electrical',
    'Chemical',
    'Biotechnology',
    'Mathematics',
    'Physics',
    'Chemistry',
    'Other'
];

if ($userType === 'student') {
    // Students require registration number and department
    if (empty($regNumber)) {
        sendResponse(false, 'Registration number is required for students.');
    }
    
    if (strlen($regNumber) < 5) {
        sendResponse(false, 'Registration number must be at least 5 characters.');
    }
    
    if (empty($department)) {
        sendResponse(false, 'Department is required for students.');
    }
    
    if (!in_array($department, $validDepartments)) {
        sendResponse(false, 'Invalid department selected.');
    }
    
    // Check if registration number already exists
    if (regNumberExists($conn, $regNumber)) {
        logRegistration($conn, null, $email, $userType, false, 'Registration number already exists');
        sendResponse(false, 'This registration number is already registered. Please contact administrator.');
    }
    
} elseif ($userType === 'teacher') {
    // Teachers require department (registration number is optional/null)
    if (empty($department)) {
        sendResponse(false, 'Department is required for teachers.');
    }
    
    if (!in_array($department, $validDepartments)) {
        sendResponse(false, 'Invalid department selected.');
    }
    
    // Teachers don't use registration_number, so set it to null
    $regNumber = null;
}

/* ========================================
   CHECK FOR DUPLICATE EMAIL
   ======================================== */

if (emailExists($conn, $email)) {
    logRegistration($conn, null, $email, $userType, false, 'Email already exists');
    sendResponse(false, 'An account with this email already exists. Please login or use a different email.');
}

/* ========================================
   CREATE USER ACCOUNT
   ======================================== */

// Hash password securely
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// Begin database transaction
$conn->begin_transaction();

try {
    // Prepare insert statement
    $stmt = $conn->prepare(
        "INSERT INTO users 
        (full_name, email, password_hash, user_type, department, registration_number, is_verified, is_active) 
        VALUES (?, ?, ?, ?, ?, ?, FALSE, TRUE)"
    );
    
    if (!$stmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }
    
    // Bind parameters (registration_number can be null for teachers)
    $stmt->bind_param("ssssss", $fullName, $email, $passwordHash, $userType, $department, $regNumber);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create user account: " . $stmt->error);
    }
    
    $userId = $stmt->insert_id;
    $stmt->close();
    
    // Generate email verification token
    $verificationToken = createVerificationToken($conn, $userId);
    
    if (!$verificationToken) {
        throw new Exception("Failed to generate verification token");
    }
    
    // Queue verification email
    queueVerificationEmail($conn, $email, $fullName, $verificationToken, $userType);
    
    // Log successful registration
    logRegistration($conn, $userId, $email, $userType, true, 'Account created successfully');
    
    // Commit transaction
    $conn->commit();
    
    // Prepare success response
    $requireEmailVerification = $settings->get('require_email_verification', true);
    
    if ($requireEmailVerification) {
        $message = "Account created successfully! Please check your email ({$email}) to verify your account before logging in.";
        $redirect = 'index.html?success=' . urlencode('Registration successful! Please verify your email to login.');
    } else {
        $message = "Account created successfully! You can now login.";
        $redirect = 'index.html?success=' . urlencode('Registration successful! You can now login.');
    }
    
    sendResponse(true, $message, [
        'user_id' => $userId,
        'email' => $email,
        'user_type' => $userType,
        'requires_verification' => $requireEmailVerification,
        'redirect' => $redirect
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on any error
    $conn->rollback();
    
    // Log error for debugging
    error_log("Registration error: " . $e->getMessage());
    
    // Log failed registration
    logRegistration($conn, null, $email, $userType, false, $e->getMessage());
    
    // Send generic error response (don't expose internal errors to user)
    sendResponse(false, 'Failed to create account. Please try again later or contact support.');
}

/* ========================================
   DATABASE SCHEMA REFERENCE
   
   users table structure:
   - user_id (INT, PRIMARY KEY, AUTO_INCREMENT)
   - full_name (VARCHAR 100, NOT NULL)
   - email (VARCHAR 100, UNIQUE, NOT NULL)
   - password_hash (VARCHAR 255, NOT NULL)
   - user_type (ENUM: 'student','teacher','admin', NOT NULL)
   - department (VARCHAR 50)
   - registration_number (VARCHAR 50, UNIQUE) - NULL for teachers
   - is_verified (BOOLEAN, DEFAULT FALSE)
   - is_active (BOOLEAN, DEFAULT TRUE)
   - created_at (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
   - last_login (TIMESTAMP NULL)
   - profile_image (VARCHAR 255)
   
   Key Differences by Role:
   - Students: MUST have registration_number (unique identifier)
   - Teachers: registration_number is NULL (not applicable)
   - Both: MUST have department
   
   Flow After Registration:
   1. User receives verification email
   2. User clicks link in email (verify-email.php)
   3. System sets is_verified = TRUE
   4. User can now login (if require_email_verification is enabled)
   
   TODO for team:
   1. Create verify-email.php handler
   2. Create email sending cron job (process email_queue table)
   3. Update student-registration.html to point to register.php
   4. Update teacher-registration.html to point to register.php
   5. Add rate limiting (limit registrations per IP/hour)
   6. Add reCAPTCHA for bot prevention
   7. Create admin approval workflow (optional)
   8. Add resend verification email feature
   ======================================== */
?>