<?php

/**
 * Module Name: AskarTech | WP Search Logs (DB + CSV export)
 * Description: Логирует поисковые запросы в БД и позволяет скачать CSV.
 * Version: 2.0
 * Author: AskarTech
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/sitemap-logs.php';

function wp_search_logs_normalize_vin($search_query)
{
    $vin = strtoupper(preg_replace('/[\s-]+/', '', trim((string) $search_query)));

    return preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin) ? $vin : '';
}

function wp_search_logs_vin_sql_condition()
{
    return "CHAR_LENGTH(query) = 17 AND UPPER(query) REGEXP '^[A-HJ-NPR-Z0-9]{17}$'";
}

function wp_search_logs_vin_result_label($result)
{
    $labels = [
        'existing' => 'VIN был на сайте',
        'created' => 'VIN создался',
        'not_found' => 'VIN не найден',
        'creation_error' => 'Ошибка создания',
    ];

    return $labels[$result] ?? '—';
}

function wp_search_logs_update_vin_result($result, $vin)
{
    $allowed_results = ['existing', 'created', 'not_found', 'creation_error'];
    if (!in_array($result, $allowed_results, true)) {
        return;
    }

    $context = $GLOBALS['mac_search_logs_current_request'] ?? null;
    if (!is_array($context) || empty($context['id']) || ($context['vin'] ?? '') !== $vin) {
        return;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'search_logs',
        ['vin_result' => $result],
        ['id' => (int) $context['id']],
        ['%s'],
        ['%d']
    );
}
add_action('mac_vin_fallback_search_result', 'wp_search_logs_update_vin_result', 10, 2);

const MAC_SEARCH_LOGS_TELEGRAM_OPTION = 'mac_search_logs_telegram_settings';
const MAC_SEARCH_LOGS_TELEGRAM_DAILY_HOOK = 'mac_search_logs_telegram_daily';
const MAC_SEARCH_LOGS_TELEGRAM_WEEKLY_HOOK = 'mac_search_logs_telegram_weekly';

function mac_search_logs_telegram_settings()
{
    return wp_parse_args((array) get_option(MAC_SEARCH_LOGS_TELEGRAM_OPTION, []), [
        'bot_token' => '',
        'chat_id' => '-1003180903998',
        'topic_id' => '264801',
        'sitemap_topic_id' => '27659',
        'daily_enabled' => '1',
        'weekly_enabled' => '1',
    ]);
}

function mac_search_logs_telegram_schedule()
{
    $now = new DateTimeImmutable('now', wp_timezone());

    if (!wp_next_scheduled(MAC_SEARCH_LOGS_TELEGRAM_DAILY_HOOK)) {
        wp_schedule_event($now->modify('tomorrow')->setTime(0, 5)->getTimestamp(), 'daily', MAC_SEARCH_LOGS_TELEGRAM_DAILY_HOOK);
    }

    if (!wp_next_scheduled(MAC_SEARCH_LOGS_TELEGRAM_WEEKLY_HOOK)) {
        $next_monday = $now->modify('monday this week')->setTime(0, 10);
        if ($next_monday <= $now) {
            $next_monday = $next_monday->modify('+1 week');
        }

        wp_schedule_event($next_monday->getTimestamp(), 'weekly', MAC_SEARCH_LOGS_TELEGRAM_WEEKLY_HOOK);
    }
}
add_action('init', 'mac_search_logs_telegram_schedule');

function mac_search_logs_telegram_send(DateTimeInterface $start, DateTimeInterface $end)
{
    global $wpdb;

    $settings = mac_search_logs_telegram_settings();
    if ($settings['bot_token'] === '' || $settings['chat_id'] === '') {
        return ['status' => 'error', 'message' => 'Заполните Token и Chat ID Telegram.'];
    }

    $table = $wpdb->prefix . 'search_logs';
    $vin_condition = wp_search_logs_vin_sql_condition();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT query, COUNT(*) AS count,
                SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(vin_result, '') ORDER BY id DESC SEPARATOR ','), ',', 1) AS vin_result
         FROM {$table}
         WHERE {$vin_condition} AND created_at >= %s AND created_at < %s
         GROUP BY query
         ORDER BY count DESC, query ASC",
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s')
    ), ARRAY_A);

    if (!$rows) {
        return ['status' => 'empty'];
    }

    $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url();
    $lines = [$host];
    foreach ($rows as $row) {
        $lines[] = $row['query'] . ' - ' . (int) $row['count'] . ' - ' . wp_search_logs_vin_result_label((string) ($row['vin_result'] ?? ''));
    }

    $body = [
        'chat_id' => $settings['chat_id'],
        'message_thread_id' => (int) $settings['topic_id'],
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
        'text' => implode("\n", $lines),
    ];

    if ($body['message_thread_id'] <= 0) {
        unset($body['message_thread_id']);
    }

    $response = wp_remote_post('https://api.telegram.org/bot' . rawurlencode($settings['bot_token']) . '/sendMessage', [
        'timeout' => 20,
        'body' => $body,
    ]);

    if (is_wp_error($response)) {
        return ['status' => 'error', 'message' => $response->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return ['status' => 'error', 'message' => 'Telegram API HTTP ' . $code . ': ' . wp_remote_retrieve_body($response)];
    }

    return ['status' => 'sent'];
}

add_action(MAC_SEARCH_LOGS_TELEGRAM_DAILY_HOOK, function () {
    $settings = mac_search_logs_telegram_settings();
    if ($settings['daily_enabled'] === '1') {
        $end = new DateTimeImmutable('today', wp_timezone());
        mac_search_logs_telegram_send($end->modify('-1 day'), $end);
        mac_sitemap_logs_send_report($end->modify('-1 day'), $end);
    }
});

add_action(MAC_SEARCH_LOGS_TELEGRAM_WEEKLY_HOOK, function () {
    $settings = mac_search_logs_telegram_settings();
    if ($settings['weekly_enabled'] === '1') {
        $today = new DateTimeImmutable('today', wp_timezone());
        $end = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
        mac_search_logs_telegram_send($end->modify('-7 days'), $end);
        mac_sitemap_logs_send_report($end->modify('-7 days'), $end);
    }
});

add_action('admin_post_mac_search_logs_save_telegram', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied.');
    }

    check_admin_referer('mac_search_logs_save_telegram');
    $current = mac_search_logs_telegram_settings();
    $token = trim((string) wp_unslash($_POST['bot_token'] ?? ''));

    update_option(MAC_SEARCH_LOGS_TELEGRAM_OPTION, [
        'bot_token' => $token !== '' ? sanitize_text_field($token) : $current['bot_token'],
        'chat_id' => preg_replace('/[^0-9-]/', '', (string) wp_unslash($_POST['chat_id'] ?? '')),
        'topic_id' => preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['topic_id'] ?? '')),
        'sitemap_topic_id' => preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['sitemap_topic_id'] ?? $current['sitemap_topic_id'])),
        'daily_enabled' => isset($_POST['daily_enabled']) ? '1' : '0',
        'weekly_enabled' => isset($_POST['weekly_enabled']) ? '1' : '0',
    ], false);

    wp_safe_redirect(add_query_arg(['page' => 'wp-search-logs', 'telegram_saved' => '1'], admin_url('admin.php')));
    exit;
});

add_action('admin_post_mac_search_logs_send_telegram', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied.');
    }

    check_admin_referer('mac_search_logs_send_telegram');
    $start = new DateTimeImmutable('today', wp_timezone());
    set_transient('mac_search_logs_telegram_result_' . get_current_user_id(), mac_search_logs_telegram_send($start, new DateTimeImmutable('now', wp_timezone())), MINUTE_IN_SECONDS);

    wp_safe_redirect(add_query_arg('page', 'wp-search-logs', admin_url('admin.php')));
    exit;
});

/**
 * Ловим поисковые запросы - исправленная версия
 */
