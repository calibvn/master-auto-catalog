<?php
declare(strict_types=1);

// Small, retryable site-to-center queue. WP-Cron sends at most ten changes per run.
function cas_vehicle_sync_payload(int $productId): ?array {
    $post = get_post($productId);
    $vin = strtoupper((string) get_post_meta($productId, '_sku', true));
    if (!$post || $post->post_type !== 'product' || !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) return null;
    if ($post->post_status === 'trash') return ['vin' => $vin, 'status' => 'deleted', 'product_id' => $productId];
    $attrs = cas_get_product_attributes($productId);
    $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
    $price = $product ? $product->get_price() : null;
    $sources = cas_get_product_sources_from_tags($productId);
    $index = cas_get_index_info($productId);
    return [
        'vin' => $vin, 'status' => cas_async_product_site_status($productId),
        'product_id' => $productId, 'product_url' => get_permalink($productId),
        'make' => $attrs['marka'] ?? '', 'model' => $attrs['model'] ?? '',
        'year' => $attrs['car_year'] ?? '', 'price' => $price !== '' ? $price : null,
        'sources' => $sources, 'source' => implode('|', $sources),
        'donor_modified_at' => cas_get_product_modified_gmt($productId),
        'gai_indexed' => $index['indexed'], 'gai_indexed_date' => $index['indexed_date'],
        'gai_last_action' => $index['last_action'], 'gallery_count' => cas_get_product_gallery_count($productId),
    ];
}

