<?php
/**
 * CryptoCloud Promocodes Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class CryptoCloud_Promocodes {

    public function __construct() {
        add_action('wp_ajax_check_cryptocloud_promocode', array($this, 'check_promocode_ajax'));
        add_action('wp_ajax_nopriv_check_cryptocloud_promocode', array($this, 'check_promocode_ajax'));
        add_action('admin_init', array($this, 'check_promocodes_table_exists'));
    }

    public function check_promocodes_table_exists() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_promocodes';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $this->create_promocodes_table();
            return;
        }

        $comment_column = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'comment'");
        if (!$comment_column) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN comment text NULL AFTER promocode");
        }
    }

    public function create_promocodes_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_promocodes';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            promocode varchar(50) NOT NULL,
            comment text NULL,
            price decimal(10,2) NOT NULL,
            usage_count int DEFAULT 0,
            max_usage int DEFAULT NULL,
            is_active boolean DEFAULT true,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY promocode (promocode)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $this->add_sample_promocodes();
    }

    private function add_sample_promocodes() {
        $sample_promocodes = array(
            array(
                'promocode' => 'WELCOME10',
                'comment' => '',
                'price' => 30.00,
                'max_usage' => 100,
                'is_active' => 1
            ),
            array(
                'promocode' => 'SUMMER2024',
                'comment' => '',
                'price' => 25.00,
                'max_usage' => 50,
                'is_active' => 1
            ),
            array(
                'promocode' => 'CRYPTO20',
                'comment' => '',
                'price' => 20.00,
                'max_usage' => 200,
                'is_active' => 1
            ),
            array(
                'promocode' => 'FREECAR',
                'comment' => '',
                'price' => 0.00,
                'max_usage' => 10,
                'is_active' => 1
            ),
            array(
                'promocode' => 'FREE100',
                'comment' => '',
                'price' => 0.00,
                'max_usage' => 100,
                'is_active' => 1
            )
        );

        foreach ($sample_promocodes as $promo) {
            $this->add_promocode($promo);
        }
    }

    public function admin_page() {
        global $wpdb;

        $this->check_promocodes_table_exists();

        if (isset($_GET['recreate_promocodes_table'])) {
            $this->create_promocodes_table();
            echo '<div class="notice notice-success"><p>Промокоды пересозданы!</p></div>';
        }

        if (isset($_POST['add_promocode'])) {
            $promo_data = array(
                'promocode' => sanitize_text_field($_POST['promocode']),
                'comment' => sanitize_textarea_field($_POST['comment'] ?? ''),
                'price' => floatval($_POST['price']),
                'max_usage' => !empty($_POST['max_usage']) ? intval($_POST['max_usage']) : null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            );

            $result = $this->add_promocode($promo_data);

            if ($result) {
                echo '<div class="notice notice-success"><p>Промокод добавлен!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Ошибка при добавлении промокода: ' . esc_html($wpdb->last_error) . '</p></div>';
            }
        }

        if (isset($_POST['update_promocode'])) {
            $promo_id = intval($_POST['promo_id']);
            $promo_data = array(
                'comment' => sanitize_textarea_field($_POST['comment'] ?? ''),
                'price' => floatval($_POST['price']),
                'max_usage' => !empty($_POST['max_usage']) ? intval($_POST['max_usage']) : null,
                'usage_count' => intval($_POST['usage_count']),
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            );

            $result = $this->update_promocode($promo_id, $promo_data);

            if ($result !== false) {
                echo '<div class="notice notice-success"><p>Промокод обновлен!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Ошибка при обновлении промокода: ' . esc_html($wpdb->last_error) . '</p></div>';
            }
        }

        if (isset($_GET['delete_promocode'])) {
            $result = $this->delete_promocode(intval($_GET['delete_promocode']));

            if ($result) {
                echo '<div class="notice notice-success"><p>Промокод удален!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Ошибка при удалении промокода: ' . esc_html($wpdb->last_error) . '</p></div>';
            }
        }

        $promocodes = $this->get_all_promocodes();
        ?>
        <div class="wrap">
            <h1>Управление промокодами CryptoCloud</h1>

            <div class="card full" style="margin-bottom: 20px;">
                <h3>Добавить новый промокод</h3>
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th>Промокод *</th>
                            <th>Комментарий</th>
                            <th>Цена ($) *</th>
                            <th>Макс. использований</th>
                            <th>Активен</th>
                            <th>Действие</th>
                        </tr>
                        <tr>
                            <td><input type="text" name="promocode" placeholder="FREECAR" class="regular-text" required /></td>
                            <td><input type="text" name="comment" placeholder="Комментарий" class="regular-text" /></td>
                            <td>
                                <input type="number" step="0.01" name="price" value="0" class="small-text" required />
                                <p class="description" style="font-size: 12px; margin: 0;">0 = бесплатно</p>
                            </td>
                            <td><input type="number" name="max_usage" placeholder="Неограничено" class="small-text" /></td>
                            <td><input type="checkbox" name="is_active" value="1" checked /></td>
                            <td><button type="submit" name="add_promocode" class="button button-primary">Добавить</button></td>
                        </tr>
                    </table>
                </form>
            </div>

            <div class="card full">
                <h3>Список промокодов</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Промокод</th>
                            <th>Комментарий</th>
                            <th>Цена</th>
                            <th>Использовано</th>
                            <th>Макс. использований</th>
                            <th>Статус</th>
                            <th>Дата создания</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($promocodes): ?>
                            <?php foreach ($promocodes as $promo): ?>
                                <tr>
                                    <td><?php echo $promo->id; ?></td>
                                    <td><strong><?php echo esc_html($promo->promocode); ?></strong></td>
                                    <td><?php echo esc_html($promo->comment ?? ''); ?></td>
                                    <td>
                                        <?php if ($promo->price == 0): ?>
                                            <span style="color: #28a745; font-weight: bold;">БЕСПЛАТНО</span>
                                        <?php else: ?>
                                            $<?php echo number_format($promo->price, 2); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $promo->usage_count; ?></td>
                                    <td><?php echo $promo->max_usage ? $promo->max_usage : '∞'; ?></td>
                                    <td>
                                        <span class="cryptocloud-status cryptocloud-status-<?php echo $promo->is_active ? 'active' : 'inactive'; ?>">
                                            <?php echo $promo->is_active ? 'Активен' : 'Неактивен'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($promo->created_at)); ?></td>
                                    <td>
                                        <button type="button" class="button button-small edit-promo"
                                                data-promo='<?php echo wp_json_encode($promo); ?>'>Редактировать</button>
                                        <a href="?page=cryptocloud-promocodes&delete_promocode=<?php echo $promo->id; ?>"
                                           class="button button-small button-link-delete"
                                           onclick="return confirm('Удалить промокод?')">Удалить</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center;">Нет промокодов</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="edit-promo-modal" style="display:none;">
                <div class="cryptocloud-modal">
                    <div>
                        <h3>Редактировать промокод</h3>
                        <form method="post" id="edit-promo-form">
                            <input type="hidden" name="promo_id" id="edit-promo-id">
                            <table class="form-table">
                                <tr>
                                    <th>Промокод</th>
                                    <td><strong id="edit-promo-code"></strong></td>
                                </tr>
                                <tr>
                                    <th>Комментарий</th>
                                    <td><textarea name="comment" id="edit-promo-comment" rows="3" class="large-text"></textarea></td>
                                </tr>
                                <tr>
                                    <th>Цена ($)</th>
                                    <td>
                                        <input type="number" step="0.01" name="price" id="edit-promo-price" class="small-text" required />
                                        <p class="description" style="font-size: 12px; margin: 0;">0 = бесплатно</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Использовано</th>
                                    <td><input type="number" name="usage_count" id="edit-promo-usage" class="small-text" required /></td>
                                </tr>
                                <tr>
                                    <th>Макс. использований</th>
                                    <td><input type="number" name="max_usage" id="edit-promo-max" class="small-text" placeholder="Неограничено" /></td>
                                </tr>
                                <tr>
                                    <th>Активен</th>
                                    <td><input type="checkbox" name="is_active" id="edit-promo-active" value="1" /></td>
                                </tr>
                            </table>
                            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                                <button type="button" id="edit-promo-cancel" class="button">Отмена</button>
                                <button type="submit" name="update_promocode" class="button button-primary">Сохранить</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <style>
            .cryptocloud-status-active {
                background: #28a745;
                color: white;
                border: none;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
            }
            .cryptocloud-status-inactive {
                background: #6c757d;
                color: white;
                border: none;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
            }
            .cryptocloud-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
            }
            .cryptocloud-modal > div {
                background: white;
                padding: 30px;
                border-radius: 5px;
                min-width: 500px;
                max-width: 90%;
            }
            </style>

            <script>
            jQuery(document).ready(function($) {
                $('.edit-promo').on('click', function() {
                    var promo = $(this).data('promo');
                    $('#edit-promo-id').val(promo.id);
                    $('#edit-promo-code').text(promo.promocode);
                    $('#edit-promo-comment').val(promo.comment || '');
                    $('#edit-promo-price').val(promo.price);
                    $('#edit-promo-usage').val(promo.usage_count);
                    $('#edit-promo-max').val(promo.max_usage || '');
                    $('#edit-promo-active').prop('checked', promo.is_active == 1);
                    $('#edit-promo-modal').show();
                });

                $('#edit-promo-cancel').on('click', function() {
                    $('#edit-promo-modal').hide();
                });

                $('#edit-promo-modal').on('click', function(e) {
                    if (e.target === this) {
                        $(this).hide();
                    }
                });
            });
            </script>
        </div>
        <?php
    }

    public function check_promocode($promocode) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_promocodes';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE promocode = %s AND is_active = 1",
            $promocode
        ));

        if ($result) {
            if ($result->max_usage && $result->usage_count >= $result->max_usage) {
                return [
                    'valid' => false,
                    'message' => 'The promo code is exhausted',
                    'is_free' => false
                ];
            }

            return [
                'valid' => true,
                'price' => floatval($result->price),
                'is_free' => floatval($result->price) == 0,
                'usage_count' => $result->usage_count,
                'max_usage' => $result->max_usage,
                'promocode' => $result->promocode
            ];
        }

        return [
            'valid' => false,
            'message' => 'Promo code not found',
            'is_free' => false
        ];
    }

    public function use_promocode($promocode) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_promocodes';

        $wpdb->update(
            $table_name,
            ['usage_count' => $wpdb->get_var($wpdb->prepare(
                "SELECT usage_count FROM $table_name WHERE promocode = %s", $promocode
            )) + 1],
            ['promocode' => $promocode],
            ['%d'],
            ['%s']
        );

        return $wpdb->rows_affected;
    }

    public function check_promocode_ajax() {
        check_ajax_referer('cryptocloud_nonce', 'nonce');

        $promocode = sanitize_text_field($_POST['promocode'] ?? '');
        $base_price = floatval($_POST['base_price'] ?? 40);

        if (!$promocode) {
            wp_send_json_error('Введите промокод');
        }

        $result = $this->check_promocode($promocode);

        if ($result['valid']) {
            $response_data = [
                'new_price' => $result['price'],
                'is_free' => $result['is_free'],
                'promocode' => $result['promocode'],
                'message' => $result['is_free']
                    ? 'рџЋ‰ The promo code has been applied! The car will be hidden for free!'
                    : 'Promo code applied! New price: $' . $result['price'],
                'usage_info' => $result['max_usage']
                    ? "Used: {$result['usage_count']}/{$result['max_usage']}"
                    : ''
            ];

            wp_send_json_success($response_data);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function get_all_promocodes() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_promocodes';

        return $wpdb->get_results("
            SELECT * FROM $table_name
            ORDER BY
                CASE WHEN price = 0 THEN 0 ELSE 1 END,
                price ASC,
                created_at DESC
        ");
    }

    public function add_promocode($data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_promocodes';

        return $wpdb->insert(
            $table_name,
            $data,
            array('%s', '%s', '%f', '%d', '%d', '%d')
        );
    }

    public function update_promocode($id, $data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_promocodes';

        return $wpdb->update(
            $table_name,
            $data,
            ['id' => $id],
            array('%s', '%f', '%d', '%d', '%d'),
            array('%d')
        );
    }

    public function delete_promocode($id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_promocodes';

        return $wpdb->delete(
            $table_name,
            ['id' => $id],
            array('%d')
        );
    }
}
