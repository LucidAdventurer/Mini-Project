<?php
/* ========================================
 * PTA SYSTEM SETTINGS MANAGER
 * File: system-settings.php
 *
 * CHANGE: APCu cross-request cache added.
 * - First request per server process: loads from DB, stores in APCu.
 * - Subsequent requests within TTL: reads from APCu — zero DB queries.
 * - Falls back to DB-only cache if APCu is not available.
 * - Cache is invalidated immediately when set() is called.
 * ======================================== */

class SystemSettings {
    private static ?self $instance = null;

    private array  $cache          = [];
    private int    $cacheExpiry    = 300;   // seconds — also stored as a setting
    private ?int   $cacheTimestamp = null;
    private ?mysqli $conn          = null;
    private bool   $initialized    = false;

    // APCu cache key prefix — include DB name so multi-app servers don't collide
    private string $apcu_key;

    const IMMUTABLE_SETTINGS = [
        'PASSWORD_HASH_ALGO'          => PASSWORD_DEFAULT,
        'PASSWORD_MIN_LENGTH'         => 8,
        'PASSWORD_REQUIRE_UPPERCASE'  => true,
        'PASSWORD_REQUIRE_LOWERCASE'  => true,
        'PASSWORD_REQUIRE_NUMBER'     => true,
        'PASSWORD_REQUIRE_SPECIAL'    => false,
        'DB_CHARSET'                  => 'utf8mb4',
        'DB_COLLATION'                => 'utf8mb4_unicode_ci',
        'ABSOLUTE_MAX_FILE_SIZE'      => 50 * 1024 * 1024,
        'UPLOAD_BASE_DIR'             => 'uploads/',
        'ABSOLUTE_MAX_ITEMS_PER_PAGE' => 500,
        'TOKEN_MIN_LENGTH'            => 32,
        'CSRF_TOKEN_LENGTH'           => 64,
    ];

    const DEFAULT_CONFIGURABLE_SETTINGS = [
        'session_timeout_minutes'    => 60,
        'max_login_attempts'         => 5,
        'lockout_duration_minutes'   => 15,
        'otp_expiry_minutes'         => 10,
        'allow_guest_tests'          => false,
        'max_file_size_mb'           => 10,
        'results_retention_days'     => 365,
        'enable_proctoring'          => false,
        'smtp_configured'            => false,
        'maintenance_mode'           => false,
        'maintenance_mode_allowed_ips' => '127.0.0.1,::1',
        'items_per_page'             => 20,
        'enable_email_notifications' => true,
        'allow_registration'         => true,
        'require_email_verification' => true,
        'cache_expiry_seconds'       => 300,
    ];

    // ────────────────────────────────────────
    // CONSTRUCTOR
    // ────────────────────────────────────────

