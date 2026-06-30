<?php
namespace GeoMetodoSEO\AI;

if (!defined('ABSPATH')) { exit; }

class AIResponse {

    private $content;
    private $raw;
    private $error;

    public function __construct($content = '', $raw = [], $error = null) {
        $this->content = $content;
        $this->raw     = $raw;
        $this->error   = $error;
    }

    public function getContent() {
        return $this->content;
    }

    public function getRaw() {
        return $this->raw;
    }

    public function getUsage() {
        return $this->raw['usage'] ?? null;
    }

    public function hasError() {
        return !empty($this->error);
    }

    public function getError() {
        return $this->error;
    }
}
