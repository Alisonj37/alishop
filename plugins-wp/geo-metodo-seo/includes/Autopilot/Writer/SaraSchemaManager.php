<?php
namespace GeoMetodoSEO\Autopilot\Writer;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller;

/**
 * SaraSchemaManager — Gera e salva schemas JSON-LD.
 * Reutiliza o sistema de schema do plugin principal via post_meta.
 * 6 tipos: Article, FAQPage, HowTo, BreadcrumbList, Organization, WebSite.
 */
class SaraSchemaManager {

    /**
     * Gerar e salvar todos os schemas necessários para um post.
     */
    public function apply(int $post_id, array $plan, string $content): void {
        $post      = get_post($post_id);
        if (!$post) return;

        $permalink = get_permalink($post_id);
        $site_url  = get_site_url();
        $site_name = get_bloginfo('name');
        $date_pub  = $post->post_date;
        $date_mod  = $post->post_modified;
        $title     = $post->post_title;
        $excerpt   = wp_strip_all_tags($post->post_excerpt ?: wp_trim_words($content, 30));
        $wc        = str_word_count(strip_tags($content));
        $lang      = get_bloginfo('language') ?: 'pt-BR';
        $keyword   = $plan['keyword'] ?? '';

        $author_name = get_option('geo_author_name', $site_name);
        $author_url  = get_option('geo_author_editorial_page', $site_url);

        // Obter categoria para breadcrumb
        $cats   = get_the_category($post_id);
        $cat    = $cats ? $cats[0] : null;

        $graph = [];

        // ── Article / HowTo / FAQPage ────────────────────────────────────
        $schema_type = $plan['schema_type'] ?? 'Article';
        $article = [
            '@type'            => $schema_type,
            '@id'              => $permalink . '#article',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $permalink],
            'headline'         => mb_substr($title, 0, 110),
            'description'      => mb_substr($excerpt, 0, 160),
            'url'              => $permalink,
            'datePublished'    => date('c', strtotime($date_pub)),
            'dateModified'     => date('c', strtotime($date_mod)),
            'wordCount'        => $wc,
            'inLanguage'       => $lang,
            'keywords'         => $keyword,
            'author'           => [
                '@type'    => 'Person',
                '@id'      => $site_url . '/#author',
                'name'     => $author_name,
                'url'      => $author_url,
            ],
            'publisher'        => [
                '@type' => 'Organization',
                '@id'   => $site_url . '/#organization',
                'name'  => $site_name,
            ],
            'speakable'        => [
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => ['.sara-quick-answer', 'h1', '.entry-content p:first-of-type'],
            ],
        ];

        $thumb = get_the_post_thumbnail_url($post_id, 'large');
        if ($thumb) {
            $article['image'] = [
                '@type' => 'ImageObject',
                'url'   => $thumb,
                '@id'   => $permalink . '#primaryimage',
            ];
        }

        $graph[] = $article;

        // ── FAQPage — extrair do conteúdo ────────────────────────────────
        $faq_entities = $this->extract_faq($content);
        if (!empty($faq_entities)) {
            $graph[] = [
                '@type'      => 'FAQPage',
                '@id'        => $permalink . '#faqpage',
                'mainEntity' => $faq_entities,
            ];
            update_post_meta($post_id, 'geo_faq_schema', wp_json_encode([
                '@type'      => 'FAQPage',
                'mainEntity' => $faq_entities,
            ]));
        }

        // ── BreadcrumbList ───────────────────────────────────────────────
        $breadcrumbs = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $site_url . '/'],
        ];
        if ($cat) {
            $breadcrumbs[] = [
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => $cat->name,
                'item'     => get_category_link($cat->term_id),
            ];
            $breadcrumbs[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $title];
        } else {
            $breadcrumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $title];
        }
        $graph[] = [
            '@type'           => 'BreadcrumbList',
            '@id'             => $permalink . '#breadcrumb',
            'itemListElement' => $breadcrumbs,
        ];

        // Salvar o schema no post_meta (o wp_head do plugin principal vai renderizar)
        $schema = ['@context' => 'https://schema.org', '@graph' => $graph];
        update_post_meta($post_id, 'geo_article_schema', wp_json_encode($schema));

        // Garantir que o post tem _geo_keyword para o wp_head funcionar
        if (empty(get_post_meta($post_id, '_geo_keyword', true))) {
            update_post_meta($post_id, '_geo_keyword', $keyword);
        }
    }

    private function extract_faq(string $content): array {
        $entities = [];

        // Formato sara-faq-item
        preg_match_all(
            '/<div[^>]*sara-faq-item[^>]*>\s*<strong[^>]*>(.*?)<\/strong>\s*<p[^>]*>(.*?)<\/p>/si',
            $content, $m, PREG_SET_ORDER
        );

        foreach (array_slice($m, 0, 10) as $pair) {
            $q = trim(strip_tags($pair[1]));
            $a = trim(strip_tags($pair[2]));
            if ($q && $a) {
                $entities[] = [
                    '@type' => 'Question',
                    'name'  => $q,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => mb_substr($a, 0, 500)],
                ];
            }
        }

        // Fallback: geo-faq-item
        if (empty($entities)) {
            preg_match_all(
                '/<div[^>]*geo-faq-item[^>]*>\s*<strong[^>]*>(.*?)<\/strong>\s*<p[^>]*>(.*?)<\/p>/si',
                $content, $m2, PREG_SET_ORDER
            );
            foreach (array_slice($m2, 0, 10) as $pair) {
                $q = trim(strip_tags($pair[1]));
                $a = trim(strip_tags($pair[2]));
                if ($q && $a) {
                    $entities[] = [
                        '@type' => 'Question',
                        'name'  => $q,
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => mb_substr($a, 0, 500)],
                    ];
                }
            }
        }

        return $entities;
    }
}
