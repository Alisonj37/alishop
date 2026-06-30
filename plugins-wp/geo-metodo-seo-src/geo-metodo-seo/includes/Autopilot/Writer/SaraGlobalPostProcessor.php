<?php
namespace GeoMetodoSEO\Autopilot\Writer;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;

/**
 * SaraGlobalPostProcessor
 * Camada global pós-escrita para todos os fluxos de artigo.
 * Responsável por: FAQ oficial único, resposta rápida única/curta, tabela HTML limpa,
 * fontes oficiais por contexto, fila assíncrona de imagens internas, E-E-A-T/autor no rodapé real e contador final.
 */
class SaraGlobalPostProcessor {

    public static function finalize(int $post_id, array $context = []): string {
        $post = get_post($post_id);
        if (!$post) return '';
        $t0 = microtime(true);

        // ── Bloquear hooks que podem causar crash durante o finalize ──────
        // wp_update_post dispara save_post 7x durante o finalize.
        // Cada disparo pode executar: web story, cron schedule, Rank Math etc.
        // Bloquear temporariamente e restaurar no final.
        remove_action('save_post_post', 'geo_metodo_schedule_auto_webstory', 10);
        remove_action('save_post', 'geo_metodo_schedule_auto_webstory', 10);
        $unhook_done = true;
        // ─────────────────────────────────────────────────────────────────

        $content = (string) $post->post_content;
        $title   = (string) ($context['title'] ?? $post->post_title);
        $keyword = (string) ($context['keyword'] ?? get_post_meta($post_id, '_geo_keyword', true) ?: $title);
        $niche   = (string) ($context['niche'] ?? \GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller::get('site_niche', get_option('sara_niche', 'Tecnologia')));
        $category = (string) ($context['category'] ?? 'geral');
        $target_words = max(0, (int) ($context['target_words'] ?? get_post_meta($post_id, '_sara_word_count_target', true)));
        $enable_faq = array_key_exists('enable_faq', $context) ? (bool)$context['enable_faq'] : true;
        $internal_image_count = max(0, min(\GeoMetodoSEO\Services\ImageGeneratorService::body_images_count(), (int)($context['internal_image_count'] ?? \GeoMetodoSEO\Services\ImageGeneratorService::body_images_count())));
        AutopilotLogger::log('writer', 'generation_started', 'info', "Post #{$post_id}: finalizacao global iniciada", [
            'post_id' => $post_id,
            'target_words' => $target_words,
            'image_source' => class_exists('GeoMetodoSEO\\Services\\LibraryImageService') && \GeoMetodoSEO\Services\LibraryImageService::is_library_mode() ? 'library' : 'ai',
        ]);

        // 1) Normalização editorial global: remove sinais de automação antes de publicar.
        $content = self::sanitize_provider_html_artifacts($content);
        $content = self::normalize_future_year_mismatch($content, $keyword, $title);
        $content = self::normalize_heading_quality($content, $keyword, $title);
        $content = self::remove_inline_faq($content);
        $content = self::apply_factual_guard($content, $keyword . ' ' . $title . ' ' . $category);
        $content = self::normalize_quick_answer($content);
        $content = self::ensure_quick_answer_final($content, $keyword, $title);
        $content = self::normalize_tables($content);
        $content = self::ensure_required_table($content, $keyword, $title, $category);
        $content = self::clean_broken_text_tables($content, $keyword . ' ' . $title . ' ' . $category);
        $content = self::replace_low_information_tables($content, $keyword . ' ' . $title . ' ' . $category);
        $content = self::remove_irrelevant_generic_sources($content, $keyword . ' ' . $title . ' ' . $category);
        $content = self::normalize_commercial_blocks($content);
        $content = self::append_contextual_official_sources($content, $keyword . ' ' . $title . ' ' . $category);
        $content = self::remove_duplicate_related_blocks($content);
        $content = self::ensure_keyword_seo_placement($content, $keyword, $title);

        // 2) Não repetir imagem destacada no corpo por padrão.
        // A imagem destacada só vira fallback se o fluxo pedir explicitamente.
        $use_featured_fallback = !empty($context['use_featured_as_body_fallback']);
        $featured_id = (int) get_post_thumbnail_id($post_id);
        if ($use_featured_fallback && $featured_id > 0 && substr_count($content, 'sara-body-image') === 0) {
            $featured_url = wp_get_attachment_url($featured_id) ?: '';
            if ($featured_url) {
                $content = self::insert_featured_image_in_body($content, $featured_url, $keyword, $title);
            }
        }

        // 2b) Vídeo do YouTube relacionado ao tema (se o usuário escolheu embedar vídeo).
        // Controlado por: context['embed_youtube_video'] (por geração) OU opção global geo_embed_youtube_video.
        // O vídeo é buscado pela keyword/título e só é inserido se for relevante ao tema.
        $want_video = !empty($context['embed_youtube_video'])
            || (get_option('geo_embed_youtube_video', '0') === '1' && !isset($context['embed_youtube_video']));
        if ($want_video
            && strpos($content, 'geo-youtube-embed') === false
            && class_exists('\\GeoMetodoSEO\\Services\\YouTubeVideoFinder')) {
            $video_html = \GeoMetodoSEO\Services\YouTubeVideoFinder::get_embed_html($keyword, $title, 'pt');
            if ($video_html !== '') {
                // Inserir após o 2º H2 (depois da introdução e do contexto inicial)
                if (preg_match_all('/<h2[\s>]/i', $content, $h2m, PREG_OFFSET_CAPTURE) && count($h2m[0]) >= 2) {
                    $pos = $h2m[0][1][1]; // posição do 2º H2
                    $content = substr($content, 0, $pos) . $video_html . substr($content, $pos);
                } else {
                    $content .= $video_html;
                }
                update_post_meta($post_id, '_geo_youtube_embed', '1');
                AutopilotLogger::log('writer', 'youtube_video_embedded', 'success',
                    "Vídeo do YouTube relacionado embedado no post #{$post_id}", ['post_id' => $post_id]);
            }
        }

        // Salvar antes do FAQ para evitar perda de conteúdo se houver falha na IA.
        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);

        // 3) FAQ oficial unico + schema.
        // 1.0.0: FAQ via IA OFF nao significa sem FAQ. Quando enable_faq=true,
        // SaraDeepFAQGenerator::generate() gera FAQ local obrigatorio sem gastar creditos.
        if ($enable_faq && class_exists(__NAMESPACE__ . '\SaraDeepFAQGenerator')) {
            $faq_gen = new SaraDeepFAQGenerator();
            $faqs = $faq_gen->generate($title, $category, $niche);
            if (!empty($faqs)) {
                $content = self::remove_inline_faq($content);
                $content .= "

" . $faq_gen->render_html($faqs);
                update_post_meta($post_id, 'geo_faq_schema', $faq_gen->build_schema($faqs));
                update_post_meta($post_id, '_sara_faq_count', count($faqs));
                update_post_meta($post_id, '_sara_faq_mode', \GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller::get('faq_generator_enabled', '0') === '1' ? 'ai_or_fallback' : 'local_no_cost');
                update_post_meta($post_id, '_aeo_faq_active', '1');
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
            } else {
                update_post_meta($post_id, '_sara_faq_count', 0);
                update_post_meta($post_id, '_aeo_faq_active', '0');
                AutopilotLogger::log('writer', 'faq_empty_unexpected', 'warning', "Post #{$post_id}: FAQ nao foi anexado porque o gerador retornou vazio");
            }
        }

        // 4) Imagens internas. Em modo Biblioteca, inserir diretamente por ID: rápido e sem 504.
        if ($internal_image_count > 0 && class_exists('GeoMetodoSEO\Services\LibraryImageService') && \GeoMetodoSEO\Services\LibraryImageService::is_library_mode()) {
            $cat_slug = \GeoMetodoSEO\Services\LibraryImageService::resolve_category_slug($post_id);
            $content = \GeoMetodoSEO\Services\LibraryImageService::inject_body_images($content, $post_id, $keyword, $cat_slug, 1, $internal_image_count);
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
            update_post_meta($post_id, '_sara_internal_images_done', substr_count($content, 'sara-body-image'));
        } elseif ($internal_image_count > 0 && class_exists(__NAMESPACE__ . '\SaraImageQueue')) {
            // Em modo IA, deixar assíncrono para não estourar gateway timeout.
            SaraImageQueue::enqueue($post_id, $keyword, $internal_image_count, [
                'title' => $title,
                'category' => $category,
                'niche' => $niche,
            ]);
            if (!empty($context['process_internal_images_now'])) {
                SaraImageQueue::process($post_id);
                $post_after_images = get_post($post_id);
                if ($post_after_images) $content = (string) $post_after_images->post_content;
            }
        }

        // 5) E-E-A-T/autor/ecossistema SEMPRE no final real do artigo.
        if (class_exists('GeoMetodoSEO\\EEAT\\EEATEngine')) {
            $eeat = new \GeoMetodoSEO\EEAT\EEATEngine();
            $eeat->apply($post_id);
            $post_after = get_post($post_id);
            if ($post_after) {
                $content = (string) $post_after->post_content;
            }
        }

        // 6) Garantia final de FAQ + FAQPage schema no Autopilot.
        // Mesmo se alguma etapa anterior remover a seção, o post não sai sem FAQ/schema.
        $content = self::ensure_faq_schema_final($post_id, $content, $title, $category, $niche);

        // 7) SEO profissional final: meta description propria, Rank Math, alt text, links e expansão segura.
        // FIX v1.0.0-WORDCOUNT: antes usava max(3000, $target_words) que desfazia o fix de tamanho.
        // Se user pediu 1500 palavras, NÃO expandir para 3000.
        // FIX 504/word count: não expandir com blocos extras após a geração manual.
        $content = self::sanitize_provider_html_artifacts($content);
        $content = self::normalize_future_year_mismatch($content, $keyword, $title);
        $content = self::normalize_quick_answer($content);
        $content = self::normalize_heading_quality($content, $keyword, $title);
        $content = self::ensure_required_table($content, $keyword, $title, $category);
        $content = self::remove_duplicate_related_blocks($content);
        $content = self::ensure_keyword_seo_placement($content, $keyword, $title);
        // 1.0.0: remover imagens duplicadas consecutivas (figura seguida de figura sem H2 entre elas).
        $content = self::dedupe_consecutive_images($content);
        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
        self::apply_professional_rank_math_meta($post_id, $content, $keyword, $title);
        self::ensure_image_alt_texts($post_id, $keyword, $title);

