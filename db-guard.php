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
 *
 * MIGRATION NOTE (mysqli -> PDO/Postgres):
 * - All `mysqli` type-hints replaced with `PDO`. This is what was
 *   throwing a fatal TypeError on every page that calls
 *   validateSession()/safePreparedQuery() etc., since $conn is a
 *   PDO instance now.
 * - safePreparedQuery() still returns ['success','result','insert_id',
 *   'affected_rows','error'] with the SAME shape as before, but
 *   'result' is now a PdoResultShim object that implements
 *   fetch_assoc() / free() / num_rows on top of a PDOStatement, so
 *   every existing dashboard file that calls ->fetch_assoc()/->free()
 *   on the result keeps working unmodified.
 * - MySQL connection-loss errno checks (2006/2013/2055) replaced with
 *   SQLSTATE class '08' (Connection Exception), which is what
 *   PDO_PGSQL raises for lost/broken connections.
 * - SHOW STATUS (MySQL-only) replaced with Postgres equivalents
 *   (pg_stat_activity / pg_postmaster_start_time) in getConnectionHealth().
 * - Backtick identifier quoting (`` `col` ``) replaced with Postgres
 *   double-quote quoting ("col") in dbInsert()/dbUpdate().
 * - is_active check in validateSession() now normalizes Postgres's
 *   't'/'f' boolean strings before the truthiness check (same issue
 *   flagged in login.php's migration notes).
 * ======================================== */

if (defined('DB_GUARD_LOADED')) {
    return;
}
define('DB_GUARD_LOADED', true);

// ────────────────────────────────────────
// PDO RESULT COMPATIBILITY SHIM
// ────────────────────────────────────────

/**
 * Wraps a fully-buffered result set so existing call sites written
 * against mysqli_result (->fetch_assoc(), ->free(), ->num_rows) keep
 * working unchanged against PDO underneath.
 */
class PdoResultShim {
    private array $rows;
    private int   $pos = 0;
    public int    $num_rows;

    public function __construct(array $rows) {
        $this->rows     = $rows;
        $this->num_rows = count($rows);
    }

    public function fetch_assoc(): ?array {
        if ($this->pos >= count($this->rows)) {
            return null;
        }
        return $this->rows[$this->pos++];
    }

    public function fetch_all(): array {
        return $this->rows;
    }

    public function free(): void {
        // No-op: rows are already fully buffered in PHP memory.
    }
}

/**
 * Normalizes a Postgres boolean value fetched via PDO into a real PHP bool.
 * PDO_PGSQL returns boolean columns as the strings 't'/'f' (not native
 * PHP true/false), so a plain truthiness check is unsafe.
 */
function pgBoolGuard($val): bool {
    if (is_bool($val)) return $val;
    if (is_int($val))  return $val === 1;
    return in_array($val, ['t', 'true', '1'], true);
}

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
 * True if a PDOException represents a lost/broken connection
 * (SQLSTATE class 08 = Connection Exception) rather than a normal
 * query error (bad SQL, constraint violation, etc.).
 */
function isConnectionLossError(PDOException $e): bool {
    $sqlstate = is_array($e->errorInfo ?? null) ? ($e->errorInfo[0] ?? $e->getCode()) : $e->getCode();
    return is_string($sqlstate) && str_starts_with($sqlstate, '08');
}

/**
 * Safe query with automatic retry on connection loss.
 */
function safeQuery(PDO $conn, string $query, int $maxRetries = 3): PDOStatement|false {
    $attempt = 0;

    while ($attempt < $maxRetries) {
        try {
            if (!ensureDatabaseConnection($conn)) {
                throw new Exception("Connection unavailable");
            }

            $stmt = $conn->query($query);
            return $stmt; // PDO throws on failure (assuming ERRMODE_EXCEPTION), so reaching here means success

        } catch (PDOException $e) {
            if (!isConnectionLossError($e)) {
                error_log("Query failed: " . $e->getMessage() . " | " . substr($query, 0, 100));
                return false;
            }
            $attempt++;
            error_log("Query attempt $attempt failed (connection loss): " . $e->getMessage());

            if ($attempt < $maxRetries) {
                ensureDatabaseConnection($conn);
                sleep(min(1, $attempt));
            } else {
                error_log("Query failed after $maxRetries attempts");
                return false;
            }
        } catch (Exception $e) {
            $attempt++;
            error_log("Query attempt $attempt failed: " . $e->getMessage());

            if ($attempt < $maxRetries) {
                ensureDatabaseConnection($conn);
                sleep(min(1, $attempt));
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
 *
 * NOTE: $types is accepted for backward compatibility with existing
 * call sites (e.g. "i", "ss") but is no longer used — PDO binds
 * positional '?' placeholders directly from $params, and Postgres
 * infers parameter types from query context.
 */
function safePreparedQuery(PDO $conn, string $query, string $types = "", array $params = []): array {
    // Extend time limit for bulk operations (INSERT/UPDATE with many rows)
    if (php_sapi_name() !== 'cli') {
        $current = ini_get('max_execution_time');
        if ($current > 0 && $current < 300) set_time_limit(300);
    }
    $maxRetries = 3;
    $attempt    = 0;

    while ($attempt < $maxRetries) {
        try {
            if (!ensureDatabaseConnection($conn)) {
                throw new Exception("Connection unavailable");
            }

            $stmt = $conn->prepare($query);

            if (!$stmt) {
                throw new Exception("Prepare failed");
            }

            $stmt->execute($params);

            // SELECT-type statements have columns; INSERT/UPDATE/DELETE don't.
            $result = null;
            if ($stmt->columnCount() > 0) {
                $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $result = new PdoResultShim($rows);
            }

            $insertId     = 0;
            $affectedRows = $stmt->rowCount();

            // Best-effort last insert id (works when the table's PK is a
            // serial/identity column tied to the session's default sequence).
            if (stripos(trim($query), 'INSERT') === 0) {
                try {
                    $insertId = (int) $conn->lastInsertId();
                } catch (PDOException $e) {
                    $insertId = 0;
                }
            }

            return ['success' => true, 'result' => $result, 'insert_id' => $insertId, 'affected_rows' => $affectedRows];

        } catch (PDOException $e) {
            if (!isConnectionLossError($e)) {
                error_log("Statement execution failed: " . $e->getMessage());
                return ['success' => false, 'error' => $e->getMessage(), 'result' => null, 'insert_id' => 0, 'affected_rows' => 0];
            }
            $attempt++;
            error_log("Prepared query attempt $attempt failed (connection loss): " . $e->getMessage());

            if ($attempt < $maxRetries) {
                ensureDatabaseConnection($conn);
                sleep(min(1, $attempt));
            } else {
                return ['success' => false, 'error' => "Failed after $maxRetries attempts", 'result' => null, 'insert_id' => 0, 'affected_rows' => 0];
            }
        } catch (Exception $e) {
            $attempt++;
            error_log("Prepared query attempt $attempt failed: " . $e->getMessage());

            if ($attempt < $maxRetries) {
                ensureDatabaseConnection($conn);
                sleep(min(1, $attempt)); // only sleep after first retry, not immediately
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
function getUserData(PDO $conn, int $userId): ?array {
    $result = safePreparedQuery(
        $conn,
        "SELECT user_id, full_name, email, role, department,
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
 * Supports both $_SESSION['role'] and legacy $_SESSION['user_type'].
 */
function getSessionRole(): string {
    if (!empty($_SESSION['role']))      return $_SESSION['role'];
    if (!empty($_SESSION['user_type'])) return $_SESSION['user_type']; // legacy fallback
    return '';
}

/**
 * Validate the session and enforce CSRF on POST requests.
 *
 * For API/JSON requests: returns JSON error responses (never redirects).
 * For page requests: redirects to login page as before.
 *
 * @param PDO         $conn         DB connection
 * @param string|null $requiredRole 'student' | 'teacher' | 'admin' | null (any role)
 * @return array      Verified user data row
 */
function validateSession(PDO $conn, ?string $requiredRole = null): array {
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

    // FIX: Postgres booleans arrive via PDO as 't'/'f' strings, both of
    // which are truthy in plain PHP — normalize before checking.
    if (!pgBoolGuard($user['is_active'])) {
        session_destroy();
        sessionAbort(403, 'Your account has been deactivated.',
            'index.html?error=account_deactivated');
    }

    // Ensure session reflects current DB role
    $_SESSION['role'] = $user['role'];

    return $user;
}

// ────────────────────────────────────────
// QUICK INSERT / UPDATE WRAPPERS
// ────────────────────────────────────────

function dbInsert(PDO $conn, string $table, array $data): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return ['success' => false, 'error' => 'Invalid table name'];
    }
    $columns      = array_keys($data);
    $values       = array_values($data);
    // Postgres uses double-quote identifier quoting, not backticks.
    $columnList   = implode(', ', array_map(fn($c) => "\"$c\"", $columns));
    $placeholders = implode(', ', array_fill(0, count($values), '?'));
    $types        = str_repeat('s', count($values));

    return safePreparedQuery($conn, "INSERT INTO \"$table\" ($columnList) VALUES ($placeholders)", $types, $values);
}

function dbUpdate(PDO $conn, string $table, array $data, string $where, array $whereParams = []): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return ['success' => false, 'error' => 'Invalid table name'];
    }
    $setParts = [];
    $values   = [];
    foreach ($data as $column => $value) {
        $setParts[] = "\"$column\" = ?";
        $values[]   = $value;
    }
    $allParams = array_merge($values, $whereParams);
    $types     = str_repeat('s', count($allParams));

    return safePreparedQuery($conn, "UPDATE \"$table\" SET " . implode(', ', $setParts) . " WHERE $where", $types, $allParams);
}

// ────────────────────────────────────────
// CONNECTION HEALTH
// ────────────────────────────────────────

function getConnectionHealth(PDO $conn): array {
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
        $health['connected'] = true;

        // Postgres equivalents of MySQL's SHOW STATUS uptime/threads_connected.
        // ('questions' — cumulative query counter — has no direct Postgres
        // equivalent without pg_stat_statements enabled, left as 0.)
        $statsStmt = safeQuery(
            $conn,
            "SELECT
                EXTRACT(EPOCH FROM (NOW() - pg_postmaster_start_time()))::bigint AS uptime,
                (SELECT COUNT(*) FROM pg_stat_activity) AS threads_connected"
        );
        if ($statsStmt) {
            $row = $statsStmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $health['uptime']            = (int) $row['uptime'];
                $health['threads_connected'] = (int) $row['threads_connected'];
            }
        }

        if ($health['threads_connected'] > 100) {
            $health['warnings'][] = 'High thread count: ' . $health['threads_connected'];
        }

    } catch (Exception $e) {
        $health['warnings'][] = 'Health check error: ' . $e->getMessage();
    }

    return $health;
}
