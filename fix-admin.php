<?php
/**
 * PTA — Admin Account Repair Tool
 * Place this file in your project root (e.g. localhost/pta/Mini-Project/fix-admin.php)
 * Run it ONCE in the browser, then DELETE it immediately.
 */

// ── Load DB connection ──
require_once __DIR__ . '/config.php';

$action  = $_POST['action']  ?? '';
$message = '';
$error   = '';

// ── Handle form submissions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Fix 1: Verify all admin accounts
    // Column is now `role` (enum: 'admin','teacher','student'), not `user_type`
    if ($action === 'fix_verify') {
        $stmt = $conn->prepare("UPDATE users SET is_verified=1, is_active=1 WHERE role='admin'");
        if ($stmt && $stmt->execute()) {
            $message = "✅ All admin accounts set to verified + active. ({$stmt->affected_rows} row(s) updated)";
            $stmt->close();
        } else {
            $error = "❌ Update failed: " . $conn->error;
        }
    }

    // Fix 2: Create remember_tokens table
    // DDL matches new schema exactly: PK is `id`, selector/token_hash/expires_at nullable,
    // no created_at column, collation utf8mb4_general_ci
    if ($action === 'create_table') {
        $sql = "CREATE TABLE IF NOT EXISTS remember_tokens (
            id         INT          NOT NULL AUTO_INCREMENT,
            user_id    INT          NOT NULL,
            selector   VARCHAR(24)  DEFAULT NULL,
            token_hash VARCHAR(64)  DEFAULT NULL,
            expires_at DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_selector (selector),
            KEY idx_user (user_id),
            CONSTRAINT remember_tokens_ibfk_1
                FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if ($conn->query($sql)) {
            $message = "✅ remember_tokens table created (or already existed).";
        } else {
            $error = "❌ Table creation failed: " . $conn->error;
        }
    }

    // Fix 3: Create or reset admin account
    // Column is now `role`, not `user_type`
    if ($action === 'create_admin') {
        $email    = trim($_POST['email']    ?? '');
        $name     = trim($_POST['name']     ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$email || !$name || !$password) {
            $error = "All fields required.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO users (full_name, email, password_hash, role, is_verified, is_active)
                 VALUES (?, ?, ?, 'admin', 1, 1)
                 ON DUPLICATE KEY UPDATE
                     password_hash = VALUES(password_hash),
                     is_verified   = 1,
                     is_active     = 1"
            );
            if ($stmt) {
                $stmt->bind_param("sss", $name, $email, $hash);
                $stmt->execute();
                $message = "✅ Admin account saved. You can now log in with: <strong>" . htmlspecialchars($email) . "</strong>";
                $stmt->close();
            } else {
                $error = "❌ " . $conn->error;
            }
        }
    }
}

// ── Fetch current admin accounts ──
// `role` replaces `user_type`; `last_login` still exists
$admins = [];
$res = $conn->query("SELECT user_id, full_name, email, is_verified, is_active, last_login FROM users WHERE role='admin'");
if ($res) { while ($r = $res->fetch_assoc()) $admins[] = $r; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PTA — Admin Repair Tool</title>
<style>
  body { font-family: Arial, sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; background: #0f172a; color: #e2e8f0; }
  h1   { color: #60a5fa; }
  h2   { color: #94a3b8; font-size: 1rem; margin-top: 2rem; }
  .card { background: #1e293b; border-radius: 8px; padding: 20px; margin: 16px 0; }
  .ok  { color: #4ade80; background: #052e16; padding: 10px; border-radius: 6px; }
  .err { color: #f87171; background: #1f0606; padding: 10px; border-radius: 6px; }
  input[type=text],input[type=email],input[type=password] {
    width: 100%; padding: 8px; margin: 6px 0 12px; background: #0f172a; border: 1px solid #334155;
    color: #e2e8f0; border-radius: 4px; box-sizing: border-box; }
  button { background: #2563eb; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; }
  button:hover { background: #1d4ed8; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  th,td { text-align: left; padding: 8px; border-bottom: 1px solid #334155; font-size: 0.85rem; }
  th { color: #94a3b8; }
  .badge-ok  { color: #4ade80; } .badge-no { color: #f87171; }
  .warn { background: #451a03; color: #fbbf24; padding: 10px; border-radius: 6px; margin-top: 20px; }
</style>
</head>
<body>
<h1>🔧 PTA Admin Repair Tool</h1>
<?php if ($message): ?><div class="ok"><?= $message ?></div><?php endif; ?>
<?php if ($error):   ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Current admin accounts -->
<div class="card">
  <h2>Current Admin Accounts</h2>
  <?php if (empty($admins)): ?>
    <p style="color:#f87171">No admin accounts found in the database.</p>
  <?php else: ?>
  <table>
    <tr><th>ID</th><th>Name</th><th>Email</th><th>Verified</th><th>Active</th><th>Last Login</th></tr>
    <?php foreach ($admins as $a): ?>
    <tr>
      <td><?= $a['user_id'] ?></td>
      <td><?= htmlspecialchars($a['full_name']) ?></td>
      <td><?= htmlspecialchars($a['email']) ?></td>
      <td class="<?= $a['is_verified'] ? 'badge-ok' : 'badge-no' ?>"><?= $a['is_verified'] ? '✅ Yes' : '❌ No' ?></td>
      <td class="<?= $a['is_active']   ? 'badge-ok' : 'badge-no' ?>"><?= $a['is_active']   ? '✅ Yes' : '❌ No' ?></td>
      <td><?= $a['last_login'] ?? 'Never' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<!-- Fix 1: Verify admin accounts -->
<?php if (!empty($admins)): ?>
<div class="card">
  <h2>Fix 1 — Mark all admin accounts as verified &amp; active</h2>
  <p>Run this if your admin has <code>is_verified = 0</code> or <code>is_active = 0</code>.</p>
  <form method="POST">
    <input type="hidden" name="action" value="fix_verify">
    <button type="submit">✅ Set is_verified=1 &amp; is_active=1 for all admins</button>
  </form>
</div>
<?php endif; ?>

<!-- Fix 2: Create remember_tokens table -->
<div class="card">
  <h2>Fix 2 — Create missing <code>remember_tokens</code> table</h2>
  <p>Required by <code>login.php</code> for "remember me" sessions. Safe to run if the table already exists.</p>
  <form method="POST">
    <input type="hidden" name="action" value="create_table">
    <button type="submit">🗄️ Create remember_tokens table</button>
  </form>
</div>

<!-- Fix 3: Create/update admin account -->
<div class="card">
  <h2>Fix 3 — Create or reset an admin account</h2>
  <p>Use this if you need to create a new admin or reset an existing password.</p>
  <form method="POST">
    <input type="hidden" name="action" value="create_admin">
    <label>Full Name</label>
    <input type="text" name="name" value="Super Admin" required>
    <label>Email</label>
    <input type="email" name="email" value="adminone@gmail.com" required>
    <label>New Password (min 8 chars)</label>
    <input type="password" name="password" placeholder="Enter a strong password" required>
    <button type="submit">💾 Save Admin Account</button>
  </form>
</div>

<div class="warn">
  ⚠️ <strong>Delete this file immediately after use!</strong><br>
  <code>fix-admin.php</code> has no authentication and must not remain on your server.
</div>
</body>
</html>