<?php
/* ========================================
 * PTA SYSTEM SETTINGS MANAGER
 * File: system-settings.php
 *
 * Purpose: Centralized system settings with multi-tier architecture
 *
 * Architecture:
 * - Tier 1 (IMMUTABLE): Core constants that should NEVER change at runtime
 * - Tier 2 (CONFIGURABLE): Settings that can be changed via admin panel
 * - Tier 3 (CACHED): Database settings cached for performance
 *
 * Usage:
 * require_once "config.php";
 * require_once "system-settings.php";
 *
 * $settings = SystemSettings::getInstance();
 * $maxAttempts = $settings->get('max_login_attempts');
 * $settings->set('session_timeout_minutes', 90, $userId);
 *
 * ======================================== */

class SystemSettings {
  private static $instance = null;
  private $cache = [];
  private $cacheExpiry = 300; // 5 minutes cache
  private $cacheTimestamp = null;
  private $conn = null;

  /* ========================================
   *     TIER 1: IMMUTABLE CORE CONSTANTS
   *     These are security-critical and should NOT be changed at runtime
   *     Defined as PHP constants for maximum performance and security
   *     ======================================== */

  const IMMUTABLE_SETTINGS = [
    // Security Settings (DO NOT change via admin panel)
    'PASSWORD_HASH_ALGO' => PASSWORD_DEFAULT,
    'PASSWORD_MIN_LENGTH' => 8,
    'PASSWORD_REQUIRE_UPPERCASE' => true,
    'PASSWORD_REQUIRE_LOWERCASE' => true,
    'PASSWORD_REQUIRE_NUMBER' => true,
    'PASSWORD_REQUIRE_SPECIAL' => false,

    // Database Settings (handled by config.php)
    'DB_CHARSET' => 'utf8mb4',
    'DB_COLLATION' => 'utf8mb4_unicode_ci',

    // File Upload Core Limits (system limits)
    'ABSOLUTE_MAX_FILE_SIZE' => 50 * 1024 * 1024, // 50 MB absolute maximum
    'UPLOAD_BASE_DIR' => 'uploads/',

    // Pagination Core Limits
    'ABSOLUTE_MAX_ITEMS_PER_PAGE' => 500, // Prevent memory overflow

    // Token Security
    'TOKEN_MIN_LENGTH' => 32,
    'CSRF_TOKEN_LENGTH' => 64,
  ];

  /* ========================================
   *     TIER 2: DEFAULT CONFIGURABLE SETTINGS
   *     These can be overridden via database (system_settings table)
   *     Admins can change these through the admin panel
   *     ======================================== */

  const DEFAULT_CONFIGURABLE_SETTINGS = [
    // Authentication & Session
    'session_timeout_minutes' => 60,
    'max_login_attempts' => 5,
    'lockout_duration_minutes' => 30,
    'remember_me_duration_days' => 30,
    'force_password_change_days' => 90,

    // OTP & Verification
    'otp_expiry_minutes' => 15,
    'otp_length' => 6,
    'email_verification_expiry_hours' => 24,
    'resend_otp_cooldown_seconds' => 60,

    // Assessment Settings
    'allow_guest_tests' => true,
    'max_assessment_duration_minutes' => 180, // 3 hours max
    'auto_submit_on_timeout' => true,
    'show_results_immediately' => true,
    'allow_review_after_submission' => true,

    // Proctoring
    'enable_proctoring' => false,
    'proctoring_tab_switch_limit' => 3,
    'proctoring_strict_mode' => false,

    // File Upload Settings (configurable within core limits)
    'max_file_size_mb' => 10,
    'allowed_file_types' => 'pdf,jpg,jpeg,png,doc,docx',
    'enable_file_virus_scan' => false,

    // Pagination
    'items_per_page' => 20,
    'max_items_per_page' => 100,

    // Notifications
    'enable_email_notifications' => true,
    'enable_push_notifications' => false,
    'notification_retention_days' => 30,

    // Data Retention
    'results_retention_days' => 365,
    'login_activity_retention_days' => 90,
    'audit_log_retention_days' => 730, // 2 years

    // System Behavior
    'maintenance_mode' => false,
    'allow_registration' => true,
    'require_email_verification' => true,
    'default_user_timezone' => 'Asia/Kolkata',

    // SMTP Configuration Status
    'smtp_configured' => false,
    'smtp_from_email' => 'noreply@pta-platform.local',
    'smtp_from_name' => 'PTA Platform',

    // Performance
    'enable_query_cache' => true,
    'cache_expiry_seconds' => 300,
    'enable_compression' => true,

    // Analytics
    'track_user_activity' => true,
    'enable_advanced_analytics' => true,
    'analytics_retention_days' => 365,
  ];

