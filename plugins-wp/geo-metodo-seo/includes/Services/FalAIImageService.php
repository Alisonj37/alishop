<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) exit;

/**
 * Provider Fal.ai — DUAL MODEL.
 *
 * v1.0.0 REFATORAÇÃO COMPLETA:
 *   - REMOVIDO: gpt-image-2 (lento, 30-90s, sempre dava timeout)
 *   - NOVO featured: fal-ai/flux-2-pro (qualidade master, 2-4s, $0.03/MP)
 *   - NOVO body: fal-ai/flux/schnell (rapidíssimo, 1-2s, $0.003/MP)
 *
 * Ambos modelos rodam SÍNCRONO via fal.run (terminam em segundos, sem queue).
 * Timeout HTTP 45s é suficiente com folga.
 *
 * @since 1.0.0
 * @since 1.0.0 refatoração — gpt-image-2 substituído por Flux-2-Pro/Schnell
 */
class FalAIImageService {

    // Endpoints SÍNCRONOS — modelos Flux são rápidos, não precisam de queue async
    private const ENDPOINT_FEATURED = 'https://fal.run/fal-ai/flux-2-pro';
    private const ENDPOINT_BODY     = 'https://fal.run/fal-ai/flux/schnell';

    // Timeout HTTP — Flux-2-Pro: 4-6s típico, Schnell: 1-2s típico. 45s dá folga absurda.
    private const HTTP_TIMEOUT = 45;

    public function is_configured(): bool {
        return (bool) get_option('geo_falai_api_key', '');
    }

    /**
     * Gera imagem com escolha automática de modelo baseada em $quality.
     *
     * @param string $prompt   Prompt visual em inglês
     * @param string $size     Tamanho/aspect (1792x1024, 16:9, etc)
     * @param string $quality  'high'|'medium'|'low' — high usa Flux-2-Pro, medium/low usa Schnell
     * @return string|WP_Error URL da imagem ou erro
     */
    public function generate(string $prompt, string $size = '1792x1024', string $quality = 'high') {
        $api_key = trim((string) get_option('geo_falai_api_key', ''));
        if ($api_key === '') {
            return new \WP_Error('geo_falai_missing_key', 'Fal.ai nao configurado.');
        }

        // ROUTING: high quality → Flux-2-Pro (featured) | medium/low → Schnell (body)
        $quality = sanitize_key($quality ?: 'high');
        if ($quality === 'high') {
            return $this->generate_flux2_pro($api_key, $prompt, $size);
        }
        return $this->generate_flux_schnell($api_key, $prompt, $size);
    }

    /**
     * Atalho explícito pra Flux-2-Pro (featured).
     * @since 1.0.0
     */
    public function generate_flux2_pro_featured(string $prompt, string $size = '1792x1024') {
        $api_key = trim((string) get_option('geo_falai_api_key', ''));
        if ($api_key === '') {
            return new \WP_Error('geo_falai_missing_key', 'Fal.ai nao configurado.');
        }
        return $this->generate_flux2_pro($api_key, $prompt, $size);
    }

    /**
     * Atalho explícito pra Flux Schnell (body).
     * @since 1.0.0
     */
    public function generate_flux_schnell_body(string $prompt, string $size = '1792x1024') {
        $api_key = trim((string) get_option('geo_falai_api_key', ''));
        if ($api_key === '') {
            return new \WP_Error('geo_falai_missing_key', 'Fal.ai nao configurado.');
        }
        return $this->generate_flux_schnell($api_key, $prompt, $size);
    }

    /**
     * Teste de conectividade — usa Schnell (mais rápido pra teste).
     */
    public function test(): array {
        if (!$this->is_configured()) {
            return ['ok' => false, 'message' => 'Fal.ai API Key nao configurada'];
        }
        $api_key = trim((string) get_option('geo_falai_api_key', ''));
        $result = $this->generate_flux_schnell($api_key, 'Simple blue circle on white background, no text, no logo', '1024x1024');
        return [
            'ok' => !is_wp_error($result) && is_string($result) && $result !== '',
            'message' => is_wp_error($result) ? $result->get_error_message() : 'Conexao OK (Flux Schnell)',
        ];
    }

