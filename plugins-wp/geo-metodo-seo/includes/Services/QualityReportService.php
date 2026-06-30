<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

class QualityReportService {
    public static function analyze(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'message' => 'Post não encontrado.'];
        }

        $content = (string) $post->post_content;
        $plain = trim(wp_strip_all_tags($content));
        $words = str_word_count($plain);
        $h2 = preg_match_all('/<h2\b[^>]*>/i', $content);
        $h3 = preg_match_all('/<h3\b[^>]*>/i', $content);
        $images = preg_match_all('/<img\b[^>]*>/i', $content);
        $internal = preg_match_all('/<a\b[^>]+href=["\']' . preg_quote(home_url(), '/') . '[^"\']*["\']/i', $content);
        $external = preg_match_all('/<a\b[^>]+href=["\']https?:\/\/(?!' . preg_quote(parse_url(home_url(), PHP_URL_HOST) ?: '', '/') . ')[^"\']+["\']/i', $content);
        $has_featured = has_post_thumbnail($post_id);
        $has_faq = (stripos($content, 'FAQPage') !== false || stripos($content, 'Perguntas frequentes') !== false || get_post_meta($post_id, 'geo_faq_schema', true));
        $has_schema = (bool) (get_post_meta($post_id, 'geo_faq_schema', true) || get_post_meta($post_id, '_geo_glossary_definedterm_schema', true));
        $has_author = (stripos($content, 'geo-author') !== false || stripos($content, 'sara-author') !== false || stripos($content, 'autor') !== false);
        $rank_focus = get_post_meta($post_id, 'rank_math_focus_keyword', true) ?: get_post_meta($post_id, '_rank_math_focus_keyword', true);
        $rank_title = get_post_meta($post_id, 'rank_math_title', true);
        $rank_desc = get_post_meta($post_id, 'rank_math_description', true);

        $score = 0;
        $checks = [];
        $add = function(string $label, bool $ok, int $points, string $hint = '') use (&$score, &$checks) {
            if ($ok) $score += $points;
            $checks[] = ['label' => $label, 'ok' => $ok, 'points' => $ok ? $points : 0, 'max' => $points, 'hint' => $hint];
        };

        $add('Título definido', strlen($post->post_title) >= 35 && strlen($post->post_title) <= 120, 8, 'Use título claro com intenção real de busca.');
        $add('Conteúdo com profundidade', $words >= 900, 12, 'Para artigo/glossário, mire pelo menos 900 palavras úteis.');
        $add('Estrutura H2/H3', $h2 >= 4, 10, 'Use H2 suficientes para cobrir subtópicos.');
        $add('Imagem destacada', $has_featured, 8, 'Configure imagem destacada.');
        $add('Imagem no corpo', $images >= 1, 8, 'Inclua imagem interna quando fizer sentido.');
        $add('FAQ presente', (bool) $has_faq, 10, 'FAQ ajuda AEO e respostas por IA.');
        $add('Schema presente', $has_schema, 10, 'FAQPage/DefinedTerm/Article fortalecem dados estruturados.');
        $add('Link interno', $internal >= 1, 8, 'Linke para conteúdo relacionado do próprio site.');
        $add('Link externo útil', $external >= 1, 6, 'Use fontes oficiais quando fizer sentido.');
        $add('Rank Math foco', !empty($rank_focus), 6, 'Preencha palavra-chave foco.');
        $add('Rank Math título', !empty($rank_title), 5, 'Preencha SEO title.');
        $add('Rank Math descrição', !empty($rank_desc), 5, 'Preencha meta description.');
        $add('Autor/E-E-A-T', (bool) $has_author, 4, 'Inclua ecossistema do autor.');

        return [
            'success' => true,
            'post_id' => $post_id,
            'title' => get_the_title($post_id),
            'edit_url' => get_edit_post_link($post_id),
            'view_url' => get_permalink($post_id),
            'score' => min(100, $score),
            'words' => $words,
            'h2' => $h2,
            'h3' => $h3,
            'images' => $images,
            'internal_links' => $internal,
            'external_links' => $external,
            'rank_math' => [
                'focus_keyword' => (string) $rank_focus,
                'title' => (string) $rank_title,
                'description' => (string) $rank_desc,
            ],
            'checks' => $checks,
        ];
    }
}
