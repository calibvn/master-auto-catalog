<?php

/**
 * Module Name: AskarTech | VIN Fallback Search for WooCommerce
 * Description: Поиск товаров по VIN и их загрузка на сайт через API
 * Version:     3.2
 * Author:      AskarTech
 */

if (!defined('ABSPATH')) exit;

// === DEBUG: включите true для логов в wp-content/debug.log; на проде лучше false
if (!defined('VIN_FS_DEBUG')) define('VIN_FS_DEBUG', false);

/**
 * Основной класс плагина
 */
class VINFallbackSearch
{

    private $settings;
    private $image_processor;
    private $attribute_manager;
    private $product_cache = [];
    private static $cached_providers = null;

    public function __construct()
    {
        $this->includes();
        $this->init_components();
        $this->init_hooks();
    }

    /**
     * Подключаем зависимости
     */
    private function includes()
    {
        $includes_dir = plugin_dir_path(__FILE__) . 'includes/';

        require_once $includes_dir . 'class-vin-provider-interface.php';
        require_once $includes_dir . 'class-vin-apicar-provider.php';
        require_once $includes_dir . 'class-vin-second-provider.php';
        require_once $includes_dir . 'class-vin-third-provider.php';
        require_once $includes_dir . 'class-vin-image-processor.php';
        require_once $includes_dir . 'class-vin-data-normalizer.php';
        require_once $includes_dir . 'class-vin-attribute-manager.php';
    }

    /**
     * Инициализация компонентов
     */
    private function init_components()
    {
        $this->image_processor = new VIN_Image_Processor();
        $this->attribute_manager = new VIN_Attribute_Manager();
        $this->settings = get_option('vin_fallback_settings', []);
    }

