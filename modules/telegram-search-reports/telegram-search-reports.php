<?php

defined('ABSPATH') || exit;

const MAC_TSR_SETTINGS_OPTION = 'mac_telegram_search_reports_settings';
const MAC_TSR_DAILY_HOOK = 'mac_telegram_search_reports_daily';
const MAC_TSR_WEEKLY_HOOK = 'mac_telegram_search_reports_weekly';

function mac_tsr_get_settings()
{
    $defaults = [
        'bot_token' => '',
        'chat_id' => '',
        'topic_id' => '',
        'daily_enabled' => '1',
        'weekly_enabled' => '1',
    ];

    return wp_parse_args((array) get_option(MAC_TSR_SETTINGS_OPTION, []), $defaults);
}

function mac_tsr_is_configured()
{
    $settings = mac_tsr_get_settings();
    return $settings['bot_token'] !== '' && $settings['chat_id'] !== '';
}

function mac_tsr_next_daily_timestamp()
{
    $now = new DateTimeImmutable('now', wp_timezone());
    return $now->modify('tomorrow')->setTime(0, 5)->getTimestamp();
}

function mac_tsr_next_weekly_timestamp()
{
    $now = new DateTimeImmutable('now', wp_timezone());
    $next_monday = $now->modify('monday this week')->setTime(0, 10);

    if ($next_monday <= $now) {
        $next_monday = $next_monday->modify('+1 week');
    }

    return $next_monday->getTimestamp();
}

function mac_tsr_ensure_schedule()
{
    if (!wp_next_scheduled(MAC_TSR_DAILY_HOOK)) {
        wp_schedule_event(mac_tsr_next_daily_timestamp(), 'daily', MAC_TSR_DAILY_HOOK);
    }

    if (!wp_next_scheduled(MAC_TSR_WEEKLY_HOOK)) {
        wp_schedule_event(mac_tsr_next_weekly_timestamp(), 'weekly', MAC_TSR_WEEKLY_HOOK);
    }
}
add_action('init', 'mac_tsr_ensure_schedule');

function mac_tsr_vin_condition()
{
    return function_exists('wp_search_logs_vin_sql_condition')
        ? wp_search_logs_vin_sql_condition()
        : "CHAR_LENGTH(query) = 17 AND UPPER(query) REGEXP '^[A-HJ-NPR-Z0-9]{17}$'";
}

function mac_tsr_get_report_rows(DateTimeInterface $start, DateTimeInterface $end)
{
    global $wpdb;

    $table = $wpdb->prefix . 'search_logs';
    $vin_condition = mac_tsr_vin_condition();

    return $wpdb->get_results($wpdb->prepare(
        "SELECT query, COUNT(*) AS count
         FROM {$table}
         WHERE {$vin_condition} AND created_at >= %s AND created_at < %s
         GROUP BY query
         ORDER BY count DESC, query ASC",
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s')
    ), ARRAY_A);
}

