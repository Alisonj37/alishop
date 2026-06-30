<?php
namespace GeoMetodoSEO\AI;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Config\ConfigManager;

class AIManager {

    private $providers = [];

    public function __construct() {
        $this->providers = [
            'openai'     => new Providers\OpenAIProvider(),
            'groq'       => new Providers\GroqProvider(),
            'gemini'     => new Providers\GeminiProvider(),
            'claude'     => new Providers\ClaudeProvider(),
            'perplexity' => new Providers\PerplexityProvider(),
            'naga'        => new Providers\NagaProvider(),
        ];
    }

    public function generateText($prompt, $provider_name = null, $model = null, $context = 'article_generation') {
        $primary = ProviderResolver::for($context ?: 'article_generation', $provider_name ?? ConfigManager::get('ai_provider', ''));
        if (!$primary) {
            return new AIResponse('', [], 'Nenhum provider de texto selecionado/configurado para o contexto: ' . ($context ?: 'article_generation'));
        }

        if (!isset($this->providers[$primary])) {
            return new AIResponse('', [], 'Provider inválido ou não suportado: ' . $primary);
        }

        $use_model = $model ?: ProviderResolver::modelFor($context ?: 'article_generation', $primary);
        if (!$this->isProviderConfigured($primary)) {
            return new AIResponse('', [], 'Provider selecionado sem API key configurada: ' . $primary);
        }
        $cache_key = 'geo_ai_' . md5($primary . '|' . ($use_model ?? '') . '|' . $prompt);
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        // Providers independentes por padrão: quando um provider foi escolhido/resolvido para
        // o contexto, não consome créditos de outros provedores sem autorização explícita.
        $allow_fallback = (bool) apply_filters(
            'geo_ai_allow_provider_fallback',
            (get_option('geo_ai_provider_fallback_enabled', '0') === '1'),
            $context,
            $primary,
            $provider_name,
            $model
        );

        $strict_contexts = [
            'article_generation', 'individual_generation', 'bulk_generation', 'youtube_article',
            'sara_writer', 'sara_manual', 'glossary_generation', 'cluster_generation', 'content_update',
        ];
        if (in_array((string) $context, $strict_contexts, true) && ! $allow_fallback) {
            $ordered_ids = [$primary];
        } else {
            $ordered_ids = array_keys($this->providers);
            $ordered_ids = array_merge([$primary], array_diff($ordered_ids, [$primary]));
        }

        $failures = [];
        $is_first = true;

        foreach ($ordered_ids as $provider_id) {
            if (!isset($this->providers[$provider_id])) continue;

            // Pular providers pausados por rate-limit/quota
            if ($this->isProviderPaused($provider_id)) {
                $reason = $this->providerPauseReason($provider_id);
                $failures[$provider_id] = $reason;
                // Aviso claro ao usuário — especialmente quando é o provider primário,
                // para não receber "todos falharam" sem entender que está pausado.
                if ($provider_id === $primary) {
                    \GeoMetodoSEO\Services\LogService::record('ai', 'warning',
                        'Provider primário "' . $provider_id . '" está pausado: ' . $reason
                        . '. Aguarde o fim da pausa ou escolha outro provider nas configurações.',
                        ['action' => 'primary_provider_paused', 'context' => [
                            'provider' => $provider_id, 'reason' => $reason,
                        ]]
                    );
                }
                $is_first = false;
                continue;
            }

            // Pular providers sem API key — logar para o usuário saber o que falta
            if (!$this->isProviderConfigured($provider_id)) {
                if ($provider_id !== $primary) {
                    // Só loga fallbacks sem key — primário sem key já foi tratado acima
                    $failures[$provider_id] = 'API key não configurada';
                }
                $is_first = false;
                continue;
            }

            $provider = $this->providers[$provider_id];
            $current_model = $is_first ? $use_model : null;

            try {
                $response = $provider->generate($prompt, $current_model);
                $is_first = false;

                if ($response && !$response->hasError() && $response->getContent()) {
                    $cache_ttl = (int) apply_filters('geo_ai_cache_ttl', HOUR_IN_SECONDS);
                    if ($cache_ttl < 60) $cache_ttl = 60;
                    if ($cache_ttl > DAY_IN_SECONDS) $cache_ttl = DAY_IN_SECONDS;
                    $actual_cache_key = 'geo_ai_' . md5($provider_id . '|' . ($current_model ?? '') . '|' . $prompt);
                    set_transient($actual_cache_key, $response, $cache_ttl);

                    \GeoMetodoSEO\Services\LogService::record(
                        'ai', 'success',
                        'Texto gerado via ' . $provider_id . (count($failures) > 0 ? ' (após fallback)' : ''),
                        ['action' => 'generate_text', 'context' => [
                            'provider_used'   => $provider_id,
                            'model_used'      => $current_model,
                            'context_name'    => $context,
                            'fallback_used'   => $provider_id !== $primary,
                            'failures_before' => $failures,
                        ]]
                    );
                    return $response;
                }

                $err_msg = ($response && method_exists($response, 'getError')) ? (string)$response->getError() : 'resposta vazia';
                $err_msg = $err_msg ?: 'resposta vazia';
                $failures[$provider_id] = $err_msg;
                $this->maybePauseProvider($provider_id, $err_msg);

                \GeoMetodoSEO\Services\LogService::record('ai', 'warning',
                    'Provider ' . $provider_id . ' retornou erro: ' . $err_msg,
                    ['action' => 'provider_failed', 'context' => [
                        'provider' => $provider_id, 'error' => $err_msg,
                    ]]
                );

            } catch (\Throwable $e) {
                $is_first = false;
                $failures[$provider_id] = $e->getMessage();
                $this->maybePauseProvider($provider_id, $e->getMessage());

                \GeoMetodoSEO\Services\LogService::record('ai', 'error',
                    'Provider ' . $provider_id . ' lançou exception: ' . $e->getMessage(),
                    ['action' => 'provider_exception', 'context' => [
                        'provider' => $provider_id,
                        'file'     => basename($e->getFile()) . ':' . $e->getLine(),
                    ]]
                );
            }
        }

        // Todos os providers falharam
        $fail_summary = implode(' | ', array_map(
            fn($p, $e) => "{$p}={$e}", array_keys($failures), array_values($failures)
        ));
        $detail = 'Todos providers falharam: ' . $fail_summary;
        \GeoMetodoSEO\Services\LogService::record('ai', 'error', $detail, [
            'action' => 'all_providers_failed',
            'context' => $failures,
        ]);
        return new AIResponse('', [], $detail);
    }


