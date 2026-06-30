<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Brain;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\{AutopilotInstaller, AutopilotLogger};

/**
 * SaraPlanner v2.0 — Planejador de conteúdo com anti-clichê, anti-canibalização
 * e geração de 2 títulos distintos por categoria.
 *
 * Adapta o SaraPlanner original adicionando:
 * - SaraApiClient (rate limit + backoff)
 * - SaraTitleValidator (anti-clichê + anti-canibalização)
 * - SaraNicheDetector (nicho automático)
 * - SaraCategoryFilter (2-5 categorias, 2 títulos por cat)
 *
 * @since 1.0.0 (SARA Brain v2.0)
 */
class SaraPlanner {

    private SaraApiClient      $api;
    private SaraTitleValidator $validator;
    private SaraCategoryFilter $cat_filter;
    private SaraIndexer        $indexer;
    private string             $table;
    private string             $site_mode;
    private string             $language;
    private string             $niche;

    /** Títulos aprovados na sessão atual (anti-duplicata interna) */
    private array $session_titles = [];

    public function __construct() {
        $this->api        = new SaraApiClient();
        $this->validator  = new SaraTitleValidator();
        $this->cat_filter = new SaraCategoryFilter();
        $this->indexer    = new SaraIndexer();

        global $wpdb;
        $this->table     = $wpdb->prefix . 'sara_editorial_calendar';
        $this->site_mode = AutopilotInstaller::get('site_mode', 'conservative');
        $this->language  = AutopilotInstaller::get('site_language', 'pt-BR');
        $this->niche     = get_option('sara_niche', 'Tecnologia');
    }

