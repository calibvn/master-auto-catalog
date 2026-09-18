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
const MAC_SITE_PROTECTION_CENTRAL_AGENT_VERSION = '1.2.0';
const MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK = 'mac_site_protection_central_sync';

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
    $settings['rate_limit_count'] = max(30, (int)($remote['site_rate_limit'] ?? $settings['rate_limit_count']));
    $settings['xml_rate_limit_count'] = max(2, (int)($remote['xml_rate_limit'] ?? $settings['xml_rate_limit_count']));
    $settings['site_daily_limit'] = max(100, (int)($remote['site_daily_limit'] ?? $settings['site_daily_limit']));
    $settings['xml_daily_limit'] = max(5, (int)($remote['xml_daily_limit'] ?? $settings['xml_daily_limit']));
    $settings['rate_limit_minutes'] = max(1, (int)($remote['window_minutes'] ?? $settings['rate_limit_minutes']));
    $settings['xml_rate_limit_minutes'] = $settings['rate_limit_minutes'];
    $settings['protection_mode'] = !empty($remote['auto_block_enabled']) ? 'enforce' : 'monitor';
    $settings['unverified_bot_limit'] = max(10, (int) ($remote['unverified_bot_limit'] ?? $settings['unverified_bot_limit']));
    $settings['seo_bot_limit'] = max(10, (int) ($remote['seo_bot_limit'] ?? $settings['seo_bot_limit']));
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
        'xml_rate_limit_enabled' => '1', 'rate_limit_enabled' => '1',
        // Start new installations in observation mode. It records threshold
        // crossings but never returns 429 until an administrator enables it.
        'protection_mode' => 'monitor',
        'rate_limit_count' => '200', 'rate_limit_minutes' => '10',
        'xml_rate_limit_count' => '5', 'xml_rate_limit_minutes' => '10',
        'site_daily_limit' => '1000', 'xml_daily_limit' => '20',
        'unverified_bot_limit' => '60', 'seo_bot_limit' => '30',
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
function mac_site_protection_escalation_minutes($level) {
    return [
        1 => 10,
        2 => HOUR_IN_SECONDS / MINUTE_IN_SECONDS,
        3 => DAY_IN_SECONDS / MINUTE_IN_SECONDS,
        4 => 30 * DAY_IN_SECONDS / MINUTE_IN_SECONDS,
        5 => null,
    ][$level] ?? null;
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
function mac_site_protection_cached_official_request($ua, $ip) {
    $bot = mac_sitemap_logs_bot_key($ua);
    if (!in_array($bot, ['googlebot','bingbot','yandexbot'], true) || $ip === '') return false;
    return get_transient('mac_sp_verified_' . md5($bot . '|' . $ip)) === '1';
}

function mac_site_protection_is_official_request($ua, $ip = '') {
    $bot = mac_sitemap_logs_bot_key($ua);
    $suffixes = ['googlebot' => ['googlebot.com', 'google.com'], 'bingbot' => ['search.msn.com'], 'yandexbot' => ['yandex.ru', 'yandex.net']];
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

function mac_site_protection_is_meaningful_request($path) {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (preg_match('#^/(?:wp-admin|wp-login\.php|wp-cron\.php)(?:/|$)#i', $path)) return false;
    if (preg_match('#^/wp-json(?:/|$)#i', $path) || strpos($uri, 'rest_route=') !== false || strpos($uri, 'wc-ajax=') !== false) {
        return mac_site_protection_is_public_catalog_api($path, $uri);
    }
    return preg_match('/\.(?:css|js|map|jpe?g|png|gif|webp|svg|ico|woff2?|ttf|eot|mp4|webm|pdf|zip)$/i', $path) !== 1;
}

/**
 * Native WordPress search is intentionally outside the site-protection
 * counters. High search activity is useful to the catalogue and must not
 * create an intensive-crawler record or trigger an IP block.
 */
function mac_site_protection_is_store_products_collection($path) {
    $route = (string) ($_GET['rest_route'] ?? $path);
    if (preg_match('#^/wp-json#i', $route)) $route = substr($route, 8);
    return preg_match('#^/wc/store/v[0-9]+/products/?$#i', $route) === 1;
}

function mac_site_protection_is_public_catalog_api($path, $uri) {
    $route = (string) ($_GET['rest_route'] ?? $path);
    if (preg_match('#^/wp-json#i', $route)) $route = substr($route, 8);
    return isset($_GET['wc-ajax'])
        || preg_match('#^/wc/store(?:/v[0-9]+)?/products(?:/|$)#i', $route) === 1
        || preg_match('#^/wp/v[0-9]+/search(?:/|$)#i', $route) === 1;
}

function mac_site_protection_is_site_search_request($wp = null) {
    if (array_key_exists('s', $_GET)) return true;
    if (is_object($wp) && isset($wp->query_vars) && array_key_exists('s', (array) $wp->query_vars)) return true;

    $query = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    if ($query === '') return false;
    parse_str($query, $params);
    return array_key_exists('s', $params);
}

function mac_site_protection_honeypot_path() {
    $option = 'mac_site_protection_honeypot_path_v2';
    $path = (string) get_option($option, '');
    if (preg_match('#^/[a-z0-9][a-z0-9/_-]{12,120}\.(?:xml|json|txt|html|js)$#', $path)) {
        return $path;
    }

    // Generated once per site and kept in wp_options. Do not rotate this path:
    // robots.txt and the invisible link must always point to the same target.
    $folders = ['assets', 'content', 'data', 'files', 'media', 'resources', 'static'];
    $names = ['archive', 'catalog', 'feed', 'index', 'manifest', 'preview', 'resource'];
    $extensions = ['xml', 'json', 'txt', 'html', 'js'];
    $token = strtolower(wp_generate_password(14, false, false));
    $path = '/' . $folders[array_rand($folders)] . '/' . $token . '/' . $names[array_rand($names)] . '-' . substr($token, 0, 7) . '.' . $extensions[array_rand($extensions)];
    update_option($option, $path, false);
    return $path;
}

add_filter('robots_txt', function ($output, $public) {
    // Remove the legacy shared path on the first request after the update.
    $output = preg_replace('#^\s*Disallow:\s*/mac-crawler-trap/?\s*$#mi', '', (string) $output);
    return rtrim($output) . "\nUser-agent: *\nDisallow: " . mac_site_protection_honeypot_path() . "\n";
}, 10, 2);

add_action('wp_footer', function () {
    if (is_admin()) return;
    $path = mac_site_protection_honeypot_path();
    $marker = 'v-' . substr(hash('sha256', $path), 0, 12);
    echo '<script>document.cookie="mac_browser=1; path=/; max-age=2592000; SameSite=Lax";</script>';
    echo '<style>.' . esc_attr($marker) . '{position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden}</style>';
    echo '<a class="' . esc_attr($marker) . '" href="' . esc_url(home_url($path)) . '" rel="nofollow" aria-hidden="true" tabindex="-1">.</a>';
}, 999);

function mac_site_protection_browser_signal_score($ua) {
    $score = 0;
    if (empty($_COOKIE['mac_browser'])) $score += 1;
    if (trim((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) === '') $score += 1;
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (stripos($ua, 'mozilla/') !== false && $accept !== '' && strpos($accept, 'text/html') === false) $score += 1;
    return $score;
}

function mac_site_protection_increment_window($ip, $rule_key, $minutes, $max_hits) {
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

function mac_site_protection_handle_daily_threshold($subject, $rule_key, $count, $limit, $ua, $mode) {
    if ($mode !== 'enforce') {
        $event_key = mac_site_protection_state_key('mac_sp_daily_monitor', $subject . '|' . $rule_key);
        if (get_transient($event_key) === false) {
            set_transient($event_key, '1', HOUR_IN_SECONDS);
            mac_site_protection_log_event($subject, $rule_key, $count, $limit, 'monitor', $ua);
        }
        return;
    }
    if (!mac_site_protection_block($subject, 'Daily request limit', DAY_IN_SECONDS / MINUTE_IN_SECONDS)) {
        mac_site_protection_log_event($subject, $rule_key, $count, $limit, 'error', $ua);
        mac_site_protection_reject(60);
    }
    mac_site_protection_log_event($subject, $rule_key, $count, $limit, 'block', $ua);
    mac_site_protection_reject(DAY_IN_SECONDS);
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

function mac_site_protection_reject($retry_after, $message = 'Too many requests. Please try again later.') {
    mac_sitemap_logs_begin_request();
    mac_crawler_logs_begin_request();
    nocache_headers();
    status_header(429);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: ' . max(60, (int) $retry_after));
    echo esc_html($message);
    exit;
}

function mac_site_protection_handle_threshold($ip, $rule_key, $count, $limit, $minutes, $ua, $mode) {
    if ($mode !== 'enforce') {
        $event_key = mac_site_protection_state_key('mac_sp_event', $ip . '|' . $rule_key);
        if (get_transient($event_key) === false) {
            set_transient($event_key, '1', $minutes * MINUTE_IN_SECONDS);
            mac_site_protection_log_event($ip, $rule_key, $count, $limit, 'monitor', $ua);
        }
        return;
    }

    $event_key = mac_site_protection_state_key('mac_sp_block_event', $ip . '|' . $rule_key);
    if (get_transient($event_key) === false) {
        set_transient($event_key, '1', $minutes * MINUTE_IN_SECONDS);
        $level = mac_site_protection_register_incident($ip, $rule_key);
        $block_minutes = mac_site_protection_escalation_minutes($level);
        mac_site_protection_block($ip, 'Intensive access, level ' . $level, $block_minutes);
        mac_site_protection_log_event($ip, $rule_key, $count, $limit, 'block', $ua);
        mac_site_protection_reject($block_minutes === null ? DAY_IN_SECONDS : $block_minutes * MINUTE_IN_SECONDS, 'Too many requests. Please try again later.');
    }
    mac_site_protection_reject(60, 'Too many requests. Please try again later.');
}

function mac_site_protection_enforce_v2($wp = null) {
    if (is_admin() || (is_user_logged_in() && current_user_can('manage_options'))) return;
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) return;
    $requestPath = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (mac_site_protection_is_site_search_request($wp) && !mac_site_protection_is_public_catalog_api($requestPath, (string) ($_SERVER['REQUEST_URI'] ?? ''))) return;

    $ip = mac_site_protection_client_ip();
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ip === '' || mac_site_protection_whitelisted($ip, $ua)) return;

    // Do not run a database UPDATE on every request just to expire old rows.
    if (!get_transient('mac_sp_expire_checked')) {
        mac_site_protection_expire_blocks();
        set_transient('mac_sp_expire_checked', '1', 5 * MINUTE_IN_SECONDS);
    }
    if (mac_site_protection_blocked($ip)) {
        mac_site_protection_record_block_hit($ip);
        mac_site_protection_reject(3600, 'Access temporarily restricted. Please try again later.');
    }

    $s = mac_site_protection_settings();
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $is_xml = mac_sitemap_logs_is_xml_request($path);
    $class = mac_site_protection_traffic_class($ua, $ip);
    if ($class === 'official') return;

    if (mac_site_protection_is_store_products_collection($path)) {
        $requestedPageSize = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
        if ($requestedPageSize > 20) {
            nocache_headers();
            status_header(400);
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode(['code' => 'catalog_page_limit', 'message' => 'Maximum 20 products per page.']);
            exit;
        }
        $minutes = 10;
        $limit = 12;
        $subject = mac_site_protection_subject($ip, $class);
        $daily_limit = max(100, (int) ($s['site_daily_limit'] ?? 1000));
        $daily_count = mac_site_protection_increment_daily_window($subject, 'site_daily_auto');
        if ($daily_count > $daily_limit) mac_site_protection_handle_daily_threshold($subject, 'site_daily_auto', $daily_count, $daily_limit, $ua, $s['protection_mode']);
        $count = mac_site_protection_increment_window($subject, 'catalog_api_rate', $minutes, $limit + 1);
        if ($count > $limit) mac_site_protection_handle_threshold($subject, 'catalog_api_rate', $count, $limit, $minutes, $ua, $s['protection_mode']);
        return;
    }

    if (untrailingslashit($path) === untrailingslashit(mac_site_protection_honeypot_path())) {
        $subject = mac_site_protection_subject($ip, 'honeypot');
        mac_site_protection_log_event($subject, 'honeypot', 1, 0, $s['protection_mode'] === 'enforce' ? 'block' : 'monitor', $ua);
        if ($s['protection_mode'] === 'enforce') {
            $minutes = 7 * DAY_IN_SECONDS / MINUTE_IN_SECONDS;
            mac_site_protection_block($subject, 'Service URL access', $minutes);
            nocache_headers(); status_header(403); header('Content-Type: text/plain; charset=utf-8'); echo 'Access denied.'; exit;
        }
        return;
    }

    if ($is_xml && $s['xml_rate_limit_enabled'] === '1') {
        $minutes = max(1, (int) $s['xml_rate_limit_minutes']);
        $limit = max(2, (int) $s['xml_rate_limit_count']);
        $rule_key = 'xml_rate_auto';
        $subject = mac_site_protection_subject($ip, $class);
        $daily_limit = max(5, (int) ($s['xml_daily_limit'] ?? 20));
        $daily_count = mac_site_protection_increment_daily_window($subject, 'xml_daily_auto');
        if ($daily_count > $daily_limit) mac_site_protection_handle_daily_threshold($subject, 'xml_daily_auto', $daily_count, $daily_limit, $ua, $s['protection_mode']);
        $count = mac_site_protection_increment_window($subject, $rule_key, $minutes, $limit + 1);
        if ($count > $limit) {
            mac_site_protection_handle_threshold($subject, $rule_key, $count, $limit, $minutes, $ua, $s['protection_mode']);
            if ($s['protection_mode'] !== 'enforce') return;
        }
        return;
    }

    if ($s['rate_limit_enabled'] !== '1' || $is_xml || !mac_site_protection_is_meaningful_request($path)) return;
    $minutes = max(1, (int) $s['rate_limit_minutes']);
    $limit = max(30, (int) $s['rate_limit_count']);
    if ($class === 'unverified_bot') $limit = max(10, (int) ($s['unverified_bot_limit'] ?? $limit));
    if ($class === 'seo') $limit = max(10, (int) ($s['seo_bot_limit'] ?? $limit));
    if ($class === 'suspicious_browser') $limit = min($limit, max(10, (int) ($s['unverified_bot_limit'] ?? $limit)));
    $rule_key = 'site_rate_auto';
    $subject = mac_site_protection_subject($ip, $class);
    $daily_limit = max(100, (int) ($s['site_daily_limit'] ?? 1000));
    $daily_count = mac_site_protection_increment_daily_window($subject, 'site_daily_auto');
    if ($daily_count > $daily_limit) mac_site_protection_handle_daily_threshold($subject, 'site_daily_auto', $daily_count, $daily_limit, $ua, $s['protection_mode']);
    $count = mac_site_protection_increment_window($subject, $rule_key, $minutes, $limit + 1);
    if ($count <= $limit) return;
    mac_site_protection_handle_threshold($subject, $rule_key, $count, $limit, $minutes, $ua, $s['protection_mode']);
}
add_action('parse_request', 'mac_site_protection_enforce_v2', -1);

add_action('admin_post_mac_site_protection_save', function () {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    check_admin_referer('mac_site_protection_save');
    update_option(MAC_SITE_PROTECTION_OPTION, [
        'xml_rate_limit_enabled' => isset($_POST['xml_rate_limit_enabled']) ? '1' : '0', 'rate_limit_enabled' => isset($_POST['rate_limit_enabled']) ? '1' : '0',
        'protection_mode' => ($_POST['protection_mode'] ?? 'monitor') === 'enforce' ? 'enforce' : 'monitor',
        'rate_limit_count' => max(30, (int) ($_POST['rate_limit_count'] ?? 300)), 'rate_limit_minutes' => max(1, (int) ($_POST['rate_limit_minutes'] ?? 10)), 'xml_rate_limit_count' => max(2, (int) ($_POST['xml_rate_limit_count'] ?? 30)), 'xml_rate_limit_minutes' => max(1, (int) ($_POST['xml_rate_limit_minutes'] ?? 10)),
        'ip_whitelist' => sanitize_textarea_field(wp_unslash($_POST['ip_whitelist'] ?? '')), 'ua_whitelist' => sanitize_textarea_field(wp_unslash($_POST['ua_whitelist'] ?? '')),
        'telegram_topic_id' => preg_replace('/[^0-9]/', '', (string) ($_POST['telegram_topic_id'] ?? '27659')),
    ], false);
    wp_safe_redirect(admin_url('admin.php?page=mac-site-protection&saved=1')); exit;
});

function mac_site_protection_page_v2() {
    if (!current_user_can('manage_options')) return;
    // Decisions and long-term history are intentionally centralised. A local
    // site is only an enforcement agent, so it shows operational state instead
    // of a second dashboard with its own controls and statistics.
    global $wpdb;
    mac_site_protection_expire_blocks();
    $settings = mac_site_protection_settings();
    $blocks = $wpdb->get_results("SELECT ip_address, reason, created_at, expires_at FROM {$wpdb->prefix}site_protection_blocks WHERE is_active=1 ORDER BY id DESC", ARRAY_A);
    $config = mac_site_protection_central_config();
    $syncStatus = (array) get_option('mac_site_protection_central_last_sync', []);
    $syncStateLabels = [
        'success' => 'Связь с центром установлена',
        'not_configured' => 'Подключение не настроено',
        'request_error' => 'Ошибка соединения с центром',
        'http_error' => 'Центр вернул ошибку HTTP',
        'invalid_response' => 'Некорректный ответ центра',
    ];
    ?>
    <div class="wrap mac-protection-content">
        <div class="mac-section-title"><h1>Защита сайта</h1><p>Сайт работает как исполнитель: история и ручные решения находятся в центральном сайте; очередь проверяется примерно раз в минуту при работающем WP-Cron.</p></div>
        <?php if (false): // Diagnostics remain stored for troubleshooting, but are not part of the executor UI. ?>
        <section class="mac-protection-panel"><div class="mac-panel-head"><h2>Подключение к центру</h2></div><p><?php echo $config['url'] !== '' && $config['api_key'] !== '' ? 'Подключено: ' . esc_html($config['url']) : 'Не настроено. Заполните адрес центра и API key в «Синхронизация с центром».'; ?></p><?php if ($syncStatus): ?><p><strong>Последняя синхронизация:</strong> <?php echo esc_html((string) ($syncStatus['attempted_at'] ?? '—')); ?><br><strong>Результат:</strong> <?php echo esc_html($syncStateLabels[(string) ($syncStatus['state'] ?? '')] ?? 'Неизвестно'); ?><?php if (!empty($syncStatus['http_code'])): ?> (HTTP <?php echo (int) $syncStatus['http_code']; ?>)<?php endif; ?><?php if (!empty($syncStatus['received_commands'])): ?><br><strong>Команды от центра:</strong> <?php echo esc_html(implode(', ', array_map('intval', (array) $syncStatus['received_commands']))); ?><?php endif; ?><?php if (!empty($syncStatus['command_results'])): ?><br><strong>Применение:</strong> <?php foreach ((array) $syncStatus['command_results'] as $result): ?><?php echo esc_html('#' . (int) ($result['id'] ?? 0) . ': ' . (!empty($result['ok']) ? 'успешно' : 'ошибка') . (!empty($result['message']) ? ' — ' . (string) $result['message'] : '') . ' '); ?><?php endforeach; ?><?php endif; ?><?php if (!empty($syncStatus['ack_state']) && $syncStatus['ack_state'] !== 'not_required'): ?><br><strong>Подтверждение центру:</strong> <?php echo esc_html($syncStatus['ack_state'] === 'confirmed' ? 'отправлено' : (string) $syncStatus['ack_state']); ?><?php endif; ?><?php if (!empty($syncStatus['error'])): ?><br><strong>Ошибка:</strong> <?php echo esc_html(wp_trim_words((string) $syncStatus['error'], 30, '…')); ?><?php endif; ?></p><?php endif; ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('mac_site_protection_sync_now'); ?><input type="hidden" name="action" value="mac_site_protection_sync_now"><button type="submit" class="button">Проверить команды сейчас</button></form></section>
        <?php endif; ?>
        <section class="mac-protection-panel"><div class="mac-panel-head"><h2>WhiteList IP</h2></div><p><?php echo $settings['ip_whitelist'] !== '' ? nl2br(esc_html($settings['ip_whitelist'])) : 'Пусто'; ?></p></section>
        <section class="mac-protection-panel"><div class="mac-panel-head"><h2>Активные блокировки</h2></div><table class="widefat striped"><thead><tr><th>IP / сеть</th><th>Причина</th><th>Создана</th><th>До</th><th>Отклонено</th><th>Последняя попытка</th></tr></thead><tbody><?php if ($blocks): foreach ($blocks as $block): ?><tr><td><?php echo esc_html($block['ip_address']); ?></td><td><?php echo esc_html($block['reason']); ?></td><td><?php echo esc_html($block['created_at']); ?></td><td><?php echo esc_html($block['expires_at'] ?: 'Навсегда'); ?></td><td><?php echo number_format_i18n((int) ($block['blocked_hits'] ?? 0)); ?></td><td><?php echo esc_html($block['last_blocked_at'] ?: '—'); ?></td></tr><?php endforeach; else: ?><tr><td colspan="6">Активных блокировок нет.</td></tr><?php endif; ?></tbody></table></section>
        <section class="mac-protection-panel mac-protection-guide"><div class="mac-panel-head"><h2>Как работает защита</h2></div><p>Сайт исполняет правила из центра и сам не создаёт постоянных ручных решений. При блокировке он отвечает <code>429 Too Many Requests</code>; счётчики в таблице показывают только новые отклонённые запросы после установки этой версии.</p><h3>Причины блокировок</h3><ul><li><strong>Массовая блокировка по отчёту защиты</strong> или <strong>Ручное решение из центра</strong> — решение администратора из центрального сайта. Срок указан в столбце «До».</li><li><strong>Превышен лимит запросов</strong> — интенсивные обращения к обычным страницам. Лимит и срок задаются в центре; повторные превышения усиливают блокировку: 10 минут → 1 час → 1 день → 1 месяц → навсегда.</li><li><strong>Превышен лимит XML</strong> — слишком частые запросы к XML-картам сайта. Для него действует отдельный, более низкий лимит из центра.</li><li><strong>Honeypot crawler trap</strong> — запрос к уникальному для этого сайта скрытому пути, запрещённому в robots.txt. Это признак парсера, игнорирующего robots.txt и скрытые ссылки; срок такой блокировки — 7 дней.</li></ul><h3>Схема работы</h3><ol><li>Сначала проверяются WhiteList и уже активные блокировки.</li><li>Поисковые запросы сайта не учитываются и не ограничиваются.</li><li>Официальные Google, Bing и Яндекс не ограничиваются после проверки происхождения.</li><li>Остальные запросы проходят лимиты страниц и XML-карт; срабатывания и агрегированная статистика отправляются в центр.</li><li>Центр выдаёт ручные решения и лимиты, а сайт проверяет очередь примерно раз в минуту при работающем WP-Cron.</li></ol></section>
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

add_action('admin_post_mac_site_protection_reset', function () {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    check_admin_referer('mac_site_protection_reset');
    global $wpdb;
    foreach (['site_protection_blocks', 'site_protection_incidents', 'site_protection_events', 'crawler_log_samples', 'crawler_logs'] as $suffix) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}{$suffix}");
    }
    update_option(MAC_SITE_PROTECTION_STATE_VERSION_OPTION, mac_site_protection_state_version() + 1, false);
    $settings = mac_site_protection_settings();
    $settings['protection_mode'] = 'monitor';
    update_option(MAC_SITE_PROTECTION_OPTION, $settings, false);
    set_transient('mac_site_protection_reset_result_' . get_current_user_id(), '1', MINUTE_IN_SECONDS);
    wp_safe_redirect(admin_url('admin.php?page=mac-site-protection&reset=1'));
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
