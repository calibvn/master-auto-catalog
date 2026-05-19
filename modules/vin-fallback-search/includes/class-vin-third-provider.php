<?php

class VIN_Third_API_Provider implements VIN_Provider_Interface
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
            throw new Exception('API ключ для Third API не настроен.');
        }

        // Проверяем существование VIN
        $vin_exists = $this->check_vin_exists($vin);
        if (!$vin_exists) {
            return null;
        }

        // Получаем детальный отчет
        return $this->get_vin_report($vin);
    }

    /**
     * Проверка существования VIN
     */
    private function check_vin_exists($vin)
    {
        $exists_url = "https://auctionsapi.com/api/local-exists/{$vin}";

        $response = wp_remote_get($exists_url, [
            'timeout'     => 20,
            'redirection' => 0,
            'headers'     => [
                'x-api-key' => $this->api_key,
                'Accept' => '*/*',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Ошибка проверки VIN: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 401 || $code === 403) {
            throw new Exception('API unauthorized (' . $code . '). Проверьте API ключ.');
        }

        if ($code < 200 || $code >= 300) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Third API: VIN check failed with code: ' . $code);
            }
            return false;
        }

        $exists_data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Third API: JSON decode error: ' . json_last_error_msg());
            }
            return false;
        }

        // Проверяем существует ли VIN
        if (isset($exists_data['exists'])) {
            $vin_exists = (bool)$exists_data['exists'];
        } else {
            $vin_exists = !empty($exists_data);
        }

        if (!$vin_exists) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Third API: VIN ' . $vin . ' не существует в базе');
            }
            return false;
        }

        return true;
    }

    /**
     * Получение детального отчета по VIN
     */
    private function get_vin_report($vin)
    {
        $report_url = "https://auctionsapi.com/api/local-report/{$vin}";

        $response = wp_remote_get($report_url, [
            'timeout'     => 20,
            'redirection' => 0,
            'headers'     => [
                'x-api-key' => $this->api_key,
                'Accept' => '*/*',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Ошибка получения отчета: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 401 || $code === 403) {
            throw new Exception('API unauthorized (' . $code . '). Проверьте API ключ.');
        }

        if ($code < 200 || $code >= 300) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Third API: Report request failed with code: ' . $code);
            }
            return null;
        }

        $report_data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($report_data)) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Third API: Report JSON decode error: ' . json_last_error_msg());
            }
            return null;
        }

        if (VIN_FS_DEBUG) {
            error_log('[VIN Fallback] Third API: Successfully parsed report data');
        }

        return $this->normalize_data($report_data, $vin);
    }

    /**
     * Нормализация данных от Third API
     */
    private function normalize_data($data, $vin)
    {
        $vehicle_data = $data['data'] ?? [];
        if (empty($vehicle_data)) {
            return null;
        }

        // Берем первый лот для основной информации
        $primary_lot = $vehicle_data['lots'][0] ?? [];

        // Заголовок
        $year = $vehicle_data['year'] ?? '';
        $make = $vehicle_data['manufacturer']['name'] ?? '';
        $model = $vehicle_data['model']['name'] ?? '';
        $generation = $vehicle_data['generation']['name'] ?? '';
        $title = $vehicle_data['title'] ?? trim(implode(' ', array_filter([$year, $make, $model, $generation])));
        if ($title === '') $title = $vin;

        // Подготавливаем данные для нормализации
        $prepared_data = $this->prepare_api_data($vehicle_data, $primary_lot);

        // Нормализуем метаданные
        $meta = VIN_Data_Normalizer::normalize_meta($prepared_data, 'third_api');
        $meta['vin'] = $vin;
        $meta['raw_api_payload'] = $data;

        // Изображения
        $images = $this->extract_images($primary_lot);

        // Цена
        $price = $this->extract_price($primary_lot);

        return [
            'sku'         => $vin,
            'title'       => $title,
            'description' => VIN_Data_Normalizer::create_description($meta),
            'price'       => $price,
            'images'      => $images,
            'meta'        => $meta,
        ];
    }

    /**
     * Подготовка данных API для нормализатора
     */
    private function prepare_api_data($vehicle_data, $primary_lot)
    {
        return [
            'year' => $vehicle_data['year'] ?? '',
            'manufacturer' => [
                'name' => $vehicle_data['manufacturer']['name'] ?? ''
            ],
            'model' => [
                'name' => $vehicle_data['model']['name'] ?? ''
            ],
            'generation' => [
                'name' => $vehicle_data['generation']['name'] ?? ''
            ],
            'body_type' => [
                'name' => $vehicle_data['body_type']['name'] ?? ''
            ],
            'color' => [
                'name' => $vehicle_data['color']['name'] ?? ''
            ],
            'engine' => [
                'name' => $vehicle_data['engine']['name'] ?? ''
            ],
            'transmission' => [
                'name' => $vehicle_data['transmission']['name'] ?? ''
            ],
            'fuel' => [
                'name' => $vehicle_data['fuel']['name'] ?? ''
            ],
            'vehicle_type' => [
                'name' => $vehicle_data['vehicle_type']['name'] ?? ''
            ],
            'lot' => $primary_lot['lot'] ?? '',
            'odometer' => [
                'km' => $primary_lot['odometer']['km'] ?? '',
                'mi' => $primary_lot['odometer']['mi'] ?? '',
                'status' => [
                    'name' => $primary_lot['odometer']['status']['name'] ?? ''
                ]
            ],
            'domain' => [
                'name' => $primary_lot['domain']['name'] ?? ''
            ],
            'status' => [
                'name' => $primary_lot['status']['name'] ?? ''
            ],
            'keys_available' => $primary_lot['keys_available'] ?? false,
            'condition' => [
                'name' => $primary_lot['condition']['name'] ?? ''
            ],
            'damage' => [
                'main' => $primary_lot['damage']['main'] ?? '',
                'second' => $primary_lot['damage']['second'] ?? ''
            ],
            'sale_date' => $primary_lot['sale_date'] ?? '',
            'final_bid' => $primary_lot['final_bid'] ?? '',
            'bid' => $primary_lot['bid'] ?? '',
            'buy_now' => $primary_lot['buy_now'] ?? '',
            'location' => [
                'country' => [
                    'name' => $primary_lot['location']['country']['name'] ?? ''
                ],
                'city' => [
                    'name' => $primary_lot['location']['city']['name'] ?? ''
                ]
            ],
            'airbags' => $primary_lot['airbags'] ?? ''
        ];
    }

    /**
     * Извлечение изображений
     */
    private function extract_images($primary_lot)
    {
        $images = [];

        if (!empty($primary_lot['images'])) {
            // Предпочитаем downloaded изображения (без водяных знаков)
            if (!empty($primary_lot['images']['downloaded']) && is_array($primary_lot['images']['downloaded'])) {
                $images = $primary_lot['images']['downloaded'];
            }
            // Или берем normal изображения
            elseif (!empty($primary_lot['images']['normal']) && is_array($primary_lot['images']['normal'])) {
                $images = $primary_lot['images']['normal'];
            }
            // Или big изображения
            elseif (!empty($primary_lot['images']['big']) && is_array($primary_lot['images']['big'])) {
                $images = $primary_lot['images']['big'];
            }
        }

        // Фильтруем и валидируем URL
        $image_urls = [];
        foreach ($images as $image) {
            if (is_string($image) && filter_var($image, FILTER_VALIDATE_URL)) {
                $image_urls[] = $image;
            }
        }

        return array_values(array_unique(array_filter($image_urls)));
    }

    /**
     * Извлечение цены
     */
    private function extract_price($primary_lot)
    {
        $price = 0.0;

        if (!empty($primary_lot)) {
            // Пробуем разные поля с ценой в порядке приоритета
            if (isset($primary_lot['final_bid']) && is_numeric($primary_lot['final_bid'])) {
                $price = floatval($primary_lot['final_bid']);
            } elseif (isset($primary_lot['bid']) && is_numeric($primary_lot['bid'])) {
                $price = floatval($primary_lot['bid']);
            } elseif (isset($primary_lot['buy_now']) && is_numeric($primary_lot['buy_now'])) {
                $price = floatval($primary_lot['buy_now']);
            }
        }

        return $price;
    }
}
