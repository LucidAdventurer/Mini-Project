<?php
// ============================================================
// api/notifications/dismiss-notification.php
//
// Two modes (auto-detected from request body):
//
//  1. X button click  → send { notification_id: 123 }
//     Deletes that single notification for this student.
//
//  2. Resource opened → send { material_id: 456 }
//     Deletes ALL notifications for that material (original behaviour).
//
// Always returns updated unread_count so the bell badge stays in sync.
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'student');
$userId      = (int) $currentUser['user_id'];

$body           = json_decode(file_get_contents('php://input'), true) ?? [];
$notificationId = isset($body['notification_id']) ? (int)$body['notification_id'] : 0;
$materialId     = isset($body['material_id'])     ? (int)$body['material_id']     : 0;

// ── Validate: need at least one valid ID ──────────────────────
if ($notificationId <= 0 && $materialId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Provide notification_id or material_id']);
    exit;
}

// ── Mode 1: dismiss a single notification by ID ───────────────
if ($notificationId > 0) {
    $stmt = $conn->prepare(
        "DELETE FROM notifications
         WHERE notification_id = ?
           AND user_id         = ?"   // user_id guard prevents deleting others' notifications
    );

    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Query prepare failed']);
        exit;
    }

    $stmt->bind_param("ii", $notificationId, $userId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

// ── Mode 2: dismiss all notifications for a material (original) ─
} else {
    $stmt = $conn->prepare(
        "DELETE FROM notifications
         WHERE user_id             = ?
           AND related_entity_type = 'material'
           AND related_entity_id   = ?"
    );

    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Query prepare failed']);
        exit;
    }

    $stmt->bind_param("ii", $userId, $materialId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
}

// ── Return fresh unread count so the bell badge updates ───────
$countResult = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0"
);
$countResult->bind_param("i", $userId);
$countResult->execute();
$row = $countResult->get_result()->fetch_assoc();
$countResult->close();

echo json_encode([
    'success'      => true,
    'deleted'      => $deleted,
    'unread_count' => (int)($row['cnt'] ?? 0),
]);