add_action('wp', function () {
    // Только фронт-энд, основной поисковый запрос
    if (is_admin() || !is_search() || !is_main_query()) {
        return;
    }

    // 🚫 Абсолютный стопер для админа
    if (
        is_user_logged_in() &&
        ( current_user_can('manage_options') || is_super_admin() || user_can(get_current_user_id(), 'manage_options') )
    ) {
        return;
    }

    $search_query = get_search_query();
    if (empty($search_query)) {
        return;
    }

    $search_query = wp_search_logs_normalize_vin($search_query);
    if ($search_query === '') {
        return;
    }

    // На всякий: если результатов ноль и это админ — не пишем (доп. страховка)
    global $wp_query;
    if (
        isset($wp_query->found_posts) &&
        $wp_query->found_posts === 0 &&
        is_user_logged_in() &&
        ( current_user_can('manage_options') || is_super_admin() || user_can(get_current_user_id(), 'manage_options') )
    ) {
        return;
    }


    // Создаем уникальный идентификатор сессии для этого запроса
    $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $user_agent = substr($user_agent, 0, 2048);
    $session_id = md5($search_query . $ip_address . current_time('Y-m-d H'));

    global $wpdb;
    $table = $wpdb->prefix . 'search_logs';

    // Проверяем, не логировали ли мы уже этот запрос в последние 5 минут
    $recent_log = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table 
         WHERE query = %s 
         AND session_id = %s 
         AND created_at > DATE_SUB(%s, INTERVAL 5 MINUTE)",
        $search_query,
        $session_id,
        current_time('mysql')
    ));

    if ($recent_log > 0) {
        return; // Уже логировали этот запрос недавно
    }
	
	// 🚫 Не логируем запросы, если поиск делает администратор
	if (is_user_logged_in() && current_user_can('manage_options')) {
		return;
	}

    $wpdb->insert(
        $table,
        [
            'created_at' => current_time('mysql'),
            'query'      => wp_strip_all_tags($search_query),
            'session_id' => $session_id,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'vin_result' => '',
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s']
    );

    if ($wpdb->insert_id) {
        $GLOBALS['mac_search_logs_current_request'] = [
            'id' => (int) $wpdb->insert_id,
            'vin' => $search_query,
        ];
    }
});

