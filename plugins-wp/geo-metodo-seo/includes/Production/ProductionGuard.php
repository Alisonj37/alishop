<?php
namespace GeoMetodoSEO\Production;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Services\LogService;

/**
 * Camada de segurança operacional de produção.
 * Responsável por preflight, publicação segura, relatório final, status incompleto,
 * validação GEO/AEO/schema e reprocessamento operacional.
 */
class ProductionGuard {

    public static function register_hooks(): void {
        add_action('wp_ajax_geo_production_reprocess_images', [__CLASS__, 'ajax_reprocess_images']);
        add_action('wp_ajax_geo_production_validate_post', [__CLASS__, 'ajax_validate_post']);
        add_action('wp_ajax_geo_production_publisher_test', [__CLASS__, 'ajax_publisher_test']);
    }

    public static function default_options(): array {
        return [
            'safe_publish_enabled' => (bool) get_option('geo_safe_publish_enabled', true),
            'require_min_content' => (bool) get_option('geo_safe_require_min_content', true),
            'min_content_chars' => max(300, (int) get_option('geo_safe_min_content_chars', 700)),
            'require_h2' => (bool) get_option('geo_safe_require_h2', true),
            'require_featured_image' => (bool) get_option('geo_safe_require_featured_image', false),
            'require_focus_keyword' => (bool) get_option('geo_safe_require_focus_keyword', false),
            'require_meta_description' => (bool) get_option('geo_safe_require_meta_description', false),
            'require_schema_on_publish' => (bool) get_option('geo_safe_require_schema_on_publish', false),
            'draft_on_critical_failure' => (bool) get_option('geo_safe_draft_on_critical_failure', true),
        ];
    }

    public static function preflight(array $args, string $title, string $content, string $requested_status): array {
        $opt = self::default_options();
        $requested_status = in_array($requested_status, ['draft','publish','future'], true) ? $requested_status : 'draft';
        $is_public_flow = in_array($requested_status, ['publish','future'], true);
        $critical = [];
        $warnings = [];
        $checks = [];

        $plain = trim(wp_strip_all_tags($content));
        $content_len = function_exists('mb_strlen') ? mb_strlen($plain) : strlen($plain);

        self::add_check($checks, $critical, 'title', $title !== '', 'Título presente.', 'Título ausente.', true);
        self::add_check($checks, $critical, 'content', $plain !== '', 'Conteúdo presente.', 'Conteúdo vazio.', true);

        if ($opt['require_min_content']) {
            self::add_check(
                $checks,
                $critical,
                'min_content',
                $content_len >= $opt['min_content_chars'],
                'Conteúdo possui tamanho mínimo.',
                'Conteúdo abaixo do mínimo operacional (' . (int)$opt['min_content_chars'] . ' caracteres).',
                $is_public_flow
            );
        }

        if ($opt['require_h2']) {
            self::add_check($checks, $warnings, 'h2_structure', (bool) preg_match('/<h2\b[^>]*>.*?<\/h2>/is', $content), 'Estrutura H2 encontrada.', 'Nenhum H2 encontrado no conteúdo.', false);
        }

        $focus = sanitize_text_field((string)($args['focus_keyword'] ?? $args['custom_meta']['_geo_keyword'] ?? $args['custom_meta']['keyword'] ?? ''));
        $meta_desc = trim((string)($args['meta_description'] ?? $args['excerpt'] ?? ''));
        $featured_id = absint($args['featured_image_id'] ?? 0);
        $featured_url = trim((string)($args['featured_image_url'] ?? $args['custom_meta']['featured_image_url'] ?? ''));

        if ($opt['require_focus_keyword']) {
            self::add_check($checks, $critical, 'focus_keyword', $focus !== '', 'Focus keyword presente.', 'Focus keyword ausente.', $is_public_flow);
        } elseif ($focus === '') {
            $warnings[] = 'Focus keyword não foi enviado diretamente; Publisher tentará deduzir pelo título.';
            $checks['focus_keyword'] = ['ok' => false, 'level' => 'warning', 'message' => 'Focus keyword não enviado diretamente.'];
        }

        if ($opt['require_meta_description']) {
            self::add_check($checks, $critical, 'meta_description', $meta_desc !== '', 'Meta description presente.', 'Meta description ausente.', $is_public_flow);
        } elseif ($meta_desc === '') {
            $warnings[] = 'Meta description não foi enviada; Publisher tentará gerar pelo conteúdo.';
            $checks['meta_description'] = ['ok' => false, 'level' => 'warning', 'message' => 'Meta description não enviada diretamente.'];
        }

        if ($opt['require_featured_image']) {
            self::add_check($checks, $critical, 'featured_image', ($featured_id > 0 || $featured_url !== ''), 'Imagem destacada informada.', 'Imagem destacada ausente.', $is_public_flow);
        } else {
            $checks['featured_image'] = ['ok' => ($featured_id > 0 || $featured_url !== ''), 'level' => 'info', 'message' => ($featured_id > 0 || $featured_url !== '') ? 'Imagem destacada informada.' : 'Imagem destacada não obrigatória no preflight.'];
        }

        $must_draft = $opt['safe_publish_enabled'] && $opt['draft_on_critical_failure'] && $is_public_flow && !empty($critical);
        $final_status = $must_draft ? 'draft' : $requested_status;

        return [
            'ok' => empty($critical),
            'requested_status' => $requested_status,
            'final_status' => $final_status,
            'downgraded_to_draft' => $final_status !== $requested_status,
            'critical' => array_values($critical),
            'warnings' => array_values($warnings),
            'checks' => $checks,
            'options' => $opt,
        ];
    }

