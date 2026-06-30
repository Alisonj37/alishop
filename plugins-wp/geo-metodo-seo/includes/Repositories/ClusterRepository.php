<?php
namespace GeoMetodoSEO\Repositories;

if (!defined('ABSPATH')) { exit; }

class ClusterRepository {

    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'geo_clusters';
    }

    public function create($pillar_id, $satellites, $keyword) {
        global $wpdb;

        $wpdb->insert($this->table, [
            'pillar_post_id'     => $pillar_id,
            'satellite_post_ids' => json_encode($satellites),
            'keyword'            => $keyword
        ]);

        return $wpdb->insert_id;
    }
}