/**
 * Альтернативный вариант - через хук pre_get_posts
 */
add_action('pre_get_posts', function ($query) {
    return;

    if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
        return;
    }

    // 🚫 Абсолютный стопер для админа
    if (
        is_user_logged_in() &&
        ( current_user_can('manage_options') || is_super_admin() || user_can(get_current_user_id(), 'manage_options') )
    ) {
        return;
    }

    $search_query = get_search_query();
    if (empty($search_query)) {
        return;
    }

    // Используем транзиент (временную метку) для предотвращения дублирования
    $transient_key = 'search_log_' . md5($search_query . $_SERVER['REMOTE_ADDR']);

    // Если уже логировали этот запрос в последние 2 минуты - пропускаем
    if (get_transient($transient_key)) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'search_logs';

	// 🚫 Не логируем запросы, если поиск делает администратор
	if (is_user_logged_in() && current_user_can('manage_options')) {
		return;
	}
	
    $wpdb->insert(
        $table,
        [
            'created_at' => current_time('mysql'),
            'query'      => wp_strip_all_tags($search_query),
        ],
        ['%s', '%s']
    );

    // Устанавливаем транзиент на 2 минуты
    set_transient($transient_key, true, 2 * MINUTE_IN_SECONDS);
});

/**
 * Страница в админке + экспорт CSV
 */
add_action('admin_menu', function () {
    if (defined('MAC_MASTER_ACTIVE') && MAC_MASTER_ACTIVE) {
        return;
    }

    add_menu_page(
        'AskarTech | Search Logs',
        'AskarTech | Search Logs',
        'manage_options',
        'wp-search-logs',
        'wp_search_logs_page',
        'dashicons-search',
        49
    );
});

function wp_search_logs_page()
{

    // Очистка логов
    if (isset($_POST['clear_logs']) && check_admin_referer('clear_search_logs')) {
        global $wpdb;
        $table = $wpdb->prefix . 'search_logs';
        $result = $wpdb->query("TRUNCATE TABLE $table");
        
        if ($result !== false) {
            echo '<div class="notice notice-success is-dismissible"><p>Логи успешно очищены!</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Ошибка при очистке логов!</p></div>';
        }
    }

    if (!current_user_can('manage_options')) return;

    // Экспорт CSV по клику
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        wp_search_logs_export_csv();
        return;
    }

    if (isset($_GET['sitemap_export']) && $_GET['sitemap_export'] === 'csv') {
        mac_sitemap_logs_export_csv();
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'search_logs';
    $vin_condition = wp_search_logs_vin_sql_condition();

    // Получаем статистику
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $vin_condition");
    $unique_queries = (int) $wpdb->get_var("SELECT COUNT(DISTINCT query) FROM $table WHERE $vin_condition");
    $today = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE $vin_condition AND DATE(created_at) = %s",
        current_time('Y-m-d')
    ));
    $yesterday = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE $vin_condition AND DATE(created_at) = %s",
        date('Y-m-d', strtotime('-1 day'))
    ));

    // Топ 20 запросов
    $top_queries = $wpdb->get_results(
        "SELECT query, COUNT(*) as count 
         FROM $table 
         WHERE $vin_condition
         GROUP BY query 
         ORDER BY count DESC 
         LIMIT 20",
        ARRAY_A
    );

    // Статистика по дням (последние 7 дней)
    $daily_stats = $wpdb->get_results(
        "SELECT DATE(created_at) as date, COUNT(*) as count 
         FROM $table 
         WHERE $vin_condition AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(created_at) 
         ORDER BY date DESC",
        ARRAY_A
    );

    // Пагинация
    $per_page = 50;
    $page = max(1, intval($_GET['paged'] ?? 1));
    $offset = ($page - 1) * $per_page;

    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT created_at, query, ip_address, user_agent, vin_result FROM $table WHERE $vin_condition ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset),
        ARRAY_A
    );

    $base_url = admin_url('admin.php?page=wp-search-logs');
    $telegram_settings = mac_search_logs_telegram_settings();
    $telegram_result = get_transient('mac_search_logs_telegram_result_' . get_current_user_id());
    delete_transient('mac_search_logs_telegram_result_' . get_current_user_id());
    $sitemap_telegram_result = get_transient('mac_sitemap_logs_telegram_result_' . get_current_user_id());
    delete_transient('mac_sitemap_logs_telegram_result_' . get_current_user_id());
