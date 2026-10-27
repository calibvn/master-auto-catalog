<?php

if (!defined('ABSPATH')) exit;

const MAC_SITE_PROTECTION_OPTION = 'mac_site_protection_settings';
const MAC_SITE_PROTECTION_STATE_VERSION_OPTION = 'mac_site_protection_state_version';

function mac_site_protection_state_version() {
    return max(1, (int) get_option(MAC_SITE_PROTECTION_STATE_VERSION_OPTION, 1));
}

/**
 * The central agent forwards short-lived local observations to the selected
 * centre and applies only decisions explicitly queued by an administrator.
 */
const MAC_SITE_PROTECTION_CENTRAL_AGENT_VERSION = '2.0.0';

// Lowest values accepted from the centre. They only prevent a typo from
// limiting every visitor; the real limits are chosen in the centre.
const MAC_SP_MIN_CARD_HOUR = 10;
const MAC_SP_MIN_CARD_DAILY = 30;
const MAC_SP_MIN_BLOCK_HOUR = 20;
const MAC_SP_MIN_RHYTHM_SAMPLES = 8;

// Sitemaps and feeds are served only to verified search engines.
const MAC_SP_SITEMAP_BLOCK_MINUTES = 30 * 24 * 60;
const MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK = 'mac_site_protection_central_sync';

// Reporting thresholds shared by the agent and local crawler log writer.
const MAC_CRAWLER_LOGS_DAILY_THRESHOLD = 100;
const MAC_CRAWLER_LOG_SAMPLES_LIMIT = 15;

add_filter('cron_schedules', function ($schedules) {
    $schedules['mac_five_minutes'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every five minutes'];
    $schedules['mac_one_minute'] = ['interval' => MINUTE_IN_SECONDS, 'display' => 'Every minute'];
    return $schedules;
});

add_action('init', function () {
    $event = wp_get_scheduled_event(MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK);
    if ($event && $event->schedule !== 'mac_one_minute') wp_clear_scheduled_hook(MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK);
    if (!wp_next_scheduled(MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK)) {
        wp_schedule_event(time() + 60, 'mac_one_minute', MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK);
    }
    if (get_option('mac_site_protection_rate_buckets_v1') !== '1') {
        global $wpdb;
        $table = $wpdb->prefix . 'site_protection_rate_buckets';
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (subject VARCHAR(80) NOT NULL, rule_key VARCHAR(32) NOT NULL, bucket_start DATETIME NOT NULL, hits INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (subject,rule_key,bucket_start), KEY bucket_start (bucket_start)) {$charset}");
        if (!$wpdb->last_error) update_option('mac_site_protection_rate_buckets_v1', '1', false);
    }
    if (get_option('mac_site_protection_incidents_schema_v2') !== '1') {
        global $wpdb;
        $table = $wpdb->prefix . 'site_protection_incidents';
        $index = $wpdb->get_row("SHOW INDEX FROM {$table} WHERE Key_name='daily_incident'", ARRAY_A);
        if ($index && (int) ($index['Non_unique'] ?? 1) === 0) $wpdb->query("ALTER TABLE {$table} DROP INDEX daily_incident, ADD INDEX daily_incident (ip_address, rule_key, incident_date)");
        if (!$wpdb->last_error) update_option('mac_site_protection_incidents_schema_v2', '1', false);
    }
    if (get_option('mac_site_protection_rhythm_v1') !== '1') {
        global $wpdb;
        $table = $wpdb->prefix . 'site_protection_rhythm';
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (subject VARCHAR(80) NOT NULL, stamps TEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (subject), KEY updated_at (updated_at)) {$charset}");
        if (!$wpdb->last_error) update_option('mac_site_protection_rhythm_v1', '1', false);
    }
});

function mac_site_protection_central_config() {
    return [
        'url' => rtrim(trim((string) get_option('cas_central_url', '')), '/'),
        'api_key' => trim((string) get_option('cas_api_key', '')),
    ];
}

/**
 * Receives only technical VIN-provider health signals. It never stores a VIN,
 * API key, password or complete remote response on the executor.
 */
function mac_site_protection_record_vin_api_health($provider_key, $provider_label, $state, $details = []) {
    $provider_key = sanitize_key((string) $provider_key);
    $provider_label = substr(sanitize_text_field((string) $provider_label), 0, 80);
    $state = $state === 'up' ? 'up' : 'down';
    if ($provider_key === '' || $provider_label === '') return;

    $details = is_array($details) ? $details : [];
    $http_code = max(0, (int) ($details['http_code'] ?? 0));
    $elapsed_ms = max(0, (int) ($details['elapsed_ms'] ?? 0));
    $failure_type = substr(sanitize_key((string) ($details['failure_type'] ?? '')), 0, 64);
    $detail = substr(sanitize_text_field((string) ($details['detail'] ?? '')), 0, 180);
    $signature = implode('|', [$failure_type, $http_code, $detail]);
    $states = (array) get_option('mac_vin_provider_health_state', []);
    $previous = is_array($states[$provider_key] ?? null) ? $states[$provider_key] : [];
    $should_queue = false;

    if ($state === 'down') {
        $should_queue = ($previous['state'] ?? 'unknown') !== 'down' || ($previous['signature'] ?? '') !== $signature;
        $states[$provider_key] = [
            'state' => 'down', 'signature' => $signature, 'provider_label' => $provider_label,
            'http_code' => $http_code, 'failure_type' => $failure_type, 'detail' => $detail,
            'elapsed_ms' => $elapsed_ms, 'updated_at' => current_time('mysql'),
        ];
    } else {
        $should_queue = ($previous['state'] ?? 'unknown') === 'down';
        $states[$provider_key] = [
            'state' => 'up', 'signature' => '', 'provider_label' => $provider_label,
            'http_code' => $http_code, 'failure_type' => '', 'detail' => '',
            'elapsed_ms' => $elapsed_ms, 'updated_at' => current_time('mysql'),
        ];
    }
    update_option('mac_vin_provider_health_state', $states, false);
    if (!$should_queue) return;

    $queue = (array) get_option('mac_vin_provider_health_queue', []);
    $queue[] = [
        'source_key' => 'vin-api-' . wp_generate_uuid4(),
        'occurred_at' => current_time('mysql'),
        'provider_key' => $provider_key,
        'provider_label' => $provider_label,
        'state' => $state,
        'failure_type' => $failure_type,
        'http_code' => $http_code,
        'elapsed_ms' => $elapsed_ms,
        'detail' => $detail,
    ];
    update_option('mac_vin_provider_health_queue', array_slice($queue, -100), false);

    // Do not make a central HTTP call during a visitor request. Ask WP-Cron to
    // deliver this state transition shortly; the normal five-minute sync is a fallback.
    if (!get_transient('mac_vin_provider_health_sync_pending')) {
        set_transient('mac_vin_provider_health_sync_pending', '1', 2 * MINUTE_IN_SECONDS);
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK);
    }
}
add_action('mac_vin_provider_health', 'mac_site_protection_record_vin_api_health', 10, 4);

function mac_site_protection_central_row_subject($ip, $ua = '') {
    return mac_site_protection_subject((string) $ip, mac_site_protection_traffic_class((string) $ua, (string) $ip));
}

function mac_site_protection_central_apply_command(array $command) {
    global $wpdb;
    $subject = trim((string) ($command['subject'] ?? ''));
    $type = (string) ($command['command_type'] ?? '');
    if ($subject === '' || !in_array($type, ['block', 'whitelist', 'unblock', 'unwhitelist'], true)) {
        return [false, 'Некорректная команда'];
    }

    $settings = mac_site_protection_settings();
    $ipItems = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $settings['ip_whitelist']))));
    if ($type === 'whitelist') {
        if (!in_array($subject, $ipItems, true)) $ipItems[] = $subject;
        $settings['ip_whitelist'] = implode("\n", $ipItems);
        update_option(MAC_SITE_PROTECTION_OPTION, $settings, false);
        $wpdb->update($wpdb->prefix . 'site_protection_blocks', ['is_active' => 0], ['ip_address' => $subject], ['%d'], ['%s']);
        delete_transient('mac_sp_block_ranges');
        $wpdb->delete($wpdb->prefix . 'site_protection_incidents', ['ip_address' => $subject], ['%s']);
        return [true, 'Добавлен в WhiteList'];
    }
    if ($type === 'unwhitelist') {
        $settings['ip_whitelist'] = implode("\n", array_values(array_filter($ipItems, static function ($item) use ($subject) { return $item !== $subject; })));
        update_option(MAC_SITE_PROTECTION_OPTION, $settings, false);
        return [true, 'Удалён из WhiteList'];
    }
    if ($type === 'unblock') {
        $wpdb->update($wpdb->prefix . 'site_protection_blocks', ['is_active' => 0], ['ip_address' => $subject], ['%d'], ['%s']);
        delete_transient(mac_site_protection_state_key('mac_sp_blocked', $subject));
        delete_transient('mac_sp_block_ranges');
        $wpdb->delete($wpdb->prefix . 'site_protection_incidents', ['ip_address' => $subject], ['%s']);
        return [true, 'Блокировка снята, история нарушений очищена'];
    }

    $expiresAt = trim((string) ($command['expires_at'] ?? ''));
    if (isset($command['expires_at_unix']) && is_numeric($command['expires_at_unix'])) {
        $expiresAt = wp_date('Y-m-d H:i:s', (int) $command['expires_at_unix']);
    }
    $table = $wpdb->prefix . 'site_protection_blocks';
    $reason = substr(trim((string) ($command['reason'] ?? 'Решение центра')), 0, 100);
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE ip_address=%s AND is_active=1 AND (expires_at IS NULL OR expires_at>%s) ORDER BY id DESC LIMIT 1",
        $subject,
        current_time('mysql')
    ));
    if ($existing) {
        // Reapplying a block is a renewal, never a deactivate-then-insert
        // operation. This preserves both the active protection and its hits.
        $updated = $wpdb->update($table, [
            'reason' => $reason,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
            'is_active' => 1,
        ], ['id' => (int) $existing], ['%s', '%s', '%d'], ['%d']);
        if ($updated === false) return [false, 'Не удалось обновить активную блокировку: ' . $wpdb->last_error];
    } else {
        $inserted = $wpdb->insert($table, [
            'ip_address' => $subject,
            'reason' => $reason,
            'created_at' => current_time('mysql'),
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
            'is_active' => 1, 'blocked_hits' => 0, 'last_blocked_at' => null,
        ], ['%s', '%s', '%s', '%s', '%d', '%d', '%s']);
        if ($inserted === false) return [false, 'Не удалось создать блокировку: ' . $wpdb->last_error];
    }
    delete_transient(mac_site_protection_state_key('mac_sp_blocked', $subject));
    delete_transient('mac_sp_block_ranges');
    return [true, $expiresAt === '' ? 'Заблокирован навсегда' : 'Заблокирован до ' . $expiresAt];
}

