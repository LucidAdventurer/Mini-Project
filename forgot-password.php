<?php
/* ========================================
   FORGOT PASSWORD HANDLER
   File: forgot-password.php

   GET  → Show the "enter your email" form
   POST → Validate email, create token, send reset email

   SECURITY:
   1. Raw token emailed; SHA-256 hash stored in DB (mirrors verify-email.php pattern).
   2. Rate-limited: max 3 requests per email per hour.
   3. Always shows a generic success message to prevent user enumeration.
   4. Old unused tokens for the same user are invalidated on new request.
   ======================================== */

ob_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

set_time_limit(60);

if (!file_exists("config.php")) {
    ob_end_clean();
    die(renderPage('error', 'Configuration error. Please contact support.'));
}
require_once "config.php";

// Load Composer autoloader for PHPMailer
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
];
foreach ($autoloadPaths as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        break;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!ensureDatabaseConnection($conn)) {
    ob_end_clean();
    die(renderPage('error', 'Database connection failed. Please try again later.'));
}

// ════════════════════════════════════════
// ENSURE TABLE EXISTS
// ════════════════════════════════════════

$conn->query(
    "CREATE TABLE IF NOT EXISTS password_reset_tokens (
        token_id   INT          PRIMARY KEY AUTO_INCREMENT,
        user_id    INT          NOT NULL,
        token      VARCHAR(64)  UNIQUE NOT NULL COMMENT 'SHA-256 hash of raw token',
        expires_at DATETIME     NOT NULL,
        is_used    BOOLEAN      DEFAULT FALSE,
        request_ip VARCHAR(45),
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        INDEX idx_token    (token),
        INDEX idx_expires  (expires_at),
        INDEX idx_user     (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);


// ════════════════════════════════════════
// HANDLE GET — show form
// ════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    echo renderForm();
    exit;
}


// ════════════════════════════════════════
// HANDLE POST — process request
// ════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    header('Location: index.html');
    exit;
}

// ── CSRF check ──
$sentToken    = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
if ($sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
    error_log("forgot-password: CSRF validation failed. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    ob_end_clean();
    echo renderForm('Invalid request. Please refresh and try again.');
    exit;
}

$email    = trim($_POST['email'] ?? '');
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Basic validation
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ob_end_clean();
    echo renderForm('Please enter a valid email address.');
    exit;
}

// ── Rate limiting: max 3 reset requests per email in 60 minutes ──
$rateStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM   password_reset_tokens t
     JOIN   users u ON u.user_id = t.user_id
     WHERE  u.email = ?
       AND  t.created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)"
);
if ($rateStmt) {
    $rateStmt->bind_param("s", $email);
    $rateStmt->execute();
    $rateRow = $rateStmt->get_result()->fetch_assoc();
    $rateStmt->close();

    if ((int)($rateRow['cnt'] ?? 0) >= 3) {
        error_log("Password reset rate limit hit for email: $email from IP: $clientIp");
        ob_end_clean();
        echo renderPage('sent',
            'If an account with that email exists, a password reset link has been sent. Please check your inbox (and spam folder).');
        exit;
    }
}

// ── Look up user (don't reveal whether email exists) ──
$userStmt = $conn->prepare(
    "SELECT user_id, full_name, is_active FROM users WHERE email = ? LIMIT 1"
);
if (!$userStmt) {
    error_log("forgot-password: prepare failed: " . $conn->error);
    ob_end_clean();
    echo renderPage('error', 'Something went wrong. Please try again.');
    exit;
}
$userStmt->bind_param("s", $email);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

// Always show the same "check your inbox" message
if (!$user || !$user['is_active']) {
    error_log("Password reset requested for unknown/inactive email: $email");
    ob_end_clean();
    echo renderPage('sent',
        'If an account with that email exists, a password reset link has been sent. Please check your inbox (and spam folder).');
    exit;
}

// ── Invalidate old unused tokens for this user ──
$invalidateStmt = $conn->prepare(
    "UPDATE password_reset_tokens
     SET    is_used = TRUE
     WHERE  user_id = ? AND is_used = FALSE"
);
if ($invalidateStmt) {
    $invalidateStmt->bind_param("i", $user['user_id']);
    $invalidateStmt->execute();
    $invalidateStmt->close();
}

// ── Generate secure token ──
$rawToken  = bin2hex(random_bytes(32));           // 64-char hex string, sent in email
$tokenHash = hash('sha256', $rawToken);           // stored in DB
$expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour window

