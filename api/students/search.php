<?php
// api/students/search.php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'teacher');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'students' => []]);
    exit;
}

$like = '%' . $q . '%';

$r = safePreparedQuery($conn,
    "SELECT user_id, full_name, email, registration_number, department
     FROM users
     WHERE role = 'student'
       AND is_active = 1
       AND (full_name LIKE ? OR registration_number LIKE ? OR email LIKE ?)
     ORDER BY full_name ASC
     LIMIT 15",
    "sss", [$like, $like, $like]
);

$students = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $students[] = [
            'user_id'             => (int)$row['user_id'],
            'full_name'           => $row['full_name'],
            'email'               => $row['email'],
            'registration_number' => $row['registration_number'] ?? '',
            'department'          => $row['department'] ?? '',
        ];
    }
    $r['result']->free();
}

echo json_encode(['success' => true, 'students' => $students]);