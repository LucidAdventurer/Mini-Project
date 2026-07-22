<?php
/* ========================================
   PRODUCTION REGISTRATION HANDLER v3.2

   CHANGES FROM v3.1:
   - queueEmail() replaced with sendVerificationEmail() via PHPMailer
   - Email is sent immediately after transaction commits (no queue/cron needed)
   - PHPMailer config lives in SMTP_* constants — fill these in before deploying
   - Email send failure is non-fatal: user account is still created, error is logged
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
   SMTP CONFIGURATION
   Fill these in with your mail provider's credentials.

   Gmail example:
     SMTP_HOST = 'smtp.gmail.com'
     SMTP_PORT = 587
     SMTP_USER = 'you@gmail.com'
     SMTP_PASS = 'your-app-password'   ← Gmail requires an App Password, not your login password
     SMTP_FROM = 'you@gmail.com'

   Outlook example:
     SMTP_HOST = 'smtp.office365.com'
     SMTP_PORT = 587

   Mailgun example:
     SMTP_HOST = 'smtp.mailgun.org'
     SMTP_PORT = 587
     SMTP_USER = 'postmaster@mg.yourdomain.com'
     SMTP_PASS = 'your-mailgun-smtp-password'
   ======================================== */
// SMTP constants are defined in config.php via env.php — do not redefine here.

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

function sanitizeInput(string $data): string {
    return trim($data);
}

function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

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
 * Send the verification email directly via PHPMailer.
 * Returns true on success, false on failure (non-fatal — account is already created).
 *
 * Requires PHPMailer:
 *   composer require phpmailer/phpmailer
 * Then ensure vendor/autoload.php is included before this file,
 * OR add the require_once line below and adjust the path.
 */
