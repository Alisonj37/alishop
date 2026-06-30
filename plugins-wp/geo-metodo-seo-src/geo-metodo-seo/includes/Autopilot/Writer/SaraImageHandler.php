<?php
namespace GeoMetodoSEO\Autopilot\Writer;

use GeoMetodoSEO\Autopilot\Shared\{AutopilotInstaller, AutopilotLogger};
use GeoMetodoSEO\Services\ImageGeneratorService;

if (!defined('ABSPATH')) exit;

/**
 * SaraImageHandler.
 * v1.0.0: imagens de IA passam pela cadeia central Fal.ai/Replicate.
 */
class SaraImageHandler {

    private string $provider;

    public function __construct() {
        $this->provider = AutopilotInstaller::get('writer_image_provider', 'ai_auto');
    }

    public function set_featured_image(int $post_id, string $keyword, string $title) {
        $keyword = $this->normalize_focus_keyword($keyword ?: $title);
        $title = trim((string)$title);

        $image = new ImageGeneratorService();
        $attachment_id = $image->generate_featured_attachment([
            'title' => $title,
            'keyword' => $keyword,
            'category' => $this->post_category_label($post_id),
        ], $post_id);

        if (!is_wp_error($attachment_id) && $attachment_id) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::set_featured_image($post_id, (int)$attachment_id);
            AutopilotLogger::log('writer', 'image_featured_ai', 'success', 'Featured image definida via cadeia Fal.ai/Replicate', [
                'post_id' => $post_id,
            ]);
            return (int)$attachment_id;
        }

        AutopilotLogger::log('writer', 'image_featured_required_failed', 'error', 'Featured obrigatoria falhou na cadeia IA configurada.', [
            'post_id' => $post_id,
        ]);