    public function generateTextForContext($prompt, string $context, $model = null, $provider_name = null) {
        $provider = ProviderResolver::for($context, $provider_name);
        $use_model = $model ?: ProviderResolver::modelFor($context, $provider);
        return $this->generateText($prompt, $provider, $use_model, $context);
    }

    /**
     * Método legado mantido apenas para compatibilidade.
     * Geração de imagens do plugin deve usar ImageGeneratorService, que respeita Fal.ai,
     * Replicate, Naga, bancos de imagem e Pollinations. Não chama OpenAI diretamente.
     */
    public function generateImage($prompt) {
        \GeoMetodoSEO\Services\LogService::record(
            'media',
            'warning',
            'AIManager::generateImage() é legado e não deve ser usado. Use ImageGeneratorService.',
            ['action' => 'legacy_generate_image_blocked']
        );
        return new AIResponse('', [], 'Método legado de imagem desativado. Use ImageGeneratorService.');
    }

    private function isProviderConfigured($provider_id): bool {
        return ProviderResolver::isConfigured((string)$provider_id);
    }

    private function isProviderPaused(string $provider_id): bool {
        $until = (int) get_transient('geo_ai_provider_paused_until_' . sanitize_key($provider_id));
        return $until > time();
    }

    private function providerPauseReason(string $provider_id): string {
        $until  = (int) get_transient('geo_ai_provider_paused_until_' . sanitize_key($provider_id));
        $reason = (string) get_transient('geo_ai_provider_paused_reason_' . sanitize_key($provider_id));
        $when   = $until > time() ? ' até ' . wp_date('Y-m-d H:i:s', $until) : '';
        return 'provider pausado' . $when . ($reason ? ': ' . $reason : '');
    }

