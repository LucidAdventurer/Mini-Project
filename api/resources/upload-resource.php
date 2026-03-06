<?php
// ============================================================
// api/resources/upload-resource.php
//
// Uploads a training material file (or registers a link/article).
// Accessible by: admin, teacher
//
// POST multipart/form-data:
//   file                    (required for pdf / video / quiz types)
//   title                   (required)
//   description             (optional)
//   material_type           pdf | video | link | article | quiz  (required)
//   category                (required)
//   difficulty              beginner | intermediate | advanced   (optional, default beginner)
//   is_public               0 | 1   (optional, default 1)
//   external_url            (required if type = link | article)
//   tags                    JSON array string e.g. '["arrays","sorting"]'
//   estimated_time_minutes  (optional)
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

// ── Read text fields ──
$title        = trim($_POST['title']                   ?? '');
$materialType = trim($_POST['material_type']           ?? '');
$category     = trim($_POST['category']                ?? '');
$description  = trim($_POST['description']             ?? '');
$externalUrl  = trim($_POST['external_url']            ?? '');
$tagsRaw      = trim($_POST['tags']                    ?? '');
$difficulty   = trim($_POST['difficulty']              ?? 'beginner');
$isPublic     = isset($_POST['is_public']) ? (int)(bool)$_POST['is_public'] : 1;
$estTime      = max(0, (int)($_POST['estimated_time_minutes'] ?? 0));

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

// ── Parse tags ──
$tagsJson = null;
if ($tagsRaw !== '') {
    $decoded = json_decode($tagsRaw, true);
    if (is_array($decoded)) {
        $decoded  = array_slice(array_map('trim', $decoded), 0, 10);
        $tagsJson = json_encode(array_values(array_filter($decoded)));
    }
}

// ── File upload vs URL ──
$filePath         = null;
$storedFilename   = null;
$originalFilename = null;
$fileSize         = null;
$mimeType         = null;
$destPath         = null;

$requiresFile = ['pdf', 'video', 'quiz'];
$requiresUrl  = ['link', 'article'];

if (in_array($materialType, $requiresFile, true)) {

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded. A file is required for this material type.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        ];
        $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        http_response_code(400);
        echo json_encode(['success' => false,
            'error' => $uploadErrors[$code] ?? 'Unknown upload error.']);
        exit;
    }

    $file             = $_FILES['file'];
    $originalFilename = basename($file['name']);
    $tmpPath          = $file['tmp_name'];
    $fileSize         = (int) $file['size'];
    $mimeType         = mime_content_type($tmpPath);

    if ($fileSize > 50 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File too large. Maximum allowed size is 50 MB.']);
        exit;
    }

    $allowedMimes = [
        'pdf'   => ['application/pdf'],
        'video' => ['video/mp4', 'video/webm', 'video/ogg',
                    'video/quicktime', 'video/x-msvideo', 'video/mpeg'],
        'quiz'  => ['application/pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/msword'],
    ];

    if (!in_array($mimeType, $allowedMimes[$materialType] ?? [], true)) {
        http_response_code(400);
        echo json_encode(['success' => false,
            'error' => "File type \"$mimeType\" is not allowed for material type \"$materialType\"."]);
        exit;
    }

    $ext            = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $safeName       = preg_replace('/[^a-z0-9_.-]/', '',
                        strtolower(pathinfo($originalFilename, PATHINFO_FILENAME)));
    $storedFilename = uniqid('mat_', true) . '_' . $safeName . '.' . $ext;

    $uploadDir = __DIR__ . '/../../uploads/materials/' . date('Y') . '/' . date('m') . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        error_log("upload-resource.php: cannot create dir $uploadDir");
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server storage error. Contact administrator.']);
        exit;
    }

    $destPath = $uploadDir . $storedFilename;
    if (!move_uploaded_file($tmpPath, $destPath)) {
        error_log("upload-resource.php: move_uploaded_file failed → $destPath");
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save file. Please try again.']);
        exit;
    }

    $filePath = 'uploads/materials/' . date('Y') . '/' . date('m') . '/' . $storedFilename;

} elseif (in_array($materialType, $requiresUrl, true)) {

    if ($externalUrl === '') {
        http_response_code(400);
        echo json_encode(['success' => false,
            'error' => 'External URL is required for link / article type.']);
        exit;
    }
    if (!filter_var($externalUrl, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid URL format.']);
        exit;
    }
}

// ── Normalise nullables ──
// mysqli bind_param sends PHP null as SQL NULL for all types.
$externalUrlParam = $externalUrl !== '' ? $externalUrl : null;

// ── Insert ──
// Param order : title | description | material_type | file_path | external_url
//               | file_size | category | difficulty | uploaded_by
//               | is_public | tags | estimated_time_minutes
// Type string : s       s          s             s          s
//               i         s          s            i
//               i         s     i
// = 12 params, type string = "sssssissiisi"  (12 chars ✓)

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
        $filePath,           // null → SQL NULL for link/article
        $externalUrlParam,   // null → SQL NULL for file types
        $fileSize,           // null → SQL NULL for link/article
        $category,
        $difficulty,
        $userId,
        $isPublic,
        $tagsJson,           // null → SQL NULL when no tags
        $estTime
    );

    $stmt->execute();
    $materialId = (int) $stmt->insert_id;
    $stmt->close();

    if (!$materialId) {
        throw new Exception("INSERT returned no insert_id.");
    }

    // ── Log in uploaded_files ──
    if ($filePath !== null) {
        $fileExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $logStmt = $conn->prepare(
            "INSERT INTO uploaded_files
                 (original_filename, stored_filename, file_path,
                  file_type, mime_type, file_size,
                  uploaded_by, entity_type, entity_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'material', ?)"
        );
        if ($logStmt) {
            $logStmt->bind_param(
                "sssssiiii",
                $originalFilename,
                $storedFilename,
                $filePath,
                $fileExt,
                $mimeType,
                $fileSize,
                $userId,
                $materialId
            );
            $logStmt->execute();
            $logStmt->close();
        }
    }

    $conn->commit();

    echo json_encode([
        'success'     => true,
        'material_id' => $materialId,
        'message'     => 'Resource uploaded successfully.',
    ]);

} catch (Exception $e) {
    $conn->rollback();

    // Clean up physical file on DB failure
    if ($destPath !== null && file_exists($destPath)) {
        @unlink($destPath);
    }

    error_log("upload-resource.php transaction failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save resource. Please try again.']);
}