<?php
namespace GeoMetodoSEO\SEO;

if (!defined('ABSPATH')) { exit; }

class LinkOrchestrator {

    private $internal;
    private $external;

    public function __construct() {
        $this->internal = new InternalLinkingService();
        $this->external = new ExternalLinkingService();
    }

    public function process($post_id, $content) {
        // 1. Links internos primeiro (prioridade SEO)
        $content = $this->internal->apply($post_id, $content);

        // 2. Links externos (credibilidade)
        $content = $this->external->apply($content);

        return $content;
    }
}
