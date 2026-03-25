<?php
// ============================================================
// api/admin/delete-admin-resource.php
//
// Deletes a row from the `resources` table.
// Admin only. Validates CSRF.
//
// POST JSON { resource_id: int }
//
// Response: { success: bool, cloudinary_public_id: string|null }
// The JS caller is responsible for any Cloudinary asset cleanup
// using the returned public_id.
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$admin   = validateSession($conn, 'admin');
$adminId = (int)$admin['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

validateCsrfToken();

$body       = json_decode(file_get_contents('php://input'), true);
$resourceId = (int)($body['resource_id'] ?? 0);

if ($resourceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid resource ID.']);
    exit;
}

// ── Fetch first to get cloudinary_public_id for caller cleanup ────────────
$fetch = safePreparedQuery(
    $conn,
    'SELECT resource_id, cloudinary_public_id FROM resources WHERE resource_id = ?',
    'i', [$resourceId]
);

if (!$fetch['success'] || !$fetch['result'] || $fetch['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Resource not found.']);
    exit;
}

$row = $fetch['result']->fetch_assoc();
$fetch['result']->free();
$cloudinaryPublicId = $row['cloudinary_public_id'] ?: null;

// ── Delete ────────────────────────────────────────────────────────────────
$del = safePreparedQuery(
    $conn,
    'DELETE FROM resources WHERE resource_id = ?',
    'i', [$resourceId]
);

if (!$del['success'] || $del['affected_rows'] === 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete resource.']);
    exit;
}

echo json_encode([
    'success'              => true,
    'cloudinary_public_id' => $cloudinaryPublicId,
]);