<?php
// ============================================================
// api/resources/update-resource.php
//
// Updates metadata of an existing material.
// Admins can edit any material; teachers can only edit their own.
//
// POST JSON {
//   material_id  : int      (required)
//   title        : string   (required)
//   description  : string
//   category     : 'aptitude'|'verbal'|'logical'|'technical'|'general'
//   difficulty   : 'beginner'|'intermediate'|'advanced'
//   visibility   : 'public'|'group'|'private'
//   external_url : string   (optional)
// }
// Returns { success: bool, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId      = (int)$currentUser['user_id'];
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
$difficulty  = trim($body['difficulty']    ?? '');
$visibility  = trim($body['visibility']    ?? '');
$externalUrl = trim($body['external_url']  ?? '');

if ($materialId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid material ID.']);
    exit;
}
if ($title === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required.']);
    exit;
}

$allowedCategories   = ['aptitude', 'verbal', 'logical', 'technical', 'general'];
$allowedDifficulties = ['beginner', 'intermediate', 'advanced'];
$allowedVisibilities = ['public', 'group', 'private'];

if (!in_array($category, $allowedCategories, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid category.']);
    exit;
}
if (!in_array($difficulty, $allowedDifficulties, true)) {
    $difficulty = 'beginner';
}
if (!in_array($visibility, $allowedVisibilities, true)) {
    $visibility = 'public';
}

if ($externalUrl !== '' && !filter_var($externalUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid URL format.']);
    exit;
}

// ── Verify material exists and check ownership ────────────────────────────
// Column is created_by, not uploaded_by
$check = safePreparedQuery($conn,
    'SELECT material_id, created_by FROM materials WHERE material_id = ?',
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

// ── Update ────────────────────────────────────────────────────────────────
// Only columns that actually exist in the materials schema.
// No: is_public, uploaded_by, tags, estimated_time_minutes, updated_at
$result = safePreparedQuery($conn,
    'UPDATE materials SET
        title                = ?,
        description          = ?,
        category             = ?,
        difficulty           = ?,
        visibility           = ?,
        external_url         = ?
     WHERE material_id = ?',
    'ssssssi',
    [
        $title,
        $description,
        $category,
        $difficulty,
        $visibility,
        $externalUrl ?: null,
        $materialId,
    ]
);

if ($result['success'] && $result['affected_rows'] >= 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed. Please try again.']);
}
