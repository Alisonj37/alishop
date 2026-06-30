<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Brain;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;

/**
 * SaraNicheDetector — Auto-detecta o nicho do site sem input do usuário.
 * Analisa: nome do site, descrição, categorias dos posts recentes e títulos.
 * Usa GPT-4.1-mini para sumarização semântica (leve e barato).
 * Resultado cacheado em transient por 24h.
 *
 * @since 1.0.0 (SARA Brain v2.0)
 */
class SaraNicheDetector {

    private AIManager $ai;
    private const TRANSIENT_KEY = 'sara_niche_detection_cache';
    private const CACHE_TTL     = DAY_IN_SECONDS;

    public function __construct() {
        $this->ai = new AIManager();
    }

    /**
     * Detectar nicho do site automaticamente.
     * Retorna resultado do cache se disponível (TTL 24h).
     *
     * @return array{niche: string, confidence: int, source: string, top_categories: string[]}
     */
    public function detect_niche(): array {
        // Cache transient 24h
        $cached = get_transient(self::TRANSIENT_KEY);
        if ($cached !== false && is_array($cached)) {
            AutopilotLogger::log('brain', 'niche_cached', 'success',
                "Nicho do cache: {$cached['niche']} ({$cached['confidence']}%)");
            return $cached;
        }

        $result = $this->run_detection();

        // Salvar em transient E em wp_option (persistente)
        set_transient(self::TRANSIENT_KEY, $result, self::CACHE_TTL);
        update_option('sara_niche', $result['niche']);
        update_option('sara_niche_confidence', $result['confidence']);

        AutopilotLogger::log('brain', 'niche_detected', 'success',
            "Nicho detectado: {$result['niche']} (confiança: {$result['confidence']}%)");

        return $result;
    }

    /** Forçar re-detecção (ignora cache) */
    public function force_detect(): array {
        delete_transient(self::TRANSIENT_KEY);
        return $this->detect_niche();
    }

    private function run_detection(): array {
        $site_name = get_bloginfo('name');
        $site_desc = get_bloginfo('description');

        // Analisar últimos 30 posts publicados
        $recent_posts = get_posts([
            'numberposts' => 30,
            'post_status' => 'publish',
            'post_type'   => 'post',
        ]);

        // Contagem por categoria
        $cat_counts = [];
        foreach ($recent_posts as $post) {
            foreach (get_the_category($post->ID) as $cat) {
                $cat_counts[$cat->name] = ($cat_counts[$cat->name] ?? 0) + 1;
            }
        }
        arsort($cat_counts);
        $top_categories = array_slice(array_keys($cat_counts), 0, 5);

        // Calcular confiança base pelas categorias
        $confidence_base = $this->calculate_confidence($cat_counts);

        // Títulos recentes para análise semântica
        $titles = array_slice(array_column($recent_posts, 'post_title'), 0, 10);

        // Tentar sumarização por IA (GPT-4.1-mini — barato)
        $ai_niche = $this->ai_summarize_niche($titles, $site_name, $site_desc);

        // Fallback: usar categoria mais frequente ou nome do site
        $fallback_niche = $top_categories[0]
            ?? (strlen($site_desc) > 5 ? $site_desc : $site_name)
            ?? 'Tecnologia';

        $final_niche = $ai_niche ?: $fallback_niche;

        // Confiança aumenta se a IA confirmou
        $confidence = $ai_niche ? min(95, $confidence_base + 15) : $confidence_base;

        return [
            'niche'          => sanitize_text_field($final_niche),
            'confidence'     => $confidence,
            'source'         => $ai_niche ? 'ai-detection' : 'category-analysis',
            'top_categories' => $top_categories,
        ];
    }

    /**
     * Sumarizar nicho via IA a partir dos títulos recentes.
     * Retorna null em caso de falha (fallback será usado).
     */
    private function ai_summarize_niche(array $titles, string $site_name, string $site_desc): ?string {
        if (empty($titles)) return null;

        $titles_text = implode("\n- ", $titles);

        $prompt = "Você é um analista de conteúdo web. Analise estes dados de um site e identifique o nicho principal.\n\n"
            . "Nome do site: {$site_name}\n"
            . "Descrição: {$site_desc}\n\n"
            . "Títulos recentes:\n- {$titles_text}\n\n"
            . "Responda APENAS com o nicho em 1-3 palavras (ex: 'SEO e Marketing', 'WordPress', 'Finanças Pessoais').\n"
            . "Sem explicações, sem pontuação, apenas o nicho.";

        $provider = ProviderResolver::for('niche_detection');
        $model = ProviderResolver::modelFor('niche_detection', $provider);
        $response = $this->ai->generateText($prompt, $provider, $model);

        if (!$response || $response->hasError()) return null;

        $niche = trim($response->getContent());
        $niche = trim($niche, '"\'');

        // Validar: deve ser curto e sem pontuação estranha
        if (mb_strlen($niche) > 50 || mb_strlen($niche) < 3) return null;
        if (preg_match('/[<>{}]/', $niche)) return null;

        return $niche;
    }

    /**
     * Calcular confiança (0-80) baseada na distribuição de categorias.
     * Quanto mais concentrado em 1-2 categorias, maior a confiança.
     */
    private function calculate_confidence(array $cat_counts): int {
        if (empty($cat_counts)) return 20;

        $total = array_sum($cat_counts);
        if ($total === 0) return 20;

        $top  = array_slice($cat_counts, 0, 2);
        $top2 = array_sum($top);
        $ratio = $top2 / $total;

        if ($ratio > 0.8) return 80;
        if ($ratio > 0.6) return 65;
        if ($ratio > 0.4) return 50;
        return 35;
    }
}
