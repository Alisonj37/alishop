<?php
namespace GeoMetodoSEO\SEO;

if (!defined('ABSPATH')) { exit; }

class InternalLinkingService {

    private $max_links = 3;

    public function apply($post_id, $content) {
        $related     = $this->find_related_posts($post_id);
        $links_added = 0;

        foreach ($related as $post) {
            if ($links_added >= $this->max_links) break;

            $url = get_permalink($post->ID);
            if (strpos($content, $url) !== false) continue;

            $keywords = $this->extract_keywords_from_title($post->post_title);

            foreach ($keywords as $keyword) {
                if (strlen($keyword) < 4) continue;

                $link = '<a href="' . esc_url($url) . '" title="' . esc_attr($post->post_title) . '">' . esc_html($keyword) . '</a>';

                $new_content = $this->replace_outside_tags($content, $keyword, $link);

                if ($new_content !== $content) {
                    $content = $new_content;
                    $links_added++;
                    break;
                }
            }
        }

        return $content;
    }

    /**
     * Substitui keyword APENAS em texto fora de tags HTML.
     * Evita quebrar atributos href, src, title, etc.
     */
    private function replace_outside_tags($content, $keyword, $replacement) {
        $parts    = preg_split('/(<!--.*?-->|<[^>]+>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $replaced = false;
        $result   = '';

        foreach ($parts as $part) {
            // Tag HTML ou comentário — não tocar
            if (preg_match('/^(<[^>]+>|<!--.*-->)$/s', $part)) {
                $result .= $part;
                continue;
            }

            // Texto puro — substituir no máximo uma vez
            if (!$replaced && stripos($part, $keyword) !== false) {
                $escaped  = preg_quote($keyword, '/');
                $pattern  = '/(?<![\\w\\-])' . $escaped . '(?![\\w\\-])/iu';
                $new_part = preg_replace($pattern, $replacement, $part, 1, $count);
                if ($count > 0) {
                    $part     = $new_part;
                    $replaced = true;
                }
            }

            $result .= $part;
        }

        return $result;
    }

    private function extract_keywords_from_title($title) {
        $stop = ['como','para','com','sem','por','que','uma','seu','sua',
                 'aos','das','dos','nas','nos','de','do','da','em',
                 'o','a','e','os','as','um','se','ao','no','na'];

        $keywords = [$title]; // título completo = âncora mais específica

        $words = preg_split('/[\s\-\/]+/', strtolower(strip_tags($title)));
        foreach ($words as $word) {
            $word = trim($word, '.,;:!?\'\"()[]');
            if (strlen($word) >= 4 && !in_array($word, $stop, true)) {
                $keywords[] = $word;
            }
        }

        return $keywords;
    }

    private function find_related_posts($post_id) {
        return get_posts([
            'post_type'      => 'post',
            'post__not_in'   => [$post_id ?: 0],
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
    }
}
