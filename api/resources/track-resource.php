<?php
// ============================================================
// api/resources/track-resource.php
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sentToken    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (
    $sessionToken === '' ||
    $sentToken === '' ||
    !hash_equals($sessionToken, $sentToken)
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid CSRF token.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Optional session: guests get null ────────────────────────────────────
$sessionUid = (int)($_SESSION['user_id'] ?? 0);
$userId     = $sessionUid > 0 ? $sessionUid : null;
$role       = $sessionUid > 0 ? ($_SESSION['role'] ?? 'guest') : 'guest';
$isGuest    = $userId === null;

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

// ── Guests: no-op ─────────────────────────────────────────────────────────
if ($isGuest) {
    echo json_encode(['success' => true]);
    exit;
}

// ── Verify material exists ────────────────────────────────────────────────
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

if ($role === 'student') {
    if ($material['visibility'] === 'private') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Access denied.'
        ]);
        exit;
    }

    $access = safePreparedQuery(
        $conn,
        "SELECT 1
         FROM material_targets mt
         JOIN group_members gm
           ON gm.group_id = mt.target_id
         WHERE mt.material_id = ?
           AND mt.target_type = 'group'
           AND gm.student_id = ?

         UNION

         SELECT 1
         FROM material_targets mt
         WHERE mt.material_id = ?
           AND mt.target_type = 'student'
           AND mt.target_id = ?

         LIMIT 1",
        "iiii",
        [$materialId, $userId, $materialId, $userId]
    );

    if (
        $material['visibility'] === 'group' &&
        (!$access['success'] ||
         !$access['result'] ||
         $access['result']->num_rows === 0)
    ) {
        if ($access['result']) {
            $access['result']->free();
        }

        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Access denied.'
        ]);
        exit;
    }

    if ($access['result']) {
        $access['result']->free();
    }
}

if ($role === 'student') {
    $availability = safePreparedQuery(
        $conn,
        "SELECT 1
         FROM materials
         WHERE material_id = ?
           AND (available_from IS NULL OR CURRENT_DATE >= available_from)
           AND (available_until IS NULL OR CURRENT_DATE <= available_until)",
        "i",
        [$materialId]
    );

    $available = (
        $availability['success'] &&
        $availability['result'] &&
        $availability['result']->num_rows > 0
    );

    if ($availability['result']) {
        $availability['result']->free();
    }

    if (!$available) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Material is not currently available.'
        ]);
        exit;
    }
}

// ── view / download: no counter columns on materials — succeed silently ───
if ($action === 'view' || $action === 'download') {
    echo json_encode(['success' => true]);
    exit;
}

// ── progress: upsert into material_progress ───────────────────────────────
try {
    $isCompleted = ($progress >= 100);

    $stmt = $conn->prepare(
        'INSERT INTO material_progress
            (material_id, user_id, progress_percentage, completed, last_accessed)
         VALUES (?, ?, ?, ?, NOW())
         ON CONFLICT (material_id, user_id)
         DO UPDATE SET
            progress_percentage = GREATEST(
                material_progress.progress_percentage,
                EXCLUDED.progress_percentage
            ),
            completed = material_progress.completed OR EXCLUDED.completed,
            last_accessed = NOW()'
    );

    $stmt->execute([
        $materialId,
        $userId,
        $progress,
        $isCompleted
    ]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log('track-resource.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Tracking failed. Please try again.'
    ]);
}