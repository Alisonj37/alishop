<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\Repositories\PostRepository;
use GeoMetodoSEO\SEO\RankMathIntegration;
use GeoMetodoSEO\SEO\LinkOrchestrator;
use GeoMetodoSEO\EEAT\EEATEngine;
use GeoMetodoSEO\Config\ConfigManager;
use GeoMetodoSEO\Admin\TemplateController;
use GeoMetodoSEO\Admin\IndividualGeneratorController;
use GeoMetodoSEO\License\LicenseManager;

class ArticlePipeline {

    private $template;
    private $context;
    private $ai;
    private $repo;
    private $seo;
    private $image;
    private $replicate;
    private $linking;
    private $eeat;
    private $provider;
    private $model;
    private $template_id;
    private $embed_youtube_video = false;

    // Campos criticos e comprimento minimo esperado (em caracteres sem HTML)
    private $required_fields = [
        'introducao'          => 200,
        'secao_definicao'     => 200,
        'secao_funcionamento' => 200,
        'secao_beneficios'    => 200,
        'secao_guia'          => 200,
        'tabela_comparativa'  => 100,
        'conclusao'           => 150,
        'faq_texto'           => 200,
        'resumo_snippet'      => 50,
        'erros_comuns'        => 100,  // Erros comuns — AEO/GEO
        'dica_especialista'   => 50,   // Dica de especialista — E-E-A-T
    ];

    public function __construct($provider = null, $model = null, $template_id = null) {
        $this->template     = new TemplateEngine();
        $this->context      = new ContextEngine();
        $this->ai           = new AIManager();
        $this->repo         = new PostRepository();
        $this->seo          = new RankMathIntegration();
        $this->image        = new ImageGeneratorService();
        $this->replicate    = new ReplicateImageService();
        $this->linking      = new LinkOrchestrator();
        $this->eeat         = new EEATEngine();
        $this->provider     = ProviderResolver::for('article_generation', is_string($provider) ? $provider : '');
        $this->model        = $model;
        $this->template_id  = $template_id;
    }

    /** Ativa/desativa embed de vídeo do YouTube relacionado ao tema neste artigo. */
    public function set_embed_youtube_video(bool $enabled): void {
        $this->embed_youtube_video = $enabled;
    }

