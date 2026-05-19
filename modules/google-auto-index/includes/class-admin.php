<?php
class GAI_Admin
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('update_option_' . GAI_SETTINGS_OPTION, [$this, 'on_settings_updated'], 10, 2);

        add_action('wp_ajax_gai_oauth_callback', [$this, 'oauth_callback']);
    }
    public function add_menu()
    {
        if (defined('MAC_MASTER_ACTIVE') && MAC_MASTER_ACTIVE) {
            return;
        }

        add_menu_page(
            'AskarTech | Google Index',
            'AskarTech | Google Index',
            'manage_options',
            'vin-google-index',
            [$this, 'settings_page'],
            'dashicons-google',
            50
        );

        add_submenu_page(
            'vin-google-index',
            'Indexing Logs',
            'Logs',
            'manage_options',
            'gai-logs',
            [$this, 'logs_page']
        );
    }
    public function settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'google-auto-index'));
        }

        $stats = GAI_Indexer::get_daily_stats();

        global $wpdb;
        $table_name = $wpdb->prefix . 'gai_logs';
        $total_today = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE DATE(created_at) = CURDATE()");
        ?>

        <div class="wrap">
            <h1>Индексация Google</h1>

            <?php settings_errors('gai_settings_group'); ?>

            <div class="gai-stats-card" style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccd0d4;">
                <h3>Использование API</h3>

                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:15px;margin:15px 0;">
                    <div style="background:#f8f8f8;padding:15px;border-radius:5px;">
                        <h4 style="margin-top:0;">Лимит Google API</h4>
                        <p><strong>Использовано:</strong> <?php echo esc_html($stats['used']); ?> / <?php echo esc_html($stats['limit']); ?></p>
                        <p><strong>Осталось:</strong>
                            <span style="color:<?php echo esc_attr($stats['remaining'] > 20 ? '#46b450' : ($stats['remaining'] > 0 ? '#f56e28' : '#dc3232')); ?>;font-weight:bold;">
                                <?php echo esc_html($stats['remaining']); ?>
                            </span>
                        </p>
                        <p><strong>Расход:</strong> <?php echo esc_html($stats['percentage']); ?>%</p>
                    </div>

                    <div style="background:#f8f8f8;padding:15px;border-radius:5px;">
                        <h4 style="margin-top:0;">Сегодня</h4>
                        <p><strong>Строк в журнале:</strong> <?php echo esc_html($total_today); ?></p>
                        <p><strong>Успешных запросов:</strong> <?php echo esc_html($stats['used']); ?></p>
                    </div>
                </div>
            </div>

            <?php if (isset($_GET['auth']) && $_GET['auth'] === 'success'): ?>
                <div class="notice notice-success"><p>OAuth-авторизация выполнена.</p></div>
            <?php elseif (isset($_GET['auth']) && $_GET['auth'] === 'state_error'): ?>
                <div class="notice notice-error"><p>Проверка OAuth state не прошла. Повторите авторизацию.</p></div>
            <?php elseif (isset($_GET['auth']) && $_GET['auth'] === 'forbidden'): ?>
                <div class="notice notice-error"><p>Недостаточно прав для завершения OAuth.</p></div>
            <?php elseif (isset($_GET['auth']) && $_GET['auth'] === 'error'): ?>
                <div class="notice notice-error"><p>OAuth завершился ошибкой. Проверьте Client ID и Client Secret.</p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('gai_settings_group');
                do_settings_sections('vin-google-index');
                submit_button();
                ?>
            </form>

            <hr>

            <h2>Авторизация Google</h2>
            <p>Authorized redirect URI: <code><?php echo esc_html(admin_url('admin-ajax.php?action=gai_oauth_callback')); ?></code></p>
            <?php if (GAI_Google_Auth::is_authenticated()): ?>
                <p style="color:green;">Google подключен</p>
            <?php else: ?>
                <?php
                $settings = function_exists('gai_get_settings') ? gai_get_settings() : get_option(GAI_SETTINGS_OPTION, []);
                $auth_url = GAI_Google_Auth::get_auth_url();
                ?>
                <?php if (!empty($settings['client_id']) && !empty($settings['client_secret']) && !empty($auth_url)): ?>
                    <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">Авторизоваться в Google</a>
                <?php else: ?>
                    <p style="color:orange;">Сначала заполните Client ID и Client Secret.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function register_settings()
    {
        register_setting('gai_settings_group', GAI_SETTINGS_OPTION, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        add_settings_section(
            'gai_main_section',
            'Основные настройки',
            null,
            'vin-google-index'
        );

        add_settings_field('client_id', 'Google Client ID', [$this, 'render_client_id_field'], 'vin-google-index', 'gai_main_section');
        add_settings_field('client_secret', 'Google Client Secret', [$this, 'render_client_secret_field'], 'vin-google-index', 'gai_main_section');
        add_settings_field('auto_index_new', 'Автоиндексация новых товаров', [$this, 'render_auto_index_field'], 'vin-google-index', 'gai_main_section');
        add_settings_field('auto_delete_draft', 'Удаление из индекса', [$this, 'render_auto_delete_field'], 'vin-google-index', 'gai_main_section');
        add_settings_field('indexing_delay', 'Задержка перед отправкой, сек.', [$this, 'render_delay_field'], 'vin-google-index', 'gai_main_section');
        add_settings_field('enable_cron', 'Фоновая индексация раз в час', [$this, 'render_enable_cron_field'], 'vin-google-index', 'gai_main_section');
        add_settings_field('log_requests', 'Сохранять журнал запросов', [$this, 'render_log_requests_field'], 'vin-google-index', 'gai_main_section');
    }

    public function sanitize_settings($input)
    {
        $defaults = function_exists('gai_default_settings') ? gai_default_settings() : [];
        if (!is_array($input)) {
            $input = [];
        }

        $sanitized = [
            'client_id' => sanitize_text_field($input['client_id'] ?? ($defaults['client_id'] ?? '')),
            'client_secret' => sanitize_text_field($input['client_secret'] ?? ($defaults['client_secret'] ?? '')),
            'auto_index_new' => !empty($input['auto_index_new']),
            'auto_delete_draft' => !empty($input['auto_delete_draft']),
            'indexing_delay' => max(0, min(300, (int) ($input['indexing_delay'] ?? ($defaults['indexing_delay'] ?? 5)))),
            'enable_cron' => !empty($input['enable_cron']),
            'log_requests' => !empty($input['log_requests']),
        ];

        return $sanitized;
    }

    public function on_settings_updated($old_value, $value)
    {
        if (function_exists('gai_ensure_schedules')) {
            gai_ensure_schedules();
        }
    }

    public function render_client_id_field()
    {
        $settings = function_exists('gai_get_settings') ? gai_get_settings() : get_option(GAI_SETTINGS_OPTION, []);
        ?>
        <input type="text" name="gai_settings[client_id]" value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>" class="regular-text">
        <?php
    }

    public function render_client_secret_field()
    {
        $settings = function_exists('gai_get_settings') ? gai_get_settings() : get_option(GAI_SETTINGS_OPTION, []);
        ?>
        <input type="password" name="gai_settings[client_secret]" value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>" class="regular-text" autocomplete="new-password">
        <?php
    }

    public function render_auto_index_field()
    {
        $settings = function_exists('gai_get_settings') ? gai_get_settings() : get_option(GAI_SETTINGS_OPTION, []);
        ?>
        <label>
            <input type="checkbox" name="gai_settings[auto_index_new]" value="1" <?php checked(!empty($settings['auto_index_new'])); ?>>
            Отправлять URL_UPDATED, когда товар становится опубликованным
        </label>
        <?php
    }

    public function render_auto_delete_field()
    {
        $settings = function_exists('gai_get_settings') ? gai_get_settings() : get_option(GAI_SETTINGS_OPTION, []);
        ?>
        <label>
            <input type="checkbox" name="gai_settings[auto_delete_draft]" value="1" <?php checked(!empty($settings['auto_delete_draft'])); ?>>
            Отправлять URL_DELETED, когда товар уходит из публикации или удаляется
        </label>
        <?php
    }

    public function render_delay_field()
    {
        $settings = function_exists('gai_get_settings') ? gai_get_settings() : get_option(GAI_SETTINGS_OPTION, []);
        ?>
        <input type="number" min="0" max="300" name="gai_settings[indexing_delay]" value="<?php echo esc_attr((int) ($settings['indexing_delay'] ?? 5)); ?>" class="small-text">
        <?php
    }

    public function render_enable_cron_field()
    {
        $settings = function_exists('gai_get_settings') ? gai_get_settings() : get_option(GAI_SETTINGS_OPTION, []);
        ?>
        <label>
            <input type="checkbox" name="gai_settings[enable_cron]" value="1" <?php checked(!empty($settings['enable_cron'])); ?>>
            Запускать почасовую фоновую обработку
        </label>
        <?php
    }

    public function render_log_requests_field()
    {
        $settings = function_exists('gai_get_settings') ? gai_get_settings() : get_option(GAI_SETTINGS_OPTION, []);
        ?>
        <label>
            <input type="checkbox" name="gai_settings[log_requests]" value="1" <?php checked(!empty($settings['log_requests'])); ?>>
            Сохранять ответы Google API в журнале
        </label>
        <?php
    }

    public function oauth_callback()
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_safe_redirect(admin_url('admin.php?page=vin-google-index&auth=forbidden'));
            exit;
        }

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (!GAI_Google_Auth::validate_oauth_state($state, get_current_user_id())) {
            wp_safe_redirect(admin_url('admin.php?page=vin-google-index&auth=state_error'));
            exit;
        }

        if (isset($_GET['code'])) {
            $code = sanitize_text_field(wp_unslash($_GET['code']));

            if (GAI_Google_Auth::get_token($code)) {
                wp_safe_redirect(admin_url('admin.php?page=vin-google-index&auth=success'));
                exit;
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=vin-google-index&auth=error'));
        exit;
    }

    public function add_meta_box()
    {
        add_meta_box(
            'gai_indexing_status',
            'Индексация Google',
            [$this, 'render_meta_box'],
            'product',
            'side',
            'high'
        );
    }

    public function render_meta_box($post)
    {
        $indexed = get_post_meta($post->ID, '_gai_indexed', true);
        $last_action = get_post_meta($post->ID, '_gai_last_action', true);
        $nonce = wp_create_nonce('gai_admin_nonce');
        ?>
        <p><strong>Статус:</strong></p>
        <p>
            <?php if ($last_action === 'deleted'): ?>
                <span style="color:red;">Удалено из индекса</span>
            <?php elseif ($indexed): ?>
                <span style="color:green;">Отправлено в индекс</span><br>
                <small><?php echo esc_html(date('d.m.Y H:i', strtotime($indexed))); ?></small>
            <?php else: ?>
                <span style="color:orange;">Еще не отправлялось</span>
            <?php endif; ?>
        </p>

        <button type="button" class="button gai-index-now" data-post-id="<?php echo esc_attr($post->ID); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">Отправить</button>
        <button type="button" class="button gai-remove-index" data-post-id="<?php echo esc_attr($post->ID); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">Удалить из индекса</button>

        <p style="margin-top:10px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=vin-google-index&mac_tab=logs&post_id=' . (int) $post->ID)); ?>">Смотреть журнал</a>
        </p>

        <script>
            jQuery(document).ready(function($) {
                $('.gai-index-now').click(function() {
                    var $btn = $(this);
                    $.post(ajaxurl, {
                        action: 'gai_index_post',
                        post_id: $btn.data('post-id'),
                        type: 'URL_UPDATED',
                        nonce: $btn.data('nonce')
                    }, function(response) {
                        alert(response.message);
                        if (response.success) {
                            location.reload();
                        }
                    });
                });

                $('.gai-remove-index').click(function() {
                    if (confirm('Удалить URL из индекса Google?')) {
                        var $btn = $(this);
                        $.post(ajaxurl, {
                            action: 'gai_index_post',
                            post_id: $btn.data('post-id'),
                            type: 'URL_DELETED',
                            nonce: $btn.data('nonce')
                        }, function(response) {
                            alert(response.message);
                        });
                    }
                });
            });
        </script>
        <?php
    }

    public function logs_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'google-auto-index'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'gai_logs';

        if (isset($_POST['clear_logs'])) {
            check_admin_referer('gai_clear_logs', 'gai_clear_nonce');
            $wpdb->query("DELETE FROM {$table_name}");
            echo '<div class="notice notice-success"><p>Журнал очищен.</p></div>';
        }

        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset = ($current_page - 1) * $per_page;

        $where = [];
        $params = [];

        if (!empty($_GET['post_id'])) {
            $where[] = 'post_id = %d';
            $params[] = (int) $_GET['post_id'];
        }

        if (!empty($_GET['status'])) {
            $status = sanitize_text_field(wp_unslash($_GET['status']));
            $allowed_status = ['success', 'error', 'skipped'];
            if (in_array($status, $allowed_status, true)) {
                $where[] = 'status = %s';
                $params[] = $status;
            }
        }

        if (!empty($_GET['action'])) {
            $action = sanitize_text_field(wp_unslash($_GET['action']));
            $allowed_actions = ['URL_UPDATED', 'URL_DELETED'];
            if (in_array($action, $allowed_actions, true)) {
                $where[] = 'action = %s';
                $params[] = $action;
            }
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = "SELECT * FROM {$table_name} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$per_page, $offset]);
        $logs = $wpdb->get_results($wpdb->prepare($query, $query_params));

        $count_query = "SELECT COUNT(*) FROM {$table_name} {$where_sql}";
        $total_items = $where ? (int) $wpdb->get_var($wpdb->prepare($count_query, $params)) : (int) $wpdb->get_var($count_query);
        ?>

        <div class="wrap">
            <h1>Журнал индексации Google</h1>

            <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccd0d4;">
                <h3>Фильтры</h3>
                <form method="get">
                    <input type="hidden" name="page" value="vin-google-index">
                    <input type="hidden" name="mac_tab" value="logs">

                    <label>ID товара:
                        <input type="number" name="post_id" value="<?php echo esc_attr($_GET['post_id'] ?? ''); ?>" style="width:100px;">
                    </label>

                    <label style="margin-left:20px;">Действие:
                        <select name="action">
                            <option value="">All</option>
                            <option value="URL_UPDATED" <?php selected($_GET['action'] ?? '', 'URL_UPDATED'); ?>>URL_UPDATED</option>
                            <option value="URL_DELETED" <?php selected($_GET['action'] ?? '', 'URL_DELETED'); ?>>URL_DELETED</option>
                        </select>
                    </label>

                    <input type="submit" class="button" value="Фильтровать" style="margin-left:20px;">
                    <a href="?page=vin-google-index&mac_tab=logs" class="button">Сбросить</a>
                </form>
            </div>

            <div class="tablenav top">
                <div class="tablenav-pages">
                    <?php
                    $total_pages = max(1, (int) ceil($total_items / $per_page));
                    echo wp_kses_post(paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => $total_pages,
                        'current' => $current_page,
                    ]));
                    ?>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Дата</th>
                        <th>Товар</th>
                        <th>Действие</th>
                        <th>Статус</th>
                        <th>URL</th>
                        <th>Сообщение</th>
                        <th>Ответ API</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs): ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $post_title = $log->post_id ? get_the_title($log->post_id) : '-';
                            $post_link = $log->post_id ? admin_url('post.php?post=' . (int) $log->post_id . '&action=edit') : '#';
                            $status_colors = [
                                'success' => 'green',
                                'error' => 'red',
                                'skipped' => 'gray',
                            ];
                            $status_color = $status_colors[$log->status] ?? 'black';
                            ?>
                            <tr>
                                <td><?php echo esc_html($log->id); ?></td>
                                <td><?php echo esc_html(date('d.m.Y H:i:s', strtotime($log->created_at))); ?></td>
                                <td>
                                    <?php if ($log->post_id): ?>
                                        <a href="<?php echo esc_url($post_link); ?>" target="_blank">#<?php echo esc_html($log->post_id); ?>: <?php echo esc_html($post_title); ?></a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log->action); ?></td>
                                <td style="color:<?php echo esc_attr($status_color); ?>;font-weight:bold;"><?php echo esc_html($log->status); ?></td>
                                <td>
                                    <?php if (!empty($log->url)): ?>
                                        <a href="<?php echo esc_url($log->url); ?>" target="_blank" style="font-size:12px;"><?php echo esc_html(mb_strimwidth($log->url, 0, 50, '...')); ?></a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log->message); ?></td>
                                <td>
                                    <?php if (!empty($log->response)): ?>
                                        <details>
                                            <summary>View</summary>
                                            <pre style="white-space:pre-wrap;max-width:420px;"><?php echo esc_html($log->response); ?></pre>
                                        </details>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center;">No logs found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top:20px;">
                <form method="post">
                    <?php wp_nonce_field('gai_clear_logs', 'gai_clear_nonce'); ?>
                    <button type="submit" name="clear_logs" class="button" onclick="return confirm('Clear all logs?')">Clear all logs</button>
                </form>
            </div>
        </div>

        <?php
    }
}