$insertStmt = $conn->prepare(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at, request_ip)
     VALUES (?, ?, ?, ?)"
);
if (!$insertStmt) {
    error_log("forgot-password: insert prepare failed: " . $conn->error);
    ob_end_clean();
    echo renderPage('error', 'Something went wrong. Please try again.');
    exit;
}
$insertStmt->bind_param("isss", $user['user_id'], $tokenHash, $expiresAt, $clientIp);
if (!$insertStmt->execute()) {
    error_log("forgot-password: insert failed: " . $insertStmt->error);
    $insertStmt->close();
    ob_end_clean();
    echo renderPage('error', 'Something went wrong. Please try again.');
    exit;
}
$insertStmt->close();

// ── Build reset link ──
$protocol  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir       = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$resetLink = $protocol . '://' . $host . $dir . '/reset-password.php?token=' . urlencode($rawToken);

// ── Send email via PHPMailer ──
$sent = sendResetEmail($email, $user['full_name'], $resetLink);

if (!$sent) {
    error_log("forgot-password: PHPMailer failed for user_id={$user['user_id']} email=$email");
    // Still show generic success for security — token is saved, user can try again
}

error_log("Password reset token created for user_id={$user['user_id']} email=$email sent=" . ($sent ? 'yes' : 'no'));

ob_end_clean();
echo renderPage('sent',
    'If an account with that email exists, a password reset link has been sent. Please check your inbox (and spam folder).');
exit;


/* ========================================
   SEND RESET EMAIL — PHPMailer / Gmail SMTP
   Reads credentials from env.php, matching
   the same pattern used by register.php and
   resend-verification.php.
   ======================================== */
function sendResetEmail(string $toEmail, string $toName, string $resetLink): bool {
    // Use SMTP_* constants defined in config.php (loaded from env.php)
    if (empty(SMTP_HOST) || empty(SMTP_USER) || empty(SMTP_PASS) || empty(SMTP_FROM)) {
        error_log("sendResetEmail: SMTP credentials missing or incomplete in env.php");
        return false;
    }

    $nameHtml   = htmlspecialchars($toName,    ENT_QUOTES, 'UTF-8');
    $linkHtml   = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
    $expireNote = 'This link will expire in 1 hour.';

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Password Reset</title></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:40px 0;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0"
             style="background:#fff;border-radius:10px;overflow:hidden;
                    box-shadow:0 2px 12px rgba(0,0,0,.1);">
        <tr>
          <td style="background:linear-gradient(135deg,#1a56db,#1e429f);
                     padding:28px 32px;text-align:center;color:#fff;">
            <h1 style="margin:0;font-size:20px;font-weight:700;">PTA Platform</h1>
            <p style="margin:4px 0 0;font-size:12px;color:#bfdbfe;">
              Placement Training &amp; Assessment
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 32px;text-align:center;">
            <span style="font-size:52px;">🔒</span>
            <h2 style="margin:16px 0 8px;font-size:22px;font-weight:700;color:#1e429f;">
              Password Reset Request
            </h2>
            <p style="margin:0 0 12px;font-size:15px;color:#374151;">
              Hi <strong>{$nameHtml}</strong>,
            </p>
            <p style="margin:0 0 20px;font-size:14px;color:#6b7280;line-height:1.6;">
              We received a request to reset your password.
              Click the button below to choose a new one.
            </p>
            <a href="{$linkHtml}"
               style="display:inline-block;padding:12px 32px;
                      background:#1a56db;color:#fff;text-decoration:none;
                      border-radius:6px;font-size:15px;font-weight:600;">
              Reset My Password
            </a>
            <p style="margin:20px 0 0;font-size:12px;color:#9ca3af;">{$expireNote}</p>
            <p style="margin:8px 0 0;font-size:12px;color:#9ca3af;">
              If you did not request a password reset, please ignore this email —
              your password will remain unchanged.
            </p>
            <hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb;">
            <p style="margin:0;font-size:11px;color:#d1d5db;">
              If the button doesn't work, copy and paste this link into your browser:<br>
              <a href="{$linkHtml}" style="color:#1a56db;word-break:break-all;">{$linkHtml}</a>
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $textBody = "Hi {$toName},\n\n"
        . "We received a request to reset your PTA Platform password.\n\n"
        . "Click the link below to reset it (expires in 1 hour):\n"
        . "{$resetLink}\n\n"
        . "If you did not request this, please ignore this email.\n\n"
        . "– PTA Platform Team";

    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Reset Your Password – PTA Platform';
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        return true;

    } catch (PHPMailerException $e) {
        error_log("sendResetEmail PHPMailer error: " . $e->getMessage());
        return false;
    }
}


/* ========================================
   RENDER HELPERS
   ======================================== */
