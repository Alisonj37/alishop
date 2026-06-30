<?php
namespace GeoMetodoSEO\Autopilot;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\{AutopilotInstaller, AutopilotLogger, ScheduleManager};
use GeoMetodoSEO\Autopilot\Brain\SaraBrain;
use GeoMetodoSEO\Autopilot\Writer\SaraWriter;

/**
 * AutopilotController — Integra o SARA Autopilot ao plugin principal.
 * Registra menu, AJAX handlers e hooks.
 */
class AutopilotController {

    public static function boot(): void {
        // Instalar tabelas imediatamente (não defer para admin_init — evita 404 no primeiro acesso)
        if (is_admin()) {
            AutopilotInstaller::run();
        }
        // Também agendar para admin_init como fallback
        add_action('admin_init', [AutopilotInstaller::class, 'run']);

        // Registrar crons
        add_action('init', [ScheduleManager::class, 'register']);
        add_action('init', [ScheduleManager::class, 'schedule_brain']);

        // Menu admin — prioridade 20 garante que o menu pai (geo-metodo-seo) já existe
        add_action('admin_menu', [__CLASS__, 'add_menu'], 20);

        // AJAX handlers
        add_action('wp_ajax_sara_autopilot_run_brain',        [__CLASS__, 'ajax_run_brain']);
        add_action('wp_ajax_sara_autopilot_run_writer_next',  [__CLASS__, 'ajax_run_writer_next']);
        add_action('wp_ajax_sara_autopilot_run_job',          [__CLASS__, 'ajax_run_job']);
        add_action('wp_ajax_sara_autopilot_save_config',      [__CLASS__, 'ajax_save_config']);
        add_action('wp_ajax_sara_autopilot_set_mode',         [__CLASS__, 'ajax_set_mode']);
        add_action('wp_ajax_sara_autopilot_get_logs',         [__CLASS__, 'ajax_get_logs']);
        add_action('wp_ajax_sara_autopilot_detect_niche',     [__CLASS__, 'ajax_detect_niche']);
        add_action('wp_ajax_sara_writer_manual_test',         [__CLASS__, 'ajax_writer_manual']);

        // 1.0.0 Manutenção e diagnóstico
        add_action('wp_ajax_sara_autopilot_clear_pending',    [__CLASS__, 'ajax_clear_pending']);
        add_action('wp_ajax_sara_autopilot_clear_logs',       [__CLASS__, 'ajax_clear_logs']);
        add_action('wp_ajax_sara_autopilot_force_replan',     [__CLASS__, 'ajax_force_replan']);
        add_action('wp_ajax_sara_autopilot_diagnose_writer',  [__CLASS__, 'ajax_diagnose_writer']);
        add_action('wp_ajax_sara_autopilot_health',           [__CLASS__, 'ajax_health']);
        add_action('wp_ajax_sara_autopilot_repair',           [__CLASS__, 'ajax_repair']);
        add_action('wp_ajax_sara_falai_test',                 [__CLASS__, 'ajax_falai_test']);

        // 1.0.0 IndexNow e Content Refresher
        add_action('wp_ajax_sara_indexnow_toggle',            [__CLASS__, 'ajax_indexnow_toggle']);
        add_action('wp_ajax_sara_indexnow_flush',             [__CLASS__, 'ajax_indexnow_flush']);
        add_action('wp_ajax_sara_refresher_toggle',           [__CLASS__, 'ajax_refresher_toggle']);
        add_action('wp_ajax_sara_refresher_run_now',          [__CLASS__, 'ajax_refresher_run_now']);
        add_action('wp_ajax_sara_refresher_get_candidates',   [__CLASS__, 'ajax_refresher_get_candidates']);
        add_action('wp_ajax_sara_refresher_refresh_post',     [__CLASS__, 'ajax_refresher_refresh_post']);
        // 1.0.0: GSC já existe via SearchConsoleService (OAuth) — não precisa duplicar

        // 1.0.0: Media Opportunities + Press Release
        add_action('wp_ajax_sara_media_toggle',               [__CLASS__, 'ajax_media_toggle']);
        add_action('wp_ajax_sara_media_collect_now',          [__CLASS__, 'ajax_media_collect_now']);
        add_action('wp_ajax_sara_media_get_opportunities',    [__CLASS__, 'ajax_media_get_opportunities']);
        add_action('wp_ajax_sara_media_generate_article',     [__CLASS__, 'ajax_media_generate_article']);
        add_action('wp_ajax_geo_zernio_accounts',             [__CLASS__, 'ajax_zernio_accounts']);
        add_action('wp_ajax_geo_zernio_share',                [__CLASS__, 'ajax_zernio_share']);
        add_action('wp_ajax_geo_social_posts_generate',       [__CLASS__, 'ajax_social_posts_generate']);
        add_action('wp_ajax_sara_media_generate_pitch',       [__CLASS__, 'ajax_media_generate_pitch']);
        add_action('wp_ajax_sara_media_mark_action',          [__CLASS__, 'ajax_media_mark_action']);
        add_action('wp_ajax_sara_press_release_generate',     [__CLASS__, 'ajax_press_release_generate']);
        add_action('wp_ajax_sara_press_release_list_posts',   [__CLASS__, 'ajax_press_release_list_posts']);

        // Hook: indexar post quando publicado/atualizado
        add_action('save_post', [__CLASS__, 'hook_save_post'], 20, 2);

        // 1.0.0: fila assíncrona de imagens internas para evitar 504.
        add_action('sara_process_internal_images', ['GeoMetodoSEO\\Autopilot\\Writer\\SaraImageQueue', 'process'], 10, 1);
    }

    public static function add_menu(): void {
        // Verificar se o menu pai existe antes de adicionar submenu
        global $menu, $submenu;
        $parent_exists = false;

        // Tentar adicionar mesmo assim — o WP gerencia internamente
        $page = add_submenu_page(
            'geo-metodo-seo',           // Slug do menu pai (mesmo do AdminController)
            'SARA Autopilot v4.0',      // Título da página
            '🤖 Autopilot',             // Label no menu
            'manage_options',
            'sara-autopilot',           // Slug desta página
            [__CLASS__, 'render_dashboard']
        );

        // Se retornou false, o menu pai não existe — tentar como menu top-level de emergência
        if (!$page) {
            add_menu_page(
                'SARA Autopilot',
                '🤖 Autopilot',
                'manage_options',
                'sara-autopilot',
                [__CLASS__, 'render_dashboard'],
                'dashicons-superhero',
                81
            );
        }
    }