function mac_site_protection_central_apply_settings(array $remote) {
    $settings = mac_site_protection_settings();
    $number = static function ($key, $min) use ($remote, $settings) {
        return max($min, (int) ($remote[$key] ?? $settings[$key]));
    };
    $settings['protection_mode'] = !empty($remote['auto_block_enabled']) ? 'enforce' : 'monitor';
    $settings['card_hour_limit'] = $number('card_hour_limit', MAC_SP_MIN_CARD_HOUR);
    $settings['card_daily_limit'] = $number('card_daily_limit', MAC_SP_MIN_CARD_DAILY);
    $settings['block_hour_limit'] = $number('block_hour_limit', MAC_SP_MIN_BLOCK_HOUR);
    $settings['block_minutes'] = $number('block_minutes', 10);
    $settings['repeat_block_minutes'] = $number('repeat_block_minutes', 10);
    $settings['rhythm_samples'] = min(50, $number('rhythm_samples', MAC_SP_MIN_RHYTHM_SAMPLES));
    $settings['rhythm_spread'] = min(100, $number('rhythm_spread', 1));
    $settings['rhythm_block_minutes'] = $number('rhythm_block_minutes', 10);
    if (array_key_exists('rhythm_enabled', $remote)) $settings['rhythm_enabled'] = !empty($remote['rhythm_enabled']) ? '1' : '0';
    update_option(MAC_SITE_PROTECTION_OPTION, $settings, false);
}

function mac_site_protection_central_save_sync_status(array $status) {
    $status['attempted_at'] = current_time('mysql');
    update_option('mac_site_protection_central_last_sync', $status, false);
}

function mac_site_protection_central_sync() {
    $config = mac_site_protection_central_config();
    if ($config['url'] === '' || $config['api_key'] === '') {
        mac_site_protection_central_save_sync_status([
            'state' => 'not_configured',
            'error' => 'Не заполнены адрес центра или API key.',
        ]);
        return;
    }

    global $wpdb;
    $eventsTable = $wpdb->prefix . 'site_protection_events';
    $sitemapTable = $wpdb->prefix . 'sitemap_logs';
    $crawlerTable = $wpdb->prefix . 'crawler_logs';
    $eventsRows = $wpdb->get_results("SELECT * FROM {$eventsTable} ORDER BY id ASC LIMIT 300", ARRAY_A);
    $sitemapRows = $wpdb->get_results("SELECT * FROM {$sitemapTable} ORDER BY id ASC LIMIT 300", ARRAY_A);
    $crawlerRows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$crawlerTable} WHERE log_date >= %s AND request_count >= %d ORDER BY id ASC LIMIT 300", wp_date('Y-m-d', time() - DAY_IN_SECONDS), MAC_CRAWLER_LOGS_DAILY_THRESHOLD), ARRAY_A);
    $activeBlocks = $wpdb->get_results("SELECT ip_address,reason,expires_at,blocked_hits FROM {$wpdb->prefix}site_protection_blocks WHERE is_active=1 AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 501", ARRAY_A);
    $pendingEvents = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$eventsTable}");
    $pendingXml = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sitemapTable}");
    $blocksComplete = count($activeBlocks) <= 500;
    $activeBlocks = array_slice($activeBlocks, 0, 500);
    $acks = (array) get_option('mac_site_protection_central_acks', []);
    $vinApiEvents = array_slice((array) get_option('mac_vin_provider_health_queue', []), 0, 50);
    $payload = ['agent_version' => MAC_SITE_PROTECTION_CENTRAL_AGENT_VERSION, 'events' => [], 'sitemap_logs' => [], 'crawler_daily' => [], 'vin_api_events' => $vinApiEvents, 'command_acks' => $acks, 'active_blocks' => $activeBlocks, 'blocks_complete' => $blocksComplete, 'pending_events' => $pendingEvents, 'pending_xml' => $pendingXml];

    foreach ($eventsRows as $row) {
        $payload['events'][] = ['source_key' => 'event-' . (int) $row['id'], 'occurred_at' => $row['created_at'], 'ip_address' => $row['ip_address'], 'subject' => mac_site_protection_central_row_subject($row['ip_address'], $row['user_agent']), 'rule_key' => $row['rule_key'], 'request_count' => (int) $row['request_count'], 'threshold_count' => (int) $row['threshold_count'], 'action_taken' => $row['action_taken'], 'request_uri' => $row['request_uri'], 'user_agent' => $row['user_agent']];
    }
    foreach ($sitemapRows as $row) {
        $payload['sitemap_logs'][] = ['source_key' => 'sitemap-' . (int) $row['id'], 'occurred_at' => $row['created_at'], 'ip_address' => $row['ip_address'], 'subject' => mac_site_protection_central_row_subject($row['ip_address'], $row['user_agent']), 'sitemap_path' => $row['sitemap_path'], 'request_uri' => $row['request_uri'], 'bot_name' => $row['bot_name'], 'verified_bot' => mac_site_protection_cached_official_request($row['user_agent'], $row['ip_address']) ? 1 : 0, 'response_code' => (int) $row['response_code'], 'referer' => $row['referer'], 'user_agent' => $row['user_agent']];
    }
    foreach ($crawlerRows as $row) {
        $payload['crawler_daily'][] = ['log_date' => $row['log_date'], 'ip_address' => $row['ip_address'], 'subject' => mac_site_protection_central_row_subject($row['ip_address'], $row['user_agent']), 'bot_name' => $row['bot_name'], 'verified_bot' => mac_site_protection_cached_official_request($row['user_agent'], $row['ip_address']) ? 1 : 0, 'request_count' => (int) $row['request_count'], 'first_seen' => $row['first_seen'], 'last_seen' => $row['last_seen'], 'last_request_uri' => $row['last_request_uri'], 'last_response_code' => (int) $row['last_response_code'], 'user_agent' => $row['user_agent']];
    }

    $response = wp_remote_post($config['url'] . '/api/protection.php', ['timeout' => 20, 'headers' => ['Content-Type' => 'application/json', 'X-API-Key' => $config['api_key']], 'body' => wp_json_encode($payload)]);
    if (is_wp_error($response)) {
        mac_site_protection_central_save_sync_status([
            'state' => 'request_error',
            'error' => $response->get_error_message(),
        ]);
        return;
    }
    $responseCode = (int) wp_remote_retrieve_response_code($response);
    if ($responseCode !== 200) {
        mac_site_protection_central_save_sync_status([
            'state' => 'http_error',
            'http_code' => $responseCode,
            'error' => wp_strip_all_tags((string) wp_remote_retrieve_body($response)),
        ]);
        return;
    }
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['success'])) {
        mac_site_protection_central_save_sync_status([
            'state' => 'invalid_response',
            'http_code' => $responseCode,
            'error' => 'Центр вернул некорректный ответ.',
        ]);
        return;
    }

    if (!empty($body['settings']) && is_array($body['settings'])) mac_site_protection_central_apply_settings($body['settings']);
    if (!empty($body['profile']) && is_array($body['profile'])) update_option(MAC_SITE_PROTECTION_PROFILE_OPTION, mac_site_protection_clean_profile($body['profile']), false);

    if ($eventsRows) $wpdb->query("DELETE FROM {$eventsTable} WHERE id IN (" . implode(',', array_map('intval', wp_list_pluck($eventsRows, 'id'))) . ')');
    if ($sitemapRows) $wpdb->query("DELETE FROM {$sitemapTable} WHERE id IN (" . implode(',', array_map('intval', wp_list_pluck($sitemapRows, 'id'))) . ')');
    if ($vinApiEvents) {
        $pending = (array) get_option('mac_vin_provider_health_queue', []);
        $delivered = array_flip(array_filter(array_map(static function ($row) { return (string) ($row['source_key'] ?? ''); }, $vinApiEvents)));
        update_option('mac_vin_provider_health_queue', array_values(array_filter($pending, static function ($row) use ($delivered) {
            return !isset($delivered[(string) ($row['source_key'] ?? '')]);
        })), false);
    }
    // Keep only current and previous daily aggregates on the executor. The
    // centre received the final aggregate before local cleanup.
    $cutoff = wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS);
    $wpdb->query($wpdb->prepare("DELETE FROM {$crawlerTable} WHERE log_date < %s", $cutoff));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}crawler_log_samples WHERE log_date < %s", $cutoff));
    $wpdb->query("DELETE FROM {$wpdb->prefix}site_protection_rate_buckets WHERE bucket_start < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) LIMIT 10000");
    $wpdb->query("DELETE FROM {$wpdb->prefix}site_protection_rhythm WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) LIMIT 10000");
    $receivedCommandIds = [];
    $newAcks = [];
    foreach ((array) ($body['commands'] ?? []) as $command) {
        $commandId = (int) ($command['id'] ?? 0);
        if ($commandId > 0) $receivedCommandIds[] = $commandId;
        try {
            [$ok, $message] = mac_site_protection_central_apply_command((array) $command);
        } catch (Throwable $error) {
            $ok = false;
            $message = $error->getMessage();
        }
        $newAcks[] = ['command_id' => $commandId, 'ok' => $ok, 'message' => $message];
    }

    // Confirm the result immediately. The old protocol waited for the next
    // five-minute agent run, leaving the central queue ambiguous for too long.
    $commandResults = array_map(static function ($ack) {
        return [
            'id' => (int) $ack['command_id'],
            'ok' => !empty($ack['ok']),
            'message' => (string) $ack['message'],
        ];
    }, $newAcks);
    $ackState = 'not_required';
    if ($newAcks) {
        $ackResponse = wp_remote_post($config['url'] . '/api/protection.php', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json', 'X-API-Key' => $config['api_key']],
            'body' => wp_json_encode([
                'agent_version' => MAC_SITE_PROTECTION_CENTRAL_AGENT_VERSION,
                'events' => [], 'sitemap_logs' => [], 'crawler_daily' => [], 'vin_api_events' => [],
                'command_acks' => $newAcks,
            ]),
        ]);
        if (!is_wp_error($ackResponse) && wp_remote_retrieve_response_code($ackResponse) === 200) {
            $ackBody = json_decode((string) wp_remote_retrieve_body($ackResponse), true);
            if (is_array($ackBody) && !empty($ackBody['success'])) {
                $newAcks = [];
                $ackState = 'confirmed';
            } else {
                $ackState = 'invalid_response';
            }
        } else {
            $ackState = is_wp_error($ackResponse) ? $ackResponse->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code($ackResponse);
        }
    }
    update_option('mac_site_protection_central_acks', $newAcks, false);
    mac_site_protection_central_save_sync_status([
        'state' => 'success',
        'http_code' => $responseCode,
        'received_commands' => $receivedCommandIds,
        'command_results' => $commandResults,
        'ack_state' => $ackState,
        'error' => '',
    ]);
}
add_action(MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK, 'mac_site_protection_central_sync');

function mac_site_protection_state_key($prefix, $value = '') {
    return $prefix . '_' . mac_site_protection_state_version() . '_' . md5((string) $value);
}

function mac_site_protection_settings() {
    return wp_parse_args((array) get_option(MAC_SITE_PROTECTION_OPTION, []), [
        // Start new installations in observation mode. It records threshold
        // crossings but never limits or blocks until the centre enables it.
        'protection_mode' => 'monitor',
        'card_hour_limit' => '50', 'card_daily_limit' => '300',
        'block_hour_limit' => '100', 'block_minutes' => '1440', 'repeat_block_minutes' => '10080',
        'rhythm_enabled' => '1', 'rhythm_samples' => '20', 'rhythm_spread' => '25', 'rhythm_block_minutes' => '10080',
        'ip_whitelist' => '', 'ua_whitelist' => '', 'telegram_topic_id' => '27659',
    ]);
}