    private function maybePauseProvider(string $provider_id, string $message): void {
        $seconds = 0;

        if (preg_match('/exceeded your current quota|insufficient_quota|credit balance|billing/i', $message)) {
            // Quota esgotada — pausa de 1h (era 6h, reduzido para não travar o usuário tanto tempo)
            $seconds = 1 * HOUR_IN_SECONDS;
        } elseif (preg_match('/request too large|tokens per minute|tpm.*limit/i', $message)) {
            // FIX: "Request too large" NÃO é rate-limit — é tamanho de prompt.
            // O PromptSizeAnalyzer já cuida disso ajustando max_tokens.
            // NÃO pausar o provider — apenas registrar o aviso.
            // Pausar aqui travava o Groq por horas sem necessidade.
            \GeoMetodoSEO\Services\LogService::record('ai', 'warning',
                'Provider ' . $provider_id . ': prompt muito grande — PromptSizeAnalyzer ajustará na próxima chamada',
                ['action' => 'prompt_too_large_no_pause', 'context' => ['provider' => $provider_id]]
            );
            return;
        } elseif (preg_match('/rate.?limit|429/i', $message)) {
            // Rate-limit real — extrair retry-after ou usar pausa curta
            if (preg_match('/try again in\s+(?:(\d+)h)?(?:(\d+)m)?(?:(\d+(?:\.\d+)?)s)?/i', $message, $mm)) {
                $h = isset($mm[1]) && $mm[1] !== '' ? (int)$mm[1] : 0;
                $m = isset($mm[2]) && $mm[2] !== '' ? (int)$mm[2] : 0;
                $s = isset($mm[3]) && $mm[3] !== '' ? (int)ceil((float)$mm[3]) : 0;
                $seconds = max(60, $h * HOUR_IN_SECONDS + $m * MINUTE_IN_SECONDS + $s);
            } else {
                $seconds = 5 * MINUTE_IN_SECONDS;
            }
        } elseif (preg_match('/resposta vazia|empty response/i', $message)) {
            // Resposta vazia — pausa curta.
            $seconds = 2 * MINUTE_IN_SECONDS;
        } elseif (preg_match('/timeout|cURL error 28|Operation timed out/i', $message)) {
            // Timeout de rede não significa falta de crédito nem provider quebrado.
            // Não pausar automaticamente, para não derrubar todos os módulos depois de uma chamada lenta.
            \GeoMetodoSEO\Services\LogService::record('ai', 'warning',
                'Provider ' . $provider_id . ': timeout de rede sem pausa automática',
                ['action' => 'provider_timeout_no_pause', 'context' => ['provider' => $provider_id]]
            );
            return;
        }

        if ($seconds <= 0) return;

        $provider_id = sanitize_key($provider_id);
        $until = time() + $seconds;
        set_transient('geo_ai_provider_paused_until_'  . $provider_id, $until,  $seconds);
        set_transient('geo_ai_provider_paused_reason_' . $provider_id, mb_substr(wp_strip_all_tags($message), 0, 500), $seconds);

        \GeoMetodoSEO\Services\LogService::record('ai', 'warning',
            'Provider pausado temporariamente: ' . $provider_id,
            ['action' => 'provider_temporarily_paused', 'context' => [
                'provider' => $provider_id,
                'until'    => $until,
                'seconds'  => $seconds,
                'reason'   => mb_substr(wp_strip_all_tags($message), 0, 200),
            ]]
        );
    }

    public static function clear_provider_pauses(string $provider = ''): void {
        $providers = $provider ? [$provider] : ['openai', 'groq', 'gemini', 'claude', 'perplexity', 'naga'];
        foreach ($providers as $p) {
            $p = sanitize_key($p);
            delete_transient('geo_ai_provider_paused_until_'  . $p);
            delete_transient('geo_ai_provider_paused_reason_' . $p);
        }
    }

}
