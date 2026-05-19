<?php

/**
 * Module Name: AskarTech | CryptoCloud Payment Gateway
 * Description: Интеграция платежной системы CryptoCloud для WordPress
 * Version: 1.3
 * Author: AskarTech
 */

// Запрещаем прямой доступ
if (!defined('ABSPATH')) {
    exit;
}

// Подключаем класс промокодов
require_once plugin_dir_path(__FILE__) . 'includes/class-cryptocloud-promocodes.php';

if (!class_exists('CryptoCloud_Promocodes')) {
    die('CryptoCloud Promocodes class not loaded! Check file path.');
}

class CryptoCloudPaymentGateway
{

    private $api_url = 'https://api.cryptocloud.plus/v2/invoice/create';
    private $api_check_url = 'https://api.cryptocloud.plus/v2/invoice/merchant/info';
    public $promocodes;

    public function __construct()
    {
        // Для фронтенда
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Для админки
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

        // Инициализируем промокоды
        $this->promocodes = new CryptoCloud_Promocodes();

        add_action('init', array($this, 'init'));
        add_action('wp_ajax_create_cryptocloud_payment', array($this, 'create_payment'));
        add_action('wp_ajax_nopriv_create_cryptocloud_payment', array($this, 'create_payment'));

        // Для обработки промокодов

        // Добавляем настройки
        add_action('admin_init', array($this, 'register_settings'));

        // ВЕБХУКИ
        add_action('rest_api_init', array($this, 'register_webhook_routes'));
        add_action('cryptocloud_check_pending_payments', array($this, 'check_pending_payments'));
        add_action('init', array($this, 'schedule_payment_checks'));

        // WooCommerce-style вебхук
        add_action('init', array($this, 'handle_wc_webhook'));

        // Создаем таблицы при активации
    }

    public function activate_plugin()
    {
        $this->create_payments_table();
        $this->promocodes->create_promocodes_table();
    }

    public function init()
    {
        add_shortcode('cryptocloud_button', array($this, 'cryptocloud_button_shortcode'));
        add_shortcode('cryptocloud_success', array($this, 'payment_success_shortcode'));
    }

    public function enqueue_scripts()
    {
        // Подключаем JavaScript для фронтенда
        wp_enqueue_script('jquery');

        // Подключаем Google reCAPTCHA если настроен
        $recaptcha_site_key = get_option('cryptocloud_recaptcha_site_key');
        if ($recaptcha_site_key) {
            wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . $recaptcha_site_key, array(), '3.0', false);
        }

