<?php
namespace GeoMetodoSEO\AI;

if (!defined('ABSPATH')) { exit; }

interface AIProviderInterface {
    public function generate($prompt, $model = null);
}
