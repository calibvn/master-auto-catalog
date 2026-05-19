<?php

class VIN_Second_API_Provider implements VIN_Provider_Interface
{

    private $login;
    private $password;
    private $token;
    private $token_expires;

    public function __construct($login, $password)
    {
        $this->login = $login;
        $this->password = $password;
        $this->token = null;
        $this->token_expires = null;
    }

    public function search($query)
    {
        $vin = strtoupper(preg_replace('/\s+/', '', (string)$query));

        if (empty($this->login) || empty($this->password)) {
            throw new Exception('Логин или пароль для Second API не настроен.');
        }

        // Получаем токен
        $token = $this->get_token();
        if (!$token) {
            throw new Exception('Не удалось получить токен авторизации для Second API.');
        }

        // Запрашиваем данные по VIN
        $url = 'https://auction-api.app/api/v1/get-vin-history';

        $response = wp_remote_post($url, [
            'timeout'     => 30,
            'redirection' => 0,
            'headers'     => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => ['vin' => $vin]
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Ошибка запроса VIN history: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (VIN_FS_DEBUG) {
            error_log('[VIN Fallback] Second API: Response code: ' . $code);
        }

        // Обработка ошибки авторизации
        if ($code === 401) {
            $this->clear_token();
            $token = $this->get_token(true);

            if (!$token) {
                throw new Exception('Токен устарел и не удалось обновить');
            }

            // Повторяем запрос с новым токеном
            $response = wp_remote_post($url, [
                'timeout'     => 30,
                'redirection' => 0,
                'headers'     => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => ['vin' => $vin]
            ]);

            if (is_wp_error($response)) {
                throw new Exception('Ошибка повторного запроса VIN: ' . $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
        }

        if ($code < 200 || $code >= 300) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Second API: VIN history request failed with code: ' . $code);
            }
            return null;
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Second API: JSON decode error: ' . json_last_error_msg());
            }
            return null;
        }

        if (empty($data['data']) || !is_array($data['data'])) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Second API: No history data found for VIN: ' . $vin);
            }
            return null;
        }

        // Берем первый (самый актуальный) элемент из истории
        $first_item = $data['data'][0] ?? null;
        if (!$first_item) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Second API: First item in history is empty for VIN: ' . $vin);
            }
            return null;
        }

        if (VIN_FS_DEBUG) {
            error_log('[VIN Fallback] Second API: Successfully parsed history data, using first of ' . count($data['data']) . ' items');
        }

        return $this->normalize_data($first_item, $vin, $data);
    }

    /**
     * Получение токена с кешированием
     */
    private function get_token($force_refresh = false)
    {
        // Проверяем кеш
        $cached_token = get_transient('vin_second_api_token');
        $cached_token_data = get_transient('vin_second_api_token_data');

        if (!$force_refresh && $cached_token && $cached_token_data) {
            $this->token = $cached_token;
            $this->token_expires = $cached_token_data['expires'];
            return $this->token;
        }

        // Получаем новый токен
        $url = 'https://auction-api.app/api/v1/login';

        $response = wp_remote_post($url, [
            'timeout'     => 20,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'email' => $this->login,
                'password' => $this->password
            ]
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Ошибка получения токена: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            throw new Exception('Ошибка авторизации: ' . $code);
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['token'])) {
            throw new Exception('Неверный ответ при авторизации');
        }

        $this->token = $data['token'];
        $this->token_expires = time() + (23 * 60 * 60); // 23 часа

        // Сохраняем в транзиенте
        set_transient('vin_second_api_token', $this->token, 23 * 60 * 60);
        set_transient('vin_second_api_token_data', [
            'expires' => $this->token_expires
        ], 23 * 60 * 60);

        if (VIN_FS_DEBUG) {
            error_log('[VIN Fallback] Second API: Token received successfully');
        }

        return $this->token;
    }

    /**
     * Очистка токена
     */
    private function clear_token()
    {
        $this->token = null;
        $this->token_expires = null;
        delete_transient('vin_second_api_token');
        delete_transient('vin_second_api_token_data');
    }

    /**
     * Нормализация данных от Second API
     */
    private function normalize_data($car_data, $vin, $full_response = [])
    {
        if (empty($car_data)) {
            return null;
        }

        // Заголовок
        $year = $car_data['year'] ?? '';
        $make = $car_data['make'] ?? '';
        $model = $car_data['model'] ?? '';
        $title = trim(implode(' ', array_filter([$year, $make, $model])));
        if ($title === '') $title = $vin;

        // Форматирование даты продажи
        $sale_date = '';
        if (!empty($car_data['sale_date'])) {
            $sale_timestamp = intval($car_data['sale_date']);
            if ($sale_timestamp > 0) {
                $sale_date = date('Y-m-d', $sale_timestamp);
            }
        }

        // Обработка изображений
        $images = $this->extract_images($car_data);

        // Нормализуем метаданные
        $meta = VIN_Data_Normalizer::normalize_meta($car_data, 'second_api');
        $meta['vin'] = $vin;
        $meta['sale_date'] = $sale_date;
        $meta['raw_api_payload'] = $full_response;

        // Цена
        $price = 0.0;
        if (isset($car_data['purchase_price']) && is_numeric($car_data['purchase_price'])) {
            $price = floatval($car_data['purchase_price']);
        } elseif (isset($car_data['est_retail_value']) && is_numeric($car_data['est_retail_value'])) {
            $price = floatval($car_data['est_retail_value']);
        }

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
     * Извлечение изображений из данных
     */
    private function extract_images($car_data)
    {
        $images = [];

        if (!empty($car_data['photo'])) {
            $photo_data = $car_data['photo'];

            // Если photo это JSON строка, декодируем
            if (is_string($photo_data) && strpos($photo_data, '[') === 0) {
                $decoded_photos = json_decode($photo_data, true);
                if (is_array($decoded_photos)) {
                    $photo_data = $decoded_photos;
                }
            }

            if (is_array($photo_data)) {
                foreach ($photo_data as $photo_url) {
                    if (is_string($photo_url) && filter_var($photo_url, FILTER_VALIDATE_URL)) {
                        $images[] = $photo_url;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($images)));
    }
}
