<?php
/**
 * Module Name: AskarTech | Google индексация товаров
 * Description: Automatic indexing of vehicle pages in Google Indexing API.
 * Version: 1.1.0
 * Author: AskarTech
 */

defined('ABSPATH') || exit;

define('GAI_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('GAI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GAI_TOKEN_OPTION', 'gai_google_token');
define('GAI_SETTINGS_OPTION', 'gai_settings');
define('GAI_LOG_RETENTION_DAYS', 60);

function gai_autoload_classes($class_name) {
    $class_mapping = [
        'GAI_Indexer' => 'class-indexer.php',
        'GAI_Google_Auth' => 'class-google-auth.php',
        'GAI_Admin' => 'class-admin.php',
    ];

    if (!isset($class_mapping[$class_name])) {
        return;
    }

    $file = GAI_PLUGIN_PATH . 'includes/' . $class_mapping[$class_name];
    if (file_exists($file)) {
        require_once $file;
    }
}

spl_autoload_register('gai_autoload_classes');

add_action('plugins_loaded', 'gai_init_plugin');

function gai_default_settings() {
    return [
        'client_id' => '',
        'client_secret' => '',
        'auto_index_new' => true,
        'auto_delete_draft' => true,
        'indexing_delay' => 5,
        'log_requests' => true,
        'enable_cron' => true,
    ];
}

function gai_get_settings() {
    $settings = get_option(GAI_SETTINGS_OPTION, []);
    if (!is_array($settings)) {
        $settings = [];
    }

    return array_merge(gai_default_settings(), $settings);
}

function gai_validate_required_files() {
    $required = [
        'includes/class-indexer.php',
        'includes/class-google-auth.php',
        'includes/class-admin.php',
    ];

    $missing = [];
    foreach ($required as $file) {
        if (!file_exists(GAI_PLUGIN_PATH . $file)) {
            $missing[] = $file;
        }
    }

    if (empty($missing)) {
        return true;
    }

    if (is_admin()) {
        add_action('admin_notices', function() use ($missing) {
            echo '<div class="notice notice-error"><p>';
            echo 'Google Auto Index: missing required files: ' . esc_html(implode(', ', $missing));
            echo '</p></div>';
        });
    }

    return false;
}

function gai_init_plugin() {
    if (!gai_validate_required_files()) {
        return;
    }

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p>Google Auto Index: WooCommerce not found. Plugin will still work for product post type.</p></div>';
        });
    }

    if (is_admin()) {
        $GLOBALS['mac_google_admin'] = new GAI_Admin();
    }

    new GAI_Indexer();
    GAI_Google_Auth::init();
    gai_ensure_schedules();
}

function gai_activate() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gai_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        action varchar(50) NOT NULL,
        url varchar(500) NOT NULL,
        status varchar(50) NOT NULL,
        message text,
        response text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY post_id (post_id),
        KEY status (status),
        KEY created_at (created_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    if (!get_option(GAI_SETTINGS_OPTION)) {
        add_option(GAI_SETTINGS_OPTION, gai_default_settings());
    }

    gai_ensure_schedules();
}

function gai_deactivate() {
    wp_clear_scheduled_hook('gai_batch_index');
    wp_clear_scheduled_hook('gai_cleanup_logs');
}

function gai_ensure_schedules() {
    $settings = gai_get_settings();

    if (!empty($settings['enable_cron'])) {
        if (!wp_next_scheduled('gai_batch_index')) {
            wp_schedule_event(time(), 'hourly', 'gai_batch_index');
        }
    } else {
        wp_clear_scheduled_hook('gai_batch_index');
    }

    if (!wp_next_scheduled('gai_cleanup_logs')) {
        wp_schedule_event(time(), 'daily', 'gai_cleanup_logs');
    }
}

function gai_cleanup_old_logs() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gai_logs';
    $cutoff = gmdate('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * GAI_LOG_RETENTION_DAYS));

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $cutoff
        )
    );
}
add_action('gai_cleanup_logs', 'gai_cleanup_old_logs');