  /* ========================================
   *     SINGLETON PATTERN
   *     ======================================== */

  private function __construct() {
    global $conn;
    $this->conn = $conn;
    $this->loadCache();
  }

  public static function getInstance() {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /* ========================================
   *     CACHE MANAGEMENT
   *     ======================================== */

  /**
   * Load all settings from database into memory cache
   */
  private function loadCache() {
    // Check if cache is still valid
    if ($this->cacheTimestamp && (time() - $this->cacheTimestamp) < $this->cacheExpiry) {
      return; // Cache still valid
    }

    // Load from database
    $result = $this->conn->query("SELECT setting_key, setting_value, setting_type FROM system_settings");

    if ($result) {
      $this->cache = [];
      while ($row = $result->fetch_assoc()) {
        $this->cache[$row['setting_key']] = [
          'value' => $this->convertValue($row['setting_value'], $row['setting_type']),
          'type' => $row['setting_type']
        ];
      }
      $this->cacheTimestamp = time();
    } else {
      error_log("Failed to load system settings: " . $this->conn->error);
    }
  }

  /**
   * Force cache refresh
   */
  public function refreshCache() {
    $this->cacheTimestamp = null;
    $this->loadCache();
  }

  /**
   * Clear cache (forces reload on next get)
   */
  public function clearCache() {
    $this->cache = [];
    $this->cacheTimestamp = null;
  }

  /* ========================================
   *     GET SETTING
   *     ======================================== */

  /**
   * Get a setting value
   * @param string $key Setting key
   * @param mixed $default Default value if not found
   * @return mixed Setting value
   */
  public function get($key, $default = null) {
    // First check immutable settings
    if (array_key_exists($key, self::IMMUTABLE_SETTINGS)) {
      return self::IMMUTABLE_SETTINGS[$key];
    }

    // Refresh cache if expired
    $this->loadCache();

    // Check cache
    if (isset($this->cache[$key])) {
      return $this->cache[$key]['value'];
    }

    // Check default configurable settings
    if (array_key_exists($key, self::DEFAULT_CONFIGURABLE_SETTINGS)) {
      return self::DEFAULT_CONFIGURABLE_SETTINGS[$key];
    }

    // Return provided default
    return $default;
  }

  /**
   * Get multiple settings at once
   * @param array $keys Array of setting keys
   * @return array Associative array of key => value
   */
  public function getMultiple(array $keys) {
    $result = [];
    foreach ($keys as $key) {
      $result[$key] = $this->get($key);
    }
    return $result;
  }

  /**
   * Get all settings
   * @return array All settings
   */
  public function getAll() {
    $this->loadCache();

    $all = [];

    // Add immutable settings
    foreach (self::IMMUTABLE_SETTINGS as $key => $value) {
      $all[$key] = [
        'value' => $value,
        'type' => gettype($value),
        'immutable' => true
      ];
    }

    // Add cached settings from database
    foreach ($this->cache as $key => $data) {
      $all[$key] = [
        'value' => $data['value'],
        'type' => $data['type'],
        'immutable' => false
      ];
    }

    // Add defaults not in database
    foreach (self::DEFAULT_CONFIGURABLE_SETTINGS as $key => $value) {
      if (!isset($all[$key])) {
        $all[$key] = [
          'value' => $value,
          'type' => gettype($value),
          'immutable' => false,
          'is_default' => true
        ];
      }
    }

    return $all;
  }

  /* ========================================
   *     SET SETTING
   *     ======================================== */

  /**
   * Update a setting (only configurable settings)
   * @param string $key Setting key
   * @param mixed $value New value
   * @param int $userId User making the change
   * @return bool Success status
   */
  public function set($key, $value, $userId = null) {
    // Prevent changing immutable settings
    if (array_key_exists($key, self::IMMUTABLE_SETTINGS)) {
      error_log("Attempted to modify immutable setting: $key");
      return false;
    }

    // Determine type
    $type = $this->determineType($value);

    // Convert value to string for storage
    $valueStr = $this->convertToString($value, $type);

    // Check if setting exists
    $stmt = $this->conn->prepare("SELECT setting_key FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($exists) {
      // Update existing
      $stmt = $this->conn->prepare(
        "UPDATE system_settings
        SET setting_value = ?, setting_type = ?, updated_by = ?, updated_at = NOW()
      WHERE setting_key = ?"
      );
      $stmt->bind_param("ssis", $valueStr, $type, $userId, $key);
    } else {
      // Insert new
      $stmt = $this->conn->prepare(
        "INSERT INTO system_settings
        (setting_key, setting_value, setting_type, updated_by)
      VALUES (?, ?, ?, ?)"
      );
      $stmt->bind_param("sssi", $key, $valueStr, $type, $userId);
    }

    $success = $stmt->execute();
    $stmt->close();

    if ($success) {
      // Update cache
      $this->cache[$key] = [
        'value' => $value,
        'type' => $type
      ];

      // Log audit trail
      if (function_exists('logAudit')) {
        logAudit($userId, 'UPDATE_SETTING', 'system_settings', null, null, [
          'key' => $key,
          'value' => $value
        ]);
      }
    }

    return $success;
  }

  /**
   * Batch update multiple settings
   * @param array $settings Associative array of key => value
   * @param int $userId User making the changes
   * @return bool Success status
   */
  public function setMultiple(array $settings, $userId = null) {
    $allSuccess = true;

    foreach ($settings as $key => $value) {
      if (!$this->set($key, $value, $userId)) {
        $allSuccess = false;
        error_log("Failed to set setting: $key");
      }
    }

    return $allSuccess;
  }

  /* ========================================
   *     TYPE CONVERSION HELPERS
   *     ======================================== */

  private function determineType($value) {
    if (is_bool($value)) return 'boolean';
    if (is_numeric($value)) return 'number';
    if (is_array($value)) return 'json';
    return 'string';
  }

  private function convertToString($value, $type) {
    switch ($type) {
      case 'boolean':
        return $value ? 'true' : 'false';
      case 'json':
        return json_encode($value);
      default:
        return (string)$value;
    }
  }

  private function convertValue($value, $type) {
    switch ($type) {
      case 'boolean':
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
      case 'number':
        return is_numeric($value) ? (strpos($value, '.') !== false ? (float)$value : (int)$value) : 0;
      case 'json':
        return json_decode($value, true);
      default:
        return $value;
    }
  }

  /* ========================================
   *     VALIDATION & SECURITY
   *     ======================================== */

  /**
   * Check if a setting is immutable
   * @param string $key Setting key
   * @return bool
   */
  public function isImmutable($key) {
    return array_key_exists($key, self::IMMUTABLE_SETTINGS);
  }

  /**
   * Validate setting value before saving
   * @param string $key Setting key
   * @param mixed $value Value to validate
   * @return bool Valid or not
   */
  public function validate($key, $value) {
    // Add validation rules here
    switch ($key) {
      case 'max_login_attempts':
        return is_numeric($value) && $value >= 1 && $value <= 20;

      case 'session_timeout_minutes':
        return is_numeric($value) && $value >= 5 && $value <= 1440; // 5 min to 24 hours

      case 'lockout_duration_minutes':
        return is_numeric($value) && $value >= 1 && $value <= 1440;

      case 'otp_expiry_minutes':
        return is_numeric($value) && $value >= 5 && $value <= 60;

      case 'max_file_size_mb':
        $absoluteMax = self::IMMUTABLE_SETTINGS['ABSOLUTE_MAX_FILE_SIZE'] / (1024 * 1024);
        return is_numeric($value) && $value >= 1 && $value <= $absoluteMax;

      case 'items_per_page':
        return is_numeric($value) && $value >= 5 && $value <= self::get('max_items_per_page', 100);

      default:
        return true; // No specific validation
    }
  }

  /* ========================================
   *     CONVENIENCE METHODS
   *     ======================================== */

  /**
   * Check if maintenance mode is active
   * @return bool
   */
  public function isMaintenanceMode() {
    return $this->get('maintenance_mode', false);
  }

  /**
   * Check if SMTP is configured
   * @return bool
   */
  public function isSmtpConfigured() {
    return $this->get('smtp_configured', false);
  }

  /**
   * Get session timeout in seconds
   * @return int
   */
  public function getSessionTimeout() {
    return $this->get('session_timeout_minutes', 60) * 60;
  }

  /**
   * Get max file size in bytes
   * @return int
   */
  public function getMaxFileSize() {
    return $this->get('max_file_size_mb', 10) * 1024 * 1024;
  }

  /* ========================================
   *     EXPORT SETTINGS FOR ADMIN PANEL
   *     ======================================== */

  /**
   * Get settings grouped by category for admin panel
   * @return array
   */
  public function getGroupedSettings() {
    $all = $this->getAll();

    $grouped = [
      'Authentication & Security' => [],
      'Assessment Settings' => [],
      'File Upload' => [],
      'Email & Notifications' => [],
      'Performance' => [],
      'Data Retention' => [],
      'System Behavior' => [],
      'Immutable (Read-Only)' => []
    ];

    foreach ($all as $key => $data) {
      $category = $this->categorize($key);
      $grouped[$category][$key] = $data;
    }

    return $grouped;
  }

  private function categorize($key) {
    if (strpos($key, 'session') !== false || strpos($key, 'login') !== false ||
      strpos($key, 'password') !== false || strpos($key, 'otp') !== false) {
      return 'Authentication & Security';
      }
      if (strpos($key, 'assessment') !== false || strpos($key, 'proctoring') !== false) {
        return 'Assessment Settings';
      }
      if (strpos($key, 'file') !== false || strpos($key, 'upload') !== false) {
        return 'File Upload';
      }
      if (strpos($key, 'email') !== false || strpos($key, 'smtp') !== false ||
        strpos($key, 'notification') !== false) {
        return 'Email & Notifications';
        }
        if (strpos($key, 'cache') !== false || strpos($key, 'performance') !== false ||
          strpos($key, 'compression') !== false) {
          return 'Performance';
          }
          if (strpos($key, 'retention') !== false) {
            return 'Data Retention';
          }
          if (array_key_exists($key, self::IMMUTABLE_SETTINGS)) {
            return 'Immutable (Read-Only)';
          }
          return 'System Behavior';
  }

  /* ========================================
   *     PREVENT CLONING & SERIALIZATION
   *     ======================================== */

  private function __clone() {}

  public function __wakeup() {
    throw new Exception("Cannot unserialize singleton");
  }
}

/* ========================================
 * GLOBAL HELPER FUNCTION
 * For backward compatibility with existing code
 * ======================================== */

/**
 * Get system setting (backward compatible)
 * @param string $key Setting key
 * @param mixed $default Default value
 * @return mixed Setting value
 */
function getSystemSetting($key, $default = null) {
  return SystemSettings::getInstance()->get($key, $default);
}

/**
 * Update system setting (backward compatible)
 * @param string $key Setting key
 * @param mixed $value New value
 * @param int $userId User ID
 * @return bool Success
 */
function updateSystemSetting($key, $value, $userId = null) {
  $settings = SystemSettings::getInstance();

  // Validate before setting
  if (!$settings->validate($key, $value)) {
    error_log("Setting validation failed for $key");
    return false;
  }

  return $settings->set($key, $value, $userId);
}

/* ========================================
 * USAGE EXAMPLES
 *
 * 1. Get Setting:
 * $settings = SystemSettings::getInstance();
 * $timeout = $settings->get('session_timeout_minutes'); // Returns 60
 *
 * 2. Update Setting:
 * $settings->set('max_login_attempts', 3, $userId);
 *
 * 3. Check Maintenance Mode:
 * if ($settings->isMaintenanceMode()) {
 *     die("System is under maintenance");
 * }
 *
 * 4. Get Multiple Settings:
 * $auth = $settings->getMultiple([
 *     'max_login_attempts',
 *     'lockout_duration_minutes',
 *     'session_timeout_minutes'
 * ]);
 *
 * 5. Backward Compatible:
 * $timeout = getSystemSetting('session_timeout_minutes', 60);
 *
 * 6. For Admin Panel:
 * $grouped = $settings->getGroupedSettings();
 * foreach ($grouped as $category => $settings) {
 *     echo "<h3>$category</h3>";
 *     foreach ($settings as $key => $data) {
 *         echo "$key: " . $data['value'];
 *     }
 * }
 *
 * ======================================== */
?>
