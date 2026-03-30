<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// DB connection
$conn = createDatabaseConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB connection failed']);
    exit;
}

// Auth
$user = validateSession($conn, 'student');
$userId = (int)$user['user_id'];

// Input
$data = json_decode(file_get_contents("php://input"), true);
$assessmentId = (int)($data['assessment_id'] ?? 0);

if ($assessmentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID']);
    exit;
}

// Current time
$now = date('Y-m-d H:i:s');

// Validate assessment access
$check = safePreparedQuery($conn,
    "SELECT assessment_id, max_attempts
     FROM assessments
     WHERE assessment_id = ?
     AND status = 'published'
     AND (start_time IS NULL OR start_time <= ?)
     AND (end_time IS NULL OR end_time >= ?)",
    "iss",
    [$assessmentId, $now, $now]
);

if (!$check['success'] || $check['result']->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Assessment not available']);
    exit;
}

$row = $check['result']->fetch_assoc();
$maxAttempts = (int)$row['max_attempts'];

// Check existing in_progress attempt
$existing = safePreparedQuery($conn,
    "SELECT attempt_id
     FROM assessment_attempts
     WHERE assessment_id = ? AND user_id = ? AND status = 'in_progress'
     ORDER BY created_at DESC LIMIT 1",
    "ii",
    [$assessmentId, $userId]
);

if ($existing['success'] && $existing['result']->num_rows > 0) {
    $row = $existing['result']->fetch_assoc();
    echo json_encode([
        'success' => true,
        'attempt_id' => (int)$row['attempt_id'],
        'resumed' => true
    ]);
    exit;
}

// Count used attempts
$count = safePreparedQuery($conn,
    "SELECT COUNT(*) AS total
     FROM assessment_attempts
     WHERE assessment_id = ? AND user_id = ?
     AND status IN ('submitted', 'timeout')",
    "ii",
    [$assessmentId, $userId]
);

$used = 0;
if ($count['success']) {
    $used = (int)$count['result']->fetch_assoc()['total'];
}

if ($used >= $maxAttempts) {
    echo json_encode(['success' => false, 'error' => 'No attempts left']);
    exit;
}

// Create attempt
$attemptNumber = $used + 1;
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

$insert = safePreparedQuery($conn,
    "INSERT INTO assessment_attempts
    (assessment_id, user_id, attempt_number, created_at, status, ip_address, user_agent)
    VALUES (?, ?, ?, ?, 'in_progress', ?, ?)",
    "iiisss",
    [$assessmentId, $userId, $attemptNumber, $now, $ip, $agent]
);

if ($insert['success'] && $insert['insert_id']) {
    echo json_encode([
        'success' => true,
        'attempt_id' => $insert['insert_id']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $insert['error'] ?? 'Insert failed'
    ]);
}