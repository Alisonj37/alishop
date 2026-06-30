<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Brain;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;

/**
 * SaraApiClient — Cliente de IA com rate limiting, backoff exponencial e fallback local.
 * Reutiliza o AIManager do plugin principal (sem duplicar lógica de provider).
 *
 * @since 1.0.0 (SARA Brain v2.0)
 */
class SaraApiClient {

    private AIManager $ai;
    private float $last_request_time = 0.0;
    private int   $min_delay_ms      = 1500;
    private int   $max_retries       = 4;

    // Log file separado conforme spec
    private string $log_file;

    public function __construct() {
        $this->ai       = new AIManager();
        $this->log_file = WP_CONTENT_DIR . '/sara-logs/brain.log';
        $this->ensure_log_dir();
    }

    /**
     * Requisição com backoff exponencial e fallback local.
     * Respeita rate limit de 1 req/s entre chamadas.
     *
     * @param  string $prompt   Prompt completo para a IA
     * @param  string $category Slug da categoria (usado no fallback)
     * @return string           Resposta da IA ou título de fallback
     */
    public function request_with_backoff(string $prompt, string $category): string {
        $delay_ms = $this->min_delay_ms;

        for ($attempt = 1; $attempt <= $this->max_retries; $attempt++) {
            // Garantir delay mínimo entre requisições
            $elapsed = microtime(true) - $this->last_request_time;
            if ($elapsed < 1.0) {
                usleep((int)((1.0 - $elapsed) * 1_000_000));
            }

            $this->brain_log("planner_one: [{$category}] Tentativa {$attempt}/{$this->max_retries}...");

            try {
                $this->last_request_time = microtime(true);

                $provider = ProviderResolver::for('brain');
                $model = ProviderResolver::modelFor('brain', $provider);
                $response = $this->ai->generateText($prompt, $provider, $model);

                if (!$response || $response->hasError()) {
                    $err = $response ? $response->getError() : 'sem resposta';

                    // Detectar rate limit pelo erro
                    if (str_contains(strtolower($err), 'rate') || str_contains($err, '429')) {
                        $this->brain_log("planner_one: [{$category}] Rate limit, retry em {$delay_ms}ms...");
                        usleep($delay_ms * 1_000);
                        $delay_ms *= 2;
                        continue;
                    }

                    AutopilotLogger::log('brain', 'api_error', 'error', $err);
                    continue;
                }

                $content = trim($response->getContent());
                if (!empty($content)) {
                    $this->brain_log("planner_one: [{$category}] Retry {$attempt}/{$this->max_retries}, sucesso!");
                    return $content;
                }

            } catch (\Throwable $e) {
                $this->brain_log("planner_one: [{$category}] Exceção: " . $e->getMessage());

                if ($attempt === $this->max_retries) {
                    AutopilotLogger::log('brain', 'api_exception', 'error', $e->getMessage());
                }

                usleep($delay_ms * 1_000);
                $delay_ms *= 2;
            }
        }

        // Fallback local — sem custo de API
        $this->brain_log("planner_one: [{$category}] Usando fallback local após {$this->max_retries} tentativas");
        return $this->fallback_local_title($category);
    }

    /**
     * Fallback: gerar título localmente baseado em padrões do nicho.
     * Garante que nunca retorna string vazia.
     */
    private function fallback_local_title(string $category): string {
        $year     = wp_date('Y');
        $niche    = get_option('sara_niche', 'Tecnologia');
        $patterns = [
            "Como melhorar {$niche} com pautas mais úteis e atualizadas em {$year}",
            "O que mudou em {$niche} e como isso afeta sites que publicam todos os dias",
            "Quando revisar conteúdos de {$niche} para recuperar tráfego orgânico",
            "Como organizar uma estratégia de {$niche} sem perder qualidade editorial",
            "Análise de {$niche}: sinais que mostram quando um conteúdo precisa ser atualizado",
        ];

        // Usar a categoria para personalizar mais
        $cat_labels = $GLOBALS['SARA_AVAILABLE_CATEGORIES'] ?? [];
        $cat_name   = $cat_labels[$category] ?? ucwords(str_replace('-', ' ', $category));

        $fallbacks_cat = [
            "Como resolver problemas comuns de {$cat_name} sem trocar de estratégia",
            "Quando vale revisar conteúdos sobre {$cat_name} publicados há mais de 120 dias",
            "{$cat_name} em {$year}: pontos que merecem atenção antes de publicar novos artigos",
            "Como escolher pautas de {$cat_name} com intenção de busca mais clara",
            "O que analisar em {$cat_name} antes de criar um novo conteúdo para Google e IA",
        ];

        // Misturar e pegar aleatório
        $all = array_merge($patterns, $fallbacks_cat);
        return $all[array_rand($all)];
    }

    /**
     * Log no arquivo brain.log (formato [BRAIN] action: message)
     */
    public function brain_log(string $message): void {
        $line = '[' . wp_date('Y-m-d H:i:s') . '] [BRAIN] ' . $message . PHP_EOL;
        file_put_contents($this->log_file, $line, FILE_APPEND | LOCK_EX);
    }

    private function ensure_log_dir(): void {
        $dir = dirname($this->log_file);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            // Proteger acesso direto
            file_put_contents($dir . '/.htaccess', 'Deny from all');
        }
    }
}
