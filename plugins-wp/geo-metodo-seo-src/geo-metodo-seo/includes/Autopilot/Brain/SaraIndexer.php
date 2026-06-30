<?php
namespace GeoMetodoSEO\Autopilot\Brain;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;

/**
 * SaraIndexer — Indexador semântico incremental.
 * Agente A lê o índice para planejar. Agente B escreve após publicar.
 */
class SaraIndexer {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'sara_semantic_index';
    }

    /**
     * Indexação incremental: apenas posts novos/modificados desde a última indexação.
     */
    public function index_incremental(): int {
        global $wpdb;
        $t0 = microtime(true);

        $last_indexed = get_option('sara_last_indexed_at', '1970-01-01 00:00:00');

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_date, post_modified, post_excerpt, post_content
             FROM {$wpdb->posts}
             WHERE post_type = 'post'
               AND post_status = 'publish'
               AND post_modified > %s
             ORDER BY post_modified DESC
             LIMIT 200",
            $last_indexed
        ), ARRAY_A);

        $indexed = 0;
        foreach ($posts as $post) {
            $this->index_post($post);
            $indexed++;
        }

        update_option('sara_last_indexed_at', current_time('mysql'));

        AutopilotLogger::log('brain', 'indexer_incremental', 'success',
            "Indexados {$indexed} posts", [
                'duration_ms' => AutopilotLogger::elapsed($t0),
            ]
        );

        return $indexed;
    }

    /** Indexar / atualizar um post específico */
    public function index_post(array $post): void {
        global $wpdb;

        $post_id   = (int) $post['ID'];
        $keyword   = get_post_meta($post_id, '_geo_keyword', true) ?: $post['post_title'];
        $cats      = get_the_category($post_id);
        $cat_id    = $cats ? (int)$cats[0]->term_id : 0;
        $word_cnt  = str_word_count(strip_tags($post['post_content']));
        $permalink = get_permalink($post_id);

        // Scores do plugin principal
        $seo_score  = (int) get_post_meta($post_id, '_geo_seo_score', true);
        $geo_score  = (int) get_post_meta($post_id, '_geo_geo_score', true);
        $eeat_score = (int) get_post_meta($post_id, '_geo_eeat_score', true);

        // Entidades e LSI do plugin
        $entities    = get_post_meta($post_id, '_geo_entities', true) ?: '[]';
        $lsi         = get_post_meta($post_id, '_geo_lsi_keywords', true) ?: '[]';

        // Links internos (posts que linkamos)
        $int_links = $this->extract_internal_links($post['post_content']);

        // Intenção de busca (heurística simples por keyword)
        $intent = $this->detect_intent($keyword);

        // Tipo de conteúdo por word count
        $content_type = $word_cnt > 2500 ? 'pillar' : ($word_cnt > 1200 ? 'cluster' : 'faq');

        $row = [
            'post_id'       => $post_id,
            'keyword'       => mb_substr($keyword, 0, 255),
            'title'         => mb_substr($post['post_title'], 0, 500),
            'url'           => $permalink,
            'category_id'   => $cat_id,
            'word_count'    => $word_cnt,
            'seo_score'     => $seo_score,
            'geo_score'     => $geo_score,
            'eeat_score'    => $eeat_score,
            'entities'      => is_string($entities) ? $entities : wp_json_encode($entities),
            'lsi_keywords'  => is_string($lsi) ? $lsi : wp_json_encode($lsi),
            'internal_links'=> wp_json_encode($int_links),
            'search_intent' => $intent,
            'content_type'  => $content_type,
            'indexed_at'    => current_time('mysql'),
        ];

        // Upsert
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE post_id = %d", $post_id
        ));

        if ($existing) {
            unset($row['indexed_at']);
            $wpdb->update($this->table, $row, ['post_id' => $post_id]);
        } else {
            $wpdb->insert($this->table, $row);
        }
    }

    /** Remover post do índice (quando deletado) */
    public function remove_post(int $post_id): void {
        global $wpdb;
        $wpdb->delete($this->table, ['post_id' => $post_id]);
    }

    /** Buscar posts do índice por similaridade semântica (keyword LIKE) */
    public function find_related(string $keyword, int $limit = 5, int $exclude_id = 0): array {
        global $wpdb;
        $words = array_filter(array_unique(explode(' ', strtolower($keyword))));
        $words = array_slice($words, 0, 3); // 3 palavras mais significativas

        if (empty($words)) return [];

        $conditions = implode(' OR ', array_fill(0, count($words), 'keyword LIKE %s'));
        $args       = array_map(fn($w) => '%' . $wpdb->esc_like($w) . '%', $words);
        $args[]     = $exclude_id;
        $args[]     = $limit;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, title, keyword, url, seo_score
             FROM {$this->table}
             WHERE ({$conditions}) AND post_id != %d
             ORDER BY seo_score DESC
             LIMIT %d",
            ...$args
        ), ARRAY_A) ?: [];
    }

    /** Keywords já cobertas por categoria */
    public function covered_keywords(int $category_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT keyword, title FROM {$this->table} WHERE category_id = %d",
            $category_id
        ), ARRAY_A);
        return array_column($rows ?: [], 'keyword');
    }

    /** Posts com decay (content decay score > threshold) */
    public function decaying_posts(int $threshold = 30): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, title, keyword, url, decay_score, updated_at
             FROM {$this->table}
             WHERE decay_score >= %d
             ORDER BY decay_score DESC
             LIMIT 20",
            $threshold
        ), ARRAY_A) ?: [];
    }

    /** Atualizar decay score de um post */
    public function update_decay(int $post_id, int $score): void {
        global $wpdb;
        $wpdb->update($this->table, ['decay_score' => $score], ['post_id' => $post_id]);
    }

    /** Total de posts indexados */
    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    private function detect_intent(string $keyword): string {
        $kw = strtolower($keyword);
        if (preg_match('/^(comprar|preço|melhor|top|versus|vs\.?|comparar)/u', $kw)) return 'comercial';
        if (preg_match('/^(como|passo\s?a\s?passo|tutorial|guia|criar|fazer|instalar)/u', $kw)) return 'informacional';
        if (preg_match('/^(o\s?que\s?é|definição|significa|conceito)/u', $kw)) return 'informacional';
        return 'informacional';
    }

    private function extract_internal_links(string $content): array {
        $site = home_url();
        preg_match_all('/<a[^>]+href=["\'](' . preg_quote($site, '/') . '[^"\']+)["\'][^>]*>([^<]+)<\/a>/i', $content, $m);
        $links = [];
        foreach (($m[1] ?? []) as $i => $url) {
            $links[] = ['url' => $url, 'anchor' => trim($m[2][$i] ?? '')];
        }
        return array_slice($links, 0, 10);
    }
}
