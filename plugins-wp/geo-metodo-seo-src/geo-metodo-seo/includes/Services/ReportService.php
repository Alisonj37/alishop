<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

class ReportService {

    public function get_articles($filters = []) {
        $args = [
            'post_type'      => 'post',
            'posts_per_page' => (int) ($filters['limit'] ?? 50),
            'meta_query'     => [
                ['key' => '_geo_keyword', 'compare' => 'EXISTS']
            ]
        ];

        if (!empty($filters['date'])) {
            $args['date_query'] = [['after' => $filters['date']]];
        }

        return get_posts($args);
    }

    public function format_data($posts) {
        $data = [];

        foreach ($posts as $post) {
            $data[] = [
                'id'        => $post->ID,
                'title'     => $post->post_title,
                'date'      => $post->post_date,
                'words'     => str_word_count(strip_tags($post->post_content)),
                'status'    => $post->post_status,
                'has_image' => has_post_thumbnail($post->ID) ? 'Sim' : 'Nao',
                'keyword'   => get_post_meta($post->ID, '_geo_keyword', true),
                'provider'  => get_post_meta($post->ID, '_geo_provider', true),
            ];
        }

        return $data;
    }
}
