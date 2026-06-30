<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) exit;

/**
 * Selecionador inteligente de imagens da Biblioteca de Mídia.
 *
 * - Quando geo_image_source=library, nenhuma IA de imagem deve ser chamada.
 * - Seleciona anexos existentes por categoria e por tipo (featured/body).
 * - Suporta fallback Global.
 * - Evita repetir imagem de destaque no corpo e repetição no mesmo artigo.
 * - Salva metas e fornece HTML padronizado com wp-image-ID e data-attachment-id.
 */
class LibraryImageService {

    private static function log(string $type, string $level, string $msg, array $ctx = []): void {
        if (class_exists(__NAMESPACE__ . '\\LogService')) {
            LogService::record($type, $level, $msg, $ctx);
        }
    }

    // Sobreposição de modo por requisição (definida pelo seletor do gerador).
    // null = usar a opção global; 'ai' ou 'library' = forçar este modo nesta geração.
    private static $mode_override = null;

    /**
     * Força o modo de imagem apenas para a requisição atual (seletor do gerador).
     * Passe 'ai', 'library' ou null (para voltar ao padrão global).
     */
    public static function set_mode_override(?string $mode): void {
        if ($mode === 'ai' || $mode === 'library') {
            self::$mode_override = $mode;
        } else {
            self::$mode_override = null;
        }
    }

    public static function is_library_mode(): bool {
        // A sobreposição por requisição (seletor do gerador) tem prioridade sobre a opção global.
        if (self::$mode_override !== null) {
            return self::$mode_override === 'library';
        }
        return get_option('geo_image_source', 'ai') === 'library';
    }

    public static function resolve_category_slug(int $post_id): string {
        $cats = get_the_category($post_id);
        return !empty($cats) ? sanitize_key((string)$cats[0]->slug) : 'global';
    }

