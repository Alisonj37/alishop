<?php
namespace GeoMetodoSEO\Autopilot\Shared;

if (!defined('ABSPATH')) { exit; }

/**
 * ScheduleManager — Gerencia os WP Cron dos 2 agentes.
 * Agente A (Brain): diário às 22:00
 * Agente B (Writer): horários configuráveis (07:00, 09:00, 11:00...)
 */
class ScheduleManager {

    const HOOK_BRAIN  = 'sara_autopilot_brain_run';
    const HOOK_WRITER = 'sara_autopilot_writer_run';

    /** Registrar hooks de cron */
    public static function register(): void {
        add_action(self::HOOK_BRAIN,  [__CLASS__, 'run_brain']);
        add_action(self::HOOK_WRITER, [__CLASS__, 'run_writer'], 10, 1);

        // BUG FIX 1.0.0: Garantir que o agendamento dos Writer jobs seja revisado a cada init.
        // Isso recupera jobs que foram planejados ontem para hoje (mas que ninguém
        // estava online à meia-noite para disparar o init e agendar o cron).
        add_action('init', [__CLASS__, 'ensure_writer_jobs_scheduled'], 20);

        // Adicionar intervalos customizados
        add_filter('cron_schedules', [__CLASS__, 'add_intervals']);
    }

