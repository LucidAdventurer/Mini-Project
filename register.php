
<?php
header('Content-Type: application/json');

// Error handling setup
ini_set('display_errors', 0);
error_reporting(E_ALL);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/registration_errors.log');

// Global error handler
set_exception_handler(function($e) {
    error_log("Registration Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please contact administrator.',
        'debug' => 'Check logs/registration_errors.log for details'
    ]);
    exit;
});

try {
    // Check if config file exists
    if (!file_exists("config.php")) {
        throw new Exception("Configuration file (config.php) not found");
    }
    
    require_once "config.php";
    
    // Verify database connection
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
    }
    
    // Verify users table exists
    if (!tableExists($conn, 'users')) {
        throw new Exception("Users table does not exist. Please run database_setup.sql first.");
    }
    
    // Helper functions
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
        return [true, 'Password valid'];
    }
    
    function emailExists($conn, $email) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        if (!$stmt) {
            error_log("Prepare failed in emailExists: " . $conn->error);
            return false;
        }
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
        if (!$stmt) {
            error_log("Prepare failed in regNumberExists: " . $conn->error);
            return false;
        }
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
    
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method. Please use the registration form.');
    }
    
    // Get and sanitize inputs
    $fullName = sanitizeInput($_POST['full_name'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $userType = sanitizeInput($_POST['user_type'] ?? '');
    $regNumber = sanitizeInput($_POST['registration_number'] ?? '');
    $department = sanitizeInput($_POST['department'] ?? '');
    
    // Log received data (remove passwords)
    error_log("Registration attempt - Email: $email, Type: $userType, Dept: $department");
    
    // Validate user type
    if (!in_array($userType, ['student', 'teacher'])) {
        sendResponse(false, 'Invalid user type. Please select Student or Teacher.');
    }
    
    // Validate required fields
    if (empty($fullName)) {
        sendResponse(false, 'Full name is required');
    }
    
    if (empty($email)) {
        sendResponse(false, 'Email address is required');
    }
    
    if (empty($password)) {
        sendResponse(false, 'Password is required');
    }
    
    // Validate name
    if (strlen($fullName) < 3) {
        sendResponse(false, 'Name must be at least 3 characters long');
    }
    
    if (!preg_match('/^[a-zA-Z\s\'.,-]+$/', $fullName)) {
        sendResponse(false, 'Name contains invalid characters');
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
    
    // Validate passwords match (if confirm_password provided)
    if (!empty($confirmPassword) && $password !== $confirmPassword) {
        sendResponse(false, 'Passwords do not match');
    }
    
    // Student-specific validation
    if ($userType === 'student') {
        if (empty($regNumber)) {
            sendResponse(false, 'Registration number is required for students');
        }
        if (strlen($regNumber) < 5) {
            sendResponse(false, 'Registration number must be at least 5 characters');
        }
        if (regNumberExists($conn, $regNumber)) {
            sendResponse(false, 'This registration number is already registered');
        }
    } else {
        $regNumber = null; // Teachers don't have registration numbers
    }
    
    // Validate department
    $validDepartments = [
        'Computer Science', 'Information Technology', 'Electronics',
        'Mechanical', 'Civil', 'Electrical', 'Chemical', 
        'Biotechnology', 'Mathematics', 'Physics', 'Chemistry', 'Other'
    ];
    
    if (empty($department)) {
        sendResponse(false, 'Department is required');
    }
    
    if (!in_array($department, $validDepartments)) {
        sendResponse(false, 'Please select a valid department');
    }
    
    // Check duplicate email
    if (emailExists($conn, $email)) {
        sendResponse(false, 'This email is already registered. Please login instead.');
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Hash password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert user
        $stmt = $conn->prepare(
            "INSERT INTO users 
            (full_name, email, password_hash, user_type, department, registration_number, is_verified, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, 0, 1)"
        );
        
        if (!$stmt) {
            throw new Exception("Database prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("ssssss", $fullName, $email, $passwordHash, $userType, $department, $regNumber);
        
        if (!$stmt->execute()) {
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
        
        error_log("User created successfully - ID: $userId, Email: $email");
        
        // Generate verification token (if table exists)
        if (tableExists($conn, 'email_verification_tokens')) {
            $token = generateToken(64);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $stmt = $conn->prepare(
                "INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
            );
            
            if ($stmt) {
                $stmt->bind_param("iss", $userId, $token, $expiresAt);
                $stmt->execute();
                $stmt->close();
                error_log("Verification token created for user: $userId");
            }
        }
        
        // Queue verification email (if table exists)
        if (tableExists($conn, 'email_queue')) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $verificationLink = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/verify-email.php?token=" . ($token ?? '');
            
            $subject = "Verify Your Email - PTA Platform";
            $body = "Hello $fullName,\n\nPlease verify your email by clicking:\n$verificationLink\n\nThis link expires in 24 hours.\n\nIf you didn't create this account, please ignore this email.";
            
            $stmt = $conn->prepare(
                "INSERT INTO email_queue (recipient_email, recipient_name, subject, body, email_type) 
                VALUES (?, ?, ?, ?, 'verification')"
            );
            
            if ($stmt) {
                $stmt->bind_param("ssss", $email, $fullName, $subject, $body);
                $stmt->execute();
                $stmt->close();
                error_log("Email queued for user: $userId");
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        error_log("Registration completed successfully for: $email");
        
        // Check if email verification is required (from system settings if available)
        $requireVerification = false;
        if (function_exists('getSystemSetting')) {
            $requireVerification = getSystemSetting('require_email_verification', false);
        }
        
        $message = $requireVerification 
            ? "Account created! Please check your email to verify your account before logging in."
            : "Account created successfully! You can now login with your credentials.";
        
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
    error_log("Fatal registration error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
    sendResponse(false, 'A server error occurred. Please check the error log or contact support.');
}
?>