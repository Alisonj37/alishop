<?php
namespace GeoMetodoSEO\Repositories;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher;

class PostRepository {

    private $allowed_statuses = ['publish', 'draft', 'pending', 'future'];

    public function create($title, $content, $excerpt = '', $slug = '', $post_status = 'draft', $scheduled_at = '', $category_name = '', $focus_keyword = '') {
        $status = in_array($post_status, $this->allowed_statuses, true) ? $post_status : 'draft';
        if ($status === 'pending') $status = 'draft';

        $category_ids = [];
        if (!empty($category_name)) {
            // Aceitar ID numérico direto — evita criar categoria nova por mismatch de nome
            if (is_numeric($category_name) && (int)$category_name > 0) {
                $term = get_term((int)$category_name, 'category');
                if ($term && !is_wp_error($term)) {
                    $category_ids[] = (int)$category_name;
                }
            } else {
                // Busca por nome — só cria categoria nova se não existir nenhuma similar
                $cat_id = get_cat_ID($category_name);
                if (!$cat_id) {
                    // Tentar busca case-insensitive antes de criar
                    $existing = get_terms([
                        'taxonomy'   => 'category',
                        'name'       => $category_name,
                        'hide_empty' => false,
                        'number'     => 1,
                    ]);
                    if (!empty($existing) && !is_wp_error($existing)) {
                        $cat_id = (int)$existing[0]->term_id;
                    } else {
                        $cat_id = wp_create_category(sanitize_text_field($category_name));
                    }
                }
                if ($cat_id && !is_wp_error($cat_id)) {
                    $category_ids[] = (int)$cat_id;
                }
            }
        }

        $result = GeoMetodoSEO_Publisher::publish([
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'status' => $status,
            'post_type' => 'post',
            'post_name' => $slug ?: '',
            'category_ids' => $category_ids,
            'scheduled_at' => $scheduled_at,
            'seo_title' => $title,
            'meta_description' => $excerpt,
            'focus_keyword' => $focus_keyword ?: $title,
            'source_module' => 'article_pipeline',
            'custom_meta' => [
                '_geo_generated_at' => current_time('mysql'),
            ],
        ]);

        if (empty($result['success'])) {
            return new \WP_Error('publisher_failed', $result['error'] ?? 'Falha ao criar post via Publisher central.');
        }

        return (int)$result['post_id'];
    }
}
