<?php
// ============================================================
// api/groups/get-group-details.php
//
// Returns all groups for this teacher with full member lists.
//
// GET ?group_id=N  → single group with members
// GET              → all groups (summary only, no members)
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

$groupId = (int)($_GET['group_id'] ?? 0);

if ($groupId > 0) {
    // ── Single group with full member list ──
    $rg = safePreparedQuery($conn,
        "SELECT group_id, name, description, created_at
         FROM groups WHERE group_id = ? AND teacher_id = ?",
        "ii", [$groupId, $teacherId]
    );
    if (!$rg['success'] || !$rg['result'] || $rg['result']->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Group not found.']);
        exit;
    }
    $group = $rg['result']->fetch_assoc();
    $rg['result']->free();

    $rm = safePreparedQuery($conn,
        "SELECT u.user_id, u.full_name, u.email, u.department, u.registration_number,
                gm.joined_at
         FROM group_members gm
         JOIN users u ON u.user_id = gm.student_id
         WHERE gm.group_id = ?
         ORDER BY u.full_name ASC",
        "i", [$groupId]
    );
    $members = [];
    if ($rm['success'] && $rm['result']) {
        while ($row = $rm['result']->fetch_assoc()) {
            $members[] = $row;
        }
        $rm['result']->free();
    }
    $group['members']      = $members;
    $group['member_count'] = count($members);

    echo json_encode(['success' => true, 'group' => $group]);

} else {
    // ── All groups summary ──
    $r = safePreparedQuery($conn,
        "SELECT g.group_id, g.name, g.description, g.created_at,
                COUNT(gm.student_id) AS member_count
         FROM groups g
         LEFT JOIN group_members gm ON gm.group_id = g.group_id
         WHERE g.teacher_id = ?
         GROUP BY g.group_id
         ORDER BY g.created_at DESC",
        "i", [$teacherId]
    );
    $groups = [];
    if ($r['success'] && $r['result']) {
        while ($row = $r['result']->fetch_assoc()) {
            $groups[] = $row;
        }
        $r['result']->free();
    }
    echo json_encode(['success' => true, 'groups' => $groups]);
}
