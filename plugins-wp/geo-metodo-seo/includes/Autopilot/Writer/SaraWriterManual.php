<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Writer;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\{AutopilotInstaller, AutopilotLogger};
use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher;

/**
 * SaraWriterManual â€” Modo manual do Agente B.
 * Geração imediata sem cron, sem fila, sem dependÃªncia do Brain.
 * Reutiliza SaraGenerator, SaraImageHandler, SaraSchemaManager (sem duplicar).
 *
 * @since 1.0.0
 */
class SaraWriterManual {

    private AIManager             $ai;
    private SaraImageHandler      $images;
    private SaraSchemaManager     $schema;
    private SaraContentValidator  $validator;
    private SaraDeepFAQGenerator  $faq_gen;

    public function __construct() {
        $this->ai        = new AIManager();
        $this->images    = new SaraImageHandler();
        $this->schema    = new SaraSchemaManager();
        $this->validator = new SaraContentValidator();
        $this->faq_gen   = new SaraDeepFAQGenerator();
    }

    /**
     * Gera um artigo completo a partir de input do usuário.
     *
     * @param array $input {
     *   title:           string  (obrigatÃ³rio, 30-100 chars)
     *   category_id:     int     (term_id da categoria)
     *   tone:            string  (profissional|casual|tecnico|persuasivo)
     *   word_count:      int     (2300-4000)
     *   gen_image:       bool
     *   gen_faq:         bool
     *   auto_publish:    bool    (true=publish, false=draft)
     * }
     * @return array Resultado com post_id, url, status, cost_usd, duration_ms
     */
    public function generate(array $input): array {
        @set_time_limit(180); // Evita corte por max_execution_time em servidores lentos
        $t0 = microtime(true);

        // GATE DE LICENÇA: plano vencido bloqueia o Writer Manual também.
        if (!\GeoMetodoSEO\License\LicenseManager::canGenerate()) {
            throw new \RuntimeException('Licença expirada ou inativa. Renove a licença para gerar artigos.');
        }

        // â”€â”€ ValidaÃ§Ã£o â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $title = trim((string)($input['title'] ?? ''));
        if (empty($title)) {
            throw new \InvalidArgumentException('Título Ã© obrigatÃ³rio.');
        }
        if (mb_strlen($title) < 30) {
            throw new \InvalidArgumentException('Título muito curto (mÃ­nimo 30 caracteres).');
        }
        if (mb_strlen($title) > 100) {
            throw new \InvalidArgumentException('Título muito longo (mÃ¡ximo 100 caracteres).');
        }

        $category_id  = (int)($input['category_id'] ?? 0);
        $tone         = sanitize_text_field($input['tone'] ?? 'profissional');
        // FIX v1.0.0-WORDCOUNT-ALL: respeitar word_count escolhido pelo usuário.
        // Antes: max(2300, min(4000, ...)) â†’ forÃ§ava mÃ­nimo 2300 mesmo quando user pedia 1500.
        // Agora: aceita 800-6000 palavras.
        $word_count   = max(800, min(6000, (int)($input['word_count'] ?? (int)AutopilotInstaller::get('conservative_word_count', 2300))));
        $gen_image    = !empty($input['gen_image']);
        // Seletor de fonte de imagem por geração (sobrepõe a opção global).
        if (!empty($input['use_library']) && class_exists('GeoMetodoSEO\\Services\\LibraryImageService')) {
            \GeoMetodoSEO\Services\LibraryImageService::set_mode_override('library');
        } elseif (array_key_exists('use_library', $input) && class_exists('GeoMetodoSEO\\Services\\LibraryImageService')) {
            // Checkbox presente mas desmarcado → forçar IA nesta geração
            \GeoMetodoSEO\Services\LibraryImageService::set_mode_override('ai');
        }
        $gen_faq      = array_key_exists('gen_faq', $input) ? !empty($input['gen_faq']) : true;
        $embed_video  = !empty($input['embed_video']);
        $auto_publish = !empty($input['auto_publish']);
        $text_provider = ProviderResolver::for('manual_writer', sanitize_text_field($input['provider'] ?? ''));
        $ai_model     = sanitize_text_field($input['ai_model'] ?? (ProviderResolver::modelFor('manual_writer', $text_provider) ?: ''));
        $strict_model = !empty($input['strict_model']) && $text_provider === 'openai';

        $tone_map = [
            'profissional' => 'tom profissional, autoridade e credibilidade',
            'casual'       => 'tom casual e conversacional, prÃ³ximo do leitor',
            'tecnico'      => 'tom tÃ©cnico com terminologia precisa do nicho',
            'persuasivo'   => 'tom persuasivo com gatilhos mentais e CTAs fortes',
        ];
        $tone_inst = $tone_map[$tone] ?? $tone_map['profissional'];

        $keyword = $this->extract_keyword($title);
        $niche   = get_option('sara_niche', 'Tecnologia');
        AutopilotLogger::log('writer', 'word_count_target', 'info',
            "Meta manual: {$word_count} palavras", ['target_words' => $word_count]);

        AutopilotLogger::log('writer', 'manual_start', 'start',
            "Geração manual iniciada: {$title}");

        // â”€â”€ 1. Gerar conteúdo HTML com validaÃ§Ã£o rÃ¡pida â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // FIX 504: uma Ãºnica chamada de IA. Retentativas completas podem estourar o gateway.
        $content       = '';
        $max_attempts  = 1;
        $validation    = null;
        $prev_errors   = [];

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            // 1.0.0: respeitar a meta escolhida pelo usuário.
            // NÃ£o aumentar automaticamente para 1.20/1.35, pois isso fazia GPT-5.5 gerar artigos de 4.000+ palavras mesmo com meta 2.300.
            $target_words = $word_count;

            if ($this->should_stop_for_timeout($t0)) {
                throw new \RuntimeException('Tempo seguro de geraÃ§Ã£o esgotado antes da chamada de IA.');
            }
            AutopilotLogger::log('writer', 'ai_call_started', 'info',
                "Chamada de IA iniciada via {$text_provider}", ['target_words' => $target_words, 'model' => $ai_model]);

            $content = $this->generate_content(
                $title, $keyword, $niche, $tone_inst, $target_words, $prev_errors, $ai_model, $strict_model, $text_provider
            );
            AutopilotLogger::log('writer', 'ai_call_finished', 'success',
                'Chamada de IA finalizada', ['elapsed_ms' => (int)round((microtime(true) - $t0) * 1000)]);

            if ($this->should_stop_for_timeout($t0)) {
                $content = $this->normalize_html((string)$content);
                return $this->save_partial_draft_for_timeout($title, $content, $category_id, $keyword, $word_count, $t0, 'after_ai_call');
            }

            if (!$content || strlen(strip_tags($content)) < 500) {
                AutopilotLogger::log('writer', 'manual_short', 'warning',
                    "Tentativa {$attempt}: conteúdo curto");
                $prev_errors = ['ConteÃºdo veio curto demais â€” escreva o ARTIGO COMPLETO sem cortar'];
                continue;
            }

            // Sanitizar: converter markdown literal para HTML real (caso a IA tenha falhado)
            $content = $this->normalize_html($content);

            // Se passou da meta, cortar localmente sem nova chamada de IA.
            $max_allowed_words = (int)ceil($word_count * 1.15);
            $actual_manual_words = $this->count_words_local($content);
            if ($actual_manual_words > $max_allowed_words) {
                $content = $this->limit_content_words_local($content, $max_allowed_words);
            }

            if ($this->should_stop_for_timeout($t0)) {
                return $this->save_partial_draft_for_timeout($title, $content, $category_id, $keyword, $word_count, $t0, 'before_validation');
            }
            // Validar com SaraContentValidator
            $validation = $this->validator->validate($content, $title, $word_count);

            if ($validation['valid']) {
                AutopilotLogger::log('writer', 'manual_validated', 'success',
                    "Tentativa {$attempt}: aprovado ({$validation['actual_words']} palavras)");
                break;
            }

            // Salvar erros para passar como feedback Ã  IA na prÃ³xima tentativa
            $prev_errors = $validation['errors'] ?? [];

            AutopilotLogger::log('writer', 'manual_rejected', 'warning',
                "Tentativa {$attempt} rejeitada: " . implode(' | ', $prev_errors));
        }

