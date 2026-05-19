<?php

defined('ABSPATH') || exit;

class Master_Auto_Catalog_Admin
{
    const MENU_SLUG = 'master-auto-catalog';

    public static function register_menu()
    {
        add_menu_page(
            'Мастер настроек каталога авто',
            '★ Авто каталог',
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'overview_page'],
            'dashicons-car',
            3
        );

        add_submenu_page(self::MENU_SLUG, 'Обзор', 'Обзор', 'manage_options', self::MENU_SLUG, [__CLASS__, 'overview_page']);
        add_submenu_page(self::MENU_SLUG, 'Загрузка авто по API', 'Загрузка авто по API', 'manage_options', 'vin-fallback-settings', [__CLASS__, 'vin_settings_page']);
        add_submenu_page(self::MENU_SLUG, 'Логи поиска', 'Логи поиска', 'manage_options', 'wp-search-logs', [__CLASS__, 'search_logs_page']);
        add_submenu_page(self::MENU_SLUG, 'Синхронизация с центром', 'Синхронизация с центром', 'manage_options', 'central-auto-sync', [__CLASS__, 'central_sync_page']);
        add_submenu_page(self::MENU_SLUG, 'Индексация Google', 'Индексация Google', 'manage_options', 'vin-google-index', [__CLASS__, 'google_page']);
        add_submenu_page(self::MENU_SLUG, 'Heleket', 'Heleket', 'manage_options', 'heleket-settings', [__CLASS__, 'heleket_page']);
        add_submenu_page(self::MENU_SLUG, 'CryptoCloud', 'CryptoCloud', 'manage_options', 'cryptocloud-settings', [__CLASS__, 'cryptocloud_page']);

