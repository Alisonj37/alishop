<?php
namespace GeoMetodoSEO\Glossary;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\Services\ContentFormatter;
use GeoMetodoSEO\Services\ImageGeneratorService;
use GeoMetodoSEO\Services\LogService;
use GeoMetodoSEO\Autopilot\Writer\SaraDeepFAQGenerator;
use GeoMetodoSEO\EEAT\EEATEngine;
use GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher;

class GeoGlossaryService {
    const CPT = 'geo_glossary';
    const TAX = 'geo_glossary_letter';
    const OPT_TITLES = 'geo_glossary_titles_v1';
    const OPT_PAGE_ID = 'geo_glossary_page_id';

    public static function register_hooks(): void {
        add_action('init', [__CLASS__, 'register_post_type_and_taxonomy'], 9);
        add_shortcode('geo_glossario_seo', [__CLASS__, 'render_glossary_shortcode']);
        add_action('admin_init', [__CLASS__, 'maybe_create_glossary_page']);
        add_action('admin_init', [__CLASS__, 'ensure_glossary_defaults']);
        add_action('wp_head', [__CLASS__, 'output_glossary_schema'], 20);
    }

    public static function register_post_type_and_taxonomy(): void {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'GEO Glossário SEO',
                'singular_name' => 'Glossário SEO',
                'add_new_item' => 'Adicionar termo do glossário',
                'edit_item' => 'Editar termo do glossário',
                'menu_name' => 'GEO Glossário SEO',
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => 'glossario-seo'],
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'author'],
            'capability_type' => 'post',
        ]);

        register_taxonomy(self::TAX, [self::CPT], [
            'labels' => [
                'name' => 'Letras do Glossário',
                'singular_name' => 'Letra do Glossário',
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_rest' => true,
            'hierarchical' => true,
            'rewrite' => ['slug' => 'glossario-letra'],
        ]);

        foreach (range('A', 'Z') as $letter) {
            if (!term_exists($letter, self::TAX)) {
                wp_insert_term($letter, self::TAX, ['slug' => strtolower($letter)]);
            }
        }
    }

    public static function maybe_create_glossary_page(): void {
        if (!current_user_can('manage_options')) return;
        $page_id = (int) get_option(self::OPT_PAGE_ID, 0);
        if ($page_id && get_post($page_id)) return;

        $existing = get_page_by_path('geo-glossario-seo');
        if ($existing && $existing->post_status !== 'trash') {
            update_option(self::OPT_PAGE_ID, (int) $existing->ID, false);
            return;
        }

        $result = GeoMetodoSEO_Publisher::publish([
            'title' => 'GEO Glossário SEO',
            'post_name' => 'geo-glossario-seo',
            'status' => 'publish',
            'post_type' => 'page',
            'content' => '[geo_glossario_seo]',
            'source_module' => 'geo_glossary_page',
        ]);
        $id = !empty($result['success']) ? (int)$result['post_id'] : new \WP_Error('publisher_failed', $result['error'] ?? 'Falha ao criar página do glossário.');
        if (!is_wp_error($id) && $id) {
            update_option(self::OPT_PAGE_ID, (int) $id, false);
            if (class_exists(LogService::class)) {
                LogService::record('other', 'success', 'Página GEO Glossário SEO criada automaticamente', ['action' => 'geo_glossary_page_created', 'post_id' => (int)$id]);
            }
        }
    }

    public static function ensure_glossary_defaults(): void {
        if (!current_user_can('manage_options')) return;
        $defaults = [
            'geo_glossary_ai_provider' => ProviderResolver::for('glossary'),
            'geo_glossary_ai_model' => (string)(ProviderResolver::modelFor('glossary') ?: ''),
            'geo_glossary_naga_model' => 'dall-e-3:free',
            'geo_use_legacy_image' => '0',
        ];
        foreach ($defaults as $k => $v) {
            if (get_option($k, null) === null || get_option($k, '') === '') {
                update_option($k, $v, false);
            }
        }
    }

    public function get_titles(): array {
        $data = get_option(self::OPT_TITLES, []);
        return is_array($data) ? $data : [];
    }

    public function save_titles(array $data): void {
        update_option(self::OPT_TITLES, $data, false);
    }

    private function default_text_provider(): string {
        return ProviderResolver::for('glossary');
    }

    private function default_text_model(string $provider): string {
        return (string) (ProviderResolver::modelFor('glossary', $provider) ?: '');
    }

    public function generate_titles(string $theme, array $letters, int $per_letter, string $provider = '', string $model = '', array $briefing = []): array {
        $theme = sanitize_text_field($theme);
        $letters = array_values(array_unique(array_filter(array_map('strtoupper', $letters), fn($l) => preg_match('/^[A-Z]$/', $l))));
        $per_letter = max(1, min(15, $per_letter));
        if (!$theme || empty($letters)) {
            return ['success' => false, 'message' => 'Tema e letras são obrigatórios.'];
        }

        $existing_titles = $this->collect_existing_titles($theme);
        $agent = new GeoGlossaryExpertAgent();
        $prompt = $agent->title_prompt($theme, $letters, $per_letter, $existing_titles, $briefing);

        $provider = $provider ?: $this->default_text_provider();
        $model = $model ?: $this->default_text_model($provider);

        $ai = new AIManager();
        $response = $ai->generateText($prompt, $provider ?: null, $model ?: null);
        if (!$response || $response->hasError() || !$response->getContent()) {
            return ['success' => false, 'message' => 'Falha na IA: ' . ($response ? $response->getError() : 'sem resposta')];
        }

        $data = ContentFormatter::extractJson($response->getContent());
        $items = $data['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            return ['success' => false, 'message' => 'A IA não retornou títulos válidos.'];
        }

        $state = $this->get_titles();
        $added = 0;
        $skipped = 0;
        foreach ($items as $item) {
            $letter = strtoupper(sanitize_text_field($item['letter'] ?? ''));
            $title = trim(wp_strip_all_tags((string)($item['title'] ?? '')));
            $intent = sanitize_text_field($item['intent'] ?? 'informacional');
            $reason = sanitize_text_field($item['reason'] ?? '');
            if (!preg_match('/^[A-Z]$/', $letter) || !in_array($letter, $letters, true) || !$title) { $skipped++; continue; }
            [$valid_title, $title_reason] = $agent->validate_title($title, $theme, $letter);
            if (!$valid_title) { $skipped++; continue; }
            if ($this->title_exists_anywhere($title, $state)) { $skipped++; continue; }

            $id = md5($theme . '|' . $letter . '|' . mb_strtolower($title));
            $state[$id] = [
                'id' => $id,
                'theme' => $theme,
                'letter' => $letter,
                'title' => $title,
                'intent' => $intent,
                'reason' => $reason,
                'briefing' => $briefing,
                'status' => 'pending',
                'post_id' => 0,
                'created_at' => current_time('mysql'),
                'generated_at' => '',
            ];
            $added++;
        }

        // 1.0.0 — Se a IA retornar menos títulos que o solicitado, completar com fallback editorial local.
        // Isso corrige o caso: usuário pede 10 títulos para letra A e a IA devolve apenas 1.
        $completed = $this->ensure_requested_titles($state, $theme, $letters, $per_letter, $agent);
        $added += (int) $completed['added'];
        $skipped += (int) $completed['skipped'];

        $this->save_titles($state);
        return ['success' => true, 'message' => "Títulos adicionados: {$added}. Ignorados por duplicidade/qualidade: {$skipped}.", 'added' => $added, 'skipped' => $skipped, 'state' => $state];
    }

    private function ensure_requested_titles(array &$state, string $theme, array $letters, int $per_letter, GeoGlossaryExpertAgent $agent): array {
        $added = 0;
        $skipped = 0;
        foreach ($letters as $letter) {
            $current = 0;
            foreach ($state as $row) {
                if (($row['theme'] ?? '') === $theme && strtoupper((string)($row['letter'] ?? '')) === $letter && in_array(($row['status'] ?? ''), ['pending','generated'], true)) {
                    $current++;
                }
            }
            if ($current >= $per_letter) continue;
            $needed = $per_letter - $current;
            foreach ($this->fallback_title_candidates($theme, $letter) as $title) {
                if ($needed <= 0) break;
                [$valid, $reason] = $agent->validate_title($title, $theme, $letter);
                if (!$valid || $this->title_exists_anywhere($title, $state)) { $skipped++; continue; }
                $id = md5($theme . '|' . $letter . '|' . mb_strtolower($title));
                $state[$id] = [
                    'id' => $id,
                    'theme' => $theme,
                    'letter' => $letter,
                    'title' => $title,
                    'intent' => 'informacional',
                    'reason' => 'fallback editorial local para completar a quantidade solicitada sem duplicar títulos',
                    'briefing' => [],
                    'status' => 'pending',
                    'post_id' => 0,
                    'created_at' => current_time('mysql'),
                    'generated_at' => '',
                ];
                $added++;
                $needed--;
            }
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    private function fallback_title_candidates(string $theme, string $letter): array {
        $theme_l = mb_strtolower(remove_accents($theme));
        $is_seo_ai = (strpos($theme_l, 'seo') !== false && (strpos($theme_l, 'ia') !== false || strpos($theme_l, 'ai') !== false || strpos($theme_l, 'inteligencia') !== false));
        $terms = [
            'A' => ['algoritmo de pesquisa', 'autoridade temática', 'análise semântica', 'AEO', 'automação editorial', 'arquitetura de conteúdo', 'auditoria SEO para IA', 'atualização de conteúdo', 'answer engine optimization', 'análise de intenção', 'atributos de entidade', 'autoridade de marca'],
            'B' => ['backlinks', 'briefing semântico', 'busca generativa', 'busca conversacional', 'base de conhecimento', 'bloco de resposta', 'brand authority', 'breadcrumb SEO', 'benchmark de conteúdo', 'busca por entidade'],
            'C' => ['cluster de conteúdo', 'canibalização SEO', 'conteúdo pilar', 'conteúdo útil', 'cobertura semântica', 'contexto editorial', 'crawling', 'CTR orgânico', 'conteúdo evergreen', 'consulta conversacional'],
            'D' => ['dados estruturados', 'densidade semântica', 'domínio temático', 'descoberta de conteúdo', 'duplicidade de conteúdo', 'documentação editorial', 'distribuição de links', 'dados de entidade'],
            'E' => ['entidade semântica', 'E-E-A-T', 'experiência do autor', 'estrutura de resposta', 'escaneabilidade', 'engajamento orgânico', 'estratégia de topical authority', 'expansão semântica'],
            'F' => ['FAQPage', 'featured snippet', 'fonte confiável', 'fluxo editorial', 'fator de relevância', 'frase de resposta', 'freshness de conteúdo', 'funil de busca'],
            'G' => ['GEO', 'Google AI Overviews', 'grafo de conhecimento', 'glossário SEO', 'guia semântico', 'geração assistida por IA', 'governança editorial'],
            'H' => ['heading SEO', 'hierarquia de conteúdo', 'HTML semântico', 'hub de conteúdo', 'histórico de atualização', 'headline editorial'],
            'I' => ['intenção de busca', 'indexação', 'interlinking', 'IA generativa', 'intenção informacional', 'índice semântico', 'insights editoriais', 'inventário de conteúdo'],
            'J' => ['jornada de busca', 'jornada do usuário', 'JSON-LD', 'junção de entidades', 'janela de contexto'],
            'K' => ['keyword research', 'knowledge graph', 'knowledge base', 'KPI editorial', 'keyword clustering'],
            'L' => ['LLM Optimization', 'linkagem interna', 'long tail', 'lacuna de conteúdo', 'linguagem natural', 'lead editorial', 'lista de entidades'],
            'M' => ['meta descrição', 'métrica de relevância', 'mapa de conteúdo', 'marcação schema', 'menção de marca', 'modelo de linguagem', 'monitoramento SEO'],
            'N' => ['nicho semântico', 'navegação por entidades', 'natural language processing', 'nível de profundidade', 'normalização de títulos'],
            'O' => ['otimização para IA', 'organic search', 'overview de IA', 'organização de conteúdo', 'outline editorial', 'otimização semântica'],
            'P' => ['prompt editorial', 'página pilar', 'pergunta frequente', 'processamento de linguagem natural', 'palavra-chave long tail', 'padrão de qualidade'],
            'Q' => ['query intent', 'qualidade editorial', 'query conversacional', 'quebra de seção', 'question answering'],
            'R' => ['resposta rápida', 'relevância semântica', 'rich result', 'rastreamento', 'reescrita de conteúdo', 'ranking orgânico', 'resumo estruturado'],
            'S' => ['schema markup', 'SEO semântico', 'sitemap', 'snippet', 'SERP', 'silo de conteúdo', 'sinal de autoridade', 'search intent'],
            'T' => ['topical authority', 'termo relacionado', 'taxonomia editorial', 'título SEO', 'tráfego orgânico', 'tabela comparativa', 'tema pilar'],
            'U' => ['URL canônica', 'usuário-intenção', 'UX editorial', 'update de conteúdo', 'utilidade do conteúdo'],
            'V' => ['validação factual', 'variação semântica', 'visibilidade orgânica', 'vetor semântico', 'verificação editorial'],
            'W' => ['web semântica', 'WordPress SEO', 'web stories', 'workflow editorial', 'web crawler'],
            'X' => ['XML sitemap', 'X-Robots-Tag', 'XPath em auditoria SEO', 'XFN em links'],
            'Y' => ['YouTube SEO', 'YMYL', 'Yoast SEO', 'yield editorial'],
            'Z' => ['zero-click search', 'zona de resposta', 'zero party data', 'Zettelkasten editorial'],
        ];
        $base_terms = $terms[$letter] ?? [];
        $titles = [];
        foreach ($base_terms as $term) {
            $titles[] = "O que é {$term} e como se conecta ao tema {$theme}";
            $titles[] = "Como {$term} ajuda a melhorar a estratégia de {$theme}";
            $titles[] = "Guia de {$term} para conteúdo otimizado em {$theme}";
        }
        return array_values(array_unique($titles));
    }

    private function collect_existing_titles(string $theme): array {
        $titles = [];
        foreach ($this->get_titles() as $row) {
            if (!empty($row['title'])) $titles[] = $row['title'];
        }
        $posts = get_posts([
            'post_type' => [self::CPT, 'post'],
            'post_status' => ['publish', 'draft', 'future', 'pending', 'private'],
            'posts_per_page' => 300,
            's' => $theme,
            'fields' => 'ids',
        ]);
        foreach ($posts as $post_id) $titles[] = get_the_title($post_id);
        return array_values(array_unique(array_filter($titles)));
    }

    private function normalize_title(string $title): string {
        $title = remove_accents(wp_strip_all_tags($title));
        $title = mb_strtolower($title);
        return preg_replace('/[^a-z0-9]+/u', ' ', $title);
    }

    private function extract_focus_term(string $title): string {
        $clean = trim(wp_strip_all_tags($title));
        $clean = preg_replace('/\s+/u', ' ', $clean);
        $patterns = [
            '/^o\s+que\s+(é|são)\s+/iu',
            '/^como\s+/iu',
            '/^quando\s+/iu',
            '/^por\s+que\s+/iu',
            '/^qual\s+(é\s+)?/iu',
            '/^quais\s+(são\s+)?/iu',
            '/^guia\s+(de|do|da|dos|das)\s+/iu',
            '/^checklist\s+(de|do|da|dos|das)\s+/iu',
            '/^diferen[cç]a\s+entre\s+/iu',
            '/^estrat[eé]gia\s+(de|do|da|dos|das)\s+/iu',
            '/^m[eé]trica\s+(de|do|da|dos|das)\s+/iu',
            '/^ferramenta\s+(de|do|da|dos|das)\s+/iu',
            '/^processo\s+(de|do|da|dos|das)\s+/iu',
            '/^t[eé]cnica\s+(de|do|da|dos|das)\s+/iu',
            '/^termo\s+(de|do|da|dos|das)\s+/iu',
        ];
        foreach ($patterns as $pattern) {
            $candidate = preg_replace($pattern, '', $clean, 1);
            if ($candidate !== null && $candidate !== $clean) {
                $clean = trim($candidate);
                break;
            }
        }
        $clean = preg_replace('/^["“”\'\s]+/u', '', $clean);
        return trim($clean);
    }

    private function title_matches_letter(string $title, string $letter): bool {
        $focus = $this->extract_focus_term($title);
        if ($focus === '') return false;
        $first = mb_substr(remove_accents($focus), 0, 1);
        return mb_strtoupper($first) === mb_strtoupper($letter);
    }

    private function title_exists_anywhere(string $title, array $state): bool {
        $norm = trim($this->normalize_title($title));
        foreach ($state as $row) {
            if (trim($this->normalize_title($row['title'] ?? '')) === $norm) return true;
        }
        $existing = get_page_by_title($title, OBJECT, [self::CPT, 'post']);
        return $existing ? true : false;
    }

    private function title_is_professional(string $title, string $theme): bool {
        $t = mb_strtolower($title);
        if (mb_strlen($title) < 22 || mb_strlen($title) > 120) return false;
        foreach (['segredo', 'chocante', 'milagroso', 'garantido', 'hack secreto', 'imperdível'] as $bad) {
            if (strpos($t, $bad) !== false) return false;
        }
        if (!preg_match('/^(o que|como|quando|por que|qual|quais|guia|checklist|diferen[cç]a|estrat[eé]gia|m[eé]trica|ferramenta|processo|t[eé]cnica|termo)\b/iu', $title)) {
            return false;
        }
        return true;
    }

    public function generate_glossary_article(string $id, string $status = 'draft', string $scheduled_at = '', string $provider = '', string $model = ''): array {
        @set_time_limit(180); // Evita corte por max_execution_time em servidores lentos
        $state = $this->get_titles();
        if (empty($state[$id]) || ($state[$id]['status'] ?? '') !== 'pending') {
            return ['success' => false, 'message' => 'Título não encontrado ou já gerado.'];
        }
        if (!$this->acquire_lock('geo_glossary_' . $id, 20 * MINUTE_IN_SECONDS)) {
            return ['success' => false, 'message' => 'Este glossario ja esta sendo gerado em outra requisicao.'];
        }
        $state[$id]['status'] = 'processing';
        $this->save_titles($state);

        $row = $state[$id];
        $title = $row['title'];
        if ($this->title_exists_as_post($title)) {
            $state[$id]['status'] = 'duplicate';
            $this->save_titles($state);
            $this->release_lock('geo_glossary_' . $id);
            return ['success' => false, 'message' => 'Já existe um glossário/post com esse título.'];
        }

        $theme = $row['theme'];
        $letter = $row['letter'];
        $related = $this->find_related_posts($title, $theme, 2);
        $related_context = '';
        foreach ($related as $p) {
            $related_context .= '- ' . get_the_title($p->ID) . ' — ' . get_permalink($p->ID) . "\n";
        }

        $agent = new GeoGlossaryExpertAgent();
        $prompt = $agent->article_prompt($title, $theme, $letter, $related_context);

        $provider = $provider ?: $this->default_text_provider();
        $model = $model ?: $this->default_text_model($provider);

        $ai = new AIManager();
        $response = $ai->generateText($prompt, $provider ?: null, $model ?: null);
        if (!$response || $response->hasError() || !$response->getContent()) {
            $state[$id]['status'] = 'pending';
            $this->save_titles($state);
            $this->release_lock('geo_glossary_' . $id);
            return ['success' => false, 'message' => 'Falha na IA: ' . ($response ? $response->getError() : 'sem resposta')];
        }

        $content = $this->sanitize_generated_html($response->getContent());
        [$article_ok, $article_reason] = $agent->validate_article_html($content, $title, $theme);
        if (!$article_ok && preg_match('/curto|seção obrigatória/iu', $article_reason)) {
            $content = $this->enrich_short_glossary_content($content, $title, $theme);
            [$article_ok, $article_reason] = $agent->validate_article_html($content, $title, $theme);
        }
        if (!$article_ok && preg_match('/fonte|factual|inventad/iu', $article_reason)) {
            $state[$id]['status'] = 'pending';
            $this->save_titles($state);
            $this->release_lock('geo_glossary_' . $id);
            return ['success' => false, 'message' => 'Glossário reprovado pelo Agente Especialista: ' . $article_reason];
        }
        // Se ainda estiver um pouco curto, seguir com enriquecimento local e não travar a geração.
        if (!$article_ok) {
            $content = $this->enrich_short_glossary_content($content, $title, $theme);
        }
        $content = $this->append_related_links($content, $related);
        $content = $this->append_related_glossary_links($content, $title, $letter, $theme);
        $content = $this->append_seo_tools_block_if_relevant($content, $title, $theme);
        $content = $this->append_trusted_external_links($content, $title, $theme);
        [$content, $faq_items] = $this->ensure_local_faq_block($content, $title, $theme);

        $post_status = in_array($status, ['draft', 'publish', 'future'], true) ? $status : 'draft';
        if ($post_status === 'future' && !$scheduled_at) {
            $state[$id]['status'] = 'pending';
            $this->save_titles($state);
            $this->release_lock('geo_glossary_' . $id);
            return ['success' => false, 'message' => 'Informe uma data/hora para agendar o glossário.'];
        }
        $post_date = current_time('mysql');
        if ($post_status === 'future' && $scheduled_at) {
            $ts = strtotime($scheduled_at);
            if ($ts && $ts > time()) $post_date = date('Y-m-d H:i:s', $ts);
            else $post_status = 'draft';
        }

        $seo = $this->build_glossary_seo_data($title, $theme, $content, $related);

        $result = GeoMetodoSEO_Publisher::publish([
            'title' => $title,
            'post_name' => $seo['slug'],
            'post_type' => self::CPT,
            'status' => $post_status,
            'scheduled_at' => $post_status === 'future' ? $post_date : '',
            'content' => $content,
            'excerpt' => $seo['description'],
            'seo_title' => $seo['title'] ?? $title,
            'meta_description' => $seo['description'] ?? '',
            'focus_keyword' => $theme ?: $title,
            'source_module' => 'geo_glossary',
        ]);
        $post_id = !empty($result['success']) ? (int)$result['post_id'] : new \WP_Error('publisher_failed', $result['error'] ?? 'Falha ao criar post do glossário.');

        if (is_wp_error($post_id) || !$post_id) {
            $state[$id]['status'] = 'pending';
            $this->save_titles($state);
            $this->release_lock('geo_glossary_' . $id);
            return ['success' => false, 'message' => 'Falha ao criar post do glossário.'];
        }

        wp_set_object_terms($post_id, [$letter], self::TAX, false);
        update_post_meta($post_id, '_geo_glossary_theme', $theme);
        update_post_meta($post_id, '_geo_glossary_letter', $letter);
        update_post_meta($post_id, '_geo_glossary_source_title_id', $id);
        $this->apply_glossary_rank_math_meta($post_id, $seo);

        $faq_schema = $this->build_faq_schema($faq_items);
        if (!empty($faq_schema)) {
            update_post_meta($post_id, 'geo_faq_schema', $faq_schema);
            update_post_meta($post_id, '_sara_faq_count', count($faq_items));
            update_post_meta($post_id, '_aeo_faq_active', '1');
            update_post_meta($post_id, '_sara_faq_mode', 'local_glossary');
        }
        update_post_meta($post_id, '_geo_glossary_word_target', '1200-1500');
        update_post_meta($post_id, '_geo_glossary_provider', $provider);
        update_post_meta($post_id, '_geo_glossary_model', $model);
        update_post_meta($post_id, '_geo_glossary_definedterm_schema', $this->build_definedterm_schema($post_id, $title, $theme, $seo));
        update_post_meta($post_id, '_geo_glossary_schema_active', '1');
        $this->save_glossary_seo_audit($post_id, $content, $seo);

        $img_id = $this->attach_glossary_featured_image($title, $theme);
        if ($img_id) \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::set_featured_image($post_id, $img_id);

        $body_img_id = $this->attach_glossary_body_image($title, $theme);
        if (!$body_img_id && $img_id) {
            $body_img_id = $img_id;
        }
        if ($body_img_id) {
            $post_obj = get_post($post_id);
            if ($post_obj && strpos($post_obj->post_content, 'geo-glossary-inline-media') === false) {
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                    'ID' => $post_id,
                    'post_content' => $this->inject_body_image_into_content($post_obj->post_content, $body_img_id, $title),
                ]);
            }
        }

        // Auditor global de mídia para Glossário: mantém o fluxo existente e completa featured + corpo.
        if (class_exists('GeoMetodoSEO\Services\GeoMediaMasterService')) {
            try {
                $media = new \GeoMetodoSEO\Services\GeoMediaMasterService();
                $media->ensure_post_media($post_id, [
                    'keyword' => $seo['keyword'] ?? $title,
                    'title' => $title,
                    'body_images' => ImageGeneratorService::body_images_count(),
                    'require_featured' => true,
                    'process_now' => false,
                    'category' => 'glossario',
                    'niche' => $theme ?? 'seo',
                ]);
            } catch (\Throwable $e) {
                if (class_exists(LogService::class)) {
                    LogService::record('other', 'warning', 'GeoMediaMasterService falhou no glossário', ['action' => 'geo_glossary_media_master_fail', 'post_id' => (int)$post_id, 'error' => $e->getMessage()]);
                }
            }
        }

        if (class_exists(EEATEngine::class)) {
            try {
                (new EEATEngine())->apply($post_id);
            } catch (\Throwable $e) {
                if (class_exists(LogService::class)) {
                    LogService::record('other', 'warning', 'Falha ao aplicar ecossistema do autor no glossário', ['action' => 'geo_glossary_eeat_fail', 'post_id' => (int)$post_id, 'error' => $e->getMessage()]);
                }
            }
        }

        // 1.0.0: MarchUpdateGuard — análise anti-Google March 2026 Update no Glossário.
        if (class_exists('GeoMetodoSEO\\Services\\MarchUpdateGuard')) {
            try {
                $post_now = get_post($post_id);
                if ($post_now) {
                    \GeoMetodoSEO\Services\MarchUpdateGuard::analyze_and_record(
                        (int) $post_id,
                        (string) $post_now->post_content,
                        1500
                    );
                }
            } catch (\Throwable $e) {
                if (class_exists(LogService::class)) {
                    LogService::record('march_guard', 'warning', 'Falha no MarchUpdateGuard (glossário)', ['post_id' => (int)$post_id, 'error' => $e->getMessage()]);
                }
            }
        }

        $final_post = get_post($post_id);
        if ($final_post) {
            $this->save_glossary_seo_audit($post_id, $final_post->post_content, $seo);
        }

        $post_after_assets = get_post($post_id);
        if ($post_after_assets) {
            $this->save_glossary_seo_audit($post_id, $post_after_assets->post_content, $seo);
        }

        $state[$id]['status'] = 'generated';
        $state[$id]['post_id'] = (int) $post_id;
        $state[$id]['generated_at'] = current_time('mysql');
        $this->save_titles($state);
        $this->release_lock('geo_glossary_' . $id);

        if (class_exists(LogService::class)) {
            LogService::record('other', 'success', "Glossário gerado: {$title}", ['action' => 'geo_glossary_generated', 'post_id' => (int)$post_id]);
        }

        return ['success' => true, 'message' => 'Glossário gerado com sucesso.', 'post_id' => (int)$post_id, 'edit_url' => get_edit_post_link($post_id), 'view_url' => get_permalink($post_id)];
    }

    private function title_exists_as_post(string $title): bool {
        return (bool) get_page_by_title($title, OBJECT, [self::CPT, 'post']);
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

    private function build_glossary_seo_data(string $title, string $theme, string $content, array $related): array {
        $focus = $this->extract_focus_term($title) ?: $title;
        $keyword = trim($focus . ', ' . $theme);
        $seo_title = mb_substr($title . ' | GEO Glossário SEO', 0, 60);
        $plain = trim(wp_strip_all_tags($content));
        $description = wp_trim_words($plain, 28, '');
        if (mb_strlen($description) < 90) {
            $description = 'Entenda ' . $focus . ' no contexto de ' . $theme . ', com definição, uso prático, relação com SEO, GEO, IA, FAQ e termos relacionados.';
        }
        $description = mb_substr($description, 0, 155);
        $slug = sanitize_title($focus . ' ' . $theme);
        if (mb_strlen($slug) > 75) $slug = substr($slug, 0, 75);
        $slug = trim($slug, '-');
        return [
            'focus' => $focus,
            'keyword' => $keyword,
            'title' => $seo_title,
            'description' => $description,
            'slug' => $slug ?: sanitize_title($title),
            'related_count' => count($related),
        ];
    }

    private function apply_glossary_rank_math_meta(int $post_id, array $seo): void {
        $exact_keyword = trim((string)($seo['keyword'] ?? ''));
        update_post_meta($post_id, 'rank_math_focus_keyword', $exact_keyword);
        update_post_meta($post_id, '_rank_math_focus_keyword', $exact_keyword);
        update_post_meta($post_id, '_geo_keyword_exact', $exact_keyword);
        update_post_meta($post_id, '_geo_keyword_clean', $exact_keyword);
        update_post_meta($post_id, 'rank_math_title', $seo['title']);
        update_post_meta($post_id, 'rank_math_description', $seo['description']);
        update_post_meta($post_id, '_geo_keyword', $seo['keyword']);
        update_post_meta($post_id, '_geo_meta_title', $seo['title']);
        update_post_meta($post_id, '_geo_meta_description', $seo['description']);
    }

    private function save_glossary_seo_audit(int $post_id, string $content, array $seo): void {
        $plain = wp_strip_all_tags($content);
        $word_count = str_word_count($plain);
        $has_internal = preg_match('/<a\s+[^>]*href=["\']' . preg_quote(home_url('/'), '/') . '/i', $content) || strpos($content, 'Artigos relacionados') !== false || strpos($content, 'Glossários relacionados') !== false;
        $has_external = preg_match('/<a\s+[^>]*href=["\']https?:\/\/(?!' . preg_quote(parse_url(home_url(), PHP_URL_HOST), '/') . ')/i', $content);
        $has_img = has_post_thumbnail($post_id) || preg_match('/<img\s/i', $content);
        $has_faq = stripos($content, 'Perguntas frequentes') !== false;
        $has_h2 = substr_count(strtolower($content), '<h2') >= 5;
        $score = 0;
        if (mb_strlen($seo['title']) >= 35 && mb_strlen($seo['title']) <= 65) $score += 15;
        if (mb_strlen($seo['description']) >= 90 && mb_strlen($seo['description']) <= 160) $score += 15;
        if (!empty($seo['keyword'])) $score += 15;
        if ($word_count >= 1100) $score += 15;
        if ($has_h2) $score += 10;
        if ($has_faq) $score += 10;
        if ($has_internal) $score += 10;
        if ($has_external) $score += 5;
        if ($has_img) $score += 5;
        update_post_meta($post_id, '_geo_glossary_seo_score', min(100, $score));
        update_post_meta($post_id, '_geo_glossary_word_count', $word_count);
        update_post_meta($post_id, '_geo_glossary_has_internal_links', $has_internal ? '1' : '0');
        update_post_meta($post_id, '_geo_glossary_has_external_links', $has_external ? '1' : '0');
        update_post_meta($post_id, '_geo_glossary_has_faq', $has_faq ? '1' : '0');
        update_post_meta($post_id, '_geo_glossary_has_image', $has_img ? '1' : '0');
    }

    private function build_definedterm_schema(int $post_id, string $title, string $theme, array $seo): string {
        $permalink = get_permalink($post_id);
        $schema = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'DefinedTerm',
                    '@id' => $permalink . '#definedterm',
                    'name' => $seo['focus'],
                    'termCode' => sanitize_title($seo['focus']),
                    'description' => $seo['description'],
                    'inDefinedTermSet' => [
                        '@type' => 'DefinedTermSet',
                        'name' => 'GEO Glossário SEO',
                        'url' => get_permalink((int) get_option(self::OPT_PAGE_ID, 0)) ?: home_url('/geo-glossario-seo/'),
                    ],
                ],
                [
                    '@type' => 'TechArticle',
                    '@id' => $permalink . '#article',
                    'headline' => $title,
                    'description' => $seo['description'],
                    'url' => $permalink,
                    'inLanguage' => get_bloginfo('language') ?: 'pt-BR',
                    'about' => [
                        '@type' => 'Thing',
                        'name' => $seo['focus'],
                    ],
                    'keywords' => $seo['keyword'],
                    'isAccessibleForFree' => true,
                ],
            ],
        ];
        return wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function append_seo_tools_block_if_relevant(string $content, string $title, string $theme): string {
        $ctx = mb_strtolower(remove_accents($title . ' ' . $theme));
        $is_relevant = false;
        foreach (['seo','wordpress','blog','ia','ai','geo','aeo','llm','conteudo','busca'] as $k) {
            if (strpos($ctx, $k) !== false) { $is_relevant = true; break; }
        }
        if (!$is_relevant || stripos($content, 'Rank Math') !== false) return $content;
        $block = '<div class="geo-glossary-tools"><h2>Ferramentas de SEO relacionadas</h2>'
            . '<p>Dependendo do contexto, esse conceito pode aparecer no trabalho com ferramentas de SEO e otimização editorial. Entre as mais conhecidas estão <strong>Rank Math</strong>, <strong>Yoast SEO</strong>, <strong>Google Search Console</strong>, <strong>Ahrefs</strong>, <strong>Semrush</strong> e <strong>Screaming Frog</strong>. Cada uma ajuda em etapas diferentes, como análise técnica, estrutura on-page, acompanhamento de desempenho e organização semântica do conteúdo.</p>'
            . '<ul><li><strong>Rank Math</strong>: útil em WordPress para metadados, schema e orientação on-page.</li><li><strong>Yoast SEO</strong>: conhecido pelo apoio à legibilidade e à estrutura básica de SEO.</li><li><strong>Google Search Console</strong>: ajuda a entender cobertura, indexação e consultas.</li><li><strong>Ahrefs</strong> e <strong>Semrush</strong>: ajudam em pesquisa de palavras-chave, backlinks e concorrência.</li><li><strong>Screaming Frog</strong>: útil para auditoria técnica e rastreamento do site.</li></ul></div>';
        return $this->insert_before_faq_or_end($content, $block);
    }

    private function append_trusted_external_links(string $content, string $title, string $theme): string {
        if (stripos($content, 'geo-glossary-external-sources') !== false) return $content;
        $ctx = mb_strtolower(remove_accents($title . ' ' . $theme));
        $links = [];
        if (strpos($ctx, 'schema') !== false || strpos($ctx, 'dados estruturados') !== false || strpos($ctx, 'faq') !== false) {
            $links[] = ['Schema.org', 'https://schema.org/'];
        }
        if (strpos($ctx, 'seo') !== false || strpos($ctx, 'busca') !== false || strpos($ctx, 'google') !== false || strpos($ctx, 'index') !== false) {
            $links[] = ['Google Search Central', 'https://developers.google.com/search'];
        }
        if (strpos($ctx, 'rank math') !== false || strpos($ctx, 'wordpress') !== false) {
            $links[] = ['Rank Math', 'https://rankmath.com/'];
            $links[] = ['WordPress.org', 'https://wordpress.org/'];
        }
        if (empty($links)) return $content;
        $html = '<div class="geo-glossary-external-sources"><h2>Referências úteis</h2><ul>';
        foreach (array_slice($links, 0, 3) as [$label, $url]) {
            $html .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener nofollow">' . esc_html($label) . '</a></li>';
        }
        $html .= '</ul></div>';
        return $this->insert_before_faq_or_end($content, $html);
    }

    private function insert_before_faq_or_end(string $content, string $block): string {
        if (preg_match('/(<h2[^>]*>\s*Perguntas frequentes(?:\s*:\s*[^<]+)?\s*<\/h2>)/iu', $content, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int) $m[1][1];
            return substr($content, 0, $pos) . $block . substr($content, $pos);
        }
        return $content . "\n" . $block;
    }

    private function attach_glossary_body_image(string $title, string $theme) {
        $subject = trim($title ?: $theme);
        $image = new ImageGeneratorService();
        $attachment_id = $image->generate_body_attachment([
            'title' => $subject,
            'keyword' => $this->extract_focus_term($title) ?: $subject,
            'category' => $theme,
            'section' => 'glossario',
        ]);
        return (!is_wp_error($attachment_id) && $attachment_id) ? (int)$attachment_id : false;
    }
    private function inject_body_image_into_content(string $content, int $attachment_id, string $title): string {
        $figure = \GeoMetodoSEO\Services\LibraryImageService::build_attachment_figure_html((int)$attachment_id, wp_strip_all_tags($title), ['geo-glossary-inline-media', 'geo-glossary-body-image'], [], 'Imagem ilustrativa relacionada ao termo do glossário.');
        if (!$figure) return $content;
        if (preg_match('/(<h2[^>]*>.*?<\/h2>)/isu', $content, $m, PREG_OFFSET_CAPTURE)) {
            $insert_at = (int) $m[1][1] + strlen($m[1][0]);
            return substr($content, 0, $insert_at) . "\n" . $figure . "\n" . substr($content, $insert_at);
        }
        return $figure . "\n" . $content;
    }

    private function enrich_short_glossary_content(string $content, string $title, string $theme): string {
        $term = $this->extract_focus_term($title) ?: $title;
        $need = [
            'O que significa' => '<h2>O que significa</h2><p>' . esc_html($term) . ' é um conceito que precisa ser entendido dentro do tema ' . esc_html($theme) . '. Em um glossário profissional, a definição deve explicar o significado do termo, o contexto em que ele aparece e a utilidade prática para quem está organizando uma estratégia de conteúdo, SEO, GEO, AEO ou otimização para mecanismos de IA.</p>',
            'Como funciona na prática' => '<h2>Como funciona na prática</h2><p>Na prática, ' . esc_html($term) . ' ajuda a organizar decisões editoriais, identificar intenção de busca, melhorar a clareza semântica e conectar páginas relacionadas. O uso correto depende do objetivo do conteúdo, da etapa do funil, do nível de conhecimento do leitor e da forma como o termo se relaciona com outros conceitos do mesmo tema.</p>',
            'Por que esse conceito importa para SEO, GEO e IAs' => '<h2>Por que esse conceito importa para SEO, GEO e IAs</h2><p>Esse conceito importa porque sistemas de busca e modelos de IA tendem a interpretar melhor conteúdos claros, bem estruturados e conectados a entidades relevantes. Quando um glossário explica o termo com definição, aplicação, exemplos e perguntas frequentes, ele fortalece a cobertura semântica do site e ajuda o leitor a encontrar respostas objetivas.</p>',
            'Exemplos de uso' => '<h2>Exemplos de uso</h2><p>Um exemplo seguro de uso é aplicar ' . esc_html($term) . ' na organização de pautas, no planejamento de clusters, na análise de conteúdos antigos ou na criação de materiais explicativos. O termo também pode apoiar briefs editoriais, páginas de apoio, conteúdos pilares e links internos, desde que seja usado com coerência e sem promessas de resultado garantido.</p>',
            'Erros comuns' => '<h2>Erros comuns ao interpretar esse termo</h2><p>Os erros mais comuns são tratar o termo como moda, usar uma definição genérica, confundir com conceitos parecidos ou aplicar o assunto fora de contexto. Outro erro é prometer ranking, tráfego ou destaque em IA sem evidência. A forma correta é explicar limites, aplicações e relação com outros elementos da estratégia.</p>',
            'Termos relacionados' => '<h2>Termos relacionados</h2><p>Termos relacionados podem incluir intenção de busca, entidade semântica, dados estruturados, linkagem interna, conteúdo útil, autoridade temática, resposta rápida, FAQPage, schema e otimização para mecanismos generativos. Esses conceitos ajudam a criar uma rede de significado em volta do glossário.</p>',
        ];
        foreach ($need as $heading => $html) {
            if (stripos($content, $heading) === false) {
                $content .= "\n" . $html;
            }
        }
        $plain = wp_strip_all_tags($content);
        if (str_word_count($plain) < 1000) {
            $content .= "\n<h2>Como usar este glossário na estratégia editorial</h2>"
                . '<p>Use este glossário como ponto de apoio para explicar conceitos de forma rápida e confiável. Ele pode ser linkado a partir de artigos maiores, usado para esclarecer dúvidas recorrentes e conectado a outros termos relacionados. Essa abordagem cria uma arquitetura de conteúdo mais clara, melhora a navegação interna e ajuda buscadores e sistemas de IA a reconhecerem a relação entre os temas do site.</p>';
            $content .= "\n<h2>Quando aprofundar o tema em um artigo completo</h2>"
                . '<p>Vale aprofundar o tema quando o leitor precisa de comparação, passo a passo, exemplos avançados ou análise técnica. O glossário deve responder à definição e ao uso básico; o artigo completo pode explorar métodos, ferramentas, casos de aplicação, checklist e decisões práticas. Essa separação evita que o glossário fique raso ou que um artigo pilar seja substituído por uma definição curta.</p>';
        }
        return $content;
    }

    private function ensure_local_faq_block(string $content, string $title, string $theme): array {
        $faq_items = $this->build_local_faqs($title, $theme);
        $faq_heading = 'Perguntas frequentes: ' . $title;
        // 1.0.0 — normalizar FAQ antigo/gerado pela IA para repetir o título no H2.
        $content = preg_replace('/<h2([^>]*)>\s*Perguntas frequentes\s*<\/h2>/iu', '<h2$1>' . esc_html($faq_heading) . '</h2>', $content) ?: $content;
        $has_faq_heading = (bool) preg_match('/<h2[^>]*>\s*Perguntas frequentes(?:\s*:\s*[^<]+)?\s*<\/h2>/iu', $content);
        $has_faq_microdata = (bool) preg_match('/FAQPage|itemtype="https:\/\/schema.org\/FAQPage"/iu', $content);
        if (!$has_faq_heading && !$has_faq_microdata) {
            $content .= "
" . $this->render_local_faq_html($faq_items, $title);
        }
        return [$content, $faq_items];
    }

    private function build_local_faqs(string $title, string $theme): array {
        $term = $this->extract_focus_term($title) ?: $title;
        return [
            ['question' => "O que é {$term} na prática?", 'answer' => "{$term} é um conceito ligado ao tema {$theme}. Na prática, ele descreve como esse termo funciona, quando faz sentido aplicá-lo e por que ele influencia decisões editoriais, de SEO, GEO e entendimento por IA. O ponto mais importante é entender o contexto de uso, e não apenas decorar a definição.", 'type' => 'comum'],
            ['question' => "Como {$term} pode ajudar em SEO, GEO e IA?", 'answer' => "Esse termo ajuda porque organiza a intenção de busca, melhora a clareza semântica do conteúdo e facilita a compreensão por mecanismos de busca e sistemas de IA. Quando o conceito é explicado com definição, exemplos e termos relacionados, ele tende a cobrir melhor lacunas informacionais e fortalecer a relevância temática da página.", 'type' => 'comum'],
            ['question' => "Quais erros são comuns ao usar {$term}?", 'answer' => "Os erros mais comuns são usar o termo de forma genérica, sem contexto, repetir definições superficiais e não mostrar aplicação prática. Outro erro frequente é confundir {$term} com conceitos parecidos, o que reduz precisão editorial. Em glossários profissionais, o ideal é explicar significado, uso real, relação com a estratégia e limites do conceito.", 'type' => 'tecnica'],
            ['question' => "Quando vale a pena aprofundar {$term} em um artigo separado?", 'answer' => "Vale a pena aprofundar quando o termo possui intenção de busca própria, gera dúvidas recorrentes ou se conecta a decisões estratégicas do usuário. Nesses casos, o glossário funciona como porta de entrada e o artigo completo aprofunda método, exemplos, comparação e aplicação. Isso melhora a experiência do leitor e a malha de links internos do site.", 'type' => 'tecnica'],
        ];
    }

    private function render_local_faq_html(array $faqs, string $title = ''): string {
        if (empty($faqs)) return '';
        $heading = $title ? 'Perguntas frequentes: ' . $title : 'Perguntas frequentes';
        $html = '<div class="geo-glossary-faq" itemscope itemtype="https://schema.org/FAQPage"><h2>' . esc_html($heading) . '</h2>';
        foreach ($faqs as $faq) {
            $html .= '<div class="geo-glossary-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">';
            $html .= '<h3 itemprop="name">' . esc_html($faq['question']) . '</h3>';
            $html .= '<div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><p itemprop="text">' . esc_html($faq['answer']) . '</p></div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function build_faq_schema(array $faqs): string {
        if (empty($faqs)) return '';
        if (class_exists(SaraDeepFAQGenerator::class)) {
            return (new SaraDeepFAQGenerator())->build_schema($faqs);
        }
        $entities = [];
        foreach ($faqs as $faq) {
            $entities[] = [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ];
        }
        return wp_json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function attach_glossary_featured_image(string $title, string $theme) {
        $subject = trim($title ?: $theme);
        $image = new ImageGeneratorService();
        $attachment_id = $image->generate_featured_attachment([
            'title' => $subject,
            'keyword' => $this->extract_focus_term($title) ?: $subject,
            'category' => $theme,
        ]);
        return (!is_wp_error($attachment_id) && $attachment_id) ? (int)$attachment_id : false;
    }
    private function save_remote_image(string $url, string $title) {
        return \GeoMetodoSEO\Services\SafeImageSideload::attachment_id($url, 0, $title);
    }

    private function sanitize_generated_html(string $html): string {
        $html = trim($html);
        $html = preg_replace('/^```html?\s*/i', '', $html);
        $html = preg_replace('/```\s*$/', '', $html);
        return wp_kses_post($html);
    }

    private function find_related_posts(string $title, string $theme, int $limit = 2): array {
        $terms = array_values(array_filter(array_unique(array_merge(
            preg_split('/\s+/', wp_strip_all_tags($title)),
            preg_split('/\s+/', wp_strip_all_tags($theme))
        ))));
        $search = implode(' ', array_slice($terms, 0, 6));
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            's' => $search,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        return $posts;
    }

    private function append_related_links(string $content, array $related): string {
        if (empty($related)) return $content;
        $html = '<div class="geo-glossary-related"><h2>Artigos relacionados</h2><ul>';
        foreach (array_slice($related, 0, 2) as $p) {
            $html .= '<li><a href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html(get_the_title($p->ID)) . '</a></li>';
        }
        $html .= '</ul></div>';
        return $content . "\n" . $html;
    }

    private function append_related_glossary_links(string $content, string $title, string $letter, string $theme): string {
        $gloss = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => 4,
            'post__not_in' => [],
            'tax_query' => [[
                'taxonomy' => self::TAX,
                'field' => 'name',
                'terms' => [$letter],
            ]],
            'meta_query' => [[
                'key' => '_geo_glossary_theme',
                'value' => $theme,
                'compare' => '=',
            ]],
        ]);
        if (empty($gloss)) return $content;
        $html = '<div class="geo-glossary-related"><h2>Glossários relacionados</h2><ul>';
        foreach (array_slice($gloss, 0, 3) as $p) {
            if (strcasecmp(get_the_title($p->ID), $title) === 0) continue;
            $html .= '<li><a href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html(get_the_title($p->ID)) . '</a></li>';
        }
        $html .= '</ul></div>';
        return $content . "\n" . $html;
    }

    public static function output_glossary_schema(): void {
        if (!is_singular(self::CPT)) return;
        $post_id = get_the_ID();
        if (!$post_id) return;
        $schemas = [];
        $defined = get_post_meta($post_id, '_geo_glossary_definedterm_schema', true);
        if ($defined) {
            $decoded = json_decode($defined, true);
            if (is_array($decoded)) $schemas[] = $decoded;
        }
        $faq = get_post_meta($post_id, 'geo_faq_schema', true);
        if ($faq) {
            $decoded = json_decode($faq, true);
            if (is_array($decoded)) $schemas[] = $decoded;
        }
        foreach ($schemas as $schema) {
            echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        }
    }

    public static function render_glossary_shortcode($atts = []): string {
        self::register_post_type_and_taxonomy();
        $letters = range('A', 'Z');
        ob_start();
        echo '<div class="geo-glossario-seo">';
        echo '<h1>GEO Glossário SEO</h1>';
        echo '<p>Glossário editorial organizado de A a Z com conceitos de SEO, GEO, AEO, LLMs e marketing digital.</p>';
        echo '<nav class="geo-glossary-az" style="display:flex;flex-wrap:wrap;gap:8px;margin:20px 0;">';
        foreach ($letters as $l) {
            echo '<a style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;text-decoration:none;" href="#letra-' . esc_attr($l) . '">' . esc_html($l) . '</a>';
        }
        echo '</nav>';
        foreach ($letters as $l) {
            $posts = get_posts([
                'post_type' => self::CPT,
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'orderby' => 'title',
                'order' => 'ASC',
                'tax_query' => [[
                    'taxonomy' => self::TAX,
                    'field' => 'name',
                    'terms' => [$l],
                ]],
            ]);
            echo '<section id="letra-' . esc_attr($l) . '" style="margin:30px 0;">';
            echo '<h2>' . esc_html($l) . '</h2>';
            if (empty($posts)) {
                echo '<p style="color:#777;">Ainda não há termos publicados nesta letra.</p>';
            } else {
                echo '<ul class="geo-glossary-list">';
                foreach ($posts as $p) {
                    echo '<li><a href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html(get_the_title($p->ID)) . '</a></li>';
                }
                echo '</ul>';
            }
            echo '</section>';
        }
        echo '</div>';
        return ob_get_clean();
    }
}


