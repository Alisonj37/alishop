<?php
namespace GeoMetodoSEO\AI\Providers;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIProviderInterface;
use GeoMetodoSEO\AI\AIResponse;
use GeoMetodoSEO\Config\ConfigManager;

/**
 * GroqProvider — robusto e estável.
 *
 * BUGS CORRIGIDOS NA v1.0.0-STABILITY-FIX:
 * - max_tokens 4000 → 8000 (artigo 3000 palavras precisa ~4500 tokens output)
 * - temperature 0.7 (estável e criativo, sem alucinar estruturas vazias)
 * - top_p 0.9 (foco em tokens prováveis, reduz H2 vazios)
 * - presence_penalty 0.1 (estimula cobertura completa de cada seção)
 * - frequency_penalty 0.3 (evita repetição — reduz H2 só com keyword)
 * - HTTP 401/402/429/500/503 com mensagens claras
 * - detecção finish_reason='length' → avisa truncagem
 * - timeout 100s (modelo grande em artigo longo)
 */
class GroqProvider implements AIProviderInterface {

    public function generate($prompt, $model = null) {
        $apiKey = ConfigManager::get('groq_api_key');

        if (!$apiKey) {
            return new AIResponse('', [], 'Groq API key não configurada');
        }

        $model_to_use = $model ?? ConfigManager::get('model_groq', 'llama-3.3-70b-versatile');

        // FIX v1.0.0-GROQ: migração automática de modelos descontinuados.
        // Lista oficial validada pelo usuário (Maio/2026):
        //   - openai/gpt-oss-120b         : Premium, artigos avançados
        //   - llama-3.3-70b-versatile     : Produção estável (default)
        //   - openai/gpt-oss-20b          : Rápido, geração em massa
        //   - llama-3.1-8b-instant        : Ultra rápido, tarefas internas
        //   - groq/compound-mini          : Pesquisa rápida com tools
        //   - groq/compound               : Pesquisa avançada com tools
        //   - qwen/qwen3-32b              : Preview, JSON/multilíngue
        //   - meta-llama/llama-4-scout-17b-16e-instruct : Multimodal preview
        // Modelos antigos (mixtral, llama3-*, llama-4-maverick) foram descontinuados.
        $deprecated_groq_map = [
            'mixtral-8x7b-32768'                  => 'llama-3.3-70b-versatile',
            'llama3-70b-8192'                     => 'llama-3.3-70b-versatile',
            'llama3-8b-8192'                      => 'llama-3.1-8b-instant',
            'gemma-7b-it'                         => 'llama-3.1-8b-instant',
            'gemma2-9b-it'                        => 'llama-3.1-8b-instant',
            'llama-4-scout-17b-16e-instruct'      => 'meta-llama/llama-4-scout-17b-16e-instruct',
            'llama-4-maverick-17b-128e-instruct'  => 'llama-3.3-70b-versatile',
        ];
        if (isset($deprecated_groq_map[$model_to_use])) {
            $original = $model_to_use;
            $model_to_use = $deprecated_groq_map[$model_to_use];
            if (class_exists('\GeoMetodoSEO\Services\LogService')) {
                \GeoMetodoSEO\Services\LogService::record(
                    'ai', 'info',
                    "Groq: modelo {$original} descontinuado — migrando para {$model_to_use}",
                    ['action' => 'groq_model_migrated', 'context' => ['from' => $original, 'to' => $model_to_use]]
                );
            }
        }

        // FIX v1.0.0-DYNAMIC-TOKENS: max_tokens proporcional ao tamanho do artigo pedido.
        // Antes: hardcoded em 8000 → IA escrevia o que quisesse, ignorando "target_words" do prompt.
        // Agora: lê o tamanho do prompt e amarra max_tokens, forçando a IA a parar no tamanho certo.
        // E também aumenta timeout pra prompts grandes evitarem 504 Gateway Timeout.
        $max_tokens = class_exists('\GeoMetodoSEO\AI\PromptSizeAnalyzer')
            ? \GeoMetodoSEO\AI\PromptSizeAnalyzer::calculate_max_tokens($prompt, 8000, 'groq')
            : 8000;

        $body = [
            'model'             => $model_to_use,
            'messages'          => [['role' => 'user', 'content' => $prompt]],
            'max_tokens'        => $max_tokens,
            'temperature'       => 0.7,
            'top_p'             => 0.9,
            'presence_penalty'  => 0.1,
            'frequency_penalty' => 0.3,
            'stream'            => false,
        ];

        $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($body),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) {
            return new AIResponse('', [], 'Groq erro de rede: ' . $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body  = wp_remote_retrieve_body($response);
        $body      = json_decode($raw_body, true);

        if ($http_code === 401) {
            return new AIResponse('', $body ?? [], 'Groq API key inválida (HTTP 401) — verifique em console.groq.com');
        }
        if ($http_code === 402 || $http_code === 403) {
            return new AIResponse('', $body ?? [], 'Groq sem permissão ou cota (HTTP ' . $http_code . ')');
        }
        if ($http_code === 429) {
            $msg = $body['error']['message'] ?? 'rate limit atingido';
            return new AIResponse('', $body ?? [], 'Groq rate-limit (HTTP 429): ' . $msg);
        }
        if ($http_code === 500 || $http_code === 502 || $http_code === 503) {
            return new AIResponse('', $body ?? [], 'Groq indisponível (HTTP ' . $http_code . ') — tente novamente');
        }
        if ($http_code >= 400) {
            $err = $body['error']['message'] ?? ('HTTP ' . $http_code);
            return new AIResponse('', $body ?? [], 'Groq erro: ' . $err);
        }

        $content       = $body['choices'][0]['message']['content'] ?? '';
        $finish_reason = $body['choices'][0]['finish_reason'] ?? '';

        if (empty(trim($content))) {
            $err = $body['error']['message'] ?? 'resposta vazia sem conteúdo';
            return new AIResponse('', $body ?? [], 'Groq retornou: ' . $err);
        }

        // CRÍTICO: registrar se foi truncado por tamanho — auto-expand pode completar
        if ($finish_reason === 'length' && class_exists('\GeoMetodoSEO\Services\LogService')) {
            \GeoMetodoSEO\Services\LogService::record(
                'ai', 'warning',
                'Groq truncou resposta no max_tokens (8000). Artigo pode ter H2 incompletos.',
                ['action' => 'groq_truncated_output', 'context' => [
                    'model' => $model_to_use,
                    'finish_reason' => $finish_reason,
                    'output_length' => strlen($content),
                ]]
            );
        }

        return new AIResponse($content, $body ?? []);
    }
}