function sendVerificationEmail(
    string $toEmail,
    string $toName,
    string $verificationLink
): bool {
    // Adjust path if your vendor folder is elsewhere
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log("PHPMailer not found — run: composer require phpmailer/phpmailer");
        return false;
    }
    require_once $autoload;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true); // true = throw exceptions

        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls'; // use 'ssl' for port 465
        $mail->Port       = SMTP_PORT;

        // Sender & recipient
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Email - PTA Platform';

        // Plain-text fallback (shown in email clients that block HTML)
        $mail->AltBody = "Hello $toName,\n\n"
                       . "Thank you for registering on the PTA Platform.\n\n"
                       . "Please verify your email address by visiting:\n"
                       . "$verificationLink\n\n"
                       . "This link expires in 24 hours.\n\n"
                       . "If you did not create this account, ignore this email.\n\n"
                       . "PTA Platform Team";

        // HTML body
        $nameEsc = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $linkEsc = htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8');
        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="padding:40px 16px;">
      <table width="480" cellpadding="0" cellspacing="0"
             style="background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.1);overflow:hidden;">
        <tr>
          <td style="background:linear-gradient(135deg,#1a56db,#1e429f);padding:28px 32px;text-align:center;">
            <h1 style="margin:0;color:#fff;font-size:20px;">PTA Platform</h1>
            <p style="margin:4px 0 0;color:#bfdbfe;font-size:12px;">Placement Training &amp; Assessment</p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 32px;">
            <p style="margin:0 0 8px;font-size:15px;color:#374151;">Hi <strong>{$nameEsc}</strong>,</p>
            <p style="margin:0 0 16px;font-size:14px;color:#6b7280;line-height:1.6;">
              Thank you for registering on the PTA Platform. Please verify your email address to activate your account.
            </p>
            <p style="text-align:center;margin:24px 0;">
              <a href="{$linkEsc}"
                 style="display:inline-block;padding:12px 32px;background:#1a56db;color:#fff;
                        text-decoration:none;border-radius:6px;font-size:15px;font-weight:600;">
                Verify Email Address
              </a>
            </p>
            <p style="margin:0 0 8px;font-size:13px;color:#9ca3af;">
              Or copy this link into your browser:
            </p>
            <p style="margin:0 0 24px;font-size:12px;color:#6b7280;word-break:break-all;">
              {$linkEsc}
            </p>
            <p style="margin:0;font-size:13px;color:#9ca3af;">
              This link expires in <strong>24 hours</strong>. If you did not create this account, ignore this email.
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:16px 32px;background:#f9fafb;text-align:center;
                     font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb;">
            © PTA Platform. This is an automated message — please do not reply.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $mail->send();
        return true;

    } catch (Throwable $e) {
        error_log("PHPMailer send failed to $toEmail: " . $e->getMessage());
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

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

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

    $sentToken    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!$sentToken || $sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
        error_log("CSRF validation failed on register.php. IP=$clientIp");
        sendResponse(false, 'Invalid request. Please refresh the page and try again.');
    }

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
    $password        = $_POST['password']         ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role            = sanitizeInput($_POST['role']                ?? '');
    $regNumber       = sanitizeInput($_POST['registration_number'] ?? '');
    $department      = sanitizeInput($_POST['department']          ?? '');

    if (empty($fullName) || empty($email) || empty($password) || empty($confirmPassword)) {
        sendResponse(false, 'Full name, email, password, and confirm password are required');
    }

    if (!in_array($role, ['student', 'teacher'], true)) {
        sendResponse(false, 'Invalid user role');
    }

    if ($password !== $confirmPassword) {
        sendResponse(false, 'Passwords do not match');
    }

    if (strlen($fullName) < 3 || !preg_match("/^[a-zA-Z\s'.,-]+$/", $fullName)) {
        sendResponse(false, 'Name must be at least 3 characters and contain only letters, spaces, and common punctuation');
    }

    if (!isValidEmail($email)) {
        sendResponse(false, 'Invalid email address format');
    }

    [$isPasswordValid, $passwordMessage] = validatePasswordStrength($password);
    if (!$isPasswordValid) {
        sendResponse(false, $passwordMessage);
    }

    if (empty($department) || !in_array($department, VALID_DEPARTMENTS, true)) {
        sendResponse(false, 'Please select a valid department');
    }

    if ($role === 'student') {
        if (empty($regNumber)) {
            sendResponse(false, 'Registration number is required for students');
        }
        if (strlen($regNumber) < 5) {
            sendResponse(false, 'Registration number must be at least 5 characters');
        }
    } else {
        $regNumber = null;
    }

    /* ========================================
       CREATE USER ACCOUNT
       ======================================== */

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Raw token sent in email; SHA-256 hash stored in DB.
    // verify-email.php hashes the incoming token before querying.
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

    $conn->beginTransaction();

    try {
        $stmt = $conn->prepare(
            "INSERT INTO users
                (full_name, email, password_hash, role, department, registration_number, is_verified, is_active)
             VALUES (?, ?, ?, ?, ?, ?, FALSE, TRUE)"
        );
        $stmt->execute([$fullName, $email, $passwordHash, $role, $department, $regNumber]);

        $userId = $conn->lastInsertId();

        $stmt = $conn->prepare(
            "INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
        );
        try {
            $stmt->execute([$userId, $tokenHash, $expiresAt]);
        } catch (PDOException $e) {
            error_log("Failed to insert verification token for user_id=$userId IP=$clientIp: " . $e->getMessage());
        }

        $conn->commit();

    } catch (PDOException $e) {
        $conn->rollback();

        // Postgres unique_violation SQLSTATE (replaces mysqli's errno === 1062 check)
        if ($e->getCode() === '23505') {
            if (str_contains($e->getMessage(), 'email')) {
                sendResponse(false, 'This email cannot be used for registration');
            }
            if (str_contains($e->getMessage(), 'registration_number')) {
                sendResponse(false, 'Registration number already exists');
            }
        }

        error_log("Registration transaction failed IP=$clientIp: " . $e->getMessage());
        sendResponse(false, 'Registration failed. Please try again.');
    }

    /* ========================================
       SEND VERIFICATION EMAIL (outside transaction)
       ======================================== */

    $protocol         = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host             = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir        = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $verificationLink = $protocol . '://' . $host . $scriptDir . '/verify-email.php?token=' . $rawToken;

    $emailSent = sendVerificationEmail($email, $fullName, $verificationLink);

    if (!$emailSent) {
        // Non-fatal — account is created. Log it and carry on.
        error_log("Verification email failed to send for user_id=$userId ($email) IP=$clientIp");
    }

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
        'redirect'              => 'login.html?success=' . urlencode($message),
    ]);

} catch (Throwable $e) {
    error_log("Fatal registration error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendResponse(false, 'A server error occurred. Please try again or contact support.');
}
?>