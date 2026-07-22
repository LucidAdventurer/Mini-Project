<?php
/* ========================================
   EMAIL VERIFICATION HANDLER
   Validates token → marks user as verified → redirects

   FIX: Incoming raw token is SHA-256 hashed before DB lookup
        because register.php stores hash('sha256', $rawToken).
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

if (!file_exists("config.php")) {
    die(renderPage('error', 'Configuration error. Please contact support.'));
}
require_once "config.php";
require_once "db-guard.php";

if (!ensureDatabaseConnection($conn)) {
    die(renderPage('error', 'Database connection failed. Please try again later.'));
}

/* ========================================
   PROCESS TOKEN
   ======================================== */
$rawToken = $_GET['token'] ?? '';
$rawToken = trim($rawToken);

if (empty($rawToken) || strlen($rawToken) > 200) {
    die(renderPage('error', 'Invalid verification link.'));
}

// register.php stores hash('sha256', $rawToken) — hash it before querying
$tokenHash = hash('sha256', $rawToken);

try {
    // 1. Look up the hashed token
    $stmt = $conn->prepare(
        "SELECT t.token_id, t.user_id, t.expires_at, t.is_used,
                u.full_name, u.email, u.is_verified
         FROM   email_verification_tokens t
         JOIN   users u ON u.user_id = t.user_id
         WHERE  t.token = ?
         LIMIT  1"
    );
    if (!$stmt) {
        throw new Exception("DB prepare failed");
    }
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        die(renderPage('error', 'Verification link is invalid or does not exist.'));
    }

    // 2. Already verified?
    if (pgBoolGuard($row['is_verified'])) {
        die(renderPage('already_verified', 'Your email is already verified. You can log in.', $row['full_name']));
    }

    // 3. Token already used?
    if (pgBoolGuard($row['is_used'])) {
        die(renderPage('error', 'This verification link has already been used. Please request a new one.'));
    }

    // 4. Expired?
    if (strtotime($row['expires_at']) < time()) {
        die(renderPage('expired', 'Your verification link has expired. Please request a new one.',
                        $row['full_name'], $row['email']));
    }

    // 5. Everything OK — verify the user
    $conn->beginTransaction();

    $upd = $conn->prepare(
        "UPDATE users SET is_verified = TRUE WHERE user_id = ?"
    );
    if (!$upd) throw new Exception("DB prepare (update users) failed");
    $upd->execute([$row['user_id']]);

    $tok = $conn->prepare(
        "UPDATE email_verification_tokens SET is_used = TRUE WHERE token_id = ?"
    );
    if (!$tok) throw new Exception("DB prepare (update token) failed");
    $tok->execute([$row['token_id']]);

    $conn->commit();

    error_log("Email verified for user_id={$row['user_id']} ({$row['email']})");

    die(renderPage('success', 'Your email has been verified successfully! You can now log in.', $row['full_name']));

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Verification error: " . $e->getMessage());
    die(renderPage('error', 'Something went wrong. Please try again or contact support.'));
}

/* ========================================
   RENDER HTML RESPONSE
   ======================================== */
function renderPage($status, $message, $name = '', $email = '') {

    $titles = [
        'success'          => 'Email Verified',
        'already_verified' => 'Already Verified',
        'expired'          => 'Link Expired',
        'error'            => 'Verification Failed',
    ];

    $icons = [
        'success'          => '✅',
        'already_verified' => 'ℹ️',
        'expired'          => '⏰',
        'error'            => '❌',
    ];

    $colors = [
        'success'          => '#16a34a',
        'already_verified' => '#1a56db',
        'expired'          => '#d97706',
        'error'            => '#dc2626',
    ];

    $title    = $titles[$status]  ?? 'Verification';
    $icon     = $icons[$status]   ?? '❓';
    $color    = $colors[$status]  ?? '#374151';
    $msgHtml  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $nameHtml = $name
        ? '<p style="margin:0 0 12px;font-size:15px;color:#374151;">Hi <strong>'
          . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        : '';

    $resendHtml = '';
    if ($status === 'expired' && !empty($email)) {
        $enc        = urlencode($email);
        $resendHtml = '<a href="resend-verification.php?email=' . $enc . '"
                          style="display:inline-block;margin-top:16px;padding:10px 28px;
                                 background:#1a56db;color:#fff;text-decoration:none;
                                 border-radius:6px;font-size:14px;font-weight:600;">
                         Request New Link
                       </a>';
    }

    $loginLink = '<a href="login.html"
                     style="display:inline-block;margin-top:16px;padding:10px 28px;
                            background:#1a56db;color:#fff;text-decoration:none;
                            border-radius:6px;font-size:14px;font-weight:600;">
                    Go to Login
                  </a>';

    $action = ($status === 'expired') ? $resendHtml : $loginLink;

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
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 12px rgba(0,0,0,.1);
      max-width: 480px;
      width: 90%;
      overflow: hidden;
    }
    .header {
      background: linear-gradient(135deg,#1a56db,#1e429f);
      padding: 28px 32px;
      text-align: center;
      color: #fff;
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
      {$action}
    </div>
  </div>
</body>
</html>
HTML;
}