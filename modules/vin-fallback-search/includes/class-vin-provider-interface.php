<?php

interface VIN_Provider_Interface
{
    public function search($query);
}

/**
 * Technical health signals are deliberately independent of a VIN result.
 * A valid empty response means that the provider works; HTTP/network/schema
 * failures are sent to the Site Protection agent when it is enabled.
 */
function mac_vin_provider_health_signal(string $provider_key, string $provider_label, string $state, array $details = []): void
{
    do_action('mac_vin_provider_health', $provider_key, $provider_label, $state, [
        'failure_type' => substr((string) ($details['failure_type'] ?? ''), 0, 64),
        'http_code' => max(0, (int) ($details['http_code'] ?? 0)),
        'elapsed_ms' => max(0, (int) ($details['elapsed_ms'] ?? 0)),
        'detail' => substr(trim((string) ($details['detail'] ?? '')), 0, 180),
    ]);
}

function mac_vin_provider_response_hint($body, $content_type = ''): string
{
    $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $body)));
    if ($text === '') {
        $text = trim((string) $content_type);
    }
    return substr($text, 0, 180);
}

/**
 * Records provider HTTP exchanges while the admin "Тест API по VIN" runs, so a
 * failed lookup can be handed to the API vendor's support with the exact
 * request we sent and the raw response we got back. Recording is off during
 * normal searches. Secrets are masked before they leave the server.
 */
final class VIN_Provider_Http_Trace
{
    const MAX_BODY_BYTES = 200000;

    private static $enabled = false;
    private static $entries = [];

    public static function start(): void
    {
        self::$enabled = true;
        self::$entries = [];
    }

    public static function stop(): array
    {
        $entries = self::$entries;
        self::$enabled = false;
        self::$entries = [];
        return $entries;
    }

    public static function record(string $method, string $url, array $args, $response, float $started_at): void
    {
        if (!self::$enabled) {
            return;
        }

        $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
        if (!isset($headers['User-Agent']) && !isset($headers['user-agent'])) {
            $headers['User-Agent'] = $args['user-agent'] ?? apply_filters('http_headers_useragent', 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'), $url);
        }

        $body = $args['body'] ?? null;
        $entry = [
            'time_utc' => gmdate('Y-m-d\TH:i:s\Z', (int) $started_at),
            'elapsed_ms' => (int) round((microtime(true) - $started_at) * 1000),
            'request' => [
                'method' => $method,
                'url' => self::mask_url($url),
                'headers' => self::mask_map($headers),
                'body' => is_array($body) ? self::mask_map($body) : $body,
                'timeout' => $args['timeout'] ?? null,
            ],
        ];
        $entry['request']['curl'] = self::to_curl($entry['request']);

        if (is_wp_error($response)) {
            $entry['error'] = $response->get_error_code() . ': ' . $response->get_error_message();
        } else {
            $raw_headers = wp_remote_retrieve_headers($response);
            if (is_object($raw_headers) && method_exists($raw_headers, 'getAll')) {
                $raw_headers = $raw_headers->getAll();
            }
            $response_body = (string) wp_remote_retrieve_body($response);
            $entry['response'] = [
                'status' => (int) wp_remote_retrieve_response_code($response),
                'message' => (string) wp_remote_retrieve_response_message($response),
                'headers' => is_array($raw_headers) ? $raw_headers : [],
                'body_bytes' => strlen($response_body),
                'body' => strlen($response_body) > self::MAX_BODY_BYTES
                    ? substr($response_body, 0, self::MAX_BODY_BYTES) . "\n…[truncated]"
                    : $response_body,
            ];
        }

        self::$entries[] = $entry;
    }

    public static function mask_secret($value): string
    {
        $value = (string) $value;
        if (preg_match('/^(Bearer|Basic)\s+(.+)$/i', $value, $m)) {
            return $m[1] . ' ' . self::mask_secret($m[2]);
        }
        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }
        return substr($value, 0, 4) . '…' . substr($value, -4) . ' (' . $length . ' chars)';
    }

    private static function is_secret_key(string $key): bool
    {
        return (bool) preg_match('/pass|secret|token|authorization|api[-_]?key|^key$/i', $key);
    }

    private static function mask_map(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            if (self::is_secret_key((string) $key)) {
                $out[$key] = preg_match('/pass/i', (string) $key) ? '********' : self::mask_secret($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private static function mask_url(string $url): string
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return $url;
        }
        parse_str($query, $params);
        return add_query_arg(array_map('rawurlencode', self::mask_map($params)), strtok($url, '?'));
    }

    private static function to_curl(array $request): string
    {
        $parts = ['curl -i -X ' . $request['method'] . ' ' . self::shell_quote($request['url'])];
        foreach ($request['headers'] as $name => $value) {
            $parts[] = '-H ' . self::shell_quote($name . ': ' . $value);
        }
        if (is_array($request['body'])) {
            foreach ($request['body'] as $name => $value) {
                $parts[] = '--data-urlencode ' . self::shell_quote($name . '=' . $value);
            }
        } elseif ($request['body'] !== null && $request['body'] !== '') {
            $parts[] = '--data ' . self::shell_quote((string) $request['body']);
        }
        return implode(" \\\n  ", $parts);
    }

    // escapeshellarg() is often disabled on shared hosting.
    private static function shell_quote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}

/**
 * wp_remote_request() wrapper used by every VIN provider.
 */
function mac_vin_provider_request(string $method, string $url, array $args)
{
    $started_at = microtime(true);
    $args['method'] = $method;
    $response = wp_remote_request($url, $args);
    VIN_Provider_Http_Trace::record($method, $url, $args, $response, $started_at);
    return $response;
}
