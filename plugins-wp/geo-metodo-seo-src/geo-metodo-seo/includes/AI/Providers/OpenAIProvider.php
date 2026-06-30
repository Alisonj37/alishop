<?php
namespace GeoMetodoSEO\AI\Providers;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIProviderInterface;
use GeoMetodoSEO\AI\AIResponse;
use GeoMetodoSEO\Config\ConfigManager;

class OpenAIProvider implements AIProviderInterface {

    /**
     * Modelos que usam a Responses API com web_search_preview.
     * Esses modelos SEMPRE têm acesso à web — não precisam de configuração extra.
     */
    private const SEARCH_MODELS = [
        'gpt-4o-search-preview',
        'gpt-4o-mini-search-preview',
    ];

    /**
     * Modelos que usam Chat Completions API padrão.
     */
    private const CHAT_MODELS = [
        'gpt-5.5',
        'gpt-5.5-pro',
        'gpt-5.5-mini',
        'gpt-5.4',
        'gpt-5.4-mini',
        'gpt-5',
        'gpt-5-mini',
        'gpt-4.1',
        'gpt-4.1-mini',
        'gpt-4.1-nano',
        'gpt-4o',
        'gpt-4o-mini',
        'o3',
        'o3-mini',
        'o4-mini',
    ];

    /**
     * Cadeia de fallback segura. Mantém o plugin profissional sem quebrar
     * caso a conta/API ainda não tenha acesso ao modelo escolhido no painel.
     * 1.0.0: não rebaixa automaticamente para GPT-4.1; se GPT-5.x falhar, o erro fica visível.
     */
    private const FALLBACK_CHAT_MODELS = [
        'gpt-4.1',
        'gpt-4.1-mini',
        'gpt-4o-mini',
    ];

    public function generate($prompt, $model = null) {
        $apiKey = ConfigManager::get('openai_api_key');
        if (!$apiKey) {
            return new AIResponse('', [], 'API Key nao definida');
        }

        $model = $model ?? ConfigManager::get('model_openai', 'gpt-4.1');

        // Modelos de busca usam a Responses API
        if (in_array($model, self::SEARCH_MODELS, true)) {
            return $this->generateWithSearch($prompt, $model, $apiKey);
        }

        // Demais modelos: Chat Completions API padrão com fallback profissional
        return $this->generateChatWithFallback($prompt, $model, $apiKey);
    }

    /**
     * Chat Completions API com fallback de modelo.
     */
    private function generateChatWithFallback(string $prompt, string $model, string $apiKey): AIResponse {
        // 1.0.0 — fallback seguro por custo: nunca sobe para GPT-5.5/Pro se o usuário não pediu
        // e nunca tenta modelos premium quando o Controle de uso está bloqueando premium.
        $chain = $this->buildSafeFallbackChain($model);
        $last = null;

        foreach ($chain as $candidate) {
            $response = $this->generateChat($prompt, $candidate, $apiKey);
            if (!$response->hasError() && $response->getContent()) {
                if ($candidate !== $model && class_exists('GeoMetodoSEO\Services\LogService')) {
                    \GeoMetodoSEO\Services\LogService::record(
                        'ai', 'warning',
                        "OpenAI: modelo {$model} falhou; fallback usado: {$candidate}",
                        ['action' => 'model_fallback', 'context' => ['requested_model' => $model, 'fallback_model' => $candidate]]
                    );
                }
                return $response;
            }
            $last = $response;
            if (!$this->shouldTryModelFallback($response ? $response->getError() : '')) {
                break;
            }
        }

        return $last ?: new AIResponse('', [], 'OpenAI: nenhum modelo respondeu');
    }


    private function buildSafeFallbackChain(string $model): array {
        $m = strtolower($model);
        $chain = [$model];

        if (str_starts_with($m, 'gpt-5.5')) {
            $chain = [$model, 'gpt-5.4', 'gpt-5'];
        } elseif (str_starts_with($m, 'gpt-5')) {
            $chain = [$model, 'gpt-5-mini'];
        } elseif (str_starts_with($m, 'gpt-4.1')) {
            $chain = [$model, 'gpt-4.1-mini'];
        } elseif (str_starts_with($m, 'gpt-4o')) {
            $chain = [$model, 'gpt-4o-mini'];
        }

        return array_values(array_unique($chain));
    }

    private function shouldTryModelFallback(string $error): bool {
        $e = mb_strtolower($error);
        if ($e === '') return false;
        foreach (['model', 'not found', 'does not exist', 'unsupported', 'invalid', 'acesso', 'access', 'permission', 'rate limit'] as $needle) {
            if (str_contains($e, $needle)) return true;
        }
        return false;
    }

