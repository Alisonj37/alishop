<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) exit;

/**
 * SafeImageSideload centralizes remote image imports.
 *
 * It keeps DALL-E via Naga.ac intact while preventing oversized or non-image
 * payloads from being passed directly to media_handle_sideload().
 */
class SafeImageSideload {

    public const DEFAULT_MAX_BYTES = 8388608; // 8 MB

    private static string $last_error = '';

    public static function last_error(): string {
        return self::$last_error;
    }

    public static function attachment_id(string $url, int $post_id = 0, string $title = '', int $max_bytes = self::DEFAULT_MAX_BYTES): int {
        self::$last_error = '';
        $url = esc_url_raw(trim($url));

        if (!$url || !wp_http_validate_url($url)) {
            self::$last_error = 'URL de imagem invalida.';
            return 0;
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            self::$last_error = $tmp->get_error_message();
            return 0;
        }

        $size = file_exists($tmp) ? (int) filesize($tmp) : 0;
        if ($size <= 0 || $size > $max_bytes) {
            @unlink($tmp);
            self::$last_error = 'Arquivo vazio ou maior que o limite permitido.';
            return 0;
        }

        $mime = function_exists('mime_content_type') ? (string) mime_content_type($tmp) : '';
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];

        if ($mime && !isset($allowed[$mime])) {
            @unlink($tmp);
            self::$last_error = 'MIME nao permitido: ' . $mime;
            return 0;
        }

        $path_ext = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $ext = $allowed[$mime] ?? '';
        if (!$ext && in_array($path_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $ext = $path_ext === 'jpeg' ? 'jpg' : $path_ext;
        }
        if (!$ext) {
            $ext = 'jpg';
        }

        $name_base = sanitize_title($title ?: 'geo-image');
        if ($name_base === '') {
            $name_base = 'geo-image';
        }

        // Nome único de verdade: wp_unique_id() reinicia a cada request e causava
        // colisão quando 10+ slides de Web Story faziam sideload no mesmo request
        // ("Não foi possível inserir o anexo no banco de dados").
        // uniqid(true) + random garante unicidade mesmo em loops rápidos.
        $unique = uniqid('', true) . '-' . wp_rand(1000, 9999);
        $unique = str_replace('.', '', $unique);

        $file = [
            'name'     => sanitize_file_name($name_base . '-' . $unique . '.' . $ext),
            'tmp_name' => $tmp,
        ];

        $check = wp_check_filetype_and_ext($tmp, $file['name'], array_flip($allowed));
        if (empty($check['type']) || !isset($allowed[$check['type']])) {
            @unlink($tmp);
            self::$last_error = 'Extensao ou tipo de arquivo reprovado pelo WordPress.';
            return 0;
        }

        $attachment_id = media_handle_sideload($file, $post_id, sanitize_text_field($title));
        if (is_wp_error($attachment_id)) {
            // media_handle_sideload falhou (erro comum: "Não foi possível inserir o
            // anexo no banco de dados", frequente em Web Stories com muitas imagens
            // no mesmo request). Fallback: inserir o attachment manualmente.
            $manual_id = self::manual_sideload($tmp, $file['name'], $check['type'], $post_id, $title);
            @unlink($tmp);
            if ($manual_id > 0) {
                return $manual_id;
            }
            self::$last_error = $attachment_id->get_error_message();
            return 0;
        }

        return (int) $attachment_id;
    }

    /**
     * Sideload manual — usado quando media_handle_sideload falha.
     * Move o arquivo para uploads e cria o attachment diretamente, sem depender
     * da função de alto nível que estava falhando em lote nas Web Stories.
     */
    private static function manual_sideload(string $tmp, string $filename, string $mime, int $post_id, string $title): int {
        $upload = wp_upload_bits($filename, null, file_get_contents($tmp));
        if (!empty($upload['error']) || empty($upload['file'])) {
            return 0;
        }
        $file_path = $upload['file'];
        $file_url  = $upload['url'];

        $attachment = [
            'guid'           => $file_url,
            'post_mime_type' => $mime,
            'post_title'     => sanitize_text_field($title ?: pathinfo($filename, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $file_path, $post_id, true);
        if (is_wp_error($attach_id) || !$attach_id) {
            @unlink($file_path);
            return 0;
        }

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        try {
            $meta = wp_generate_attachment_metadata($attach_id, $file_path);
            if (is_array($meta)) {
                wp_update_attachment_metadata($attach_id, $meta);
            }
        } catch (\Throwable $e) {
            // metadados são opcionais — a imagem já está salva
        }
        return (int) $attach_id;
    }

    public static function url(string $url, int $post_id = 0, string $title = '', int $max_bytes = self::DEFAULT_MAX_BYTES): string {
        $id = self::attachment_id($url, $post_id, $title, $max_bytes);
        return $id ? (string) wp_get_attachment_url($id) : '';
    }
}
