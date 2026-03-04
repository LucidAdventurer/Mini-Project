<?php
/* ========================================
 * DATABASE CONNECTION GUARD
 * File: db-guard.php
 *
 * SECURITY CHANGE:
 * validateSession() now enforces CSRF token verification
 * on every POST request automatically.
 *
 * HOW IT WORKS:
 * 1. On login, config.php seeds $_SESSION['csrf_token'].
 * 2. Your JS reads the token from GET /api/csrf-token.php
 *    and attaches it to every state-changing request:
 *      fetch(url, {
 *        method: 'POST',
 *        headers: { 'X-CSRF-Token': csrfToken, 'Content-Type': 'application/json' },
 *        body: JSON.stringify(payload)
 *      })
 * 3. validateSession() checks the header before anything else.
 * ======================================== */

if (defined('DB_GUARD_LOADED')) {
    return;
}
define('DB_GUARD_LOADED', true);

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
        header('Content-Type: application/json; charset=utf-8');
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
    $sentToken    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!$sentToken || $sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
        error_log("CSRF validation failed. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
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
                throw new \RuntimeException("Connection unavailable");
            }

            $result = $conn->query($query);

            if ($result === false) {
                if (in_array($conn->errno, [2006, 2013, 2055])) {
                    throw new \RuntimeException("Connection lost during query");
                }
                error_log("Query failed: " . $conn->error . " | " . substr($query, 0, 100));
                return false;
            }

            return $result;

        } catch (Throwable $e) {
            $attempt++;
            error_log("Query attempt $attempt failed: " . $e->getMessage());

            if ($attempt < $maxRetries) {
                ensureDatabaseConnection($conn);
                usleep(200_000); // 200ms — lighter retry delay
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
                throw new \RuntimeException("Connection unavailable");
            }

            $stmt = $conn->prepare($query);

            if (!$stmt) {
                throw new \RuntimeException("Prepare failed: " . $conn->error);
            }

            if ($types !== '' && !empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            try {
                $stmt->execute();
            } catch (Throwable $e) {
                if (in_array($stmt->errno, [2006, 2013, 2055], true)) {
                    $stmt->close();
                    throw new \RuntimeException("Connection lost during execution");
                }
                $error = $e->getMessage();
                $stmt->close();
                error_log("Statement execution failed: $error");
                return ['success' => false, 'error' => $error, 'result' => null, 'insert_id' => 0, 'affected_rows' => 0];
            }

            $result       = $stmt->get_result();
            $result       = ($result instanceof mysqli_result) ? $result : null;
            $insertId     = $stmt->insert_id;
            $affectedRows = $stmt->affected_rows;
            $stmt->close();

            return ['success' => true, 'result' => $result, 'insert_id' => $insertId, 'affected_rows' => $affectedRows];

        } catch (Throwable $e) {
            $attempt++;
            error_log("Prepared query attempt $attempt failed: " . $e->getMessage());

            if ($attempt < $maxRetries) {
                ensureDatabaseConnection($conn);
                usleep(200_000); // 200ms — lighter retry delay
            } else {
                return ['success' => false, 'error' => "Failed after $maxRetries attempts", 'result' => null, 'insert_id' => 0, 'affected_rows' => 0];
            }
        }
    }

    return ['success' => false, 'error' => 'Unexpected failure', 'result' => null, 'insert_id' => 0, 'affected_rows' => 0];
}

/**
 * Fetch a single user row by user_id.
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
 * Validate the session and enforce CSRF on POST requests.
 *
 * All protected API endpoints call this function first.
 * No other changes are needed in those files — CSRF is enforced here.
 *
 * @param mysqli      $conn         DB connection (passed by reference)
 * @param string|null $requiredRole 'student' | 'teacher' | 'admin' | null (any)
 * @return array      Verified user data row
 */
/**
 * Returns true if the request was made via fetch/XHR (AJAX).
 * Checks X-Requested-With header (set manually by fetch calls)
 * and Content-Type: application/json as a secondary signal.
 */
function isAjaxRequest(): bool {
    $xhr         = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    $jsonContent = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    $xCsrf       = isset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $accept      = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    return $xhr || $jsonContent || $xCsrf || $accept;
}

/**
 * Abort with either a JSON 401/403 response (AJAX) or a redirect (browser).
 */
function authFail(int $code, string $error, string $redirectParam): never {
    if (isAjaxRequest()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $error]);
    } else {
        header("Location: index.html?error=" . $redirectParam);
    }
    exit;
}

function validateSession(mysqli &$conn, ?string $requiredRole = null): array {
    // ── 1. Session exists ──
    if (!isset($_SESSION['uid']) || !isset($_SESSION['role'])) {
        authFail(401, 'Session expired. Please log in again.', 'session_expired');
    }

    // ── 2. Role check ──
    if ($requiredRole !== null && $_SESSION['role'] !== $requiredRole) {
        authFail(403, 'Unauthorized. Insufficient permissions.', 'unauthorized');
    }

    // ── 3. CSRF check on all state-changing requests ──
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        validateCsrfToken();
    }

    // ── 4. User still exists and is active ──
    // Cache user record in session for 5 minutes to avoid a DB hit on every request
    $now = time();
    if (
        isset($_SESSION['_user_cache'], $_SESSION['_user_cache_ts']) &&
        ($now - $_SESSION['_user_cache_ts']) < 300
    ) {
        return $_SESSION['_user_cache'];
    }

    $user = getUserData($conn, (int) $_SESSION['uid']);

    if (!$user) {
        session_destroy();
        authFail(401, 'User account not found.', 'user_not_found');
    }

    if (!$user['is_active']) {
        session_destroy();
        authFail(403, 'Account has been deactivated.', 'account_deactivated');
    }

    // Store in session cache
    $_SESSION['_user_cache']    = $user;
    $_SESSION['_user_cache_ts'] = $now;

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
    $types        = '';
    foreach ($values as $v) {
        if (is_int($v))   $types .= 'i';
        elseif (is_float($v)) $types .= 'd';
        else              $types .= 's';
    }

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
    $types     = '';
    foreach ($allParams as $v) {
        if (is_int($v))       $types .= 'i';
        elseif (is_float($v)) $types .= 'd';
        else                  $types .= 's';
    }

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

    } catch (Throwable $e) {
        $health['warnings'][] = 'Health check error: ' . $e->getMessage();
    }

    return $health;
}