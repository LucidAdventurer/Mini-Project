<?php
// ============================================================
// api/resources/upload-resource.php
//
// Saves training material metadata after the file has been
// uploaded directly to Cloudinary from the browser.
// The Cloudinary secure_url is passed as external_url.
//
// Accessible by: admin, teacher
//
// POST JSON {
//   title                   (required)
//   material_type           pdf | video | link | article | quiz  (required)
//   category                (required)
//   external_url            (required — Cloudinary URL for files, raw URL for links)
//   description             (optional)
//   difficulty              beginner | intermediate | advanced   (optional, default beginner)
//   is_public               0 | 1   (optional, default 1)
//   tags                    JSON array string e.g. '["arrays","sorting"]'
//   estimated_time_minutes  (optional)
//   file_size               (optional, bytes — returned by Cloudinary)
// }
//
// Returns { success: bool, material_id: int, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// ── Auth: admin or teacher only ──
$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];
$role        = $currentUser['user_type'];

if (!in_array($role, ['admin', 'teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only teachers and admins can upload resources.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

validateCsrfToken();

// ── Parse JSON body ──
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$title        = trim($body['title']                   ?? '');
$materialType = trim($body['material_type']           ?? '');
$category     = trim($body['category']                ?? '');
$description  = trim($body['description']             ?? '');
$externalUrl  = trim($body['external_url']            ?? '');
$tagsRaw      = $body['tags']                         ?? null;
$difficulty   = trim($body['difficulty']              ?? 'beginner');
$isPublic     = isset($body['is_public']) ? (int)(bool)$body['is_public'] : 1;
$estTime      = max(0, (int)($body['estimated_time_minutes'] ?? 0));
$fileSize     = max(0, (int)($body['file_size']       ?? 0));

// ── Validate title ──
if ($title === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required.']);
    exit;
}
if (mb_strlen($title) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title must not exceed 200 characters.']);
    exit;
}

// ── Validate material_type ──
$allowedTypes = ['pdf', 'video', 'link', 'article', 'quiz'];
if (!in_array($materialType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false,
        'error' => 'Invalid material type. Allowed: ' . implode(', ', $allowedTypes)]);
    exit;
}

// ── Validate category ──
$allowedCategories = ['aptitude', 'technical', 'coding', 'reasoning',
                      'english', 'general', 'placement', 'interview'];
if (!in_array($category, $allowedCategories, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid category.']);
    exit;
}

// ── Validate difficulty ──
$allowedDifficulties = ['beginner', 'intermediate', 'advanced'];
if (!in_array($difficulty, $allowedDifficulties, true)) {
    $difficulty = 'beginner';
}

// ── Validate URL (required for all types now) ──
if ($externalUrl === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'external_url is required.']);
    exit;
}
if (!filter_var($externalUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid URL format.']);
    exit;
}

// ── Parse tags ──
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

// ── file_path is always NULL — files live on Cloudinary ──
$filePath      = null;
$fileSizeParam = $fileSize > 0 ? $fileSize : null;

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "INSERT INTO training_materials
             (title, description, material_type,
              file_path, external_url, file_size,
              category, difficulty, uploaded_by,
              is_public, tags, estimated_time_minutes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param(
        "sssssissiisi",
        $title,
        $description,
        $materialType,
        $filePath,
        $externalUrl,
        $fileSizeParam,
        $category,
        $difficulty,
        $userId,
        $isPublic,
        $tagsJson,
        $estTime
    );

    $stmt->execute();
    $materialId = (int) $stmt->insert_id;
    $stmt->close();

    if (!$materialId) {
        throw new Exception("INSERT returned no insert_id.");
    }

    $conn->commit();

    echo json_encode([
        'success'     => true,
        'material_id' => $materialId,
        'message'     => 'Resource saved successfully.',
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("upload-resource.php transaction failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save resource. Please try again.']);
}