        // 7b) Auditor global de mídia: garante imagem destacada + imagens internas para todos os fluxos.
        if (class_exists('GeoMetodoSEO\Services\GeoMediaMasterService') && !(class_exists('GeoMetodoSEO\Services\LibraryImageService') && \GeoMetodoSEO\Services\LibraryImageService::is_library_mode())) {
            $media = new \GeoMetodoSEO\Services\GeoMediaMasterService();
            // FIX v1.0.0-IMAGES: usar configuração real (ImageGeneratorService::body_images_count())
            // em vez de hardcode 4 ou $internal_image_count que podia ser 0.
            $configured_body_count = class_exists('GeoMetodoSEO\Services\ImageGeneratorService')
                ? \GeoMetodoSEO\Services\ImageGeneratorService::body_images_count()
                : ($internal_image_count > 0 ? $internal_image_count : 3);
            $media->ensure_post_media($post_id, [
                'keyword' => $keyword,
                'title' => $title,
                'body_images' => $configured_body_count,
                'require_featured' => true,
                'process_now' => !empty($context['process_internal_images_now']),
                'category' => $category,
                'niche' => $niche,
            ]);
            $post_after_media = get_post($post_id);
            if ($post_after_media) {
                $content = (string) $post_after_media->post_content;
            }
            // Reaplicar alt text depois da Central de Mídias, porque featured/body images podem ter sido criadas nesta etapa.
            self::ensure_image_alt_texts($post_id, $keyword, $title);
            $post_after_alt = get_post($post_id);
            if ($post_after_alt) {
                $content = (string) $post_after_alt->post_content;
            }
        }

