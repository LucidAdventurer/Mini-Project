<?php
// ============================================================
// api/resources/track-resource.php
//
// Tracks student/teacher progress on a training material.
// Records views, downloads, and completion status.
//
// POST JSON {
//   material_id: int,
//   action: "view" | "download" | "progress",
//   progress_percentage?: int   (0–100, for action=progress)
// }
// Returns { success: bool, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];
$role        = $currentUser['user_type'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true);
$materialId = (int)($body['material_id'] ?? 0);
$action     = trim($body['action'] ?? '');
$progress   = max(0, min(100, (int)($body['progress_percentage'] ?? 0)));

if ($materialId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid material ID.']);
    exit;
}

$allowedActions = ['view', 'download', 'progress'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action. Allowed: view, download, progress.']);
    exit;
}

// ── Verify material exists ──
$check = safePreparedQuery($conn,
    "SELECT material_id, is_public, uploaded_by FROM training_materials WHERE material_id = ?",
    "i", [$materialId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Material not found.']);
    exit;
}
$material = $check['result']->fetch_assoc();
$check['result']->free();

// Students can only access public materials
if ($role === 'student' && !$material['is_public']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

try {
    if ($action === 'view') {
        // Increment view counter
        $stmt = $conn->prepare(
            "UPDATE training_materials SET views = views + 1 WHERE material_id = ?"
        );
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("i", $materialId);
        $stmt->execute();
        $stmt->close();

    } elseif ($action === 'download') {
        // Increment download counter
        $stmt = $conn->prepare(
            "UPDATE training_materials SET downloads = downloads + 1 WHERE material_id = ?"
        );
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("i", $materialId);
        $stmt->execute();
        $stmt->close();

    } elseif ($action === 'progress') {
        // Upsert progress record
        $isCompleted = ($progress >= 100) ? 1 : 0;
        $completedAt = $isCompleted ? 'NOW()' : 'NULL';

        $stmt = $conn->prepare(
            "INSERT INTO material_progress
                 (user_id, material_id, progress_percentage, is_completed, completed_at, last_accessed_at)
             VALUES (?, ?, ?, ?, " . ($isCompleted ? "NOW()" : "NULL") . ", NOW())
             ON DUPLICATE KEY UPDATE
                 progress_percentage = GREATEST(progress_percentage, VALUES(progress_percentage)),
                 is_completed        = VALUES(is_completed),
                 completed_at        = IF(VALUES(is_completed) = 1 AND is_completed = 0, NOW(), completed_at),
                 last_accessed_at    = NOW()"
        );
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("iiii", $userId, $materialId, $progress, $isCompleted);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("track-resource.php failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Tracking failed. Please try again.']);
}