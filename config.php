<?php
/*
 * ========================================
 * PTA GLOBAL CONFIGURATION
 * File: config.php
 *
 * SECURITY:
 * 1. DB credentials loaded from env.php (outside web root), NOT hardcoded here.
 * 2. CSRF token generated once per session with 1-hour expiry rotation.
 * ======================================== */

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

if (!is_numeric($env['DB_PORT'])) {
    error_log("Invalid DB_PORT value: " . $env['DB_PORT']);
    http_response_code(500);
    die("Server configuration error. Contact the administrator.");
}

// ========================================
// ENVIRONMENT
// ========================================
define('APP_ENVIRONMENT', $env['APP_ENV'] ?? 'production');

if (APP_ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    // Suppress notices/deprecations in production to prevent log flooding
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('log_errors_max_len', 0);
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    if (is_dir($logDir) && is_writable($logDir)) {
        ini_set('error_log', $logDir . '/php_errors.log');
    }
    unset($logDir);
}

// ========================================
// DATABASE CONSTANTS
// ========================================
define('DB_HOST', $env['DB_HOST']);
define('DB_USER', $env['DB_USER']);
define('DB_PASS', $env['DB_PASS']);
define('DB_NAME', $env['DB_NAME']);
define('DB_PORT', (int) $env['DB_PORT']);

// ========================================
// SMTP CONSTANTS
// ========================================
define('SMTP_HOST',      $env['SMTP_HOST']      ?? '');
define('SMTP_PORT',      (int) ($env['SMTP_PORT'] ?? 587));
define('SMTP_USER',      $env['SMTP_USER']      ?? '');
define('SMTP_PASS',      $env['SMTP_PASS']      ?? '');
define('SMTP_FROM',      $env['SMTP_FROM']      ?? '');
define('SMTP_FROM_NAME', $env['SMTP_FROM_NAME'] ?? 'PTA Platform');

unset($env); // Don't leave credentials in memory longer than needed

// ========================================
// CONNECTION SETTINGS
// Fast-fail values: timeout quickly and only retry once.
// A remote DB either accepts the connection or it doesn't —
// retrying 5 times with backoff just hangs the page for 75s.
// ========================================
define('DB_CONNECT_TIMEOUT', 5);   // was 10 — cut in half
define('DB_READ_TIMEOUT',    30);
define('DB_WRITE_TIMEOUT',   30);
define('DB_MAX_RETRIES',      1);  // was 5 — no backoff loop for remote DB
define('DB_TYPE', 'MariaDB');

// Set mysqli strict mode once, outside the retry loop
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Establish a database connection with retry + exponential backoff.
 */
function createDatabaseConnection(): ?mysqli {
    $retryCount = 0;
    $retryDelay = 1;

    while ($retryCount < DB_MAX_RETRIES) {
        try {
            $conn = mysqli_init();
            if (!$conn) {
                throw new \RuntimeException("mysqli_init failed");
            }

            $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, DB_CONNECT_TIMEOUT);

            if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
                $conn->options(MYSQLI_OPT_READ_TIMEOUT, DB_READ_TIMEOUT);
            }
            if (defined('MYSQLI_OPT_WRITE_TIMEOUT')) {
                $conn->options(MYSQLI_OPT_WRITE_TIMEOUT, DB_WRITE_TIMEOUT);
            }

            if (!$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT)) {
                throw new mysqli_sql_exception("Connection failed: " . $conn->connect_error);
            }

            $conn->set_charset("utf8mb4");
            $conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            $conn->query("SET SESSION net_read_timeout = 60");
            $conn->query("SET SESSION net_write_timeout = 60");

            if (APP_ENVIRONMENT === 'development') {
                error_log("✓ DB connected on attempt " . ($retryCount + 1));
            }

            return $conn;

        } catch (Throwable $e) {
            $retryCount++;

            if ($retryCount < DB_MAX_RETRIES) {
                if (APP_ENVIRONMENT === 'development') {
                    error_log("✗ DB connection attempt $retryCount failed: " . $e->getMessage() . " — retrying in {$retryDelay}s");
                }
                sleep($retryDelay);
                $retryDelay = min($retryDelay * 2, 10);
            } else {
                error_log("✗ All DB connection attempts failed: " . $e->getMessage());
                return null;
            }
        }
    }

    return null;
}

