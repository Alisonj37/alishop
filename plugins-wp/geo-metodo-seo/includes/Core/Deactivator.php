<?php
namespace GeoMetodoSEO\Core;

if (!defined('ABSPATH')) { exit; }

class Deactivator {

    public static function deactivate() {
        $hooks = [
            'geo_queue_worker_event',
            'geo_auto_schedule_event',
            'geo_daily_rewrite_event',
            'sara_autopilot_health_repair',
            'geo_license_revalidate_event',
            'geo_generate_story_delayed',
            'geo_youtube_process_context_images',
            'sara_process_internal_images',
            'sara_content_refresher_run',
            'sara_media_collect_cron',
            'sara_indexnow_flush',
            'geo_logs_daily_cleanup',
        ];

        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        if (class_exists('GeoMetodoSEO\\Autopilot\\Shared\\ScheduleManager')) {
            \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::clear();
        }
        if (class_exists('GeoMetodoSEO\\TitleBank\\SeoGeoTitleBank')) {
            \GeoMetodoSEO\TitleBank\SeoGeoTitleBank::unregister_cron();
        }
        if (class_exists('GeoMetodoSEO\\Tools\\ImageReSideloader')) {
            \GeoMetodoSEO\Tools\ImageReSideloader::unregister_cron();
        }
    }
}
