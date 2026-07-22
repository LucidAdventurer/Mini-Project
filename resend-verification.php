<?php
/* ========================================
   RESEND EMAIL VERIFICATION
   Accepts ?email=... via GET (linked from verify-email.php expired state)
   or POST {email} from a form submission.

   Flow:
     1. Look up user by email
     2. Guard: already verified → redirect to login
     3. Rate-limit: block if a fresh token was issued in last 5 minutes
     4. Invalidate all old tokens for this user
     5. Generate new token, store hash, send email via PHPMailer
     6. Render confirmation page
   ======================================== */

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

set_time_limit(30);

require_once "config.php";
require_once "db-guard.php";

if (!ensureDatabaseConnection($conn)) {
    die(renderPage('error', 'Database connection failed. Please try again later.'));
}

/* ========================================
   HANDLE FORM POST (the resend page itself posts here)
   ======================================== */
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($isPost) {
    // CSRF check on POST
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $sentToken    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$sentToken || $sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
        die(renderPage('error', 'Invalid request. Please refresh and try again.'));
    }

    $email = trim($_POST['email'] ?? '');
} else {
    // GET — email prefilled from verify-email.php expired link
    $email = trim($_GET['email'] ?? '');
}

/* ========================================
   SHOW FORM when no email supplied via GET
   ======================================== */
if (empty($email) && !$isPost) {
    die(renderForm('', ''));
}

/* ========================================
   VALIDATE EMAIL FORMAT
   ======================================== */
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    if ($isPost) {
        die(renderForm($email, 'Please enter a valid email address.'));
    }
    die(renderPage('error', 'Invalid email address.'));
}

/* ========================================
   LOOK UP USER
   ======================================== */
try {
    $stmt = $conn->prepare(
        "SELECT user_id, full_name, email, is_verified
         FROM   users
         WHERE  email = ?
         LIMIT  1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Neutral response — don't reveal whether the email exists
    if (!$user) {
        die(renderPage('sent', 'If that email is registered and unverified, a new link has been sent.'));
    }

    // Already verified — just redirect
    if (pgBoolGuard($user['is_verified'])) {
        die(renderPage('already_verified', 'Your email is already verified. You can log in.', $user['full_name']));
    }

    /* ========================================
       RATE LIMIT — one resend per 5 minutes
       ======================================== */
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS recent
         FROM   email_verification_tokens
         WHERE  user_id    = ?
           AND  is_used    = FALSE
           AND  created_at > NOW() - INTERVAL '5 minutes'"
    );
    $stmt->execute([$user['user_id']]);
    $rl = $stmt->fetch(PDO::FETCH_ASSOC);

    if ((int) $rl['recent'] > 0) {
        die(renderPage('rate_limit',
            'A verification email was sent recently. Please wait 5 minutes before requesting another.',
            $user['full_name']));
    }

    /* ========================================
       INVALIDATE OLD TOKENS
       ======================================== */
    $stmt = $conn->prepare(
        "UPDATE email_verification_tokens SET is_used = TRUE WHERE user_id = ? AND is_used = FALSE"
    );
    $stmt->execute([$user['user_id']]);

    /* ========================================
       GENERATE NEW TOKEN
       ======================================== */
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
    );
    $stmt->execute([$user['user_id'], $tokenHash, $expiresAt]);

    /* ========================================
       QUEUE EMAIL
       ======================================== */
    $protocol         = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host             = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $verificationLink = $protocol . '://' . $host . '/verify-email.php?token=' . $rawToken;

    sendVerificationEmail($user['email'], $user['full_name'], $verificationLink);

    error_log("Resent verification email to user_id={$user['user_id']} ({$user['email']})");

    die(renderPage('sent', 'A new verification link has been sent to your email. It expires in 24 hours.',
                    $user['full_name']));

} catch (Exception $e) {
    error_log("Resend verification error: " . $e->getMessage());
    die(renderPage('error', 'Something went wrong. Please try again or contact support.'));
}

/* ========================================
   HELPER: send verification email via PHPMailer
   Self-contained — no dependency on register.php
   ======================================== */
function sendVerificationEmail(
    string $toEmail,
    string $toName,
    string $verificationLink
): bool {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log("PHPMailer not found — run: composer require phpmailer/phpmailer");
        return false;
    }
    require_once $autoload;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);

        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Email - PTA Platform';

        $mail->AltBody = "Hello $toName,\n\n"
                       . "You requested a new email verification link.\n\n"
                       . "Please verify your email address by visiting:\n"
                       . "$verificationLink\n\n"
                       . "This link expires in 24 hours.\n\n"
                       . "If you did not request this, ignore this email.\n\n"
                       . "PTA Platform Team";

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
              You requested a new verification link. Click below to verify your email address.
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
              This link expires in <strong>24 hours</strong>. If you did not request this, ignore this email.
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
        error_log("PHPMailer resend failed to $toEmail: " . $e->getMessage());
        return false;
    }
}

