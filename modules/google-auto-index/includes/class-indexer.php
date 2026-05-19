<?php
class GAI_Indexer {
    private $settings;
    private $daily_limit = 100;
    private $processing_posts = [];

    public function __construct() {
        $this->settings = function_exists('gai_get_settings') ? gai_get_settings() : get_option(GAI_SETTINGS_OPTION, []);
        $this->daily_limit = (int) apply_filters('gai_daily_limit', 100);

        add_action('transition_post_status', [$this, 'on_status_transition'], 10, 3);
        add_action('wp_trash_post', [$this, 'on_post_trashed'], 10, 1);
        add_action('gai_delayed_index_action', [$this, 'execute_delayed_index'], 10, 2);

        if (!empty($this->settings['enable_cron'])) {
            add_action('gai_batch_index', [$this, 'batch_index']);
        }

        add_action('wp_ajax_gai_index_post', [$this, 'ajax_index_post']);
        add_action('wp_ajax_gai_get_stats', [$this, 'ajax_get_stats']);
    }

    public function on_status_transition($new_status, $old_status, $post) {
        if (empty($post) || $post->post_type !== 'product') {
            return;
        }

        if ($new_status === 'publish' && $old_status !== 'publish') {
            $published_url = $this->get_product_permalink($post->ID);
            if ($published_url) {
                update_post_meta($post->ID, '_gai_last_published_url', $published_url);
            }

            if (!empty($this->settings['auto_index_new'])) {
                $this->schedule_delayed_index($post->ID, 'URL_UPDATED');
            }

            return;
        }

        if ($old_status === 'publish' && $new_status === 'draft' && !empty($this->settings['auto_delete_draft'])) {
            if ($this->is_post_processing($post->ID, 'draft')) {
                return;
            }

            $this->set_post_processing($post->ID, 'draft');
            $original_url = $this->get_last_published_url($post->ID);
            if ($original_url) {
                $this->direct_index($post->ID, 'URL_DELETED', $original_url);
            }
            $this->clear_post_processing($post->ID, 'draft');
        }
    }

    public function on_post_trashed($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'product') {
            return;
        }

        if ($this->is_post_processing($post_id, 'trash')) {
            return;
        }

        $this->set_post_processing($post_id, 'trash');

        if (!empty($this->settings['auto_delete_draft'])) {
            $original_url = $this->get_last_published_url($post_id);
            if ($original_url) {
                $this->direct_index($post_id, 'URL_DELETED', $original_url);
            }
        }

