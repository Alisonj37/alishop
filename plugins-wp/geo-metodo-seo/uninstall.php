<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$hooks = [
    // Cron events ativos
    'geo_auto_schedule_event',
    'geo_daily_rewrite_event',
    'geo_license_revalidate_event',
    'geo_titlebank_daily_cleanup',
    'geo_logs_daily_cleanup',
    'geo_resideload_daily',
    'sara_autopilot_health_repair',
    'sara_content_refresher_run',
    'sara_media_collect_cron',
    // Single events (podem estar agendados)
    'geo_generate_story_delayed',
    'geo_youtube_process_context_images',
    'geo_process_single_keyword_event',
    'sara_process_internal_images',
    // Legados (versões antigas) — manter para limpar instalações antigas
    'geo_queue_worker_event',
    'geo_resideload_daily_cron',
];

foreach ($hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

if ((int) get_option('geo_delete_data_on_uninstall', 0) !== 1) {
    return;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'geo_jobs',
    $wpdb->prefix . 'geo_clusters',
    $wpdb->prefix . 'geo_logs',
    $wpdb->prefix . 'geo_bulk_sessions',
    $wpdb->prefix . 'geo_templates',
    $wpdb->prefix . 'sara_editorial_calendar',
    $wpdb->prefix . 'sara_execution_log',
    $wpdb->prefix . 'sara_semantic_index',
    $wpdb->prefix . 'sara_media_opportunities',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

$option_names = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options}
     WHERE option_name LIKE 'geo\_%'
        OR option_name LIKE 'sara\_%'
        OR option_name LIKE 'autopilot\_%'
        OR option_name LIKE '_geo_lock\_%'"
);

foreach ((array) $option_names as $option_name) {
    delete_option($option_name);
    delete_site_option($option_name);
}
