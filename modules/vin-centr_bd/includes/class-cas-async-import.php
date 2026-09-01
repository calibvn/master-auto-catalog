<?php
defined('ABSPATH') || exit;

const CAS_ASYNC_DB_VERSION = '1.0';
const CAS_ASYNC_HOOK = 'cas_process_async_import';
const CAS_WORKER_HOOK = 'cas_async_import_worker';
const CAS_CALLBACK_HOOK = 'cas_send_async_import_callback';
const CAS_ASYNC_MAX_CONCURRENT_WORKERS = 10;

function cas_async_table(): string { global $wpdb; return $wpdb->prefix . 'cas_import_jobs'; }

function cas_async_ensure_table(): void {
    if (get_option('cas_async_db_version') === CAS_ASYNC_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = cas_async_table(); $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        job_id VARCHAR(64) NOT NULL,
        vin VARCHAR(17) NOT NULL,
        batch_id VARCHAR(64) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'queued',
        stage VARCHAR(32) NOT NULL DEFAULT 'queued',
        product_id BIGINT UNSIGNED NULL,
        product_url TEXT NULL,
        images_total INT UNSIGNED NULL,
        images_loaded INT UNSIGNED NULL,
        message TEXT NULL,
        result_json LONGTEXT NULL,
        callback_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        callback_error TEXT NULL,
        created_at DATETIME NOT NULL,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY job_id (job_id),
        KEY vin_status (vin, status),
        KEY batch_id (batch_id)
    ) {$charset};");
    update_option('cas_async_db_version', CAS_ASYNC_DB_VERSION, false);
}
add_action('init', 'cas_async_ensure_table', 5);

function cas_async_update(string $jobId, array $fields): void {
    global $wpdb; $fields['updated_at'] = current_time('mysql');
    $wpdb->update(cas_async_table(), $fields, ['job_id' => $jobId]);
}
function cas_async_get(string $jobId): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cas_async_table() . ' WHERE job_id = %s LIMIT 1', $jobId), ARRAY_A);
    return is_array($row) ? $row : null;
}

function cas_async_schedule(string $hook, string $jobId, int $delay = 0): void {
    if ($delay > 0 && function_exists('as_schedule_single_action')) {
        as_schedule_single_action(time() + $delay, $hook, ['job_id' => $jobId], 'master-auto-catalog', true);
        return;
    }
    if ($delay === 0 && function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action($hook, ['job_id' => $jobId], 'master-auto-catalog', true);
        return;
    }
    if (!wp_next_scheduled($hook, [$jobId])) wp_schedule_single_event(time() + $delay, $hook, [$jobId]);
    if (function_exists('spawn_cron')) spawn_cron(time());
}

/**
 * Queue a single site-wide worker. Import jobs must never be scheduled one
 * workers per VIN: jobs for this WordPress site are claimed atomically by
 * cas_async_kick(), up to the configured concurrency limit.
 */
function cas_async_worker_limit(): int {
    return max(1, min(10, (int) apply_filters('cas_async_worker_limit', CAS_ASYNC_MAX_CONCURRENT_WORKERS)));
}

function cas_async_schedule_worker(int $delay = 1): void {
    $delay = max(1, $delay);

    // A single scheduled starter fills free slots. Import work itself is
    // claimed atomically below, so no more than the configured worker limit
    // can run even when several starter requests overlap.
    global $wpdb;
    $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . cas_async_table() . " WHERE status IN ('claimed','running')");
    if ($active >= cas_async_worker_limit()) {
        return;
    }

    if (function_exists('as_schedule_single_action')) {
        if (function_exists('as_next_scheduled_action') && as_next_scheduled_action(CAS_WORKER_HOOK, [], 'master-auto-catalog') !== false) {
            return;
        }
        as_schedule_single_action(time() + $delay, CAS_WORKER_HOOK, [], 'master-auto-catalog', true);
        return;
    }
    if (!wp_next_scheduled(CAS_WORKER_HOOK)) {
        wp_schedule_single_event(time() + $delay, CAS_WORKER_HOOK);
    }
    if (function_exists('spawn_cron')) spawn_cron(time());
}

function cas_async_run_worker(): void {
    cas_async_kick();
}
add_action(CAS_WORKER_HOOK, 'cas_async_run_worker');

