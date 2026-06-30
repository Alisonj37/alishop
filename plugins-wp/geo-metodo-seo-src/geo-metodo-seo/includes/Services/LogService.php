<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * LogService — Sistema de log unificado do plugin.
 *
 * @since 1.0.0
 * @since 1.0.0 Schema enriquecido + métodos de query com filtros.
 *               Mantém 100% de compatibilidade com a API antiga: log($type, $message, $post_id).
 *               Tabela geo_logs ganha colunas: module, context (JSON), action, duration_ms.
 */
class LogService {

    const TYPE_INFO    = 'info';
    const TYPE_SUCCESS = 'success';
    const TYPE_WARNING = 'warning';
    const TYPE_ERROR   = 'error';

    /** Módulos conhecidos do plugin (apenas referência — qualquer string é válida) */
    const MODULE_GENERATOR    = 'generator';     // Gerador individual
    const MODULE_BULK         = 'bulk';          // Geração em massa
    const MODULE_TEMPLATE     = 'template';
    const MODULE_TITLE        = 'title';
    const MODULE_CLUSTER      = 'cluster';
    const MODULE_SCHEMA       = 'schema';
    const MODULE_EEAT         = 'eeat';
    const MODULE_GSC          = 'gsc';
    const MODULE_REPLICATE    = 'replicate';
    const MODULE_NAGA         = 'naga';
    const MODULE_TTS          = 'tts';
    const MODULE_YOUTUBE      = 'youtube';
    const MODULE_WEBSTORIES   = 'webstories';
    const MODULE_PIPELINE     = 'pipeline';
    const MODULE_UPDATER      = 'updater';
    const MODULE_AUTOPILOT    = 'autopilot';
    const MODULE_LICENSE      = 'license';
    const MODULE_AI           = 'ai';            // Provedores de texto (OpenAI, Claude, Gemini, etc)
    const MODULE_SEO          = 'seo';
    const MODULE_MEDIA        = 'media';         // Media Opportunities
    const MODULE_INDEXING     = 'indexing';      // IndexNow
    const MODULE_REFRESHER    = 'refresher';     // ContentRefresher
    const MODULE_SETTINGS     = 'settings';
    const MODULE_OTHER        = 'other';

    /**
     * 1.0.0 — Garantir que a tabela tem o schema novo (idempotente).
     * Roda automaticamente no bootstrap do plugin.
     */
    public static function ensure_schema(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'geo_logs';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Tabela base. Se já existir, dbDelta apenas adiciona colunas faltantes.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'info',
            module VARCHAR(40) DEFAULT NULL,
            action VARCHAR(60) DEFAULT NULL,
            post_id BIGINT UNSIGNED DEFAULT 0,
            message TEXT,
            context LONGTEXT DEFAULT NULL,
            duration_ms INT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY ix_created (created_at),
            KEY ix_type (type),
            KEY ix_module (module),
            KEY ix_post (post_id)
        ) {$charset};";

