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
 *
 * SECURITY FIXES (round 1):
 * 1. set() requires a valid admin userId — rejects unauthorized callers.
 * 2. validate() enforces per-key range/type rules instead of always returning true.
 * 3. cache_expiry_seconds is clamped (30–3600) when loaded from DB.
 * 4. initializeSettings() uses a DB advisory lock to prevent race conditions.
 * 5. getAllowedFileTypes() whitelists safe extensions only.
 * 6. Constructor validates that $conn is a live PDO instance.
 * 7. APCu key is namespaced with a versioned prefix to prevent cross-app collisions.
 *
 * SECURITY FIXES (round 2):
 * 8.  set() no longer accepts userId as a parameter — admin identity is resolved
 *     from the validated session internally, blocking privilege escalation.
 * 9.  loadFromApcu() validates APCu payload structure before trusting it.
 * 10. max_file_size_mb validation is bound to ABSOLUTE_MAX_FILE_SIZE constant.
 * 11. maintenance_mode_allowed_ips validates each entry as a real IP address.
 * 12. set() rejects setting keys longer than 100 characters.
 * ======================================== */

class SystemSettings {
    private static ?self $instance = null;

    private array   $cache          = [];
    private int     $cacheExpiry    = 300;   // seconds — also stored as a setting
    private ?int    $cacheTimestamp = null;
    private ?PDO    $conn           = null;
    private bool    $initialized    = false;