    /**
     * Инициализация хуков
     */
    private function init_hooks()
    {
        // Основные хуки
        add_action('template_redirect', [$this, 'maybe_handle_search_fallback'], 1);
        add_filter('vin_fallback_providers', [$this, 'get_active_providers']);

        // Админка
        add_action('admin_init', [$this, 'settings_init']);
        add_action('admin_enqueue_scripts', [$this, 'admin_styles']);
        add_action('wp_ajax_vin_fallback_test_provider', [$this, 'ajax_test_provider']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Оптимизация: очистка кэша
        add_action('save_post_product', [$this, 'clear_product_cache_on_save']);
        add_action('deleted_post', [$this, 'clear_product_cache_on_delete']);
    }

    /**
     * Очистка кэша при сохранении товара
     */
    public function clear_product_cache_on_save($post_id)
    {
        if (get_post_type($post_id) === 'product') {
            $sku = get_post_meta($post_id, '_sku', true);
            if ($sku) {
                $this->clear_product_cache($sku);
            }
        }
    }

    /**
     * Очистка кэша при удалении товара
     */
    public function clear_product_cache_on_delete($post_id)
    {
        if (get_post_type($post_id) === 'product') {
            $sku = get_post_meta($post_id, '_sku', true);
            if ($sku) {
                $this->clear_product_cache($sku);
            }
        }
    }

    /**
     * Очистка кэша продукта
     */
    private function clear_product_cache($sku)
    {
        $cache_key = 'vin_product_' . md5($sku);
        unset($this->product_cache[$sku]);
        wp_cache_delete($cache_key, 'vin_fallback');

        // Сбрасываем кэш провайдеров при изменениях
        self::$cached_providers = null;
    }

    private function get_provider_registry()
    {
        return [
            'apicar' => [
                'label' => 'Apicar.store',
                'class' => 'VIN_Apicar_Provider',
                'tag' => 'Apicar',
                'enabled_field' => 'apicar_enabled',
                'fields' => [
                    'api_key' => ['label' => 'API РєР»СЋС‡ Apicar.store', 'type' => 'password'],
                ],
                'factory' => function () {
                    if (empty($this->settings['apicar_api_key'])) return null;
                    return new VIN_Apicar_Provider($this->settings['apicar_api_key']);
                },
            ],
            'api2' => [
                'label' => 'Second API (Auction API)',
                'class' => 'VIN_Second_API_Provider',
                'tag' => 'Auction-api',
                'enabled_field' => 'api2_enabled',
                'fields' => [
                    'login' => ['label' => 'Р›РѕРіРёРЅ Second API', 'type' => 'text'],
                    'password' => ['label' => 'РџР°СЂРѕР»СЊ Second API', 'type' => 'password'],
                ],
                'factory' => function () {
                    if (empty($this->settings['api2_login']) || empty($this->settings['api2_password'])) return null;
                    return new VIN_Second_API_Provider($this->settings['api2_login'], $this->settings['api2_password']);
                },
            ],
            'api3' => [
                'label' => 'Third API (Auctionsapi)',
                'class' => 'VIN_Third_API_Provider',
                'tag' => 'Auctionsapi',
                'enabled_field' => 'api3_enabled',
                'fields' => [
                    'key' => ['label' => 'API РєР»СЋС‡ Third API', 'type' => 'password'],
                ],
                'factory' => function () {
                    if (empty($this->settings['api3_key'])) return null;
                    return new VIN_Third_API_Provider($this->settings['api3_key']);
                },
            ],
        ];
    }

    private function get_provider_order()
    {
        $registry = $this->get_provider_registry();
        $default = array_keys($registry);
        $raw = trim((string)($this->settings['provider_order'] ?? ''));
        $order = $raw !== '' ? array_filter(array_map('trim', explode(',', $raw))) : $default;
        $order = array_values(array_unique(array_filter($order, function ($id) use ($registry) {
            return isset($registry[$id]);
        })));

        foreach ($default as $id) {
            if (!in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return $order;
    }

    /**
     * Админка: добавляем пункт меню
     */
    public function add_admin_menu()
    {
        if (defined('MAC_MASTER_ACTIVE') && MAC_MASTER_ACTIVE) {
            return;
        }

        add_menu_page(
            'AskarTech | Поиск авто по VIN',
            'AskarTech | Поиск авто по VIN',
            'manage_options',
            'vin-fallback-search',
            [$this, 'admin_page'],
            'dashicons-search',
            48
        );

        add_submenu_page(
            'vin-fallback-search',
            'Настройки поиска',
            'Настройки',
            'manage_options',
            'vin-fallback-settings',
            [$this, 'settings_page']
        );
    }

    /**
     * Инициализация настроек
     */
    public function settings_init()
    {
        register_setting('vin_fallback_settings', 'vin_fallback_settings', [$this, 'sanitize_settings']);

        // Основная секция
        add_settings_section(
            'vin_fallback_providers_section',
            'Настройки API провайдеров',
            [$this, 'settings_section_callback'],
            'vin_fallback_settings'
        );

        // Поля для провайдеров
        $providers_config = [
            'apicar' => [
                'enabled' => ['label' => 'Apicar.store включен', 'desc' => 'Включить поиск через Apicar.store'],
                'api_key' => ['label' => 'API ключ Apicar.store', 'type' => 'password']
            ],
            'api2' => [
                'enabled' => ['label' => 'Second API включен', 'desc' => 'Включить Second API (Auction API)'],
                'login' => ['label' => 'Логин Second API', 'type' => 'text'],
                'password' => ['label' => 'Пароль Second API', 'type' => 'password']
            ],
            'api3' => [
                'enabled' => ['label' => 'Third API включен', 'desc' => 'Включить Third API'],
                'key' => ['label' => 'API ключ Third API', 'type' => 'password']
            ]
        ];

        foreach ($providers_config as $provider => $fields) {
            foreach ($fields as $field => $config) {
                $field_name = $provider . '_' . $field;
                add_settings_field(
                    $field_name,
                    $config['label'],
                    isset($config['type']) ? [$this, 'text_field_render'] : [$this, 'checkbox_field_render'],
                    'vin_fallback_settings',
                    'vin_fallback_providers_section',
                    [
                        'field' => $field_name,
                        'label' => $config['desc'] ?? '',
                        'type' => $config['type'] ?? ''
                    ]
                );
            }
        }

        foreach ($this->get_provider_registry() as $provider => $provider_config) {
            if (isset($providers_config[$provider])) {
                continue;
            }

            add_settings_field(
                $provider . '_enabled',
                $provider_config['label'] . ' enabled',
                [$this, 'checkbox_field_render'],
                'vin_fallback_settings',
                'vin_fallback_providers_section',
                [
                    'field' => $provider . '_enabled',
                    'label' => 'Включить источник ' . $provider_config['label'],
                ]
            );

            foreach ($provider_config['fields'] as $field => $config) {
                $field_name = $provider . '_' . $field;
                add_settings_field(
                    $field_name,
                    $config['label'],
                    [$this, 'text_field_render'],
                    'vin_fallback_settings',
                    'vin_fallback_providers_section',
                    [
                        'field' => $field_name,
                        'label' => $config['desc'] ?? '',
                        'type' => $config['type'] ?? 'text'
                    ]
                );
            }
        }

        add_settings_field(
            'provider_order',
            'Порядок источников API',
            [$this, 'provider_order_field_render'],
            'vin_fallback_settings',
            'vin_fallback_providers_section'
        );

        add_settings_section(
            'vin_fallback_product_section',
            'Настройки создаваемых товаров',
            [$this, 'product_settings_section_callback'],
            'vin_fallback_settings'
        );

        add_settings_field(
            'title_template',
            'Шаблон названия товара',
            [$this, 'title_template_field_render'],
            'vin_fallback_settings',
            'vin_fallback_product_section'
        );

        $template_fields = [
            'slug_template' => 'Шаблон URL slug',
        ];

        foreach ($template_fields as $field => $label) {
            add_settings_field(
                $field,
                $label,
                [$this, 'template_field_render'],
                'vin_fallback_settings',
                'vin_fallback_product_section',
                ['field' => $field]
            );
        }
    }

    public function sanitize_settings($input)
    {
        $input = is_array($input) ? $input : [];
        $old = get_option('vin_fallback_settings', []);

        $settings = is_array($old) ? $old : [];
        $checkboxes = ['apicar_enabled', 'api2_enabled', 'api3_enabled'];
        foreach ($this->get_provider_registry() as $provider => $provider_config) {
            $checkboxes[] = $provider . '_enabled';
        }
        $checkboxes = array_values(array_unique($checkboxes));
        foreach ($checkboxes as $field) {
            $settings[$field] = !empty($input[$field]) ? 1 : 0;
        }

        $text_fields = [
            'apicar_api_key',
            'api2_login',
            'api2_password',
            'api3_key',
            'provider_order',
            'title_template',
            'slug_template',
        ];

        foreach ($this->get_provider_registry() as $provider => $provider_config) {
            foreach (array_keys($provider_config['fields']) as $field) {
                $text_fields[] = $provider . '_' . $field;
            }
        }

        $text_fields = array_values(array_unique($text_fields));

        foreach ($text_fields as $field) {
            if (isset($input[$field])) {
                $settings[$field] = sanitize_text_field((string)$input[$field]);
            } elseif ($field === 'title_template' && empty($settings[$field])) {
                $settings[$field] = $this->get_default_title_template();
            }
        }

        return $settings;
    }

    /**
     * Рендер чекбокса
     */
    public function checkbox_field_render($args)
    {
        $field = $args['field'];
        $checked = isset($this->settings[$field]) ? $this->settings[$field] : 0;
?>
        <label>
            <input type='checkbox' name='vin_fallback_settings[<?php echo $field; ?>]' value='1' <?php checked(1, $checked); ?>>
            <?php echo $args['label']; ?>
        </label>
    <?php
    }

    /**
     * Рендер текстового поля
     */
    public function text_field_render($args)
    {
        $field = $args['field'];
        $value = isset($this->settings[$field]) ? $this->settings[$field] : '';
        $type = isset($args['type']) ? $args['type'] : 'text';
    ?>
        <input type='<?php echo $type; ?>' name='vin_fallback_settings[<?php echo $field; ?>]' value='<?php echo esc_attr($value); ?>' class='regular-text'>
    <?php
    }

    public function product_settings_section_callback()
    {
        echo 'Настройте, как плагин будет формировать данные нового WooCommerce-товара при импорте по VIN.';
    }

    public function title_template_field_render()
    {
        $value = $this->settings['title_template'] ?? $this->get_default_title_template();
        $tokens = $this->get_title_template_tokens();
    ?>
        <input
            type="text"
            id="vin-title-template"
            name="vin_fallback_settings[title_template]"
            value="<?php echo esc_attr($value); ?>"
            class="large-text"
            placeholder="<?php echo esc_attr($this->get_default_title_template()); ?>"
        >
        <p class="description">Можно менять порядок, добавлять обычный текст и вставлять свойства кнопками ниже.</p>
        <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;max-width:900px;">
            <?php foreach ($tokens as $token => $label) : ?>
                <button type="button" class="button vin-title-token" data-token="<?php echo esc_attr($token); ?>"><?php echo esc_html($label); ?></button>
            <?php endforeach; ?>
        </div>
        <p class="description">
            Пример: <code>{make} {model} {year} - VIN {vin}</code>
        </p>
        <script>
            (function() {
                var input = document.getElementById('vin-title-template');
                if (!input) return;
                document.querySelectorAll('.vin-title-token').forEach(function(button) {
                    button.addEventListener('click', function() {
                        var token = button.getAttribute('data-token') || '';
                        var start = input.selectionStart || input.value.length;
                        var end = input.selectionEnd || input.value.length;
                        var prefix = input.value.slice(0, start);
                        var suffix = input.value.slice(end);
                        var spacer = prefix && !/\s$/.test(prefix) ? ' ' : '';
                        input.value = prefix + spacer + token + suffix;
                        input.focus();
                        var pos = (prefix + spacer + token).length;
                        input.setSelectionRange(pos, pos);
                    });
                });
            })();
        </script>
    <?php
    }

    public function provider_order_field_render()
    {
        $order = $this->get_provider_order();
        $registry = $this->get_provider_registry();
    ?>
        <input type="text" name="vin_fallback_settings[provider_order]" value="<?php echo esc_attr(implode(',', $order)); ?>" class="regular-text">
        <p class="description">Укажите ID источников через запятую. Доступно: <?php echo esc_html(implode(', ', array_keys($registry))); ?>.</p>
        <ol style="margin-top:8px;">
            <?php foreach ($order as $id) : if (!isset($registry[$id])) continue; ?>
                <li><code><?php echo esc_html($id); ?></code> - <?php echo esc_html($registry[$id]['label']); ?></li>
            <?php endforeach; ?>
        </ol>
    <?php
    }

    public function template_field_render($args)
    {
        $field = $args['field'];
        $value = $this->settings[$field] ?? '';
        $target_id = 'vin-template-' . esc_attr($field);
        $tokens = $this->get_title_template_tokens();
    ?>
        <input id="<?php echo $target_id; ?>" type="text" name="vin_fallback_settings[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr($value); ?>" class="large-text">
        <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;max-width:900px;">
            <?php foreach ($tokens as $token => $label) : ?>
                <button type="button" class="button vin-template-token" data-target="<?php echo $target_id; ?>" data-token="<?php echo esc_attr($token); ?>"><?php echo esc_html($label); ?></button>
            <?php endforeach; ?>
        </div>
        <p class="description"><?php echo esc_html($this->get_template_field_hint($field)); ?></p>
        <script>
            (function() {
                if (window.vinTemplateButtonsReady) return;
                window.vinTemplateButtonsReady = true;
                document.addEventListener('click', function(event) {
                    var button = event.target.closest('.vin-template-token');
                    if (!button) return;
                    var input = document.getElementById(button.getAttribute('data-target'));
                    if (!input) return;
                    var token = button.getAttribute('data-token') || '';
                    var start = input.selectionStart || input.value.length;
                    var end = input.selectionEnd || input.value.length;
                    var prefix = input.value.slice(0, start);
                    var suffix = input.value.slice(end);
                    var spacer = prefix && !/\s$/.test(prefix) ? ' ' : '';
                    input.value = prefix + spacer + token + suffix;
                    input.focus();
                    var pos = (prefix + spacer + token).length;
                    input.setSelectionRange(pos, pos);
                });
            })();
        </script>
    <?php
    }

    private function get_template_field_hint($field)
    {
        $hints = [
            'slug_template' => 'Если оставить пустым, используется старая генерация slug. Пример: {make}-{model}-{year}-{lot_number}.',
        ];

        return $hints[$field] ?? '';
    }

    /**
     * Описание секции
     */
    public function settings_section_callback()
    {
        echo 'Настройте API провайдеров для поиска автомобилей по VIN. Можно включить/выключить каждый провайдер отдельно.';
    }

    /**
     * Страница настроек
     */
    public function settings_page()
    {
    ?>
        <div class="wrap">
            <h1>Настройки поиска авто по VIN</h1>

            <form action='options.php' method='post'>
                <?php
                settings_fields('vin_fallback_settings');
                do_settings_sections('vin_fallback_settings');
                submit_button();
                ?>
            </form>

            <div class="card" style="margin-top: 20px;">
                <h3>Тест API по VIN</h3>
                <p>
                    <input type="text" id="vin-api-test-input" class="regular-text" placeholder="19VDE1F5XDE010374">
                    <button type="button" class="button button-primary" id="vin-api-test-button">Проверить активные API</button>
                </p>
                <pre id="vin-api-test-result" style="display:none;max-height:480px;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #dcdcde;"></pre>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3>Логика поиска</h3>
                <ol>
                    <li>Если нашли авто по VIN на сайте - показываем его в поиске</li>
                    <li>Если не нашли - ищем в API 1</li>
                    <li>Если не нашли в API 1 или он выключен - ищем в API 2</li>
                    <li>Если не нашли в API 2 или он выключен - ищем в API 3</li>
                    <li>Если не нашли в API 3 или он выключен - показываем "ничего не найдено"</li>
                    <li>Если нашли авто на сайте, но он в черновиках - показываем "ничего не найдено"</li>
                </ol>

                <h3>Отладка</h3>
                <p>Для тестирования используйте REST URL: <code>/wp-json/master-auto-catalog/v1/vin-data?vin=YOUR_VIN_HERE</code></p>
                <p>Пример: <code>/wp-json/master-auto-catalog/v1/vin-data?vin=19VDE1F5XDE010374</code></p>
            </div>
        </div>
        <script>
            (function() {
                var button = document.getElementById('vin-api-test-button');
                var input = document.getElementById('vin-api-test-input');
                var result = document.getElementById('vin-api-test-result');
                if (!button || !input || !result) return;

                button.addEventListener('click', function() {
                    var vin = (input.value || '').trim();
                    result.style.display = 'block';
                    result.textContent = 'Loading...';
                    button.disabled = true;

                    var body = new URLSearchParams();
                    body.append('action', 'vin_fallback_test_provider');
                    body.append('nonce', '<?php echo esc_js(wp_create_nonce('vin_fallback_test_provider')); ?>');
                    body.append('vin', vin);

                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) { result.textContent = JSON.stringify(data, null, 2); })
                    .catch(function(error) { result.textContent = String(error); })
                    .finally(function() { button.disabled = false; });
                });
            })();
        </script>
    <?php
    }

    /**
     * Главная страница админки
     */
    public function admin_page()
    {
    ?>
        <div class="wrap">
            <h1>Поиск авто по VIN</h1>

            <div class="card">
                <h3>Статус системы</h3>
                <p><strong>Активные провайдеры:</strong>
                    <?php
                    $active_providers = $this->get_active_providers([]);
                    if (empty($active_providers)) {
                        echo 'Нет активных провайдеров. <a href="' . admin_url('admin.php?page=vin-fallback-settings') . '">Настройте API</a>';
                    } else {
                        echo count($active_providers) . ' провайдер(ов) активны';
                        echo '<ul>';
                        foreach ($active_providers as $provider) {
                            echo '<li>' . get_class($provider) . '</li>';
                        }
                        echo '</ul>';
                    }
                    ?>
                </p>
            </div>

            <div class="card">
                <h3>Быстрые действия</h3>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=vin-fallback-settings'); ?>" class="button button-primary">Настройки API</a>
                    <a href="<?php echo esc_url(rest_url('master-auto-catalog/v1/vin-data?vin=19VDE1F5XDE010374')); ?>" target="_blank" class="button">Тестовый поиск</a>
                </p>
            </div>
        </div>
<?php
    }

    /**
     * Стили для админки
     */
    public function admin_styles($hook)
    {
        if (strpos($hook, 'vin-fallback') === false) return;

        echo '<style>
            .vin-status-active { color: green; font-weight: bold; }
            .vin-status-inactive { color: red; }
        </style>';
    }

    /**
     * Получаем только активные провайдеры в правильном порядке (с кэшированием)
     */
    public function get_active_providers($providers)
    {
        // Используем статическое кэширование
        if (self::$cached_providers !== null) {
            return self::$cached_providers;
        }

        $providers = [];
        $registry = $this->get_provider_registry();

        foreach ($this->get_provider_order() as $provider_id) {
            if (empty($registry[$provider_id])) {
                continue;
            }

            $enabled_field = $registry[$provider_id]['enabled_field'] ?? ($provider_id . '_enabled');
            if (empty($this->settings[$enabled_field]) || empty($registry[$provider_id]['factory']) || !is_callable($registry[$provider_id]['factory'])) {
                continue;
            }

            $provider = call_user_func($registry[$provider_id]['factory']);
            if ($provider instanceof VIN_Provider_Interface) {
                $providers[] = $provider;
            }
        }

        self::$cached_providers = $providers;
        return $providers;
    }

    /**
     * Проверяем существование товара по SKU с правильной логикой (оптимизированная)
     */
    protected function check_existing_product($sku)
    {
        // Проверяем внутренний кэш
        if (isset($this->product_cache[$sku])) {
            return $this->product_cache[$sku];
        }

        // Проверяем WordPress object cache
        $cache_key = 'vin_product_' . md5($sku);
        $cached = wp_cache_get($cache_key, 'vin_fallback');

        if ($cached !== false) {
            $this->product_cache[$sku] = $cached;
            return $cached;
        }

        $existing_id = $this->get_product_id_by_sku_any_status($sku);

        if (!$existing_id) {
            $result = null; // Товара нет вообще
        } else {
            $status = get_post_status($existing_id);
            $result = ($status === 'publish') ? $existing_id : false;
        }

        // Сохраняем в кэшах
        $this->product_cache[$sku] = $result;
        wp_cache_set($cache_key, $result, 'vin_fallback', 3600); // Кэш на 1 час

        return $result;
    }

    /**
     * Оптимизированный поиск товара по SKU
     */
    protected function get_product_id_by_sku_any_status(string $sku): int
    {
        global $wpdb;

        $sku = trim($sku);
        if ($sku === '') return 0;

        // Используем подготовленный запрос для безопасности
        $post_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE pm.meta_key = '_sku'
                   AND pm.meta_value = %s
                   AND p.post_type = 'product'
                 LIMIT 1",
                $sku
            )
        );

