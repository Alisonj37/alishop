<?php
namespace GeoMetodoSEO\AI\Providers;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIProviderInterface;
use GeoMetodoSEO\AI\AIResponse;
use GeoMetodoSEO\Config\ConfigManager;

class ClaudeProvider implements AIProviderInterface {

    public function generate($prompt, $model = null) {
        $apiKey = ConfigManager::get('claude_api_key');

        if (empty($apiKey)) {
            return new AIResponse('', [], 'Claude API key não configurada');
        }

        // Modelos Claude 4.x válidos (IDs reais da API Anthropic)
        // claude-sonnet-4-6 = Sonnet 4.6 (lançado Fev/2026, $3/$15 por MTok)
        // claude-sonnet-4-5 = Sonnet 4.5 (anterior, mesmo preço)
        // claude-3-5-sonnet-latest = alias sempre aponta pra versão mais recente
        // Modelos Claude série 4.x — atuais e suportados.
        // claude-sonnet-4-6 = Sonnet 4.6 (padrão, melhor custo-benefício)
        // claude-opus-4-7   = Opus 4.7 (mais capaz, mais caro)
        // claude-haiku-4-5  = Haiku 4.5 (mais rápido e econômico)
        $valid_models = [
            'claude-sonnet-4-6',
            'claude-sonnet-4-5',
            'claude-sonnet-4-5-20250929',
            'claude-opus-4-7',
            'claude-opus-4-6',
            'claude-haiku-4-5',
            'claude-haiku-4-5-20251001',
        ];
        $model_to_use = $model ?? ConfigManager::get('model_claude', 'claude-sonnet-4-6');
        // Se não for um modelo reconhecido, usa Sonnet 4.6 como fallback seguro
        if (!in_array($model_to_use, $valid_models, true) && !str_starts_with((string)$model_to_use, 'claude-')) {
            $model_to_use = 'claude-sonnet-4-6';
        }
        // Migrar modelos 3.5 descontinuados automaticamente para 4.x
        if (str_starts_with((string)$model_to_use, 'claude-3-5-sonnet') || str_starts_with((string)$model_to_use, 'claude-3-opus')) {
            $model_to_use = 'claude-sonnet-4-6';
        } elseif (str_starts_with((string)$model_to_use, 'claude-3-5-haiku') || str_starts_with((string)$model_to_use, 'claude-3-haiku')) {
            $model_to_use = 'claude-haiku-4-5';
        }
        // FIX v1.0.0-DYNAMIC-TOKENS: max_tokens proporcional ao tamanho pedido.
        $max_tokens = class_exists('\GeoMetodoSEO\AI\PromptSizeAnalyzer')
            ? \GeoMetodoSEO\AI\PromptSizeAnalyzer::calculate_max_tokens($prompt, 8000, 'claude')
            : 8000;
        // Timeout 120s: acomoda artigos grandes (large = 3500 palavras / ~5600 tokens).
        // Claude gera ~55 tokens/s, então um artigo large leva ~100s.
        // Se o seu servidor tiver fastcgi_read_timeout ou max_execution_time menor que 120s,
        // aumente esses valores no servidor para evitar cURL error 28.
        $timeout = 120;

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body'    => json_encode([
                'model'       => $model_to_use,
                'max_tokens'  => $max_tokens,
                'temperature' => 0.7,
                // NOTA: A API Anthropic NÃO aceita temperature e top_p simultaneamente.
                // Usar apenas temperature. top_p removido para evitar erro 400.
                'messages'    => [['role' => 'user', 'content' => $prompt]],
            ]),
            'timeout' => $timeout,
        ]);

        if (is_wp_error($response)) {
            return new AIResponse('', [], 'Claude erro de rede: ' . $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body  = wp_remote_retrieve_body($response);
        $body      = json_decode($raw_body, true);

        // Erros HTTP com mensagem clara
        if ($http_code === 401) {
            return new AIResponse('', $body ?? [], 'Claude API key inválida ou expirada (HTTP 401)');
        }
        if ($http_code === 403) {
            return new AIResponse('', $body ?? [], 'Claude sem permissão (HTTP 403) — verifique a API key');
        }
        if ($http_code === 402 || (isset($body['error']['type']) && str_contains((string)($body['error']['type'] ?? ''), 'credit'))) {
            return new AIResponse('', $body ?? [], 'Claude SEM CRÉDITOS — recarregue em console.anthropic.com');
        }
        if ($http_code === 529 || $http_code === 503) {
            return new AIResponse('', $body ?? [], 'Claude sobrecarregado (HTTP ' . $http_code . ') — tente novamente');
        }
        if ($http_code === 429) {
            $retry_msg = isset($body['error']['message']) ? ': ' . $body['error']['message'] : '';
            return new AIResponse('', $body ?? [], 'Claude rate-limit (HTTP 429)' . $retry_msg);
        }
        if ($http_code >= 400) {
            $err = $body['error']['message'] ?? ('HTTP ' . $http_code);
            return new AIResponse('', $body ?? [], 'Claude erro: ' . $err);
        }

        $content = $body['content'][0]['text'] ?? '';

        if (empty(trim($content))) {
            // Tenta extrair mensagem de erro mesmo em HTTP 200
            $err_msg = $body['error']['message'] ?? 'resposta vazia sem conteúdo';
            return new AIResponse('', $body ?? [], 'Claude retornou: ' . $err_msg);
        }

        return new AIResponse($content, $body ?? []);
    }
}
