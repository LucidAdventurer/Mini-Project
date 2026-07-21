<?php
/*
 * ========================================
 * PTA GLOBAL CONFIGURATION - SUPABASE (POSTGRES) v3.0
 * File: config.php
 *
 * Migrated from mysqli/MariaDB (filess.io) to PDO/PostgreSQL (Supabase).
 *
 * IMPORTANT: This file only fixes the CONNECTION LAYER.
 * Any other file using mysqli-specific calls (bind_param, get_result,
 * real_escape_string, etc.) still needs to be converted to PDO style.
 * See executeQuery() below for the pattern to use in those files.
 * ======================================== */

// ========================================
// EXECUTION TIME LIMITS
// ========================================
set_time_limit(120);
ini_set('max_execution_time', '120');

// ========================================
// LOAD ENV.PHP CONFIGURATION
// ========================================

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

$_appEnv = $_env['APP_ENV'] ?? getenv('APP_ENV') ?: 'development';
define('APP_ENVIRONMENT', $_appEnv);

if (APP_ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    ini_set('error_log', $logDir . '/php_errors.log');
}

// ========================================
// DATABASE CONFIGURATION (SUPABASE / POSTGRES)
// ========================================

// NOTE: no hardcoded password fallback on purpose.
// Set these in env.php locally, and in Render's Environment tab in production.
define('DB_HOST', $_env['DB_HOST'] ?? getenv('DB_HOST') ?: 'aws-1-ap-south-1.pooler.supabase.com');
define('DB_PORT', $_env['DB_PORT'] ?? getenv('DB_PORT') ?: '5432');
define('DB_NAME', $_env['DB_NAME'] ?? getenv('DB_NAME') ?: 'postgres');
define('DB_USER', $_env['DB_USER'] ?? getenv('DB_USER') ?: 'postgres.enteveefrrxsuhlxdcfu');
define('DB_PASS', $_env['DB_PASS'] ?? getenv('DB_PASS') ?: '');

if (DB_PASS === '') {
    error_log("WARNING: DB_PASS is not set. Configure it in env.php or as an environment variable.");
}

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

// Connection settings
define('DB_CONNECT_TIMEOUT', 10);
define('DB_MAX_RETRIES', 2);
define('DB_TYPE', 'PostgreSQL');

/**
 * Establish a PDO connection to Supabase Postgres with retry logic.
 *
 * @return PDO|null
 */
function createDatabaseConnection() {
    $retryCount = 0;
    $retryDelay = 1;

    $dsn = sprintf(
        "pgsql:host=%s;port=%s;dbname=%s;sslmode=require",
        DB_HOST, DB_PORT, DB_NAME
    );

    while ($retryCount < DB_MAX_RETRIES) {
        try {
            $conn = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => DB_CONNECT_TIMEOUT,
            ]);

            // Verify connection is alive
            $conn->query("SELECT 1");

            error_log("✓ DB connected on attempt " . ($retryCount + 1));
            return $conn;

        } catch (PDOException $e) {
            $retryCount++;
            error_log("✗ DB connection attempt $retryCount failed: " . $e->getMessage());

            if ($retryCount < DB_MAX_RETRIES) {
                error_log("→ Retrying in {$retryDelay}s...");
                sleep($retryDelay);
                $retryDelay = min($retryDelay * 2, 10);
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

if ($conn === null) {
    error_log("Database connection failed after all retries.");

    if (basename($_SERVER['PHP_SELF']) === 'register.php') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Unable to connect to database. Please try again in a few moments.'
        ]);
        exit;
    }

    http_response_code(503);
    die("Service temporarily unavailable. Our database is currently unreachable. Please try again later.");
}

// ========================================
// SESSION MANAGEMENT
// ========================================

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', $isSecure ? 1 : 0);
    ini_set('session.use_only_cookies', 1);

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
// DATABASE HELPER FUNCTIONS (PDO VERSION)
// ========================================

/**
 * Executes a PDO prepared statement and returns the result.
 *
 * USAGE CHANGE FROM MYSQLI:
 *   OLD (mysqli):
 *     $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
 *     $stmt->bind_param("i", $id);
 *     $result = executeQuery($stmt);
 *
 *   NEW (PDO):
 *     $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
 *     $result = executeQuery($stmt, [$id]);
 *
 * @param PDOStatement $stmt   The prepared statement to execute.
 * @param array        $params Positional or named parameters for the statement.
 * @return mixed Array of rows for SELECT queries, affected row count for writes, or false on failure.
 */
function executeQuery($stmt, array $params = []) {
    try {
        $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Statement execution failed: " . $e->getMessage());
        return false;
    }

    // SELECT queries: return all rows
    if (stripos($stmt->queryString, 'select') === 0) {
        return $stmt->fetchAll();
    }

    // INSERT/UPDATE/DELETE: return affected row count
    return $stmt->rowCount();
}

/**
 * Checks if a database table exists (Postgres version).
 *
 * @param PDO    $conn  The database connection.
 * @param string $table The name of the table to check.
 * @return bool
 */
function tableExists($conn, $table) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        error_log("Invalid table name format: $table");
        return false;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = ?
            )"
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("tableExists() query failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if database connection is still alive and reconnect if needed.
 *
 * @param PDO|null $conn Database connection (passed by reference)
 * @return bool
 */
function ensureDatabaseConnection(&$conn) {
    if ($conn) {
        try {
            $conn->query("SELECT 1");
            return true;
        } catch (PDOException $e) {
            // Connection is dead, fall through to reconnect
        }
    }

    error_log("Database connection lost - attempting to reconnect");
    $conn = createDatabaseConnection();

    return ($conn !== null);
}

// Set timezone for this session (adjust if your users aren't in IST)
try {
    $conn->query("SET TIME ZONE 'Asia/Kolkata'");
} catch (PDOException $e) {
    error_log("Failed to set timezone: " . $e->getMessage());
}

// ========================================
// SHUTDOWN HANDLER
// ========================================

register_shutdown_function(function() use ($conn) {
    // PDO connections close automatically when the object is unset/script ends.
    // Nothing explicit required, kept for parity with the old structure.
});
?>
