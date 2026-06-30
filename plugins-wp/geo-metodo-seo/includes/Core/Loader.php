<?php
namespace GeoMetodoSEO\Core;

if (!defined('ABSPATH')) { exit; }

class Loader {

    protected $actions = [];

    public function add_action($hook, $component, $callback) {
        $this->actions[] = [
            'hook'      => $hook,
            'component' => $component,
            'callback'  => $callback
        ];
    }

    public function run() {
        foreach ($this->actions as $action) {
            add_action(
                $action['hook'],
                [$action['component'], $action['callback']]
            );
        }
    }
}
