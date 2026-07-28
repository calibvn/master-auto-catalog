<?php

/**
 * Module Name: AskarTech | WP Search Logs (DB + CSV export)
 * Description: Логирует поисковые запросы в БД и позволяет скачать CSV.
 * Version: 2.0
 * Author: AskarTech
 */

if (!defined('ABSPATH')) exit;

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
    $session_id = md5($search_query . $_SERVER['REMOTE_ADDR'] . current_time('Y-m-d H'));

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
            'session_id' => $session_id
        ],
        ['%s', '%s', '%s']
    );
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

    global $wpdb;
    $table = $wpdb->prefix . 'search_logs';

    // Получаем статистику
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $unique_queries = (int) $wpdb->get_var("SELECT COUNT(DISTINCT query) FROM $table");
    $today = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE DATE(created_at) = %s",
        current_time('Y-m-d')
    ));
    $yesterday = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE DATE(created_at) = %s",
        date('Y-m-d', strtotime('-1 day'))
    ));

    // Топ 20 запросов
    $top_queries = $wpdb->get_results(
        "SELECT query, COUNT(*) as count 
         FROM $table 
         GROUP BY query 
         ORDER BY count DESC 
         LIMIT 20",
        ARRAY_A
    );

    // Статистика по дням (последние 7 дней)
    $daily_stats = $wpdb->get_results(
        "SELECT DATE(created_at) as date, COUNT(*) as count 
         FROM $table 
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(created_at) 
         ORDER BY date DESC",
        ARRAY_A
    );

    // Пагинация
    $per_page = 50;
    $page = max(1, intval($_GET['paged'] ?? 1));
    $offset = ($page - 1) * $per_page;

    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT created_at, query FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset),
        ARRAY_A
    );

    $base_url = admin_url('admin.php?page=wp-search-logs');
?>
    <div class="wrap">
        <h1>Search Logs</h1>

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
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo esc_html(date('d.m.Y H:i', strtotime($r['created_at']))); ?></td>
                                    <td><?php echo esc_html($r['query']); ?></td>
                                </tr>
                            <?php endforeach;
                        else: ?>
                            <tr>
                                <td colspan="2">Пока пусто. Сделайте поиск на фронте (/?s=что-нибудь).</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php
                // Пагинация
                $pages = max(1, ceil($total / $per_page));
                if ($pages > 1):
                    echo '<div style="margin-top: 20px; text-align: center;">';
                    for ($i = 1; $i <= $pages; $i++) {
                        $link = esc_url(add_query_arg('paged', $i, $base_url));
                        if ($i == $page) {
                            echo '<span style="margin: 0 5px; padding: 5px 10px; background: #0073aa; color: white; border-radius: 3px;">' . $i . '</span>';
                        } else {
                            echo '<a style="margin: 0 5px; padding: 5px 10px; background: #f1f1f1; color: #0073aa; text-decoration: none; border-radius: 3px;" href="' . $link . '">' . $i . '</a>';
                        }
                    }
                    echo '</div>';
                endif;
                ?>
            </div>
        </div>

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
    $rows = $wpdb->get_results("SELECT created_at AS date, query FROM $table ORDER BY id ASC", ARRAY_A);

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=search-log-' . date('Y-m-d') . '.csv');

    $out = fopen('php://output', 'w');
    // заголовок
    fputcsv($out, ['date', 'query']);
    if ($rows) {
        foreach ($rows as $r) {
            fputcsv($out, [$r['date'], $r['query']]);
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
