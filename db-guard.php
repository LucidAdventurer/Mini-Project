<?php
/* ========================================
 * DATABASE CONNECTION GUARD
 * File: db-guard.php
 *
 * Purpose: Wrapper functions to protect all database operations
 *          from connection failures and timeouts
 *
 * Usage: Include this AFTER config.php in all files
 * 
 * Features:
 * - Auto-reconnect on connection loss
 * - Retry logic for failed queries
 * - Connection health monitoring
 * - Error logging and recovery
 * ======================================== */

// Prevent multiple inclusions
if (defined('DB_GUARD_LOADED')) {
    return;
}
define('DB_GUARD_LOADED', true);

/**
 * Safe database query with automatic retry
 * Use this instead of $conn->query() for important queries
 *
 * @param mysqli $conn Database connection
 * @param string $query SQL query to execute
 * @param int $maxRetries Maximum retry attempts
 * @return mysqli_result|bool Query result or false on failure
 */
function safeQuery(&$conn, $query, $maxRetries = 3) {
    $attempt = 0;
    
    while ($attempt < $maxRetries) {
        try {
            // Check connection before query
            if (!ensureDatabaseConnection($conn)) {
                throw new Exception("Connection unavailable");
            }
            
            $result = $conn->query($query);
            
            if ($result === false) {
                // Check if error is connection-related
                if (in_array($conn->errno, [2006, 2013, 2055])) {
                    // MySQL server has gone away (2006)
                    // Lost connection to MySQL server during query (2013)
                    // Lost connection at 'reading initial communication packet' (2055)
                    throw new Exception("Connection lost during query");
                }
                
                // Non-connection error - log and return false
                error_log("Query failed: " . $conn->error . " | Query: " . substr($query, 0, 100));
                return false;
            }
            
            return $result;
            
        } catch (Exception $e) {
            $attempt++;
            error_log("Query attempt $attempt failed: " . $e->getMessage());
            
            if ($attempt < $maxRetries) {
                // Try to reconnect
                ensureDatabaseConnection($conn);
                sleep(1); // Wait 1 second before retry
            } else {
                error_log("Query failed after $maxRetries attempts");
                return false;
            }
        }
    }
    
    return false;
}

/**
 * Safe prepared statement execution with retry logic
 * Use this for INSERT/UPDATE/DELETE operations
 *
 * @param mysqli $conn Database connection
 * @param string $query SQL query with placeholders
 * @param string $types Parameter types (e.g., "ssi" for string, string, int)
 * @param array $params Array of parameters
 * @return array ['success' => bool, 'result' => mixed, 'insert_id' => int, 'affected_rows' => int]
 */
function safePreparedQuery(&$conn, $query, $types = "", $params = []) {
    $maxRetries = 3;
    $attempt = 0;
    
    while ($attempt < $maxRetries) {
        try {
            // Ensure connection is alive
            if (!ensureDatabaseConnection($conn)) {
                throw new Exception("Connection unavailable");
            }
            
            $stmt = $conn->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            // Bind parameters if provided
            if (!empty($types) && !empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            // Execute
            if (!$stmt->execute()) {
                // Check for connection errors
                if (in_array($stmt->errno, [2006, 2013, 2055])) {
                    $stmt->close();
                    throw new Exception("Connection lost during execution");
                }
                
                $error = $stmt->error;
                $stmt->close();
                error_log("Statement execution failed: $error");
                return [
                    'success' => false,
                    'error' => $error,
                    'result' => null,
                    'insert_id' => 0,
                    'affected_rows' => 0
                ];
            }
            
            // Get result
            $result = null;
            if ($stmt->result_metadata()) {
                $result = $stmt->get_result();
            }
            
            $insertId = $stmt->insert_id;
            $affectedRows = $stmt->affected_rows;
            
            $stmt->close();
            
            return [
                'success' => true,
                'result' => $result,
                'insert_id' => $insertId,
                'affected_rows' => $affectedRows
            ];
            
        } catch (Exception $e) {
            $attempt++;
            error_log("Prepared query attempt $attempt failed: " . $e->getMessage());
            
            if ($attempt < $maxRetries) {
                ensureDatabaseConnection($conn);
                sleep(1);
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed after ' . $maxRetries . ' attempts',
                    'result' => null,
                    'insert_id' => 0,
                    'affected_rows' => 0
                ];
            }
        }
    }
    
    return [
        'success' => false,
        'error' => 'Unexpected failure',
        'result' => null,
        'insert_id' => 0,
        'affected_rows' => 0
    ];
}

/**
 * Get user data with automatic retry
 * Common operation - made safe and easy
 *
 * @param mysqli $conn Database connection
 * @param int $userId User ID to fetch
 * @return array|null User data or null on failure
 */
function getUserData(&$conn, $userId) {
    $result = safePreparedQuery($conn, 
        "SELECT user_id, full_name, email, user_type, department, registration_number, is_verified, is_active FROM users WHERE user_id = ?",
        "i",
        [$userId]
    );
    
    if ($result['success'] && $result['result']) {
        $userData = $result['result']->fetch_assoc();
        $result['result']->free();
        return $userData;
    }
    
    return null;
}

