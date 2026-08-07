<?php

if (!defined('ABSPATH')) exit;

const MAC_CRAWLER_LOGS_DAILY_THRESHOLD = 100;

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

function mac_sitemap_logs_settings()
{
    $settings = mac_search_logs_telegram_settings();
    $settings['sitemap_topic_id'] = preg_replace('/[^0-9]/', '', (string) ($settings['sitemap_topic_id'] ?? '27659'));
    return $settings;
}

function mac_sitemap_logs_is_xml_request($request_path)
{
    return is_string($request_path) && preg_match('/\.xml$/i', $request_path) === 1;
}

function mac_sitemap_logs_detect_bot($user_agent)
{
    $user_agent = strtolower((string) $user_agent);
    $bots = [
        'googlebot' => 'Googlebot',
        'bingbot' => 'Bingbot',
        'yandexbot' => 'YandexBot',
        'duckduckbot' => 'DuckDuckBot',
        'baiduspider' => 'BaiduSpider',
        'applebot' => 'Applebot',
        'ahrefsbot' => 'AhrefsBot',
        'semrushbot' => 'SemrushBot',
        'mj12bot' => 'MJ12bot',
    ];

    foreach ($bots as $needle => $name) {
        if (strpos($user_agent, $needle) !== false) return $name;
    }

    return preg_match('/bot|crawler|spider|slurp|archiver/i', $user_agent) ? 'Другой бот' : 'Посетитель';
}

function mac_sitemap_logs_begin_request()
{
    if (isset($GLOBALS['mac_sitemap_logs_current_request'])) return;

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) return;

    $request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $request_path = wp_parse_url($request_uri, PHP_URL_PATH);
    if (!is_string($request_path) || $request_path === '') return;

    if (!mac_sitemap_logs_is_xml_request($request_path)) return;

    $GLOBALS['mac_sitemap_logs_current_request'] = [
        'sitemap_path' => mac_sitemap_logs_normalize_path($request_path),
        'request_uri' => substr(sanitize_text_field(wp_unslash($request_uri)), 0, 2048),
        'request_method' => $method,
        'ip_address' => substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45),
        'user_agent' => substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 2048),
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

    $GLOBALS['mac_crawler_logs_current_request'] = [
        'ip_address' => substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45),
        'user_agent' => substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 2048),
        'request_uri' => substr(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), 0, 2048),
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

function mac_sitemap_logs_send_report(DateTimeInterface $start, DateTimeInterface $end)
{
    global $wpdb;

    $settings = mac_sitemap_logs_settings();
    if ($settings['bot_token'] === '' || $settings['chat_id'] === '') {
        return ['status' => 'error', 'message' => 'Заполните Token и Chat ID Telegram.'];
    }

    $table = $wpdb->prefix . 'sitemap_logs';
    $summary = $wpdb->get_results($wpdb->prepare(
        "SELECT sitemap_path, bot_name, COUNT(*) AS count
         FROM {$table}
         WHERE created_at >= %s AND created_at < %s
         GROUP BY sitemap_path, bot_name
         ORDER BY sitemap_path ASC, count DESC, bot_name ASC",
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s')
    ), ARRAY_A);

    if (!$summary) return ['status' => 'empty'];

    $total = array_sum(array_map('intval', wp_list_pluck($summary, 'count')));
    $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url();
    $lines = [$host, 'Просмотров XML: ' . $total];
    foreach ($summary as $row) {
        $lines[] = $row['sitemap_path'] . ' — ' . ($row['bot_name'] ?: 'Неизвестно') . ' — ' . (int) $row['count'];
    }

    $body = [
        'chat_id' => $settings['chat_id'],
        'message_thread_id' => (int) $settings['sitemap_topic_id'],
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
        'text' => implode("\n", $lines),
    ];
    if ($body['message_thread_id'] <= 0) unset($body['message_thread_id']);

    $response = wp_remote_post('https://api.telegram.org/bot' . rawurlencode($settings['bot_token']) . '/sendMessage', [
        'timeout' => 20,
        'body' => $body,
    ]);
    if (is_wp_error($response)) return ['status' => 'error', 'message' => $response->get_error_message()];

    $code = (int) wp_remote_retrieve_response_code($response);
    return ($code >= 200 && $code < 300)
        ? ['status' => 'sent']
        : ['status' => 'error', 'message' => 'Telegram API HTTP ' . $code . ': ' . wp_remote_retrieve_body($response)];
}

add_action('admin_post_mac_sitemap_logs_send_telegram', function () {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    check_admin_referer('mac_sitemap_logs_send_telegram');

    $start = new DateTimeImmutable('today', wp_timezone());
    set_transient('mac_sitemap_logs_telegram_result_' . get_current_user_id(), mac_sitemap_logs_send_report($start, new DateTimeImmutable('now', wp_timezone())), MINUTE_IN_SECONDS);
    wp_safe_redirect(add_query_arg('page', 'wp-search-logs', admin_url('admin.php')));
    exit;
});

