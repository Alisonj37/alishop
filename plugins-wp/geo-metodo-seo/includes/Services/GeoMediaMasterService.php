<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Writer\SaraImageHandler;
use GeoMetodoSEO\Autopilot\Writer\SaraImageQueue;

/**
 * GeoMediaMasterService
 * Camada global de garantia de mídia para todos os fluxos do plugin.
 * Garante imagem destacada, imagens internas, localização na biblioteca e auditoria final.
 */
class GeoMediaMasterService {

    public function ensure_post_media(int $post_id, array $args = []): array {
        $defaults = [
            'keyword' => '',
            'title' => '',
            'body_images' => ImageGeneratorService::body_images_count(),
            'require_featured' => ImageGeneratorService::featured_required(),
            'process_now' => false,
            'allow_featured_as_body_fallback' => false,
            'category' => '',
            'niche' => '',
        ];
        $args = wp_parse_args($args, $defaults);

        $post = get_post($post_id);
        if (!$post) return ['success' => false, 'reason' => 'post_not_found'];

        $title = trim((string)($args['title'] ?: $post->post_title));
        $keyword = trim((string)($args['keyword'] ?: get_post_meta($post_id, '_geo_keyword', true) ?: $title));
        $body_target = max(0, min(ImageGeneratorService::body_images_count(), (int)$args['body_images']));

        $result = [
            'success' => false,
            'featured_added' => false,
            'featured_id' => (int)get_post_thumbnail_id($post_id),
            'body_requested' => $body_target,
            'body_before' => $this->count_body_images((string)$post->post_content),
            'body_final_count' => 0,
            'body_added' => 0,
            'errors' => [],
        ];

        $this->maybe_load_autopilot_media_classes();

        // FIX v1.0.0-IMAGES:
        // Se artigo vai ter body images, OBRIGATORIAMENTE precisa ter featured também.
        // Caso contrário, ficava o cenário: só featured / só body / nenhum.
        // Agora: se require_featured veio false MAS body_target > 0, força require_featured.
        $force_featured = !empty($args['require_featured']) || $body_target > 0;

        if ($force_featured && $result['featured_id'] <= 0 && class_exists('GeoMetodoSEO\\Autopilot\\Writer\\SaraImageHandler')) {
            // Retry: até 2 tentativas com fallback de provider
            $max_attempts = 2;
            for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
                try {
                    $handler = new SaraImageHandler();
                    $featured_id = $handler->set_featured_image($post_id, $keyword, $title);
                    if ($featured_id) {
                        $result['featured_id'] = (int)$featured_id;
                        $result['featured_added'] = true;
                        break;
                    }
                    if ($attempt === $max_attempts) {
                        $result['errors'][] = 'featured_failed_after_' . $max_attempts . '_attempts';
                        $this->log('warning', "GeoMediaMaster: featured falhou após {$max_attempts} tentativas", $post_id);
                    }
                } catch (\Throwable $e) {
                    if ($attempt === $max_attempts) {
                        $result['errors'][] = 'featured_exception: ' . $e->getMessage();
                        $this->log('error', 'GeoMediaMaster: featured exception — ' . $e->getMessage(), $post_id);
                    }
                }
                // Pequena pausa entre tentativas
                if ($attempt < $max_attempts) usleep(500000); // 0.5s
            }
        }

        if ($body_target > 0) {
            $post = get_post($post_id);
            $content = (string)($post ? $post->post_content : '');
            // v1.0.0: NÃO remover imagens existentes antes de gerar novas.
            // A remoção destrutiva era a principal causa de instabilidade: a primeira camada
            // gerava imagens e a auditoria final apagava tudo para tentar reconstruir.
            // Agora a auditoria apenas completa o que falta.
            $current = $this->count_body_images($content);
            $missing = max(0, $body_target - $current);

            if ($missing > 0 && class_exists('GeoMetodoSEO\\Autopilot\\Writer\\SaraImageQueue')) {
                try {
                    SaraImageQueue::enqueue($post_id, $keyword, $missing, [
                        'title' => $title,
                        'category' => (string)$args['category'],
                        'niche' => (string)$args['niche'],
                    ]);
                    if (!empty($args['process_now'])) SaraImageQueue::process($post_id);
                } catch (\Throwable $e) {
                    $result['errors'][] = 'queue_exception: ' . $e->getMessage();
                    $this->log('warning', 'GeoMediaMaster: queue exception — ' . $e->getMessage(), $post_id);
                }
            }

            $post = get_post($post_id);
            $content = (string)($post ? $post->post_content : '');
            $after_queue = $this->count_body_images($content);
            $missing_after_queue = max(0, $body_target - $after_queue);

            if ($missing_after_queue > 0 && !empty($args['process_now'])) {
                $content = $this->insert_fallback_body_images($post_id, $content, $keyword, $title, $missing_after_queue);
            }

            // v1.0.0: preservar imagens externas existentes. Remover URL externa depois
            // de uma tentativa parcial pode deixar o artigo sem imagem. A correção agora é
            // completar o que falta, não apagar o que já está visível.
            $content = $this->dedupe_consecutive_images($content);
            $content = $this->limit_body_images($content, $body_target);
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
        }

