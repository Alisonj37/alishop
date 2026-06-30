<?php
namespace GeoMetodoSEO\Tools;

use GeoMetodoSEO\Services\ImageGeneratorService;
use GeoMetodoSEO\Services\SafeImageSideload;

if (!defined('ABSPATH')) exit;

/**
 * Recupera imagens externas antigas e garante midia local.
 */
class ImageReSideloader {

    /**
     * Dominios considerados externos temporarios - devem virar locais.
     * IMPORTANTE: image.pollinations.ai esta aqui APENAS para deteccao
     * de URLs LEGADAS em posts antigos. NUNCA e usado como gerador novo.
     * Pollinations foi removido como provider na v1.0.0.
     */
    const EXTERNAL_DOMAINS = [
        'replicate.delivery',
        'image.pollinations.ai',
        'tempfile.aiquickdraw.com',
        'cdn.naga.ac',
        'oaidalleapiprodscus.blob.core.windows.net',
        'cdn.openai.com',
    ];

    public static function register_hooks(): void {
        add_action('wp_ajax_geo_resideload_scan', [__CLASS__, 'ajax_scan']);
        add_action('wp_ajax_geo_resideload_run', [__CLASS__, 'ajax_run']);
        add_action('geo_resideload_daily', [__CLASS__, 'cron_daily']);
        if (!wp_next_scheduled('geo_resideload_daily')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'geo_resideload_daily');
        }
    }

    public static function unregister_cron(): void {
        $timestamp = wp_next_scheduled('geo_resideload_daily');
        if ($timestamp) wp_unschedule_event($timestamp, 'geo_resideload_daily');
    }

    public static function scan(int $days_back = 30, int $limit = 100): array {
        $args = [
            'post_type' => 'post',
            'post_status' => ['publish', 'draft', 'future'],
            'posts_per_page' => max(1, min(500, $limit)),
            'date_query' => [['after' => max(1, $days_back) . ' days ago']],
            'fields' => 'ids',
        ];
        $ids = get_posts($args);
        $items = [];
        foreach ($ids as $post_id) {
            $post = get_post((int)$post_id);
            if (!$post) continue;
            $external = self::find_external_urls((string)$post->post_content);
            $missing = self::count_missing_images((string)$post->post_content, (int)$post_id);
            if (!empty($external) || $missing > 0 || !has_post_thumbnail((int)$post_id)) {
                $items[] = [
                    'post_id' => (int)$post_id,
                    'title' => get_the_title((int)$post_id),
                    'external_urls' => count($external),
                    'missing_images' => $missing,
                    'has_featured' => has_post_thumbnail((int)$post_id),
                ];
            }
        }
        return ['total' => count($items), 'items' => $items];
    }

    public static function find_broken_imgs(string $content): array {
        preg_match_all('/<img\b[^>]*\bsrc=["\']?\s*["\']?[^>]*>/iu', $content, $m);
        return $m[0] ?? [];
    }

    public static function count_missing_images(string $content, int $post_id): int {
        preg_match_all('/<figure\b[^>]*class=["\'][^"\']*(?:sara-body-image|sara-internal-queued-image|geo-youtube-context-image)[^"\']*["\'][^>]*>/iu', $content, $m);
        $current = count($m[0] ?? []);
        return max(0, ImageGeneratorService::body_images_count() - $current);
    }

    public static function process_batch(int $batch_size = 5, int $days_back = 30): array {
        $scan = self::scan($days_back, $batch_size);
        $results = [];
        foreach (array_slice($scan['items'], 0, $batch_size) as $item) {
            $results[] = self::process_single_post((int)$item['post_id']);
        }
        return ['processed' => count($results), 'results' => $results];
    }

    public static function process_single_post(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) return ['post_id' => $post_id, 'success' => false, 'errors' => ['post_not_found']];

        $content = (string)$post->post_content;
        $log = ['post_id' => $post_id, 'fixed' => 0, 'regenerated' => 0, 'failed' => 0, 'errors' => []];

        foreach (self::find_external_urls($content) as $url) {
            $local = self::download_and_attach($url, $post_id, $post->post_title);
            if ($local) {
                $content = str_replace($url, $local, $content);
                $log['fixed']++;
            } else {
                $replacement = self::generate_contextual_image(self::extract_alt_for_url($content, $url), self::get_post_focus_keyword($post_id, $post->post_title), self::get_post_category_name($post_id), '', $post_id, $post->post_title);
                if ($replacement) {
                    $content = str_replace($url, $replacement, $content);
                    $log['fixed']++;
                    $log['regenerated']++;
                } else {
                    $log['failed']++;
                    $log['errors'][] = 'Falha ao recuperar URL externa.';
                }
            }
        }

        if (!has_post_thumbnail($post_id)) {
            $image = new ImageGeneratorService();
            $id = $image->generate_featured_attachment([
                'title' => $post->post_title,
                'keyword' => self::get_post_focus_keyword($post_id, $post->post_title),
                'category' => self::get_post_category_name($post_id),
            ], $post_id);
            if (!is_wp_error($id) && $id) \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::set_featured_image($post_id, (int)$id);
        }

        $missing = self::count_missing_images($content, $post_id);
        if ($missing > 0) {
            $content = self::inject_missing_body_images($content, $post_id, $post->post_title, $missing, $log);
        }

        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
        return $log + ['success' => $log['failed'] === 0];
    }

    private static function inject_missing_body_images(string $content, int $post_id, string $title, int $needed, array &$log): string {
        if (!preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $content, $matches, PREG_OFFSET_CAPTURE)) return $content;
        $image = new ImageGeneratorService();
        $inserted = 0;
        $interval = ImageGeneratorService::h2_interval();
        $h2_seen = 0;
        $shift = 0;
        foreach ($matches[0] as $idx => $match) {
            if ($inserted >= $needed) break;
            $h2_text = trim(wp_strip_all_tags($matches[1][$idx][0] ?? ''));
            if ($h2_text === '' || preg_match('/faq|perguntas\s+frequentes|conclus[aã]o/iu', $h2_text)) continue;
            $h2_seen++;
            if (($h2_seen % $interval) !== 0) continue;
            $pos = (int)$match[1] + $shift;
            $after = substr($content, $pos + strlen($match[0]), 400);
            if (preg_match('/<(?:figure|img)\b/i', $after)) continue;
            $id = $image->generate_body_attachment([
                'title' => $title,
                'keyword' => self::get_post_focus_keyword($post_id, $title),
                'category' => self::get_post_category_name($post_id),
                'section' => $h2_text,
            ], $post_id);
            if (is_wp_error($id) || !$id) continue;
            $alt = trim(self::get_post_focus_keyword($post_id, $title) . ' - ' . $h2_text);
            $fig = "\n<figure class=\"sara-body-image sara-internal-queued-image\" style=\"margin:28px 0;\">" . wp_get_attachment_image((int)$id, 'large', false, ['alt' => esc_attr($alt), 'loading' => 'lazy', 'style' => 'width:100%;height:auto;border-radius:8px;display:block;']) . "</figure>\n";
            $insert_at = $pos + strlen($match[0]);
            $content = substr($content, 0, $insert_at) . $fig . substr($content, $insert_at);
            $shift += strlen($fig);
            $inserted++;
            $log['regenerated']++;
        }
        return $content;
    }

    private static function get_post_focus_keyword(int $post_id, string $fallback_title = ''): string {
        return sanitize_text_field((string)(get_post_meta($post_id, '_geo_keyword', true) ?: $fallback_title));
    }

    private static function get_post_category_name(int $post_id): string {
        $cats = get_the_category($post_id);
        return !empty($cats[0]->name) ? sanitize_text_field((string)$cats[0]->name) : '';
    }

    public static function find_external_urls(string $content): array {
        preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/iu', $content, $matches);
        $found = [];
        foreach ($matches[1] ?? [] as $url) {
            foreach (self::EXTERNAL_DOMAINS as $domain) {
                if (stripos($url, $domain) !== false) {
                    $found[] = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
                    break;
                }
            }
        }
        return array_values(array_unique($found));
    }

    public static function download_and_attach(string $url, int $post_id, string $title): string {
        $id = SafeImageSideload::attachment_id($url, $post_id, $title);
        return $id ? (string)wp_get_attachment_url($id) : '';
    }

    private static function extract_alt_for_url(string $content, string $url): string {
        $quoted = preg_quote($url, '/');
        if (preg_match('/<img\b(?=[^>]*src=["\']' . $quoted . '["\'])[^>]*alt=["\']([^"\']*)["\']/iu', $content, $m)) {
            return sanitize_text_field(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        return '';
    }

    public static function generate_contextual_image(string $h2_text, string $keyword, string $category, string $paragraph_context, int $post_id, string $title): string {
        $image = new ImageGeneratorService();
        $id = $image->generate_body_attachment([
            'title' => $title,
            'keyword' => $keyword ?: $title,
            'category' => $category,
            'section' => trim($h2_text . ' ' . $paragraph_context),
        ], $post_id);
        return (!is_wp_error($id) && $id) ? (string)wp_get_attachment_url((int)$id) : '';
    }

    public static function build_master_prompt(string $h2_text, string $keyword, string $category, string $paragraph_context, string $title): string {
        return trim($title . ' ' . $keyword . ' ' . $category . ' ' . $h2_text . ' ' . $paragraph_context);
    }

    public static function record_failure_reason(string $type, string $message): void {
        $reasons = (array)get_option('geo_image_failure_reasons', []);
        $reasons[] = ['time' => time(), 'type' => sanitize_key($type), 'message' => sanitize_text_field($message)];
        update_option('geo_image_failure_reasons', array_slice($reasons, -50), false);
    }

    public static function regenerate_legacy_image(string $context, int $post_id, string $title): string {
        return self::generate_contextual_image($context, self::get_post_focus_keyword($post_id, $title), self::get_post_category_name($post_id), '', $post_id, $title);
    }

    public static function ajax_scan(): void {
        check_ajax_referer('geo_resideload_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada'], 403);
            return;
        }
        wp_send_json_success(self::scan(absint($_POST['days'] ?? 30), absint($_POST['limit'] ?? 100)));
    }

    public static function ajax_run(): void {
        check_ajax_referer('geo_resideload_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada'], 403);
            return;
        }
        wp_send_json_success(self::process_batch(absint($_POST['batch'] ?? 5), absint($_POST['days'] ?? 30)));
    }

    public static function cron_daily(): void {
        self::process_batch(5, 30);
    }
}

