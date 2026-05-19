<?php

/**
 * Module Name: AskarTech | Heleket Payment Gateway
 * Description: Интеграция платежной системы Heleket для WordPress
 * Version: 3.0
 * Author: AskarTech
 */

// Запрещаем прямой доступ
if (!defined('ABSPATH')) {
    exit;
}

// Подключаем класс промокодов
require_once plugin_dir_path(__FILE__) . 'includes/class-heleket-promocodes.php';

if (!class_exists('Heleket_Promocodes')) {
    die('Heleket Promocodes class not loaded! Check file path.');
}

class HeleketPaymentGateway
{

    private $api_url = 'https://api.heleket.com/v1/payment';
    public $promocodes;

    public function __construct()
    {
        // Для фронтенда
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Для админки
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

        // Инициализируем промокоды
        $this->promocodes = new Heleket_Promocodes();

        add_action('init', array($this, 'init'));
        add_action('wp_ajax_create_heleket_payment', array($this, 'create_payment'));
        add_action('wp_ajax_nopriv_create_heleket_payment', array($this, 'create_payment'));

        // Для обработки промокодов

        // Добавляем настройки
        add_action('admin_init', array($this, 'register_settings'));

        // ВЕБХУКИ
        add_action('rest_api_init', array($this, 'register_webhook_routes'));
        add_action('heleket_check_pending_payments', array($this, 'check_pending_payments'));
        add_action('init', array($this, 'schedule_payment_checks'));

        // Создаем таблицы при активации
    }

    public function activate_plugin()
    {
        $this->create_payments_table();
        $this->promocodes->create_promocodes_table();
    }

    public function init()
    {
        add_shortcode('heleket_button', array($this, 'heleket_button_shortcode'));
        add_shortcode('heleket_success', array($this, 'payment_success_shortcode'));
    }

    public function enqueue_scripts()
    {
        // Подключаем JavaScript для фронтенда
        wp_enqueue_script('jquery');
        wp_enqueue_script('heleket-js', plugin_dir_url(__FILE__) . 'assets/js/heleket.js', array('jquery'), '3.0', true);
        wp_localize_script('heleket-js', 'heleket_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('heleket_nonce'),
            'current_url' => home_url($_SERVER['REQUEST_URI'])
        ));

