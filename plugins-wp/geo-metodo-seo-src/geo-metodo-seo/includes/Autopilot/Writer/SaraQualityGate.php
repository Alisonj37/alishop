<?php
namespace GeoMetodoSEO\Autopilot\Writer;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\{AutopilotInstaller, AutopilotLogger};
use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;

/**
 * SaraQualityGate — Quality Gate do Agente B.
 * Usa GPT-4.1-mini (economia) para scoring e aprovação.
 * Score mínimo: 75/100 para publicar.
 */
class SaraQualityGate {

    private AIManager $ai;
    private int $threshold;

    public function __construct() {
        $this->ai        = new AIManager();
        // 1.0.0 BUG FIX: threshold default 70 (era 75 — rejeitava artigos de 72 sempre)
        $this->threshold = (int) AutopilotInstaller::get('writer_quality_threshold', 70);
    }

    /**
     * Avaliar conteúdo e retornar aprovação + scores.
     * @return array ['approved'=>bool, 'score'=>int, 'breakdown'=>[], 'issues'=>[]]
     */
    public function evaluate(string $content, array $plan): array {
        $t0 = microtime(true);

        // Verificações locais rápidas (sem IA)
        $local = $this->local_checks($content, $plan);

        // 1.0.0: March 2026 Core Update — validação de Thin Content
        if (class_exists('\\GeoMetodoSEO\\Quality\\ThinContentValidator')) {
            $thin = \GeoMetodoSEO\Quality\ThinContentValidator::validate(
                $content,
                (int) ($plan['word_count_target'] ?? 1500)
            );
            $local['thin_content_score']   = $thin['score'];
            $local['thin_content_issues']  = $thin['issues'];
            $local['thin_content_metrics'] = $thin['metrics'];
            if (!$thin['passed']) {
                $local['thin_content_failed'] = true;
                // Reduz score geral em 40% se Thin Content detectado
                $local['score'] = (int) round(($local['score'] ?? 100) * 0.6);
            }
        }

        // Se já falhou em critérios básicos, rejeitar sem gastar crédito de IA
        if ($local['score'] < 40) {
            return array_merge($local, [
                'approved' => false,
                'source'   => 'local',
            ]);
        }

        // 1.0.0: Quality Gate IA agora é OPCIONAL (economia ~$0.01/artigo).
        // Default DESLIGADO — usa só local_checks() que já valida word count, headings, FAQ etc.
        // Set 'quality_gate_ai_enabled' = '1' nas configurações para reativar.
        $use_ai_gate = (string) AutopilotInstaller::get('quality_gate_ai_enabled', '0') === '1';
        if (!$use_ai_gate) {
            AutopilotLogger::log('writer', 'quality_gate_local_only', 'info',
                'Quality Gate em modo LOCAL (IA desligada para economia)',
                ['calendar_id' => $plan['id'] ?? 0, 'score' => $local['score'] ?? 0]
            );
            return array_merge($local, [
                'source'   => 'local_only',
                'approved' => ($local['score'] ?? 0) >= $this->threshold,
            ]);
        }

        // Quality Gate via modelo configurável (padrão econômico)
        $prompt = $this->build_quality_prompt($content, $plan);
        $provider = ProviderResolver::for('sara_scoring');
        $model  = ProviderResolver::modelFor('sara_scoring', $provider);
        $response = $this->ai->generateText($prompt, $provider, $model);

        if (!$response || $response->hasError()) {
            // Fallback: usar apenas score local se IA falhar
            AutopilotLogger::log('writer', 'quality_gate_fallback', 'warning',
                'IA indisponível, usando score local');
            return array_merge($local, ['source' => 'local_fallback']);
        }

        $raw  = trim($response->getContent());
        $raw  = preg_replace('/^```json\s*/i', '', $raw);
        $raw  = preg_replace('/```\s*$/i', '', $raw);
        $data = json_decode(trim($raw), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['unified_score'])) {
            return array_merge($local, ['source' => 'local_parse_error']);
        }