    private function __construct() {
        global $conn;
        $this->conn     = $conn;
        $this->apcu_key = 'pta_settings_' . DB_NAME;

        // Try APCu first — avoids any DB query on cache hit
        if ($this->loadFromApcu()) {
            return;
        }

        // APCu miss (or unavailable) — load from DB
        $this->loadCache();

        if (empty($this->cache)) {
            $this->initializeSettings();
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ────────────────────────────────────────
    // APCU CACHE
    // ────────────────────────────────────────

    /**
     * Try to populate $this->cache from APCu.
     * Returns true on hit, false on miss or APCu unavailable.
     */
    private function loadFromApcu(): bool {
        if (!function_exists('apcu_fetch')) {
            return false;
        }

        $success = false;
        $data    = apcu_fetch($this->apcu_key, $success);

        if ($success && is_array($data)) {
            $this->cache          = $data['cache'];
            $this->cacheTimestamp = $data['timestamp'];
            $this->cacheExpiry    = $data['expiry'];
            return true;
        }

        return false;
    }

    /**
     * Write the current in-memory cache to APCu.
     * TTL matches $this->cacheExpiry so APCu auto-expires the entry.
     */
    private function saveToApcu(): void {
        if (!function_exists('apcu_store')) {
            return;
        }

        apcu_store($this->apcu_key, [
            'cache'     => $this->cache,
            'timestamp' => $this->cacheTimestamp,
            'expiry'    => $this->cacheExpiry,
        ], $this->cacheExpiry);
    }

    /**
     * Invalidate the APCu entry immediately (call after set()).
     */
    private function invalidateApcu(): void {
        if (function_exists('apcu_delete')) {
            apcu_delete($this->apcu_key);
        }
    }

    // ────────────────────────────────────────
    // DB CACHE
    // ────────────────────────────────────────

    private function loadCache(): void {
        if ($this->cacheTimestamp && (time() - $this->cacheTimestamp) < $this->cacheExpiry) {
            return; // In-process cache still valid
        }

        try {
            $result = $this->conn->query(
                "SELECT setting_key, setting_value, setting_type FROM system_settings"
            );

            if ($result) {
                $this->cache = [];
                while ($row = $result->fetch_assoc()) {
                    $this->cache[$row['setting_key']] = [
                        'value' => $this->convertValue($row['setting_value'], $row['setting_type']),
                        'type'  => $row['setting_type'],
                    ];
                }
                $this->cacheTimestamp = time();
                $result->free();

                if (isset($this->cache['cache_expiry_seconds'])) {
                    $this->cacheExpiry = (int) $this->cache['cache_expiry_seconds']['value'];
                }

                // Persist to APCu so the next request skips this DB query
                $this->saveToApcu();

                error_log("SystemSettings: loaded " . count($this->cache) . " settings from DB");
            } else {
                error_log("SystemSettings: failed to load — " . $this->conn->error);
            }
        } catch (Exception $e) {
            error_log("SystemSettings loadCache error: " . $e->getMessage());
        }
    }

    public function refreshCache(): void {
        $this->cacheTimestamp = null;
        $this->invalidateApcu();
        $this->loadCache();
    }

    public function clearCache(): void {
        $this->cache          = [];
        $this->cacheTimestamp = null;
        $this->invalidateApcu();
    }

    // ────────────────────────────────────────
    // GET / SET
    // ────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed {
        if (array_key_exists($key, self::IMMUTABLE_SETTINGS)) {
            return self::IMMUTABLE_SETTINGS[$key];
        }

        $this->loadCache(); // No-op if in-process cache is fresh

        if (isset($this->cache[$key])) {
            return $this->cache[$key]['value'];
        }

        if (array_key_exists($key, self::DEFAULT_CONFIGURABLE_SETTINGS)) {
            return self::DEFAULT_CONFIGURABLE_SETTINGS[$key];
        }

        return $default;
    }

    public function getMultiple(array $keys): array {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    public function getAll(): array {
        $this->loadCache();
        $all = [];

        foreach (self::IMMUTABLE_SETTINGS as $k => $v) {
            $all[$k] = ['value' => $v, 'type' => $this->determineType($v), 'immutable' => true];
        }
        foreach ($this->cache as $k => $data) {
            $all[$k] = ['value' => $data['value'], 'type' => $data['type'], 'immutable' => false];
        }
        foreach (self::DEFAULT_CONFIGURABLE_SETTINGS as $k => $v) {
            if (!isset($all[$k])) {
                $all[$k] = ['value' => $v, 'type' => $this->determineType($v), 'immutable' => false, 'is_default' => true];
            }
        }

        return $all;
    }

    public function set(string $key, mixed $value, ?int $userId = null): bool {
        if ($this->isImmutable($key)) {
            error_log("SystemSettings: attempted to modify immutable setting: $key");
            return false;
        }

        if (!$this->validate($key, $value)) {
            error_log("SystemSettings: validation failed for: $key");
            return false;
        }

        $type     = $this->determineType($value);
        $valueStr = $this->convertToString($value, $type);

        try {
            $stmt = $this->conn->prepare(
                "SELECT is_editable FROM system_settings WHERE setting_key = ?"
            );
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $result  = $stmt->get_result();
            $setting = $result->fetch_assoc();
            $stmt->close();

            if ($setting) {
                if (!$setting['is_editable']) {
                    error_log("SystemSettings: attempted to modify non-editable setting: $key");
                    return false;
                }
                $stmt = $this->conn->prepare(
                    "UPDATE system_settings
                     SET setting_value = ?, setting_type = ?, updated_by = ?, updated_at = NOW()
                     WHERE setting_key = ?"
                );
                $stmt->bind_param("ssis", $valueStr, $type, $userId, $key);
            } else {
                $description = $this->getSettingDescription($key);
                $stmt = $this->conn->prepare(
                    "INSERT INTO system_settings
                        (setting_key, setting_value, setting_type, description, updated_by)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("ssssi", $key, $valueStr, $type, $description, $userId);
            }

            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                // Update in-process cache immediately
                $this->cache[$key] = ['value' => $value, 'type' => $type];
                // Bust APCu so other workers pick up the change on next request
                $this->invalidateApcu();
            }

            return $ok;

        } catch (Exception $e) {
            error_log("SystemSettings set() error: " . $e->getMessage());
            return false;
        }
    }

    // ────────────────────────────────────────
    // INITIALISATION
    // ────────────────────────────────────────

    private function needsInitialization(): bool {
        try {
            $result = $this->conn->query("SELECT COUNT(*) AS count FROM system_settings");
            if ($result) {
                $row   = $result->fetch_assoc();
                $count = (int) $row['count'];
                $result->free();
                return $count < count(self::DEFAULT_CONFIGURABLE_SETTINGS);
            }
            return true;
        } catch (Exception $e) {
            error_log("SystemSettings needsInitialization error: " . $e->getMessage());
            return false;
        }
    }

    private function initializeSettings(): void {
        if ($this->initialized) {
            return;
        }
        if (!$this->needsInitialization()) {
            $this->initialized = true;
            return;
        }

        error_log("SystemSettings: initializing missing default settings...");

        try {
            $values = [];
            $types  = "";
            $params = [];

            foreach (self::DEFAULT_CONFIGURABLE_SETTINGS as $key => $value) {
                $type        = $this->determineType($value);
                $valueStr    = $this->convertToString($value, $type);
                $description = $this->getSettingDescription($key);

                $params[]  = $key;
                $params[]  = $valueStr;
                $params[]  = $type;
                $params[]  = $description;
                $types    .= "ssss";
                $values[]  = "(?, ?, ?, ?, 1)";
            }

            if (!empty($values)) {
                $sql  = "INSERT IGNORE INTO system_settings
                            (setting_key, setting_value, setting_type, description, is_editable)
                         VALUES " . implode(', ', $values);
                $stmt = $this->conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    error_log("SystemSettings: initialized {$stmt->affected_rows} settings");
                    $stmt->close();
                }
            }

            $this->initialized = true;

        } catch (Exception $e) {
            error_log("SystemSettings initializeSettings error: " . $e->getMessage());
        }
    }

    // ────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────

    public function isImmutable(string $key): bool {
        return array_key_exists($key, self::IMMUTABLE_SETTINGS);
    }

    public function validate(string $key, mixed $value): bool {
        // Add per-key validation rules here as needed
        return true;
    }

    private function determineType(mixed $value): string {
        return match (true) {
            is_bool($value)  => 'boolean',
            is_int($value)   => 'integer',
            is_float($value) => 'float',
            default          => 'string',
        };
    }

    private function convertValue(string $value, string $type): mixed {
        return match ($type) {
            'boolean' => in_array(strtolower($value), ['1', 'true', 'yes'], true),
            'integer' => (int) $value,
            'float'   => (float) $value,
            default   => $value,
        };
    }

    private function convertToString(mixed $value, string $type): string {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            default   => (string) $value,
        };
    }

