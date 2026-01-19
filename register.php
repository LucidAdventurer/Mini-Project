<?php
/* ========================================
   PRODUCTION REGISTRATION HANDLER
   ======================================== */

// CRITICAL: No whitespace before this line
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

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

// Wrap everything in try-catch
try {
    // Include dependencies with error checking
    if (!file_exists("config.php")) {
        throw new Exception("Configuration file not found");
    }
    require_once "config.php";
    
    // Check database connection
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception("Database connection failed");
    }
    
    // system-settings.php is optional - handle gracefully
    $settingsAvailable = file_exists("system-settings.php");
    if ($settingsAvailable) {
        require_once "system-settings.php";
        $settings = SystemSettings::getInstance();
    }

    /* ========================================
       HELPER FUNCTIONS
       ======================================== */
    
    function sendResponse($success, $message, $data = []) {
        echo json_encode(array_merge([
            'success' => $success,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ], $data));
        exit;
    }
    
    function sanitizeInput($data) {
        return htmlspecialchars(trim(stripslashes($data)), ENT_QUOTES, 'UTF-8');
    }
    
    function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
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
    
    function generateToken($length = 64) {
        return bin2hex(random_bytes($length / 2));
    }
    
    /* ========================================
       MAIN LOGIC
       ======================================== */
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method');
    }
    
    // Check if registration allowed (if settings available)
    if ($settingsAvailable) {
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
    
    // Validate passwords match
    if (!empty($confirmPassword) && $password !== $confirmPassword) {
        sendResponse(false, 'Passwords do not match');
    }
    
    // Validate name
    if (strlen($fullName) < 3 || !preg_match('/^[a-zA-Z\s\'.,-]+$/', $fullName)) {
        sendResponse(false, 'Invalid name format');
    }
    
    // Validate email
    if (!isValidEmail($email)) {
        sendResponse(false, 'Invalid email address');
    }
    
    // Validate password strength
    list($isPasswordValid, $passwordMessage) = validatePasswordStrength($password);
    if (!$isPasswordValid) {
        sendResponse(false, $passwordMessage);
    }
    
    // Role-specific validation
    $validDepartments = [
        'Computer Science', 'Information Technology', 'Electronics',
        'Mechanical', 'Civil', 'Electrical', 'Chemical', 
        'Biotechnology', 'Mathematics', 'Physics', 'Chemistry', 'Other'
    ];
    
    if ($userType === 'student') {
        if (empty($regNumber)) {
            sendResponse(false, 'Registration number is required for students');
        }
        if (strlen($regNumber) < 5) {
            sendResponse(false, 'Registration number must be at least 5 characters');
        }
        if (regNumberExists($conn, $regNumber)) {
            sendResponse(false, 'Registration number already exists');
        }
    } else {
        $regNumber = null; // Teachers don't have registration numbers
    }
    
    if (empty($department) || !in_array($department, $validDepartments)) {
        sendResponse(false, 'Please select a valid department');
    }
    
    // Check duplicate email
    if (emailExists($conn, $email)) {
        sendResponse(false, 'Email already registered. Please login instead.');
    }
    
    // Create user account
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $conn->begin_transaction();
    
    try {
        // Insert user
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
        
        $stmt = $conn->prepare(
            "INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
        );
        
        if ($stmt) {
            $stmt->bind_param("iss", $userId, $token, $expiresAt);
            $stmt->execute();
            $stmt->close();
        }
        
        // Queue email (if table exists)
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $verificationLink = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/verify-email.php?token=" . $token;
        
        $subject = "Verify Your Email - PTA Platform";
        $body = "Hello $fullName,\n\nPlease verify your email by clicking:\n$verificationLink\n\nThis link expires in 24 hours.";
        
        $stmt = $conn->prepare(
            "INSERT INTO email_queue (recipient_email, recipient_name, subject, body, email_type) 
            VALUES (?, ?, ?, ?, 'verification')"
        );
        
        if ($stmt) {
            $stmt->bind_param("ssss", $email, $fullName, $subject, $body);
            $stmt->execute();
            $stmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        $requireVerification = $settingsAvailable ? $settings->get('require_email_verification', true) : true;
        
        $message = $requireVerification 
            ? "Account created! Please check your email to verify your account."
            : "Account created successfully! You can now login.";
        
        sendResponse(true, $message, [
            'user_id' => $userId,
            'requires_verification' => $requireVerification,
            'redirect' => 'index.html?success=' . urlencode($message)
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Registration transaction failed: " . $e->getMessage());
        sendResponse(false, $e->getMessage());
    }
    
} catch (Throwable $e) {
    error_log("Fatal registration error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendResponse(false, 'A server error occurred. Please try again or contact support.');
}