function cas_async_spawn_workers(int $maximum = 3): void {
    $key = trim((string) get_option('cas_sync_key', ''));
    if ($key === '' || $maximum < 1) return;
    global $wpdb;
    $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . cas_async_table() . " WHERE status IN ('claimed','running')");
    $count = min($maximum, max(0, cas_async_worker_limit() - $active));
    if ($count < 1) return;
    $url = rest_url('auto-sync/v1/import-kick');
    for ($i = 0; $i < $count; $i++) {
        wp_remote_post($url, [
            'timeout' => 1,
            'blocking' => false,
            'headers' => ['X-API-Key' => $key, 'X-CAS-Worker-Spawn' => '1'],
        ]);
    }
}

/**
 * Recover a queue left behind by an interrupted old worker. The check is
 * throttled, so it does not add a query to every frontend request.
 */
function cas_async_resume_pending_queue(): void {
    if (get_transient('cas_async_queue_resume_check')) {
        return;
    }
    set_transient('cas_async_queue_resume_check', 1, 30);

    global $wpdb;
    $staleBefore = date('Y-m-d H:i:s', current_time('timestamp') - 30 * MINUTE_IN_SECONDS);
    $wpdb->query($wpdb->prepare(
        "UPDATE " . cas_async_table() . " SET status='queued', stage='queued', message='Зависшее задание возвращено в очередь', updated_at=%s WHERE status IN ('claimed','running') AND updated_at < %s",
        current_time('mysql'),
        $staleBefore
    ));
    $hasQueued = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . cas_async_table() . " WHERE status IN ('queued','retry') LIMIT 1");
    if ($hasQueued > 0) {
        cas_async_schedule_worker();
    }
}
add_action('init', 'cas_async_resume_pending_queue', 20);

function cas_async_register_routes(): void {
    $permission = static function (WP_REST_Request $request): bool {
        $configured = trim((string)get_option('cas_sync_key', ''));
        $provided = trim((string)$request->get_header('X-API-Key'));
        return $configured !== '' && $provided !== '' && hash_equals($configured, $provided);
    };
    register_rest_route('auto-sync/v1', '/import-async', ['methods' => 'POST', 'callback' => 'cas_async_accept', 'permission_callback' => $permission]);
    register_rest_route('auto-sync/v1', '/import-status/(?P<job_id>[a-zA-Z0-9_-]{1,64})', ['methods' => 'GET', 'callback' => 'cas_async_status', 'permission_callback' => $permission]);
    register_rest_route('auto-sync/v1', '/import-kick', ['methods' => 'POST', 'callback' => 'cas_async_kick', 'permission_callback' => $permission]);
}
add_action('rest_api_init', 'cas_async_register_routes');

function cas_async_public_job(array $row): array {
    return [
        'job_id' => $row['job_id'], 'vin' => $row['vin'], 'batch_id' => $row['batch_id'],
        'status' => $row['status'], 'stage' => $row['stage'],
        'product_id' => $row['product_id'] ? (int)$row['product_id'] : null,
        'product_url' => $row['product_url'] ?: null,
        'images_total' => $row['images_total'] !== null ? (int)$row['images_total'] : null,
        'images_loaded' => $row['images_loaded'] !== null ? (int)$row['images_loaded'] : null,
        'message' => $row['message'] ?: '', 'updated_at' => $row['updated_at'],
    ];
}

function cas_async_accept(WP_REST_Request $request): WP_REST_Response {
    cas_async_ensure_table(); global $wpdb;
    $data = $request->get_json_params(); $data = is_array($data) ? $data : [];
    $vin = strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', trim((string)($data['vin'] ?? ''))));
    $jobId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($data['job_id'] ?? ''));
    $batchId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($data['batch_id'] ?? ''));
    if (strlen($vin) !== 17 || $jobId === '') return new WP_REST_Response(['success' => false, 'message' => 'Некорректный VIN или идентификатор задания'], 400);
    $existing = cas_async_get($jobId);
    if ($existing) {
        if (in_array($existing['status'], ['queued', 'retry'], true)) cas_async_schedule_worker();
        return new WP_REST_Response(['success' => true, 'accepted' => true, 'duplicate_job' => true, 'job' => cas_async_public_job($existing)], 202);
    }
    $now = current_time('mysql');
    $ok = $wpdb->insert(cas_async_table(), ['job_id' => substr($jobId,0,64), 'vin' => $vin, 'batch_id' => substr($batchId,0,64), 'status' => 'queued', 'stage' => 'queued', 'message' => 'Задание поставлено в очередь', 'created_at' => $now, 'updated_at' => $now]);
    if (!$ok) return new WP_REST_Response(['success' => false, 'message' => 'Не удалось сохранить задание импорта'], 500);
    cas_async_schedule_worker();
    return new WP_REST_Response(['success' => true, 'accepted' => true, 'job_id' => $jobId, 'vin' => $vin, 'status' => 'queued', 'stage' => 'queued'], 202);
}

