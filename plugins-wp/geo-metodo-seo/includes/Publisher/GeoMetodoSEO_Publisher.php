<?php
namespace GeoMetodoSEO\Publisher;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Services\LogService;

/**
 * Publisher Central — responsabilidade única: criar e atualizar posts.
 *
 * ═══════════════════════════════════════════════════════════
 * REGRA ABSOLUTA — nunca violar:
 *
 *   O Publisher cria/atualiza posts + aplica: status, agendamento,
 *   categoria, tags, SEO (Rank Math / Yoast), log.
 *
 *   O Publisher NÃO lida com imagens de nenhum tipo.
 *   Imagens são responsabilidade exclusiva de:
 *     • ImageGeneratorService    → gera e define imagem de destaque
 *     • LibraryImageService      → define destaque da biblioteca
 *     • SaraGlobalPostProcessor  → insere imagens de corpo no HTML final
 *                                  e remove destaque duplicado no corpo
 *
 * MÉTODOS PÚBLICOS:
 *   publish()            → cria post novo
 *   update_post()        → atualiza post existente
 *   set_featured_image() → wrapper seguro de set_post_thumbnail
 *   apply_post_terms()   → categorias e tags
 *   map_seo()            → monta dados SEO normalizados
 *   clean_title()        → sanitiza título
 * ═══════════════════════════════════════════════════════════
 */
class GeoMetodoSEO_Publisher {

    // ── Criar post novo ────────────────────────────────────────────────────

    public static function publish(array $args): array {
        $source    = sanitize_key((string)($args['source_module'] ?? 'unknown'));
        $status    = self::normalize_status((string)($args['status'] ?? 'draft'));
        $title     = self::clean_title((string)($args['title'] ?? ''));
        $content   = (string)($args['content'] ?? '');
        $post_type = sanitize_key((string)($args['post_type'] ?? 'post'));

        if ($title === '') {
            return self::error('Título vazio.', $source, $status);
        }
        if ($content === '' && $post_type !== 'attachment') {
            return self::error('Conteúdo vazio.', $source, $status);
        }

        $post_data = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => sanitize_textarea_field((string)($args['excerpt'] ?? '')),
            'post_status'  => $status,
            'post_type'    => $post_type ?: 'post',
            'post_author'  => absint($args['author_id'] ?? 0) ?: (get_current_user_id() ?: 1),
        ];

        if (!empty($args['post_name'])) {
            $post_data['post_name'] = sanitize_title((string)$args['post_name']);
        }

        if ($status === 'future') {
            $scheduled = self::normalize_schedule($args['scheduled_at'] ?? '');
            if (!$scheduled) {
                return self::error('scheduled_at inválido para post futuro.', $source, $status);
            }
            $post_data['post_date']     = $scheduled['local'];
            $post_data['post_date_gmt'] = $scheduled['gmt'];
        }

