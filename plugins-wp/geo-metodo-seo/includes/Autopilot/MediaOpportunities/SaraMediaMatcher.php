<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\MediaOpportunities;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;
use GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller;

/**
 * SaraMediaMatcher — Classifica relevância de cada oportunidade ao nicho do site.
 *
 * Usa o nicho que o Brain já detectou (sara_site_niche) e processa em batches
 * de 10 queries por chamada de IA — economiza muito custo vs 1 chamada por query.
 *
 * Saída: cada oportunidade ganha:
 *   - relevance_score (0-100)
 *   - relevance_reason (porque é/não é relevante)
 *   - niche_match (qual aspecto do nicho casa)
 *   - status: 'new' → 'matched' (relevante) ou 'rejected' (irrelevante)
 *
 * @since 1.0.0
 */
class SaraMediaMatcher {

    /** Score mínimo para considerar relevante */
    private const MIN_RELEVANT_SCORE = 50;

    /** Quantas queries processar por chamada de IA (economia de custo) */
    private const BATCH_SIZE = 10;

    /** Limite de processamento por execução (proteção contra runaway) */
    private const MAX_PER_RUN = 50;

    /**
     * Processar todas as oportunidades pendentes (status='new').
     *
     * @return array{processed:int, matched:int, rejected:int}
     */
    public static function match_pending(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sara_media_opportunities';

        // Pegar pendentes (limite por execução)
        $pending = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, content, lang, source_type
                 FROM {$table}
                 WHERE status = 'new'
                 ORDER BY collected_at DESC
                 LIMIT %d",
                self::MAX_PER_RUN
            ),
            ARRAY_A
        );

        if (empty($pending)) {
            return ['processed' => 0, 'matched' => 0, 'rejected' => 0];
        }

        $niche = self::get_site_niche();
        if (empty($niche)) {
            AutopilotLogger::log('media', 'matcher_no_niche', 'warning',
                'Nicho do site não detectado — execute o Brain primeiro');
            return ['processed' => 0, 'matched' => 0, 'rejected' => 0];
        }

        // Processar em batches
        $matched   = 0;
        $rejected  = 0;
        $processed = 0;

        foreach (array_chunk($pending, self::BATCH_SIZE) as $batch) {
            $results = self::classify_batch($batch, $niche);
            foreach ($results as $row) {
                self::update_status((int)$row['id'], $row);
                $processed++;
                if ((int)$row['relevance_score'] >= self::MIN_RELEVANT_SCORE) {
                    $matched++;
                } else {
                    $rejected++;
                }
            }
        }

        AutopilotLogger::log('media', 'matcher_done', 'success',
            "Matcher: {$processed} processados ({$matched} relevantes, {$rejected} descartados)");

        return ['processed' => $processed, 'matched' => $matched, 'rejected' => $rejected];
    }

    /**
     * Detectar nicho do site (lê o que o Brain já detectou).
     */
    private static function get_site_niche(): string {
        // Brain salva em sara_site_niche
        $niche = get_option('sara_site_niche', '');
        if (!empty($niche)) return (string)$niche;

        // Fallback: AutopilotInstaller (chave alternativa)
        if (class_exists('\GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller')) {
            $alt = AutopilotInstaller::get('site_niche', '');
            if (!empty($alt)) return $alt;
        }

        // Fallback final: nome + descrição do site
        return get_bloginfo('name') . ' — ' . get_bloginfo('description');
    }

    /**
     * Classificar um batch de queries com 1 única chamada de IA.
     */
    private static function classify_batch(array $batch, string $niche): array {
        if (!class_exists('\GeoMetodoSEO\Autopilot\Brain\SaraApiClient')) {
            // Sem IA: aceitar tudo com score neutro (50) para o usuário decidir
            return array_map(fn($r) => array_merge($r, [
                'relevance_score'  => 50,
                'relevance_reason' => '(sem IA disponível — usuário decide)',
                'niche_match'      => '',
            ]), $batch);
        }

        // Montar prompt compacto
        $queries_text = '';
        foreach ($batch as $i => $row) {
            $idx = $i + 1;
            $title    = mb_substr($row['title'], 0, 200);
            $content  = mb_substr($row['content'] ?? '', 0, 400);
            $queries_text .= "QUERY #{$idx}:\nTítulo: {$title}\nContexto: {$content}\n\n";
        }

        $prompt = <<<PROMPT
Você é um especialista em PR e SEO que avalia oportunidades de mídia para um site específico.

NICHO DO SITE:
{$niche}

LISTA DE QUERIES DE JORNALISTAS:
{$queries_text}

TAREFA:
Para CADA query (numerada 1-{count}), avalie se é uma oportunidade relevante para o site responder.

Critérios:
- Relevância ao nicho (0-100)
- Possibilidade do site fornecer expertise útil
- Qualidade do match (genérico vs específico)

REGRAS:
- Score 0-30: irrelevante (outro nicho/tema)
- Score 31-49: tangencial (relacionado mas distante)
- Score 50-69: relevante mas concorrido
- Score 70-89: muito relevante
- Score 90-100: ótima oportunidade (resposta perfeita possível)

IDIOMA DA RESPOSTA:
- Sempre responda os campos reason e match em português brasileiro, mesmo quando a oportunidade original estiver em inglês.
- Não traduza o título original; apenas explique relevância em PT-BR.

Retorne APENAS um JSON array, sem nenhum texto antes ou depois:

[
  {"id":1,"score":XX,"reason":"motivo curto em PT-BR","match":"aspecto do nicho que casa"},
  ...
]
PROMPT;

        $prompt = str_replace('{count}', (string)count($batch), $prompt);

        try {
            $api = new \GeoMetodoSEO\Autopilot\Brain\SaraApiClient();
            $raw = '';

            if (method_exists($api, 'simple_complete')) {
                $raw = (string) $api->simple_complete($prompt);
            } elseif (method_exists($api, 'request_with_backoff')) {
                $raw = (string) $api->request_with_backoff($prompt, 'media_matcher');
            }

            $raw = trim($raw);
            // Limpar code fences
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/```\s*$/', '', $raw);
            $raw = trim($raw);

            $parsed = json_decode($raw, true);
            if (!is_array($parsed)) {
                AutopilotLogger::log('media', 'matcher_parse_fail', 'warning',
                    'IA retornou JSON inválido — usando score neutro');
                return array_map(fn($r) => array_merge($r, [
                    'relevance_score'  => 50,
                    'relevance_reason' => '(falha ao parsear resposta da IA)',
                    'niche_match'      => '',
                ]), $batch);
            }

            // Mapear de volta para os rows originais por índice
            $result = [];
            foreach ($batch as $i => $row) {
                $idx = $i + 1;
                $match_data = null;
                foreach ($parsed as $p) {
                    if ((int)($p['id'] ?? 0) === $idx) { $match_data = $p; break; }
                }

                $result[] = array_merge($row, [
                    'relevance_score'  => max(0, min(100, (int)($match_data['score'] ?? 0))),
                    'relevance_reason' => mb_substr((string)($match_data['reason'] ?? ''), 0, 500),
                    'niche_match'      => mb_substr((string)($match_data['match'] ?? ''), 0, 120),
                ]);
            }
            return $result;

        } catch (\Throwable $e) {
            AutopilotLogger::log('media', 'matcher_api_error', 'error', $e->getMessage());
            return array_map(fn($r) => array_merge($r, [
                'relevance_score'  => 50,
                'relevance_reason' => '(erro de IA: ' . substr($e->getMessage(), 0, 100) . ')',
                'niche_match'      => '',
            ]), $batch);
        }
    }

    /**
     * Atualizar status no banco baseado no score.
     */
    private static function update_status(int $id, array $row): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sara_media_opportunities';

        $score  = (int) $row['relevance_score'];
        $status = $score >= self::MIN_RELEVANT_SCORE ? 'matched' : 'rejected';

        $wpdb->update(
            $table,
            [
                'relevance_score'  => $score,
                'relevance_reason' => $row['relevance_reason'],
                'niche_match'      => $row['niche_match'],
                'status'           => $status,
            ],
            ['id' => $id],
            ['%d', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Buscar oportunidades relevantes para exibir no dashboard.
     *
     * @return array Lista de oportunidades com score >= 50
     */
    public static function get_matched(int $limit = 20, string $lang_filter = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sara_media_opportunities';

        // Verificar se tabela existe
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) return [];

        $where = "status = 'matched' AND (user_action IS NULL OR user_action != 'dismissed')";
        $args = [];
        if (in_array($lang_filter, ['en', 'pt'], true)) {
            $where .= " AND lang = %s";
            $args[] = $lang_filter;
        }
        $args[] = $limit;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, source_name, source_type, lang, title, content, link, author,
                    published_at, collected_at, relevance_score, relevance_reason,
                    niche_match, generated_pitch, user_action
             FROM {$table}
             WHERE {$where}
             ORDER BY relevance_score DESC, collected_at DESC
             LIMIT %d",
            ...$args
        ), ARRAY_A) ?: [];
    }

    /**
     * Stats para o dashboard.
     */
    public static function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sara_media_opportunities';

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) return ['total' => 0, 'matched' => 0, 'pending' => 0];

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as n FROM {$table} GROUP BY status",
            ARRAY_A
        );

        $stats = ['total' => 0, 'matched' => 0, 'pending' => 0, 'rejected' => 0];
        foreach ($rows as $r) {
            $stats[$r['status']] = (int)$r['n'];
            $stats['total'] += (int)$r['n'];
        }
        $stats['pending'] = $stats['new'] ?? 0;

        return $stats;
    }
}
