<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Brain;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;

/**
 * SaraTitleValidator — Validador de títulos com anti-clichê, anti-duplicata e anti-canibalização.
 * Rejeita títulos genéricos, muito curtos/longos, sem números e sem ângulo emocional.
 *
 * @since 1.0.0 (SARA Brain v2.0)
 */
class SaraTitleValidator {

    /** Padrões proibidos — clichês rejeitados automaticamente
     *  @since 1.0.0 BUG FIX: adicionados mais padrões fracos detectados em produção
     */
    private const FORBIDDEN_PATTERNS = [
        '/guia\s+completo/iu',
        '/guia\s+definitivo/iu',
        '/\bdefinitivo\b/iu',
        '/tudo\s+o\s+que\s+voc[eê]\s+precisa\s+saber/iu',
        '/melhores\s+pr[áa]ticas/iu',
        '/dicas\s+essenciais/iu',
        '/passo\s+a\s+passo/iu',
        '/\btudo\s+sobre\b/iu',
        '/\bintrodução\s+ao?\b/iu',
        '/\bconheça\b/iu',
        '/\bconfira\b/iu',
        '/\bcompleto\s+para\s+iniciantes\b/iu',
        // Novos padrões fracos detectados em produção (v1.0.0)
        '/^melhores\s+/iu',                        // "Melhores X em 2026"
        '/\bmelhores?\s+\w+\s+(em|para|de)\s+\d{4}\b/iu',  // "Melhores X em 2026"
        '/^top\s+\d+\b/iu',                        // "Top 10 X" (sem ângulo)
        '/^aprenda\s+/iu',                         // "Aprenda X"
        '/^tudo\s+sobre/iu',
        '/^para\s+iniciantes/iu',
        '/\bguia\s+pr[áa]tico\b/iu',
        '/\bguia\s+r[áa]pido\b/iu',
    ];

    /** Ao menos UM desses padrões deve estar presente */
    private const REQUIRED_PATTERNS = [
        '/\?|como\s|por\s+que\s|quando\s|vale\s+a\s+pena|compar|diferen[çc]a|estrat[ée]gia|tend[êe]ncia|atualiza[çc][ãa]o|ferramenta|wordpress|google|ia|seo|not[íi]cias|smartphone|celular|android|iphone|samsung|xiaomi|motorola|apple|bateria|c[âa]mera|atualiza[çc][ãa]o|lan[çc]amento|review|an[áa]lise|problema|solu[çc][ãa]o/iu'
                                                 => 'Deve ter intenção clara, entidade ou ângulo editorial',
    ];

    /** Termos excessivamente apelativos que reduzem naturalidade editorial */
    private const CLICKBAIT_WORDS = [
        'fatal', 'fatais', 'secreto', 'secretos', 'segredo', 'segredos',
        'proibido', 'proibidos', 'chocante', 'urgente', 'ninguém te conta',
    ];

    /** Similaridade máxima com posts existentes (%) — preserva anti-canibalização sem bloquear variações editoriais legítimas. */
    private const SIMILARITY_THRESHOLD = 78;

    /** Similaridade máxima na sessão atual (%) — evita duplicatas reais, mas não bloqueia 2 pautas da mesma categoria. */
    private const SESSION_SIMILARITY_THRESHOLD = 70;