function mac_sitemap_logs_export_csv()
{
    if (!current_user_can('manage_options')) return;
    global $wpdb;

    $rows = $wpdb->get_results("SELECT created_at, sitemap_path, request_uri, request_method, response_code, ip_address, bot_name, referer, user_agent, user_id FROM {$wpdb->prefix}sitemap_logs ORDER BY id ASC", ARRAY_A);
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=sitemap-log-' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['date', 'sitemap_path', 'request_uri', 'method', 'response_code', 'ip_address', 'bot_name', 'referer', 'user_agent', 'user_id']);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

function mac_sitemap_logs_render_admin_table()
{
    global $wpdb;
    $table = $wpdb->prefix . 'sitemap_logs';
    $per_page = 50;
    $page = max(1, (int) ($_GET['sitemap_paged'] ?? 1));
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $pages);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ($page - 1) * $per_page), ARRAY_A);
    $base_url = admin_url('admin.php?page=wp-search-logs');
    ?>
    <div class="postbox" style="margin:20px 0;background:white;border:1px solid #ccd0d4;border-radius:4px;">
        <div class="postbox-header" style="background:#f1f1f1;padding:10px 15px;border-bottom:1px solid #ccd0d4;"><h2 style="margin:0;">Логи карты сайта</h2></div>
        <div class="inside" style="padding:15px;overflow-x:auto;">
            <p>Записей: <strong><?php echo number_format_i18n($total); ?></strong></p>
            <table class="widefat striped">
                <thead><tr><th>Дата</th><th>Бот / посетитель</th><th>URL карты</th><th>IP</th><th>Метод</th><th>Статус</th><th>Referer</th><th>User-Agent</th></tr></thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('d.m.Y H:i:s', strtotime($row['created_at']))); ?></td>
                        <td><?php echo esc_html($row['bot_name'] ?: '—'); ?></td>
                        <td style="word-break:break-word;max-width:220px;"><?php echo esc_html($row['request_uri'] ?: $row['sitemap_path']); ?></td>
                        <td><?php echo esc_html($row['ip_address'] ?: '—'); ?></td>
                        <td><?php echo esc_html($row['request_method']); ?></td>
                        <td><?php echo esc_html($row['response_code']); ?></td>
                        <td style="word-break:break-word;max-width:220px;"><?php echo esc_html($row['referer'] ?: '—'); ?></td>
                        <td style="word-break:break-word;min-width:260px;"><?php echo esc_html($row['user_agent'] ?: '—'); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8">Запросов к XML-картам сайта пока не было.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($pages > 1): ?>
                <p style="margin-top:15px;">
                    <?php $shown = array_values(array_unique(array_filter([1, 2, 3, $page - 1, $page, $page + 1, $pages - 2, $pages - 1, $pages], static function ($item) use ($pages) { return $item >= 1 && $item <= $pages; }))); sort($shown); $previous = 0; ?>
                    <?php foreach ($shown as $item): ?>
                        <?php if ($previous && $item > $previous + 1): ?>&hellip;<?php endif; ?>
                        <?php if ($item === $page): ?><strong style="margin:0 5px;"><?php echo $item; ?></strong><?php else: ?><a style="margin:0 5px;" href="<?php echo esc_url(add_query_arg('sitemap_paged', $item, $base_url)); ?>"><?php echo $item; ?></a><?php endif; ?>
                        <?php $previous = $item; ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <p><a class="button" href="<?php echo esc_url(add_query_arg('sitemap_export', 'csv', $base_url)); ?>">Скачать CSV логов карты сайта</a></p>
        </div>
    </div>
    <?php
}

function mac_crawler_logs_render_admin_table()
{
    global $wpdb;

    $table = $wpdb->prefix . 'crawler_logs';
    $per_page = 50;
    $page = max(1, (int) ($_GET['crawler_paged'] ?? 1));
    $threshold = MAC_CRAWLER_LOGS_DAILY_THRESHOLD;
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE request_count >= %d", $threshold));
    $pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $pages);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE request_count >= %d ORDER BY log_date DESC, request_count DESC, last_seen DESC LIMIT %d OFFSET %d",
        $threshold,
        $per_page,
        ($page - 1) * $per_page
    ), ARRAY_A);
    $base_url = admin_url('admin.php?page=wp-search-logs');
    ?>
    <div class="postbox" style="margin:20px 0;background:white;border:1px solid #ccd0d4;border-radius:4px;">
        <div class="postbox-header" style="background:#f1f1f1;padding:10px 15px;border-bottom:1px solid #ccd0d4;"><h2 style="margin:0;">Интенсивные обходы страниц</h2></div>
        <div class="inside" style="padding:15px;overflow-x:auto;">
            <p>Показаны IP с <?php echo (int) $threshold; ?> и более запросами за один день. Одна строка — один IP за день.</p>
            <table class="widefat striped">
                <thead><tr><th>Дата</th><th>Бот / посетитель</th><th>IP</th><th>Запросов</th><th>Первый / последний</th><th>Последний URL</th><th>User-Agent</th></tr></thead>
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
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7">IP с высокой активностью пока не найдено.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($pages > 1): ?>
                <p style="margin-top:15px;">
                    <?php $shown = array_values(array_unique(array_filter([1, 2, 3, $page - 1, $page, $page + 1, $pages - 2, $pages - 1, $pages], static function ($item) use ($pages) { return $item >= 1 && $item <= $pages; }))); sort($shown); $previous = 0; ?>
                    <?php foreach ($shown as $item): ?>
                        <?php if ($previous && $item > $previous + 1): ?>&hellip;<?php endif; ?>
                        <?php if ($item === $page): ?><strong style="margin:0 5px;"><?php echo $item; ?></strong><?php else: ?><a style="margin:0 5px;" href="<?php echo esc_url(add_query_arg('crawler_paged', $item, $base_url)); ?>"><?php echo $item; ?></a><?php endif; ?>
                        <?php $previous = $item; ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