        wp_enqueue_script('cryptocloud-js', plugin_dir_url(__FILE__) . 'assets/js/cryptocloud.js', array('jquery'), '1.3', true);
        wp_localize_script('cryptocloud-js', 'cryptocloud_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cryptocloud_nonce'),
            'current_url' => home_url($_SERVER['REQUEST_URI']),
            'recaptcha_site_key' => $recaptcha_site_key,
            'base_price' => 40 // Базовая цена для промокодов
        ));

        // Подключаем стили для фронтенда
        wp_enqueue_style('cryptocloud-frontend', plugin_dir_url(__FILE__) . 'assets/css/cryptocloud-frontend.css', array(), '1.3');
    }

    // Отдельный метод для админских стилей
    public function enqueue_admin_styles()
    {
        wp_enqueue_style('cryptocloud-admin', plugin_dir_url(__FILE__) . 'assets/css/cryptocloud-admin.css', array(), '1.3');
    }

    // Регистрация настроек
    public function register_settings()
    {
        register_setting('cryptocloud_settings', 'cryptocloud_shop_id');
        register_setting('cryptocloud_settings', 'cryptocloud_api_key');
        register_setting('cryptocloud_settings', 'cryptocloud_recaptcha_site_key');
        register_setting('cryptocloud_settings', 'cryptocloud_recaptcha_secret_key');
    }

    // Создание таблицы для хранения оплат
    public function create_payments_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_payments';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(100) NOT NULL,
            invoice_uuid varchar(100),
            amount decimal(10,2) NOT NULL,
            currency varchar(10) DEFAULT 'USD',
            customer_email varchar(100),
            customer_telegram varchar(100),
            product_url text,
            post_id bigint(20),
            payment_status varchar(50),
            promocode varchar(50),
            payment_date datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_id (order_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // ВЕБХУКИ - Регистрация REST API routes
    public function register_webhook_routes()
    {
        register_rest_route('cryptocloud/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * Обработка WooCommerce-style вебхука
     */
    public function handle_wc_webhook()
    {
        // Проверяем, что это наш вебхук
        if (isset($_GET['wc-api']) && $_GET['wc-api'] === 'WC_Gateway_CryptoCloud') {
            $this->log('=== WOOCOMMERCE WEBHOOK START ===');

            // Получаем данные из разных источников
            $input = file_get_contents('php://input');
            $post_data = $_POST;

            $this->log('WC Webhook - POST data:', $post_data);
            $this->log('WC Webhook - Raw input:', $input);
            $this->log('WC Webhook - GET data:', $_GET);

            // Пробуем разные форматы данных
            $data = array();

            // Если данные в JSON формате
            if (!empty($input)) {
                $json_data = json_decode($input, true);
                if ($json_data) {
                    $data = $json_data;
                    $this->log('Parsed JSON data:', $json_data);
                }
            }

            // Если данные в POST
            if (empty($data) && !empty($post_data)) {
                $data = $post_data;
            }

            // Извлекаем ключевые параметры
            $invoice_uuid = $data['uuid'] ?? $data['invoice_uuid'] ?? '';
            $status = $data['status'] ?? $data['payment_status'] ?? '';
            $order_id = $data['order_id'] ?? '';

            $this->log("Extracted - Order ID: {$order_id}, Invoice UUID: {$invoice_uuid}, Status: {$status}");

            // Обрабатываем оплаченный платеж
            $paid_statuses = ['paid', 'success', 'completed', 'overpaid'];
            if (in_array($status, $paid_statuses) && (!empty($invoice_uuid) || !empty($order_id))) {
                $this->process_webhook_payment($order_id, $invoice_uuid, $data);
            }

            $this->log('=== WOOCOMMERCE WEBHOOK COMPLETED ===');

            // Отправляем ответ для CryptoCloud
            status_header(200);
            echo 'OK';
            exit;
        }
    }

    /**
     * Обработка платежа из вебхука
     */
    private function process_webhook_payment($order_id, $invoice_uuid, $data)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cryptocloud_payments';

        $this->log('Processing webhook payment');

        // Ищем платеж в базе данных
        $where_conditions = array();
        $where_format = array();

        if (!empty($invoice_uuid)) {
            $where_conditions['invoice_uuid'] = $invoice_uuid;
            $where_format[] = '%s';
        }

        if (!empty($order_id) && empty($invoice_uuid)) {
            $where_conditions['order_id'] = $order_id;
            $where_format[] = '%s';
        }

        if (empty($where_conditions)) {
            $this->log('No order_id or invoice_uuid provided');
            return;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE " . implode(' = %s OR ', array_keys($where_conditions)) . " = %s",
            ...array_values($where_conditions)
        ));

        if ($existing) {
            $this->log('Payment found in DB:', $existing);

            // Если статус еще не completed - обновляем
            if ($existing->payment_status !== 'completed') {
                $this->log('Updating payment status to completed');

                // Обновляем статус
                $wpdb->update(
                    $table_name,
                    array('payment_status' => 'completed'),
                    array('id' => $existing->id),
                    array('%s'),
                    array('%d')
                );

                // Обрабатываем успешный платеж
                $this->process_successful_payment(array(
                    'uuid' => $existing->invoice_uuid,
                    'order_id' => $existing->order_id,
                    'amount_usd' => $existing->amount,
                    'email' => $existing->customer_email,
                    'add_fields' => array(
                        'telegram' => $existing->customer_telegram,
                        'product_url' => $existing->product_url
                    )
                ));

                $this->log('Payment successfully processed via webhook');
            } else {
                $this->log('Payment already completed, skipping');
            }
        } else {
            $this->log('Payment not found in DB, creating new record');

            // Создаем новую запись на основе данных вебхука
            $payment_data = array(
                'order_id' => $order_id ?: 'webhook_' . time() . '_' . uniqid(),
                'invoice_uuid' => $invoice_uuid,
                'amount' => $data['amount_usd'] ?? $data['amount'] ?? '0',
                'customer_email' => $data['email'] ?? '',
                'customer_telegram' => $data['add_fields']['telegram'] ?? '',
                'product_url' => $data['add_fields']['product_url'] ?? '',
                'post_id' => 0,
                'payment_status' => 'completed'
            );

            $this->save_payment_to_db($payment_data);

            // Пытаемся обработать платеж
            $this->process_successful_payment($data);

            $this->log('New payment record created and processed');
        }
    }

    // Обработчик REST API вебхука
    public function handle_webhook($request)
    {
        $this->log('=== REST API WEBHOOK START ===');

        // Получаем все возможные данные
        $params = $request->get_params();
        $body = $request->get_body();
        $headers = $request->get_headers();

        $this->log('Webhook received - Params:', $params);
        $this->log('Webhook received - Raw body:', $body);
        $this->log('Webhook received - Headers:', $headers);

        // Пробуем разные варианты получения данных
        $invoice_uuid = $params['uuid'] ?? '';
        $status = $params['status'] ?? '';

        // Если данные в теле запроса (JSON)
        if (empty($invoice_uuid) && !empty($body)) {
            $body_data = json_decode($body, true);
            if ($body_data) {
                $invoice_uuid = $body_data['uuid'] ?? $body_data['invoice_uuid'] ?? '';
                $status = $body_data['status'] ?? $body_data['payment_status'] ?? '';
                $this->log('Parsed from body:', $body_data);
            }
        }

        $this->log("Extracted - Invoice UUID: {$invoice_uuid}, Status: {$status}");

        // Проверяем, что это уведомление об оплате
        $paid_statuses = ['paid', 'success', 'completed'];
        if (in_array($status, $paid_statuses) && !empty($invoice_uuid)) {
            $this->log('Processing paid payment');

            // Проверяем не обработан ли уже этот платеж
            global $wpdb;
            $table_name = $wpdb->prefix . 'cryptocloud_payments';
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE invoice_uuid = %s",
                $invoice_uuid
            ));

            if ($existing) {
                $this->log('Payment exists in DB:', $existing);

                // Если статус еще не completed - обновляем
                if ($existing->payment_status !== 'completed') {
                    $this->log('Updating payment status to completed');

                    $wpdb->update(
                        $table_name,
                        array('payment_status' => 'completed'),
                        array('invoice_uuid' => $invoice_uuid),
                        array('%s'),
                        array('%s')
                    );

                    // Обрабатываем успешный платеж
                    $this->process_successful_payment(array(
                        'uuid' => $invoice_uuid,
                        'order_id' => $existing->order_id,
                        'amount_usd' => $existing->amount,
                        'email' => $existing->customer_email,
                        'add_fields' => array(
                            'telegram' => $existing->customer_telegram,
                            'product_url' => $existing->product_url
                        )
                    ));

                    $this->log('Payment successfully processed and product hidden');
                } else {
                    $this->log('Payment already completed, skipping');
                }
            } else {
                $this->log('Payment not found in DB - will be processed by cron');

                // Сохраняем информацию для последующей обработки cron'ом
                $this->save_payment_to_db(array(
                    'order_id' => 'webhook_' . time() . '_' . uniqid(),
                    'invoice_uuid' => $invoice_uuid,
                    'amount' => $params['amount_usd'] ?? '0',
                    'customer_email' => $params['email'] ?? '',
                    'customer_telegram' => $params['add_fields']['telegram'] ?? '',
                    'product_url' => $params['add_fields']['product_url'] ?? '',
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

    // Планировщик для проверки необработанных платежей
    public function schedule_payment_checks()
    {
        if (!wp_next_scheduled('cryptocloud_check_pending_payments')) {
            wp_schedule_event(time(), 'hourly', 'cryptocloud_check_pending_payments');
        }
    }

    // Проверка необработанных платежей
    public function check_pending_payments()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cryptocloud_payments';

        // Получаем invoice_uuid платежей, которые созданы но не обработаны
        // за последние 24 часа
        $pending_invoices = $wpdb->get_col("
            SELECT invoice_uuid FROM $table_name 
            WHERE payment_status = 'pending' 
            AND invoice_uuid IS NOT NULL
            AND invoice_uuid != ''
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        foreach ($pending_invoices as $invoice_uuid) {
            $result = $this->check_and_process_payment('', $invoice_uuid);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CryptoCloud cron check for {$invoice_uuid}: " . $result['status']);
            }
        }
    }

    // Метод для логирования
    private function log($message, $data = null)
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;

        $log_message = '[CryptoCloud] ' . $message;
        if ($data) {
            $log_message .= ' | Data: ' . print_r($data, true);
        }

        error_log($log_message);

        // Также пишем в файл для надежности
        $log_file = WP_CONTENT_DIR . '/cryptocloud-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($log_file, "[$timestamp] $log_message\n", FILE_APPEND | LOCK_EX);
    }

    // Валидация reCAPTCHA
    private function validate_recaptcha($recaptcha_token)
    {
        $secret_key = get_option('cryptocloud_recaptcha_secret_key');

        // Если reCAPTCHA не настроена, пропускаем проверку
        if (!$secret_key) {
            return true;
        }

        // Если токен пустой, возвращаем ошибку
        if (empty($recaptcha_token)) {
            error_log('CryptoCloud: reCAPTCHA token is empty');
            return false;
        }

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
            'body' => array(
                'secret' => $secret_key,
                'response' => $recaptcha_token
            ),
            'timeout' => 10
        ));

        if (is_wp_error($response)) {
            error_log('CryptoCloud reCAPTCHA error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('CryptoCloud reCAPTCHA response: ' . $body);
        }

        // Проверяем успешность и score (обычно threshold 0.5)
        if ($data['success'] && $data['score'] > 0.3) { // Более низкий порог для удобства пользователей
            return true;
        }

        error_log('CryptoCloud reCAPTCHA failed. Score: ' . ($data['score'] ?? 'none') . ' | Errors: ' . json_encode($data['error-codes'] ?? []));
        return false;
    }

    // Сохранение платежа в БД
    private function save_payment_to_db($payment_data)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_payments';

        $this->log('Saving payment to DB:', $payment_data);

        // Проверяем обязательные поля
        if (empty($payment_data['order_id'])) {
            $this->log('Missing required field: order_id');
            return false;
        }

        // Для amount проверяем, что поле установлено (даже если оно 0)
        if (!isset($payment_data['amount'])) {
            $this->log('Missing required field: amount');
            return false;
        }

        // Проверяем, что amount - число (может быть 0)
        if (!is_numeric($payment_data['amount'])) {
            $this->log('Amount must be numeric');
            return false;
        }

        // Устанавливаем значения по умолчанию
        $defaults = array(
            'invoice_uuid' => '',
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

        // Подготавливаем данные и форматы
        $data_to_insert = array(
            'order_id' => $payment_data['order_id'],
            'invoice_uuid' => $payment_data['invoice_uuid'],
            'amount' => floatval($payment_data['amount']),
            'currency' => $payment_data['currency'],
            'customer_email' => $payment_data['customer_email'],
            'customer_telegram' => $payment_data['customer_telegram'],
            'product_url' => $payment_data['product_url'],
            'post_id' => intval($payment_data['post_id']),
            'payment_status' => $payment_data['payment_status'],
            'promocode' => $payment_data['promocode'],
            'payment_date' => $payment_data['payment_date']
        );

        $format_to_insert = array(
            '%s', // order_id
            '%s', // invoice_uuid
            '%f', // amount
            '%s', // currency
            '%s', // customer_email
            '%s', // customer_telegram
            '%s', // product_url
            '%d', // post_id
            '%s', // payment_status
            '%s', // promocode
            '%s'  // payment_date
        );

        $this->log('Data to insert:', $data_to_insert);

        $result = $wpdb->insert(
            $table_name,
            $data_to_insert,
            $format_to_insert
        );

        if ($result === false) {
            $this->log('Database error saving payment: ' . $wpdb->last_error);
            $this->log('Query was: ' . $wpdb->last_query);
            return false;
        }

        $this->log('Payment saved successfully. Insert ID: ' . $wpdb->insert_id);
        return $wpdb->insert_id;
    }
    // Получение всех платежей
    public function get_all_payments()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_payments';

        return $wpdb->get_results("
            SELECT * FROM $table_name 
            ORDER BY created_at DESC
        ");
    }

    // Добавление платежа вручную
    public function add_manual_payment($data)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cryptocloud_payments';

        // Проверяем обязательные поля
        if (empty($data['amount']) || empty($data['product_url'])) {
            return false;
        }

        // Подготавливаем данные для вставки
        $insert_data = array(
            'order_id' => 'manual_' . time() . '_' . uniqid(),
            'invoice_uuid' => '',
            'amount' => floatval($data['amount']),
            'currency' => 'USD',
            'customer_email' => sanitize_email($data['customer_email'] ?? ''),
            'customer_telegram' => sanitize_text_field($data['customer_telegram'] ?? ''),
            'product_url' => sanitize_url($data['product_url']),
            'post_id' => 0,
            'payment_status' => sanitize_text_field($data['payment_status'] ?? 'completed'),
            'promocode' => sanitize_text_field($data['promocode'] ?? '')
        );

        return $wpdb->insert(
            $table_name,
            $insert_data,
            array('%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
    }

    // Шорткод для кнопки
    public function cryptocloud_button_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'id' => 'cryptocloud-pay-button',
            'amount' => '40',
            'text' => 'Оплатить $40'
        ), $atts);

        return '<button id="' . esc_attr($atts['id']) . '" 
                class="cryptocloud-pay-button" 
                data-amount="' . esc_attr($atts['amount']) . '">' .
            esc_html($atts['text']) . '</button>';
    }

    // Создание платежа
    public function create_payment()
    {
        check_ajax_referer('cryptocloud_nonce', 'nonce');

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
                // Если промокод бесплатный
                if ($promo_result['is_free'] && $amount == 0) {
                    $is_free_payment = true;

                    // Генерируем уникальный order_id для бесплатного заказа
                    $order_id = 'free_' . time() . '_' . uniqid();

                    // Используем промокод - делаем это ПЕРВЫМ
                    $promocode_used = $this->promocodes->use_promocode($promocode);
                    if (!$promocode_used) {
                        wp_send_json_error('Error when using a promo code');
                        wp_die();
                    }

                    // Скрываем товар
                    $hidden_result = $this->hide_product_and_complete_payment($product_url, $email, $telegram, $promocode, $order_id);

                    if (!$hidden_result['success']) {
                        wp_send_json_error('Error when hiding the product: ' . $hidden_result['message']);
                        wp_die();
                    }

                    // Сохраняем платеж в БД как completed
                    $saved = $this->save_payment_to_db(array(
                        'order_id' => $order_id,
                        'invoice_uuid' => 'FREE_' . $promocode . '_' . time(),
                        'amount' => 0,
                        'customer_email' => $email,
                        'customer_telegram' => $telegram,
                        'product_url' => $product_url,
                        'post_id' => $hidden_result['post_id'],
                        'payment_status' => 'completed',
                        'promocode' => $promocode
                    ));

                    if (!$saved) {
                        wp_send_json_error('Error when saving a payment to the database');
                        wp_die();
                    }

                    // Возвращаем успех с redirect_url на страницу благодарности
                    $success_url = home_url('/payment-success?order_id=' . $order_id . '&free=1');

                    $this->log('Free payment completed successfully. Redirecting to: ' . $success_url);

                    wp_send_json_success(array(
                        'is_free' => true,
                        'redirect_url' => $success_url,
                        'message' => 'A free promo code has been applied! The product is hidden.'
                    ));
                    wp_die();
                } else {
                    // Обычный промокод со скидкой
                    $this->promocodes->use_promocode($promocode);
                }
            }
        }

        // Если не бесплатный платеж - обычная логика
        if (!$email || !$amount) {
            wp_send_json_error('Incorrect information: email address and amount are required');
        }

        // Настройки API
        $shop_id = get_option('cryptocloud_shop_id');
        $api_key = get_option('cryptocloud_api_key');

        if (!$shop_id || !$api_key) {
            wp_send_json_error('The payment system is not configured. Check the plugin settings.');
        }

        // Генерируем order_id
        $order_id = 'wp_' . time() . '_' . uniqid();

        // Данные для создания платежа
        $payment_data = array(
            'shop_id' => $shop_id,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'USD',
            'order_id' => $order_id,
            'email' => $email,
            'add_fields' => array(
                'product_url' => $product_url,
                'telegram' => $telegram,
                'promocode' => $promocode
            ),
            'url_success' => home_url('/payment-success?order_id=' . $order_id),
            'url_callback' => home_url('/?wc-api=WC_Gateway_CryptoCloud')
        );

        // Отправляем запрос к API CryptoCloud
        $response = wp_remote_post($this->api_url, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Token ' . $api_key
            ),
            'body' => json_encode($payment_data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Error connecting to the payment system: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($response_code !== 200) {
            $error_message = "HTTP ошибка: {$response_code}";
            if (isset($data['detail'])) $error_message .= ' - ' . $data['detail'];
            if (isset($data['message'])) $error_message .= ' - ' . $data['message'];
            wp_send_json_error($error_message);
        }

        if (isset($data['status']) && $data['status'] === 'success') {
            if (isset($data['result']['link'])) {
                $payment_url = $data['result']['link'];
            } elseif (isset($data['result']['pay_url'])) {
                $payment_url = $data['result']['pay_url'];
            } else {
                wp_send_json_error('В ответе отсутствует URL платежа (link/pay_url)');
                return;
            }

            // Сохраняем начальную запись о платеже
            $this->save_payment_to_db(array(
                'order_id' => $order_id,
                'invoice_uuid' => $data['result']['uuid'] ?? '',
                'amount' => $amount,
                'customer_email' => $email,
                'customer_telegram' => $telegram,
                'product_url' => $product_url,
                'post_id' => 0,
                'payment_status' => 'pending',
                'promocode' => $promocode
            ));

            wp_send_json_success(array(
                'is_free' => false,
                'payment_url' => $payment_url
            ));
        } else {
            $error_message = 'Ошибка создания платежа';
            if (isset($data['detail'])) $error_message .= ': ' . $data['detail'];
            if (isset($data['message'])) $error_message .= ': ' . $data['message'];
            if (isset($data['error'])) $error_message .= ': ' . $data['error'];
            wp_send_json_error($error_message);
        }
    }

    // Новый метод для скрытия товара и завершения бесплатного платежа
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
                $result['message'] = 'Товар успешно скрыт';
                $this->log('FREE PROMOCODE: Product hidden - Post ID: ' . $post_id);
            } else {
                $result['message'] = 'Ошибка при скрытии товара: ' . $update_result->get_error_message();
                $this->log('FREE PROMOCODE ERROR: ' . $result['message']);
            }
        } else {
            $result['message'] = 'Товар не найден по URL: ' . $clean_url;
            $this->log('FREE PROMOCODE ERROR: ' . $result['message']);
        }

        return $result;
    }

    private function process_successful_payment($payment_data)
    {
        $product_url = $payment_data['add_fields']['product_url'] ?? '';
        $customer_email = $payment_data['email'] ?? '';
        $customer_telegram = $payment_data['add_fields']['telegram'] ?? '';
        $promocode = $payment_data['add_fields']['promocode'] ?? '';

        // Находим ID поста по URL товара
        $post_id = url_to_postid($product_url);

        if ($post_id) {
            // Переводим пост в черновики
            wp_update_post(array(
                'ID' => $post_id,
                'post_status' => 'draft'
            ));

            $this->log('Product hidden - Post ID: ' . $post_id . ', URL: ' . $product_url);
        } else {
            $this->log('Product not found by URL: ' . $product_url);
        }

        // Обновляем платеж в таблице
        global $wpdb;
        $table_name = $wpdb->prefix . 'cryptocloud_payments';

        $wpdb->update(
            $table_name,
            array(
                'payment_status' => 'completed',
                'post_id' => $post_id,
                'payment_date' => current_time('mysql')
            ),
            array('invoice_uuid' => $payment_data['uuid']),
            array('%s', '%d', '%s'),
            array('%s')
        );

        $this->log('Payment completed in database - Invoice UUID: ' . $payment_data['uuid']);
    }

    // Шорткод для страницы успеха
    // В методе payment_success_shortcode:

    public function payment_success_shortcode()
    {
        $order_id = sanitize_text_field($_GET['order_id'] ?? '');
        $is_free = isset($_GET['free']) && $_GET['free'] == '1';
        $invoice_uuid = sanitize_text_field($_GET['invoice_uuid'] ?? '');

        // If this is a free promocode
        if ($is_free && !empty($order_id)) {
            // Check if such order exists in the database
            global $wpdb;
            $table_name = $wpdb->prefix . 'cryptocloud_payments';
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE order_id = %s AND payment_status = 'completed'",
                $order_id
            ));

            if ($payment) {
                return '<div class="cryptocloud-success-page">
                    <h3>🎉 Free Promocode Applied!</h3>		
                    <p>The product has been successfully hidden from the site.</p>
                    <p><strong>Order ID:</strong> ' . esc_html($payment->order_id) . '</p>
                    <p><strong>Promocode:</strong> ' . esc_html($payment->promocode) . '</p>
                    <p><strong>Status:</strong> Free via promocode</p>
                    <p><strong>Date:</strong> ' . date('d.m.Y H:i', strtotime($payment->created_at)) . '</p>
                    <p>Thank you for using our service!</p>
                </div>';
            } else {
                return '<div class="cryptocloud-error-page">
                    <h3>Error</h3>
                    <p>Order not found or not yet processed.</p>
                    <p>Please wait a few minutes or contact support.</p>
                </div>';
            }
        }

        // Regular payment check (for paid orders)
        if (!$order_id && !$invoice_uuid) {
            return '<div class="cryptocloud-pending-page">
                <h3>Payment Processing</h3>
                <p>Thank you, the payment has been received. The page will be hidden for 5 minutes.</p>
            </div>';
        }

        // Check status of regular payment
        $result = $this->check_and_process_payment($order_id, $invoice_uuid);

        if ($result['status'] === 'success') {
            return '<div class="cryptocloud-success-page">
                <h3>Payment Successfully Completed!</h3>		
                <p>The product has been hidden from the site.</p>
                <p><strong>Order ID:</strong> ' . esc_html($order_id) . '</p>
                <p>Thank you for your purchase!</p>
            </div>';
        } else {
            return '<div class="cryptocloud-pending-page">
                <h3>Payment Processing</h3>
                <p>Status: ' . esc_html($result['message']) . '</p>
                <p>We will notify you when the payment is confirmed.</p>
                <p>This usually takes a few minutes.</p>
            </div>';
        }
    }

    public function check_and_process_payment($order_id, $invoice_uuid)
    {
        $api_key = get_option('cryptocloud_api_key');

        if (!$api_key) {
            return ['status' => 'error', 'message' => 'Payment system not configured'];
        }

        // Check if this payment has already been processed
        global $wpdb;
        $table_name = $wpdb->prefix . 'cryptocloud_payments';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE (order_id = %s OR invoice_uuid = %s) AND payment_status = 'completed'",
            $order_id,
            $invoice_uuid
        ));

        if ($existing) {
            return ['status' => 'success', 'message' => 'Payment already processed'];
        }

        // If invoice_uuid exists, use it for checking
        $uuids_to_check = [];
        if (!empty($invoice_uuid)) {
            $uuids_to_check[] = $invoice_uuid;
        }

        // Get payment information
        $check_data = array('uuids' => $uuids_to_check);

        $response = wp_remote_post($this->api_check_url, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Token ' . $api_key
            ),
            'body' => json_encode($check_data),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return ['status' => 'error', 'message' => 'Connection error: ' . $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Check successful response
        if (isset($data['status']) && $data['status'] === 'success' && isset($data['result'])) {
            $invoices = $data['result'];

            foreach ($invoices as $invoice) {
                $status = $invoice['status'] ?? 'unknown';

                // If payment is paid or overpaid - process it
                if ($status === 'paid' || $status === 'overpaid' || $status === 'success') {
                    $this->process_successful_payment($invoice);
                    return ['status' => 'success', 'message' => 'Payment successfully processed'];
                }
            }

            $status_messages = [
                'waiting' => 'waiting',
                'pending' => 'pending',
                'processing' => 'processing',
                'unknown' => 'unknown'
            ];

            $status_text = $status_messages[$status] ?? 'pending';
            return ['status' => 'pending', 'message' => 'Payment status: ' . $status_text];
        }

        return ['status' => 'unknown', 'message' => 'Unknown API response'];
    }
}