/**
 * Check if session is valid and user exists
 * Use at the start of protected pages
 *
 * @param mysqli $conn Database connection
 * @param string $requiredRole Required user role ('student', 'teacher', 'admin') or null for any
 * @return array User data if valid, redirects to login if invalid
 */
function validateSession(&$conn, $requiredRole = null) {
    // Check if session variables exist
    if (!isset($_SESSION['uid']) || !isset($_SESSION['role'])) {
        header("Location: index.html?error=session_expired");
        exit;
    }
    
    // Check role if specified
    if ($requiredRole !== null && $_SESSION['role'] !== $requiredRole) {
        header("Location: index.html?error=unauthorized");
        exit;
    }
    
    // Verify user still exists and is active
    $user = getUserData($conn, $_SESSION['uid']);
    
    if (!$user) {
        // User not found - destroy session
        session_destroy();
        header("Location: index.html?error=user_not_found");
        exit;
    }
    
    if (!$user['is_active']) {
        // Account deactivated
        session_destroy();
        header("Location: index.html?error=account_deactivated");
        exit;
    }
    
    return $user;
}

/**
 * Monitor connection health
 * Call periodically in long-running scripts
 *
 * @param mysqli $conn Database connection
 * @return array Connection status information
 */
function getConnectionHealth($conn) {
    $health = [
        'connected' => false,
        'uptime' => 0,
        'threads_connected' => 0,
        'questions' => 0,
        'warnings' => []
    ];
    
    try {
        if (!$conn) {
            $health['warnings'][] = 'No connection object';
            return $health;
        }
        
        // Test connection
        $result = safeQuery($conn, "SELECT 1");
        if (!$result) {
            $health['warnings'][] = 'Connection test failed';
            return $health;
        }
        $result->free();
        
        $health['connected'] = true;
        
        // Get server status
        $result = safeQuery($conn, "SHOW STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected', 'Questions')");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $key = strtolower($row['Variable_name']);
                $health[$key] = (int)$row['Value'];
            }
            $result->free();
        }
        
        // Check for warnings
        if ($health['threads_connected'] > 100) {
            $health['warnings'][] = 'High thread count: ' . $health['threads_connected'];
        }
        
    } catch (Exception $e) {
        $health['warnings'][] = 'Health check error: ' . $e->getMessage();
    }
    
    return $health;
}

/**
 * Log database operation for debugging
 * Useful for tracking slow queries or connection issues
 *
 * @param string $operation Operation description
 * @param float $startTime Start time (microtime)
 * @param bool $success Whether operation succeeded
 */
function logDatabaseOperation($operation, $startTime, $success) {
    $duration = round((microtime(true) - $startTime) * 1000, 2); // milliseconds
    
    $logMessage = sprintf(
        "[DB %s] %s - %dms",
        $success ? 'OK' : 'FAIL',
        $operation,
        $duration
    );
    
    // Log slow queries (> 1 second)
    if ($duration > 1000) {
        error_log("SLOW QUERY: $logMessage");
    }
    
    // Log failed operations
    if (!$success) {
        error_log("FAILED: $logMessage");
    }
}

/**
 * Close database connection safely
 * Call at the end of scripts if needed
 *
 * @param mysqli $conn Database connection
 */
function closeConnection($conn) {
    if ($conn && $conn->thread_id) {
        try {
            $conn->close();
            error_log("Database connection closed successfully");
        } catch (Exception $e) {
            error_log("Error closing connection: " . $e->getMessage());
        }
    }
}

/* ========================================
 * CONVENIENCE HELPERS
 * Quick wrappers for common operations
 * ======================================== */

/**
 * Quick SELECT query wrapper
 */
function dbSelect(&$conn, $table, $where = "1=1", $params = []) {
    $query = "SELECT * FROM `$table` WHERE $where";
    
    if (!empty($params)) {
        $types = str_repeat('s', count($params)); // Assume all strings by default
        return safePreparedQuery($conn, $query, $types, $params);
    }
    
    $result = safeQuery($conn, $query);
    return ['success' => ($result !== false), 'result' => $result];
}

/**
 * Quick INSERT wrapper
 */
function dbInsert(&$conn, $table, $data) {
    $columns = array_keys($data);
    $values = array_values($data);
    
    $columnList = implode(', ', array_map(function($col) { return "`$col`"; }, $columns));
    $placeholders = implode(', ', array_fill(0, count($values), '?'));
    
    $query = "INSERT INTO `$table` ($columnList) VALUES ($placeholders)";
    $types = str_repeat('s', count($values));
    
    return safePreparedQuery($conn, $query, $types, $values);
}

/**
 * Quick UPDATE wrapper
 */
function dbUpdate(&$conn, $table, $data, $where, $whereParams = []) {
    $setParts = [];
    $values = [];
    
    foreach ($data as $column => $value) {
        $setParts[] = "`$column` = ?";
        $values[] = $value;
    }
    
    $setClause = implode(', ', $setParts);
    $query = "UPDATE `$table` SET $setClause WHERE $where";
    
    $allParams = array_merge($values, $whereParams);
    $types = str_repeat('s', count($allParams));
    
    return safePreparedQuery($conn, $query, $types, $allParams);
}
?>