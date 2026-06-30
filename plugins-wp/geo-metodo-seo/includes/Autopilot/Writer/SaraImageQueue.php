<?php
namespace GeoMetodoSEO\Autopilot\Writer;

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;
use GeoMetodoSEO\Services\ImageGeneratorService;

if (!defined('ABSPATH')) exit;

/**
 * Fila simples de imagens internas para evitar 504 em requisicoes longas.
 * v1.0.0: gera imagens pela cadeia central Replicate -> Fal.ai e insere apenas URLs locais.
 */
class SaraImageQueue {

    public static function enqueue(int $post_id, string $keyword, int $count = 2, array $context = []): void {
        $count = max(1, min(ImageGeneratorService::body_images_count(), absint($count)));
        if (class_exists('\\GeoMetodoSEO\\Services\\LibraryImageService') && \GeoMetodoSEO\Services\LibraryImageService::is_library_mode()) {
            $post = get_post($post_id);
            if ($post) {
                $cat_slug = \GeoMetodoSEO\Services\LibraryImageService::resolve_category_slug($post_id);
                $content = \GeoMetodoSEO\Services\LibraryImageService::inject_body_images((string)$post->post_content, $post_id, $keyword, $cat_slug, 1, $count);
                if ($content !== (string)$post->post_content) {
                    \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
                }
            }
            delete_post_meta($post_id, '_sara_internal_images_pending');
            delete_post_meta($post_id, '_sara_internal_images_processing');
            update_post_meta($post_id, '_sara_internal_images_status', 'done');
            AutopilotLogger::log('writer', 'library_queue_sync_done', 'info',
                "Modo biblioteca: fila pesada ignorada e imagens inseridas direto no post #{$post_id}", ['post_id' => $post_id]);
            return;
        }
        update_post_meta($post_id, '_sara_internal_images_status', 'queued');
        update_post_meta($post_id, '_sara_internal_images_pending', wp_json_encode([
            'keyword' => sanitize_text_field($keyword),
            'count' => $count,
            'created' => current_time('mysql'),
            'title' => sanitize_text_field((string)($context['title'] ?? '')),
            'category' => sanitize_text_field((string)($context['category'] ?? '')),
            'niche' => sanitize_text_field((string)($context['niche'] ?? '')),
        ], JSON_UNESCAPED_UNICODE));

        // 1.0.0: agenda WP-Cron como SAFETY NET (caso process_now não rode).
        // Delay 45s pra dar tempo do WP-Cron real disparar e não conflitar com
        // chamada síncrona feita logo depois no SaraGlobalPostProcessor.
        if (!wp_next_scheduled('sara_process_internal_images', [$post_id])) {
            wp_schedule_single_event(time() + 45, 'sara_process_internal_images', [$post_id]);
        }

        AutopilotLogger::log('writer', 'internal_images_queued', 'info',
            "Imagens internas enfileiradas para post #{$post_id} ({$count})", ['post_id' => $post_id]);
    }

    public static function process(int $post_id): void {
        if (class_exists('\\GeoMetodoSEO\\Services\\LibraryImageService') && \GeoMetodoSEO\Services\LibraryImageService::is_library_mode()) {
            $raw = get_post_meta($post_id, '_sara_internal_images_pending', true);
            $job = $raw ? json_decode((string)$raw, true) : [];
            $post = get_post($post_id);
            if ($post) {
                $keyword = sanitize_text_field((string)($job['keyword'] ?? $post->post_title));
                $target = max(1, min(ImageGeneratorService::body_images_count(), absint($job['count'] ?? ImageGeneratorService::body_images_count())));
                $cat_slug = \GeoMetodoSEO\Services\LibraryImageService::resolve_category_slug($post_id);
                $content = \GeoMetodoSEO\Services\LibraryImageService::inject_body_images((string)$post->post_content, $post_id, $keyword, $cat_slug, 1, $target);
                if ($content !== (string)$post->post_content) {
                    \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
                }
            }
            delete_post_meta($post_id, '_sara_internal_images_pending');
            delete_post_meta($post_id, '_sara_internal_images_processing');
            update_post_meta($post_id, '_sara_internal_images_status', 'done');
            return;
        }
        // 1.0.0: aumentar limite de execução pra processar N imagens (N × ~3s + sideload).
        // Sem isso, em hosts com max_execution_time=30, o PHP mata no meio.
        if (function_exists('set_time_limit')) {
            @set_time_limit(100);
        }
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }

        $raw = get_post_meta($post_id, '_sara_internal_images_pending', true);
        if (!$raw) return;

