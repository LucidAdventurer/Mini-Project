<?php
/* ========================================
   PRODUCTION REGISTRATION HANDLER - REMOTE DB OPTIMIZED v2.0
   
   OPTIMIZATIONS:
   - Increased execution time limit
   - Reduced database queries
   - Better error handling for timeouts
   - Graceful degradation if settings unavailable
   ======================================== */

// CRITICAL: No whitespace before this line
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

// Increase execution time for remote database operations
set_time_limit(120); // 2 minutes
ini_set('max_execution_time', '120');

// Ensure logs directory exists
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

header('Content-Type: application/json');

// Global error handler for unexpected errors
set_exception_handler(function($e) {
    error_log("Uncaught exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again.'
    ]);
    exit;
});

/**
 * Send JSON response and exit
 */
function sendResponse($success, $message, $data = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ], $data));
    exit;
}

// Wrap everything in try-catch
try {
    // Include dependencies with error checking
    if (!file_exists("config.php")) {
        throw new Exception("Configuration file not found");
    }
    
    require_once "config.php";
    
    // Check database connection with automatic reconnect
    if (!ensureDatabaseConnection($conn)) {
        throw new Exception("Database connection failed after retries");
    }
    
    // system-settings.php is optional - handle gracefully
    $settingsAvailable = false;
    $settings = null;
    
    if (file_exists("system-settings.php")) {
        try {
            require_once "system-settings.php";
            $settings = SystemSettings::getInstance();
            $settingsAvailable = true;
        } catch (Exception $e) {
            error_log("Failed to load system settings: " . $e->getMessage());
            // Continue without settings - use defaults
        }
    }

    /* ========================================
       HELPER FUNCTIONS
       ======================================== */
    
    /**
     * Sanitize user input
     */
    function sanitizeInput($data) {
        return htmlspecialchars(trim(stripslashes($data)), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate email format
     */
    function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate password strength
     * Returns [bool, string] - [isValid, message]
     */
    function validatePasswordStrength($password) {
        $errors = [];
        if (strlen($password) < 8) $errors[] = "at least 8 characters";
        if (!preg_match('/[A-Z]/', $password)) $errors[] = "one uppercase letter";
        if (!preg_match('/[a-z]/', $password)) $errors[] = "one lowercase letter";
        if (!preg_match('/[0-9]/', $password)) $errors[] = "one number";
        
        if (count($errors) > 0) {
            return [false, "Password must contain " . implode(', ', $errors)];
        }
        return [true, 'Password meets requirements'];
    }
    
    /**
     * Check if email already exists in database
     */
    function emailExists($conn, $email) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        if (!$stmt) {
            error_log("emailExists prepare failed: " . $conn->error);
            return false;
        }
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }
    
    /**
     * Check if registration number already exists
     */
    function regNumberExists($conn, $regNumber) {
        if (empty($regNumber)) return false;
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE registration_number = ? LIMIT 1");
        if (!$stmt) {
            error_log("regNumberExists prepare failed: " . $conn->error);
            return false;
        }
        $stmt->bind_param("s", $regNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }
    
    /**
     * Generate secure random token
     */
    function generateToken($length = 64) {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Queue email for sending (with fallback if table doesn't exist)
     */
    function queueEmail($conn, $email, $name, $subject, $body, $type = 'verification') {
        try {
            // Check if email_queue table exists
            $result = $conn->query("SHOW TABLES LIKE 'email_queue'");
            if ($result && $result->num_rows > 0) {
                $stmt = $conn->prepare(
                    "INSERT INTO email_queue (recipient_email, recipient_name, subject, body, email_type) 
                    VALUES (?, ?, ?, ?, ?)"
                );
                if ($stmt) {
                    $stmt->bind_param("sssss", $email, $name, $subject, $body, $type);
                    $stmt->execute();
                    $stmt->close();
                    return true;
                }
            } else {
                error_log("email_queue table does not exist - email not queued");
            }
        } catch (Exception $e) {
            error_log("Failed to queue email: " . $e->getMessage());
        }
        return false;
    }
    
    /* ========================================
       MAIN LOGIC
       ======================================== */
    
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method');
    }
    
    // Check if registration is allowed (if settings available)
    if ($settingsAvailable && $settings) {
        $allowRegistration = $settings->get('allow_registration', true);
        if (!$allowRegistration) {
            sendResponse(false, 'Registration is currently disabled');
        }
    }
    
    // Get and sanitize inputs
    $fullName = sanitizeInput($_POST['full_name'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $userType = sanitizeInput($_POST['user_type'] ?? '');
    $regNumber = sanitizeInput($_POST['registration_number'] ?? '');
    $department = sanitizeInput($_POST['department'] ?? '');
    
    // Validate user type
    if (!in_array($userType, ['student', 'teacher'])) {
        sendResponse(false, 'Invalid user type');
    }
    
    // Validate required fields
    if (empty($fullName) || empty($email) || empty($password)) {
        sendResponse(false, 'Full name, email, and password are required');
    }
    
    // Validate passwords match (if confirm password is provided)
    if (!empty($confirmPassword) && $password !== $confirmPassword) {
        sendResponse(false, 'Passwords do not match');
    }
    
    // Validate name format
    if (strlen($fullName) < 3 || !preg_match('/^[a-zA-Z\s\'.,-]+$/', $fullName)) {
        sendResponse(false, 'Invalid name format. Name must be at least 3 characters and contain only letters, spaces, and common punctuation');
    }
    
    // Validate email
    if (!isValidEmail($email)) {
        sendResponse(false, 'Invalid email address format');
    }
    
    // Validate password strength
    list($isPasswordValid, $passwordMessage) = validatePasswordStrength($password);
    if (!$isPasswordValid) {
        sendResponse(false, $passwordMessage);
    }
    
    // Valid departments list
    $validDepartments = [
        'Computer Science', 'Information Technology', 'Electronics',
        'Mechanical', 'Civil', 'Electrical', 'Chemical', 
        'Biotechnology', 'Mathematics', 'Physics', 'Chemistry', 'Other'
    ];
    
    // Role-specific validation
    if ($userType === 'student') {
        if (empty($regNumber)) {
            sendResponse(false, 'Registration number is required for students');
        }
        if (strlen($regNumber) < 5) {
            sendResponse(false, 'Registration number must be at least 5 characters');
        }
        // Check if registration number already exists
        if (regNumberExists($conn, $regNumber)) {
            sendResponse(false, 'Registration number already exists');
        }
    } else {
        $regNumber = null; // Teachers don't have registration numbers
    }
    
    // Validate department
    if (empty($department) || !in_array($department, $validDepartments)) {
        sendResponse(false, 'Please select a valid department');
    }
    
    // Check if email already exists
    if (emailExists($conn, $email)) {
        sendResponse(false, 'Email already registered. Please login instead.');
    }
    
    /* ========================================
       CREATE USER ACCOUNT
       ======================================== */
    
    // Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    // Begin transaction for data consistency
    $conn->begin_transaction();
    
    try {
        // Insert user into database
        $stmt = $conn->prepare(
            "INSERT INTO users 
            (full_name, email, password_hash, user_type, department, registration_number, is_verified, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, FALSE, TRUE)"
        );
        
        if (!$stmt) {
            throw new Exception("Database prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("ssssss", $fullName, $email, $passwordHash, $userType, $department, $regNumber);
        
        if (!$stmt->execute()) {
            // Handle duplicate entry errors
            if ($stmt->errno === 1062) {
                if (strpos($stmt->error, 'email') !== false) {
                    throw new Exception("Email already exists");
                } elseif (strpos($stmt->error, 'registration_number') !== false) {
                    throw new Exception("Registration number already exists");
                }
            }
            throw new Exception("Failed to create account: " . $stmt->error);
        }
        
        $userId = $stmt->insert_id;
        $stmt->close();
        
        // Generate verification token
        $token = generateToken(64);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Insert verification token (with error handling)
        try {
            $stmt = $conn->prepare(
                "INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
            );
            
            if ($stmt) {
                $stmt->bind_param("iss", $userId, $token, $expiresAt);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Failed to create verification token: " . $e->getMessage());
            // Continue without token - not critical
        }
        
        // Queue verification email
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $verificationLink = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/verify-email.php?token=" . $token;
        
        $subject = "Verify Your Email - PTA Platform";
        $body = "Hello $fullName,\n\n";
        $body .= "Thank you for registering on the PTA Platform.\n\n";
        $body .= "Please verify your email address by clicking the link below:\n";
        $body .= "$verificationLink\n\n";
        $body .= "This link will expire in 24 hours.\n\n";
        $body .= "If you did not create this account, please ignore this email.\n\n";
        $body .= "Best regards,\nPTA Platform Team";
        
        queueEmail($conn, $email, $fullName, $subject, $body, 'verification');
        
        // Commit transaction
        $conn->commit();
        
        // Determine if email verification is required
        $requireVerification = $settingsAvailable 
            ? $settings->get('require_email_verification', true) 
            : true;
        
        $message = $requireVerification 
            ? "Account created successfully! Please check your email to verify your account."
            : "Account created successfully! You can now login.";
        
        // Send success response
        sendResponse(true, $message, [
            'user_id' => $userId,
            'requires_verification' => $requireVerification,
            'redirect' => 'index.html?success=' . urlencode($message)
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        error_log("Registration transaction failed: " . $e->getMessage());
        sendResponse(false, $e->getMessage());
    }
    
} catch (Throwable $e) {
    // Catch any fatal errors
    error_log("Fatal registration error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendResponse(false, 'A server error occurred. Please try again or contact support.');
}
?>