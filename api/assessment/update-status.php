<?php
// ============================================================
// api/assessments/update-status.php
//
// Quick-publish endpoint called from teacher-assessments.php.
// Updates assessment status AND syncs assessment_targets.
//
// POST JSON {
//   assessment_id: int,
//   status: 'published'|'draft'|'archived',
//   targets?: [{ type: 'group'|'student', id: int }]
// }
// Returns { success: bool, message?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$conn = createDatabaseConnection();
if (!$conn) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database unavailable.']);
    exit;
}

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

// ── Validate inputs ──
$assessmentId = (int)($body['assessment_id'] ?? 0);
$status       = trim($body['status'] ?? '');

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid assessment ID.']);
    exit;
}

$allowedStatuses = ['published', 'draft', 'archived'];
if (!in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status.']);
    exit;
}

// ── Parse targets ──
$targets = [];
if (!empty($body['targets']) && is_array($body['targets'])) {
    foreach ($body['targets'] as $t) {
        $ttype = trim($t['type'] ?? '');
        $tid   = (int)($t['id'] ?? 0);
        if (in_array($ttype, ['group', 'student'], true) && $tid > 0) {
            $targets[] = ['type' => $ttype, 'id' => $tid];
        }
    }
}

// ── Require targets when publishing ──
if ($status === 'published' && empty($targets)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please assign the assessment to at least one group or student before publishing.']);
    exit;
}

// ── Verify ownership ──
$check = safePreparedQuery($conn,
    "SELECT assessment_id FROM assessments WHERE assessment_id = ? AND created_by = ?",
    "ii", [$assessmentId, $teacherId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Assessment not found or access denied.']);
    exit;
}

// ── Update status ──
$result = safePreparedQuery($conn,
    "UPDATE assessments SET status = ?, updated_at = NOW() WHERE assessment_id = ? AND created_by = ?",
    "sii", [$status, $assessmentId, $teacherId]
);

if (!$result['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update status.']);
    exit;
}

// ── Sync assessment_targets ──
safePreparedQuery($conn,
    "DELETE FROM assessment_targets WHERE assessment_id = ?",
    "i", [$assessmentId]
);
foreach ($targets as $t) {
    safePreparedQuery($conn,
        "INSERT INTO assessment_targets (assessment_id, target_type, target_id) VALUES (?, ?, ?)",
        "isi", [$assessmentId, $t['type'], $t['id']]
    );
}

// ── Notify assigned students when publishing ──
if ($status === 'published' && !empty($targets)) {
    $studentIds = [];

    foreach ($targets as $t) {
        if ($t['type'] === 'group') {
            $r = safePreparedQuery($conn,
                "SELECT student_id FROM group_members WHERE group_id = ?",
                "i", [$t['id']]
            );
            if ($r['success'] && $r['result']) {
                while ($row = $r['result']->fetch_assoc()) {
                    $studentIds[] = (int)$row['student_id'];
                }
                $r['result']->free();
            }
        } elseif ($t['type'] === 'student') {
            $studentIds[] = $t['id'];
        }
    }

    $studentIds = array_unique($studentIds);

    if (!empty($studentIds)) {
        // Fetch assessment title for the notification message
        $titleRow = safePreparedQuery($conn,
            "SELECT title FROM assessments WHERE assessment_id = ?",
            "i", [$assessmentId]
        );
        $assessTitle = '';
        if ($titleRow['success'] && $titleRow['result']) {
            $tr = $titleRow['result']->fetch_assoc();
            $assessTitle = $tr['title'] ?? '';
            $titleRow['result']->free();
        }

        $notifTitle   = 'New Assessment Assigned';
        $notifMessage = 'A new assessment "' . $assessTitle . '" has been assigned to you.';
        $notifType    = 'assessment';

        $stmt = $conn->prepare(
            "INSERT INTO notifications (user_id, title, message, type, related_entity_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        if ($stmt) {
            foreach ($studentIds as $uid) {
                $stmt->bind_param("isssi", $uid, $notifTitle, $notifMessage, $notifType, $assessmentId);
                $stmt->execute();
            }
            $stmt->close();
        }
    }
}

echo json_encode(['success' => true]);
