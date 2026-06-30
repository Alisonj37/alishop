<?php
namespace GeoMetodoSEO\Config;

if (!defined('ABSPATH')) { exit; }

class ConfigManager {

    public static function get($key, $default = null) {
        return get_option('geo_' . $key, $default);
    }

    public static function set($key, $value) {
        update_option('geo_' . $key, $value);
    }
}
