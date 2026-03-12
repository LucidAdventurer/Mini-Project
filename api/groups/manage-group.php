<?php
// ============================================================
// api/groups/manage-group.php
//
// Handles all group CRUD + member management.
//
// POST body: { action, ...params }
//
// action = 'create'  { name, description, student_ids[] }
// action = 'update'  { group_id, name, description }
// action = 'delete'  { group_id }
// action = 'add_members'    { group_id, student_ids[] }
// action = 'remove_member'  { group_id, student_id }
//
// All responses: { success: bool, error?: string, ...extras }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$conn = createDatabaseConnection();
if (!$conn) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Database unavailable.']);
    exit;
}

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($body['action'] ?? '');

// ── Helper: verify group belongs to this teacher ──
function ownsGroup(mysqli $conn, int $groupId, int $teacherId): bool {
    global $safePreparedQuery;
    $r = safePreparedQuery($conn,
        "SELECT group_id FROM groups WHERE group_id = ? AND teacher_id = ?",
        "ii", [$groupId, $teacherId]
    );
    return $r['success'] && $r['result'] && $r['result']->num_rows > 0;
}

switch ($action) {

    // ── Create group ──────────────────────────────────────────
    case 'create': {
        $name        = trim($body['name'] ?? '');
        $description = trim($body['description'] ?? '');
        $studentIds  = array_filter(array_map('intval', $body['student_ids'] ?? []));

        if ($name === '') {
            echo json_encode(['success' => false, 'error' => 'Group name is required.']);
            exit;
        }

        $r = safePreparedQuery($conn,
            "INSERT INTO groups (teacher_id, name, description) VALUES (?, ?, ?)",
            "iss", [$teacherId, $name, $description]
        );
        if (!$r['success'] || !$r['insert_id']) {
            echo json_encode(['success' => false, 'error' => 'Failed to create group.']);
            exit;
        }
        $groupId = $r['insert_id'];

        // Add initial members
        foreach ($studentIds as $sid) {
            safePreparedQuery($conn,
                "INSERT IGNORE INTO group_members (group_id, student_id) VALUES (?, ?)",
                "ii", [$groupId, $sid]
            );
        }

        echo json_encode(['success' => true, 'group_id' => $groupId]);
        break;
    }

    // ── Update group name/description ─────────────────────────
    case 'update': {
        $groupId     = (int)($body['group_id'] ?? 0);
        $name        = trim($body['name'] ?? '');
        $description = trim($body['description'] ?? '');

        if (!$groupId || $name === '') {
            echo json_encode(['success' => false, 'error' => 'Group ID and name are required.']);
            exit;
        }
        if (!ownsGroup($conn, $groupId, $teacherId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied.']);
            exit;
        }

        $r = safePreparedQuery($conn,
            "UPDATE groups SET name = ?, description = ? WHERE group_id = ?",
            "ssi", [$name, $description, $groupId]
        );
        echo json_encode(['success' => $r['success']]);
        break;
    }

    // ── Delete group ──────────────────────────────────────────
    case 'delete': {
        $groupId = (int)($body['group_id'] ?? 0);
        if (!$groupId) {
            echo json_encode(['success' => false, 'error' => 'Group ID required.']);
            exit;
        }
        if (!ownsGroup($conn, $groupId, $teacherId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied.']);
            exit;
        }

        // Members cascade if FK set; explicit delete is safer
        safePreparedQuery($conn, "DELETE FROM group_members WHERE group_id = ?", "i", [$groupId]);
        safePreparedQuery($conn, "DELETE FROM assessment_targets WHERE target_type = 'group' AND target_id = ?", "i", [$groupId]);
        $r = safePreparedQuery($conn, "DELETE FROM groups WHERE group_id = ?", "i", [$groupId]);
        echo json_encode(['success' => $r['success']]);
        break;
    }

    // ── Add members ───────────────────────────────────────────
    case 'add_members': {
        $groupId    = (int)($body['group_id'] ?? 0);
        $studentIds = array_filter(array_map('intval', $body['student_ids'] ?? []));

        if (!$groupId || empty($studentIds)) {
            echo json_encode(['success' => false, 'error' => 'Group ID and at least one student required.']);
            exit;
        }
        if (!ownsGroup($conn, $groupId, $teacherId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied.']);
            exit;
        }

        $added = 0;
        foreach ($studentIds as $sid) {
            $r = safePreparedQuery($conn,
                "INSERT IGNORE INTO group_members (group_id, student_id) VALUES (?, ?)",
                "ii", [$groupId, $sid]
            );
            if ($r['success'] && $r['affected_rows'] > 0) $added++;
        }

        echo json_encode(['success' => true, 'added' => $added]);
        break;
    }

    // ── Remove single member ──────────────────────────────────
    case 'remove_member': {
        $groupId   = (int)($body['group_id'] ?? 0);
        $studentId = (int)($body['student_id'] ?? 0);

        if (!$groupId || !$studentId) {
            echo json_encode(['success' => false, 'error' => 'Group ID and student ID required.']);
            exit;
        }
        if (!ownsGroup($conn, $groupId, $teacherId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied.']);
            exit;
        }

        $r = safePreparedQuery($conn,
            "DELETE FROM group_members WHERE group_id = ? AND student_id = ?",
            "ii", [$groupId, $studentId]
        );
        echo json_encode(['success' => $r['success']]);
        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