        // Meta de rastreamento — sem imagens
        $custom_meta = is_array($args['custom_meta'] ?? null) ? $args['custom_meta'] : [];
        $custom_meta['_geo_source_module'] = $source;
        $custom_meta['_geo_published_via'] = 'central_publisher';
        $custom_meta['_geo_published_at']  = current_time('mysql');
        $post_data['meta_input'] = self::sanitize_meta_array($custom_meta);

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            return self::error($post_id->get_error_message(), $source, $status);
        }
        $post_id = absint($post_id);

        // Categorias e tags
        self::apply_terms($post_id, $args);

        // SEO (Rank Math + Yoast) — sem imagens
        $seo = self::map_seo($args, $title, $content);
        self::apply_seo_meta($post_id, $seo);

        // Imagem de destaque passada via args (ex: Web Stories, Glossário)
        // NOTA: só aplica se o caller passou featured_image_id diretamente.
        // Não faz sideload, não faz resolve via URL — quem chama é responsável.
        $featured_id = absint($args['featured_image_id'] ?? 0);
        if ($featured_id > 0 && wp_attachment_is_image($featured_id)) {
            set_post_thumbnail($post_id, $featured_id);
            update_post_meta($post_id, '_geo_featured_image_id',  $featured_id);
            update_post_meta($post_id, '_sara_featured_image_id', $featured_id);
        }

        if (class_exists(LogService::class)) {
            LogService::record('publisher', 'success', 'Post criado via Publisher central', [
                'action'  => 'publisher_created',
                'post_id' => $post_id,
                'context' => [
                    'source_module'     => $source,
                    'status'            => $status,
                    'post_type'         => $post_type,
                    'featured_image_id' => $featured_id,
                    'seo_focus_keyword' => $seo['focus_keyword'],
                ],
            ]);
        }

        return [
            'success' => true,
            'post_id' => $post_id,
            'status'  => get_post_status($post_id) ?: $status,
            'error'   => null,
        ];
    }

    // ── Atualizar post existente ───────────────────────────────────────────

    public static function update_post(
        array  $postarr,
        bool   $wp_error         = false,
        bool   $fire_after_hooks = true,
        string $source_module    = 'publisher_update'
    ) {
        $post_id = absint($postarr['ID'] ?? 0);
        $source  = sanitize_key($source_module ?: 'publisher_update');
        unset($postarr['_geo_source_module']);

        if ($post_id <= 0 || !get_post($post_id)) {
            $err = new \WP_Error('publisher_update_invalid_post', 'Post inválido para atualização.');
            self::log_action('error', 0, $source, 'Post inválido para atualização.');
            return $wp_error ? $err : 0;
        }

        if (isset($postarr['post_title'])) {
            $postarr['post_title'] = self::clean_title((string)$postarr['post_title']);
        }
        if (isset($postarr['post_status'])) {
            if (!in_array($postarr['post_status'], ['draft','publish','future','pending','private'], true)) {
                $postarr['post_status'] = 'draft';
            }
        }
        if (!empty($postarr['post_date']) && empty($postarr['post_date_gmt'])) {
            try {
                $dt = new \DateTimeImmutable((string)$postarr['post_date'], wp_timezone());
                $postarr['post_date_gmt'] = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {}
        }

        $result = wp_update_post($postarr, true, $fire_after_hooks);
        if (is_wp_error($result)) {
            self::log_action('error', $post_id, $source, $result->get_error_message());
            return $wp_error ? $result : 0;
        }

        self::log_action('success', $post_id, $source, 'Post atualizado via Publisher central.');
        return (int)$result;
    }

    // ── Imagem de destaque — wrapper seguro ────────────────────────────────
    // Valida o attachment antes de chamar set_post_thumbnail.
    // NÃO remove a imagem do corpo — isso é responsabilidade do SaraGlobalPostProcessor.

    public static function set_featured_image(
        int    $post_id,
        int    $attachment_id,
        string $source_module = 'publisher_featured_image'
    ): bool {
        $post_id       = absint($post_id);
        $attachment_id = absint($attachment_id);

        if ($post_id <= 0 || $attachment_id <= 0) {
            return false;
        }
        if (!wp_attachment_is_image($attachment_id)) {
            self::log_action('error', $post_id, sanitize_key($source_module),
                'Falha ao aplicar imagem destacada via Publisher central.');
            return false;
        }

        $ok = (bool) set_post_thumbnail($post_id, $attachment_id);
        if ($ok) {
            update_post_meta($post_id, '_geo_featured_image_id',  $attachment_id);
            update_post_meta($post_id, '_sara_featured_image_id', $attachment_id);
            self::log_action('success', $post_id, sanitize_key($source_module),
                'Imagem destacada aplicada via Publisher central.');
        } else {
            self::log_action('error', $post_id, sanitize_key($source_module),
                'Falha ao aplicar imagem destacada via Publisher central.');
        }
        return $ok;
    }

    // ── Categorias e tags ──────────────────────────────────────────────────

    public static function apply_post_terms(
        int    $post_id,
        array  $category_ids = [],
        array  $tags         = [],
        string $source_module = 'publisher_terms'
    ): void {
        $post_id = absint($post_id);
        if ($post_id <= 0) return;
        self::apply_terms($post_id, ['category_ids' => $category_ids, 'tags' => $tags]);
        self::log_action('success', $post_id, sanitize_key($source_module),
            'Termos aplicados via Publisher central.');
    }

    // ── SEO helper ─────────────────────────────────────────────────────────

    public static function map_seo(array $args, string $title, string $content): array {
        $focus = sanitize_text_field((string)($args['focus_keyword'] ?? ''));
        $custom_meta = is_array($args['custom_meta'] ?? null) ? $args['custom_meta'] : [];

        if ($focus === '') {
            foreach (['_geo_keyword','keyword','_sara_keyword','focus_keyword'] as $k) {
                if (!empty($custom_meta[$k])) {
                    $focus = sanitize_text_field((string)$custom_meta[$k]);
                    break;
                }
            }
        }
        if ($focus === '') $focus = self::extract_focus_from_title($title);

        $seo_title = self::clean_title((string)($args['seo_title'] ?? $title));
        $desc      = sanitize_textarea_field((string)($args['meta_description'] ?? ''));
        if ($desc === '') {
            $desc = sanitize_textarea_field(wp_trim_words(wp_strip_all_tags($content), 28, ''));
        }

        return [
            'seo_title'        => mb_substr($seo_title, 0, 70),
            'meta_description' => mb_substr($desc, 0, 160),
            'focus_keyword'    => $focus,
        ];
    }

    // ── Título limpo ───────────────────────────────────────────────────────

    public static function clean_title(string $title): string {
        $title = wp_strip_all_tags($title);
        $title = preg_replace('/\s+/u', ' ', $title);
        $title = preg_replace('/\bGEO\s*M[eé]todo\s*SEO\s*v?\d*(?:\.\d+)*\b/iu', '', $title);
        $title = preg_replace('/\bv\d+(?:\.\d+)*\b/iu', '', $title);
        $title = preg_replace('/\bvers[aã]o\s*\d+(?:\.\d+)*\b/iu', '', $title);
        $title = preg_replace('/\b(?:plugin\s+interno|prompt\s+mestre|debug\s+interno|modelo\s+de\s+IA)\b/iu', '', $title);
        $title = preg_replace('/\bprovider\s*[:\-]\s*[a-z0-9_.-]+\b/iu', '', $title);
        return sanitize_text_field(trim(preg_replace('/\s+/u', ' ', $title)));
    }

    // ── Privados ───────────────────────────────────────────────────────────

    private static function normalize_status(string $status): string {
        return in_array($status, ['draft','publish','future','pending','private'], true)
            ? $status : 'draft';
    }

    private static function normalize_schedule($val): ?array {
        $v = is_string($val) ? trim($val) : '';
        if ($v === '') return null;
        try {
            $tz = wp_timezone();
            $dt = new \DateTimeImmutable($v, $tz);
            if ($dt->getTimestamp() <= time()) return null;
            return [
                'local' => $dt->format('Y-m-d H:i:s'),
                'gmt'   => $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function apply_terms(int $post_id, array $args): void {
        $cat_ids = array_values(array_filter(array_map('absint', (array)($args['category_ids'] ?? []))));
        if (!empty($cat_ids) && get_post_type($post_id) === 'post') {
            wp_set_post_terms($post_id, $cat_ids, 'category', false);
        }
        $tags = $args['tags'] ?? [];
        if (!empty($tags) && get_post_type($post_id) === 'post') {
            if (!is_array($tags)) $tags = [$tags];
            $clean = array_values(array_filter(array_map(static function ($t) {
                return is_numeric($t) ? absint($t) : sanitize_text_field((string)$t);
            }, $tags)));
            if (!empty($clean)) wp_set_post_terms($post_id, $clean, 'post_tag', false);
        }
    }

    private static function apply_seo_meta(int $post_id, array $seo): void {
        $focus = sanitize_text_field((string)$seo['focus_keyword']);
        $title = sanitize_text_field((string)$seo['seo_title']);
        $desc  = sanitize_textarea_field((string)$seo['meta_description']);

        update_post_meta($post_id, 'rank_math_title',          $title);
        update_post_meta($post_id, 'rank_math_description',    $desc);
        update_post_meta($post_id, 'rank_math_focus_keyword',  $focus);
        update_post_meta($post_id, '_rank_math_focus_keyword', $focus);
        update_post_meta($post_id, '_yoast_wpseo_title',       $title);
        update_post_meta($post_id, '_yoast_wpseo_metadesc',    $desc);
        update_post_meta($post_id, '_yoast_wpseo_focuskw',     $focus);

        if ($focus !== '') {
            update_post_meta($post_id, '_geo_keyword',       $focus);
            update_post_meta($post_id, '_geo_keyword_exact', $focus);
        }
    }

    private static function log_action(string $level, int $post_id, string $source, string $msg): void {
        if (class_exists(LogService::class)) {
            LogService::record('publisher', $level, $msg, [
                'action'  => 'publisher_update',
                'post_id' => $post_id,
                'context' => ['source_module' => $source],
            ]);
        }
    }

    private static function extract_focus_from_title(string $title): string {
        $t = self::clean_title($title);
        $t = preg_replace('/^(?:como|o que [eé]|por que|qual|quais|guia|veja)\s+/iu', '', $t);
        return sanitize_text_field(mb_substr($t, 0, 80));
    }

    private static function sanitize_meta_array(array $meta): array {
        $out = [];
        foreach ($meta as $key => $value) {
            $key = sanitize_key((string)$key);
            if ($key === '') continue;
            $out[$key] = is_scalar($value) ? sanitize_text_field((string)$value) : $value;
        }
        return $out;
    }

    private static function error(string $msg, string $source, string $status): array {
        if (class_exists(LogService::class)) {
            LogService::record('publisher', 'error', 'Falha no Publisher: ' . $msg, [
                'action'  => 'publisher_error',
                'context' => ['source_module' => $source, 'status' => $status],
            ]);
        }
        return ['success' => false, 'post_id' => null, 'status' => $status, 'error' => $msg];
    }
}