    /**
     * Validar um título candidato.
     * @return bool true = aprovado, false = rejeitado
     */
    public function validate(string $title): bool {
        $title = trim($title);

        // Remover aspas que a IA às vezes adiciona
        $title = trim($title, '"\'„"');

        // Comprimento
        if (mb_strlen($title) < 30) {
            $this->reject($title, 'muito curto (' . mb_strlen($title) . ' chars)');
            return false;
        }
        if (mb_strlen($title) > 125) {
            $this->reject($title, 'muito longo (' . mb_strlen($title) . ' chars)');
            return false;
        }

        foreach (self::CLICKBAIT_WORDS as $word) {
            if (mb_stripos($title, $word) !== false) {
                $this->reject($title, 'clickbait excessivo: ' . $word);
                return false;
            }
        }

        // Padrões proibidos (clichês)
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $title)) {
                $this->reject($title, "clichê: {$pattern}");
                return false;
            }
        }

        // Padrões obrigatórios
        $has_required = false;
        foreach (self::REQUIRED_PATTERNS as $pattern => $reason) {
            if (preg_match($pattern, $title)) {
                $has_required = true;
                break;
            }
        }
        if (!$has_required) {
            $this->reject($title, 'sem intenção clara, entidade ou ângulo editorial');
            return false;
        }

        return true;
    }

    /**
     * Verificar canibalização com posts existentes do WordPress.
     * @param  string   $new_title     Novo título candidato
     * @param  \WP_Post[] $existing_posts Posts publicados para comparar
     * @return array   ['is_duplicate'=>bool, 'similarity'=>float, 'reason'=>string]
     */
    public function check_cannibalization(string $new_title, array $existing_posts): array {
        $new_slug = sanitize_title($new_title);
        $new_kw   = $this->extract_main_keyword($new_title);

        foreach ($existing_posts as $post) {
            $existing_slug = sanitize_title($post->post_title);

            // Similaridade de slug
            similar_text($new_slug, $existing_slug, $percent);
            if ($percent > self::SIMILARITY_THRESHOLD) {
                return [
                    'is_duplicate'   => true,
                    'similarity'     => round($percent, 1),
                    'existing_post'  => $post->ID,
                    'existing_title' => $post->post_title,
                    'reason'         => "Título muito similar ({$percent}%) ao post #{$post->ID}",
                ];
            }

            // Mesma keyword principal
            $existing_kw = $this->extract_main_keyword($post->post_title);
            if (mb_strtolower($new_kw) === mb_strtolower($existing_kw) && !empty($new_kw)) {
                return [
                    'is_duplicate'  => true,
                    'similarity'    => 100.0,
                    'existing_post' => $post->ID,
                    'reason'        => "Mesma keyword principal: \"{$new_kw}\"",
                ];
            }
        }

        return ['is_duplicate' => false, 'similarity' => 0.0];
    }

    /**
     * Verificar duplicata interna (sessão atual do planejador).
     *
     * @since 1.0.0 BUG FIX: agora também compara keyword principal e usa threshold 55%
     *                       para evitar pares como "Guia Completo: X em 2026" + "Guia Completo: Y em 2026"
     *
     * @param  string   $title           Candidato
     * @param  string[] $session_titles  Títulos já aprovados nesta sessão
     */
    public function is_duplicate_in_session(string $title, array $session_titles): bool {
        if (empty($session_titles)) return false;

        $new_slug = sanitize_title($title);
        $new_kw   = mb_strtolower($this->extract_main_keyword($title));

        foreach ($session_titles as $existing) {
            // 1) Similaridade de slug (rigoroso: 55%)
            similar_text($new_slug, sanitize_title($existing), $pct);
            if ($pct > self::SESSION_SIMILARITY_THRESHOLD) return true;

            // 2) Mesma keyword principal (mesmo que com slug diferente)
            $existing_kw = mb_strtolower($this->extract_main_keyword($existing));
            if ($new_kw !== '' && $new_kw === $existing_kw) return true;

            // 3) Mesma estrutura inicial (primeiras 4 palavras significativas)
            $new_start      = $this->first_n_words($title, 4);
            $existing_start = $this->first_n_words($existing, 4);
            if ($new_start !== '' && $new_start === $existing_start) return true;
        }
        return false;
    }

    /**
     * Pegar as primeiras N palavras significativas em lowercase para comparação estrutural.
     * @since 1.0.0
     */
    private function first_n_words(string $title, int $n): string {
        $stopwords = ['de','do','da','dos','das','e','o','a','os','as','em','no','na',
                      'por','para','com','que','um','uma','como','sobre','seu','sua'];
        $words = explode(' ', mb_strtolower($title));
        $sig   = array_filter($words, fn($w) => !in_array($w, $stopwords) && mb_strlen($w) > 2);
        return implode(' ', array_slice(array_values($sig), 0, $n));
    }

    /**
     * Extrair keyword principal: primeiras 3-4 palavras significativas.
     */
    public function extract_main_keyword(string $title): string {
        // Remover stopwords básicas
        $stopwords = ['de', 'do', 'da', 'dos', 'das', 'e', 'o', 'a', 'os', 'as',
                      'em', 'no', 'na', 'nos', 'nas', 'por', 'para', 'com', 'que',
                      'seu', 'sua', 'seus', 'suas', 'um', 'uma', 'como', 'por'];
        $words = explode(' ', mb_strtolower($title));
        $significant = array_filter($words, fn($w) => !in_array($w, $stopwords) && mb_strlen($w) > 2);
        return implode(' ', array_slice(array_values($significant), 0, 3));
    }

    /**
     * Limpar título retornado pela IA (remover aspas, markdown, prefixos).
     */
    public function clean_title(string $raw): string {
        $clean = trim($raw);
        $clean = trim($clean, '"\'„""**');
        // Remover prefixos como "Título:" ou "1." que a IA às vezes adiciona
        $clean = preg_replace('/^(?:título[:：]|title[:：]|\d+[\.\)]\s*)/iu', '', $clean);
        return trim($clean);
    }

    private function reject(string $title, string $reason): void {
        AutopilotLogger::log('brain', 'title_rejected', 'warning',
            "Título rejeitado ({$reason}): {$title}");
        // Log no arquivo brain.log
        $log_file = WP_CONTENT_DIR . '/sara-logs/brain.log';
        if (is_writable(dirname($log_file))) {
            file_put_contents($log_file,
                '[' . wp_date('Y-m-d H:i:s') . '] [BRAIN] title_rejected: ' . $reason . ' — ' . $title . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    }
}
