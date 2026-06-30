<?php
namespace GeoMetodoSEO\Quality;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Services\LogService;

/**
 * ThinContentValidator — Detecta padrões de "thin content"
 * penalizados pelo Google March 2026 Core Update.
 *
 * Valida 5 sinais de qualidade:
 *  1. Word count mínimo
 *  2. Dados específicos (números, %, R$, $, anos, períodos)
 *  3. Exemplos concretos ("exemplo", "cliente", "caso real")
 *  4. Frases vazias ("é fundamental", "no mundo de hoje")
 *  5. H2 com conteúdo substantivo (média de palavras por seção)
 *
 * Score 0-100. Threshold default 60.
 * Não usa IA — toda validação é local com regex/word counting.
 *
 * @since 1.0.0
 */
class ThinContentValidator {

    /**
     * Valida qualidade de conteúdo de artigo.
     *
     * @param string $content       HTML do artigo
     * @param int    $target_words  Word count alvo (padrão 1500)
     * @return array  ['passed' => bool, 'score' => 0-100, 'threshold' => int,
     *                 'issues' => string[], 'metrics' => array]
     */
    public static function validate(string $content, int $target_words = 1500): array {
        $text = wp_strip_all_tags($content);
        $word_count = str_word_count($text);

        $issues  = [];
        $score   = 100;
        $metrics = [
            'word_count'   => $word_count,
            'has_data'     => false,
            'has_examples' => false,
            'filler_ratio' => 0.0,
            'h2_quality'   => 0,
            'data_hits'    => 0,
            'example_hits' => 0,
            'filler_hits'  => 0,
        ];

        // 1. Word count check (March 2026: < 1200 palavras = thin)
        $min_words = (int) get_option('geo_thin_content_min_words', 1200);
        if ($word_count < $min_words) {
            $issues[] = "Word count {$word_count} abaixo do mínimo {$min_words}";
            $score -= 30;
        }

        // 2. Detecção de dados específicos
        $data_patterns = [
            '/\b\d+\s*%/u',                                    // percentuais
            '/\b(?:R\$|US\$|\$)\s*\d/u',                       // moeda
            '/\b(19|20)\d{2}\b/u',                             // anos
            '/\b\d+(?:\.\d+)?(?:k|mil|milhões|m|bn)\b/iu',     // números grandes
            '/\b\d+\s+(?:dias|semanas|meses|anos|horas)\b/iu', // períodos
        ];
        $data_hits = 0;
        foreach ($data_patterns as $pattern) {
            if (preg_match_all($pattern, $text, $m)) {
                $data_hits += count($m[0]);
            }
        }
        $metrics['data_hits'] = $data_hits;
        $metrics['has_data']  = $data_hits >= 3;
        if ($data_hits < 3) {
            $issues[] = "Dados específicos insuficientes ({$data_hits} encontrados, precisa 3+)";
            $score -= 20;
        }

        // 3. Detecção de exemplos concretos
        $example_patterns = [
            '/\bexemplo\b/iu',
            '/\bcliente\b/iu',
            '/\bcaso\s+(?:real|prático|de\s+uso)\b/iu',
            '/\bestudo\s+de\s+caso\b/iu',
            '/\bna\s+prática\b/iu',
            '/\bpor\s+exemplo\b/iu',
        ];
        $example_hits = 0;
        foreach ($example_patterns as $pattern) {
            if (preg_match_all($pattern, $text, $m)) {
                $example_hits += count($m[0]);
            }
        }
        $metrics['example_hits'] = $example_hits;
        $metrics['has_examples'] = $example_hits >= 2;
        if ($example_hits < 2) {
            $issues[] = "Sem exemplos concretos detectados ({$example_hits} encontrados, precisa 2+)";
            $score -= 15;
        }

        // 4. Frases vazias / clichês
        $fillers = [
            'é fundamental', 'é essencial', 'é importante saber',
            'no mundo de hoje', 'cada vez mais', 'à frente da curva',
            'tudo que você precisa saber', 'vamos explorar', 'vamos ver',
            'guia completo', 'imperdível', 'tudo sobre',
            'ao longo dos anos', 'nos dias atuais', 'em pleno século',
        ];
        $filler_hits = 0;
        $text_lower  = mb_strtolower($text);
        foreach ($fillers as $f) {
            $filler_hits += mb_substr_count($text_lower, $f);
        }
        $filler_ratio = $word_count > 0 ? ($filler_hits / max(1, $word_count)) * 1000 : 0;
        $metrics['filler_ratio'] = round($filler_ratio, 2);
        $metrics['filler_hits']  = $filler_hits;
        if ($filler_ratio > 3.0) {
            $issues[] = "Muitas frases vazias ({$filler_hits} = " . round($filler_ratio, 1) . "/1000 palavras)";
            $score -= 20;
        }

        // 5. H2 substantivo
        $h2_count = preg_match_all('/<h2[^>]*>/i', $content, $m);
        $h2_count = $h2_count !== false ? $h2_count : 0;
        if ($h2_count >= 3) {
            $words_per_h2 = $h2_count > 0 ? $word_count / $h2_count : 0;
            $metrics['h2_quality'] = (int) round($words_per_h2);
            if ($words_per_h2 < 150) {
                $issues[] = "Seções H2 muito curtas (média " . round($words_per_h2) . " palavras, precisa 150+)";
                $score -= 15;
            }
        } else {
            $issues[] = "Estrutura H2 insuficiente ({$h2_count} H2s, precisa 3+)";
            $score -= 15;
        }

        $score     = max(0, min(100, $score));
        $threshold = (int) get_option('geo_thin_content_threshold', 60);
        $passed    = $score >= $threshold;

        if (class_exists(LogService::class)) {
            LogService::record('quality', $passed ? 'success' : 'warning',
                "ThinContent score: {$score}/100 (threshold {$threshold}). " .
                ($passed ? 'PASSED' : 'FAILED: ' . implode('; ', $issues)),
                ['action' => 'thin_content_validate', 'metrics' => $metrics]
            );
        }

        return [
            'passed'    => $passed,
            'score'     => $score,
            'threshold' => $threshold,
            'issues'    => $issues,
            'metrics'   => $metrics,
        ];
    }
}
