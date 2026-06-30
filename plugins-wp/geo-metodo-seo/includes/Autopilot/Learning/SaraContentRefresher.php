<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Learning;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;
use GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller;
use GeoMetodoSEO\Autopilot\Indexing\SaraIndexNow;

/**
 * SaraContentRefresher — Atualiza artigos antigos para mantê-los relevantes.
 *
 * IMPORTANTE: este módulo segue as práticas legítimas de "Helpful Content updates"
 * recomendadas pelo próprio Google. Ele NÃO faz:
 * - Republish em massa para fingir conteúdo novo (cloaking)
 * - Mudança apenas de data sem mudança de conteúdo (date manipulation)
 * - Geração automatizada sem critério (scaled content abuse)
 *
 * O que ele FAZ:
 * - Identifica posts antigos (>= 60 dias) que provavelmente estão desatualizados
 * - Pede à IA para sugerir 1-2 atualizações relevantes (novos dados, exemplos atuais)
 * - Atualiza o conteúdo existente, mantendo URL/slug/título
 * - Adiciona uma nota sutil de "Atualizado em <data>" no final
 * - Re-submete via IndexNow para re-crawling
 *
 * Critério de seleção (sem GSC):
 * - post_status = publish
 * - data de publicação > 60 dias atrás
 * - data da última modificação > 30 dias atrás (não atualizar quem já foi atualizado)
 * - max 2 posts por execução (semanal) — evita spam de updates
 *
 * Quando você ativar GSC depois, o critério vira: CTR < 2% AND posição 11-30
 * (oportunidades reais de subir para o top 10).
 *
 * @since 1.0.0
 */
class SaraContentRefresher {

    /** Limite de posts atualizados por execução */
    private const MAX_PER_RUN = 5;

    /** Idade mínima do post para ser candidato (dias) */
    private const MIN_AGE_DAYS = 120;

    /** Tempo mínimo desde último update (dias) — evita atualizar quem foi atualizado recentemente */
    private const MIN_DAYS_SINCE_UPDATE = 30;

    /** Option name para enable/disable */
    private const ENABLED_OPTION = 'sara_refresher_enabled';

    /**
     * Bootstrap: registrar cron weekly.
     */
    public static function register(): void {
        add_filter('cron_schedules', function(array $schedules): array {
            if (!isset($schedules['weekly'])) {
                $schedules['weekly'] = ['interval' => WEEK_IN_SECONDS, 'display' => 'Uma vez por semana'];
            }
            return $schedules;
        });
        add_action('sara_content_refresher_run', [__CLASS__, 'run']);

        if (!wp_next_scheduled('sara_content_refresher_run')) {
            // Domingo às 03:00 (servidor)
            $next_sunday_3am = strtotime('next sunday 03:00');
            wp_schedule_event($next_sunday_3am, 'weekly', 'sara_content_refresher_run');
        }
    }

    public static function is_enabled(): bool {
        return get_option(self::ENABLED_OPTION, '0') === '1';
    }

    public static function set_enabled(bool $on): void {
        update_option(self::ENABLED_OPTION, $on ? '1' : '0');
    }