        $post = get_post($post_id);
        $content = (string)($post ? $post->post_content : '');
        $final_count = $this->count_body_images($content);
        $result['featured_id'] = (int)get_post_thumbnail_id($post_id);

        update_post_meta($post_id, '_geo_featured_image_ok', $result['featured_id'] > 0 ? '1' : '0');
        update_post_meta($post_id, '_geo_body_images_count', $final_count);
        update_post_meta($post_id, '_geo_media_status', ($result['featured_id'] > 0 && $final_count >= $body_target) ? 'ok' : 'partial');
        update_post_meta($post_id, '_geo_media_last_run', current_time('mysql'));

        $result['body_final_count'] = $final_count;
        $result['body_added'] = max(0, $final_count - (int)$result['body_before']);
        $result['success'] = ($result['featured_id'] > 0 && $final_count >= $body_target);

        $this->log($result['success'] ? 'success' : 'warning', 'GeoMediaMaster: auditoria final de mídia', $post_id, $result);
        return $result;
    }

    public function ensure_story_slides_media(array $slides, string $keyword, string $provider = 'auto'): array {
        $out = [];
        foreach ($slides as $i => $slide) {
            if (!is_array($slide)) continue;
            $prompt = (string)($slide['image_prompt'] ?? ($keyword . ' slide ' . ($i + 1)));
            $url = (string)($slide['image_url'] ?? '');
            if (!$url) $url = $this->generate_story_image_url($prompt, $provider, $i, $keyword);
            $local = $this->localize_remote_image($url, 0, $keyword . '-webstory-slide-' . ($i + 1));
            $slide['image_url'] = !empty($local['url']) ? $local['url'] : $url;
            if (!empty($local['attachment_id'])) $slide['attachment_id'] = (int)$local['attachment_id'];
            $out[] = $slide;
        }
        return $out;
    }

    public function localize_remote_image(string $url, int $post_id = 0, string $title = 'image'): array {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return ['attachment_id' => 0, 'url' => ''];
        $attachment_id = SafeImageSideload::attachment_id($url, $post_id, $title);
        if (!$attachment_id) return ['attachment_id' => 0, 'url' => ''];
        $alt = trim(wp_strip_all_tags($title));
        if ($alt !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', mb_substr($alt, 0, 160));
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                'ID' => $attachment_id,
                'post_title' => mb_substr($alt, 0, 120),
                'post_excerpt' => mb_substr($alt, 0, 160),
            ]);
        }
        return ['attachment_id' => $attachment_id, 'url' => wp_get_attachment_url($attachment_id) ?: ''];
    }

    private function count_body_images(string $content): int {
        preg_match_all('/<figure[^>]*class=["\'][^"\']*(?:sara-body-image|sara-internal-queued-image|geo-youtube-context-image)[^"\']*["\'][^>]*>.*?<\/figure>/isu', $content, $m);
        $figures = count($m[0] ?? []);
        if ($figures > 0) return $figures;
        preg_match_all('/<img\b[^>]*class=["\'][^"\']*(?:sara-body-image|sara-internal-queued-image|geo-youtube-context-image)[^"\']*["\'][^>]*>/isu', $content, $imgs);
        return count($imgs[0] ?? []);
    }

    private function insert_fallback_body_images(int $post_id, string $content, string $keyword, string $title, int $missing): string {
        if ($missing <= 0 || !preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $content, $matches, PREG_SET_ORDER)) return $content;
        $inserted = 0;
        $positions = $this->select_image_h2_indexes($matches, $missing);
        foreach ($positions as $slot => $idx) {
            $match = $matches[$idx] ?? null;
            if (!$match) continue;
            if ($inserted >= $missing) break;
            $h2_full = $match[0];
            $h2_text = trim(wp_strip_all_tags($match[1] ?? ''));
            if ($h2_text === '' || preg_match('/faq|perguntas\s+frequentes|conclus[aã]o/iu', $h2_text)) continue;
            $pos = strpos($content, $h2_full);
            if ($pos === false) continue;
            $after = substr($content, $pos + strlen($h2_full), 500);
            if (preg_match('/<(?:figure|img)\b/i', $after)) continue;
            $image = new ImageGeneratorService();
            $attachment_id = $image->generate_body_attachment([
                'title' => $title,
                'keyword' => $keyword,
                'section' => $h2_text,
            ], $post_id);
            $final_url = (!is_wp_error($attachment_id) && $attachment_id) ? (string) wp_get_attachment_url((int)$attachment_id) : '';
            if (!$final_url) {
                $this->log('warning', 'GeoMediaMaster: fallback body image discarded because sideload failed', $post_id, ['section' => $h2_text]);
                continue;
            }
            $alt_text = trim($keyword . ' - ' . $h2_text);
            $fig = "\n<figure class=\"sara-body-image sara-internal-queued-image\" style=\"margin:28px 0;\"><img src=\"" . esc_url($final_url) . "\" alt=\"" . esc_attr($alt_text) . "\" loading=\"lazy\" style=\"width:100%;height:auto;border-radius:8px;display:block;\"><figcaption style=\"font-size:12px;color:#6b7280;text-align:center;margin-top:6px;\">" . esc_html($alt_text) . "</figcaption></figure>\n";
            $insert_pos = $pos + strlen($h2_full);
            $content = substr($content, 0, $insert_pos) . $fig . substr($content, $insert_pos);
            $inserted++;
        }
        return $content;
    }

    private function remove_managed_body_images(string $content): string {
        $content = preg_replace('/<figure\b[^>]*class=["\'][^"\']*(?:sara-body-image|sara-internal-queued-image|sara-manual-fallback-image|geo-youtube-context-image)[^"\']*["\'][^>]*>.*?<\/figure>\s*/isu', '', $content) ?? $content;
        return preg_replace('/<img\b[^>]*class=["\'][^"\']*(?:sara-body-image|sara-internal-queued-image|sara-manual-fallback-image|geo-youtube-context-image)[^"\']*["\'][^>]*>\s*/isu', '', $content) ?? $content;
    }

    private function remove_external_body_images(string $content): string {
        $local_hosts = array_filter([
            parse_url(home_url(), PHP_URL_HOST),
            parse_url(site_url(), PHP_URL_HOST),
            parse_url((string)(wp_get_upload_dir()['baseurl'] ?? ''), PHP_URL_HOST),
        ]);

        return preg_replace_callback('/<figure\b[^>]*class=["\'][^"\']*(?:sara-body-image|sara-internal-queued-image|sara-manual-fallback-image|geo-youtube-context-image)[^"\']*["\'][^>]*>.*?<\/figure>/isu', function($m) use ($local_hosts) {
            $src = '';
            if (preg_match('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/isu', $m[0], $img)) {
                $src = html_entity_decode((string)$img[1], ENT_QUOTES, 'UTF-8');
            }
            if (!$src) return '';
            $host = parse_url($src, PHP_URL_HOST);
            if (!$host || in_array($host, $local_hosts, true)) return $m[0];
            return '';
        }, $content) ?? $content;
    }

    private function limit_body_images(string $content, int $target): string {
        $seen = 0;
        return preg_replace_callback('/<figure\b[^>]*class=["\'][^"\']*(?:sara-body-image|sara-internal-queued-image|sara-manual-fallback-image|geo-youtube-context-image)[^"\']*["\'][^>]*>.*?<\/figure>\s*/isu', function($m) use (&$seen, $target) {
            $seen++;
            return $seen <= $target ? $m[0] : '';
        }, $content) ?? $content;
    }

    private function select_image_h2_indexes(array $matches, int $needed): array {
        $eligible = [];
        foreach ($matches as $idx => $match) {
            $h2_text = trim(wp_strip_all_tags($match[1] ?? ''));
            if ($h2_text !== '' && !preg_match('/faq|perguntas\s+frequentes|conclus[aã]o/iu', $h2_text)) {
                $eligible[] = $idx;
            }
        }
        if (empty($eligible)) return [];

        $needed = max(1, min(ImageGeneratorService::body_images_count(), $needed));
        $step = max(ImageGeneratorService::h2_interval(), (int) floor(count($eligible) / $needed));
        $selected = [];
        $cursor = min(1, count($eligible) - 1);
        while (count($selected) < $needed && $cursor < count($eligible)) {
            $selected[] = $eligible[$cursor];
            $cursor += $step;
        }
        foreach ($eligible as $idx) {
            if (count($selected) >= $needed) break;
            if (!in_array($idx, $selected, true)) $selected[] = $idx;
        }
        sort($selected);
        return $selected;
    }

    private function dedupe_consecutive_images(string $content): string {
        return preg_replace('/(<figure[^>]*class=["\'][^"\']*(?:sara-body-image|sara-internal-queued-image|geo-youtube-context-image)[^"\']*["\'][^>]*>.*?<\/figure>\s*){2,}/isu', '$1', $content) ?? $content;
    }

    private function generate_story_image_url(string $prompt, string $provider, int $i, string $keyword): string {
        $image = new ImageGeneratorService();
        $url = $image->generate_web_story_url([
            'title' => $keyword,
            'keyword' => $keyword,
            'section' => $prompt,
        ]);
        return is_wp_error($url) ? '' : (string)$url;
    }

    private function maybe_load_autopilot_media_classes(): void {
        if (!defined('GEO_METODO_SEO_PATH')) return;
        $files = [
            'includes/Autopilot/Writer/SaraImageHandler.php',
            'includes/Autopilot/Writer/SaraImageQueue.php',
        ];
        foreach ($files as $file) {
            $path = GEO_METODO_SEO_PATH . $file;
            if (file_exists($path)) require_once $path;
        }
    }

    private function log(string $level, string $message, int $post_id = 0, array $context = []): void {
        if (class_exists('GeoMetodoSEO\\Services\\LogService')) {
            LogService::log($level, $message . ($post_id ? ' — post #' . $post_id : '') . (!empty($context) ? ' — ' . wp_json_encode($context) : ''));
        }
    }
}