$GLOBALS['mac_cryptocloud_gateway'] = new CryptoCloudPaymentGateway();

function mac_get_cryptocloud_gateway()
{
    if (!isset($GLOBALS['mac_cryptocloud_gateway']) || !is_object($GLOBALS['mac_cryptocloud_gateway'])) {
        $GLOBALS['mac_cryptocloud_gateway'] = new CryptoCloudPaymentGateway();
    }

    return $GLOBALS['mac_cryptocloud_gateway'];
}

// Добавляем страницу настроек в админке

function cryptocloud_admin_menu()
{
    if (defined('MAC_MASTER_ACTIVE') && MAC_MASTER_ACTIVE) {
        return;
    }

    // Создаем отдельный пункт главного меню для CryptoCloud
    add_menu_page(
        'AskarTech | CryptoCloud Payments',           // Title страницы
        'AskarTech | CryptoCloud Payments',           // Название в меню
        'manage_options',                 // Права доступа
        'cryptocloud-settings',           // Slug
        'cryptocloud_settings_page',      // Функция отображения
        'dashicons-money-alt',            // Иконка
        46                                // Позиция в меню (после Heleket)
    );

    // Добавляем подменю "Настройки"
    add_submenu_page(
        'cryptocloud-settings',
        'Settings - CryptoCloud Payments',
        'Settings',
        'manage_options',
        'cryptocloud-settings',
        'cryptocloud_settings_page'
    );

    // Добавляем подменю "Промокоды"
    add_submenu_page(
        'cryptocloud-settings',
        'Promocodes - CryptoCloud Payments',
        'Promocodes',
        'manage_options',
        'cryptocloud-promocodes',
        'cryptocloud_promocodes_page'
    );

    // Добавляем подменю "История оплат" 
    add_submenu_page(
        'cryptocloud-settings',
        'Payment History - CryptoCloud Payments',
        'Payment History',
        'manage_options',
        'cryptocloud-payments',
        'cryptocloud_payments_page'
    );

    // Добавляем подменю "Debug" для отладки
    add_submenu_page(
        'cryptocloud-settings',
        'Debug - CryptoCloud Payments',
        'Debug',
        'manage_options',
        'cryptocloud-debug',
        'cryptocloud_debug_page'
    );
}