    public static function expand_range(int $min, int $max): array {
        if ($min <= 0 || $max <= 0 || $min > $max) return [];
        $cache_key = 'geo_lib_range_' . $min . '_' . $max;
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) return array_values(array_map('intval', $cached));

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type='attachment'
               AND post_mime_type LIKE 'image/%%'
               AND post_status='inherit'
               AND ID BETWEEN %d AND %d
             ORDER BY ID ASC",
            $min, $max
        ));
        $result = array_values(array_map('intval', (array)$ids));
        set_transient($cache_key, $result, 12 * HOUR_IN_SECONDS);
        return $result;
    }

    private static function ptr_key(string $type, string $cat_slug): string {
        return 'geo_lib_' . sanitize_key($type) . '_ptr_' . sanitize_key($cat_slug);
    }

    private static function range_keys(string $type, string $slug): array {
        return [
            'min' => 'geo_lib_' . $type . '_min_' . $slug,
            'max' => 'geo_lib_' . $type . '_max_' . $slug,
        ];
    }

    private static function get_range_config(string $type, string $cat_slug): array {
        $slug = sanitize_key($cat_slug ?: 'global');
        $keys = self::range_keys($type, $slug);
        $min = absint(get_option($keys['min'], 0));
        $max = absint(get_option($keys['max'], 0));
        $ids = [];
        $source = $slug;

        if ($min > 0 && $max > 0 && $max >= $min) {
            $ids = self::expand_range($min, $max);
        }

        if (empty($ids) && $slug !== 'global') {
            $keys = self::range_keys($type, 'global');
            $gmin = absint(get_option($keys['min'], 0));
            $gmax = absint(get_option($keys['max'], 0));
            if ($gmin > 0 && $gmax > 0 && $gmax >= $gmin) {
                $ids = self::expand_range($gmin, $gmax);
                $min = $gmin;
                $max = $gmax;
                $source = 'global';
                if (!empty($ids)) {
                    self::log('media', 'info', 'LibraryImage: fallback global aplicado', [
                        'action' => 'library_global_fallback',
                        'context' => ['requested_slug' => $slug, 'type' => $type, 'count' => count($ids)],
                    ]);
                }
            }
        }

        return ['source_slug' => $source, 'min' => $min, 'max' => $max, 'ids' => $ids];
    }

    public static function get_featured_ids(string $cat_slug): array {
        return (array)(self::get_range_config('featured', $cat_slug)['ids'] ?? []);
    }

    public static function get_body_ids(string $cat_slug): array {
        return (array)(self::get_range_config('body', $cat_slug)['ids'] ?? []);
    }

    private static function get_next_id(string $type, string $cat_slug, array $exclude_ids = []): int {
        $cfg = self::get_range_config($type, $cat_slug);
        $ids = array_values(array_unique(array_filter(array_map('absint', (array)($cfg['ids'] ?? [])))));
        if (empty($ids)) {
            self::log('media', 'warning', 'LibraryImage: nenhum ID válido encontrado', [
                'action' => 'library_ids_empty',
                'context' => ['type' => $type, 'cat_slug' => $cat_slug],
            ]);
            return 0;
        }

        $range_slug = sanitize_key((string)($cfg['source_slug'] ?? $cat_slug));
        $ptr_key = self::ptr_key($type, $range_slug);
        $ptr = absint(get_option($ptr_key, 0));
        $total = count($ids);
        if ($ptr >= $total) $ptr = 0;

        $exclude = array_values(array_unique(array_filter(array_map('absint', $exclude_ids))));
        $selected = 0;
        for ($i = 0; $i < $total; $i++) {
            $index = ($ptr + $i) % $total;
            $candidate = absint($ids[$index]);
            if ($candidate <= 0) continue;
            if (!wp_attachment_is_image($candidate)) continue;
            if (in_array($candidate, $exclude, true)) continue;
            $selected = $candidate;
            update_option($ptr_key, (($index + 1) % $total), false);
            break;
        }

        if (!$selected && $type === 'body') {
            // Se o usuário configurou menos imagens do que o alvo do artigo, reutiliza de forma controlada
            // em vez de deixar o corpo sem imagens. A imagem destacada continua excluída.
            foreach ($ids as $candidate) {
                $candidate = absint($candidate);
                if ($candidate > 0 && wp_attachment_is_image($candidate) && !in_array($candidate, $exclude, true)) {
                    $selected = $candidate;
                    break;
                }
            }
        }

        if (!$selected) {
            self::log('media', 'warning', 'LibraryImage: range sem imagem utilizável após exclusões', [
                'action' => 'library_ids_exhausted',
                'context' => ['type' => $type, 'cat_slug' => $cat_slug, 'excluded' => $exclude, 'available_ids' => $ids],
            ]);
        }

        return $selected;
    }

    public static function set_featured(int $post_id, string $keyword, string $title, string $cat_slug = ''): int {
        if (!$cat_slug) $cat_slug = self::resolve_category_slug($post_id);
        $id = self::get_next_id('featured', $cat_slug);
        if (!$id) return 0;

        if ($post_id > 0) {
            // set_post_thumbnail direto — sem Publisher::set_featured_image.
            // Motivo: o Publisher não deve ser chamado para operações de imagem.
            // O ArticlePipeline chama Publisher::set_featured_image depois com o ID retornado.
            set_post_thumbnail($post_id, $id);
            update_post_meta($post_id, '_geo_featured_image_id', $id);
            update_post_meta($post_id, '_sara_featured_image_id', $id);
            update_post_meta($post_id, '_geo_image_source', 'library');
            update_post_meta($post_id, '_sara_image_source', 'library');
        }

        $alt = sanitize_text_field(mb_substr($keyword ?: $title, 0, 160));
        if ($alt !== '') update_post_meta($id, '_wp_attachment_image_alt', $alt);
        if ($title !== '') {
            // wp_update_post direto no attachment — sem Publisher::update_post.
            // Publisher::update_post não deve ser chamado em attachments.
            wp_update_post(['ID' => $id, 'post_title' => sanitize_text_field(mb_substr($title, 0, 200))]);
        }

        self::log('media', 'success', 'LibraryImage: destaque definido', [
            'action' => 'library_featured_set',
            'context' => ['post_id' => $post_id, 'attachment_id' => $id, 'cat_slug' => $cat_slug],
        ]);
        return $id;
    }

    public static function get_body_id(int $post_id, string $h2_text, string $keyword, string $cat_slug = ''): int {
        if (!$cat_slug) $cat_slug = self::resolve_category_slug($post_id);
        $exclude = [];
        $featured_id = $post_id > 0 ? absint(get_post_thumbnail_id($post_id)) : 0;
        if ($featured_id > 0) $exclude[] = $featured_id;
        if ($post_id > 0) {
            $existing = get_post_meta($post_id, '_geo_body_image_ids', true);
            if (!is_array($existing)) $existing = get_post_meta($post_id, '_sara_body_image_ids', true);
            if (is_array($existing)) $exclude = array_merge($exclude, array_map('absint', $existing));
        }
        $id = self::get_next_id('body', $cat_slug, $exclude);
        if (!$id) return 0;

        $alt = sanitize_text_field(mb_substr($h2_text ?: $keyword, 0, 160));
        if ($alt !== '') update_post_meta($id, '_wp_attachment_image_alt', $alt);
        return $id;
    }

    public static function build_attachment_figure_html(int $attachment_id, string $alt_text = '', array $figure_classes = [], array $img_attrs = [], string $caption = ''): string {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) return '';

        $alt_text = trim($alt_text) !== '' ? trim($alt_text) : (string)get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if ($alt_text === '') $alt_text = trim((string)get_the_title($attachment_id));

        $figure_classes = array_values(array_unique(array_filter(array_merge(['sara-body-image', 'sara-library-image', 'geo-content-image'], $figure_classes))));
        $class_attr = implode(' ', array_map('sanitize_html_class', $figure_classes));

        $attrs = array_merge([
            'class' => 'wp-image-' . $attachment_id . ' sara-body-image sara-library-image',
            'alt' => $alt_text,
            'loading' => 'lazy',
            'data-attachment-id' => (string)$attachment_id,
            'style' => 'width:100%;height:auto;border-radius:8px;display:block;',
        ], $img_attrs);

        $img_html = wp_get_attachment_image($attachment_id, 'large', false, $attrs);
        if (!$img_html) {
            $src = wp_get_attachment_image_url($attachment_id, 'large');
            if (!$src) return '';
            $pairs = [];
            foreach ($attrs as $k => $v) {
                $pairs[] = $k . '="' . esc_attr((string)$v) . '"';
            }
            $img_html = '<img src="' . esc_url($src) . '" ' . implode(' ', $pairs) . ' />';
        }

        $caption_html = $caption !== '' ? '<figcaption style="font-size:12px;color:#6b7280;text-align:center;margin-top:6px;">' . esc_html($caption) . '</figcaption>' : '';
        return "\n<figure class=\"{$class_attr}\" data-attachment-id=\"{$attachment_id}\" style=\"margin:28px 0;\">{$img_html}{$caption_html}</figure>\n";
    }

    public static function remember_body_image(int $post_id, int $attachment_id, string $source = 'library'): void {
        if ($post_id <= 0 || $attachment_id <= 0) return;
        $attachment_id = absint($attachment_id);
        $ids = get_post_meta($post_id, '_geo_body_image_ids', true);
        if (!is_array($ids)) $ids = [];
        $ids[] = $attachment_id;
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        update_post_meta($post_id, '_geo_body_image_ids', $ids);
        update_post_meta($post_id, '_sara_body_image_ids', $ids);
        update_post_meta($post_id, '_geo_body_images_count', count($ids));
        update_post_meta($post_id, '_sara_body_images_count', count($ids));
        update_post_meta($post_id, '_geo_image_source', $source);
        update_post_meta($post_id, '_sara_image_source', $source);
    }

    public static function extract_attachment_ids_from_content(string $content): array {
        $ids = [];
        if (preg_match_all('/data-attachment-id=["\'](\d+)["\']/i', $content, $m)) {
            $ids = array_merge($ids, array_map('absint', $m[1]));
        }
        if (preg_match_all('/wp-image-(\d+)/i', $content, $m)) {
            $ids = array_merge($ids, array_map('absint', $m[1]));
        }
        if (preg_match_all('/<img\b[^>]*src=["\']([^"\']+)["\']/i', $content, $m)) {
            foreach ((array)$m[1] as $src) {
                $aid = attachment_url_to_postid(html_entity_decode((string)$src, ENT_QUOTES, 'UTF-8'));
                if ($aid > 0) $ids[] = absint($aid);
            }
        }
        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    public static function sync_post_image_meta_from_content(int $post_id, string $content): array {
        if ($post_id <= 0) return [];
        $ids = self::extract_attachment_ids_from_content($content);
        if (empty($ids)) return [];

        $featured = absint(get_post_thumbnail_id($post_id));
        $body_ids = $ids;
        if ($featured > 0) {
            $body_ids = array_values(array_diff($body_ids, [$featured]));
            update_post_meta($post_id, '_geo_featured_image_id', $featured);
            update_post_meta($post_id, '_sara_featured_image_id', $featured);
        }
        if (!empty($body_ids)) {
            update_post_meta($post_id, '_geo_body_image_ids', $body_ids);
            update_post_meta($post_id, '_sara_body_image_ids', $body_ids);
            update_post_meta($post_id, '_geo_body_images_count', count($body_ids));
            update_post_meta($post_id, '_sara_body_images_count', count($body_ids));
        }
        return $body_ids;
    }

    public static function inject_body_images(string $content, int $post_id, string $keyword, string $cat_slug = '', int $h2_interval = 0, int $max_images = 0): string {
        if (!$cat_slug) $cat_slug = self::resolve_category_slug($post_id);
        $ids = self::get_body_ids($cat_slug);
        if (empty($ids)) {
            self::log('media', 'warning', 'LibraryImage: nenhuma imagem de corpo disponivel para inserir', [
                'action' => 'library_body_ids_empty',
                'context' => ['post_id' => $post_id, 'cat_slug' => $cat_slug],
            ]);
            return $content;
        }

        $interval = $h2_interval > 0 ? $h2_interval : (int)get_option('geo_image_h2_interval', 3);
        $max = $max_images > 0 ? $max_images : (int)get_option('geo_body_images_count', 3);
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $content, $matches, PREG_OFFSET_CAPTURE);
        $h2_positions = $matches[0] ?? [];
        $h2_texts = $matches[1] ?? [];
        if (empty($h2_positions)) {
            self::log('media', 'warning', 'LibraryImage: artigo sem H2 elegivel para imagens de corpo', [
                'action' => 'library_body_no_h2',
                'context' => ['post_id' => $post_id, 'cat_slug' => $cat_slug],
            ]);
            return $content;
        }

        $eligible = [];
        foreach ($h2_positions as $idx => $match) {
            $h2_text = wp_strip_all_tags($h2_texts[$idx][0] ?? $keyword);
            if ($h2_text === '' || preg_match('/faq|perguntas\s+frequentes|conclus[aã]o|artigos\s+relacionados/iu', $h2_text)) continue;
            $eligible[] = ['idx' => $idx, 'match' => $match, 'text' => $h2_text];
        }
        if (empty($eligible)) {
            self::log('media', 'warning', 'LibraryImage: nenhum H2 elegivel para imagens de corpo', [
                'action' => 'library_body_no_slot',
                'context' => ['post_id' => $post_id, 'cat_slug' => $cat_slug],
            ]);
            return $content;
        }
        $max = min($max, count($eligible));
        $step = max(1, (int)floor(count($eligible) / max(1, $max)));
        $selected = [];
        for ($i = 0; $i < count($eligible) && count($selected) < $max; $i += $step) $selected[] = $eligible[$i];
        foreach ($eligible as $item) {
            if (count($selected) >= $max) break;
            if (!in_array($item, $selected, true)) $selected[] = $item;
        }
        usort($selected, fn($a, $b) => $b['match'][1] <=> $a['match'][1]);
        $inserted = 0;
        foreach ($selected as $item) {
            if ($inserted >= $max) break;
            $h2_text = $item['text'];
            $img_id = self::get_body_id($post_id, $h2_text, $keyword, $cat_slug);
            if (!$img_id) continue;
            $alt = trim($h2_text ?: $keyword);
            $figure = self::build_attachment_figure_html($img_id, $alt, ['sara-body-image', 'sara-library-image'], [], $alt);
            if ($figure === '') continue;
            $match = $item['match'];
            $h2_end_pos = $match[1] + strlen($match[0]);
            $content = substr($content, 0, $h2_end_pos) . $figure . substr($content, $h2_end_pos);
            $inserted++;
            self::remember_body_image($post_id, $img_id, 'library');
        }
        if ($inserted > 0) {
            self::log('media', 'success', 'LibraryImage: imagens de corpo inseridas', [
                'action' => 'body_images_inserted_into_html',
                'context' => ['post_id' => $post_id, 'count' => $inserted, 'cat_slug' => $cat_slug],
            ]);
        } else {
            self::log('media', 'warning', 'LibraryImage: nenhuma imagem de corpo foi inserida no HTML final', [
                'action' => 'library_body_inserted_zero',
                'context' => ['post_id' => $post_id, 'cat_slug' => $cat_slug],
            ]);
        }
        return $content;
    }

    public static function get_stats(string $cat_slug): array {
        $featured_ids = self::get_featured_ids($cat_slug);
        $body_ids = self::get_body_ids($cat_slug);
        $featured_cfg = self::get_range_config('featured', $cat_slug);
        $body_cfg = self::get_range_config('body', $cat_slug);
        return [
            'featured_total' => count($featured_ids),
            'featured_ptr' => (int)get_option(self::ptr_key('featured', (string)($featured_cfg['source_slug'] ?? $cat_slug)), 0),
            'body_total' => count($body_ids),
            'body_ptr' => (int)get_option(self::ptr_key('body', (string)($body_cfg['source_slug'] ?? $cat_slug)), 0),
        ];
    }

    public static function reset_pointers(string $cat_slug): void {
        update_option(self::ptr_key('featured', $cat_slug), 0, false);
        update_option(self::ptr_key('body', $cat_slug), 0, false);
    }

    public static function clear_range_cache(int $min, int $max): void {
        delete_transient('geo_lib_range_' . $min . '_' . $max);
    }
}
