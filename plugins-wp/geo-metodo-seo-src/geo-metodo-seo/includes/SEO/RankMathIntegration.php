<?php
namespace GeoMetodoSEO\SEO;

if (!defined('ABSPATH')) { exit; }

class RankMathIntegration {

    public function apply($post_id, $title, $keyword, $description = '') {
        // Salva sempre: as metas são seguras mesmo se Rank Math estiver temporariamente desativado.
        // Isso preserva a keyword exata para o plugin e deixa pronta quando Rank Math estiver ativo.
        update_post_meta($post_id, 'rank_math_title', $title);
        update_post_meta($post_id, 'rank_math_description', $description ?: substr(strip_tags($title), 0, 160));
        $exact_keyword = trim(wp_strip_all_tags((string)$keyword));
        update_post_meta($post_id, 'rank_math_focus_keyword', $exact_keyword);
        update_post_meta($post_id, '_rank_math_focus_keyword', $exact_keyword);
        update_post_meta($post_id, '_geo_keyword_exact', $exact_keyword);
    }
}