function mac_site_protection_ip_in_cidr($ip, $cidr) {
    if (strpos($cidr, '/') === false) return hash_equals($cidr, $ip);
    [$network, $bits] = explode('/', $cidr, 2);
    $ip_bin = @inet_pton($ip);
    $network_bin = @inet_pton($network);
    $bits = (int) $bits;
    if ($ip_bin === false || $network_bin === false || strlen($ip_bin) !== strlen($network_bin) || $bits < 0 || $bits > strlen($ip_bin) * 8) return false;
    $full_bytes = intdiv($bits, 8);
    $remaining_bits = $bits % 8;
    if ($full_bytes > 0 && substr($ip_bin, 0, $full_bytes) !== substr($network_bin, 0, $full_bytes)) return false;
    if ($remaining_bits === 0) return true;
    $mask = (0xFF << (8 - $remaining_bits)) & 0xFF;
    return (ord($ip_bin[$full_bytes]) & $mask) === (ord($network_bin[$full_bytes]) & $mask);
}

function mac_site_protection_is_trusted_proxy($ip) {
    // Official Cloudflare proxy ranges. CF-Connecting-IP is used only if the
    // TCP peer is in this list, so visitors cannot forge their client IP.
    $cloudflare = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32',
        '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    foreach ($cloudflare as $cidr) {
        $cidr = trim($cidr);
        if ($cidr !== '' && mac_site_protection_ip_in_cidr($ip, $cidr)) return true;
    }
    return false;
}

function mac_site_protection_client_ip() {
    $remote = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if (!filter_var($remote, FILTER_VALIDATE_IP) || !mac_site_protection_is_trusted_proxy($remote)) return $remote;
    $cf_ip = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if (filter_var($cf_ip, FILTER_VALIDATE_IP)) return substr($cf_ip, 0, 45);
    $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '');
    return filter_var($forwarded, FILTER_VALIDATE_IP) ? substr($forwarded, 0, 45) : $remote;
}

function mac_site_protection_ipv6_network($ip) {
    $packed = @inet_pton((string) $ip);
    if ($packed === false || strlen($packed) !== 16) return '';
    return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
}

function mac_site_protection_subject($ip, $traffic_class = '') {
    // Rotating addresses inside one IPv6 /64 are one crawler for the
    // purposes of suspicious traffic, honeypots and rate limiting.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && in_array($traffic_class, ['suspicious_browser', 'unverified_bot', 'honeypot'], true)) {
        return mac_site_protection_ipv6_network($ip) ?: $ip;
    }
    return $ip;
}
function mac_site_protection_whitelisted($ip, $ua) {
    $s = mac_site_protection_settings();
    foreach (preg_split('/\r\n|\r|\n/', (string) $s['ip_whitelist']) as $item) {
        $item = trim(preg_replace('/#.*/', '', $item));
        if ($item !== '' && mac_site_protection_ip_in_cidr((string) $ip, $item)) return true;
    }
    foreach (preg_split('/\r\n|\r|\n/', (string) $s['ua_whitelist']) as $item) {
        $item = trim(preg_replace('/#.*/', '', $item));
        if ($item !== '' && stripos((string) $ua, $item) !== false) return true;
    }
    return false;
}
function mac_site_protection_matching_block_subjects($ip) {
    global $wpdb;
    $subjects = array_values(array_unique(array_filter([$ip, mac_site_protection_ipv6_network($ip)])));
    $ranges = get_transient('mac_sp_block_ranges');
    if (!is_array($ranges)) {
        $ranges = $wpdb->get_col("SELECT ip_address FROM {$wpdb->prefix}site_protection_blocks WHERE is_active=1 AND ip_address LIKE '%/%' AND (expires_at IS NULL OR expires_at > NOW())");
        set_transient('mac_sp_block_ranges', $ranges, MINUTE_IN_SECONDS);
    }
    foreach ($ranges as $range) {
        if (mac_site_protection_ip_in_cidr($ip, (string) $range)) $subjects[] = (string) $range;
    }
    return array_values(array_unique($subjects));
}
function mac_site_protection_blocked($ip) {
    global $wpdb;
    $subjects = mac_site_protection_matching_block_subjects($ip);
    if (!$subjects) return false;
    $hasRange = count($subjects) > 1 || strpos((string) $subjects[0], '/') !== false;
    $key = mac_site_protection_state_key('mac_sp_blocked', $ip);
    if (!$hasRange) {
        $cached = get_transient($key);
        if ($cached !== false) return $cached === '1';
    }
    $placeholders = implode(',', array_fill(0, count($subjects), '%s'));
    $blocked = (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}site_protection_blocks WHERE ip_address IN ({$placeholders}) AND is_active=1 AND (expires_at IS NULL OR expires_at>%s) LIMIT 1", ...array_merge($subjects, [current_time('mysql')])));
    if (!$hasRange) set_transient($key, $blocked ? '1' : '0', 30);
    return $blocked;
}
function mac_site_protection_record_block_hit($ip) {
    global $wpdb;
    $subjects = mac_site_protection_matching_block_subjects($ip);
    if (!$subjects) return;
    $placeholders = implode(',', array_fill(0, count($subjects), '%s'));
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}site_protection_blocks SET blocked_hits=blocked_hits+1, last_blocked_at=%s WHERE ip_address IN ({$placeholders}) AND is_active=1 AND (expires_at IS NULL OR expires_at>%s)", ...array_merge([current_time('mysql')], $subjects, [current_time('mysql')])));
}

function mac_site_protection_expire_blocks() {
    global $wpdb;
    $expired = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}site_protection_blocks SET is_active = 0 WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at <= %s", current_time('mysql')));
    if ($expired) delete_transient('mac_sp_block_ranges');
}
function mac_site_protection_register_incident($ip, $rule_key) {
    global $wpdb;
    $today = current_time('Y-m-d'); $from = wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS);
    $table = $wpdb->prefix . 'site_protection_incidents';
    $wpdb->insert($table, ['ip_address'=>$ip,'rule_key'=>$rule_key,'incident_date'=>$today,'created_at'=>current_time('mysql'),'level'=>1], ['%s','%s','%s','%s','%d']);
    $incidents = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ip_address=%s AND rule_key=%s AND incident_date >= %s", $ip, $rule_key, $from));
    return min(5, max(1, $incidents));
}
function mac_site_protection_block($ip, $reason, $minutes) {
    global $wpdb;
    $expires_at = $minutes === null ? null : wp_date('Y-m-d H:i:s', time() + max(1, (int) $minutes) * MINUTE_IN_SECONDS);
    $already_blocked = $wpdb->get_row($wpdb->prepare(
        "SELECT id, expires_at FROM {$wpdb->prefix}site_protection_blocks WHERE ip_address = %s AND is_active = 1 AND (expires_at IS NULL OR expires_at > %s) ORDER BY expires_at DESC LIMIT 1",
        $ip,
        current_time('mysql')
    ));
    if ($already_blocked) {
        if ($already_blocked->expires_at === null || ($expires_at !== null && $already_blocked->expires_at >= $expires_at)) return true;
        $updated = $wpdb->update($wpdb->prefix . 'site_protection_blocks', ['expires_at' => $expires_at, 'reason' => $reason], ['id' => (int) $already_blocked->id], ['%s', '%s'], ['%d']);
        if ($updated === false) return false;
        delete_transient(mac_site_protection_state_key('mac_sp_blocked', $ip));
        return true;
    }
    $inserted = $wpdb->insert($wpdb->prefix . 'site_protection_blocks', ['ip_address' => $ip, 'reason' => $reason, 'created_at' => current_time('mysql'), 'expires_at' => $expires_at, 'is_active' => 1], ['%s','%s','%s','%s','%d']);
    if ($inserted === false) {
        error_log('Site protection block insert failed: ' . $wpdb->last_error);
        return false;
    }
    delete_transient(mac_site_protection_state_key('mac_sp_blocked', $ip));
    return true;
}
/**
 * Search-engine family to verify by reverse DNS. Google's Ads, Merchant
 * Center and Search Console crawlers do not contain "Googlebot" in the UA.
 */
function mac_site_protection_search_engine_key($ua) {
    if (preg_match('/adsbot-google|storebot-google|google-inspectiontool|mediapartners-google|googleother/i', (string) $ua)) return 'googlebot';
    return mac_sitemap_logs_bot_key($ua);
}

function mac_site_protection_cached_official_request($ua, $ip) {
    $bot = mac_site_protection_search_engine_key($ua);
    if (!in_array($bot, ['googlebot','bingbot','yandexbot','applebot'], true) || $ip === '') return false;
    return get_transient('mac_sp_verified_' . md5($bot . '|' . $ip)) === '1';
}

function mac_site_protection_is_official_request($ua, $ip = '') {
    $bot = mac_site_protection_search_engine_key($ua);
    $suffixes = ['googlebot' => ['googlebot.com', 'google.com'], 'bingbot' => ['search.msn.com'], 'yandexbot' => ['yandex.ru', 'yandex.net', 'yandex.com'], 'applebot' => ['applebot.apple.com']];
    if (!isset($suffixes[$bot]) || $ip === '') return false;
    $cache_key = 'mac_sp_verified_' . md5($bot . '|' . $ip);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached === '1';
    $host = strtolower((string) @gethostbyaddr($ip));
    $valid = false;
    foreach ($suffixes[$bot] as $suffix) {
        if ($host !== $suffix && substr($host, -strlen('.' . $suffix)) !== '.' . $suffix) continue;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $records = @dns_get_record($host, DNS_AAAA) ?: [];
            $valid = in_array(inet_pton($ip), array_map('inet_pton', array_column($records, 'ipv6')), true);
        } else {
            $resolved = @gethostbynamel($host) ?: [];
            $valid = in_array($ip, $resolved, true);
        }
        if ($valid) break;
    }
    // A short negative cache lets a temporary DNS failure recover quickly.
    set_transient($cache_key, $valid ? '1' : '0', $valid ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
    return $valid;
}

function mac_site_protection_traffic_class($ua, $ip) {
    if (mac_site_protection_is_official_request($ua, $ip)) return 'official';
    $bot = mac_sitemap_logs_bot_key($ua);
    if (in_array($bot, ['ahrefsbot', 'semrushbot', 'mj12bot'], true)) return 'seo';
    if ($bot !== 'visitor') return 'unverified_bot';
    return mac_site_protection_browser_signal_score($ua) >= 2 ? 'suspicious_browser' : 'visitor';
}

function mac_site_protection_is_store_products_collection($path) {
    $route = (string) ($_GET['rest_route'] ?? $path);
    if (preg_match('#^/wp-json#i', $route)) $route = substr($route, 8);
    return preg_match('#^/wc/store/v[0-9]+/products/?$#i', $route) === 1;
}

// Plugin namespaces. Their routes work as before, but guests do not see them
// listed: the same list on every site would tie the sites together.
const MAC_SP_HIDDEN_REST_NAMESPACES = ['master-auto-catalog/v1', 'auto-sync/v1', 'cryptocloud/v1', 'heleket/v1'];

/**
 * Core and WooCommerce routes that list or describe products, and the index
 * pages of the plugin namespaces. Visitors never need them: the theme renders
 * the catalogue on the server, the cart and the checkout use other wc/store
 * routes, and the centre calls its own routes with X-API-Key.
 */
function mac_site_protection_is_private_rest_route($route) {
    $route = '/' . ltrim((string) $route, '/');
    foreach (MAC_SP_HIDDEN_REST_NAMESPACES as $namespace) {
        if (untrailingslashit($route) === '/' . $namespace) return true;
    }
    return preg_match('#^/wp/v[0-9]+(?:/|$)#i', $route) === 1
        || preg_match('#^/wc/store(?:/v[0-9]+)?/products(?:/|$)#i', $route) === 1;
}

