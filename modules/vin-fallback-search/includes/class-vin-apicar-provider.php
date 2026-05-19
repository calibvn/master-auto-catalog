<?php

class VIN_Apicar_Provider implements VIN_Provider_Interface
{

    private $api_key;

    public function __construct($api_key)
    {
        $this->api_key = $api_key;
    }

    public function search($query)
    {
        $vin = strtoupper(preg_replace('/\s+/', '', (string)$query));

        if (empty($this->api_key)) {
            throw new Exception('API ключ для Apicar.store не настроен.');
        }

        $url = add_query_arg(['vin' => $vin], 'https://api.apicar.store/api/cars/vin/all');

        $response = wp_remote_get($url, [
            'timeout'     => 20,
            'redirection' => 0,
            'headers'     => [
                'accept' => '*/*',
                'api-key' => $this->api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 401 || $code === 403) {
            throw new Exception('API unauthorized (' . $code . '). Проверьте API ключ.');
        }

        if ($code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || empty($data)) {
            return null;
        }

        $row = $data[0];
        return $this->normalize_data($row, $vin);
    }

    /**
     * Нормализация данных от API
     */
    private function normalize_data($row, $vin) {
    // Заголовок
    $year   = $row['year']  ?? '';
    $make   = $row['make']  ?? '';
    $model  = $row['model'] ?? '';
    $series = $row['color']?? '';
    $title  = trim(implode(' ', array_filter([$make, $model, $year, $series])));
    if ($title === '') $title = $row['title'] ?? $vin;

    // Нормализуем метаданные
    $meta = VIN_Data_Normalizer::normalize_meta($row, 'apicar');
    $meta['vin'] = $vin;

    // ЦЕНА - пробуем разные поля
    $price = 0.0;
    $price_fields = ['purchase_price', 'cost_priced', 'current_bid', 'price_new'];
    
    foreach ($price_fields as $field) {
        if (isset($row[$field]) && is_numeric($row[$field]) && floatval($row[$field]) > 0) {
            $price = floatval($row[$field]);
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Apicar price found in field "' . $field . '": ' . $price);
            }
            break;
        }
    }

    // Если цена не найдена, логируем это
    if ($price <= 0 && VIN_FS_DEBUG) {
        error_log('[VIN Fallback] Apicar no price found in fields: ' . implode(', ', $price_fields));
        error_log('[VIN Fallback] Available price fields: ' . print_r(array_intersect_key($row, array_flip($price_fields)), true));
    }

    // Изображения
    $images = [];
    if (!empty($row['link_img_hd']) && is_array($row['link_img_hd'])) {
        $images = $row['link_img_hd'];
    } elseif (!empty($row['link_img_small']) && is_array($row['link_img_small'])) {
        $images = $row['link_img_small'];
    }
    $images = array_values(array_unique(array_filter($images, 'is_string')));

    return [
        'sku'         => $vin,
        'title'       => $title,
        'description' => VIN_Data_Normalizer::create_description($meta),
        'price'       => $price,
        'images'      => $images,
        'meta'        => $meta,
    ];
}
}
