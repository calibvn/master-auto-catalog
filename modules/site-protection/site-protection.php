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
const MAC_SITE_PROTECTION_CENTRAL_AGENT_VERSION = '1.1.0';
const MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK = 'mac_site_protection_central_sync';

add_filter('cron_schedules', function ($schedules) {
    $schedules['mac_five_minutes'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every five minutes'];
    return $schedules;
});

add_action('init', function () {
    if (!wp_next_scheduled(MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK)) {
        wp_schedule_event(time() + 120, 'mac_five_minutes', MAC_SITE_PROTECTION_CENTRAL_SYNC_HOOK);
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
        return [true, 'Блокировка снята'];
    }

    $expiresAt = trim((string) ($command['expires_at'] ?? ''));
    $wpdb->update($wpdb->prefix . 'site_protection_blocks', ['is_active' => 0], ['ip_address' => $subject], ['%d'], ['%s']);
    $wpdb->insert($wpdb->prefix . 'site_protection_blocks', [
        'ip_address' => $subject,
        'reason' => substr(trim((string) ($command['reason'] ?? 'Решение центра')), 0, 100),
        'created_at' => current_time('mysql'),
        'expires_at' => $expiresAt !== '' ? $expiresAt : null,
        'is_active' => 1,
    ], ['%s', '%s', '%s', '%s', '%d']);
    delete_transient(mac_site_protection_state_key('mac_sp_blocked', $subject));
    return [true, $expiresAt === '' ? 'Заблокирован навсегда' : 'Заблокирован до ' . $expiresAt];
}

function mac_site_protection_central_apply_settings(array $remote) {
    $settings = mac_site_protection_settings();
    $settings['rate_limit_count'] = max(30, (int)($remote['site_rate_limit'] ?? $settings['rate_limit_count']));
    $settings['xml_rate_limit_count'] = max(2, (int)($remote['xml_rate_limit'] ?? $settings['xml_rate_limit_count']));
    $settings['rate_limit_minutes'] = max(1, (int)($remote['window_minutes'] ?? $settings['rate_limit_minutes']));
    $settings['xml_rate_limit_minutes'] = $settings['rate_limit_minutes'];
    $settings['protection_mode'] = !empty($remote['auto_block_enabled']) ? 'enforce' : 'monitor';
    update_option(MAC_SITE_PROTECTION_OPTION, $settings, false);
}

function mac_site_protection_central_sync() {
    $config = mac_site_protection_central_config();
    if ($config['url'] === '' || $config['api_key'] === '') return;

    global $wpdb;
    $eventsTable = $wpdb->prefix . 'site_protection_events';
    $sitemapTable = $wpdb->prefix . 'sitemap_logs';
    $crawlerTable = $wpdb->prefix . 'crawler_logs';
    $eventsRows = $wpdb->get_results("SELECT * FROM {$eventsTable} ORDER BY id ASC LIMIT 200", ARRAY_A);
    $sitemapRows = $wpdb->get_results("SELECT * FROM {$sitemapTable} ORDER BY id ASC LIMIT 200", ARRAY_A);
    $crawlerRows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$crawlerTable} WHERE log_date >= %s AND request_count >= %d ORDER BY id ASC LIMIT 300", wp_date('Y-m-d', time() - DAY_IN_SECONDS), MAC_CRAWLER_LOGS_DAILY_THRESHOLD), ARRAY_A);
    $acks = (array) get_option('mac_site_protection_central_acks', []);
    $vinApiEvents = array_slice((array) get_option('mac_vin_provider_health_queue', []), 0, 50);
    $payload = ['agent_version' => MAC_SITE_PROTECTION_CENTRAL_AGENT_VERSION, 'events' => [], 'sitemap_logs' => [], 'crawler_daily' => [], 'vin_api_events' => $vinApiEvents, 'command_acks' => $acks];

    foreach ($eventsRows as $row) {
        $payload['events'][] = ['source_key' => 'event-' . (int) $row['id'], 'occurred_at' => $row['created_at'], 'ip_address' => $row['ip_address'], 'subject' => mac_site_protection_central_row_subject($row['ip_address'], $row['user_agent']), 'rule_key' => $row['rule_key'], 'request_count' => (int) $row['request_count'], 'threshold_count' => (int) $row['threshold_count'], 'action_taken' => $row['action_taken'], 'request_uri' => $row['request_uri'], 'user_agent' => $row['user_agent']];
    }
    foreach ($sitemapRows as $row) {
        $payload['sitemap_logs'][] = ['source_key' => 'sitemap-' . (int) $row['id'], 'occurred_at' => $row['created_at'], 'ip_address' => $row['ip_address'], 'subject' => mac_site_protection_central_row_subject($row['ip_address'], $row['user_agent']), 'sitemap_path' => $row['sitemap_path'], 'request_uri' => $row['request_uri'], 'bot_name' => $row['bot_name'], 'response_code' => (int) $row['response_code'], 'referer' => $row['referer'], 'user_agent' => $row['user_agent']];
    }
    foreach ($crawlerRows as $row) {
        $payload['crawler_daily'][] = ['log_date' => $row['log_date'], 'ip_address' => $row['ip_address'], 'subject' => mac_site_protection_central_row_subject($row['ip_address'], $row['user_agent']), 'bot_name' => $row['bot_name'], 'request_count' => (int) $row['request_count'], 'first_seen' => $row['first_seen'], 'last_seen' => $row['last_seen'], 'last_request_uri' => $row['last_request_uri'], 'last_response_code' => (int) $row['last_response_code'], 'user_agent' => $row['user_agent']];
    }

    $response = wp_remote_post($config['url'] . '/api/protection.php', ['timeout' => 20, 'headers' => ['Content-Type' => 'application/json', 'X-API-Key' => $config['api_key']], 'body' => wp_json_encode($payload)]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return;
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['success'])) return;

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
    $newAcks = [];
    foreach ((array) ($body['commands'] ?? []) as $command) {
        [$ok, $message] = mac_site_protection_central_apply_command((array) $command);
        $newAcks[] = ['command_id' => (int) ($command['id'] ?? 0), 'ok' => $ok, 'message' => $message];
    }
    update_option('mac_site_protection_central_acks', $newAcks, false);
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
        'ip_whitelist' => '', 'ua_whitelist' => 'MasterAutoCentr/DeletedVinExport', 'telegram_topic_id' => '27659',
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
function mac_site_protection_blocked($ip) {
    global $wpdb;
    $network = mac_site_protection_ipv6_network($ip);
    $subjects = array_values(array_unique(array_filter([$ip, $network])));
    // IPv6 may be checked against both the exact address and its /64. Do not
    // cache that mixed result, otherwise an exact manual block could leak to
    // the whole subnet (or a stale false result could delay a new /64 block).
    if ($network !== '') {
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}site_protection_blocks WHERE ip_address IN (" . implode(',', array_fill(0, count($subjects), '%s')) . ") AND is_active = 1 AND (expires_at IS NULL OR expires_at > %s) LIMIT 1", ...array_merge($subjects, [current_time('mysql')])));
    }
    $key = mac_site_protection_state_key('mac_sp_blocked', implode('|', $subjects));
    $cached = get_transient($key);
    if ($cached !== false) return $cached === '1';
    $blocked = (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}site_protection_blocks WHERE ip_address IN (" . implode(',', array_fill(0, count($subjects), '%s')) . ") AND is_active = 1 AND (expires_at IS NULL OR expires_at > %s) LIMIT 1", ...array_merge($subjects, [current_time('mysql')])));
    set_transient($key, $blocked ? '1' : '0', MINUTE_IN_SECONDS);
    return $blocked;
}
function mac_site_protection_expire_blocks() {
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}site_protection_blocks SET is_active = 0 WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at <= %s", current_time('mysql')));
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
    $today = current_time('Y-m-d'); $from = wp_date('Y-m-d', current_time('timestamp') - 30 * DAY_IN_SECONDS);
    $table = $wpdb->prefix . 'site_protection_incidents';
    $wpdb->insert($table, ['ip_address'=>$ip,'rule_key'=>$rule_key,'incident_date'=>$today,'created_at'=>current_time('mysql'),'level'=>1], ['%s','%s','%s','%s','%d']);
    $incidents = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ip_address=%s AND rule_key=%s AND incident_date >= %s", $ip, $rule_key, $from));
    return min(5, max(1, $incidents));
}
function mac_site_protection_block($ip, $reason, $minutes) {
    global $wpdb;
    $expires_at = $minutes === null ? null : wp_date('Y-m-d H:i:s', time() + max(1, (int) $minutes) * MINUTE_IN_SECONDS);
    $already_blocked = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}site_protection_blocks WHERE ip_address = %s AND is_active = 1 AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
        $ip,
        current_time('mysql')
    ));
    if ($already_blocked) return;
    $wpdb->insert($wpdb->prefix . 'site_protection_blocks', ['ip_address' => $ip, 'reason' => $reason, 'created_at' => current_time('mysql'), 'expires_at' => $expires_at, 'is_active' => 1], ['%s','%s','%s','%s','%d']);
    delete_transient(mac_site_protection_state_key('mac_sp_blocked', implode('|', array_values(array_unique(array_filter([$ip, mac_site_protection_ipv6_network($ip)]))))));
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
        $resolved = @gethostbynamel($host) ?: [];
        $valid = in_array($ip, $resolved, true);
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
    return $bot === 'visitor' ? 'visitor' : 'unverified_bot';
}

