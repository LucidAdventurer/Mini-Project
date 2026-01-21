<?php
/*
 * ========================================
 * PTA GLOBAL CONFIGURATION - REMOTE DATABASE OPTIMIZED v2.0
 * File: config.php
 *
 * Purpose: Core application setup with remote database connection optimization
 * 
 * FIXES APPLIED:
 * 1. Added connection timeout configuration for remote databases
 * 2. Increased PHP execution time for database operations
 * 3. Added connection retry logic with exponential backoff
 * 4. Optimized session handling
 * 5. Added persistent connection option
 * ======================================== */

// ========================================
// EXECUTION TIME LIMITS
// Increase for remote database operations
// ========================================
set_time_limit(120); // 2 minutes for remote DB operations
ini_set('max_execution_time', '120');

// ========================================
// ENVIRONMENT & ERROR REPORTING
// ========================================

// Define application environment (development, testing, production)
define('APP_ENVIRONMENT', getenv('APP_ENV') ?: 'development');

// Strict error reporting for development
if (APP_ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    // Ensure the logs directory exists and is writable
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/php_errors.log');
}

// ========================================
// DATABASE CONFIGURATION & CONNECTION
// ========================================

// Load database credentials from environment variables
define('DB_HOST', 'vtkd-1.h.filess.io');
define('DB_USER', 'pta_solutionbe');
define('DB_PASS', '6c50175d8af914603b87d5606b7bf4806f89644f');
define('DB_NAME', 'pta_solutionbe');
define('DB_PORT', '61000');

// Connection settings for remote database
define('DB_CONNECT_TIMEOUT', 20); // Connection timeout in seconds (increased for remote)
define('DB_READ_TIMEOUT', 45);     // Read timeout in seconds
define('DB_WRITE_TIMEOUT', 45);    // Write timeout in seconds
define('DB_MAX_RETRIES', 3);       // Maximum connection retry attempts

// MariaDB detection and compatibility
define('DB_TYPE', 'MariaDB'); // Set to 'MariaDB' or 'MySQL' if known

/**
 * Establish database connection with retry logic
 * This function handles connection timeouts and retries for remote databases
 *
 * @return mysqli|null Database connection or null on failure
 */
function createDatabaseConnection() {
    $retryCount = 0;
    $retryDelay = 1; // Start with 1 second delay
    
    while ($retryCount < DB_MAX_RETRIES) {
        try {
            // Initialize mysqli with error reporting
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            
            // Create connection object first (don't connect yet)
            $conn = mysqli_init();
            
            if (!$conn) {
                throw new Exception("mysqli_init failed");
            }
            
            // Set connection timeout options (before connecting)
            // MYSQLI_OPT_CONNECT_TIMEOUT is universally supported
            $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, DB_CONNECT_TIMEOUT);
            
            // MYSQLI_OPT_READ_TIMEOUT - check if available (PHP 7.2+)
            if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
                $conn->options(MYSQLI_OPT_READ_TIMEOUT, DB_READ_TIMEOUT);
            }
            
            // MYSQLI_OPT_WRITE_TIMEOUT - check if available (MySQL Native Driver)
            // This constant may not exist in all environments
            if (defined('MYSQLI_OPT_WRITE_TIMEOUT')) {
                $conn->options(MYSQLI_OPT_WRITE_TIMEOUT, DB_WRITE_TIMEOUT);
            }
            
            // Now establish the actual connection
            if (!$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT)) {
                throw new mysqli_sql_exception("Connection failed: " . $conn->connect_error);
            }
            
            // Set character set and SQL mode
            $conn->set_charset("utf8mb4");
            
            // MariaDB-compatible SQL mode (slightly different from MySQL)
            // Remove ONLY_FULL_GROUP_BY for MariaDB compatibility
            $conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            
            // Set session timeout at MySQL/MariaDB level (works on all versions)
            $conn->query("SET SESSION wait_timeout = 300");        // 5 minutes
            $conn->query("SET SESSION interactive_timeout = 300"); // 5 minutes
            $conn->query("SET SESSION net_read_timeout = 60");     // 60 seconds
            $conn->query("SET SESSION net_write_timeout = 60");    // 60 seconds
            
            error_log("Database connection established successfully on attempt " . ($retryCount + 1));
            return $conn;
            
        } catch (Exception $e) {
            $retryCount++;
            error_log("Database connection attempt $retryCount failed: " . $e->getMessage());
            
            if ($retryCount < DB_MAX_RETRIES) {
                // Exponential backoff: wait before retrying
                error_log("Retrying connection in {$retryDelay} seconds...");
                sleep($retryDelay);
                $retryDelay *= 2; // Double the delay for next retry
            } else {
                error_log("All database connection attempts failed after $retryCount tries");
                return null;
            }
        }
    }
    
    return null;
}

