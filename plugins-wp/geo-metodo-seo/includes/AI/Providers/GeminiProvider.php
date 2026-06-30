<?php
namespace GeoMetodoSEO\AI\Providers;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIProviderInterface;
use GeoMetodoSEO\AI\AIResponse;
use GeoMetodoSEO\Config\ConfigManager;

class GeminiProvider implements AIProviderInterface {

    public function generate($prompt, $model = null) {
        $apiKey = ConfigManager::get('gemini_api_key');

        if (empty($apiKey)) {
            return new AIResponse('', [], 'Gemini API key não configurada');
        }

        // DEFAULT: gemini-2.5-flash-lite — estável, gratuito, boa cota
        // Modelos válidos na API do Google (Mai/2026):
        //   - gemini-2.5-flash-lite : estável GA, gratuito ✅ recomendado
        //   - gemini-2.5-flash      : estável, gratuito ✅
        //   - gemini-3.1-flash-lite : lançado Mar/2026, gratuito ✅ (novo)
        //   - gemini-3.1-pro-preview: pago
        $model_name = $model ?? ConfigManager::get('model_gemini', 'gemini-2.5-flash-lite');

        // Migração automática: modelos inválidos, encerrados ou sem cota gratuita
        $deprecated_map = [
            // Modelos encerrados
            'gemini-2.0-flash'          => 'gemini-2.5-flash-lite',
            'gemini-2.0-flash-lite'     => 'gemini-2.5-flash-lite',
            'gemini-2.0-flash-001'      => 'gemini-2.5-flash-lite',
            'gemini-2.0-flash-lite-001' => 'gemini-2.5-flash-lite',
            'gemini-1.5-pro'            => 'gemini-2.5-flash-lite',
            'gemini-1.5-flash'          => 'gemini-2.5-flash-lite',
            // Pro — sem cota gratuita
            'gemini-2.5-pro'            => 'gemini-2.5-flash-lite',
            'gemini-2.5-pro-preview'    => 'gemini-2.5-flash-lite',
            'gemini-3.1-pro'            => 'gemini-2.5-flash-lite',
            'gemini-3.1-pro-preview'    => 'gemini-2.5-flash-lite',
            'gemini-3-pro-preview'      => 'gemini-2.5-flash-lite',
            // TYPOS — gemini-3-flash NÃO EXISTE (correto: gemini-3.1-flash-lite)
            'gemini-3-flash'            => 'gemini-3.1-flash-lite',
            'gemini-3-flash-lite'       => 'gemini-3.1-flash-lite',
            'gemini-3.0-flash'          => 'gemini-3.1-flash-lite',
            'gemini-3.0-flash-lite'     => 'gemini-3.1-flash-lite',
            // Genéricos sem versão
            'gemini-flash'              => 'gemini-2.5-flash-lite',
            'gemini-lite'               => 'gemini-2.5-flash-lite',
        ];
        if (isset($deprecated_map[$model_name])) {
            $original = $model_name;
            $model_name = $deprecated_map[$model_name];
            if (class_exists('\GeoMetodoSEO\Services\LogService')) {
                \GeoMetodoSEO\Services\LogService::record(
                    'ai', 'info',
                    "Gemini: {$original} sem cota no free tier — migrando para {$model_name}",
                    ['action' => 'gemini_model_migrated', 'context' => ['from' => $original, 'to' => $model_name]]
                );
            }
        }
        $endpoint   = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model_name . ':generateContent?key=' . $apiKey;

        $max_tokens_dyn = class_exists('\GeoMetodoSEO\AI\PromptSizeAnalyzer')
            ? \GeoMetodoSEO\AI\PromptSizeAnalyzer::calculate_max_tokens($prompt, 8000, 'gemini')
            : 8000;

        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'contents'         => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'maxOutputTokens' => $max_tokens_dyn,
                    'temperature'     => 0.7,
                    'topP'            => 0.9,
                ],
            ]),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) {
            return new AIResponse('', [], 'Gemini erro de rede: ' . $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body      = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code === 400) {
            $err = $body['error']['message'] ?? 'requisição inválida';
            return new AIResponse('', $body ?? [], 'Gemini HTTP 400: ' . $err);
        }
        if ($http_code === 401 || $http_code === 403) {
            return new AIResponse('', $body ?? [], 'Gemini API key inválida ou sem permissão (HTTP ' . $http_code . ') — confira em aistudio.google.com/app/apikey');
        }
        if ($http_code === 429) {
            return new AIResponse('', $body ?? [], 'Gemini rate-limit (HTTP 429) — aguarde e tente novamente');
        }
        if ($http_code === 503 || $http_code === 529) {
            return new AIResponse('', $body ?? [], 'Gemini indisponível (HTTP ' . $http_code . ') — tente novamente');
        }
        if ($http_code >= 400) {
            $err = $body['error']['message'] ?? ('HTTP ' . $http_code);
            return new AIResponse('', $body ?? [], 'Gemini erro: ' . $err);
        }

        $content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty(trim($content))) {
            // Verifica se foi bloqueado por safety filters
            $finish_reason = $body['candidates'][0]['finishReason'] ?? '';
            if ($finish_reason === 'SAFETY') {
                return new AIResponse('', $body ?? [], 'Gemini bloqueou por filtro de segurança — reformule o prompt');
            }
            $err = $body['error']['message'] ?? 'resposta vazia sem conteúdo';
            return new AIResponse('', $body ?? [], 'Gemini retornou: ' . $err);
        }

        return new AIResponse($content, $body ?? []);
    }
}
