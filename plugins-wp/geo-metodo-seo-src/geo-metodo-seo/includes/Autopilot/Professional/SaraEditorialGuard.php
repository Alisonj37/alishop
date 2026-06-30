<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Professional;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller;
use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;

/**
 * SARA Editorial Guard — última barreira antes da publicação.
 * Não substitui o Quality Gate por IA; adiciona validações locais e previsíveis.
 *
 * @since 1.0.0
 */
class SaraEditorialGuard {

    public static function inspect_post(int $post_id, array $plan, array $quality = []): array {
        $post = get_post($post_id);
        if (!$post) {
            return ['approved' => false, 'score' => 0, 'critical' => ['Post não encontrado'], 'warnings' => []];
        }

        $content = (string)$post->post_content;
        $text    = trim(wp_strip_all_tags($content));
        $words   = str_word_count($text);
        $target  = max(800, (int)($plan['word_count_target'] ?? 1500));
        $score   = 100;
        $critical = [];
        $warnings = [];

        preg_match_all('/<h2\b/i', $content, $h2);
        $h2_count = count($h2[0] ?? []);

        // 1.0.0 BUG FIX: critérios de palavras suavizados.
        // Antes: < 70% do alvo = CRITICAL → bloqueava publicação automática mesmo em artigos OK.
        //        Cenário comum: alvo 3000, gerou 2050 (68%) → CRITICAL → forçava rascunho sempre.
        // Agora: só CRITICAL se < 50% do alvo (artigo realmente curto).
        //        Entre 50-80%: warning (penaliza score mas não bloqueia).
        //        Aceita também limite absoluto de 800 palavras (não importa o alvo).
        if ($words < 800 && $words < (int)round($target * 0.50)) {
            $critical[] = "Conteúdo curto demais: {$words} palavras (mínimo 800)";
            $score -= 30;
        } elseif ($words < (int)round($target * 0.80)) {
            $warnings[] = "Conteúdo abaixo do alvo: {$words}/{$target} palavras";
            $score -= 10;
        }

        if ($h2_count < 3) {
            $warnings[] = "Poucos subtítulos H2: {$h2_count}";
            $score -= 10;
        }

        if (stripos($content, 'sara-quick-answer') === false && stripos($text, 'resposta rápida') === false) {
            $warnings[] = 'Resposta rápida ausente';
            $score -= 8;
        }

        if (stripos($content, '<table') === false) {
            $warnings[] = 'Tabela comparativa ausente';
            $score -= 8;
        }

        if (!has_post_thumbnail($post_id)) {
            $warnings[] = 'Imagem destacada ausente';
            $score -= 12;
        }

        if (self::looks_repetitive($text)) {
            $critical[] = 'Texto repetitivo detectado';
            $score -= 25;
        }

        $ai_score = (int)($quality['score'] ?? 0);
        if ($ai_score > 0 && $ai_score < 60) {
            $critical[] = "Quality Gate IA abaixo de 60: {$ai_score}";
            $score -= 20;
        }

        $score = max(0, min(100, $score));
        // 1.0.0: threshold para aprovação configurável (default 55, era 68).
        // 68 era muito restritivo para uso real — pequenas faltas (sem tabela + sem quick-answer = -16) já bloqueavam.
        $threshold = (int) get_option('geo_editorial_guard_min_score', 55);
        $approved = empty($critical) && $score >= $threshold;

        update_post_meta($post_id, '_sara_editorial_guard_score', $score);
        update_post_meta($post_id, '_sara_editorial_guard_issues', wp_json_encode([
            'critical' => $critical,
            'warnings' => $warnings,
        ], JSON_UNESCAPED_UNICODE));

        return [
            'approved' => $approved,
            'score'    => $score,
            'critical' => $critical,
            'warnings' => $warnings,
        ];
    }

    public static function enforce(int $post_id, array $plan, array $quality = []): array {
        $result = self::inspect_post($post_id, $plan, $quality);
        $mode   = AutopilotInstaller::get('writer_publish_mode', 'draft');

        if (!$result['approved'] && $mode === 'publish') {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                'ID' => $post_id,
                'post_status' => 'draft',
            ]);
            AutopilotLogger::log('writer', 'editorial_guard_draft', 'warning',
                'Post rebaixado para rascunho pelo Editorial Guard: ' . implode('; ', $result['critical']),
                ['post_id' => $post_id, 'calendar_id' => (int)($plan['id'] ?? 0), 'context' => $result]
            );
        } else {
            AutopilotLogger::log('writer', 'editorial_guard', $result['approved'] ? 'success' : 'warning',
                'Editorial Guard score=' . $result['score'] . ($result['approved'] ? ' aprovado' : ' com avisos'),
                ['post_id' => $post_id, 'calendar_id' => (int)($plan['id'] ?? 0), 'context' => $result]
            );
        }

        return $result;
    }

    private static function looks_repetitive(string $text): bool {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $seen = [];
        foreach ($sentences as $sentence) {
            $norm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $sentence)));
            if (mb_strlen($norm) < 80) continue;
            $hash = md5(mb_substr($norm, 0, 180));
            if (isset($seen[$hash])) return true;
            $seen[$hash] = true;
        }
        return false;
    }
}
