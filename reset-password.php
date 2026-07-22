<?php
/* ========================================
   PASSWORD RESET HANDLER
   File: reset-password.php

   GET  → Validate token → Show new-password form
   POST → Validate token + CSRF + inputs → Update password → Redirect to login

   SECURITY:
   1. Raw token arrives in URL; SHA-256 hash looked up in DB.
   2. Token is single-use and expires after 1 hour.
   3. Password is hashed with PASSWORD_DEFAULT (bcrypt).
   4. Session is untouched — user must log in again after reset.
   5. CSRF token embedded in form, validated on POST.
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

set_time_limit(30);

if (!file_exists("config.php")) {
    ob_end_clean();
    die(renderPage('error', 'Configuration error. Please contact support.'));
}
require_once "config.php";
require_once "db-guard.php";

if (!ensureDatabaseConnection($conn)) {
    ob_end_clean();
    die(renderPage('error', 'Database connection failed. Please try again later.'));
}


// ════════════════════════════════════════
// VALIDATE RESET TOKEN (shared by GET and POST)
// ════════════════════════════════════════

$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');

if (empty($rawToken) || strlen($rawToken) > 200) {
    ob_end_clean();
    die(renderPage('error', 'Invalid or missing reset link.'));
}

$tokenHash = hash('sha256', $rawToken);

try {
    $stmt = $conn->prepare(
        "SELECT t.token_id, t.user_id, t.expires_at, t.is_used,
                u.full_name, u.email, u.is_active
         FROM   password_reset_tokens t
         JOIN   users u ON u.user_id = t.user_id
         WHERE  t.token = ?
         LIMIT  1"
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("reset-password: token lookup failed: " . $e->getMessage());
    ob_end_clean();
    die(renderPage('error', 'Something went wrong. Please try again.'));
}

if (!$row) {
    ob_end_clean();
    die(renderPage('error', 'This reset link is invalid or does not exist.'));
}

if (pgBoolGuard($row['is_used'])) {
    ob_end_clean();
    die(renderPage('error', 'This reset link has already been used. Please request a new one.'));
}

if (strtotime($row['expires_at']) < time()) {
    ob_end_clean();
    die(renderPage('expired', 'This reset link has expired. Please request a new one.'));
}

if (!pgBoolGuard($row['is_active'])) {
    ob_end_clean();
    die(renderPage('error', 'This account is inactive. Please contact the administrator.'));
}


// ════════════════════════════════════════
// GET — Show the new-password form
// ════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    echo renderForm($rawToken, $row['full_name']);
    exit;
}


// ════════════════════════════════════════
// POST — Update the password
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
    error_log("reset-password: CSRF validation failed. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    ob_end_clean();
    echo renderForm($rawToken, $row['full_name'], ['Invalid request. Please refresh and try again.']);
    exit;
}

$newPassword     = $_POST['password']         ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// ── Validation ──
$errors = [];

if (strlen($newPassword) < 8) {
    $errors[] = 'Password must be at least 8 characters long.';
}
if (!preg_match('/[A-Z]/', $newPassword)) {
    $errors[] = 'Password must contain at least one uppercase letter.';
}
if (!preg_match('/[a-z]/', $newPassword)) {
    $errors[] = 'Password must contain at least one lowercase letter.';
}
if (!preg_match('/[0-9]/', $newPassword)) {
    $errors[] = 'Password must contain at least one number.';
}
if ($newPassword !== $confirmPassword) {
    $errors[] = 'Passwords do not match.';
}

if (!empty($errors)) {
    ob_end_clean();
    echo renderForm($rawToken, $row['full_name'], $errors);
    exit;
}

// ── Hash & persist ──
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

try {
    $conn->beginTransaction();

    // Update password
    $upd = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    $upd->execute([$newHash, $row['user_id']]);

    // Mark token used
    $tok = $conn->prepare("UPDATE password_reset_tokens SET is_used = TRUE WHERE token_id = ?");
    $tok->execute([$row['token_id']]);

    $conn->commit();

    error_log("Password reset successful for user_id={$row['user_id']} email={$row['email']}");

    ob_end_clean();
    echo renderPage('success',
        'Your password has been reset successfully. You can now log in with your new password.',
        $row['full_name']);
    exit;

} catch (PDOException $e) {
    $conn->rollback();
    error_log("reset-password error: " . $e->getMessage());
    ob_end_clean();
    echo renderPage('error', 'Something went wrong while resetting your password. Please try again.');
    exit;
}


/* ========================================
   RENDER HELPERS
   ======================================== */