?>
    <div class="wrap">
        <h1>Search Logs</h1>

        <?php if (isset($_GET['telegram_saved'])): ?>
            <div class="notice notice-success is-dismissible"><p>Настройки Telegram сохранены.</p></div>
        <?php endif; ?>
        <?php if ($telegram_result && $telegram_result['status'] === 'sent'): ?>
            <div class="notice notice-success is-dismissible"><p>Отчёт отправлен.</p></div>
        <?php elseif ($telegram_result && $telegram_result['status'] === 'empty'): ?>
            <div class="notice notice-info"><p>За сегодня нет VIN-поисков. Отчёт не отправлен.</p></div>
        <?php elseif ($telegram_result && $telegram_result['status'] === 'error'): ?>
            <div class="notice notice-error"><p><?php echo esc_html($telegram_result['message']); ?></p></div>
        <?php endif; ?>
        <?php if ($sitemap_telegram_result && $sitemap_telegram_result['status'] === 'sent'): ?>
            <div class="notice notice-success is-dismissible"><p>Отчёт по карте сайта отправлен.</p></div>
        <?php elseif ($sitemap_telegram_result && $sitemap_telegram_result['status'] === 'empty'): ?>
            <div class="notice notice-info"><p>За сегодня не было просмотров карты сайта. Отчёт не отправлен.</p></div>
        <?php elseif ($sitemap_telegram_result && $sitemap_telegram_result['status'] === 'error'): ?>
            <div class="notice notice-error"><p><?php echo esc_html($sitemap_telegram_result['message']); ?></p></div>
        <?php endif; ?>

        <div class="postbox" style="margin: 20px 0; background: white; border: 1px solid #ccd0d4; border-radius: 4px;">
            <div class="inside" style="padding: 15px;">
                <h2 style="margin-top: 0;">Telegram отчёты</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('mac_search_logs_save_telegram'); ?>
                    <input type="hidden" name="action" value="mac_search_logs_save_telegram">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="search-logs-tg-token">Token бота</label></th>
                            <td><input id="search-logs-tg-token" type="password" name="bot_token" class="regular-text" placeholder="<?php echo $telegram_settings['bot_token'] !== '' ? 'Настроен - оставьте пустым, чтобы не менять' : ''; ?>" autocomplete="new-password"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="search-logs-tg-chat">Chat ID</label></th>
                            <td><input id="search-logs-tg-chat" type="text" name="chat_id" class="regular-text" value="<?php echo esc_attr($telegram_settings['chat_id']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="search-logs-tg-topic">Topic ID</label></th>
                            <td><input id="search-logs-tg-topic" type="text" name="topic_id" class="regular-text" value="<?php echo esc_attr($telegram_settings['topic_id']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="search-logs-sitemap-topic">Topic ID карты сайта</label></th>
                            <td><input id="search-logs-sitemap-topic" type="text" name="sitemap_topic_id" class="regular-text" value="<?php echo esc_attr($telegram_settings['sitemap_topic_id']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Автоматические отчёты</th>
                            <td>
                                <label><input type="checkbox" name="daily_enabled" value="1" <?php checked($telegram_settings['daily_enabled'], '1'); ?>> Каждый день</label><br>
                                <label><input type="checkbox" name="weekly_enabled" value="1" <?php checked($telegram_settings['weekly_enabled'], '1'); ?>> Раз в неделю</label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Сохранить настройки Telegram'); ?>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('mac_search_logs_send_telegram'); ?>
                    <input type="hidden" name="action" value="mac_search_logs_send_telegram">
                    <?php submit_button('Отправить отчёт за сегодня', 'secondary'); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('mac_sitemap_logs_send_telegram'); ?>
                    <input type="hidden" name="action" value="mac_sitemap_logs_send_telegram">
                    <?php submit_button('Отправить отчёт по карте сайта за сегодня', 'secondary'); ?>
                </form>
            </div>
        </div>

        <!-- Статистика -->
        <div class="search-stats" style="margin: 20px 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <div class="stat-card" style="background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid #0073aa;">
                <h3 style="margin: 0 0 10px 0; color: #0073aa;">Всего запросов</h3>
                <div style="font-size: 2em; font-weight: bold;"><?php echo number_format($total); ?></div>
            </div>

            <div class="stat-card" style="background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid #46b450;">
                <h3 style="margin: 0 0 10px 0; color: #46b450;">Уникальных запросов</h3>
                <div style="font-size: 2em; font-weight: bold;"><?php echo number_format($unique_queries); ?></div>
            </div>

            <div class="stat-card" style="background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid #ffb900;">
                <h3 style="margin: 0 0 10px 0; color: #ffb900;">Сегодня</h3>
                <div style="font-size: 2em; font-weight: bold;"><?php echo number_format($today); ?></div>
            </div>

            <div class="stat-card" style="background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid #dc3232;">
                <h3 style="margin: 0 0 10px 0; color: #dc3232;">Вчера</h3>
                <div style="font-size: 2em; font-weight: bold;"><?php echo number_format($yesterday); ?></div>
            </div>
        </div>

        <!-- Топ 20 запросов -->
        <div class="postbox" style="margin: 20px 0; background: white; border: 1px solid #ccd0d4; border-radius: 4px;">
            <div class="postbox-header" style="background: #f1f1f1; padding: 10px 15px; border-bottom: 1px solid #ccd0d4;">
                <h2 style="margin: 0;">Топ 20 поисковых запросов</h2>
            </div>
            <div class="inside" style="padding: 15px;">
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Запрос</th>
                            <th style="width: 100px;">Количество</th>
                            <th style="width: 100px;">% от общего</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($top_queries):
                            foreach ($top_queries as $index => $item):
                                $percentage = $total > 0 ? round(($item['count'] / $total) * 100, 1) : 0;
                        ?>
                                <tr>
                                    <td>
                                        <span style="font-weight: bold; color: #0073aa;"><?php echo ($index + 1); ?>.</span>
                                        <?php echo esc_html($item['query']); ?>
                                    </td>
                                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($item['count']); ?></td>
                                    <td style="text-align: center;">
                                        <span style="background: #e1f5fe; padding: 2px 8px; border-radius: 12px;">
                                            <?php echo $percentage; ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach;
                        else: ?>
                            <tr>
                                <td colspan="3">Нет данных о популярных запросах</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Статистика по дням -->
        <div class="postbox" style="margin: 20px 0; background: white; border: 1px solid #ccd0d4; border-radius: 4px;">
            <div class="postbox-header" style="background: #f1f1f1; padding: 10px 15px; border-bottom: 1px solid #ccd0d4;">
                <h2 style="margin: 0;">Статистика за последние 7 дней</h2>
            </div>
            <div class="inside" style="padding: 15px;">
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th style="width: 100px;">Запросов</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($daily_stats):
                            foreach ($daily_stats as $stat):
                        ?>
                                <tr>
                                    <td><?php echo date('d.m.Y', strtotime($stat['date'])); ?></td>
                                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($stat['count']); ?></td>
                                </tr>
                            <?php endforeach;
                        else: ?>
                            <tr>
                                <td colspan="2">Нет данных за последние 7 дней</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        
        <!-- Последние запросы -->
        <div class="postbox" style="background: white; border: 1px solid #ccd0d4; border-radius: 4px;">
            <div class="postbox-header" style="background: #f1f1f1; padding: 10px 15px; border-bottom: 1px solid #ccd0d4;">
                <h2 style="margin: 0;">Последние поисковые запросы</h2>
            </div>
            <div class="inside" style="padding: 15px;">
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:200px;">Дата и время</th>
                            <th>Поисковый запрос</th>
                            <th style="width:150px;">IP</th>
                            <th>User-Agent</th>
                            <th style="width:170px;">Результат VIN</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo esc_html(date('d.m.Y H:i', strtotime($r['created_at']))); ?></td>
                                    <td><?php echo esc_html($r['query']); ?></td>
                                    <td><?php echo esc_html($r['ip_address'] ?: '—'); ?></td>
                                    <td style="word-break: break-word;"><?php echo esc_html($r['user_agent'] ?: '—'); ?></td>
                                    <td><?php echo esc_html(wp_search_logs_vin_result_label((string) $r['vin_result'])); ?></td>
                                </tr>
                            <?php endforeach;
                        else: ?>
                            <tr>
                                <td colspan="5">Пока пусто. Сделайте поиск на фронте (/?s=что-нибудь).</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php
                // Пагинация
                $pages = max(1, ceil($total / $per_page));
                if ($pages > 1):
                    echo '<div style="margin-top: 20px; text-align: center;">';
                    $pagination_pages = array_unique(array_filter([
                        1, 2, 3,
                        $page - 1, $page, $page + 1,
                        $pages - 2, $pages - 1, $pages,
                    ], function ($item) use ($pages) {
                        return $item >= 1 && $item <= $pages;
                    }));
                    sort($pagination_pages, SORT_NUMERIC);
                    $previous_page = 0;

                    foreach ($pagination_pages as $i) {
                        if ($previous_page && $i > $previous_page + 1) {
                            echo '<span style="margin: 0 5px;">&hellip;</span>';
                        }

                        $link = esc_url(add_query_arg('paged', $i, $base_url));
                        if ($i == $page) {
                            echo '<span style="margin: 0 5px; padding: 5px 10px; background: #0073aa; color: white; border-radius: 3px;">' . $i . '</span>';
                        } else {
                            echo '<a style="margin: 0 5px; padding: 5px 10px; background: #f1f1f1; color: #0073aa; text-decoration: none; border-radius: 3px;" href="' . $link . '">' . $i . '</a>';
                        }

                        $previous_page = $i;
                    }
                    echo '</div>';
                endif;
                ?>
            </div>
        </div>

        <?php mac_sitemap_logs_render_admin_table(); ?>
        <?php mac_crawler_logs_render_admin_table(); ?>

        <!-- Кнопка экспорта -->
        <p><a class="button button-primary" href="<?php echo esc_url($base_url . '&export=csv'); ?>">Скачать CSV со всеми данными</a></p>
        <br>
        <form method="post" style="margin: 0;">
            <?php wp_nonce_field('clear_search_logs'); ?>
            <button type="submit" name="clear_logs" class="button button-link-delete" 
                    onclick="return confirm('Вы уверены, что хотите полностью очистить все логи поиска? Это действие нельзя отменить.')">
                🗑️ Очистить все логи
            </button>
        </form>
    </div>

    <style>
        .search-stats .stat-card {
            transition: all 0.3s ease;
        }

        .search-stats .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
    </style>
