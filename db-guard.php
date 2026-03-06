<?php
/* ========================================
 * DATABASE CONNECTION GUARD
 * File: db-guard.php
 *
 * FIX: validateSession() now detects API/JSON requests and
 * returns a JSON error response instead of doing an HTML
 * redirect. This fixes all api/ endpoints that were silently
 * getting a 302 redirect instead of a usable error.
 *
 * A request is treated as an API call when ANY of these is true:
 *   - URL path contains /api/
 *   - Accept header includes application/json
 *   - Content-Type header is application/json
 *   - X-Requested-With: XMLHttpRequest header is present
 * ======================================== */

if (defined('DB_GUARD_LOADED')) {
    return;
}
define('DB_GUARD_LOADED', true);

// ────────────────────────────────────────
// HELPER: detect API / JSON context
// ────────────────────────────────────────

function isApiRequest(): bool {
    $uri         = $_SERVER['REQUEST_URI']    ?? '';
    $accept      = $_SERVER['HTTP_ACCEPT']    ?? '';
    $contentType = $_SERVER['CONTENT_TYPE']   ?? '';
    $xrw         = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

    return str_contains($uri, '/api/')
        || str_contains($accept, 'application/json')
        || str_contains($contentType, 'application/json')
        || strtolower($xrw) === 'xmlhttprequest';
}

/**
 * Abort with a JSON error or an HTML redirect depending on context.
 */
function sessionAbort(int $code, string $jsonError, string $htmlRedirect): never {
    http_response_code($code);
    if (isApiRequest()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $jsonError]);
    } else {
        header('Location: ' . $htmlRedirect);
    }
    exit;
}

// ────────────────────────────────────────
// CSRF HELPERS
// ────────────────────────────────────────

/**
 * Return the current session's CSRF token.
 * Aborts with 401 if the session has no token (not logged in).
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'No active session.']);
        exit;
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the CSRF token sent with a POST request.
 *
 * Reads X-CSRF-Token header first (AJAX/fetch calls),
 * then falls back to $_POST['csrf_token'] (traditional forms).
 *
 * Uses hash_equals() to prevent timing attacks.
 * Aborts with 403 on failure.
 */
