<?php
namespace GeoMetodoSEO\Autopilot\Brain;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\{AutopilotInstaller, AutopilotLogger};

/**
 * SaraBrain v2.0 — Controller do Agente A.
 * Orquestra: Indexer → NicheDetector → Decay Detector → Planner v2 → Decision Engine
 * Roda diariamente às 22:00.
 */
class SaraBrain {

    private SaraIndexer       $indexer;
    private SaraPlanner       $planner;
    private SaraNicheDetector $niche_detector;
    private SaraCategoryFilter $cat_filter;
    private SaraApiClient     $api_client;

    public function __construct() {
        $this->indexer        = new SaraIndexer();
        $this->planner        = new SaraPlanner();
        $this->niche_detector = new SaraNicheDetector();
        $this->cat_filter     = new SaraCategoryFilter();
        $this->api_client     = new SaraApiClient();
    }

    /**
     * Ponto de entrada principal — chamado pelo WP Cron.
     */
    public function run(): void {
        $t0 = microtime(true);

        if (!AutopilotInstaller::get('brain_enabled', '1')) {
            AutopilotLogger::log('brain', 'run_skip', 'skip', 'Brain desabilitado nas configurações');
            return;
        }

        $this->api_client->brain_log('run_start: SARA Brain iniciado');
        AutopilotLogger::log('brain', 'run_start', 'start', 'SARA Brain v2.0 iniciado');

        try {
            // 1. Indexação incremental
            $indexed = $this->indexer->index_incremental();
            $this->api_client->brain_log("indexer_incremental: Indexados {$indexed} posts");

            // 2. Auto-detectar nicho (com cache 24h)
            if (empty(get_option('sara_niche'))) {
                $niche_data = $this->niche_detector->detect_niche();
                $this->api_client->brain_log(
                    "niche_detected: {$niche_data['niche']} (confiança: {$niche_data['confidence']}%)"
                );
            }

            // 3. Verificar categorias ativas
            if (!$this->cat_filter->has_minimum()) {
                $this->api_client->brain_log('planner_error: Nenhuma categoria ativa selecionada');
                AutopilotLogger::log('brain', 'planner_error', 'warning',
                    'Menos de 2 categorias ativas — configure em SARA Autopilot → Configurações');
            }

            // 4. Detectar content decay
            $decayed = $this->detect_decay();

            // 5. Planejar artigos para amanhã (SaraPlanner v2.0)
            $planned = $this->planner->plan_tomorrow();

            $this->api_client->brain_log(
                "run_done: Brain concluído: {$indexed} indexados, {$planned} planejados, 0 falhas"
            );

            AutopilotLogger::log('brain', 'run_done', 'success',
                "Brain concluído: {$indexed} indexados, {$decayed} decay, {$planned} planejados", [
                    'duration_ms' => AutopilotLogger::elapsed($t0),
                ]
            );

        } catch (\Throwable $e) {
            AutopilotLogger::log('brain', 'run_error', 'error',
                'Exceção no Brain: ' . $e->getMessage(), [
                    'duration_ms' => AutopilotLogger::elapsed($t0),
                ]
            );
        }
    }

    /**
     * Detectar posts com decay (sem atualização + queda de performance estimada).
     */
    private function detect_decay(): int {
        $threshold_days = (int) AutopilotInstaller::get('brain_decay_threshold', 30);
        $cutoff = wp_date('Y-m-d', current_time('timestamp') - ($threshold_days * DAY_IN_SECONDS));

        global $wpdb;
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT si.post_id, si.title, si.updated_at
             FROM {$wpdb->prefix}sara_semantic_index si
             WHERE si.updated_at < %s
             LIMIT 50",
            $cutoff . ' 00:00:00'
        ), ARRAY_A);

        $decayed = 0;
        foreach ($posts as $post) {
            $updated_ts = mysql2date('U', (string) $post['updated_at'], false);
            $days_old   = $updated_ts ? (int) ((current_time('timestamp') - $updated_ts) / DAY_IN_SECONDS) : $threshold_days;
            $score      = min(100, (int)($days_old / $threshold_days * 50));
            $this->indexer->update_decay((int)$post['post_id'], $score);
            if ($score > 30) $decayed++;
        }

        return $decayed;
    }

    /** Executar manualmente via AJAX */
    public function run_ajax(): void {
        check_ajax_referer('sara_autopilot_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão']);

        $this->run();
        wp_send_json_success(['message' => 'Brain executado com sucesso']);
    }

    /** Status atual do Brain */
    public function status(): array {
        $next = \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::next_brain();
        return [
            'enabled'       => (bool) AutopilotInstaller::get('brain_enabled', '1'),
            'indexed_posts' => $this->indexer->count(),
            'next_run'      => $next ? wp_date('d/m/Y H:i', $next) : 'Não agendado',
            'last_run'      => get_option('sara_brain_last_run', 'Nunca'),
            'site_mode'     => AutopilotInstaller::get('site_mode', 'conservative'),
        ];
    }
}
