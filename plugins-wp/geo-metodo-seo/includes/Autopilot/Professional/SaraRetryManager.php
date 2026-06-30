<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Professional;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller;
use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;
use GeoMetodoSEO\Autopilot\Shared\ScheduleManager;

/**
 * SARA Retry Manager — retry profissional com backoff progressivo.
 * Providers independentes: sem troca automática de provider.
 */
class SaraRetryManager {

    public static function handle_failure(int $calendar_id, string $reason, string $agent = 'writer'): bool {
        // Providers independentes: erros de quota/billing pertencem ao provider escolhido.
        // Não pausamos a SARA globalmente nem trocamos de provider.

        global $wpdb;
        $table = $wpdb->prefix . 'sara_editorial_calendar';

        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $calendar_id), ARRAY_A);
        if (!$job) return false;

        $max_retries = max(0, (int) AutopilotInstaller::get('writer_max_job_retries', 3));
        $retry_count = max(0, (int)($job['retry_count'] ?? 0));
        $reason_safe = self::clean_reason($reason);

        if ($retry_count >= $max_retries) {
            $wpdb->update($table, [
                'status'         => 'failed',
                'failure_reason' => $reason_safe,
                'last_error'     => $reason_safe,
                'executed_at'    => current_time('mysql'),
                'locked_at'      => null,
            ], ['id' => $calendar_id]);

            self::clear_scheduled_writer_event($calendar_id);

            AutopilotLogger::log($agent, 'retry_exhausted', 'error',
                "Job #{$calendar_id} falhou definitivamente após {$retry_count} retries: {$reason_safe}",
                ['calendar_id' => $calendar_id]
            );
            return false;
        }

        $next_retry = $retry_count + 1;
        $delay_min  = self::delay_minutes($next_retry);
        $event_ts   = time() + ($delay_min * MINUTE_IN_SECONDS); // WP-Cron usa timestamp Unix real.
        $local      = self::local_datetime_after($delay_min * MINUTE_IN_SECONDS);

        $wpdb->update($table, [
            'status'         => 'pending',
            'scheduled_date' => $local['date'],
            'scheduled_time' => $local['time'],
            'retry_count'    => $next_retry,
            'failure_reason' => 'Retry ' . $next_retry . '/' . $max_retries . ': ' . $reason_safe,
            'last_error'     => $reason_safe,
            'locked_at'      => null,
        ], ['id' => $calendar_id]);

        self::clear_scheduled_writer_event($calendar_id);
        wp_schedule_single_event($event_ts, ScheduleManager::HOOK_WRITER, [$calendar_id]);

        AutopilotLogger::log($agent, 'retry_scheduled', 'warning',
            "Job #{$calendar_id} reagendado para {$local['date']} {$local['time']} após falha: {$reason_safe}",
            ['calendar_id' => $calendar_id, 'context' => ['retry_count' => $next_retry, 'delay_minutes' => $delay_min]]
        );

        return true;
    }

    public static function handle_provider_error(int $calendar_id, string $reason, string $agent = 'writer'): bool {
        // Providers independentes: não existe bloqueio global.
        // O erro pertence ao provider escolhido e seguirá o retry normal do job.
        AutopilotLogger::log($agent, 'provider_error_no_pause', 'warning',
            'Provider escolhido falhou: ' . self::clean_reason($reason),
            ['calendar_id' => $calendar_id]
        );
        return false;
    }

    public static function is_provider_blocking_error(string $reason): bool {
        $e = mb_strtolower($reason);
        foreach (['sem crédito', 'sem credito', 'credit balance', 'prepaid', 'hard limit'] as $needle) {
            if (str_contains($e, $needle)) return true;
        }
        return false;
    }

    private static function clean_reason(string $reason): string {
        return mb_substr(wp_strip_all_tags($reason), 0, 1000);
    }

    private static function local_datetime_after(int $seconds): array {
        try {
            $dt = new \DateTimeImmutable('now', wp_timezone());
            $dt = $dt->modify('+' . max(0, $seconds) . ' seconds');
            return ['date' => $dt->format('Y-m-d'), 'time' => $dt->format('H:i:s')];
        } catch (\Throwable $e) {
            $ts = time() + max(0, $seconds);
            return ['date' => date('Y-m-d', $ts), 'time' => date('H:i:s', $ts)];
        }
    }

    private static function clear_scheduled_writer_event(int $calendar_id): void {
        // Remove eventos duplicados para o mesmo job. Evita martelar a API se o servidor já acumulou WP-Cron.
        while ($ts = wp_next_scheduled(ScheduleManager::HOOK_WRITER, [$calendar_id])) {
            wp_unschedule_event($ts, ScheduleManager::HOOK_WRITER, [$calendar_id]);
        }
    }

    public static function delay_minutes(int $retry_number): int {
        $map = [1 => 2, 2 => 10, 3 => 30, 4 => 90, 5 => 180];
        return $map[$retry_number] ?? min(360, 60 * $retry_number);
    }

    public static function release_stale_processing(int $older_than_minutes = 45): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sara_editorial_calendar';
        $cutoff = (new \DateTimeImmutable('now', wp_timezone()))->modify('-' . $older_than_minutes . ' minutes')->format('Y-m-d H:i:s');

        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE status = 'processing'
               AND (locked_at IS NULL OR locked_at < %s)
             LIMIT 20",
            $cutoff
        ), ARRAY_A);

        $reset = 0;
        foreach ($jobs as $job) {
            $id = (int)$job['id'];
            if (self::handle_failure($id, 'Job ficou travado em processing e foi liberado pelo Health Monitor', 'system')) {
                $reset++;
            }
        }
        return $reset;
    }
}
