<?php
/**
 * Plugin Name: Мастер настроек каталога авто
 * Description: Единый мастер для VIN-импорта, логов поиска, синхронизации, Google Indexing и криптоплатежей каталога авто.
 * Version: 1.0.21
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
        'telegram-search-reports/telegram-search-reports.php',
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
    wp_clear_scheduled_hook('mac_telegram_search_reports_daily');
    wp_clear_scheduled_hook('mac_telegram_search_reports_weekly');
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
        PRIMARY KEY  (id),
        KEY created_at (created_at),
        KEY query (query),
        KEY session_id (session_id)
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
        return;
    }

    $table_sql = '`' . str_replace('`', '``', $table) . '`';
    $session_id_column = $wpdb->get_var("SHOW COLUMNS FROM {$table_sql} LIKE 'session_id'");

    if ($session_id_column === null) {
        $wpdb->query("ALTER TABLE {$table_sql} ADD COLUMN session_id VARCHAR(64) DEFAULT '' AFTER query");
    }
}