    /**
     * Chat Completions API — modelos padrão (GPT-4.1, GPT-5, o3, etc.)
     */
    private function generateChat(string $prompt, string $model, string $apiKey): AIResponse {
        // Modelos de raciocínio e família GPT-5 usam max_completion_tokens
        $is_reasoning = in_array($model, ['o3', 'o3-mini', 'o4-mini'], true) || str_starts_with($model, 'gpt-5');

        $body = [
            'model'    => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        $max_tokens = class_exists('\GeoMetodoSEO\AI\PromptSizeAnalyzer')
            ? \GeoMetodoSEO\AI\PromptSizeAnalyzer::calculate_max_tokens($prompt, 8000, 'openai')
            : 8000;

        if ($is_reasoning) {
            $body['max_completion_tokens'] = $max_tokens;
            // Modelos reasoning (o3, o4, gpt-5) não suportam temperature/top_p/penalties
        } else {
            $body['max_tokens']        = $max_tokens;
            $body['temperature']       = 0.7;   // estável + criativo
            $body['top_p']             = 0.9;   // foco em tokens prováveis
            $body['presence_penalty']  = 0.1;   // cobertura completa de cada H2
            $body['frequency_penalty'] = 0.3;   // evita repetições e H2 só com keyword
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) {
            return new AIResponse('', [], 'OpenAI erro de rede: ' . $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $data      = json_decode(wp_remote_retrieve_body($response), true);

        // Tratamento HTTP explícito (igual Claude, Naga, Perplexity, Gemini)
        if ($http_code === 401 || $http_code === 403) {
            return new AIResponse('', $data ?? [], 'OpenAI: API key inválida (HTTP ' . $http_code . ')');
        }
        if ($http_code === 402 || (isset($data['error']['code']) && $data['error']['code'] === 'insufficient_quota')) {
            return new AIResponse('', $data ?? [], 'OpenAI SEM CRÉDITOS — recarregue em platform.openai.com');
        }
        if ($http_code === 429) {
            $msg = $data['error']['message'] ?? 'rate limit';
            return new AIResponse('', $data ?? [], 'OpenAI rate-limit (HTTP 429): ' . $msg);
        }
        if ($http_code === 404 || (isset($data['error']['code']) && $data['error']['code'] === 'model_not_found')) {
            $msg = $data['error']['message'] ?? "Modelo {$model} não encontrado";
            return new AIResponse('', $data ?? [], 'OpenAI: ' . $msg);
        }
        if ($http_code >= 500) {
            return new AIResponse('', $data ?? [], 'OpenAI indisponível (HTTP ' . $http_code . ') — tente novamente');
        }
        if ($http_code >= 400) {
            $err = $data['error']['message'] ?? ('HTTP ' . $http_code);
            return new AIResponse('', $data ?? [], 'OpenAI erro: ' . $err);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';

        if (empty($content) && !empty($data['error']['message'])) {
            return new AIResponse('', $data, $data['error']['message']);
        }

        return new AIResponse($content, $data);
    }

    /**
     * Responses API com web_search_preview.
     * Retorna conteúdo com dados reais da web — elimina alucinações de fatos.
     * Modelos suportados: gpt-4o-search-preview, gpt-4o-mini-search-preview
     */
    private function generateWithSearch(string $prompt, string $model, string $apiKey): AIResponse {
        // FIX Bug#5: max_output_tokens dinâmico + timeout seguro
        $max_out = class_exists('\GeoMetodoSEO\AI\PromptSizeAnalyzer')
            ? \GeoMetodoSEO\AI\PromptSizeAnalyzer::calculate_max_tokens($prompt, 8000, 'openai')
            : 8000;

        $body = [
            'model'              => $model,
            'tools'              => [['type' => 'web_search_preview']],
            'input'              => $prompt,
            'max_output_tokens'  => $max_out,
        ];

        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) {
            return new AIResponse('', [], 'OpenAI Search erro de rede: ' . $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $data      = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code === 401 || $http_code === 403) {
            return new AIResponse('', $data ?? [], 'OpenAI Search: API key inválida (HTTP ' . $http_code . ')');
        }
        if ($http_code === 402 || (isset($data['error']['code']) && $data['error']['code'] === 'insufficient_quota')) {
            return new AIResponse('', $data ?? [], 'OpenAI Search SEM CRÉDITOS');
        }
        if ($http_code === 429) {
            $msg = $data['error']['message'] ?? 'rate limit';
            return new AIResponse('', $data ?? [], 'OpenAI Search rate-limit (HTTP 429): ' . $msg);
        }
        if ($http_code === 404) {
            return new AIResponse('', $data ?? [], 'OpenAI Search: modelo ' . $model . ' não encontrado');
        }
        if ($http_code >= 500) {
            return new AIResponse('', $data ?? [], 'OpenAI Search indisponível (HTTP ' . $http_code . ')');
        }
        if ($http_code >= 400) {
            $err = $data['error']['message'] ?? ('HTTP ' . $http_code);
            return new AIResponse('', $data ?? [], 'OpenAI Search erro: ' . $err);
        }

        // Responses API retorna output como array de items
        $content = '';
        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'message') {
                foreach ($item['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'output_text') {
                        $content .= $block['text'] ?? '';
                    }
                }
            }
        }

        if (empty($content) && !empty($data['error']['message'])) {
            return new AIResponse('', $data, $data['error']['message']);
        }

        return new AIResponse($content, $data);
    }
}
