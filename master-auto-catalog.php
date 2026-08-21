<?php
/**
 * Plugin Name: Мастер настроек каталога авто
 * Description: Единый мастер для VIN-импорта, логов поиска, синхронизации, Google Indexing и криптоплатежей каталога авто.
 * Version: 1.0.43
 * Author: AskarTech
 */

defined('ABSPATH') || exit;

define('MAC_MASTER_ACTIVE', true);
define('MAC_PLUGIN_FILE', __FILE__);
define('MAC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('MAC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MAC_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('MAC_GITHUB_OWNER', 'calibvn');
define('MAC_GITHUB_REPO', 'master-auto-catalog');

require_once MAC_PLUGIN_PATH . 'includes/class-master-auto-catalog-admin.php';
require_once MAC_PLUGIN_PATH . 'includes/class-master-auto-catalog-updater.php';

function mac_load_modules()
{
    $modules = [
        'wp-search-logs/wp-search-logs.php',
        'site-protection/site-protection.php',
        'vin-fallback-search/vin-fallback-search.php',
        'google-auto-index/google-auto-index.php',
        'vin-centr_bd/wordpress-sync.php',
        'heleket-payment/heleket-payment.php',
        'cryptocloud-payment/cryptocloud-payment.php',
    ];

    foreach ($modules as $module) {
        $file = MAC_PLUGIN_PATH . 'modules/' . $module;
        if (file_exists($file)) {
            require_once $file;
        }
    }
}

add_action('plugins_loaded', 'mac_load_modules', 1);
add_action('plugins_loaded', 'mac_ensure_search_logs_schema', 2);
add_action('admin_menu', ['Master_Auto_Catalog_Admin', 'register_menu'], 20);
add_action('admin_enqueue_scripts', ['Master_Auto_Catalog_Admin', 'enqueue_admin_assets']);
add_action('init', ['Master_Auto_Catalog_Updater', 'init']);

register_activation_hook(__FILE__, 'mac_activate');
register_deactivation_hook(__FILE__, 'mac_deactivate');

function mac_activate()
{
    mac_create_search_logs_table();
    mac_create_sitemap_logs_table();
    mac_create_crawler_logs_table();
    mac_create_crawler_log_samples_table();
    mac_create_site_protection_tables();

    if (!function_exists('gai_activate')) {
        mac_load_modules();
    }

    if (function_exists('gai_activate')) {
        gai_activate();
    }

    if (isset($GLOBALS['mac_heleket_gateway']) && is_object($GLOBALS['mac_heleket_gateway'])) {
        $GLOBALS['mac_heleket_gateway']->activate_plugin();
    } elseif (class_exists('HeleketPaymentGateway')) {
        $gateway = new HeleketPaymentGateway();
        $gateway->activate_plugin();
    }

    if (isset($GLOBALS['mac_cryptocloud_gateway']) && is_object($GLOBALS['mac_cryptocloud_gateway'])) {
        $GLOBALS['mac_cryptocloud_gateway']->activate_plugin();
    } elseif (class_exists('CryptoCloudPaymentGateway')) {
        $gateway = new CryptoCloudPaymentGateway();
        $gateway->activate_plugin();
    }
}

function mac_deactivate()
{
    if (function_exists('gai_deactivate')) {
        gai_deactivate();
    }

    wp_clear_scheduled_hook('gai_batch_index');
    wp_clear_scheduled_hook('gai_cleanup_logs');
    wp_clear_scheduled_hook('heleket_check_pending_payments');
    wp_clear_scheduled_hook('cryptocloud_check_pending_payments');
    wp_clear_scheduled_hook('mac_search_logs_telegram_daily');
    wp_clear_scheduled_hook('mac_search_logs_telegram_weekly');
}

function mac_create_sitemap_logs_table()
{
    global $wpdb;

    $table = $wpdb->prefix . 'sitemap_logs';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        sitemap_path VARCHAR(255) NOT NULL,
        request_uri TEXT NULL,
        request_method VARCHAR(10) NOT NULL DEFAULT 'GET',
        response_code SMALLINT UNSIGNED NULL,
        ip_address VARCHAR(45) DEFAULT '',
        user_agent TEXT NULL,
        bot_name VARCHAR(100) DEFAULT '',
        referer TEXT NULL,
        user_id BIGINT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY created_at (created_at),
        KEY sitemap_path (sitemap_path),
        KEY bot_name (bot_name),
        KEY ip_address (ip_address)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function mac_create_crawler_logs_table()
{
    global $wpdb;

    $table = $wpdb->prefix . 'crawler_logs';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        log_date DATE NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        bot_name VARCHAR(100) DEFAULT '',
        user_agent TEXT NULL,
        request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        last_request_uri TEXT NULL,
        last_response_code SMALLINT UNSIGNED NULL,
        PRIMARY KEY (id),
        UNIQUE KEY daily_ip (log_date, ip_address),
        KEY log_date (log_date),
        KEY request_count (request_count)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function mac_create_crawler_log_samples_table()
{
    global $wpdb;

    $table = $wpdb->prefix . 'crawler_log_samples';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        log_date DATE NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        created_at DATETIME NOT NULL,
        request_uri TEXT NULL,
        response_code SMALLINT UNSIGNED NULL,
        referer TEXT NULL,
        PRIMARY KEY (id),
        KEY daily_ip (log_date, ip_address),
        KEY created_at (created_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function mac_create_search_logs_table()
{
    global $wpdb;

    $table = $wpdb->prefix . 'search_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        query VARCHAR(255) NOT NULL,
        session_id VARCHAR(64) DEFAULT '',
        ip_address VARCHAR(45) DEFAULT '',
        user_agent TEXT NULL,
        vin_result VARCHAR(32) DEFAULT '',
        PRIMARY KEY  (id),
        KEY created_at (created_at),
        KEY query (query),
        KEY session_id (session_id),
        KEY vin_result (vin_result)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Plugin updates do not run the activation hook.  Older installations can
 * therefore have a search_logs table created before session_id was added.
 */
function mac_ensure_search_logs_schema()
{
    global $wpdb;

    $table = $wpdb->prefix . 'search_logs';
    $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    if ($table_exists !== $table) {
        mac_create_search_logs_table();
    } else {
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $session_id_column = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'session_id'");

        if ($session_id_column === null) {
            $wpdb->query("ALTER TABLE {$table_sql} ADD COLUMN session_id VARCHAR(64) DEFAULT '' AFTER query");
        }

        $columns = [
            'ip_address' => "VARCHAR(45) DEFAULT '' AFTER session_id",
            'user_agent' => 'TEXT NULL AFTER ip_address',
            'vin_result' => "VARCHAR(32) DEFAULT '' AFTER user_agent",
        ];

        foreach ($columns as $column => $definition) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table_sql} LIKE %s", $column));
            if ($exists === null) {
                $wpdb->query("ALTER TABLE {$table_sql} ADD COLUMN {$column} {$definition}");
            }
        }

        $result_index = $wpdb->get_var("SHOW INDEX FROM {$table_sql} WHERE Key_name = 'vin_result'");
        if ($result_index === null) {
            $wpdb->query("ALTER TABLE {$table_sql} ADD KEY vin_result (vin_result)");
        }
    }

    mac_create_sitemap_logs_table();
    mac_create_crawler_logs_table();
    mac_create_crawler_log_samples_table();
    mac_create_site_protection_tables();
}

function mac_create_site_protection_tables()
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}site_protection_blocks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ip_address VARCHAR(45) NOT NULL,
        reason VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        blocked_hits BIGINT UNSIGNED NOT NULL DEFAULT 0, last_blocked_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY active_ip (ip_address, is_active, expires_at)
    ) $charset;");
    // dbDelta does not reliably add fields to this old table on every host.
    // Keep the per-block hit counter compatible with installations created
    // before the counter existed.
    $blocksTable = $wpdb->prefix . 'site_protection_blocks';
    $blockColumns = $wpdb->get_col("SHOW COLUMNS FROM {$blocksTable}", 0);
    if (!in_array('blocked_hits', $blockColumns, true)) {
        $wpdb->query("ALTER TABLE {$blocksTable} ADD COLUMN blocked_hits BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active");
    }
    if (!in_array('last_blocked_at', $blockColumns, true)) {
        $wpdb->query("ALTER TABLE {$blocksTable} ADD COLUMN last_blocked_at DATETIME NULL AFTER blocked_hits");
    }
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}site_protection_incidents (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ip_address VARCHAR(45) NOT NULL,
        rule_key VARCHAR(32) NOT NULL, incident_date DATE NOT NULL, created_at DATETIME NOT NULL,
        level TINYINT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (id),
        UNIQUE KEY daily_incident (ip_address, rule_key, incident_date),
        KEY ip_rule_date (ip_address, rule_key, incident_date)
    ) $charset;");
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}site_protection_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, created_at DATETIME NOT NULL,
        ip_address VARCHAR(45) NOT NULL, rule_key VARCHAR(32) NOT NULL,
        request_count INT UNSIGNED NOT NULL, threshold_count INT UNSIGNED NOT NULL,
        action_taken VARCHAR(16) NOT NULL, request_uri TEXT NULL, user_agent TEXT NULL,
        PRIMARY KEY (id), KEY created_at (created_at), KEY ip_rule (ip_address, rule_key)
    ) $charset;");
}