    /**
     * Generate article preview (no save, no images).
     * Returns ['title', 'excerpt', 'sections'] or false on error.
     */
    public function preview(string $keyword, string $language = 'pt-BR', string $size = 'large') {
        $size = in_array($size, ['small', 'medium', 'large', 'cluster_satellite'], true) ? $size : 'large';
        $prompt   = $this->context->enrich($keyword, $language, '', $size);
        $response = $this->ai->generateText($prompt, $this->provider, $this->model);

        if ($response->hasError()) return false;

        $data = ContentFormatter::extractJson($response->getContent());
        if (!$data) return false;

        // Extract H2 headings from built content
        $content  = $this->buildContent($data, $keyword);
        $sections = [];
        if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $content, $m)) {
            foreach ($m[1] as $h) {
                $sections[] = wp_strip_all_tags($h);
            }
        }

        return [
            'title'    => $data['title_seo']       ?? ucfirst($keyword),
            'excerpt'  => $data['meta_description'] ?? '',
            'sections' => $sections,
        ];
    }

    /**
     * Detecta ou cria a categoria adequada para a keyword.
     */
    private function define_category(string $keyword): string {
        $kw  = strtolower($keyword);
        $map = [
            'seo'         => 'SEO',
            'marketing'   => 'Marketing Digital',
            'trafego'     => 'Tráfego Pago',
            'redes sociais' => 'Redes Sociais',
            'email'       => 'E-mail Marketing',
            'conteudo'    => 'Conteúdo',
            'wordpress'   => 'WordPress',
            'vendas'      => 'Vendas',
            'negocio'     => 'Negócios',
            'negocios'    => 'Negócios',
            'financ'      => 'Finanças',
            'saude'       => 'Saúde',
            'tecnologia'  => 'Tecnologia',
            'ia'          => 'Inteligência Artificial',
            'inteligencia artificial' => 'Inteligência Artificial',
        ];

        foreach ($map as $needle => $cat) {
            if (strpos($kw, $needle) !== false) {
                return $cat;
            }
        }

        return 'Geral';
    }

    public function process($keyword, $language = null, $post_status = 'draft', $scheduled_at = '', $tone = '', $category = 'auto', $size = 'large') {
        // Verificar trial antes de processar
        if (!LicenseManager::canGenerate()) {
            LogService::log('error', 'Trial esgotado: nao foi possivel gerar artigo para "' . $keyword . '"');
            return false;
        }

        // Providers independentes: usa somente o provider escolhido/resolvido.
        // Não há fallback para OpenAI/Groq/Claude/Naga. Se falhar, erro claro.
        if (!$this->provider) {
            LogService::log('error', 'Nenhum provider de texto selecionado/configurado para gerar artigo. Escolha um provider nas configurações ou no gerador.');
            return false;
        }
        if (!LicenseManager::isProviderAllowed($this->provider)) {
            LogService::log('error', 'Provider não permitido pela licença/plano: ' . $this->provider);
            return false;
        }
        if (!ProviderResolver::isConfigured($this->provider)) {
            LogService::log('error', 'Provider selecionado sem API key configurada: ' . $this->provider);
            return false;
        }

        if (!$this->model) {
            $this->model = ProviderResolver::modelFor('article_generation', $this->provider);
        }

        $language = $language ?? ConfigManager::get('default_language', 'pt-BR');
        $size = in_array($size, ['small', 'medium', 'large', 'cluster_satellite', 'cluster_pillar'], true) ? $size : 'large';

        // Mapa de palavras por size:
        //   cluster_satellite = 1600 (faixa 1550-1650)
        //   cluster_pillar    = 4000 (faixa 3500-4500) — pilar completo e profissional
        $size_word_map = ['small' => 1500, 'cluster_satellite' => 1600, 'medium' => 2500, 'large' => 3500, 'cluster_pillar' => 4000];
        $target_words  = $size_word_map[$size] ?? 2500;

        // Resolve category
        $resolved_category = ($category === 'auto') ? $this->define_category($keyword) : sanitize_text_field($category);

        // MPC (cadeia de chamadas JSON) DESATIVADO. Todos os geradores usam
        // o caminho de chamada única html_prompt abaixo — garante 1 chamada,
        // HTML completo, sem H2s vazios. process_mpc mantido apenas para
        // compatibilidade caso geo_use_mpc seja forçado manualmente no banco.
        if ((bool) get_option('geo_use_mpc', 0) && get_option('geo_force_legacy_mpc', 0)) {
            return $this->process_mpc($keyword, $language, $post_status, $scheduled_at, $tone, $resolved_category, $size);
        }

        // ── CHAMADA ÚNICA: HTML completo em vez de JSON fragmentado ──────────
        // Motivo: JSON fragmentado causa H2s vazios quando providers não preenchem
        // todos os campos. HTML direto garante artigo completo em 1 chamada.

        $int_links = \GeoMetodoSEO\Services\ContextEngine::get_internal_links($keyword, 0, $resolved_category);
        // Fontes externas NÃO são definidas por keyword aqui. A inserção real
        // (mais abaixo) usa o CONTEÚDO gerado e só adiciona fonte de entidade
        // realmente citada — evitando fonte fora de contexto.
        $ext_links = [];
        $niche     = get_option('sara_niche', 'Tecnologia');

        // Gerar outline estruturado baseado no size
        $n_h2 = ($size === 'small' || $size === 'cluster_satellite') ? 5 : ($size === 'medium' ? 7 : ($size === 'cluster_pillar' ? 10 : 9));
        $outline = self::build_outline($keyword, $n_h2, $resolved_category);

        $prompt = \GeoMetodoSEO\Services\SingleMasterPromptService::html_prompt([
            'keyword'        => $keyword,
            'title'          => $keyword,
            'language'       => $language ?? 'pt-BR',
            'scope'          => 'gerador_individual',
            'niche'          => $niche,
            'target_words'   => $target_words,
            'tone'           => $tone ?: 'profissional',
            'briefing'       => '',
            'outline'        => $outline,
            'internal_links' => $int_links,
            'external_links' => $ext_links,
        ]);

        $response = $this->ai->generateText($prompt, $this->provider, $this->model);

        if ($response->hasError()) {
            LogService::log('error', 'Pipeline: falha na geracao IA para "' . $keyword . '": ' . $response->getError());
            return false;
        }

        // Conteúdo HTML direto — sem parse JSON, sem segunda chamada
        $content = self::clean_html_response($response->getContent());

        // Extrair title e excerpt do HTML gerado
        $title   = self::extract_title_from_html($content, $keyword);
        $content = self::strip_title_comment($content); // remover <!-- TITLE --> do corpo
        $excerpt = self::extract_excerpt_from_html($content);
        $slug    = sanitize_title($keyword);

        // Verificar qualidade mínima — se sem H2, tentar novamente 1x
        if (substr_count($content, '<h2') < 3) {
            LogService::record('pipeline', 'warning',
                'Pipeline: HTML sem H2s suficientes — tentando novamente',
                ['action' => 'html_retry', 'context' => ['keyword' => $keyword]]
            );
            $retry = $this->ai->generateText($prompt, $this->provider, $this->model);
            if ($retry && !$retry->hasError()) {
                $retry_html = self::clean_html_response($retry->getContent());
                if (substr_count($retry_html, '<h2') >= 3) {
                    $content = $retry_html;
                    $title   = self::extract_title_from_html($content, $keyword);
                    $content = self::strip_title_comment($content);
                    $excerpt = self::extract_excerpt_from_html($content);
                }
            }
        }

        // ─────────────────────────────────────────────────────────────────────

        $post_id = $this->repo->create($title, $content, $excerpt, $slug, $post_status, $scheduled_at, $resolved_category, $keyword);

        if (is_wp_error($post_id)) {
            LogService::log('error', 'Pipeline: falha ao criar post para "' . $keyword . '"');
            return false;
        }

        // 6. Salvar metadados
        update_post_meta($post_id, '_geo_keyword',  $keyword);
        update_post_meta($post_id, '_geo_provider', $this->provider);
        update_post_meta($post_id, '_geo_text_provider_used', $this->provider);
        update_post_meta($post_id, '_geo_text_model_used', (string)($this->model ?: ''));
        update_post_meta($post_id, '_geo_generation_context', 'article_pipeline');
        update_post_meta($post_id, '_geo_language', $language);
        if (!empty($tone)) {
            update_post_meta($post_id, '_geo_tone', $tone);
        }
        if (!empty($resolved_category)) {
            update_post_meta($post_id, '_geo_category', $resolved_category);
        }
        if ($this->model) {
            update_post_meta($post_id, '_geo_model', $this->model);
        }
        // Custo estimado
        $est_cost = IndividualGeneratorController::$cost_estimates[$this->model ?? ''] ?? 0.005;
        update_post_meta($post_id, '_geo_api_cost', $est_cost);

        // Salvar faq_texto bruto para extracao confiavel pelo EEATEngine
        if (!empty($data['faq_texto'])) {
            update_post_meta($post_id, '_geo_faq_raw', $data['faq_texto']);
        }

        // Incrementar contador trial
        LicenseManager::incrementTrialCount();

        // 7. Aplicar linkagem interna DEPOIS do wp_kses_post (evita HTML cru)
        $saved_content  = get_post_field('post_content', $post_id);
        $linked_content = $this->linking->process($post_id, $saved_content);

        // 7b. FIX-LINKS: Adicionar links externos de autoridade (Individual/Massa/Cluster)
        // Recalcular as fontes COM BASE NO CONTEÚDO REAL gerado — só entram fontes
        // de entidades efetivamente citadas no artigo, nunca genéricas por nicho.
        $ext_links = \GeoMetodoSEO\Services\ContextEngine::get_authority_links($keyword, $linked_content);
        if (!empty($ext_links)) {
            $already_in = false;
            foreach ($ext_links as $ext) {
                if (!empty($ext['url']) && stripos($linked_content, $ext['url']) !== false) {
                    $already_in = true;
                    break;
                }
            }
            if (!$already_in) {
                $inserted = 0;
                foreach (array_slice($ext_links, 0, 2) as $link) {
                    if ($inserted >= 2) break;
                    if (empty($link['url']) || empty($link['anchor'])) continue;
                    if (stripos($linked_content, $link['url']) !== false) continue;
                    $linked_content = preg_replace(
                        '/(<\/p>)/i',
                        ' Fonte: <a href="' . esc_url($link['url']) . '" rel="noopener noreferrer" target="_blank">' . esc_html($link['anchor']) . '</a>.$1',
                        $linked_content,
                        1
                    );
                    $inserted++;
                }
            }
        }

        // 8. Imagens internas: SaraGlobalPostProcessor e a fonte oficial.
        // Se ele nao existir, mantem fallback legado.
        $final_content = class_exists('GeoMetodoSEO\\Autopilot\\Writer\\SaraGlobalPostProcessor')
            ? $linked_content
            : $this->insert_body_images($linked_content, $keyword);

        if ($final_content !== $saved_content) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                'ID'           => $post_id,
                'post_content' => $final_content,
            ]);
        }

        // 9. Imagem destaque (logging detalhado pra debug)
        LogService::record('generator', 'info',
            "Iniciando geração de imagem destaque para post #{$post_id}",
            ['post_id' => $post_id, 'action' => 'featured_image_start']);

        $image_id = $this->image->generate_featured_attachment([
            'title' => $title,
            'keyword' => $keyword,
            'category' => $resolved_category,
        ], (int) $post_id);
        $image_id = is_wp_error($image_id) ? 0 : (int) $image_id;
        if ($image_id) {
            // No modo Biblioteca, set_featured() passa pela camada central do Publisher.
            // Só chamar Publisher::set_featured_image no modo IA (para não duplicar e gerar log de falha).
            $is_library = class_exists('\GeoMetodoSEO\Services\LibraryImageService')
                && \GeoMetodoSEO\Services\LibraryImageService::is_library_mode();
            if (!$is_library) {
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::set_featured_image($post_id, $image_id);
            }
            LogService::record('generator', 'success',
                "Imagem destaque setada (attachment #{$image_id})",
                ['post_id' => $post_id, 'action' => 'featured_image_set']);
        } else {
            LogService::record('generator', 'error',
                "Imagem destaque NÃO foi gerada para '{$title}' — verifique logs de Fal.ai/Replicate",
                ['post_id' => $post_id, 'action' => 'featured_image_failed',
                 'context' => ['title' => $title, 'keyword' => $keyword]]);
        }

        // 10. Aplicar SEO (RankMath)
        $this->seo->apply($post_id, $title, $keyword, $excerpt);

        // 11. E-E-A-T
        $this->eeat->apply($post_id);

        // 11b. Artigos relacionados (2 cards após bloco EEAT)
        $this->insert_related_articles($post_id, $keyword);

        // 11c. Pós-processamento editorial global também no Gerador Individual:
        // resposta rápida única/curta, tabela HTML, fontes oficiais, FAQ único e autor no rodapé.
        if (class_exists('GeoMetodoSEO\Autopilot\Writer\SaraGlobalPostProcessor')) {
            \GeoMetodoSEO\Autopilot\Writer\SaraGlobalPostProcessor::finalize($post_id, [
                'title' => $title,
                'keyword' => $keyword,
                'category' => $resolved_category,
                'niche' => get_option('sara_niche', 'Tecnologia'),
                'target_words' => $target_words,
                'enable_faq' => true,
                'internal_image_count' => ImageGeneratorService::body_images_count(),
                // Artigo Individual/Massa/Cluster gera no admin via AJAX — body images
                // têm que aparecer NA HORA, tanto no modo IA quanto no modo Biblioteca.
                // O Replicate gera cada imagem em ~1s, então é seguro processar síncrono.
                'process_internal_images_now' => true,
                'use_featured_as_body_fallback' => false,
                'embed_youtube_video' => $this->embed_youtube_video,
            ]);
        }

        // 12. Log de sucesso
        LogService::log(
            'success',
            'Artigo gerado: "' . $title . '" (keyword: ' . $keyword . ', provedor: ' . $this->provider . ')',
            $post_id
        );

        // 13. Hook extensivel
        do_action('geo_article_generated', $post_id, $keyword);

        return $post_id;
    }

    /**
     * Verifica se os campos principais estao abaixo do minimo.
     * Se sim, faz nova chamada a IA pedindo expandir APENAS esses campos.
     * Maximo de 1 retry (2 tentativas no total).
     */
    private function retry_short_sections($data, $keyword, $language) {
        $short_fields = $this->find_short_fields($data);

        if (empty($short_fields)) {
            return $data;
        }

        $fields_list  = implode(', ', $short_fields);
        $year = wp_date('Y');

        // Instruções específicas por campo para máxima qualidade
        $field_instructions = [
            'resumo_snippet'    => '"resumo_snippet": "<p>[Resposta direta 1-2 frases, 40-75 palavras, sobre ' . $keyword . ' em ' . $year . '. Use a keyword. Apenas <p> sem <strong>.]</p>"',
            'conclusao'         => '"conclusao": "<p>[Síntese — 120 palavras nova perspectiva]</p><p>[Tendências ' . $year . ' — 80 palavras]</p><p>[CTA acionável — 60 palavras]</p>"',
            'faq_texto'         => '"faq_texto": "<div class=\\"geo-faq-item\\"><strong>[Pergunta específica sobre ' . $keyword . '?]</strong><p>[100+ palavras com exemplo real]</p></div> — repita 8 vezes"',
            'tabela_comparativa'=> '"tabela_comparativa": "<table style=\\"width:100%;border-collapse:collapse;margin:20px 0;\\"><thead><tr style=\\"background:#1a1a2e;color:#fff;\\"><th>Critério</th><th>Opção A</th><th>Opção B</th><th>Opção C</th></tr></thead><tbody>[5+ linhas com informações cautelosas e verificáveis; não inventar números, preço, data ou especificações]</tbody></table>"',
            'erros_comuns'      => '"erros_comuns": "<h3>[Erro 1: nome específico sobre ' . $keyword . ']</h3><p>[80+ palavras]</p><h3>[Erro 2]</h3><p>[80+ palavras]</p><h3>[Erro 3]</h3><p>[80+ palavras]</p>"',
            'tendencias_2026'   => '"tendencias_2026": "<p><strong>Tendência 1 em ' . $year . ':</strong> [100 palavras com dados somente se confirmados; se não houver confirmação, escreva de forma cautelosa]</p><p><strong>Tendência 2:</strong> [100 palavras]</p><p><strong>Previsão 2027:</strong> [80 palavras]</p>"',
            'dica_especialista' => '"dica_especialista": "' . '<blockquote style="border-left:4px solid #8B5CF6;padding:16px 20px;background:rgba(139,92,246,0.08);margin:28px 0;border-radius:0 8px 8px 0;"><strong>💡 Dica de Especialista:</strong> [insight NÃO ÓBVIO sobre ' . $keyword . ' — mínimo 80 palavras surpreendentes e acionáveis]</blockquote>"',
        ];

        $specific_instructions = [];
        foreach ($short_fields as $f) {
            $specific_instructions[] = $field_instructions[$f] ?? '"' . $f . '": "[Conteúdo HTML rico com mínimo 300 palavras sobre ' . $keyword . ']"';
        }

        $retry_prompt = 'TAREFA CRÍTICA: O artigo sobre "' . $keyword . '" está incompleto — faltam: ' . $fields_list . ".

"
            . 'Gere SOMENTE um JSON válido com os campos faltantes. Idioma: ' . $language . ". Ano: {$year}.

"
            . "CAMPOS NECESSÁRIOS (siga o formato exato):
"
            . "{
  " . implode(",
  ", $specific_instructions) . "
}