function mac_site_protection_is_meaningful_request($path) {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (strpos($uri, 'wc-ajax=') !== false || strpos($uri, 'rest_route=') !== false) return false;
    if (preg_match('#^/(?:wp-admin|wp-login\.php|wp-cron\.php|wp-json)(?:/|$)#i', $path)) return false;
    return preg_match('/\.(?:css|js|map|jpe?g|png|gif|webp|svg|ico|woff2?|ttf|eot|mp4|webm|pdf|zip)$/i', $path) !== 1;
}

/**
 * Native WordPress search is intentionally outside the site-protection
 * counters. High search activity is useful to the catalogue and must not
 * create an intensive-crawler record or trigger an IP block.
 */
function mac_site_protection_is_site_search_request($wp = null) {
    if (array_key_exists('s', $_GET)) return true;
    if (is_object($wp) && isset($wp->query_vars) && array_key_exists('s', (array) $wp->query_vars)) return true;

    $query = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    if ($query === '') return false;
    parse_str($query, $params);
    return array_key_exists('s', $params);
}

function mac_site_protection_honeypot_path() {
    return '/mac-crawler-trap/';
}

add_filter('robots_txt', function ($output, $public) {
    return rtrim((string) $output) . "\nUser-agent: *\nDisallow: " . mac_site_protection_honeypot_path() . "\n";
}, 10, 2);

