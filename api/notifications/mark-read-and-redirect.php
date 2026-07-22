<?php
// ============================================================
// api/notifications/mark-read-and-redirect.php
//
// Marks a notification as read and returns the redirect URL
// based on the notification's related_entity_type and related_entity_id.
//
// POST JSON { notification_id: int }
// Returns   { success: bool, redirect_url?: string, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'student'); // students click notifications
$userId      = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body           = json_decode(file_get_contents('php://input'), true);
$notificationId = (int)($body['notification_id'] ?? 0);

if ($notificationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid notification ID.']);
    exit;
}

// ── Fetch notification (must belong to this user) ──
$r = safePreparedQuery($conn,
    "SELECT notification_id, related_entity_type, related_entity_id, type
     FROM notifications
     WHERE notification_id = ? AND user_id = ?",
    "ii", [$notificationId, $userId]
);

$notif = ($r['success'] && $r['result']) ? $r['result']->fetch_assoc() : null;
if ($r['result']) {
    $r['result']->free();
}

if (!$notif) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Notification not found.']);
    exit;
}

$entityType = $notif['related_entity_type'];
$entityId   = (int) $notif['related_entity_id'];

// ── Mark as read ──
safePreparedQuery($conn,
    "UPDATE notifications SET is_read = true WHERE notification_id = ? AND user_id = ?",
    "ii", [$notificationId, $userId]
);

// ── Build redirect URL ──
// ⚠️  UPDATE THESE PATHS to match your actual page URLs
$redirectUrl = match($entityType) {
    'assessment' => '/student/assessment.php?id=' . $entityId,
    'material'   => '/student/material.php?id='   . $entityId,
    default      => '/student/dashboard.php',
};

echo json_encode(['success' => true, 'redirect_url' => $redirectUrl]);