$conn = createDatabaseConnection();

if ($conn === null) {
    error_log("Database connection failed after all retries.");
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $isApiEndpoint = str_contains($path, '/api/');

    // login.php and verify-email.php are redirect-based, not AJAX — send them
    // to the error page instead of returning JSON which the browser cannot handle.
    if ($isApiEndpoint || in_array($currentScript, ['register.php'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => false, 'message' => 'Unable to connect to database. Please try again in a few moments.'],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    if (in_array($currentScript, ['login.php', 'verify-email.php'], true)) {
        header("Location: index.html?error=" . urlencode('database_error'));
        exit;
    }

    http_response_code(503);
    die("Service temporarily unavailable. Please try again later.");
}

// ========================================
// SESSION MANAGEMENT
// ========================================
if (session_status() === PHP_SESSION_NONE) {
    // Robust HTTPS detection — handles reverse proxies and port 443
    $isSecure =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);
    session_set_cookie_params([
        'lifetime' => 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $isSecure,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_only_cookies', 1);
    session_start();

    // Prevent session fixation: force a new ID on first use
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
    }
}

// CSRF token: generate once per session.
// Rotation happens after successful POST validation, not here,
// to prevent breaking long-running form submissions.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ========================================
// SYSTEM SETTINGS
// Wrapped in try/catch — if the system_settings table doesn't exist yet
// or the DB hiccups during SystemSettings init, we log the error and
// continue with a null $settings rather than throwing an uncaught exception
// that would cause a 500 on every page load.
// ========================================
require_once __DIR__ . '/system-settings.php';

$settings = null;
try {
    $settings = SystemSettings::getInstance();
} catch (Throwable $e) {
    error_log("SystemSettings init failed: " . $e->getMessage());
    // $settings stays null — maintenance mode check below is skipped safely.
}

// ========================================
// MAINTENANCE MODE
// ========================================
if ($settings !== null && $settings->get('maintenance_mode', false)) {
    $allowedIPs = $settings->get('maintenance_mode_allowed_ips', '127.0.0.1,::1');
    $allowedIPs = array_map('trim', explode(',', $allowedIPs));

    // Only trust CF-Connecting-IP (set by Cloudflare) or fall back to REMOTE_ADDR.
    // X-Forwarded-For is client-controllable and not used — an attacker could
    // spoof it to bypass maintenance restrictions (e.g. X-Forwarded-For: 127.0.0.1).
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $clientIP = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    } else {
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    if (!in_array($clientIP, $allowedIPs, true)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Service temporarily unavailable. Please try again later.';
        exit;
    }
}

// ========================================
// DATABASE HELPERS
// ========================================

/**
 * Execute a prepared statement and return results or affected rows.
 */
function executeQuery(mysqli_stmt $stmt): mixed {
    // With MYSQLI_REPORT_STRICT enabled, execute() throws instead of returning false.
    try {
        $stmt->execute();
    } catch (Throwable $e) {
        error_log("Statement execution failed: " . $e->getMessage());
        return false;
    }

    $result = $stmt->get_result();
    if ($result instanceof mysqli_result) {
        return $result;
    }

    return $stmt->affected_rows;
}

function tableExists(mysqli $conn, string $table): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        error_log("Invalid table name format: $table");
        return false;
    }
    // Use information_schema — avoids SHOW TABLES LIKE ESCAPE quoting issues
    // that behave inconsistently across MariaDB versions.
    // Table name is already validated as alphanumeric+underscore so safe to bind directly.
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    if (!$stmt) {
        error_log("tableExists() prepare failed: " . $conn->error);
        return false;
    }
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count > 0;
}

function ensureDatabaseConnection(?mysqli &$conn): bool {
    if ($conn) {
        try {
            $r = $conn->query("SELECT 1");
            if ($r instanceof mysqli_result) {
                $r->free();
                return true;
            }
        } catch (Throwable $e) {
            // fall through to reconnect
        }
    }
    error_log("Database connection lost — attempting reconnect");
    $conn = createDatabaseConnection();
    return ($conn !== null);
}

// ========================================
// SHUTDOWN HANDLER
// PHP closes connections automatically; this is an explicit safety net.
// ========================================
register_shutdown_function(function () use ($conn) {
    if ($conn && $conn->thread_id) {
        $conn->close();
    }
});