add_action('wp_footer', function () {
    if (is_admin()) return;
    echo '<script>document.cookie="mac_browser=1; path=/; max-age=2592000; SameSite=Lax";</script>';
    echo '<a href="' . esc_url(home_url(mac_site_protection_honeypot_path())) . '" rel="nofollow" aria-hidden="true" tabindex="-1" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">.</a>';
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
    // One small transient per IP/rule. The stored timestamps make the window
    // rolling rather than aligned to an arbitrary ten-minute boundary.
    $key = mac_site_protection_state_key('mac_sp_window', $ip . '|' . $rule_key);
    $now = time();
    $cutoff = $now - $minutes * MINUTE_IN_SECONDS;
    $hits = get_transient($key);
    $hits = is_array($hits) ? array_values(array_filter($hits, static function ($time) use ($cutoff) { return (int) $time > $cutoff; })) : [];
    $hits[] = $now;
    $max_hits = max(2, (int) $max_hits);
    set_transient($key, array_slice($hits, -$max_hits), $minutes * MINUTE_IN_SECONDS + MINUTE_IN_SECONDS);
    return count($hits);
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
    if (mac_site_protection_is_site_search_request($wp)) return;

    $ip = mac_site_protection_client_ip();
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ip === '' || mac_site_protection_whitelisted($ip, $ua)) return;

    // Do not run a database UPDATE on every request just to expire old rows.
    if (!get_transient('mac_sp_expire_checked')) {
        mac_site_protection_expire_blocks();
        set_transient('mac_sp_expire_checked', '1', 5 * MINUTE_IN_SECONDS);
    }
    if (mac_site_protection_blocked($ip)) mac_site_protection_reject(3600, 'Access temporarily restricted. Please try again later.');

    $s = mac_site_protection_settings();
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $is_xml = mac_sitemap_logs_is_xml_request($path);
    $class = mac_site_protection_traffic_class($ua, $ip);
    if ($class === 'official') return;

    if (untrailingslashit($path) === untrailingslashit(mac_site_protection_honeypot_path())) {
        $subject = mac_site_protection_subject($ip, 'honeypot');
        mac_site_protection_log_event($subject, 'honeypot', 1, 0, $s['protection_mode'] === 'enforce' ? 'block' : 'monitor', $ua);
        if ($s['protection_mode'] === 'enforce') {
            $minutes = 7 * DAY_IN_SECONDS / MINUTE_IN_SECONDS;
            mac_site_protection_block($subject, 'Honeypot crawler trap', $minutes);
            nocache_headers(); status_header(403); header('Content-Type: text/plain; charset=utf-8'); echo 'Access denied.'; exit;
        }
        return;
    }

    if ($is_xml && $s['xml_rate_limit_enabled'] === '1') {
        $minutes = max(1, (int) $s['xml_rate_limit_minutes']);
        $limit = max(2, (int) $s['xml_rate_limit_count']);
        $rule_key = 'xml_rate_auto';
        $subject = mac_site_protection_subject($ip, $class);
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
    $rule_key = 'site_rate_auto';
    $subject = mac_site_protection_subject($ip, $class);
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
    ?>
    <div class="wrap mac-protection-content">
        <div class="mac-section-title"><h1>Защита сайта</h1><p>Сайт работает как исполнитель: история и ручные решения находятся в центральном сайте; очередь проверяется примерно раз в 5 минут.</p></div>
        <section class="mac-protection-panel"><div class="mac-panel-head"><h2>Подключение к центру</h2></div><p><?php echo $config['url'] !== '' && $config['api_key'] !== '' ? 'Подключено: ' . esc_html($config['url']) : 'Не настроено. Заполните адрес центра и API key в «Синхронизация с центром».'; ?></p></section>
        <section class="mac-protection-panel"><div class="mac-panel-head"><h2>WhiteList IP</h2></div><p><?php echo $settings['ip_whitelist'] !== '' ? nl2br(esc_html($settings['ip_whitelist'])) : 'Пусто'; ?></p></section>
        <section class="mac-protection-panel"><div class="mac-panel-head"><h2>Активные блокировки</h2></div><table class="widefat striped"><thead><tr><th>IP / сеть</th><th>Причина</th><th>Создана</th><th>До</th></tr></thead><tbody><?php if ($blocks): foreach ($blocks as $block): ?><tr><td><?php echo esc_html($block['ip_address']); ?></td><td><?php echo esc_html($block['reason']); ?></td><td><?php echo esc_html($block['created_at']); ?></td><td><?php echo esc_html($block['expires_at'] ?: 'Навсегда'); ?></td></tr><?php endforeach; else: ?><tr><td colspan="4">Активных блокировок нет.</td></tr><?php endif; ?></tbody></table></section>
    </div>
    <?php
    return;
    $s = mac_site_protection_settings(); ?>
    <div class="mac-protection-content">
        <?php if (isset($_GET['reset'])): ?><div class="notice notice-success is-dismissible"><p>Состояние защиты и журнал интенсивных обходов сброшены. Режим переключён на «Только логировать».</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mac-protection-settings">
            <?php wp_nonce_field('mac_site_protection_save'); ?><input type="hidden" name="action" value="mac_site_protection_save">
            <section class="mac-setting-card"><h2>Режим работы</h2><label>Защита<select name="protection_mode"><option value="monitor" <?php selected($s['protection_mode'], 'monitor'); ?>>Только логировать</option><option value="enforce" <?php selected($s['protection_mode'], 'enforce'); ?>>Блокировать по правилам</option></select></label><p class="description">Начните с режима «Только логировать» на 7 дней. В нём видны превышения, но посетители не получают 429.</p></section>
            <section class="mac-setting-card"><h2>Порог обходов страниц</h2><div class="mac-inline-fields"><label>Запросов<input type="number" name="rate_limit_count" value="<?php echo esc_attr($s['rate_limit_count']); ?>" min="30"></label><label>Окно, минут<input type="number" name="rate_limit_minutes" value="<?php echo esc_attr($s['rate_limit_minutes']); ?>" min="1"></label></div><p class="description">Для всех неофициальных источников действует единый порог. Официальные Google, Bing и Яндекс после DNS-проверки не ограничиваются.</p></section>
            <section class="mac-setting-card"><h2>Правила XML</h2><label class="mac-switch-row"><input type="checkbox" name="xml_rate_limit_enabled" <?php checked($s['xml_rate_limit_enabled'], '1'); ?>><span>Ограничивать интенсивный просмотр XML-карт</span></label><div class="mac-inline-fields"><label>XML запросов<input type="number" name="xml_rate_limit_count" value="<?php echo esc_attr($s['xml_rate_limit_count']); ?>" min="2"></label><label>За минут<input type="number" name="xml_rate_limit_minutes" value="<?php echo esc_attr($s['xml_rate_limit_minutes']); ?>" min="1"></label></div><label class="mac-switch-row"><input type="checkbox" name="rate_limit_enabled" <?php checked($s['rate_limit_enabled'], '1'); ?>><span>Включить защиту интенсивных обходов</span></label><p class="description">При синхронизации с центром эти значения и режим защиты управляются из центра. Лестница блокировок: 10 минут → 1 час → 1 день → 1 месяц → навсегда.</p></section>
            <section class="mac-setting-card"><h2>Исключения и отчёты</h2><label>Topic ID Telegram<input type="text" name="telegram_topic_id" value="<?php echo esc_attr($s['telegram_topic_id']); ?>"></label><label>WhiteList IP<textarea name="ip_whitelist" rows="4"><?php echo esc_textarea($s['ip_whitelist']); ?></textarea></label><label>WhiteList User-Agent<textarea name="ua_whitelist" rows="4"><?php echo esc_textarea($s['ua_whitelist']); ?></textarea></label></section>
            <div class="mac-settings-save"><button class="button button-primary">Сохранить изменения</button></div>
        </form>
        <?php mac_site_protection_render_blocks(); mac_site_protection_render_events(); mac_site_protection_render_xml_blocks(); mac_sitemap_logs_render_admin_table(); mac_crawler_logs_render_admin_table(); ?>
        <section class="mac-help mac-protection-guide"><h2>Как использовать</h2><ol><li><strong>Режим работы.</strong> Начните с «Только логировать» на 7 дней. Таблица «Срабатывания защиты» покажет превышения без ответов 429. Затем включайте «Блокировать по правилам» только после проверки порогов.</li><li><strong>Пороги.</strong> Отдельно задаются для посетителей, неофициальных ботов и SEO-краулеров. Google, Bing и Яндекс не ограничиваются только после DNS-подтверждения. Первое превышение в боевом режиме даёт throttle на 5 минут; повторные нарушения усиливают временную блокировку.</li><li><strong>Расшифровка правил.</strong> <code>xml_rate</code> — слишком частые открытия XML-карт; <code>site_visitor</code> — интенсивный обход с признаками обычного браузера; <code>site_suspicious_browser</code> — браузерный User-Agent, но отсутствуют минимум два сигнала реального браузера (cookie, Accept-Language, корректный Accept); <code>site_seo</code> — Ahrefs, Semrush или MJ12; <code>site_unverified_bot</code> — бот/сканер, который не прошёл подтверждение как официальный; <code>honeypot</code> — открыта скрытая ссылка, запрещённая в robots.txt.</li><li><strong>IPv6.</strong> Для <code>site_suspicious_browser</code>, <code>site_unverified_bot</code> и <code>honeypot</code> адреса одной IPv6-подсети /64 считаются единым источником. Это не даёт парсеру обходить лимит простой сменой последнего сегмента IP.</li><li><strong>Логи карты сайта.</strong> Здесь каждый просмотр XML-карты: дата, бот, URL, IP, код ответа, referer и User-Agent. Цветная точка у IP отражает тип запроса. «Ограниченные XML-карты» — только запросы с 403/429.</li><li><strong>Интенсивные обходы.</strong> Одна строка — один IP за день с 100+ запросами. Кнопка с глазом открывает 15 последних URL и статусов. Это журнал наблюдения, а не автоматический приговор.</li><li><strong>Блокировки и WhiteList.</strong> WL↺ снимает все блокировки IP, обнуляет историю инцидентов и добавляет IP в WhiteList. Кнопки 1ч, 24ч и ∞ — только ручные действия.</li><li><strong>Когда нужен WAF.</strong> Если блокировки стабильно выше 100 в час, сайт заметно замедляется или атака идёт множеством IP, переносите защиту в Cloudflare/WAF или Nginx. WordPress видит запрос только после запуска PHP.</li></ol></section>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mac-protection-reset">
            <?php wp_nonce_field('mac_site_protection_reset'); ?><input type="hidden" name="action" value="mac_site_protection_reset">
            <strong>Начать наблюдение с нуля</strong><span>Удалит блокировки, историю инцидентов, срабатывания и интенсивные обходы. Логи карт сайта и поиска останутся.</span><button class="button" type="submit" onclick="return confirm('Сбросить состояние защиты и журналы интенсивных обходов? Активные и ручные блокировки тоже будут сняты.');">Сбросить состояние защиты</button>
        </form>
        <script>
        document.querySelectorAll('.mac-protection-content .widefat td').forEach(function (cell) {
            if (cell.querySelector('button,form,a,input')) return;
            var value = cell.textContent.trim(); if (!value || value === '—') return;
            cell.classList.add('mac-copyable'); cell.title = value;
            cell.addEventListener('click', function () {
                if (!navigator.clipboard) return;
                navigator.clipboard.writeText(value).then(function () { cell.classList.add('mac-copied'); setTimeout(function () { cell.classList.remove('mac-copied'); }, 700); });
            });
        });
        document.addEventListener('click', function (event) {
            var link = event.target.closest('.mac-pagination a[data-mac-page]');
            if (!link) return;
            event.preventDefault();
            var panelName = link.dataset.macPage;
            var currentPanel = document.querySelector('[data-mac-panel="' + panelName + '"]');
            if (!currentPanel) return;
            currentPanel.classList.add('is-loading');
            fetch(link.href, {credentials: 'same-origin'}).then(function (response) { return response.text(); }).then(function (html) {
                var nextDocument = new DOMParser().parseFromString(html, 'text/html');
                var nextPanel = nextDocument.querySelector('[data-mac-panel="' + panelName + '"]');
                if (!nextPanel) throw new Error('Panel not found');
                currentPanel.replaceWith(nextPanel);
                history.replaceState({}, '', link.href);
            }).catch(function () { window.location.href = link.href; });
        });
        </script>
    </div>
<?php }

add_action('admin_menu', function () { if (defined('MAC_MASTER_ACTIVE') && MAC_MASTER_ACTIVE) add_submenu_page('master-auto-catalog', 'Защита сайта', 'Защита сайта', 'manage_options', 'mac-site-protection', 'mac_site_protection_page_v2'); }, 30);

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

function mac_site_protection_render_blocks() { global $wpdb; mac_site_protection_expire_blocks(); $page=max(1,(int)($_GET['blocks_paged']??1)); $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}site_protection_blocks"); $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}site_protection_blocks ORDER BY id DESC LIMIT 15 OFFSET %d",($page-1)*15),ARRAY_A); ?><div class="mac-protection-panel" data-mac-panel="blocks"><div class="mac-panel-head"><h2>Блокировки IP</h2><?php mac_logs_cleanup_buttons('blocks'); ?></div><table class="widefat striped"><thead><tr><th>IP</th><th>Причина</th><th>Создана</th><th>До</th><th>Статус</th><th></th></tr></thead><tbody><?php foreach ($rows as $r): $expired=$r['expires_at']&&strtotime($r['expires_at'])<=current_time('timestamp'); ?><tr><td><?php echo esc_html($r['ip_address']); ?></td><td><?php echo esc_html($r['reason']); ?></td><td><?php echo esc_html($r['created_at']); ?></td><td><?php echo esc_html($r['expires_at'] ?: 'Навсегда'); ?></td><td><?php echo $r['is_active'] ? ($r['expires_at'] ? 'Активна' : 'Постоянная') : ($expired ? 'Истекла' : 'Снята'); ?></td><td><?php if ($r['is_active']): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('mac_site_protection_action'); ?><input type="hidden" name="action" value="mac_site_protection_action"><input type="hidden" name="ip" value="<?php echo esc_attr($r['ip_address']); ?>"><button name="protection_action" value="unblock" class="button button-small">Снять</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php mac_site_protection_pager('blocks_paged',$total,$page,'blocks'); ?></div><?php }