        $this->clear_post_processing($post_id, 'trash');
    }

    private function schedule_delayed_index($post_id, $action) {
        $delay = max(0, (int) ($this->settings['indexing_delay'] ?? 5));
        $args = [(int) $post_id, (string) $action];

        if (wp_next_scheduled('gai_delayed_index_action', $args)) {
            return;
        }

        wp_schedule_single_event(time() + $delay, 'gai_delayed_index_action', $args);
    }

    public function execute_delayed_index($post_id, $action) {
        $allowed_actions = ['URL_UPDATED', 'URL_DELETED'];
        if (!in_array($action, $allowed_actions, true)) {
            return;
        }

        if ($this->is_post_processing($post_id, 'delayed_' . $action)) {
            return;
        }

        $this->set_post_processing($post_id, 'delayed_' . $action);

        $stats = $this->get_limit_stats();
        if ($stats['remaining'] <= 0) {
            $this->log($post_id, 'skipped', 'Daily limit reached: ' . $stats['used'] . '/' . $this->daily_limit, $action);
            $this->clear_post_processing($post_id, 'delayed_' . $action);
            return;
        }

        $url = $action === 'URL_DELETED' ? $this->get_last_published_url($post_id) : $this->get_product_permalink($post_id);
        if ($url) {
            $this->send_to_google($post_id, $action, $url);
        }

        $this->clear_post_processing($post_id, 'delayed_' . $action);
    }

    private function get_product_permalink($post_id) {
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($post_id);
            if ($product) {
                return $this->normalize_url($product->get_permalink());
            }
        }

        $url = get_permalink($post_id);
        if ($url && !is_wp_error($url)) {
            return $this->normalize_url($url);
        }

        $post = get_post($post_id);
        if ($post && !empty($post->post_name)) {
            return home_url('/product/' . $post->post_name . '/');
        }

        return false;
    }

    private function normalize_url($url) {
        $url = (string) $url;
        $url = preg_replace('/\?.*$/', '', $url);
        $url = preg_replace('/__trashed\/?$/', '', $url);

        return rtrim($url, '/') . '/';
    }

    private function direct_index($post_id, $action, $custom_url = null) {
        if ($this->is_post_processing($post_id, 'direct_' . $action)) {
            return;
        }

        $this->set_post_processing($post_id, 'direct_' . $action);

        $stats = $this->get_limit_stats();
        if ($stats['remaining'] <= 0) {
            $this->log($post_id, 'skipped', 'Daily limit reached: ' . $stats['used'] . '/' . $this->daily_limit, $action);
            $this->clear_post_processing($post_id, 'direct_' . $action);
            return;
        }

        $url = $custom_url ?: $this->get_product_permalink($post_id);
        if ($url) {
            $this->send_to_google($post_id, $action, $url);
        }

        $this->clear_post_processing($post_id, 'direct_' . $action);
    }

    private function send_to_google($post_id, $action, $url) {
        $allowed_actions = ['URL_UPDATED', 'URL_DELETED'];
        if (!in_array($action, $allowed_actions, true)) {
            $this->log($post_id, 'error', 'Unsupported action', $action, $url);
            return false;
        }

        $start_time = microtime(true);

        if ($action === 'URL_UPDATED') {
            $status = get_post_status($post_id);
            if ($status !== 'publish') {
                $this->log($post_id, 'skipped', 'Post is not published', $action, $url);
                return false;
            }
        }

        $access_token = GAI_Google_Auth::get_access_token();
        if (!$access_token) {
            $this->log($post_id, 'error', 'Missing access token', $action, $url);
            return false;
        }

        $response = wp_remote_post('https://indexing.googleapis.com/v3/urlNotifications:publish', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode(['url' => $url, 'type' => $action]),
            'timeout' => 10,
        ]);

        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        if (is_wp_error($response)) {
            $this->log($post_id, 'error', 'HTTP error: ' . $response->get_error_message(), $action, $url, null, $execution_time);
            return false;
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            $response_data = $this->parse_google_response($response_body);
            $this->log($post_id, 'success', 'Sent to Google (' . $execution_time . 'ms)', $action, $url, $response_data, $execution_time);

            if ($action === 'URL_UPDATED') {
                update_post_meta($post_id, '_gai_indexed', current_time('mysql'));
                update_post_meta($post_id, '_gai_last_action', 'indexed');
                update_post_meta($post_id, '_gai_last_published_url', $url);
            } else {
                update_post_meta($post_id, '_gai_last_action', 'deleted');
                update_post_meta($post_id, '_gai_deleted_at', current_time('mysql'));
            }

            return true;
        }

        $this->log($post_id, 'error', 'API error: ' . $response_code, $action, $url, $response_body, $execution_time);
        return false;
    }

    private function parse_google_response($response_body) {
        $data = json_decode($response_body, true);

        if (json_last_error() === JSON_ERROR_NONE && $data !== null) {
            return $data;
        }

        return [
            'raw_response' => $response_body,
            'note' => 'Response is not valid JSON',
        ];
    }

    private function log($post_id, $status, $message, $action, $url = '', $response = null, $execution_time = 0) {
        if (empty($this->settings['log_requests'])) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'gai_logs';

        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name
             WHERE post_id = %d AND action = %s
             AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
            $post_id,
            $action
        ));

        if ((int) $recent > 0) {
            return;
        }

        $response_formatted = '';
        if ($response !== null) {
            $response_formatted = is_array($response) || is_object($response)
                ? wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : (string) $response;
        }

        $wpdb->insert($table_name, [
            'post_id' => (int) $post_id,
            'action' => (string) $action,
            'url' => substr((string) ($url ?: ''), 0, 500),
            'status' => (string) $status,
            'message' => (string) $message,
            'response' => $response_formatted,
            'created_at' => current_time('mysql'),
        ]);
    }

    private function get_permalink_before_trash($post_id) {
        global $wpdb;

        $cached = wp_cache_get('gai_url_' . $post_id, 'gai');
        if ($cached) {
            return $cached;
        }

        $post_name = $wpdb->get_var($wpdb->prepare(
            "SELECT post_name FROM {$wpdb->posts} WHERE ID = %d",
            $post_id
        ));

        if ($post_name) {
            $url = home_url('/product/' . $post_name . '/');
            wp_cache_set('gai_url_' . $post_id, $url, 'gai', HOUR_IN_SECONDS);
            return $url;
        }

        $url = get_permalink($post_id);
        if ($url && !is_wp_error($url)) {
            $url = $this->normalize_url($url);
            wp_cache_set('gai_url_' . $post_id, $url, 'gai', HOUR_IN_SECONDS);
            return $url;
        }

        return false;
    }

    private function get_last_published_url($post_id) {
        $stored_url = get_post_meta($post_id, '_gai_last_published_url', true);
        if (!empty($stored_url)) {
            return $stored_url;
        }

        return $this->get_permalink_before_trash($post_id);
    }

    private function check_daily_limit() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gai_logs';

        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             AND status = 'success'"
        );

        return (int) $count;
    }

    public function get_limit_stats() {
        $used = $this->check_daily_limit();
        $remaining = max(0, $this->daily_limit - $used);
        $percentage = $this->daily_limit > 0 ? round(($used / $this->daily_limit) * 100, 1) : 0;

        return [
            'used' => $used,
            'remaining' => $remaining,
            'limit' => $this->daily_limit,
            'percentage' => $percentage,
            'status' => $remaining > 0 ? 'available' : 'exceeded',
        ];
    }

    private function is_post_processing($post_id, $action) {
        $key = $post_id . '_' . $action;
        return isset($this->processing_posts[$key]) && (time() - $this->processing_posts[$key]) < 30;
    }

    private function set_post_processing($post_id, $action) {
        $this->processing_posts[$post_id . '_' . $action] = time();
    }

    private function clear_post_processing($post_id, $action) {
        unset($this->processing_posts[$post_id . '_' . $action]);
    }

    public function batch_index() {
        $stats = $this->get_limit_stats();
        if ($stats['remaining'] <= 0) {
            return;
        }

        $limit = min(10, $stats['remaining']);
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => [[
                'key' => '_gai_indexed',
                'compare' => 'NOT EXISTS',
            ]],
            'fields' => 'ids',
        ];

        $post_ids = get_posts($args);
        foreach ($post_ids as $post_id) {
            if ($this->get_limit_stats()['remaining'] <= 0) {
                break;
            }

            $url = $this->get_product_permalink($post_id);
            if ($url) {
                $this->send_to_google($post_id, 'URL_UPDATED', $url);
                usleep(500000);
            }
        }
    }

    public function ajax_index_post() {
        check_ajax_referer('gai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json(['success' => false, 'message' => 'Insufficient permissions']);
            return;
        }

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'URL_UPDATED';

        if (!in_array($type, ['URL_UPDATED', 'URL_DELETED'], true)) {
            wp_send_json(['success' => false, 'message' => 'Invalid action type']);
            return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'product') {
            wp_send_json(['success' => false, 'message' => 'Product not found']);
            return;
        }

        $stats = $this->get_limit_stats();
        if ($stats['remaining'] <= 0) {
            wp_send_json([
                'success' => false,
                'message' => 'Daily limit reached: ' . $stats['used'] . '/' . $this->daily_limit,
                'stats' => $stats,
            ]);
            return;
        }

        $url = $type === 'URL_DELETED' ? $this->get_last_published_url($post_id) : $this->get_product_permalink($post_id);
        if (!$url) {
            wp_send_json(['success' => false, 'message' => 'Could not resolve URL']);
            return;
        }

        $result = $this->send_to_google($post_id, $type, $url);

        wp_send_json([
            'success' => (bool) $result,
            'message' => $result ? 'Sent to Google successfully' : 'Failed to send request',
            'stats' => $this->get_limit_stats(),
        ]);
    }

    public function ajax_get_stats() {
        check_ajax_referer('gai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json(['success' => false, 'message' => 'Insufficient permissions']);
            return;
        }

        wp_send_json([
            'success' => true,
            'stats' => $this->get_limit_stats(),
            'timestamp' => current_time('mysql'),
        ]);
    }

    public static function get_daily_stats() {
        $daily_limit = (int) apply_filters('gai_daily_limit', 100);
        global $wpdb;
        $table_name = $wpdb->prefix . 'gai_logs';

        $used = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             AND status = 'success'"
        );

        $remaining = max(0, $daily_limit - $used);
        $percentage = $daily_limit > 0 ? round(($used / $daily_limit) * 100, 1) : 0;

        return [
            'used' => $used,
            'remaining' => $remaining,
            'limit' => $daily_limit,
            'percentage' => $percentage,
            'status' => $remaining > 0 ? 'available' : 'exceeded',
        ];
    }
}