function renderForm(string $rawToken, string $name, array $errors = []): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
    $tokenHtml = htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8');
    $nameHtml  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $errorHtml = '';
    if (!empty($errors)) {
        $listItems = implode('', array_map(
            fn($e) => '<li>' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '</li>',
            $errors
        ));
        $errorHtml = '<div style="color:#dc2626;background:#fef2f2;border-left:4px solid #dc2626;'
            . 'padding:10px 14px;border-radius:6px;font-size:14px;margin-bottom:16px;">'
            . '<ul style="margin:0;padding-left:18px;">' . $listItems . '</ul></div>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password – PTA Platform</title>
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
      max-width: 440px; width: 90%; overflow: hidden;
    }
    .header {
      background: linear-gradient(135deg, #1a56db, #1e429f);
      padding: 28px 32px; text-align: center; color: #fff;
    }
    .header h1 { margin: 0; font-size: 20px; font-weight: 700; }
    .header p  { margin: 4px 0 0; font-size: 12px; color: #bfdbfe; }
    .body      { padding: 36px 32px; }
    .body h2   { margin: 0 0 6px; font-size: 20px; font-weight: 700;
                 color: #1e429f; text-align: center; }
    .body .sub { font-size: 14px; color: #6b7280; text-align: center;
                 margin: 0 0 24px; line-height: 1.6; }
    label      { display: block; font-size: 14px; font-weight: 600;
                 color: #374151; margin-bottom: 6px; margin-top: 16px; }
    label:first-of-type { margin-top: 0; }
    input[type="password"] {
      width: 100%; padding: 12px 14px;
      border: 2px solid #d1d5db; border-radius: 8px;
      font-size: 14px; color: #111;
      transition: border-color .2s;
    }
    input[type="password"]:focus { outline: none; border-color: #1a56db; }
    .hint {
      font-size: 12px; color: #9ca3af; margin-top: 5px;
    }
    .strength-bar {
      height: 4px; border-radius: 2px; margin-top: 6px;
      background: #e5e7eb; overflow: hidden;
    }
    .strength-bar-fill {
      height: 100%; width: 0%; border-radius: 2px;
      transition: width .3s, background .3s;
    }
    .strength-label {
      font-size: 11px; color: #9ca3af; margin-top: 3px;
    }
    button[type="submit"] {
      width: 100%; margin-top: 22px;
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
      <h2>🔒 Reset Password</h2>
      <p class="sub">Hi <strong>{$nameHtml}</strong>, enter your new password below.</p>
      {$errorHtml}
      <form method="POST" action="reset-password.php">
        <input type="hidden" name="token" value="{$tokenHtml}">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">

        <label for="password">New Password</label>
        <input type="password" id="password" name="password"
               placeholder="Minimum 8 characters"
               required autocomplete="new-password"
               oninput="checkStrength(this.value)">
        <div class="strength-bar"><div class="strength-bar-fill" id="strengthFill"></div></div>
        <p class="strength-label" id="strengthLabel"></p>
        <p class="hint">Must be at least 8 characters and include uppercase, lowercase, and a number.</p>

        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               placeholder="Re-enter your new password"
               required autocomplete="new-password">

        <button type="submit">Set New Password</button>
      </form>
      <a class="back" href="index.html">← Back to Login</a>
    </div>
  </div>

  <script>
    function checkStrength(pw) {
      var fill  = document.getElementById('strengthFill');
      var label = document.getElementById('strengthLabel');
      if (!pw) { fill.style.width = '0%'; label.textContent = ''; return; }

      var score = 0;
      if (pw.length >= 8)              score++;
      if (/[A-Z]/.test(pw))            score++;
      if (/[a-z]/.test(pw))            score++;
      if (/[0-9]/.test(pw))            score++;
      if (/[^A-Za-z0-9]/.test(pw))     score++;

      var levels = [
        { w: '20%', bg: '#ef4444', text: 'Very weak'  },
        { w: '40%', bg: '#f97316', text: 'Weak'       },
        { w: '60%', bg: '#eab308', text: 'Fair'       },
        { w: '80%', bg: '#22c55e', text: 'Strong'     },
        { w: '100%',bg: '#16a34a', text: 'Very strong'},
      ];
      var lvl = levels[Math.max(0, score - 1)];
      fill.style.width      = lvl.w;
      fill.style.background = lvl.bg;
      label.textContent     = lvl.text;
      label.style.color     = lvl.bg;
    }
  </script>
</body>
</html>
HTML;
}

function renderPage(string $status, string $message, string $name = ''): string {
    $configs = [
        'success' => ['title' => 'Password Reset!',    'icon' => '✅', 'color' => '#16a34a'],
        'expired' => ['title' => 'Link Expired',       'icon' => '⏰', 'color' => '#d97706'],
        'error'   => ['title' => 'Reset Failed',       'icon' => '❌', 'color' => '#dc2626'],
    ];

    $cfg     = $configs[$status] ?? $configs['error'];
    $title   = $cfg['title'];
    $icon    = $cfg['icon'];
    $color   = $cfg['color'];
    $msgHtml = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $nameHtml = $name
        ? '<p style="margin:0 0 12px;font-size:15px;color:#374151;">Hi <strong>'
          . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        : '';

    $actionHtml = ($status === 'expired')
        ? '<a href="forgot-password.php"
              style="display:inline-block;margin-top:16px;padding:10px 28px;
                     background:#1a56db;color:#fff;text-decoration:none;
                     border-radius:6px;font-size:14px;font-weight:600;">
             Request New Link
           </a>'
        : '<a href="index.html"
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
      {$actionHtml}
    </div>
  </div>
</body>
</html>
HTML;
}