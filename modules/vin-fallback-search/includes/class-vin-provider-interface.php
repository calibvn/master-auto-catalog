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
