<?php
namespace GeoMetodoSEO\Core;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Admin\AdminController;

class Plugin {

    protected $loader;

    public function __construct() {
        $this->loader = new Loader();
        $this->define_admin_hooks();
    }

    private function define_admin_hooks() {
        $admin = new AdminController();
        $this->loader->add_action('admin_menu', $admin, 'register_menu');
        if (is_multisite()) {
            $this->loader->add_action('network_admin_menu', $admin, 'register_network_menu');
        }
    }

    public function run() {
        $this->loader->run();
    }
}
