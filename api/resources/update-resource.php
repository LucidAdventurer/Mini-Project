<?php
// ============================================================
// api/resources/update-resource.php
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId      = (int)$currentUser['user_id'];
$role        = $currentUser['role'];   // role, not user_type

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

validateCsrfToken();

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$materialId  = (int)($body['material_id']  ?? 0);
$title       = trim($body['title']         ?? '');
$description = trim($body['description']   ?? '');
$category    = trim($body['category']      ?? '');
$externalUrl = trim($body['external_url']  ?? '');

// Accept either visibility string or is_public int from JS
$visibility = trim($body['visibility'] ?? '');
if ($visibility === '' || !in_array($visibility, ['public', 'group', 'private'], true)) {
    $isPublic   = isset($body['is_public']) ? (int)$body['is_public'] : null;
    $visibility = ($isPublic === 1) ? 'public' : (($isPublic === 0) ? 'private' : '');
}

if ($materialId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid material ID.']);
    exit;
}
if ($title === '') {
    // Allow partial update (visibility toggle only) — title may be empty
    // Only require title when other fields are also provided
    if ($description !== '' || $category !== '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Title is required.']);
        exit;
    }
}

$allowedCategories   = ['aptitude', 'verbal', 'logical', 'technical', 'general', 'coding', 'reasoning', 'english'];
$allowedVisibilities = ['public', 'group', 'private'];

if ($category !== '' && !in_array($category, $allowedCategories, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid category.']);
    exit;
}
if ($visibility !== '' && !in_array($visibility, $allowedVisibilities, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid visibility.']);
    exit;
}
if ($externalUrl !== '' && !filter_var($externalUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid URL format.']);
    exit;
}

// ── Verify material exists and check ownership ────────────────────────────
$check = safePreparedQuery($conn,
    'SELECT material_id, created_by, title, description, category, visibility, external_url
     FROM materials WHERE material_id = ?',
    'i', [$materialId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Material not found.']);
    exit;
}
$mRow = $check['result']->fetch_assoc();
$check['result']->free();

if ($role !== 'admin' && (int)$mRow['created_by'] !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. You can only edit your own materials.']);
    exit;
}

// ── Merge with existing values so partial updates work ───────────────────
$finalTitle       = $title       !== '' ? $title       : $mRow['title'];
$finalDescription = $description !== '' ? $description : ($mRow['description'] ?? '');
$finalCategory    = $category    !== '' ? $category    : ($mRow['category']    ?? 'general');
$finalVisibility  = $visibility  !== '' ? $visibility  : $mRow['visibility'];
$finalExternalUrl = $externalUrl !== '' ? $externalUrl : ($mRow['external_url'] ?? null);

// ── Update — only columns that exist on materials ─────────────────────────
// No difficulty, no is_public, no available_from/until on this table
$result = safePreparedQuery($conn,
    'UPDATE materials SET
        title        = ?,
        description  = ?,
        category     = ?,
        visibility   = ?,
        external_url = ?
     WHERE material_id = ?',
    'sssssi',
    [
        $finalTitle,
        $finalDescription,
        $finalCategory,
        $finalVisibility,
        $finalExternalUrl ?: null,
        $materialId,
    ]
);

if ($result['success'] && $result['affected_rows'] >= 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed. Please try again.']);
}