<?php
/*
 * ========================================
 * PTA GLOBAL CONFIGURATION - FIXED VERSION hi
 * File: config.php
 *
 * Purpose: Core application setup, including error reporting, database connection,
 *          and session management. This file is the central bootstrap for the application.
 * 
 * FIXES APPLIED:
 * 1. Session handling moved to AFTER all includes to prevent ini_set warnings
 * 2. Fixed tableExists() function - table names cannot use prepared statement parameters
 * 3. Added proper parameter handling for tableExists()
 * ======================================== */

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
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '102005');
define('DB_NAME', getenv('DB_NAME') ?: 'pta');

// Establish database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    // Log the error
    error_log("Database connection failed: " . $conn->connect_error);
    
    // Return JSON instead of die()
    if (basename($_SERVER['PHP_SELF']) === 'register.php') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed. Please try again later.'
        ]);
        exit;
    }
    
    // For other pages, show error page
    http_response_code(503);
    die("Service temporarily unavailable. Please try again later.");
}

// Set character set and SQL mode
$conn->set_charset("utf8mb4");
$conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,ONLY_FULL_GROUP_BY'");

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
        // You should have a dedicated maintenance page
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