        if (!$content || strlen(strip_tags($content)) < 500) {
            throw new \RuntimeException('IA retornou conteúdo muito curto apÃ³s retry.');
        }

        // Se ainda invÃ¡lido apÃ³s retry, registrar warnings mas seguir como rascunho
        $validation_warnings = [];
        if ($validation && !$validation['valid']) {
            $validation_warnings = $validation['errors'];
            $auto_publish = false; // forÃ§ar rascunho se houver problemas
            AutopilotLogger::log('writer', 'manual_force_draft', 'warning',
                'ForÃ§ando rascunho devido a problemas de validaÃ§Ã£o: ' . implode(' | ', $validation['errors']));
        }

        // â”€â”€ 2. Criar post no WordPress â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $publish_result = GeoMetodoSEO_Publisher::publish([
            'title' => wp_strip_all_tags($title),
            'content' => $content,
            'excerpt' => mb_substr(wp_strip_all_tags($content), 0, 200),
            'status' => $auto_publish ? 'publish' : 'draft',
            'post_type' => 'post',
            'author_id' => get_current_user_id() ?: 1,
            'category_ids' => $category_id > 0 ? [$category_id] : [],
            'focus_keyword' => $keyword,
            'seo_title' => $title,
            'meta_description' => mb_substr(wp_strip_all_tags($content), 0, 160),
            'source_module' => 'writer_manual',
            'custom_meta' => [
                '_geo_keyword' => $keyword,
                '_sara_manual' => '1',
                '_sara_tone' => $tone,
                '_sara_requested_model' => $ai_model,
                '_geo_text_provider_used' => $text_provider,
                '_geo_text_model_used' => $ai_model,
                '_geo_generation_context' => 'manual_writer',
                '_sara_strict_model' => $strict_model ? '1' : '0',
            ],
        ]);
        if (empty($publish_result['success'])) {
            throw new \RuntimeException('Falha ao criar post: ' . ($publish_result['error'] ?? 'Publisher central retornou erro.'));
        }
        $post_id = (int)$publish_result['post_id'];
        AutopilotLogger::log('writer', 'post_created_draft', 'success',
            "Post manual #{$post_id} criado", ['post_id' => $post_id, 'status' => $auto_publish ? 'publish' : 'draft']);

        // â”€â”€ 3. Meta SEO/GEO/AEO â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        update_post_meta($post_id, '_geo_keyword',     $keyword);
        update_post_meta($post_id, '_geo_optimized',   true);
        update_post_meta($post_id, '_sara_manual',     '1');
        update_post_meta($post_id, '_sara_tone',       $tone);
        update_post_meta($post_id, '_sara_requested_model', $ai_model);
        update_post_meta($post_id, '_geo_text_provider_used', $text_provider);
        update_post_meta($post_id, '_geo_text_model_used', $ai_model);
        update_post_meta($post_id, '_geo_generation_context', 'manual_writer');
        update_post_meta($post_id, '_sara_strict_model', $strict_model ? '1' : '0');
        update_post_meta($post_id, '_yoast_wpseo_focuskw',     $keyword);
        update_post_meta($post_id, '_yoast_wpseo_title',       mb_substr($title, 0, 60));
        update_post_meta($post_id, '_yoast_wpseo_metadesc',    mb_substr(wp_strip_all_tags($content), 0, 160));
        update_post_meta($post_id, 'rank_math_focus_keyword',  $keyword);
        update_post_meta($post_id, '_rank_math_focus_keyword', $keyword);
        update_post_meta($post_id, '_geo_keyword_exact',       $keyword);
        update_post_meta($post_id, 'rank_math_title',          mb_substr($title, 0, 60));
        update_post_meta($post_id, 'rank_math_description',    mb_substr(wp_strip_all_tags($content), 0, 160));

        // â”€â”€ 4. Preparar imagens, mas executar depois do FAQ â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // 1.0.0: o Writer Manual agora prioriza conteúdo + FAQ + autor antes das imagens,
        // para evitar que timeout de provedor de imagem deixe o artigo incompleto.
        $image_url = '';
        $img_id    = 0;

        // â”€â”€ 5. Schemas (Article + FAQPage se solicitado) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $plan = [
            'title'       => $title,
            'keyword'     => $keyword,
            'schema_type' => str_starts_with(strtolower($title), 'como') ? 'HowTo' : 'Article',
        ];
        $this->schema->apply($post_id, $plan, $content);
        if ($this->should_stop_for_timeout($t0)) {
            return $this->timeout_existing_post_result($post_id, $content, $word_count, $t0, 'after_schema');
        }

        // 1.0.0: FAQ oficial passa a ser aplicado globalmente ao final para evitar duplicidade.
        if (false && $gen_faq) {
            $faqs = $this->faq_gen->generate($title, get_the_category_by_ID($category_id) ?: 'geral', $niche);

            if (!empty($faqs)) {
                $faq_html = $this->faq_gen->render_html($faqs);
                $faq_schema = $this->faq_gen->build_schema($faqs);

                // Anexar FAQ HTML ao conteúdo
                $content = $content . "\n\n" . $faq_html;
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);

                // Schema FAQPage no meta (renderizado pelo wp_head do plugin)
                update_post_meta($post_id, 'geo_faq_schema', $faq_schema);
                update_post_meta($post_id, '_sara_faq_count', count($faqs));
                update_post_meta($post_id, '_aeo_faq_active', '1');
            }
        }
        // â”€â”€ 5b. Featured + imagem no corpo apÃ³s FAQ â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if ($gen_image) {
            if ($this->should_stop_for_timeout($t0)) {
                return $this->timeout_existing_post_result($post_id, $content, $word_count, $t0, 'before_images');
            }
            $img_id = (int) $this->images->set_featured_image($post_id, $keyword, $title);
            if ($img_id) {
                $image_url = wp_get_attachment_url($img_id) ?: '';
                update_post_meta($post_id, '_sara_featured_image_id', $img_id);
                update_post_meta($post_id, '_sara_featured_image_url', $image_url);
                // 1.0.0: não inserir a imagem destacada no corpo por padrÃ£o.
                // Imagens internas diferentes entram pela fila assÃ­ncrona global para evitar duplicidade visual.
                if ($image_url) {
                    update_post_meta($post_id, '_sara_featured_body_fallback_disabled', '1');
                }
            }
            update_post_meta($post_id, '_sara_body_images_count', substr_count($content, 'sara-body-image'));
        }

        // â”€â”€ 6. Links internos automÃ¡ticos (buscar posts da mesma cat) â”€â”€
        // (linkagem interna agora Ã© feita via add_related_internal_links apÃ³s geraÃ§Ã£o completa)

        // â”€â”€ 6c. Linkagem interna automÃ¡tica (5 artigos relacionados) â”€â”€
        if ($this->should_stop_for_timeout($t0)) {
            return $this->timeout_existing_post_result($post_id, $content, $word_count, $t0, 'before_internal_links');
        }
        $this->add_related_internal_links($post_id, $category_id, $keyword);

        // â”€â”€ 6d. Linkagem externa para autoridades (1-2 fontes oficiais) â”€
        if ($this->should_stop_for_timeout($t0)) {
            return $this->timeout_existing_post_result($post_id, $content, $word_count, $t0, 'before_authority_links');
        }
        $this->add_authority_external_links($post_id, $keyword);

        // â”€â”€ 6e. Bloco "Artigos Relacionados" ao final do conteúdo â”€â”€â”€â”€â”€
        if ($this->should_stop_for_timeout($t0)) {
            return $this->timeout_existing_post_result($post_id, $content, $word_count, $t0, 'before_related');
        }
        $this->append_related_articles_block($post_id, $category_id);

        // â”€â”€ 6f. Aplicar EEATEngine por Ãºltimo: caixa/ecossistema do autor no rodapÃ© real â”€
        if ($this->should_stop_for_timeout($t0)) {
            return $this->timeout_existing_post_result($post_id, $content, $word_count, $t0, 'before_author_box');
        }
        if (class_exists('GeoMetodoSEO\EEAT\EEATEngine')) {
            $eeat = new \GeoMetodoSEO\EEAT\EEATEngine();
            $eeat->apply($post_id);
            update_post_meta($post_id, '_sara_author_ecosystem_applied', '1');
        }

        // â”€â”€ 6g. Pós-processamento GLOBAL: FAQ Ãºnico, imagem no corpo sem duplicar mÃ­dia,
        // fila assÃ­ncrona de imagens internas e autor/ecossistema no rodapÃ© real â”€
        if ($this->should_stop_for_timeout($t0)) {
            return $this->timeout_existing_post_result($post_id, $content, $word_count, $t0, 'before_global_finalize');
        }
        if (class_exists('GeoMetodoSEO\Autopilot\Writer\SaraGlobalPostProcessor')) {
            $content = SaraGlobalPostProcessor::finalize($post_id, [
                'title' => $title,
                'keyword' => $keyword,
                'category' => get_the_category_by_ID($category_id) ?: 'geral',
                'niche' => $niche,
                'target_words' => $word_count,
                'enable_faq' => $gen_faq,
                'embed_youtube_video' => $embed_video,
                // 1.0.0: hardcoded 4 substituÃ­do pela option configurÃ¡vel (Regra #9 v1.0.0).
                'internal_image_count' => $gen_image ? \GeoMetodoSEO\Services\ImageGeneratorService::body_images_count() : 0,
                // Body images processadas na hora (Replicate ~1s cada). Antes só em modo Biblioteca.
                'process_internal_images_now' => true,
            ]);
            update_post_meta($post_id, '_sara_body_images_count', substr_count($content, 'sara-body-image'));
        }

        // â”€â”€ 7. Indexar no Ã­ndice semÃ¢ntico â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if (class_exists('GeoMetodoSEO\Autopilot\Brain\SaraIndexer')) {
            if ($this->should_stop_for_timeout($t0)) {
                return $this->timeout_existing_post_result($post_id, $content, $word_count, $t0, 'before_indexer');
            }
            $indexer = new \GeoMetodoSEO\Autopilot\Brain\SaraIndexer();
            $post    = get_post($post_id);
            if ($post) {
                $indexer->index_post([
                    'ID'            => $post_id,
                    'post_title'    => $post->post_title,
                    'post_date'     => $post->post_date,
                    'post_modified' => $post->post_modified,
                    'post_excerpt'  => $post->post_excerpt,
                    'post_content'  => $post->post_content,
                ]);
            }
        }

        $duration_ms = (int)((microtime(true) - $t0) * 1000);
        $cost_usd    = $this->estimate_cost($word_count, $gen_image);

        AutopilotLogger::log('writer', 'manual_done', 'success',
            "Manual: post #{$post_id} criado ({$word_count} palavras alvo)", [
                'post_id'     => $post_id,
                'duration_ms' => $duration_ms,
                'cost_usd'    => $cost_usd,
            ]
        );

        $word_count_real = class_exists('GeoMetodoSEO\\Autopilot\\Writer\\SaraGlobalPostProcessor') ? SaraGlobalPostProcessor::count_words($content) : str_word_count(strip_tags($content));

        return [
            'success'         => true,
            'post_id'         => $post_id,
            'post_url'        => get_permalink($post_id),
            'edit_url'        => get_edit_post_link($post_id, 'raw'),
            'preview_url'     => get_preview_post_link($post_id),
            'status'          => $auto_publish ? 'published' : 'draft',
            'word_count_real' => $word_count_real,
            'word_count_target' => $word_count,
            'model_requested'   => $ai_model,
            'strict_model'      => $strict_model,
            'image_url'       => $image_url,
            'cost_usd'        => $cost_usd,
            'duration_ms'     => $duration_ms,
            'preview_html'    => $content,
            'warnings'        => $validation_warnings,
        ];
    }

    /**
     * Gerar conteúdo HTML completo via IA usando o prompt do arquivo.
     *
     * @since 1.0.0 Aceita $prev_errors para feedback Ã  IA em retries
     */
    private function generate_content(
        string $title,
        string $keyword,
        string $niche,
        string $tone_inst,
        int    $word_count,
        array  $prev_errors = [],
        string $ai_model = '',
        bool   $strict_model = false,
        string $text_provider = ''
    ): string {
        $prompt_file = GEO_METODO_SEO_PATH . 'sara-autopilot/prompts/writer/writer-prompt.txt';
        $base = file_exists($prompt_file) ? file_get_contents($prompt_file) : '';

        if (empty($base)) {
            $base = "VocÃª Ã© redator sÃªnior. Escreva artigo profundo, original, com orientacao pratica e linguagem cautelosa; nao invente dados.";
        }

        // 1.0.0 BUG FIX: Idioma do site forÃ§ado (antes ignorava â†’ sempre saÃ­a em PT)
        $language  = AutopilotInstaller::get('site_language', 'pt-BR');
        $lang_map  = [
            'pt-BR' => 'portuguÃªs brasileiro',
            'pt-PT' => 'portuguÃªs europeu',
            'en-US' => 'American English',
            'en-GB' => 'British English',
            'es-ES' => 'espaÃ±ol',
            'es-MX' => 'espaÃ±ol mexicano',
            'fr-FR' => 'franÃ§ais',
            'de-DE' => 'Deutsch',
            'it-IT' => 'italiano',
        ];
        $native_name = $lang_map[$language] ?? 'portuguÃªs brasileiro';
        $lang_block  = "ðŸŒ IDIOMA OBRIGATÃ“RIO: Escreva o artigo INTEIRAMENTE em {$native_name} ({$language}).\n"
                     . "TODA palavra, tÃ­tulo, lista, conclusÃ£o deve estar em {$native_name}. "
                     . "NÃƒO use outro idioma. NÃƒO traduza sÃ³ uma parte. NÃƒO misture.";

        $h2_word_count = (int) max(120, floor($word_count / 6));

        // Substituir variÃ¡veis do template (FAQ Ã© gerado separadamente)
        $prompt = $lang_block . "\n\n" . str_replace(
            [
                '[NICHO]', '[TITULO]', '[TITLE]', '[KEYWORD]', '[TOM]',
                '[WORD_COUNT]', '[TARGET_WORDS]', '[H2_WORD_COUNT]',
                '[IDIOMA]', '[LANGUAGE]', '[CATEGORIA]', '[CATEGORY]'
            ],
            [
                $niche, $title, $title, $keyword, $tone_inst,
                (string)$word_count, (string)$word_count, (string)$h2_word_count,
                $native_name, $native_name, $niche, $niche
            ],
            $base
        );
        $prompt = preg_replace('/\[[A-Z_]*WORD_COUNT[A-Z_]*\]/', (string)$word_count, $prompt) ?? $prompt;

        // BUG FIX 1.0.0: ReforÃ§o de qualidade no prompt â€” evitar artigos
        // genÃ©ricos rÃ¡pidos. Instrui a IA a pensar antes de escrever.
        $quality_boost = "\n\nâ•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•"
                       . "\nâš ï¸ INSTRUÃ‡Ã•ES FACTUAIS E DE QUALIDADE OBRIGATÃ“RIAS"
                       . "\nâ•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•"
                       . "\nâ€¢ Meta: aproximadamente {$word_count} palavras; não corte o artigo antes de cobrir a intenÃ§Ã£o de busca"
                       . "\nâ€¢ Escreva no PRESENTE e de forma atual/atemporal quando o tema for guia, compra, tecnologia ou tutorial"
                       . "\nâ€¢ NÃƒO transforme guia atual em texto histÃ³rico; evite 'foi', 'era', 'antigamente', 'ao longo dos anos' quando não houver pedido histÃ³rico"
                       . "\nâ€¢ REGRA FATAL: NÃƒO invente nÃºmeros, percentuais, preÃ§os, datas, fichas tÃ©cnicas, rankings, benchmarks, fontes, estudos, casos reais, nomes de pessoas, cidades, mÃ©todos prÃ³prios ou lanÃ§amentos"
                       . "\nâ€¢ PROIBIDO criar personagem/caso real, exemplo com nome completo, cidade, valores ou histÃ³ria de cliente. Se precisar, use apenas cenÃ¡rio hipotÃ©tico sem nome"
                       . "\nâ€¢ PROIBIDO citar Statista, Kantar, IDC, Gartner, McKinsey, DXOMARK, Xiaomi ou qualquer fonte sem URL/contexto fornecido no briefing"
                       . "\nâ€¢ PROIBIDO criar mÃ©todo autoral inventado, siglas prÃ³prias ou frameworks não existentes no projeto"
                       . "\nâ€¢ Se algo não estiver confirmado, use linguagem cautelosa: 'pode', 'costuma', 'depende do modelo', 'verifique no site oficial'"
                       . "\nâ€¢ Para Xiaomi/smartphones: fale por critÃ©rios verificÃ¡veis â€” atualizaÃ§Ã£o, suporte, bateria, cÃ¢mera, desempenho, garantia, assistÃªncia, armazenamento e perfil de uso"
                       . "\nâ€¢ PROIBIDO frases vazias: 'Ã© fundamental', 'Ã© importante', 'Ã© essencial', 'Ã  frente da curva', 'no mundo de hoje', 'cada vez mais'"
                       . "\nâ€¢ PROIBIDO clichÃªs: 'guia completo', 'tudo sobre', 'melhores prÃ¡ticas'"
                       . "\nâ€¢ Cada H2 deve responder uma dÃºvida real e ter conteúdo Ãºtil"
                       . "\nâ€¢ Inclua 3-5 H2 com H3s aninhados quando fizer sentido"
                       . "\nâ€¢ Tom: {$tone_inst}"
                       . "\nâ€¢ ðŸŒ IDIOMA: Tudo em {$native_name} (lembrete final)"
                       . "\n\nâ•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•"
                       . "\nðŸ›¡ï¸ GOOGLE MARCH 2026 CORE+SPAM UPDATE â€” REGRAS CRÃTICAS"
                       . "\nâ•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•"
                       . "
â€¢ ORIGINALIDADE: cada parÃ¡grafo deve trazer orientaÃ§Ã£o prÃ¡tica, critÃ©rio de decisÃ£o ou explicaÃ§Ã£o Ãºtil sem inventar dados"
                       . "
â€¢ NÃ£o use estatÃ­sticas, porcentagens, valores, datas futuras, estudos ou benchmarks sem fonte verificÃ¡vel fornecida no briefing"
                       . "
â€¢ Se não houver dado confirmado, prefira linguagem segura: 'pode ajudar', 'costuma funcionar', 'depende do contexto', 'verifique em fonte oficial'";

        // BUG FIX 1.0.0: Se houve erros em tentativas anteriores, alimentar a IA
        if (!empty($prev_errors)) {
            $errors_str = implode("\n  - ", $prev_errors);
            $quality_boost .= "\n\nâ•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•"
                           . "\nðŸ” TENTATIVA ANTERIOR FOI REJEITADA POR:"
                           . "\nâ•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•"
                           . "\n  - {$errors_str}"
                           . "\n\nCorrija TODOS esses problemas nesta nova versÃ£o.";
        }

        $prompt .= $quality_boost;

        $model = $ai_model ?: (ProviderResolver::modelFor('manual_writer', $text_provider) ?: '');
        $provider = ProviderResolver::for('manual_writer', $text_provider);

        if ($strict_model) {
            $response = $this->generate_openai_strict($prompt, $model);
            if ($response && !$response->hasError() && trim((string)$response->getContent()) !== '') {
                update_option('sara_last_manual_model_used', $model, false);
                AutopilotLogger::log('writer', 'manual_model_strict', 'success', 'Writer Manual usou modelo OpenAI forÃ§ado: ' . $model);
                $content = trim($response->getContent());
                $content = preg_replace('/^```html?\s*/i', '', $content);
                $content = preg_replace('/^```\s*/i', '', $content);
                $content = preg_replace('/```\s*$/i', '', $content);
                return trim($content);
            }
            $err = $response ? $response->getError() : 'sem resposta';
            throw new \RuntimeException('Modelo forÃ§ado falhou (' . $model . '): ' . $err . '. Desative "forÃ§ar modelo" para permitir o provider configurado.');
        }

        $response = $this->ai->generateText($prompt, $provider, $model);
        if (!$response || $response->hasError() || trim((string)$response->getContent()) === '') {
            $detail = $response ? $response->getError() : 'sem resposta';
            throw new \RuntimeException('IA falhou via ' . $provider . ': ' . $detail);
        }

        $content = trim($response->getContent());

        // Limpar fences e garantir HTML puro
        $content = preg_replace('/^```(?:html?|json|xml)?\s*/im', '', $content);
        $content = preg_replace('/\s*```\s*$/im', '', $content);
        // Se retornou JSON em vez de HTML, tentar extrair conteúdo relevante
        if (strpos(trim($content), '{') === 0) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                // Concatenar campos de conteúdo do JSON
                $html_parts = [];
                foreach (['introducao','secao_definicao','secao_funcionamento','tabela_comparativa','secao_beneficios','secao_guia','erros_comuns','tendencias_2026','conclusao'] as $k) {
                    if (!empty($decoded[$k])) $html_parts[] = $decoded[$k];
                }
                if (!empty($html_parts)) {
                    $content = implode("\n\n", $html_parts);
                    AutopilotLogger::log('writer', 'json_fallback_used', 'warning', '[writer_manual] Provider retornou JSON â€” convertido para HTML');
                }
            }
        }
        $content = trim($content);

        // FIX v1.0.0-STABILITY: detectar H2 vazios e expandir.
        // Antes do fix, Writer Manual salvava artigos com H2 vazios silenciosamente.
        if (false && class_exists('\GeoMetodoSEO\Services\H2QualityValidator')) {
            $h2_check = \GeoMetodoSEO\Services\H2QualityValidator::detect_thin_h2s($content);
            if ($h2_check['has_problem']) {
                \GeoMetodoSEO\Services\H2QualityValidator::log_problem($h2_check, 0, 'writer_manual');
                $kw = $this->extract_keyword_from_prompt($prompt);
                $expand_prompt = \GeoMetodoSEO\Services\H2QualityValidator::build_expand_prompt($content, $h2_check['problematic_h2s'], $kw);
                $expand_response = $this->ai->generateText($expand_prompt, $provider, $model);
                if ($expand_response && !$expand_response->hasError()) {
                    $expanded = trim($expand_response->getContent());
                    $expanded = preg_replace('/^```html?\s*|^```\s*|```\s*$/i', '', $expanded);
                    if (trim($expanded) !== '' && strpos($expanded, '<h2') !== false) {
                        $content = trim($expanded);
                        AutopilotLogger::log('writer', 'h2_thin_fixed_manual', 'success', '[writer_manual] H2 vazios corrigidos');
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Extrai keyword do prompt (helper para expand de H2 vazios).
     */
    private function extract_keyword_from_prompt(string $prompt): string {
        if (preg_match('/KEYWORD[^:]*:\s*([^\n]+)/iu', $prompt, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/T[ÃI]TULO[^:]*:\s*([^\n]+)/iu', $prompt, $m)) {
            return trim($m[1]);
        }
        return 'artigo';
    }

    private function count_words_local(string $html): int {
        if (class_exists('GeoMetodoSEO\\Autopilot\\Writer\\SaraGlobalPostProcessor')) {
            return SaraGlobalPostProcessor::count_words($html);
        }
        $text = wp_strip_all_tags($html);
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'â€™\-]*/u', $text, $m);
        return count($m[0] ?? []);
    }

    private function should_stop_for_timeout(float $start_time, int $limit = 110): bool {
        // 110s: alinhado ao timeout de 120s dos providers, com 10s de margem
        // para salvar o rascunho antes de qualquer corte do servidor.
        return (microtime(true) - $start_time) >= $limit;
    }

    private function limit_content_words_local(string $content, int $max_words): string {
        if ($max_words <= 0 || $this->count_words_local($content) <= $max_words) return $content;
        $protected_pos = strlen($content);
        foreach ([
            '/<table\b/iu',
            '/<figure\b/iu',
            '/<h2[^>]*>\s*(?:dicas\s+profissionais|boas\s+pr[aÃ¡]ticas|recomenda[cÃ§][oÃµ]es\s+profissionais)/iu',
            '/<h2[^>]*>\s*(?:conclus[aÃ£]o|considera[cÃ§][oÃµ]es\s+finais)/iu',
            '/<div\b[^>]*class=["\'][^"\']*(?:sara-faq-section|geo-faq-section|geo-author-box|geo-author-box-reinforced)[^"\']*["\']/iu',
            '/<section\b[^>]*class=["\'][^"\']*(?:sara-related-articles|geo-related-articles)[^"\']*["\']/iu',
        ] as $pattern) {
            if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                $protected_pos = min($protected_pos, (int)$m[0][1]);
            }
        }
        $editable = substr($content, 0, $protected_pos);
        $protected = substr($content, $protected_pos);
        if (!preg_match_all('/<p\b[^>]*>.*?<\/p>/isu', $editable, $matches, PREG_OFFSET_CAPTURE)) return $content;
        foreach (array_reverse($matches[0]) as $p) {
            if ($this->count_words_local($editable . $protected) <= $max_words) break;
            $html = $p[0];
            $plain = trim(wp_strip_all_tags($html));
            if ($plain === '' || mb_strlen($plain) < 90 || stripos($html, 'sara-quick-answer') !== false) continue;
            $pos = (int)$p[1];
            $editable = substr($editable, 0, $pos) . substr($editable, $pos + strlen($html));
        }
        return trim(trim($editable) . "\n\n" . ltrim($protected));
    }

    private function save_partial_draft_for_timeout(string $title, string $content, int $category_id, string $keyword, int $word_count, float $t0, string $stage): array {
        $publish_result = GeoMetodoSEO_Publisher::publish([
            'title' => wp_strip_all_tags($title),
            'content' => $content,
            'excerpt' => mb_substr(wp_strip_all_tags($content), 0, 200),
            'status' => 'draft',
            'post_type' => 'post',
            'author_id' => get_current_user_id() ?: 1,
            'category_ids' => $category_id > 0 ? [$category_id] : [],
            'focus_keyword' => $keyword,
            'seo_title' => $title,
            'meta_description' => mb_substr(wp_strip_all_tags($content), 0, 160),
            'source_module' => 'writer_manual_timeout',
            'custom_meta' => [
                '_geo_keyword' => $keyword,
                '_sara_manual' => '1',
                '_sara_generation_status' => 'partial_processing',
            ],
        ]);
        if (empty($publish_result['success'])) {
            throw new \RuntimeException('Tempo seguro esgotado e falha ao salvar rascunho parcial: ' . ($publish_result['error'] ?? 'Publisher central retornou erro.'));
        }
        $post_id = (int)$publish_result['post_id'];
        update_post_meta($post_id, '_geo_keyword', $keyword);
        update_post_meta($post_id, '_sara_manual', '1');
        update_post_meta($post_id, '_sara_word_count_target', $word_count);
        update_post_meta($post_id, '_sara_generation_status', 'partial_processing');
        return $this->timeout_existing_post_result((int)$post_id, $content, $word_count, $t0, $stage);
    }

    private function timeout_existing_post_result(int $post_id, string $content, int $word_count, float $t0, string $stage): array {
        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_status' => 'draft', 'post_content' => $content]);
        $actual = $this->count_words_local($content);
        update_post_meta($post_id, '_sara_word_count_real_final', $actual);
        update_post_meta($post_id, '_sara_word_count_target', $word_count);
        update_post_meta($post_id, '_sara_generation_status', 'partial_processing');
        AutopilotLogger::log('writer', 'timeout_guard_triggered', 'warning',
            "Writer Manual parou antes do 504 em {$stage}", [
                'post_id' => $post_id,
                'stage' => $stage,
                'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
                'word_count' => $actual,
                'target_words' => $word_count,
            ]);
        return [
            'success' => true,
            'partial_processing' => true,
            'post_id' => $post_id,
            'post_url' => get_permalink($post_id),
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'preview_url' => get_preview_post_link($post_id),
            'status' => 'draft',
            'word_count_real' => $actual,
            'word_count_target' => $word_count,
            'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
            'preview_html' => $content,
            'warnings' => ['Processamento parcial salvo como rascunho antes do timeout do servidor.'],
        ];
    }

    private function condense_content_to_target(string $content, string $title, string $keyword, int $target_words, int $max_words, string $ai_model, bool $strict_model, string $text_provider = '') {
        $actual = $this->count_words_local($content);
        $prompt = "VocÃª Ã© editor sÃªnior. O artigo abaixo passou muito da meta de palavras.\n\n"
            . "Título: {$title}\nKeyword: {$keyword}\nPalavras atuais: {$actual}\nMeta: {$target_words}\nMÃ¡ximo permitido: {$max_words}\n\n"
            . "Reescreva/condense mantendo HTML puro, todos os pontos importantes, resposta rÃ¡pida Ãºnica, tabela se existir, sem FAQ, sem inventar dados e sem perder qualidade. "
            . "Retorne o artigo completo com aproximadamente {$target_words} palavras e nunca acima de {$max_words}.\n\nARTIGO:\n{$content}";
        try {
            if ($strict_model) {
                $response = $this->generate_openai_strict($prompt, $ai_model ?: (ProviderResolver::modelFor('manual_writer', 'openai') ?: 'gpt-4.1-mini'));
                if ($response && !$response->hasError() && trim((string)$response->getContent()) !== '') {
                    return (string)$response->getContent();
                }
                return false;
            }
            $provider = ProviderResolver::for('manual_writer', $text_provider);
            $model = $ai_model ?: (ProviderResolver::modelFor('manual_writer', $provider) ?: null);
            $response = $this->ai->generateText($prompt, $provider, $model);
            if ($response && !$response->hasError() && trim((string)$response->getContent()) !== '') {
                return (string)$response->getContent();
            }
            return false;
        } catch (\Throwable $e) {
            AutopilotLogger::log('writer', 'manual_condense_failed', 'warning', 'CondensaÃ§Ã£o falhou: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Chamada direta OpenAI sem fallback para testar exatamente o modelo selecionado.
     */
    private function generate_openai_strict(string $prompt, string $model): \GeoMetodoSEO\AI\AIResponse {
        $apiKey = \GeoMetodoSEO\Config\ConfigManager::get('openai_api_key');
        if (!$apiKey) {
            return new \GeoMetodoSEO\AI\AIResponse('', [], 'API Key OpenAI não definida');
        }
        // Providers independentes: sem trava de custo artificial.
        // OpenAI chamada direta para o Writer Manual â€” sem Controle de uso bloqueando.

        $is_reasoning = str_starts_with($model, 'gpt-5') || in_array($model, ['o3', 'o3-mini', 'o4-mini'], true);
        $max_tokens = 5000;
        $body = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($is_reasoning) {
            $body['max_completion_tokens'] = $max_tokens;
        } else {
            $body['max_tokens'] = $max_tokens;
        }
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ]);
        if (is_wp_error($response)) {
            return new \GeoMetodoSEO\AI\AIResponse('', [], $response->get_error_message());
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (empty($content) && !empty($data['error']['message'])) {
            return new \GeoMetodoSEO\AI\AIResponse('', $data, $data['error']['message']);
        }
        return new \GeoMetodoSEO\AI\AIResponse((string)$content, $data);
    }

    /**
     * Garante pelo menos uma imagem dentro do corpo quando a imagem destacada foi criada,
     * mas o pool de imagens internas falhou ou ficou vazio.
     */
    private function insert_featured_image_in_body(string $content, string $image_url, string $keyword, string $title): string {
        if (!$image_url || str_contains($content, 'sara-body-image')) return $content;
        $alt = esc_attr($keyword . ' - ' . $title);
        $figure = "\n<figure class=\"sara-body-image sara-featured-fallback\" style=\"margin:28px 0;\"><img src=\"" . esc_url($image_url) . "\" alt=\"{$alt}\" loading=\"lazy\" style=\"width:100%;height:auto;border-radius:8px;display:block;\"><figcaption style=\"font-size:12px;color:#6b7280;text-align:center;margin-top:6px;\">" . esc_html($keyword) . "</figcaption></figure>\n";
        if (preg_match('/<\/p>/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($content, 0, $pos) . $figure . substr($content, $pos);
        }
        if (preg_match('/<\/h2>/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($content, 0, $pos) . $figure . substr($content, $pos);
        }
        return $figure . $content;
    }

    /**
     * Normalizar HTML retornado pela IA â€” converte markdown literal em HTML real
     * caso a IA tenha falhado em retornar HTML puro.
     *
     * Trata os casos mais comuns:
     * - **negrito** â†’ <strong>negrito</strong>
     * - *itÃ¡lico* â†’ <em>itÃ¡lico</em>
     * - ## Título â†’ <h2>Título</h2>
     * - ### SubtÃ­tulo â†’ <h3>SubtÃ­tulo</h3>
     * - - item â†’ <ul><li>item</li></ul>
     * - 1. item â†’ <ol><li>item</li></ol>
     * - Linhas em branco entre parÃ¡grafos sem tag â†’ envolver em <p>
     *
     * @since 1.0.0
     */
    private function normalize_html(string $content): string {
        $content = trim($content);
        if ($content === '') return '';

        // 1) Headings markdown (## H2, ### H3, #### H4) â€” sÃ³ linhas inteiras
        $content = preg_replace('/^#{4}\s+(.+)$/m',  '<h4>$1</h4>',  $content);
        $content = preg_replace('/^#{3}\s+(.+)$/m',  '<h3>$1</h3>',  $content);
        $content = preg_replace('/^#{2}\s+(.+)$/m',  '<h2>$1</h2>',  $content);
        $content = preg_replace('/^#{1}\s+(.+)$/m',  '<h2>$1</h2>',  $content);

        // 2) Negrito **texto** â†’ <strong>texto</strong>
        $content = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $content);

        // 3) ItÃ¡lico *texto* â†’ <em>texto</em>  (não pegar ** jÃ¡ convertido)
        $content = preg_replace('/(?<![\*\w])\*([^\*\n]+?)\*(?![\*\w])/u', '<em>$1</em>', $content);

        // 4) Listas markdown não ordenadas: agrupar linhas que comecem com "- " ou "* "
        $content = preg_replace_callback(
            '/(?:^[\-\*]\s+.+\n?)+/m',
            function ($m) {
                $items = preg_split('/\n/', trim($m[0]));
                $html  = "<ul>\n";
                foreach ($items as $it) {
                    $it = preg_replace('/^[\-\*]\s+/', '', $it);
                    if ($it !== '') $html .= "  <li>{$it}</li>\n";
                }
                return $html . "</ul>\n";
            },
            $content
        );

        // 5) Listas ordenadas markdown: linhas que comecem com "1. ", "2. ", etc.
        $content = preg_replace_callback(
            '/(?:^\d+\.\s+.+\n?)+/m',
            function ($m) {
                $items = preg_split('/\n/', trim($m[0]));
                $html  = "<ol>\n";
                foreach ($items as $it) {
                    $it = preg_replace('/^\d+\.\s+/', '', $it);
                    if ($it !== '') $html .= "  <li>{$it}</li>\n";
                }
                return $html . "</ol>\n";
            },
            $content
        );

        // 6) Envolver linhas-soltas (que não sÃ£o tag de bloco) em <p>
        // Quebrar em blocos por linha em branco e envolver os que não comeÃ§am com tag de bloco.
        $blocks = preg_split('/\n{2,}/', $content);
        $block_tags_re = '/^\s*<(?:h[1-6]|p|ul|ol|li|blockquote|pre|table|figure|div|section|article|aside|header|footer|nav|hr|img)/i';
        foreach ($blocks as &$block) {
            $b = trim($block);
            if ($b === '') continue;
            if (!preg_match($block_tags_re, $b)) {
                $block = '<p>' . $b . '</p>';
            }
        }
        unset($block);

        return implode("\n\n", $blocks);
    }

    /** Adicionar 2-3 links internos automaticamente */
    private function add_internal_links(int $post_id, int $category_id, string $keyword): void {
        if (!$category_id) return;

        $related = get_posts([
            'category'       => $category_id,
            'numberposts'    => 3,
            'post__not_in'   => [$post_id],
            'orderby'        => 'rand',
        ]);

        if (empty($related)) return;

        $post    = get_post($post_id);
        $content = $post->post_content;
        $linked  = 0;

        foreach ($related as $r) {
            if ($linked >= 2) break;

            $r_title = $r->post_title;
            $r_url   = get_permalink($r->ID);

            // Encontrar palavras do tÃ­tulo relacionado no conteúdo
            $words = explode(' ', $r_title);
            foreach ($words as $word) {
                if (mb_strlen($word) < 5) continue;
                $pattern = '/\b(' . preg_quote($word, '/') . ')\b/iu';

                if (preg_match($pattern, $content) && stripos($content, "href=\"{$r_url}\"") === false) {
                    $content = preg_replace(
                        $pattern,
                        '<a href="' . esc_url($r_url) . '" title="' . esc_attr($r_title) . '">$1</a>',
                        $content,
                        1
                    );
                    $linked++;
                    break;
                }
            }
        }

        if ($linked > 0) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
        }
    }

    /**
     * Linkagem interna automÃ¡tica â€” adiciona atÃ© 5 links para artigos relacionados
     * da mesma categoria (anchor text = palavra do tÃ­tulo do post relacionado).
     *
     * @since 1.0.0 ImplementaÃ§Ã£o que faltava (era chamado mas não existia)
     */
    private function add_related_internal_links(int $post_id, int $category_id, string $keyword): void {
        if (!$category_id || !$post_id) return;

        $related = get_posts([
            'category'     => $category_id,
            'numberposts'  => 8,
            'post__not_in' => [$post_id],
            'post_status'  => 'publish',
            'orderby'      => 'rand',
        ]);
        if (empty($related)) return;

        $post = get_post($post_id);
        if (!$post) return;

        $content       = $post->post_content;
        $linked        = 0;
        $max_links     = 5;
        $linked_urls   = [];

        foreach ($related as $r) {
            if ($linked >= $max_links) break;

            $r_url = get_permalink($r->ID);
            if (in_array($r_url, $linked_urls, true)) continue;
            if (stripos($content, "href=\"{$r_url}\"") !== false) continue;

            // Tentar achar uma palavra do tÃ­tulo relacionado no conteúdo (>= 5 chars)
            $words = array_filter(explode(' ', $r->post_title), fn($w) => mb_strlen(trim($w, '.,;:!?"')) >= 5);
            foreach ($words as $word) {
                $word = trim($word, '.,;:!?"');
                if ($word === '') continue;

                $pat = '/(?<![\w\-<>])(' . preg_quote($word, '/') . ')(?![\w\-<>])/iu';
                // NÃ£o substituir dentro de tags <a> existentes
                if (preg_match($pat, strip_tags($content))) {
                    $new_content = preg_replace(
                        $pat,
                        '<a href="' . esc_url($r_url) . '" title="' . esc_attr($r->post_title) . '">$1</a>',
                        $content,
                        1
                    );
                    if ($new_content && $new_content !== $content) {
                        $content       = $new_content;
                        $linked_urls[] = $r_url;
                        $linked++;
                        break;
                    }
                }
            }
        }

        if ($linked > 0) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
            AutopilotLogger::log('writer', 'manual_internal_links', 'success',
                "{$linked} links internos adicionados");
        }
    }

    /**
     * Linkagem externa para autoridades â€” adiciona 1-2 links para fontes oficiais
     * baseadas no nicho. Links rel="nofollow noopener" e abre em nova aba.
     *
     * @since 1.0.0 ImplementaÃ§Ã£o que faltava
     */
    private function add_authority_external_links(int $post_id, string $keyword): void {
        if (!$post_id) return;

        $post = get_post($post_id);
        if (!$post) return;
        $content = $post->post_content;

        // Fontes baseadas em ENTIDADES REAIS citadas no artigo (keyword + conteúdo).
        // Antes, este método usava um mapa de nicho que casava "ia" dentro de
        // "tecnologia/notícia" e colava OpenAI/Anthropic em qualquer artigo.
        // Agora só entra fonte de marca/órgão efetivamente mencionado no texto.
        $picks = \GeoMetodoSEO\Services\ContextEngine::get_authority_links($keyword, $content);

        $added = 0;
        $links_html = '';
        foreach ($picks as $a) {
            if ($added >= 2) break;
            if (empty($a['url']) || empty($a['anchor'])) continue;
            if (stripos($content, $a['url']) !== false) continue;
            $links_html .= '<li>Fonte oficial: <a href="' . esc_url($a['url']) . '" target="_blank" rel="nofollow noopener noreferrer">' . esc_html($a['anchor']) . '</a></li>';
            $added++;
        }

        if ($added === 0) return;

        $block = "\n\n<aside class=\"sara-authority-sources\" style=\"margin:24px 0;padding:16px 18px;background:transparent;color:inherit;border:1px solid rgba(148,163,184,.35);border-radius:6px;\">"
               . '<p style="margin:0 0 8px;font-weight:600;color:inherit;">📚 Fontes de Autoridade</p>'
               . '<ul style="margin:0;padding-left:20px;color:inherit;">' . $links_html . '</ul>'
               . "</aside>\n";

        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
            'ID'           => $post_id,
            'post_content' => $content . $block,
        ]);

        AutopilotLogger::log('writer', 'manual_external_links', 'success',
            "{$added} links de autoridade adicionados (baseados em entidades reais)");
    }

    /**
     * Anexar bloco "Artigos Relacionados" ao final do conteúdo.
     * Lista 4 posts da mesma categoria com links.
     *
     * @since 1.0.0 ImplementaÃ§Ã£o que faltava
     */
    private function append_related_articles_block(int $post_id, int $category_id): void {
        if (!$category_id || !$post_id) return;

        $related = get_posts([
            'category'     => $category_id,
            'numberposts'  => 4,
            'post__not_in' => [$post_id],
            'post_status'  => 'publish',
            'orderby'      => 'date',
            'order'        => 'DESC',
        ]);

        if (empty($related)) return;

        $items = '';
        foreach ($related as $r) {
            $url   = esc_url(get_permalink($r->ID));
            $title = esc_html($r->post_title);
            $thumb = get_the_post_thumbnail_url($r->ID, 'medium') ?: '';

            $thumb_html = $thumb
                ? '<img src="' . esc_url($thumb) . '" alt="' . $title . '" style="width:100%;height:140px;object-fit:cover;border-radius:6px;margin-bottom:8px;" loading="lazy">'
                : '';

            $items .= '<div style="background:transparent;padding:12px;border-radius:8px;border:1px solid rgba(148,163,184,.35);">'
                    . $thumb_html
                    . '<a href="' . $url . '" style="font-weight:600;color:inherit;text-decoration:none;font-size:14px;line-height:1.4;display:block;">'
                    . $title
                    . '</a></div>';
        }

        $block = "\n\n<section class=\"geo-related-articles\" style=\"margin:32px 0 16px;padding:20px;background:transparent;color:inherit;border:1px solid rgba(148,163,184,.35);border-radius:10px;\">"
               . '<h3 style="margin:0 0 16px;color:inherit;font-size:18px;">ðŸ“– Artigos Relacionados</h3>'
               . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">'
               . $items
               . '</div></section>';

        $post = get_post($post_id);
        if (!$post) return;

        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
            'ID'           => $post_id,
            'post_content' => $post->post_content . $block,
        ]);

        AutopilotLogger::log('writer', 'manual_related_block', 'success',
            count($related) . ' artigos relacionados anexados');
    }

    private function extract_keyword(string $title): string {
        $stopwords = ['de','do','da','dos','das','e','o','a','os','as','em','no','na',
                      'por','para','com','que','um','uma','como','sobre'];
        $words = explode(' ', mb_strtolower($title));
        $sig   = array_filter($words, fn($w) => !in_array($w, $stopwords) && mb_strlen($w) > 2);
        return implode(' ', array_slice(array_values($sig), 0, 3));
    }

    /** Estimativa de custo: GPT-4.1 + DALL-E (se imagem) */
    private function estimate_cost(int $word_count, bool $gen_image): float {
        // GPT-4.1: ~$0.01 por 1k tokens output, ~750 palavras = 1k tokens
        $tokens = (int)($word_count / 0.75);
        $text_cost = ($tokens / 1000) * 0.01;
        // Imagem via Unsplash = $0
        return round($text_cost, 4);
    }
}
