<?php

class VIN_Image_Processor
{
    private $MAX_WIDTH = 1200;
    private $CROP_PERCENT = 0.04;
    private $JPEG_QUALITY = 92;
    private $skip_processing = false;

    public function __construct()
    {
        // Проверяем наличие GD при инициализации
        $this->skip_processing = !extension_loaded('gd');
        if ($this->skip_processing && VIN_FS_DEBUG) {
            error_log('[VIN Image Processor] GD библиотека не найдена. Обработка изображений отключена.');
        }
    }

    public function attach_images(int $post_id, array $urls): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $urls = array_values(array_unique(array_filter(array_map('esc_url_raw', $urls))));
        if (empty($urls)) return;

        $gallery_ids = [];
        $hashes = [];

        foreach ($urls as $idx => $url) {
            $att_id = false;
            
            if ($this->skip_processing) {
                // Без GD - используем простую загрузку
                $att_id = $this->simple_download_image($url, $post_id, $idx);
            } else {
                // С GD - полная обработка
                $image_data = $this->download_and_process_image($url);
                if ($image_data) {
                    $att_id = $this->create_attachment($post_id, $image_data, $url, $idx);
                }
            }

            if ($att_id) {
                if ($idx === 0) {
                    set_post_thumbnail($post_id, $att_id);
                    if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Установлена миниатюра: ' . $att_id);
                } else {
                    $gallery_ids[] = $att_id;
                }
            }
        }

