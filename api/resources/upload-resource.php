<?php
// ============================================================
// api/resources/upload-resource.php
//
// File uploads are stored locally; external links are stored in external_url.
// External links are stored directly in external_url.
// ============================================================

// ── Must be ABSOLUTELY first — suppress HTML errors for this API endpoint ──
// config.php sets display_errors=1 in development which outputs HTML and
// corrupts our JSON response. Force errors to log only, never to output.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// ── Increase upload limits BEFORE anything else ───────────────────────────
@ini_set('upload_max_filesize', '55M');
@ini_set('post_max_size',       '60M');
@ini_set('memory_limit',        '256M');

// ── Catch PHP's own post_max_size overflow early ──────────────────────────
// When POST data exceeds post_max_size, PHP empties $_POST and $_FILES.
// Detect this and return clean JSON instead of broken HTML.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['CONTENT_LENGTH']) &&
    (int) $_SERVER['CONTENT_LENGTH'] > 0 &&
    empty($_POST) && empty($_FILES) &&
    strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false
) {
    header('Content-Type: application/json');
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum upload size is 50 MB.']);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Session guard ─────────────────────────────────────────────────────────
$sessionRole = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($sessionRole, ['admin', 'teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────────────
$csrfSent    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if ($csrfSession === '' || !hash_equals($csrfSession, $csrfSent)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Detect mode ───────────────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isJson      = str_contains($contentType, 'application/json');

if ($isJson) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
        exit;
    }
    $action             = $body['action']               ?? 'upload_link';
    $title              = trim($body['title']            ?? '');
    $description        = trim($body['description']      ?? '');
    $category           = trim($body['category']         ?? '');
    $visibilityRaw      = trim($body['visibility']       ?? '');
    $isPublic           = ($visibilityRaw === 'public' || ($body['is_public'] ?? 0) == 1) ? 1 : 0;
    $targets            = $body['targets']               ?? [];
        $externalUrl        = trim($body['external_url']     ?? '');
    $cloudinaryPublicId = trim($body['cloudinary_public_id'] ?? '');

    $availableFrom = !empty($body['available_from'])
        ? $body['available_from']
        : null;

    $availableUntil = !empty($body['available_until'])
        ? $body['available_until']
        : null;

    $autoDeleteAfterExpiry = !empty($body['auto_delete_after_expiry'])
        ? filter_var($body['auto_delete_after_expiry'], FILTER_VALIDATE_BOOLEAN)
        : false;

    $uploadedFile = null;
} else {
    // FormData (action = upload)
    $action             = $_POST['action']          ?? 'upload';
    $title              = trim($_POST['title']       ?? '');
    $description        = trim($_POST['description'] ?? '');
    $category           = trim($_POST['category']    ?? '');
    $visibilityRaw      = trim($_POST['visibility']  ?? '');
    $isPublic           = ($visibilityRaw === 'public' || ($_POST['is_public'] ?? '') === '1') ? 1 : 0;
    $targets            = json_decode($_POST['targets'] ?? '[]', true) ?: [];
    $externalUrl        = '';
    $cloudinaryPublicId = '';

    $availableFrom = !empty($_POST['available_from'])
        ? $_POST['available_from']
        : null;

    $availableUntil = !empty($_POST['available_until'])
        ? $_POST['available_until']
        : null;

    $autoDeleteAfterExpiry = !empty($_POST['auto_delete_after_expiry'])
        && $_POST['auto_delete_after_expiry'] === '1';

    $uploadedFile = $_FILES['file'] ?? null;
}

// ── Validate title ────────────────────────────────────────────────────────
if ($title === '' || mb_strlen($title) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required (max 200 chars).']);
    exit;
}

// ── Sanitise category ─────────────────────────────────────────────────────
$validCategories = ['aptitude', 'verbal', 'logical', 'technical', 'general', 'coding', 'reasoning', 'english'];
$category = strtolower($category);
if (!in_array($category, $validCategories, true)) $category = 'general';

// Use explicit visibility if valid, else derive from isPublic flag
$allowedVisibilities = ['public', 'group', 'private'];
$visibility = in_array($visibilityRaw, $allowedVisibilities, true) ? $visibilityRaw : ($isPublic ? 'public' : 'private');
$createdBy  = (int) $_SESSION['user_id'];

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

if ($availableFrom !== null && $availableUntil !== null &&
    $availableUntil < $availableFrom) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Available To cannot be earlier than Available From.'
    ]);
    exit;
}

$cloudinaryPublicId = null;
$storedExternalUrl  = null;

