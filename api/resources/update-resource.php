<?php
// ============================================================
// api/resources/update-resource.php
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId      = (int)$currentUser['user_id'];
$role        = $currentUser['role'];

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
$targets     = $body['targets']            ?? null; // array of {type, id} or null = no change

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

if ($title === '' && ($description !== '' || $category !== '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required.']);
    exit;
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

// ── Verify ownership ──────────────────────────────────────────────────────
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

// ── Merge with existing values ────────────────────────────────────────────
$finalTitle       = $title       !== '' ? $title       : $mRow['title'];
$finalDescription = $description !== '' ? $description : ($mRow['description'] ?? '');
$finalCategory    = $category    !== '' ? $category    : ($mRow['category']    ?? 'general');
$finalVisibility  = $visibility  !== '' ? $visibility  : $mRow['visibility'];
$finalExternalUrl = $externalUrl !== '' ? $externalUrl : ($mRow['external_url'] ?? null);

// ── Update materials row ──────────────────────────────────────────────────
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

if (!$result['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed. Please try again.']);
    exit;
}

// ── Sync material_targets ─────────────────────────────────────────────────
// Always wipe existing targets first, then re-insert based on final visibility.
// If $targets is null (not sent), reconstruct from visibility alone.
$conn->begin_transaction();
try {
    // Delete existing targets
    $del = $conn->prepare('DELETE FROM material_targets WHERE material_id = ?');
    $del->bind_param('i', $materialId);
    $del->execute();
    $del->close();

    // Insert new targets only when visibility = 'group'
    if ($finalVisibility === 'group') {
        if (is_array($targets) && count($targets) > 0) {
            // Use targets from request payload
            $ins = $conn->prepare(
                'INSERT INTO material_targets (material_id, target_type, target_id) VALUES (?, ?, ?)'
            );
            foreach ($targets as $t) {
                $tType = trim($t['type'] ?? '');
                $tId   = (int)($t['id']   ?? 0);
                if (!in_array($tType, ['group', 'student'], true) || $tId <= 0) continue;
                $ins->bind_param('isi', $materialId, $tType, $tId);
                $ins->execute();
            }
            $ins->close();
        }
        // If targets is null or empty and visibility is group, targets were wiped above —
        // the resource becomes group-visible with no targets (no one can see it).
        // This mirrors the assessment behaviour.
    }
    // For public/private, no rows in material_targets needed.

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to update targets.']);
}