    public static function render_dashboard(): void {
        if (class_exists('GeoMetodoSEO\\License\\LicenseManager') && !\GeoMetodoSEO\License\LicenseManager::isActive()) {
            wp_safe_redirect(admin_url('admin.php?page=geo-license&geo_locked=1'));
            exit;
        }
        // Garantir que as classes estão carregadas antes de incluir o dashboard
        if (!class_exists('GeoMetodoSEO\\Autopilot\\Brain\\SaraBrain')) {
            wp_die('SARA Autopilot: classes não carregadas. Verifique a instalação do plugin.');
        }

        $dashboard_file = GEO_METODO_SEO_PATH . 'sara-autopilot/dashboard.php';
        if (!file_exists($dashboard_file)) {
            wp_die('SARA Autopilot: arquivo de dashboard não encontrado em: ' . $dashboard_file);
        }

        include $dashboard_file;
    }

    // ── AJAX ──────────────────────────────────────────────────────────────

    public static function ajax_run_brain(): void {
        self::check_nonce();
        @set_time_limit(120);
        (new SaraBrain())->run();
        update_option('sara_brain_last_run', current_time('mysql'));
        wp_send_json_success(['message' => '✅ Brain executado com sucesso!']);
    }

    public static function ajax_run_writer_next(): void {
        self::check_nonce();
        @set_time_limit(300);

        global $wpdb;
        $cal = $wpdb->prefix . 'sara_editorial_calendar';
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$cal}
             WHERE scheduled_date = %s AND status = 'pending'
             ORDER BY scheduled_time ASC LIMIT 1",
            current_time('Y-m-d')
        ), ARRAY_A);

        if (!$job) {
            wp_send_json_error(['message' => 'Nenhum job pendente para hoje. Execute o Brain primeiro.']);
        }

        (new SaraWriter())->run((int)$job['id']);
        wp_send_json_success(['message' => "✅ Job #{$job['id']} executado!"]);
    }

    public static function ajax_run_job(): void {
        self::check_nonce();
        @set_time_limit(300);

        $id = (int)($_POST['calendar_id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => 'ID inválido']);

        (new SaraWriter())->run($id);
        wp_send_json_success(['message' => "✅ Job #{$id} executado!"]);
    }

    public static function ajax_save_config(): void {
        self::check_nonce();

        $keys = [
            'site_niche', 'site_language', 'brain_run_time',
            'brain_articles_per_category',
            'writer_publish_mode', 'writer_quality_threshold', 'writer_interval_minutes',
            'writer_image_provider', 'brain_enabled', 'writer_enabled',
            'writer_model_generation', 'writer_model_scoring', 'writer_model_briefing', 'writer_model_expansion',
            'writer_provider_generation', 'writer_provider_scoring', 'writer_provider_briefing', 'writer_provider_expansion',
            'youtube_model_metadata', 'youtube_model_article', 'writer_max_job_retries',
            'quality_gate_ai_enabled', 'faq_generator_enabled',
            'refresher_min_age_days', 'refresher_max_per_run', 'auto_repair_stale_jobs', 'auto_expand_enabled',
            'conservative_word_count', 'aggressive_word_count', 'youtube_word_count_target',
            'geo_naga_api_key', 'geo_naga_model', 'geo_naga_text_model', 'autopilot_naga_api_key',
        ];

        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $val = sanitize_text_field($_POST[$key]);
                if ($key === 'writer_interval_minutes') {
                    $val = (string) max(15, min(240, (int) $val));
                }
                AutopilotInstaller::set($key, $val);
                if (in_array($key, ['geo_naga_api_key', 'geo_naga_model', 'geo_naga_text_model'], true)) {
                    update_option($key, $val, false);
                }
            }
        }
        // FIX: antes os nomes estavam trocados — form 'autopilot_naga_api_key' salvava em 'geo_naga_api_key' e vice-versa.
        // Agora: ambos os campos do form salvam nos 2 options simultaneamente pra garantir consistência.
        $naga_key_value = '';
        if (isset($_POST['autopilot_naga_api_key']) && !empty($_POST['autopilot_naga_api_key'])) {
            $naga_key_value = sanitize_text_field($_POST['autopilot_naga_api_key']);
        } elseif (isset($_POST['geo_naga_api_key']) && !empty($_POST['geo_naga_api_key'])) {
            $naga_key_value = sanitize_text_field($_POST['geo_naga_api_key']);
        }
        if ($naga_key_value !== '') {
            update_option('geo_naga_api_key', $naga_key_value, false);
            update_option('autopilot_naga_api_key', $naga_key_value, false);
        }

        // 1.0.0 — Normalizar metas de palavras e evitar valores perigosos/curtos.
        $word_targets = [
            'conservative_word_count'   => [800, 3000, 1500],
            'aggressive_word_count'     => [1200, 4000, 2500],
            'youtube_word_count_target' => [2300, 4000, 2600],
        ];
        foreach ($word_targets as $key => [$min, $max, $default]) {
            if (isset($_POST[$key])) {
                $value = max($min, min($max, (int) $_POST[$key]));
                if ($value <= 0) { $value = $default; }
                AutopilotInstaller::set($key, (string) $value);
            }
        }


        // 1.0.0 BUG FIX: caps subidos para valores realistas.
        // Antes: max=10 chamadas/dia, max=$1/dia, max=50k prompt — impossível subir mesmo no admin.
        // Agora: caps generosos. Default seguro mantido. Premium continua restrito (gpt-5.5 é caro).
        $safety_caps = []; // Controle de créditos removido: sem caps internos de custo.
        foreach ($safety_caps as $key => [$min, $max, $default]) {
            if (isset($_POST[$key])) {
                $raw = is_float($min) || is_float($max) ? (float) $_POST[$key] : (int) $_POST[$key];
                if ($raw <= 0) $raw = $default;
                $raw = max($min, min($max, $raw));
                AutopilotInstaller::set($key, (string) $raw);
            }
        }

        // ── BUG FIX 1.0.0: writer_schedule_times precisa de tratamento especial ──
        // sanitize_text_field() remove aspas duplas e quebra o JSON, fazendo
        // json_decode retornar null → fallback usa só ['07:00'] → todos os
        // posts cairiam no mesmo horário.
        // Aceita 3 formatos de entrada e SEMPRE salva como JSON válido:
        //   1) JSON: ["07:00","09:00","11:00"]
        //   2) Lista simples: 07:00,09:00,11:00
        //   3) Espaços: 07:00 09:00 11:00
        if (isset($_POST['writer_schedule_times'])) {
            $raw = wp_unslash((string) $_POST['writer_schedule_times']);
            $raw = trim($raw);
            $times = [];

            // Tentar JSON primeiro
            if ($raw !== '' && $raw[0] === '[') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $times = $decoded;
                }
            }

            // Se não veio JSON válido, fazer split por vírgula/espaço/ponto-e-vírgula
            if (empty($times)) {
                $parts = preg_split('/[,;\s]+/', $raw) ?: [];
                $times = array_filter(array_map('trim', $parts));
            }

            // Validar formato HH:MM (00:00 a 23:59) e normalizar
            $valid = [];
            foreach ($times as $t) {
                $t = trim((string) $t, "\"' \t\n\r");
                if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $t, $m)) {
                    $valid[] = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
                }
            }

            // Remover duplicatas e ordenar
            $valid = array_values(array_unique($valid));
            sort($valid);

            // Fallback de segurança se nada válido foi enviado
            if (empty($valid)) {
                $valid = ['07:00', '09:00', '11:00'];
            }

            // SEMPRE salvar como JSON válido (sem passar por sanitize_text_field)
            AutopilotInstaller::set('writer_schedule_times', wp_json_encode($valid));
        }

        // Brain v2.0: salvar nicho manual e categorias ativas
        if (isset($_POST['sara_niche'])) {
            update_option('sara_niche', sanitize_text_field($_POST['sara_niche']));
        }
        if (isset($_POST['sara_active_categories']) && is_array($_POST['sara_active_categories'])) {
            $cats = array_map('sanitize_text_field', $_POST['sara_active_categories']);
            $filter = new \GeoMetodoSEO\Autopilot\Brain\SaraCategoryFilter();
            $filter->set_active_categories($cats);
        }
        if (isset($_POST['sara_articles_per_category'])) {
            $n = max(1, min(3, (int)$_POST['sara_articles_per_category']));
            AutopilotInstaller::set('brain_articles_per_category', (string)$n);
        }

        // Re-agendar o Brain com o novo horário
        ScheduleManager::clear();
        ScheduleManager::schedule_brain();

        wp_send_json_success(['message' => '✅ Configurações salvas!']);
    }

    public static function ajax_set_mode(): void {
        self::check_nonce();
        $mode = sanitize_text_field($_POST['mode'] ?? 'conservative');
        if (!in_array($mode, ['conservative', 'aggressive'])) {
            wp_send_json_error(['message' => 'Modo inválido']);
        }
        AutopilotInstaller::set('site_mode', $mode);
        wp_send_json_success(['message' => "Modo alterado para: {$mode}"]);
    }

    public static function ajax_detect_niche(): void {
        self::check_nonce();
        @set_time_limit(60);
        $detector = new \GeoMetodoSEO\Autopilot\Brain\SaraNicheDetector();
        $result   = $detector->force_detect();
        wp_send_json_success([
            'message'    => "Nicho detectado: {$result['niche']} ({$result['confidence']}% confiança)",
            'niche'      => $result['niche'],
            'confidence' => $result['confidence'],
            'top_categories' => $result['top_categories'],
        ]);
    }

    public static function ajax_writer_manual(): void {
        self::check_nonce();
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');
        $geo_ajax_ob_level = ob_get_level();
        ob_start();

        // 1.0.0 — Shutdown handler que captura fatal errors e devolve JSON
        // (resolve "Unexpected token '<'" no JS quando PHP morre antes do wp_send_json)
        register_shutdown_function(function () use ($geo_ajax_ob_level) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                while (ob_get_level() > $geo_ajax_ob_level) {
                    @ob_end_clean();
                }
                if (!headers_sent()) {
                    @http_response_code(500);
                    @header('Content-Type: application/json; charset=utf-8');
                }
                $msg = '⚠️ Erro fatal no Writer: ' . $err['message']
                     . ' em ' . basename($err['file'] ?? '?') . ':' . ($err['line'] ?? '?');
                if (class_exists('\GeoMetodoSEO\Autopilot\Shared\AutopilotLogger')) {
                    \GeoMetodoSEO\Autopilot\Shared\AutopilotLogger::log('writer', 'manual_fatal', 'error', $msg);
                }
                echo wp_json_encode(['success' => false, 'data' => ['message' => $msg]]);
                exit;
            }
        });

        $input = [
            'title'        => sanitize_text_field($_POST['title']        ?? ''),
            'category_id'  => (int)($_POST['category_id']                ?? 0),
            'tone'         => sanitize_text_field($_POST['tone']         ?? 'profissional'),
            'word_count'   => max(800, min(3000, (int)($_POST['word_count'] ?? 1500))),
            'gen_image'    => !empty($_POST['gen_image']),
            'gen_faq'      => !empty($_POST['gen_faq']),
            'auto_publish' => !empty($_POST['auto_publish']),
            'provider'     => sanitize_text_field($_POST['provider'] ?? ''),
            'ai_model'     => sanitize_text_field($_POST['ai_model'] ?? ''),
            'strict_model' => !empty($_POST['strict_model']),
        ];

        try {
            $writer = new \GeoMetodoSEO\Autopilot\Writer\SaraWriterManual();
            $result = $writer->generate($input);
            if (class_exists('GeoMetodoSEO\\TitleBank\\SeoGeoTitleBank')) {
                $pid = (int)($result['post_id'] ?? 0);
                \GeoMetodoSEO\TitleBank\SeoGeoTitleBank::mark_used_by_title((string)$input['title'], 'writer_manual', $pid);
            }
            while (ob_get_level() > $geo_ajax_ob_level) {
                @ob_end_clean();
            }
            AutopilotLogger::log('writer', 'ajax_json_success', 'success', 'Writer manual retornou JSON limpo', ['post_id' => (int)($result['post_id'] ?? 0)]);
            wp_send_json_success($result);
        } catch (\InvalidArgumentException $e) {
            while (ob_get_level() > $geo_ajax_ob_level) {
                @ob_end_clean();
            }
            AutopilotLogger::log('writer', 'ajax_json_error', 'warning', $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            \GeoMetodoSEO\Autopilot\Shared\AutopilotLogger::log(
                'writer', 'manual_error', 'error',
                $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
            );
            while (ob_get_level() > $geo_ajax_ob_level) {
                @ob_end_clean();
            }
            AutopilotLogger::log('writer', 'ajax_json_error', 'error', $e->getMessage());
            wp_send_json_error(['message' => '⚠️ ' . $e->getMessage()]);
        }
    }

    public static function ajax_health(): void {
        self::check_nonce();
        if (!class_exists('\GeoMetodoSEO\Autopilot\Professional\SaraHealthMonitor')) {
            wp_send_json_error(['message' => 'Health Monitor não carregado']);
        }
        wp_send_json_success(\GeoMetodoSEO\Autopilot\Professional\SaraHealthMonitor::snapshot());
    }

    public static function ajax_repair(): void {
        self::check_nonce();
        if (!class_exists('\GeoMetodoSEO\Autopilot\Professional\SaraHealthMonitor')) {
            wp_send_json_error(['message' => 'Health Monitor não carregado']);
        }
        $result = \GeoMetodoSEO\Autopilot\Professional\SaraHealthMonitor::repair();
        wp_send_json_success(array_merge($result, ['message' => '✅ SARA verificada e reparos seguros aplicados.']));
    }

    public static function ajax_get_logs(): void {
        self::check_nonce();
        $logs = AutopilotLogger::recent('', 30);
        $html = '';
        foreach ($logs as $log) {
            $cost = $log['cost_usd'] ? ' <span style="color:#fbbf24;">($' . number_format((float)$log['cost_usd'], 6) . ')</span>' : '';
            $html .= '<div class="log-' . esc_attr($log['status']) . '">'
                . '[' . esc_html(substr($log['created_at'], 5, 14)) . '] '
                . '[' . esc_html(strtoupper($log['agent'])) . '] '
                . esc_html($log['action']) . ': '
                . esc_html(mb_substr($log['message'], 0, 200))
                . $cost
                . '</div>';
        }
        wp_send_json_success(['html' => $html]);
    }

    // ── Hooks ─────────────────────────────────────────────────────────────

    public static function hook_save_post(int $post_id, \WP_Post $post): void {
        if ($post->post_type !== 'post' || $post->post_status !== 'publish') return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        $indexer = new \GeoMetodoSEO\Autopilot\Brain\SaraIndexer();
        $indexer->index_post([
            'ID'           => $post_id,
            'post_title'   => $post->post_title,
            'post_date'    => $post->post_date,
            'post_modified'=> $post->post_modified,
            'post_excerpt' => $post->post_excerpt,
            'post_content' => $post->post_content,
        ]);
    }

    // ── 1.0.0 Manutenção / Diagnóstico ────────────────────────────────────

    /**
     * Apagar todos os jobs pendentes do calendário + cancelar agendamentos cron.
     * Útil quando títulos foram gerados com versão buggy do plugin.
     */
    public static function ajax_clear_pending(): void {
        self::check_nonce();
        global $wpdb;

        $cal_table = $wpdb->prefix . 'sara_editorial_calendar';

        // Pegar todos os jobs pending para cancelar os crons agendados
        $pending = $wpdb->get_results(
            "SELECT id FROM {$cal_table} WHERE status = 'pending'", ARRAY_A
        );

        $cancelled_crons = 0;
        foreach ($pending as $row) {
            $job_id = (int)$row['id'];
            // Cancelar todos os crons agendados para este job
            $timestamp = wp_next_scheduled('sara_autopilot_writer_run', [$job_id]);
            while ($timestamp) {
                wp_unschedule_event($timestamp, 'sara_autopilot_writer_run', [$job_id]);
                $cancelled_crons++;
                $timestamp = wp_next_scheduled('sara_autopilot_writer_run', [$job_id]);
            }
        }

        $deleted = $wpdb->query(
            "DELETE FROM {$cal_table} WHERE status = 'pending'"
        );

        \GeoMetodoSEO\Autopilot\Shared\AutopilotLogger::log(
            'system', 'maint_clear_pending', 'success',
            "Manutenção: {$deleted} jobs pendentes apagados, {$cancelled_crons} crons cancelados"
        );

        wp_send_json_success([
            'deleted'         => (int)$deleted,
            'cancelled_crons' => $cancelled_crons,
            'message'         => "✅ {$deleted} jobs pendentes apagados e {$cancelled_crons} agendamentos cron cancelados.",
        ]);
    }

    /**
     * Limpar todos os logs de execução.
     */
    public static function ajax_clear_logs(): void {
        self::check_nonce();
        global $wpdb;

        $log_table = $wpdb->prefix . 'sara_execution_log';

        // Verificar se a tabela existe
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $log_table));
        if (!$exists) {
            wp_send_json_error(['message' => 'Tabela de logs não encontrada.']);
            return;
        }

        $deleted = $wpdb->query("DELETE FROM {$log_table}");

        wp_send_json_success([
            'deleted' => (int)$deleted,
            'message' => "✅ {$deleted} entradas de log apagadas.",
        ]);
    }

    /**
     * Forçar re-planejamento: apaga pendentes E roda o Brain agora para gerar novos títulos.
     * Combinação de clear_pending + executar Brain.
     */
    public static function ajax_force_replan(): void {
        self::check_nonce();
        global $wpdb;

        // 1. Cancelar crons pendentes
        $cal_table = $wpdb->prefix . 'sara_editorial_calendar';
        $pending = $wpdb->get_results("SELECT id FROM {$cal_table} WHERE status = 'pending'", ARRAY_A);
        $cancelled = 0;
        foreach ($pending as $row) {
            $ts = wp_next_scheduled('sara_autopilot_writer_run', [(int)$row['id']]);
            while ($ts) {
                wp_unschedule_event($ts, 'sara_autopilot_writer_run', [(int)$row['id']]);
                $cancelled++;
                $ts = wp_next_scheduled('sara_autopilot_writer_run', [(int)$row['id']]);
            }
        }

        // 2. Apagar pendentes
        $deleted = $wpdb->query("DELETE FROM {$cal_table} WHERE status = 'pending'");

        // 3. Executar o Brain para gerar plano novo
        try {
            $brain   = new \GeoMetodoSEO\Autopilot\Brain\SaraBrain();
            $result  = $brain->run();
            $planned = is_array($result) ? ($result['planned'] ?? 0) : 0;

            wp_send_json_success([
                'deleted'         => (int)$deleted,
                'cancelled_crons' => $cancelled,
                'planned'         => $planned,
                'message'         => "✅ Apagados {$deleted} jobs antigos, cancelados {$cancelled} crons. " .
                                     "Brain gerou {$planned} novos artigos.",
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => 'Erro ao executar Brain: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Diagnosticar o Writer automático: verificar crons, fila pendente, estado do hook.
     */
    public static function ajax_diagnose_writer(): void {
        self::check_nonce();
        global $wpdb;

        $cal_table = $wpdb->prefix . 'sara_editorial_calendar';
        $now       = current_time('mysql');
        $today     = \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::today_date();

        // Contar jobs por status
        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as n FROM {$cal_table} GROUP BY status",
            ARRAY_A
        );
        $by_status = [];
        foreach ($counts as $r) $by_status[$r['status']] = (int)$r['n'];

        // Pegar pendentes e ver quais já passaram do horário
        $pending = $wpdb->get_results($wpdb->prepare(
            "SELECT id, scheduled_date, scheduled_time, title, category_name
             FROM {$cal_table}
             WHERE status = 'pending' AND scheduled_date <= %s
             ORDER BY scheduled_date ASC, scheduled_time ASC LIMIT 30",
            $today
        ), ARRAY_A);

        $missed     = [];
        $upcoming   = [];
        $no_cron    = [];
        $current_ts = \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::now_timestamp();

        foreach ($pending as $job) {
            $job_ts = \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::local_timestamp($job['scheduled_date'], $job['scheduled_time']);
            $cron_ts = wp_next_scheduled('sara_autopilot_writer_run', [(int)$job['id']]);

            $entry = [
                'id'         => (int)$job['id'],
                'title'      => mb_substr($job['title'], 0, 60),
                'category'   => $job['category_name'],
                'scheduled'  => $job['scheduled_date'] . ' ' . $job['scheduled_time'],
                'cron_at'    => $cron_ts ? (new \DateTimeImmutable('@' . $cron_ts))->setTimezone(\GeoMetodoSEO\Autopilot\Shared\ScheduleManager::timezone())->format('Y-m-d H:i:s') : null,
            ];

            if ($job_ts < $current_ts) {
                $missed[] = $entry;
            } else {
                $upcoming[] = $entry;
            }

            if (!$cron_ts) $no_cron[] = $entry;
        }

        // Verificar se DISABLE_WP_CRON está definido
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        // Verificar último log do writer
        $log_table = $wpdb->prefix . 'sara_execution_log';
        $last_writer_log = $wpdb->get_row(
            "SELECT created_at, action, message FROM {$log_table}
             WHERE agent = 'writer' ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );

        wp_send_json_success([
            'now'                 => $now,
            'by_status'           => $by_status,
            'missed_count'        => count($missed),
            'missed_jobs'         => array_slice($missed, 0, 10),
            'upcoming_count'      => count($upcoming),
            'upcoming_jobs'       => array_slice($upcoming, 0, 10),
            'no_cron_count'       => count($no_cron),
            'jobs_without_cron'   => array_slice($no_cron, 0, 10),
            'wp_cron_disabled'    => $cron_disabled,
            'last_writer_log'     => $last_writer_log,
            'writer_enabled'      => \GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller::get('writer_enabled', '1') === '1',
        ]);
    }

    // ── /Manutenção ───────────────────────────────────────────────────────

    /**
     * Testar conexao com Fal.ai.
     */
    public static function ajax_falai_test(): void {
        self::check_nonce();
        @set_time_limit(120);
        @ini_set('memory_limit', '256M');

        $fal = new \GeoMetodoSEO\Services\FalAIImageService();
        $result = $fal->test();
        $message = $result['message'] ?? 'Fal.ai falhou';
        if (is_array($message) || is_object($message)) {
            $message = wp_json_encode($message, JSON_UNESCAPED_UNICODE) ?: 'Fal.ai retornou erro em formato inesperado.';
        }
        $message = sanitize_text_field((string) $message);
        if (!empty($result['ok'])) {
            wp_send_json_success(['message' => $message ?: 'Fal.ai OK']);
        }
        wp_send_json_error(['message' => $message ?: 'Fal.ai falhou']);
    }

    // ── 1.0.0 IndexNow e Content Refresher ────────────────────────────────

    public static function ajax_indexnow_toggle(): void {
        self::check_nonce();
        $enable = isset($_POST['enable']) && $_POST['enable'] === '1';
        \GeoMetodoSEO\Autopilot\Indexing\SaraIndexNow::set_enabled($enable);

        $status = \GeoMetodoSEO\Autopilot\Indexing\SaraIndexNow::get_status();
        wp_send_json_success([
            'enabled' => $status['enabled'],
            'key_url' => $status['key_url'],
            'message' => $enable
                ? "✅ IndexNow ativado. Verifique se {$status['key_url']} responde com a chave."
                : '⏸️ IndexNow desativado.',
        ]);
    }

    public static function ajax_indexnow_flush(): void {
        self::check_nonce();
        // Botão manual "Enviar Agora" deve varrer conteúdos publicados, não apenas o buffer antigo.
        // Inclui posts, páginas, Web Stories e Glossário SEO via SaraIndexNow::enqueue_published_content().
        $status_before = \GeoMetodoSEO\Autopilot\Indexing\SaraIndexNow::get_status();
        if (!empty($status_before['enabled'])) {
            \GeoMetodoSEO\Autopilot\Indexing\SaraIndexNow::enqueue_published_content(500);
        }
        $status_before = \GeoMetodoSEO\Autopilot\Indexing\SaraIndexNow::get_status();
        $count = (int)$status_before['buffer_size'];

        if (!$status_before['enabled']) {
            wp_send_json_error(['message' => 'IndexNow está desativado. Ative primeiro.']);
            return;
        }
        if ($count === 0) {
            wp_send_json_success(['message' => 'Buffer vazio — nada para enviar.', 'sent' => 0]);
            return;
        }

        $ok = \GeoMetodoSEO\Autopilot\Indexing\SaraIndexNow::flush_buffer();
        wp_send_json_success([
            'sent'    => $count,
            'success' => $ok,
            'message' => $ok
                ? "✅ {$count} URLs enviadas ao IndexNow."
                : "⚠️ Falha no envio de {$count} URLs (re-enfileiradas para retry).",
        ]);
    }

    public static function ajax_refresher_toggle(): void {
        self::check_nonce();
        $enable = isset($_POST['enable']) && $_POST['enable'] === '1';
        \GeoMetodoSEO\Autopilot\Learning\SaraContentRefresher::set_enabled($enable);

        $status = \GeoMetodoSEO\Autopilot\Learning\SaraContentRefresher::get_status();
        wp_send_json_success([
            'enabled'    => $status['enabled'],
            'next_run'   => $status['next_run'],
            'candidates' => $status['candidates'],
            'message'    => $enable
                ? "✅ Refresher ativado. Próxima execução automática: {$status['next_run']}."
                : '⏸️ Refresher desativado.',
        ]);
    }

    public static function ajax_refresher_run_now(): void {
        self::check_nonce();

        if (!\GeoMetodoSEO\Autopilot\Learning\SaraContentRefresher::is_enabled()) {
            wp_send_json_error(['message' => 'Refresher está desativado. Ative primeiro.']);
            return;
        }

        try {
            $stats = \GeoMetodoSEO\Autopilot\Learning\SaraContentRefresher::run();

            $msg = "✅ Refresher executado. " .
                   "Candidatos: {$stats['candidates_found']} | " .
                   "Atualizados: {$stats['refreshed']} | " .
                   "Falhas: {$stats['failed']} | " .
                   "Tempo: {$stats['duration_ms']}ms";

            wp_send_json_success(array_merge($stats, ['message' => $msg]));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Erro: ' . $e->getMessage()]);
        }
    }


    public static function ajax_refresher_get_candidates(): void {
        self::check_nonce();

        try {
            $items = \GeoMetodoSEO\Autopilot\Learning\SaraContentRefresher::get_candidate_preview(20);
            $html = '';
            if (empty($items)) {
                $html = '<div style="padding:10px;color:#64748b;">Nenhum artigo antigo elegível agora. Ajuste a idade mínima ou aguarde novos candidatos.</div>';
            } else {
                $html .= '<div style="overflow:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;">';
                $html .= '<thead><tr style="background:#f1f5f9;color:#0f172a;">'
                       . '<th style="text-align:left;padding:8px;border:1px solid #e2e8f0;">Artigo</th>'
                       . '<th style="text-align:left;padding:8px;border:1px solid #e2e8f0;">Idade</th>'
                       . '<th style="text-align:left;padding:8px;border:1px solid #e2e8f0;">Imagens</th>'
                       . '<th style="text-align:left;padding:8px;border:1px solid #e2e8f0;">Última mod.</th>'
                       . '<th style="text-align:left;padding:8px;border:1px solid #e2e8f0;">Ação</th>'
                       . '</tr></thead><tbody>';
                foreach ($items as $it) {
                    $img = $it['has_featured_image'] ? '✅ destacada' : '⚠️ sem destacada';
                    $img .= ' / internas: ' . (int)$it['content_images'];
                    $html .= '<tr>'
                           . '<td style="padding:8px;border:1px solid #e2e8f0;"><strong>' . esc_html($it['title']) . '</strong><br><a href="' . esc_url($it['edit_link']) . '" target="_blank">Editar</a> · <a href="' . esc_url($it['permalink']) . '" target="_blank">Ver</a></td>'
                           . '<td style="padding:8px;border:1px solid #e2e8f0;">' . (int)$it['age_days'] . ' dias</td>'
                           . '<td style="padding:8px;border:1px solid #e2e8f0;">' . esc_html($img) . '</td>'
                           . '<td style="padding:8px;border:1px solid #e2e8f0;">' . esc_html($it['modified']) . '</td>'
                           . '<td style="padding:8px;border:1px solid #e2e8f0;"><button class="btn-secondary" style="font-size:11px;background:#16a34a;color:#fff;border:none;" onclick="refresherRefreshPost(this,' . (int)$it['id'] . ')">Reescrever este</button></td>'
                           . '</tr>';
                }
                $html .= '</tbody></table></div>';
                $html .= '<div style="margin-top:8px;color:#475569;font-size:12px;">A reescrita mantém a imagem destacada original, não altera slug e preserva imagens internas já existentes.</div>';
            }

            wp_send_json_success(['items' => $items, 'html' => $html]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Erro ao listar candidatos: ' . $e->getMessage()]);
        }
    }

    public static function ajax_refresher_refresh_post(): void {
        self::check_nonce();
        @set_time_limit(300);
        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => 'Post inválido.']);
            return;
        }

        $post = get_post($post_id);
        $allowed_types = ['post', 'geo_glossary'];
        if (!$post || !in_array($post->post_type, $allowed_types, true) || $post->post_status !== 'publish') {
            wp_send_json_error(['message' => 'Conteúdo não encontrado, tipo não suportado ou não publicado.']);
            return;
        }

        $featured_before = get_post_thumbnail_id($post_id);
        $images_before   = substr_count((string)$post->post_content, '<img');

        try {
            $ok = \GeoMetodoSEO\Autopilot\Learning\SaraContentRefresher::refresh_post($post_id);
            if (!$ok) {
                wp_send_json_error(['message' => 'A IA não retornou uma atualização útil para este artigo. Nada foi alterado.']);
                return;
            }

            $after = get_post($post_id);
            $featured_after = get_post_thumbnail_id($post_id);
            $images_after   = substr_count((string)$after->post_content, '<img');
            $preserved = ((string)$featured_before === (string)$featured_after);

            wp_send_json_success([
                'message' => '✅ Artigo reescrito/atualizado com segurança. Imagem destacada preservada: ' . ($preserved ? 'sim' : 'verificar') . '. Imagens internas antes/depois: ' . $images_before . '/' . $images_after . '.',
                'featured_preserved' => $preserved,
                'images_before' => $images_before,
                'images_after' => $images_after,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Erro ao reescrever: ' . $e->getMessage()]);
        }
    }

    // ── /IndexNow e Refresher ─────────────────────────────────────────────

    // ── 1.0.0 Media Opportunities + Press Release ─────────────────────────

    public static function ajax_media_toggle(): void {
        self::check_nonce();
        $enable = !empty($_POST['enable']) && $_POST['enable'] !== '0';
        update_option('sara_media_enabled', $enable ? '1' : '0', false);

        // Salvar idioma de filtro se enviado
        if (!empty($_POST['lang'])) {
            $lang = in_array($_POST['lang'], ['both', 'en', 'pt'], true) ? $_POST['lang'] : 'both';
            update_option('sara_media_lang_filter', $lang, false);
        }

        wp_send_json_success([
            'enabled' => $enable,
            'message' => $enable
                ? '✅ Media Opportunities ativado. Coleta a cada 6 horas.'
                : '⏸️ Media Opportunities desativado.',
        ]);
    }

    public static function ajax_media_collect_now(): void {
        self::check_nonce();
        @set_time_limit(120);
        if (isset($_POST['lang'])) {
            $lang = in_array($_POST['lang'], ['both', 'en', 'pt'], true) ? sanitize_key($_POST['lang']) : 'both';
            update_option('sara_media_lang_filter', $lang, false);
        }

        try {
            $result = \GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaCollector::collect_all();

            // Após coletar, processar matching
            $match_result = \GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaMatcher::match_pending();

            wp_send_json_success([
                'collected'  => $result['collected'],
                'errors'     => $result['errors'],
                'matched'    => $match_result['matched'] ?? 0,
                'rejected'   => $match_result['rejected'] ?? 0,
                'message'    => "✅ Coletadas {$result['collected']} oportunidades novas. " .
                               "Relevantes: " . ($match_result['matched'] ?? 0) . ", " .
                               "Descartadas: " . ($match_result['rejected'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Erro: ' . $e->getMessage()]);
        }
    }

    public static function ajax_media_get_opportunities(): void {
        self::check_nonce();
        $limit = min(50, max(5, (int)($_POST['limit'] ?? 20)));
        $lang = isset($_POST['lang']) && in_array($_POST['lang'], ['both','en','pt'], true) ? sanitize_key($_POST['lang']) : get_option('sara_media_lang_filter', 'both');
        update_option('sara_media_lang_filter', $lang, false);

        $items = \GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaMatcher::get_matched($limit, $lang);
        $stats = \GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaMatcher::get_stats();

        wp_send_json_success([
            'items' => $items,
            'stats' => $stats,
        ]);
    }

    public static function ajax_media_generate_pitch(): void {
        self::check_nonce();
        @set_time_limit(60);

        $opp_id = (int)($_POST['id'] ?? 0);
        if ($opp_id <= 0) {
            wp_send_json_error(['message' => 'ID inválido']);
            return;
        }

        $result = \GeoMetodoSEO\Autopilot\MediaOpportunities\SaraPitchGenerator::generate($opp_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => $result['message'] ?? 'Falha ao gerar pitch']);
        }
    }

    public static function ajax_media_mark_action(): void {
        self::check_nonce();

        $opp_id = (int)($_POST['id'] ?? 0);
        $action = sanitize_text_field((string)($_POST['user_action'] ?? ''));

        if ($opp_id <= 0 || empty($action)) {
            wp_send_json_error(['message' => 'Parâmetros inválidos']);
            return;
        }

        $ok = \GeoMetodoSEO\Autopilot\MediaOpportunities\SaraPitchGenerator::mark_action($opp_id, $action);
        if ($ok) {
            wp_send_json_success(['message' => 'Ação registrada']);
        } else {
            wp_send_json_error(['message' => 'Ação inválida']);
        }
    }

    /** Gera posts sociais separados por rede a partir de um artigo. */
    public static function ajax_social_posts_generate(): void {
        self::check_nonce();
        @set_time_limit(120);

        $post_id  = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) {
            wp_send_json_error(['message' => 'Selecione um post.']);
            return;
        }
        $networks = [];
        if (!empty($_POST['networks'])) {
            $decoded = json_decode(stripslashes((string)$_POST['networks']), true);
            if (is_array($decoded)) {
                $networks = array_map('sanitize_key', $decoded);
            }
        }

        if (!\GeoMetodoSEO\License\LicenseManager::canGenerate()) {
            wp_send_json_error(['message' => 'Limite do plano atingido.']);
            return;
        }

        try {
            $result = \GeoMetodoSEO\Services\SocialPostsGenerator::generate($post_id, $networks);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Erro: ' . $e->getMessage()]);
            return;
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => $result['message'] ?? 'Falha ao gerar posts']);
        }
    }

    /** Lista contas sociais conectadas na Zernio. */
    public static function ajax_zernio_accounts(): void {
        self::check_nonce();
        $result = \GeoMetodoSEO\Services\ZernioSocialService::get_accounts();
        if ($result['success']) {
            wp_send_json_success(['accounts' => $result['accounts'] ?? []]);
        } else {
            wp_send_json_error(['message' => $result['message'] ?? 'Falha']);
        }
    }

    /** Compartilha um post nas redes sociais via Zernio. */
    public static function ajax_zernio_share(): void {
        self::check_nonce();
        @set_time_limit(60);

        $post_id   = (int)($_POST['post_id'] ?? 0);
        $platforms = json_decode(stripslashes((string)($_POST['platforms'] ?? '[]')), true);
        $text      = sanitize_textarea_field((string)($_POST['text'] ?? ''));
        $scheduled = sanitize_text_field((string)($_POST['scheduled_at'] ?? ''));

        if ($post_id <= 0) {
            wp_send_json_error(['message' => 'Post inválido']);
            return;
        }
        if (!is_array($platforms) || empty($platforms)) {
            wp_send_json_error(['message' => 'Selecione ao menos uma conta para compartilhar']);
            return;
        }

        // Sanitizar as plataformas
        $clean = [];
        foreach ($platforms as $p) {
            if (!empty($p['platform']) && !empty($p['accountId'])) {
                $clean[] = [
                    'platform'  => sanitize_text_field($p['platform']),
                    'accountId' => sanitize_text_field($p['accountId']),
                ];
            }
        }

        $result = \GeoMetodoSEO\Services\ZernioSocialService::share_post($post_id, $clean, $text, $scheduled);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => $result['message'] ?? 'Falha ao compartilhar']);
        }
    }

    /**
     * Gera um artigo completo a partir de uma oportunidade de mídia coletada.
     * Usa o título/conteúdo da oportunidade como tema e passa pelo pipeline normal
     * (mesma qualidade dos outros artigos: FAQ, autor, imagens, schema, etc).
     */
    public static function ajax_media_generate_article(): void {
        self::check_nonce();
        @set_time_limit(300);

        global $wpdb;
        $opp_id = (int)($_POST['id'] ?? 0);
        if ($opp_id <= 0) {
            wp_send_json_error(['message' => 'ID da oportunidade inválido']);
            return;
        }

        $table = $wpdb->prefix . 'sara_media_opportunities';
        $opp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $opp_id), ARRAY_A);
        if (!$opp) {
            wp_send_json_error(['message' => 'Oportunidade não encontrada']);
            return;
        }

        // O tema do artigo é o título da oportunidade. O conteúdo vira contexto extra.
        $keyword = sanitize_text_field((string)$opp['title']);
        $extra_context = wp_strip_all_tags((string)($opp['content'] ?? ''));

        if (mb_strlen($keyword) < 5) {
            wp_send_json_error(['message' => 'Título da oportunidade muito curto para gerar artigo']);
            return;
        }

        // Idioma: usa o que o usuário enviou, senão o idioma PADRÃO do plugin.
        // Assim, uma oportunidade em inglês pode virar artigo em português
        // (ou no idioma que você configurou), não fica preso ao inglês.
        $language = sanitize_text_field((string)($_POST['language'] ?? ''));
        if ($language === '') {
            $language = get_option('geo_default_language', 'pt-BR');
        }

        // Provider/modelo do gerador individual (respeita a config do usuário)
        $provider = \GeoMetodoSEO\AI\ProviderResolver::for('individual_generation');
        $model    = \GeoMetodoSEO\AI\ProviderResolver::modelFor('individual_generation', $provider);

        if (!\GeoMetodoSEO\License\LicenseManager::canGenerate()) {
            wp_send_json_error(['message' => 'Limite do plano atingido. Faça upgrade para gerar mais artigos.']);
            return;
        }

        $pipeline = new \GeoMetodoSEO\Services\ArticlePipeline($provider, $model ?: null);
        try {
            $post_id = $pipeline->process(
                $keyword,
                $language,
                'draft',          // sempre rascunho — o usuário revisa antes de publicar
                '',
                'jornalístico',   // tom adequado para oportunidades de mídia
                'auto',
                'medium'          // medium (2500 palavras) — mais rápido, evita timeout de 120s
            );
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Erro ao gerar artigo: ' . $e->getMessage()]);
            return;
        }

        if (!$post_id || is_wp_error($post_id)) {
            $msg = is_wp_error($post_id) ? $post_id->get_error_message() : 'Falha ao gerar artigo';
            // Mensagem amigável para o erro mais comum (timeout)
            if (stripos($msg, 'timed out') !== false || stripos($msg, 'cURL error 28') !== false) {
                $msg = 'A geração demorou demais e o servidor cortou (timeout). '
                     . 'Tente novamente — geralmente funciona na 2ª tentativa. '
                     . 'Se persistir, aumente o max_execution_time do servidor para 180s.';
            }
            wp_send_json_error(['message' => $msg]);
            return;
        }

        // Marcar a oportunidade como aproveitada e vincular ao post
        $wpdb->update($table,
            ['user_action' => 'article_generated', 'status' => 'used'],
            ['id' => $opp_id], ['%s', '%s'], ['%d']
        );
        update_post_meta($post_id, '_sara_media_opportunity_id', $opp_id);

        wp_send_json_success([
            'message'   => 'Artigo gerado como rascunho com sucesso!',
            'post_id'   => $post_id,
            'edit_link' => get_edit_post_link($post_id, 'raw'),
            'title'     => get_the_title($post_id),
        ]);
    }

    public static function ajax_press_release_generate(): void {
        self::check_nonce();
        @set_time_limit(120);

        $post_id  = (int)($_POST['post_id'] ?? 0);
        $template = sanitize_text_field((string)($_POST['template'] ?? ''));
        $extra    = [];

        if (!empty($_POST['key_facts']))     $extra['key_facts']     = wp_unslash((string)$_POST['key_facts']);
        if (!empty($_POST['contact_email'])) $extra['contact_email'] = (string)$_POST['contact_email'];
        if (!empty($_POST['spokesperson']))  $extra['spokesperson']  = (string)$_POST['spokesperson'];

        if ($post_id <= 0 || empty($template)) {
            wp_send_json_error(['message' => 'Selecione um post e um template']);
            return;
        }

        $result = \GeoMetodoSEO\Autopilot\MediaOpportunities\SaraPressRelease::generate($post_id, $template, $extra);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => $result['message'] ?? 'Falha']);
        }
    }

    public static function ajax_press_release_list_posts(): void {
        self::check_nonce();

        // Listar posts publicados ordenados por data (para o select do PR generator)
        $posts = get_posts([
            'numberposts' => 50,
            'post_status' => 'publish',
            'post_type'   => 'post',
            'orderby'     => 'date',
            'order'       => 'DESC',
            'fields'      => 'ids',
        ]);

        $list = [];
        foreach ($posts as $pid) {
            $list[] = [
                'id'    => (int)$pid,
                'title' => get_the_title($pid),
                'date'  => get_the_date('d/m/Y', $pid),
            ];
        }

        wp_send_json_success(['posts' => $list]);
    }

    // ── /Media Opportunities ──────────────────────────────────────────────

    private static function check_nonce(): void {
        check_ajax_referer('sara_autopilot_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão']);
    }
}
