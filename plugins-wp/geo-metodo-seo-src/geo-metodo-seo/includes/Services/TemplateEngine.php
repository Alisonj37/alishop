<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

class TemplateEngine {

    public function process($template, $data = []) {
        foreach ($data as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        return $template;
    }
}