    /**
     * FLUX-2-PRO — usado pra featured image (qualidade master).
     * Tempo típico: 2-4 segundos.
     * Custo: $0.03/MP (~$0.05 por imagem 1792x1024).
     *
     * Endpoint: https://fal.run/fal-ai/flux-2-pro
     * Body: { "prompt", "image_size", "num_images", "output_format", "safety_tolerance" }
     */
    private function generate_flux2_pro(string $api_key, string $prompt, string $size) {
        $body = [
            'prompt'                 => $prompt,
            'image_size'             => $this->normalize_size($size),
            'num_images'             => 1,
            'output_format'          => 'jpeg',
            'safety_tolerance'       => '2',
            'enable_safety_checker'  => true,
        ];

        $start = microtime(true);
        $response = wp_remote_post(self::ENDPOINT_FEATURED, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'Authorization' => 'Key ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        return $this->handle_response($response, 'flux-2-pro', $size, $start);
    }

    /**
     * FLUX SCHNELL — usado pra body images (rapidíssimo).
     * Tempo típico: 1-2 segundos.
     * Custo: $0.003/MP (~$0.005 por imagem 1792x1024).
     *
     * Endpoint: https://fal.run/fal-ai/flux/schnell
     * Body: { "prompt", "image_size", "num_images", "num_inference_steps", "enable_safety_checker" }
     */
    private function generate_flux_schnell(string $api_key, string $prompt, string $size) {
        $body = [
            'prompt'                => $prompt,
            'image_size'            => $this->normalize_size($size),
            'num_images'            => 1,
            'num_inference_steps'   => 4,         // Schnell roda em 4 steps
            'enable_safety_checker' => true,
            'output_format'         => 'jpeg',
        ];

        $start = microtime(true);
        $response = wp_remote_post(self::ENDPOINT_BODY, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'Authorization' => 'Key ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        return $this->handle_response($response, 'flux-schnell', $size, $start);
    }

    /**
     * Tratamento unificado de resposta com erros DETALHADOS (críticos pra debug).
     * @since 1.0.0
     */
    private function handle_response($response, string $model_label, string $size, float $start) {
        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            // Diagnóstico específico de timeout
            if (strpos($err_msg, 'cURL error 28') !== false || strpos($err_msg, 'timed out') !== false) {
                return new \WP_Error(
                    'geo_falai_timeout',
                    "Fal.ai {$model_label} timeout: servidor Fal.ai sobrecarregado ou rede lenta. Tente novamente."
                );
            }
            return new \WP_Error('geo_falai_network', "Fal.ai {$model_label} erro de rede: " . $err_msg);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        // Erros HTTP categorizados com mensagens úteis pro admin
        if ($code === 401 || $code === 403) {
            return new \WP_Error(
                'geo_falai_unauthorized',
                "Fal.ai HTTP {$code}: API key invalida ou expirada. Confira em fal.ai/dashboard/keys"
            );
        }
        if ($code === 402) {
            return new \WP_Error(
                'geo_falai_no_credits',
                'Fal.ai SEM CREDITOS - recarregue em fal.ai/dashboard/billing'
            );
        }
        if ($code === 429) {
            return new \WP_Error(
                'geo_falai_rate_limit',
                "Fal.ai rate-limit (HTTP 429) em {$model_label}. Aguarde alguns segundos."
            );
        }
        if ($code === 422) {
            // Erro de validação Fal.ai geralmente vem em $data['detail']
            $detail = $data['detail'] ?? 'parametros invalidos';
            return new \WP_Error(
                'geo_falai_validation',
                "Fal.ai validacao falhou: " . $this->normalize_error_message($detail)
            );
        }
        if ($code >= 500) {
            return new \WP_Error(
                'geo_falai_server_error',
                "Fal.ai servidor com problema (HTTP {$code}) em {$model_label}. Tente novamente."
            );
        }
        if ($code < 200 || $code >= 300) {
            $message = $data['detail'] ?? $data['error']['message'] ?? ('HTTP ' . $code);
            return new \WP_Error(
                'geo_falai_http_error',
                "Fal.ai {$model_label} erro: " . $this->normalize_error_message($message)
            );
        }

        // Resposta OK — extrai URL
        $url = $this->extract_url(is_array($data) ? $data : []);
        if ($url === '') {
            return new \WP_Error(
                'geo_falai_empty_url',
                "Fal.ai {$model_label}: resposta OK mas sem URL de imagem. Body: " . substr($raw, 0, 200)
            );
        }

        $duration = (int) round((microtime(true) - $start) * 1000);
        LogService::record('falai', 'success', "Imagem gerada via {$model_label}", [
            'action' => 'generate_image',
            'duration_ms' => $duration,
            'context' => ['model' => $model_label, 'size' => $size],
        ]);

        return $url;
    }

    private function normalize_error_message($message): string {
        if (is_array($message) || is_object($message)) {
            $encoded = wp_json_encode($message, JSON_UNESCAPED_UNICODE);
            return sanitize_text_field($encoded ?: 'Fal.ai retornou erro em formato inesperado.');
        }
        $message = trim((string) $message);
        return sanitize_text_field($message !== '' ? $message : 'Fal.ai retornou erro sem mensagem.');
    }

    /**
     * Normaliza tamanho pro formato aceito pelo Fal.ai (image_size).
     * Aceita: 1024x1024, 1792x1024, 16:9, 9:16, etc.
     */
    private function normalize_size(string $size): string {
        // Aspect ratios → strings nominais do Fal
        if ($size === '1024x1024' || $size === '1:1') return 'square';
        if ($size === '16:9' || $size === '1792x1024') return 'landscape_16_9';
        if ($size === '9:16' || $size === '1024x1792' || $size === '720x1280') return 'portrait_16_9';
        if ($size === '4:3') return 'landscape_4_3';
        if ($size === '3:4') return 'portrait_4_3';
        if ($size === 'square_hd') return 'square_hd';

        // Já é um nome válido?
        $allowed = ['square_hd', 'square', 'portrait_4_3', 'portrait_16_9', 'landscape_4_3', 'landscape_16_9', 'auto'];
        return in_array($size, $allowed, true) ? $size : 'landscape_16_9';
    }

    /**
     * Extrai URL da resposta — Flux retorna `data.images[0].url`.
     */
    private function extract_url(array $data): string {
        if (!empty($data['images'][0]['url'])) return esc_url_raw((string) $data['images'][0]['url']);
        if (!empty($data['data'][0]['url']))   return esc_url_raw((string) $data['data'][0]['url']);
        if (!empty($data['url']))              return esc_url_raw((string) $data['url']);
        if (!empty($data['image']['url']))     return esc_url_raw((string) $data['image']['url']);
        return '';
    }
}