/* ========================================
   RENDER: input form (shown when email not pre-supplied)
   ======================================== */
function renderForm(string $email = '', string $error = ''): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
    $emailVal  = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $errorHtml = $error
        ? '<p style="color:#dc2626;font-size:13px;margin:0 0 12px;">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resend Verification – PTA Platform</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0; padding: 0; background: #f4f6f9;
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
      background: linear-gradient(135deg,#1a56db,#1e429f);
      padding: 28px 32px; text-align: center; color: #fff;
    }
    .header h1 { margin:0; font-size:20px; font-weight:700; }
    .header p  { margin:4px 0 0; font-size:12px; color:#bfdbfe; }
    .body      { padding: 36px 32px; }
    h2         { margin:0 0 8px; font-size:20px; color:#111827; }
    .sub       { font-size:14px; color:#6b7280; margin:0 0 24px; }
    label      { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px; }
    input[type="email"] {
      width:100%; padding:10px 14px; border:1px solid #d1d5db;
      border-radius:6px; font-size:14px; outline:none;
    }
    input[type="email"]:focus { border-color:#1a56db; box-shadow:0 0 0 3px rgba(26,86,219,.15); }
    button {
      margin-top:16px; width:100%; padding:11px;
      background:#1a56db; color:#fff; border:none;
      border-radius:6px; font-size:14px; font-weight:600; cursor:pointer;
    }
    button:hover { background:#1e429f; }
    .back { display:block; text-align:center; margin-top:16px; font-size:13px; color:#6b7280; text-decoration:none; }
    .back:hover { color:#1a56db; }
  </style>
</head>
<body>
  <div class="card">
    <div class="header">
      <h1>PTA Platform</h1>
      <p>Placement Training &amp; Assessment</p>
    </div>
    <div class="body">
      <h2>Resend Verification</h2>
      <p class="sub">Enter your registered email and we'll send a new verification link.</p>
      {$errorHtml}
      <form method="POST" action="resend-verification.php">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" value="{$emailVal}" required placeholder="you@example.com">
        <button type="submit">Send Verification Link</button>
      </form>
      <a href="login.html" class="back">← Back to Login</a>
    </div>
  </div>
</body>
</html>
HTML;
}

/* ========================================
   RENDER: status pages
   ======================================== */
function renderPage(string $status, string $message, string $name = ''): string {

    $titles = [
        'sent'             => 'Email Sent',
        'already_verified' => 'Already Verified',
        'rate_limit'       => 'Too Many Requests',
        'error'            => 'Something Went Wrong',
    ];

    $icons = [
        'sent'             => '📧',
        'already_verified' => 'ℹ️',
        'rate_limit'       => '⏳',
        'error'            => '❌',
    ];

    $colors = [
        'sent'             => '#16a34a',
        'already_verified' => '#1a56db',
        'rate_limit'       => '#d97706',
        'error'            => '#dc2626',
    ];

    $title    = $titles[$status]  ?? 'Notice';
    $icon     = $icons[$status]   ?? 'ℹ️';
    $color    = $colors[$status]  ?? '#374151';
    $msgHtml  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $nameHtml = $name
        ? '<p style="margin:0 0 12px;font-size:15px;color:#374151;">Hi <strong>'
          . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        : '';

    $loginLink = '<a href="login.html"
                     style="display:inline-block;margin-top:16px;padding:10px 28px;
                            background:#1a56db;color:#fff;text-decoration:none;
                            border-radius:6px;font-size:14px;font-weight:600;">
                    Go to Login
                  </a>';

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
      margin: 0; padding: 0; background: #f4f6f9;
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
      background: linear-gradient(135deg,#1a56db,#1e429f);
      padding: 28px 32px; text-align: center; color: #fff;
    }
    .header h1 { margin:0; font-size:20px; font-weight:700; }
    .header p  { margin:4px 0 0; font-size:12px; color:#bfdbfe; }
    .body      { padding: 36px 32px; text-align: center; }
    .icon      { font-size: 52px; margin-bottom: 16px; display:block; }
    .status    { font-size: 22px; font-weight: 700; color:{$color}; margin: 0 0 12px; }
    .message   { font-size: 14px; color: #6b7280; line-height: 1.6; margin: 0 0 8px; }
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
      {$nameHtml}
      <p class="message">{$msgHtml}</p>
      {$loginLink}
    </div>
  </div>
</body>
</html>
HTML;
}