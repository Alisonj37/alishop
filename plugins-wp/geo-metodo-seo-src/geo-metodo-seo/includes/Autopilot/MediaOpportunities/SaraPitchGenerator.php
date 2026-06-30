<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\MediaOpportunities;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;
use GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller;

/**
 * SaraPitchGenerator — Gera draft de resposta para uma query de jornalista.
 *
 * Sob demanda (clique do usuário no botão "Gerar Pitch"). Não roda em background
 * para evitar custo desnecessário de IA.
 *
 * O draft é gerado considerando:
 *   - Voz/tom do site (analisado a partir dos últimos 3 posts publicados)
 *   - Nicho detectado pelo Brain
 *   - Idioma da query (PT ou EN)
 *   - Tipo da fonte (Twitter exige resposta curta, Reddit/SourceBottle pode ser longo)
 *
 * O pitch é SEMPRE rascunho — usuário revisa e envia manualmente. Compliance.
 *
 * @since 1.0.0
 */
class SaraPitchGenerator {

    /** Tamanho máximo de pitch por tipo de fonte */
    private const MAX_TWITTER_CHARS = 280;
    private const MAX_REDDIT_WORDS  = 250;
    private const MAX_DEFAULT_WORDS = 200;

    /**
     * Gerar pitch para uma oportunidade específica.
     *
     * @param int $opportunity_id ID na tabela sara_media_opportunities
     * @return array{success:bool, pitch?:string, message?:string}
     */
    public static function generate(int $opportunity_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sara_media_opportunities';

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $opportunity_id),
            ARRAY_A
        );

        if (!$row) {
            return ['success' => false, 'message' => 'Oportunidade não encontrada.'];
        }

        // Se já gerou antes, retornar do cache (DB)
        if (!empty($row['generated_pitch'])) {
            return [
                'success' => true,
                'pitch'   => $row['generated_pitch'],
                'cached'  => true,
            ];
        }

        // Verificar se IA está disponível
        if (!class_exists('\GeoMetodoSEO\Autopilot\Brain\SaraApiClient')) {
            return ['success' => false, 'message' => 'API de IA não disponível.'];
        }

        try {
            $context = self::build_context($row);
            $prompt  = self::build_prompt($row, $context);

            $api = new \GeoMetodoSEO\Autopilot\Brain\SaraApiClient();
            $raw = '';

            if (method_exists($api, 'simple_complete')) {
                $raw = (string) $api->simple_complete($prompt);
            } elseif (method_exists($api, 'request_with_backoff')) {
                $raw = (string) $api->request_with_backoff($prompt, 'pitch_generator');
            }

            $pitch = self::clean_pitch($raw, $row['source_type']);

            if (mb_strlen($pitch) < 50) {
                return ['success' => false, 'message' => 'IA retornou pitch muito curto.'];
            }

            // Salvar no DB para evitar regerar
            $wpdb->update(
                $table,
                ['generated_pitch' => $pitch],
                ['id' => $opportunity_id],
                ['%s'],
                ['%d']
            );

            AutopilotLogger::log('media', 'pitch_generated', 'success',
                "Pitch gerado para oportunidade #{$opportunity_id}");

            return ['success' => true, 'pitch' => $pitch];

        } catch (\Throwable $e) {
            AutopilotLogger::log('media', 'pitch_error', 'error',
                "#{$opportunity_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erro: ' . $e->getMessage()];
        }
    }

    /**
     * Coletar contexto do site para usar no prompt.
     */
    private static function build_context(array $row): array {
        $niche = get_option('sara_site_niche', '');
        if (empty($niche) && class_exists('\GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller')) {
            $niche = AutopilotInstaller::get('site_niche', '');
        }
        if (empty($niche)) $niche = get_bloginfo('name');

        // Pegar 2-3 posts recentes para a IA captar a voz/estilo
        $recent_posts = get_posts([
            'numberposts' => 3,
            'post_status' => 'publish',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        $voice_samples = [];
        foreach ($recent_posts as $p) {
            $excerpt = wp_strip_all_tags($p->post_content);
            $excerpt = preg_replace('/\s+/u', ' ', $excerpt);
            $voice_samples[] = mb_substr(trim($excerpt), 0, 400);
        }

        $site_name        = get_bloginfo('name');
        $site_description = get_bloginfo('description');

        return [
            'niche'         => $niche,
            'site_name'     => $site_name,
            'site_url'      => get_site_url(),
            'description'   => $site_description,
            'voice_samples' => $voice_samples,
        ];
    }

    /**
     * Montar prompt para a IA.
     */
    private static function build_prompt(array $row, array $context): string {
        $is_pt = ($row['lang'] === 'pt');
        $type  = $row['source_type'];

        // Limites por tipo de fonte
        $limit_text = '';
        if ($type === 'twitter') {
            $limit_text = $is_pt
                ? "MÁXIMO 280 caracteres (limite do Twitter)."
                : "MAX 280 characters (Twitter limit).";
        } elseif ($type === 'reddit') {
            $limit_text = $is_pt
                ? "ENTRE 150-250 palavras. Tom conversacional do Reddit."
                : "BETWEEN 150-250 words. Reddit conversational tone.";
        } else {
            $limit_text = $is_pt
                ? "ENTRE 100-200 palavras. Direto e informativo."
                : "BETWEEN 100-200 words. Direct and informative.";
        }

        $voice_block = '';
        if (!empty($context['voice_samples'])) {
            $samples = "\n---\n" . implode("\n---\n", $context['voice_samples']) . "\n---";
            $voice_block = $is_pt
                ? "AMOSTRAS DA VOZ DO SITE (escreva no MESMO estilo):{$samples}"
                : "WRITING VOICE SAMPLES (match this style):{$samples}";
        }

        $query_block = "QUERY:\n" . $row['title'];
        if (!empty($row['content'])) {
            $query_block .= "\n\nCONTEXTO ADICIONAL: " . mb_substr($row['content'], 0, 800);
        }

        if ($is_pt) {
            return <<<PROMPT
Você está escrevendo uma resposta a uma query de jornalista em nome do site "{$context['site_name']}".

NICHO/EXPERTISE: {$context['niche']}
SITE: {$context['site_url']}
DESCRIÇÃO: {$context['description']}

{$voice_block}

{$query_block}

REGRAS DA RESPOSTA:
- {$limit_text}
- Não use clichês ("é fundamental", "no mundo de hoje", "à frente da curva")
- Forneça insight ESPECÍFICO, dado concreto, exemplo prático
- Inclua 1 frase com a perspectiva única do site
- Tom profissional mas humano (não robótico)
- Se possível, termine com link relevante: {$context['site_url']}
- IDIOMA: Português do Brasil

RETORNE APENAS A RESPOSTA, sem comentários ou explicações.
PROMPT;
        }

        // English version
        return <<<PROMPT
You are drafting a response to a journalist's query on behalf of "{$context['site_name']}".

NICHE/EXPERTISE: {$context['niche']}
WEBSITE: {$context['site_url']}
DESCRIPTION: {$context['description']}

{$voice_block}

{$query_block}

RESPONSE RULES:
- {$limit_text}
- Avoid clichés ("it's essential", "in today's world", "ahead of the curve")
- Provide SPECIFIC insight, concrete data, practical example
- Include 1 sentence with the site's unique perspective
- Professional but human tone (not robotic)
- If possible, end with relevant link: {$context['site_url']}
- LANGUAGE: English

RETURN ONLY THE RESPONSE, no comments or explanations.
PROMPT;
    }

    /**
     * Limpar e validar o pitch gerado.
     */
    private static function clean_pitch(string $raw, string $type): string {
        $pitch = trim($raw);
        $pitch = preg_replace('/^```\w*\s*/m', '', $pitch);
        $pitch = preg_replace('/\s*```$/m', '', $pitch);
        $pitch = trim($pitch);

        // Twitter tem limite hard
        if ($type === 'twitter' && mb_strlen($pitch) > self::MAX_TWITTER_CHARS) {
            $pitch = mb_substr($pitch, 0, self::MAX_TWITTER_CHARS - 3) . '...';
        }

        return $pitch;
    }

    /**
     * Marcar uma oportunidade com ação do usuário (dismissed, used, copied).
     */
    public static function mark_action(int $opportunity_id, string $action): bool {
        $valid = ['dismissed', 'copied', 'used', 'pinned'];
        if (!in_array($action, $valid, true)) return false;

        global $wpdb;
        $table = $wpdb->prefix . 'sara_media_opportunities';

        return $wpdb->update(
            $table,
            ['user_action' => $action],
            ['id' => $opportunity_id],
            ['%s'],
            ['%d']
        ) !== false;
    }
}