"
            . 'REGRAS: HTML dentro dos valores. Conteúdo real e específico sobre "' . $keyword . '". Retorne APENAS o JSON válido, sem markdown.';

        $retry_response = $this->ai->generateText($retry_prompt, $this->provider, $this->model);

        if (!$retry_response->hasError() && $retry_response->getContent()) {
            $retry_data = ContentFormatter::extractJson($retry_response->getContent());
            if ($retry_data) {
                foreach ($short_fields as $field) {
                    if (!empty($retry_data[$field]) && strlen(strip_tags($retry_data[$field])) > 100) {
                        $data[$field] = $retry_data[$field];
                    }
                }
                LogService::log('info', 'Retry de secoes curtas para "' . $keyword . '" — campos: ' . $fields_list);
            }
        }

        return $data;
    }

    /**
     * Retorna array com nomes dos campos abaixo do comprimento minimo.
     */
    private function find_short_fields($data) {
        $short = [];
        foreach ($this->required_fields as $field => $min_len) {
            $value = $data[$field] ?? '';
            if (strlen(strip_tags($value)) < $min_len) {
                $short[] = $field;
            }
        }
        return $short;
    }

    /**
     * Insere imagens Replicate/Flux entre secoes H2 do conteudo.
     * Gera minimo 3 e maximo 5 imagens distribuidas entre as secoes.
     */
    private function insert_body_images($content, $keyword) {
        return $this->insert_body_images_v100($content, $keyword);
    }

    private function insert_body_images_v100($content, $keyword) {
        @set_time_limit(0);

        $parts = preg_split('/(?=<h2[\s>])/i', $content, -1, PREG_SPLIT_NO_EMPTY);
        $count = count($parts);
        if ($count < 4) return $content;

        $candidates = range(1, $count - 2);
        $target = min(ImageGeneratorService::body_images_count(), count($candidates));
        if ($target <= 0) return $content;

        // Lógica adaptativa: se interval é grande pra caber as imagens, reduz proporcionalmente
        $h2_interval_config = max(2, (int) ImageGeneratorService::h2_interval());
        $total = count($candidates);
        $max_with_config = (int) ceil($total / $h2_interval_config);
        $effective_interval = ($target > $max_with_config && $target > 1)
            ? max(2, (int) floor($total / $target))
            : $h2_interval_config;
        $min_distance = max(2, $effective_interval - 1);

        $positions = [];
        // Começa do intervalo efetivo
        for ($i = $effective_interval - 1; $i < $total && count($positions) < $target; $i += $effective_interval) {
            $positions[] = $candidates[$i];
        }
        // Preenche restantes com distância mínima adaptativa
        foreach ($candidates as $candidate) {
            if (count($positions) >= $target) break;
            if (in_array($candidate, $positions, true)) continue;
            $too_close = false;
            foreach ($positions as $p) {
                if (abs($candidate - $p) < $min_distance) { $too_close = true; break; }
            }
            if (!$too_close) $positions[] = $candidate;
        }
        sort($positions);

        $images_done = 0;
        foreach ($positions as $pos) {
            if (!isset($parts[$pos])) continue;
            $h2_text = trim(wp_strip_all_tags($parts[$pos]));
            if ($h2_text !== '' && preg_match('/faq|perguntas\s+frequentes|conclus[aã]o|próximos passos|o que fazer agora/iu', $h2_text)) continue;

            $attachment_id = $this->image->generate_body_attachment([
                'title' => $keyword,
                'keyword' => $keyword,
                'section' => $h2_text,
            ]);
            if (is_wp_error($attachment_id) || !$attachment_id) continue;

            $alt = trim($keyword . ' - ' . $h2_text);
            $img_html = \GeoMetodoSEO\Services\LibraryImageService::build_attachment_figure_html((int)$attachment_id, $alt, ['sara-internal-queued-image'], ['style' => 'max-width:100%;height:auto;border-radius:6px;'], '');
            if ($img_html === '') continue;
            \GeoMetodoSEO\Services\LibraryImageService::remember_body_image($post_id, (int)$attachment_id, \GeoMetodoSEO\Services\LibraryImageService::is_library_mode() ? 'library' : 'ai');

            $parts[$pos] = $img_html . $parts[$pos];
            $images_done++;
            if ($images_done < count($positions)) sleep(1);
        }

        return implode('', $parts);
    }

    /**
     * Busca 2 artigos relacionados e insere cards ao final do post_content.
     * Prioriza artigos da mesma categoria; fallback por keyword similar.
     */
    private function insert_related_articles($post_id, $keyword) {
        $categories = wp_get_post_categories($post_id, ['fields' => 'ids']);
        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 4,
            'post__not_in'   => [$post_id],
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ];

        if (!empty($categories)) {
            $args['category__in'] = $categories;
        } else {
            // Fallback: search by keyword words
            $args['s'] = implode(' ', array_slice(explode(' ', $keyword), 0, 3));
        }

        $related_ids = get_posts($args);

        // If category search returned nothing, try keyword search
        if (empty($related_ids) && !empty($categories)) {
            $args2 = $args;
            unset($args2['category__in']);
            $args2['s'] = implode(' ', array_slice(explode(' ', $keyword), 0, 3));
            $related_ids = get_posts($args2);
        }

        $related_ids = array_slice($related_ids, 0, 2);
        if (empty($related_ids)) {
            return;
        }

        $cards_html = '<div class="geo-related-articles" style="margin-top:40px;padding:24px;background:transparent;border:1px solid rgba(148,163,184,.35);border-radius:8px;">'
                    . '<h3 style="margin:0 0 16px;font-size:18px;color:inherit;">Artigos Relacionados</h3>'
                    . '<div style="display:flex;gap:16px;flex-wrap:wrap;">';

        foreach ($related_ids as $rid) {
            $r_title     = get_the_title($rid);
            $r_permalink = get_permalink($rid);
            $r_date      = get_the_date('d/m/Y', $rid);
            $r_thumb     = get_the_post_thumbnail_url($rid, 'medium');

            $thumb_html = '';
            if ($r_thumb) {
                $thumb_html = '<img src="' . esc_url($r_thumb) . '" alt="' . esc_attr($r_title) . '"'
                            . ' style="width:100%;height:140px;object-fit:cover;border-radius:6px 6px 0 0;display:block;">';
            } else {
                $thumb_html = '<div style="width:100%;height:140px;background:#ddd;border-radius:6px 6px 0 0;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:32px;">📄</div>';
            }

            $cards_html .= '<a href="' . esc_url($r_permalink) . '" style="flex:1;min-width:200px;max-width:300px;text-decoration:none;color:inherit;">'
                         . '<div style="background:transparent;border:1px solid rgba(148,163,184,.35);border-radius:6px;overflow:hidden;height:100%;">'
                         . $thumb_html
                         . '<div style="padding:12px;">'
                         . '<strong style="font-size:14px;line-height:1.4;display:block;color:inherit;">' . esc_html($r_title) . '</strong>'
                         . '<span style="font-size:12px;color:inherit;opacity:.72;margin-top:6px;display:block;">' . esc_html($r_date) . '</span>'
                         . '</div></div></a>';
        }

        $cards_html .= '</div></div>';

        $post = get_post($post_id);
        if ($post) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                'ID'           => $post_id,
                'post_content' => $post->post_content . "\n\n" . $cards_html,
            ]);
        }
    }

    /**
     * MPC path: uses MPCContentGenerator (6 focused AI calls).
     */
    private function process_mpc(string $keyword, string $language, string $post_status, string $scheduled_at, string $tone, string $resolved_category, string $size = 'large') {
        $mpc  = new MPCContentGenerator($this->ai, $this->provider, $this->model);
        $data = $mpc->generate($keyword, $language, $tone ?: 'profissional', $size);

        $title   = $data['title'];
        $content = $data['content'];
        $excerpt = $data['meta'];
        $slug    = $data['slug'];

        // FIX v1.0.0-STABILITY: detectar H2 vazios e expandir antes de criar o post.
        if (false && class_exists('\GeoMetodoSEO\Services\H2QualityValidator')) {
            $h2_check = \GeoMetodoSEO\Services\H2QualityValidator::detect_thin_h2s($content);
            if ($h2_check['has_problem']) {
                \GeoMetodoSEO\Services\H2QualityValidator::log_problem($h2_check, 0, 'mpc_pipeline');
                $expand_prompt   = \GeoMetodoSEO\Services\H2QualityValidator::build_expand_prompt($content, $h2_check['problematic_h2s'], $keyword);
                $expand_response = $this->ai->generateText($expand_prompt, $this->provider, $this->model);
                if ($expand_response && !$expand_response->hasError()) {
                    $expanded = trim($expand_response->getContent());
                    if ($expanded !== '' && strpos($expanded, '<h2') !== false) {
                        $content = $expanded;
                        LogService::record('pipeline', 'success', '[mpc_pipeline] H2 vazios corrigidos', ['action' => 'h2_thin_fixed', 'context' => ['keyword' => $keyword]]);
                    }
                }
            }
        }

        $post_id = $this->repo->create($title, $content, $excerpt, $slug, $post_status, $scheduled_at, $resolved_category, $keyword);

        if (is_wp_error($post_id)) {
            LogService::log('error', 'MPC Pipeline: falha ao criar post para "' . $keyword . '"');
            return false;
        }

        update_post_meta($post_id, '_geo_keyword',  $keyword);
        update_post_meta($post_id, '_geo_provider', $this->provider);
        update_post_meta($post_id, '_geo_text_provider_used', $this->provider);
        update_post_meta($post_id, '_geo_text_model_used', (string)($this->model ?: ''));
        update_post_meta($post_id, '_geo_generation_context', 'article_pipeline');
        update_post_meta($post_id, '_geo_language', $language);
        update_post_meta($post_id, '_geo_tone',     $tone);
        update_post_meta($post_id, '_geo_category', $resolved_category);
        update_post_meta($post_id, '_geo_mpc',      1);

        LicenseManager::incrementTrialCount();

        // Links internos via LinkOrchestrator
        $saved_content  = get_post_field('post_content', $post_id);
        $linked_content = $this->linking->process($post_id, $saved_content);

        // FIX-LINKS: Links externos de autoridade no MPC (mesmo padrão do Individual)
        // Usar o CONTEÚDO REAL ($linked_content) para casar só entidades citadas.
        $ext_links_mpc = \GeoMetodoSEO\Services\ContextEngine::get_authority_links($keyword, $linked_content);
        if (!empty($ext_links_mpc)) {
            $already = false;
            foreach ($ext_links_mpc as $el) {
                if (!empty($el['url']) && stripos($linked_content, $el['url']) !== false) {
                    $already = true; break;
                }
            }
            if (!$already) {
                $ins = 0;
                foreach (array_slice($ext_links_mpc, 0, 2) as $link) {
                    if ($ins >= 2 || empty($link['url']) || empty($link['anchor'])) break;
                    $linked_content = preg_replace(
                        '/(<\/p>)/i',
                        ' Fonte: <a href="' . esc_url($link['url']) . '" rel="noopener noreferrer" target="_blank">' . esc_html($link['anchor']) . '</a>.$1',
                        $linked_content, 1
                    );
                    $ins++;
                }
            }
        }

        // Imagens de corpo ficam na fila global para evitar 504 no AJAX.
        $final_content = $linked_content;

        if ($final_content !== $saved_content) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $final_content]);
        }

        // Featured image (com logging)
        LogService::record('generator', 'info',
            "MPC: Iniciando imagem destaque para post #{$post_id}",
            ['post_id' => $post_id, 'action' => 'featured_image_start_mpc']);

        $image_id = $this->image->generate_featured_attachment([
            'title' => $title,
            'keyword' => $keyword,
            'category' => $resolved_category,
        ], (int) $post_id);
        $image_id = is_wp_error($image_id) ? 0 : (int) $image_id;
        if ($image_id) {
            $is_library = class_exists('\GeoMetodoSEO\Services\LibraryImageService')
                && \GeoMetodoSEO\Services\LibraryImageService::is_library_mode();
            if (!$is_library) {
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::set_featured_image($post_id, $image_id);
            }
            LogService::record('generator', 'success',
                "MPC: Imagem destaque setada (attachment #{$image_id})",
                ['post_id' => $post_id, 'action' => 'featured_image_set_mpc']);
        } else {
            LogService::record('generator', 'error',
                "MPC: Imagem destaque NÃO foi gerada — verifique logs dos providers de imagem",
                ['post_id' => $post_id, 'action' => 'featured_image_failed_mpc']);
        }

        $this->seo->apply($post_id, $title, $keyword, $excerpt);
        $this->eeat->apply($post_id);
        $this->insert_related_articles($post_id, $keyword);

        if (class_exists('GeoMetodoSEO\Autopilot\Writer\SaraGlobalPostProcessor')) {
            \GeoMetodoSEO\Autopilot\Writer\SaraGlobalPostProcessor::finalize($post_id, [
                'title' => $title,
                'keyword' => $keyword,
                'category' => $resolved_category,
                'niche' => get_option('sara_niche', 'Tecnologia'),
                'target_words' => isset($target_words) ? $target_words : 2500,
                'enable_faq' => true,
                'internal_image_count' => ImageGeneratorService::body_images_count(),
                // Body images processadas na hora em ambos os modos (IA e Biblioteca).
                'process_internal_images_now' => true,
                'use_featured_as_body_fallback' => false,
                'embed_youtube_video' => $this->embed_youtube_video,
            ]);
        }

        LogService::log('success', 'MPC: artigo gerado "' . $title . '" (keyword: ' . $keyword . ')', $post_id);
        do_action('geo_article_generated', $post_id, $keyword);

        return $post_id;
    }

    /**
     * Insere imagens no corpo pela cadeia oficial Replicate -> Fal.ai.
     */
    /**
     * Método público para inserir imagens no corpo — usado pela SARA após reescrita.
     */
    public function insert_body_images_public($post_id, $content, $keyword) {
        try {
            return $this->insert_body_images_with_fallback($content, $keyword);
        } catch (\Exception $e) {
            \GeoMetodoSEO\Services\LogService::log('error', 'insert_body_images_public: ' . $e->getMessage());
            return $content;
        }
    }

    private function insert_body_images_with_fallback($content, $keyword) {
        return $content;
    }

    // ── Métodos auxiliares para geração HTML única ─────────────────────────

    private static function build_outline(string $keyword, int $n_h2, string $category): string {
        $year = date('Y');
        $sections = [
            "O que é {$keyword} e por que importa em {$year}",
            "Como funciona na prática — passo a passo",
            "Dados, comparativos e benchmarks reais",
            "Benefícios principais e resultados esperados",
            "Guia prático: como implementar corretamente",
            "Erros comuns e como evitá-los",
            "Tendências e o que esperar em " . ($year + 1),
            "Casos de uso e exemplos reais",
            "Conclusão e próximos passos",
        ];
        $selected = array_slice($sections, 0, $n_h2);
        return implode("\n", array_map(fn($i, $s) => ($i+1) . ". {$s}", array_keys($selected), $selected));
    }

    private static function clean_html_response(string $html): string {
        $html = trim($html);
        // Remover markdown fences se existirem
        $html = preg_replace('/^```(?:html|xml|markdown)?\s*/im', '', $html);
        $html = preg_replace('/\s*```\s*$/im', '', $html);
        // Remover H1 (plugin cuida do título separado)
        $html = preg_replace('/<h1[^>]*>.*?<\/h1>/is', '', $html);
        // Remover JSON acidental no início
        if (strpos(trim($html), '{') === 0) {
            $start = strpos($html, '<');
            if ($start !== false) $html = substr($html, $start);
        }
        return trim($html);
    }

    /** Remove o comentário <!-- TITLE: ... --> do corpo (após o título já ter sido extraído). */
    private static function strip_title_comment(string $html): string {
        return trim(preg_replace('/<!--\s*TITLE:.*?-->\s*/is', '', $html));
    }

    private static function extract_title_from_html(string $html, string $keyword): string {
        // 1. Procurar um comentário de título embutido: <!-- TITLE: ... -->
        if (preg_match('/<!--\s*TITLE:\s*(.+?)\s*-->/is', $html, $m)) {
            $t = trim(wp_strip_all_tags($m[1]));
            // Aceitar a partir de 10 caracteres (alguns títulos bons são curtos)
            if (mb_strlen($t) >= 10) return self::polish_title($t);
        }
        // 2. Procurar um H1 (caso o modelo tenha colocado, embora seja removido depois)
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $t = trim(wp_strip_all_tags($m[1]));
            if (mb_strlen($t) >= 10) return self::polish_title($t);
        }
        // 3. Sem título da IA → construir um título COMPLETO a partir da keyword,
        //    não apenas a keyword crua. Gera algo como o Google recomenda.
        return self::build_seo_title_from_keyword($keyword);
    }

    /**
     * Constrói um título H1 completo e atraente a partir da keyword/tema, quando
     * a IA não forneceu um. Em vez de usar a keyword crua ("freelance writing tips"),
     * gera um título de verdade ("Freelance Writing Tips: Guia Completo [2026]").
     */
    private static function build_seo_title_from_keyword(string $keyword): string {
        $kw = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($keyword)));
        if ($kw === '') return 'Artigo';

        // Remover marcadores comuns de query de oportunidade (HARO, etc)
        $kw = preg_replace('/^\s*(re:|fwd:|request:|query:|source needed:?|looking for:?)\s*/iu', '', $kw);
        $kw = trim($kw, " \t\n\r\0\x0B-–—:");

        // Se já é uma pergunta ou frase completa (tem verbo/estrutura), só capitaliza
        $is_question = (bool) preg_match('/^(como|o que|por que|porque|quando|onde|quais|qual|quem|vale a pena|quanto|how|what|why|when|where|which|who)\b/iu', $kw);
        $has_structure = str_word_count($kw) >= 5 || $is_question;

        // Capitalização inteligente (mantém siglas GEO/SEO/IA/AEO/LLM/API maiúsculas)
        $title = self::smart_title_case($kw);

        if ($has_structure) {
            // Já parece um título/pergunta — usar como está (sem sufixo redundante)
            return mb_substr($title, 0, 120);
        }

        // Keyword curta/seca → enriquecer com um complemento + ano, formato SEO.
        $year = date('Y');
        $complementos = [
            ': Guia Completo',
            ': Guia Completo para ' . $year,
            ': Tudo o Que Você Precisa Saber',
            ': O Guia Definitivo',
        ];
        // Escolher de forma estável (baseado no tamanho) para não variar a cada chamada
        $idx = mb_strlen($kw) % count($complementos);
        $title .= $complementos[$idx];

        return mb_substr($title, 0, 120);
    }

    /** Title Case preservando siglas conhecidas em maiúsculas. */
    private static function smart_title_case(string $text): string {
        $acronyms = ['GEO','SEO','AEO','GEO','IA','AI','LLM','API','SaaS','WordPress','YouTube','ChatGPT','SERP','HARO','CTR','ROI','B2B','B2C','SEO/GEO'];
        $minor = ['de','da','do','das','dos','e','o','a','os','as','para','com','em','no','na','por','que','the','of','for','and','to','in','on','a','an'];
        $words = preg_split('/\s+/u', $text);
        $out = [];
        foreach ($words as $i => $w) {
            $upper = mb_strtoupper($w);
            // Sigla conhecida → manter maiúscula
            $matched = false;
            foreach ($acronyms as $ac) {
                if ($upper === mb_strtoupper($ac)) { $out[] = $ac; $matched = true; break; }
            }
            if ($matched) continue;
            $lower = mb_strtolower($w);
            // Palavra menor (preposição/artigo) no meio → minúscula
            if ($i > 0 && in_array($lower, $minor, true)) { $out[] = $lower; continue; }
            // Caso normal → primeira letra maiúscula
            $out[] = mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
        }
        return implode(' ', $out);
    }

    /**
     * Transforma a keyword em um título apresentável.
     * Ex: "como usar geo metodo seo" → "Como Usar GEO Método SEO: Guia Completo"
     */
    private static function polish_title(string $raw): string {
        $t = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($raw)));
        if ($t === '') return 'Artigo';
        // Capitalizar primeira letra mantendo siglas (GEO, SEO, AEO, IA)
        $t = mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
        return mb_substr($t, 0, 120);
    }

    private static function extract_excerpt_from_html(string $html): string {
        // Primeiro parágrafo como excerpt
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
            return mb_substr(wp_strip_all_tags($m[1]), 0, 200);
        }
        return '';
    }

    // Método legado — mantido para compatibilidade com process_mpc
    private function buildContent($data, $keyword) {

        // If a custom template is selected, build from its sections
        if ($this->template_id) {
            $sections = TemplateController::get_template_sections($this->template_id);
            if (!empty($sections)) {
                $content = '';
                foreach ($sections as $sec) {
                    $heading = str_replace('{{keyword}}', esc_html($keyword), $sec['heading']);
                    $content .= '<h2>' . esc_html($heading) . '</h2>' . "\n";
                    // Try to find matching data key by normalizing heading
                    $slug = sanitize_title($heading);
                    if (isset($data[$slug])) {
                        $content .= $data[$slug] . "\n\n";
                    } elseif (isset($data['introducao']) && mb_stripos($heading, 'intro') !== false) {
                        $content .= $data['introducao'] . "\n\n";
                    } elseif (isset($data['conclusao']) && mb_stripos($heading, 'conclu') !== false) {
                        $content .= $data['conclusao'] . "\n\n";
                    } else {
                        // Use a generic section from data if available
                        $fallback_keys = ['secao_definicao','secao_funcionamento','secao_beneficios','secao_guia'];
                        foreach ($fallback_keys as $fk) {
                            if (isset($data[$fk]) && !empty($data[$fk])) {
                                $content .= $data[$fk] . "\n\n";
                                unset($data[$fk]);
                                break;
                            }
                        }
                    }
                }
                return $content;
            }
        }

        // Default built-in template
        // Keyword curta para H2s: máximo 4 palavras
        $kw_words  = explode(' ', esc_html($keyword));
        $kw_curto  = implode(' ', array_slice($kw_words, 0, 4));
        if (count($kw_words) > 4) $kw_curto .= '...'; // indica que foi cortado
        $template = '<div class="geo-quick-answer" style="background:linear-gradient(135deg,rgba(0,200,255,0.08),rgba(139,92,246,0.08));border-left:4px solid #00C8FF;padding:18px 22px;margin:0 0 32px;border-radius:0 10px 10px 0;"><strong style="color:#00C8FF;display:block;margin-bottom:8px;">⚡ Resposta Rápida</strong>{{resumo}}</div>

{{introducao}}

<h2>O que é ' . $kw_curto . '</h2>
{{secao_definicao}}

<h2>Como Funciona</h2>
{{secao_funcionamento}}

<h2>Dados e Comparativo</h2>
{{tabela_comparativa}}

<h2>Benefícios Principais</h2>
{{secao_beneficios}}

<h2>Guia Prático: Como Escolher</h2>
{{secao_guia}}

{{dica_especialista}}

<h2>Erros Comuns e Como Evitar</h2>
{{erros_comuns}}

<h2>Tendências em {{year}}</h2>
{{tendencias_2026}}

<h2>Conclusão</h2>
{{conclusao}}

<h2>Perguntas Frequentes</h2>
{{faq_texto}}';

        return $this->template->process($template, [
            'keyword'          => esc_html($keyword),
            'year'             => wp_date('Y'),
            'resumo'           => $data['resumo_snippet']    ?? '',
            'introducao'       => $data['introducao']         ?? '',
            'secao_definicao'  => $data['secao_definicao']    ?? '',
            'secao_funcionamento' => $data['secao_funcionamento'] ?? '',
            'tabela_comparativa'  => $data['tabela_comparativa']  ?? '',
            'secao_beneficios' => $data['secao_beneficios']   ?? '',
            'secao_guia'       => $data['secao_guia']          ?? '',
            'dica_especialista'=> $data['dica_especialista']   ?? '',
            'erros_comuns'     => $data['erros_comuns']        ?? '',
            'tendencias_2026'  => $data['tendencias_2026']     ?? '',
            'conclusao'        => $data['conclusao']           ?? '',
            'faq_texto'        => $data['faq_texto']           ?? '',
        ]);
    }
}
