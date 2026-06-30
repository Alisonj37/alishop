<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) exit;

/**
 * OperationalDashboardService
 *
 * Controles manuais de fila/retry/reprocessamento. Evita alterar core de geração.
 */
class OperationalDashboardService {

    public static function register_hooks(): void {
        add_action('wp_ajax_geo_diag_retry_job', [__CLASS__, 'ajax_retry_job']);
        add_action('wp_ajax_geo_diag_clear_stuck_jobs', [__CLASS__, 'ajax_clear_stuck_jobs']);
        add_action('wp_ajax_geo_diag_reprocess_media', [__CLASS__, 'ajax_reprocess_media']);
        add_action('wp_ajax_geo_diag_post_timeline', [__CLASS__, 'ajax_post_timeline']);
    }

    public static function render_queue_monitor(): void {
        $queue = get_option('geo_scheduled_queue', []);
        if (!is_array($queue)) $queue = [];
        $counts = ['total' => count($queue), 'pending' => 0, 'processing' => 0, 'failed' => 0, 'done' => 0, 'stuck' => 0];
        $now = time();
        foreach ($queue as $j) {
            $status = (string)($j['status'] ?? 'pending');
            if (isset($counts[$status])) $counts[$status]++;
            if (in_array($status, ['processing','retrying'], true) && (int)($j['started_at'] ?? $j['updated_at'] ?? $j['scheduled_at'] ?? 0) < ($now - 45 * MINUTE_IN_SECONDS)) {
                $counts['stuck']++;
            }
        }
        echo '<div class="geo-card">';
        echo '<h2>⚙️ Monitor operacional da fila</h2>';
        echo '<p><strong>Total:</strong> ' . esc_html((string)$counts['total']) . ' | <strong>Pendentes:</strong> ' . esc_html((string)$counts['pending']) . ' | <strong>Processando:</strong> ' . esc_html((string)$counts['processing']) . ' | <strong>Falhos:</strong> ' . esc_html((string)$counts['failed']) . ' | <strong>Travados:</strong> ' . esc_html((string)$counts['stuck']) . '</p>';
        echo '<p><button class="button" id="geo-clear-stuck-jobs">Recuperar jobs travados</button></p>';
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Status</th><th>Keyword</th><th>Tipo</th><th>Provider/model</th><th>Retries</th><th>Post</th><th>Ações</th></tr></thead><tbody>';
        if (!$queue) {
            echo '<tr><td colspan="8">Fila vazia.</td></tr>';
        }
        foreach (array_slice($queue, 0, 50, true) as $idx => $job) {
            $status = (string)($job['status'] ?? 'pending');
            $post_id = (int)($job['post_id'] ?? 0);
            echo '<tr>';
            echo '<td><code>' . esc_html((string)$idx) . '</code></td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html((string)($job['keyword'] ?? $job['kw'] ?? $job['title'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string)($job['type'] ?? 'article')) . '</td>';
            echo '<td>' . esc_html((string)($job['provider'] ?? '—')) . '<br><code>' . esc_html((string)($job['model'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string)($job['retry_count'] ?? 0)) . '</td>';
            echo '<td>' . ($post_id ? '<a href="' . esc_url(get_edit_post_link($post_id)) . '">#' . esc_html((string)$post_id) . '</a>' : '—') . '</td>';
            echo '<td><button class="button button-small geo-retry-job" data-job="' . esc_attr((string)$idx) . '">Retry</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public static function ajax_retry_job(): void {
        self::check();
        $job_id = sanitize_text_field($_POST['job_id'] ?? '');
        $queue = get_option('geo_scheduled_queue', []);
        if (!is_array($queue) || $job_id === '' || !array_key_exists($job_id, $queue)) {
            wp_send_json_error(['message' => 'Job não encontrado.']);
        }
        $queue[$job_id]['status'] = 'pending';
        $queue[$job_id]['scheduled_at'] = time() + 30;
        $queue[$job_id]['retry_count'] = (int)($queue[$job_id]['retry_count'] ?? 0) + 1;
        $queue[$job_id]['last_retry_at'] = current_time('mysql');
        update_option('geo_scheduled_queue', $queue, false);
        self::ensure_schedule();
        LogService::record('settings', 'success', 'Job reenfileirado manualmente', ['action' => 'manual_retry_job', 'context' => ['job_id' => $job_id]]);
        wp_send_json_success(['message' => 'Job reenfileirado com sucesso.']);
    }

    public static function ajax_clear_stuck_jobs(): void {
        self::check();
        $queue = get_option('geo_scheduled_queue', []);
        if (!is_array($queue)) $queue = [];
        $now = time();
        $changed = 0;
        foreach ($queue as &$job) {
            $status = (string)($job['status'] ?? '');
            $last = (int)($job['started_at'] ?? $job['updated_at'] ?? $job['scheduled_at'] ?? 0);
            if (in_array($status, ['processing','retrying'], true) && $last < ($now - 45 * MINUTE_IN_SECONDS)) {
                $job['status'] = 'pending';
                $job['scheduled_at'] = $now + 60;
                $job['recovered_at'] = current_time('mysql');
                $job['retry_count'] = (int)($job['retry_count'] ?? 0) + 1;
                $changed++;
            }
        }
        unset($job);
        update_option('geo_scheduled_queue', $queue, false);
        self::clear_expired_locks();
        self::ensure_schedule();
        LogService::record('settings', 'success', 'Jobs travados recuperados', ['action' => 'clear_stuck_jobs', 'context' => ['changed' => $changed]]);
        wp_send_json_success(['message' => $changed . ' job(s) recuperado(s).']);
    }

    public static function ajax_reprocess_media(): void {
        self::check();
        $post_id = absint($_POST['post_id'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post) wp_send_json_error(['message' => 'Post inválido.']);
        if (!class_exists('GeoMetodoSEO\\Services\\GeoMediaMasterService')) {
            wp_send_json_error(['message' => 'GeoMediaMasterService indisponível.']);
        }
        $keyword = get_post_meta($post_id, '_geo_keyword_exact', true) ?: get_post_meta($post_id, 'rank_math_focus_keyword', true) ?: $post->post_title;
        $service = new GeoMediaMasterService();
        $result = $service->ensure_post_media($post_id, [
            'keyword' => (string)$keyword,
            'title' => $post->post_title,
            'body_images' => 4,
            'require_featured' => true,
            'process_now' => true,
        ]);
        LogService::record('media', !empty($result['success']) ? 'success' : 'warning', 'Reprocessamento manual de mídia executado', ['action' => 'manual_reprocess_media', 'post_id' => $post_id, 'context' => $result]);
        wp_send_json_success(['message' => 'Reprocessamento executado.', 'result' => $result]);
    }

    public static function ajax_post_timeline(): void {
        self::check();
        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id || !get_post($post_id)) wp_send_json_error(['message' => 'Post inválido.']);
        $rows = ObservabilityService::timeline_for_post($post_id, 30);
        wp_send_json_success(['post_id' => $post_id, 'timeline' => $rows]);
    }

    private static function check(): void {
        check_ajax_referer('geo_diagnostics_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.']);
    }

    private static function ensure_schedule(): void {
        if (!wp_next_scheduled('geo_auto_schedule_event')) {
            wp_schedule_event(time() + 60, 'geo_five_minutes', 'geo_auto_schedule_event');
        }
    }

    private static function clear_expired_locks(): void {
        global $wpdb;
        $now = time();
        $options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_geo\\_lock\\_%'", ARRAY_A);
        foreach ((array)$options as $row) {
            if ((int)$row['option_value'] <= $now) {
                delete_option((string)$row['option_name']);
            }
        }
    }
}
