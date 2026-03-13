<?php
// ============================================================
// api/resources/track-resource.php
//
// Tracks progress on a material for logged-in users.
// Guests receive a successful no-op — nothing is persisted.
//
// The materials table has no views/downloads counters.
// Only material_progress is written (for logged-in users).
//
// POST JSON {
//   material_id: int,
//   action: "view" | "download" | "progress",
//   progress_percentage?: int   (0–100, required for action=progress)
// }
// Returns { success: bool, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Optional session: guests get null ────────────────────────────────────
$currentUser = optionalSession($conn);
$userId      = $currentUser ? (int)$currentUser['user_id'] : null;
$role        = $currentUser ? $currentUser['user_type']    : 'guest';
$isGuest     = $userId === null;

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

// ── Guests: no-op for all actions ────────────────────────────────────────
// Nothing to persist without a user_id; return success so the
// front-end does not need to branch on authentication state.
if ($isGuest) {
    echo json_encode(['success' => true]);
    exit;
}

// ── Verify material exists and check access ───────────────────────────────
// Columns: material_id, visibility, created_by (no is_public / uploaded_by)
$check = safePreparedQuery($conn,
    'SELECT material_id, visibility, created_by FROM materials WHERE material_id = ?',
    'i', [$materialId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Material not found.']);
    exit;
}
$material = $check['result']->fetch_assoc();
$check['result']->free();

// Students cannot track private materials
if ($role === 'student' && $material['visibility'] === 'private') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

// ── view / download: no counter columns on materials — record as progress ─
// We silently succeed for view/download to keep the API contract stable.
// If you later add views/downloads columns to materials, add UPDATEs here.
if ($action === 'view' || $action === 'download') {
    echo json_encode(['success' => true]);
    exit;
}

// ── progress: upsert into material_progress ───────────────────────────────
// Schema: material_progress(material_id PK, user_id PK,
//   progress_percentage, completed, last_accessed)
try {
    $isCompleted = ($progress >= 100) ? 1 : 0;

    $stmt = $conn->prepare(
        'INSERT INTO material_progress
             (material_id, user_id, progress_percentage, completed, last_accessed)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
             progress_percentage = GREATEST(progress_percentage, VALUES(progress_percentage)),
             completed           = VALUES(completed),
             last_accessed       = NOW()'
    );
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iiii', $materialId, $userId, $progress, $isCompleted);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('track-resource.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Tracking failed. Please try again.']);
}
