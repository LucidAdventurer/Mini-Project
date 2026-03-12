<?php
// ============================================================
// api/resources/delete-resource.php
//
// Deletes a material record from the `materials` table.
// The Cloudinary asset is NOT deleted here — the returned
// cloudinary_public_id lets the caller purge it separately
// via the Cloudinary Management API if needed.
//
// Requires: admin session + valid CSRF token.
//
// POST JSON { material_id: int }
//
// Returns { success: bool, cloudinary_public_id: string|null }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Admin session guard ───────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────────────
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Parse body ────────────────────────────────────────────────────────────
$body       = json_decode(file_get_contents('php://input'), true);
$materialId = (int)($body['material_id'] ?? 0);

if ($materialId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid material ID.']);
    exit;
}

// ── Fetch first to return cloudinary_public_id to caller ─────────────────
// PK is material_id, not resource_id
$fetchRes = safePreparedQuery(
    $conn,
    'SELECT material_id, cloudinary_public_id FROM materials WHERE material_id = ?',
    'i', [$materialId]
);

if (!$fetchRes['success'] || !$fetchRes['result'] || $fetchRes['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Material not found.']);
    exit;
}

$row = $fetchRes['result']->fetch_assoc();
$fetchRes['result']->free();
$cloudinaryPublicId = $row['cloudinary_public_id'] ?: null;

// ── Delete ────────────────────────────────────────────────────────────────
$del = safePreparedQuery(
    $conn,
    'DELETE FROM materials WHERE material_id = ?',
    'i', [$materialId]
);

if (!$del['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete material.']);
    exit;
}

echo json_encode([
    'success'              => true,
    'cloudinary_public_id' => $cloudinaryPublicId,
]);
