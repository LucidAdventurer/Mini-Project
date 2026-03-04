<?php
/* ========================================
 * DATABASE CONNECTION GUARD
 * File: db-guard.php
 *
 * CHANGES:
 * - safePreparedQuery and safeQuery now only retry on connection
 *   loss error codes (2006, 2013, 2055). Logical errors (bad SQL,
 *   constraint violations, etc.) fail immediately — no wasted retries.
 * - All previous CSRF + session logic retained.
 * ======================================== */

if (defined('DB_GUARD_LOADED')) {
    return;
}
define('DB_GUARD_LOADED', true);

// Connection-loss error codes — the only ones worth retrying
const DB_RETRY_ERRNO = [2006, 2013, 2055];

// ────────────────────────────────────────
// CSRF HELPERS
// ────────────────────────────────────────

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'No active session.']);
        exit;
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(): void {
    $sentToken    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
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
 * Safe query with retry on connection loss only.
 */
function safeQuery(mysqli &$conn, string $query, int $maxRetries = 3): mysqli_result|bool {
    $attempt = 0;

    while ($attempt < $maxRetries) {
        if (!ensureDatabaseConnection($conn)) {
            $attempt++;
            if ($attempt < $maxRetries) {
                sleep(1);
                continue;
            }
            error_log("safeQuery: connection unavailable after $maxRetries attempts");
            return false;
        }

        try {
            $result = $conn->query($query);

            if ($result === false) {
                if (in_array($conn->errno, DB_RETRY_ERRNO)) {
                    // Connection dropped mid-query — reconnect and retry
                    $attempt++;
                    error_log("safeQuery: connection lost (errno {$conn->errno}), attempt $attempt");
                    ensureDatabaseConnection($conn);
                    if ($attempt < $maxRetries) {
                        sleep(1);
                    }
                    continue;
                }
                // Logical error (bad SQL, constraint, etc.) — fail immediately
                error_log("safeQuery failed (errno {$conn->errno}): {$conn->error} | " . substr($query, 0, 120));
                return false;
            }

            return $result;

        } catch (Exception $e) {
            $attempt++;
            error_log("safeQuery exception attempt $attempt: " . $e->getMessage());
            ensureDatabaseConnection($conn);
            if ($attempt < $maxRetries) {
                sleep(1);
            }
        }
    }

    return false;
}

/**
 * Safe prepared statement with retry on connection loss only.
 * Returns ['success', 'result', 'insert_id', 'affected_rows', 'error'].
 */
function safePreparedQuery(mysqli &$conn, string $query, string $types = "", array $params = []): array {
    $fail = fn(string $err) => ['success' => false, 'error' => $err, 'result' => null, 'insert_id' => 0, 'affected_rows' => 0];

    $maxRetries = 3;
    $attempt    = 0;

    while ($attempt < $maxRetries) {
        if (!ensureDatabaseConnection($conn)) {
            $attempt++;
            if ($attempt < $maxRetries) {
                sleep(1);
                continue;
            }
            return $fail("Connection unavailable after $maxRetries attempts");
        }

        $stmt = $conn->prepare($query);

        if (!$stmt) {
            // Prepare failed — check if it's a connection issue
            if (in_array($conn->errno, DB_RETRY_ERRNO)) {
                $attempt++;
                error_log("safePreparedQuery: prepare lost connection (errno {$conn->errno}), attempt $attempt");
                ensureDatabaseConnection($conn);
                if ($attempt < $maxRetries) {
                    sleep(1);
                }
                continue;
            }
            // Logical error (bad SQL) — fail immediately, no retry
            error_log("safePreparedQuery: prepare failed (errno {$conn->errno}): {$conn->error}");
            return $fail("Prepare failed: {$conn->error}");
        }

        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            $errno = $stmt->errno;
            $error = $stmt->error;
            $stmt->close();

            if (in_array($errno, DB_RETRY_ERRNO)) {
                // Connection dropped during execute — retry
                $attempt++;
                error_log("safePreparedQuery: execute lost connection (errno $errno), attempt $attempt");
                ensureDatabaseConnection($conn);
                if ($attempt < $maxRetries) {
                    sleep(1);
                }
                continue;
            }

            // Logical error — fail immediately, no retry
            error_log("safePreparedQuery: execute failed (errno $errno): $error");
            return $fail($error);
        }

        $result       = $stmt->result_metadata() ? $stmt->get_result() : null;
        $insertId     = $stmt->insert_id;
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        return ['success' => true, 'result' => $result, 'insert_id' => $insertId, 'affected_rows' => $affectedRows];
    }

    return $fail("Failed after $maxRetries attempts");
}

// ────────────────────────────────────────
// SESSION / AUTH HELPERS
// ────────────────────────────────────────

function getUserData(mysqli &$conn, int $userId): ?array {
    $result = safePreparedQuery(
        $conn,
        "SELECT user_id, full_name, email, user_type, department,
                registration_number, is_verified, is_active
         FROM users WHERE user_id = ?",
        "i", [$userId]
    );

    if ($result['success'] && $result['result']) {
        $userData = $result['result']->fetch_assoc();
        $result['result']->free();
        return $userData ?: null;
    }

    return null;
}

function validateSession(mysqli &$conn, ?string $requiredRole = null): array {
    if (!isset($_SESSION['uid']) || !isset($_SESSION['role'])) {
        header("Location: index.html?error=session_expired");
        exit;
    }

    if ($requiredRole !== null && $_SESSION['role'] !== $requiredRole) {
        header("Location: index.html?error=unauthorized");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateCsrfToken();
    }

    $user = getUserData($conn, (int) $_SESSION['uid']);

    if (!$user) {
        session_destroy();
        header("Location: index.html?error=user_not_found");
        exit;
    }

    if (!$user['is_active']) {
        session_destroy();
        header("Location: index.html?error=account_deactivated");
        exit;
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
    $setParts  = [];
    $values    = [];
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
                $health[strtolower($row['Variable_name'])] = (int) $row['Value'];
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