function cas_async_status(WP_REST_Request $request): WP_REST_Response {
    $row = cas_async_get((string)$request['job_id']);
    return $row ? new WP_REST_Response(['success' => true, 'job' => cas_async_public_job($row)], 200) : new WP_REST_Response(['success' => false, 'message' => 'Задание не найдено'], 404);
}

function cas_async_kick(): WP_REST_Response {
    cas_async_ensure_table();
    global $wpdb;
    ignore_user_abort(true);

    $table = cas_async_table();
    $staleBefore = date('Y-m-d H:i:s', current_time('timestamp') - 30 * MINUTE_IN_SECONDS);
    $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='queued', stage='queued', message='Зависшее задание возвращено в очередь', updated_at=%s WHERE status IN ('claimed','running') AND updated_at < %s", current_time('mysql'), $staleBefore));

    $lockName = 'cas_async_kick_' . md5(cas_async_table());
    $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $lockName));
    if ($locked !== 1) return new WP_REST_Response(['success' => true, 'started' => false, 'reason' => 'claim_lock_busy'], 202);

    $jobId = '';
    try {
        $active = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('claimed','running')");
        if ($active >= cas_async_worker_limit()) return new WP_REST_Response(['success' => true, 'started' => false, 'reason' => 'worker_limit'], 202);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $candidate = (string)$wpdb->get_var("SELECT job_id FROM {$table} WHERE status IN ('queued','retry') ORDER BY id ASC LIMIT 1");
            if ($candidate === '') break;
            $claimed = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='claimed', stage='queued', message='Задание взято в обработку', updated_at=%s WHERE job_id=%s AND status IN ('queued','retry')", current_time('mysql'), $candidate));
            if ($claimed === 1) { $jobId = $candidate; break; }
        }
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
    }
    if ($jobId === '') return new WP_REST_Response(['success' => true, 'started' => false, 'reason' => 'queue_empty_or_claim_race'], 200);

    if (empty($_SERVER['HTTP_X_CAS_WORKER_SPAWN'])) {
        cas_async_spawn_workers(cas_async_worker_limit() - 1);
    }

    $GLOBALS['cas_async_kick_job_id'] = $jobId;
    cas_process_async_import($jobId);
    unset($GLOBALS['cas_async_kick_job_id']);
    return new WP_REST_Response(['success' => true, 'started' => true, 'job_id' => $jobId], 200);
}

function cas_async_trace_job(array $event): void {
    $jobId = (string)($GLOBALS['cas_async_current_job_id'] ?? ''); if ($jobId === '') return;
    $fields = [];
    if (!empty($event['stage'])) $fields['stage'] = sanitize_key((string)$event['stage']);
    if (isset($event['images_total'])) $fields['images_total'] = (int)$event['images_total'];
    if (isset($event['images_loaded'])) $fields['images_loaded'] = (int)$event['images_loaded'];
    if (!empty($event['message'])) $fields['message'] = sanitize_textarea_field((string)$event['message']);
    if ($fields) {
        $fields['status'] = 'running';
        cas_async_update($jobId, $fields);
        cas_async_send_progress($jobId);
    }
}
add_action('mac_vin_import_trace', 'cas_async_trace_job', 5);

function cas_async_send_progress(string $jobId): void {
    $row = cas_async_get($jobId); if (!$row) return;
    $url = rtrim((string)get_option('cas_central_url', ''), '/') . '/api/import-callback.php';
    $key = trim((string)get_option('cas_api_key', ''));
    if ($url === '/api/import-callback.php' || $key === '') return;
    wp_remote_post($url, ['timeout' => 1, 'blocking' => false, 'headers' => ['Content-Type' => 'application/json', 'X-API-Key' => $key], 'body' => wp_json_encode(cas_async_public_job($row))]);
}

