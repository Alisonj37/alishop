<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * NagaImageService — Geração de imagens via naga.ac
 *
 * API compatível com OpenAI. Modelos gratuitos disponíveis.
 * Docs: https://docs.naga.ac
 *
 * @since 1.0.0
 */
class NagaImageService {

    private string $api_key;
    private string $base_url = 'https://api.naga.ac/v1';

    // Modelos de imagem — gratuitos primeiro
    public static array $models = [
        // ── GRATUITOS ──────────────────────────────
        'dall-e-3:free'       => '⭐ DALL-E 3 (GRÁTIS)',
        'flux-1-schnell:free' => '⚡ Flux 1 Schnell (GRÁTIS)',
        'sdxl:free'           => '🎨 SDXL (GRÁTIS)',
        // ── PAGOS (muito baratos) ───────────────────
        'flux-1-schnell'      => 'Flux Schnell (~$0.0015/img)',
        'sdxl'                => 'SDXL (~$0.0025/img)',
        'kandinsky-3.1'       => 'Kandinsky 3.1 (~$0.005/img)',
        'dall-e-3'            => 'DALL-E 3 (~$0.04/img)',
        'dall-e-2'            => 'DALL-E 2 (~$0.02/img)',
    ];

    public function __construct() {
        $this->api_key = get_option('geo_naga_api_key', '') ?: get_option('autopilot_naga_api_key', '');
    }

    public function isConfigured(): bool {
        return !empty($this->api_key);
    }

    /**
     * Gerar imagem via naga.ac
     *
     * @param string $prompt  Descrição da imagem
     * @param string $size    '1024x1024' | '720x1280' | '1792x1024'
     * @param string $model   ID do modelo (vazio = usa configuração salva)
     * @return string|false   URL da imagem ou false em caso de erro
     */
    public function generate(string $prompt, string $size = '1024x1024', string $model = '') {
        if (!$this->isConfigured()) return false;

        $model = $model ?: get_option('geo_naga_model', 'dall-e-3:free');

        // Normalizar tamanho por modelo
        $size = $this->normalize_size($size, $model);

        $body = [
            'model'           => $model,
            'prompt'          => $prompt,
            'n'               => 1,
            'size'            => $size,
            'response_format' => 'url',
        ];

        // DALL-E 3 suporta quality
        if (strpos($model, 'dall-e-3') !== false) {
            $body['quality'] = 'standard';
        }

        $response = wp_remote_post($this->base_url . '/images/generations', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            LogService::log('error', 'NagaImageService: ' . $response->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code === 401 || $code === 403) {
            LogService::log('error', "NagaImageService ({$model}): API key invalida ou sem permissao (HTTP {$code})");
            return false;
        }

        if ($code === 402 || stripos((string)($data['error']['message'] ?? ''), 'credit') !== false || stripos((string)($data['error']['type'] ?? ''), 'insufficient') !== false) {
            LogService::log('error', "NagaImageService ({$model}): sem creditos/saldo insuficiente");
            return false;
        }

        if ($code === 429) {
            LogService::log('warning', "NagaImageService ({$model}): rate limit HTTP 429");
            return false;
        }

        if ($code < 200 || $code >= 300) {
            $error = $data['error']['message'] ?? "HTTP {$code}";
            LogService::log('error', "NagaImageService ({$model}): {$error}");
            return false;
        }

        $url  = $data['data'][0]['url'] ?? '';

        if (empty($url)) {
            $error = $data['error']['message'] ?? 'sem URL na resposta';
            LogService::log('error', "NagaImageService ({$model}): {$error}");
            return false;
        }

        LogService::log('info', "NagaImageService: imagem gerada com {$model}");
        return $url;
    }

    /**
     * Normalizar tamanho para o modelo.
     * DALL-E 3: 1024x1024, 1792x1024, 1024x1792
     * DALL-E 2: 256x256, 512x512, 1024x1024
     * Flux/SDXL: passam como estão
     */
    private function normalize_size(string $size, string $model): string {
        $is_dalle2 = strpos($model, 'dall-e-2') !== false;
        $is_dalle3 = strpos($model, 'dall-e-3') !== false;

        if ($is_dalle2) {
            return '512x512';
        }

        if ($is_dalle3) {
            // Portrait 9:16 para Web Stories
            if ($size === '720x1280' || $size === '9:16') return '1024x1792';
            // Landscape
            if ($size === '1792x1024' || $size === '16:9') return '1792x1024';
            return '1024x1024';
        }

        // Flux, SDXL e outros aceitam dimensões livres
        if ($size === '720x1280') return '720x1280';
        return '1024x1024';
    }

    /**
     * Testar conexão e API key.
     */
    public function test(): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'API Key não configurada'];
        }

        $response = wp_remote_get($this->base_url . '/models', [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer ' . $this->api_key],
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        return [
            'ok'      => $code === 200,
            'message' => $code === 200 ? '✅ Conexão OK' : "HTTP {$code}",
        ];
    }
}
