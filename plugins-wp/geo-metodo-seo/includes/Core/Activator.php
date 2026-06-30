<?php
namespace GeoMetodoSEO\Core;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Database\DatabaseManager;

class Activator {

    public static function activate() {
        DatabaseManager::create_tables();
        if (class_exists('GeoMetodoSEO\\Glossary\\GeoGlossaryService')) {
            \GeoMetodoSEO\Glossary\GeoGlossaryService::register_post_type_and_taxonomy();
            \GeoMetodoSEO\Glossary\GeoGlossaryService::maybe_create_glossary_page();
            flush_rewrite_rules();
        }
    }
}