        if (!empty($gallery_ids)) {
            update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids));
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Галерея создана: ' . count($gallery_ids) . ' изображений');
        }
    }

    /**
     * Простая загрузка изображения без обработки GD
     */
    private function simple_download_image($url, $post_id, $index)
    {
        if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Простая загрузка изображения: ' . $url);
        
        // Временно отключаем SSL проверку если нужно
        $tmp = download_url($url, 30);
        
        if (is_wp_error($tmp)) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Ошибка загрузки: ' . $tmp->get_error_message());
            return false;
        }
        
        // Получаем расширение файла из URL
        $path_parts = pathinfo($url);
        $extension = isset($path_parts['extension']) ? $path_parts['extension'] : 'jpg';
        $extension = strtolower($extension);
        
        // Проверяем допустимые расширения
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($extension, $allowed_extensions)) {
            $extension = 'jpg';
        }
        
        $file_array = [
            'name'     => 'vin-' . $post_id . '-' . ($index + 1) . '.' . $extension,
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize($tmp)
        ];
        
        $att_id = media_handle_sideload($file_array, $post_id);
        
        if (is_wp_error($att_id)) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Ошибка создания вложения: ' . $att_id->get_error_message());
            @unlink($tmp);
            return false;
        }
        
        if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Изображение загружено: ' . $att_id);
        return $att_id;
    }

    /**
     * Скачивание и обработка изображения (только если GD доступен)
     */
    private function download_and_process_image($url)
    {
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] image get error: ' . $response->get_error_message() . ' (' . $url . ')');
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] image HTTP ' . $code . ' (' . $url . ')');
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (!$body) return false;

        return $this->process_image($body);
    }

    /**
     * Обработка изображения (только если GD доступен)
     */
    private function process_image($image_data)
    {
        // Дополнительная проверка на случай, если skip_processing не сработал
        if (!function_exists('imagecreatefromstring')) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] GD функции недоступны, возвращаем оригинальные данные');
            return $image_data;
        }
        
        $img = @imagecreatefromstring($image_data);
        if (!$img) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Не удалось создать изображение из данных');
            return $image_data;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Размер изображения: ' . $w . 'x' . $h);

        // Сначала ресайзим если нужно
        if ($w > $this->MAX_WIDTH) {
            $img = $this->resize_image($img, $w, $h);
            // Обновляем размеры после ресайза
            $w = imagesx($img);
            $h = imagesy($img);
        }

        // Затем обрезаем водяной знак
        $img = $this->crop_watermark($img, $w, $h);

        // Конвертируем в JPEG
        $result = $this->convert_to_jpeg($img);
        imagedestroy($img);

        return $result;
    }

    /**
     * Обрезка водяного знака (только если GD доступен)
     */
    private function crop_watermark($img, $w, $h)
    {
        if (!$img) return $img;
        
        // Вычисляем высоту обрезки (4% от высоты, но не менее 20px)
        $crop_pixels = max(20, (int)round($h * $this->CROP_PERCENT));
        $crop_h = max(0, $h - $crop_pixels);

        if ($crop_h > 0 && $crop_h < $h) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Обрезка водяного знака: ' . $crop_pixels . 'px снизу');
            
            // Определяем область обрезки
            $rect = ['x' => 0, 'y' => 0, 'width' => $w, 'height' => $crop_h];

            // Пытаемся использовать imagecrop если доступен
            if (function_exists('imagecrop')) {
                $cropped = imagecrop($img, $rect);
                if ($cropped !== false) {
                    imagedestroy($img);
                    return $cropped;
                }
            }
            
            // Если imagecrop не сработал, используем ручной метод
            return $this->manual_crop($img, $w, $h, $crop_h);
        }
        return $img;
    }

    /**
     * Ручная обрезка (только если GD доступен)
     */
    private function manual_crop($img, $w, $h, $crop_h)
    {
        if (!$img) return $img;
        
        // Создаем новое изображение
        $cropped = imagecreatetruecolor($w, $crop_h);
        if (!$cropped) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Ошибка создания canvas для обрезки');
            return $img;
        }

        // Заполняем фон белым
        $white = imagecolorallocate($cropped, 255, 255, 255);
        imagefilledrectangle($cropped, 0, 0, $w, $crop_h, $white);

        // Копируем часть изображения
        $success = imagecopy($cropped, $img, 0, 0, 0, 0, $w, $crop_h);

        if ($success) {
            imagedestroy($img);
            return $cropped;
        } else {
            imagedestroy($cropped);
            return $img;
        }
    }

    /**
     * Ресайз изображения (только если GD доступен)
     */
    private function resize_image($img, $w, $h)
    {
        if (!$img) return $img;
        
        $new_w = $this->MAX_WIDTH;
        $new_h = (int) round($h * ($new_w / $w));
        
        if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Ресайз с ' . $w . 'x' . $h . ' до ' . $new_w . 'x' . $new_h);
        
        $dst = imagecreatetruecolor($new_w, $new_h);
        if (!$dst) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Ошибка создания canvas для ресайза');
            return $img;
        }
        
        // Заполняем фон белым перед ресайзом
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        
        imageinterlace($dst, true);
        $success = imagecopyresampled($dst, $img, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
        imagedestroy($img);

        if (!$success) {
            imagedestroy($dst);
            return $img;
        }

        return $dst;
    }

    /**
     * Конвертация в JPEG (только если GD доступен)
     */
    private function convert_to_jpeg($img)
    {
        if (!$img) return '';
        
        ob_start();
        imagejpeg($img, null, $this->JPEG_QUALITY);
        $result = ob_get_clean();
        
        if (!$result || strlen($result) < 100) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Ошибка конвертации в JPEG');
            return '';
        }
        
        return $result;
    }

    /**
     * Создание вложения (работает с обоими методами)
     */
    private function create_attachment($post_id, $image_data, $url, $index)
    {
        if (!$image_data || strlen($image_data) < 100) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Пустые данные изображения');
            return false;
        }
        
        $tmp = wp_tempnam(basename($url));
        if (!$tmp) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Ошибка создания временного файла');
            return false;
        }

        $bytes_written = file_put_contents($tmp, $image_data);
        if ($bytes_written === false || $bytes_written < 100) {
            if (VIN_FS_DEBUG) error_log('[VIN Image Processor] Ошибка записи временного файла');
            @unlink($tmp);
            return false;
        }

        $file_array = [
            'name'     => 'vin-' . $post_id . '-' . ($index + 1) . '.jpg',
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => $bytes_written
        ];

        $att_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($att_id)) {
            if (VIN_FS_DEBUG) error_log('[VIN Fallback] media_handle_sideload error: ' . $att_id->get_error_message() . ' (' . $url . ')');
            @unlink($tmp);
            return false;
        }

        return $att_id;
    }
}
