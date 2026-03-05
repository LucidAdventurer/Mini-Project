<?php
/* ========================================
   PRODUCTION REGISTRATION HANDLER v3.1

   CHANGES FROM v3.0:
   - rollback() called explicitly before sendResponse() on errno 1062
     (prevents leaving an open transaction on duplicate detection)
   - X-Frame-Options replaced with Content-Security-Policy: frame-ancestors 'none'
   - Strict-Transport-Security header added (HTTPS only)
   - $validDepartments moved to a constant (VALID_DEPARTMENTS)
   - IP address included in CSRF failure and error log lines
   ======================================== */

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

set_time_limit(120);
ini_set('max_execution_time', '120');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: strict-origin');

// Only send HSTS when the connection is HTTPS
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

set_exception_handler(function (Throwable $e) {
    error_log("Uncaught exception in register.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode([
        'success'   => false,
        'message'   => 'An unexpected error occurred. Please try again.',
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
    exit;
});

/* ========================================
   CONSTANTS
   ======================================== */

const VALID_DEPARTMENTS = [
    'Computer Science', 'Information Technology', 'Electronics',
    'Mechanical', 'Civil', 'Electrical', 'Chemical',
    'Biotechnology', 'Mathematics', 'Physics', 'Chemistry', 'Other',
];

/* ========================================
   HELPERS
   ======================================== */

function sendResponse(bool $success, string $message, array $data = []): never {
    echo json_encode(array_merge([
        'success'   => $success,
        'message'   => $message,
        'timestamp' => date('Y-m-d H:i:s'),
    ], $data));
    exit;
}

/**
 * Trim only — HTML escaping belongs at render time, not in the DB.
 */
function sanitizeInput(string $data): string {
    return trim($data);
}

function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Returns [bool $isValid, string $message].
 */
function validatePasswordStrength(string $password): array {
    $errors = [];
    if (strlen($password) < 8)             $errors[] = 'at least 8 characters';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'one uppercase letter';
    if (!preg_match('/[a-z]/', $password)) $errors[] = 'one lowercase letter';
    if (!preg_match('/[0-9]/', $password)) $errors[] = 'one number';

    if ($errors) {
        return [false, 'Password must contain ' . implode(', ', $errors)];
    }
    return [true, 'Password meets requirements'];
}

/**
 * Queue a verification email.
 * Uses tableExists() from config.php, which caches the SHOW TABLES result,
 * so we avoid repeated metadata queries.
 */
function queueEmail(
    mysqli $conn,
    string $email,
    string $name,
    string $subject,
    string $body,
    string $type = 'verification'
): bool {
    try {
        if (!tableExists($conn, 'email_queue')) {
            error_log("email_queue table does not exist — email not queued for $email");
            return false;
        }

        $stmt = $conn->prepare(
            "INSERT INTO email_queue (recipient_email, recipient_name, subject, body, email_type)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            error_log("queueEmail prepare failed: " . $conn->error);
            return false;
        }
        $stmt->bind_param('sssss', $email, $name, $subject, $body, $type);
        $result = $stmt->execute();
        $stmt->close();
        return $result;

    } catch (Throwable $e) {
        error_log("queueEmail exception: " . $e->getMessage());
        return false;
    }
}

/* ========================================
   BOOTSTRAP
   ======================================== */

try {
    if (!file_exists('config.php')) {
        throw new RuntimeException('Configuration file not found');
    }
    require_once 'config.php';

    if (!ensureDatabaseConnection($conn)) {
        throw new RuntimeException('Database connection failed after retries');
    }

    // Session must exist before CSRF check
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // system-settings.php is optional
    $settingsAvailable = false;
    $settings          = null;

    if (file_exists('system-settings.php')) {
        try {
            require_once 'system-settings.php';
            $settings          = SystemSettings::getInstance();
            $settingsAvailable = true;
        } catch (Throwable $e) {
            error_log("Failed to load system settings: " . $e->getMessage());
        }
    }

    /* ========================================
       REQUEST VALIDATION
       ======================================== */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method');
    }

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // CSRF — validate token sent via X-CSRF-Token header or POST field
    $sentToken    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!$sentToken || $sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
        error_log("CSRF validation failed on register.php. IP=$clientIp");
        sendResponse(false, 'Invalid request. Please refresh the page and try again.');
    }

    // Registration enabled?
    if ($settingsAvailable && $settings) {
        if (!$settings->get('allow_registration', true)) {
            sendResponse(false, 'Registration is currently disabled');
        }
    }

    /* ========================================
       INPUT SANITIZATION & VALIDATION
       ======================================== */

    $fullName        = sanitizeInput($_POST['full_name']           ?? '');
    $email           = sanitizeInput($_POST['email']               ?? '');
    $password        = $_POST['password']          ?? '';
    $confirmPassword = $_POST['confirm_password']  ?? '';
    $userType        = sanitizeInput($_POST['user_type']           ?? '');
    $regNumber       = sanitizeInput($_POST['registration_number'] ?? '');
    $department      = sanitizeInput($_POST['department']          ?? '');

    // Required fields
    if (empty($fullName) || empty($email) || empty($password) || empty($confirmPassword)) {
        sendResponse(false, 'Full name, email, password, and confirm password are required');
    }

    // User type whitelist — admin cannot be registered publicly
    if (!in_array($userType, ['student', 'teacher'], true)) {
        sendResponse(false, 'Invalid user type');
    }

    // Passwords match (strictly required — no optional skip)
    if ($password !== $confirmPassword) {
        sendResponse(false, 'Passwords do not match');
    }

    // Name format
    if (strlen($fullName) < 3 || !preg_match("/^[a-zA-Z\s'.,-]+$/", $fullName)) {
        sendResponse(false, 'Name must be at least 3 characters and contain only letters, spaces, and common punctuation');
    }

    // Email format
    if (!isValidEmail($email)) {
        sendResponse(false, 'Invalid email address format');
    }

    // Password strength
    [$isPasswordValid, $passwordMessage] = validatePasswordStrength($password);
    if (!$isPasswordValid) {
        sendResponse(false, $passwordMessage);
    }

    // Department whitelist
    if (empty($department) || !in_array($department, VALID_DEPARTMENTS, true)) {
        sendResponse(false, 'Please select a valid department');
    }

    // Role-specific validation
    if ($userType === 'student') {
        if (empty($regNumber)) {
            sendResponse(false, 'Registration number is required for students');
        }
        if (strlen($regNumber) < 5) {
            sendResponse(false, 'Registration number must be at least 5 characters');
        }
    } else {
        $regNumber = null; // Teachers do not have registration numbers
    }

    /* ========================================
       CREATE USER ACCOUNT
       ======================================== */

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Raw token sent in email; SHA-256 hash stored in DB.
    // A DB leak does not expose valid verification links.
    // verify-email.php must hash the incoming token before querying:
    //   WHERE token = hash('sha256', $incomingToken)
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken); // 64-char hex — fits token CHAR(64)
    $expiresAt = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            "INSERT INTO users
                (full_name, email, password_hash, user_type, department, registration_number, is_verified, is_active)
             VALUES (?, ?, ?, ?, ?, ?, FALSE, TRUE)"
        );

        if (!$stmt) {
            throw new RuntimeException("Database prepare failed: " . $conn->error);
        }

        $stmt->bind_param('ssssss', $fullName, $email, $passwordHash, $userType, $department, $regNumber);

        if (!$stmt->execute()) {
            // DB UNIQUE constraints handle duplicate detection — no pre-check queries needed.
            // Rollback explicitly before sendResponse() to avoid leaving an open transaction.
            if ($stmt->errno === 1062) {
                $conn->rollback();
                if (str_contains($stmt->error, 'email')) {
                    // Neutral message — avoids email enumeration
                    sendResponse(false, 'This email cannot be used for registration');
                }
                if (str_contains($stmt->error, 'registration_number')) {
                    sendResponse(false, 'Registration number already exists');
                }
            }
            throw new RuntimeException("Failed to create account: " . $stmt->error);
        }

        $userId = $stmt->insert_id;
        $stmt->close();

        // Store hashed token in DB
        $stmt = $conn->prepare(
            "INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
        );

        if ($stmt) {
            $stmt->bind_param('iss', $userId, $tokenHash, $expiresAt);
            if (!$stmt->execute()) {
                error_log("Failed to insert verification token for user_id=$userId IP=$clientIp: " . $stmt->error);
            }
            $stmt->close();
        } else {
            error_log("Failed to prepare token insert for user_id=$userId: " . $conn->error);
        }

        // Commit before queuing email — keeps transaction scope tight
        $conn->commit();

    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Registration transaction failed IP=$clientIp: " . $e->getMessage());
        sendResponse(false, 'Registration failed. Please try again.');
    }

    /* ========================================
       QUEUE VERIFICATION EMAIL (outside transaction)
       ======================================== */

    $protocol         = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host             = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $verificationLink = $protocol . '://' . $host . '/verify-email.php?token=' . $rawToken;

    $subject = 'Verify Your Email - PTA Platform';
    $body    = "Hello $fullName,\n\n"
             . "Thank you for registering on the PTA Platform.\n\n"
             . "Please verify your email address by clicking the link below:\n"
             . "$verificationLink\n\n"
             . "This link will expire in 24 hours.\n\n"
             . "If you did not create this account, please ignore this email.\n\n"
             . "Best regards,\nPTA Platform Team";

    queueEmail($conn, $email, $fullName, $subject, $body, 'verification');

    /* ========================================
       SUCCESS RESPONSE
       ======================================== */

    $requireVerification = $settingsAvailable
        ? $settings->get('require_email_verification', true)
        : true;

    $message = $requireVerification
        ? 'Account created successfully! Please check your email to verify your account.'
        : 'Account created successfully! You can now login.';

    sendResponse(true, $message, [
        'user_id'               => $userId,
        'requires_verification' => $requireVerification,
        'redirect'              => 'index.html?success=' . urlencode($message),
    ]);

} catch (Throwable $e) {
    error_log("Fatal registration error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendResponse(false, 'A server error occurred. Please try again or contact support.');
}
?>