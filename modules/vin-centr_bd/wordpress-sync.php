<?php
/*
Module Name: Central Auto Sync
Description: Синхронизация авто с центральным сервисом
Version: 2.5
*/

if (!defined('ABSPATH')) exit;

// можно менять
define('CAS_SYNC_BATCH_SIZE', 50);   // размер пачки
define('CAS_SYNC_TIMEOUT', 120);     // timeout запроса на центральный

add_action('admin_menu', function () {
    if (defined('MAC_MASTER_ACTIVE') && MAC_MASTER_ACTIVE) {
        return;
    }

    add_options_page(
        'Central Auto Sync',
        'Auto Sync',
        'manage_options',
        'central-auto-sync',
        'cas_options_page'
    );
});

function cas_options_page()
{
    if (isset($_POST['save_settings'])) {
        check_admin_referer('cas_save_settings');

        update_option('cas_central_url', sanitize_text_field($_POST['central_url'] ?? ''));
        update_option('cas_api_key', sanitize_text_field($_POST['api_key'] ?? ''));
        update_option('cas_sync_key', sanitize_text_field($_POST['sync_key'] ?? ''));

        echo '<div class="notice notice-success"><p>Настройки сохранены!</p></div>';
    }

    $central_url = esc_attr(get_option('cas_central_url', ''));
    $api_key     = esc_attr(get_option('cas_api_key', ''));
    $sync_key    = esc_attr(get_option('cas_sync_key', ''));

    $nonce_step = wp_create_nonce('cas_sync_step');
?>
    <div class="wrap">
        <h1>Синхронизация с центром</h1>

        <form method="POST">
            <?php wp_nonce_field('cas_save_settings'); ?>

            <h2>Подключение к центральному сервису</h2>
            <table class="form-table">
                <tr>
                    <th><label>URL центрального сервиса:</label></th>
                    <td>
                        <input type="url" name="central_url" value="<?= $central_url ?>" class="regular-text"
                            placeholder="https://autosync.your-domain.com" required>
                        <p class="description">Адрес центрального сервиса</p>
                    </td>
                </tr>
                <tr>
                    <th><label>API Key:</label></th>
                    <td>
                        <input type="text" name="api_key" value="<?= $api_key ?>" class="regular-text" required>
                        <p class="description">Ключ для отправки данных на центральный сервис (webhook.php)</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Sync Key:</label></th>
                    <td>
                        <input type="text" name="sync_key" value="<?= $sync_key ?>" class="regular-text" required>
                        <p class="description">Ключ для получения команд от центрального сервиса (REST /vehicles и /import)</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="save_settings" class="button button-primary">
                    💾 Сохранить настройки
                </button>

                <button type="button" class="button button-secondary" id="casStartSyncBtn">
                    🔄 Запустить синхронизацию
                </button>
            </p>
        </form>

        <div id="casProgressBox" style="display:none; margin-top:14px; max-width:700px;">
            <div style="height:18px;background:#eee;border-radius:8px;overflow:hidden;">
                <div id="casBar" style="height:100%;width:0%;background:#2271b1;"></div>
            </div>
            <div id="casText" style="margin-top:8px;color:#444;"></div>
        </div>

        <script>
            (function() {
                const btn = document.getElementById('casStartSyncBtn');
                const box = document.getElementById('casProgressBox');
                const bar = document.getElementById('casBar');
                const txt = document.getElementById('casText');

                async function step(page) {
                    const resp = await jQuery.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'cas_sync_step',
                            nonce: '<?= esc_js($nonce_step) ?>',
                            page: page
                        }
                    });

                    if (!resp || !resp.success) {
                        const msg = resp?.data?.message || 'Unknown error';
                        throw new Error(msg);
                    }
                    return resp.data;
                }

                btn.addEventListener('click', async () => {
                    if (!confirm('Начать синхронизацию ВСЕХ авто пачками?\n\nБудут отправлены все товары (включая черновики и скрытые).')) return;

                    btn.disabled = true;
                    btn.textContent = '🔄 Синхронизация...';
                    box.style.display = 'block';
                    bar.style.width = '0%';
                    txt.textContent = 'Подготовка...';

                    try {
                        let page = 1;
                        let sentTotal = 0;
                        let total = null;

                        while (true) {
                            const data = await step(page);

                            total = data.total ?? total;
                            sentTotal += (data.sent ?? 0);

                            const percent = total ? Math.min(100, Math.round((sentTotal / total) * 100)) : 0;
                            bar.style.width = percent + '%';

                            txt.textContent =
                                `Страница ${data.page}/${data.total_pages} • ` +
                                `Отправлено: ${sentTotal} из ${total} • ` +
                                `Пачка: ${data.sent} (ошибок: ${data.errors})`;

                            if (data.done) break;

                            page = data.next_page;

                            // лёгкая пауза, чтобы не душить центральный
                            await new Promise(r => setTimeout(r, 400));
                        }

                        txt.textContent = `✅ Синхронизация завершена. Отправлено: ${sentTotal} из ${total}`;
                        bar.style.width = '100%';

                    } catch (e) {
                        alert('❌ Ошибка синхронизации: ' + (e?.message || e));
                        txt.textContent = '❌ Ошибка: ' + (e?.message || e);
                    } finally {
                        btn.disabled = false;
                        btn.textContent = '🔄 Запустить синхронизацию';
                    }
                });
            })();
        </script>
    </div>
