<?php
// ============================================================
// api/resources/delete-resource.php
//
// Deletes a training material and its associated file on disk.
// Admins can delete any material; teachers only their own.
//
// POST JSON { material_id: int }
// Returns   { success: bool, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];
$role        = $currentUser['user_type'];

if (!in_array($role, ['admin', 'teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true);
$materialId = (int)($body['material_id'] ?? 0);

if ($materialId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid material ID.']);
    exit;
}

// ── Fetch material (verify exists + ownership) ──
$check = safePreparedQuery($conn,
    "SELECT material_id, uploaded_by, file_path FROM training_materials WHERE material_id = ?",
    "i", [$materialId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Material not found.']);
    exit;
}
$material = $check['result']->fetch_assoc();
$check['result']->free();

if ($role !== 'admin' && (int)$material['uploaded_by'] !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. You can only delete your own materials.']);
    exit;
}

$conn->begin_transaction();

try {
    // Soft-delete associated file record
    $stmt = $conn->prepare(
        "UPDATE uploaded_files SET is_deleted = 1
         WHERE entity_type = 'material' AND entity_id = ?"
    );
    if ($stmt) {
        $stmt->bind_param("i", $materialId);
        $stmt->execute();
        $stmt->close();
    }

    // Delete material record (FK cascades progress records)
    $stmt = $conn->prepare("DELETE FROM training_materials WHERE material_id = ?");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $materialId);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        throw new Exception("Delete affected 0 rows");
    }
    $stmt->close();

    $conn->commit();

    // ── Delete physical file after DB commit ──
    if (!empty($material['file_path'])) {
        $absPath = __DIR__ . '/../../' . ltrim($material['file_path'], '/');
        if (file_exists($absPath)) {
            @unlink($absPath);
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("delete-resource.php failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed. Please try again.']);
}