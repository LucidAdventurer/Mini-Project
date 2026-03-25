<?php
// ============================================================
// api/admin/delete-admin-resource.php
//
// Deletes a row from the `resources` table.
// Returns cloudinary_public_id so caller can purge from Cloudinary.
//
// POST JSON { resource_id: int }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true);
$resourceId = (int)($body['resource_id'] ?? 0);

if ($resourceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid resource ID.']);
    exit;
}

$fetch = safePreparedQuery($conn,
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

$del = safePreparedQuery($conn,
    'DELETE FROM resources WHERE resource_id = ?',
    'i', [$resourceId]
);

if (!$del['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed.']);
    exit;
}

echo json_encode([
    'success'              => true,
    'cloudinary_public_id' => $cloudinaryPublicId,
]);
