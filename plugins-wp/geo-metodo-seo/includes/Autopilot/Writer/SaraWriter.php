<?php
namespace GeoMetodoSEO\Autopilot\Writer;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\{AutopilotInstaller, AutopilotLogger};
use GeoMetodoSEO\Autopilot\Brain\SaraIndexer;
use GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher;

/**
 * SaraWriter — Controller do Agente B.
 * Orquestra: Generator → Quality Gate → Image Handler → Schema Manager → Publisher.
 * Roda sob demanda (WP Cron nos horários configurados).
 */
class SaraWriter {

    private SaraGenerator   $generator;
    private SaraQualityGate $quality;
    private SaraImageHandler $images;
    private SaraSchemaManager $schema;
    private SaraIndexer     $indexer;
    private string          $table;

    public function __construct() {
        $this->generator = new SaraGenerator();
        $this->quality   = new SaraQualityGate();
        $this->images    = new SaraImageHandler();
        $this->schema    = new SaraSchemaManager();
        $this->indexer   = new SaraIndexer();
        global $wpdb;
        $this->table     = $wpdb->prefix . 'sara_editorial_calendar';
    }

    /**
     * Executar o Agente B para um job do calendário.
     */
    public function run(int $calendar_id): void {
        @set_time_limit(180); // Evita corte por max_execution_time em servidores lentos
        $t0 = microtime(true);

        if (!AutopilotInstaller::get('writer_enabled', '1')) {
            AutopilotLogger::log('writer', 'run_skip', 'skip', 'Writer desabilitado');
            return;
        }

        global $wpdb;
        $plan = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $calendar_id),
            ARRAY_A
        );

        if (!$plan) {
            AutopilotLogger::log('writer', 'run_error', 'error',
                "Job #{$calendar_id} não encontrado");
            return;
        }

        if ($plan['status'] !== 'pending') {
            AutopilotLogger::log('writer', 'run_skip', 'skip',
                "Job #{$calendar_id} status={$plan['status']} — ignorando");
            return;
        }

        // Marcar como em processamento
        $this->update_status($calendar_id, 'processing');
        AutopilotLogger::log('writer', 'run_start', 'start',
            "Iniciando: {$plan['title']}", ['calendar_id' => $calendar_id]);

        try {
            $this->execute($calendar_id, $plan, $t0);
        } catch (\Throwable $e) {
            if (class_exists('\\GeoMetodoSEO\\Autopilot\\Professional\\SaraRetryManager')) {
                \GeoMetodoSEO\Autopilot\Professional\SaraRetryManager::handle_failure($calendar_id, $e->getMessage(), 'writer');
            } else {
                $this->update_status($calendar_id, 'failed', $e->getMessage());
            }
            AutopilotLogger::log('writer', 'run_exception', 'error',
                'Exceção: ' . $e->getMessage(), ['calendar_id' => $calendar_id]);
        }
    }

    private function execute(int $cal_id, array $plan, float $t0): void {
        // GATE DE LICENÇA: plano vencido = SARA Autopilot parado por completo.
        // Nem inicia o job. Inclui artigos agendados que cairiam agora.
        if (!\GeoMetodoSEO\License\LicenseManager::canGenerate()) {
            AutopilotLogger::log('writer', 'license_blocked', 'error',
                'Job não executado: licença expirada/inativa. O SARA Autopilot só volta após renovar a licença.',
                ['calendar_id' => $cal_id]);
            return;
        }

        $max_retries  = (int) AutopilotInstaller::get('writer_max_retries', 2);
        $publish_mode = AutopilotInstaller::get('writer_publish_mode', 'draft');

        // 1.0.0: log do publish_mode efetivo (ajuda diagnóstico do "não publica")
        AutopilotLogger::log('writer', 'config_loaded', 'info',
            "publish_mode={$publish_mode} | max_retries={$max_retries}",
            ['calendar_id' => $cal_id]
        );

        $content = null;
        $quality = null;

        // ── 1. Gerar conteúdo (com retry no quality gate) ────────────────
        $prev_issues = []; // 1.0.0: feedback acumulado para próxima tentativa
        $fatal_generation_error = ''; // 1.0.0: erro do provider não deve fazer loop de tentativas
        $best_score   = 0;
        $best_content = null;
        $best_quality = null;

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            // Passar issues anteriores para o generator melhorar
            $content = $this->generator->generate($plan, $prev_issues);

            if (!$content) {
                $last_error = method_exists($this->generator, 'get_last_error') ? (string)$this->generator->get_last_error() : '';
                AutopilotLogger::log('writer', 'generate_fail', 'warning',
                    "Tentativa {$attempt}/{$max_retries}: geração falhou" . ($last_error ? ' — ' . mb_substr($last_error, 0, 180) : ''), ['calendar_id' => $cal_id]);

                if (class_exists('\GeoMetodoSEO\Autopilot\Professional\SaraRetryManager')
                    && $last_error
                    && \GeoMetodoSEO\Autopilot\Professional\SaraRetryManager::is_provider_blocking_error($last_error)) {
                    $fatal_generation_error = $last_error;
                    break;
                }
                continue;
            }

            // ── 2. Quality Gate ──────────────────────────────────────────
            $quality = $this->quality->evaluate($content, $plan);

            // Trackear melhor versão (caso todas falhem, usa a melhor)
            if ($quality['score'] > $best_score) {
                $best_score   = $quality['score'];
                $best_content = $content;
                $best_quality = $quality;
            }

            if ($quality['approved']) break;

            // 1.0.0: alimentar próxima tentativa com issues identificados
            $prev_issues = $quality['issues'] ?? [];

            AutopilotLogger::log('writer', 'quality_fail', 'warning',
                "Tentativa {$attempt}/{$max_retries}: score={$quality['score']}", [
                    'calendar_id' => $cal_id,
                    'context'     => $quality['issues'] ?? [],
                ]
            );
            $content = null; // forçar nova geração
        }

        // 1.0.0 BUG FIX: borderline NÃO força mais draft automaticamente.
        // Antes (v1.0.0-v1.0.0): score 65-79 = força force_draft=true → todo artigo borderline
        //   virava rascunho mesmo com publish_mode=publish, mesmo após Editorial Guard aprovar.
        // Causa raiz dos logs:
        //   #1188 quality_gate_local_only (sem IA, threshold 80 muito alto)
        //   #1190 quality_borderline 70/100 → force_draft = true ← BUG aqui
        //   #1196 editorial_guard score=90 aprovado (mas force_draft já decidiu)
        //   #1210 publish_mode_forced_draft (resultado final: rascunho)
        //
        // Agora: respeitamos a escolha do usuário. Se ele escolheu "publicar":
        //   - Score >= 65: aceita e tenta publicar (Editorial Guard final tem voz)
        //   - Score < 65: marca borderline mas ainda permite Editorial Guard final decidir
        //   - Só force_draft se publish_mode='draft' (escolha explícita do usuário)
        $borderline_threshold = (int) get_option('geo_writer_borderline_threshold', 65);
        if ((!$content || empty($quality['approved'])) && $best_content && $best_score >= $borderline_threshold) {
            $content      = $best_content;
            $quality      = $best_quality;
            // Só força draft se o usuário não pediu publicação direta.
            // Em publish_mode=publish, deixamos o Editorial Guard final (mais permissivo agora) decidir.
            $force_draft  = ($publish_mode !== 'publish');

            AutopilotLogger::log('writer', 'quality_borderline', $force_draft ? 'warning' : 'info',
                "Score borderline {$best_score}/100 — " .
                ($force_draft ? "salvando como rascunho (publish_mode={$publish_mode})" :
                 "publish_mode=publish: passando para Editorial Guard final decidir"),
                ['calendar_id' => $cal_id]
            );
        } else {
            $force_draft = false;
        }

        if (!$content || empty($quality)) {
            $reason = $fatal_generation_error ?: ($best_quality ? "Score insuficiente: {$best_score}/100" : "Geração falhou");
            if (class_exists('\\GeoMetodoSEO\\Autopilot\\Professional\\SaraRetryManager')) {
                \GeoMetodoSEO\Autopilot\Professional\SaraRetryManager::handle_failure($cal_id, $reason, 'writer');
            } else {
                $this->update_status($cal_id, 'failed', $reason);
            }
            AutopilotLogger::log('writer', 'run_fail', 'error', $reason, ['calendar_id' => $cal_id]);
            return;
        }

        // ── 3. Criar post no WordPress ───────────────────────────────────
        // Forçar draft se foi salvo como borderline
        $requested_status = $force_draft ? 'draft' : ($publish_mode === 'publish' ? 'publish' : 'draft');
        // Segurança editorial: o post nasce sempre como draft e só é publicado no guard final.
        $effective_status = 'draft';

        $publish_result = GeoMetodoSEO_Publisher::publish([
            'title' => wp_strip_all_tags($plan['title']),
            'content' => $content,
            'excerpt' => '',
            'status' => $effective_status,
            'post_type' => 'post',
            'author_id' => $this->resolve_post_author(),
            'category_ids' => $plan['category_id'] ? [(int)$plan['category_id']] : [],
            'focus_keyword' => (string)($plan['keyword'] ?? $plan['title']),
            'seo_title' => wp_strip_all_tags($plan['title']),
            'meta_description' => mb_substr(wp_strip_all_tags($content), 0, 160),
            'source_module' => 'sara_autopilot',
        ]);
        $post_id = !empty($publish_result['success']) ? (int)$publish_result['post_id'] : new \WP_Error('publisher_failed', $publish_result['error'] ?? 'Falha ao criar post via Publisher central.');

        if (is_wp_error($post_id)) {
            $reason = $post_id->get_error_message();
            if (class_exists('\\GeoMetodoSEO\\Autopilot\\Professional\\SaraRetryManager')) {
                \GeoMetodoSEO\Autopilot\Professional\SaraRetryManager::handle_failure($cal_id, $reason, 'writer');
            } else {
                $this->update_status($cal_id, 'failed', $reason);
            }
            return;
        }

        // ── 4. Salvar meta do plugin principal ───────────────────────────
        update_post_meta($post_id, '_geo_keyword',    $plan['keyword']);
        update_post_meta($post_id, '_geo_seo_score',  $quality['breakdown']['seo'] ?? 0);
        update_post_meta($post_id, '_geo_geo_score',  $quality['breakdown']['geo'] ?? 0);
        update_post_meta($post_id, '_geo_eeat_score', $quality['breakdown']['eeat'] ?? 0);

        // 1.0.0 — Contador real de palavras: salva meta auditável para painel/revisão.
        $initial_word_count = $this->count_words($content);
        $target_word_count  = $this->normalize_target_word_count($plan);
        update_post_meta($post_id, '_sara_word_count_real', $initial_word_count);
        update_post_meta($post_id, '_sara_word_count_target', $target_word_count);
        update_post_meta($post_id, '_sara_word_count_status', $initial_word_count >= (int)floor($target_word_count * 0.90) ? 'ok' : 'below_target');
        AutopilotLogger::log('writer', 'word_count_meta_saved', 'info',
            "Post #{$post_id}: {$initial_word_count}/{$target_word_count} palavras antes das imagens/FAQ",
            ['calendar_id' => $cal_id, 'post_id' => $post_id]
        );

        // ── 5. Imagens ───────────────────────────────────────────────────
        // 1.0.0: REGRA — 4 imagens no corpo (era 3). Modo agressivo = 5.
        // Mais 1 featured = 5 imagens totais por artigo. Distribuídas a cada 3-4 H2.
        $img_count = \GeoMetodoSEO\Services\ImageGeneratorService::body_images_count();
        // 1.0.0: gerar apenas a imagem destacada na requisição principal.
        // Imagens internas variadas entram em fila assíncrona para evitar 504/timeouts.
        $this->images->set_featured_image($post_id, $plan['keyword'], $plan['title']);
        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);

        // ── 6. Schema Manager ────────────────────────────────────────────
        $this->schema->apply($post_id, $plan, $content);

        // ── 6b. EEATEngine — caixa do autor + Person schema ────────────
        if (class_exists('GeoMetodoSEO\\EEAT\\EEATEngine')) {
            $eeat = new \GeoMetodoSEO\EEAT\EEATEngine();
            $eeat->apply($post_id);
        }

        // ── 6c. FAQ profundo (com Schema) — SEMPRE remove FAQ inline da IA antes ───────
        // 1.0.0 BUG FIX: detecção corrigida + remoção do FAQ duplicado da IA.
        // Antes: verificava só 'sara-faq-section', mas IA gerava 'sara-faq-item' inline
        //        → resultado: 2 FAQs (a inline crua + a oficial com schema).
        if (class_exists('GeoMetodoSEO\\Autopilot\\Writer\\SaraDeepFAQGenerator')) {

            // 1. Detectar e REMOVER qualquer FAQ inline que a IA tenha gerado por engano
            //    (sempre roda — limpeza não custa nada e evita FAQ duplicado)
            $had_inline_faq = false;
            $patterns_inline_faq = [
                '/<h[23][^>]*>\s*(?:perguntas\s+frequentes|faq|f\.a\.q\.?)[^<]*<\/h[23]>.*?(?=<h[23]|$)/isu',
                '/<div\s+class="[^"]*sara-faq-item[^"]*"[^>]*>.*?<\/div>\s*/isu',
                '/<div\s+class="[^"]*sara-faq-section[^"]*"[^>]*>.*?<\/div>\s*/isu',
            ];
            foreach ($patterns_inline_faq as $p) {
                $cleaned = preg_replace($p, '', $content);
                if ($cleaned !== null && $cleaned !== $content) {
                    $had_inline_faq = true;
                    $content = $cleaned;
                }
            }

            if ($had_inline_faq) {
                AutopilotLogger::log('writer', 'faq_inline_removed', 'info',
                    'FAQ inline da IA removido (será substituído pelo oficial com Schema)',
                    ['post_id' => $post_id]);
                // Atualiza o post mesmo se não gerar FAQ novo (limpeza importante)
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
            }

            // 1.0.0 BUG FIX: Removido bloco antigo que duplicava FAQ.
            // O FAQ oficial agora é adicionado APENAS pelo SaraGlobalPostProcessor::finalize()
            // chamado mais abaixo (linha ~343). Antes: ligar 'faq_generator_enabled' criava 2 FAQs.
            // Agora: GlobalPostProcessor é a fonte única de FAQ (com IA quando setting=1, com local quando setting=0).
        }

        // ── 6d. Linkagem interna automática ─────────────────────────────
        if (!empty($plan['category_id'])) {
            $related = get_posts([
                'category'     => (int)$plan['category_id'],
                'numberposts'  => 5,
                'post__not_in' => [$post_id],
                'post_status'  => 'publish',
            ]);
            $linked = 0;
            $current_content = $content;
            foreach ($related as $r) {
                if ($linked >= 3) break;
                $r_url = get_permalink($r->ID);
                $words = array_filter(explode(' ', $r->post_title), fn($w) => mb_strlen($w) >= 5);
                foreach ($words as $word) {
                    $word = trim($word, '.,;:!?"');
                    if (empty($word)) continue;
                    $pat = '/(?<![\w-])(' . preg_quote($word, '/') . ')(?![\w-])/iu';
                    if (preg_match($pat, $current_content) && stripos($current_content, $r_url) === false) {
                        $current_content = preg_replace(
                            $pat,
                            '<a href="' . esc_url($r_url) . '">$1</a>',
                            $current_content, 1
                        );
                        $linked++;
                        break;
                    }
                }
            }
            if ($linked > 0) {
                $content = $current_content;
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
            }
        }

        // ── 7. Rank Math / Yoast SEO ─────────────────────────────────────
        if (class_exists('GeoMetodoSEO\SEO\RankMathIntegration')) {
            $rm = new \GeoMetodoSEO\SEO\RankMathIntegration();
            $rm->apply($post_id, $plan['title'], $plan['keyword'],
                mb_substr(strip_tags($content), 0, 155));
        }

        // ── 7b. Editorial Guard final: se houver risco, rebaixa para rascunho ──
        $guard = null;
        if (class_exists('\GeoMetodoSEO\Autopilot\Professional\SaraEditorialGuard')) {
            $guard = \GeoMetodoSEO\Autopilot\Professional\SaraEditorialGuard::enforce($post_id, $plan, $quality);
            update_post_meta($post_id, '_sara_final_guard', wp_json_encode($guard, JSON_UNESCAPED_UNICODE));
        }

        // ── 7c. EEATEngine FINAL — caixa/ecossistema do autor abaixo de todo o artigo ──
        if (class_exists('GeoMetodoSEO\EEAT\EEATEngine')) {
            $eeat = new \GeoMetodoSEO\EEAT\EEATEngine();
            $eeat->apply($post_id);
            $post_after_eeat = get_post($post_id);
            if ($post_after_eeat) {
                $content = $post_after_eeat->post_content;
            }
        }

        // 1.0.0 — pós-processamento global: FAQ único, imagem no corpo sem duplicar mídia,
        // fila assíncrona de imagens internas e autor/ecossistema no rodapé real.
        if (class_exists('GeoMetodoSEO\Autopilot\Writer\SaraGlobalPostProcessor')) {
            $content = SaraGlobalPostProcessor::finalize($post_id, [
                'title' => $plan['title'],
                'keyword' => $plan['keyword'],
                'category' => $plan['category_name'] ?? '',
                'niche' => AutopilotInstaller::get('site_niche', 'Tecnologia'),
                // FIX v1.0.0-WORDCOUNT: respeitar word_count_target real, sem max(3000).
                'target_words' => $target_word_count,
                'enable_faq' => true,
                'internal_image_count' => (int)$img_count,
                'process_internal_images_now' => class_exists('GeoMetodoSEO\\Services\\LibraryImageService') ? \GeoMetodoSEO\Services\LibraryImageService::is_library_mode() : false,
            ]);
        }

        // ── 7d. v1.0.0 March 2026 Core Update — Box "📊 Dados em Destaque" ──
        // Injeta dados específicos (números, %, fontes) que o Google premia como
        // "informação genuinamente nova". Setting 'geo_original_data_box_enabled' (default '1').
        if (class_exists('\\GeoMetodoSEO\\Quality\\OriginalDataInjector')) {
            $content_with_box = \GeoMetodoSEO\Quality\OriginalDataInjector::inject($content, [
                'title'   => $plan['title'] ?? '',
                'keyword' => $plan['keyword'] ?? '',
                'niche'   => AutopilotInstaller::get('site_niche', 'geral'),
                'theme'   => $plan['category_name'] ?? '',
            ]);
            if ($content_with_box !== $content) {
                $content = $content_with_box;
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
            }
        }

        // ── 7e. v1.0.0 — Author Box reforçado com credenciais E-E-A-T ──
        // Adiciona box visível com bio + credenciais + LinkedIn do autor.
        // Lê meta criada pelo AuthorProfileFields. Setting 'geo_author_box_reinforced' (default '1').
        if (class_exists('\\GeoMetodoSEO\\EEAT\\EEATEngine')) {
            $eeat_box = new \GeoMetodoSEO\EEAT\EEATEngine();
            if (method_exists($eeat_box, 'inject_reinforced_author_box')) {
                $eeat_box->inject_reinforced_author_box($post_id);
                // Refresh content após injeção
                $post_after_box = get_post($post_id);
                if ($post_after_box) {
                    $content = $post_after_box->post_content;
                }
            }
        }

        // ── 7f. v1.0.0 — Schema Speakable para AI Overviews ──
        // Marca primeiro H2 + parágrafo como "speakable" e emite JSON-LD via wp_head.
        // Setting 'geo_speakable_schema_enabled' (default '1').
        if (class_exists('\\GeoMetodoSEO\\Schema\\SpeakableSchemaInjector')) {
            \GeoMetodoSEO\Schema\SpeakableSchemaInjector::apply($post_id);
        }

        // 1.0.0 — contador final após imagens, FAQ, links e caixa do autor.
        $final_word_count = $this->count_words($content);
        update_post_meta($post_id, '_sara_word_count_real_final', $final_word_count);
        // FIX v1.0.0-WORDCOUNT: usar target real sem max(3000).
        update_post_meta($post_id, '_sara_word_count_status_final', $final_word_count >= (int)floor($target_word_count * 0.90) ? 'ok' : 'below_target');

        // 1.0.0 — Publish Mode Guard Final:
        // O Editorial Guard antigo rodava antes do pós-processamento global. Quando o artigo ainda estava
        // curto naquele momento, ele rebaixava para rascunho e nunca restaurava a publicação, mesmo após FAQ,
        // relacionados, expansão local, imagens e E-E-A-T deixarem o post aprovado.
        // Agora, se o usuário escolheu "Publicar Diretamente", reavaliamos o post FINAL e publicamos somente
        // se ele passar no guard final. Se não passar, permanece rascunho com motivo claro no log.
        $final_guard = null;
        if ($publish_mode === 'publish' && !$force_draft) {
            if (class_exists('\GeoMetodoSEO\Autopilot\Professional\SaraEditorialGuard')) {
                $final_guard = \GeoMetodoSEO\Autopilot\Professional\SaraEditorialGuard::inspect_post($post_id, $plan, $quality);
                update_post_meta($post_id, '_sara_final_publish_guard', wp_json_encode($final_guard, JSON_UNESCAPED_UNICODE));

                if (!empty($final_guard['approved'])) {
                    \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                        'ID' => $post_id,
                        'post_status' => 'publish',
                    ]);
                    AutopilotLogger::log('writer', 'auto_publish_final', 'success',
                        "Post #{$post_id} publicado após validação final do pós-processamento", [
                            'calendar_id' => $cal_id,
                            'post_id' => $post_id,
                            'context' => $final_guard,
                        ]
                    );
                } else {
                    \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                        'ID' => $post_id,
                        'post_status' => 'draft',
                    ]);
                    $critical_msg = implode('; ', (array)($final_guard['critical'] ?? []));
                    $warning_msg  = implode('; ', (array)($final_guard['warnings'] ?? []));
                    AutopilotLogger::log('writer', 'final_publish_blocked', 'warning',
                        'Publicação automática bloqueada pelo guard final: ' . trim($critical_msg . ' ' . $warning_msg), [
                            'calendar_id' => $cal_id,
                            'post_id' => $post_id,
                            'context' => $final_guard,
                        ]
                    );
                }
            } else {
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                    'ID' => $post_id,
                    'post_status' => 'draft',
                ]);
                AutopilotLogger::log('writer', 'auto_publish_guard_missing', 'warning',
                    "Publicação automática bloqueada: SaraEditorialGuard não está disponível para validação final", [
                        'calendar_id' => $cal_id,
                        'post_id' => $post_id,
                    ]
                );
            }
        } elseif ($publish_mode === 'publish' && $force_draft) {
            AutopilotLogger::log('writer', 'publish_mode_forced_draft', 'warning',
                "Modo publicar estava ativo, mas o artigo ficou borderline no quality gate e foi mantido como rascunho", [
                    'calendar_id' => $cal_id,
                    'post_id' => $post_id,
                ]
            );
        }

        $final_post_status = get_post_status($post_id) ?: $requested_status;
        update_post_meta($post_id, '_sara_effective_publish_mode', $publish_mode);
        update_post_meta($post_id, '_sara_final_post_status', $final_post_status);

        // ── 8. Atualizar calendário ───────────────────────────────────────
        global $wpdb;
        $wpdb->update($this->table, [
            'status'       => 'done',
            'post_id'      => $post_id,
            'quality_score'=> $quality['score'],
            'executed_at'  => current_time('mysql'),
        ], ['id' => $cal_id]);

        // ── 9. Atualizar índice semântico ─────────────────────────────────
        $post = get_post($post_id);
        if ($post) {
            $this->indexer->index_post([
                'ID'           => $post_id,
                'post_title'   => $post->post_title,
                'post_date'    => $post->post_date,
                'post_modified'=> $post->post_modified,
                'post_excerpt' => $post->post_excerpt,
                'post_content' => $post->post_content,
            ]);
        }

        // ── 10. Ping sitemap ──────────────────────────────────────────────
        $this->ping_sitemap();

        AutopilotLogger::log('writer', 'run_done', 'success',
            "Post #{$post_id} finalizado como {$final_post_status}: {$plan['title']} (score={$quality['score']})", [
                'calendar_id' => $cal_id,
                'post_id'     => $post_id,
                'duration_ms' => AutopilotLogger::elapsed($t0),
            ]
        );
    }

    private function normalize_target_word_count(array $plan): int {
        $raw = isset($plan['word_count_target']) ? (int) $plan['word_count_target'] : 2300;
        return max(800, min(5000, $raw > 0 ? $raw : 2300));
    }

    private function resolve_post_author(): int {
        $configured = (int) get_option('geo_default_post_author', 0);
        if ($configured > 0 && get_user_by('id', $configured)) {
            return $configured;
        }
        $current = get_current_user_id();
        if ($current > 0 && get_user_by('id', $current)) {
            return $current;
        }
        $admins = get_users([
            'role'   => 'administrator',
            'number' => 1,
            'fields' => ['ID'],
        ]);
        if (!empty($admins[0]->ID)) {
            return (int) $admins[0]->ID;
        }
        return 1;
    }

    /** 1.0.0 — contador real com suporte a português/acentos. */
    private function count_words(string $html): int {
        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text, $m);
        return count($m[0] ?? []);
    }

    private function update_status(int $id, string $status, string $reason = ''): void {
        global $wpdb;
        $data = ['status' => $status];
        if ($status === 'processing') $data['locked_at'] = current_time('mysql');
        if (in_array($status, ['done', 'failed'])) $data['locked_at'] = null;
        if ($reason) { $data['failure_reason'] = $reason; $data['last_error'] = $reason; }
        if (in_array($status, ['done', 'failed'])) $data['executed_at'] = current_time('mysql');
        $wpdb->update($this->table, $data, ['id' => $id]);
    }

    private function ping_sitemap(): void {
        $sitemap = get_sitemap_url('index');
        if ($sitemap) {
            wp_remote_get('https://www.google.com/ping?sitemap=' . urlencode($sitemap), ['timeout' => 5, 'blocking' => false]);
        }
    }

    /** Status do Writer */
    public function status(): array {
        global $wpdb;
        $cal = $wpdb->prefix . 'sara_editorial_calendar';
        $today = current_time('Y-m-d');
        return [
            'enabled'       => (bool) AutopilotInstaller::get('writer_enabled', '1'),
            'publish_mode'  => AutopilotInstaller::get('writer_publish_mode', 'draft'),
            'pending_today' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$cal} WHERE scheduled_date = %s AND status IN ('pending','retrying')",
                $today
            )),
            'done_today'    => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$cal} WHERE scheduled_date = %s AND status = 'done'",
                $today
            )),
            'next_job'      => \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::next_writer(),
        ];
    }
}