add_filter('rest_index', function ($response) {
    if (is_user_logged_in() || !($response instanceof WP_REST_Response)) return $response;
    $data = $response->get_data();
    if (isset($data['namespaces'])) $data['namespaces'] = array_values(array_diff((array) $data['namespaces'], MAC_SP_HIDDEN_REST_NAMESPACES));
    foreach (array_keys((array) ($data['routes'] ?? [])) as $route) {
        foreach (MAC_SP_HIDDEN_REST_NAMESPACES as $namespace) {
            if (strpos($route, '/' . $namespace) === 0) unset($data['routes'][$route]);
        }
    }
    $response->set_data($data);
    return $response;
});

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if ($result !== null || current_user_can('edit_posts')) return $result;
    if (!mac_site_protection_is_private_rest_route($request->get_route())) return $result;
    // Answer like a missing route so the response does not confirm that a
    // product or a hidden draft exists.
    return new WP_Error('rest_no_route', 'No route was found matching the URL and request method.', ['status' => 404]);
}, 0, 3);

add_action('init', function () {
    if (is_user_logged_in()) return;
    // These headers point every page to its /wp-json/wp/v2/... record.
    remove_action('wp_head', 'rest_output_link_wp_head', 10);
    remove_action('template_redirect', 'rest_output_link_header', 11);
});

/**
 * Some hosts list the uploads folders, which shows every new product photo
 * with its upload time without touching PHP. The listing is closed with
 * "Options -Indexes" in uploads/.htaccess. A host that forbids that directive
 * answers 500 for every image, and nginx ignores .htaccess, so the result is
 * checked with a request to the folder: on failure the file is restored and
 * an empty index.html is placed in each folder instead.
 */
const MAC_SP_UPLOAD_LISTING_OPTION = 'mac_site_protection_upload_listing_v2';
const MAC_SP_UPLOAD_HTACCESS_MARK = '# BEGIN MAC no-listing';

/** @return string 'open', 'closed', 'error' or '' when the check failed. */
function mac_site_protection_upload_listing_state($url) {
    $response = wp_remote_get($url, ['timeout' => 15, 'redirection' => 0]);
    if (is_wp_error($response)) return '';
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code >= 500) return 'error';
    return stripos((string) wp_remote_retrieve_body($response), 'Index of') !== false ? 'open' : 'closed';
}

function mac_site_protection_hide_upload_listing($dir) {
    $dir = untrailingslashit((string) $dir);
    if ($dir === '' || !is_dir($dir) || file_exists($dir . '/index.html') || file_exists($dir . '/index.php')) return;
    @file_put_contents($dir . '/index.html', '');
}

function mac_site_protection_add_upload_index_files($base) {
    mac_site_protection_hide_upload_listing($base);
    foreach ((array) glob($base . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR) as $year) {
        mac_site_protection_hide_upload_listing($year);
        foreach ((array) glob($year . '/[0-9][0-9]', GLOB_ONLYDIR) as $month) mac_site_protection_hide_upload_listing($month);
    }
}

function mac_site_protection_close_upload_listing() {
    if (get_option(MAC_SP_UPLOAD_LISTING_OPTION, '') !== '') return;
    $uploads = wp_get_upload_dir();
    $base = untrailingslashit((string) ($uploads['basedir'] ?? ''));
    $url = trailingslashit((string) ($uploads['baseurl'] ?? ''));
    if ($base === '' || !is_dir($base) || $url === '/') return;

    $state = mac_site_protection_upload_listing_state($url);
    if ($state === 'closed') {
        update_option(MAC_SP_UPLOAD_LISTING_OPTION, 'server', false);
        return;
    }

    if ($state === 'open') {
        $file = $base . '/.htaccess';
        $original = file_exists($file) ? (string) file_get_contents($file) : null;
        if ($original !== null && strpos($original, MAC_SP_UPLOAD_HTACCESS_MARK) !== false) {
            $written = true;
        } else {
            $prefix = $original === null || $original === '' ? '' : rtrim($original, "\n") . "\n\n";
            $written = @file_put_contents($file, $prefix . MAC_SP_UPLOAD_HTACCESS_MARK . "\nOptions -Indexes\n# END MAC no-listing\n") !== false;
        }
        if ($written && mac_site_protection_upload_listing_state($url) === 'closed') {
            update_option(MAC_SP_UPLOAD_LISTING_OPTION, 'htaccess', false);
            return;
        }
        // 500 (directive forbidden) or still open (nginx): undo the change.
        if ($written) {
            if ($original === null) @unlink($file); else @file_put_contents($file, $original);
        }
    }

    // A failed loopback request also ends here: index.html is harmless.
    mac_site_protection_add_upload_index_files($base);
    update_option(MAC_SP_UPLOAD_LISTING_OPTION, 'index', false);
}
add_action(MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK, 'mac_site_protection_close_upload_listing', 5);

add_filter('upload_dir', function ($uploads) {
    // With index.html protection a new month folder needs its own file.
    if (empty($uploads['error']) && get_option(MAC_SP_UPLOAD_LISTING_OPTION, '') === 'index') {
        mac_site_protection_hide_upload_listing($uploads['path'] ?? '');
    }
    return $uploads;
});

/**
 * A product's modified date is public in three places: the sitemap lastmod,
 * the order of the Yoast product sitemap (sorted by post_modified, so an
 * edited product moves to its last page) and the page's
 * article:modified_time and schema dateModified. A published product
 * therefore keeps its creation date as its modified date.
 */
add_filter('wp_insert_post_data', function ($data, $postarr, $unsanitized = [], $update = false) {
    if (!$update || ($data['post_type'] ?? '') !== 'product') return $data;
    // Drafts have no GMT date yet; they get one when published.
    if (empty($data['post_date']) || empty($data['post_date_gmt']) || $data['post_date_gmt'] === '0000-00-00 00:00:00') return $data;
    $data['post_modified'] = $data['post_date'];
    $data['post_modified_gmt'] = $data['post_date_gmt'];
    return $data;
}, 99, 4);

// Products edited before this rule keep their old modified dates; align them once.
add_action(MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK, function () {
    if (get_option('mac_site_protection_product_dates_v1') === '1') return;
    global $wpdb;
    $wpdb->query("UPDATE {$wpdb->posts} SET post_modified=post_date, post_modified_gmt=post_date_gmt
        WHERE post_type='product' AND post_status='publish' AND post_date_gmt<>'0000-00-00 00:00:00'
          AND (post_modified<>post_date OR post_modified_gmt<>post_date_gmt)");
    if ($wpdb->last_error === '') update_option('mac_site_protection_product_dates_v1', '1', false);
}, 6);

add_filter('wpseo_sitemap_entry', function ($url, $type, $post) {
    if ($type !== 'post' || !is_object($post) || get_query_var('sitemap') !== 'product') return $url;
    // Only published products belong in the sitemap, whatever Yoast cached.
    if (isset($post->post_status) && $post->post_status !== 'publish') return false;
    if (is_array($url) && !empty($post->post_date_gmt) && $post->post_date_gmt !== '0000-00-00 00:00:00') $url['mod'] = $post->post_date_gmt;
    return $url;
}, 10, 3);

add_filter('wpseo_schema_webpage', function ($data) {
    if (is_singular('product') && is_array($data) && !empty($data['datePublished'])) $data['dateModified'] = $data['datePublished'];
    return $data;
});

add_filter('wpseo_frontend_presenters', function ($presenters) {
    if (!is_singular('product') || !is_array($presenters)) return $presenters;
    return array_values(array_filter($presenters, static function ($presenter) {
        return !is_a($presenter, 'Yoast\WP\SEO\Presenters\Open_Graph\Article_Modified_Time_Presenter');
    }));
});

/**
 * Sitemaps and feeds publish the full product list with dates. Visitors never
 * open them, so only verified search engines receive them.
 */
function mac_site_protection_is_listing_feed($path) {
    if (mac_sitemap_logs_is_xml_request($path)) return true;
    if (isset($_GET['sitemap']) || isset($_GET['sitemap-stylesheet']) || isset($_GET['feed'])) return true;
    return preg_match('#/feed(?:/(?:rss2?|atom|rdf|rss-http))?/?$#i', (string) $path) === 1;
}

function mac_site_protection_is_own_server($ip) {
    if (in_array($ip, ['127.0.0.1', '::1'], true)) return true;
    $server = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    return $server !== '' && hash_equals($server, (string) $ip);
}

function mac_site_protection_random_token($min, $max) {
    return strtolower(wp_generate_password(random_int($min, $max), false, false));
}

/**
 * Names and markup that the protection adds to public pages, chosen at
 * random once per site. With fixed names the same cookie and the same hidden
 * link on every site would show that the sites belong together.
 */
function mac_site_protection_fingerprint() {
    static $fingerprint = null;
    if ($fingerprint !== null) return $fingerprint;
    $stored = get_option('mac_site_protection_fingerprint');
    if (is_array($stored) && !empty($stored['cookie']) && !empty($stored['class'])) return $fingerprint = $stored;
    $cookies = ['_vsid', '_uvt', '_tzo', '_sbv', '_cxs', '_pvt', '_lvs', '_ssr', '_dpv', '_hsv', '_rfs', '_wvc'];
    $fingerprint = [
        'cookie' => $cookies[array_rand($cookies)] . mac_site_protection_random_token(2, 4),
        'value' => mac_site_protection_random_token(4, 10),
        'class' => chr(random_int(97, 122)) . mac_site_protection_random_token(5, 9),
        'text' => ['.', '·', '›', '»', '-'][random_int(0, 4)],
        'style' => random_int(0, 2),
    ];
    update_option('mac_site_protection_fingerprint', $fingerprint, true);
    return $fingerprint;
}

function mac_site_protection_honeypot_path() {
    $option = 'mac_site_protection_honeypot_path_v3';
    $path = (string) get_option($option, '');
    if (preg_match('#^/[a-z0-9][a-z0-9/_.-]{6,160}$#', $path)) return $path;

    // Generated once per site and kept in wp_options. Do not rotate this path:
    // robots.txt and the invisible link must always point to the same target.
    // The shape varies too, so robots.txt files of two sites do not match.
    $folders = ['assets', 'content', 'data', 'files', 'media', 'resources', 'static', 'lib', 'storage', 'public', 'docs', 'export', 'cache', 'legacy', 'partners', 'dealer', 'inventory', 'stock', 'listing'];
    $names = ['index', 'list', 'export', 'feed', 'dump', 'items', 'records', 'stock', 'inventory', 'full', 'all', 'backup', 'catalog', 'products', 'vehicles', 'lots'];
    $extensions = ['xml', 'json', 'csv', 'txt', 'html'];
    $segments = [];
    for ($i = random_int(1, 3); $i > 0; $i--) $segments[] = random_int(0, 1) ? $folders[array_rand($folders)] : mac_site_protection_random_token(6, 14);
    $name = $names[array_rand($names)] . (random_int(0, 1) ? (random_int(0, 1) ? '-' : '_') . mac_site_protection_random_token(4, 8) : '');
    $path = '/' . implode('/', array_unique($segments)) . '/' . $name . '.' . $extensions[array_rand($extensions)];
    update_option($option, $path, false);
    return $path;
}

/** The current trap and the one older versions listed in robots.txt. */
function mac_site_protection_honeypot_paths() {
    $paths = [mac_site_protection_honeypot_path(), (string) get_option('mac_site_protection_honeypot_path_v2', '')];
    return array_values(array_unique(array_filter(array_map('untrailingslashit', $paths))));
}

add_filter('robots_txt', function ($output, $public) {
    // Remove the legacy shared path on the first request after the update.
    $output = preg_replace('#^\s*Disallow:\s*/mac-crawler-trap/?\s*$#mi', '', (string) $output);
    $rule = 'Disallow: ' . mac_site_protection_honeypot_path();
    // Join the existing "User-agent: *" group instead of adding a second one.
    if (preg_match('/^User-agent:[ \t]*\*[ \t]*\r?$/mi', $output)) {
        return preg_replace('/^(User-agent:[ \t]*\*[ \t]*)\r?$/mi', '$1' . "\n" . $rule, $output, 1);
    }
    return rtrim($output) . "\n\nUser-agent: *\n" . $rule . "\n";
}, 10, 2);

add_action('wp_footer', function () {
    if (is_admin()) return;
    $fp = mac_site_protection_fingerprint();
    $css = [
        '.%s{position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden}',
        '.%s{position:absolute!important;clip:rect(1px,1px,1px,1px);width:1px;height:1px;overflow:hidden}',
        '.%s{position:fixed;top:-999px;left:-999px;opacity:0;pointer-events:none}',
    ][(int) $fp['style'] % 3];
    echo '<script>document.cookie="' . esc_js($fp['cookie']) . '=' . esc_js($fp['value']) . ';path=/;max-age=2592000;SameSite=Lax"</script>';
    echo '<style>' . sprintf($css, esc_attr($fp['class'])) . '</style>';
    echo '<a class="' . esc_attr($fp['class']) . '" href="' . esc_url(home_url(mac_site_protection_honeypot_path())) . '" rel="nofollow" aria-hidden="true" tabindex="-1">' . esc_html($fp['text']) . '</a>';
}, 999);

function mac_site_protection_browser_signal_score($ua) {
    $score = 0;
    if (empty($_COOKIE[mac_site_protection_fingerprint()['cookie']])) $score += 1;
    if (trim((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) === '') $score += 1;
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (stripos($ua, 'mozilla/') !== false && $accept !== '' && strpos($accept, 'text/html') === false) $score += 1;
    return $score;
}

function mac_site_protection_increment_window($ip, $rule_key, $minutes) {
    global $wpdb;
    $table = $wpdb->prefix . 'site_protection_rate_buckets';
    $bucket = gmdate('Y-m-d H:i:00');
    $cutoff = gmdate('Y-m-d H:i:00', time() - (max(1, (int) $minutes) - 1) * MINUTE_IN_SECONDS);
    $wpdb->query($wpdb->prepare("INSERT INTO {$table} (subject,rule_key,bucket_start,hits) VALUES (%s,%s,%s,1) ON DUPLICATE KEY UPDATE hits=hits+1", $ip, $rule_key, $bucket));
    if ($wpdb->last_error) return 0;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(hits),0) FROM {$table} WHERE subject=%s AND rule_key=%s AND bucket_start >= %s", $ip, $rule_key, $cutoff));
}

/**
 * Current and previous 95 quarter-hour buckets cover the past 24 hours
 * with at most 15 minutes of boundary approximation.
 */
function mac_site_protection_increment_daily_window($subject, $rule_key) {
    global $wpdb;
    $table = $wpdb->prefix . 'site_protection_rate_buckets';
    $slot = intdiv(time(), 15 * MINUTE_IN_SECONDS) * 15 * MINUTE_IN_SECONDS;
    $bucket = gmdate('Y-m-d H:i:00', $slot);
    $cutoff = gmdate('Y-m-d H:i:00', $slot - 95 * 15 * MINUTE_IN_SECONDS);
    $inserted = $wpdb->query($wpdb->prepare("INSERT INTO {$table} (subject,rule_key,bucket_start,hits) VALUES (%s,%s,%s,1) ON DUPLICATE KEY UPDATE hits=hits+1", $subject, $rule_key, $bucket));
    if ($inserted === false) {
        error_log('Site protection daily counter failed: ' . $wpdb->last_error);
        return 0;
    }
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(hits),0) FROM {$table} WHERE subject=%s AND rule_key=%s AND bucket_start >= %s", $subject, $rule_key, $cutoff));
}