        return $post_id > 0 ? $post_id : 0;
    }

    /**
     * Оптимизированная проверка VIN
     */
    private function is_valid_vin($vin)
    {
        // Более строгая проверка стандартного VIN (17 символов)
        return preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin);
    }

    /**
     * Получаем метку провайдера по классу
     */
    private function get_provider_tag($provider_class)
    {
        foreach ($this->get_provider_registry() as $provider_config) {
            if (($provider_config['class'] ?? '') === $provider_class && !empty($provider_config['tag'])) {
                return $provider_config['tag'];
            }
        }

        $provider_tags = [
            'VIN_Apicar_Provider'    => 'Apicar',
            'VIN_Second_API_Provider' => 'Auction-api',
            'VIN_Third_API_Provider'  => 'Auctionsapi'
        ];

        return $provider_tags[$provider_class] ?? 'Unknown-API';
    }

    private function get_default_title_template()
    {
        return '{make} {model} {year} {vin}';
    }

    private function get_title_template_tokens()
    {
        return [
            '{make}' => 'Марка',
            '{model}' => 'Модель',
            '{year}' => 'Год',
            '{vin}' => 'VIN',
            '{api_title}' => 'Название из API',
            '{lot_number}' => 'Номер лота',
            '{lot_id}' => 'Lot ID',
            '{generation}' => 'Поколение',
            '{body_type}' => 'Кузов',
            '{color}' => 'Цвет',
            '{engine}' => 'Двигатель',
            '{transmission}' => 'КПП',
            '{fuel}' => 'Топливо',
            '{drive}' => 'Привод',
            '{odometer}' => 'Пробег',
            '{damage_primary}' => 'Основное повреждение',
            '{damage_secondary}' => 'Доп. повреждение',
            '{location}' => 'Локация',
            '{auction}' => 'Аукцион',
            '{sale_status}' => 'Статус продажи',
            '{sale_date}' => 'Дата продажи',
            '{price}' => 'Цена',
            '{provider}' => 'Источник API',
        ];
    }

    private function build_product_title(array $item, $provider_class = null)
    {
        $template = trim((string)($this->settings['title_template'] ?? ''));

        if ($template === '') {
            $template = $this->get_default_title_template();
        }

        $title = $this->render_item_template($template, $item, $provider_class);

        if ($title === '') {
            $sku = sanitize_text_field($item['sku'] ?? '');
            $title = wp_strip_all_tags($item['title'] ?? $sku);
        }

        return $title;
    }

    private function get_template_values(array $item, $provider_class = null)
    {
        $sku = sanitize_text_field($item['sku'] ?? '');
        $meta = isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : [];

        $values = [
            'vin' => $sku,
            'sku' => $sku,
            'api_title' => $item['title'] ?? '',
            'make' => $meta['make'] ?? $meta['marka'] ?? '',
            'model' => $meta['model'] ?? '',
            'year' => $meta['year'] ?? $meta['car_year'] ?? '',
            'lot_number' => $meta['lot_number'] ?? '',
            'lot_id' => $meta['lot_id'] ?? '',
            'generation' => $meta['generation'] ?? '',
            'body_type' => $meta['body_type'] ?? $meta['vehicle_type'] ?? '',
            'color' => $meta['color'] ?? $meta['exterior'] ?? '',
            'engine' => $meta['engine'] ?? '',
            'transmission' => $meta['transmission'] ?? '',
            'fuel' => $meta['fuel'] ?? '',
            'drive' => $meta['drive'] ?? $meta['drive_wheel'] ?? '',
            'odometer' => $meta['odometer'] ?? $meta['mileage'] ?? '',
            'damage_primary' => $meta['damage_primary'] ?? $meta['osnovnye-povrezhdeniya'] ?? '',
            'damage_secondary' => $meta['damage_secondary'] ?? $meta['dopolnitelnye-povrezhdeniya'] ?? '',
            'location' => $meta['location'] ?? '',
            'auction' => $meta['auction'] ?? $meta['base_site'] ?? '',
            'sale_status' => $meta['sale_status'] ?? '',
            'sale_date' => $meta['sale_date'] ?? '',
            'price' => isset($item['price']) && (float)$item['price'] > 0 ? (string)$item['price'] : '',
            'provider' => $provider_class ? $this->get_provider_tag($provider_class) : '',
        ];

        foreach ($meta as $key => $value) {
            $normalized_key = str_replace('-', '_', strtolower((string)$key));
            if (!isset($values[$normalized_key]) && is_scalar($value)) {
                $values[$normalized_key] = $value;
            }
        }

        return $values;
    }

    private function render_item_template($template, array $item, $provider_class = null)
    {
        $values = $this->get_template_values($item, $provider_class);

        $title = preg_replace_callback('/\{([a-zA-Z0-9_\-]+)\}/', function ($matches) use ($values) {
            $key = str_replace('-', '_', strtolower($matches[1]));
            $value = $values[$key] ?? '';

            if (is_array($value)) {
                $value = implode(' ', array_filter($value, 'is_scalar'));
            }

            return is_scalar($value) ? (string)$value : '';
        }, $template);

        $title = wp_strip_all_tags((string)$title);
        $title = preg_replace('/\s+/', ' ', trim($title));
        $title = preg_replace('/\s+([,.;:!?])/', '$1', $title);
        $title = trim($title, " \t\n\r\0\x0B-_,.;:");

        return $title;
    }

    private function build_product_slug(array $item, $title, $sku, $provider_class = null)
    {
        $template = trim((string)($this->settings['slug_template'] ?? ''));

        if ($template === '') {
            return VIN_Data_Normalizer::generate_slug($item, $title, $sku);
        }

        $slug = $this->render_item_template($template, $item, $provider_class);
        $slug = sanitize_title($slug);

        return $slug !== '' ? $slug : VIN_Data_Normalizer::generate_slug($item, $title, $sku);
    }

    /**
     * Создает новый товар с меткой провайдера
     */
    protected function create_new_product(array $item, $provider_class = null): int
    {
        $sku = sanitize_text_field($item['sku'] ?? '');
        $title = $this->build_product_title($item, $provider_class);
        $price = isset($item['price']) ? floatval($item['price']) : 0.0;

        if (VIN_FS_DEBUG) {
            error_log('[VIN Fallback] Creating product from item:');
            error_log('[VIN Fallback] - SKU: ' . $sku);
            error_log('[VIN Fallback] - Title: ' . $title);
            error_log('[VIN Fallback] - Price: ' . $price);
            error_log('[VIN Fallback] - Provider: ' . $provider_class);
        }

        // Формируем slug с помощью нормализатора
        $slug = $this->build_product_slug($item, $title, $sku, $provider_class);

        if (VIN_FS_DEBUG) {
            error_log('[VIN Fallback] SLUG DEBUG: sku=' . $sku);
            error_log('[VIN Fallback] SLUG DEBUG: generated=' . $slug);
        }

        // Создаём пост-товар
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ], true);

        $after = get_post_field('post_name', $post_id);
        if (VIN_FS_DEBUG) {
            error_log('[VIN Fallback] SLUG AFTER INSERT: ' . $after);
        }

        if (is_wp_error($post_id) || !$post_id) {
            if (VIN_FS_DEBUG) {
                $msg = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown';
                error_log('[VIN Fallback] wp_insert_post error: ' . $msg);
            }
            return 0;
        }

        // Настраиваем товар с меткой провайдера
        $this->setup_product_data($post_id, $item, $sku, $price, $provider_class);

        $after2 = get_post_field('post_name', $post_id);
        if (VIN_FS_DEBUG) {
            error_log('[VIN Fallback] SLUG AFTER SETUP: ' . $after2);
        }

        if (VIN_FS_DEBUG) {
            error_log('[VIN Fallback] New product created: #' . $post_id);
        }

        return (int)$post_id;
    }

    /**
     * Оптимизированная настройка данных товара с метками
     */
    protected function setup_product_data($post_id, $item, $sku, $price, $provider_class = null)
    {
        // Тип товара — simple
        wp_set_object_terms($post_id, 'simple', 'product_type');

        // Добавляем метку провайдера если указан
        if ($provider_class) {
            $provider_tag = $this->get_provider_tag($provider_class);
            if ($provider_tag && $provider_tag !== 'Unknown-API') {
                wp_set_object_terms($post_id, $provider_tag, 'product_tag', true);

                if (VIN_FS_DEBUG) {
                    error_log('[VIN Fallback] Added provider tag: ' . $provider_tag);
                }
            }
        }

        // Подготавливаем все метаданные для батчинга
        $meta_data = [
            '_sku' => $sku,
            '_stock_status' => 'instock',
            '_manage_stock' => 'no',
            '_sold_individually' => 'no',
            '_virtual' => 'no',
            '_downloadable' => 'no',
        ];

        // Обработка цены
        if ($price > 0) {
            $meta_data['_regular_price'] = $price;
            $meta_data['_price'] = $price;
            $meta_data['_sale_price'] = '';
        } else {
            $meta_data['_regular_price'] = '';
            $meta_data['_price'] = '';
        }

        // Lot ID и метаданные
        $meta = $item['meta'] ?? [];
        $lot_id = VIN_Data_Normalizer::extract_lot_id($meta);

        if (!empty($lot_id)) {
            $meta_data['lot_number'] = sanitize_text_field($lot_id);
            $meta_data['_global_unique_id'] = sanitize_text_field($lot_id);
            $meta_data['_gtin'] = sanitize_text_field($lot_id);
            $meta_data['lot_id'] = sanitize_text_field($lot_id);
        }

        // Кастомные метаданные из API
        if (!empty($meta)) {
            foreach ($meta as $k => $v) {
                $key = sanitize_key($k);
                if (is_scalar($v) && !empty($v)) {
                    $meta_data[$key] = sanitize_text_field((string)$v);
                }
            }
        }

        // Массовое обновление метаданных
        $this->update_post_meta_batch($post_id, $meta_data);

        // Изображения
        if (!empty($item['images']) && is_array($item['images'])) {
            $this->image_processor->attach_images($post_id, $item['images']);
        }

        // Атрибуты и категория
        if (!empty($meta)) {
            $this->setup_attributes_and_category($post_id, $meta);
        }

        if (VIN_FS_DEBUG) {
            $saved_price = get_post_meta($post_id, '_price', true);
            $saved_tags = wp_get_post_terms($post_id, 'product_tag', ['fields' => 'names']);
            error_log('[VIN Fallback] After setup - Saved price: ' . $saved_price);
            error_log('[VIN Fallback] Product tags: ' . implode(', ', $saved_tags));
        }
    }

    /**
     * Основная логика поиска (обновленная для передачи провайдера)
     */
    public function maybe_handle_search_fallback()
    {
        if (!is_search() || !is_main_query()) return;
        if (!class_exists('WooCommerce')) return;

        global $wp_query;

        if (VIN_FS_DEBUG) {
            error_log('[VIN Fallback] === START SEARCH ===');
            error_log('[VIN Fallback] Search query: "' . get_search_query() . '"');
            error_log('[VIN Fallback] Found posts: ' . intval($wp_query->found_posts));
        }

        // 1) Если штатный поиск что-то нашёл — уходим
        if (intval($wp_query->found_posts) > 0) {
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] WooCommerce found products - exit');
            return;
        }

        // Пробуем интерпретировать строку поиска как VIN
        $raw_q = get_search_query();
        $q = strtoupper(preg_replace('/\s+/', '', $raw_q));

        // Оптимизированная проверка VIN
        if (!$this->is_valid_vin($q)) {
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] Query "' . $q . '" не похож на VIN — оставляем "ничего не найдено".');
            return;
        }

        if (VIN_FS_DEBUG) error_log('[VIN Fallback] Valid VIN detected: ' . $q);

        // Проверяем существование товара на сайте
        $existing_product = $this->check_existing_product($q);

        if ($existing_product === false) {
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] Product exists but is draft - show "nothing found"');
            return;
        }

        if ($existing_product !== null) {
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] Product exists and published: #' . $existing_product . ' - redirecting');
            wp_safe_redirect(get_permalink($existing_product), 302);
            exit;
        }

        // Товара нет на сайте - ищем в API провайдерах
        $providers = apply_filters('vin_fallback_providers', []);

        if (empty($providers)) {
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] No active providers - show "nothing found"');
            return;
        }

        if (VIN_FS_DEBUG) error_log('[VIN Fallback] Starting API search with ' . count($providers) . ' providers');

        // Последовательно опрашиваем API провайдеры
        foreach ($providers as $index => $provider) {
            $provider_class = get_class($provider);
            $provider_name = $provider_class;

            if (VIN_FS_DEBUG) error_log('[VIN Fallback] Trying provider ' . ($index + 1) . ': ' . $provider_name);

            try {
                $item = $provider->search($q);

                if ($item && is_array($item)) {
                    if (VIN_FS_DEBUG) error_log('[VIN Fallback] Provider ' . $provider_name . ' found vehicle');

                    // Создаем товар и редиректим (передаем класс провайдера)
                    $product_id = $this->create_or_get_product($item, $provider_class);

                    if ($product_id) {
                        if (VIN_FS_DEBUG) error_log('[VIN Fallback] Product created: #' . $product_id . ' - redirecting');
                        wp_safe_redirect(get_permalink($product_id), 302);
                        exit;
                    } else {
                        if (VIN_FS_DEBUG) error_log('[VIN Fallback] Failed to create product from provider: ' . $provider_name);
                    }
                } else {
                    if (VIN_FS_DEBUG) error_log('[VIN Fallback] Provider ' . $provider_name . ' found nothing');
                }
            } catch (Exception $e) {
                if (VIN_FS_DEBUG) error_log('[VIN Fallback] Provider error (' . $provider_name . '): ' . $e->getMessage());
            }
        }

        if (VIN_FS_DEBUG) error_log('[VIN Fallback] No providers found vehicle - show "nothing found"');
        return;
    }

    public function import_by_vin($vin)
    {
        if (!class_exists('WooCommerce')) {
            return ['success' => false, 'message' => 'WooCommerce not available'];
        }

        $q = strtoupper(preg_replace('/\s+/', '', (string)$vin));

        if (!$this->is_valid_vin($q)) {
            return ['success' => false, 'message' => 'Invalid VIN format'];
        }

        // 1) Проверяем, есть ли товар на сайте
        $existing_product = $this->check_existing_product($q);

        if ($existing_product === false) {
            // у вас это означает "есть, но draft"
            return ['success' => false, 'message' => 'Vehicle exists but is draft'];
        }

        if ($existing_product !== null) {
            $modified_gmt = get_post_field('post_modified_gmt', (int)$existing_product);

            return [
                'success' => true,
                'message' => 'Vehicle already exists',
                'product_id' => (int)$existing_product,
                'status' => 'published',
                'url' => get_permalink($existing_product),
                'already_exists' => true,

                // ✅ дата изменения на доноре
                'donor_modified_at' => (string)$modified_gmt,
            ];
        }

        // 2) Товара нет — ищем по провайдерам
        $providers = apply_filters('vin_fallback_providers', []);

        if (empty($providers)) {
            return ['success' => false, 'message' => 'No active providers'];
        }

        foreach ($providers as $provider) {
            try {
                $item = $provider->search($q);

                if ($item && is_array($item)) {
                    $provider_class = get_class($provider);
                    $product_id = $this->create_or_get_product($item, $provider_class);

                    if ($product_id) {
                        // ✅ добавляем метку-источник (НЕ затираем существующие метки)
                        $tag_name = (string)$provider_class;
                        $tag_name = trim($tag_name);
                        if ($tag_name !== '') {
                            $r = wp_set_object_terms((int)$product_id, [$tag_name], 'product_tag', true);
                            // ошибки не валим
                        }

                        // ✅ donor modified
                        $modified_gmt = get_post_field('post_modified_gmt', (int)$product_id);

                        return [
                            'success' => true,
                            'message' => 'Vehicle imported successfully',
                            'product_id' => (int)$product_id,
                            'url' => get_permalink($product_id),
                            'already_exists' => false,

                            // ✅ дата изменения на доноре
                            'donor_modified_at' => (string)$modified_gmt,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                if (defined('VIN_FS_DEBUG') && VIN_FS_DEBUG) {
                    error_log('[VIN Fallback] import_by_vin provider error: ' . $e->getMessage());
                }
            }
        }

        return ['success' => false, 'message' => 'No providers found vehicle'];
    }





    /**
     * Создаёт (или возвращает) товар WooCommerce с меткой провайдера
     */
    protected function create_or_get_product(array $item, $provider_class = null): int
    {
        $sku = sanitize_text_field($item['sku'] ?? '');
        if (!$sku) return 0;

        // Проверяем существование товара
        $existing_product = $this->check_existing_product($sku);

        if ($existing_product && $existing_product !== false) {
            return (int) $existing_product;
        }

        if ($existing_product === false) {
            return 0;
        }

        // Создаем новый товар с меткой провайдера
        return $this->create_new_product($item, $provider_class);
    }

    public function ajax_test_provider()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        check_ajax_referer('vin_fallback_test_provider', 'nonce');

        $vin = strtoupper(preg_replace('/\s+/', '', sanitize_text_field((string)($_POST['vin'] ?? ''))));
        if (!$this->is_valid_vin($vin)) {
            wp_send_json_error(['message' => 'Invalid VIN format', 'vin' => $vin], 400);
        }

        $providers = apply_filters('vin_fallback_providers', []);
        $out = [
            'vin' => $vin,
            'providers_count' => count($providers),
            'providers' => [],
        ];

        foreach ($providers as $provider) {
            $provider_class = get_class($provider);
            $started = microtime(true);

            try {
                $item = $provider->search($vin);
                $generated_title = $item ? $this->build_product_title($item, $provider_class) : null;

                $out['providers'][] = [
                    'class' => $provider_class,
                    'tag' => $this->get_provider_tag($provider_class),
                    'elapsed_ms' => round((microtime(true) - $started) * 1000),
                    'found' => (bool)$item,
                    'generated_title' => $generated_title,
                    'generated_slug' => $item ? $this->build_product_slug($item, $generated_title, $item['sku'] ?? $vin, $provider_class) : null,
                    'item' => $item,
                ];
            } catch (\Throwable $e) {
                $out['providers'][] = [
                    'class' => $provider_class,
                    'tag' => $this->get_provider_tag($provider_class),
                    'elapsed_ms' => round((microtime(true) - $started) * 1000),
                    'found' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        wp_send_json_success($out);
    }

    public function register_rest_routes()
    {
        register_rest_route('master-auto-catalog/v1', '/vin-data', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_vin_data'],
            'permission_callback' => [$this, 'rest_can_read_vin_data'],
            'args' => [
                'vin' => [
                    'required' => true,
                ],
            ],
        ]);

        register_rest_route('master-auto-catalog/v1', '/local-vin-data', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_local_vin_data'],
            'permission_callback' => [$this, 'rest_can_read_vin_data'],
            'args' => [
                'vin' => [
                    'required' => true,
                ],
            ],
        ]);
    }

    public function rest_can_read_vin_data($request)
    {
        $configured_key = trim((string)get_option('cas_sync_key', ''));
        $request_key = trim((string)$request->get_header('X-API-Key'));

        return $configured_key !== '' && hash_equals($configured_key, $request_key);
    }

    public function rest_get_vin_data($request)
    {
        $vin = strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', sanitize_text_field((string)$request->get_param('vin'))));
        if (!$this->is_valid_vin($vin)) {
            return new WP_Error('invalid_vin', 'Invalid VIN format', ['status' => 400]);
        }

        $providers = apply_filters('vin_fallback_providers', []);
        $out = [
            'vin' => $vin,
            'providers_count' => count($providers),
            'providers' => [],
        ];

        foreach ($providers as $provider) {
            $provider_class = get_class($provider);
            $started = microtime(true);

            try {
                $item = $provider->search($vin);
                $generated_title = $item ? $this->build_product_title($item, $provider_class) : null;

                $out['providers'][] = [
                    'class' => $provider_class,
                    'tag' => $this->get_provider_tag($provider_class),
                    'elapsed_ms' => round((microtime(true) - $started) * 1000),
                    'found' => (bool)$item,
                    'generated_title' => $generated_title,
                    'generated_slug' => $item ? $this->build_product_slug($item, $generated_title, $item['sku'] ?? $vin, $provider_class) : null,
                    'item' => $item,
                ];
            } catch (\Throwable $e) {
                $out['providers'][] = [
                    'class' => $provider_class,
                    'tag' => $this->get_provider_tag($provider_class),
                    'elapsed_ms' => round((microtime(true) - $started) * 1000),
                    'found' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'data' => $out,
        ];
    }
    /**
     * Массовое обновление метаданных
     */
    public function rest_get_local_vin_data($request)
    {
        $vin = strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', sanitize_text_field((string)$request->get_param('vin'))));
        if (!$this->is_valid_vin($vin)) {
            return new WP_Error('invalid_vin', 'Invalid VIN format', ['status' => 400]);
        }

        $item = $this->build_local_vin_item($vin);
        $provider = [
            'class' => 'Local_Woo_Product',
            'tag' => 'Local Woo Product',
            'found' => (bool)$item,
            'item' => $item,
        ];

        return [
            'success' => true,
            'data' => [
                'vin' => $vin,
                'providers_count' => 1,
                'providers' => [$provider],
            ],
        ];
    }

    private function build_local_vin_item(string $vin): ?array
    {
        $post_id = $this->get_product_id_by_sku_any_status($vin);
        if ($post_id <= 0 || get_post_type($post_id) !== 'product') {
            return null;
        }

        $meta = $this->get_local_product_meta($post_id, $vin);
        $price = get_post_meta($post_id, '_price', true);

        return [
            'sku' => $vin,
            'title' => get_the_title($post_id),
            'price' => is_numeric($price) ? (float)$price : 0,
            'meta' => $meta,
            'images' => $this->get_local_product_images($post_id),
        ];
    }

    private function get_local_product_meta(int $post_id, string $vin): array
    {
        $raw_meta = get_post_meta($post_id);
        $meta = [
            'vin' => $vin,
            'permalink' => get_permalink($post_id),
            'post_status' => get_post_status($post_id),
        ];

        foreach ($raw_meta as $key => $values) {
            if ($key === '' || $key[0] === '_') {
                continue;
            }

            $value = is_array($values) ? reset($values) : $values;
            if (is_scalar($value) && trim((string)$value) !== '') {
                $meta[$key] = sanitize_text_field((string)$value);
            }
        }

        foreach (['make', 'model', 'year', 'engine', 'lot_id', 'lot_number', 'odometer', 'damage_primary', 'damage_secondary', 'damage_pr', 'damage_sec', 'color', 'location', 'sale_date', 'sale_status', 'auction', 'raw_api_source'] as $key) {
            if (!isset($meta[$key])) {
                $value = get_post_meta($post_id, $key, true);
                if (is_scalar($value) && trim((string)$value) !== '') {
                    $meta[$key] = sanitize_text_field((string)$value);
                }
            }
        }

        return $meta;
    }

    private function get_local_product_images(int $post_id): array
    {
        $images = [];
        $thumb_id = (int)get_post_thumbnail_id($post_id);
        if ($thumb_id > 0) {
            $url = wp_get_attachment_url($thumb_id);
            if ($url) {
                $images[] = $url;
            }
        }

        $gallery = (string)get_post_meta($post_id, '_product_image_gallery', true);
        foreach (array_filter(array_map('trim', explode(',', $gallery))) as $attachment_id) {
            $url = wp_get_attachment_url((int)$attachment_id);
            if ($url) {
                $images[] = $url;
            }
        }

        return array_values(array_unique(array_filter($images)));
    }
    private function update_post_meta_batch($post_id, $meta_array)
    {
        foreach ($meta_array as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
    }

    /**
     * Настройка атрибутов и категории
     */
    protected function setup_attributes_and_category($post_id, $meta)
    {
        try {
            // Устанавливаем атрибуты
            $this->attribute_manager->set_attributes_from_meta($post_id, $meta);

            // Назначаем категорию
            $make = (string)($meta['make'] ?? $meta['marka'] ?? '');
            $this->attribute_manager->assign_category_by_make($post_id, $make);
        } catch (Exception $e) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Error setting attributes: ' . $e->getMessage());
            }
        }
    }
}


// Инициализация плагина
$GLOBALS['mac_vin_fallback'] = new VINFallbackSearch();
