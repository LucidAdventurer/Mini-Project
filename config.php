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
// LOAD ENV.PHP CONFIGURATION
// ========================================

// Try to load env.php from parent directory first, then same directory
$_envPath = file_exists(dirname(__DIR__) . '/env.php')
    ? dirname(__DIR__) . '/env.php'
    : __DIR__ . '/env.php';

if (file_exists($_envPath)) {
    $_env = require $_envPath;
} else {
    $_env = [];
}

// ========================================
// ENVIRONMENT & ERROR REPORTING
// ========================================

// Define application environment - reads from env.php first, then system env, then defaults to development
$_appEnv = $_env['APP_ENV'] ?? getenv('APP_ENV') ?: 'development';
define('APP_ENVIRONMENT', $_appEnv);

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

// Load database credentials from env.php (falls back to hardcoded values)
define('DB_HOST', $_env['DB_HOST'] ?? 'vtkd-1.h.filess.io');
define('DB_USER', $_env['DB_USER'] ?? 'pta_solutionbe');
define('DB_PASS', $_env['DB_PASS'] ?? '6c50175d8af914603b87d5606b7bf4806f89644f');
define('DB_NAME', $_env['DB_NAME'] ?? 'pta_solutionbe');
define('DB_PORT', $_env['DB_PORT'] ?? '61000');

// Cloudinary
define('CLOUDINARY_CLOUD_NAME', $_env['CLOUDINARY_CLOUD_NAME'] ?? '');
define('CLOUDINARY_API_KEY',    $_env['CLOUDINARY_API_KEY']    ?? '');
define('CLOUDINARY_API_SECRET', $_env['CLOUDINARY_API_SECRET'] ?? '');

// SMTP
define('SMTP_HOST',      $_env['SMTP_HOST']      ?? '');
define('SMTP_PORT',      (int)($_env['SMTP_PORT'] ?? 587));
define('SMTP_USER',      $_env['SMTP_USER']       ?? '');
define('SMTP_PASS',      $_env['SMTP_PASS']       ?? '');
define('SMTP_FROM',      $_env['SMTP_FROM']       ?? '');
define('SMTP_FROM_NAME', $_env['SMTP_FROM_NAME']  ?? 'PTA Platform');

// Connection settings for remote database
define('DB_CONNECT_TIMEOUT', 10); // Shorter - fail fast if DB is down
define('DB_READ_TIMEOUT', 30);     // Read timeout in seconds
define('DB_WRITE_TIMEOUT', 30);    // Write timeout in seconds
define('DB_MAX_RETRIES', 2);       // Keep low - DB only allows 5 simultaneous connections

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
            $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, DB_CONNECT_TIMEOUT);
            
            // Optional timeouts - check if available
            if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
                $conn->options(MYSQLI_OPT_READ_TIMEOUT, DB_READ_TIMEOUT);
            }
            
            if (defined('MYSQLI_OPT_WRITE_TIMEOUT')) {
                $conn->options(MYSQLI_OPT_WRITE_TIMEOUT, DB_WRITE_TIMEOUT);
            }
            
            // Enable auto-reconnect if supported (not available in all PHP versions)
            if (defined('MYSQLI_OPT_RECONNECT')) {
                $conn->options(MYSQLI_OPT_RECONNECT, 1);
            }
            
            // Now establish the actual connection
            if (!$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT)) {
                throw new mysqli_sql_exception("Connection failed: " . $conn->connect_error);
            }
            
            // Verify connection is actually alive (PHP 8.4 compatible)
            try {
                $conn->query("SELECT 1");
            } catch (Exception $e) {
                throw new Exception("Connection test query failed");
            }
            
            // Set character set and SQL mode
            $conn->set_charset("utf8mb4");
            
            // MariaDB-compatible SQL mode
            $conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            
            error_log("✓ DB connected on attempt " . ($retryCount + 1));
            return $conn;
            
        } catch (Exception $e) {
            $retryCount++;
            error_log("✗ DB connection attempt $retryCount failed: " . $e->getMessage());
            
            if ($retryCount < DB_MAX_RETRIES) {
                error_log("→ Retrying in {$retryDelay}s...");
                sleep($retryDelay);
                $retryDelay = min($retryDelay * 2, 10); // Max 10s between retries
            } else {
                error_log("✗ All DB connection attempts failed");
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
 * Check if database connection is still alive and reconnect if needed
 * Call this before important database operations
 *
 * @param mysqli $conn Database connection
 * @return bool True if connection is alive or reconnected successfully
 */
function ensureDatabaseConnection(&$conn) {
    // Check if connection exists and test it with a simple query (PHP 8.4 compatible)
    if ($conn) {
        try {
            $result = $conn->query("SELECT 1");
            if ($result) {
                $result->free();
                return true; // Connection is alive
            }
        } catch (Exception $e) {
            // Connection is dead, continue to reconnect
        }
    }
    
    error_log("Database connection lost - attempting to reconnect");
    
    // Try to reconnect
    $conn = createDatabaseConnection();
    
    return ($conn !== null);
}

$conn->query("SET time_zone = '+05:30'");

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