    // FIX 7: versioned prefix prevents cross-app APCu collisions
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
        'session_timeout_minutes'      => 60,
        'max_login_attempts'           => 5,
        'lockout_duration_minutes'     => 15,
        'otp_expiry_minutes'           => 10,
        'allow_guest_tests'            => false,
        'max_file_size_mb'             => 10,
        'results_retention_days'       => 365,
        'enable_proctoring'            => false,
        'smtp_configured'              => false,
        'maintenance_mode'             => false,
        'maintenance_mode_allowed_ips' => '127.0.0.1,::1',
        'items_per_page'               => 20,
        'enable_email_notifications'   => true,
        'allow_registration'           => true,
        'require_email_verification'   => true,
        'cache_expiry_seconds'         => 300,
    ];

    // Whitelist of safe file extensions for getAllowedFileTypes()
    private const SAFE_FILE_EXTENSIONS = ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'csv', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt'];

    // Maximum allowed IP entries in maintenance_mode_allowed_ips
    private const MAX_ALLOWED_IPS = 50;

    // Maximum length for a setting key
    private const MAX_KEY_LENGTH = 100;

    // ────────────────────────────────────────
    // CONSTRUCTOR
    // ────────────────────────────────────────

    private function __construct() {
        global $conn;

        // FIX 6: validate DB connection before use
        if (!$conn instanceof PDO) {
            throw new RuntimeException("SystemSettings: database connection not initialized or invalid");
        }
        $this->conn = $conn;

        // FIX 7: versioned, hashed APCu key prevents cross-app collisions
        $this->apcu_key = 'pta_v1_settings_' . hash('sha256', DB_NAME);

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
     *
     * FIX 9: validates payload structure before trusting any field.
     */
    private function loadFromApcu(): bool {
        if (!function_exists('apcu_fetch')) {
            return false;
        }

        $success = false;
        $data    = apcu_fetch($this->apcu_key, $success);

        // FIX 9: require all expected keys and correct types before using the payload
        if (
            $success &&
            is_array($data) &&
            isset($data['cache'], $data['timestamp'], $data['expiry']) &&
            is_array($data['cache']) &&
            is_int($data['timestamp']) &&
            is_int($data['expiry'])
        ) {
            $this->cache          = $data['cache'];
            $this->cacheTimestamp = $data['timestamp'];
            $this->cacheExpiry    = max(30, min(3600, $data['expiry'])); // FIX: clamp to same safe range as DB-loaded values
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
            $stmt = $this->conn->query(
                "SELECT setting_key, setting_value, setting_type FROM system_settings"
            );

            if ($stmt) {
                $this->cache = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $this->cache[$row['setting_key']] = [
                        'value' => $this->convertValue($row['setting_value'], $row['setting_type']),
                        'type'  => $row['setting_type'],
                    ];
                }
                $this->cacheTimestamp = time();

                // FIX 3: clamp cache_expiry_seconds to a safe range (30–3600 s)
                if (isset($this->cache['cache_expiry_seconds'])) {
                    $this->cacheExpiry = max(30, min(3600, (int) $this->cache['cache_expiry_seconds']['value']));
                    $this->cache['cache_expiry_seconds']['value'] = $this->cacheExpiry;
                }

                // Persist to APCu so the next request skips this DB query
                $this->saveToApcu();

                error_log("SystemSettings: loaded " . count($this->cache) . " settings from DB");
            } else {
                error_log("SystemSettings: failed to load — query returned false");
            }
        } catch (PDOException $e) {
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

        foreach ($this->cache as $k => $entry) {
            if (!isset($all[$k])) {
                $all[$k] = ['value' => $entry['value'], 'type' => $entry['type'], 'immutable' => false];
            }
        }

        foreach (self::DEFAULT_CONFIGURABLE_SETTINGS as $k => $v) {
            if (!isset($all[$k])) {
                $all[$k] = ['value' => $v, 'type' => $this->determineType($v), 'immutable' => false, 'is_default' => true];
            }
        }

        return $all;
    }

    /**
     * FIX 8:  userId is no longer a caller-supplied parameter — admin identity
     *         is resolved from the validated session internally.
     * FIX 12: keys longer than MAX_KEY_LENGTH are rejected before any DB write.
     */
    public function set(string $key, mixed $value): bool {
        // FIX 12: reject oversized keys before any DB interaction
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            error_log("SystemSettings: rejected oversized key (" . strlen($key) . " chars)");
            return false;
        }

        // FIX 8: resolve admin identity from session — never trust caller input
        $userId = $this->getSessionAdminId();

        if ($userId === null) {
            error_log("SystemSettings: set() rejected — no authenticated admin session for key: $key");
            return false;
        }

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
            $stmt->execute([$key]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);

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
                $ok = $stmt->execute([$valueStr, $type, $userId, $key]);
            } else {
                $description = $this->getSettingDescription($key);
                $stmt = $this->conn->prepare(
                    "INSERT INTO system_settings
                        (setting_key, setting_value, setting_type, description, updated_by)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $ok = $stmt->execute([$key, $valueStr, $type, $description, $userId]);
            }

            if ($ok) {
                // Update in-process cache immediately
                $this->cache[$key] = ['value' => $value, 'type' => $type];
                // Bust APCu so other workers pick up the change on next request
                $this->invalidateApcu();
            }

            return $ok;

        } catch (PDOException $e) {
            error_log("SystemSettings set() error: " . $e->getMessage());
            return false;
        }
    }

    // ────────────────────────────────────────
    // INITIALISATION
    // ────────────────────────────────────────

    private function needsInitialization(): bool {
        try {
            $stmt = $this->conn->query("SELECT COUNT(*) AS count FROM system_settings");
            if ($stmt) {
                $row   = $stmt->fetch(PDO::FETCH_ASSOC);
                $count = (int) $row['count'];
                return $count < count(self::DEFAULT_CONFIGURABLE_SETTINGS);
            }
            return true;
        } catch (PDOException $e) {
            error_log("SystemSettings needsInitialization error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * FIX 4: use a MySQL advisory lock to prevent concurrent initialization
     * across multiple PHP workers starting simultaneously.
     */
    private function initializeSettings(): void {
        if ($this->initialized) {
            return;
        }
        if (!$this->needsInitialization()) {
            $this->initialized = true;
            return;
        }

        // Acquire advisory lock (Postgres session-level lock keyed by a fixed
        // hashed name — pg_try_advisory_lock is non-blocking, so we poll briefly
        // to emulate MySQL's GET_LOCK(name, timeout) behaviour).
        $lockKey    = "SELECT hashtext('pta_settings_init')::bigint AS k";
        $keyRow     = $this->conn->query($lockKey)->fetch(PDO::FETCH_ASSOC);
        $lockId     = $keyRow['k'];

        $acquired = false;
        $deadline = microtime(true) + 5; // wait up to 5 seconds, like the old GET_LOCK timeout
        do {
            $stmt = $this->conn->prepare("SELECT pg_try_advisory_lock(?) AS acquired");
            $stmt->execute([$lockId]);
            $acquired = (bool) $stmt->fetchColumn();
            if (!$acquired) {
                usleep(100000); // 100ms
            }
        } while (!$acquired && microtime(true) < $deadline);

        if (!$acquired) {
            error_log("SystemSettings: could not acquire init lock — skipping initialization");
            return;
        }

        // Re-check after acquiring lock; another worker may have already initialized
        if (!$this->needsInitialization()) {
            $this->conn->prepare("SELECT pg_advisory_unlock(?)")->execute([$lockId]);
            $this->initialized = true;
            return;
        }

        error_log("SystemSettings: initializing missing default settings...");

        try {
            $rowsAffected = 0;

            // Postgres has no INSERT IGNORE — use ON CONFLICT DO NOTHING per row
            // (setting_key is expected to be the unique/primary key on this table).
            $stmt = $this->conn->prepare(
                "INSERT INTO system_settings
                    (setting_key, setting_value, setting_type, description, is_editable)
                 VALUES (?, ?, ?, ?, true)
                 ON CONFLICT (setting_key) DO NOTHING"
            );

            foreach (self::DEFAULT_CONFIGURABLE_SETTINGS as $key => $value) {
                $type        = $this->determineType($value);
                $valueStr    = $this->convertToString($value, $type);
                $description = $this->getSettingDescription($key);

                $stmt->execute([$key, $valueStr, $type, $description]);
                $rowsAffected += $stmt->rowCount();
            }

            error_log("SystemSettings: initialized {$rowsAffected} settings");

            $this->initialized = true;

        } catch (PDOException $e) {
            error_log("SystemSettings initializeSettings error: " . $e->getMessage());
        } finally {
            $this->conn->prepare("SELECT pg_advisory_unlock(?)")->execute([$lockId]);
        }
    }

    // ────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────

    public function isImmutable(string $key): bool {
        return array_key_exists($key, self::IMMUTABLE_SETTINGS);
    }

    /**
     * FIX 2:  enforce per-key validation rules.
     * FIX 10: max_file_size_mb bound to ABSOLUTE_MAX_FILE_SIZE constant.
     * FIX 11: maintenance_mode_allowed_ips validates each entry as a real IP.
     */
    public function validate(string $key, mixed $value): bool {
        return match ($key) {
            'session_timeout_minutes'  => is_int($value) && $value >= 5  && $value <= 1440,
            'max_login_attempts'       => is_int($value) && $value >= 3  && $value <= 20,
            'lockout_duration_minutes' => is_int($value) && $value >= 1  && $value <= 1440,
            'otp_expiry_minutes'       => is_int($value) && $value >= 1  && $value <= 60,

            // FIX 10: upper bound derived from immutable constant, not a magic number
            'max_file_size_mb' =>
                is_int($value) &&
                $value >= 1 &&
                ($value * 1024 * 1024) <= self::IMMUTABLE_SETTINGS['ABSOLUTE_MAX_FILE_SIZE'],

            'items_per_page'       => is_int($value) && $value >= 1  && $value <= self::IMMUTABLE_SETTINGS['ABSOLUTE_MAX_ITEMS_PER_PAGE'],
            'cache_expiry_seconds' => is_int($value) && $value >= 30 && $value <= 3600,
            'results_retention_days' => is_int($value) && $value >= 1 && $value <= 3650,

            'allow_guest_tests',
            'enable_proctoring',
            'smtp_configured',
            'maintenance_mode',
            'enable_email_notifications',
            'allow_registration',
            'require_email_verification' => is_bool($value),

            // FIX 11: each comma-separated entry must be a valid IP address
            'maintenance_mode_allowed_ips' => $this->validateIpList($value),

            default => true,
        };
    }

    /**
     * FIX 11: validates a comma-separated IP list.
     * Rejects wildcards, CIDR ranges, and malformed entries.
     * Caps the list at MAX_ALLOWED_IPS entries.
     */
    private function validateIpList(mixed $value): bool {
        if (!is_string($value) || strlen($value) > 1000) {
            return false;
        }

        $ips = array_map('trim', explode(',', $value));

        if (count($ips) > self::MAX_ALLOWED_IPS) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }
        }

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
            'session_timeout_minutes'      => 'Session timeout in minutes',
            'max_login_attempts'           => 'Maximum failed login attempts before lockout',
            'lockout_duration_minutes'     => 'Account lockout duration in minutes',
            'otp_expiry_minutes'           => 'OTP expiration time in minutes',
            'allow_guest_tests'            => 'Allow guest users to take public tests',
            'max_file_size_mb'             => 'Maximum file upload size in MB',
            'results_retention_days'       => 'How long to keep assessment results',
            'enable_proctoring'            => 'Enable proctoring features',
            'smtp_configured'              => 'Is SMTP email configured',
            'maintenance_mode'             => 'Enable maintenance mode',
            'maintenance_mode_allowed_ips' => 'IPs allowed during maintenance',
            'items_per_page'               => 'Default items per page in lists',
            'enable_email_notifications'   => 'Enable email notifications',
            'allow_registration'           => 'Allow new user registration',
            'require_email_verification'   => 'Require email verification for new accounts',
            'cache_expiry_seconds'         => 'Settings cache TTL in seconds',
        ];
        return $descriptions[$key] ?? ucwords(str_replace('_', ' ', $key));
    }

    public function getMaxFileSize(): int {
        return (int) $this->get('max_file_size_mb', 10) * 1024 * 1024;
    }

    /**
     * FIX 5: whitelist safe extensions — DB value cannot introduce dangerous types.
     */
    public function getAllowedFileTypes(): array {
        $typesStr = $this->get('allowed_file_types', '');
        if (empty($typesStr)) {
            return [];
        }

        $requested = array_map('trim', explode(',', strtolower($typesStr)));

        return array_values(array_intersect($requested, self::SAFE_FILE_EXTENSIONS));
    }

    /**
     * FIX 8: resolve the current admin user ID from the active session.
     * Returns the user_id integer if the session holds a verified active admin,
     * or null if there is no session or the user is not an admin.
     */
    private function getSessionAdminId(): ?int {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        // FIX: verify session was explicitly authenticated by the login flow,
        // not just that a user_id key happens to exist in the session.
        if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            return null;
        }

        $userId = $_SESSION['user_id'] ?? null;

        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return null;
        }

        $userId = (int) $userId;

        return $this->userIsAdmin($userId) ? $userId : null;
    }

    /**
     * Verify that a user ID belongs to an active admin account.
     * Queries the DB directly — no trust in caller-supplied flags.
     */
    private function userIsAdmin(int $userId): bool {
        try {
            $stmt = $this->conn->prepare(
                "SELECT role FROM users WHERE user_id = ? AND is_active = true LIMIT 1"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return isset($row['role']) && $row['role'] === 'admin';
        } catch (PDOException $e) {
            error_log("SystemSettings userIsAdmin error: " . $e->getMessage());
            return false;
        }
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

/**
 * FIX 8: userId parameter removed — admin identity is resolved from session internally.
 */
function updateSystemSetting(string $key, mixed $value): bool {
    return SystemSettings::getInstance()->set($key, $value);
}