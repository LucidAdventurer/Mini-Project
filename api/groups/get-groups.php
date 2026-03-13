<?php
// ============================================================
// api/groups/get-groups.php
//
// Returns all groups owned by the logged-in teacher,
// with member counts.
//
// GET (no params)
// Returns { success: bool, groups: [{ group_id, name, description, member_count }] }
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

$r = safePreparedQuery($conn,
    "SELECT g.group_id, g.name, g.description,
            COUNT(gm.student_id) AS member_count
     FROM groups g
     LEFT JOIN group_members gm ON gm.group_id = g.group_id
     WHERE g.teacher_id = ?
     GROUP BY g.group_id
     ORDER BY g.name ASC",
    "i", [$teacherId]
);

$groups = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $groups[] = [
            'group_id'     => (int) $row['group_id'],
            'name'         => $row['name'],
            'description'  => $row['description'] ?? '',
            'member_count' => (int) $row['member_count'],
        ];
    }
    $r['result']->free();
}

echo json_encode(['success' => true, 'groups' => $groups]);