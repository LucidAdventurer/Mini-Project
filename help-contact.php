<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/config.php'; // provides $conn

header('Content-Type: application/json');

// ── Get POST data ──
$name       = trim($_POST['name']       ?? '');
$email      = trim($_POST['email']      ?? '');
$issueType  = trim($_POST['issue_type'] ?? '');
$message    = trim($_POST['message']    ?? '');

// ── Basic validation ──
if (!$name || !$email || !$message) {
    echo json_encode(['success' => false, 'error' => 'Please fill in all required fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
    exit;
}

// ── Verify email exists in registered users ──
$stmt = $conn->prepare("SELECT user_id, full_name, role FROM users WHERE email = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
    exit;
}
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'This email is not registered on PREPAURA. Please use your registered account email.']);
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// ── Determine theme based on role ──
$roleLabel = ucfirst($user['role'] ?? 'user');
$isTeacher = ($user['role'] === 'teacher');

if ($isTeacher) {
    $headerBg     = 'linear-gradient(135deg, #0d0a14 0%, #261d35 60%, #3b1f6e 100%)';
    $accentColor  = '#7c3aed';
    $accentLight  = '#c084fc';
    $roleBg       = '#ede9f6';
    $roleColor    = '#7c3aed';
    $borderColor  = 'rgba(124,58,237,0.2)';
    $footerBg     = '#f7f5fb';
    $labelColor   = '#8b7fa8';
    $portal       = 'Teacher Portal';
    $portalIcon   = '👨‍🏫';
} else {
    $headerBg     = 'linear-gradient(135deg, #1a3a52 0%, #1e5276 60%, #1a6fa0 100%)';
    $accentColor  = '#0ea5e9';
    $accentLight  = '#38bdf8';
    $roleBg       = '#e0f2fe';
    $roleColor    = '#0369a1';
    $borderColor  = 'rgba(14,165,233,0.2)';
    $footerBg     = '#f0f9ff';
    $labelColor   = '#94a3b8';
    $portal       = 'Student Portal';
    $portalIcon   = '🎓';
}

// ── Gmail credentials ──
$gmailUser    = 'tempminiprojects6@gmail.com';
$gmailAppPass = 'yotsmomqohjyoxsk';

// ── Send mail ──
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $gmailUser;
    $mail->Password   = $gmailAppPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom($gmailUser, 'PREPAURA Help & Support');
    $mail->addAddress($gmailUser, 'PREPAURA Support');
    $mail->addReplyTo($email, $name);

    $mail->isHTML(true);
    $mail->Subject = "[{$roleLabel}] Support Request: {$issueType} — from {$name}";
    $mail->Body = "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.15);'>

        <!-- HEADER -->
        <div style='background:{$headerBg};padding:28px 32px;'>
            <div style='display:flex;align-items:center;gap:10px;margin-bottom:6px;'>
                <span style='font-size:22px;'>🛟</span>
                <span style='font-size:20px;font-weight:800;color:white;letter-spacing:0.03em;'>New Support Request</span>
            </div>
            <p style='color:rgba(255,255,255,0.6);margin:0;font-size:13px;'>PREPAURA Help &amp; Support</p>
            <!-- Portal badge -->
            <div style='margin-top:14px;display:inline-block;padding:4px 14px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:20px;font-size:12px;font-weight:600;color:rgba(255,255,255,0.85);letter-spacing:0.04em;text-transform:uppercase;'>
                {$portalIcon} {$portal}
            </div>
        </div>

        <!-- BODY -->
        <div style='background:#ffffff;padding:28px 32px;border-left:1px solid {$borderColor};border-right:1px solid {$borderColor};'>
            <table style='width:100%;border-collapse:collapse;font-size:14px;'>
                <tr>
                    <td style='padding:12px 0;color:{$labelColor};font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;width:120px;border-bottom:1px solid #f1f1f1;'>Name</td>
                    <td style='padding:12px 0;color:#1a1425;font-weight:600;border-bottom:1px solid #f1f1f1;'>" . htmlspecialchars($name) . "</td>
                </tr>
                <tr>
                    <td style='padding:12px 0;color:{$labelColor};font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid #f1f1f1;'>Email</td>
                    <td style='padding:12px 0;border-bottom:1px solid #f1f1f1;'>
                        <a href='mailto:" . htmlspecialchars($email) . "' style='color:{$accentColor};text-decoration:none;font-weight:500;'>" . htmlspecialchars($email) . "</a>
                    </td>
                </tr>
                <tr>
                    <td style='padding:12px 0;color:{$labelColor};font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid #f1f1f1;'>Role</td>
                    <td style='padding:12px 0;border-bottom:1px solid #f1f1f1;'>
                        <span style='padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;background:{$roleBg};color:{$roleColor};letter-spacing:0.04em;text-transform:uppercase;'>{$roleLabel}</span>
                    </td>
                </tr>
                <tr>
                    <td style='padding:12px 0;color:{$labelColor};font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid #f1f1f1;'>Issue Type</td>
                    <td style='padding:12px 0;color:#1a1425;border-bottom:1px solid #f1f1f1;'>" . htmlspecialchars($issueType ?: 'Not specified') . "</td>
                </tr>
                <tr>
                    <td style='padding:12px 0;color:{$labelColor};font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;vertical-align:top;'>Message</td>
                    <td style='padding:12px 0;color:#1a1425;line-height:1.7;'>" . nl2br(htmlspecialchars($message)) . "</td>
                </tr>
            </table>
        </div>

        <!-- FOOTER -->
        <div style='background:{$footerBg};padding:16px 32px;border:1px solid {$borderColor};border-top:3px solid {$accentColor};border-radius:0 0 16px 16px;text-align:center;'>
            <p style='margin:0;font-size:12px;color:{$labelColor};'>
                Sent from <strong style='color:{$accentColor};'>PREPAURA</strong> Help &amp; Support &nbsp;•&nbsp; Reply directly to respond to {$name}
            </p>
        </div>

    </div>
    ";
    $mail->AltBody = "[$roleLabel] Support Request\nName: $name\nEmail: $email\nIssue Type: $issueType\nMessage: $message";

    $mail->send();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Mail could not be sent. Error: ' . $mail->ErrorInfo]);
}
?>
