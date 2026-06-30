<?php
namespace GeoMetodoSEO\Database;

if (!defined('ABSPATH')) { exit; }

class DatabaseManager {

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table1 = $wpdb->prefix . 'geo_jobs';
        dbDelta("CREATE TABLE {$table1} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword TEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset_collate};");

        $table2 = $wpdb->prefix . 'geo_clusters';
        dbDelta("CREATE TABLE {$table2} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pillar_post_id BIGINT,
            satellite_post_ids LONGTEXT,
            keyword TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset_collate};");

        $table3 = $wpdb->prefix . 'geo_logs';
        dbDelta("CREATE TABLE {$table3} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            type VARCHAR(20) DEFAULT 'info',
            post_id BIGINT UNSIGNED DEFAULT 0,
            message TEXT,
            PRIMARY KEY (id),
            KEY type (type),
            KEY created_at (created_at)
        ) {$charset_collate};");

        $table4 = $wpdb->prefix . 'geo_bulk_sessions';
        dbDelta("CREATE TABLE {$table4} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(36) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            keywords LONGTEXT,
            PRIMARY KEY (id),
            KEY session_id (session_id)
        ) {$charset_collate};");

        $table5 = $wpdb->prefix . 'geo_templates';
        dbDelta("CREATE TABLE {$table5} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            sections LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset_collate};");
    }
}
