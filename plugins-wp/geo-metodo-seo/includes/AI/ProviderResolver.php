<?php
namespace GeoMetodoSEO\AI;

use GeoMetodoSEO\Config\ConfigManager;

if (!defined('ABSPATH')) exit;

/**
 * ProviderResolver — resolução direta e limpa de provider + modelo.
 *
 * Filosofia: cada gerador tem sua própria opção de provider e modelo.
 * Sem cadeia de fallback de opções. Sem lookup em 10 chaves diferentes.
 * Sem camadas intermediárias.
 *
 * Mapeamento direto:
 *   Gerador Individual  → geo_individual_provider + geo_individual_model
 *   Gerador em Massa    → geo_bulk_provider       + geo_bulk_model
 *   Cluster SEO         → geo_cluster_provider    + geo_cluster_model
 *   YouTube → Artigo    → geo_youtube_provider    + geo_youtube_model
 *   SARA Autopilot Auto → geo_sara_provider       + geo_sara_model
 *   SARA Writer Manual  → geo_manual_provider     + geo_manual_model
 *   Glossário           → geo_glossary_provider   + geo_glossary_model
 *
 * Se o gerador não tiver provider configurado → usa geo_default_provider.
 * Se geo_default_provider vazio → retorna '' (erro claro ao usuário).
 */
class ProviderResolver {

    public const PROVIDERS = ['openai', 'groq', 'gemini', 'claude', 'perplexity', 'naga'];

    // Mapa direto: contexto → chave de opção do provider
    private const CONTEXT_PROVIDER_KEY = [
        'article_generation'   => 'geo_individual_provider',
        'individual_generation'=> 'geo_individual_provider',
        'bulk_generation'      => 'geo_bulk_provider',
        'cluster_generation'   => 'geo_cluster_provider',
        'youtube_article'      => 'geo_youtube_provider',
        'sara_generation'      => 'geo_sara_provider',
        'sara_writer'          => 'geo_sara_provider',
        'sara_briefing'        => 'geo_sara_provider',
        'sara_scoring'         => 'geo_sara_provider',
        'sara_expansion'       => 'geo_sara_provider',
        'brain'                => 'geo_sara_provider',
        'niche_detection'      => 'geo_sara_provider',
        'manual_writer'        => 'geo_manual_provider',
        'sara_manual'          => 'geo_manual_provider',
        'glossary'             => 'geo_glossary_provider',
        'glossary_generation'  => 'geo_glossary_provider',
        'content_refresher'    => 'geo_refresher_provider',
        'content_update'       => 'geo_refresher_provider',
        'faq'                  => 'geo_sara_provider',
        'image_prompt'         => 'geo_sara_provider',
        'title_generation'     => 'geo_sara_provider',
        'webstories'           => 'geo_sara_provider',
        'media_matcher'        => 'geo_sara_provider',
        'media_pitch'          => 'geo_sara_provider',
        'tts'                  => 'geo_sara_provider',
    ];

    // Mapa direto: contexto → chave de opção do modelo
    private const CONTEXT_MODEL_KEY = [
        'article_generation'   => 'geo_individual_model',
        'individual_generation'=> 'geo_individual_model',
        'bulk_generation'      => 'geo_bulk_model',
        'cluster_generation'   => 'geo_cluster_model',
        'youtube_article'      => 'geo_youtube_model',
        'sara_generation'      => 'geo_sara_model',
        'sara_writer'          => 'geo_sara_model',
        'sara_briefing'        => 'geo_sara_model',
        'sara_scoring'         => 'geo_sara_model',
        'sara_expansion'       => 'geo_sara_model',
        'brain'                => 'geo_sara_model',
        'niche_detection'      => 'geo_sara_model',
        'manual_writer'        => 'geo_manual_model',
        'sara_manual'          => 'geo_manual_model',
        'glossary'             => 'geo_glossary_model',
        'glossary_generation'  => 'geo_glossary_model',
        'content_refresher'    => 'geo_refresher_model',
        'content_update'       => 'geo_refresher_model',
        'faq'                  => 'geo_sara_model',
        'image_prompt'         => 'geo_sara_model',
        'title_generation'     => 'geo_sara_model',
        'webstories'           => 'geo_sara_model',
        'media_matcher'        => 'geo_sara_model',
        'media_pitch'          => 'geo_sara_model',
        'tts'                  => 'geo_sara_model',
    ];

