<?php

class VIN_Attribute_Manager
{

    private $attribute_cache = [];
    private $taxonomy_cache = [];

    /**
     * Установка атрибутов из метаданных (оптимизированная)
     */
    public function set_attributes_from_meta(int $post_id, array $meta): void
    {
        $attributes_map = $this->get_attributes_mapping($meta);
        $product_attributes = [];
        $terms_to_set = [];

        foreach ($attributes_map as $attr_slug => $raw_value) {
            if (empty($raw_value)) continue;

            $value = $this->prepare_attribute_value($attr_slug, $raw_value);
            if (empty($value)) continue;

            $taxonomy = 'pa_' . sanitize_title($attr_slug);
            $term_id = $this->get_or_create_term($taxonomy, $value);

            if ($term_id) {
                $terms_to_set[$taxonomy][] = $term_id;
                
                $product_attributes[$taxonomy] = [
                    'name'         => $taxonomy,
                    'value'        => $term_id,
                    'is_visible'   => 1,
                    'is_variation' => 0,
                    'is_taxonomy'  => 1,
                ];
            }
        }

        // Массовая установка терминов
        foreach ($terms_to_set as $taxonomy => $term_ids) {
            wp_set_object_terms($post_id, $term_ids, $taxonomy, false);
        }

        if (!empty($product_attributes)) {
            update_post_meta($post_id, '_product_attributes', $product_attributes);
            
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Saved product attributes: ' . count($product_attributes));
            }
        }
    }

    /**
     * Маппинг атрибутов с передачей метаданных
     */
    private function get_attributes_mapping(array $meta)
    {
        return [
            // Основные атрибуты
            'car_year'                 => $meta['car_year'] ?? $meta['year'] ?? '',
            'marka'                    => $meta['marka'] ?? $meta['make'] ?? '',
            'model'                    => $meta['model'] ?? '',
            'generation'               => $meta['generation'] ?? '',
            'body_type'                => $meta['body_type'] ?? $meta['vehicle_type'] ?? '',
            'exterior'                 => $meta['exterior'] ?? $meta['color'] ?? '',
            'engine'                   => $meta['engine'] ?? '',
            'transmission'             => $meta['transmission'] ?? '',
            'fuel'                     => $meta['fuel'] ?? '',
            'drive_wheel'              => $meta['drive_wheel'] ?? $meta['drive'] ?? '',
            'vehicle_type'             => $meta['vehicle_type'] ?? '',
            'vin'                      => $meta['vin'] ?? '',

            // Атрибуты лота/аукциона
            'lot_number'               => $meta['lot_number'] ?? $meta['nomer_lota'] ?? '',
            'lot_id'                   => $meta['lot_id'] ?? $meta['lot_number'] ?? '',
            'mileage'                  => $meta['mileage'] ?? $meta['odometer'] ?? $meta['odometer_km'] ?? '',
            'auction'                  => $meta['auction'] ?? $meta['base_site'] ?? '',
            'sale_status'              => $meta['sale_status'] ?? '',
            'keys'                     => $meta['keys'] ?? $meta['keys_available'] ?? '',
            'condition'                => $meta['condition'] ?? '',
            'osnovnye-povrezhdeniya'   => $meta['osnovnye-povrezhdeniya'] ?? $meta['damage_primary'] ?? $meta['damage_pr'] ?? '',
            'dopolnitelnye-povrezhdeniya' => $meta['dopolnitelnye-povrezhdeniya'] ?? $meta['damage_secondary'] ?? $meta['damage_sec'] ?? '',
            'sale_date'                => $meta['sale_date'] ?? '',

            // Локация
            'location'                 => $meta['location'] ?? $meta['location_city'] ?? $meta['location_country'] ?? '',
            'country'                  => $meta['country'] ?? $meta['location_country'] ?? '',

            // Дополнительные атрибуты
            'airbags'                  => $meta['airbags'] ?? '',
        ];
    }

    /**
     * Подготовка значения атрибута
     */
    private function prepare_attribute_value($attr_slug, $raw_value)
    {
        // Если значение массив - берем первое непустое значение
        if (is_array($raw_value)) {
            $value = $this->array_to_display_value($raw_value);
        } else {
            $value = $raw_value;
        }

        $value = is_array($value) ? implode(', ', array_filter($value)) : trim((string)$value);
        if ($value === '') return '';

        // Специальная обработка для определенных атрибутов
        switch ($attr_slug) {
            case 'sale_date':
            case 'auction_date':
                // Форматирование даты
                return explode('T', $value)[0];

            case 'keys':
                // Нормализация булевых значений
                if ($value === 'yes' || $value === 'true' || $value === '1' || $value === true) {
                    return 'yes';
                } else {
                    return 'no';
                }

            default:
                return $value;
        }
    }

    /**
     * Получение или создание термина
     */
    private function array_to_display_value(array $value)
    {
        foreach (['name', 'title', 'code', 'value', 'label'] as $key) {
            if (isset($value[$key]) && $value[$key] !== '' && $value[$key] !== null && !is_array($value[$key])) {
                return $value[$key];
            }
        }

        $parts = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $this->array_to_display_value($item);
            }

            if ($item !== '' && $item !== null && !is_array($item)) {
                $parts[] = (string)$item;
            }
        }

        return implode(', ', array_filter($parts, static function ($item) {
            return $item !== '';
        }));
    }

    private function get_or_create_term($taxonomy, $value)
    {
        $cache_key = $taxonomy . '_' . md5($value);

        if (isset($this->attribute_cache[$cache_key])) {
            return $this->attribute_cache[$cache_key];
        }

        // Проверяем существование таксономии
        if (!taxonomy_exists($taxonomy)) {
            $this->create_attribute_taxonomy($taxonomy);
        }

        // Ищем существующий термин
        $term = term_exists($value, $taxonomy);

        // Создаем новый термин если не существует
        if (!$term) {
            $term = wp_insert_term($value, $taxonomy);
        }

        if (is_wp_error($term)) {
            if (VIN_FS_DEBUG) {
                error_log('[VIN Fallback] Term creation error: ' . $term->get_error_message());
            }
            return 0;
        }

        $term_id = (int)($term['term_id'] ?? 0);
        $this->attribute_cache[$cache_key] = $term_id;

        return $term_id;
    }

    /**
     * Создание таксономии атрибута
     */
    private function create_attribute_taxonomy($taxonomy)
    {
        $attribute_name = str_replace('pa_', '', $taxonomy);
        $attribute_label = $this->get_attribute_label($attribute_name);

        $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_name);

        if (!$attribute_id) {
            $attribute_id = wc_create_attribute([
                'name' => $attribute_label,
                'slug' => $attribute_name,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);
        }

        // Регистрируем таксономию
        if ($attribute_id && !taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['product'], []);
        }

        return $attribute_id;
    }

    /**
     * Привязать категорию по марке (с автоматическим созданием)
     */
    private function get_attribute_label($attribute_name)
    {
        $labels = [
            'osnovnye-povrezhdeniya' => 'Primary damage',
            'dopolnitelnye-povrezhdeniya' => 'Secondary damage',
        ];

        return $labels[$attribute_name] ?? ucfirst(str_replace(['-', '_'], ' ', $attribute_name));
    }

    public function assign_category_by_make(int $post_id, string $make): void
    {
        $make = trim($make);
        $taxonomy = 'product_cat';

        // Термин для "misc" — создадим при отсутствии
        $misc_term = term_exists('misc', $taxonomy);
        if (!$misc_term) {
            $misc_term = wp_insert_term('misc', $taxonomy);
        }
        $misc_id = is_wp_error($misc_term) ? 0 : (int)($misc_term['term_id'] ?? $misc_term['term_id'] ?? 0);

        if ($make === '') {
            if ($misc_id) {
                wp_set_object_terms($post_id, [$misc_id], $taxonomy, false);
            }
            return;
        }

        // Ищем точное совпадение по имени категории
        $maybe = get_term_by('name', $make, $taxonomy);
        if ($maybe && !is_wp_error($maybe)) {
            // Категория найдена - присваиваем
            wp_set_object_terms($post_id, [(int)$maybe->term_id], $taxonomy, false);
            
            if (VIN_FS_DEBUG) {
                error_log("[VIN Fallback] Found existing category '{$make}' (ID: {$maybe->term_id}) for product {$post_id}");
            }
        } else {
            // Категория не найдена - создаем новую
            $new_term = wp_insert_term($make, $taxonomy, [
                'slug' => sanitize_title($make),
                'description' => "Автомобили марки {$make} (автоматически создано из VIN)"
            ]);
            
            if (!is_wp_error($new_term) && isset($new_term['term_id'])) {
                // Успешно создали - присваиваем
                wp_set_object_terms($post_id, [(int)$new_term['term_id']], $taxonomy, false);
                
                if (VIN_FS_DEBUG) {
                    error_log("[VIN Fallback] Created new category '{$make}' (ID: {$new_term['term_id']}) for product {$post_id}");
                }
            } else {
                // Ошибка создания - кидаем в misc
                if ($misc_id) {
                    wp_set_object_terms($post_id, [$misc_id], $taxonomy, false);
                    
                    if (VIN_FS_DEBUG) {
                        $error = is_wp_error($new_term) ? $new_term->get_error_message() : 'unknown error';
                        error_log("[VIN Fallback] Failed to create category '{$make}': {$error}. Assigned to misc.");
                    }
                }
            }
        }
    }

    /**
     * Очистка кеша
     */
    public function clear_cache()
    {
        $this->attribute_cache = [];
    }
}
