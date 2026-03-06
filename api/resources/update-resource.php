<?php
// ============================================================
// api/resources/update-resource.php
//
// Updates metadata of an existing training material.
// Admins can edit any material; teachers can only edit their own.
//
// POST JSON {
//   material_id, title, description, category,
//   difficulty, is_public, external_url?,
//   tags?, estimated_time_minutes?
// }
// Returns { success: bool, error?: string }
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

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$materialId  = (int)($body['material_id'] ?? 0);
$title       = trim($body['title']        ?? '');
$description = trim($body['description']  ?? '');
$category    = trim($body['category']     ?? '');
$difficulty  = trim($body['difficulty']   ?? '');
$externalUrl = trim($body['external_url'] ?? '');
$tagsRaw     = $body['tags'] ?? null;
$estTime     = max(0, (int)($body['estimated_time_minutes'] ?? 0));
$isPublic    = isset($body['is_public']) ? (int)(bool)$body['is_public'] : 1;

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

$allowedCategories   = ['aptitude', 'technical', 'coding', 'reasoning', 'english', 'general', 'placement', 'interview'];
$allowedDifficulties = ['beginner', 'intermediate', 'advanced'];

if (!in_array($category, $allowedCategories, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid category.']);
    exit;
}
if (!in_array($difficulty, $allowedDifficulties, true)) {
    $difficulty = 'beginner';
}

// ── Validate external URL if provided ──
if ($externalUrl !== '' && !filter_var($externalUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid URL format.']);
    exit;
}

// ── Tags ──
$tagsJson = null;
if (is_array($tagsRaw)) {
    $clean    = array_slice(array_map('trim', $tagsRaw), 0, 10);
    $tagsJson = json_encode(array_values(array_filter($clean)));
} elseif (is_string($tagsRaw) && $tagsRaw !== '') {
    $decoded = json_decode($tagsRaw, true);
    if (is_array($decoded)) {
        $tagsJson = json_encode(array_values(array_filter(array_map('trim', $decoded))));
    }
}

// ── Verify ownership ──
$check = safePreparedQuery($conn,
    "SELECT material_id, uploaded_by FROM training_materials WHERE material_id = ?",
    "i", [$materialId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Material not found.']);
    exit;
}
$mRow = $check['result']->fetch_assoc();
$check['result']->free();

if ($role !== 'admin' && (int)$mRow['uploaded_by'] !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. You can only edit your own materials.']);
    exit;
}

// ── Update ──
$result = safePreparedQuery($conn,
    "UPDATE training_materials SET
        title                   = ?,
        description             = ?,
        category                = ?,
        difficulty              = ?,
        is_public               = ?,
        external_url            = ?,
        tags                    = ?,
        estimated_time_minutes  = ?,
        updated_at              = NOW()
     WHERE material_id = ?",
    "ssssissii",
    [
        $title, $description, $category, $difficulty,
        $isPublic,
        $externalUrl ?: null,
        $tagsJson,
        $estTime,
        $materialId,
    ]
);

if ($result['success'] && $result['affected_rows'] >= 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed. Please try again.']);
}