        // Подключаем стили для фронтенда
        wp_enqueue_style('heleket-frontend', plugin_dir_url(__FILE__) . 'assets/css/heleket-frontend.css', array(), '3.0');
    }

    // Отдельный метод для админских стилей
    public function enqueue_admin_styles()
    {
        // Подключаем стили для админки
        wp_enqueue_style('heleket-admin', plugin_dir_url(__FILE__) . 'assets/css/heleket-admin.css', array(), '3.0');
    }

    // Регистрация настроек
    public function register_settings()
    {
        register_setting('heleket_settings', 'heleket_merchant_id');
        register_setting('heleket_settings', 'heleket_api_key');
    }

    // Создание таблицы для хранения оплат
    public function create_payments_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'heleket_payments';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(100) NOT NULL,
            payment_id varchar(100),
            amount decimal(10,2) NOT NULL,
            currency varchar(10) DEFAULT 'USD',
            customer_email varchar(100),
            customer_telegram varchar(100),
            product_url text,
            post_id bigint(20),
            payment_status varchar(50),
            promocode varchar(50) DEFAULT '',
            payment_date datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_id (order_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Сохранение платежа в БД
    private function save_payment_to_db($payment_data)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'heleket_payments';

        // Устанавливаем значения по умолчанию
        $defaults = array(
            'payment_id' => '',
            'currency' => 'USD',
            'customer_email' => '',
            'customer_telegram' => '',
            'product_url' => '',
            'post_id' => 0,
            'payment_status' => 'pending',
            'promocode' => '',
            'payment_date' => current_time('mysql')
        );

        $payment_data = wp_parse_args($payment_data, $defaults);

        // Подготавливаем данные и форматы (12 полей с promocode)
        $data_to_insert = array(
            'order_id' => $payment_data['order_id'],
            'payment_id' => $payment_data['payment_id'],
            'amount' => floatval($payment_data['amount']),
            'currency' => $payment_data['currency'],
            'customer_email' => $payment_data['customer_email'],
            'customer_telegram' => $payment_data['customer_telegram'],
            'product_url' => $payment_data['product_url'],
            'post_id' => intval($payment_data['post_id']),
            'payment_status' => $payment_data['payment_status'],
            'promocode' => $payment_data['promocode'],  // Вот здесь
            'payment_date' => $payment_data['payment_date']
        );

        $format_to_insert = array(
            '%s', // order_id
            '%s', // payment_id
            '%f', // amount
            '%s', // currency
            '%s', // customer_email
            '%s', // customer_telegram
            '%s', // product_url
            '%d', // post_id
            '%s', // payment_status
            '%s', // promocode - ДОБАВЛЕНО
            '%s'  // payment_date
        );

        $result = $wpdb->insert(
            $table_name,
            $data_to_insert,
            $format_to_insert
        );

        return $result !== false;
    }

    // Метод для логирования
    private function log($message, $data = null)
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;

        $log_message = '[Heleket] ' . $message;
        if ($data) {
            $log_message .= ' | Data: ' . print_r($data, true);
        }

        error_log($log_message);

        // Также пишем в файл для надежности
        $log_file = WP_CONTENT_DIR . '/heleket-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($log_file, "[$timestamp] $log_message\n", FILE_APPEND | LOCK_EX);
    }

    // Метод для проверки доступности REST API
    public function test_rest_api()
    {
        $rest_url = home_url('/wp-json/heleket/v1/webhook');
        $this->log('REST API Test URL: ' . $rest_url);

        $response = wp_remote_get($rest_url);
        if (is_wp_error($response)) {
            $this->log('REST API Test FAILED: ' . $response->get_error_message());
        } else {
            $this->log('REST API Test SUCCESS. Response: ' . wp_remote_retrieve_body($response));
        }
    }

    // Для начального сохранения платежа
    private function save_initial_payment($order_id, $amount, $email, $telegram, $product_url, $promocode = '')
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'heleket_payments';

        // Проверяем, не существует ли уже запись
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE order_id = %s",
            $order_id
        ));

        if (!$existing) {
            return $wpdb->insert(
                $table_name,
                array(
                    'order_id' => $order_id,
                    'payment_id' => '',
                    'amount' => $amount,
                    'currency' => 'USD',
                    'customer_email' => $email,
                    'customer_telegram' => $telegram,
                    'product_url' => $product_url,
                    'post_id' => 0,
                    'payment_status' => 'pending',
                    'promocode' => $promocode  // Вот здесь
                ),
                array('%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s') // 10 форматов
            );
        }

        return true;
    }

    // Получение всех платежей
    public function get_all_payments()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'heleket_payments';

        return $wpdb->get_results("
            SELECT * FROM $table_name 
            ORDER BY created_at DESC
        ");
    }

    // Добавление платежа вручную
    public function add_manual_payment($data)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'heleket_payments';

        // Проверяем обязательные поля
        if (empty($data['amount']) || empty($data['product_url'])) {
            return false;
        }

        // Подготавливаем данные для вставки
        $insert_data = array(
            'order_id' => 'manual_' . time() . '_' . uniqid(),
            'payment_id' => '',
            'amount' => floatval($data['amount']),
            'currency' => 'USD',
            'customer_email' => sanitize_email($data['customer_email'] ?? ''),
            'customer_telegram' => sanitize_text_field($data['customer_telegram'] ?? ''),
            'product_url' => sanitize_url($data['product_url']),
            'post_id' => 0,
            'payment_status' => sanitize_text_field($data['payment_status'] ?? 'completed')
        );

        return $wpdb->insert(
            $table_name,
            $insert_data,
            array('%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s')
        );
    }


    // Регистрация REST API routes для вебхуков
    public function register_webhook_routes()
    {
        register_rest_route('heleket/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    // Обработчик вебхука
    public function handle_webhook($request)
    {
        $this->log('=== WEBHOOK START ===');

        // Получаем все возможные данные
        $params = $request->get_params();
        $body = $request->get_body();
        $headers = $request->get_headers();

        $this->log('Webhook received - Params:', $params);
        $this->log('Webhook received - Raw body:', $body);
        $this->log('Webhook received - Headers:', $headers);

        // Пробуем разные варианты получения данных
        $order_id = $params['order_id'] ?? '';
        $status = $params['payment_status'] ?? $params['status'] ?? '';

        // Если данные в теле запроса (JSON)
        if (empty($order_id) && !empty($body)) {
            $body_data = json_decode($body, true);
            if ($body_data) {
                $order_id = $body_data['order_id'] ?? '';
                $status = $body_data['payment_status'] ?? $body_data['status'] ?? '';
                $this->log('Parsed from body:', $body_data);
            }
        }

        $this->log("Extracted - Order ID: {$order_id}, Status: {$status}");

        // Проверяем, что это уведомление об оплате
        $paid_statuses = ['paid', 'paid_over', 'success', 'completed'];
        if (in_array($status, $paid_statuses) && !empty($order_id)) {
            $this->log('Processing paid payment');

            // Проверяем не обработан ли уже этот платеж
            global $wpdb;
            $table_name = $wpdb->prefix . 'heleket_payments';
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE order_id = %s",
                $order_id
            ));

            if ($existing) {
                $this->log('Payment exists in DB:', $existing);

                // Если статус еще не completed - обновляем
                if ($existing->payment_status !== 'completed') {
                    $this->log('Updating payment status to completed');

                    $wpdb->update(
                        $table_name,
                        array('payment_status' => 'completed'),
                        array('order_id' => $order_id),
                        array('%s'),
                        array('%s')
                    );

                    // Обрабатываем успешный платеж
                    $this->process_successful_payment(array(
                        'order_id' => $order_id,
                        'additional_data' => json_encode(array(
                            'u' => $existing->product_url,
                            'e' => $existing->customer_email,
                            't' => $existing->customer_telegram ?: ''
                        ))
                    ));

                    $this->log('Payment successfully processed and product hidden');
                } else {
                    $this->log('Payment already completed, skipping');
                }
            } else {
                $this->log('Payment not found in DB - cannot process without product URL');

                // Создаем запись но НЕ как completed, чтобы cron мог позже обработать
                $this->save_payment_to_db(array(
                    'order_id' => $order_id,
                    'payment_id' => $params['payment_id'] ?? $params['uuid'] ?? '',
                    'amount' => $params['amount'] ?? '0',
                    'customer_email' => $params['payer_email'] ?? '',
                    'customer_telegram' => '',
                    'product_url' => '',
                    'post_id' => 0,
                    'payment_status' => 'pending' // Оставляем pending чтобы найти позже
                ));

                $this->log('Payment saved as pending - will be processed by cron');
            }

            $this->log('=== WEBHOOK COMPLETED SUCCESSFULLY ===');
            return new WP_REST_Response(['status' => 'success', 'message' => 'Payment processed'], 200);
        }

        $this->log('=== WEBHOOK IGNORED (not a paid status) ===');
        return new WP_REST_Response(['status' => 'ignored', 'message' => 'Not a paid status'], 200);
    }

    // Ручной вызов вебхука для тестирования
    public function test_webhook_manual()
    {
        if (!isset($_GET['test_webhook'])) return;

        $test_data = array(
            'order_id' => 'wp_' . time() . '_test',
            'payment_status' => 'paid',
            'amount' => '40.00',
            'payer_email' => 'test@example.com'
        );

        $this->log('Manual webhook test:', $test_data);

        // Имитируем вебхук запрос
        $request = new WP_REST_Request('POST', '/heleket/v1/webhook');
        $request->set_body_params($test_data);

        $result = $this->handle_webhook($request);

        wp_die('Webhook test completed. Check logs.');
    }

    // Временный метод для логирования реальных вебхуков
    public function log_real_webhook_data()
    {
        if (!isset($_GET['log_real_webhook'])) return;

        $this->log('REAL WEBHOOK DUMP - POST:', $_POST);
        $this->log('REAL WEBHOOK DUMP - GET:', $_GET);
        $this->log('REAL WEBHOOK DUMP - INPUT:', file_get_contents('php://input'));
        $this->log('REAL WEBHOOK DUMP - HEADERS:', getallheaders());

        wp_die('Real webhook data logged');
    }


    // Тестовая страница для отладки
    public function debug_page()
    {
        if (!current_user_can('manage_options')) return;

        $this->test_rest_api();

        echo '<div class="wrap">';
        echo '<h1>Heleket Debug</h1>';

        // Проверка REST API
        $rest_url = home_url('/wp-json/heleket/v1/webhook');
        echo '<h3>REST API Endpoint:</h3>';
        echo '<p><code>' . $rest_url . '</code></p>';

        // Проверка доступности
        $response = wp_remote_get($rest_url);
        if (is_wp_error($response)) {
            echo '<div class="error"><p>REST API недоступен: ' . $response->get_error_message() . '</p></div>';
        } else {
            echo '<div class="updated"><p>REST API доступен. Код ответа: ' . wp_remote_retrieve_response_code($response) . '</p></div>';
        }

        // Показать последние логи
        $log_file = WP_CONTENT_DIR . '/heleket-debug.log';
        if (file_exists($log_file)) {
            echo '<h3>Последние логи:</h3>';
            $logs = file_get_contents($log_file);
            echo '<pre style="background: #f1f1f1; padding: 10px; overflow: auto; max-height: 400px;">';
            echo esc_html($logs);
            echo '</pre>';
        }

        echo '</div>';
    }

    // Планировщик для проверки необработанных платежей
    public function schedule_payment_checks()
    {
        if (!wp_next_scheduled('heleket_check_pending_payments')) {
            wp_schedule_event(time(), 'hourly', 'heleket_check_pending_payments');
        }
    }

    // Проверка необработанных платежей
    public function check_pending_payments()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'heleket_payments';

        // Получаем order_id платежей, которые созданы но не обработаны
        // за последние 24 часа
        $pending_orders = $wpdb->get_col("
			SELECT order_id FROM $table_name 
			WHERE payment_status = 'pending' 
			AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
		");

        foreach ($pending_orders as $order_id) {
            $result = $this->check_and_process_payment($order_id);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Heleket cron check for {$order_id}: " . $result['status']);
            }
        }
    }

    // Генерация подписи
    private function generate_sign($data, $api_key)
    {
        $json_data = json_encode($data);
        $base64_data = base64_encode($json_data);
        $sign_string = $base64_data . $api_key;
        return md5($sign_string);
    }

    // Шорткод для кнопки
    public function heleket_button_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'id' => 'heleket-pay-button',
            'amount' => '40',
            'text' => 'Оплатить $40'
        ), $atts);

        return '<button id="' . esc_attr($atts['id']) . '" 
                class="heleket-pay-button" 
                data-amount="' . esc_attr($atts['amount']) . '">' .
            esc_html($atts['text']) . '</button>';
    }

    // Создание платежа
    public function create_payment()
    {
        check_ajax_referer('heleket_nonce', 'nonce');

        // Получаем данные из запроса
        $amount = floatval($_POST['amount']);
        $product_url = sanitize_url($_POST['product_url']);
        $email = sanitize_email($_POST['email']);
        $telegram = sanitize_text_field($_POST['telegram']);
        $promocode = sanitize_text_field($_POST['promocode'] ?? '');

        // Проверяем промокод
        $is_free_payment = false;
        if ($promocode) {
            $promo_result = $this->promocodes->check_promocode($promocode);
            if ($promo_result['valid']) {
                $amount = $promo_result['price'];

                // Если промокод бесплатный
                if ($promo_result['is_free'] && $amount == 0) {
                    $is_free_payment = true;

                    // Генерируем уникальный order_id для бесплатного заказа
                    $order_id = 'free_' . time() . '_' . uniqid();

                    // Используем промокод
                    $promocode_used = $this->promocodes->use_promocode($promocode);
                    if (!$promocode_used) {
                        wp_send_json_error('Error using promocode');
                        wp_die();
                    }

                    // Скрываем товар
                    $hidden_result = $this->hide_product_and_complete_payment($product_url, $email, $telegram, $promocode, $order_id);

                    if (!$hidden_result['success']) {
                        wp_send_json_error('Error hiding product: ' . $hidden_result['message']);
                        wp_die();
                    }

                    // Сохраняем платеж в БД как completed
                    $saved = $this->save_payment_to_db(array(
                        'order_id' => $order_id,
                        'payment_id' => 'FREE_' . $promocode . '_' . time(),
                        'amount' => 0,
                        'customer_email' => $email,
                        'customer_telegram' => $telegram,
                        'product_url' => $product_url,
                        'post_id' => $hidden_result['post_id'],
                        'payment_status' => 'completed',
                        'promocode' => $promocode
                    ));

                    if (!$saved) {
                        wp_send_json_error('Error saving payment to database');
                        wp_die();
                    }

                    // Возвращаем успех с redirect_url на страницу благодарности
                    $success_url = home_url('/payment-success?order_id=' . $order_id . '&free=1');

                    $this->log('Free payment completed successfully. Redirecting to: ' . $success_url);

                    wp_send_json_success(array(
                        'is_free' => true,
                        'redirect_url' => $success_url,
                        'message' => 'Free promocode applied! Product hidden.'
                    ));
                    wp_die();
                } else {
                    // Обычный промокод со скидкой
                    $this->promocodes->use_promocode($promocode);
                }
            }
        }

        if (!$email || !$amount) {
            wp_send_json_error('Incorrect information: email address and amount required');
        }

        // Настройки API
        $merchant_id = get_option('heleket_merchant_id');
        $api_key = get_option('heleket_api_key');

        if (!$merchant_id || !$api_key) {
            wp_send_json_error('The payment system is not configured. Check the plugin settings.');
        }

        // Генерируем order_id
        $order_id = 'wp_' . time() . '_' . uniqid();

        // Сохраняем начальную запись о платеже
        $initial_save = $this->save_initial_payment($order_id, $amount, $email, $telegram, $product_url, $promocode);

        // Данные для создания платежа
        $additional_data = array(
            'u' => $product_url,
            'e' => $email,
            't' => $telegram,
            'promo' => $promocode,
            'uid' => get_current_user_id()
        );

        // Ограничиваем длину до 255 символов
        $additional_data_string = json_encode($additional_data);
        if (strlen($additional_data_string) > 255) {
            $additional_data_string = substr($additional_data_string, 0, 252) . '..."';
        }

        $payment_data = array(
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'USD',
            'order_id' => $order_id,
            'url_success' => home_url('/payment-success?order_id=' . $order_id),
            'url_callback' => home_url('/wp-json/heleket/v1/webhook'),
            'is_payment_multiple' => false,
            'lifetime' => 3600,
            'additional_data' => $additional_data_string,
            'payer_email' => $email
        );

        // Генерируем подпись
        $sign = $this->generate_sign($payment_data, $api_key);

        // Отправляем запрос к API Heleket
        $response = wp_remote_post($this->api_url, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'merchant' => $merchant_id,
                'sign' => $sign
            ),
            'body' => json_encode($payment_data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Connection error with payment system: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($response_code !== 200) {
            $error_message = "HTTP error: {$response_code}";
            if (isset($data['message'])) {
                $error_message .= ' - ' . $data['message'];
            }
            wp_send_json_error($error_message);
        }

        if (isset($data['state']) && $data['state'] === 0 && isset($data['result']['url'])) {
            wp_send_json_success(array(
                'is_free' => false,
                'payment_url' => $data['result']['url']
            ));
        } else {
            wp_send_json_error('Error creating payment');
        }
    }

    private function hide_product_and_complete_payment($product_url, $email, $telegram, $promocode, $order_id)
    {
        // Очищаем URL от мусора
        $clean_url = preg_replace('/\/undefined$/i', '', $product_url);
        $clean_url = preg_replace('/\/null$/i', '', $clean_url);
        $clean_url = rtrim($clean_url, '/');

        $result = array(
            'success' => false,
            'post_id' => 0,
            'message' => ''
        );

        $this->log('FREE PROMOCODE: Processing - URL: ' . $clean_url . ', Promo: ' . $promocode);

        // Находим ID поста
        $post_id = url_to_postid($clean_url);

        if (!$post_id) {
            // Пробуем найти другим способом
            $post_id = attachment_url_to_postid($clean_url);
            if (!$post_id) {
                // Пробуем парсить URL для получения ID товара
                $url_parts = parse_url($clean_url);
                $path = $url_parts['path'] ?? '';

                if (preg_match('/product\/(.+?)\//', $path, $matches)) {
                    $slug = $matches[1];
                    $post = get_page_by_path($slug, OBJECT, 'product');
                    if ($post) {
                        $post_id = $post->ID;
                    }
                }
            }
        }

        if ($post_id) {
            // Переводим пост в черновики
            $update_result = wp_update_post(array(
                'ID' => $post_id,
                'post_status' => 'draft'
            ));

            if (!is_wp_error($update_result)) {
                $result['success'] = true;
                $result['post_id'] = $post_id;
                $result['message'] = 'Product successfully hidden';
                $this->log('FREE PROMOCODE: Product hidden - Post ID: ' . $post_id);
            } else {
                $result['message'] = 'Error hiding product: ' . $update_result->get_error_message();
                $this->log('FREE PROMOCODE ERROR: ' . $result['message']);
            }
        } else {
            $result['message'] = 'Product not found by URL: ' . $clean_url;
            $this->log('FREE PROMOCODE ERROR: ' . $result['message']);
        }

        return $result;
    }

    private function process_successful_payment($payment_data)
    {
        $additional_data = json_decode($payment_data['additional_data'] ?? '{}', true);
        $product_url = $additional_data['u'] ?? '';
        $customer_email = $additional_data['e'] ?? '';
        $customer_telegram = $additional_data['t'] ?? '';
        $promocode = $additional_data['promo'] ?? '';

        // Находим ID поста по URL товара
        $post_id = url_to_postid($product_url);

        if ($post_id) {
            // Переводим пост в черновики
            wp_update_post(array(
                'ID' => $post_id,
                'post_status' => 'draft'
            ));
        }

        // Сохраняем платеж в таблицу
        $this->save_payment_to_db(array(
            'order_id' => $payment_data['order_id'] ?? '',
            'payment_id' => $payment_data['uuid'] ?? '',
            'amount' => $payment_data['amount'] ?? '0',
            'customer_email' => $customer_email,
            'customer_telegram' => $customer_telegram,
            'product_url' => $product_url,
            'post_id' => $post_id,
            'payment_status' => 'completed'
        ));

        // Если был использован промокод - увеличиваем счетчик
        if (!empty($promocode)) {
            // Используем существующий метод из класса промокодов
            $this->promocodes->use_promocode($promocode);
        }
    }

    // Шорткод для страницы успеха
    public function payment_success_shortcode()
    {
        $order_id = sanitize_text_field($_GET['order_id'] ?? '');
        $is_free = isset($_GET['free']) && $_GET['free'] == '1';

        // Если это бесплатный промокод
        if ($is_free && !empty($order_id)) {
            // Проверяем, существует ли такой заказ в базе
            global $wpdb;
            $table_name = $wpdb->prefix . 'heleket_payments';
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE order_id = %s",
                $order_id
            ));

            if ($payment) {
                // Автоматически обновляем статус для бесплатных платежей
                if ($payment->payment_status !== 'completed') {
                    $wpdb->update(
                        $table_name,
                        array('payment_status' => 'completed'),
                        array('order_id' => $order_id),
                        array('%s'),
                        array('%s')
                    );
                    $payment->payment_status = 'completed';
                }

                // Используем промокод из столбца promocode
                $promocode = $payment->promocode ?: '';

                return '<div class="heleket-success-page">
                    <h3>🎉 Free Promocode Applied!</h3>		
                    <p>The product has been successfully hidden from the site.</p>
                    <p><strong>Order ID:</strong> ' . esc_html($payment->order_id) . '</p>' .
                    (!empty($promocode) ? '<p><strong>Promocode:</strong> ' . esc_html($promocode) . '</p>' : '') .
                    '<p><strong>Status:</strong> Free via promocode</p>
                    <p><strong>Date:</strong> ' . date('d.m.Y H:i', strtotime($payment->created_at)) . '</p>
                    <p>Thank you for using our service!</p>
                </div>';
            } else {
                return '<div class="heleket-error-page">
                    <h3>❌ Error</h3>
                    <p>Order not found or not yet processed.</p>
                    <p>Please wait a few minutes or contact support.</p>
                </div>';
            }
        }

        // Обычная проверка платежа (для платных заказов)
        if (!$order_id) {
            return '<div class="heleket-error-page">
                <h3>❌ Error</h3>
                <p>Order ID is not specified</p>
                <p>Please return to the product page and try again.</p>
            </div>';
        }

        // Проверяем статус платежа
        $result = $this->check_and_process_payment($order_id);

        if ($result['status'] === 'paid' || $result['status'] === 'success' || $result['status'] === 'completed') {
            return '<div class="heleket-success-page">
                <h3>✅ Payment Successfully Completed!</h3>		
                <p>The product has been hidden from the site.</p>
                <p><strong>Order ID:</strong> ' . esc_html($order_id) . '</p>
                <p>Thank you for your purchase!</p>
            </div>';
        } else {
            return '<div class="heleket-pending-page">
                <h3>⏳ Payment Processing</h3>
                <p>Status: ' . esc_html($result['message']) . '</p>
                <p>We will notify you when the payment is confirmed.</p>
                <p>This usually takes a few minutes.</p>
            </div>';
        }
    }

    public function check_and_process_payment($order_id)
    {
        $merchant_id = get_option('heleket_merchant_id');
        $api_key = get_option('heleket_api_key');

        if (!$merchant_id || !$api_key) {
            return ['status' => 'error', 'message' => 'Платежная система не настроена'];
        }

        // Проверяем не обработан ли уже этот платеж
        global $wpdb;
        $table_name = $wpdb->prefix . 'heleket_payments';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE order_id = %s",
            $order_id
        ));

        if ($existing) {
            return ['status' => 'paid', 'message' => 'Платеж уже обработан'];
        }

        // Получаем информацию о платеже
        $check_data = array('order_id' => $order_id);
        $sign = $this->generate_sign($check_data, $api_key);

        $response = wp_remote_post('https://api.heleket.com/v1/payment/info', array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'merchant' => $merchant_id,
                'sign' => $sign
            ),
            'body' => json_encode($check_data),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return ['status' => 'error', 'message' => 'Ошибка соединения: ' . $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Проверяем успешный ответ
        if (isset($data['state']) && $data['state'] === 0 && isset($data['result'])) {
            $payment_data = $data['result'];
            $status = $payment_data['payment_status'] ?? 'unknown';

            // Если платеж оплачен, обрабатываем его
            if ($status === 'paid' || $status === 'paid_over') {
                $this->process_successful_payment($payment_data);
                return ['status' => 'paid', 'message' => 'Платеж успешно обработан'];
            }

            return ['status' => $status, 'message' => 'Статус платежа: ' . $status];
        }

        // Если ошибка, выводим детали
        if (isset($data['state']) && $data['state'] === 1) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Неизвестная ошибка API';
            return ['status' => 'error', 'message' => 'Ошибка API: ' . $error_msg];
        }

        return ['status' => 'unknown', 'message' => 'Неизвестный ответ API'];
    }
}

