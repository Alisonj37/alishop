<?php
namespace GeoMetodoSEO\Autopilot\Shared;

if (!defined('ABSPATH')) { exit; }

/**
 * AutopilotLogger — Logger centralizado para os 2 agentes.
 * Grava em wp_sara_execution_log e também no LogService do plugin principal.
 */
class AutopilotLogger {

    /**
     * @param string $agent     brain|writer|system
     * @param string $action    Nome da ação (ex: 'plan_categories')
     * @param string $status    start|success|error|warning|skip
     * @param string $message   Mensagem legível
     * @param array  $context   Dados extras (post_id, tokens, etc.)
     */
    public static function log(
        string $agent,
        string $action,
        string $status,
        string $message = '',
        array  $context = []
    ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sara_execution_log';

        $row = [
            'agent'       => $agent,
            'action'      => $action,
            'status'      => $status,
            'message'     => mb_substr($message, 0, 65000),
            'context'     => !empty($context) ? wp_json_encode($context) : null,
            'calendar_id' => $context['calendar_id'] ?? null,
            'post_id'     => $context['post_id'] ?? null,
            'duration_ms' => $context['duration_ms'] ?? null,
            'tokens_used' => $context['tokens_used'] ?? null,
            'cost_usd'    => $context['cost_usd'] ?? null,
        ];

        $wpdb->insert($table, $row);
        $inserted_id = (int) $wpdb->insert_id;

        // Também logar no sistema de log do plugin principal
        if (class_exists('GeoMetodoSEO\Services\LogService')) {
            $level = ($status === 'error') ? 'error' : (($status === 'warning') ? 'warning' : 'info');
            \GeoMetodoSEO\Services\LogService::log($level, "[SARA {$agent}] {$action}: {$message}");
        }

        return $inserted_id;
    }

    /** Timer simples: retorna duração em ms desde $start */
    public static function elapsed(float $start): int {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /** Últimos N logs de um agente */
    public static function recent(string $agent = '', int $limit = 50): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sara_execution_log';
        if ($agent) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE agent = %s ORDER BY id DESC LIMIT %d",
                $agent, $limit
            ), ARRAY_A) ?: [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit
        ), ARRAY_A) ?: [];
    }

    /** Custo total em USD nos últimos N dias */
    public static function total_cost(int $days = 30): float {
        global $wpdb;
        $table = $wpdb->prefix . 'sara_execution_log';
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(cost_usd) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        return round((float) $val, 6);
    }
}