    public static function allowedProviders(): array {
        return self::PROVIDERS;
    }

    public static function isValid(?string $provider): bool {
        return in_array(self::normalize($provider), self::PROVIDERS, true);
    }

    public static function normalize(?string $provider): string {
        $p = strtolower(sanitize_key((string)$provider));
        $aliases = [
            'anthropic' => 'claude',
            'google'    => 'gemini',
            'nagaac'    => 'naga',
        ];
        $p = $aliases[$p] ?? $p;
        return in_array($p, self::PROVIDERS, true) ? $p : '';
    }

    /**
     * Resolve o provider para um contexto.
     * Prioridade: 1) preferred (passado explicitamente pelo caller)
     *             2) opção específica do contexto (ex: geo_individual_provider)
     *             3) opção padrão global (geo_default_provider)
     *             4) '' — nenhum configurado, caller deve tratar o erro
     */
    public static function for(string $context, ?string $preferred = null): string {
        // 1. Provider passado explicitamente (gerador individual, cluster etc)
        $p = self::normalize($preferred);
        if ($p !== '') return $p;

        // 2. Opção específica do contexto
        $key = self::CONTEXT_PROVIDER_KEY[sanitize_key($context)] ?? '';
        if ($key !== '') {
            $p = self::normalize((string) get_option($key, ''));
            if ($p !== '') return $p;
        }

        // 3. Fallback para provider padrão global
        $p = self::normalize((string) get_option('geo_default_provider', ''));
        if ($p !== '') return $p;

        // 4. Compatibilidade com opção antiga geo_ai_provider
        $p = self::normalize((string) get_option('geo_ai_provider', ''));
        return $p;
    }

    /**
     * Resolve o modelo para um provider + contexto.
     * Prioridade: 1) modelo passado explicitamente
     *             2) opção específica do contexto (ex: geo_individual_model)
     *             3) modelo padrão do provider (geo_default_model_{provider})
     *             4) modelo hardcoded do provider
     */
    public static function modelFor(string $context, ?string $provider = null, ?string $preferred = null): ?string {
        $provider = self::for($context, $provider);
        if ($provider === '') return null;

        // 1. Modelo passado explicitamente
        if ($preferred !== null && trim($preferred) !== '') {
            return sanitize_text_field($preferred);
        }

        // 2. Opção específica do contexto
        $key = self::CONTEXT_MODEL_KEY[sanitize_key($context)] ?? '';
        if ($key !== '') {
            $value = trim((string) get_option($key, ''));
            if ($value !== '') return sanitize_text_field($value);
        }

        // 3. Modelo padrão do provider configurado na tela de keys
        $default_key = 'geo_default_model_' . $provider;
        $value = trim((string) get_option($default_key, ''));
        if ($value !== '') return sanitize_text_field($value);

        // 4. Compatibilidade com chave antiga geo_model_{provider}
        $value = trim((string) get_option('geo_model_' . $provider, ''));
        if ($value !== '') return sanitize_text_field($value);

        // 5. Modelo padrão hardcoded
        return self::defaultModel($provider);
    }

    public static function isConfigured(string $provider): bool {
        $provider = self::normalize($provider);
        $key_map = [
            'openai'     => 'geo_openai_api_key',
            'groq'       => 'geo_groq_api_key',
            'gemini'     => 'geo_gemini_api_key',
            'claude'     => 'geo_claude_api_key',
            'perplexity' => 'geo_perplexity_api_key',
            'naga'       => 'geo_naga_api_key',
        ];
        $key = $key_map[$provider] ?? '';
        return $key !== '' && !empty(get_option($key, ''));
    }

    public static function defaultModel(string $provider): ?string {
        $defaults = [
            'openai'     => 'gpt-4.1-mini',
            'groq'       => 'llama-3.3-70b-versatile',
            'gemini'     => 'gemini-2.5-flash-lite',
            'claude'     => 'claude-sonnet-4-6',
            'perplexity' => 'sonar',
            'naga'       => 'gemini-2.5-flash:free',
        ];
        return $defaults[self::normalize($provider)] ?? null;
    }

    // Compatibilidade retroativa — modelBelongsToProvider não é mais usado
    // mas mantido para não quebrar código legado que chame diretamente
    public static function modelBelongsToProvider(string $provider, ?string $model): bool {
        return true; // Simplificado — não validamos mais, o provider cuida disso
    }
}