function mac_site_protection_render_xml_blocks() { global $wpdb; $page=max(1,(int)($_GET['xml_blocks_paged']??1)); $table=$wpdb->prefix.'sitemap_logs'; $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE response_code IN (403,429)"); $rows=$wpdb->get_results($wpdb->prepare("SELECT created_at, ip_address, sitemap_path, request_uri, bot_name, user_agent, response_code FROM {$table} WHERE response_code IN (403,429) ORDER BY id DESC LIMIT 15 OFFSET %d",($page-1)*15),ARRAY_A); ?><div class="mac-protection-panel" data-mac-panel="xml-blocks"><div class="mac-panel-head"><h2>Ограниченные XML-карты</h2><?php mac_logs_cleanup_buttons('xml_restricted'); ?></div><table class="widefat striped"><thead><tr><th>Дата</th><th>Ответ</th><th>IP</th><th>Карта</th><th>Бот</th><th>User-Agent</th><th></th></tr></thead><tbody><?php if ($rows): foreach ($rows as $row): $response=(int)$row['response_code']; ?><tr><td><?php echo esc_html($row['created_at']); ?></td><td title="<?php echo $response === 429 ? 'Новый лимит XML' : 'Ответ от другого или старого правила'; ?>"><?php echo $response === 429 ? '429 лимит' : '403 правило'; ?></td><td><?php echo esc_html($row['ip_address']); ?></td><td><?php echo esc_html($row['request_uri'] ?: $row['sitemap_path']); ?></td><td><?php echo esc_html($row['bot_name']); ?></td><td><?php echo esc_html($row['user_agent']); ?></td><td><?php mac_site_protection_action_buttons($row['ip_address']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="7">Ограниченных запросов пока нет.</td></tr><?php endif; ?></tbody></table><?php mac_site_protection_pager('xml_blocks_paged',$total,$page,'xml-blocks'); ?></div><?php }

function mac_site_protection_render_events() {
    global $wpdb;
    $table = $wpdb->prefix . 'site_protection_events';
    $page = max(1, (int) ($_GET['protection_events_paged'] ?? 1));
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT 15 OFFSET %d", ($page - 1) * 15), ARRAY_A);
    ?><div class="mac-protection-panel" data-mac-panel="events"><div class="mac-panel-head"><h2>Срабатывания защиты</h2><?php mac_logs_cleanup_buttons('protection_events'); ?></div><table class="widefat striped"><thead><tr><th>Дата</th><th>Правило</th><th>IP</th><th>Запросов</th><th>Действие</th><th>URL</th></tr></thead><tbody><?php if ($rows): foreach ($rows as $row): ?><tr><td><?php echo esc_html($row['created_at']); ?></td><td><?php echo esc_html($row['rule_key']); ?></td><td><?php echo esc_html($row['ip_address']); ?></td><td><?php echo (int) $row['request_count']; ?> / <?php echo (int) $row['threshold_count']; ?></td><td><?php echo $row['action_taken'] === 'monitor' ? 'Только лог' : ($row['action_taken'] === 'throttle' ? 'Throttle 5 мин.' : 'Блокировка'); ?></td><td><?php echo esc_html($row['request_uri']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="6">Превышений пока нет.</td></tr><?php endif; ?></tbody></table><?php mac_site_protection_pager('protection_events_paged', $total, $page, 'events'); ?></div><?php
}

function mac_site_protection_pager($key, $total, $page, $panel = '') { $pages = max(1, (int) ceil($total / 15)); if ($pages < 2) return; $shown = array_unique(array_filter([1,2,3,$page-1,$page,$page+1,$pages-2,$pages-1,$pages], function($n) use($pages){return $n>0&&$n<=$pages;})); sort($shown); $last=0; echo '<nav class="mac-pagination" aria-label="Пагинация">'; foreach($shown as $n){if($last&&$n>$last+1)echo '<span class="mac-pagination-gap">&hellip;</span>'; echo $n===$page?'<span class="is-current">'.$n.'</span>':'<a data-mac-page="'.esc_attr($panel).'" href="'.esc_url(add_query_arg($key,$n)).'">'.$n.'</a>'; $last=$n;} echo '</nav>'; }

function mac_site_protection_action_buttons($ip) { ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mac-ip-actions">
    <?php wp_nonce_field('mac_site_protection_action'); ?>
    <input type="hidden" name="action" value="mac_site_protection_action"><input type="hidden" name="ip" value="<?php echo esc_attr($ip); ?>">
    <button name="protection_action" value="whitelist" class="button button-small" title="Обнулить блокировки и добавить в WhiteList">WL↺</button><button name="protection_action" value="block_1h" class="button button-small" title="Заблокировать на 1 час">1ч</button><button name="protection_action" value="block_1d" class="button button-small" title="Заблокировать на сутки">24ч</button><button name="protection_action" value="block_forever" class="button button-small button-link-delete" title="Заблокировать навсегда">∞</button>
</form>
<?php }

const MAC_CRAWLER_LOGS_DAILY_THRESHOLD = 100;
const MAC_CRAWLER_LOG_SAMPLES_LIMIT = 15;

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

function mac_sitemap_logs_is_official_bot($bot_name)
{
    return in_array($bot_name, ['Googlebot', 'YandexBot', 'Bingbot', 'DuckDuckBot', 'BaiduSpider', 'Applebot'], true);
}

function mac_site_protection_xml_decision(array $row) {
    if ((int) ($row['response_code'] ?? 0) === 403) return ['Заблокирован', 'mac-dot-danger'];
    $bot = (string) ($row['bot_name'] ?? '');
    if (mac_sitemap_logs_is_official_bot($bot)) return ['Официальный бот', 'mac-dot-good'];
    if ($bot === 'Другой бот') return ['Неофициальный бот', 'mac-dot-warn'];
    return ['Подозрительный запрос', 'mac-dot-danger'];
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

function mac_sitemap_logs_render_admin_table()
{
    global $wpdb;
    $table = $wpdb->prefix . 'sitemap_logs';
    $per_page = 15;
    $page = max(1, (int) ($_GET['sitemap_paged'] ?? 1));
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_agent NOT LIKE %s", '%AccelerateWP/Preload%'));
    $pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $pages);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE user_agent NOT LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d", '%AccelerateWP/Preload%', $per_page, ($page - 1) * $per_page), ARRAY_A);
    $base_url = admin_url('admin.php?page=mac-site-protection');
    ?>
    <div class="postbox" data-mac-panel="sitemap" style="margin:20px 0;background:white;border:1px solid #ccd0d4;border-radius:4px;">
        <div class="postbox-header" style="background:#f1f1f1;padding:10px 15px;border-bottom:1px solid #ccd0d4;display:flex;justify-content:space-between;align-items:center;gap:10px;"><h2 style="margin:0;">Логи карты сайта</h2><?php mac_logs_cleanup_buttons('sitemap'); ?></div>
        <div class="inside" style="padding:15px;overflow-x:auto;">
            <p>Записей: <strong><?php echo number_format_i18n($total); ?></strong></p>
            <table class="widefat striped">
                <thead><tr><th>Дата</th><th>Бот / посетитель</th><th>URL карты</th><th>IP</th><th>Статус</th><th>Referer</th><th>User-Agent</th><th>Действия</th></tr></thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $row): $decision = mac_site_protection_xml_decision($row); ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('d.m.Y H:i:s', strtotime($row['created_at']))); ?></td>
                        <td><?php echo esc_html($row['bot_name'] ?: '—'); ?></td>
                        <td style="word-break:break-word;max-width:220px;"><?php echo esc_html($row['request_uri'] ?: $row['sitemap_path']); ?></td>
                        <td><span class="mac-decision-dot <?php echo esc_attr($decision[1]); ?>" title="<?php echo esc_attr($decision[0]); ?>" aria-label="<?php echo esc_attr($decision[0]); ?>"></span><?php echo esc_html($row['ip_address'] ?: '—'); ?></td>
                        <td><?php echo esc_html($row['response_code']); ?></td>
                        <td style="word-break:break-word;max-width:220px;"><?php echo esc_html($row['referer'] ?: '—'); ?></td>
                        <td style="word-break:break-word;min-width:260px;"><?php echo esc_html($row['user_agent'] ?: '—'); ?></td>
                        <td><?php mac_site_protection_action_buttons($row['ip_address']); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8">Запросов к XML-картам сайта пока не было.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php mac_site_protection_pager('sitemap_paged', $total, $page, 'sitemap'); ?>
        </div>
    </div>
    <?php
}