<?php
}

/**
 * Атрибуты
 */
function cas_get_product_attributes($product_id)
{
    $attributes = [];
    $attribute_slugs = ['marka', 'model', 'car_year'];

    foreach ($attribute_slugs as $slug) {
        $taxonomy = 'pa_' . $slug;
        $terms = wp_get_post_terms($product_id, $taxonomy);

        if (!is_wp_error($terms) && !empty($terms)) {
            $attributes[$slug] = $terms[0]->name;
        } else {
            $attributes[$slug] = '';
        }
    }
    return $attributes;
}

/**
 * Источник = все метки товара (product_tag)
 */
function cas_get_product_sources_from_tags($product_id)
{
    $terms = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
    if (is_wp_error($terms) || empty($terms)) return [];
    return array_values(array_unique(array_filter(array_map('trim', array_map('strval', $terms)))));
}

/**
 * Дата изменения товара на доноре (GMT)
 */
function cas_get_product_modified_gmt($product_id)
{
    $gmt = get_post_field('post_modified_gmt', $product_id);
    return $gmt ? (string)$gmt : '';
}

/**
 * Получить страницу товаров (добавляем информацию об индексации ИЗ POSTMETA)
 */
function cas_get_products_page($page, $per_page)
{
    global $wpdb;

    $page = max(1, (int)$page);
    $per_page = max(1, (int)$per_page);
    $offset = ($page - 1) * $per_page;

    // total
    $total = (int)$wpdb->get_var("
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
        WHERE p.post_type = 'product'
          AND p.post_status IN ('publish', 'private', 'draft', 'pending')
          AND pm_sku.meta_value <> ''
          AND pm_sku.meta_value IS NOT NULL
    ");

    // ВАЖНО: Правильный запрос с LEFT JOIN для метаполей индексации
    $sql = $wpdb->prepare("
        SELECT 
            p.ID,
            p.post_title,
            p.post_status,
            pm_sku.meta_value as sku,
            pm_price.meta_value as price,
            pm_regular_price.meta_value as regular_price
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
        LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
        LEFT JOIN {$wpdb->postmeta} pm_regular_price ON p.ID = pm_regular_price.post_id AND pm_regular_price.meta_key = '_regular_price'
        WHERE p.post_type = 'product'
          AND p.post_status IN ('publish', 'private', 'draft', 'pending')
          AND pm_sku.meta_value <> ''
          AND pm_sku.meta_value IS NOT NULL
        ORDER BY p.ID DESC
        LIMIT %d OFFSET %d
    ", $per_page, $offset);

    $rows = $wpdb->get_results($sql);

    return [$total, $rows];
}


/**
 * Получить информацию об индексации товара
 */
function cas_get_index_info($product_id)
{
    $indexed_date = get_post_meta($product_id, '_gai_indexed', true);
    $last_action = get_post_meta($product_id, '_gai_last_action', true);

    return [
        'indexed' => !empty($indexed_date), // true/false
        'indexed_date' => $indexed_date,    // дата индексации или пусто
        'last_action' => $last_action       // 'indexed' или 'deleted' или пусто
    ];
}

/**
 * Получить количество фотографий в галерее товара
 */
function cas_get_product_gallery_count($product_id) {
    if (!function_exists('wc_get_product')) {
        return 0;
    }
    
    $product = wc_get_product($product_id);
    if (!$product) {
        return 0;
    }
    
    // Получаем только ID фотографий галереи (без главного фото)
    $gallery_ids = $product->get_gallery_image_ids();
    
    if (!is_array($gallery_ids)) {
        return 0;
    }
    
    return count($gallery_ids);
}
/**
 * AJAX STEP: отправляем одну пачку
 */
add_action('wp_ajax_cas_sync_step', function () {
    check_ajax_referer('cas_sync_step', 'nonce');

    $central_url = get_option('cas_central_url');
    $api_key = get_option('cas_api_key');

    if (empty($central_url) || empty($api_key)) {
        wp_send_json_error(['message' => 'Не заполнены cas_central_url / cas_api_key']);
    }

    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = (int)CAS_SYNC_BATCH_SIZE;

    [$total, $products] = cas_get_products_page($page, $per_page);

    if ($total <= 0) {
        wp_send_json_error(['message' => 'Не найдено товаров с заполненным SKU (VIN)']);
    }

    $vehicles = [];
    $errors = 0;

    foreach ($products as $product) {
        $vin = trim((string)$product->sku);
        if (!$vin) {
            $errors++;
            continue;
        }

        $attrs = cas_get_product_attributes($product->ID);

        $price = $product->price;
        if (empty($price) || (float)$price == 0) $price = $product->regular_price;

        $sources = cas_get_product_sources_from_tags($product->ID);
        $source_string = $sources ? implode('|', $sources) : '';

        // ВАЖНО: добавляем информацию об индексации
        $index_info = cas_get_index_info($product->ID);
        $gallery_count = cas_get_product_gallery_count($product->ID);
        $vehicles[] = [
            'vin' => $vin,
            'make' => $attrs['marka'] ?? '',
            'model' => $attrs['model'] ?? '',
            'year' => $attrs['car_year'] ?? '',
            'price' => $price !== null && $price !== '' ? (float)$price : null,
            'status' => $product->post_status == 'publish' ? 'published' : ($product->post_status == 'private' ? 'hidden' : 'draft'),
            'product_id' => (int)$product->ID,
            'product_url' => get_permalink($product->ID),
            'title' => (string)$product->post_title,

            'sources' => $sources,
            'source' => $source_string,

            'donor_modified_at' => cas_get_product_modified_gmt($product->ID),

            // НОВОЕ ПОЛЕ: информация об индексации в Google
            'gai_indexed' => $index_info['indexed'],           // true/false - индексирован ли товар
            'gai_indexed_date' => $index_info['indexed_date'], // дата индексации (если есть)
            'gai_last_action' => $index_info['last_action'],    // последнее действие (indexed/deleted)
            'gallery_count' => $gallery_count
        ];
    }

    $total_pages = (int)ceil($total / $per_page);

    // если на странице вообще пусто — заканчиваем
    if (empty($vehicles)) {
        wp_send_json_success([
            'page' => $page,
            'total' => $total,
            'total_pages' => $total_pages,
            'sent' => 0,
            'errors' => $errors,
            'done' => ($page >= $total_pages),
            'next_page' => $page + 1,
        ]);
    }

    $resp = wp_remote_post(rtrim($central_url, '/') . '/api/webhook.php?action=initial_sync', [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-API-Key' => $api_key,
        ],
        'body' => wp_json_encode([
            'action' => 'initial_sync',
            'chunk' => $page,
            'total_chunks' => $total_pages,
            'vehicles' => $vehicles,  // Теперь здесь есть информация об индексации
        ]),
        'timeout' => (int)CAS_SYNC_TIMEOUT,
    ]);

    if (is_wp_error($resp)) {
        wp_send_json_error(['message' => 'HTTP error: ' . $resp->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $decoded = json_decode($body, true);

    if ($code !== 200 || empty($decoded['success'])) {
        $msg = $decoded['error'] ?? $decoded['message'] ?? $body;
        wp_send_json_error(['message' => "Central ответил HTTP {$code}: {$msg}"]);
    }

    wp_send_json_success([
        'page' => $page,
        'total' => $total,
        'total_pages' => $total_pages,
        'sent' => count($vehicles),
        'errors' => $errors,
        'done' => ($page >= $total_pages),
        'next_page' => $page + 1,
    ]);
});

/**
 * REST endpoints (как было)
 */
add_action('rest_api_init', function () {
    register_rest_route('auto-sync/v1', '/vehicles', [
        'methods' => 'GET',
        'callback' => 'cas_api_get_all_vehicles',
        'permission_callback' => function ($request) {
            return $request->get_header('X-API-Key') === get_option('cas_sync_key');
        }
    ]);

    register_rest_route('auto-sync/v1', '/import', [
        'methods' => 'POST',
        'callback' => 'cas_api_import_vehicle',
        'permission_callback' => function ($request) {
            return $request->get_header('X-API-Key') === get_option('cas_sync_key');
        }
    ]);

    register_rest_route('auto-sync/v1', '/hide', [
        'methods' => 'POST',
        'callback' => 'cas_api_hide_vehicle',
        'permission_callback' => function ($request) {
            return $request->get_header('X-API-Key') === get_option('cas_sync_key');
        }
    ]);
});

function cas_api_get_all_vehicles($request)
{
    // оставляем как было — если надо, тоже переведём на пагинацию
    return ['success' => true, 'message' => 'Use admin sync for full export'];
}

function cas_api_import_vehicle($request)
{
    if (!class_exists('WooCommerce')) {
        return ['success' => false, 'message' => 'WooCommerce not available'];
    }

    $data = $request->get_json_params();
    $vin = $data['vin'] ?? '';

    if (!$vin) {
        return new WP_Error('missing_vin', 'VIN is required', ['status' => 400]);
    }

    $vin_norm = strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', trim((string)$vin)));

    $existing_id = wc_get_product_id_by_sku($vin_norm);
    if ($existing_id) {
        $product = wc_get_product($existing_id);

        $sources = cas_get_product_sources_from_tags($existing_id);
        $source_string = $sources ? implode('|', $sources) : '';

        return [
            'success' => false,
            'already_exists' => true,
            'message' => 'Vehicle already exists',
            'product_id' => (int)$existing_id,
            'status' => $product ? $product->get_status() : 'publish',
            'url' => get_permalink($existing_id),
            'sources' => $sources,
            'source' => $source_string,
            'donor_modified_at' => cas_get_product_modified_gmt($existing_id),
        ];
    }

    if (class_exists('VINFallbackSearch')) {
        $vinFallback = $GLOBALS['mac_vin_fallback'] ?? null;

        if ($vinFallback && method_exists($vinFallback, 'import_by_vin')) {
            $result = $vinFallback->import_by_vin($vin_norm);

            if (!is_array($result)) {
                return ['success' => false, 'message' => 'Invalid import result'];
            }

            if (!empty($result['product_id'])) {
                $pid = (int)$result['product_id'];
                $sources = cas_get_product_sources_from_tags($pid);
                $source_string = $sources ? implode('|', $sources) : '';

                $result['sources'] = $sources;
                $result['source'] = $source_string;
                $result['donor_modified_at'] = cas_get_product_modified_gmt($pid);
            }

            return $result;
        }

        return ['success' => false, 'message' => 'VINFallbackSearch::import_by_vin not found'];
    }

    return ['success' => false, 'message' => 'VINFallbackSearch plugin not loaded'];
}

function cas_find_product_by_vin($vin)
{
    $vin_norm = strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', trim((string)$vin)));
    if ($vin_norm === '') {
        return 0;
    }

    return (int)wc_get_product_id_by_sku($vin_norm);
}

function cas_move_product_to_draft($product_id)
{
    $product_id = (int)$product_id;
    if ($product_id <= 0) {
        return new WP_Error('invalid_product', 'Invalid product ID', ['status' => 400]);
    }

    $current_status = (string)get_post_status($product_id);
    if ($current_status === '') {
        return new WP_Error('product_not_found', 'Product not found', ['status' => 404]);
    }

    if ($current_status === 'draft') {
        return [
            'success' => true,
            'message' => 'Vehicle already in draft',
            'product_id' => $product_id,
            'status' => 'draft',
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
        'url' => get_permalink($product_id),
        'already_in_draft' => false,
    ];
}

function cas_api_hide_vehicle($request)
{
    if (!class_exists('WooCommerce')) {
        return ['success' => false, 'message' => 'WooCommerce not available'];
    }

    $data = $request->get_json_params();
    $vin = $data['vin'] ?? '';

    if (!$vin) {
        return new WP_Error('missing_vin', 'VIN is required', ['status' => 400]);
    }

    $vin_norm = strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', trim((string)$vin)));
    if ($vin_norm === '') {
        return new WP_Error('invalid_vin', 'VIN is invalid', ['status' => 400]);
    }

    $existing_id = cas_find_product_by_vin($vin_norm);
    if ($existing_id) {
        $hide_result = cas_move_product_to_draft($existing_id);
        if (is_wp_error($hide_result)) {
            return [
                'success' => false,
                'message' => $hide_result->get_error_message(),
            ];
        }

        return $hide_result;
    }

    if (!class_exists('VINFallbackSearch')) {
        return ['success' => false, 'message' => 'VINFallbackSearch plugin not loaded'];
    }

    $vinFallback = $GLOBALS['mac_vin_fallback'] ?? null;

    if (!$vinFallback || !method_exists($vinFallback, 'import_by_vin')) {
        return ['success' => false, 'message' => 'VINFallbackSearch::import_by_vin not found'];
    }

    $import_result = $vinFallback->import_by_vin($vin_norm);
    if (!is_array($import_result)) {
        return ['success' => false, 'message' => 'Invalid import result'];
    }

    if (empty($import_result['product_id'])) {
        return [
            'success' => false,
            'message' => $import_result['message'] ?? 'Import failed',
        ];
    }

    $product_id = (int)$import_result['product_id'];
    $hide_result = cas_move_product_to_draft($product_id);
    if (is_wp_error($hide_result)) {
        return [
            'success' => false,
            'message' => $hide_result->get_error_message(),
            'product_id' => $product_id,
        ];
    }

    $hide_result['message'] = 'Vehicle imported and moved to draft';
    $hide_result['imported'] = true;

    return $hide_result;
}