    private function getSettingDescription(string $key): string {
        $descriptions = [
            'session_timeout_minutes'    => 'Session timeout in minutes',
            'max_login_attempts'         => 'Maximum failed login attempts before lockout',
            'lockout_duration_minutes'   => 'Account lockout duration in minutes',
            'otp_expiry_minutes'         => 'OTP expiration time in minutes',
            'allow_guest_tests'          => 'Allow guest users to take public tests',
            'max_file_size_mb'           => 'Maximum file upload size in MB',
            'results_retention_days'     => 'How long to keep assessment results',
            'enable_proctoring'          => 'Enable proctoring features',
            'smtp_configured'            => 'Is SMTP email configured',
            'maintenance_mode'           => 'Enable maintenance mode',
            'maintenance_mode_allowed_ips' => 'IPs allowed during maintenance',
            'items_per_page'             => 'Default items per page in lists',
            'enable_email_notifications' => 'Enable email notifications',
            'allow_registration'         => 'Allow new user registration',
            'require_email_verification' => 'Require email verification for new accounts',
            'cache_expiry_seconds'       => 'Settings cache TTL in seconds',
        ];
        return $descriptions[$key] ?? ucwords(str_replace('_', ' ', $key));
    }

    public function getMaxFileSize(): int {
        return (int) $this->get('max_file_size_mb', 10) * 1024 * 1024;
    }

    public function getAllowedFileTypes(): array {
        $typesStr = $this->get('allowed_file_types', '');
        if (empty($typesStr)) {
            return [];
        }
        return array_map('trim', explode(',', $typesStr));
    }

    private function __clone() {}

    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// ── Global helper functions ──

function getSystemSetting(string $key, mixed $default = null): mixed {
    return SystemSettings::getInstance()->get($key, $default);
}

function updateSystemSetting(string $key, mixed $value, ?int $userId = null): bool {
    return SystemSettings::getInstance()->set($key, $value, $userId);
}