function mac_crawler_logs_render_admin_table()
{
    global $wpdb;

    $table = $wpdb->prefix . 'crawler_logs';
    $per_page = 15;
    $page = max(1, (int) ($_GET['crawler_paged'] ?? 1));
    $threshold = MAC_CRAWLER_LOGS_DAILY_THRESHOLD;
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE request_count >= %d AND user_agent NOT LIKE %s", $threshold, '%AccelerateWP/Preload%'));
    $pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $pages);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE request_count >= %d AND user_agent NOT LIKE %s ORDER BY log_date DESC, request_count DESC, last_seen DESC LIMIT %d OFFSET %d",
        $threshold,
        '%AccelerateWP/Preload%',
        $per_page,
        ($page - 1) * $per_page
    ), ARRAY_A);
    $base_url = admin_url('admin.php?page=mac-site-protection');
    ?>
    <div class="postbox" data-mac-panel="crawler" style="margin:20px 0;background:white;border:1px solid #ccd0d4;border-radius:4px;">
        <div class="postbox-header" style="background:#f1f1f1;padding:10px 15px;border-bottom:1px solid #ccd0d4;display:flex;justify-content:space-between;align-items:center;gap:10px;"><h2 style="margin:0;">Интенсивные обходы страниц</h2><?php mac_logs_cleanup_buttons('crawler'); ?></div>
        <div class="inside" style="padding:15px;overflow-x:auto;">
            <p>Показаны IP с <?php echo (int) $threshold; ?> и более запросами за один день. Одна строка — один IP за день.</p>
            <table class="widefat striped">
                <thead><tr><th>Дата</th><th>Бот / посетитель</th><th>IP</th><th>Запросов</th><th>Первый / последний</th><th>Последний URL</th><th>User-Agent</th><th>Действия</th><th>Запросы</th></tr></thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('d.m.Y', strtotime($row['log_date']))); ?></td>
                        <td><?php echo esc_html($row['bot_name'] ?: '—'); ?></td>
                        <td><?php echo esc_html($row['ip_address']); ?></td>
                        <td><strong><?php echo number_format_i18n((int) $row['request_count']); ?></strong></td>
                        <td><?php echo esc_html(date_i18n('H:i:s', strtotime($row['first_seen'])) . ' — ' . date_i18n('H:i:s', strtotime($row['last_seen']))); ?></td>
                        <td style="word-break:break-word;max-width:260px;"><?php echo esc_html($row['last_request_uri'] ?: '—'); ?></td>
                        <td style="word-break:break-word;min-width:260px;"><?php echo esc_html($row['user_agent'] ?: '—'); ?></td>
                        <td><?php mac_site_protection_action_buttons($row['ip_address']); ?></td><td><button type="button" class="button button-small mac-crawler-log-details" title="Последние 15 запросов" data-date="<?php echo esc_attr($row['log_date']); ?>" data-ip="<?php echo esc_attr($row['ip_address']); ?>">👁</button></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8">IP с высокой активностью пока не найдено.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php mac_site_protection_pager('crawler_paged', $total, $page, 'crawler'); ?>
        </div>
    </div>
    <div id="mac-crawler-log-modal" style="display:none;position:fixed;z-index:100000;inset:0;background:rgba(0,0,0,.45);padding:40px 20px;overflow:auto;">
        <div style="background:#fff;max-width:1000px;margin:0 auto;padding:20px;border-radius:6px;position:relative;">
            <button type="button" id="mac-crawler-log-modal-close" class="button-link" style="position:absolute;right:15px;top:12px;font-size:22px;">&times;</button>
            <h2 style="margin-top:0;">Последние 15 запросов</h2>
            <div id="mac-crawler-log-modal-content">Загрузка&hellip;</div>
        </div>
    </div>
    <script>
    (function () {
        var modal = document.getElementById('mac-crawler-log-modal');
        var content = document.getElementById('mac-crawler-log-modal-content');
        var close = document.getElementById('mac-crawler-log-modal-close');
        var escapeHtml = function (value) { var node = document.createElement('span'); node.textContent = value || '—'; return node.innerHTML; };
        var closeModal = function () { modal.style.display = 'none'; };
        close.addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(); });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeModal(); });
        document.querySelectorAll('.mac-crawler-log-details').forEach(function (button) {
            button.addEventListener('click', function () {
                modal.style.display = 'block';
                content.textContent = 'Загрузка…';
                var form = new FormData();
                form.append('action', 'mac_crawler_logs_details');
                form.append('nonce', '<?php echo esc_js(wp_create_nonce('mac_crawler_logs_details')); ?>');
                form.append('log_date', button.dataset.date);
                form.append('ip_address', button.dataset.ip);
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: form }).then(function (response) { return response.json(); }).then(function (response) {
                    if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : 'Ошибка загрузки.');
                    if (!response.data.rows.length) { content.textContent = 'Детальные записи появятся для новых запросов после обновления плагина.'; return; }
                    var html = '<table class="widefat striped"><thead><tr><th>Время</th><th>URL</th><th>Статус</th><th>Referer</th></tr></thead><tbody>';
                    response.data.rows.forEach(function (row) {
                        var url = <?php echo wp_json_encode(home_url('/')); ?>.replace(/\/$/, '') + (row.request_uri || '');
                        html += '<tr><td>' + escapeHtml(row.created_at) + '</td><td style="word-break:break-word;"><a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' + escapeHtml(row.request_uri) + '</a></td><td>' + escapeHtml(String(row.response_code || '—')) + '</td><td style="word-break:break-word;">' + escapeHtml(row.referer) + '</td></tr>';
                    });
                    content.innerHTML = html + '</tbody></table>';
                }).catch(function (error) { content.textContent = error.message; });
            });
        });
    }());
    </script>
    <?php
}