    /**
     * Planejar artigos para amanhã: 2 títulos por categoria ativa.
     * Compatível com o formato do sara_editorial_calendar.
     *
     * @return int Número de artigos planejados
     */
    public function plan_tomorrow(): int {
        $t0       = microtime(true);
        $tomorrow = (new \DateTimeImmutable('tomorrow', \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::timezone()))->format('Y-m-d');
        $lock_key = 'sara_planner_' . $tomorrow;

        if (!$this->acquire_lock($lock_key, 20 * MINUTE_IN_SECONDS)) {
            AutopilotLogger::log('brain', 'planner_skip_locked', 'skip',
                "Planner ja esta em execucao para {$tomorrow}");
            return 0;
        }

        // Verificar se já existe plano para amanhã
        global $wpdb;
        $existing = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE scheduled_date = %s", $tomorrow
        ));
        if ($existing > 0) {
            AutopilotLogger::log('brain', 'planner_skip', 'skip',
                "Plano para {$tomorrow} já existe ({$existing} artigos)");
            $this->release_lock($lock_key);
            return 0;
        }

        // BUG FIX 1.0.0: Pegar SOMENTE categorias estritamente ativas
        // (filtra slugs que não existem mais ou não foram selecionados)
        $active_cats = $this->cat_filter->get_active_categories();
        if (empty($active_cats)) {
            $this->api->brain_log('planner_error: Nenhuma categoria ativa selecionada');
            AutopilotLogger::log('brain', 'planner_error', 'error',
                'Nenhuma categoria ativa — selecione 2-5 categorias em Configurações');
            $this->release_lock($lock_key);
            return 0;
        }

        $this->api->brain_log('planner_start: Categorias ativas: ' . implode(', ', $active_cats));
        AutopilotLogger::log('brain', 'planner_start', 'start',
            'Planejando para categorias: ' . implode(', ', $active_cats));

        // Carregar posts existentes para anti-canibalização
        $existing_posts = get_posts([
            'numberposts' => 200,
            'post_status'  => 'publish',
            'post_type'    => 'post',
        ]);

        $per_cat  = (int)AutopilotInstaller::get('brain_articles_per_category', '2');
        $per_cat  = max(1, min(3, $per_cat));
        $total_expected = max(1, count($active_cats) * $per_cat);
        // Agenda sequencial: 1 artigo por horário, sem sobrepor categorias.
        // Padrão: começa no primeiro horário configurado (ex: 07:00) e avança de 60 em 60 minutos.
        $times    = $this->build_sequential_writer_times($total_expected);
        $planned  = 0;

        foreach ($active_cats as $cat_slug) {
            // BUG FIX 1.0.0: Validar novamente que a categoria é estritamente ativa
            if (!$this->cat_filter->is_active_strict($cat_slug)) {
                $this->api->brain_log("planner_skip: [{$cat_slug}] não está nas ativas, pulando");
                continue;
            }

            $cat_label = $this->cat_filter->get_label($cat_slug);
            $cat_id    = $this->cat_filter->resolve_wp_term_id($cat_slug) ?? 0;

            if ($cat_id === 0) {
                $this->api->brain_log(
                    "planner_warn: [{$cat_slug}] não tem term_id no WP — criar a categoria '{$cat_label}' primeiro"
                );
                AutopilotLogger::log('brain', 'planner_warn', 'warning',
                    "Categoria '{$cat_label}' (slug: {$cat_slug}) não existe no WordPress. " .
                    "Crie a categoria em Posts → Categorias antes de planejar.");
                continue;
            }


            for ($i = 1; $i <= $per_cat; $i++) {
                $plan = $this->plan_one($cat_slug, $cat_label, $cat_id, $i, $existing_posts);
                if (!$plan) continue;

                // Horário sequencial global. Evita dois artigos no mesmo horário,
                // inclusive quando há 2+ artigos por categoria.
                $time = $times[$planned] ?? end($times);

                $wpdb->insert($this->table, [
                    'scheduled_date'     => $tomorrow,
                    'scheduled_time'     => $time,
                    'category_id'        => $cat_id,
                    'category_name'      => $cat_label,
                    'title'              => $plan['title'],
                    'keyword'            => $plan['keyword'],
                    'search_intent'      => $plan['search_intent'] ?? 'informacional',
                    'content_type'       => $plan['content_type'] ?? 'cluster',
                    'word_count_target'  => $plan['word_count_target'] ?? $this->default_word_count(),
                    'outline'            => wp_json_encode($plan['outline'] ?? []),
                    'entities'           => wp_json_encode($plan['entities'] ?? []),
                    'secondary_keywords' => wp_json_encode($plan['secondary_keywords'] ?? []),
                    'lsi_keywords'       => wp_json_encode($plan['lsi_keywords'] ?? []),
                    'external_sources'   => wp_json_encode($plan['external_sources'] ?? []),
                    'internal_links'     => wp_json_encode($plan['internal_links'] ?? []),
                    'eeat_angle'         => $plan['eeat_angle'] ?? 'experiencia_pratica',
                    'schema_type'        => $plan['schema_type'] ?? 'Article',
                    'priority'           => 5,
                    'status'             => 'pending',
                    'planned_by'         => 'sara_brain_v2',
                ]);

                $this->session_titles[] = $plan['title'];
                $planned++;

                $cost = 0.000800;
                $this->api->brain_log(
                    "planner_one: Planejado: \"{$plan['title']}\" [{$cat_slug} @ {$time}] (\${$cost})"
                );
            }
        }

        // Agendar Writer jobs
        \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::schedule_writer_jobs();

        $this->api->brain_log(
            "planner_done: Planejados {$planned} artigos para {$tomorrow}"
        );

        AutopilotLogger::log('brain', 'planner_done', 'success',
            "Planejados {$planned} artigos para {$tomorrow}", [
                'duration_ms' => AutopilotLogger::elapsed($t0),
            ]
        );

        $this->release_lock($lock_key);
        return $planned;
    }

    private function acquire_lock(string $key, int $ttl): bool {
        $option = '_geo_lock_' . sanitize_key($key);
        $now = time();
        $expires = (int) get_option($option, 0);
        if ($expires > $now) return false;
        if (add_option($option, $now + $ttl, '', 'no')) return true;
        $expires = (int) get_option($option, 0);
        if ($expires <= $now) {
            update_option($option, $now + $ttl, false);
            return true;
        }
        return false;
    }

    private function release_lock(string $key): void {
        delete_option('_geo_lock_' . sanitize_key($key));
    }

    /**
     * Planejar 1 artigo com validação completa (anti-clichê + anti-canibalização + anti-duplicata).
     * Máximo 4 tentativas por artigo (conforme spec).
     *
     * @since 1.0.0 BUG FIX: usar fallback inteligente quando IA falha 4x
     */
    private function plan_one(
        string $cat_slug,
        string $cat_label,
        int    $cat_id,
        int    $attempt_number,
        array  $existing_posts
    ): ?array {
        $max_attempts = 6;
        $year         = wp_date('Y');

        // Histórico de ângulos usados (para evitar repetição)
        $angle_history = $this->get_angle_history($cat_id);

        // Títulos já publicados nesta categoria
        $covered = $this->indexer->covered_keywords($cat_id);
        $existing_titles = implode("\n", array_slice($covered, 0, 20))
            ?: 'Nenhum conteúdo publicado ainda.';

        for ($try = 1; $try <= $max_attempts; $try++) {
            $this->api->brain_log("planner_one: [{$cat_slug}] Tentativa {$try}/{$max_attempts}...");

            // Construir prompt com o template do arquivo
            $prompt = $this->build_v2_prompt(
                $cat_slug, $cat_label, $existing_titles, $angle_history, $year, $try
            );

            // Chamada IA com backoff (SaraApiClient)
            $raw = $this->api->request_with_backoff($prompt, $cat_slug);

            // Limpar título
            $title = $this->validator->clean_title($raw);
            if ($title === '' || mb_strlen($title) < 10) {
                $this->api->brain_log("planner_one: [{$cat_slug}] IA retornou título vazio/curto, nova tentativa");
                continue;
            }

            // Validar anti-clichê
            if (!$this->validator->validate($title)) continue;

            // Anti-canibalização com posts existentes do WP
            $cannib = $this->validator->check_cannibalization($title, $existing_posts);
            if ($cannib['is_duplicate']) {
                $this->api->brain_log(
                    "planner_one: [{$cat_slug}] Canibalização: {$cannib['reason']}"
                );
                continue;
            }

            // Anti-duplicata interna (mesma sessão)
            if ($this->validator->is_duplicate_in_session($title, $this->session_titles)) {
                $this->api->brain_log("planner_one: [{$cat_slug}] Duplicata interna, nova tentativa");
                continue;
            }

            // ✅ Título aprovado — montar plano completo
            return $this->build_plan_from_title($title, $cat_id, $cat_label, $year, $attempt_number);
        }

        // BUG FIX 1.0.0: Após 4 falhas, usar fallback inteligente em vez de retornar null
        // Isso garante que sempre haja um título único — mesmo que de fallback
        $this->api->brain_log("planner_one: [{$cat_slug}] Falha após {$max_attempts} tentativas — usando fallback inteligente");

        for ($fb_try = 1; $fb_try <= 5; $fb_try++) {
            $fallback_title = $this->generate_smart_fallback($cat_slug, $cat_label, $year, $fb_try);

            // Validar até o fallback
            if (!$this->validator->validate($fallback_title)) continue;

            $cannib = $this->validator->check_cannibalization($fallback_title, $existing_posts);
            if ($cannib['is_duplicate']) continue;

            if ($this->validator->is_duplicate_in_session($fallback_title, $this->session_titles)) continue;

            $this->api->brain_log("planner_one: [{$cat_slug}] Fallback aceito: {$fallback_title}");
            return $this->build_plan_from_title($fallback_title, $cat_id, $cat_label, $year, $attempt_number);
        }

        $this->api->brain_log("planner_one: [{$cat_slug}] Falha total — pulando esta categoria");
        return null;
    }

    /**
     * Construir o array de plano a partir de um título aprovado.
     * Extraído de plan_one para reutilização (IA + fallback).
     *
     * @since 1.0.0
     */
    private function build_plan_from_title(
        string $title,
        int    $cat_id,
        string $cat_label,
        string $year,
        int    $attempt_number
    ): array {
        $keyword  = $this->validator->extract_main_keyword($title);
        $related  = $this->indexer->find_related($keyword, 3, $cat_id);

        return [
            'title'             => $title,
            'keyword'           => $keyword,
            'search_intent'     => $this->detect_intent($title),
            'content_type'      => $attempt_number === 1 ? 'pillar' : 'cluster',
            'word_count_target' => $this->default_word_count(),
            'outline'           => $this->auto_outline($title, $cat_label, $year),
            'entities'          => [$this->niche, $cat_label],
            'secondary_keywords'=> [$keyword . ' ' . $year, 'como ' . $keyword],
            'lsi_keywords'      => [],
            // As fontes NÃO são escolhidas aqui (plano é pré-conteúdo, só temos a
            // keyword e casaria fonte fora de contexto). A fonte oficial real é
            // inserida depois pelo SaraGlobalPostProcessor, que analisa o CONTEÚDO
            // gerado e só adiciona fonte de entidade realmente citada.
            'external_sources'  => [],
            'internal_links'    => array_map(fn($r) => [
                'target_post_id' => $r['post_id'],
                'url'            => $r['url'],
                'anchor_text'    => $r['title'],
            ], $related),
            'eeat_angle'        => 'experiencia_pratica',
            'schema_type'       => str_starts_with(strtolower($title), 'como') ? 'HowTo' : 'Article',
        ];
    }

    /**
     * Gerar título de fallback inteligente — usa o nicho + categoria + variantes.
     * Tem MUITAS variantes para evitar duplicata em chamadas seguidas.
     *
     * @since 1.0.0
     */
    private function generate_smart_fallback(string $cat_slug, string $cat_label, string $year, int $variant): string {
        // Fallback editorial: natural, profissional, long-tail e sem clickbait exagerado.
        $templates = [
            "Como aplicar {CAT} em sites de {NICHO} sem perder qualidade editorial",
            "{CAT} para {NICHO}: o que avaliar antes de automatizar conteúdo em {YEAR}",
            "Quando vale usar {CAT} e quais cuidados evitam queda de qualidade",
            "{CAT} no WordPress: ajustes práticos para melhorar SEO e experiência do leitor",
            "Estratégia de {CAT}: como organizar conteúdo para Google, IA e Discover",
            "{CAT} em {YEAR}: mudanças importantes para sites que publicam todos os dias",
            "Como medir resultados de {CAT} sem depender apenas de volume de posts",
            "{CAT} para notícias: estrutura, atualização e sinais de confiança editorial",
            "Diferenças entre {CAT} básico e uma operação profissional de conteúdo",
            "Como revisar {CAT} com foco em originalidade, fontes e intenção de busca",
        ];

        $seed = (int) hexdec(substr(md5($cat_slug . $variant . $year), 0, 8));
        $tpl  = $templates[$seed % count($templates)];

        $title = str_replace(
            ['{CAT}', '{YEAR}', '{NICHO}'],
            [$cat_label, $year, $this->niche],
            $tpl
        );

        if (mb_strlen($title) > 105) {
            $title = rtrim(mb_substr($title, 0, 102)) . '...';
        }

        return $title;
    }

    /**
     * Construir prompt v2 usando o arquivo planner-prompt.txt.
     * Substitui as variáveis com contexto real do site.
     */
    private function build_v2_prompt(
        string $cat_slug,
        string $cat_label,
        string $existing_titles,
        string $angle_history,
        string $year,
        int    $attempt
    ): string {
        $prompt_file = GEO_METODO_SEO_PATH . 'sara-autopilot/prompts/brain/planner-prompt.txt';
        $base        = file_exists($prompt_file) ? file_get_contents($prompt_file) : '';

        $lang_inst = $this->language === 'pt-BR'
            ? 'Responda APENAS em Português do Brasil. Retorne APENAS o título, sem aspas, sem explicações.'
            : 'Respond ONLY in ' . $this->language . '. Return ONLY the title.';

        // Variação de ângulos naturais para evitar títulos artificiais e repetitivos.
        $angle_variants = [
            1 => 'problema específico do leitor com resposta prática',
            2 => 'comparação ou decisão entre caminhos/ferramentas',
            3 => 'atualização/tendência relevante para ' . $year,
            4 => 'tutorial específico sem parecer genérico',
            5 => 'análise editorial com foco em confiança, qualidade e fontes',
            6 => 'checklist profissional, usando número apenas se fizer sentido',
        ];
        $angle_hint = $angle_variants[$attempt] ?? $angle_variants[1];

        $number_inst = "
Use número somente se o título ficar natural. Priorize long-tail, entidade específica e intenção clara.";

        return str_replace(
            ['[NICHO_DETECTADO]', '[CATEGORIA]', '[NICHO]',
             '[LISTA_ULTIMOS_5_TITULOS]', '[ANGULOS_HISTORICOS]'],
            [$this->niche, $cat_label, $this->niche,
             $existing_titles, $angle_history],
            $base
        )
        . "\n\n{$lang_inst}"
        . "\n\nAno atual: {$year}"
        . "\n\nÂngulo sugerido para esta tentativa: {$angle_hint}"
        . $number_inst
        . "\n\nTítulo precisa parecer natural, profissional e publicável em um portal sério. Evite clickbait, porcentagens inventadas e promessas exageradas."
        . "\nPode usar formato 'Como...', 'O que...', 'Quando...' ou comparação, desde que o título seja específico e útil."
        . "\nNão repita exatamente os títulos existentes. Gere uma pauta nova dentro da categoria."
        . "\n\nRetorne APENAS o título. Sem aspas. Sem explicações.";
    }

    /** Gerar outline básico automaticamente (para o SaraWriter usar) */
    private function auto_outline(string $title, string $cat_label, string $year): array {
        return [
            ['level' => 'H2', 'title' => "O que é {$cat_label} em {$year}", 'h3s' => []],
            ['level' => 'H2', 'title' => "Análise Detalhada", 'h3s' => []],
            ['level' => 'H2', 'title' => "Como Aplicar na Prática", 'h3s' => []],
            ['level' => 'H2', 'title' => "Erros Comuns e Como Evitar", 'h3s' => []],
            ['level' => 'H2', 'title' => "Perguntas Frequentes", 'h3s' => []],
        ];
    }

    /** Buscar histórico de ângulos usados para esta categoria (evitar repetição) */
    private function get_angle_history(int $cat_id): string {
        if (!$cat_id) return '';
        global $wpdb;
        $titles = $wpdb->get_col($wpdb->prepare(
            "SELECT title FROM {$this->table}
             WHERE category_id = %d AND status = 'done'
             ORDER BY created_at DESC LIMIT 10",
            $cat_id
        ));
        return $titles ? implode("\n", $titles) : '';
    }

    private function detect_intent(string $title): string {
        $t = mb_strtolower($title);
        if (preg_match('/^como\s|tutorial|passo/u', $t)) return 'informacional';
        if (preg_match('/compar|vs\.?|melhor|qual/u', $t)) return 'comercial';
        if (preg_match('/comprar|pre[çc]o|desconto/u', $t)) return 'transacional';
        return 'informacional';
    }

    private function default_word_count(): int {
        return $this->site_mode === 'conservative' ? 3000 : 4000;
    }


    /**
     * Monta horários sequenciais para o Writer.
     * Exemplo: configurado 07:00 + intervalo 60 => 07:00, 08:00, 09:00...
     * Isso corrige o comportamento antigo 07:00, 09:00, 11:00 quando havia muitos artigos.
     */
    private function build_sequential_writer_times(int $count): array {
        $base_times = $this->get_writer_times();
        $start      = $base_times[0] ?? '07:00:00';
        $interval   = (int) AutopilotInstaller::get('writer_interval_minutes', '60');
        $interval   = max(15, min(240, $interval));
        $tz = \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::timezone();
        $today_sp = \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::today_date();
        try {
            $start_dt = new \DateTimeImmutable($today_sp . ' ' . $start, $tz);
        } catch (\Exception $e) {
            $start_dt = new \DateTimeImmutable($today_sp . ' 07:00:00', $tz);
        }

        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $times[] = $start_dt->modify('+' . ($i * $interval) . ' minutes')->format('H:i:s');
        }
        return $times;
    }

    private function get_writer_times(): array {
        $raw = AutopilotInstaller::get('writer_schedule_times', '["07:00","09:00","11:00"]');
        // Usa o parser robusto do ScheduleManager (aceita JSON ou string corrompida)
        $arr = \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::parse_writer_times($raw);
        return array_map(fn($t) => $t . ':00', $arr);
    }
}