// Функция для страницы промокодов
function cryptocloud_promocodes_page()
{
    $gateway = mac_get_cryptocloud_gateway();
    $gateway->promocodes->admin_page();
}

// Функция для страницы отладки
function cryptocloud_debug_page()
{
    $gateway = mac_get_cryptocloud_gateway();
?>
    <div class="wrap">
        <h1>CryptoCloud Debug</h1>

        <!-- Проверка REST API -->
        <div class="card">
            <h3>REST API Endpoint</h3>
            <?php
            $rest_url = home_url('/wp-json/cryptocloud/v1/webhook');
            echo '<p><strong>Webhook URL:</strong> <code>' . $rest_url . '</code></p>';

            // Проверка доступности
            $response = wp_remote_get($rest_url);
            if (is_wp_error($response)) {
                echo '<div class="error"><p>REST API недоступен: ' . $response->get_error_message() . '</p></div>';
            } else {
                echo '<div class="updated"><p>REST API доступен. Код ответа: ' . wp_remote_retrieve_response_code($response) . '</p></div>';
            }
            ?>

            <p><strong>WC-API Webhook URL (рекомендуется):</strong></p>
            <code><?php echo home_url('/?wc-api=WC_Gateway_CryptoCloud'); ?></code>

            <p><strong>Для настройки вебхука в личном кабинете CryptoCloud используйте этот URL:</strong></p>
            <code><?php echo home_url('/?wc-api=WC_Gateway_CryptoCloud'); ?></code>
        </div>

        <!-- Показать последние логи -->
        <div class="card">
            <h3>Логи</h3>
            <?php
            $log_file = WP_CONTENT_DIR . '/cryptocloud-debug.log';
            if (file_exists($log_file)) {
                $logs = file_get_contents($log_file);
                echo '<pre style="background: #f1f1f1; padding: 10px; overflow: auto; max-height: 400px;">';
                echo esc_html($logs);
                echo '</pre>';

                echo '<p><a href="' . esc_url(wp_nonce_url(add_query_arg('clear_logs', '1'), 'cryptocloud_clear_logs')) . '" class="button">Очистить логи</a></p>';
            } else {
                echo '<p>Файл логов не найден.</p>';
            }

            // Очистка логов
            if (isset($_GET['clear_logs'])) {
                check_admin_referer('cryptocloud_clear_logs');
                if (file_exists($log_file)) {
                    file_put_contents($log_file, '');
                    echo '<div class="updated"><p>Логи очищены!</p></div>';
                    echo '<script>setTimeout(function(){ window.location.href = "' . remove_query_arg('clear_logs') . '"; }, 1000);</script>';
                }
            }
            ?>
        </div>
    </div>
<?php
}