        add_submenu_page(null, 'Google logs', 'Google logs', 'manage_options', 'gai-logs', [__CLASS__, 'google_page']);
        add_submenu_page(null, 'VIN import', 'VIN import', 'manage_options', 'vin-fallback-search', [__CLASS__, 'vin_settings_page']);
    }

    public static function enqueue_admin_assets()
    {
        wp_enqueue_style('mac-admin', MAC_PLUGIN_URL . 'assets/admin.css', [], '1.1.0');
    }

    public static function overview_page()
    {
        $status = self::get_status_data();
        self::open_page('Мастер настроек каталога авто');
        ?>
        <div class="mac-hero">
            <h2>Состояние каталога</h2>
            <p>Короткая сводка по импорту, поиску, синхронизации, индексации и оплатам.</p>
        </div>

        <div class="mac-status-grid">
            <?php
            self::status_card('Загрузка авто по API', $status['vin']['state'], $status['vin']['text'], 'vin-fallback-settings');
            self::status_card('Логи поиска', $status['search']['state'], $status['search']['text'], 'wp-search-logs');
            self::status_card('Синхронизация с центром', $status['sync']['state'], $status['sync']['text'], 'central-auto-sync');
            self::status_card('Индексация Google', $status['google']['state'], $status['google']['text'], 'vin-google-index');
            self::status_card('Оплата', $status['payments']['state'], $status['payments']['text'], $status['payments']['page']);
            ?>
        </div>

        <div class="mac-grid">
            <?php
            self::module_card('Загрузка авто по API', 'API-ключи VIN-провайдеров, порядок поиска, шаблон названия и URL товара.', 'vin-fallback-settings');
            self::module_card('Логи поиска', 'Статистика реальных поисков на сайте и CSV-выгрузка.', 'wp-search-logs');
            self::module_card('Синхронизация с центром', 'Отправка товаров на центральный сервис и REST-импорт обратно на сайт.', 'central-auto-sync');
            self::module_card('Индексация Google', 'OAuth-настройки, отправка URL_UPDATED/URL_DELETED и журнал запросов.', 'vin-google-index');
            self::module_card('Heleket', 'Настройки, промокоды, история платежей и диагностика на одной странице.', 'heleket-settings');
            self::module_card('CryptoCloud', 'Настройки, промокоды, история платежей и диагностика на одной странице.', 'cryptocloud-settings');
            ?>
        </div>
        <?php
        self::help_block('Что настроить в первую очередь', [
            'Загрузка авто по API: включите нужные VIN-провайдеры и задайте порядок поиска.',
            'Индексация Google: укажите Client ID, Client Secret и redirect URL из раздела Google.',
            'Синхронизация с центром: заполните Central URL, API Key и Sync Key, если сайт должен обмениваться товарами с центральной базой.',
            'Оплата: настройте Heleket или CryptoCloud и проверьте webhook на вкладке диагностики.',
        ]);
        self::close_page();
    }

    public static function vin_settings_page()
    {
        self::section_header('Загрузка авто по API', 'Настройка источников VIN-данных и правил создания WooCommerce-товаров.');
        self::call_object_page('mac_vin_fallback', 'settings_page');
        self::help_block('Как настроить', [
            'Включите только те API, для которых есть рабочие ключи.',
            'Порядок источников задается строкой вроде apicar,api2,api3.',
            'Шаблон названия управляет title товара. Пример: {make} {model} {year} {vin}.',
            'Шаблон URL управляет slug товара. Если поле пустое, используется стандартная генерация.',
            'После сохранения проверьте обычный поиск VIN на фронте сайта.',
        ]);
    }

    public static function search_logs_page()
    {
        self::section_header('Логи поиска', 'Статистика поисковых запросов пользователей по каталогу.');
        self::call_function_page('wp_search_logs_page');
        self::help_block('Как использовать', [
            'Логируются только фронтовые поиски, администраторские запросы исключаются.',
            'CSV нужен для анализа спроса: какие VIN или модели пользователи ищут чаще всего.',
            'Очистка удаляет все записи из таблицы search_logs.',
        ]);
    }

    public static function central_sync_page()
    {
        self::section_header('Синхронизация с центром', 'Обмен товарами с центральной базой автомобилей.');
        self::call_function_page('cas_options_page');
        self::help_block('Как настроить', [
            'Central URL: адрес центрального сервиса, например https://example.com.',
            'API Key: ключ для отправки товаров с этого сайта в центр.',
            'Sync Key: ключ для REST-запросов центра к этому сайту.',
            'Кнопка синхронизации отправляет товары пачками, включая черновики и скрытые товары с заполненным SKU/VIN.',
            'REST endpoints: /wp-json/auto-sync/v1/vehicles и /wp-json/auto-sync/v1/import.',
        ]);
    }

    public static function google_page()
    {
        $tab = self::active_tab(['settings', 'logs'], 'settings');
        self::section_header('Индексация Google', 'Настройки Google Indexing API и журнал отправленных уведомлений.');
        self::tabs('vin-google-index', [
            'settings' => 'Настройки',
            'logs' => 'Журнал',
        ], $tab);

        if ($tab === 'logs') {
            self::call_object_page('mac_google_admin', 'logs_page');
            self::help_block('Как читать журнал', [
                'success означает, что Google API принял уведомление. Это не гарантия мгновенного появления страницы в поиске.',
                'error обычно связан с OAuth, доступом в Search Console или дневным лимитом API.',
                'URL_UPDATED отправляется для публикации/обновления, URL_DELETED - для удаления из индекса.',
            ]);
            return;
        }

        self::call_object_page('mac_google_admin', 'settings_page');
        self::help_block('Как настроить Google', [
            'В Google Cloud создайте OAuth Client ID типа Web application.',
            'Authorized redirect URI укажите точно: ' . admin_url('admin-ajax.php?action=gai_oauth_callback'),
            'Вставьте Client ID и Client Secret, сохраните настройки и нажмите авторизацию Google.',
            'Google-аккаунт должен иметь доступ к этому сайту в Search Console.',
            'Auto index new products отправляет URL_UPDATED при публикации товара.',
            'Remove on draft/trash отправляет URL_DELETED, когда товар уходит из публикации.',
        ]);
    }

    public static function heleket_page()
    {
        $tab = self::active_tab(['settings', 'promocodes', 'payments', 'debug'], 'settings');
        self::section_header('Heleket', 'Настройки платежей, промокоды, история и диагностика.');
        self::tabs('heleket-settings', [
            'settings' => 'Настройки',
            'promocodes' => 'Промокоды',
            'payments' => 'Платежи',
            'debug' => 'Диагностика',
        ], $tab);

        if ($tab === 'promocodes') {
            self::call_function_page('heleket_promocodes_page');
            self::help_block('Промокоды', [
                'Создайте код, размер скидки и лимит использования.',
                'Проверка кода идет через AJAX при создании платежа.',
                'Использование фиксируется после успешной оплаты.',
            ]);
            return;
        }

        if ($tab === 'payments') {
            self::call_function_page('heleket_payments_page');
            self::help_block('Платежи', [
                'История берется из таблицы heleket_payments.',
                'Pending-платежи дополнительно проверяются cron-задачей.',
                'Manual payment используйте только для ручной корректировки истории.',
            ]);
            return;
        }

        if ($tab === 'debug') {
            self::call_function_page('heleket_debug_page');
            self::help_block('Диагностика', [
                'Проверьте доступность webhook: ' . home_url('/wp-json/heleket/v1/webhook'),
                'Если платеж оплачен, но статус не обновился, проверьте webhook URL в кабинете Heleket.',
                'Ошибки API и подписи смотрите в debug-блоке.',
            ]);
            return;
        }

        self::call_function_page('heleket_settings_page');
        self::help_block('Как настроить Heleket', [
            'Merchant ID и API Key берутся в кабинете Heleket.',
            'Shortcode кнопки: [heleket_button amount="40" text="Hide car"].',
            'Shortcode страницы успешной оплаты: [heleket_success].',
            'Webhook REST URL: ' . home_url('/wp-json/heleket/v1/webhook'),
        ]);
    }

    public static function cryptocloud_page()
    {
        $tab = self::active_tab(['settings', 'promocodes', 'payments', 'debug'], 'settings');
        self::section_header('CryptoCloud', 'Настройки платежей, промокоды, история и диагностика.');
        self::tabs('cryptocloud-settings', [
            'settings' => 'Настройки',
            'promocodes' => 'Промокоды',
            'payments' => 'Платежи',
            'debug' => 'Диагностика',
        ], $tab);

        if ($tab === 'promocodes') {
            self::call_function_page('cryptocloud_promocodes_page');
            self::help_block('Промокоды', [
                'Промокоды CryptoCloud хранятся отдельно от Heleket.',
                'Комментарий используйте для внутренней пометки кампании или клиента.',
                'Проверка идет через AJAX action check_cryptocloud_promocode.',
            ]);
            return;
        }

        if ($tab === 'payments') {
            self::call_function_page('cryptocloud_payments_page');
            self::help_block('Платежи', [
                'История показывает invoice_uuid, сумму, клиента, промокод, URL товара и статус.',
                'Webhook обновляет статус, cron проверяет pending-счета.',
                'Recreate Table используйте только если таблица не создалась.',
            ]);
            return;
        }

        if ($tab === 'debug') {
            self::call_function_page('cryptocloud_debug_page');
            self::help_block('Диагностика', [
                'Webhook URL для кабинета CryptoCloud: ' . home_url('/?wc-api=WC_Gateway_CryptoCloud'),
                'REST webhook также доступен: ' . home_url('/wp-json/cryptocloud/v1/webhook'),
                'Лог cryptocloud-debug.log показывает тело webhook и результат обработки.',
            ]);
            return;
        }

        self::call_function_page('cryptocloud_settings_page');
        self::help_block('Как настроить CryptoCloud', [
            'Shop ID и API Key берутся в кабинете CryptoCloud.',
            'Webhook URL для кабинета CryptoCloud: ' . home_url('/?wc-api=WC_Gateway_CryptoCloud'),
            'reCAPTCHA Site Key и Secret Key нужны только если включаете защиту от ботов.',
            'Shortcode кнопки: [cryptocloud_button amount="40" text="Hide car"].',
            'Shortcode страницы успешной оплаты: [cryptocloud_success].',
        ]);
    }

    private static function get_status_data()
    {
        $vin_settings = get_option('vin_fallback_settings', []);
        $vin_settings = is_array($vin_settings) ? $vin_settings : [];
        $api_keys = ['apicar_enabled', 'api2_enabled', 'api3_enabled'];
        $enabled_api = 0;
        foreach ($api_keys as $key) {
            if (!empty($vin_settings[$key])) {
                $enabled_api++;
            }
        }

        global $wpdb;
        $search_table = $wpdb->prefix . 'search_logs';
        $search_count = self::table_exists($search_table) ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$search_table}") : 0;

        $sync_ready = get_option('cas_central_url') && get_option('cas_api_key') && get_option('cas_sync_key');

        $gai = function_exists('gai_get_settings') ? gai_get_settings() : get_option('gai_settings', []);
        $gai = is_array($gai) ? $gai : [];
        $google_auth = class_exists('GAI_Google_Auth') && GAI_Google_Auth::is_authenticated();
        $google_auto = !empty($gai['auto_index_new']);

        $heleket_ready = get_option('heleket_merchant_id') && get_option('heleket_api_key');
        $crypto_ready = get_option('cryptocloud_shop_id') && get_option('cryptocloud_api_key');
        $payment_parts = [];
        if ($heleket_ready) {
            $payment_parts[] = 'Heleket настроен';
        }
        if ($crypto_ready) {
            $payment_parts[] = 'CryptoCloud настроен';
        }

        return [
            'vin' => [
                'state' => $enabled_api > 0 ? 'good' : 'warn',
                'text' => $enabled_api > 0 ? 'Включено API: ' . $enabled_api . ' из 3' : 'API пока не включены',
            ],
            'search' => [
                'state' => self::table_exists($search_table) ? 'good' : 'warn',
                'text' => self::table_exists($search_table) ? 'Записей в логах: ' . $search_count : 'Таблица логов не создана',
            ],
            'sync' => [
                'state' => $sync_ready ? 'good' : 'warn',
                'text' => $sync_ready ? 'Подключение заполнено' : 'Central URL/API Key/Sync Key не заполнены',
            ],
            'google' => [
                'state' => ($google_auth && $google_auto) ? 'good' : 'warn',
                'text' => ($google_auth ? 'OAuth подключен' : 'OAuth не подключен') . ', автоиндексация: ' . ($google_auto ? 'вкл' : 'выкл'),
            ],
            'payments' => [
                'state' => $payment_parts ? 'good' : 'warn',
                'text' => $payment_parts ? implode(', ', $payment_parts) : 'Платежная система не настроена',
                'page' => $heleket_ready ? 'heleket-settings' : 'cryptocloud-settings',
            ],
        ];
    }

    private static function table_exists($table)
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function section_header($title, $text)
    {
        echo '<div class="wrap mac-wrap mac-admin-page">';
        echo '<div class="mac-section-title"><h1>' . esc_html($title) . '</h1><p>' . esc_html($text) . '</p></div>';
        echo '</div>';
    }

    private static function open_page($title)
    {
        echo '<div class="wrap mac-wrap mac-admin-page"><h1>' . esc_html($title) . '</h1>';
    }

    private static function close_page()
    {
        echo '</div>';
    }

    private static function module_card($title, $text, $page)
    {
        echo '<a class="mac-card" href="' . esc_url(admin_url('admin.php?page=' . $page)) . '">';
        echo '<strong>' . esc_html($title) . '</strong>';
        echo '<span>' . esc_html($text) . '</span>';
        echo '</a>';
    }

    private static function status_card($title, $state, $text, $page)
    {
        echo '<a class="mac-status-card mac-status-' . esc_attr($state) . '" href="' . esc_url(admin_url('admin.php?page=' . $page)) . '">';
        echo '<span class="mac-status-dot"></span>';
        echo '<strong>' . esc_html($title) . '</strong>';
        echo '<em>' . esc_html($text) . '</em>';
        echo '</a>';
    }

    private static function tabs($page, array $tabs, $active)
    {
        echo '<div class="wrap mac-wrap"><nav class="mac-tabs">';
        foreach ($tabs as $id => $label) {
            $url = admin_url('admin.php?page=' . $page . '&mac_tab=' . $id);
            $class = $id === $active ? ' class="is-active"' : '';
            echo '<a' . $class . ' href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav></div>';
    }

    private static function active_tab(array $allowed, $default)
    {
        $tab = isset($_GET['mac_tab']) ? sanitize_key(wp_unslash($_GET['mac_tab'])) : $default;
        return in_array($tab, $allowed, true) ? $tab : $default;
    }

    private static function help_block($title, array $items)
    {
        echo '<div class="wrap mac-wrap"><div class="mac-help"><h2>' . esc_html($title) . '</h2><ol>';
        foreach ($items as $item) {
            echo '<li>' . esc_html($item) . '</li>';
        }
        echo '</ol></div></div>';
    }

    private static function call_function_page($function)
    {
        if (function_exists($function)) {
            call_user_func($function);
            return;
        }

        self::missing_page($function);
    }

    private static function call_object_page($global_key, $method)
    {
        if (isset($GLOBALS[$global_key]) && is_object($GLOBALS[$global_key]) && method_exists($GLOBALS[$global_key], $method)) {
            call_user_func([$GLOBALS[$global_key], $method]);
            return;
        }

        self::missing_page($global_key . '::' . $method);
    }

    private static function missing_page($target)
    {
        self::open_page('Модуль недоступен');
        echo '<div class="notice notice-error"><p>Не найден обработчик: <code>' . esc_html($target) . '</code>.</p></div>';
        self::close_page();
    }
}