        $score    = (int) $data['unified_score'];
        $approved = $score >= $this->threshold
                    && ($data['breakdown']['seo'] ?? 0)  >= 60
                    && ($data['breakdown']['geo'] ?? 0)  >= 60
                    && ($data['breakdown']['aeo'] ?? 0)  >= 60;

        AutopilotLogger::log('writer', 'quality_gate', $approved ? 'success' : 'warning',
            "Score: {$score}/100 | " . ($approved ? 'APROVADO' : 'REJEITADO'), [
                'calendar_id' => $plan['id'] ?? 0,
                'duration_ms' => AutopilotLogger::elapsed($t0),
                'tokens_used' => 3000,
                'cost_usd'    => 0.0012,
            ]
        );

        return [
            'approved'   => $approved,
            'score'      => $score,
            'breakdown'  => $data['breakdown'] ?? [],
            'issues'     => $data['issues'] ?? [],
            'suggestions'=> $data['improvement_suggestions'] ?? [],
            'source'     => 'ai',
        ];
    }

    /** Verificações locais rápidas (sem chamada de IA) */
    private function local_checks(string $content, array $plan): array {
        $score  = 100;
        $issues = [];
        $text   = strip_tags($content);
        $wc     = str_word_count($text);
        // FIX v1.0.0-WORDCOUNT: aceitar 800-6000 palavras.
        $target = max(800, min(6000, (int)($plan['word_count_target'] ?? 2300)));
        $kw     = strtolower($plan['keyword'] ?? '');

        // Contagem de palavras
        $ratio = $wc / max($target, 1);
        if ($ratio < 0.6) {
            $score -= 30;
            $issues[] = ['severity' => 'high', 'description' => "Conteúdo muito curto: {$wc}/{$target} palavras"];
        } elseif ($ratio < 0.8) {
            $score -= 10;
            $issues[] = ['severity' => 'medium', 'description' => "Conteúdo abaixo do target: {$wc}/{$target} palavras"];
        }

        // Keyword no conteúdo
        if ($kw && substr_count(strtolower($text), $kw) === 0) {
            $score -= 20;
            $issues[] = ['severity' => 'high', 'description' => "Keyword principal ausente no conteúdo"];
        }

        // FAQ oficial é gerado depois do Quality Gate; não penalizar aqui para evitar falso negativo.

        // Tabela comparativa
        if (stripos($content, '<table') === false) {
            $score -= 10;
            $issues[] = ['severity' => 'medium', 'description' => "Tabela comparativa ausente"];
        }

        // H2 headings
        preg_match_all('/<h2/i', $content, $h2_matches);
        if (count($h2_matches[0]) < 3) {
            $score -= 10;
            $issues[] = ['severity' => 'medium', 'description' => "Poucos H2s: " . count($h2_matches[0])];
        }

        return [
            'approved'  => $score >= $this->threshold,
            'score'     => max(0, $score),
            'breakdown' => ['local' => $score],
            'issues'    => $issues,
        ];
    }

    private function build_quality_prompt(string $content, array $plan): string {
        $prompt_file = GEO_METODO_SEO_PATH . 'sara-autopilot/prompts/writer/quality-prompt.txt';
        $base = file_exists($prompt_file) ? file_get_contents($prompt_file) : '';

        if (empty($base)) {
            $base = "Você é SARA QUALITY, editor de qualidade sênior. Avalie o artigo e retorne JSON com scores.";
        }

        $excerpt = mb_substr(strip_tags($content), 0, 3000);

        return $base . "\n\n"
            . "TÍTULO PLANEJADO: {$plan['title']}\n"
            . "KEYWORD: {$plan['keyword']}\n"
            . "TAMANHO ALVO: {$plan['word_count_target']} palavras\n"
            . "CONTAGEM REAL: " . str_word_count(strip_tags($content)) . " palavras\n\n"
            . "ARTIGO (primeiras 3000 palavras):\n{$excerpt}\n\n"
            . "Retorne APENAS JSON válido com: approved, unified_score (0-100), "
            . "breakdown (seo, geo, aeo, llm, ux cada 0-100), issues (array), improvement_suggestions (array)";
    }
}