$GLOBALS['mac_heleket_gateway'] = new HeleketPaymentGateway();

function mac_get_heleket_gateway()
{
    if (!isset($GLOBALS['mac_heleket_gateway']) || !is_object($GLOBALS['mac_heleket_gateway'])) {
        $GLOBALS['mac_heleket_gateway'] = new HeleketPaymentGateway();
    }

    return $GLOBALS['mac_heleket_gateway'];
}

// Добавляем страницу настроек в админке

// Заменяем функцию heleket_admin_menu на эту:
function heleket_admin_menu()
{
    if (defined('MAC_MASTER_ACTIVE') && MAC_MASTER_ACTIVE) {
        return;
    }

    // Создаем отдельный пункт главного меню для Heleket
    add_menu_page(
        'AskarTech | Heleket Payments',           // Title страницы
        'AskarTech | Heleket Payments',           // Название в меню
        'manage_options',             // Права доступа
        'heleket-settings',           // Slug
        'heleket_settings_page',      // Функция отображения
        'dashicons-money-alt',        // Иконка (можно поменять)
        47                            // Позиция в меню (после комментариев)
    );

    // Добавляем подменю "Настройки"
    add_submenu_page(
        'heleket-settings',
        'Settings - Heleket Payments',
        'Settings',
        'manage_options',
        'heleket-settings',
        'heleket_settings_page'
    );

    // Добавляем подменю "Промокоды"
    add_submenu_page(
        'heleket-settings',
        'Promocodes - Heleket Payments',
        'Promocodes',
        'manage_options',
        'heleket-promocodes',
        'heleket_promocodes_page'
    );

    // Добавляем подменю "История оплат" 
    add_submenu_page(
        'heleket-settings',
        'Payment History - Heleket Payments',
        'Payment History',
        'manage_options',
        'heleket-payments',
        'heleket_payments_page'
    );
    add_submenu_page(
        'heleket-settings',
        'Debug - Heleket Payments',
        'Debug',
        'manage_options',
        'heleket-debug',
        'heleket_debug_page'
    );
}

