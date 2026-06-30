<?php
namespace GeoMetodoSEO\Helpers;

if (!defined('ABSPATH')) { exit; }

class SecurityHelper {

    public static function sanitize_text($value) {
        return sanitize_text_field($value);
    }

    public static function sanitize_textarea($value) {
        return sanitize_textarea_field($value);
    }

    public static function verify_nonce($nonce, $action) {
        if (!isset($nonce) || !wp_verify_nonce($nonce, $action)) {
            wp_die('Acao nao autorizada');
        }
    }

    public static function current_user_can_manage() {
        if (!current_user_can('manage_options')) {
            wp_die('Permissao negada');
        }
    }
}
