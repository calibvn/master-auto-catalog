<?php

if (!defined('ABSPATH')) exit;

class MAC_VIN_Remove_Sync
{
    private const DEFAULT_ENDPOINT = 'https://report-vin.com/api/internal-ingest.php';
    private const ALL_VIN_STORY_ENDPOINT = 'https://allvinstory.com/api/internal-ingest.php';
    private const GET_VIN_ENDPOINT = 'https://get-vin.com/api/internal-ingest.php';
    private const DEFAULT_KEY = 'Fcrfhjgnjv123';
    private static $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;
        add_action('vin_fallback_vehicle_imported', [__CLASS__, 'send_vehicle'], 10, 3);
    }

    public static function send_vehicle(int $product_id, array $item, $provider_class = null): void
    {
        if (defined('VIN_REMOVE_SYNC_ENABLED') && VIN_REMOVE_SYNC_ENABLED === false) {
            return;
        }

        $vin = self::normalize_vin((string)($item['sku'] ?? ''));
        if ($vin === '' || strlen($vin) !== 17) {
            return;
        }

        $targets = self::sync_targets();
        if (empty($targets)) {
            return;
        }

        $payload = [
            'source_app' => 'vin-fallback-search',
            'mode' => 'vehicle_data',
            'channel' => 'wp-master-hidden',
            'vin' => $vin,
            'vehicle_id' => $product_id,
            'triggered_at' => gmdate('Y-m-d H:i:s'),
            'vehicle_data' => self::build_vehicle_data($product_id, $item, (string)$provider_class, $vin),
        ];

        $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($targets as $target) {
            $response = wp_remote_post($target['endpoint'], [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Ingest-Key' => $target['key'],
                ],
                'body' => $body,
                'timeout' => 3,
                'blocking' => false,
            ]);

            if (is_wp_error($response) && defined('VIN_FS_DEBUG') && VIN_FS_DEBUG) {
                error_log('[VIN Remove Sync] Request error for ' . $target['endpoint'] . ': ' . $response->get_error_message());
            }
        }
    }

    private static function sync_targets(): array
    {
        if (defined('VIN_REMOVE_SYNC_TARGETS') && is_array(VIN_REMOVE_SYNC_TARGETS)) {
            return self::normalize_targets(VIN_REMOVE_SYNC_TARGETS);
        }

        $key = defined('VIN_REMOVE_SYNC_KEY') ? (string)VIN_REMOVE_SYNC_KEY : self::DEFAULT_KEY;
        $primary_endpoint = defined('VIN_REMOVE_SYNC_ENDPOINT') ? (string)VIN_REMOVE_SYNC_ENDPOINT : self::DEFAULT_ENDPOINT;

        return self::normalize_targets([
            [
                'endpoint' => $primary_endpoint,
                'key' => $key,
            ],
            [
                'endpoint' => self::ALL_VIN_STORY_ENDPOINT,
                'key' => $key,
            ],
            [
                'endpoint' => self::GET_VIN_ENDPOINT,
                'key' => $key,
            ],
        ]);
    }

    private static function normalize_targets(array $targets): array
    {
        $normalized = [];
        $seen = [];

        foreach ($targets as $target) {
            if (is_string($target)) {
                $endpoint = trim($target);
                $key = defined('VIN_REMOVE_SYNC_KEY') ? (string)VIN_REMOVE_SYNC_KEY : self::DEFAULT_KEY;
            } elseif (is_array($target)) {
                $endpoint = trim((string)($target['endpoint'] ?? ''));
                $key = trim((string)($target['key'] ?? (defined('VIN_REMOVE_SYNC_KEY') ? VIN_REMOVE_SYNC_KEY : self::DEFAULT_KEY)));
            } else {
                continue;
            }

            if ($endpoint === '' || $key === '' || isset($seen[$endpoint])) {
                continue;
            }

            $seen[$endpoint] = true;
            $normalized[] = [
                'endpoint' => $endpoint,
                'key' => $key,
            ];
        }

        return $normalized;
    }

    private static function build_vehicle_data(int $product_id, array $item, string $provider_class, string $vin): array
    {
        $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
        $provider = self::provider_name($provider_class, $meta);
        $images = self::string_list($item['images'] ?? []);
        $lot_id = self::first_non_empty([
            $meta['lot_id'] ?? '',
            $meta['lot_number'] ?? '',
            $meta['raw_api_payload']['lot_id'] ?? '',
            $meta['raw_api_payload']['lot'] ?? '',
        ]);

        $make = self::text($meta['make'] ?? $meta['marka'] ?? '');
        $model = self::text($meta['model'] ?? '');
        $year = self::text($meta['year'] ?? $meta['car_year'] ?? '');
        $engine = self::text($meta['engine'] ?? $meta['engine_type'] ?? '');

        $lot_key = $lot_id !== '' ? $lot_id : $vin;
        $lot_internal_id = 'vinfs-' . substr(md5($provider . '|' . $vin . '|' . $lot_key), 0, 18);

        return [
            'car' => [
                'vin' => $vin,
                'make' => $make,
                'model' => $model,
                'year' => $year,
                'engine' => $engine,
                'api_source' => $provider,
            ],
            'lots' => [
                [
                    'id' => $lot_internal_id,
                    'lot_id' => $lot_id !== '' ? $lot_id : null,
                    'site' => self::text($meta['auction'] ?? ''),
                    'base_site' => $provider,
                    'odometer' => self::text($meta['odometer'] ?? ''),
                    'year' => $year,
                    'make' => $make,
                    'model' => $model,
                    'damage_pr' => self::text($meta['damage_primary'] ?? ''),
                    'damage_sec' => self::text($meta['damage_secondary'] ?? ''),
                    'engine' => $engine,
                    'color' => self::text($meta['color'] ?? ''),
                    'location' => self::text($meta['location'] ?? ''),
                    'document' => self::text($meta['document'] ?? $meta['condition'] ?? ''),
                    'currency' => 'usd',
                    'link' => $product_id > 0 ? get_permalink($product_id) : '',
                    'link_img_hd' => wp_json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'link_img_small' => wp_json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sale_history' => wp_json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sale_date' => self::text($meta['sale_date'] ?? ''),
                    'sale_status' => self::text($meta['sale_status'] ?? ''),
                    'purchase_price' => isset($item['price']) && is_numeric($item['price']) ? (float)$item['price'] : null,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'api_source' => $provider,
                ],
            ],
            'donor' => [
                'site_url' => home_url('/'),
                'product_id' => $product_id,
                'product_url' => $product_id > 0 ? get_permalink($product_id) : '',
                'provider_class' => $provider_class,
                'provider' => $provider,
                'modified_at' => $product_id > 0 ? (string)get_post_field('post_modified_gmt', $product_id) : '',
                'plugin' => 'master-auto-catalog',
            ],
        ];
    }

    private static function normalize_vin(string $vin): string
    {
        return strtoupper(preg_replace('/[^A-HJ-NPR-Z0-9]/', '', trim($vin)));
    }

    private static function provider_name(string $provider_class, array $meta): string
    {
        $source = self::text($meta['raw_api_source'] ?? '');
        if ($source !== '') {
            return $source;
        }

        $provider_class = trim($provider_class);
        return $provider_class !== '' ? $provider_class : 'vin-fallback-search';
    }

    private static function first_non_empty(array $values): string
    {
        foreach ($values as $value) {
            $value = self::text($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function text($value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string)$value);
    }

    private static function string_list($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }
}