function heleket_debug_page()
{
    $gateway = mac_get_heleket_gateway();
    $gateway->debug_page();
}

function heleket_settings_page()
{
?>
    <div class="wrap">
        <h1>Настройки Heleket</h1>

        <!-- Настройки API -->
        <div class="card">
            <h3>API настройки</h3>
            <form method="post" action="options.php">
                <?php
                settings_fields('heleket_settings');
                do_settings_sections('heleket_settings');
                ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Merchant ID</th>
                        <td>
                            <input type="text" name="heleket_merchant_id" value="<?php echo esc_attr(get_option('heleket_merchant_id')); ?>" class="regular-text" />
                            <p class="description">Merchant ID (UUID) from Heleket account</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" name="heleket_api_key" value="<?php echo esc_attr(get_option('heleket_api_key')); ?>" class="regular-text" />
                            <p class="description">API key from Heleket account</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Шорткод для страницы благодарности</th>
                        <td>
                            <p class="description">[heleket_success]</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Шорткод для кнопки покупки</th>
                        <td>
                            <p class="description">[heleket_button amount="40" text="Hide car"] </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
    </div>
<?php
}

function heleket_promocodes_page()
{
    $heleket = mac_get_heleket_gateway();
    $heleket->promocodes->admin_page();
}

function heleket_payments_page()
{
    // Переносим сюда код из heleket_settings_page() который отвечает за таблицу платежей
    if (isset($_GET['recreate_table'])) {
        check_admin_referer('heleket_recreate_table');
        $gateway = mac_get_heleket_gateway();
        $gateway->create_payments_table();
        echo '<div class="notice notice-success"><p>Таблица пересоздана!</p></div>';
    }

    // Обработка добавления ручной записи
    if (isset($_POST['add_manual_payment'])) {
        check_admin_referer('heleket_add_manual_payment');
        $manual_data = array(
            'amount' => floatval($_POST['manual_amount']),
            'customer_email' => sanitize_email($_POST['manual_email'] ?? ''),
            'customer_telegram' => sanitize_text_field($_POST['manual_telegram'] ?? ''),
            'product_url' => sanitize_url($_POST['manual_product_url']),
            'payment_status' => sanitize_text_field($_POST['manual_status'] ?? 'completed')
        );

        $gateway = mac_get_heleket_gateway();
        $result = $gateway->add_manual_payment($manual_data);

        if ($result) {
            echo '<div class="notice notice-success"><p>Запись успешно добавлена!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Ошибка при добавлении записи</p></div>';
        }
    }

    // Получаем все платежи
    $gateway = mac_get_heleket_gateway();
    $payments = $gateway->get_all_payments();
?>
    <div class="wrap">
        <h1>История заказов Heleket</h1>

        <p><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=heleket-settings&mac_tab=payments&recreate_table=1'), 'heleket_recreate_table')); ?>" class="button">Recreate Table</a></p>

        <!-- Форма добавления ручной записи -->
        <div class="card full" style="margin-bottom: 20px;">
            <h3>Добавить запись</h3>
            <form method="post">
                <?php wp_nonce_field('heleket_add_manual_payment'); ?>
                <table class="form-table">
                    <tr>
                        <th>Сумма *</th>
                        <th>Email</th>
                        <th>Telegram</th>
                        <th>URL товара*</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                    <tr>
                        <td><input type="number" step="0.01" name="manual_amount" placeholder="Сумма" class="small-text" required /></td>
                        <td><input type="email" name="manual_email" placeholder="Email" class="regular-text" /></td>
                        <td><input type="text" name="manual_telegram" placeholder="Telegram" class="regular-text" /></td>
                        <td><input type="url" name="manual_product_url" placeholder="URL товара" class="regular-text" required /></td>
                        <td>
                            <select name="manual_status">
                                <option value="completed">Успех</option>
                                <option value="pending">В обработке</option>
                                <option value="failed">Ошибка</option>
                            </select>
                        </td>
                        <td><button type="submit" name="add_manual_payment" class="button button-primary">Добавить</button></td>
                    </tr>
                </table>
            </form>
        </div>

        <!-- Таблица платежей -->
        <div class="card full">
            <h3>История заказов</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ID заказа</th>
                        <th>Сумма</th>
                        <th>Email</th>
                        <th>Telegram</th>
                        <th>URL товара</th>
                        <th>Статус</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($payments): ?>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo $payment->id; ?></td>
                                <td><?php echo esc_html($payment->order_id); ?></td>
                                <td>$<?php echo number_format($payment->amount, 2); ?></td>
                                <td><?php echo esc_html($payment->customer_email); ?></td>
                                <td><?php echo esc_html($payment->customer_telegram); ?></td>
                                <td style="font-size: 7px;"><?php echo esc_html($payment->product_url); ?></td>
                                <td>
                                    <span class="heleket-status heleket-status-<?php echo esc_attr($payment->payment_status); ?>">
                                        <?php echo esc_html($payment->payment_status); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d.m.Y H:i', strtotime($payment->payment_date)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center;">Нет записей</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
}
