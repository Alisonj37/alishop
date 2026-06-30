<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) exit;

/**
 * Provider HuggingFace para FLUX.1-schnell.
 */
class HuggingFaceImageService {

    private string $endpoint = 'https://api-inference.huggingface.co/models/black-forest-labs/FLUX.1-schnell';

    public function is_configured(): bool {
        return (bool) get_option('geo_huggingface_api_key', '');
    }

    public function generate(string $prompt, string $aspect_ratio = '9:16') {
        $api_key = trim((string)get_option('geo_huggingface_api_key', ''));
        if ($api_key === '') {
            return new \WP_Error('geo_hf_missing_key', 'HuggingFace nao configurado.');
        }

        $response = $this->request($api_key, $prompt, $aspect_ratio);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));

        if ($code < 200 || $code >= 300) {
            $data = json_decode($body, true);
            $message = is_array($data) ? ($data['error'] ?? 'HTTP ' . $code) : ('HTTP ' . $code);
            return new \WP_Error('geo_hf_http_error', sanitize_text_field((string)$message));
        }

        if (strpos($content_type, 'image/') === false || $body === '') {
            return new \WP_Error('geo_hf_invalid_response', 'HuggingFace nao retornou binario de imagem.');
        }

        return $this->save_binary_to_library($body, $content_type, 'huggingface-flux');
    }

    public function test(): array {
        if (!$this->is_configured()) {
            return ['ok' => false, 'message' => 'HuggingFace API Key nao configurada'];
        }
        $result = $this->generate('Simple blue circle on a white background, no text, no logo', '1:1');
        return [
            'ok' => !is_wp_error($result) && is_string($result) && $result !== '',
            'message' => is_wp_error($result) ? $result->get_error_message() : 'Conexao OK',
        ];
    }

    private function request(string $api_key, string $prompt, string $aspect_ratio) {
        return wp_remote_post($this->endpoint, [
            'timeout' => 8,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'image/png',
            ],
            'body' => wp_json_encode([
                'inputs' => $prompt,
                'parameters' => [
                    'num_inference_steps' => 4,
                    'guidance_scale' => 0,
                    'width' => $aspect_ratio === '9:16' ? 720 : 1024,
                    'height' => $aspect_ratio === '9:16' ? 1280 : 1024,
                ],
            ]),
        ]);
    }

    private function save_binary_to_library(string $binary, string $content_type, string $title) {
        if (!function_exists('wp_upload_bits')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $ext = strpos($content_type, 'jpeg') !== false ? 'jpg' : 'png';
        $filename = sanitize_file_name($title . '-' . wp_unique_id() . '.' . $ext);
        $upload = wp_upload_bits($filename, null, $binary);
        if (!empty($upload['error'])) {
            return new \WP_Error('geo_hf_upload_failed', (string)$upload['error']);
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $content_type,
            'post_title' => sanitize_text_field($title),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file'], 0);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        $meta = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $meta);

        return (string) wp_get_attachment_url((int)$attachment_id);
    }
}