        // 1.0.0: lock contra dupla execução (process_now síncrono + WP-Cron 45s).
        // Sem isso, ambos podem rodar e gerar imagens duplicadas.
        $lock_key = '_sara_internal_images_processing';
        $lock_value = get_post_meta($post_id, $lock_key, true);
        if ($lock_value && (time() - (int)$lock_value) < 300) {
            // Lock recente (< 5min) — outra execução está rolando
            AutopilotLogger::log('writer', 'body_image_lock_skip', 'info',
                "Process pulado: lock ativo no post #{$post_id}", ['post_id' => $post_id]);
            return;
        }
        update_post_meta($post_id, $lock_key, time());
        update_post_meta($post_id, '_sara_internal_images_status', 'processing');

        $job = json_decode((string)$raw, true);
        if (!is_array($job)) {
            delete_post_meta($post_id, $lock_key);
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            delete_post_meta($post_id, $lock_key);
            return;
        }

        $content = (string)$post->post_content;
        $target = max(1, min(ImageGeneratorService::body_images_count(), absint($job['count'] ?? 2)));
        if (substr_count($content, 'sara-internal-queued-image') >= $target) {
            delete_post_meta($post_id, '_sara_internal_images_pending');
            delete_post_meta($post_id, '_sara_internal_images_processing');
            return;
        }

        $keyword = sanitize_text_field((string)($job['keyword'] ?? $post->post_title));
        $title = sanitize_text_field((string)($job['title'] ?? $post->post_title));
        $category = sanitize_text_field((string)($job['category'] ?? ''));
        $niche = sanitize_text_field((string)($job['niche'] ?? ''));
        $image = new ImageGeneratorService();
        $attachment_ids = [];

        $h2s = self::select_spaced_contexts(self::extract_h2_contexts($content), $target);
        for ($i = 0; $i < $target; $i++) {
            $section = $h2s[$i] ?? '';
            $attempt_start = microtime(true);
            $attachment_id = $image->generate_body_attachment([
                'title' => $title,
                'keyword' => $keyword,
                'category' => $category ?: $niche,
                'section' => $section,
            ], $post_id);

            $duration = (int) round((microtime(true) - $attempt_start) * 1000);

            if (is_wp_error($attachment_id) || !$attachment_id) {
                // 1.0.0: log DETALHADO de cada falha pra debug
                $err = is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'attachment_id vazio';
                AutopilotLogger::log('writer', 'body_image_failed', 'error',
                    "Body image #" . ($i + 1) . "/{$target} FALHOU em {$duration}ms: " . substr($err, 0, 200),
                    ['post_id' => $post_id]);
                continue;
            }

            $attachment_id = (int)$attachment_id;
            self::set_attachment_alt($attachment_id, $title, $i + 1);
            $url = wp_get_attachment_url($attachment_id) ?: '';
            if ($attachment_id > 0 && !in_array($attachment_id, $attachment_ids, true)) {
                $attachment_ids[] = $attachment_id;
                // 1.0.0: log de SUCCESS por imagem — Arlens vê quantas geradas
                AutopilotLogger::log('writer', 'body_image_success', 'success',
                    "Body image #" . ($i + 1) . "/{$target} gerada em {$duration}ms (attachment #{$attachment_id})",
                    ['post_id' => $post_id]);
            }
        }

        if (empty($attachment_ids)) {
            // v1.0.0: retry real. Se todos os providers/sideload falharem, reagendar até 3 vezes.
            $attempts = (int) get_post_meta($post_id, '_sara_internal_images_attempts', true);
            $attempts++;
            update_post_meta($post_id, '_sara_internal_images_attempts', $attempts);
            delete_post_meta($post_id, '_sara_internal_images_processing');
            update_post_meta($post_id, '_sara_internal_images_status', $attempts < 3 ? 'retry_scheduled' : 'failed');
            if ($attempts < 3 && !wp_next_scheduled('sara_process_internal_images', [$post_id])) {
                wp_schedule_single_event(time() + (60 * $attempts), 'sara_process_internal_images', [$post_id]);
            }
            AutopilotLogger::log('writer', 'internal_images_queue_empty', 'warning',
                "Fila de imagens internas sem imagem local para post #{$post_id}; tentativa {$attempts}/3", ['post_id' => $post_id]);
            return;
        }