        return false;
    }

    public function inject_body_images(string $content, string $keyword, int $count = 0): string {
        $count = $count > 0 ? min(ImageGeneratorService::body_images_count(), absint($count)) : ImageGeneratorService::body_images_count();
        if (!preg_match_all('/(<h2[^>]*>)(.*?)(<\/h2>)/is', $content, $h2_matches, PREG_SET_ORDER)) {
            return $content;
        }

        $image = new ImageGeneratorService();
        $inserted = 0;
        $h2_seen = 0;
        $interval = ImageGeneratorService::h2_interval();

        foreach ($h2_matches as $match) {
            if ($inserted >= $count) break;
            $h2_text = trim(wp_strip_all_tags($match[2] ?? ''));
            if ($h2_text === '' || preg_match('/faq|perguntas\s+frequentes|conclus[aã]o/iu', $h2_text)) continue;
            $h2_seen++;
            if (($h2_seen % $interval) !== 0) continue;

            $pos = strpos($content, $match[0]);
            if ($pos === false) continue;
            $after = substr($content, $pos + strlen($match[0]), 400);
            if (preg_match('/<(?:figure|img)\b/i', $after)) continue;

            $attachment_id = $image->generate_body_attachment([
                'title' => $keyword,
                'keyword' => $keyword,
                'section' => $h2_text,
            ]);
            if (is_wp_error($attachment_id) || !$attachment_id) continue;

            $alt = $this->generate_alt_text($keyword, $h2_text);
            $figure = "\n<figure class=\"sara-body-image sara-internal-queued-image\" style=\"margin:28px 0;\">"
                . wp_get_attachment_image((int)$attachment_id, 'large', false, [
                    'alt' => esc_attr($alt),
                    'style' => 'width:100%;height:auto;border-radius:8px;display:block;',
                    'loading' => 'lazy',
                ])
                . '<figcaption style="font-size:12px;color:#6b7280;text-align:center;margin-top:6px;">' . esc_html($alt) . '</figcaption></figure>' . "\n";

            $insert_pos = $pos + strlen($match[0]);
            $content = substr($content, 0, $insert_pos) . $figure . substr($content, $insert_pos);
            $inserted++;
        }

        return $content;
    }

    public function fetch_url(string $query, string $orientation = 'landscape'): string {
        $providers = [$this->provider, 'unsplash', 'pexels', 'pixabay'];
        $providers = array_values(array_unique(array_filter($providers)));
        foreach ($providers as $provider) {
            $url = $this->fetch_from($this->to_stock_search_query($query), $orientation, $provider);
            if ($url) return $url;
        }
        return '';
    }

    private function fetch_from(string $query, string $orientation, string $provider): string {
        switch ($provider) {
            case 'unsplash':
                return $this->from_unsplash($query, $orientation);
            case 'pexels':
                return $this->from_pexels($query, $orientation);
            case 'pixabay':
                return $this->from_pixabay($query, $orientation);
            default:
                return '';
        }
    }

    private function from_unsplash(string $query, string $orientation): string {
        $key = get_option('geo_unsplash_api_key', '');
        if (!$key) return '';
        $r = wp_remote_get('https://api.unsplash.com/search/photos?' . http_build_query([
            'query' => $query,
            'per_page' => 3,
            'orientation' => $orientation === 'portrait' ? 'portrait' : 'landscape',
        ]), ['timeout' => 8, 'headers' => ['Authorization' => 'Client-ID ' . $key]]);
        if (is_wp_error($r)) return '';
        $data = json_decode(wp_remote_retrieve_body($r), true);
        return esc_url_raw((string)($data['results'][0]['urls']['regular'] ?? ''));
    }

    private function from_pexels(string $query, string $orientation): string {
        $key = get_option('geo_pexels_api_key', '');
        if (!$key) return '';
        $r = wp_remote_get('https://api.pexels.com/v1/search?' . http_build_query([
            'query' => $query,
            'per_page' => 3,
            'orientation' => $orientation === 'portrait' ? 'portrait' : 'landscape',
        ]), ['timeout' => 8, 'headers' => ['Authorization' => $key]]);
        if (is_wp_error($r)) return '';
        $data = json_decode(wp_remote_retrieve_body($r), true);
        return esc_url_raw((string)($data['photos'][0]['src']['large'] ?? ''));
    }

    private function from_pixabay(string $query, string $orientation): string {
        $key = get_option('geo_pixabay_api_key', '');
        if (!$key) return '';
        $r = wp_remote_get('https://pixabay.com/api/?' . http_build_query([
            'key' => $key,
            'q' => $query,
            'image_type' => 'photo',
            'orientation' => $orientation === 'portrait' ? 'vertical' : 'horizontal',
            'per_page' => 3,
            'safesearch' => 'true',
        ]), ['timeout' => 8]);
        if (is_wp_error($r)) return '';
        $data = json_decode(wp_remote_retrieve_body($r), true);
        return esc_url_raw((string)($data['hits'][0]['largeImageURL'] ?? ''));
    }

    private function import_to_library(string $url, int $post_id, string $title) {
        if (!class_exists('\\GeoMetodoSEO\\Services\\SafeImageSideload')) {
            return false;
        }
        $id = \GeoMetodoSEO\Services\SafeImageSideload::attachment_id($url, $post_id, $title);
        return $id ?: false;
    }

    private function normalize_focus_keyword(string $keyword): string {
        $keyword = trim(wp_strip_all_tags($keyword));
        $keyword = preg_replace('/\s+/u', ' ', $keyword) ?? $keyword;
        return trim(mb_substr($keyword, 0, 160));
    }

    private function build_stock_search_query(string $keyword, string $title = '', string $section = ''): string {
        $base = trim($section !== '' ? $section : ($keyword !== '' ? $keyword : $title));
        $base = strtolower(remove_accents($base));
        $base = preg_replace('/[^a-z0-9\s]+/', ' ', $base) ?? $base;
        $base = preg_replace('/\s+/', ' ', $base) ?? $base;
        $words = array_values(array_filter(explode(' ', trim($base))));
        return trim(implode(' ', array_slice($words, 0, 6))) ?: trim($keyword ?: $title);
    }

    private function to_stock_search_query(string $query): string {
        if (preg_match('/Main keyword:\s*([^\.]+)/iu', $query, $m)) {
            return $this->build_stock_search_query(trim($m[1]), trim($m[1]));
        }
        return $this->build_stock_search_query($query, $query);
    }

    private function generate_alt_text(string $keyword, string $h2_text): string {
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', '', mb_substr($h2_text, 0, 80));
        return trim($keyword . ': ' . $clean);
    }

    private function post_category_label(int $post_id): string {
        $cats = get_the_category($post_id);
        if (!empty($cats) && !empty($cats[0]->name)) {
            return (string)$cats[0]->name;
        }
        return '';
    }
}
