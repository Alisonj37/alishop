<?php
namespace GeoMetodoSEO\Autopilot\Shared;

if (!defined('ABSPATH')) { exit; }

/**
 * AutopilotInstaller — Cria/atualiza as 4 tabelas do SARA Autopilot.
 * Chamado em plugin activation e em admin_init quando versão muda.
 */
class AutopilotInstaller {

    const DB_VERSION = '4.3.0';
    const DB_VERSION_OPTION = 'sara_autopilot_db_version';

    public static function run(): void {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) return;
        self::create_tables();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── 1. Índice Semântico (Agente A lê, Agente B escreve) ───────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sara_semantic_index (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id       BIGINT UNSIGNED NOT NULL,
            keyword       VARCHAR(255)    NOT NULL DEFAULT '',
            title         TEXT            NOT NULL,
            url           VARCHAR(512)    NOT NULL DEFAULT '',
            category_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            word_count    SMALLINT        NOT NULL DEFAULT 0,
            seo_score     TINYINT         NOT NULL DEFAULT 0,
            geo_score     TINYINT         NOT NULL DEFAULT 0,
            eeat_score    TINYINT         NOT NULL DEFAULT 0,
            entities      JSON,
            lsi_keywords  JSON,
            internal_links JSON,
            search_intent VARCHAR(32)     NOT NULL DEFAULT 'informacional',
            content_type  VARCHAR(32)     NOT NULL DEFAULT 'cluster',
            decay_score   TINYINT         NOT NULL DEFAULT 0,
            indexed_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY   (id),
            UNIQUE KEY    uk_post (post_id),
            KEY           idx_keyword (keyword(100)),
            KEY           idx_category (category_id),
            KEY           idx_indexed (indexed_at)
        ) $charset;");

        // ── 2. Calendário Editorial (Agente A planeja, Agente B executa) ──
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sara_editorial_calendar (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scheduled_date  DATE            NOT NULL,
            scheduled_time  TIME            NOT NULL DEFAULT '07:00:00',
            category_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            category_name   VARCHAR(128)    NOT NULL DEFAULT '',
            title           TEXT            NOT NULL,
            keyword         VARCHAR(255)    NOT NULL DEFAULT '',
            search_intent   VARCHAR(32)     NOT NULL DEFAULT 'informacional',
            content_type    VARCHAR(32)     NOT NULL DEFAULT 'cluster',
            word_count_target SMALLINT      NOT NULL DEFAULT 1500,
            outline         JSON,
            entities        JSON,
            secondary_keywords JSON,
            lsi_keywords    JSON,
            external_sources JSON,
            internal_links  JSON,
            eeat_angle      VARCHAR(64)     NOT NULL DEFAULT 'experiencia_pratica',
            schema_type     VARCHAR(32)     NOT NULL DEFAULT 'Article',
            priority        TINYINT         NOT NULL DEFAULT 5,
            status          ENUM('pending','processing','retrying','done','failed','skipped') NOT NULL DEFAULT 'pending',
            post_id         BIGINT UNSIGNED DEFAULT NULL,
            quality_score   TINYINT         DEFAULT NULL,
            failure_reason  TEXT,
            retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            locked_at       DATETIME        DEFAULT NULL,
            last_error      TEXT,
            planned_by      VARCHAR(64)     NOT NULL DEFAULT 'sara_brain',
            executed_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY     (id),
            KEY             idx_schedule (scheduled_date, status),
            KEY             idx_category (category_id),
            KEY             idx_status (status),
            KEY             idx_locked (locked_at),
            KEY             idx_retry (retry_count)
        ) $charset;");

