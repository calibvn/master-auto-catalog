<?php

class VIN_Data_Normalizer
{

    /**
     * Генерация slug для товара
     */
    public static function generate_slug($item, $title, $vin)
    {
        $meta = $item['meta'] ?? [];

        $make  = sanitize_title($meta['marka'] ?? $meta['make'] ?? '');
        $model = sanitize_title($meta['model'] ?? '');
        $year  = sanitize_title($meta['car_year'] ?? $meta['year'] ?? '');
        $lot   = self::extract_lot_id($meta, true);

        // ✅ slug без VIN
        $slug_parts = array_filter([$make, $model, $year, $lot]);
        $slug = implode('-', $slug_parts);

        if (empty($slug)) {
            $slug = sanitize_title($title);
        }

        // Получаем текущий домен
        $site_url = parse_url(get_site_url(), PHP_URL_HOST);

        // Проверяем домен и не добавляем VIN, если домен carlogique.com
        if ($site_url !== 'carlogique.com') {
            $slug .= '-' . sanitize_title($vin);
        }

        return $slug;
    }


    /**
     * Извлечение ID лота из метаданных
     */
    public static function extract_lot_id($meta, $for_slug = false)
    {
        $lot = '';

        // Пробуем разные источники в порядке приоритета
        if (!empty($meta['lot_number'])) {
            $lot = $meta['lot_number'];
        } elseif (!empty($meta['lot_id'])) {
            $lot = $meta['lot_id'];
        } elseif (!empty($meta['raw_api_payload']['lot_id'])) {
            $lot = $meta['raw_api_payload']['lot_id'];
        } elseif (!empty($meta['raw_api_payload']['lots'][0]['lot'])) {
            $lot = $meta['raw_api_payload']['lots'][0]['lot'];
        } elseif (!empty($meta['raw_api_payload']['id'])) {
            $lot = 'id-' . $meta['raw_api_payload']['id'];
        }

        if ($for_slug && !empty($lot)) {
            $lot = sanitize_title($lot);
        }

        return $lot;
    }

    /**
     * Нормализация метаданных от разных провайдеров
     */
    public static function normalize_meta($raw_meta, $source)
    {
        $mapping = self::get_field_mapping($source);
        $normalized = [];

        foreach ($mapping as $standard_field => $source_fields) {
            $value = self::get_first_non_empty($raw_meta, $source_fields);
            $normalized[$standard_field] = self::format_value($standard_field, $value);
        }

        // Добавляем исходные данные для отладки
        $normalized['raw_api_source'] = $source;
        $normalized['raw_api_payload'] = $raw_meta;

        return $normalized;
    }

    /**
     * Маппинг полей для разных API
     */
    private static function get_field_mapping($source)
    {
        $mappings = [
            'apicar' => [
                'vin' => ['vin'],
                'year' => ['year', 'car_year'],
                'make' => ['make', 'marka'],
                'model' => ['model'],
                'odometer' => ['odometer', 'mileage'],
                'damage_primary' => ['damage_pr', 'damage_primary', 'osnovnye-povrezhdeniya'],
                'damage_secondary' => ['damage_sec', 'damage_secondary', 'dopolnitelnye-povrezhdeniya'],
                'location' => ['location', 'location_city'],
                'engine' => ['engine'],
                'transmission' => ['transmission'],
                'fuel' => ['fuel'],
                'drive' => ['drive', 'drive_wheel'],
                'auction' => ['base_site', 'auction'],
                'sale_status' => ['sale_status'],
                'sale_date' => ['sale_date'],
                'lot_id' => ['lot_id'],
                'lot_number' => ['lot_number'],
                'color' => ['color', 'exterior'],
                'vehicle_type' => ['vehicle_type'],
                'keys' => ['keys', 'keys_available'],
            ],
            'second_api' => [
                'vin' => ['vin'],
                'year' => ['year', 'car_year'],
                'make' => ['make', 'marka'],
                'model' => ['model'],
                'odometer' => ['odometer', 'mileage'],
                'damage_primary' => ['primary_damage', 'damage_primary', 'osnovnye-povrezhdeniya'],
                'damage_secondary' => ['secondary_damage', 'damage_secondary', 'dopolnitelnye-povrezhdeniya'],
                'location' => ['location', 'location_city'],
                'engine' => ['engine_type', 'engine'],
                'transmission' => ['transmission'],
                'fuel' => ['fuel'],
                'drive' => ['drive', 'drive_wheel'],
                'auction' => ['auction_name', 'auction'],
                'sale_status' => ['sale_status'],
                'sale_date' => ['sale_date'],
                'lot_id' => ['lot_id'],
                'lot_number' => ['lot_number'],
                'color' => ['color', 'exterior'],
                'vehicle_type' => ['vehicle_type'],
                'keys' => ['car_keys', 'keys'],
            ],
            'third_api' => [
                'vin' => ['vin'],
                'year' => ['year', 'car_year'],
                'make' => ['manufacturer.name', 'make', 'marka'],
                'model' => ['model.name', 'model'],
                'generation' => ['generation.name', 'generation'],
                'odometer' => ['odometer.km', 'mileage', 'odometer_km'],
                'damage_primary' => ['damage.main', 'osnovnye-povrezhdeniya'],
                'damage_secondary' => ['damage.second', 'dopolnitelnye-povrezhdeniya'],
                'location' => ['location.city.name', 'location.country.name', 'location'],
                'engine' => ['engine.name', 'engine'],
                'transmission' => ['transmission.name', 'transmission'],
                'fuel' => ['fuel.name', 'fuel'],
                'drive' => ['drive_wheel', 'drive'],
                'auction' => ['domain.name', 'auction'],
                'sale_status' => ['status.name', 'sale_status'],
                'sale_date' => ['sale_date'],
                'lot_id' => ['lot'],
                'lot_number' => ['lot'],
                'color' => ['color.name', 'exterior'],
                'vehicle_type' => ['vehicle_type.name', 'vehicle_type'],
                'keys' => ['keys_available', 'keys'],
                'body_type' => ['body_type.name', 'body_type'],
                'condition' => ['condition.name', 'condition'],
            ]
        ];

        return $mappings[$source] ?? [];
    }

