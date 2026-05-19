<?php

defined('ABSPATH') || exit;

class Master_Auto_Catalog_Updater
{
    const OPTION_SECRET = 'mac_github_webhook_secret';
    const OPTION_LAST_AUTO_UPDATE = 'mac_github_last_auto_update';
    const OPTION_UPDATE_LOG = 'mac_github_update_log';
    const TRANSIENT_UPDATE_LOCK = 'mac_github_update_lock';
    const TRANSIENT_RELEASE = 'mac_github_latest_release';
    const TRANSIENT_TIMEOUT = 6 * HOUR_IN_SECONDS;

    public static function init()
    {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'fix_github_source_folder'], 10, 4);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_mac_save_update_settings', [__CLASS__, 'save_settings']);
        add_action('admin_post_mac_force_update_check', [__CLASS__, 'force_update_check']);
        add_action('admin_post_mac_install_update', [__CLASS__, 'install_update_from_admin']);
    }

    public static function current_version()
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data(MAC_PLUGIN_FILE, false, false);
        return isset($data['Version']) ? $data['Version'] : '0.0.0';
    }

    public static function webhook_url()
    {
        return rest_url('master-auto-catalog/v1/github-webhook');
    }

    public static function get_secret()
    {
        return (string) get_option(self::OPTION_SECRET, '');
    }

    public static function get_last_auto_update()
    {
        $data = get_option(self::OPTION_LAST_AUTO_UPDATE, []);
        return is_array($data) ? $data : [];
    }

    public static function get_update_log()
    {
        $log = get_option(self::OPTION_UPDATE_LOG, []);
        return is_array($log) ? $log : [];
    }

    public static function check_for_update($transient)
    {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        $release = self::get_latest_release(false);
        if (!$release || empty($release['version']) || empty($release['package'])) {
            return $transient;
        }

        if (!version_compare($release['version'], self::current_version(), '>')) {
            if (isset($transient->response[MAC_PLUGIN_BASENAME])) {
                unset($transient->response[MAC_PLUGIN_BASENAME]);
            }
            return $transient;
        }

        $transient->response[MAC_PLUGIN_BASENAME] = (object) [
            'id' => MAC_PLUGIN_BASENAME,
            'slug' => MAC_GITHUB_REPO,
            'plugin' => MAC_PLUGIN_BASENAME,
            'new_version' => $release['version'],
            'url' => self::repo_url(),
            'package' => $release['package'],
            'tested' => '',
            'requires_php' => '',
        ];

        return $transient;
    }

    public static function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== MAC_GITHUB_REPO) {
            return $result;
        }

        $release = self::get_latest_release(false);
        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'Master Auto Catalog',
            'slug' => MAC_GITHUB_REPO,
            'version' => $release['version'],
            'author' => '<a href="https://github.com/' . esc_attr(MAC_GITHUB_OWNER) . '">AskarTech</a>',
            'homepage' => self::repo_url(),
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Master plugin for catalog modules.',
                'changelog' => $release['body'] ?: 'See GitHub release notes.',
            ],
        ];
    }

    public static function fix_github_source_folder($source, $remote_source, $upgrader, $hook_extra = [])
    {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== MAC_PLUGIN_BASENAME) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem || basename(untrailingslashit($source)) === MAC_GITHUB_REPO) {
            return $source;
        }

        $target = trailingslashit($remote_source) . MAC_GITHUB_REPO . '/';
        if ($wp_filesystem->exists($target)) {
            $wp_filesystem->delete($target, true);
        }

        if ($wp_filesystem->move($source, $target, true)) {
            return $target;
        }

        return $source;
    }

    public static function register_routes()
    {
        register_rest_route('master-auto-catalog/v1', '/github-webhook', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_webhook(WP_REST_Request $request)
    {
        $secret = self::get_secret();
        if ($secret === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Webhook secret is not configured.'], 403);
        }

        $body = $request->get_body();
        $signature = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? (string) $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

        if (!hash_equals($expected, $signature)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid signature.'], 403);
        }

        $event = isset($_SERVER['HTTP_X_GITHUB_EVENT']) ? sanitize_key($_SERVER['HTTP_X_GITHUB_EVENT']) : '';
        $payload = json_decode($body, true);
        $action = is_array($payload) && isset($payload['action']) ? sanitize_key($payload['action']) : '';

        $auto_update = null;
        if ($event === 'release' && in_array($action, ['published', 'released'], true)) {
            self::log('Webhook accepted.', ['event' => $event, 'action' => $action]);
            self::clear_cache();
            $release = self::get_latest_release(true);
            $auto_update = self::install_available_update($release, 'github_webhook');
        }

        return new WP_REST_Response(['ok' => true, 'auto_update' => $auto_update], 200);
    }

    public static function save_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.');
        }

        check_admin_referer('mac_save_update_settings');
        $secret = isset($_POST['mac_github_webhook_secret']) ? sanitize_text_field(wp_unslash($_POST['mac_github_webhook_secret'])) : '';
        update_option(self::OPTION_SECRET, $secret, false);

        wp_safe_redirect(add_query_arg(['page' => 'mac-updates', 'mac_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public static function force_update_check()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.');
        }

        check_admin_referer('mac_force_update_check');
        self::clear_cache();
        self::get_latest_release(true);
        wp_clean_update_cache();

        wp_safe_redirect(add_query_arg(['page' => 'mac-updates', 'mac_checked' => '1'], admin_url('admin.php')));
        exit;
    }

    public static function install_update_from_admin()
    {
        if (!current_user_can('update_plugins')) {
            wp_die('Access denied.');
        }

        check_admin_referer('mac_install_update');
        self::clear_cache();
        $release = self::get_latest_release(true);
        self::install_available_update($release, 'admin_button');
        wp_clean_update_cache();

        wp_safe_redirect(add_query_arg(['page' => 'mac-updates', 'mac_installed' => '1'], admin_url('admin.php')));
        exit;
    }

    public static function install_available_update($release = null, $source = 'manual')
    {
        if (get_site_transient(self::TRANSIENT_UPDATE_LOCK)) {
            return self::record_auto_update('skipped', 'Another update is already running.', $source, '', false);
        }

        set_site_transient(self::TRANSIENT_UPDATE_LOCK, 1, 10 * MINUTE_IN_SECONDS);

        if (!$release) {
            $release = self::get_latest_release(true);
        }

        if (!$release || empty($release['version']) || empty($release['package'])) {
            delete_site_transient(self::TRANSIENT_UPDATE_LOCK);
            return self::record_auto_update('error', 'No GitHub release package was found.', $source, '');
        }

        $target_version = (string) $release['version'];
        $current_version = self::current_version();
        if (!version_compare($target_version, $current_version, '>')) {
            delete_site_transient(self::TRANSIENT_UPDATE_LOCK);
            return self::record_auto_update('skipped', 'No newer version is available.', $source, $target_version, false);
        }

        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!function_exists('wp_update_plugins')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }

        $was_active = is_plugin_active(MAC_PLUGIN_BASENAME);
        self::log('Starting plugin update.', [
            'source' => $source,
            'from' => $current_version,
            'to' => $target_version,
            'was_active' => $was_active ? 'yes' : 'no',
        ]);

        self::clear_cache();
        wp_update_plugins();

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->upgrade(MAC_PLUGIN_BASENAME);
        $version_after = self::current_version();
        $messages = !empty($skin->messages) && is_array($skin->messages) ? implode(' ', array_map('wp_strip_all_tags', $skin->messages)) : '';

        if ($was_active && !is_plugin_active(MAC_PLUGIN_BASENAME)) {
            self::restore_active_plugin_flag();
            self::log('Plugin active flag restored after update.');
        }

        if (is_wp_error($result)) {
            delete_site_transient(self::TRANSIENT_UPDATE_LOCK);
            return self::record_auto_update('error', $result->get_error_message(), $source, $target_version);
        }

        if (version_compare($version_after, $target_version, '>=')) {
            self::clear_cache();
            delete_site_transient(self::TRANSIENT_UPDATE_LOCK);
            return self::record_auto_update('success', 'Plugin updated successfully. ' . $messages, $source, $target_version);
        }

        delete_site_transient(self::TRANSIENT_UPDATE_LOCK);
        return self::record_auto_update('error', $messages ?: 'WordPress upgrader returned no result and plugin version did not change.', $source, $target_version);
    }

    public static function get_latest_release($force)
    {
        if (!$force) {
            $cached = get_site_transient(self::TRANSIENT_RELEASE);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = 'https://api.github.com/repos/' . rawurlencode(MAC_GITHUB_OWNER) . '/' . rawurlencode(MAC_GITHUB_REPO) . '/releases/latest';
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => MAC_GITHUB_REPO . '-wordpress-updater',
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name'])) {
            return false;
        }

        $tag = ltrim((string) $data['tag_name'], 'vV');
        $release = [
            'version' => $tag,
            'tag' => (string) $data['tag_name'],
            'name' => isset($data['name']) ? (string) $data['name'] : (string) $data['tag_name'],
            'body' => isset($data['body']) ? (string) $data['body'] : '',
            'published_at' => isset($data['published_at']) ? (string) $data['published_at'] : '',
            'package' => self::package_url((string) $data['tag_name']),
            'html_url' => isset($data['html_url']) ? (string) $data['html_url'] : self::repo_url(),
        ];

        set_site_transient(self::TRANSIENT_RELEASE, $release, self::TRANSIENT_TIMEOUT);
        return $release;
    }

    public static function clear_cache()
    {
        delete_site_transient(self::TRANSIENT_RELEASE);
        delete_site_transient('update_plugins');
    }

    public static function repo_url()
    {
        return 'https://github.com/' . MAC_GITHUB_OWNER . '/' . MAC_GITHUB_REPO;
    }

    private static function package_url($tag)
    {
        return self::repo_url() . '/archive/refs/tags/' . rawurlencode($tag) . '.zip';
    }

    private static function record_auto_update($status, $message, $source, $version, $store_as_last = true)
    {
        $data = [
            'status' => $status,
            'message' => $message,
            'source' => $source,
            'version' => $version,
            'time' => current_time('mysql'),
        ];

        if ($store_as_last) {
            update_option(self::OPTION_LAST_AUTO_UPDATE, $data, false);
        }
        self::log('Update finished.', $data);
        return $data;
    }

    private static function restore_active_plugin_flag()
    {
        if (is_multisite() && is_plugin_active_for_network(MAC_PLUGIN_BASENAME)) {
            $active_sitewide = get_site_option('active_sitewide_plugins', []);
            if (!is_array($active_sitewide)) {
                $active_sitewide = [];
            }
            $active_sitewide[MAC_PLUGIN_BASENAME] = time();
            update_site_option('active_sitewide_plugins', $active_sitewide);
            return;
        }

        $active_plugins = get_option('active_plugins', []);
        if (!is_array($active_plugins)) {
            $active_plugins = [];
        }

        if (!in_array(MAC_PLUGIN_BASENAME, $active_plugins, true)) {
            $active_plugins[] = MAC_PLUGIN_BASENAME;
            sort($active_plugins);
            update_option('active_plugins', $active_plugins);
        }
    }

    private static function log($message, array $context = [])
    {
        $log = self::get_update_log();
        array_unshift($log, [
            'time' => current_time('mysql'),
            'message' => $message,
            'context' => $context,
        ]);

        $log = array_slice($log, 0, 30);
        update_option(self::OPTION_UPDATE_LOG, $log, false);
    }
}