function mac_site_protection_log_event($ip, $rule_key, $count, $limit, $action, $ua) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'site_protection_events', [
        'created_at' => current_time('mysql'), 'ip_address' => $ip, 'rule_key' => $rule_key,
        'request_count' => $count, 'threshold_count' => $limit, 'action_taken' => $action,
        'request_uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 2048),
        'user_agent' => substr($ua, 0, 2048),
    ], ['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);
}

/**
 * Remembers when a subject opened its last pages and measures how even the
 * gaps between them are. A person reads for three seconds, then for four
 * minutes; a script waits about the same time every time, even with a random
 * pause. A pause of 30 minutes starts a new series.
 *
 * @return array{0:bool,1:int} Robot verdict and the spread of the gaps, %.
 */
function mac_site_protection_rhythm_check($subject, $samples, $spread_limit) {
    global $wpdb;
    $table = $wpdb->prefix . 'site_protection_rhythm';
    $now = microtime(true);
    $stored = (string) $wpdb->get_var($wpdb->prepare("SELECT stamps FROM {$table} WHERE subject=%s", $subject));
    $stamps = $stored === '' ? [] : array_map('floatval', explode(',', $stored));
    if ($stamps && $now - end($stamps) > 30 * MINUTE_IN_SECONDS) $stamps = [];
    $stamps[] = $now;
    $stamps = array_slice($stamps, -max(2, (int) $samples));
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (subject,stamps,updated_at) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE stamps=VALUES(stamps), updated_at=VALUES(updated_at)",
        $subject,
        implode(',', array_map(static function ($stamp) { return sprintf('%.3f', $stamp); }, $stamps)),
        gmdate('Y-m-d H:i:s')
    ));
    if (count($stamps) < $samples) return [false, 0];

    $gaps = [];
    for ($i = 1, $n = count($stamps); $i < $n; $i++) $gaps[] = max(0.0, $stamps[$i] - $stamps[$i - 1]);
    $mean = array_sum($gaps) / count($gaps);
    // Many pages within the same moment are parallel downloads, not reading.
    if ($mean < 0.2) return [true, 0];
    $variance = 0.0;
    foreach ($gaps as $gap) $variance += ($gap - $mean) ** 2;
    $spread = (int) round(sqrt($variance / count($gaps)) / $mean * 100);
    return [$spread < $spread_limit, $spread];
}

/** An HTML page a person opens: not the API, AJAX, assets or service files. */
function mac_site_protection_is_page_request($path) {
    if (preg_match('#^/(?:wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php)(?:/|$)#i', $path)) return false;
    if (preg_match('#^/wp-json(?:/|$)#i', $path) || isset($_GET['rest_route']) || isset($_GET['wc-ajax'])) return false;
    return preg_match('/\.(?:css|js|map|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|eot|mp4|webm|pdf|zip|txt|xml|json)$/i', $path) !== 1;
}

const MAC_SITE_PROTECTION_PROFILE_OPTION = 'mac_site_protection_profile';

/**
 * How this site answers a blocked visitor and what a limited visitor sees.
 * The centre sends one profile per group of sites, so sites of different
 * groups never share a block page.
 */
function mac_site_protection_profile_defaults() {
    return [
        'group' => '',
        'layout' => 'card', 'status' => 429, 'retry_after' => '1',
        'title' => 'Too many requests',
        'text' => 'You have opened a lot of pages in a short time. Please try again later.',
        'email' => '', 'accent' => '#2563eb', 'server_name' => 'nginx',
        'vin_mode' => 'mask', 'vin_text' => 'Available in the full report', 'mask_char' => '•',
        'photo_label' => 'Photo unavailable', 'photo_bg' => '#e5e7eb',
    ];
}

function mac_site_protection_clean_profile(array $raw) {
    $p = wp_parse_args($raw, mac_site_protection_profile_defaults());
    $d = mac_site_protection_profile_defaults();
    return [
        'group' => substr(sanitize_text_field((string) $p['group']), 0, 100),
        'layout' => in_array($p['layout'], ['theme', 'card', 'minimal', 'server', 'plain'], true) ? $p['layout'] : $d['layout'],
        'status' => in_array((int) $p['status'], [403, 429, 503], true) ? (int) $p['status'] : 429,
        'retry_after' => empty($p['retry_after']) ? '0' : '1',
        'title' => substr(sanitize_text_field((string) $p['title']), 0, 150),
        'text' => substr(sanitize_textarea_field((string) $p['text']), 0, 1000),
        'email' => sanitize_email((string) $p['email']),
        'accent' => sanitize_hex_color((string) $p['accent']) ?: $d['accent'],
        'server_name' => substr(sanitize_text_field((string) $p['server_name']), 0, 40),
        'vin_mode' => $p['vin_mode'] === 'text' ? 'text' : 'mask',
        'vin_text' => substr(sanitize_text_field((string) $p['vin_text']), 0, 100),
        'mask_char' => mb_substr(sanitize_text_field((string) $p['mask_char']), 0, 1) ?: '•',
        'photo_label' => substr(sanitize_text_field((string) $p['photo_label']), 0, 100),
        'photo_bg' => sanitize_hex_color((string) $p['photo_bg']) ?: $d['photo_bg'],
    ];
}

function mac_site_protection_profile() {
    return mac_site_protection_clean_profile((array) get_option(MAC_SITE_PROTECTION_PROFILE_OPTION, []));
}

function mac_site_protection_profile_text($text, array $profile) {
    return strtr((string) $text, ['{site_name}' => get_bloginfo('name'), '{home_url}' => home_url('/'), '{email}' => $profile['email']]);
}

function mac_site_protection_status_phrase($status) {
    return [403 => 'Forbidden', 429 => 'Too Many Requests', 503 => 'Service Temporarily Unavailable'][(int) $status] ?? 'Too Many Requests';
}

function mac_site_protection_send_block_headers(array $profile, $retry_after) {
    nocache_headers();
    status_header((int) $profile['status']);
    if ($profile['retry_after'] === '1') header('Retry-After: ' . max(60, (int) $retry_after));
    header('X-Robots-Tag: noindex', true);
}