function validateCsrfToken(): void {
    $sentToken = $_SERVER['HTTP_X_CSRF_TOKEN']
              ?? $_POST['csrf_token']
              ?? '';

    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if ($sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
        error_log("CSRF validation failed. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token.']);
        exit;
    }
}

// ────────────────────────────────────────
// CONNECTION HELPERS
// ────────────────────────────────────────

/**
 * Safe query with automatic retry on connection loss.
 */
function safeQuery(mysqli &$conn, string $query, int $maxRetries = 3): mysqli_result|bool {
    $attempt = 0;

    while ($attempt < $maxRetries) {
        try {
            if (!ensureDatabaseConnection($conn)) {
                throw new Exception("Connection unavailable");
            }

            $result = $conn->query($query);

            if ($result === false) {
                if (in_array($conn->errno, [2006, 2013, 2055])) {
                    throw new Exception("Connection lost during query");
                }
                error_log("Query failed: " . $conn->error . " | " . substr($query, 0, 100));
                return false;
            }

            return $result;

        } catch (Exception $e) {
            $attempt++;
            error_log("Query attempt $attempt failed: " . $e->getMessage());

            if ($attempt < $maxRetries) {
                ensureDatabaseConnection($conn);
                sleep(1);
            } else {
                error_log("Query failed after $maxRetries attempts");
                return false;
            }
        }
    }

    return false;
}

/**
 * Safe prepared statement with retry.
 * Returns ['success', 'result', 'insert_id', 'affected_rows', 'error'].
 */
function safePreparedQuery(mysqli &$conn, string $query, string $types = "", array $params = []): array {
    $maxRetries = 3;
    $attempt    = 0;

    while ($attempt < $maxRetries) {
        try {
            if (!ensureDatabaseConnection($conn)) {
                throw new Exception("Connection unavailable");
            }

            $stmt = $conn->prepare($query);

            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            if ($types !== '' && !empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            if (!$stmt->execute()) {
                if (in_array($stmt->errno, [2006, 2013, 2055])) {
                    $stmt->close();
                    throw new Exception("Connection lost during execution");
                }
                $error = $stmt->error;
                $stmt->close();
                error_log("Statement execution failed: $error");
                return ['success' => false, 'error' => $error, 'result' => null, 'insert_id' => 0, 'affected_rows' => 0];
            }

            $result       = $stmt->result_metadata() ? $stmt->get_result() : null;
            $insertId     = $stmt->insert_id;
            $affectedRows = $stmt->affected_rows;
            $stmt->close();

            return ['success' => true, 'result' => $result, 'insert_id' => $insertId, 'affected_rows' => $affectedRows];

        } catch (Exception $e) {
            $attempt++;
            error_log("Prepared query attempt $attempt failed: " . $e->getMessage());

            if ($attempt < $maxRetries) {
                ensureDatabaseConnection($conn);
                sleep(1);
            } else {
                return ['success' => false, 'error' => "Failed after $maxRetries attempts", 'result' => null, 'insert_id' => 0, 'affected_rows' => 0];
            }
        }
    }

    return ['success' => false, 'error' => 'Unexpected failure', 'result' => null, 'insert_id' => 0, 'affected_rows' => 0];
}

/**
 * Fetch a single user row by user_id.
 * Accepts both $_SESSION['uid'] and $_SESSION['user_id'] naming conventions.
 */
function getUserData(mysqli &$conn, int $userId): ?array {
    $result = safePreparedQuery(
        $conn,
        "SELECT user_id, full_name, email, user_type, department,
                registration_number, is_verified, is_active
         FROM users WHERE user_id = ?",
        "i",
        [$userId]
    );

    if ($result['success'] && $result['result']) {
        $userData = $result['result']->fetch_assoc();
        $result['result']->free();
        return $userData ?: null;
    }

    return null;
}

/**
 * Resolve the logged-in user's ID from session.
 * Supports both $_SESSION['uid'] and $_SESSION['user_id'].
 */
function getSessionUserId(): int {
    // Support both naming conventions
    if (!empty($_SESSION['uid']))     return (int) $_SESSION['uid'];
    if (!empty($_SESSION['user_id'])) return (int) $_SESSION['user_id'];
    return 0;
}

/**
 * Resolve the logged-in user's role from session.
 * Supports both $_SESSION['role'] and $_SESSION['user_type'].
 */
function getSessionRole(): string {
    if (!empty($_SESSION['role']))      return $_SESSION['role'];
    if (!empty($_SESSION['user_type'])) return $_SESSION['user_type'];
    return '';
}

/**
 * Validate the session and enforce CSRF on POST requests.
 *
 * For API/JSON requests: returns JSON error responses (never redirects).
 * For page requests: redirects to login page as before.
 *
 * @param mysqli      $conn         DB connection (passed by reference)
 * @param string|null $requiredRole 'student' | 'teacher' | 'admin' | null (any role)
 * @return array      Verified user data row
 */
function validateSession(mysqli &$conn, ?string $requiredRole = null): array {
    $uid  = getSessionUserId();
    $role = getSessionRole();

    // ── 1. Session must exist ──
    if ($uid === 0 || $role === '') {
        sessionAbort(401, 'Session expired. Please log in again.',
            'index.html?error=session_expired');
    }

    // ── 2. Role check ──
    if ($requiredRole !== null && $role !== $requiredRole) {
        sessionAbort(403, 'Access denied. Insufficient permissions.',
            'index.html?error=unauthorized');
    }

    // ── 3. CSRF check on all state-changing requests ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateCsrfToken();
    }

    // ── 4. User still exists and is active in DB ──
    $user = getUserData($conn, $uid);

    if (!$user) {
        session_destroy();
        sessionAbort(401, 'User account not found. Please log in again.',
            'index.html?error=user_not_found');
    }

    if (!$user['is_active']) {
        session_destroy();
        sessionAbort(403, 'Your account has been deactivated.',
            'index.html?error=account_deactivated');
    }

    return $user;
}

// ────────────────────────────────────────
// QUICK INSERT / UPDATE WRAPPERS
// ────────────────────────────────────────

function dbInsert(mysqli &$conn, string $table, array $data): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return ['success' => false, 'error' => 'Invalid table name'];
    }
    $columns      = array_keys($data);
    $values       = array_values($data);
    $columnList   = implode(', ', array_map(fn($c) => "`$c`", $columns));
    $placeholders = implode(', ', array_fill(0, count($values), '?'));
    $types        = str_repeat('s', count($values));

    return safePreparedQuery($conn, "INSERT INTO `$table` ($columnList) VALUES ($placeholders)", $types, $values);
}

function dbUpdate(mysqli &$conn, string $table, array $data, string $where, array $whereParams = []): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return ['success' => false, 'error' => 'Invalid table name'];
    }
    $setParts = [];
    $values   = [];
    foreach ($data as $column => $value) {
        $setParts[] = "`$column` = ?";
        $values[]   = $value;
    }
    $allParams = array_merge($values, $whereParams);
    $types     = str_repeat('s', count($allParams));

    return safePreparedQuery($conn, "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE $where", $types, $allParams);
}

// ────────────────────────────────────────
// CONNECTION HEALTH
// ────────────────────────────────────────

function getConnectionHealth(mysqli &$conn): array {
    $health = ['connected' => false, 'uptime' => 0, 'threads_connected' => 0, 'questions' => 0, 'warnings' => []];

    try {
        if (!$conn) {
            $health['warnings'][] = 'No connection object';
            return $health;
        }

        $result = safeQuery($conn, "SELECT 1");
        if (!$result) {
            $health['warnings'][] = 'Connection test failed';
            return $health;
        }
        $result->free();
        $health['connected'] = true;

        $result = safeQuery($conn, "SHOW STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Questions')");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $key          = strtolower($row['Variable_name']);
                $health[$key] = (int) $row['Value'];
            }
            $result->free();
        }

        if ($health['threads_connected'] > 100) {
            $health['warnings'][] = 'High thread count: ' . $health['threads_connected'];
        }

    } catch (Exception $e) {
        $health['warnings'][] = 'Health check error: ' . $e->getMessage();
    }

    return $health;
}