function cas_vehicle_sync_request(string $action, array $payload): bool {
    $url = trim((string) get_option('cas_central_url', ''));
    $key = trim((string) get_option('cas_api_key', ''));
    if ($url === '' || $key === '') return false;
    $response = wp_remote_post(rtrim($url, '/') . '/api/webhook.php?action=' . rawurlencode($action), [
        'timeout' => 12, 'redirection' => 0, 'sslverify' => true,
        'headers' => ['Content-Type' => 'application/json', 'X-API-Key' => $key],
        'body' => wp_json_encode(['action' => $action] + $payload),
    ]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return false;
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($body) && !empty($body['success']);
}

function cas_vehicle_sync_schedule(int $delay = 30): void {
    if (!wp_next_scheduled('cas_vehicle_sync_worker')) wp_schedule_single_event(time() + $delay, 'cas_vehicle_sync_worker');
}

function cas_vehicle_sync_enqueue(int $productId, ?array $deleted = null): void {
    $payload = $deleted ?: cas_vehicle_sync_payload($productId);
    if (!$payload || empty($payload['vin'])) return;
    $queue = get_option('cas_vehicle_sync_queue', []);
    if (!is_array($queue)) $queue = [];
    $queue[(string) $productId] = [
        'vin' => $payload['vin'], 'deleted' => $deleted, 'attempt' => 0,
        'due' => time() + 20, 'stamp' => wp_generate_uuid4(),
    ];
    update_option('cas_vehicle_sync_queue', $queue, false);
    cas_vehicle_sync_schedule();
}

add_action('save_post_product', static function ($postId, $post, $update): void {
    if (wp_is_post_revision($postId)) return;
    cas_vehicle_sync_enqueue((int) $postId);
}, 100, 3);
add_action('woocommerce_update_product', static function ($productId): void {
    cas_vehicle_sync_enqueue((int) $productId);
}, 100, 1);
add_action('before_delete_post', static function ($postId): void {
    if (get_post_type($postId) !== 'product') return;
    $payload = cas_vehicle_sync_payload((int) $postId);
    if ($payload) cas_vehicle_sync_enqueue((int) $postId, ['vin' => $payload['vin'], 'status' => 'deleted', 'product_id' => (int) $postId]);
}, 10, 1);

add_action('cas_vehicle_sync_worker', static function (): void {
    if (get_transient('cas_vehicle_sync_busy')) return;
    set_transient('cas_vehicle_sync_busy', 1, 60);
    try {
        $queue = get_option('cas_vehicle_sync_queue', []);
        if (!is_array($queue)) return;
        $processed = 0;
        foreach ($queue as $productId => $item) {
            if ($processed >= 10) break;
            if ((int) ($item['due'] ?? 0) > time()) continue;
            $processed++;
            $payload = is_array($item['deleted'] ?? null) ? $item['deleted'] : cas_vehicle_sync_payload((int) $productId);
            if (!$payload && !empty($item['vin'])) $payload = ['vin' => $item['vin'], 'status' => 'deleted', 'product_id' => (int) $productId];
            $sent = $payload && cas_vehicle_sync_request('vehicle_updated', $payload);
            $fresh = get_option('cas_vehicle_sync_queue', []);
            if (!is_array($fresh) || !isset($fresh[$productId]) || ($fresh[$productId]['stamp'] ?? '') !== ($item['stamp'] ?? '')) continue;
            if ($sent || !$payload) unset($fresh[$productId]);
            else {
                $attempt = (int) ($item['attempt'] ?? 0) + 1;
                $fresh[$productId]['attempt'] = $attempt;
                $fresh[$productId]['due'] = time() + min(60 * (2 ** min($attempt, 5)), 1800);
            }
            update_option('cas_vehicle_sync_queue', $fresh, false);
            usleep(200000);
        }
    } finally {
        delete_transient('cas_vehicle_sync_busy');
        if (get_option('cas_vehicle_sync_queue', [])) cas_vehicle_sync_schedule(60);
    }
});

// Daily keyset scan. The center only marks missing rows deleted after every page succeeds.
add_action('init', static function (): void {
    if (!wp_next_scheduled('cas_vehicle_sync_daily')) wp_schedule_event(time() + 3600, 'daily', 'cas_vehicle_sync_daily');
    if (get_option('cas_vehicle_sync_queue', [])) cas_vehicle_sync_schedule(60);
    if (get_option('cas_vehicle_reconcile_state', [] ) && !wp_next_scheduled('cas_vehicle_reconcile_step')) wp_schedule_single_event(time() + 60, 'cas_vehicle_reconcile_step');
});
add_action('cas_vehicle_sync_daily', static function (): void {
    if (get_option('cas_vehicle_reconcile_state', [])) return;
    $state = ['run_id' => 'rec_' . gmdate('YmdHis') . '_' . wp_generate_password(8, false, false), 'cursor' => PHP_INT_MAX, 'started' => false];
    update_option('cas_vehicle_reconcile_state', $state, false);
    wp_schedule_single_event(time() + 5, 'cas_vehicle_reconcile_step');
});
add_action('cas_vehicle_reconcile_step', static function (): void {
    if (get_transient('cas_vehicle_reconcile_busy')) return;
    set_transient('cas_vehicle_reconcile_busy', 1, 90);
    try {
        $state = get_option('cas_vehicle_reconcile_state', []);
        if (!is_array($state) || empty($state['run_id'])) return;
        if (empty($state['started'])) {
            if (!cas_vehicle_sync_request('reconcile_start', ['run_id' => $state['run_id']])) return;
            $state['started'] = true;
            update_option('cas_vehicle_reconcile_state', $state, false);
        }
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} sku ON sku.post_id=p.ID AND sku.meta_key='_sku' WHERE p.post_type='product' AND p.post_status IN ('publish','private','draft','pending') AND p.ID < %d AND sku.meta_value <> '' ORDER BY p.ID DESC LIMIT 50",
            (int) $state['cursor']
        ));
        if ($wpdb->last_error) return;
        $vehicles = [];
        foreach ($ids as $id) {
            $payload = cas_vehicle_sync_payload((int) $id);
            if ($payload && $payload['status'] !== 'deleted') $vehicles[] = $payload;
        }
        if ($ids) {
            if (!$vehicles) return;
            if ($vehicles && !cas_vehicle_sync_request('initial_sync', ['vehicles' => $vehicles, 'chunk' => 1, 'total_chunks' => 1, 'run_id' => $state['run_id']])) return;
            $state['cursor'] = min(array_map('intval', $ids));
            update_option('cas_vehicle_reconcile_state', $state, false);
        } else {
            if (cas_vehicle_sync_request('reconcile_complete', ['run_id' => $state['run_id']])) delete_option('cas_vehicle_reconcile_state');
        }
    } finally {
        delete_transient('cas_vehicle_reconcile_busy');
        if (get_option('cas_vehicle_reconcile_state', [])) wp_schedule_single_event(time() + 60, 'cas_vehicle_reconcile_step');
    }
});
