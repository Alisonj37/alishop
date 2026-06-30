<?php
namespace GeoMetodoSEO\Schema;

if (!defined('ABSPATH')) { exit; }

/**
 * SpeakableSchemaInjector — Adiciona Schema.org Speakable + Citation
 * pra tornar o conteúdo elegível pra citação em Google AI Overviews.
 *
 * March 2026: páginas citadas em AI Overviews ganham 35% mais clicks
 * que ranquear organicamente sozinho (LaunchCodex study).
 *
 * Funciona em 2 etapas:
 *   1. apply($post_id) — marca primeiro H2 + parágrafo com class "speakable-section"
 *   2. output_schema() — emite JSON-LD com SpeakableSpecification + Citations
 *
 * @since 1.0.0
 */
class SpeakableSchemaInjector {

    /**
     * Marca o primeiro H2 + parágrafo seguinte como "speakable".
     * Salva meta `geo_speakable_enabled` no post.
     */
    public static function apply(int $post_id): bool {
        if (!$post_id) return false;
        $enabled = (string) get_option('geo_speakable_schema_enabled', '1') === '1';
        if (!$enabled) return false;

        $post = get_post($post_id);
        if (!$post) return false;

        $content = (string) $post->post_content;

        // Adiciona class "speakable-section" ao primeiro H2 + próximo P
        $modified = preg_replace_callback(
            '/(<h2\b)([^>]*)(>.*?<\/h2>\s*<p\b)([^>]*)(>)/is',
            function($m) {
                // Se já marcado, retorna sem alteração
                if (strpos($m[2], 'speakable-section') !== false) return $m[0];
                if (strpos($m[4], 'speakable-section') !== false) return $m[0];
                // Adiciona class no H2
                $h2_attrs = self::add_class($m[2], 'speakable-section');
                // Adiciona class no P
                $p_attrs = self::add_class($m[4], 'speakable-section');
                return $m[1] . $h2_attrs . $m[3] . $p_attrs . $m[5];
            },
            $content,
            1
        );

        if ($modified !== null && $modified !== $content) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $modified]);
        }

        update_post_meta($post_id, 'geo_speakable_enabled', '1');
        return true;
    }

    /**
     * Helper: adiciona class a uma string de atributos HTML.
     */
    private static function add_class(string $attrs, string $class): string {
        if (preg_match('/class\s*=\s*(["\'])(.*?)\1/i', $attrs, $m)) {
            // Já tem class — adiciona se não existir
            if (strpos($m[2], $class) !== false) return $attrs;
            $new = 'class=' . $m[1] . trim($m[2] . ' ' . $class) . $m[1];
            return preg_replace('/class\s*=\s*(["\']).*?\1/i', $new, $attrs, 1);
        }
        // Sem class — adiciona
        return $attrs . ' class="' . $class . '"';
    }

    /**
     * Output JSON-LD com Speakable + Citations.
     * Hook: wp_head em single posts.
     */
    public static function output_schema(): void {
        if (!is_singular()) return;
        $post_id = get_the_ID();
        if (!$post_id) return;
        if (!get_post_meta($post_id, 'geo_speakable_enabled', true)) return;

        $schema = [
            '@context'  => 'https://schema.org',
            '@type'     => 'Article',
            'speakable' => [
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => ['.speakable-section'],
            ],
            'headline'      => get_the_title($post_id),
            'datePublished' => get_the_date('c', $post_id),
            'dateModified'  => get_the_modified_date('c', $post_id),
        ];

        // Adiciona citations se houver links pra autoridades
        $citations = self::extract_citations($post_id);
        if (!empty($citations)) {
            $schema['citation'] = $citations;
        }

        echo "\n" . '<script type="application/ld+json">'
           . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
           . '</script>' . "\n";
    }

    /**
     * Extrai citations de domínios de autoridade do conteúdo.
     */
    private static function extract_citations(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) return [];

        $authority_domains = [
            'wikipedia.org', 'britannica.com', '.gov.br', '.gov',
            'mckinsey.com', 'gartner.com', 'forrester.com',
            'searchengineland.com', 'searchenginejournal.com',
            'developers.google.com', 'support.google.com',
            'reuters.com', 'bbc.com', 'forbes.com',
        ];

        $citations = [];
        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $post->post_content, $m)) {
            foreach ($m[1] as $i => $url) {
                foreach ($authority_domains as $auth) {
                    if (stripos($url, $auth) !== false) {
                        $citations[] = [
                            '@type' => 'CreativeWork',
                            'url'   => esc_url_raw($url),
                            'name'  => wp_strip_all_tags((string)($m[2][$i] ?? '')),
                        ];
                        break;
                    }
                }
                if (count($citations) >= 5) break;
            }
        }

        return $citations;
    }
}