    /**
     * Получение первого непустого значения из массива ключей
     */
    private static function get_first_non_empty($data, $keys)
    {
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        foreach ($keys as $key) {
            // Обработка вложенных ключей (например: 'manufacturer.name')
            if (strpos($key, '.') !== false) {
                $value = self::get_nested_value($data, $key);
            } else {
                $value = $data[$key] ?? null;
            }

            if (!empty($value) || $value === 0 || $value === '0') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Получение вложенных значений
     */
    private static function get_nested_value($data, $key)
    {
        $keys = explode('.', $key);
        $value = $data;

        foreach ($keys as $k) {
            if (is_array($value) && isset($value[$k])) {
                $value = $value[$k];
            } else {
                return '';
            }
        }

        return $value;
    }

    /**
     * Форматирование значений по типу поля
     */
    private static function format_value($field, $value)
    {
        if (is_array($value)) {
            $value = self::array_to_display_value($value);
        }

        if (empty($value)) {
            return $value;
        }

        switch ($field) {
            case 'sale_date':
                // Форматирование даты: "2025-08-23T00:48:00.000Z" → "2025-08-23"
                return explode('T', $value)[0];

            case 'keys':
                // Нормализация булевых значений
                if ($value === 'yes' || $value === 'true' || $value === '1' || $value === true) {
                    return 'yes';
                } else {
                    return 'no';
                }

            case 'odometer':
                // Обеспечиваем числовое значение
                return is_numeric($value) ? floatval($value) : $value;

            default:
                return $value;
        }
    }

    private static function array_to_display_value(array $value)
    {
        foreach (['name', 'title', 'code', 'value', 'label'] as $key) {
            if (isset($value[$key]) && $value[$key] !== '' && $value[$key] !== null && !is_array($value[$key])) {
                return $value[$key];
            }
        }

        $parts = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = self::array_to_display_value($item);
            }

            if ($item !== '' && $item !== null && !is_array($item)) {
                $parts[] = (string)$item;
            }
        }

        return implode(', ', array_filter($parts, static function ($item) {
            return $item !== '';
        }));
    }

    /**
     * Создание описания товара из метаданных
     */
    public static function create_description($meta)
    {
        $fields = [
            'VIN' => $meta['vin'] ?? '',
            'Год' => $meta['year'] ?? $meta['car_year'] ?? '',
            'Марка' => $meta['make'] ?? $meta['marka'] ?? '',
            'Модель' => $meta['model'] ?? '',
            'Поколение' => $meta['generation'] ?? '',
            'Тип ТС' => $meta['vehicle_type'] ?? '',
            'Двигатель' => $meta['engine'] ?? '',
            'Трансмиссия' => $meta['transmission'] ?? '',
            'Пробег' => $meta['odometer'] ?? $meta['mileage'] ?? '',
            'Цвет' => $meta['color'] ?? $meta['exterior'] ?? '',
            'Повреждения' => $meta['damage_primary'] ?? $meta['osnovnye-povrezhdeniya'] ?? '',
            'Доп. повреждения' => $meta['damage_secondary'] ?? $meta['dopolnitelnye-povrezhdeniya'] ?? '',
            'Локация' => $meta['location'] ?? '',
            'Аукцион' => $meta['auction'] ?? '',
            'Статус продажи' => $meta['sale_status'] ?? '',
            'Дата продажи' => $meta['sale_date'] ?? '',
            'Ключи' => $meta['keys'] ?? '',
            'Лот' => $meta['lot_number'] ?? $meta['lot_id'] ?? '',
        ];

        $meta_lines = [];
        foreach ($fields as $k => $v) {
            if ($v !== '' && $v !== null && $v !== 'Не указана' && $v !== 'Нет информации') {
                $meta_lines[] = '<strong>' . esc_html($k) . ':</strong> ' . esc_html((string)$v);
            }
        }

        return '<p>' . implode('<br>', $meta_lines) . '</p>';
    }
}