/** The whole answer for every layout except "theme", which needs the theme. */
function mac_site_protection_block_document(array $profile) {
    $title = mac_site_protection_profile_text($profile['title'], $profile);
    $text = mac_site_protection_profile_text($profile['text'], $profile);
    if ($profile['layout'] === 'plain') {
        header('Content-Type: text/plain; charset=utf-8');
        return trim($title . "\n\n" . $text) . "\n";
    }
    header('Content-Type: text/html; charset=utf-8');
    if ($profile['layout'] === 'server') {
        $line = (int) $profile['status'] . ' ' . mac_site_protection_status_phrase($profile['status']);
        $signature = $profile['server_name'] !== '' ? "<hr><center>" . esc_html($profile['server_name']) . "</center>\r\n" : '';
        return "<html>\r\n<head><title>{$line}</title></head>\r\n<body>\r\n<center><h1>{$line}</h1></center>\r\n{$signature}</body>\r\n</html>\r\n";
    }

    $site = get_bloginfo('name');
    $logo_id = (int) get_theme_mod('custom_logo');
    $logo = $logo_id ? (string) wp_get_attachment_image_url($logo_id, 'medium') : '';
    $icon = (string) get_site_icon_url(64);
    $accent = $profile['accent'];
    $brand = $logo !== '' ? '<img src="' . esc_url($logo) . '" alt="' . esc_attr($site) . '">' : esc_html($site);
    $head = '<!DOCTYPE html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html($title . ' – ' . $site) . '</title>' . ($icon !== '' ? '<link rel="icon" href="' . esc_url($icon) . '">' : '');
    $body = wpautop(esc_html($text));
    if ($profile['layout'] === 'minimal') {
        return $head . '<style>body{margin:0;font:16px/1.6 Georgia,"Times New Roman",serif;color:#222;background:#fff}header{padding:18px 24px;border-bottom:1px solid #e5e5e5}header a{color:' . $accent . ';text-decoration:none;font-weight:bold}header img{max-height:40px}section{max-width:640px;padding:48px 24px}h1{font-size:28px;font-weight:normal;margin:0 0 16px}</style></head>'
            . '<body><header><a href="' . esc_url(home_url('/')) . '">' . $brand . '</a></header><section><h1>' . esc_html($title) . '</h1>' . $body . '</section></body></html>';
    }
    return $head . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f7f9;color:#1f2933}main{max-width:520px;margin:24px;padding:40px 32px;background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.06);border-top:4px solid ' . $accent . '}.brand{display:inline-block;margin-bottom:24px;color:' . $accent . ';font-weight:700;font-size:20px;text-decoration:none}.brand img{max-height:48px;max-width:200px}h1{margin:0 0 12px;font-size:24px}p{margin:0 0 12px;line-height:1.6}</style></head>'
        . '<body><main><a class="brand" href="' . esc_url(home_url('/')) . '">' . $brand . '</a><h1>' . esc_html($title) . '</h1>' . $body . '</main></body></html>';
}

function mac_site_protection_can_render_in_theme($path) {
    if (!mac_site_protection_is_page_request($path) || mac_site_protection_is_listing_feed($path)) return false;
    return !function_exists('wp_is_block_theme') || !wp_is_block_theme();
}

function mac_site_protection_render_theme_block() {
    $data = $GLOBALS['mac_sp_theme_block'] ?? null;
    if (!is_array($data)) return;
    $profile = $data['profile'];
    $title = mac_site_protection_profile_text($profile['title'], $profile);
    $text = mac_site_protection_profile_text($profile['text'], $profile);
    $document_title = static function () use ($title) { return $title . ' – ' . get_bloginfo('name'); };
    add_filter('pre_get_document_title', $document_title, 99);
    add_filter('wpseo_title', $document_title, 99);
    add_filter('wp_robots', 'wp_robots_no_robots');
    add_filter('wpseo_robots', static function () { return 'noindex, nofollow'; }, 99);
    mac_site_protection_send_block_headers($profile, $data['retry_after']);
    header('Content-Type: text/html; charset=utf-8');
    get_header();
    echo '<div class="container"><article class="page type-page"><header class="entry-header"><h1 class="entry-title">' . esc_html($title) . '</h1></header><div class="entry-content">' . wpautop(esc_html($text)) . '</div></article></div>';
    get_footer();
    exit;
}

/**
 * Answers a refused request with the page of the site's group. The theme
 * layout needs the theme, so a normal page is answered at template_redirect
 * after an empty 404 query; other requests and layouts are answered at once.
 * The caller must return right after this call.
 */
function mac_site_protection_deny($retry_after, $wp = null) {
    mac_sitemap_logs_begin_request();
    mac_crawler_logs_begin_request();
    $profile = mac_site_protection_profile();
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($profile['layout'] === 'theme' && is_object($wp) && mac_site_protection_can_render_in_theme($path)) {
        $GLOBALS['mac_sp_theme_block'] = ['profile' => $profile, 'retry_after' => $retry_after];
        $wp->query_vars = ['error' => '404'];
        add_action('template_redirect', 'mac_site_protection_render_theme_block', PHP_INT_MIN);
        return;
    }
    if ($profile['layout'] === 'theme') $profile['layout'] = 'card';
    mac_site_protection_send_block_headers($profile, $retry_after);
    echo mac_site_protection_block_document($profile);
    exit;
}

/**
 * The limited product card: no photos and a masked VIN. The visitor sees an
 * ordinary page, so a crawler does not learn that it has been limited.
 */
function mac_site_protection_start_limited_mode() {
    if (!empty($GLOBALS['mac_sp_limited'])) return;
    $GLOBALS['mac_sp_limited'] = true;
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    add_filter('get_post_metadata', static function ($value, $object_id, $meta_key) {
        if (!in_array($meta_key, ['_thumbnail_id', '_product_image_gallery'], true) || get_post_type($object_id) !== 'product') return $value;
        return [''];
    }, 99, 3);
    add_filter('woocommerce_product_get_image_id', '__return_zero', 99);
    add_filter('woocommerce_product_get_gallery_image_ids', '__return_empty_array', 99);
    add_filter('woocommerce_placeholder_img_src', 'mac_site_protection_placeholder_image', 99);
    add_action('send_headers', static function () { header('Cache-Control: private, no-store, max-age=0', true); });
    add_action('template_redirect', static function () {
        $GLOBALS['mac_sp_limited_patterns'] = mac_site_protection_product_image_patterns();
        ob_start('mac_site_protection_limited_output');
    }, PHP_INT_MAX);
}

/**
 * Address patterns of the opened product's photos, in every size. A theme
 * may print them without WooCommerce functions, so the page text is cleaned
 * as well. Read from the database directly: the meta filters above hide them.
 */
function mac_site_protection_product_image_patterns() {
    if (!is_singular('product')) return [];
    global $wpdb;
    $product_id = (int) get_queried_object_id();
    $ids = [];
    foreach ($wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ('_thumbnail_id','_product_image_gallery')", $product_id)) as $value) {
        foreach (explode(',', (string) $value) as $id) $ids[] = (int) $id;
    }
    $ids = array_merge($ids, array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent=%d AND post_type='attachment' LIMIT 100", $product_id))));
    $patterns = [];
    foreach (array_slice(array_unique(array_filter($ids)), 0, 100) as $id) {
        $base = preg_replace('/(?:-scaled)?\.[a-z0-9]+$/i', '', (string) get_post_meta($id, '_wp_attached_file', true));
        // A short bare name such as "1" would also match unrelated images.
        if (strlen(basename($base)) < 4) continue;
        $patterns[] = '#[^\s"\'(),]*' . preg_quote($base, '#') . '(?:-scaled)?(?:-\d+x\d+)?\.(?:jpe?g|png|gif|webp|avif)#i';
    }
    return $patterns;
}

function mac_site_protection_limited_output($html) {
    if (!is_string($html) || $html === '') return $html;
    $patterns = (array) ($GLOBALS['mac_sp_limited_patterns'] ?? []);
    if ($patterns) $html = preg_replace($patterns, mac_site_protection_placeholder_image(), $html) ?? $html;
    return mac_site_protection_mask_vins($html, mac_site_protection_profile());
}

/**
 * Masks VINs in the page text. Lowercase VINs in addresses stay: the product
 * address must keep working. A VIN has a digit and letters and no I, O or Q.
 */
function mac_site_protection_mask_vins($html, array $profile) {
    $masked = preg_replace_callback('/(?<![A-Za-z0-9\/_.=%-])[A-HJ-NPR-Z0-9]{17}(?![A-Za-z0-9])/', static function ($match) use ($profile) {
        $vin = $match[0];
        if (!preg_match('/[0-9]/', $vin) || preg_match_all('/[A-Z]/', $vin) < 2) return $vin;
        if ($profile['vin_mode'] === 'text') return esc_html($profile['vin_text']);
        return substr($vin, 0, 3) . str_repeat($profile['mask_char'], 10) . substr($vin, -4);
    }, $html);
    return $masked ?? $html;
}

function mac_site_protection_placeholder_image() {
    static $uri = null;
    if ($uri !== null) return $uri;
    $profile = mac_site_protection_profile();
    $label = $profile['photo_label'] !== '' ? '<text x="400" y="300" font-family="Arial,sans-serif" font-size="28" fill="#6b7280" text-anchor="middle" dominant-baseline="middle">' . esc_html($profile['photo_label']) . '</text>' : '';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600"><rect width="800" height="600" fill="' . $profile['photo_bg'] . '"/>' . $label . '</svg>';
    return $uri = 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
}

const MAC_SP_LIMITED_RECENT_OPTION = 'mac_site_protection_limited_recent';

function mac_site_protection_remember_limited($subject, $rule_key) {
    $list = (array) get_option(MAC_SP_LIMITED_RECENT_OPTION, []);
    $list[$subject] = ['rule' => $rule_key, 'at' => current_time('mysql')];
    $cutoff = wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    $list = array_filter($list, static function ($row) use ($cutoff) { return (string) ($row['at'] ?? '') >= $cutoff; });
    uasort($list, static function ($a, $b) { return strcmp((string) $b['at'], (string) $a['at']); });
    update_option(MAC_SP_LIMITED_RECENT_OPTION, array_slice($list, 0, 200, true), false);
}

/** Shows the limited card; the event is reported once an hour per subject. */
function mac_site_protection_limit($subject, $rule_key, $count, $limit, $ua, $mode) {
    $event_key = mac_site_protection_state_key('mac_sp_limit_event', $subject . '|' . $rule_key);
    if (get_transient($event_key) === false) {
        set_transient($event_key, '1', HOUR_IN_SECONDS);
        mac_site_protection_log_event($subject, $rule_key, $count, $limit, $mode === 'enforce' ? 'limit' : 'monitor', $ua);
        if ($mode === 'enforce') mac_site_protection_remember_limited($subject, $rule_key);
    }
    if ($mode === 'enforce') mac_site_protection_start_limited_mode();
}

/**
 * Blocks the subject for a rule. A repeated hourly-limit violation within
 * 30 days gets the longer repeat term. The caller must return afterwards.
 */
function mac_site_protection_apply_block($subject, $rule_key, $count, $limit, $ua, $mode, $wp) {
    if ($mode !== 'enforce') {
        $event_key = mac_site_protection_state_key('mac_sp_block_monitor', $subject . '|' . $rule_key);
        if (get_transient($event_key) === false) {
            set_transient($event_key, '1', HOUR_IN_SECONDS);
            mac_site_protection_log_event($subject, $rule_key, $count, $limit, 'monitor', $ua);
        }
        return;
    }
    $s = mac_site_protection_settings();
    $level = mac_site_protection_register_incident($subject, $rule_key);
    $minutes = $rule_key === 'rhythm' ? (int) $s['rhythm_block_minutes'] : (int) ($level > 1 ? $s['repeat_block_minutes'] : $s['block_minutes']);
    $reason = ['page_hour' => 'Hourly page limit', 'rhythm' => 'Robot rhythm', 'catalog_api_rate' => 'Catalog API limit'][$rule_key] ?? $rule_key;
    if ($level > 1) $reason .= ', repeat';
    if (!mac_site_protection_block($subject, $reason, max(1, $minutes))) {
        mac_site_protection_log_event($subject, $rule_key, $count, $limit, 'error', $ua);
        mac_site_protection_deny(60, $wp);
        return;
    }
    mac_site_protection_log_event($subject, $rule_key, $count, $limit, 'block', $ua);
    mac_site_protection_deny(max(1, $minutes) * MINUTE_IN_SECONDS, $wp);
}

function mac_site_protection_enforce_v2($wp = null) {
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) return;
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) return;

    $ip = mac_site_protection_client_ip();
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    // Loopback requests come from the site's own server.
    if ($ip === '' || mac_site_protection_is_own_server($ip) || mac_site_protection_whitelisted($ip, $ua)) return;

    // Do not run a database UPDATE on every request just to expire old rows.
    if (!get_transient('mac_sp_expire_checked')) {
        mac_site_protection_expire_blocks();
        set_transient('mac_sp_expire_checked', '1', 5 * MINUTE_IN_SECONDS);
    }
    // An active block covers every address, including search, sitemaps and feeds.
    if (mac_site_protection_blocked($ip)) {
        mac_site_protection_record_block_hit($ip);
        mac_site_protection_deny(HOUR_IN_SECONDS, $wp);
        return;
    }

    $s = mac_site_protection_settings();
    $mode = $s['protection_mode'];
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $class = mac_site_protection_traffic_class($ua, $ip);
    if ($class === 'official') return;
    $subject = mac_site_protection_subject($ip, $class);

    if (in_array(untrailingslashit($path), mac_site_protection_honeypot_paths(), true)) {
        $subject = mac_site_protection_subject($ip, 'honeypot');
        mac_site_protection_log_event($subject, 'honeypot', 1, 0, $mode === 'enforce' ? 'block' : 'monitor', $ua);
        if ($mode === 'enforce') {
            mac_site_protection_block($subject, 'Service URL access', 7 * DAY_IN_SECONDS / MINUTE_IN_SECONDS);
            mac_site_protection_deny(7 * DAY_IN_SECONDS, $wp);
        }
        return;
    }

    if (mac_site_protection_is_listing_feed($path)) {
        if ($mode !== 'enforce') {
            $event_key = mac_site_protection_state_key('mac_sp_feed_monitor', $subject);
            if (get_transient($event_key) === false) {
                set_transient($event_key, '1', HOUR_IN_SECONDS);
                mac_site_protection_log_event($subject, 'sitemap_forbidden', 1, 0, 'monitor', $ua);
            }
            return;
        }
        // SEO services are refused without a block: they announce themselves
        // honestly and are not the crawlers that copy the catalogue.
        if ($class !== 'seo') {
            mac_site_protection_block($subject, 'Sitemap or feed access', MAC_SP_SITEMAP_BLOCK_MINUTES);
            mac_site_protection_log_event($subject, 'sitemap_forbidden', 1, 0, 'block', $ua);
        }
        mac_site_protection_deny(DAY_IN_SECONDS, $wp);
        return;
    }

    if (mac_site_protection_is_store_products_collection($path)) {
        $requestedPageSize = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
        if ($requestedPageSize > 20) {
            // The same answer WordPress gives for an out-of-range parameter.
            nocache_headers();
            status_header(400);
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode(['code' => 'rest_invalid_param', 'message' => 'Invalid parameter(s): per_page', 'data' => ['status' => 400, 'params' => ['per_page' => 'per_page must be between 1 (inclusive) and 20 (inclusive)']]]);
            exit;
        }
        $count = mac_site_protection_increment_window($subject, 'catalog_api_rate', 10);
        if ($count > 12) mac_site_protection_apply_block($subject, 'catalog_api_rate', $count, 12, $ua, $mode, $wp);
        return;
    }

    if (!mac_site_protection_is_page_request($path)) return;

    $hour = mac_site_protection_increment_window($subject, 'page_hour', 60);
    $block_limit = (int) $s['block_hour_limit'];
    if ($hour > $block_limit) {
        mac_site_protection_apply_block($subject, 'page_hour', $hour, $block_limit, $ua, $mode, $wp);
        return;
    }
    if ($s['rhythm_enabled'] === '1') {
        [$robot, $spread] = mac_site_protection_rhythm_check($subject, (int) $s['rhythm_samples'], (int) $s['rhythm_spread']);
        if ($robot) {
            mac_site_protection_apply_block($subject, 'rhythm', $spread, (int) $s['rhythm_spread'], $ua, $mode, $wp);
            return;
        }
    }

    $day = mac_site_protection_increment_daily_window($subject, 'page_day');
    if ($hour > (int) $s['card_hour_limit']) {
        mac_site_protection_limit($subject, 'card_hour', $hour, (int) $s['card_hour_limit'], $ua, $mode);
    } elseif ($day > (int) $s['card_daily_limit']) {
        mac_site_protection_limit($subject, 'card_daily', $day, (int) $s['card_daily_limit'], $ua, $mode);
    } elseif ($class === 'unverified_bot' || $class === 'seo') {
        mac_site_protection_limit($subject, 'bot_ua', $hour, 0, $ua, $mode);
    } elseif ($class === 'suspicious_browser') {
        mac_site_protection_limit($subject, 'no_browser', mac_site_protection_browser_signal_score($ua), 2, $ua, $mode);
    }
}
add_action('parse_request', 'mac_site_protection_enforce_v2', -1);