    /**
     * Executar uma rodada de refresh.
     * Pode ser chamado pelo cron OU manualmente via dashboard.
     *
     * @return array Estatísticas: ['candidates_found', 'refreshed', 'failed', 'duration_ms']
     */
    public static function run(): array {
        $t0 = microtime(true);

        // GATE DE LICENÇA: refresher reescreve conteúdo com IA → também para se vencer.
        if (!\GeoMetodoSEO\License\LicenseManager::canGenerate()) {
            AutopilotLogger::log('system', 'refresher_license_blocked', 'error',
                'Content Refresher bloqueado: licença expirada/inativa.');
            return ['candidates_found' => 0, 'refreshed' => 0, 'failed' => 0, 'duration_ms' => 0];
        }

        if (!self::is_enabled()) {
            AutopilotLogger::log('system', 'refresher_skip', 'skip',
                'Refresher desabilitado nas configurações');
            return ['candidates_found' => 0, 'refreshed' => 0, 'failed' => 0, 'duration_ms' => 0];
        }

        $candidates = self::find_candidates();
        if (empty($candidates)) {
            AutopilotLogger::log('system', 'refresher_no_candidates', 'skip',
                'Nenhum post candidato a refresh (precisa idade ≥ ' . self::min_age_days() . ' dias)');
            return ['candidates_found' => 0, 'refreshed' => 0, 'failed' => 0, 'duration_ms' => 0];
        }

        AutopilotLogger::log('system', 'refresher_start', 'start',
            'Refresher iniciado: ' . count($candidates) . ' candidatos, processando até ' . self::max_per_run());

        $refreshed = 0;
        $failed    = 0;

        foreach ($candidates as $post) {
            try {
                if (self::refresh_post((int)$post->ID)) {
                    $refreshed++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                AutopilotLogger::log('system', 'refresher_error', 'error',
                    "Erro no post #{$post->ID}: " . $e->getMessage());
            }
        }

        $duration_ms = (int) ((microtime(true) - $t0) * 1000);

        AutopilotLogger::log('system', 'refresher_done', 'success',
            "Refresher concluído: {$refreshed} atualizados, {$failed} falharam",
            ['duration_ms' => $duration_ms]
        );

        return [
            'candidates_found' => count($candidates),
            'refreshed'        => $refreshed,
            'failed'           => $failed,
            'duration_ms'      => $duration_ms,
        ];
    }

    /**
     * Buscar posts candidatos para atualização.
     *
     * Estratégia em 2 níveis (v1.0.0):
     *   1) Se GSC estiver configurado → priorizar posts com baixa performance real
     *      (CTR < 2% AND posição 11-30 — oportunidades de subir para top 10)
     *   2) Fallback temporal → idade >= 60 dias, última modificação >= 30 dias atrás
     *
     * O critério baseado em GSC é MUITO superior porque atua só onde dá ROI.
     */
    private static function find_candidates(): array {
        // Tentar GSC primeiro
        $gsc_candidates = self::find_candidates_via_gsc();
        if (!empty($gsc_candidates)) {
            AutopilotLogger::log('system', 'refresher_gsc_mode', 'success',
                'Usando GSC: ' . count($gsc_candidates) . ' posts com baixa performance encontrados');
            return $gsc_candidates;
        }

        // Fallback: critério temporal
        return self::find_candidates_temporal();
    }

    /**
     * Buscar candidatos via Google Search Console (quando configurado).
     * Retorna posts com posição 11-30 e CTR < 2% (oportunidades reais de subir).
     *
     * @since 1.0.0  Inicial (com Service Account — depois descartado)
     * @since 1.0.0  Reescrito para usar SearchConsoleService existente (OAuth)
     * @return \WP_Post[] Lista de posts a refreshar (vazia se GSC não estiver disponível)
     */
    private static function find_candidates_via_gsc(): array {
        if (!class_exists('\GeoMetodoSEO\SEO\SearchConsoleService')) return [];

        $gsc = new \GeoMetodoSEO\SEO\SearchConsoleService();
        if (!$gsc->is_connected()) return [];

        // Pegar top 100 páginas dos últimos 28 dias
        $top_pages = $gsc->get_top_pages(28, 100);
        if (empty($top_pages)) return [];

        // Filtrar oportunidades: posição 11-30, CTR baixo, com volume mínimo
        $candidates = [];
        foreach ($top_pages as $row) {
            // Critérios de oportunidade (oportunidade real de subir para top 10)
            if ((int)$row['post_id'] <= 0)             continue; // só conteúdos WP detectáveis por URL
            if ((int)$row['impressions'] < 10)         continue; // precisa algum volume
            if ((float)$row['ctr'] > 5.0 && (float)$row['position'] <= 10.0) continue; // já está bem
            if ((float)$row['position'] < 4.0 && (float)$row['ctr'] > 2.0) continue; // topo com CTR aceitável
            if ((float)$row['position'] > 60.0)        continue; // muito distante — baixa prioridade

            $candidates[] = $row;
        }

        if (empty($candidates)) return [];

        // Ordenar por melhor oportunidade (mais impressões / mais perto da página 1)
        usort($candidates, function($a, $b) {
            $score_a = (int)$a['impressions'] / max(0.5, (float)$a['position'] - 10);
            $score_b = (int)$b['impressions'] / max(0.5, (float)$b['position'] - 10);
            return $score_b <=> $score_a;
        });

        // Resolver para WP_Post + filtros (no-refresh meta + último update)
        $posts = [];
        foreach ($candidates as $row) {
            if (count($posts) >= self::max_per_run()) break;

            $post_id = (int)$row['post_id'];
            $post    = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') continue;
            if (!in_array($post->post_type, ['post', 'geo_glossary'], true)) continue;

            // Respeitar opt-out
            if (get_post_meta($post_id, '_sara_no_refresh', true) === '1') continue;

            // Não atualizar quem foi atualizado nos últimos 30 dias
            $modified_ts = strtotime($post->post_modified);
            if ($modified_ts > strtotime('-' . self::MIN_DAYS_SINCE_UPDATE . ' days')) continue;

            // Salvar dados do GSC como meta (consumidos depois pelo prompt do refresh)
            update_post_meta($post_id, '_sara_gsc_position',    (float)$row['position']);
            update_post_meta($post_id, '_sara_gsc_ctr',         (float)$row['ctr']);
            update_post_meta($post_id, '_sara_gsc_impressions', (int)$row['impressions']);
            update_post_meta($post_id, '_sara_gsc_opportunity', (string)$row['opportunity']);

            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * Critério temporal (fallback quando não tem GSC).
     */
    private static function find_candidates_temporal(): array {
        $args = [
            'post_type'      => ['post', 'geo_glossary'],
            'post_status'    => 'publish',
            'posts_per_page' => max(self::max_per_run(), 20),
            'orderby'        => 'modified',
            'order'          => 'ASC',
            'date_query'     => [
                [
                    'column' => 'post_date',
                    'before' => self::min_age_days() . ' days ago',
                ],
                [
                    'column' => 'post_modified',
                    'before' => self::MIN_DAYS_SINCE_UPDATE . ' days ago',
                ],
            ],
            // Não atualizar posts marcados como "no-refresh"
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key'     => '_sara_no_refresh',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_sara_no_refresh',
                    'value'   => '1',
                    'compare' => '!=',
                ],
            ],
        ];

        return get_posts($args);
    }

    /**
     * Preview seguro dos artigos candidatos para o painel.
     * Não altera conteúdo; apenas mostra ao usuário o que pode ser reescrito.
     */
    public static function get_candidate_preview(int $limit = 20): array {
        $limit = max(1, min(50, $limit));
        $posts = self::find_candidates();
        if (count($posts) < $limit) {
            $extra = self::find_candidates_temporal();
            $seen = [];
            foreach ($posts as $p) { if ($p instanceof \WP_Post) $seen[(int)$p->ID] = true; }
            foreach ($extra as $p) {
                if (!$p instanceof \WP_Post) continue;
                if (!isset($seen[(int)$p->ID])) {
                    $posts[] = $p;
                    $seen[(int)$p->ID] = true;
                }
                if (count($posts) >= $limit) break;
            }
        }
        $posts = array_slice($posts, 0, $limit);

        $items = [];
        foreach (array_slice($posts, 0, $limit) as $post) {
            if (!$post instanceof \WP_Post) continue;
            $age_days = (int) floor((current_time('timestamp') - strtotime($post->post_date)) / DAY_IN_SECONDS);
            $items[] = [
                'id'                 => (int) $post->ID,
                'title'              => get_the_title($post),
                'age_days'           => $age_days,
                'modified'           => get_date_from_gmt(get_gmt_from_date($post->post_modified), 'Y-m-d H:i'),
                'has_featured_image' => has_post_thumbnail($post->ID),
                'featured_id'        => (int) get_post_thumbnail_id($post->ID),
                'content_images'     => substr_count((string) $post->post_content, '<img'),
                'permalink'          => get_permalink($post->ID),
                'edit_link'          => get_edit_post_link($post->ID, ''),
            ];
        }
        return $items;
    }

    /**
     * Atualizar 1 post específico.
     * Mantém URL/slug/título, adiciona conteúdo relevante, marca data de update.
     *
     * @return bool true se atualizado com sucesso
     */
    public static function refresh_post(int $post_id): bool {
        if (!\GeoMetodoSEO\License\LicenseManager::canGenerate()) {
            return false;
        }
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') return false;
        if (!in_array($post->post_type, ['post', 'geo_glossary'], true)) return false;

        $title       = $post->post_title;
        $content     = $post->post_content;
        $featured_id = (int) get_post_thumbnail_id($post_id);
        $original_kw = self::extract_keyword($post);

        // Pedir à IA uma atualização relevante (1-2 parágrafos novos com dados atuais)
        // 1.0.0: passa post_id para acessar dados do GSC se disponíveis
        $update_html = self::generate_update_section($title, $original_kw, $content, $post_id);
        if (!$update_html) {
            AutopilotLogger::log('system', 'refresher_skip_post', 'skip',
                "Post #{$post_id}: IA não retornou atualização relevante");
            return false;
        }

        // Remover bloco de update anterior se existir (evitar acumular)
        $content_clean = preg_replace(
            '/<!-- sara-refresh-block-start -->.*?<!-- sara-refresh-block-end -->/su',
            '',
            $content
        );

        // Inserir novo bloco — antes do bloco de "Artigos Relacionados" se existir,
        // ou no fim do conteúdo principal
        $marker = '<section class="sara-related-articles"';
        if (strpos($content_clean, $marker) !== false) {
            $new_content = str_replace($marker, $update_html . "\n\n" . $marker, $content_clean);
        } else {
            $new_content = $content_clean . "\n\n" . $update_html;
        }

        // Atualizar post — IMPORTANTE: NÃO mudar post_date (manter publicação original)
        // Apenas post_modified vai ser atualizado automaticamente.
        $result = \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
            'ID'           => $post_id,
            'post_content' => $new_content,
        ], true);