<?php
}

function wp_search_logs_export_csv()
{
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $table = $wpdb->prefix . 'search_logs';
    $vin_condition = wp_search_logs_vin_sql_condition();
    $rows = $wpdb->get_results("SELECT created_at AS date, query, ip_address, user_agent, vin_result FROM $table WHERE $vin_condition ORDER BY id ASC", ARRAY_A);

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=search-log-' . date('Y-m-d') . '.csv');

    $out = fopen('php://output', 'w');
    // заголовок
    fputcsv($out, ['date', 'query', 'ip_address', 'user_agent', 'vin_result']);
    if ($rows) {
        foreach ($rows as $r) {
            fputcsv($out, [$r['date'], $r['query'], $r['ip_address'], $r['user_agent'], wp_search_logs_vin_result_label((string) $r['vin_result'])]);
        }
    }
    fclose($out);
    exit;
}

// Убираем служебный параметр Elementor из поиска редиректом
add_action('template_redirect', function () {
    if (isset($_GET['e_search_props']) && isset($_GET['s'])) {
        wp_redirect(home_url('/?s=' . urlencode(wp_unslash($_GET['s']))), 301);
        exit;
    }
});

// 2) Ограничиваем поиск только товарами WooCommerce
add_action('pre_get_posts', function ($q) {
    if (is_admin() || !$q->is_main_query()) return;

    // Любая страница результатов поиска -> только товары
    if ($q->is_search()) {
        // Ищем только опубликованные товары
        $q->set('post_type', ['product']);
        $q->set('post_status', ['publish']);
    }
});
