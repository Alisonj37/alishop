<?php
namespace GeoMetodoSEO\AI\Providers;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIProviderInterface;
use GeoMetodoSEO\AI\AIResponse;

/**
 * NagaProvider — Provedor de texto via naga.ac
 *
 * API compatível com OpenAI. Acesso a 220+ modelos com preços reduzidos.
 * Modelos gratuitos: gemini-2.5-flash:free, glm-4.5-air:free, llama-3.1-8b-instruct:free
 * Modelos pagos com 50% desconto: GPT-5.4, Gemini 2.5 Flash, Claude, etc.
 *
 * O usuário usa naga.ac para Gemini (gratuito), mantendo a API OpenAI separada.
 *
 * @since 1.0.0
 */
class NagaProvider implements AIProviderInterface {

    private string $base_url = 'https://api.naga.ac/v1';

    // Modelos de texto disponíveis
    public static array $models = [
        // ── GRATUITOS ──────────────────────────────────────────────
        'gemini-2.5-flash:free'       => '⭐ Gemini 2.5 Flash (GRÁTIS)',
        'glm-4.5-air:free'            => '✅ GLM 4.5 Air (GRÁTIS)',
        'llama-3.1-8b-instruct:free'  => '✅ Llama 3.1 8B (GRÁTIS)',
        // ── PAGOS COM DESCONTO ──────────────────────────────────────
        'gpt-5.4'                     => 'GPT-5.4 (-50%)',
        'gemini-2.5-flash'            => 'Gemini 2.5 Flash (-50%)',
        'gpt-4o-mini-2024-07-18'      => 'GPT-4o Mini (-50%)',
        'claude-sonnet-4-5'           => 'Claude Sonnet 4.5 (-50%)',
    ];

    public function generate($prompt, $model = null) {
        $api_key = get_option('geo_naga_api_key', '') ?: get_option('autopilot_naga_api_key', '');

        if (empty($api_key)) {
            return new AIResponse('', [], 'Naga.ac: API Key não configurada');
        }

        $model = $model ?: get_option('geo_naga_text_model', 'gemini-2.5-flash:free');

        // FIX Bug#1+#3: max_tokens dinâmico (igual aos outros providers) + timeout seguro
        $max_tokens = class_exists('\GeoMetodoSEO\AI\PromptSizeAnalyzer')
            ? \GeoMetodoSEO\AI\PromptSizeAnalyzer::calculate_max_tokens($prompt, 4096, 'naga')
            : 4096;

        $response = wp_remote_post($this->base_url . '/chat/completions', [
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'max_tokens'  => $max_tokens,
                'temperature' => 0.7,
                'top_p'       => 0.9,
            ]),
        ]);

        if (is_wp_error($response)) {
            return new AIResponse('', [], 'Naga.ac erro de rede: ' . $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $data      = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code === 401 || $http_code === 403) {
            return new AIResponse('', $data ?? [], 'Naga.ac: API key inválida (HTTP ' . $http_code . ')');
        }
        if ($http_code === 429) {
            $msg = $data['error']['message'] ?? 'rate limit atingido';
            return new AIResponse('', $data ?? [], 'Naga.ac rate-limit (HTTP 429): ' . $msg);
        }
        if ($http_code >= 500) {
            return new AIResponse('', $data ?? [], 'Naga.ac indisponível (HTTP ' . $http_code . ')');
        }
        if ($http_code >= 400) {
            $err = $data['error']['message'] ?? ('HTTP ' . $http_code);
            return new AIResponse('', $data ?? [], 'Naga.ac erro: ' . $err);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            $err = $data['error']['message'] ?? 'resposta vazia';
            return new AIResponse('', $data ?? [], 'Naga.ac: ' . $err);
        }

        return new AIResponse($content, $data);
    }
}