// ── Handle file upload → local storage ───────────────────────────────────
if ($action === 'upload' && $uploadedFile) {
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'File upload error code: ' . $uploadedFile['error']
        ]);
        exit;
    }

    $maxBytes = 50 * 1024 * 1024;

    if ($uploadedFile['size'] > $maxBytes) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'File exceeds 50 MB limit.'
        ]);
        exit;
    }

    $allowedMimes = [
        'application/pdf',
        'video/mp4',
        'video/webm',
        'video/ogg',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
    ];

    $mime = mime_content_type($uploadedFile['tmp_name']);

    if (!in_array($mime, $allowedMimes, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'File type not allowed. Accepted: PDF, MP4, JPEG, PNG, DOCX.'
        ]);
        exit;
    }

    $extension = strtolower(
        pathinfo($uploadedFile['name'], PATHINFO_EXTENSION)
    );

    $safeExtension = preg_replace(
        '/[^a-z0-9]/',
        '',
        $extension
    );

    if ($safeExtension === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Unable to determine file extension.'
        ]);
        exit;
    }

    // Generate a unique filename; do not trust the original filename.
    $filename = bin2hex(random_bytes(16)) . '.' . $safeExtension;

    $uploadDir = __DIR__ . '/../../uploads/materials/';
    $destination = $uploadDir . $filename;

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        error_log("Failed to create resource upload directory: $uploadDir");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to prepare upload directory.'
        ]);
        exit;
    }

    if (!is_writable($uploadDir)) {
        error_log("Resource upload directory is not writable: $uploadDir");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Upload directory is not writable.'
        ]);
        exit;
    }

    if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
        error_log("Failed to move uploaded resource to: $destination");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to store uploaded file.'
        ]);
        exit;
    }

    // Store relative path in existing external_url column.
    $storedExternalUrl = 'uploads/materials/' . $filename;

} elseif ($action === 'upload_link') {
    if ($externalUrl === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A URL is required.']);
        exit;
    }
    if (!filter_var($externalUrl, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid URL format.']);
        exit;
    }
    $storedExternalUrl = $externalUrl;
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file or URL provided.']);
    exit;
}

// ── Insert into materials ─────────────────────────────────────────────────
// Columns: material_id(auto), title, description, created_by, visibility,
//          cloudinary_public_id, external_url, category
$ins = safePreparedQuery(
    $conn,
    'INSERT INTO materials
         (title, description, created_by, visibility,
          cloudinary_public_id, external_url, category,
          available_from, available_until, auto_delete_after_expiry)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'ssissssssi',
    [
        $title,
        $description,
        $createdBy,
        $visibility,
        $cloudinaryPublicId ?: null,
        $storedExternalUrl ?: null,
        $category,
        $availableFrom,
        $availableUntil,
        $autoDeleteAfterExpiry ? 1 : 0
    ]
);

if (!$ins['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save resource to database.']);
    exit;
}

$newId = $ins['insert_id'];

// ── Insert material_targets ───────────────────────────────────────────────
if (!empty($targets) && is_array($targets) && in_array($visibility, ['group'], true)) {
    foreach ($targets as $target) {
        $tType = $target['type'] ?? '';
        $tId   = (int)($target['id'] ?? 0);
        if (!in_array($tType, ['group', 'student'], true) || $tId <= 0) continue;
        safePreparedQuery($conn,
            'INSERT INTO material_targets (
                material_id,
                target_type,
                target_id
            )
            VALUES (?, ?, ?)
            ON CONFLICT DO NOTHING',
            'isi',
            [$newId, $tType, $tId]
        );
    }
}

// ── Notify students for public resources ──────────────────────────────────
if ($visibility === 'public') {
    $notifTitle   = '📚 New Resource Available';
    $notifMessage = 'A new resource "' . $title . '" has been shared with you.';

    $students = safePreparedQuery(
        $conn,
        "SELECT user_id
            FROM users
            WHERE role = 'student'
            AND is_active = TRUE",
        '', []
    );

    if ($students['success'] && $students['result']) {
        $studentIds = [];
        while ($row = $students['result']->fetch_assoc()) {
            $studentIds[] = (int) $row['user_id'];
        }
        $students['result']->free();

        if (!empty($studentIds)) {
            $placeholders = implode(', ', array_fill(0, count($studentIds), '(?, ?, ?, ?, ?)'));
            $types        = str_repeat('isssi', count($studentIds));
            $params       = [];
            foreach ($studentIds as $sid) {
                $params[] = $sid;
                $params[] = $notifTitle;
                $params[] = $notifMessage;
                $params[] = 'material';
                $params[] = $newId;
            }
            safePreparedQuery($conn,
                "INSERT INTO notifications (
                    user_id,
                    title,
                    message,
                    type,
                    related_entity_id
                )
                VALUES $placeholders
                ON CONFLICT DO NOTHING",
                $types,
                $params
            );
        }
    }
}

echo json_encode(['success' => true, 'material_id' => $newId]);