        $content = self::insert_after_h2($content, $attachment_ids, $keyword, $post_id);
        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);

        $final_inserted = substr_count($content, 'sara-internal-queued-image');
        if ($final_inserted < $target) {
            $attempts = (int) get_post_meta($post_id, '_sara_internal_images_attempts', true);
            $attempts++;
            update_post_meta($post_id, '_sara_internal_images_attempts', $attempts);
            if ($attempts < 3) {
                update_post_meta($post_id, '_sara_internal_images_status', 'queued');
        update_post_meta($post_id, '_sara_internal_images_pending', wp_json_encode([
                    'keyword' => $keyword,
                    'count' => max(1, $target - $final_inserted),
                    'created' => current_time('mysql'),
                    'title' => $title,
                    'category' => $category,
                    'niche' => $niche,
                ], JSON_UNESCAPED_UNICODE));
                if (!wp_next_scheduled('sara_process_internal_images', [$post_id])) {
                    wp_schedule_single_event(time() + (60 * $attempts), 'sara_process_internal_images', [$post_id]);
                }
                update_post_meta($post_id, '_sara_internal_images_status', 'partial_retry_scheduled');
                AutopilotLogger::log('writer', 'internal_images_partial_retry', 'warning',
                    "Somente {$final_inserted}/{$target} imagens ficaram no post #{$post_id}; retry {$attempts}/3 agendado", ['post_id' => $post_id]);
            } else {
                delete_post_meta($post_id, '_sara_internal_images_pending');
                update_post_meta($post_id, '_sara_internal_images_status', 'partial_failed');
            }
        } else {
            delete_post_meta($post_id, '_sara_internal_images_pending');
            delete_post_meta($post_id, '_sara_internal_images_attempts');
            update_post_meta($post_id, '_sara_internal_images_status', 'done');
        }

        update_post_meta($post_id, '_sara_internal_images_done', $final_inserted);

        // 1.0.0: libera lock
        delete_post_meta($post_id, '_sara_internal_images_processing');

        AutopilotLogger::log('writer', 'internal_images_inserted', 'success',
            $final_inserted . " imagens internas presentes no post #{$post_id}", ['post_id' => $post_id]);
    }

    private static function set_attachment_alt(int $attachment_id, string $title, int $index): void {
        $alt = trim($title . ' - imagem editorial ' . $index);
        update_post_meta($attachment_id, '_wp_attachment_image_alt', mb_substr($alt, 0, 160));
        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
            'ID' => $attachment_id,
            'post_title' => mb_substr($alt, 0, 120),
            'post_excerpt' => mb_substr($alt, 0, 160),
        ]);
    }

    private static function extract_h2_contexts(string $content): array {
        if (!preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $content, $m)) return [];
        $out = [];
        foreach ($m[1] as $h2) {
            $text = trim(wp_strip_all_tags($h2));
            if ($text !== '' && !preg_match('/perguntas\s+frequentes|faq|conclus[aã]o/iu', $text)) {
                $out[] = $text;
            }
        }
        return array_values($out);
    }

    private static function insert_after_h2(string $content, array $attachment_ids, string $keyword, int $post_id = 0): string {
        if (empty($attachment_ids)) return $content;
        if (!preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $content, $matches, PREG_OFFSET_CAPTURE)) return $content;

        // Coletar H2 elegíveis (excluindo FAQ/Conclusão e os que já têm imagem logo abaixo)
        $eligible = [];
        foreach ($matches[0] as $idx => $full) {
            $h2_full = $full[0];
            $pos = (int)$full[1];
            $h2_text = trim(wp_strip_all_tags($matches[1][$idx][0] ?? ''));
            if ($h2_text === '' || preg_match('/perguntas\s+frequentes|faq|conclus[aã]o|próximos passos|o que fazer agora/iu', $h2_text)) continue;
            $after = substr($content, $pos + strlen($h2_full), 650);
            if (preg_match('/<(?:figure|img)\b/i', $after)) continue;
            $eligible[] = ['idx' => $idx, 'pos' => $pos, 'h2' => $h2_full, 'text' => $h2_text];
        }

        if (empty($eligible)) return $content;

        // ────────────────────────────────────────────────────────────
        // FIX v1.0.0-IMAGES-FINAL: distribuição inteligente que adapta ao tamanho real do artigo
        //
        // Lógica:
        // 1. Tenta usar o h2_interval configurado (3 ou 4)
        // 2. Se artigo tem H2 demais para o interval pedido, reduz interval mantendo proporção
        // 3. Distância mínima entre imagens = max(2, total/needed - 1)
        // 4. Garante que NENHUMA imagem fique no primeiro H2 (logo após intro)
        // ────────────────────────────────────────────────────────────
        $h2_interval_config = max(2, (int) \GeoMetodoSEO\Services\ImageGeneratorService::h2_interval());
        $needed             = min(count($attachment_ids), count($eligible));
        $total              = count($eligible);

        if ($total === 0 || $needed === 0) return $content;

        // Calcula interval efetivo: se artigo é pequeno, reduz pra caber as imagens pedidas
        $max_imgs_with_config_interval = (int) ceil($total / $h2_interval_config);
        $effective_interval = ($needed > $max_imgs_with_config_interval && $needed > 1)
            ? max(2, (int) floor($total / $needed))
            : $h2_interval_config;

        // Distância mínima entre imagens — adaptativa
        $min_distance = max(2, $effective_interval - 1);

        $slots = [];
        $start_index = ($total >= 3) ? 1 : 0; // pula primeiro H2 só se houver espaço

        for ($i = $start_index; $i < $total && count($slots) < $needed; $i += $effective_interval) {
            $slots[] = $eligible[$i];
        }

        // Se ainda faltar, distribui com distância mínima
        if (count($slots) < $needed) {
            $used_indexes = array_map(fn($s) => $s['idx'], $slots);
            foreach ($eligible as $candidate) {
                if (count($slots) >= $needed) break;
                if (in_array($candidate['idx'], $used_indexes, true)) continue;
                $too_close = false;
                foreach ($used_indexes as $u) {
                    if (abs($candidate['idx'] - $u) < $min_distance) { $too_close = true; break; }
                }
                if (!$too_close) {
                    $slots[] = $candidate;
                    $used_indexes[] = $candidate['idx'];
                }
            }
        }

        // Inserir de trás para frente para não quebrar offsets
        usort($slots, fn($a, $b) => $b['pos'] <=> $a['pos']);
        $url_index = min(count($attachment_ids), count($slots)) - 1;
        foreach ($slots as $slot) {
            if ($url_index < 0) break;
            $attachment_id = (int)$attachment_ids[$url_index];
            $h2_text = $slot['text'];
            $alt_text = trim(($h2_text ?: $keyword) . ' - imagem editorial ' . ($url_index + 1));
            $fig = \GeoMetodoSEO\Services\LibraryImageService::build_attachment_figure_html($attachment_id, $alt_text, ['sara-internal-queued-image'], [], $alt_text);
            if ($fig === '') { $url_index--; continue; }
            if ($post_id > 0) { \GeoMetodoSEO\Services\LibraryImageService::remember_body_image($post_id, $attachment_id, \GeoMetodoSEO\Services\LibraryImageService::is_library_mode() ? 'library' : 'ai'); }
            $insert_pos = $slot['pos'] + strlen($slot['h2']);
            $content = substr($content, 0, $insert_pos) . $fig . substr($content, $insert_pos);
            $url_index--;
        }

        return $content;
    }

    private static function select_spaced_contexts(array $h2s, int $count): array {
        if (count($h2s) <= $count) return $h2s;
        $count = max(1, min(ImageGeneratorService::body_images_count(), $count));
        $step = max(ImageGeneratorService::h2_interval(), (int) floor(count($h2s) / $count));
        $selected = [];
        $cursor = min(1, count($h2s) - 1);
        while (count($selected) < $count && $cursor < count($h2s)) {
            $selected[] = $h2s[$cursor];
            $cursor += $step;
        }
        foreach ($h2s as $h2) {
            if (count($selected) >= $count) break;
            if (!in_array($h2, $selected, true)) $selected[] = $h2;
        }
        return $selected;
    }

    private static function select_image_h2_indexes(string $content, int $count): array {
        if (!preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $content, $matches)) return [];
        $eligible = [];
        foreach ($matches[1] as $idx => $h2) {
            $text = trim(wp_strip_all_tags($h2));
            if ($text !== '' && !preg_match('/perguntas\s+frequentes|faq|conclus[aã]o/iu', $text)) {
                $eligible[] = $idx;
            }
        }
        if (empty($eligible)) return [];

        $count = max(1, min(ImageGeneratorService::body_images_count(), $count));
        $step = max(ImageGeneratorService::h2_interval(), (int) floor(count($eligible) / $count));
        $selected = [];
        $cursor = min(1, count($eligible) - 1);
        while (count($selected) < $count && $cursor < count($eligible)) {
            $selected[] = $eligible[$cursor];
            $cursor += $step;
        }
        foreach ($eligible as $idx) {
            if (count($selected) >= $count) break;
            if (!in_array($idx, $selected, true)) $selected[] = $idx;
        }
        sort($selected);
        return $selected;
    }
}
