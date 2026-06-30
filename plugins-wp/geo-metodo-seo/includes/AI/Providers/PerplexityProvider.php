<?php
namespace GeoMetodoSEO\AI\Providers;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIProviderInterface;
use GeoMetodoSEO\AI\AIResponse;
use GeoMetodoSEO\Config\ConfigManager;

class PerplexityProvider implements AIProviderInterface {

    public function generate($prompt, $model = null) {
        $apiKey = ConfigManager::get('perplexity_api_key');

        if (empty($apiKey)) {
            return new AIResponse('', [], 'Perplexity: API key não configurada');
        }

        // FIX Bug#2+#4: max_tokens dinâmico + timeout seguro + tratamento de erros HTTP
        $max_tokens = class_exists('\GeoMetodoSEO\AI\PromptSizeAnalyzer')
            ? \GeoMetodoSEO\AI\PromptSizeAnalyzer::calculate_max_tokens($prompt, 4096, 'perplexity')
            : 4096;

        $response = wp_remote_post('https://api.perplexity.ai/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode([
                'model'       => $model ?? ConfigManager::get('model_perplexity', 'sonar-pro'),
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'max_tokens'  => $max_tokens,
                'temperature' => 0.7,
                'top_p'       => 0.9,
            ]),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) {
            return new AIResponse('', [], 'Perplexity erro de rede: ' . $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body      = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code === 401 || $http_code === 403) {
            return new AIResponse('', $body ?? [], 'Perplexity: API key inválida (HTTP ' . $http_code . ')');
        }
        if ($http_code === 429) {
            $msg = $body['error']['message'] ?? 'rate limit atingido';
            return new AIResponse('', $body ?? [], 'Perplexity rate-limit (HTTP 429): ' . $msg);
        }
        if ($http_code >= 500) {
            return new AIResponse('', $body ?? [], 'Perplexity indisponível (HTTP ' . $http_code . ')');
        }
        if ($http_code >= 400) {
            $err = $body['error']['message'] ?? ('HTTP ' . $http_code);
            return new AIResponse('', $body ?? [], 'Perplexity erro: ' . $err);
        }

        $content = $body['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            $err = $body['error']['message'] ?? 'resposta vazia';
            return new AIResponse('', $body ?? [], 'Perplexity: ' . $err);
        }

        return new AIResponse($content, $body);
    }
}
