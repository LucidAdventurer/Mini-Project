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

$availableFrom = !empty($body['available_from'])
    ? trim($body['available_from'])
    : null;

$availableUntil = !empty($body['available_until'])
    ? trim($body['available_until'])
    : null;

$autoDeleteAfterExpiry = !empty($body['auto_delete_after_expiry'])
    && (
        $body['auto_delete_after_expiry'] === true ||
        $body['auto_delete_after_expiry'] === 1 ||
        $body['auto_delete_after_expiry'] === '1'
    );

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

$allowedCategories = [
    'aptitude',
    'verbal',
    'logical',
    'technical',
    'general',
    'coding',
    'reasoning',
    'english',
    'interview',
    'other'
];
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
if ($externalUrl !== '') {
    $isHttpUrl = filter_var($externalUrl, FILTER_VALIDATE_URL) !== false
        && preg_match('/^https?:\/\//i', $externalUrl);

    $isLocalPath = preg_match(
        '#^/?uploads/materials/[A-Za-z0-9._/-]+$#',
        $externalUrl
    );

    if (!$isHttpUrl && !$isLocalPath) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid URL or local file path.']);
        exit;
    }
}

// ── Validate availability dates ───────────────────────────────────────────
foreach ([
    'available_from'  => $availableFrom,
    'available_until' => $availableUntil
] as $field => $date) {
    if ($date !== null) {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);

        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid ' . str_replace('_', ' ', $field) . ' date.'
            ]);
            exit;
        }
    }
}

if (
    $availableFrom !== null &&
    $availableUntil !== null &&
    $availableUntil < $availableFrom
) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Available To cannot be earlier than Available From.'
    ]);
    exit;
}

// ── Verify ownership ──────────────────────────────────────────────────────
$check = $conn->prepare(
    'SELECT material_id, created_by, title, description, category, visibility, external_url,
        available_from, available_until, auto_delete_after_expiry
     FROM materials
     WHERE material_id = ?'
);
$check->execute([$materialId]);

$mRow = $check->fetch(PDO::FETCH_ASSOC);

if (!$mRow) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Material not found.'
    ]);
    exit;
}

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

$finalAvailableFrom = $availableFrom !== null
    ? $availableFrom
    : ($mRow['available_from'] ?? null);

$finalAvailableUntil = $availableUntil !== null
    ? $availableUntil
    : ($mRow['available_until'] ?? null);

$finalAutoDeleteAfterExpiry = array_key_exists('auto_delete_after_expiry', $body)
    ? $autoDeleteAfterExpiry
    : (
        $mRow['auto_delete_after_expiry'] === true ||
        $mRow['auto_delete_after_expiry'] === 't' ||
        $mRow['auto_delete_after_expiry'] === 1 ||
        $mRow['auto_delete_after_expiry'] === '1'
    );

// ── Update materials row ──────────────────────────────────────────────────
$result = safePreparedQuery($conn,
    'UPDATE materials SET
        title                   = ?,
        description             = ?,
        category                = ?,
        visibility              = ?,
        external_url            = ?,
        available_from          = ?,
        available_until         = ?,
        auto_delete_after_expiry = ?
     WHERE material_id = ?',
    'ssssssssi',
    [
        $finalTitle,
        $finalDescription,
        $finalCategory,
        $finalVisibility,
        $finalExternalUrl ?: null,
        $finalAvailableFrom,
        $finalAvailableUntil,
        $finalAutoDeleteAfterExpiry,
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
$conn->beginTransaction();

try {
    // Delete existing targets
    $del = $conn->prepare(
        'DELETE FROM material_targets WHERE material_id = ?'
    );
    $del->execute([$materialId]);

    // Insert new targets only when visibility = 'group'
    if ($finalVisibility === 'group') {
        if (is_array($targets) && count($targets) > 0) {
            $ins = $conn->prepare(
                'INSERT INTO material_targets
                 (material_id, target_type, target_id)
                 VALUES (?, ?, ?)'
            );

            foreach ($targets as $t) {
                $tType = trim($t['type'] ?? '');
                $tId   = (int)($t['id'] ?? 0);

                if (
                    !in_array($tType, ['group', 'student'], true) ||
                    $tId <= 0
                ) {
                    continue;
                }

                $ins->execute([
                    $materialId,
                    $tType,
                    $tId
                ]);
            }
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true
    ]);

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log(
        'update-resource.php target sync failed: ' . $e->getMessage()
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update targets.'
    ]);
}