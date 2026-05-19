<?php
class GAI_Google_Auth {
    private static $client_id = '';
    private static $client_secret = '';

    public static function init() {
        $settings = function_exists('gai_get_settings') ? gai_get_settings() : get_option(GAI_SETTINGS_OPTION, []);
        self::$client_id = $settings['client_id'] ?? '';
        self::$client_secret = $settings['client_secret'] ?? '';
    }

    public static function get_auth_url() {
        if (empty(self::$client_id) || empty(self::$client_secret)) {
            return '';
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return '';
        }

        $state = wp_generate_password(32, false, false);
        set_transient('gai_oauth_state_' . $user_id, $state, 15 * MINUTE_IN_SECONDS);

        $redirect_uri = admin_url('admin-ajax.php?action=gai_oauth_callback');
        $params = [
            'client_id' => self::$client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params);
    }

    public static function validate_oauth_state($state, $user_id = null) {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id || empty($state)) {
            return false;
        }

        $key = 'gai_oauth_state_' . (int) $user_id;
        $stored = get_transient($key);
        if (empty($stored) || !hash_equals($stored, (string) $state)) {
            return false;
        }

        delete_transient($key);
        return true;
    }

    public static function get_token($code) {
        if (empty($code) || empty(self::$client_id) || empty(self::$client_secret)) {
            return false;
        }

        $redirect_uri = admin_url('admin-ajax.php?action=gai_oauth_callback');

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code' => $code,
                'client_id' => self::$client_id,
                'client_secret' => self::$client_secret,
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[GAI Auth] Error getting token: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token']) || empty($body['expires_in'])) {
            error_log('[GAI Auth] Token response error: ' . wp_json_encode($body));
            return false;
        }

        $body['expires_at'] = time() + (int) $body['expires_in'];
        update_option(GAI_TOKEN_OPTION, $body, false);

        return true;
    }

    public static function refresh_token() {
        $token = get_option(GAI_TOKEN_OPTION);

        if (empty($token['refresh_token']) || empty(self::$client_id) || empty(self::$client_secret)) {
            return false;
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id' => self::$client_id,
                'client_secret' => self::$client_secret,
                'refresh_token' => $token['refresh_token'],
                'grant_type' => 'refresh_token',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[GAI Auth] Refresh error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token']) || empty($body['expires_in'])) {
            error_log('[GAI Auth] Refresh response error: ' . wp_json_encode($body));
            return false;
        }

        $new_token = array_merge($token, $body);
        $new_token['expires_at'] = time() + (int) $new_token['expires_in'];
        update_option(GAI_TOKEN_OPTION, $new_token, false);

        return $new_token['access_token'];
    }

    public static function get_access_token() {
        $token = get_option(GAI_TOKEN_OPTION);
        if (empty($token['access_token']) || empty($token['expires_at'])) {
            return false;
        }

        if (time() > ((int) $token['expires_at'] - 300)) {
            return self::refresh_token();
        }

        return $token['access_token'];
    }

    public static function is_authenticated() {
        return !empty(self::get_access_token());
    }
}