        dbDelta($sql);
    }

    /**
     * Registra um evento no log.
     *
     * Mantém 100% de compatibilidade com a API antiga.
     * Suporta 3 formas de chamada:
     *
     *  1) Antiga (10 services existentes):
     *     LogService::log('info', 'Mensagem', $post_id);
     *
     *  2) Rica (nova):
     *     LogService::log('info', 'Mensagem', $post_id, [
     *         'module'      => 'generator',
     *         'action'      => 'generate_article',
     *         'context'     => ['key' => 'value'],
     *         'duration_ms' => 1234,
     *     ]);
     *
     *  3) Estilo "rich" via método dedicado (recomendado para código novo):
     *     LogService::record('generator', 'success', 'Artigo gerado', [
     *         'action' => 'generate', 'post_id' => 123, 'duration_ms' => 5000,
     *     ]);
     */
    public static function log($type, $message, $post_id = 0, array $extra = []): int {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_logs';

        $row = [
            'created_at'  => current_time('mysql'),
            'type'        => sanitize_text_field((string)$type),
            'post_id'     => absint($post_id),
            'message'     => mb_substr(sanitize_textarea_field((string)$message), 0, 65000),
            'module'      => isset($extra['module']) ? sanitize_text_field((string)$extra['module']) : null,
            'action'      => isset($extra['action']) ? sanitize_text_field((string)$extra['action']) : null,
            'context'     => isset($extra['context']) ? wp_json_encode($extra['context']) : null,
            'duration_ms' => isset($extra['duration_ms']) ? (int)$extra['duration_ms'] : null,
        ];

        $formats = ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d'];

        // Auto-detectar módulo via stack trace se não foi passado
        if (empty($row['module'])) {
            $row['module'] = self::auto_detect_module();
        }

        $wpdb->insert($table, $row, $formats);
        return (int) $wpdb->insert_id;
    }

    /**
     * Método rico (recomendado para código novo).
     *
     * @param string $module     'generator' | 'cluster' | 'gsc' | 'falai' | etc
     * @param string $type       'info' | 'success' | 'warning' | 'error'
     * @param string $message    Mensagem detalhada
     * @param array  $opts       Opções adicionais:
     *                            - 'action':      string  Ex: 'generate_article', 'submit_url'
     *                            - 'post_id':     int     Post relacionado
     *                            - 'context':     array   Dados extras (serializados em JSON)
     *                            - 'duration_ms': int     Duração da operação
     */
    public static function record(string $module, string $type, string $message, array $opts = []): int {
        return self::log(
            $type,
            $message,
            (int) ($opts['post_id'] ?? 0),
            [
                'module'      => $module,
                'action'      => $opts['action']      ?? null,
                'context'     => $opts['context']     ?? null,
                'duration_ms' => $opts['duration_ms'] ?? null,
            ]
        );
    }

    /**
     * Tentar inferir o módulo a partir do backtrace (caller class).
     */
    private static function auto_detect_module(): string {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';
            if (empty($class)) continue;
            // Pular frames internos do LogService
            if (str_contains($class, 'LogService')) continue;

            // Mapear classe → módulo
            $map = [
                'Replicate'     => self::MODULE_REPLICATE,
                'Naga'          => self::MODULE_NAGA,
                'TTS'           => self::MODULE_TTS,
                'YouTube'       => self::MODULE_YOUTUBE,
                'WebStor'       => self::MODULE_WEBSTORIES,
                'Cluster'       => self::MODULE_CLUSTER,
                'Schema'        => self::MODULE_SCHEMA,
                'EEAT'          => self::MODULE_EEAT,
                'SearchConsole' => self::MODULE_GSC,
                'Individual'    => self::MODULE_GENERATOR,
                'Bulk'          => self::MODULE_BULK,
                'Title'         => self::MODULE_TITLE,
                'Template'      => self::MODULE_TEMPLATE,
                'Pipeline'      => self::MODULE_PIPELINE,
                'Updater'       => self::MODULE_UPDATER,
                'Autopilot'     => self::MODULE_AUTOPILOT,
                'License'       => self::MODULE_LICENSE,
                'SARA'          => self::MODULE_AI,
                'IndexNow'      => self::MODULE_INDEXING,
                'Refresher'     => self::MODULE_REFRESHER,
                'Media'         => self::MODULE_MEDIA,
                'Settings'      => self::MODULE_SETTINGS,
            ];

            foreach ($map as $needle => $module) {
                if (stripos($class, $needle) !== false) return $module;
            }
        }

        return self::MODULE_OTHER;
    }

    /**
     * Retorna registros recentes (sem filtros).
     */
    public static function get_recent(int $limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_logs';
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit)
        );
    }

    /**
     * 1.0.0 — Query com filtros (usada pela página admin).
     *
     * @param array $filters  Filtros disponíveis:
     *                         - 'type':     'info'|'success'|'warning'|'error'
     *                         - 'module':   string (ex: 'gsc')
     *                         - 'post_id':  int
     *                         - 'search':   string (busca em message + action)
     *                         - 'period':   '24h'|'7d'|'30d'|'all'
     *                         - 'limit':    int (default 200)
     *                         - 'offset':   int (default 0)
     * @return array{rows:array, total:int}
     */
    public static function query(array $filters = []): array {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_logs';

        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['type'])) {
            $where[]  = 'type = %s';
            $params[] = $filters['type'];
        }

        if (!empty($filters['module'])) {
            $where[]  = 'module = %s';
            $params[] = $filters['module'];
        }

        if (!empty($filters['post_id'])) {
            $where[]  = 'post_id = %d';
            $params[] = (int) $filters['post_id'];
        }

        if (!empty($filters['search'])) {
            $where[]  = '(message LIKE %s OR action LIKE %s)';
            $like     = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $period = $filters['period'] ?? 'all';
        switch ($period) {
            case '24h': $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"; break;
            case '7d':  $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";   break;
            case '30d': $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";  break;
        }

        $limit  = (int) ($filters['limit']  ?? 200);
        $offset = (int) ($filters['offset'] ?? 0);
        if ($limit  < 1)    $limit  = 200;
        if ($limit  > 1000) $limit  = 1000;
        if ($offset < 0)    $offset = 0;

        $where_sql = implode(' AND ', $where);

        // Total
        if (empty($params)) {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}");
        } else {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params)
            );
        }

        // Rows
        $sql        = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $all_params = array_merge($params, [$limit, $offset]);
        $rows       = $wpdb->get_results($wpdb->prepare($sql, $all_params));

        return ['rows' => $rows ?: [], 'total' => $total];
    }

    /**
     * 1.0.0 — Lista módulos distintos no banco (para popular o filtro).
     */
    public static function distinct_modules(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_logs';
        $rows = $wpdb->get_col(
            "SELECT DISTINCT module FROM {$table} WHERE module IS NOT NULL AND module != '' ORDER BY module ASC"
        );
        return $rows ?: [];
    }

    /**
     * 1.0.0 — Stats por módulo nas últimas 24h (dashboard rápido).
     */
    public static function get_stats_24h(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_logs';
        $rows = $wpdb->get_results(
            "SELECT module, type, COUNT(*) as n
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY module, type",
            ARRAY_A
        );

        $stats = [];
        foreach ($rows ?: [] as $r) {
            $module = $r['module'] ?? 'other';
            if (!isset($stats[$module])) {
                $stats[$module] = ['info' => 0, 'success' => 0, 'warning' => 0, 'error' => 0, 'total' => 0];
            }
            $stats[$module][$r['type']] = (int)$r['n'];
            $stats[$module]['total']   += (int)$r['n'];
        }
        return $stats;
    }

    /**
     * Apaga logs (com filtro opcional).
     */
    public static function clear(array $filters = []): int {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_logs';

        if (empty($filters)) {
            $wpdb->query("TRUNCATE TABLE {$table}");
            return -1;
        }

        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['type'])) {
            $where[]  = 'type = %s';
            $params[] = $filters['type'];
        }
        if (!empty($filters['module'])) {
            $where[]  = 'module = %s';
            $params[] = $filters['module'];
        }
        if (!empty($filters['older_than_days'])) {
            $where[] = "created_at < DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params[] = (int) $filters['older_than_days'];
        }

        $where_sql = implode(' AND ', $where);
        $sql = "DELETE FROM {$table} WHERE {$where_sql}";

        if (empty($params)) {
            return (int) $wpdb->query($sql);
        }
        return (int) $wpdb->query($wpdb->prepare($sql, $params));
    }

    public static function cleanup_old(?int $days = null): array {
        global $wpdb;
        $days = max(7, min(365, (int) ($days ?? get_option('geo_logs_retention_days', 45))));

        $geo_deleted = self::clear(['older_than_days' => $days]);

        $sara_table = $wpdb->prefix . 'sara_execution_log';
        $sara_deleted = 0;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sara_table));
        if ($exists === $sara_table) {
            $sara_deleted = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$sara_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $days
                )
            );
        }

        return [
            'retention_days' => $days,
            'geo_logs_deleted' => $geo_deleted,
            'sara_logs_deleted' => $sara_deleted,
        ];
    }
}


