<?php
// ============================================================
// api/resources/delete-resource.php
//
// Deletes a training material record from the DB, then removes
// the file from Cloudinary via the Admin API (destroy).
// Admins can delete any material; teachers only their own.
//
// POST JSON { material_id: int }
// Returns   { success: bool, error?: string }
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

validateCsrfToken();

$body       = json_decode(file_get_contents('php://input'), true);
$materialId = (int)($body['material_id'] ?? 0);

if ($materialId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid material ID.']);
    exit;
}

// ── Fetch material (verify exists + ownership) ──
$check = safePreparedQuery($conn,
    "SELECT material_id, uploaded_by, cloudinary_public_id, material_type
     FROM training_materials WHERE material_id = ?",
    "i", [$materialId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Material not found.']);
    exit;
}
$material = $check['result']->fetch_assoc();
$check['result']->free();

if ($role !== 'admin' && (int)$material['uploaded_by'] !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. You can only delete your own materials.']);
    exit;
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("DELETE FROM training_materials WHERE material_id = ?");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $materialId);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        throw new Exception("Delete affected 0 rows");
    }
    $stmt->close();

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    error_log("delete-resource.php DB failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed. Please try again.']);
    exit;
}

// ── Delete from Cloudinary after DB commit ──
// Only attempt if we have a public_id (link/article types won't have one).
$publicId = $material['cloudinary_public_id'] ?? '';
if ($publicId !== '') {
    // video → 'video', pdf/quiz → 'raw'
    $resourceType = $material['material_type'] === 'video' ? 'video' : 'raw';

    $timestamp = time();
    // Sign only public_id + timestamp — resource_type is not part of destroy signature
    $sigString = "public_id={$publicId}&timestamp={$timestamp}" . CLOUDINARY_API_SECRET;
    $signature = sha1($sigString);

    $postFields = http_build_query([
        'public_id' => $publicId,
        'timestamp' => $timestamp,
        'api_key'   => CLOUDINARY_API_KEY,
        'signature' => $signature,
    ]);

    // Correct endpoint: /v1_1/<cloud>/<resource_type>/destroy
    $ch = curl_init("https://api.cloudinary.com/v1_1/" . CLOUDINARY_CLOUD_NAME . "/{$resourceType}/destroy");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("delete-resource.php Cloudinary destroy failed for public_id={$publicId}: {$curlErr}");
    } else {
        $decoded = json_decode($response, true);
        if (($decoded['result'] ?? '') !== 'ok' && ($decoded['result'] ?? '') !== 'not found') {
            error_log("delete-resource.php Cloudinary unexpected response for public_id={$publicId}: {$response}");
        }
    }
}

echo json_encode(['success' => true]);