    private static function add_check(array &$checks, array &$bucket, string $key, bool $ok, string $ok_msg, string $fail_msg, bool $critical): void {
        $checks[$key] = [
            'ok' => $ok,
            'level' => $ok ? 'success' : ($critical ? 'critical' : 'warning'),
            'message' => $ok ? $ok_msg : $fail_msg,
        ];
        if (!$ok) $bucket[] = $fail_msg;
    }

    public static function final_report(int $post_id, array $args, array $preflight, array $runtime = []): array {
        $post_id = absint($post_id);
        $validation = self::validate_post($post_id);
        $critical = array_merge((array)($preflight['critical'] ?? []), (array)($runtime['critical'] ?? []));
        $warnings = array_merge((array)($preflight['warnings'] ?? []), (array)($runtime['warnings'] ?? []), (array)($validation['warnings'] ?? []));

        foreach ((array)($validation['critical'] ?? []) as $msg) {
            if (!in_array($msg, $critical, true)) $critical[] = $msg;
        }

        $report = [
            'version' => 'production_guard_1.0.0',
            'generated_at' => current_time('mysql'),
            'post_id' => $post_id,
            'source_module' => sanitize_key((string)($args['source_module'] ?? 'unknown')),
            'requested_status' => (string)($preflight['requested_status'] ?? ''),
            'final_status' => get_post_status($post_id),
            'preflight' => $preflight,
            'runtime' => $runtime,
            'validation' => $validation,
            'critical' => array_values(array_unique(array_filter($critical))),
            'warnings' => array_values(array_unique(array_filter($warnings))),
        ];

        $complete = empty($report['critical']);
        update_post_meta($post_id, '_geo_production_report', wp_json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        update_post_meta($post_id, '_geo_production_status', $complete ? 'complete' : 'incomplete');
        update_post_meta($post_id, '_geo_last_validation_at', current_time('mysql'));

        if (class_exists(LogService::class)) {
            LogService::record('publisher', $complete ? 'success' : 'warning', $complete ? 'Relatório final de publicação aprovado.' : 'Relatório final marcou post como incompleto.', [
                'action' => 'publisher_final_report',
                'post_id' => $post_id,
                'context' => [
                    'status' => $report['final_status'],
                    'production_status' => $complete ? 'complete' : 'incomplete',
                    'critical' => $report['critical'],
                    'warnings' => $report['warnings'],
                ],
            ]);
        }

        return $report;
    }

    public static function should_draft_after_runtime_failure(array $report): bool {
        $opt = self::default_options();
        if (empty($opt['safe_publish_enabled']) || empty($opt['draft_on_critical_failure'])) return false;
        if (empty($report['critical'])) return false;
        return true;
    }

    public static function validate_post(int $post_id): array {
        $post_id = absint($post_id);
        $post = get_post($post_id);
        if (!$post) {
            return ['ok' => false, 'critical' => ['Post não encontrado.'], 'warnings' => [], 'checks' => []];
        }

        $content = (string)$post->post_content;
        $plain = trim(wp_strip_all_tags($content));
        $checks = [];
        $critical = [];
        $warnings = [];

        self::add_validation_check($checks, $critical, 'title', trim((string)$post->post_title) !== '', 'Título OK.', 'Título ausente.', true);
        self::add_validation_check($checks, $critical, 'content', $plain !== '', 'Conteúdo OK.', 'Conteúdo vazio.', true);
        self::add_validation_check($checks, $warnings, 'direct_answer', self::has_direct_answer($content), 'Resposta direta detectada.', 'Resposta direta não detectada no início.', false);
        self::add_validation_check($checks, $warnings, 'h2', (bool) preg_match('/<h2\b[^>]*>/i', $content), 'H2 detectado.', 'Nenhum H2 detectado.', false);
        self::add_validation_check($checks, $warnings, 'faq_visual', self::has_faq_visual($content), 'FAQ visual detectado.', 'FAQ visual não detectado.', false);
        self::add_validation_check($checks, $warnings, 'internal_links', self::has_internal_links($content), 'Links internos detectados.', 'Links internos não detectados.', false);
        self::add_validation_check($checks, $warnings, 'featured_image', get_post_thumbnail_id($post_id) > 0, 'Imagem destacada OK.', 'Imagem destacada ausente.', false);

        $focus = get_post_meta($post_id, 'rank_math_focus_keyword', true) ?: get_post_meta($post_id, '_rank_math_focus_keyword', true) ?: get_post_meta($post_id, '_geo_keyword', true);
        $meta_desc = get_post_meta($post_id, 'rank_math_description', true) ?: get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        self::add_validation_check($checks, $warnings, 'focus_keyword', trim((string)$focus) !== '', 'Focus keyword OK.', 'Focus keyword ausente.', false);
        self::add_validation_check($checks, $warnings, 'meta_description', trim((string)$meta_desc) !== '', 'Meta description OK.', 'Meta description ausente.', false);

        $schema = self::detect_schema($post_id, $content);
        $checks['schema_article'] = ['ok' => !empty($schema['Article']), 'level' => !empty($schema['Article']) ? 'success' : 'warning', 'message' => !empty($schema['Article']) ? 'Article schema detectado.' : 'Article schema não confirmado.'];
        $checks['schema_faq'] = ['ok' => !empty($schema['FAQPage']), 'level' => !empty($schema['FAQPage']) ? 'success' : 'warning', 'message' => !empty($schema['FAQPage']) ? 'FAQPage schema detectado.' : 'FAQPage schema não confirmado.'];
        $checks['schema_howto'] = ['ok' => !empty($schema['HowTo']) || !self::looks_like_howto($content), 'level' => (!empty($schema['HowTo']) || !self::looks_like_howto($content)) ? 'success' : 'warning', 'message' => !empty($schema['HowTo']) ? 'HowTo schema detectado.' : (self::looks_like_howto($content) ? 'Conteúdo parece tutorial, mas HowTo schema não foi confirmado.' : 'HowTo não aplicável.')];

        $indexable = get_post_meta($post_id, 'rank_math_robots', true);
        $robots = is_array($indexable) ? implode(',', $indexable) : (string)$indexable;
        self::add_validation_check($checks, $warnings, 'indexation', stripos($robots, 'noindex') === false, 'Indexação permitida ou sem bloqueio detectado.', 'Possível noindex detectado.', false);

        return [
            'ok' => empty($critical),
            'critical' => array_values($critical),
            'warnings' => array_values($warnings),
            'checks' => $checks,
            'schema_detected' => $schema,
            'internal_images' => self::internal_images_status($post_id),
        ];
    }

    private static function add_validation_check(array &$checks, array &$bucket, string $key, bool $ok, string $ok_msg, string $fail_msg, bool $critical): void {
        $checks[$key] = ['ok' => $ok, 'level' => $ok ? 'success' : ($critical ? 'critical' : 'warning'), 'message' => $ok ? $ok_msg : $fail_msg];
        if (!$ok) $bucket[] = $fail_msg;
    }

    public static function internal_images_status(int $post_id): array {
        $status = get_post_meta($post_id, '_sara_internal_images_status', true) ?: 'not_queued';
        $done = (int) get_post_meta($post_id, '_sara_internal_images_done', true);
        $attempts = (int) get_post_meta($post_id, '_sara_internal_images_attempts', true);
        $pending_raw = get_post_meta($post_id, '_sara_internal_images_pending', true);
        $pending = [];
        if ($pending_raw) {
            $decoded = json_decode((string)$pending_raw, true);
            if (is_array($decoded)) $pending = $decoded;
        }
        return [
            'status' => sanitize_key((string)$status),
            'done' => $done,
            'attempts' => $attempts,
            'pending' => $pending,
            'processing' => (bool) get_post_meta($post_id, '_sara_internal_images_processing', true),
        ];
    }

    public static function reprocess_internal_images(int $post_id): array {
        $post_id = absint($post_id);
        if ($post_id <= 0 || !get_post($post_id)) return ['success' => false, 'message' => 'Post inválido.'];

        delete_post_meta($post_id, '_sara_internal_images_processing');
        update_post_meta($post_id, '_sara_internal_images_status', 'queued');

        if (class_exists('GeoMetodoSEO\\Autopilot\\Writer\\SaraImageQueue')) {
            \GeoMetodoSEO\Autopilot\Writer\SaraImageQueue::process($post_id);
            return ['success' => true, 'message' => 'Reprocessamento de imagens internas executado.', 'status' => self::internal_images_status($post_id)];
        }

        return ['success' => false, 'message' => 'SaraImageQueue não está carregada.'];
    }

    public static function ajax_reprocess_images(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.'], 403);
        check_ajax_referer('geo_production_guard', 'nonce');
        $post_id = absint($_POST['post_id'] ?? 0);
        $res = self::reprocess_internal_images($post_id);
        $res['success'] ? wp_send_json_success($res) : wp_send_json_error($res);
    }

    public static function ajax_validate_post(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.'], 403);
        check_ajax_referer('geo_production_guard', 'nonce');
        $post_id = absint($_POST['post_id'] ?? 0);
        $validation = self::validate_post($post_id);
        update_post_meta($post_id, '_geo_last_validation_report', wp_json_encode($validation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        wp_send_json_success($validation);
    }

    public static function ajax_publisher_test(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.'], 403);
        check_ajax_referer('geo_production_guard', 'nonce');
        if (!class_exists('GeoMetodoSEO\\Publisher\\GeoMetodoSEO_Publisher')) wp_send_json_error(['message' => 'Publisher não carregado.']);

        $mode = sanitize_key((string)($_POST['mode'] ?? 'draft'));
        $status = $mode === 'future' ? 'future' : ($mode === 'publish' ? 'publish' : 'draft');
        $scheduled_at = $status === 'future' ? gmdate('Y-m-d H:i:s', time() + 3600) : '';
        $content = '<p><strong>Resposta direta:</strong> este é um post de teste operacional do Publisher central.</p><h2>Validação do Publisher</h2><p>Conteúdo de teste criado para validar publicação, SEO, categoria, status e relatório operacional.</p><h2>Perguntas frequentes</h2><h3>O teste é real?</h3><p>Sim, ele cria um post de teste no WordPress.</p>';
        $result = \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::publish([
            'title' => 'Teste operacional do Publisher central',
            'content' => $content,
            'excerpt' => 'Teste operacional do Publisher central do GEO Método SEO.',
            'status' => $status,
            'scheduled_at' => $scheduled_at,
            'post_type' => 'post',
            'focus_keyword' => 'teste publisher central',
            'seo_title' => 'Teste operacional do Publisher central',
            'meta_description' => 'Teste para validar o Publisher central, SEO, status e relatório operacional.',
            'source_module' => 'production_guard_tester',
            'custom_meta' => ['_geo_test_post' => '1'],
        ]);
        if (empty($result['success'])) wp_send_json_error($result);
        wp_send_json_success($result);
    }

    private static function detect_schema(int $post_id, string $content): array {
        $detected = [];
        $meta_keys = ['_geo_schema_jsonld', '_sara_schema_jsonld', '_geo_eeat_schema', '_geo_structured_data'];
        foreach ($meta_keys as $key) {
            $raw = get_post_meta($post_id, $key, true);
            if (!$raw) continue;
            $json = is_array($raw) ? $raw : json_decode((string)$raw, true);
            self::walk_schema_types($json, $detected);
        }
        if (stripos($content, 'FAQPage') !== false) $detected['FAQPage'] = true;
        if (stripos($content, 'HowTo') !== false) $detected['HowTo'] = true;
        if (stripos($content, 'Article') !== false || get_post_type($post_id) === 'post') $detected['Article'] = true;
        return $detected;
    }

    private static function walk_schema_types($node, array &$detected): void {
        if (!is_array($node)) return;
        if (!empty($node['@type'])) {
            $types = is_array($node['@type']) ? $node['@type'] : [$node['@type']];
            foreach ($types as $type) $detected[(string)$type] = true;
        }
        foreach ($node as $value) self::walk_schema_types($value, $detected);
    }

    private static function has_direct_answer(string $content): bool {
        $start = mb_substr(wp_strip_all_tags($content), 0, 700);
        return (bool) preg_match('/\b(resposta direta|em resumo|resumo|é|são|significa|funciona)\b/iu', $start);
    }

    private static function has_faq_visual(string $content): bool {
        return (bool) preg_match('/\b(faq|perguntas frequentes|dúvidas frequentes)\b/iu', wp_strip_all_tags($content));
    }

    private static function has_internal_links(string $content): bool {
        $home = home_url('/');
        return (bool) preg_match('/<a\b[^>]+href=["\']' . preg_quote($home, '/') . '/i', $content);
    }

    private static function looks_like_howto(string $content): bool {
        return (bool) preg_match('/\b(passo a passo|como fazer|etapa|tutorial)\b/iu', wp_strip_all_tags($content));
    }
}