    /**
     * Garantir que TODOS os jobs pendentes (de hoje E que já passaram do horário)
     * tenham crons agendados. Recupera jobs perdidos e dispara os atrasados imediatamente.
     *
     * @since 1.0.0 Corrigir Writer automático que não rodava jobs pendentes
     */
    public static function ensure_writer_jobs_scheduled(): void {
        // Não rodar em todas as requisições — limitar a 1x a cada 5 min via transient
        if (get_transient('sara_writer_schedule_check')) return;
        set_transient('sara_writer_schedule_check', 1, 5 * MINUTE_IN_SECONDS);

        // Só rodar se Writer estiver ativo
        if (AutopilotInstaller::get('writer_enabled', '1') !== '1') return;

        global $wpdb;
        $cal_table = $wpdb->prefix . 'sara_editorial_calendar';
        $today     = self::today_date();
        $now       = self::now_timestamp();

        // Pegar jobs pendentes de hoje (ou anteriores que ficaram presos)
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, scheduled_date, scheduled_time
             FROM {$cal_table}
             WHERE status = 'pending' AND scheduled_date <= %s
             ORDER BY scheduled_date ASC, scheduled_time ASC
             LIMIT 30",
            $today
        ), ARRAY_A);

        $scheduled_now    = 0;
        $scheduled_future = 0;

        foreach ($jobs as $job) {
            $job_id  = (int)$job['id'];
            $job_ts  = self::local_timestamp((string) $job['scheduled_date'], (string) $job['scheduled_time']);

            // Se já tem cron agendado para este job, pular
            if (wp_next_scheduled(self::HOOK_WRITER, [$job_id])) continue;

            if ($job_ts > $now) {
                // Job futuro: agendar para o horário correto
                wp_schedule_single_event($job_ts, self::HOOK_WRITER, [$job_id]);
                $scheduled_future++;
            } else {
                // Job atrasado: agendar para 30 segundos a partir de agora
                // (escalonado de 30 em 30 seg para não sobrecarregar a API de IA)
                wp_schedule_single_event(
                    $now + 30 + ($scheduled_now * 30),
                    self::HOOK_WRITER,
                    [$job_id]
                );
                $scheduled_now++;
            }
        }

        if ($scheduled_now > 0 || $scheduled_future > 0) {
            AutopilotLogger::log(
                'system', 'writer_recovery', 'success',
                "Recuperação Writer: {$scheduled_now} jobs atrasados re-agendados, {$scheduled_future} futuros agendados"
            );
        }
    }

    public static function add_intervals(array $schedules): array {
        $schedules['sara_daily_22'] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => 'SARA Brain — Diário às 22h',
        ];
        return $schedules;
    }

    /** Agendar Brain para rodar hoje às 22:00 (se não agendado) */
    public static function schedule_brain(): void {
        if (wp_next_scheduled(self::HOOK_BRAIN)) return;

        $run_time = AutopilotInstaller::get('brain_run_time', '22:00');
        [$h, $m]  = array_map('intval', explode(':', $run_time));
        $now      = self::now_timestamp();
        $base     = self::today_date();
        $today    = self::local_timestamp($base, sprintf('%02d:%02d:00', $h, $m));
        $next     = ($today > $now) ? $today : $today + DAY_IN_SECONDS;

        wp_schedule_event($next, 'sara_daily_22', self::HOOK_BRAIN);
    }

    /**
     * Agendar Writer para os horários configurados.
     * Chamado APÓS o Brain terminar de planejar o dia seguinte.
     *
     * @since 1.0.0 BUG FIX: agendar jobs de qualquer data futura, não só de hoje
     */
    public static function schedule_writer_jobs(): void {
        global $wpdb;
        $cal_table = $wpdb->prefix . 'sara_editorial_calendar';
        $now       = self::now_timestamp();

        // BUG FIX 1.0.0: pegar todos os jobs pendentes futuros, não só de hoje
        // (o Brain planeja para amanhã, então amanhã também precisa ser agendado)
        $today = self::today_date();
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, scheduled_date, scheduled_time FROM {$cal_table}
             WHERE scheduled_date >= %s AND status = 'pending'
             ORDER BY scheduled_date ASC, scheduled_time ASC",
            $today
        ), ARRAY_A);

        $scheduled = 0;
        foreach ($jobs as $job) {
            $job_id = (int)$job['id'];
            $job_ts = self::local_timestamp((string) $job['scheduled_date'], (string) $job['scheduled_time']);

            // Se já tem cron agendado, pular
            if (wp_next_scheduled(self::HOOK_WRITER, [$job_id])) continue;

            if ($job_ts > $now) {
                wp_schedule_single_event($job_ts, self::HOOK_WRITER, [$job_id]);
                $scheduled++;
            } else {
                // Atrasado — agendar com pequeno delay
                wp_schedule_single_event(
                    $now + 60 + ($scheduled * 30),
                    self::HOOK_WRITER,
                    [$job_id]
                );
                $scheduled++;
            }
        }

        if ($scheduled > 0) {
            AutopilotLogger::log(
                'system', 'writer_scheduled', 'success',
                "Agendados {$scheduled} jobs do Writer"
            );
        }
    }

    /**
     * Parsear horários do Writer com tolerância a formatos legados/quebrados.
     * Aceita: JSON ["07:00","09:00"], lista 07:00,09:00, ou string corrompida.
     * Sempre retorna pelo menos um horário válido.
     *
     * @since 1.0.0
     */
    public static function parse_writer_times(string $raw): array {
        $raw   = trim($raw);
        $times = [];

        // Tentar JSON
        if ($raw !== '' && $raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $times = $decoded;
        }

        // Fallback: split por vírgula/espaço/ponto-e-vírgula (lida com JSON corrompido pelo sanitize)
        if (empty($times)) {
            $parts = preg_split('/[,;\s\[\]"\']+/', $raw) ?: [];
            $times = array_filter(array_map('trim', $parts));
        }

        // Validar formato HH:MM e normalizar
        $valid = [];
        foreach ($times as $t) {
            $t = trim((string) $t, "\"' \t\n\r");
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $t, $m)) {
                $valid[] = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            }
        }

        $valid = array_values(array_unique($valid));
        sort($valid);

        return !empty($valid) ? $valid : ['07:00', '09:00', '11:00'];
    }

    /** Callback: rodar Agente A */
    public static function run_brain(): void {
        if (class_exists('GeoMetodoSEO\\License\\LicenseManager') && !\GeoMetodoSEO\License\LicenseManager::isActive()) return;
        if (!class_exists('GeoMetodoSEO\Autopilot\Brain\SaraBrain')) return;
        $brain = new \GeoMetodoSEO\Autopilot\Brain\SaraBrain();
        $brain->run();
    }

    /** Callback: rodar Agente B para um job específico */
    public static function run_writer(int $calendar_id): void {
        if (class_exists('GeoMetodoSEO\\License\\LicenseManager') && !\GeoMetodoSEO\License\LicenseManager::isActive()) return;
        if (!class_exists('GeoMetodoSEO\Autopilot\Writer\SaraWriter')) return;
        $writer = new \GeoMetodoSEO\Autopilot\Writer\SaraWriter();
        $writer->run($calendar_id);
    }

    public static function timezone(): \DateTimeZone {
        // SARA deve obedecer horário do Brasil/São Paulo, independente do timezone do servidor.
        return new \DateTimeZone('America/Sao_Paulo');
    }

    public static function now_timestamp(): int {
        return (new \DateTimeImmutable('now', self::timezone()))->getTimestamp();
    }

    public static function today_date(): string {
        return (new \DateTimeImmutable('now', self::timezone()))->format('Y-m-d');
    }

    public static function local_timestamp(string $date, string $time): int {
        $time = preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time) ? $time : '00:00:00';
        if (substr_count($time, ':') === 1) {
            $time .= ':00';
        }
        try {
            $dt = new \DateTimeImmutable($date . ' ' . $time, self::timezone());
            return $dt->getTimestamp();
        } catch (\Exception $e) {
            return self::now_timestamp();
        }
    }

    /** Limpar todos os crons do autopilot */
    public static function clear(): void {
        wp_clear_scheduled_hook(self::HOOK_BRAIN);
        wp_clear_scheduled_hook(self::HOOK_WRITER);
    }

    /** Próxima execução (timestamp) */
    public static function next_brain(): ?int {
        $ts = wp_next_scheduled(self::HOOK_BRAIN);
        return $ts ?: null;
    }

    public static function next_writer(): ?int {
        // Retornar o próximo job de writer que está agendado
        global $wpdb;
        $cal = $wpdb->prefix . 'sara_editorial_calendar';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT scheduled_date, scheduled_time FROM {$cal}
             WHERE status = 'pending' AND scheduled_date >= %s
             ORDER BY scheduled_date ASC, scheduled_time ASC LIMIT 1",
            self::today_date()
        ), ARRAY_A);
        if (!$row) return null;
        return self::local_timestamp((string) $row['scheduled_date'], (string) $row['scheduled_time']);
    }
}
