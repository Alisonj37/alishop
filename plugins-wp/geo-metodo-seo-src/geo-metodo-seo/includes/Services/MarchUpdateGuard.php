<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * MarchUpdateGuard — Validador anti-Google March 2026 Update.
 *
 * Analisa conteúdo gerado por IA e detecta:
 *   - Thin content (artigos curtos demais)
 *   - Falta de dados originais (sem números, %, valores, casos reais)
 *   - Excesso de frases vazias ("no mundo de hoje", "é fundamental", etc)
 *   - Padrão "aggregator" (introduçÃµes genéricas sem aprofundamento)
 *
 * @since 1.0.0
 */

class MarchUpdateGuard {

    /** Frases vazias proibidas pelo Google March 2026 Core Update. */
    private const EMPTY_PHRASES = [
        'no mundo de hoje', 'no cenário atual', 'cada vez mais',
        'Ã  frente da curva', 'a frente da curva',
        'é fundamental', 'é essencial', 'é importante',
        'vamos explorar', 'vamos ver', 'tudo que você precisa saber',
        'guia completo', 'guia definitivo', 'imperdível', 'garantido',
        'sem dúvida', 'sem duvida', 'com certeza',
        'no fim das contas', 'em última análise', 'em ultima analise',
    ];

    /**
     * Analisa conteúdo HTML e retorna score + issues detectados.
     *
     * @param string $html       Conteúdo HTML do artigo
     * @param int    $word_target Palavras alvo (default 1500)
     * @return array {
     *   @type int    $score    0-100 (>=70 = aprovado, <70 = atenção)
     *   @type array  $issues   Lista de problemas encontrados
     *   @type array  $metrics  Métricas detalhadas (word_count, originality_signals, etc)
     *   @type bool   $approved (score >= threshold configurado)
     * }
     */
    public static function analyze(string $html, int $word_target = 1500): array {
        $text = wp_strip_all_tags($html);
        $text_lower = mb_strtolower(remove_accents($text));
        $word_count = str_word_count($text, 0, 'áéíóúÃ Ã¢êôãÃµçÃ±');

        $issues = [];
        $score = 100;
        $metrics = [
            'word_count'           => $word_count,
            'word_target'          => $word_target,
            'numeric_data_count'   => 0,
            'currency_count'       => 0,
            'percentage_count'     => 0,
            'comparison_count'     => 0,
            'practical_signal_count' => 0,
            'empty_phrases_found'  => [],
            'has_examples'         => false,
        ];

        // 1. Word count check (thin content)
        $min_words = max(800, (int) round($word_target * 0.55));
        if ($word_count < $min_words) {
            $score -= 25;
            $issues[] = "Thin content: artigo tem {$word_count} palavras (mínimo {$min_words})";
        }

        // 2. Dados numéricos originais (números genuínos, não datas/anos)
        if (preg_match_all('/\b\d{1,3}(?:[.,]\d{3})*(?:[.,]\d+)?\s*(?:%|por\s*cento)/iu', $text, $m)) {
            $metrics['percentage_count'] = count($m[0]);
        }
        if (preg_match_all('/(?:R\$|USD|US\$|â‚¬|\$)\s*\d+(?:[.,]\d+)*/u', $text, $m)) {
            $metrics['currency_count'] = count($m[0]);
        }
        if (preg_match_all('/\b\d+(?:[.,]\d+)?\s*(?:milhÃµes|milhoes|bilhÃµes|bilhoes|mil|usuários|usuarios|empresas|sites|clientes|pessoas|dias|meses|horas|minutos|segundos|x\s+mais|vezes)/iu', $text, $m)) {
            $metrics['numeric_data_count'] = count($m[0]);
        }

        $total_data = $metrics['percentage_count'] + $metrics['currency_count'] + $metrics['numeric_data_count'];
        if ($total_data < 3) {
            $issues[] = "Sem numeros especificos confirmados; aceitavel quando nao ha fonte verificavel no briefing.";
        }

        // 3. Sinais de experiência prática (E-E-A-T)
        $practical_signals = [
            'na prática', 'na pratica', 'em testes', 'ao analisar',
            'comparando', 'no caso de', 'por exemplo', 'segundo o relatório',
            'segundo o relatorio', 'um cliente', 'um e-commerce',
            'um site', 'uma empresa', 'em projetos reais',
        ];
        foreach ($practical_signals as $signal) {
            $count = mb_substr_count($text_lower, $signal);
            if ($count > 0) $metrics['practical_signal_count'] += $count;
        }
        if ($metrics['practical_signal_count'] < 2) {
            $score -= 15;
            $issues[] = "Falta sinal de experiência prática (E-E-A-T): apenas {$metrics['practical_signal_count']} marcadores";
        }

        // 4. ComparaçÃµes ("X vs Y", "diferença entre")
        if (preg_match_all('/\b(?:vs\.?|versus|em comparação|em comparacao|diferença entre|diferenca entre|enquanto que)\b/iu', $text, $m)) {
            $metrics['comparison_count'] = count($m[0]);
        }

        // 5. Frases vazias (penalidade pesada)
        foreach (self::EMPTY_PHRASES as $phrase) {
            $count = mb_substr_count($text_lower, mb_strtolower(remove_accents($phrase)));
            if ($count > 0) {
                $metrics['empty_phrases_found'][$phrase] = $count;
                $score -= ($count * 3); // -3 por ocorrência
            }
        }
        $total_empty = array_sum($metrics['empty_phrases_found']);
        if ($total_empty > 5) {
            $issues[] = "Excesso de frases vazias: {$total_empty} ocorrências (máximo 5)";
        }

        // 6. Examples / cases
        if (preg_match('/\b(?:exemplo prático|exemplo pratico|caso real|caso prático|caso pratico|estudo de caso)\b/iu', $text)) {
            $metrics['has_examples'] = true;
        } else {
            $score -= 10;
            $issues[] = "Sem exemplos práticos ou casos reais explícitos";
        }

        // 7. Densidade de listas/bullets (Google penaliza listas vazias)
        $list_items = substr_count($html, '<li');
        if ($list_items > 30) {
            $score -= 10;
            $issues[] = "Excesso de bullets ({$list_items}): pode ser percebido como conteúdo raso";
        }

        $score = max(0, min(100, $score));
        $threshold = (int) get_option('geo_march_guard_threshold', 60);
        $approved = $score >= $threshold;

        return [
            'score'    => $score,
            'issues'   => $issues,
            'metrics'  => $metrics,
            'approved' => $approved,
            'threshold'=> $threshold,
        ];
    }

