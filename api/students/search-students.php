<?php
// api/students/search-students.php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'teacher');

$q     = trim($_GET['q'] ?? '');
$limit = min(30, max(1, (int)($_GET['limit'] ?? 20)));

if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'students' => []]);
    exit;
}

$like = '%' . $q . '%';

$r = safePreparedQuery($conn,
    "SELECT user_id, full_name, email, department, registration_number
     FROM users
     WHERE role = 'student'
       AND is_active = 1
       AND (full_name LIKE ? OR email LIKE ? OR registration_number LIKE ?)
     ORDER BY full_name ASC
     LIMIT ?",
    "sssi", [$like, $like, $like, $limit]
);

$students = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $students[] = [
            'user_id'             => (int) $row['user_id'],
            'full_name'           => $row['full_name'],
            'email'               => $row['email'],
            'department'          => $row['department'] ?? '',
            'registration_number' => $row['registration_number'] ?? '',
        ];
    }
    $r['result']->free();
}

echo json_encode(['success' => true, 'students' => $students]);