// Lets an administrator see the block page and the limited card of this site.
add_action('parse_request', function ($wp) {
    $preview = (string) ($_GET['mac_sp_preview'] ?? '');
    if ($preview === '' || !current_user_can('manage_options')) return;
    if ($preview === 'card') {
        mac_site_protection_start_limited_mode();
        return;
    }
    mac_site_protection_deny(HOUR_IN_SECONDS, $wp);
}, -2);

function mac_site_protection_minutes_label($minutes) {
    $minutes = (int) $minutes;
    if ($minutes % (DAY_IN_SECONDS / MINUTE_IN_SECONDS) === 0) return ($minutes / (DAY_IN_SECONDS / MINUTE_IN_SECONDS)) . ' дн.';
    if ($minutes % 60 === 0) return ($minutes / 60) . ' ч';
    return $minutes . ' мин';
}

function mac_site_protection_page_v2() {
    if (!current_user_can('manage_options')) return;
    // Rules, groups and history live in the centre. The site only shows what
    // it currently enforces.
    global $wpdb;
    mac_site_protection_expire_blocks();
    $settings = mac_site_protection_settings();
    $profile = mac_site_protection_profile();
    $blocks = $wpdb->get_results("SELECT ip_address, reason, created_at, expires_at, blocked_hits, last_blocked_at FROM {$wpdb->prefix}site_protection_blocks WHERE is_active=1 ORDER BY id DESC", ARRAY_A);
    $limited = (array) get_option(MAC_SP_LIMITED_RECENT_OPTION, []);
    $config = mac_site_protection_central_config();
    $syncStatus = (array) get_option('mac_site_protection_central_last_sync', []);
    $syncStateLabels = [
        'success' => 'связь есть',
        'not_configured' => 'подключение не настроено',
        'request_error' => 'ошибка соединения',
        'http_error' => 'центр вернул ошибку HTTP',
        'invalid_response' => 'некорректный ответ центра',
    ];
    $layoutLabels = ['theme' => 'в оформлении сайта', 'card' => 'карточка с логотипом', 'minimal' => 'простая страница', 'server' => 'как ошибка сервера', 'plain' => 'только текст'];
    $limitLabels = ['card_hour' => 'страниц за час', 'card_daily' => 'страниц за сутки', 'bot_ua' => 'бот по User-Agent', 'no_browser' => 'не похож на браузер'];
    $tab = ($_GET['tab'] ?? '') === 'limited' ? 'limited' : 'blocks';
    $tabUrl = static function ($name) { return esc_url(admin_url('admin.php?page=mac-site-protection&tab=' . $name)); };
    ?>
    <div class="wrap mac-protection-content">
        <div class="mac-section-title"><h1>Защита сайта</h1><p>Правила, группы и история находятся в центре. Сайт применяет их и проверяет команды примерно раз в минуту.</p></div>
        <section class="mac-protection-panel">
            <p><strong>Центр:</strong> <?php echo $config['url'] !== '' && $config['api_key'] !== '' ? esc_html($config['url']) : 'не настроен — заполните адрес и API key в «Синхронизация с центром»'; ?>
            <?php if ($syncStatus): ?> · <?php echo esc_html($syncStateLabels[(string) ($syncStatus['state'] ?? '')] ?? 'неизвестно'); ?>, последний обмен <?php echo esc_html((string) ($syncStatus['attempted_at'] ?? '—')); ?><?php endif; ?></p>
            <?php if (!empty($syncStatus['error'])): ?><p><strong>Ошибка:</strong> <?php echo esc_html((string) $syncStatus['error']); ?></p><?php endif; ?>
            <p><strong>Группа:</strong> <?php echo esc_html($profile['group'] !== '' ? $profile['group'] : 'ещё не получена от центра'); ?> · <strong>Режим:</strong> <?php echo $settings['protection_mode'] === 'enforce' ? 'работает' : 'наблюдение (только отметки)'; ?></p>
            <p><strong>Сейчас действует:</strong> урезанная карточка с <?php echo (int) $settings['card_hour_limit']; ?> страниц за час или <?php echo (int) $settings['card_daily_limit']; ?> за сутки · блок с <?php echo (int) $settings['block_hour_limit']; ?> страниц за час на <?php echo esc_html(mac_site_protection_minutes_label($settings['block_minutes'])); ?>, повторно на <?php echo esc_html(mac_site_protection_minutes_label($settings['repeat_block_minutes'])); ?> · ритм робота: <?php echo $settings['rhythm_enabled'] === '1' ? 'разброс меньше ' . (int) $settings['rhythm_spread'] . '% на ' . (int) $settings['rhythm_samples'] . ' страницах → блок на ' . esc_html(mac_site_protection_minutes_label($settings['rhythm_block_minutes'])) : 'выключен'; ?></p>
            <p><strong>Страница блокировки:</strong> <?php echo esc_html($layoutLabels[$profile['layout']] ?? $profile['layout']); ?>, код <?php echo (int) $profile['status']; ?> ·
                <a href="<?php echo esc_url(add_query_arg('mac_sp_preview', '1', home_url('/'))); ?>" target="_blank" rel="noopener">посмотреть страницу блокировки</a> ·
                <a href="<?php echo esc_url(add_query_arg('mac_sp_preview', 'card', home_url('/'))); ?>" target="_blank" rel="noopener">посмотреть урезанную карточку</a> <span class="description">(откройте любой товар и добавьте к адресу <code>?mac_sp_preview=card</code>)</span></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('mac_site_protection_sync_now'); ?><input type="hidden" name="action" value="mac_site_protection_sync_now"><button type="submit" class="button">Проверить команды сейчас</button></form>
        </section>
        <h2 class="nav-tab-wrapper">
            <a class="nav-tab <?php echo $tab === 'blocks' ? 'nav-tab-active' : ''; ?>" href="<?php echo $tabUrl('blocks'); ?>">Блокировки (<?php echo count($blocks); ?>)</a>
            <a class="nav-tab <?php echo $tab === 'limited' ? 'nav-tab-active' : ''; ?>" href="<?php echo $tabUrl('limited'); ?>">Урезанная карточка за сутки (<?php echo count($limited); ?>)</a>
        </h2>
        <section class="mac-protection-panel">
        <?php if ($tab === 'blocks'): ?>
            <table class="widefat striped"><thead><tr><th>IP / сеть</th><th>Причина</th><th>Создана</th><th>До</th><th>Отклонено</th><th>Последняя попытка</th></tr></thead><tbody>
            <?php if ($blocks): foreach ($blocks as $block): ?><tr><td><?php echo esc_html($block['ip_address']); ?></td><td><?php echo esc_html($block['reason']); ?></td><td><?php echo esc_html($block['created_at']); ?></td><td><?php echo esc_html($block['expires_at'] ?: 'Навсегда'); ?></td><td><?php echo number_format_i18n((int) ($block['blocked_hits'] ?? 0)); ?></td><td><?php echo esc_html($block['last_blocked_at'] ?: '—'); ?></td></tr><?php endforeach; else: ?><tr><td colspan="6">Активных блокировок нет.</td></tr><?php endif; ?>
            </tbody></table>
        <?php else: ?>
            <table class="widefat striped"><thead><tr><th>IP / сеть</th><th>Причина</th><th>Последний раз</th></tr></thead><tbody>
            <?php if ($limited): foreach ($limited as $subject => $row): ?><tr><td><?php echo esc_html((string) $subject); ?></td><td><?php echo esc_html($limitLabels[(string) ($row['rule'] ?? '')] ?? (string) ($row['rule'] ?? '')); ?></td><td><?php echo esc_html((string) ($row['at'] ?? '')); ?></td></tr><?php endforeach; else: ?><tr><td colspan="3">За сутки никто не получал урезанную карточку.</td></tr><?php endif; ?>
            </tbody></table>
        <?php endif; ?>
        </section>
        <section class="mac-protection-panel"><p><strong>WhiteList IP:</strong> <?php echo $settings['ip_whitelist'] !== '' ? esc_html(implode(', ', preg_split('/\r\n|\r|\n/', (string) $settings['ip_whitelist']))) : 'пусто'; ?></p></section>
    </div>
    <?php
}

