<?php
// ============================================================
// api/resources/delete-resource.php
//
// Deletes a resource record from the `resources` table.
// The Cloudinary asset is NOT deleted server-side here —
// it can be done via the Cloudinary Management API separately,
// using the cloudinary_public_id stored in the row.
//
// Requires: admin session + valid CSRF token.
//
// POST JSON { material_id: int }
//
// Returns { success: bool, cloudinary_public_id: string|null }
// (returning public_id so the caller can optionally purge Cloudinary)
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Admin session guard ────────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

// ── CSRF check ─────────────────────────────────────────────────────────────
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Parse body ─────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$resourceId = (int)($body['material_id'] ?? 0);

if ($resourceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid resource ID.']);
    exit;
}

// ── Fetch the row first so we can return the cloudinary_public_id ──────────
$fetchRes = safePreparedQuery(
    $conn,
    "SELECT resource_id, cloudinary_public_id FROM resources WHERE resource_id = ?",
    "i", [$resourceId]
);

if (!$fetchRes['success'] || !$fetchRes['result'] || $fetchRes['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Resource not found.']);
    exit;
}

$row = $fetchRes['result']->fetch_assoc();
$fetchRes['result']->free();
$cloudinaryPublicId = $row['cloudinary_public_id'] ?: null;

// ── Delete ─────────────────────────────────────────────────────────────────
$del = safePreparedQuery(
    $conn,
    "DELETE FROM resources WHERE resource_id = ?",
    "i", [$resourceId]
);

if (!$del['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete resource.']);
    exit;
}

echo json_encode([
    'success'              => true,
    'cloudinary_public_id' => $cloudinaryPublicId,
]);