        // 7c) Última garantia visual/editorial após mídia, FAQ, E-E-A-T e blocos relacionados.
        $content = self::sanitize_provider_html_artifacts($content);
        $content = self::normalize_future_year_mismatch($content, $keyword, $title);
        $content = self::normalize_quick_answer($content);
        $content = self::normalize_heading_quality($content, $keyword, $title);
        $content = self::ensure_required_table($content, $keyword, $title, $category);
        $content = self::remove_duplicate_related_blocks($content);
        $content = self::normalize_quick_answer($content);
        $content = self::ensure_quick_answer_final($content, $keyword, $title);
        $content = self::ensure_conclusion_before_faq($content, $keyword, $title);
        $content = self::ensure_professional_tips_before_conclusion($content, $keyword, $title);
        $content = self::ensure_conclusion_before_faq($content, $keyword, $title);
        $content = self::move_table_before_final_blocks($content);
        $content = self::ensure_body_images_visible($post_id, $content, $keyword, $title, $category, $internal_image_count);
        $content = self::dedupe_consecutive_images($content);
        $content = self::ensure_related_articles_block($post_id, $content, $keyword, $title, $category);
        $content = self::enforce_word_count_ceiling($content, $target_words);
        $content = self::sanitize_provider_html_artifacts($content);
        $content = self::normalize_future_year_mismatch($content, $keyword, $title);
        $content = self::normalize_quick_answer($content);
        $content = self::ensure_quick_answer_final($content, $keyword, $title);
        $content = self::ensure_conclusion_before_faq($content, $keyword, $title);
        $content = self::ensure_professional_tips_before_conclusion($content, $keyword, $title);
        $content = self::move_table_before_final_blocks($content);
        $content = self::ensure_conclusion_before_faq($content, $keyword, $title);
        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);

        // Remover imagem de destaque duplicada no corpo.
        // Responsabilidade do SaraGlobalPostProcessor (não do Publisher).
        // save_post está bloqueado aqui — sem cascata de hooks.
        $featured_id = (int) get_post_thumbnail_id($post_id);
        if ($featured_id > 0 && strpos($content, (string)$featured_id) !== false) {
            $pat_fig = '/<figure\b[^>]*data-attachment-id=["\']' . preg_quote((string)$featured_id, '/') . '["\'][\s\S]*?<\/figure>\s*/i';
            $pat_img = '/<img\b[^>]*(?:data-attachment-id=["\']' . preg_quote((string)$featured_id, '/') . '["\']|class=["\'][^"\']*wp-image-' . preg_quote((string)$featured_id, '/') . '[^"\']*["\'])[^>]*>\s*/i';
            $clean   = (string) preg_replace([$pat_fig, $pat_img], '', $content);
            if ($clean !== $content) {
                $content = $clean;
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
            }
        }

        if (class_exists('\\GeoMetodoSEO\\Services\\LibraryImageService')) {
            \GeoMetodoSEO\Services\LibraryImageService::sync_post_image_meta_from_content($post_id, $content);
        }

        // 8) Contador final auditável.
        $final_words = self::count_words($content);
        update_post_meta($post_id, '_sara_word_count_real_final', $final_words);
        if ($target_words > 0) {
            update_post_meta($post_id, '_sara_word_count_target', $target_words);
            update_post_meta($post_id, '_sara_word_count_status_final', $final_words >= (int)floor($target_words * 0.85) ? 'ok' : 'below_target');
        }

        // 9) MarchUpdateGuard: validação anti-Google March 2026 Core+Spam Update.
        // Analisa originalidade, dados, frases vazias e thin content. Score salvo como meta.
        // 1.0.0: aplicado em TODO artigo finalizado (Autopilot, Manual, Pipeline, YouTube, Glossary).
        if (class_exists('GeoMetodoSEO\\Services\\MarchUpdateGuard')) {
            \GeoMetodoSEO\Services\MarchUpdateGuard::analyze_and_record(
                $post_id,
                $content,
                $target_words > 0 ? $target_words : 1500
            );
        }

        AutopilotLogger::log('writer', 'global_post_processed', 'success',
            "Post #{$post_id} finalizado globalmente: resposta rápida, tabela, fontes, FAQ, autor e fila de imagens internas", [
                'post_id' => $post_id,
                'words' => $final_words,
                'target' => $target_words,
                'internal_image_count' => $internal_image_count,
                'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
            ]
        );

        // Restaurar hook de Web Story agora que o finalize terminou
        if (!empty($unhook_done)) {
            add_action('save_post_post', 'geo_metodo_schedule_auto_webstory', 10, 1);
        }

        return $content;
    }

    private static function ensure_related_articles_block(int $post_id, string $content, string $keyword, string $title, string $category): string {
        $content = self::remove_duplicate_related_blocks($content);
        $related = self::find_related_articles($post_id, $keyword, $title, $category, 4);
        if (empty($related)) return $content;

        $items = '';
        foreach ($related as $p) {
            $items .= '<li><a href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html(get_the_title($p->ID)) . '</a></li>';
        }
        if ($items === '') return $content;

        $block = "\n\n<section class=\"geo-related-articles\" style=\"margin:28px 0;padding:18px 22px;background:transparent;color:inherit;border:1px solid rgba(148,163,184,.35);border-radius:8px;\"><h3 style=\"margin:0 0 12px;font-size:16px;color:inherit;\">Artigos relacionados</h3><p style=\"margin:0 0 10px;font-size:13px;color:inherit;opacity:.75;\">Continue aprofundando o tema:</p><ul style=\"margin:0;padding-left:20px;color:inherit;\">{$items}</ul></section>\n";
        return rtrim($content) . $block;
    }

    private static function find_related_articles(int $post_id, string $keyword, string $title, string $category, int $limit = 4): array {
        $exclude = [$post_id];
        $cat_ids = [];
        foreach (get_the_category($post_id) as $cat) $cat_ids[] = (int)$cat->term_id;
        $terms = array_values(array_filter(array_unique(preg_split('/\s+/', remove_accents(wp_strip_all_tags($keyword . ' ' . $title . ' ' . $category))))));
        $terms = array_values(array_filter($terms, fn($w) => mb_strlen($w) >= 4));
        $search = implode(' ', array_slice($terms, 0, 5));
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__not_in' => $exclude,
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        if (!empty($cat_ids)) $args['category__in'] = $cat_ids;
        if ($search !== '') $args['s'] = $search;
        $posts = get_posts($args);
        if (count($posts) < 2 && !empty($cat_ids)) {
            unset($args['s']);
            $posts = get_posts($args);
        }
        return $posts ?: [];
    }

    private static function insert_before_faq_or_author(string $content, string $block): string {
        if (preg_match('/(<h[23][^>]*>\s*(?:perguntas\s+frequentes|faq)[^<]*<\/h[23]>|<div\s+class="[^"]*(?:geo-author-box|sara-author|geo-eeat)[^"]*")/isu', $content, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int)$m[0][1];
            return substr($content, 0, $pos) . $block . substr($content, $pos);
        }
        return $content . $block;
    }

    private static function exact_focus_keyword(string $keyword, string $title = ''): string {
        $kw = trim(wp_strip_all_tags($keyword));
        if ($kw === '') $kw = trim(wp_strip_all_tags($title));
        $kw = preg_replace('/\s+/u', ' ', $kw) ?? $kw;
        return trim(mb_substr($kw, 0, 120));
    }

    private static function editorial_focus_keyword(string $keyword, string $title = ''): string {
        $kw = self::exact_focus_keyword($keyword, $title);
        if ($kw === '') return '';
        $clean = preg_replace('/^(o que é|como|guia de|guia para|qual|quais|por que)\s+/iu', '', $kw) ?? $kw;
        $clean = preg_split('/[:\?\|]/u', $clean)[0] ?? $clean;
        return trim(mb_substr($clean, 0, 70));
    }

    private static function clean_focus_keyword(string $keyword, string $title = ''): string {
        return self::editorial_focus_keyword($keyword, $title);
    }

    private static function ensure_keyword_seo_placement(string $content, string $keyword, string $title): string {
        $kw_exact = self::exact_focus_keyword($keyword, $title);
        $kw = self::editorial_focus_keyword($keyword, $title);
        if ($kw_exact === '' && $kw === '') return $content;
        if ($kw === '') $kw = $kw_exact;

        $plain_start = mb_substr(wp_strip_all_tags($content), 0, 500);
        if ($kw_exact !== '' && stripos(remove_accents($plain_start), remove_accents($kw_exact)) === false) {
            if (preg_match('/<p[^>]*>\s*<strong>\s*Resposta\s+r[aá]pida:\s*<\/strong>/iu', $content)) {
                // NÃO injeta texto placeholder — a Resposta Rápida já deve ter a keyword
                // inserida pela IA. Injetar texto aqui criava "X é o foco principal deste guia."
                // que é conteúdo genérico visível no artigo publicado.
            } elseif (preg_match('/<div[^>]*class=["\'][^"\']*sara-quick-answer[^"\']*["\'][^>]*>/iu', $content)) {
                // Resposta Rápida no formato div — não injeta placeholder
            }
            // Não injeta nenhum texto automático — deixa como está e loga para debug
            if (class_exists('\GeoMetodoSEO\Services\LogService')) {
                \GeoMetodoSEO\Services\LogService::record('pipeline', 'info',
                    'Keyword não encontrada nos primeiros 500 chars — NÃO injetando placeholder',
                    ['action' => 'keyword_placement_skipped', 'context' => ['keyword' => $kw_exact]]
                );
            }
        }

        $has_kw_h2 = false;
        if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $content, $m)) {
            foreach ($m[1] as $h) {
                $plain_h = wp_strip_all_tags($h);
                if (($kw_exact !== '' && stripos(remove_accents($plain_h), remove_accents($kw_exact)) !== false) || stripos(remove_accents($plain_h), remove_accents($kw)) !== false) { $has_kw_h2 = true; break; }
            }
        }
        if (!$has_kw_h2) {
            $heading_kw = $kw_exact !== '' ? $kw_exact : $kw;
            $new_h2 = '<h2>' . esc_html($heading_kw) . ': como avaliar na prática</h2>';
            if (preg_match('/<h2[^>]*>.*?<\/h2>/isu', $content, $m, PREG_OFFSET_CAPTURE)) {
                $pos = (int)$m[0][1] + strlen($m[0][0]);
                $content = substr($content, 0, $pos) . "
" . $new_h2 . "
<p>Use critérios objetivos para avaliar " . esc_html($heading_kw) . ": contexto de uso, atualização, suporte, confiabilidade, custo-benefício, segurança e compatibilidade com sua necessidade real.</p>
" . substr($content, $pos);
            }
        }
        return $content;
    }

    private static function build_professional_meta_description(string $content, string $keyword, string $title): string {
        $kw_exact = self::exact_focus_keyword($keyword, $title);
        $kw = self::editorial_focus_keyword($keyword, $title);
        $base = $kw_exact !== '' ? $kw_exact : ($kw !== '' ? $kw : $title);
        $desc = 'Entenda ' . $base . ' com critérios práticos, cuidados antes de decidir, pontos de comparação, dúvidas frequentes e orientações seguras para evitar escolhas erradas.';
        $desc = trim(preg_replace('/\s+/', ' ', $desc));
        return mb_substr($desc, 0, 158);
    }

    private static function apply_professional_rank_math_meta(int $post_id, string $content, string $keyword, string $title): void {
        $focus_exact = self::exact_focus_keyword($keyword, $title);
        if ($focus_exact === '') $focus_exact = $title;
        $focus_editorial = self::editorial_focus_keyword($focus_exact, $title);
        if ($focus_editorial === '') $focus_editorial = $focus_exact;

        $seo_title = trim((string)$title);
        if ($focus_exact !== '' && stripos(remove_accents($seo_title), remove_accents($focus_exact)) === false) {
            $seo_title = $focus_exact . ' | ' . $seo_title;
        }
        $seo_title = mb_substr(trim(preg_replace('/\s+/', ' ', $seo_title)), 0, 58);
        $desc = self::build_professional_meta_description($content, $focus_exact, $title);

        update_post_meta($post_id, 'rank_math_focus_keyword', $focus_exact);
        update_post_meta($post_id, '_rank_math_focus_keyword', $focus_exact);
        update_post_meta($post_id, '_geo_keyword_exact', $focus_exact);
        update_post_meta($post_id, '_geo_keyword_clean', $focus_editorial);
        update_post_meta($post_id, 'rank_math_title', $seo_title);
        update_post_meta($post_id, 'rank_math_description', $desc);
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_exact);
        update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $desc);
    }

    private static function ensure_image_alt_texts(int $post_id, string $keyword, string $title): void {
        $focus = self::clean_focus_keyword($keyword, $title);
        $featured_id = (int)get_post_thumbnail_id($post_id);
        if ($featured_id > 0) {
            update_post_meta($featured_id, '_wp_attachment_image_alt', trim($focus . ' - ' . $title));
        }
        $post = get_post($post_id);
        if (!$post) return;
        if (preg_match_all('/wp-image-(\d+)/i', (string)$post->post_content, $m)) {
            foreach (array_unique($m[1]) as $aid) {
                $aid = (int)$aid;
                if ($aid > 0 && !get_post_meta($aid, '_wp_attachment_image_alt', true)) {
                    update_post_meta($aid, '_wp_attachment_image_alt', trim($focus . ' - imagem do artigo'));
                }
            }
        }
        $content = preg_replace_callback('/<img\b([^>]*?)>/iu', function($m) use ($focus, $title) {
            $tag = $m[0];
            if (preg_match('/\salt\s*=\s*["\'][^"\']+["\']/iu', $tag)) return $tag;
            return preg_replace('/<img\b/iu', '<img alt="' . esc_attr(trim($focus . ' - ' . $title)) . '"', $tag, 1) ?: $tag;
        }, (string)$post->post_content) ?? (string)$post->post_content;
        if ($content !== $post->post_content) \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
        if (class_exists('\GeoMetodoSEO\Services\LibraryImageService')) {
            \GeoMetodoSEO\Services\LibraryImageService::sync_post_image_meta_from_content($post_id, (string)$content);
        }
    }

    private static function expand_content_if_below_target(string $content, string $keyword, string $title, string $category, string $niche, int $target_words): string {
        // FIX Bug#6: antes forçava max(2800, min(3500, $target_words)) — desfazia word count para artigos pequenos.
        // Se user pediu 1500 palavras, esta função NÃO pode forçar expansão para 2800.
        $target_words = max(800, min(6000, $target_words));
        $current = self::count_words($content);
        if ($current >= (int)floor($target_words * 0.90)) return $content;
        if (str_contains($content, 'sara-safe-expansion-block')) return $content;
        $kw = self::clean_focus_keyword($keyword, $title);
        $ctx = mb_strtolower(remove_accents($kw . ' ' . $title . ' ' . $category . ' ' . $niche));
        $mobile = preg_match('/xiaomi|redmi|poco|hyperos|android|smartphone|celular|telefone/u', $ctx);
        $seo = preg_match('/seo|geo|aeo|llm|ia|wordpress|rank math|google|conteudo/u', $ctx);
        $sections = '';
        if ($mobile) {
            $sections .= '<h2 class="sara-safe-expansion-block">Critérios avançados para avaliar ' . esc_html($kw ?: $title) . '</h2>';
            $sections .= '<p>Para tomar uma decisão mais segura, avalie o aparelho pelo conjunto de fatores, não apenas por uma característica isolada. Verifique política de atualização, garantia no Brasil, origem do produto, disponibilidade de assistência, armazenamento, memória, bateria, tela, câmeras, conectividade e compatibilidade com os aplicativos que você usa todos os dias.</p>';
            $sections .= '<p>Em celulares Xiaomi, Redmi e POCO, também vale observar se o modelo é nacional ou importado, se recebe atualizações de sistema de forma regular e se o vendedor informa claramente nota fiscal, garantia e versão do aparelho. Esses detalhes costumam fazer mais diferença do que promessas genéricas de desempenho.</p>';
            $sections .= '<h2>Erros que prejudicam a escolha de um smartphone</h2><p>Um erro comum é escolher apenas pelo preço ou por uma ficha técnica aparentemente forte. Outro erro é ignorar atualização, suporte, procedência e uso real. Para evitar arrependimento, compare modelos por perfil de uso: redes sociais, câmera, jogos, trabalho, bateria ou armazenamento. Assim, a escolha fica mais alinhada à necessidade real.</p>';
        } elseif ($seo) {
            $sections .= '<h2 class="sara-safe-expansion-block">Como aplicar ' . esc_html($kw ?: $title) . ' em uma estratégia SEO/GEO</h2>';
            $sections .= '<p>Para aplicar esse tema de forma profissional, comece entendendo a intenção de busca, as entidades principais, as perguntas recorrentes do usuário e a relação com conteúdos já publicados no site. Depois, organize o artigo com resposta rápida, subtítulos claros, exemplos práticos, links internos e dados estruturados quando fizer sentido.</p>';
            $sections .= '<p>Em estratégias voltadas para Google e sistemas de IA, a clareza semântica é essencial. O conteúdo precisa explicar o conceito, mostrar aplicação prática, conectar termos relacionados e evitar promessas sem comprovação. Isso ajuda tanto o leitor humano quanto modelos de linguagem a interpretar o contexto da página.</p>';
            $sections .= '<h2>Checklist editorial antes de publicar</h2><p>Antes de publicar, confirme se a palavra-chave aparece no início do texto, em pelo menos um subtítulo, na meta descrição e de forma natural ao longo do conteúdo. Também verifique se há links internos, imagem com alt text, FAQ, schema e uma conclusão útil.</p>';
        } else {
            $sections .= '<h2 class="sara-safe-expansion-block">Pontos importantes antes de tomar uma decisão</h2>';
            $sections .= '<p>Antes de aplicar as orientações deste conteúdo, avalie contexto, objetivo, riscos, alternativas e fontes confiáveis. Uma decisão melhor nasce da comparação entre critérios claros, e não de uma promessa isolada ou de uma informação sem confirmação.</p>';
            $sections .= '<h2>Como usar este guia com segurança</h2><p>Use este conteúdo como ponto de partida para organizar dúvidas, comparar caminhos possíveis e identificar o que precisa ser confirmado antes de agir. Quando houver detalhes técnicos, comerciais ou legais, confirme em fontes oficiais ou especializadas.</p>';
        }
        return self::insert_before_faq_or_author($content, "\n\n" . $sections . "\n");
    }

    private static function ensure_faq_schema_final(int $post_id, string $content, string $title, string $category, string $niche): string {
        if (!class_exists(__NAMESPACE__ . '\SaraDeepFAQGenerator')) return $content;
        $has_faq_html = (bool) preg_match('/sara-faq-section|geo-faq-section|FAQPage|Perguntas\s+Frequentes|Perguntas\s+frequentes/iu', $content);
        $schema_raw = (string) get_post_meta($post_id, 'geo_faq_schema', true);
        $schema_ok = false;
        if ($schema_raw !== '') {
            $decoded = json_decode($schema_raw, true);
            $schema_ok = is_array($decoded) && (($decoded['@type'] ?? '') === 'FAQPage') && !empty($decoded['mainEntity']);
        }

        if ($has_faq_html && $schema_ok) {
            update_post_meta($post_id, '_aeo_faq_active', '1');
            return $content;
        }

        $faq_gen = new SaraDeepFAQGenerator();
        $faqs = $faq_gen->generate($title, $category, $niche);
        if (empty($faqs)) {
            $faqs = [
                [
                    'question' => 'O que considerar antes de aplicar este conteúdo na prática?',
                    'answer' => 'Antes de tomar uma decisão, confirme se as informações fazem sentido para o seu contexto, verifique dados oficiais quando existirem e compare critérios objetivos como necessidade, custo, suporte, atualização, segurança e compatibilidade. Essa abordagem reduz decisões baseadas em promessas genéricas.',
                    'type' => 'local',
                ],
                [
                    'question' => 'Como evitar interpretações erradas sobre este tema?',
                    'answer' => 'Evite assumir números, preços, datas, recursos ou resultados sem confirmação. O ideal é usar o conteúdo como orientação inicial e validar detalhes técnicos ou comerciais em fontes oficiais, páginas de fabricante, documentação ou canais reconhecidos.',
                    'type' => 'local',
                ],
                [
                    'question' => 'Quando vale a pena aprofundar o assunto?',
                    'answer' => 'Vale aprofundar quando o tema envolve decisão de compra, configuração técnica, atualização, compatibilidade, segurança, desempenho ou comparação entre alternativas. Nesses casos, um artigo complementar pode detalhar critérios, riscos e passos práticos.',
                    'type' => 'local',
                ],
                [
                    'question' => 'Este conteúdo substitui uma fonte oficial?',
                    'answer' => 'Não. O conteúdo ajuda a organizar a decisão e explicar conceitos, mas informações específicas devem ser confirmadas em fontes oficiais, especialmente quando envolvem ficha técnica, preço, disponibilidade, atualização, garantia ou política de suporte.',
                    'type' => 'local',
                ],
            ];
        }

        if (!$has_faq_html) {
            $content = self::remove_inline_faq($content);
            $content .= "\n\n" . $faq_gen->render_html($faqs);
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
        }

        $schema = $faq_gen->build_schema($faqs);
        update_post_meta($post_id, 'geo_faq_schema', $schema);
        update_post_meta($post_id, '_sara_faq_count', count($faqs));
        update_post_meta($post_id, '_sara_faq_mode', 'forced_local_final');
        update_post_meta($post_id, '_aeo_faq_active', '1');
        AutopilotLogger::log('writer', 'faq_final_ensured', 'success', "Post #{$post_id}: FAQ/schema garantidos no pós-processamento final", ['post_id' => $post_id, 'faq_count' => count($faqs)]);
        return $content;
    }

    public static function remove_inline_faq(string $content): string {
        $patterns = [
            '/<div\s+class="[^"]*(?:sara-faq-section|geo-faq-section)[^"]*"[^>]*>.*?<\/div>\s*/isu',
            '/<section\s+class="[^"]*(?:sara-faq-section|geo-faq-section)[^"]*"[^>]*>.*?<\/section>\s*/isu',
            '/<h[23][^>]*>\s*(?:perguntas\s+frequentes|faq|f\.a\.q\.?)[^<]*<\/h[23]>.*?(?=<h[23]|<div\s+class="[^"]*(?:geo-author-box|sara-author|geo-eeat)|$)/isu',
        ];
        foreach ($patterns as $pattern) {
            $new = preg_replace($pattern, '', $content);
            if ($new !== null) $content = $new;
        }
        return trim($content);
    }

    /**
     * 1.0.0 — Guarda factual forte pós-escrita.
     * Remove padrões de alucinação comuns: caso real inventado, método autoral inventado,
     * fontes/estatísticas sem briefing e linguagem comercial exagerada.
     */
    public static function apply_factual_guard(string $content, string $context): string {
        $ctx = mb_strtolower(remove_accents($context));
        $is_mobile = (bool) preg_match('/xiaomi|redmi|poco|hyperos|android|smartphone|celular|telefone|samsung|motorola|iphone/u', $ctx);

        // Remover blocos de "caso real" inventado. Substitui por exemplo hipotético seguro.
        $content = preg_replace(
            '/<h[23][^>]*>\s*(?:caso\s+real|hist[oó]ria\s+real|exemplo\s+real|estudo\s+de\s+caso)[^<]*<\/h[23]>.*?(?=<h2|<h3|$)/isu',
            '<h2>Exemplo prático de decisão</h2><p>Em vez de assumir um caso real específico, use o cenário de compra como exercício: compare perfil de uso, garantia, política de atualização, armazenamento, bateria, câmera e suporte antes de escolher um aparelho. Esses critérios são mais seguros do que depender de relatos sem fonte verificável.</p>',
            $content
        ) ?? $content;

        // Remover métodos autorais criados pela IA (ex.: P.A.R.A.X.) e trocar por checklist neutro.
        $content = preg_replace(
            '/<h[23][^>]*>\s*(?:m[eé]todo|framework|f[oó]rmula)\s+[A-Z](?:\.[A-Z]){2,}\.?.*?<\/h[23]>.*?(?=<h2|<h3|$)/isu',
            '<h2>Checklist prático de avaliação</h2><p>Para tomar uma decisão mais segura, avalie uso principal, orçamento, versão do sistema, política de atualização, garantia, assistência, bateria, tela, câmera, armazenamento e conectividade. Esse checklist evita conclusões baseadas em promessas sem comprovação.</p>',
            $content
        ) ?? $content;

        // Remover frases de autoridade falsa ou estatística sem fonte verificável.
        $content = preg_replace('/\b(?:segundo|de acordo com)\s+(?:a\s+)?(?:Statista|Kantar|IDC|Gartner|McKinsey|Deloitte|PwC|KPMG|DXOMARK)\b[^.!?]*(?:[.!?])/iu', '', $content) ?? $content;
        $content = preg_replace('/\b(?:pesquisa|estudo|relat[oó]rio|levantamento)\s+(?:mostra|aponta|revela|comprova)[^.!?]*(?:[.!?])/iu', '', $content) ?? $content;
        $content = preg_replace('/\b(?:mais\s+de\s+)?\d+\s+(?:compras|casos|clientes|aparelhos)\s+(?:reais\s+)?(?:que\s+)?(?:analisei|testei|acompanhei)[^.!?]*(?:[.!?])/iu', '', $content) ?? $content;

        // Em mobile/smartphones, remover percentuais e valores soltos com alegação forte sem link/fonte próxima.
        if ($is_mobile) {
            $content = preg_replace('/<p[^>]*>[^<]*(?:\d+[,.]?\d*\s*%|R\$\s*\d|\d+[,.]?\d*\s*milh(?:ões|oes))[^<]*(?:mercado|vendas|brasileiros|desvaloriza|risco|crescimento|unidades|pretendem|representa)[^<]*<\/p>/isu', '', $content) ?? $content;
            $content = str_ireplace(['sem travamentos', 'desempenho extremo', 'alta procura', 'garantido', 'detona seus rivais'], ['uso mais estável', 'bom desempenho dependendo do modelo', 'procura variável', 'possível', 'deve ser comparado com rivais reais'], $content);
        }

        return trim(preg_replace('/\n{3,}/', "\n\n", $content) ?? $content);
    }

    /**
     * 1.0.0 — Corrige tabelas que saíram como texto corrido em vez de HTML.
     */
    public static function clean_broken_text_tables(string $content, string $context): string {
        $ctx = mb_strtolower(remove_accents($context));
        $is_mobile = (bool) preg_match('/xiaomi|redmi|poco|smartphone|celular|android|hyperos/u', $ctx);
        if (!$is_mobile) return $content;

        $replacement = '<h2>Checklist de comparação segura</h2>'
            . '<table class="sara-data-table sara-safe-buying-table" style="width:100%;border-collapse:collapse;margin:24px 0;">'
            . '<thead><tr><th style="border:1px solid #e5e7eb;padding:10px;text-align:left;">Critério</th><th style="border:1px solid #e5e7eb;padding:10px;text-align:left;">Como avaliar sem inventar dados</th></tr></thead><tbody>'
            . '<tr><td style="border:1px solid #e5e7eb;padding:10px;">Atualização</td><td style="border:1px solid #e5e7eb;padding:10px;">Verifique a versão do Android/HyperOS e a política oficial do fabricante para o modelo.</td></tr>'
            . '<tr><td style="border:1px solid #e5e7eb;padding:10px;">Garantia</td><td style="border:1px solid #e5e7eb;padding:10px;">Confirme nota fiscal, assistência e cobertura no Brasil antes da compra.</td></tr>'
            . '<tr><td style="border:1px solid #e5e7eb;padding:10px;">Desempenho</td><td style="border:1px solid #e5e7eb;padding:10px;">Compare testes independentes e o perfil de uso, sem assumir benchmarks não fornecidos.</td></tr>'
            . '<tr><td style="border:1px solid #e5e7eb;padding:10px;">Bateria e câmera</td><td style="border:1px solid #e5e7eb;padding:10px;">Avalie reviews confiáveis e uso real, porque números isolados não contam toda a experiência.</td></tr>'
            . '</tbody></table>';

        $content = preg_replace('/<p[^>]*>\s*Crit[eé]rio\s+(?:Redmi|POCO|Xiaomi|Samsung|Motorola|iPhone)[^<]{120,1200}<\/p>/isu', $replacement, $content) ?? $content;
        $content = preg_replace('/(^|\n)\s*Crit[eé]rio\s+(?:Redmi|POCO|Xiaomi|Samsung|Motorola|iPhone).{120,1200}(?=<h2|\n\s*<h2|$)/isu', "\n" . $replacement . "\n", $content) ?? $content;
        return $content;
    }

    /**
     * Evita blocos comerciais repetidos invadindo o corpo editorial.
     */
    public static function normalize_commercial_blocks(string $content): string {
        $needle = 'Veja todos os modelos de celular da Xiaomi';
        if (mb_substr_count(wp_strip_all_tags($content), $needle) <= 1) return $content;
        $first = true;
        return preg_replace_callback('/<(?:div|section|aside)[^>]*>[^<]*(?:Veja todos os modelos de celular da Xiaomi).*?<\/(?:div|section|aside)>/isu', function($m) use (&$first) {
            if ($first) { $first = false; return $m[0]; }
            return '';
        }, $content) ?? $content;
    }

    public static function normalize_quick_answer(string $content): string {
        // Remove qualquer duplicação de Resposta Rápida, inclusive variações com aspas simples,
        // class order diferente ou blocos criados pelo ArticlePipeline/Gerador em Massa.
        $pattern = '/<(div|section)\b[^>]*class\s*=\s*(["\'])(?=[^"\']*(?:geo|sara)-quick-answer)[^"\']*\2[^>]*>.*?<\/\1>\s*/isu';
        $blocks = [];
        if (preg_match_all($pattern, $content, $m)) {
            $blocks = $m[0];
        }
        $content = preg_replace($pattern, '', $content) ?? $content;

        // Fallback para blocos de quick answer com HTML quebrado ou sem classe esperada.
        if (empty($blocks) && preg_match('/<(div|section)\b[^>]*>\s*<strong[^>]*>\s*(?:⚡\s*)?Resposta\s+Rápida.*?<\/\1>\s*/isu', $content, $m)) {
            $blocks[] = $m[0];
            $content = str_replace($m[0], '', $content);
        }

        if (empty($blocks)) {
            return trim($content);
        }

        $text = trim(wp_strip_all_tags($blocks[0]));
        $text = preg_replace('/^\s*(⚡\s*)?Resposta\s+Rápida\s*:?\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = self::limit_words($text, 75);
        if ($text === '') return trim($content);

        $box = '<div class="sara-quick-answer geo-quick-answer" style="background:rgba(0,115,170,.08);border-left:4px solid #0073aa;padding:16px 20px;margin:0 0 28px;border-radius:0 8px 8px 0;"><strong style="display:block;margin-bottom:8px;color:#0073aa;">⚡ Resposta Rápida</strong><p style="margin:0;font-size:16px;line-height:1.6;">' . esc_html($text) . '</p></div>';
        return $box . "\n\n" . trim($content);
    }

    /** Garante Resposta Rápida no início mesmo quando o provider/fluxo não entregou. */
    private static function ensure_quick_answer_final(string $content, string $keyword, string $title): string {
        if (preg_match('/<(?P<tag>div|section)\s+class="[^"]*(?:geo|sara)-quick-answer[^"]*"[^>]*>.*?<\/\k<tag>>/isu', $content)) {
            // Se já existe, normalize_quick_answer já moveu para o topo.
            return $content;
        }
        $topic = trim(wp_strip_all_tags($keyword ?: $title));
        if ($topic === '') $topic = 'este tema';
        $topic_clean = preg_replace('/\s+/u', ' ', $topic) ?? $topic;
        $text = $topic_clean . ' deve ser explicado com clareza, contexto prático e estrutura otimizada para SEO, GEO e AEO. O conteúdo precisa responder rapidamente à intenção de busca, organizar os subtópicos com lógica e facilitar a leitura para usuários, mecanismos de busca e sistemas de IA.';
        $text = self::limit_words($text, 72);
        $box = '<div class="sara-quick-answer geo-quick-answer" style="background:rgba(0,115,170,.08);border-left:4px solid #0073aa;padding:16px 20px;margin:0 0 28px;border-radius:0 8px 8px 0;"><strong style="display:block;margin-bottom:8px;color:#0073aa;">⚡ Resposta Rápida</strong><p style="margin:0;font-size:16px;line-height:1.6;">' . esc_html($text) . '</p></div>';
        return $box . "\n\n" . ltrim($content);
    }

    /** Garante que exista uma conclusão antes do FAQ e que ela não fique depois das perguntas. */
    private static function ensure_conclusion_before_faq(string $content, string $keyword, string $title): string {
        $faq_pattern = '/<(?:div|section)\b[^>]*class="[^"]*(?:sara-faq-section|geo-faq-section)[^"]*"[^>]*>|<h2[^>]*>\s*(?:perguntas\s+frequentes|faq)[^<]*<\/h2>/isu';
        $conclusion_pattern = '/<h2[^>]*>\s*(?:conclus[aã]o|considera[cç][oõ]es\s+finais)[^<]*<\/h2>/isu';

        $faq_pos = null;
        if (preg_match($faq_pattern, $content, $fm, PREG_OFFSET_CAPTURE)) {
            $faq_pos = (int)$fm[0][1];
        }
        if ($faq_pos === null) {
            if (preg_match($conclusion_pattern, $content)) return $content;
            $topic = self::clean_editorial_topic($keyword, $title);
            return rtrim($content) . "\n\n" . '<h2>Conclusão</h2><p>Em resumo, ' . esc_html($topic) . ' deve ser avaliado com foco em utilidade real, clareza editorial, organização semântica e aplicação prática. Um bom conteúdo não depende apenas de volume de texto, mas de responder bem à intenção de busca, orientar o leitor e manter consistência técnica até a publicação.</p>';
        }

        if (preg_match($conclusion_pattern, $content, $cm, PREG_OFFSET_CAPTURE)) {
            $conclusion_pos = (int)$cm[0][1];
            if ($conclusion_pos < $faq_pos) {
                $next_pos = $faq_pos;
                $block_text = trim(wp_strip_all_tags(substr($content, $conclusion_pos, max(0, $next_pos - $conclusion_pos))));
                if (mb_strlen($block_text) >= 180 && !preg_match('/por\s*(?:\.\.\.|$)|para\s*(?:\.\.\.|$)|com\s*(?:\.\.\.|$)/iu', $block_text)) return $content;
                $safe_topic = self::clean_editorial_topic($keyword, $title);
                $replacement = '<h2>Conclusão</h2><p>Em resumo, ' . esc_html($safe_topic) . ' funciona melhor quando o conteúdo é planejado com intenção clara, estrutura semântica, imagens bem distribuídas, tabela útil, FAQ consistente e revisão editorial antes da publicação. Esse conjunto ajuda o leitor a entender o tema e também facilita a interpretação por mecanismos de busca e sistemas de IA.</p>';
                $content = substr($content, 0, $conclusion_pos) . $replacement . "

" . substr($content, $faq_pos);
                return $content;
            }
            // Conclusão existe depois do FAQ: recorta o bloco e move para antes do FAQ.
            $start = $conclusion_pos;
            $after = substr($content, $start);
            $end_rel = strlen($after);
            if (preg_match('/<h2[^>]*>/isu', substr($after, strlen($cm[0][0])), $next, PREG_OFFSET_CAPTURE)) {
                $end_rel = strlen($cm[0][0]) + (int)$next[0][1];
            }
            $block = trim(substr($content, $start, $end_rel));
            $content = substr($content, 0, $start) . substr($content, $start + $end_rel);
            if (preg_match($faq_pattern, $content, $fm2, PREG_OFFSET_CAPTURE)) {
                $faq_pos = (int)$fm2[0][1];
                return substr($content, 0, $faq_pos) . "\n\n" . $block . "\n\n" . substr($content, $faq_pos);
            }
            return rtrim($content) . "\n\n" . $block;
        }

        $topic = self::clean_editorial_topic($keyword, $title);
        $block = '<h2>Conclusão</h2><p>Em resumo, ' . esc_html($topic) . ' deve ser tratado com clareza, profundidade e foco na intenção de busca. A estrutura ideal combina resposta rápida, desenvolvimento bem organizado, tabela, imagens, dicas profissionais e perguntas frequentes para entregar valor ao leitor e facilitar a interpretação por mecanismos de busca e sistemas de IA.</p>';
        return substr($content, 0, $faq_pos) . "\n\n" . $block . "\n\n" . substr($content, $faq_pos);
    }

    /**
     * Evita seção de tendências falando de ano futuro quando o título/keyword fixa outro ano.
     * Ex.: título fala 2026 e o provider escreve "Tendências em 2027".
     */
    private static function normalize_future_year_mismatch(string $content, string $keyword, string $title): string {
        $context = $keyword . ' ' . $title;
        if (!preg_match('/\b(20[2-9][0-9])\b/u', $context, $m)) {
            return $content;
        }
        $target_year = (int)$m[1];
        if ($target_year < 2020 || $target_year > 2099) return $content;

        return preg_replace_callback('/(<h2[^>]*>.*?(?:tend[eê]ncias?|previs[oõ]es?|futuro).*?<\/h2>)(.*?)(?=<h2\b|<section\b|<div\b[^>]*class=["\'][^"\']*(?:sara-faq-section|geo-author-box|related)[^"\']*["\']|$)/isu', function($m) use ($target_year) {
            $block = $m[0];
            return preg_replace_callback('/\b(20[2-9][0-9])\b/u', function($ym) use ($target_year) {
                $year = (int)$ym[1];
                return $year > $target_year ? (string)$target_year : (string)$year;
            }, $block) ?? $block;
        }, $content) ?? $content;
    }

    private static function limit_words(string $text, int $limit): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text, $m, PREG_OFFSET_CAPTURE);
        $words = $m[0] ?? [];
        if (count($words) <= $limit) return $text;
        $last = $words[$limit - 1][1] + strlen($words[$limit - 1][0]);
        return rtrim(mb_substr($text, 0, $last), " ,;:.-") . '.';
    }

    public static function normalize_tables(string $content): string {
        // Converter tabelas markdown com pipes para HTML real.
        $content = preg_replace_callback('/(?:^|\n)(\|.+\|\s*\n\|[\s:\-\|]+\|\s*\n(?:\|.*\|\s*\n?)+)/mu', function($m) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", trim($m[1])))));
            if (count($lines) < 3) return $m[0];
            $headers = array_map('trim', explode('|', trim($lines[0], '|')));
            $html = '<table class="sara-data-table" style="width:100%;border-collapse:collapse;margin:24px 0;"><thead><tr>';
            foreach ($headers as $h) $html .= '<th style="border:1px solid #e5e7eb;padding:10px;text-align:left;">' . esc_html($h) . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach (array_slice($lines, 2) as $line) {
                $cells = array_map('trim', explode('|', trim($line, '|')));
                if (count($cells) < 2) continue;
                $html .= '<tr>';
                foreach ($cells as $c) $html .= '<td style="border:1px solid #e5e7eb;padding:10px;vertical-align:top;">' . esc_html($c) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            return "\n" . $html . "\n";
        }, $content) ?? $content;
        return $content;
    }


    /**
     * Substitui tabelas de baixa informação (ex.: comparativos cheios de "Não informado")
     * por uma matriz editorial útil. Isso evita publicar conteúdo que parece técnico,
     * mas não entrega valor real ao leitor quando o briefing não possui dados confirmados.
     */
    public static function replace_low_information_tables(string $content, string $context): string {
        $ctx = mb_strtolower($context);
        $is_product_or_mobile = (bool) preg_match('/poco|xiaomi|redmi|samsung|motorola|iphone|apple|android|smartphone|celular|review|comprar|lançamento|lancamento|pro|max|ultra/u', $ctx);
        if (!$is_product_or_mobile || stripos($content, '<table') === false) {
            return $content;
        }

        $low_info_terms = [
            'não informado', 'nao informado', 'não informada', 'nao informada',
            'não há confirmação', 'nao ha confirmacao', 'não existe confirmação',
            'sem testes', 'sem teste', 'sem dados', 'não disponível', 'nao disponivel',
            'não é possível concluir', 'nao e possivel concluir', 'não confirmado',
            'nao confirmado', 'não confirmada', 'nao confirmada', 'indisponível',
            'indisponivel', 'não fornecido', 'nao fornecido', 'não fornecida',
            'nao fornecida', 'não citado', 'nao citado', 'não citada', 'nao citada'
        ];

        return preg_replace_callback('/<table\b[^>]*>.*?<\/table>/isu', function($m) use ($low_info_terms, $ctx) {
            $table = $m[0];
            $plain = mb_strtolower(wp_strip_all_tags($table));
            $hits = 0;
            foreach ($low_info_terms as $term) {
                $hits += substr_count($plain, $term);
            }

            // Contar células para evitar substituir uma tabela boa só porque tem uma observação cautelosa.
            preg_match_all('/<t[dh]\b[^>]*>.*?<\/t[dh]>/isu', $table, $cells);
            $cell_count = count($cells[0] ?? []);
            $ratio = $cell_count > 0 ? ($hits / max(1, $cell_count)) : 0;

            if ($hits < 4 && $ratio < 0.30) {
                return $table;
            }

            $topic = 'o produto ou tema analisado';
            if (preg_match('/(poco\s+x8\s+pro\s+max|poco\s+x8\s+pro|poco|xiaomi|redmi|samsung|motorola|iphone|android)/iu', $ctx, $mm)) {
                $topic = trim($mm[1]);
            }

            $rows = [
                ['Confirmação oficial', 'Verificar se o fabricante publicou página, comunicado ou suporte oficial.', 'Evita comprar com base apenas em rumor, vídeo promocional ou título chamativo.'],
                ['Ficha técnica completa', 'Conferir processador, tela, bateria, câmeras, memória e armazenamento em fonte confiável.', 'Permite comparar valor real antes de decidir.'],
                ['Preço e disponibilidade no Brasil', 'Confirmar preço, garantia, nota fiscal, estoque e variante vendida no país.', 'Preço e garantia mudam totalmente a recomendação de compra.'],
                ['Testes independentes', 'Procurar análises com benchmark, bateria, câmera, temperatura e uso real.', 'Ajuda a separar promessa comercial de desempenho comprovado.'],
                ['Comparação com rivais reais', 'Comparar com modelos já disponíveis na mesma faixa de preço.', 'Mostra se a compra faz sentido ou se existe opção melhor.'],
            ];

            $html = '<table class="sara-data-table sara-verification-matrix" style="width:100%;border-collapse:collapse;margin:24px 0;">';
            $html .= '<thead><tr>';
            $html .= '<th style="border:1px solid #e5e7eb;padding:10px;text-align:left;">O que verificar</th>';
            $html .= '<th style="border:1px solid #e5e7eb;padding:10px;text-align:left;">Como confirmar</th>';
            $html .= '<th style="border:1px solid #e5e7eb;padding:10px;text-align:left;">Impacto para o leitor</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>';
                foreach ($r as $cell) {
                    $html .= '<td style="border:1px solid #e5e7eb;padding:10px;vertical-align:top;">' . esc_html($cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $note = '<p class="sara-verification-note"><strong>Nota editorial:</strong> quando os dados de ' . esc_html($topic) . ' ainda não estão confirmados no contexto disponível, a análise deve priorizar critérios de verificação em vez de uma comparação conclusiva.</p>';
            return $note . "\n" . $html;
        }, $content) ?? $content;
    }

    public static function remove_irrelevant_generic_sources(string $content, string $context): string {
        $ctx = mb_strtolower($context);
        $is_mobile = preg_match('/android|smartphone|celular|samsung|motorola|xiaomi|poco|iphone|apple|redmi|galaxy|moto/u', $ctx);
        if (!$is_mobile) return $content;
        // Remover links acadêmicos/genéricos quando o contexto pede fonte oficial de fabricante/plataforma.
        $content = preg_replace('/\s*\((?:\s*<a[^>]+href="https?:\/\/(?:www\.)?(?:acm\.org|dl\.acm\.org|ieee\.org|mit\.edu|wikipedia\.org|pt\.wikipedia\.org|openai\.com|anthropic\.com)[^>]*>.*?<\/a>\s*|\s*(?:ACM|IEEE|MIT|Wikip[eé]dia)\s*)\)/isu', '', $content) ?? $content;
        $content = preg_replace('/<li>\s*(?:Fonte oficial:\s*)?<a[^>]+href="https?:\/\/(?:www\.)?(?:acm\.org|dl\.acm\.org|ieee\.org|mit\.edu|wikipedia\.org|pt\.wikipedia\.org|openai\.com|anthropic\.com)[^>]*>.*?<\/a>\s*<\/li>/isu', '', $content) ?? $content;
        return $content;
    }

    public static function append_contextual_official_sources(string $content, string $context): string {
        if (str_contains($content, 'sara-official-sources')) return $content;
        if (str_contains($content, 'sara-authority-sources')) return $content;

        // Fontes baseadas em ENTIDADES REAIS citadas no artigo (context + conteúdo).
        // Removido o mapa por regex e o FALLBACK genérico (Google Acadêmico/Wikipédia),
        // que colavam fontes sem relação com o tema. Agora: se nenhuma entidade real
        // for citada, o artigo fica SEM bloco de fontes — melhor que fonte falsa.
        $sources = [];
        if (class_exists('\\GeoMetodoSEO\\Services\\ContextEngine')) {
            $links = \GeoMetodoSEO\Services\ContextEngine::get_authority_links($context, $content);
            foreach ($links as $l) {
                $sources[] = [$l['anchor'], $l['url']];
            }
        }
        if (empty($sources)) return $content; // nada relevante → não inventa fonte

        $lis = '';
        foreach (array_slice($sources, 0, 3) as $s) {
            if (stripos($content, $s[1]) !== false) continue;
            $lis .= '<li><a href="' . esc_url($s[1]) . '" target="_blank" rel="nofollow noopener noreferrer">' . esc_html($s[0]) . '</a></li>';
        }
        if ($lis === '') return $content;
        $block = "\n\n<aside class=\"sara-official-sources\" style=\"margin:24px 0;padding:16px 18px;background:transparent;color:inherit;border:1px solid rgba(148,163,184,.35);border-radius:6px;\"><p style=\"margin:0 0 8px;font-weight:600;color:inherit;\">📚 Fontes oficiais para conferir</p><ul style=\"margin:0;padding-left:20px;color:inherit;\">{$lis}</ul></aside>\n";
        // Inserir antes do FAQ/autor se já existir.
        if (preg_match('/(<h2[^>]*>\s*(?:perguntas\s+frequentes|faq)\s*<\/h2>|<div\s+class="[^"]*(?:geo-author-box|sara-author|geo-eeat)[^"]*")/isu', $content, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            return substr($content, 0, $pos) . $block . substr($content, $pos);
        }
        return $content . $block;
    }

    public static function insert_featured_image_in_body(string $content, string $image_url, string $keyword, string $title): string {
        if (!$image_url || str_contains($content, 'sara-featured-fallback') || str_contains($content, $image_url)) {
            return $content;
        }
        $alt = esc_attr(trim($keyword . ' - ' . $title));
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

        private static function build_contextual_image_prompt(string $keyword, string $section, int $index = 1): string {
        $ctx = mb_strtolower(remove_accents($keyword . ' ' . $section));
        $base = trim($section) !== '' ? trim($section) : trim($keyword);
        $negative = 'no text, no words, no logos, no watermark, no random landscape, no unrelated objects';
        if (preg_match('/xiaomi|redmi|poco|hyperos|android|smartphone|celular|telefone/u', $ctx)) {
            $variants = [
                1 => 'realistic editorial photo of a generic Android smartphone being evaluated before purchase, modern desk, Brazilian consumer technology context',
                2 => 'close-up of smartphone settings and software update concept, security patch, app compatibility, clean tech blog style',
                3 => 'person comparing smartphone models on a screen and taking notes, buying guide context, realistic editorial photography',
                4 => 'smartphone battery camera and performance evaluation concept, modern technology workspace, realistic photo',
            ];
            return ($variants[$index] ?? $variants[1]) . '. Article topic: ' . $base . '. Main keyword: ' . $keyword . '. ' . $negative . ', 16:9, high quality.';
        }
        if (preg_match('/seo|geo|aeo|llm|ia|inteligencia|rank math|wordpress|google|conteudo|blog/u', $ctx)) {
            $variants = [
                1 => 'professional SEO content dashboard with search analytics, semantic map and editorial planning, realistic modern workspace',
                2 => 'knowledge graph and website optimization workflow on a computer screen, realistic editorial technology image',
                3 => 'content strategist reviewing search performance and internal links, professional blog editorial setting',
                4 => 'structured data and search results optimization concept, clean modern SEO workspace',
            ];
            return ($variants[$index] ?? $variants[1]) . '. Article topic: ' . $base . '. Main keyword: ' . $keyword . '. ' . $negative . ', 16:9, high quality.';
        }
        return 'realistic professional editorial image directly related to: ' . $base . '. Main keyword: ' . $keyword . '. Show the practical context of the section, clean blog photo style, ' . $negative . ', 16:9, high quality.';
    }

private static function force_insert_body_chain_images(string $content, string $keyword, int $count): string {
        if (class_exists('GeoMetodoSEO\Services\GeoMediaMasterService')) {
            return $content;
        }
        return $content;
    }

    /** Remove sobras de providers e HTML quebrado que podem vazar no início/fim do artigo. */
    private static function sanitize_provider_html_artifacts(string $content): string {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $patterns = [
            '/<p>\s*```(?:html|php|json|javascript|js|css)?\s*<\/p>/iu',
            '/<p>\s*```\s*<\/p>/iu',
            '/```(?:html|php|json|javascript|js|css)?/iu',
            '/```/u',
            '/<!--\s*wp:paragraph\s*-->\s*<p>\s*<\/p>\s*<!--\s*\/wp:paragraph\s*-->/iu',
            '/<!--\s*wp:paragraph\s*-->\s*<!--\s*\/wp:paragraph\s*-->/iu',
            '/<p>\s*<\/p>/iu',
            '/<(?:html|head|body|title|meta)[^>]*>/iu',
            '/<\/(?:html|head|body|title|meta)>/iu',
        ];
        foreach ($patterns as $p) {
            $content = preg_replace($p, '', $content) ?? $content;
        }
        // Corrige parágrafos abertos/fechados indevidamente dentro de blocos div/section.
        $content = preg_replace('/background\s*:\s*(?:#fff|#ffffff|#f8fafc|#f9f9f9)\s*;?/iu', 'background:transparent;', $content) ?? $content;
        $content = preg_replace('/color\s*:\s*(?:#555|#333|#222|#1e293b|#475569)\s*;?/iu', 'color:inherit;', $content) ?? $content;
        $content = preg_replace('/(<(?:div|section|aside)\b[^>]*>)\s*<p>/iu', '$1', $content) ?? $content;
        $content = preg_replace('/<\/p>\s*(<\/(?:div|section|aside)>)/iu', '$1', $content) ?? $content;
        $content = preg_replace('/<p>\s*(<(?:div|section|aside|figure|table)\b)/iu', '$1', $content) ?? $content;
        $content = preg_replace('/(<\/(?:div|section|aside|figure|table)>)\s*<\/p>/iu', '$1', $content) ?? $content;
        $content = preg_replace('/(<\/(?:div|section|aside)>)\s*<\/a>/iu', '$1', $content) ?? $content;
        $content = preg_replace('/<a\b([^>]*)>\s*<\/a>/isu', '', $content) ?? $content;
        // Remove links aninhados causados por interlinking repetido.
        for ($i = 0; $i < 3; $i++) {
            $content = preg_replace('/<a\b([^>]*)>\s*<a\b[^>]*>(.*?)<\/a>\s*<\/a>/isu', '<a$1>$2</a>', $content) ?? $content;
        }
        return trim(preg_replace("/\n{3,}/", "\n\n", $content) ?? $content);
    }

    /** Melhora H2 fracos sem inventar nova pauta. */
    private static function normalize_heading_quality(string $content, string $keyword, string $title): string {
        $base = trim(wp_strip_all_tags($keyword ?: $title));
        $base = preg_replace('/\s+é\s+o\s+melhor\s+plugin.*$/iu', '', $base) ?? $base;
        $base = preg_replace('/\s+como\s+funciona.*$/iu', '', $base) ?? $base;
        $base = trim($base, " .:;,-");
        if ($base === '') $base = 'GEO Método SEO';

        return preg_replace_callback('/<h2([^>]*)>(.*?)<\/h2>/isu', function($m) use ($base) {
            $attrs = $m[1];
            $h = trim(wp_strip_all_tags($m[2]));
            $clean = preg_replace('/\s+/u', ' ', $h) ?? $h;
            $lc = mb_strtolower($clean);
            $clean = preg_replace('/^O que é O\b/u', 'O que é o', $clean) ?? $clean;
            $clean = preg_replace('/^O que é A\b/u', 'O que é a', $clean) ?? $clean;
            if (preg_match('/como funciona na prática\?:\s*como avaliar na prática/iu', $clean)) {
                $clean = 'Como o ' . $base . ' funciona na prática';
            }
            if (preg_match('/^guia prÃ¡tico:\s*como escolher$/iu', $clean)) {
                $clean = 'Como usar ' . $base . ' com estratégia';
            }
            if (preg_match('/^o que é\s+(.+?)\s+é\.{0,3}$/iu', $clean, $mm)) {
                $clean = 'O que é ' . trim($mm[1]);
            }
            if (preg_match('/^o que é\s+.+\.\.\.$/iu', $clean)) {
                $clean = 'O que é ' . $base;
            }
            if (preg_match('/^'.preg_quote($base, '/').'\s+é\s+o\s+melhor\s+plugin.*como avaliar na prática$/iu', $clean) || preg_match('/é\s+o\s+melhor\s+plugin.*como avaliar na prática/iu', $clean)) {
                $clean = 'Como avaliar o ' . $base . ' na prática';
            }
            if ($lc === 'como funciona') {
                $clean = 'Como o ' . $base . ' funciona na prática';
            }
            if ($lc === 'benefícios principais') {
                $clean = 'Principais benefícios do ' . $base;
            }
            if ($lc === 'dados e comparativo') {
                $clean = 'Dados e comparativo do ' . $base;
            }
            $lc_ascii = mb_strtolower(remove_accents($clean));
            if ($lc_ascii === 'beneficios principais' || str_contains($lc_ascii, 'principais beneficios')) {
                $clean = 'Principais recursos do ' . $base;
            }
            if ($lc_ascii === 'dados e comparativo' || str_contains($lc_ascii, 'dados e comparativo')) {
                $clean = 'Comparativo entre SEO tradicional, GEO e AEO';
            }
            if (mb_strlen($clean) > 92) {
                $clean = rtrim(mb_substr($clean, 0, 88), " ,;:-") . '...';
            }
            return '<h2' . $attrs . '>' . esc_html($clean) . '</h2>';
        }, $content) ?? $content;
    }

    /** Limpa e estabiliza o assunto usado em H2s/blocos automáticos. */
    private static function clean_editorial_topic(string $keyword, string $title): string {
        $topic = trim(wp_strip_all_tags($title ?: $keyword));
        if (($topic === '' || (preg_match('/^(veja|como|funciona|plugin|seo|geo|aeo)(\s|$)/iu', $topic) && mb_strlen($topic) < 32))) {
            $topic = trim(wp_strip_all_tags($keyword ?: $title));
        }
        $topic = html_entity_decode($topic, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $topic = preg_replace('/\s+/u', ' ', $topic) ?? $topic;
        $topic = preg_replace('/\s+é\s+o\s+melhor\s+plugin.*$/iu', '', $topic) ?? $topic;
        $topic = preg_replace('/\s+como\s+funciona\s+na\s+pr[aá]tica\??.*$/iu', '', $topic) ?? $topic;
        $topic = preg_replace('/^(veja\s+)?como\s+funciona\s+/iu', '', $topic) ?? $topic;
        $topic = trim($topic, " .:;,-?\t\n\r\0\x0B");
        if ($topic === '' || mb_strlen($topic) < 8) $topic = 'Plugin GEO Método SEO';
        return $topic;
    }

    /** Move a primeira tabela para antes de dicas/conclusão/FAQ/autor/relacionados. */
    private static function move_table_before_final_blocks(string $content): string {
        if (stripos($content, '<table') === false) return $content;
        if (!preg_match('/<table\b[^>]*>.*?<\/table>/isu', $content, $tm, PREG_OFFSET_CAPTURE)) return $content;
        $table = $tm[0][0];
        $table_pos = (int)$tm[0][1];
        $marker_pattern = '/<h2[^>]*>\s*(?:dicas\s+profissionais|boas\s+pr[aá]ticas|recomenda[cç][oõ]es\s+profissionais|conclus[aã]o|considera[cç][oõ]es\s+finais|perguntas\s+frequentes|faq)[^<]*<\/h2>|<(?:div|section)\b[^>]*class=["\'][^"\']*(?:sara-faq-section|geo-faq-section|geo-author-box|sara-related-articles|geo-related-articles)[^"\']*["\'][^>]*>/isu';
        if (!preg_match($marker_pattern, $content, $mm, PREG_OFFSET_CAPTURE)) return $content;
        $marker_pos = (int)$mm[0][1];
        if ($table_pos < $marker_pos) return $content;
        $content = substr($content, 0, $table_pos) . substr($content, $table_pos + strlen($table));
        if (preg_match($marker_pattern, $content, $mm2, PREG_OFFSET_CAPTURE)) {
            $marker_pos = (int)$mm2[0][1];
            return substr($content, 0, $marker_pos) . "\n" . trim($table) . "\n" . substr($content, $marker_pos);
        }
        return rtrim($content) . "\n" . trim($table);
    }

    /** Garante pelo menos uma tabela HTML útil e posicionada antes dos blocos finais. */
    private static function ensure_required_table(string $content, string $keyword, string $title, string $category): string {
        if (stripos($content, '<table') !== false) {
            return self::move_table_before_final_blocks($content);
        }
        $topic = self::clean_editorial_topic($keyword, $title);
        $table = '<table class="sara-data-table geo-required-table" style="width:100%;border-collapse:collapse;margin:24px 0;">'
            . '<thead><tr>'
            . '<th style="border:1px solid rgba(148,163,184,.45);padding:10px;text-align:left;">Recurso</th>'
            . '<th style="border:1px solid rgba(148,163,184,.45);padding:10px;text-align:left;">Como ajuda na prática</th>'
            . '<th style="border:1px solid rgba(148,163,184,.45);padding:10px;text-align:left;">Impacto esperado</th>'
            . '</tr></thead><tbody>';
        $rows = [
            ['SEO técnico', 'Organiza título, meta descrição, headings, links internos e estrutura editorial.', 'Melhora a clareza para buscadores e leitores.'],
            ['GEO e AEO', 'Prepara respostas diretas, FAQ e contexto semântico para mecanismos de IA.', 'Aumenta a chance de interpretação correta por buscadores e assistentes.'],
            ['Automação com IA', 'Ajuda a criar, revisar e publicar conteúdo com menos trabalho manual.', 'Ganha velocidade sem abandonar controle editorial.'],
            ['Imagens e mídia', 'Usa imagem destacada e imagens do corpo com IDs reais da Biblioteca de Mídia.', 'Evita imagens quebradas e melhora a experiência visual.'],
            ['Dados estruturados', 'Gera FAQ/schema e elementos interpretáveis por Google e sistemas de resposta.', 'Facilita rich results e entendimento do conteúdo.'],
        ];
        foreach ($rows as $r) {
            $table .= '<tr>';
            foreach ($r as $cell) {
                $table .= '<td style="border:1px solid rgba(148,163,184,.45);padding:10px;vertical-align:top;">' . esc_html($cell) . '</td>';
            }
            $table .= '</tr>';
        }
        $table .= '</tbody></table>';
        $heading = '<h2>Comparativo prático do ' . esc_html($topic) . '</h2>';
        $block = $heading . "\n" . $table;

        if (preg_match('/<h2[^>]*>[^<]*(?:dados|comparativo|benef[ií]cios|beneficios|recursos)[^<]*<\/h2>/iu', $content, $m, PREG_OFFSET_CAPTURE)) {
            $insert_at = (int)$m[0][1] + strlen($m[0][0]);
            $content = substr($content, 0, $insert_at) . "\n" . $table . "\n" . substr($content, $insert_at);
            return self::move_table_before_final_blocks($content);
        }
        $marker_pattern = '/<h2[^>]*>\s*(?:dicas\s+profissionais|boas\s+pr[aá]ticas|recomenda[cç][oõ]es\s+profissionais|conclus[aã]o|considera[cç][oõ]es\s+finais|perguntas\s+frequentes|faq)[^<]*<\/h2>|<(?:section|div)\b[^>]*class=["\'][^"\']*(?:sara-related-articles|geo-related-articles|sara-faq-section|geo-author-box)[^"\']*["\'][^>]*>/isu';
        if (preg_match($marker_pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            return substr($content, 0, (int)$m[0][1]) . "\n" . $block . "\n" . substr($content, (int)$m[0][1]);
        }
        return rtrim($content) . "\n\n" . $block;
    }

    /** Remove blocos duplicados de relacionados: mantém o primeiro bloco funcional. */
    private static function remove_duplicate_related_blocks(string $content): string {
        $content = preg_replace('/<(section|div)\b[^>]*class="[^"]*(?:sara-related-articles|geo-related-articles)[^"]*"[^>]*>.*?<\/\1>\s*/isu', '', $content) ?? $content;
        $content = preg_replace('/<\/div>\s*<\/a>/iu', '</div>', $content) ?? $content;
        $content = preg_replace('/<p>\s*<\/a>\s*<\/p>/iu', '', $content) ?? $content;
        return trim($content);
    }

    /** Última garantia: se há IDs no meta mas as figuras sumiram, reinsere as imagens no corpo. */
    private static function ensure_body_images_visible(int $post_id, string $content, string $keyword, string $title, string $category, int $target): string {
        if ($target <= 0) return $content;
        $target = max(1, min(\GeoMetodoSEO\Services\ImageGeneratorService::body_images_count(), $target > 0 ? $target : \GeoMetodoSEO\Services\ImageGeneratorService::body_images_count()));
        $current = substr_count($content, 'sara-body-image');
        if ($current >= $target) {
            if (class_exists('\\GeoMetodoSEO\\Services\\LibraryImageService')) {
                \GeoMetodoSEO\Services\LibraryImageService::sync_post_image_meta_from_content($post_id, $content);
            }
            return $content;
        }
        if (!class_exists('\\GeoMetodoSEO\\Services\\LibraryImageService')) return $content;
        $ids = get_post_meta($post_id, '_geo_body_image_ids', true);
        if (!is_array($ids) || empty($ids)) $ids = get_post_meta($post_id, '_sara_body_image_ids', true);
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (empty($ids)) {
            $cat_slug = \GeoMetodoSEO\Services\LibraryImageService::resolve_category_slug($post_id);
            for ($i = 0; $i < $target; $i++) {
                $id = \GeoMetodoSEO\Services\LibraryImageService::get_body_id($post_id, $title, $keyword, $cat_slug);
                if ($id && !in_array($id, $ids, true)) $ids[] = $id;
            }
        }
        if (empty($ids)) return $content;
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $content, $m, PREG_OFFSET_CAPTURE);
        if (empty($m[0])) return $content;
        $slots = [];
        foreach ($m[0] as $i => $full) {
            $h2_text = trim(wp_strip_all_tags($m[1][$i][0] ?? ''));
            if ($h2_text === '' || preg_match('/faq|perguntas\s+frequentes|conclus[aã]o|artigos\s+relacionados/iu', $h2_text)) continue;
            $after = substr($content, (int)$full[1] + strlen($full[0]), 450);
            if (preg_match('/<(?:figure|img)\b/i', $after)) continue;
            $slots[] = ['pos' => (int)$full[1], 'h2' => $full[0], 'text' => $h2_text];
        }
        if (empty($slots)) return $content;
        $ids = array_slice($ids, 0, min(count($ids), count($slots), $target));
        $slots = array_slice($slots, 0, count($ids));
        usort($slots, fn($a, $b) => $b['pos'] <=> $a['pos']);
        $ids = array_reverse($ids);
        foreach ($slots as $idx => $slot) {
            $id = absint($ids[$idx] ?? 0);
            if (!$id) continue;
            $alt = trim(($slot['text'] ?: $keyword) . ' - imagem do artigo');
            $fig = \GeoMetodoSEO\Services\LibraryImageService::build_attachment_figure_html($id, $alt, ['sara-internal-queued-image'], [], $alt);
            if ($fig === '') continue;
            $insert_at = $slot['pos'] + strlen($slot['h2']);
            $content = substr($content, 0, $insert_at) . $fig . substr($content, $insert_at);
            \GeoMetodoSEO\Services\LibraryImageService::remember_body_image($post_id, $id, \GeoMetodoSEO\Services\LibraryImageService::is_library_mode() ? 'library' : 'ai');
        }
        return $content;
    }

    private static function ensure_professional_tips_before_conclusion(string $content, string $keyword, string $title): string {
        if (preg_match('/<h2[^>]*>\s*(?:dicas\s+profissionais|boas\s+pr[aá]ticas|recomenda[cç][oõ]es\s+profissionais)/iu', $content)) {
            return $content;
        }

        $topic = self::clean_editorial_topic($keyword, $title);
        $is_plugin = (bool) preg_match('/plugin|wordpress|geo\s*m[eé]todo|seo|aeo|llm|ia/iu', $topic . ' ' . $keyword . ' ' . $title);
        $heading = $is_plugin
            ? 'Dicas profissionais para usar o ' . $topic . ' com mais resultado'
            : 'Dicas profissionais para aplicar ' . $topic . ' com mais segurança';
        $block = '<h2>' . esc_html($heading) . '</h2>'
            . '<ul class="sara-professional-tips">'
            . '<li>Defina a intenção de busca antes de ampliar o texto, para evitar volume sem utilidade.</li>'
            . '<li>Use exemplos práticos somente quando eles puderem ser explicados sem inventar dados, casos ou estatísticas.</li>'
            . '<li>Revise títulos, tabela, FAQ, imagens, links internos e conclusão antes de publicar.</li>'
            . '</ul>';

        if (preg_match('/<h2[^>]*>\s*(?:conclus[aã]o|considera[cç][oõ]es\s+finais)[^<]*<\/h2>/iu', $content, $m, PREG_OFFSET_CAPTURE)) {
            return substr($content, 0, (int)$m[0][1]) . "\n\n" . $block . "\n\n" . substr($content, (int)$m[0][1]);
        }
        return self::insert_before_faq_or_author($content, "\n\n" . $block . "\n");
    }

    private static function enforce_word_count_ceiling(string $content, int $target_words): string {
        if ($target_words <= 0) return $content;

        $max_words = (int)ceil($target_words * 1.10);
        $min_words = (int)floor($target_words * 0.85);
        $current = self::count_words($content);
        AutopilotLogger::log('writer', 'word_count_actual', 'info', "Word count final antes do corte: {$current}/{$target_words}", [
            'target_words' => $target_words,
            'actual_words' => $current,
            'maximum_words' => $max_words,
        ]);
        if ($current <= $max_words) {
            AutopilotLogger::log('writer', 'word_count_status', 'info', 'ok', [
                'target_words' => $target_words,
                'actual_words' => $current,
            ]);
            return $content;
        }

        $protected_pos = strlen($content);
        $markers = [
            '/<h2[^>]*>\s*(?:dicas\s+profissionais|boas\s+pr[aá]ticas|recomenda[cç][oõ]es\s+profissionais)/iu',
            '/<h2[^>]*>\s*(?:conclus[aã]o|considera[cç][oõ]es\s+finais)/iu',
            '/<div\s+class="[^"]*(?:sara-faq-section|geo-faq-section|geo-author-box|geo-author-box-reinforced)[^"]*"/iu',
            '/<section\s+class="[^"]*(?:sara-related-articles|geo-related-articles)[^"]*"/iu',
        ];
        foreach ($markers as $pattern) {
            if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                $protected_pos = min($protected_pos, (int)$m[0][1]);
            }
        }

        $editable = substr($content, 0, $protected_pos);
        $protected = substr($content, $protected_pos);
        if (!preg_match_all('/<p\b[^>]*>.*?<\/p>/isu', $editable, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $paragraphs = array_reverse($matches[0]);
        foreach ($paragraphs as $p) {
            if (self::count_words($editable . $protected) <= $max_words) break;
            $html = $p[0];
            $plain = trim(wp_strip_all_tags($html));
            if ($plain === '' || stripos($html, 'sara-quick-answer') !== false) continue;
            if (mb_strlen($plain) < 90) continue;
            $pos = (int)$p[1];
            $editable = substr($editable, 0, $pos) . substr($editable, $pos + strlen($html));
            if (self::count_words($editable . $protected) < $min_words) {
                $editable = substr($editable, 0, $pos) . $html . substr($editable, $pos);
                break;
            }
        }

        $final = trim($editable) . "\n\n" . ltrim($protected);
        $final_words = self::count_words($final);
        AutopilotLogger::log('writer', 'final_word_count', $final_words <= $max_words ? 'success' : 'warning',
            "Word count final: {$final_words}/{$target_words}", [
                'target_words' => $target_words,
                'actual_words' => $final_words,
                'maximum_words' => $max_words,
            ]);
        return $final;
    }

    public static function count_words(string $html): int {
        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text, $m);
        return count($m[0] ?? []);
    }

    /**
     * Remove imagens duplicadas consecutivas (figura seguida de figura sem texto entre elas).
     * @since 1.0.0 corrige bug visual de "imagens empilhadas".
     */
    private static function dedupe_consecutive_images(string $content): string {
        // Padrão: 2 figures seguidos com pouco texto entre eles → remove o segundo
        $pattern = '/(<figure\b[^>]*>.*?<\/figure>)\s*(<figure\b[^>]*>.*?<\/figure>)/isu';
        $iterations = 0;
        while ($iterations < 5 && preg_match($pattern, $content)) {
            $content = preg_replace($pattern, '$1', $content);
            $iterations++;
        }
        return $content;
    }
}