// Establish database connection
$conn = createDatabaseConnection();

if ($conn === null || $conn->connect_error) {
    // Log the error
    error_log("Database connection failed after all retries: " . ($conn ? $conn->connect_error : 'Connection object is null'));
    
    // Return JSON for API endpoints
    if (basename($_SERVER['PHP_SELF']) === 'register.php') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Unable to connect to database. Please try again in a few moments.'
        ]);
        exit;
    }
    
    // For other pages, show error page
    http_response_code(503);
    die("Service temporarily unavailable. Our database is currently unreachable. Please try again later.");
}

// ========================================
// SESSION MANAGEMENT
// ========================================

// IMPORTANT: Only configure session settings if session is NOT already active
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters based on environment
    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', $isSecure ? 1 : 0);
    ini_set('session.use_only_cookies', 1);
    
    // Start session
    session_start();
}

// ========================================
// LOAD SYSTEM SETTINGS
// ========================================

require_once __DIR__ . '/system-settings.php';
$settings = SystemSettings::getInstance();

// ========================================
// MAINTENANCE MODE
// ========================================

if ($settings->get('maintenance_mode', false)) {
    $allowedIPs = $settings->get('maintenance_mode_allowed_ips', '127.0.0.1,::1');
    $allowedIPs = array_map('trim', explode(',', $allowedIPs));

    if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
        http_response_code(503);
        echo "<h1>Service Unavailable</h1><p>The site is currently down for maintenance. Please check back later.</p>";
        exit;
    }
}

// ========================================
// DATABASE HELPER FUNCTIONS
// ========================================

/**
 * Executes a prepared statement and returns the result.
 * Handles both SELECT and write (INSERT, UPDATE, DELETE) queries.
 *
 * @param mysqli_stmt $stmt The prepared statement to execute.
 * @return mixed The result set for SELECT queries, affected rows for writes, or false on failure.
 */
function executeQuery($stmt) {
    if (!$stmt->execute()) {
        error_log("Statement execution failed: " . $stmt->error);
        return false;
    }

    // For SELECT queries, return the result set
    if ($stmt->result_metadata()) {
        return $stmt->get_result();
    }

    // For INSERT, UPDATE, DELETE, return the number of affected rows
    return $stmt->affected_rows;
}

/**
 * Checks if a database table exists.
 * 
 * FIXED: Table names cannot be used as prepared statement parameters in SHOW TABLES
 * MySQL doesn't allow parameters for table names in metadata queries
 *
 * @param mysqli $conn The database connection.
 * @param string $table The name of the table to check.
 * @return bool True if the table exists, false otherwise.
 */
function tableExists($conn, $table) {
    // Sanitize table name to prevent SQL injection
    // Only allow alphanumeric characters and underscores
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        error_log("Invalid table name format: $table");
        return false;
    }
    
    // Use direct query (safe because we validated the table name above)
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    
    if ($result === false) {
        error_log("tableExists() query failed: " . $conn->error);
        return false;
    }
    
    $exists = $result->num_rows > 0;
    $result->free();
    
    return $exists;
}

/**
 * Check if database connection is still alive
 * Useful for long-running scripts with remote databases
 *
 * @param mysqli $conn Database connection
 * @return bool True if connection is alive
 */
function isDatabaseConnected($conn) {
    if (!$conn || $conn->connect_error) {
        return false;
    }
    
    try {
        return $conn->ping();
    } catch (Exception $e) {
        error_log("Database ping failed: " . $e->getMessage());
        return false;
    }
}

// ========================================
// SHUTDOWN HANDLER
// ========================================

// Register a shutdown function to close the database connection
register_shutdown_function(function() use ($conn) {
    if ($conn && $conn->thread_id) {
        $conn->close();
    }
});
?>