function cas_process_async_import(string $jobId): void {
    $row = cas_async_get($jobId);
    $kickClaim = $row && $row['status'] === 'claimed' && (string)($GLOBALS['cas_async_kick_job_id'] ?? '') === $jobId;
    // A job can run only after the atomic claim in cas_async_kick(). Legacy
    // per-job scheduled actions are deliberately ignored to prevent parallel
    // imports on the same site.
    if (!$row || !$kickClaim) return;
    cas_async_update($jobId, ['status' => 'running', 'stage' => 'searching', 'started_at' => current_time('mysql'), 'message' => 'Импорт начат']);
    $GLOBALS['cas_async_current_job_id'] = $jobId;
    try {
        $request = new WP_REST_Request('POST', '/auto-sync/v1/import');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['vin' => $row['vin']]));
        $result = cas_api_import_vehicle($request);
        if (is_wp_error($result)) $result = ['success' => false, 'message' => $result->get_error_message()];
        elseif ($result instanceof WP_REST_Response) $result = $result->get_data();
        $result = is_array($result) ? $result : ['success' => false, 'message' => 'Некорректный результат импорта'];
        $already = !empty($result['already_exists']);
        $message = trim((string)($result['message'] ?? ''));
        $notFound = preg_match('/(?:no providers found vehicle|vehicle not found|not found)/i', $message) === 1;
        $status = $already ? 'already_exists' : (!empty($result['success']) ? 'completed' : ($notFound ? 'not_found' : 'failed'));
        $pid = !empty($result['product_id']) ? (int)$result['product_id'] : null;
        $progress = cas_async_get($jobId) ?: [];
        $actualImages = $pid ? 1 + cas_get_product_gallery_count($pid) : null;
        $imagesTotal = $progress['images_total'] !== null ? (int)$progress['images_total'] : $actualImages;
        $imagesLoaded = $progress['images_loaded'] !== null ? (int)$progress['images_loaded'] : $actualImages;
        if ($status === 'completed' && $imagesTotal !== null && $imagesLoaded !== null && $imagesLoaded < $imagesTotal) {
            $status = 'completed_with_warnings';
        }
        cas_async_update($jobId, ['status' => $status, 'stage' => 'completed', 'product_id' => $pid, 'product_url' => (string)($result['url'] ?? ''), 'images_total' => $imagesTotal, 'images_loaded' => $imagesLoaded, 'message' => $message, 'result_json' => wp_json_encode($result), 'completed_at' => current_time('mysql')]);
    } catch (Throwable $e) {
        cas_async_update($jobId, ['status' => 'failed', 'stage' => 'completed', 'message' => $e->getMessage(), 'completed_at' => current_time('mysql')]);
    }
    unset($GLOBALS['cas_async_current_job_id']);
    cas_send_async_import_callback($jobId);
    cas_async_schedule_worker(2);
}
add_action(CAS_ASYNC_HOOK, 'cas_process_async_import');

function cas_send_async_import_callback(string $jobId): void {
    $row = cas_async_get($jobId); if (!$row) return;
    $url = rtrim((string)get_option('cas_central_url', ''), '/') . '/api/import-callback.php';
    $key = trim((string)get_option('cas_api_key', '')); $attempt = (int)$row['callback_attempts'] + 1;
    $response = wp_remote_post($url, ['timeout' => 15, 'headers' => ['Content-Type' => 'application/json', 'X-API-Key' => $key], 'body' => wp_json_encode(cas_async_public_job($row))]);
    $error = is_wp_error($response) ? $response->get_error_message() : ((int)wp_remote_retrieve_response_code($response) === 200 ? '' : 'HTTP ' . (int)wp_remote_retrieve_response_code($response));
    cas_async_update($jobId, ['callback_attempts' => $attempt, 'callback_error' => $error]);
    if ($error !== '' && $attempt < 5) cas_async_schedule(CAS_CALLBACK_HOOK, $jobId, min(900, 60 * (2 ** ($attempt - 1))));
}
add_action(CAS_CALLBACK_HOOK, 'cas_send_async_import_callback');
