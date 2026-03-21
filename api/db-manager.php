<?php
/**
 * ============================================================
 * DATABASE MANAGER API — PTA Compatible
 * File: api/db-manager.php
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php'; // loads $conn + session

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function jsonOut(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Admin session check ────────────────────────────────────
// DEBUG MODE: dump session keys so we can identify the right key
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    jsonOut(['session_keys' => array_keys($_SESSION), 'session_data' => $_SESSION]);
}

$isAdmin = !empty($_SESSION['admin_id'])
    || !empty($_SESSION['adminId'])
    || !empty($_SESSION['admin'])
    || !empty($_SESSION['is_admin'])
    || (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'superadmin']))
    || (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'superadmin']))
    || (isset($_SESSION['type']) && in_array($_SESSION['type'], ['admin', 'superadmin']))
    || (isset($_SESSION['usertype']) && in_array($_SESSION['usertype'], ['admin', 'superadmin']));

if (!$isAdmin) {
    // Return session keys to help debug (remove in production)
    jsonOut([
        'success' => false,
        'error' => 'Unauthorized. Please log in as admin.',
        'debug_session_keys' => array_keys($_SESSION)
    ], 401);
}

// ── Ensure DB connection is alive ──────────────────────────
if (!ensureDatabaseConnection($conn)) {
    jsonOut(['success' => false, 'error' => 'Database connection failed.'], 503);
}

// ── Detect table structure once ────────────────────────────
function getTableInfo(mysqli $conn, string $tableKey): array {
    // Check for separate students/teachers tables first
    if (tableExists($conn, $tableKey)) {
        return ['table' => $tableKey, 'where' => '1=1'];
    }
    // Fall back to unified users table with role column
    if (tableExists($conn, 'users')) {
        $roleVal = $tableKey === 'students' ? 'student' : 'teacher';
        // detect role column name
        $res = $conn->query("DESCRIBE `users`");
        $cols = [];
        while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
        $roleCol = in_array('role', $cols) ? 'role'
            : (in_array('user_type', $cols) ? 'user_type'
            : (in_array('type', $cols) ? 'type' : null));
        if ($roleCol) {
            return ['table' => 'users', 'where' => "`$roleCol` = '$roleVal'", 'cols' => $cols];
        }
    }
    return ['table' => null, 'where' => '1=1'];
}

const PER_PAGE = 20;
const BLOCKED  = ['DROP', 'TRUNCATE', 'ALTER', 'RENAME'];

$method = $_SERVER['REQUEST_METHOD'];

// ══════════════════════════════════════════════════════════
// GET — list rows or export CSV
// ══════════════════════════════════════════════════════════
if ($method === 'GET') {
    $tableKey = $_GET['table'] ?? '';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $search   = trim($_GET['search'] ?? '');
    $export   = $_GET['export'] ?? '';

    if (!in_array($tableKey, ['students', 'teachers'])) {
        jsonOut(['success' => false, 'error' => 'Invalid table.'], 400);
    }

    $info = getTableInfo($conn, $tableKey);
    if (!$info['table']) {
        jsonOut(['success' => false, 'error' => "Could not find table for '$tableKey'. Check your DB structure."]);
    }

    $tbl   = $info['table'];
    $where = $info['where'];

    // Get all columns
    $res  = $conn->query("DESCRIBE `$tbl`");
    $cols = [];
    while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];

    // Build search clause
    $searchable = array_values(array_intersect($cols,
        ['name','full_name','email','reg_no','registration_no','department','subject','username','phone']
    ));
    $extraWhere = '';
    $searchParams = [];
    $searchTypes  = '';

    if ($search !== '' && $searchable) {
        $parts = array_map(fn($c) => "`$c` LIKE ?", $searchable);
        $extraWhere   = ' AND (' . implode(' OR ', $parts) . ')';
        $searchParams = array_fill(0, count($searchable), '%' . $search . '%');
        $searchTypes  = str_repeat('s', count($searchable));
    }

    $fullWhere = $where . $extraWhere;

    // Count
    $cStmt = $conn->prepare("SELECT COUNT(*) FROM `$tbl` WHERE $fullWhere");
    if ($searchParams) $cStmt->bind_param($searchTypes, ...$searchParams);
    $cStmt->execute();
    $total = (int)$cStmt->get_result()->fetch_row()[0];
    $cStmt->close();

    $limit  = $export === 'csv' ? 99999 : PER_PAGE;
    $offset = ($page - 1) * PER_PAGE;

    // Detect primary key column name
    $pkCol = 'id';
    $pkRes = $conn->query("SHOW KEYS FROM `$tbl` WHERE Key_name = 'PRIMARY'");
    if ($pkRes && $pkRow = $pkRes->fetch_assoc()) {
        $pkCol = $pkRow['Column_name'];
    }

    $stmt = $conn->prepare("SELECT * FROM `$tbl` WHERE $fullWhere ORDER BY `$pkCol` DESC LIMIT $limit OFFSET $offset");
    if ($searchParams) $stmt->bind_param($searchTypes, ...$searchParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($export === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $tableKey . '_' . date('Ymd_His') . '.csv"');
        if (!empty($rows)) {
            echo implode(',', array_keys($rows[0])) . "\n";
            foreach ($rows as $row) {
                echo implode(',', array_map(
                    fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"', $row
                )) . "\n";
            }
        }
        exit;
    }

    jsonOut(['success' => true, 'rows' => $rows, 'total' => $total, 'per_page' => PER_PAGE, 'pk' => $pkCol]);
}

// ══════════════════════════════════════════════════════════
// POST — query / toggle_status / delete
// ══════════════════════════════════════════════════════════
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) jsonOut(['success' => false, 'error' => 'Invalid JSON.'], 400);

    $action = $body['action'] ?? '';

    // ── Raw SQL query ──────────────────────────────────────
    if ($action === 'query') {
        $sql = trim($body['sql'] ?? '');
        if (!$sql) jsonOut(['success' => false, 'error' => 'No SQL provided.'], 400);

        foreach (BLOCKED as $kw) {
            if (stripos($sql, $kw) !== false) {
                jsonOut(['success' => false, 'error' => "Blocked: '$kw' is not allowed."], 403);
            }
        }

        $result = $conn->query($sql);
        if ($result === false) jsonOut(['success' => false, 'error' => $conn->error]);
        if ($result === true) jsonOut(['success' => true, 'affected_rows' => $conn->affected_rows]);

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        jsonOut(['success' => true, 'rows' => $rows]);
    }

    // ── Toggle is_active / status ──────────────────────────
    if ($action === 'toggle_status') {
        $id       = (int)($body['id'] ?? 0);
        $status   = (int)($body['status'] ?? 0);
        $tableKey = $body['table'] ?? '';

        if (!$id || !in_array($tableKey, ['students', 'teachers'])) {
            jsonOut(['success' => false, 'error' => 'Invalid parameters.'], 400);
        }

        $info = getTableInfo($conn, $tableKey);
        $tbl  = $info['table'];

        // Detect status column
        $res  = $conn->query("DESCRIBE `$tbl`");
        $cols = [];
        while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
        $statusCol = in_array('is_active', $cols) ? 'is_active'
            : (in_array('status', $cols) ? 'status' : 'is_active');

        $pkRes2 = $conn->query("SHOW KEYS FROM `$tbl` WHERE Key_name = 'PRIMARY'");
        $pkCol2 = 'id';
        if ($pkRes2 && $pkRow2 = $pkRes2->fetch_assoc()) $pkCol2 = $pkRow2['Column_name'];
        $stmt = $conn->prepare("UPDATE `$tbl` SET `$statusCol` = ? WHERE `$pkCol2` = ?");
        $stmt->bind_param('ii', $status, $id);
        $ok = $stmt->execute();
        $stmt->close();

        jsonOut(['success' => $ok, 'error' => $ok ? null : $conn->error]);
    }

    // ── Delete row ─────────────────────────────────────────
    if ($action === 'delete') {
        $id       = (int)($body['id'] ?? 0);
        $tableKey = $body['table'] ?? '';

        if (!$id || !in_array($tableKey, ['students', 'teachers'])) {
            jsonOut(['success' => false, 'error' => 'Invalid parameters.'], 400);
        }

        $info = getTableInfo($conn, $tableKey);
        $tbl  = $info['table'];

        $pkRes3 = $conn->query("SHOW KEYS FROM `$tbl` WHERE Key_name = 'PRIMARY'");
        $pkCol3 = 'id';
        if ($pkRes3 && $pkRow3 = $pkRes3->fetch_assoc()) $pkCol3 = $pkRow3['Column_name'];
        $stmt = $conn->prepare("DELETE FROM `$tbl` WHERE `$pkCol3` = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        jsonOut(['success' => $ok, 'error' => $ok ? null : $conn->error]);
    }

    jsonOut(['success' => false, 'error' => 'Unknown action.'], 400);
}

jsonOut(['success' => false, 'error' => 'Method not allowed.'], 405);