function renderForm(string $error = ''): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');

    $errorHtml = '';
    if ($error !== '') {
        $errorHtml = '<p style="color:#dc2626;background:#fef2f2;border-left:4px solid #dc2626;'
            . 'padding:10px 14px;border-radius:6px;font-size:14px;margin-bottom:16px;">'
            . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password – PTA Platform</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0; padding: 0;
      background: #f4f6f9;
      font-family: Arial, Helvetica, sans-serif;
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh;
    }
    .card {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 12px rgba(0,0,0,.1);
      max-width: 440px;
      width: 90%;
      overflow: hidden;
    }
    .header {
      background: linear-gradient(135deg, #1a56db, #1e429f);
      padding: 28px 32px;
      text-align: center;
      color: #fff;
    }
    .header h1 { margin: 0; font-size: 20px; font-weight: 700; }
    .header p  { margin: 4px 0 0; font-size: 12px; color: #bfdbfe; }
    .body      { padding: 36px 32px; }
    .body h2   { margin: 0 0 8px; font-size: 20px; font-weight: 700; color: #1e429f; text-align:center; }
    .body .sub { font-size: 14px; color: #6b7280; text-align: center; margin: 0 0 24px; line-height: 1.6; }
    label      { display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 6px; }
    input[type="email"] {
      width: 100%; padding: 12px 14px;
      border: 2px solid #d1d5db; border-radius: 8px;
      font-size: 14px; color: #111;
      transition: border-color .2s;
    }
    input[type="email"]:focus { outline: none; border-color: #1a56db; }
    button[type="submit"] {
      width: 100%; margin-top: 18px;
      padding: 13px;
      background: linear-gradient(135deg, #1a56db, #1e429f);
      color: #fff; border: none; border-radius: 8px;
      font-size: 15px; font-weight: 600; cursor: pointer;
      transition: opacity .2s;
    }
    button[type="submit"]:hover { opacity: .9; }
    .back {
      display: block; text-align: center;
      margin-top: 18px; font-size: 13px; color: #6b7280;
      text-decoration: none;
    }
    .back:hover { color: #1a56db; }
  </style>
</head>
<body>
  <div class="card">
    <div class="header">
      <h1>PTA Platform</h1>
      <p>Placement Training &amp; Assessment</p>
    </div>
    <div class="body">
      <h2>🔑 Forgot Password?</h2>
      <p class="sub">
        Enter the email address linked to your account and we'll send you
        a link to reset your password.
      </p>
      {$errorHtml}
      <form method="POST" action="forgot-password.php">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               placeholder="your@institution.edu"
               required autocomplete="email">
        <button type="submit">Send Reset Link</button>
      </form>
      <a class="back" href="index.html">← Back to Login</a>
    </div>
  </div>
</body>
</html>
HTML;
}

function renderPage(string $status, string $message): string {
    $configs = [
        'sent'  => ['title' => 'Check Your Email', 'icon' => '📧', 'color' => '#16a34a'],
        'error' => ['title' => 'Something Went Wrong', 'icon' => '❌', 'color' => '#dc2626'],
    ];

    $cfg     = $configs[$status] ?? $configs['error'];
    $title   = $cfg['title'];
    $icon    = $cfg['icon'];
    $color   = $cfg['color'];
    $msgHtml = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title} – PTA Platform</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0; padding: 0;
      background: #f4f6f9;
      font-family: Arial, Helvetica, sans-serif;
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh;
    }
    .card {
      background: #fff; border-radius: 10px;
      box-shadow: 0 2px 12px rgba(0,0,0,.1);
      max-width: 480px; width: 90%; overflow: hidden;
    }
    .header {
      background: linear-gradient(135deg, #1a56db, #1e429f);
      padding: 28px 32px; text-align: center; color: #fff;
    }
    .header h1 { margin: 0; font-size: 20px; font-weight: 700; }
    .header p  { margin: 4px 0 0; font-size: 12px; color: #bfdbfe; }
    .body      { padding: 36px 32px; text-align: center; }
    .icon      { font-size: 52px; margin-bottom: 16px; display: block; }
    .status    { font-size: 22px; font-weight: 700; color: {$color}; margin: 0 0 12px; }
    .message   { font-size: 14px; color: #6b7280; line-height: 1.6; margin: 0 0 20px; }
    a.btn {
      display: inline-block; padding: 10px 28px;
      background: #1a56db; color: #fff; text-decoration: none;
      border-radius: 6px; font-size: 14px; font-weight: 600;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="header">
      <h1>PTA Platform</h1>
      <p>Placement Training &amp; Assessment</p>
    </div>
    <div class="body">
      <span class="icon">{$icon}</span>
      <h2 class="status">{$title}</h2>
      <p class="message">{$msgHtml}</p>
      <a class="btn" href="index.html">Back to Login</a>
    </div>
  </div>
</body>
</html>
HTML;
}