function cryptocloud_settings_page()
{
?>
    <div class="wrap">
        <h1>Настройки CryptoCloud</h1>

        <!-- Настройки API -->
        <div class="card">
            <h3>API настройки</h3>
            <form method="post" action="options.php">
                <?php
                settings_fields('cryptocloud_settings');
                do_settings_sections('cryptocloud_settings');
                ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Shop ID</th>
                        <td>
                            <input type="text" name="cryptocloud_shop_id" value="<?php echo esc_attr(get_option('cryptocloud_shop_id')); ?>" class="regular-text" />
                            <p class="description">Уникальный идентификатор магазина из личного кабинета CryptoCloud</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" name="cryptocloud_api_key" value="<?php echo esc_attr(get_option('cryptocloud_api_key')); ?>" class="regular-text" />
                            <p class="description">API ключ из личного кабинета CryptoCloud</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">reCAPTCHA Site Key</th>
                        <td>
                            <input type="text" name="cryptocloud_recaptcha_site_key" value="<?php echo esc_attr(get_option('cryptocloud_recaptcha_site_key')); ?>" class="regular-text" />
                            <p class="description">Ключ сайта для Google reCAPTCHA v3</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">reCAPTCHA Secret Key</th>
                        <td>
                            <input type="password" name="cryptocloud_recaptcha_secret_key" value="<?php echo esc_attr(get_option('cryptocloud_recaptcha_secret_key')); ?>" class="regular-text" />
                            <p class="description">Секретный ключ для Google reCAPTCHA v3</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Webhook URL</th>
                        <td>
                            <code><?php echo home_url('/?wc-api=WC_Gateway_CryptoCloud'); ?></code>
                            <p class="description">Скопируйте этот URL в настройках вебхука в личном кабинете CryptoCloud</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Шорткод для страницы благодарности</th>
                        <td>
                            <p class="description">[cryptocloud_success]</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Шорткод для кнопки покупки</th>
                        <td>
                            <p class="description">[cryptocloud_button amount="40" text="Hide car"] </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
    </div>
<?php
}

