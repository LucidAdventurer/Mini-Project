<?php
// ============================================================
// api/resources/delete-resource.php
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Session guard — use role, not user_type ───────────────────────────────
$sessionRole = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($sessionRole, ['admin', 'teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}
$userId = (int) $_SESSION['user_id'];

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

// ── Fetch first — verify ownership ───────────────────────────────────────
$fetchRes = safePreparedQuery(
    $conn,
    'SELECT material_id, created_by, cloudinary_public_id, external_url FROM materials WHERE material_id = ?',
    'i', [$materialId]
);

if (!$fetchRes['success'] || !$fetchRes['result'] || $fetchRes['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Material not found.']);
    exit;
}

$row = $fetchRes['result']->fetch_assoc();
$fetchRes['result']->free();

// Teachers can only delete their own; admins can delete any
if ($sessionRole !== 'admin' && (int)$row['created_by'] !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. You can only delete your own materials.']);
    exit;
}

$cloudinaryPublicId = $row['cloudinary_public_id'] ?: null;
$externalUrl        = $row['external_url'] ?: null;

// ── Delete local file if stored on disk ──────────────────────────────────
if ($externalUrl && strpos($externalUrl, 'uploads/') === 0) {
    $localPath = __DIR__ . '/../../' . $externalUrl;
    if (file_exists($localPath)) {
        @unlink($localPath);
    }
}

// ── Delete DB record ──────────────────────────────────────────────────────
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