        if (is_wp_error($result)) {
            AutopilotLogger::log('system', 'refresher_db_error', 'error',
                "Falha ao atualizar #{$post_id}: " . $result->get_error_message());
            return false;
        }

        // Preservar imagem destacada original explicitamente.
        if ($featured_id > 0 && (int) get_post_thumbnail_id($post_id) !== $featured_id) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::set_featured_image($post_id, $featured_id);
        }

        // Marcar metadata
        update_post_meta($post_id, '_sara_last_refresh', current_time('mysql'));
        update_post_meta($post_id, '_sara_refresh_count',
            (int) get_post_meta($post_id, '_sara_refresh_count', true) + 1);

        // Re-submeter ao IndexNow se ativo
        if (class_exists(SaraIndexNow::class) && SaraIndexNow::is_enabled()) {
            SaraIndexNow::add_to_buffer(get_permalink($post_id));
        }

        AutopilotLogger::log('system', 'refresher_done_post', 'success',
            "Post #{$post_id} atualizado: {$title}");

        return true;
    }

    /**
     * Gerar bloco de atualização via IA.
     * O bloco contém: dados/exemplos atualizados + nota de "Atualizado em".
     *
     * @since 1.0.0 Aceita post_id para usar dados do GSC (se disponíveis) e guiar o refresh
     */
    private static function generate_update_section(string $title, string $keyword, string $original_content, int $post_id = 0): string {
        // Verificar se a classe de IA do plugin está disponível
        if (!class_exists('GeoMetodoSEO\Autopilot\Brain\SaraApiClient')) {
            return '';
        }

        $year      = date('Y');
        $month     = self::month_pt((int) date('n'));
        $excerpt   = mb_substr(strip_tags($original_content), 0, 1500);

        // Pegar dados do GSC se disponíveis (vindos de find_candidates_via_gsc)
        $gsc_hint = '';
        if ($post_id > 0) {
            $position    = get_post_meta($post_id, '_sara_gsc_position', true);
            $ctr         = get_post_meta($post_id, '_sara_gsc_ctr', true);
            $impressions = get_post_meta($post_id, '_sara_gsc_impressions', true);

            if ($position && $ctr) {
                $gsc_hint = "\n\nDADOS REAIS DO GOOGLE SEARCH CONSOLE (últimos 28 dias):\n"
                          . "- Posição média: {$position} (precisa subir para top 10)\n"
                          . "- CTR atual: {$ctr}% (baixo — precisa de título mais atraente nos H2s)\n"
                          . "- Impressões: {$impressions}\n\n"
                          . "ESTRATÉGIA: Como o artigo está na página 2 do Google, foque em:\n"
                          . "- Adicionar dados/números que faltam vs concorrentes do top 10\n"
                          . "- H2 com palavra-chave nova de cauda longa\n"
                          . "- Cobrir uma sub-pergunta que o artigo original não responde";
            }
        }

        $prompt = <<<PROMPT
Você é um editor de conteúdo SEO especializado em atualizações relevantes.

ARTIGO ORIGINAL (resumo):
Título: {$title}
Keyword principal: {$keyword}
Trecho do conteúdo: {$excerpt}{$gsc_hint}

TAREFA:
Escreva UMA seção HTML curta (300-450 palavras) com o título "Atualização de {$month}/{$year}".
A seção deve trazer:
1. Algo realmente útil que complemente o artigo original
2. Exemplos práticos, contexto atual ou orientações editoriais seguras
3. Tom natural — sem clichês ("é fundamental", "é essencial", "à frente da curva")

REGRAS DE CONFIANÇA:
- NÃO invente preço, data, estatística, pesquisa, lançamento, fonte, estudo, versão de software ou especificação técnica.
- Se o contexto não confirmar um fato, escreva de forma cautelosa e geral, sem apresentar como dado confirmado.
- Não altere a tese central do artigo. Não prometa resultado garantido.
- Não crie bloco de autor, imagem, legenda, link externo falso nem FAQ duplicado.

REGRAS TÉCNICAS:
- HTML puro: <h2>, <p>, <ul><li>, sem markdown
- NÃO repita conteúdo do artigo original
- NÃO use frases vazias
- Termine SEM call-to-action genérico

RETORNE APENAS O HTML, sem explicação.
PROMPT;

        try {
            $api = new \GeoMetodoSEO\Autopilot\Brain\SaraApiClient();
            // Usar o método mais barato que existe — pode ser request_with_backoff ou direct
            $response = '';

            if (method_exists($api, 'simple_complete')) {
                $response = $api->simple_complete($prompt);
            } elseif (method_exists($api, 'request_with_backoff')) {
                $response = $api->request_with_backoff($prompt, 'refresher');
            } else {
                return '';
            }

            $response = trim((string) $response);
            if (strlen($response) < 200) return '';

            // Limpar code fences se vierem
            $response = preg_replace('/^```html?\s*/i', '', $response);
            $response = preg_replace('/```\s*$/i', '', $response);
            $response = trim($response);

            // Envolver em marker para futuro reset
            $today = current_time('d/m/Y');
            $block = "<!-- sara-refresh-block-start -->\n"
                   . '<aside class="sara-refresh-block" style="margin:32px 0;padding:20px;background:#f0fdf4;border-left:4px solid #16a34a;border-radius:8px;">'
                   . "\n" . $response . "\n"
                   . '<p style="margin:16px 0 0;color:#166534;font-size:13px;font-style:italic;">📅 Atualizado em ' . esc_html($today) . '</p>'
                   . '</aside>'
                   . "\n<!-- sara-refresh-block-end -->";

            return $block;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Extrair keyword principal de um post (do meta ou do título).
     */
    private static function extract_keyword(\WP_Post $post): string {
        // Tentar meta _yoast_wpseo_focuskw ou rank_math_focus_keyword
        $kw = get_post_meta($post->ID, '_yoast_wpseo_focuskw', true)
            ?: get_post_meta($post->ID, 'rank_math_focus_keyword', true);

        if (!empty($kw)) return (string)$kw;

        // Fallback: usar primeiras palavras do título sem stopwords
        $stopwords = ['de','do','da','dos','das','e','o','a','os','as','em','no','na',
                      'por','para','com','que','um','uma','como','sobre','seu','sua'];
        $words = explode(' ', mb_strtolower($post->post_title));
        $sig   = array_filter($words, fn($w) => !in_array($w, $stopwords) && mb_strlen($w) > 3);
        return implode(' ', array_slice(array_values($sig), 0, 3));
    }

    private static function min_age_days(): int {
        return max(30, (int) AutopilotInstaller::get('refresher_min_age_days', self::MIN_AGE_DAYS));
    }

    private static function max_per_run(): int {
        return max(1, min(10, (int) AutopilotInstaller::get('refresher_max_per_run', self::MAX_PER_RUN)));
    }

    private static function month_pt($n): string {
        $n = max(1, min(12, (int) $n));
        $meses = [1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',5=>'maio',6=>'junho',
                  7=>'julho',8=>'agosto',9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'];
        return $meses[$n] ?? 'mês';
    }

    /**
     * Status para o dashboard.
     */
    public static function get_status(): array {
        $next = wp_next_scheduled('sara_content_refresher_run');
        return [
            'enabled'     => self::is_enabled(),
            'next_run'    => $next ? date('Y-m-d H:i', $next) : null,
            'candidates'  => count(self::find_candidates()),
            'min_age'     => self::min_age_days(),
            'max_per_run' => self::max_per_run(),
        ];
    }
}