function cryptocloud_payments_page()
{

    if (isset($_GET['recreate_table'])) {
        check_admin_referer('cryptocloud_recreate_table');
        $gateway = mac_get_cryptocloud_gateway();
        $gateway->create_payments_table();
        echo '<div class="notice notice-success"><p>Таблица пересоздана!</p></div>';
    }
    // Обработка добавления ручной записи
    if (isset($_POST['add_manual_payment'])) {
        check_admin_referer('cryptocloud_add_manual_payment');
        $manual_data = array(
            'amount' => floatval($_POST['manual_amount']),
            'customer_email' => sanitize_email($_POST['manual_email'] ?? ''),
            'customer_telegram' => sanitize_text_field($_POST['manual_telegram'] ?? ''),
            'product_url' => sanitize_url($_POST['manual_product_url']),
            'payment_status' => sanitize_text_field($_POST['manual_status'] ?? 'completed'),
            'promocode' => sanitize_text_field($_POST['manual_promocode'] ?? '')
        );

        $gateway = mac_get_cryptocloud_gateway();
        $result = $gateway->add_manual_payment($manual_data);

        if ($result) {
            echo '<div class="notice notice-success"><p>Запись успешно добавлена!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Ошибка при добавлении записи</p></div>';
        }
    }

    // Получаем все платежи
    $gateway = mac_get_cryptocloud_gateway();
    $payments = $gateway->get_all_payments();
?>
    <div class="wrap">
        <h1>История заказов CryptoCloud</h1>
        <p><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cryptocloud-settings&mac_tab=payments&recreate_table=1'), 'cryptocloud_recreate_table')); ?>" class="button">Recreate Table</a></p>
        <!-- Форма добавления ручной записи -->
        <div class="card full" style="margin-bottom: 20px;">
            <h3>Добавить запись</h3>
            <form method="post">
                <?php wp_nonce_field('cryptocloud_add_manual_payment'); ?>
                <table class="form-table">
                    <tr>
                        <th>Сумма *</th>
                        <th>Email</th>
                        <th>Telegram</th>
                        <th>URL товара*</th>
                        <th>Промокод</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                    <tr>
                        <td><input type="number" step="0.01" name="manual_amount" placeholder="Сумма" class="small-text" required /></td>
                        <td><input type="email" name="manual_email" placeholder="Email" class="regular-text" /></td>
                        <td><input type="text" name="manual_telegram" placeholder="Telegram" class="regular-text" /></td>
                        <td><input type="url" name="manual_product_url" placeholder="URL товара" class="regular-text" required /></td>
                        <td><input type="text" name="manual_promocode" placeholder="Промокод" class="regular-text" /></td>
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
                        <th>UUID счета</th>
                        <th>Сумма</th>
                        <th>Email</th>
                        <th>Telegram</th>
                        <th>Промокод</th>
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
                                <td><?php echo esc_html($payment->invoice_uuid); ?></td>
                                <td>$<?php echo number_format($payment->amount, 2); ?></td>
                                <td><?php echo esc_html($payment->customer_email); ?></td>
                                <td><?php echo esc_html($payment->customer_telegram); ?></td>
                                <td><?php echo esc_html($payment->promocode); ?></td>
                                <td style="font-size: 7px;"><?php echo esc_html($payment->product_url); ?></td>
                                <td>
                                    <span class="cryptocloud-status cryptocloud-status-<?php echo esc_attr($payment->payment_status); ?>">
                                        <?php echo esc_html($payment->payment_status); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d.m.Y H:i', strtotime($payment->payment_date)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" style="text-align: center;">Нет записей</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
}
