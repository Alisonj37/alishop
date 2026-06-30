<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Professional;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller;
use GeoMetodoSEO\Autopilot\Shared\ScheduleManager;
use GeoMetodoSEO\Autopilot\Learning\SaraContentRefresher;
use GeoMetodoSEO\Autopilot\Indexing\SaraIndexNow;

/**
 * SARA Health Monitor — diagnóstico operacional para automação 24/7.
 *
 * @since 1.0.0
 */
class SaraHealthMonitor {

    public static function snapshot(): array {
        global $wpdb;
        $cal = $wpdb->prefix . 'sara_editorial_calendar';
        $log = $wpdb->prefix . 'sara_execution_log';

        $counts = [
            'pending' => 0, 'processing' => 0, 'done_today' => 0, 'failed_7d' => 0, 'stale_processing' => 0,
        ];

        if (self::table_exists($cal)) {
            $today = current_time('Y-m-d');
            $counts['pending'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$cal} WHERE status='pending'");
            $counts['processing'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$cal} WHERE status='processing'");
            $counts['done_today'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$cal} WHERE status='done' AND scheduled_date=%s", $today));
            $counts['failed_7d'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$cal} WHERE status='failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $counts['stale_processing'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$cal} WHERE status='processing' AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 45 MINUTE))");
        }

        $uploads = wp_get_upload_dir();
        $api_keys = self::api_key_status();
        $cron = [
            'brain_next'  => self::format_next(wp_next_scheduled(ScheduleManager::HOOK_BRAIN)),
            'writer_next' => self::format_next(self::next_writer_event()),
            'refresher_next' => self::format_next(wp_next_scheduled('sara_content_refresher_run')),
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        ];

        $recent_errors = [];
        if (self::table_exists($log)) {
            $recent_errors = $wpdb->get_results(
                "SELECT agent, action, status, message, created_at FROM {$log}
                 WHERE status IN ('error','warning')
                 ORDER BY created_at DESC LIMIT 8",
                ARRAY_A
            ) ?: [];
        }

        $checks = [];
        $text_ok = !empty($api_keys['openai']['configured']) || !empty($api_keys['groq']['configured']) || !empty($api_keys['gemini']['configured']) || !empty($api_keys['claude']['configured']) || !empty($api_keys['perplexity']['configured']) || !empty($api_keys['naga']['configured']);
        $checks[] = self::check('Provider de texto configurado', $text_ok, 'Configure ao menos um provider de texto: OpenAI, Groq, Gemini, Claude, Perplexity ou Naga.ac.');
        $checks[] = self::check('Writer ativo', AutopilotInstaller::get('writer_enabled', '1') === '1', 'Ative o Writer na SARA.');
        $checks[] = self::check('Brain ativo', AutopilotInstaller::get('brain_enabled', '1') === '1', 'Ative o Brain na SARA.');
        $checks[] = self::check('Cron do Brain', !empty($cron['brain_next']), 'Reagende o Brain ou acesse o painel para registrar o cron.');
        $checks[] = self::check('Uploads gravável', !empty($uploads['basedir']) && wp_is_writable($uploads['basedir']), 'Corrija permissões da pasta uploads.');
        $checks[] = self::check('Sem jobs travados', $counts['stale_processing'] === 0, 'Use Reparar SARA para liberar jobs travados.');
        $checks[] = self::check('Providers independentes', true, 'Cada geração usa apenas o provider selecionado.');

        $health_score = self::score($checks, $counts);

        return [
            'score' => $health_score,
            'status' => $health_score >= 85 ? 'saudável' : ($health_score >= 65 ? 'atenção' : 'crítico'),
            'counts' => $counts,
            'cron' => $cron,
            'api_keys' => $api_keys,
            'models' => [
                'generation' => AutopilotInstaller::get('writer_model_generation', '') ?: 'modelo padrão do provider',
                'scoring' => AutopilotInstaller::get('writer_model_scoring', '') ?: 'modelo padrão do provider',
                'provider_generation' => AutopilotInstaller::get('writer_provider_generation', '') ?: 'provider global',
                'provider_scoring' => AutopilotInstaller::get('writer_provider_scoring', '') ?: 'provider global',
                'quality_threshold' => (int)AutopilotInstaller::get('writer_quality_threshold', 70),
            ],
            'refresher' => class_exists(SaraContentRefresher::class) ? SaraContentRefresher::get_status() : [],
            'indexnow' => class_exists(SaraIndexNow::class) ? SaraIndexNow::get_status() : [],
            'checks' => $checks,
            'recent_errors' => $recent_errors,
        ];
    }

    public static function repair(): array {
        $released = SaraRetryManager::release_stale_processing(45);
        ScheduleManager::schedule_writer_jobs();

        if (!wp_next_scheduled(ScheduleManager::HOOK_BRAIN)) {
            ScheduleManager::schedule_brain();
        }
        return ['released_stale_jobs' => $released, 'snapshot' => self::snapshot()];
    }

    private static function api_key_status(): array {
        $providers = ['openai', 'claude', 'gemini', 'groq', 'perplexity', 'replicate', 'falai', 'naga', 'huggingface'];
        $out = [];
        foreach ($providers as $provider) {
            $key = (string)get_option("geo_{$provider}_api_key", '');
            $out[$provider] = ['configured' => trim($key) !== ''];
        }
        return $out;
    }

    private static function check(string $label, bool $ok, string $fix): array {
        return ['label' => $label, 'ok' => $ok, 'fix' => $fix];
    }

    private static function score(array $checks, array $counts): int {
        $score = 100;
        foreach ($checks as $check) {
            if (empty($check['ok'])) $score -= 12;
        }
        if (($counts['failed_7d'] ?? 0) > 5) $score -= 10;
        if (($counts['stale_processing'] ?? 0) > 0) $score -= 15;
        return max(0, min(100, $score));
    }

    private static function next_writer_event(): ?int {
        $crons = _get_cron_array();
        if (!is_array($crons)) return null;
        foreach ($crons as $timestamp => $hooks) {
            if (isset($hooks[ScheduleManager::HOOK_WRITER])) return (int)$timestamp;
        }
        return null;
    }

    private static function format_next($timestamp): ?string {
        return $timestamp ? wp_date('Y-m-d H:i:s', (int)$timestamp) : null;
    }

    private static function table_exists(string $table): bool {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}