    /**
     * Salva relatório do March Guard como meta do post.
     */
    public static function record_report(int $post_id, array $report): void {
        if ($post_id <= 0) return;
        update_post_meta($post_id, '_geo_march_guard_score', (int) ($report['score'] ?? 0));
        update_post_meta($post_id, '_geo_march_guard_issues', $report['issues'] ?? []);
        update_post_meta($post_id, '_geo_march_guard_metrics', $report['metrics'] ?? []);
        update_post_meta($post_id, '_geo_march_guard_at', current_time('mysql'));

        if (class_exists('GeoMetodoSEO\\Services\\LogService')) {
            $level = ($report['approved'] ?? false) ? 'info' : 'warning';
            $message = "March Guard score: {$report['score']}/100" . (!empty($report['issues']) ? ' — ' . count($report['issues']) . ' issues' : '');
            \GeoMetodoSEO\Services\LogService::record('march_guard', $level, $message, [
                'post_id' => $post_id,
                'context' => $report,
            ]);
        }
    }

    /**
     * Helper: aplica análise + grava relatório em um único call.
     * Usado nos pontos de geração de artigo.
     */
    public static function analyze_and_record(int $post_id, string $html, int $word_target = 1500): array {
        $report = self::analyze($html, $word_target);
        self::record_report($post_id, $report);
        return $report;
    }
}