        // ── 3. Configuração do Autopilot (por site) ─────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sara_autopilot_config (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            config_key    VARCHAR(128)    NOT NULL,
            config_value  LONGTEXT        NOT NULL,
            config_group  VARCHAR(64)     NOT NULL DEFAULT 'general',
            description   TEXT,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY   (id),
            UNIQUE KEY    uk_key (config_key),
            KEY           idx_group (config_group)
        ) $charset;");

        // ── 4. Log de Execução (auditoria completa) ──────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sara_execution_log (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agent         ENUM('brain','writer','system') NOT NULL DEFAULT 'system',
            action        VARCHAR(128)    NOT NULL,
            status        ENUM('start','success','error','warning','skip') NOT NULL DEFAULT 'start',
            message       TEXT,
            context       JSON,
            calendar_id   BIGINT UNSIGNED DEFAULT NULL,
            post_id       BIGINT UNSIGNED DEFAULT NULL,
            duration_ms   INT UNSIGNED    DEFAULT NULL,
            tokens_used   INT UNSIGNED    DEFAULT NULL,
            cost_usd      DECIMAL(10,6)   DEFAULT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY   (id),
            KEY           idx_agent (agent),
            KEY           idx_action (action),
            KEY           idx_calendar (calendar_id),
            KEY           idx_created (created_at)
        ) $charset;");

        // Inserir configurações padrão
        self::seed_default_config();
        self::upgrade_existing_config();
    }

    private static function seed_default_config(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sara_autopilot_config';

        $defaults = [
            // Modo do site
            ['site_mode',          'conservative', 'general',    'conservative = site novo | aggressive = site authority'],
            ['site_da',            '0',            'general',    'Domain Authority estimado (0-100)'],
            ['site_niche',         '',             'general',    'Nicho principal do site (ex: marketing digital)'],
            ['site_language',      'pt-BR',        'general',    'Idioma principal do conteúdo'],

            // Agente A — Brain
            ['brain_enabled',              '1',      'brain',  'Ativa o Agente A (planejador)'],
            ['brain_run_time',             '22:00',  'brain',  'Horário de execução do Brain (HH:MM)'],
            ['brain_categories',           '[]',     'brain',  'JSON array de category_ids para planejar'],
            ['brain_articles_per_category','1',      'brain',  'Artigos por categoria por dia'],
            ['brain_trend_sources',        '["google_trends"]', 'brain', 'Fontes de tendência'],
            ['brain_competitive_urls',     '[]',     'brain',  'URLs dos concorrentes para monitorar'],
            ['brain_decay_threshold',      '120',    'brain',  'Dias sem atualização = decay detectado'],

            // Agente B — Writer
            ['writer_enabled',             '1',      'writer', 'Ativa o Agente B (gerador)'],
            ['writer_publish_mode',        'draft',  'writer', 'draft | publish'],
            ['writer_schedule_times',      '["07:00","09:00","11:00"]', 'writer', 'Horários base de publicação'],
            ['writer_interval_minutes',    '60',     'writer', 'Intervalo entre artigos agendados em minutos'],
            ['writer_model_generation',    '',  'writer', 'Modelo principal para geração de conteúdo long-form (vazio = padrão do provider)'],
            ['writer_provider_generation', '',         'writer', 'Provider principal para geração de conteúdo long-form (vazio = provider global)'],
            ['writer_provider_briefing',   '',         'writer', 'Provider principal para briefing factual (vazio = provider global)'],
            ['writer_provider_expansion',  '',         'writer', 'Provider principal para auto-expansão/condensação (vazio = provider global)'],
            ['writer_provider_scoring',    '',         'writer', 'Provider principal para revisão/score (vazio = provider global)'],
            // 1.0.0 FIX 4: Defaults recalibrados para uso real (12 artigos/dia)







            // 1.0.0 FIX 1: Tolerância de 5% antes de disparar expand/condense
            ['auto_expand_tolerance_pct',  '5',        'writer', 'Tolerância (%) antes de disparar auto-expand/condense'],
            // 1.0.0 FIX 2: Skip briefing IA quando plano já tem outline+entities+keywords
            ['briefing_skip_when_plan_complete', '1',  'writer', 'Pula briefing IA quando o plano vem completo'],
            ['writer_model_scoring',       '', 'writer', 'Modelo para scoring/revisão/quality gate (vazio = padrão do provider)'],
            ['writer_model_briefing',      '', 'writer', 'Modelo para briefing factual antes da escrita (vazio = padrão do provider)'],
            ['writer_model_expansion',     '', 'writer', 'Modelo para auto-expansão quando o artigo fica curto (vazio = padrão do provider)'],
            ['youtube_model_metadata',     '', 'writer', 'Modelo para metadados do YouTube → Artigo (vazio = padrão do provider)'],
            ['youtube_model_article',      '', 'writer', 'Modelo para artigo longo do YouTube → Artigo (vazio = padrão do provider)'],
            ['youtube_word_count_target',  '2600',   'article', 'Meta de palavras para YouTube → Artigo'],
            // 1.0.0: auto_expand ligado por default em '1' — garante qualidade quando artigo gera curto.
            // Custa ~$0.005 extra de IA por expansão, mas garante artigo com palavras suficientes
            // pra cumprir target_words. Sem isso, artigo de alvo 3000 podia sair com 1500 palavras
            // ('raso' pro Google March 2026 Update). Quem quer economizar pode setar '0' manualmente.
            ['auto_expand_enabled',        '1',      'writer', 'Expande automaticamente quando ficar abaixo da meta'],
            ['writer_quality_threshold',   '70',     'writer', 'Score mínimo para publicar (0-100)'],
            ['writer_max_retries',         '0',      'writer', 'Tentativas de regeneração no quality gate'],
            ['writer_max_job_retries',     '1',      'writer', 'Retries automáticos de jobs com backoff'],
            ['writer_image_provider',      'ai_auto', 'writer', 'ai_auto | replicate | falai | naga | huggingface | unsplash | pexels | pixabay'],
            // 1.0.0: Controles de economia de IA
            ['quality_gate_ai_enabled',    '0',      'writer', 'Quality Gate via IA (mais caro). 0 = só local (econômico, default)'],
            ['faq_generator_enabled',      '0',      'writer', 'FAQ via IA. 0 = desligado por padrão para proteger créditos; usa FAQ local/template quando disponível'],
            ['professional_health_enabled','1',      'professional', 'Ativa monitor de saúde da SARA'],
            ['refresher_min_age_days',     '120',    'professional', 'Idade mínima do artigo para refresh automático'],
            ['refresher_max_per_run',      '3',      'professional', 'Máximo de artigos atualizados por execução'],
            ['auto_repair_stale_jobs',     '1',      'professional', 'Libera jobs travados automaticamente'],

            // Artigo padrão por modo
            ['conservative_word_count',    '1500',   'article', 'Palavras para modo conservador'],
            ['conservative_images',        '2',      'article', 'Imagens para modo conservador'],
            ['conservative_schema',        'basic',  'article', 'Schema para modo conservador'],
            ['aggressive_word_count',      '2500',   'article', 'Palavras para modo agressivo'],
            ['aggressive_images',          '4',      'article', 'Imagens para modo agressivo'],
            ['aggressive_schema',          'full',   'article', 'Schema para modo agressivo'],
        ];

        foreach ($defaults as [$key, $value, $group, $desc]) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (config_key, config_value, config_group, description)
                 VALUES (%s, %s, %s, %s)",
                $key, $value, $group, $desc
            ));
        }
    }


    /**
     * 1.0.0 — Atualiza configurações antigas sem sobrescrever ajustes personalizados fortes.
     */
    private static function upgrade_existing_config(): void {
        $current_conservative = (int) self::get('conservative_word_count', 0);
        if ($current_conservative > 0 && $current_conservative < 3000) {
            self::set('conservative_word_count', '1500');
        }

        $current_aggressive = (int) self::get('aggressive_word_count', 0);
        if ($current_aggressive > 0 && $current_aggressive < 4000) {
            self::set('aggressive_word_count', '2500');
        }

        $current_interval = (int) self::get('writer_interval_minutes', 0);
        if ($current_interval <= 0) {
            self::set('writer_interval_minutes', '60');
        }

        // 1.0.0 — modelos vazios significam: usar o modelo padrão do provedor selecionado.

        // 1.0.0 FIX 1 + FIX 2: novos toggles
        if ((string) self::get('auto_expand_tolerance_pct', '') === '') self::set('auto_expand_tolerance_pct', '5');
        if ((string) self::get('briefing_skip_when_plan_complete', '') === '') self::set('briefing_skip_when_plan_complete', '1');

        $yt_words = (int) self::get('youtube_word_count_target', 0);
        if ($yt_words < 2300) {
            self::set('youtube_word_count_target', '2600');
        }

        // Controle de uso desativado: defaults seguros APENAS na primeira instalação.
        // 1.0.0 BUG FIX: antes era set() puro que sobrescrevia escolha manual do usuário a cada upgrade.
        // Agora: só seta se a key ainda não existe (primeira instalação).
        // 1.0.0: auto_expand default mudou para '1' (qualidade > economia).
        if (self::get('auto_expand_enabled', null) === null) {
            self::set('auto_expand_enabled', '1');
        }

        // 1.0.0 MIGRATION ONE-SHOT: usuários que instalaram v1.0.0-v1.0.0 ficaram com
        // auto_expand_enabled='0' salvo no banco. Logs mostram score 42, 927/3000 palavras,
        // thin content fail. Esta migração roda UMA vez pra corrigir banco existente.
        // Marcador 'geo_v100_auto_expand_migrated' garante que roda só uma vez —
        // se o usuário DEPOIS desligar manualmente, respeita.
        if (get_option('geo_v100_auto_expand_migrated', '0') !== '1') {
            if ((string) self::get('auto_expand_enabled', '1') === '0') {
                self::set('auto_expand_enabled', '1');
                AutopilotLogger::log('writer', 'migration_v100_auto_expand_fixed', 'success',
                    'Migração v1.0.0: auto_expand_enabled forçado a 1 (estava 0 do upgrade).',
                    []
                );
            }
            update_option('geo_v100_auto_expand_migrated', '1', false);
        }

        if (self::get('quality_gate_ai_enabled', null) === null) {
            self::set('quality_gate_ai_enabled', '0');
        }
        if (self::get('faq_generator_enabled', null) === null) {
            self::set('faq_generator_enabled', '0');
        }
        $job_retries = (int) self::get('writer_max_job_retries', 0);
        if ($job_retries < 0 || $job_retries > 1) self::set('writer_max_job_retries', '1');
        if (self::get('writer_max_retries', null) === null) {
            self::set('writer_max_retries', '0');
        }
    }

    /** Helper: ler config */
    public static function get(string $key, $default = null) {
        global $wpdb;
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}sara_autopilot_config WHERE config_key = %s",
            $key
        ));
        return $val ?? $default;
    }

    /** Helper: escrever config */
    public static function set(string $key, $value): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}sara_autopilot_config (config_key, config_value)
             VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)",
            $key, is_array($value) ? wp_json_encode($value) : (string) $value
        ));
    }
}