add_action('admin_menu', function () { if (defined('MAC_MASTER_ACTIVE') && MAC_MASTER_ACTIVE) add_submenu_page('master-auto-catalog', 'Защита сайта', 'Защита сайта', 'manage_options', 'mac-site-protection', 'mac_site_protection_page_v2'); }, 30);

add_action('admin_post_mac_site_protection_sync_now', function () {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    check_admin_referer('mac_site_protection_sync_now');
    mac_site_protection_central_sync();
    wp_safe_redirect(admin_url('admin.php?page=mac-site-protection&sync_now=1'));
    exit;
});

function mac_sitemap_logs_normalize_path($path)
{
    $path = trim((string) $path);
    if ($path === '') return '/sitemap.xml';

    $parsed_path = wp_parse_url($path, PHP_URL_PATH);
    if (is_string($parsed_path) && $parsed_path !== '') {
        $path = $parsed_path;
    }

    $path = '/' . ltrim($path, '/');
    return $path === '/' ? '/sitemap.xml' : untrailingslashit($path);
}

function mac_sitemap_logs_is_xml_request($request_path)
{
    return is_string($request_path) && preg_match('/\.xml$/i', $request_path) === 1;
}

function mac_sitemap_logs_detect_bot($user_agent)
{
    return mac_sitemap_logs_bot_label(mac_sitemap_logs_bot_key($user_agent));
}

function mac_sitemap_logs_bot_key($user_agent)
{
    $user_agent = strtolower((string) $user_agent);
    $bots = [
        'googlebot' => 'googlebot', 'bingbot' => 'bingbot', 'yandexbot' => 'yandexbot',
        'duckduckbot' => 'duckduckbot', 'baiduspider' => 'baiduspider', 'applebot' => 'applebot',
        'ahrefsbot' => 'ahrefsbot', 'semrushbot' => 'semrushbot', 'mj12bot' => 'mj12bot',
        'marketgoo' => 'marketgoo', 'sparixemailscraper' => 'sparixemailscraper',
    ];
    foreach ($bots as $needle => $key) {
        if (strpos($user_agent, $needle) !== false) return $key;
    }
    return preg_match('/bot|crawler|spider|slurp|archiver/i', $user_agent) ? 'other_bot' : 'visitor';
}

function mac_sitemap_logs_bot_label($key)
{
    $labels = [
        'googlebot' => 'Googlebot', 'bingbot' => 'Bingbot', 'yandexbot' => 'YandexBot',
        'duckduckbot' => 'DuckDuckBot', 'baiduspider' => 'BaiduSpider', 'applebot' => 'Applebot',
        'ahrefsbot' => 'AhrefsBot', 'semrushbot' => 'SemrushBot', 'mj12bot' => 'MJ12bot',
        'marketgoo' => 'MarketGoo', 'sparixemailscraper' => 'SparixEmailScraper',
        'other_bot' => 'Другой бот', 'visitor' => 'Посетитель',
    ];
    return $labels[$key] ?? 'Посетитель';
}

function mac_sitemap_logs_is_ignored_user_agent($user_agent)
{
    return stripos((string) $user_agent, 'AccelerateWP/Preload') !== false;
}

function mac_site_protection_is_authenticated_central_request($request_uri = '')
{
    $path = (string) wp_parse_url((string) $request_uri, PHP_URL_PATH);
    if ($path !== '/wp-json/master-auto-catalog/v1/local-vin-data') return false;
    $configured_key = trim((string) get_option('cas_sync_key', ''));
    $provided_key = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    return $configured_key !== '' && $provided_key !== '' && hash_equals($configured_key, $provided_key);
}

function mac_sitemap_logs_begin_request()
{
    if (isset($GLOBALS['mac_sitemap_logs_current_request'])) return;

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) return;

    $request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $request_path = wp_parse_url($request_uri, PHP_URL_PATH);
    if (!is_string($request_path) || $request_path === '') return;
    $user_agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    if (!mac_sitemap_logs_is_xml_request($request_path)) return;
    if (mac_sitemap_logs_is_ignored_user_agent($user_agent)) return;

    $GLOBALS['mac_sitemap_logs_current_request'] = [
        'sitemap_path' => mac_sitemap_logs_normalize_path($request_path),
        'request_uri' => substr(sanitize_text_field(wp_unslash($request_uri)), 0, 2048),
        'request_method' => $method,
        'ip_address' => function_exists('mac_site_protection_client_ip') ? mac_site_protection_client_ip() : substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45),
        'user_agent' => substr(sanitize_text_field(wp_unslash($user_agent)), 0, 2048),
        'referer' => substr(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'] ?? '')), 0, 2048),
        'user_id' => get_current_user_id(),
    ];

    add_action('shutdown', 'mac_sitemap_logs_write_current_request', PHP_INT_MAX);
}
add_action('parse_request', 'mac_sitemap_logs_begin_request', 0);

function mac_crawler_logs_begin_request()
{
    if (isset($GLOBALS['mac_crawler_logs_current_request'])) return;
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) return;

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) return;
    $user_agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (mac_sitemap_logs_is_ignored_user_agent($user_agent)) return;
    // The centre verifies local VINs through this authenticated endpoint.
    // It is operational traffic, not an external crawler.
    if (mac_site_protection_is_authenticated_central_request($_SERVER['REQUEST_URI'] ?? '')) return;

    $GLOBALS['mac_crawler_logs_current_request'] = [
        'ip_address' => function_exists('mac_site_protection_client_ip') ? mac_site_protection_client_ip() : substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45),
        'user_agent' => substr(sanitize_text_field(wp_unslash($user_agent)), 0, 2048),
        'request_uri' => substr(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), 0, 2048),
        'referer' => substr(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'] ?? '')), 0, 2048),
    ];

    add_action('shutdown', 'mac_crawler_logs_write_current_request', PHP_INT_MAX);
}
add_action('parse_request', 'mac_crawler_logs_begin_request', 1);

function mac_crawler_logs_write_current_request()
{
    $data = $GLOBALS['mac_crawler_logs_current_request'] ?? null;
    if (!is_array($data) || $data['ip_address'] === '') return;

    global $wpdb;
    $now = current_time('mysql');
    $response_code = (int) http_response_code();
    if ($response_code < 100) $response_code = 200;

    $table = $wpdb->prefix . 'crawler_logs';
    $sql = "INSERT INTO {$table}
        (log_date, ip_address, bot_name, user_agent, request_count, first_seen, last_seen, last_request_uri, last_response_code)
        VALUES (%s, %s, %s, %s, 1, %s, %s, %s, %d)
        ON DUPLICATE KEY UPDATE
            request_count = request_count + 1,
            bot_name = VALUES(bot_name),
            user_agent = VALUES(user_agent),
            last_seen = VALUES(last_seen),
            last_request_uri = VALUES(last_request_uri),
            last_response_code = VALUES(last_response_code)";

    $wpdb->query($wpdb->prepare(
        $sql,
        current_time('Y-m-d'),
        $data['ip_address'],
        mac_sitemap_logs_detect_bot($data['user_agent']),
        $data['user_agent'],
        $now,
        $now,
        $data['request_uri'],
        $response_code
    ));

    $request_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT request_count FROM {$table} WHERE log_date = %s AND ip_address = %s",
        current_time('Y-m-d'),
        $data['ip_address']
    ));
    if ($request_count < MAC_CRAWLER_LOGS_DAILY_THRESHOLD) return;

    $samples_table = $wpdb->prefix . 'crawler_log_samples';
    $wpdb->insert($samples_table, [
        'log_date' => current_time('Y-m-d'),
        'ip_address' => $data['ip_address'],
        'created_at' => $now,
        'request_uri' => $data['request_uri'],
        'response_code' => $response_code,
        'referer' => $data['referer'],
    ], ['%s', '%s', '%s', '%s', '%d', '%s']);

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$samples_table}
         WHERE log_date = %s AND ip_address = %s AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM {$samples_table}
                WHERE log_date = %s AND ip_address = %s
                ORDER BY id DESC
                LIMIT %d
            ) AS latest_samples
         )",
        current_time('Y-m-d'),
        $data['ip_address'],
        current_time('Y-m-d'),
        $data['ip_address'],
        MAC_CRAWLER_LOG_SAMPLES_LIMIT
    ));
}

function mac_sitemap_logs_write_current_request()
{
    $data = $GLOBALS['mac_sitemap_logs_current_request'] ?? null;
    if (!is_array($data)) return;

    global $wpdb;
    $table = $wpdb->prefix . 'sitemap_logs';
    $response_code = (int) http_response_code();
    if ($response_code < 100) $response_code = 200;

    $wpdb->insert($table, [
        'created_at' => current_time('mysql'),
        'sitemap_path' => $data['sitemap_path'],
        'request_uri' => $data['request_uri'],
        'request_method' => $data['request_method'],
        'response_code' => $response_code,
        'ip_address' => $data['ip_address'],
        'user_agent' => $data['user_agent'],
        'bot_name' => mac_sitemap_logs_detect_bot($data['user_agent']),
        'referer' => $data['referer'],
        'user_id' => $data['user_id'] > 0 ? $data['user_id'] : null,
    ], ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d']);
}

add_action('wp_ajax_mac_crawler_logs_details', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Access denied.'], 403);
    check_ajax_referer('mac_crawler_logs_details', 'nonce');

    $log_date = sanitize_text_field(wp_unslash($_POST['log_date'] ?? ''));
    $ip_address = sanitize_text_field(wp_unslash($_POST['ip_address'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $log_date) || !filter_var($ip_address, FILTER_VALIDATE_IP)) {
        wp_send_json_error(['message' => 'Некорректные параметры.'], 400);
    }

    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT created_at, request_uri, response_code, referer
         FROM {$wpdb->prefix}crawler_log_samples
         WHERE log_date = %s AND ip_address = %s
         ORDER BY id DESC
         LIMIT %d",
        $log_date,
        $ip_address,
        MAC_CRAWLER_LOG_SAMPLES_LIMIT
    ), ARRAY_A);

    wp_send_json_success(['rows' => $rows]);
});
