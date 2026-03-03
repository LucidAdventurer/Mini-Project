<?php
/*
 * ========================================
 * PTA GLOBAL CONFIGURATION
 * File: config.php
 *
 * SECURITY CHANGES:
 * 1. DB credentials loaded from env.php (outside web root), NOT hardcoded here.
 * 2. CSRF token generated once per session.
 * ======================================== */

set_time_limit(120);
ini_set('max_execution_time', '120');

// ========================================
// LOAD CREDENTIALS FROM env.php
// Try above web root first, fall back to same directory.
// ========================================
$envPaths = [
    dirname(__DIR__) . '/env.php',  // one level above web root (preferred)
    __DIR__ . '/env.php',           // same directory (fallback)
];

$env = null;
foreach ($envPaths as $envPath) {
    if (file_exists($envPath)) {
        $env = require $envPath;
        break;
    }
}

if (!is_array($env)) {
    error_log("env.php not found. Checked: " . implode(', ', $envPaths));
    http_response_code(500);
    die("Server configuration error. Contact the administrator.");
}

$requiredKeys = ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_PORT'];
foreach ($requiredKeys as $_key) {
    if (empty($env[$_key])) {
        error_log("Missing required env key: $_key");
        http_response_code(500);
        die("Server configuration error. Contact the administrator.");
    }
}
unset($_key);

// ========================================
// ENVIRONMENT
// ========================================
define('APP_ENVIRONMENT', $env['APP_ENV'] ?? 'production');

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
// DATABASE CONSTANTS
// ========================================
define('DB_HOST', $env['DB_HOST']);
define('DB_USER', $env['DB_USER']);
define('DB_PASS', $env['DB_PASS']);
define('DB_NAME', $env['DB_NAME']);
define('DB_PORT', (int) $env['DB_PORT']);

unset($env); // Don't leave credentials in memory longer than needed

define('DB_CONNECT_TIMEOUT', 10);
define('DB_READ_TIMEOUT',    30);
define('DB_WRITE_TIMEOUT',   30);
define('DB_MAX_RETRIES',      5);
define('DB_TYPE', 'MariaDB');

/**
 * Establish a database connection with retry + exponential backoff.
 */
function createDatabaseConnection(): ?mysqli {
    $retryCount = 0;
    $retryDelay = 1;

    while ($retryCount < DB_MAX_RETRIES) {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            $conn = mysqli_init();
            if (!$conn) {
                throw new Exception("mysqli_init failed");
            }

            $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, DB_CONNECT_TIMEOUT);

            if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
                $conn->options(MYSQLI_OPT_READ_TIMEOUT, DB_READ_TIMEOUT);
            }
            if (defined('MYSQLI_OPT_WRITE_TIMEOUT')) {
                $conn->options(MYSQLI_OPT_WRITE_TIMEOUT, DB_WRITE_TIMEOUT);
            }
            if (defined('MYSQLI_OPT_RECONNECT')) {
                $conn->options(MYSQLI_OPT_RECONNECT, 1);
            }

            if (!$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT)) {
                throw new mysqli_sql_exception("Connection failed: " . $conn->connect_error);
            }

            $conn->query("SELECT 1");
            $conn->set_charset("utf8mb4");
            $conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            $conn->query("SET SESSION wait_timeout = 300");
            $conn->query("SET SESSION interactive_timeout = 300");
            $conn->query("SET SESSION net_read_timeout = 60");
            $conn->query("SET SESSION net_write_timeout = 60");

            error_log("✓ DB connected on attempt " . ($retryCount + 1));
            return $conn;

        } catch (Exception $e) {
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

$conn = createDatabaseConnection();

if ($conn === null) {
    error_log("Database connection failed after all retries.");
    $isApiEndpoint = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApiEndpoint || in_array(basename($_SERVER['PHP_SELF']), ['register.php', 'login.php', 'verify-email.php'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unable to connect to database. Please try again in a few moments.']);
        exit;
    }
    http_response_code(503);
    die("Service temporarily unavailable. Please try again later.");
}

// ========================================
// SESSION MANAGEMENT
// ========================================
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure',   $isSecure ? 1 : 0);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

// CSRF token: generate once per session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ========================================
// SYSTEM SETTINGS
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
        echo "<h1>Service Unavailable</h1><p>The site is currently down for maintenance.</p>";
        exit;
    }
}

// ========================================
// DATABASE HELPERS
// ========================================

function executeQuery(mysqli_stmt $stmt): mixed {
    if (!$stmt->execute()) {
        error_log("Statement execution failed: " . $stmt->error);
        return false;
    }
    if ($stmt->result_metadata()) {
        return $stmt->get_result();
    }
    return $stmt->affected_rows;
}

function tableExists(mysqli $conn, string $table): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        error_log("Invalid table name format: $table");
        return false;
    }
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result === false) {
        error_log("tableExists() failed: " . $conn->error);
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function ensureDatabaseConnection(?mysqli &$conn): bool {
    if ($conn) {
        try {
            $r = $conn->query("SELECT 1");
            if ($r) {
                $r->free();
                return true;
            }
        } catch (Exception $e) {
            // fall through
        }
    }
    error_log("Database connection lost — attempting reconnect");
    $conn = createDatabaseConnection();
    return ($conn !== null);
}

// ========================================
// SHUTDOWN HANDLER
// ========================================
register_shutdown_function(function () use ($conn) {
    if ($conn && $conn->thread_id) {
        $conn->close();
    }
});