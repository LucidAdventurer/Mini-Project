<?php
/* ========================================
 * NOTIFY NEW RESOURCE API
 * File: api/resources/notify-new-resource.php
 *
 * Called by the teacher's upload/publish flow after a material is saved.
 * Inserts one notification row per active student so they see the
 * "New Resource" popup and badge on their next page load or poll.
 *
 * POST body (JSON):
 * {
 *   "material_id": 42,
 *   "title":       "Introduction to Data Structures",
 *   "message":     "A new PDF has been added to your resources."   ← optional
 * }
 *
 * Only teachers may call this endpoint.
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// ── Auth: teacher only ──
$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

validateCsrfToken();

// ── Parse body ──
$body = json_decode(file_get_contents('php://input'), true);

$materialId = isset($body['material_id']) ? (int) $body['material_id'] : 0;
$title      = trim($body['title']   ?? '');
$message    = trim($body['message'] ?? '');

if ($materialId <= 0 || $title === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'material_id and title are required.']);
    exit;
}

// Verify the material actually exists and was uploaded by this teacher
$matCheck = safePreparedQuery(
    $conn,
    "SELECT material_id, title FROM study_materials
     WHERE material_id = ? AND uploaded_by = ? AND is_published = 1
     LIMIT 1",
    "ii",
    [$materialId, $teacherId]
);

if (!$matCheck['success'] || !$matCheck['result']) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Material not found or not yet published.']);
    exit;
}

$mat = $matCheck['result']->fetch_assoc();
$matCheck['result']->free();

if (!$mat) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Material not found.']);
    exit;
}

// Use DB title as the canonical source of truth
$notifTitle   = '📚 New Resource: ' . $mat['title'];
$notifMessage = $message ?: $currentUser['full_name'] . ' added a new study material for you.';
$linkUrl      = 'student-resources.php';

// ── Fetch all active student IDs ──
$studentsResult = safePreparedQuery(
    $conn,
    "SELECT user_id FROM users WHERE role = 'student' AND is_active = 1",
    "",
    []
);

if (!$studentsResult['success'] || !$studentsResult['result']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not fetch student list.']);
    exit;
}

$studentIds = [];
while ($row = $studentsResult['result']->fetch_assoc()) {
    $studentIds[] = (int) $row['user_id'];
}
$studentsResult['result']->free();

if (empty($studentIds)) {
    echo json_encode(['success' => true, 'notified' => 0, 'message' => 'No active students to notify.']);
    exit;
}

// ── Batch-insert notifications ──
// Build a single INSERT with one row per student for efficiency.
// notifications table schema assumed:
//   user_id, title, message, type, material_id, link_url, is_read, created_at
$placeholders = implode(', ', array_fill(0, count($studentIds), '(?, ?, ?, ?, ?, ?, 0, NOW())'));
$types        = str_repeat('issssi', count($studentIds));   // 6 typed params per student

$params = [];
foreach ($studentIds as $sid) {
    $params[] = $sid;
    $params[] = $notifTitle;
    $params[] = $notifMessage;
    $params[] = 'material';   // notification type
    $params[] = $linkUrl;
    $params[] = $materialId;
}

$insertResult = safePreparedQuery(
    $conn,
    "INSERT INTO notifications (user_id, title, message, type, link_url, material_id, is_read, created_at)
     VALUES $placeholders",
    $types,
    $params
);

if (!$insertResult['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to insert notifications.']);
    exit;
}

echo json_encode([
    'success'  => true,
    'notified' => $insertResult['affected_rows'],
    'message'  => "Notified {$insertResult['affected_rows']} student(s).",
]);