function mac_tsr_send_report(DateTimeInterface $start, DateTimeInterface $end)
{
    $rows = mac_tsr_get_report_rows($start, $end);
    if (!$rows) {
        return ['status' => 'empty'];
    }

    if (!mac_tsr_is_configured()) {
        return ['status' => 'error', 'message' => 'Telegram settings are incomplete.'];
    }

    $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url();
    $lines = [$host];
    foreach ($rows as $row) {
        $lines[] = $row['query'] . ' - ' . (int) $row['count'];
    }

    $settings = mac_tsr_get_settings();
    $body = [
        'chat_id' => $settings['chat_id'],
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
        'text' => implode("\n", $lines),
    ];

    if ($settings['topic_id'] !== '') {
        $body['message_thread_id'] = (int) $settings['topic_id'];
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

    return ['status' => 'sent', 'count' => count($rows)];
}

function mac_tsr_send_daily_report()
{
    $end = new DateTimeImmutable('today', wp_timezone());
    $start = $end->modify('-1 day');
    $settings = mac_tsr_get_settings();

    if ($settings['daily_enabled'] === '1') {
        mac_tsr_send_report($start, $end);
    }
}
add_action(MAC_TSR_DAILY_HOOK, 'mac_tsr_send_daily_report');

function mac_tsr_send_weekly_report()
{
    $today = new DateTimeImmutable('today', wp_timezone());
    $end = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
    $start = $end->modify('-7 days');
    $settings = mac_tsr_get_settings();

    if ($settings['weekly_enabled'] === '1') {
        mac_tsr_send_report($start, $end);
    }
}
add_action(MAC_TSR_WEEKLY_HOOK, 'mac_tsr_send_weekly_report');

add_action('admin_post_mac_tsr_save_settings', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied.');
    }

    check_admin_referer('mac_tsr_save_settings');
    $current = mac_tsr_get_settings();
    $token = trim((string) ($_POST['bot_token'] ?? ''));
    $settings = [
        'bot_token' => $token !== '' ? sanitize_text_field(wp_unslash($token)) : $current['bot_token'],
        'chat_id' => preg_replace('/[^0-9-]/', '', (string) wp_unslash($_POST['chat_id'] ?? '')),
        'topic_id' => preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['topic_id'] ?? '')),
        'daily_enabled' => isset($_POST['daily_enabled']) ? '1' : '0',
        'weekly_enabled' => isset($_POST['weekly_enabled']) ? '1' : '0',
    ];

    update_option(MAC_TSR_SETTINGS_OPTION, $settings, false);
    wp_safe_redirect(add_query_arg(['page' => 'mac-telegram-search-reports', 'mac_tsr_saved' => '1'], admin_url('admin.php')));
    exit;
});

add_action('admin_post_mac_tsr_send_manual', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied.');
    }

    check_admin_referer('mac_tsr_send_manual');
    $start = new DateTimeImmutable('today', wp_timezone());
    $result = mac_tsr_send_report($start, new DateTimeImmutable('now', wp_timezone()));
    set_transient('mac_tsr_manual_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS);

    wp_safe_redirect(add_query_arg(['page' => 'mac-telegram-search-reports'], admin_url('admin.php')));
    exit;
});

function mac_tsr_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = mac_tsr_get_settings();
    $result = get_transient('mac_tsr_manual_result_' . get_current_user_id());
    delete_transient('mac_tsr_manual_result_' . get_current_user_id());
    ?>
    <?php if (isset($_GET['mac_tsr_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
    <?php endif; ?>
    <?php if ($result && $result['status'] === 'sent') : ?>
        <div class="notice notice-success is-dismissible"><p>Report sent.</p></div>
    <?php elseif ($result && $result['status'] === 'empty') : ?>
        <div class="notice notice-info"><p>No VIN searches for the selected period. Nothing was sent.</p></div>
    <?php elseif ($result && $result['status'] === 'error') : ?>
        <div class="notice notice-error"><p><?php echo esc_html($result['message']); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('mac_tsr_save_settings'); ?>
        <input type="hidden" name="action" value="mac_tsr_save_settings">
        <table class="form-table">
            <tr>
                <th scope="row"><label for="mac-tsr-token">Bot token</label></th>
                <td><input id="mac-tsr-token" type="password" name="bot_token" class="regular-text" placeholder="<?php echo $settings['bot_token'] !== '' ? 'Configured - leave blank to keep' : ''; ?>" autocomplete="new-password"></td>
            </tr>
            <tr>
                <th scope="row"><label for="mac-tsr-chat">Chat ID</label></th>
                <td><input id="mac-tsr-chat" type="text" name="chat_id" class="regular-text" value="<?php echo esc_attr($settings['chat_id']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="mac-tsr-topic">Topic ID</label></th>
                <td><input id="mac-tsr-topic" type="text" name="topic_id" class="regular-text" value="<?php echo esc_attr($settings['topic_id']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">Automatic reports</th>
                <td>
                    <label><input type="checkbox" name="daily_enabled" value="1" <?php checked($settings['daily_enabled'], '1'); ?>> Daily</label><br>
                    <label><input type="checkbox" name="weekly_enabled" value="1" <?php checked($settings['weekly_enabled'], '1'); ?>> Weekly</label>
                </td>
            </tr>
        </table>
        <?php submit_button('Save Telegram settings'); ?>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('mac_tsr_send_manual'); ?>
        <input type="hidden" name="action" value="mac_tsr_send_manual">
        <?php submit_button('Send today report now', 'secondary'); ?>
    </form>
    <?php
}
