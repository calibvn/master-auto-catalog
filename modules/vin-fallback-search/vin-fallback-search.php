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
        add_action('template_redirect', [$this, 'maybe_redirect_hidden_product'], 0);
        add_action('template_redirect', [$this, 'maybe_handle_search_fallback'], 1);
        add_filter('vin_fallback_providers', [$this, 'get_active_providers']);
        add_filter('woocommerce_product_query_meta_query', [$this, 'exclude_hidden_products_from_woocommerce_queries'], 20, 2);
        add_filter('woocommerce_product_is_visible', [$this, 'exclude_hidden_product_from_visibility'], 20, 2);
        add_action('pre_get_posts', [$this, 'exclude_hidden_products_from_main_search'], 20);

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
                    'api_key' => ['label' => 'API ключ', 'type' => 'password'],
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
                    'login' => ['label' => 'Логин (email)', 'type' => 'text'],
                    'password' => ['label' => 'Пароль', 'type' => 'password'],
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
                    'key' => ['label' => 'API ключ (x-api-key)', 'type' => 'password'],
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

    private function get_provider_state($provider_id, array $config)
    {
        $enabled_field = $config['enabled_field'] ?? ($provider_id . '_enabled');
        $configured = is_callable($config['factory'] ?? null) && call_user_func($config['factory']) instanceof VIN_Provider_Interface;

        if (empty($this->settings[$enabled_field])) {
            return ['key' => 'off', 'label' => 'Выключен'];
        }
        if (!$configured) {
            return ['key' => 'warn', 'label' => 'Не заполнены доступы'];
        }
        return ['key' => 'good', 'label' => 'Участвует в поиске'];
    }

    private function render_provider_card($provider_id, array $config, $position)
    {
        $enabled_field = $config['enabled_field'] ?? ($provider_id . '_enabled');
        $state = $this->get_provider_state($provider_id, $config);
    ?>
        <li class="vfs-provider vfs-state-<?php echo esc_attr($state['key']); ?>" data-id="<?php echo esc_attr($provider_id); ?>">
            <div class="vfs-provider-head">
                <span class="vfs-position"><?php echo (int) $position; ?></span>
                <div class="vfs-provider-name">
                    <strong><?php echo esc_html($config['label']); ?></strong>
                    <code><?php echo esc_html($provider_id); ?></code>
                </div>
                <span class="vfs-badge vfs-badge-<?php echo esc_attr($state['key']); ?>"><?php echo esc_html($state['label']); ?></span>
                <label class="vfs-switch">
                    <input type="checkbox" name="vin_fallback_settings[<?php echo esc_attr($enabled_field); ?>]" value="1" <?php checked(1, (int) ($this->settings[$enabled_field] ?? 0)); ?>>
                    Включить
                </label>
                <span class="vfs-move">
                    <button type="button" class="button button-small vfs-move-up" title="Выше" aria-label="Поднять выше">&uarr;</button>
                    <button type="button" class="button button-small vfs-move-down" title="Ниже" aria-label="Опустить ниже">&darr;</button>
                </span>
            </div>
            <div class="vfs-provider-fields">
                <?php foreach ($config['fields'] as $field => $field_config) :
                    $name = $provider_id . '_' . $field;
                    $type = $field_config['type'] ?? 'text';
                ?>
                    <label>
                        <span><?php echo esc_html($field_config['label']); ?></span>
                        <input type="<?php echo esc_attr($type); ?>" name="vin_fallback_settings[<?php echo esc_attr($name); ?>]" value="<?php echo esc_attr($this->settings[$name] ?? ''); ?>" autocomplete="<?php echo $type === 'password' ? 'new-password' : 'off'; ?>">
                    </label>
                <?php endforeach; ?>
            </div>
        </li>
    <?php
    }

    public function template_field_render($field)
    {
        $default = $field === 'title_template' ? $this->get_default_title_template() : '';
        $value = $this->settings[$field] ?? $default;
        $target_id = 'vin-template-' . $field;
    ?>
        <input id="<?php echo esc_attr($target_id); ?>" type="text" name="vin_fallback_settings[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($default); ?>" class="large-text">
        <div class="vfs-tokens">
            <?php foreach ($this->get_title_template_tokens() as $token => $label) : ?>
                <button type="button" class="button button-small vin-template-token" data-target="<?php echo esc_attr($target_id); ?>" data-token="<?php echo esc_attr($token); ?>"><?php echo esc_html($label); ?></button>
            <?php endforeach; ?>
        </div>
        <p class="description"><?php echo esc_html($this->get_template_field_hint($field)); ?></p>
    <?php
    }

    private function get_template_field_hint($field)
    {
        $hints = [
            'title_template' => 'Можно менять порядок, добавлять обычный текст и вставлять свойства кнопками. Пример: {make} {model} {year} - VIN {vin}.',
            'slug_template' => 'Если оставить пустым, используется стандартная генерация slug. Пример: {make}-{model}-{year}-{lot_number}.',
        ];

        return $hints[$field] ?? '';
    }

    /**
     * Страница настроек
     */
    public function settings_page()
    {
        $registry = $this->get_provider_registry();
        $order = $this->get_provider_order();
        $standalone = !(defined('MAC_MASTER_ACTIVE') && MAC_MASTER_ACTIVE);
    ?>
        <div class="wrap vfs-page">
            <?php if ($standalone) : ?>
                <h1>Настройки поиска авто по VIN</h1>
            <?php endif; ?>
            <?php settings_errors(); ?>

            <nav class="vfs-toc">
                <a href="#vfs-sources">1. Источники API</a>
                <a href="#vfs-product">2. Создаваемые товары</a>
                <a href="#vfs-test">3. Тест API по VIN</a>
                <a href="#vfs-logic">4. Как работает поиск</a>
            </nav>

            <form action="options.php" method="post">
                <?php settings_fields('vin_fallback_settings'); ?>

                <section class="vfs-panel" id="vfs-sources">
                    <header>
                        <h2>1. Источники API</h2>
                        <p>Поиск идёт сверху вниз до первого найденного авто. Порядок меняется стрелками и сохраняется вместе с настройками.</p>
                    </header>
                    <ol class="vfs-providers" id="vfs-providers">
                        <?php foreach ($order as $i => $provider_id) {
                            $this->render_provider_card($provider_id, $registry[$provider_id], $i + 1);
                        } ?>
                    </ol>
                    <input type="hidden" id="vfs-provider-order" name="vin_fallback_settings[provider_order]" value="<?php echo esc_attr(implode(',', $order)); ?>">
                </section>

                <section class="vfs-panel" id="vfs-product">
                    <header>
                        <h2>2. Создаваемые товары</h2>
                        <p>Как плагин формирует название и URL нового WooCommerce-товара при импорте по VIN.</p>
                    </header>
                    <div class="vfs-field">
                        <label for="vin-template-title_template">Шаблон названия товара</label>
                        <?php $this->template_field_render('title_template'); ?>
                    </div>
                    <div class="vfs-field">
                        <label for="vin-template-slug_template">Шаблон URL (slug)</label>
                        <?php $this->template_field_render('slug_template'); ?>
                    </div>
                </section>

                <div class="vfs-save"><?php submit_button('Сохранить настройки', 'primary', 'submit', false); ?></div>
            </form>

            <section class="vfs-panel" id="vfs-test">
                <header>
                    <h2>3. Тест API по VIN</h2>
                    <p>Опрашивает все включённые источники и ничего не создаёт на сайте. По каждому запросу видно, что мы отправили и что ответил сервер. «Скопировать для поддержки» собирает готовый текст для техподдержки API, ключи и пароли в нём замаскированы.</p>
                </header>
                <div class="vfs-test-form">
                    <input type="text" id="vin-api-test-input" class="regular-text" maxlength="17" placeholder="19VDE1F5XDE010374" autocomplete="off">
                    <button type="button" class="button button-primary" id="vin-api-test-button">Проверить</button>
                    <span class="description">Используются сохранённые настройки.</span>
                </div>
                <div id="vin-api-test-result" class="vfs-test-result" hidden></div>
            </section>

            <section class="vfs-panel" id="vfs-logic">
                <header><h2>4. Как работает поиск</h2></header>
                <ol class="vfs-logic">
                    <li>VIN уже есть на сайте и товар опубликован: показываем его.</li>
                    <li>Товар есть, но в черновиках или скрыт: показываем «ничего не найдено».</li>
                    <?php foreach ($order as $provider_id) :
                        $state = $this->get_provider_state($provider_id, $registry[$provider_id]);
                    ?>
                        <li>
                            Ищем в <strong><?php echo esc_html($registry[$provider_id]['label']); ?></strong>, если нашли, создаём товар и показываем его.
                            <?php if ($state['key'] !== 'good') : ?><span class="vfs-badge vfs-badge-<?php echo esc_attr($state['key']); ?>">Пропускается: <?php echo esc_html(mb_strtolower($state['label'])); ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <li>Нигде не нашли: показываем «ничего не найдено».</li>
                </ol>
                <p class="description">REST для отладки: <code>GET /wp-json/master-auto-catalog/v1/vin-data?vin=VIN</code> с заголовком <code>X-API-Key</code>, равным Sync Key из «Синхронизации с центром».</p>
            </section>
        </div>
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

        $base = plugin_dir_path(__FILE__) . 'assets/';
        $url = plugin_dir_url(__FILE__) . 'assets/';
        wp_enqueue_style('vin-fallback-admin', $url . 'admin.css', [], (string) filemtime($base . 'admin.css'));
        wp_enqueue_script('vin-fallback-admin', $url . 'admin.js', [], (string) filemtime($base . 'admin.js'), true);
        wp_localize_script('vin-fallback-admin', 'vinFallbackAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('vin_fallback_test_provider'),
        ]);
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
    private function is_redirect_hidden_product(int $product_id): bool
    {
        return $product_id > 0 && get_post_meta($product_id, '_mac_vin_hide_mode', true) === 'redirect';
    }

    private function is_hidden_placeholder(int $product_id): bool
    {
        return $product_id > 0 && get_post_meta($product_id, '_mac_vin_hidden_placeholder', true) === '1';
    }

    private function append_hidden_product_clause(array $meta_query): array
    {
        $hidden_clause = [
            'key' => '_mac_vin_hide_mode',
            'compare' => 'NOT EXISTS',
        ];

        if (empty($meta_query)) {
            return [$hidden_clause];
        }

        return [
            'relation' => 'AND',
            $meta_query,
            $hidden_clause,
        ];
    }

    public function exclude_hidden_products_from_woocommerce_queries($meta_query, $query)
    {
        if (is_admin()) {
            return $meta_query;
        }

        return $this->append_hidden_product_clause(is_array($meta_query) ? $meta_query : []);
    }

    public function exclude_hidden_product_from_visibility($visible, $product_id)
    {
        if (is_admin() || !$visible) {
            return $visible;
        }

        return !$this->is_redirect_hidden_product((int)$product_id);
    }

    public function exclude_hidden_products_from_main_search($query)
    {
        if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
            return;
        }

        $query->set('meta_query', $this->append_hidden_product_clause((array)$query->get('meta_query')));
    }

    public function maybe_redirect_hidden_product()
    {
        if (is_admin() || !is_singular('product')) {
            return;
        }

        $product_id = (int)get_queried_object_id();
        if (!$this->is_redirect_hidden_product($product_id)) {
            return;
        }

        if (!headers_sent()) {
            header('X-Robots-Tag: noindex', true);
        }

        wp_safe_redirect(home_url('/'), 302);
        exit;
    }

    public function hide_product(int $product_id, string $hide_mode = 'draft')
    {
        if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
            return new WP_Error('invalid_product', 'Invalid product ID', ['status' => 400]);
        }

        $hide_mode = $hide_mode === 'redirect' ? 'redirect' : 'draft';

        if ($this->is_hidden_placeholder($product_id)) {
            delete_post_meta($product_id, '_mac_vin_hide_mode');

            if (get_post_status($product_id) !== 'draft') {
                $updated = wp_update_post([
                    'ID' => $product_id,
                    'post_status' => 'draft',
                ], true);
                if (is_wp_error($updated)) {
                    return $updated;
                }
            }

            return [
                'success' => true,
                'message' => 'Vehicle placeholder remains in draft',
                'product_id' => $product_id,
                'status' => 'draft',
                'hide_mode' => 'draft',
                'placeholder' => true,
                'url' => get_permalink($product_id),
            ];
        }

        // A redirect hides a product visitors could already open. Publishing a
        // draft would add it to the sitemap under its draft title and slug
        // (old "Product" drafts became /product-80/ that way), so a product
        // that is not published stays a draft.
        if ($hide_mode === 'redirect' && get_post_status($product_id) !== 'publish') {
            delete_post_meta($product_id, '_mac_vin_hide_mode');

            return [
                'success' => true,
                'message' => 'Vehicle is not published; kept in draft instead of redirect',
                'product_id' => $product_id,
                'status' => (string)get_post_status($product_id),
                'hide_mode' => 'draft',
                'url' => get_permalink($product_id),
            ];
        }

        if ($hide_mode === 'redirect') {
            update_post_meta($product_id, '_mac_vin_hide_mode', 'redirect');

            return [
                'success' => true,
                'message' => 'Vehicle hidden with redirect',
                'product_id' => $product_id,
                'status' => 'publish',
                'hide_mode' => 'redirect',
                'url' => get_permalink($product_id),
            ];
        }

        delete_post_meta($product_id, '_mac_vin_hide_mode');

        if (get_post_status($product_id) === 'draft') {
            return [
                'success' => true,
                'message' => 'Vehicle already in draft',
                'product_id' => $product_id,
                'status' => 'draft',
                'hide_mode' => 'draft',
                'url' => get_permalink($product_id),
                'already_in_draft' => true,
            ];
        }

        $updated = wp_update_post([
            'ID' => $product_id,
            'post_status' => 'draft',
        ], true);

        if (is_wp_error($updated)) {
            return $updated;
        }

        return [
            'success' => true,
            'message' => 'Vehicle moved to draft',
            'product_id' => $product_id,
            'status' => 'draft',
            'hide_mode' => 'draft',
            'url' => get_permalink($product_id),
            'already_in_draft' => false,
        ];
    }

    public function create_hidden_placeholder(string $vin)
    {
        $vin = strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', trim($vin)));
        if (!$this->is_valid_vin($vin)) {
            return new WP_Error('invalid_vin', 'Invalid VIN format', ['status' => 400]);
        }

        $existing_id = $this->get_product_id_by_sku_any_status($vin);
        if ($existing_id > 0) {
            return $this->hide_product($existing_id);
        }

        $product_id = wp_insert_post([
            'post_title' => $vin,
            'post_name' => sanitize_title($vin),
            'post_status' => 'draft',
            'post_type' => 'product',
        ], true);

        if (is_wp_error($product_id)) {
            return $product_id;
        }

        $product_id = (int)$product_id;
        update_post_meta($product_id, '_sku', $vin);
        update_post_meta($product_id, '_mac_vin_hidden_placeholder', '1');
        wp_set_object_terms($product_id, 'simple', 'product_type', false);
        $this->clear_product_cache($vin);

        return [
            'success' => true,
            'message' => 'Vehicle placeholder created in draft',
            'product_id' => $product_id,
            'status' => 'draft',
            'hide_mode' => 'draft',
            'placeholder' => true,
            'url' => get_permalink($product_id),
        ];
    }

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
            $found_vin = strtoupper(preg_replace('/[\s-]+/', '', get_search_query()));
            if ($this->is_valid_vin($found_vin)) {
                do_action('mac_vin_fallback_search_result', 'existing', $found_vin);
            }
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] WooCommerce found products - exit');
            return;
        }

        // Пробуем интерпретировать строку поиска как VIN
        $raw_q = get_search_query();
        $q = strtoupper(preg_replace('/[\s-]+/', '', $raw_q));

        // Оптимизированная проверка VIN
        if (!$this->is_valid_vin($q)) {
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] Query "' . $q . '" не похож на VIN — оставляем "ничего не найдено".');
            return;
        }

        if (VIN_FS_DEBUG) error_log('[VIN Fallback] Valid VIN detected: ' . $q);

        // Проверяем существование товара на сайте
        $existing_product = $this->check_existing_product($q);

        if ($existing_product === false) {
            // A SKU exists but the product is not public (including draft
            // placeholders and draft hides). Do not treat it as an ordinary
            // existing vehicle in the search-log report.
            do_action('mac_vin_fallback_search_result', 'existing_hidden', $q);
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] Product exists but is draft - show "nothing found"');
            return;
        }

        if ($existing_product !== null) {
            if ($this->is_redirect_hidden_product((int)$existing_product)) {
                do_action('mac_vin_fallback_search_result', 'existing_hidden', $q);
                if (VIN_FS_DEBUG) error_log('[VIN Fallback] Product exists but is hidden with redirect - show "nothing found"');
                return;
            }

            do_action('mac_vin_fallback_search_result', 'existing', $q);

            if (VIN_FS_DEBUG) error_log('[VIN Fallback] Product exists and published: #' . $existing_product . ' - redirecting');
            wp_safe_redirect(get_permalink($existing_product), 302);
            exit;
        }

        // Товара нет на сайте - ищем в API провайдерах
        $providers = apply_filters('vin_fallback_providers', []);

        if (empty($providers)) {
            do_action('mac_vin_fallback_search_result', 'not_found', $q);
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] No active providers - show "nothing found"');
            return;
        }

        if (VIN_FS_DEBUG) error_log('[VIN Fallback] Starting API search with ' . count($providers) . ' providers');

        // Последовательно опрашиваем API провайдеры
        $creation_failed = false;
        foreach ($providers as $index => $provider) {
            $provider_class = get_class($provider);
            $provider_name = $provider_class;

            if (VIN_FS_DEBUG) error_log('[VIN Fallback] Trying provider ' . ($index + 1) . ': ' . $provider_name);

            try {
                $item = $provider->search($q);

                if ($item && is_array($item)) {
                    if (VIN_FS_DEBUG) error_log('[VIN Fallback] Provider ' . $provider_name . ' found vehicle');

                    // Создаем товар и редиректим (передаем класс провайдера)
                    $was_created = false;
                    $product_id = $this->create_or_get_product($item, $provider_class, $was_created);

                    if ($product_id) {
                        do_action('mac_vin_fallback_search_result', $was_created ? 'created' : 'existing', $q);
                        if (VIN_FS_DEBUG) error_log('[VIN Fallback] Product created: #' . $product_id . ' - redirecting');
                        wp_safe_redirect(get_permalink($product_id), 302);
                        exit;
                    } else {
                        $creation_failed = true;
                        if (VIN_FS_DEBUG) error_log('[VIN Fallback] Failed to create product from provider: ' . $provider_name);
                    }
                } else {
                    if (VIN_FS_DEBUG) error_log('[VIN Fallback] Provider ' . $provider_name . ' found nothing');
                }
            } catch (Exception $e) {
                if (VIN_FS_DEBUG) error_log('[VIN Fallback] Provider error (' . $provider_name . '): ' . $e->getMessage());
            }
        }

        do_action('mac_vin_fallback_search_result', $creation_failed ? 'creation_error' : 'not_found', $q);
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
            if ($this->is_redirect_hidden_product((int)$existing_product)) {
                return ['success' => false, 'message' => 'Vehicle exists but is hidden'];
            }

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
            $provider_started_at = microtime(true);
            $provider_name = get_class($provider);
            do_action('mac_vin_import_trace', ['stage' => 'provider_search', 'status' => 'started', 'provider' => $provider_name, 'message' => 'Provider search started']);
            try {
                $item = $provider->search($q);
                do_action('mac_vin_import_trace', ['stage' => 'provider_search', 'status' => $item && is_array($item) ? 'found' : 'not_found', 'provider' => $provider_name, 'duration_ms' => round((microtime(true) - $provider_started_at) * 1000), 'images_total' => is_array($item['images'] ?? null) ? count($item['images']) : null, 'message' => $item && is_array($item) ? 'Vehicle found' : 'Vehicle not found']);

                if ($item && is_array($item)) {
                    $provider_class = $provider_name;
                    $creation_started_at = microtime(true);
                    do_action('mac_vin_import_trace', ['stage' => 'product_create', 'status' => 'started', 'provider' => $provider_name, 'images_total' => is_array($item['images'] ?? null) ? count($item['images']) : 0, 'message' => 'Product creation started']);
                    $product_id = $this->create_or_get_product($item, $provider_class);
                    do_action('mac_vin_import_trace', ['stage' => 'product_create', 'status' => $product_id ? 'success' : 'error', 'provider' => $provider_name, 'product_id' => $product_id ?: null, 'duration_ms' => round((microtime(true) - $creation_started_at) * 1000), 'images_total' => is_array($item['images'] ?? null) ? count($item['images']) : 0, 'message' => $product_id ? 'Product created or found' : 'Product creation failed']);

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
                do_action('mac_vin_import_trace', ['stage' => 'provider_search', 'status' => 'error', 'provider' => $provider_name, 'duration_ms' => round((microtime(true) - $provider_started_at) * 1000), 'message' => $e->getMessage()]);
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
    protected function create_or_get_product(array $item, $provider_class = null, &$was_created = false): int
    {
        $was_created = false;
        $sku = sanitize_text_field($item['sku'] ?? '');
        if (!$sku) return 0;

        // Проверяем существование товара
        $existing_product = $this->check_existing_product($sku);

        if ($existing_product && $existing_product !== false) {
            if ($this->is_redirect_hidden_product((int)$existing_product)) {
                return 0;
            }

            return (int) $existing_product;
        }

        if ($existing_product === false) {
            return 0;
        }

        // Создаем новый товар с меткой провайдера
        $product_id = $this->create_new_product($item, $provider_class);
        $was_created = $product_id > 0;
        return $product_id;
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

        $registry = $this->get_provider_registry();
        $out = [
            'vin' => $vin,
            'site' => home_url('/'),
            'tested_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'php' => PHP_VERSION,
            'wordpress' => get_bloginfo('version'),
            'providers' => [],
        ];

        foreach ($this->get_provider_order() as $provider_id) {
            $config = $registry[$provider_id];
            $row = [
                'id' => $provider_id,
                'label' => $config['label'],
                'class' => $config['class'],
                'tag' => $config['tag'],
            ];

            $enabled_field = $config['enabled_field'] ?? ($provider_id . '_enabled');
            $provider = is_callable($config['factory'] ?? null) ? call_user_func($config['factory']) : null;
            if (empty($this->settings[$enabled_field])) {
                $out['providers'][] = $row + ['status' => 'disabled'];
                continue;
            }
            if (!$provider instanceof VIN_Provider_Interface) {
                $out['providers'][] = $row + ['status' => 'not_configured'];
                continue;
            }

            $started = microtime(true);
            VIN_Provider_Http_Trace::start();

            try {
                $item = $provider->search($vin);
                $generated_title = $item ? $this->build_product_title($item, $config['class']) : null;
                $row += [
                    'status' => $item ? 'found' : 'not_found',
                    'generated_title' => $generated_title,
                    'generated_slug' => $item ? $this->build_product_slug($item, $generated_title, $item['sku'] ?? $vin, $config['class']) : null,
                    'item' => $item,
                ];
            } catch (\Throwable $e) {
                $row += [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }

            $row['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
            $row['http'] = VIN_Provider_Http_Trace::stop();
            $out['providers'][] = $row;
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
