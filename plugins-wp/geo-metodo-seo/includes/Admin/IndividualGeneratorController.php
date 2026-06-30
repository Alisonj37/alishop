<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Services\ArticlePipeline;
use GeoMetodoSEO\Helpers\SecurityHelper;
use GeoMetodoSEO\License\LicenseManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\TitleBank\SeoGeoTitleBank;

class IndividualGeneratorController {

    /**
     * Estimated cost per article by model (USD).
     */
    public static $cost_estimates = [
        // OpenAI
        'gpt-4.1'      => 0.010,
        'gpt-4.1-mini' => 0.003,
        'gpt-4.1-nano' => 0.001,
        'gpt-4o'       => 0.010,
        'gpt-4o-mini'  => 0.003,
        'o3'           => 0.020,
        'o3-mini'      => 0.005,
        'o4-mini'      => 0.004,
        // Groq (lista oficial Maio/2026)
        'openai/gpt-oss-120b'                         => 0.002,
        'llama-3.3-70b-versatile'                     => 0.001,
        'openai/gpt-oss-20b'                          => 0.001,
        'llama-3.1-8b-instant'                        => 0.0005,
        'groq/compound-mini'                          => 0.001,
        'groq/compound'                               => 0.002,
        'qwen/qwen3-32b'                              => 0.001,
        'meta-llama/llama-4-scout-17b-16e-instruct'   => 0.001,
        // Gemini
        'gemini-2.5-pro-preview' => 0.005,
        'gemini-3.1-flash-lite'  => 0.001,
        'gemini-3.1-pro'         => 0.010,
        'gemini-3-flash'         => 0.002,
        'gemini-2.5-pro'         => 0.005,
        'gemini-2.5-flash'       => 0.002,
        'gemini-2.5-flash-lite'  => 0.001,
        'gemini-2.0-flash'       => 0.002,
        'gemini-2.0-flash-lite'  => 0.001,
        'gemini-1.5-pro'         => 0.005,
        'gemini-1.5-flash'       => 0.002,
        // Claude
        'claude-sonnet-4-5'          => 0.008,
        'claude-opus-4-5'            => 0.025,
        'claude-haiku-4-5'           => 0.003,
        'claude-3-7-sonnet-20250219' => 0.008,
        'claude-3-5-haiku-20241022'  => 0.003,
        // Perplexity
        'sonar-pro'           => 0.005,
        'sonar'               => 0.002,
        'sonar-reasoning'     => 0.005,
        'sonar-reasoning-pro' => 0.008,
        'r1-1776'             => 0.005,
    ];

    private $providers = [
        'openai'     => 'OpenAI',
        'groq'       => 'Groq',
        'gemini'     => 'Gemini',
        'claude'     => 'Claude',
        'perplexity' => 'Perplexity',
        'naga'        => 'Naga.ac (Gemini grátis)',
    ];

    private $languages = [
        'pt-BR' => '🇧🇷 Portugues do Brasil',
        'en'    => '🇺🇸 English',
        'es'    => '🇪🇸 Espanol',
        'fr'    => '🇫🇷 Francais',
    ];

    private $statuses = [
        'draft'   => '📝 Rascunho',
        'publish' => '🟢 Publicado',
        'pending' => '🔔 Pendente de Revisao',
        'future'  => '📅 Agendado',
    ];

    public function render_page() {

        // ── Verificação de trial ──────────────────────────────────────────
        if (LicenseManager::isTrialExpired()) {
            echo '<div class="wrap">';
            echo '<h1>Gerar Artigo Individual</h1>';
            echo '<div style="max-width:620px;margin:32px 0;padding:28px;background:#fff8f8;border:2px solid #dc3232;border-radius:8px;">';
            echo '<h2 style="color:#dc3232;margin-top:0;">⛔ Trial Esgotado</h2>';
            echo '<p>Você usou todos os <strong>' . LicenseManager::TRIAL_LIMIT . ' artigos</strong> do trial gratuito.</p>';
            echo '<p>Para continuar gerando artigos ilimitados, adquira uma licença em:</p>';
            echo '<a href="https://aiconteudo.com.br/plugin" target="_blank" class="button button-primary button-large">Adquirir Licença →</a>';
            echo '&nbsp;<a href="' . esc_url(admin_url('admin.php?page=geo-license')) . '" class="button">Gerenciar Licença</a>';
            echo '</div></div>';
            return;
        }

        $post_id      = null;
        $error        = null;
        $keyword      = '';
        $provider     = ProviderResolver::for('individual_generation');
        $language     = get_option('geo_default_language', 'pt-BR');
        $post_status  = 'draft';
        $scheduled_at = '';
        $model        = '';
        $template_id  = 0;

        // Forçar provedor permitido no trial
        $allowed_providers = LicenseManager::getAllowedProviders();
        if (!LicenseManager::isProviderAllowed($provider)) {
            foreach (['groq','naga','gemini','claude','perplexity','openai'] as $candidate) {
                if (LicenseManager::isProviderAllowed($candidate) && ProviderResolver::isConfigured($candidate)) { $provider = $candidate; break; }
            }
        }

        // Pre-fill keyword from title generator link
        if (isset($_GET['geo_prefill'])) {
            $keyword = sanitize_text_field($_GET['geo_prefill']);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            SecurityHelper::verify_nonce($_POST['_wpnonce'] ?? '', 'geo_individual_generate');
            SecurityHelper::current_user_can_manage();

            $keyword      = sanitize_text_field($_POST['geo_keyword']      ?? '');
            $provider     = sanitize_text_field($_POST['geo_provider']     ?? $provider);
            $language     = sanitize_text_field($_POST['geo_language']     ?? $language);
            $post_status  = sanitize_text_field($_POST['geo_post_status']  ?? 'draft');
            $scheduled_at = sanitize_text_field($_POST['geo_scheduled_at'] ?? '');
            $model        = sanitize_text_field($_POST['geo_model']        ?? '');
            $template_id  = intval($_POST['geo_template_id']               ?? 0);
            $tone         = sanitize_text_field($_POST['geo_tone']         ?? get_option('geo_default_tone', ''));
            $category     = sanitize_text_field($_POST['geo_category']     ?? 'auto');
            $size         = in_array($_POST['geo_article_size'] ?? '', ['small','medium','large']) ? $_POST['geo_article_size'] : get_option('geo_article_size', 'large');

            // Garantir provedor permitido no trial
            if (!LicenseManager::isProviderAllowed($provider)) {
                $provider = 'openai';
            }

            if (!array_key_exists($language, $this->languages))  $language    = 'pt-BR';
            if (!array_key_exists($post_status, $this->statuses)) $post_status = 'draft';

            // Validate model belongs to provider
            $all_models = SettingsController::get_provider_models();
            $valid_models = array_keys($all_models[$provider] ?? []);
            if (!in_array($model, $valid_models, true)) {
                $model = '';
            }

            if (!LicenseManager::canGenerate()) {
                $error = 'Trial esgotado. Ative uma licenca para continuar gerando artigos.';
            } elseif (empty($keyword)) {
                $error = 'Informe uma keyword para gerar o artigo.';
            } else {
                $pipeline = new ArticlePipeline($provider, $model ?: null, $template_id ?: null);
                $pipeline->set_embed_youtube_video(($_POST['geo_embed_video'] ?? '0') === '1');
                $img_src = sanitize_key($_POST['geo_image_source_gen'] ?? '');
                if (in_array($img_src, ['ai','library'], true) && class_exists('GeoMetodoSEO\\Services\\LibraryImageService')) {
                    \GeoMetodoSEO\Services\LibraryImageService::set_mode_override($img_src);
                }
                $result   = $pipeline->process($keyword, $language, $post_status, $scheduled_at, $tone, $category, $size);

                if ($result && !is_wp_error($result)) {
                    $post_id = $result;
                    LicenseManager::incrementTrialCount();
                    if (class_exists(SeoGeoTitleBank::class)) {
                        SeoGeoTitleBank::mark_used_by_title($keyword, 'individual', (int)$post_id);
                    }
                } else {
                    $error = 'Falha ao gerar artigo. Verifique a API Key do provedor "'
                           . esc_html($this->providers[$provider] ?? $provider)
                           . '" em Configuracoes.';
                }
            }
        }

        $tone         = $tone     ?? get_option('geo_default_tone', '');
        $category     = $category ?? 'auto';
        $improve_nonce     = wp_create_nonce('geo_improve_article_nonce');
        $analysis_nonce    = wp_create_nonce('geo_analysis_nonce');
        $preview_nonce     = wp_create_nonce('geo_preview_article');
        $all_models_json   = wp_json_encode(SettingsController::get_provider_models());
        $cost_json         = wp_json_encode(self::$cost_estimates);
        $templates         = TemplateController::get_templates_list();
        $is_trial          = LicenseManager::getPlan() === 'trial';
        $trial_count       = LicenseManager::getTrialCount();
        $allowed_providers = LicenseManager::getAllowedProviders();

        // Default saved models per provider
        $saved_models = [];
        foreach (array_keys(SettingsController::get_provider_models()) as $prov) {
            $saved_models[$prov] = get_option('geo_model_' . $prov, '');
        }
        $saved_models_json = wp_json_encode($saved_models);

        ?>
        <div class="wrap">
            <h1>Gerar Artigo Individual</h1>

            <?php if ($is_trial): ?>
            <div style="background:<?php echo $trial_count >= LicenseManager::TRIAL_LIMIT - 1 ? '#fff8f8' : '#f0f8ff'; ?>;border:1px solid <?php echo $trial_count >= LicenseManager::TRIAL_LIMIT - 1 ? '#dc3232' : '#0073aa'; ?>;border-radius:6px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <span style="font-size:13px;">
                    🆓 <strong>Trial:</strong> <?php echo intval($trial_count); ?>/<?php echo LicenseManager::TRIAL_LIMIT; ?> artigos usados
                    &nbsp;|&nbsp; Apenas OpenAI e Groq disponíveis
                </span>
                <a href="<?php echo esc_url(admin_url('admin.php?page=geo-license')); ?>" class="button button-small">🔑 Ativar Licença</a>
            </div>
            <?php endif; ?>

            <p class="description" style="font-size:14px;">
                Gera um artigo completo com SEO, E-E-A-T, tabela comparativa, imagens automáticas (IA ou biblioteca conforme configurado) e linkagem automatica.
            </p>

            <?php if ($post_id): ?>
                <div class="notice notice-success" id="geo-success-notice">
                    <p>
                        ✅ <strong>Artigo gerado com sucesso!</strong>
                        &nbsp;|&nbsp; ID: <strong><?php echo intval($post_id); ?></strong>
                        &nbsp;|&nbsp; <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" target="_blank">✏️ Editar</a>
                        &nbsp;|&nbsp; <a href="<?php echo esc_url(get_permalink($post_id)); ?>" target="_blank">👁️ Visualizar</a>
                        &nbsp;|&nbsp;
                        <button type="button" id="geo-improve-btn" class="button button-secondary"
                                data-post-id="<?php echo intval($post_id); ?>"
                                data-nonce="<?php echo esc_attr($improve_nonce); ?>">
                            🔄 Melhorar este artigo
                        </button>
                        <span id="geo-improve-status" style="margin-left:8px;font-style:italic;color:#46b450;display:none;"></span>
                    </p>
                </div>
                <script>
                document.getElementById('geo-improve-btn').addEventListener('click', function() {
                    var btn = this;
                    var status = document.getElementById('geo-improve-status');
                    btn.disabled = true;
                    btn.textContent = '⏳ Melhorando...';
                    status.style.display = 'none';
                    var data = new FormData();
                    data.append('action', 'geo_improve_article');
                    data.append('nonce', btn.dataset.nonce);
                    data.append('post_id', btn.dataset.postId);
                    fetch(ajaxurl, {method:'POST', body:data})
                        .then(r => r.json())
                        .then(function(res) {
                            btn.disabled = false;
                            if (res.success) {
                                btn.textContent = '✅ Melhorado!';
                                status.textContent = res.data.message;
                                status.style.display = 'inline';
                            } else {
                                btn.textContent = '🔄 Melhorar este artigo';
                                status.textContent = '❌ ' + (res.data.message || 'Erro');
                                status.style.color = '#dc3232';
                                status.style.display = 'inline';
                            }
                        });
                });
                </script>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice notice-error">
                    <p>❌ <?php echo esc_html($error); ?></p>
                </div>
            <?php endif; ?>

            <div style="max-width:700px; margin-top:20px;">
                <form method="post" id="geo-individual-form">
                    <?php wp_nonce_field('geo_individual_generate'); ?>

                    <?php echo SeoGeoTitleBank::render_picker('#geo_keyword', 'single'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="geo_keyword">Keyword</label></th>
                            <td>
                                <input type="text" id="geo_keyword" name="geo_keyword"
                                       value="<?php echo esc_attr($keyword); ?>"
                                       class="large-text"
                                       placeholder="Ex: como fazer SEO em 2025" required>
                                <p class="description">Keyword principal do artigo — injetada no prompt e nos metadados SEO.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="geo_provider">Provedor de IA</label></th>
                            <td>
                                <select id="geo_provider" name="geo_provider" class="regular-text"
                                        onchange="geoUpdateModelSelect(this.value)">
                                    <?php foreach ($this->providers as $val => $label): ?>
                                        <?php if (!in_array($val, $allowed_providers, true)) continue; ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($provider, $val); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($is_trial): ?>
                                    <p class="description">⚠️ Trial: apenas OpenAI e Groq disponíveis. <a href="<?php echo esc_url(admin_url('admin.php?page=geo-license')); ?>">Ativar licença</a> para desbloquear todos.</p>
                                <?php else: ?>
                                <p class="description">Em caso de falha, o sistema tenta os demais provedores automaticamente.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="geo_model">Modelo</label></th>
                            <td>
                                <select id="geo_model" name="geo_model" class="regular-text">
                                    <!-- Populated by JS -->
                                </select>
                                <p class="description">Modelo especifico do provedor selecionado.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="geo_language">Idioma do Artigo</label></th>
                            <td>
                                <select id="geo_language" name="geo_language" class="regular-text">
                                    <?php foreach ($this->languages as $val => $label): ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($language, $val); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="geo_article_size">Tamanho do Artigo</label></th>
                            <td>
                                <select id="geo_article_size" name="geo_article_size" class="regular-text">
                                    <option value="small"  <?php selected(get_option('geo_article_size','large'),'small'); ?>>📄 Pequeno — completo até 1.500 palavras</option>
                                    <option value="medium" <?php selected(get_option('geo_article_size','large'),'medium'); ?>>📋 Médio — completo até 2.200 palavras</option>
                                    <option value="large"  <?php selected(get_option('geo_article_size','large'),'large'); ?>>📚 Grande — completo até 3.500 palavras</option>
                                </select>
                                <p class="description">Define a profundidade e volume de cada seção do artigo.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="geo_embed_video">Vídeo do YouTube</label></th>
                            <td>
                                <label style="display:flex;align-items:center;gap:8px;">
                                    <input type="checkbox" id="geo_embed_video" name="geo_embed_video" value="1">
                                    <span>Embedar um vídeo do YouTube relacionado ao tema dentro do artigo</span>
                                </label>
                                <p class="description">O vídeo é escolhido automaticamente pela palavra-chave de foco. Só vídeos relevantes ao tema são inseridos. Requer a YouTube Data API Key configurada.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="geo_image_source_gen">Fonte das Imagens</label></th>
                            <td>
                                <select id="geo_image_source_gen" name="geo_image_source_gen" class="regular-text">
                                    <option value="" <?php selected(get_option('geo_image_source','ai'), ''); ?>>⚙️ Usar configuração global (<?php echo get_option('geo_image_source','ai') === 'library' ? 'Biblioteca' : 'IA'; ?>)</option>
                                    <option value="ai">🤖 Gerar com IA (Replicate/Fal.ai)</option>
                                    <option value="library">🖼️ Usar minha Biblioteca (IDs configuradas)</option>
                                </select>
                                <p class="description">Escolha aqui sem precisar ir às Configurações. "Biblioteca" usa os intervalos de IDs que você configurou por categoria.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="geo_tone">Tom de Voz</label></th>
                            <td>
                                <select id="geo_tone" name="geo_tone" class="regular-text">
                                    <?php
                                    $tones = [
                                        ''             => '— Padrão das configurações —',
                                        'profissional' => 'Profissional',
                                        'persuasivo'   => 'Persuasivo',
                                        'informal'     => 'Informal',
                                        'tecnico'      => 'Técnico',
                                        'storytelling' => 'Storytelling',
                                        'educativo'    => 'Educativo',
                                    ];
                                    foreach ($tones as $val => $lbl):
                                    ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($tone, $val); ?>>
                                            <?php echo esc_html($lbl); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Tom de escrita aplicado em todo o artigo.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="geo_category">Categoria</label></th>
                            <td>
                                <select id="geo_category" name="geo_category" class="regular-text">
                                    <option value="auto" <?php selected($category, 'auto'); ?>>🤖 Automático (detectar pela keyword)</option>
                                    <?php
                                    $cats = get_categories(['hide_empty' => false, 'orderby' => 'name']);
                                    foreach ($cats as $cat):
                                    ?>
                                        <option value="<?php echo esc_attr($cat->name); ?>" <?php selected($category, $cat->name); ?>>
                                            <?php echo esc_html($cat->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="_new_">✚ Criar nova categoria pela keyword</option>
                                </select>
                                <p class="description">Categoria do WordPress para o artigo gerado.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="geo_post_status">Status do Post</label></th>
                            <td>
                                <select id="geo_post_status" name="geo_post_status" class="regular-text"
                                        onchange="geoToggleScheduled(this.value)">
                                    <?php foreach ($this->statuses as $val => $label): ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($post_status, $val); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr id="geo-scheduled-row" style="<?php echo $post_status === 'future' ? '' : 'display:none;'; ?>">
                            <th scope="row"><label for="geo_scheduled_at">Data e Hora de Publicacao</label></th>
                            <td>
                                <input type="datetime-local" id="geo_scheduled_at" name="geo_scheduled_at"
                                       value="<?php echo esc_attr($scheduled_at); ?>"
                                       class="regular-text">
                                <p class="description">Data e hora no fuso horario do servidor WordPress.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="geo_template_id">Template de Artigo</label></th>
                            <td>
                                <select id="geo_template_id" name="geo_template_id" class="regular-text">
                                    <option value="0">— Padrao (template interno) —</option>
                                    <?php foreach ($templates as $tpl): ?>
                                        <option value="<?php echo intval($tpl->id); ?>"
                                            <?php selected($template_id, intval($tpl->id)); ?>>
                                            <?php echo esc_html($tpl->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    Escolha um template personalizado ou <a href="<?php echo esc_url(admin_url('admin.php?page=geo-templates')); ?>">crie um novo</a>.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Estimativa de Custo</th>
                            <td>
                                <span id="geo-cost-estimate" style="font-size:14px; font-weight:600; color:#0073aa;">
                                    Calculando...
                                </span>
                                <p class="description">Custo aproximado por artigo gerado com o modelo selecionado.</p>
                            </td>
                        </tr>
                    </table>

                    <p style="margin-top:16px;">
                        <button type="submit" id="geo-submit-btn" class="button button-primary button-large">
                            ⚡ Gerar Artigo
                        </button>
                        <button type="button" id="geo-preview-btn" class="button button-large" style="margin-left:8px;">
                            👁️ Pre-visualizar
                        </button>
                        <span id="geo-loading" style="display:none; margin-left:14px; vertical-align:middle;">
                            <span class="spinner is-active" style="float:none; vertical-align:middle;"></span>
                            <em>Gerando artigo com IA — pode levar 60-120s...</em>
                        </span>
                        <span id="geo-preview-loading" style="display:none; margin-left:14px; vertical-align:middle;">
                            <span class="spinner is-active" style="float:none; vertical-align:middle;"></span>
                            <em>Gerando pre-visualizacao...</em>
                        </span>
                    </p>
                </form>

                <!-- Preview Modal -->
                <div id="geo-preview-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.6); z-index:99999; overflow-y:auto;">
                    <div style="background:#fff; max-width:820px; margin:40px auto; border-radius:8px; padding:32px; position:relative;">
                        <button id="geo-preview-close" type="button"
                                style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:24px; cursor:pointer; color:#666; line-height:1;">✕</button>
                        <h2 style="margin-top:0;">👁️ Pre-visualizacao do Artigo</h2>
                        <p style="color:#666; font-size:13px;">O artigo foi gerado pela IA mas ainda nao foi salvo. Confirme para publicar ou descarte sem gastar mais creditos.</p>
                        <div id="geo-preview-content" style="border:1px solid #ddd; border-radius:4px; padding:20px; margin-top:16px; max-height:55vh; overflow-y:auto;"></div>
                        <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">
                            <button id="geo-preview-confirm" type="button" class="button button-primary button-large">✅ Confirmar e Salvar</button>
                            <button id="geo-preview-discard" type="button" class="button button-large" style="color:#dc3232;">🗑️ Descartar</button>
                        </div>
                        <form id="geo-preview-confirm-form" method="post" style="display:none;">
                            <?php wp_nonce_field('geo_individual_generate'); ?>
                            <input type="hidden" name="geo_keyword"     id="geo-preview-kw">
                            <input type="hidden" name="geo_provider"    id="geo-preview-prov">
                            <input type="hidden" name="geo_language"    id="geo-preview-lang">
                            <input type="hidden" name="geo_post_status" id="geo-preview-status">
                            <input type="hidden" name="geo_model"       id="geo-preview-model">
                            <input type="hidden" name="geo_template_id" id="geo-preview-tpl">
                        </form>
                    </div>
                </div>
            </div>

            <!-- ================================================================
                 FERRAMENTAS DE ANÁLISE
                 ================================================================ -->
            <div style="max-width:900px; margin-top:40px;">
                <hr style="margin-bottom:28px; border:none; border-top:1px solid #ddd;">
                <h2 style="font-size:16px; margin-bottom:6px;">🔬 Ferramentas de Análise de Keyword</h2>
                <p class="description" style="margin-bottom:16px;">
                    Preencha a keyword acima e use as ferramentas abaixo para analisar <strong>antes</strong> de gerar o artigo.
                </p>

                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(190px,1fr)); gap:8px; margin-bottom:20px;">
                    <?php
                    $tools = [
                        ['action' => 'geo_check_cannibalization', 'icon' => '⚠️', 'label' => 'Verificar Canibalizacao'],
                        ['action' => 'geo_analyze_intent',        'icon' => '🎯', 'label' => 'Analisar Intencao'],
                        ['action' => 'geo_suggest_headings',      'icon' => '📑', 'label' => 'Sugerir Headings'],
                        ['action' => 'geo_context_engine',        'icon' => '🧠', 'label' => 'AI Context Engine'],
                        ['action' => 'geo_content_map',           'icon' => '🗺️', 'label' => 'Mapa de Conteudo'],
                        ['action' => 'geo_people_also_ask',       'icon' => '❓', 'label' => 'People Also Ask'],
                        ['action' => 'geo_content_gaps',          'icon' => '📊', 'label' => 'Content Gaps'],
                        ['action' => 'geo_extract_entities',      'icon' => '🏷️', 'label' => 'Entidades'],
                        ['action' => 'geo_serp_simulation',       'icon' => '🔍', 'label' => 'SERP Sim'],
                    ];
                    foreach ($tools as $tool): ?>
                        <button type="button"
                                class="button geo-analysis-btn"
                                data-action="<?php echo esc_attr($tool['action']); ?>"
                                style="font-size:13px; text-align:left; padding:8px 12px; height:auto;">
                            <?php echo esc_html($tool['icon'] . ' ' . $tool['label']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Área de resultado -->
                <div id="geo-analysis-result" style="display:none; padding:20px; background:#fff; border:1px solid #ddd; border-radius:6px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <strong id="geo-analysis-title" style="font-size:14px;"></strong>
                        <button type="button" id="geo-analysis-close"
                                style="background:none; border:none; font-size:18px; cursor:pointer; color:#666; line-height:1;">✕</button>
                    </div>
                    <div id="geo-analysis-loading" style="display:none; padding:12px 0; color:#666;">
                        <span class="spinner is-active" style="float:none; vertical-align:middle;"></span>
                        <em style="margin-left:8px;">Analisando com IA...</em>
                    </div>
                    <div id="geo-analysis-content"></div>
                </div>
            </div>
        </div>

        <script>
        // ── Model selector ────────────────────────────────────────────────────
        var GEO_MODELS       = <?php echo $all_models_json; ?>;
        var GEO_SAVED_MODELS = <?php echo $saved_models_json; ?>;
        var GEO_POST_MODEL   = <?php echo wp_json_encode($model); ?>;
        var GEO_COSTS        = <?php echo $cost_json; ?>;
        var GEO_PREVIEW_NONCE = '<?php echo esc_js($preview_nonce); ?>';
        var GEO_AJAX_URL      = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

        function geoUpdateModelSelect(provider) {
            var sel    = document.getElementById('geo_model');
            var models = GEO_MODELS[provider] || {};
            var saved  = GEO_SAVED_MODELS[provider] || '';
            sel.innerHTML = '';
            Object.keys(models).forEach(function(val) {
                var opt = document.createElement('option');
                opt.value       = val;
                opt.textContent = models[val];
                if (val === saved || (saved === '' && sel.options.length === 0)) {
                    opt.selected = true;
                }
                sel.appendChild(opt);
            });
            if (GEO_POST_MODEL && GEO_POST_MODEL !== '' && models[GEO_POST_MODEL]) {
                sel.value = GEO_POST_MODEL;
            }
            geoUpdateCostEstimate();
        }

        function geoUpdateCostEstimate() {
            var model = document.getElementById('geo_model').value;
            var cost  = GEO_COSTS[model];
            var el    = document.getElementById('geo-cost-estimate');
            if (!el) return;
            if (cost !== undefined) {
                el.textContent = '~$' + cost.toFixed(3) + ' USD por artigo';
            } else {
                el.textContent = 'Estimativa nao disponivel';
            }
        }

        // Initialize on page load
        geoUpdateModelSelect(document.getElementById('geo_provider').value);
        document.getElementById('geo_model').addEventListener('change', geoUpdateCostEstimate);

        function geoToggleScheduled(val) {
            var row = document.getElementById('geo-scheduled-row');
            if (row) row.style.display = (val === 'future') ? '' : 'none';
        }

        document.getElementById('geo-individual-form').addEventListener('submit', function() {
            document.getElementById('geo-submit-btn').disabled = true;
            document.getElementById('geo-loading').style.display = 'inline-block';
        });

        // ── Preview ──────────────────────────────────────────────────────────
        document.getElementById('geo-preview-btn').addEventListener('click', function() {
            var keyword  = document.getElementById('geo_keyword').value.trim();
            var provider = document.getElementById('geo_provider').value;
            var model    = document.getElementById('geo_model').value;
            var language = document.getElementById('geo_language').value;
            var tpl      = document.getElementById('geo_template_id') ? document.getElementById('geo_template_id').value : '0';

            if (!keyword) { alert('Preencha a keyword antes de pre-visualizar.'); return; }

            document.getElementById('geo-preview-btn').disabled = true;
            document.getElementById('geo-preview-loading').style.display = 'inline-block';

            var data = 'action=geo_preview_article'
                + '&nonce='    + encodeURIComponent(GEO_PREVIEW_NONCE)
                + '&keyword='  + encodeURIComponent(keyword)
                + '&provider=' + encodeURIComponent(provider)
                + '&model='    + encodeURIComponent(model)
                + '&language=' + encodeURIComponent(language)
                + '&template_id=' + encodeURIComponent(tpl);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', GEO_AJAX_URL, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.timeout = 120000;

            xhr.onload = function() {
                document.getElementById('geo-preview-btn').disabled = false;
                document.getElementById('geo-preview-loading').style.display = 'none';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data) {
                        var d = res.data;
                        var html = '<h2 style="margin-top:0;">' + escHtml(d.title || '') + '</h2>';
                        if (d.excerpt) html += '<p style="color:#555;font-style:italic;">' + escHtml(d.excerpt) + '</p>';
                        if (d.sections && d.sections.length) {
                            html += '<hr><h3>Estrutura do Artigo (H2s):</h3><ul>';
                            d.sections.forEach(function(s) { html += '<li>' + escHtml(s) + '</li>'; });
                            html += '</ul>';
                        }
                        document.getElementById('geo-preview-content').innerHTML = html;
                        document.getElementById('geo-preview-modal').style.display = 'block';
                        // Pre-fill confirm form
                        document.getElementById('geo-preview-kw').value     = keyword;
                        document.getElementById('geo-preview-prov').value   = provider;
                        document.getElementById('geo-preview-lang').value   = language;
                        document.getElementById('geo-preview-status').value = document.getElementById('geo_post_status').value;
                        document.getElementById('geo-preview-model').value  = model;
                        document.getElementById('geo-preview-tpl').value    = tpl;
                    } else {
                        alert('Erro ao gerar pre-visualizacao: ' + ((res.data && res.data.message) || 'Erro desconhecido'));
                    }
                } catch(e) {
                    alert('Erro ao processar resposta da IA.');
                }
            };
            xhr.onerror = xhr.ontimeout = function() {
                document.getElementById('geo-preview-btn').disabled = false;
                document.getElementById('geo-preview-loading').style.display = 'none';
                alert('Timeout ou erro de rede.');
            };
            xhr.send(data);
        });

        document.getElementById('geo-preview-close').addEventListener('click', function() {
            document.getElementById('geo-preview-modal').style.display = 'none';
        });
        document.getElementById('geo-preview-discard').addEventListener('click', function() {
            document.getElementById('geo-preview-modal').style.display = 'none';
        });
        document.getElementById('geo-preview-confirm').addEventListener('click', function() {
            document.getElementById('geo-preview-confirm-form').submit();
        });

        function escHtml(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        </script>

        <script>
        (function() {
            var AJAX_URL = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var NONCE    = '<?php echo esc_js($analysis_nonce); ?>';

            var toolLabels = {
                geo_check_cannibalization : '⚠️ Verificando Canibalizacao...',
                geo_analyze_intent        : '🎯 Analisando Intencao...',
                geo_suggest_headings      : '📑 Sugerindo Headings...',
                geo_context_engine        : '🧠 Analisando Contexto Semantico...',
                geo_content_map           : '🗺️ Gerando Mapa de Conteudo...',
                geo_people_also_ask       : '❓ Buscando Perguntas Relacionadas...',
                geo_content_gaps          : '📊 Analisando Content Gaps...',
                geo_extract_entities      : '🏷️ Extraindo Entidades...',
                geo_serp_simulation       : '🔍 Simulando SERP...',
            };

            var resultBox = document.getElementById('geo-analysis-result');
            var titleEl   = document.getElementById('geo-analysis-title');
            var loadingEl = document.getElementById('geo-analysis-loading');
            var contentEl = document.getElementById('geo-analysis-content');

            document.getElementById('geo-analysis-close').addEventListener('click', function() {
                resultBox.style.display = 'none';
            });

            document.querySelectorAll('.geo-analysis-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var keyword = document.getElementById('geo_keyword')
                                    ? document.getElementById('geo_keyword').value.trim() : '';
                    if (!keyword) {
                        alert('Preencha a keyword no campo acima antes de usar as ferramentas.');
                        return;
                    }

                    var action   = this.getAttribute('data-action');
                    var provider = document.getElementById('geo_provider')
                                    ? document.getElementById('geo_provider').value : 'openai';

                    resultBox.style.display  = 'block';
                    titleEl.textContent      = toolLabels[action] || action;
                    loadingEl.style.display  = 'block';
                    contentEl.innerHTML      = '';

                    resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', AJAX_URL, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.timeout = 90000;

                    xhr.onload = function() {
                        loadingEl.style.display = 'none';
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && res.data && res.data.html) {
                                titleEl.textContent = res.data.title || titleEl.textContent.replace('...', '');
                                contentEl.innerHTML = res.data.html;
                            } else {
                                var msg = (res.data && res.data.message) ? res.data.message : 'Erro desconhecido';
                                contentEl.innerHTML = '<p style="color:#dc3232;">❌ ' + msg + '</p>';
                            }
                        } catch(e) {
                            contentEl.innerHTML = '<p style="color:#dc3232;">❌ Erro ao processar resposta.</p>';
                        }
                    };

                    xhr.ontimeout = function() {
                        loadingEl.style.display = 'none';
                        contentEl.innerHTML = '<p style="color:#dc3232;">⏰ Timeout — tente novamente ou mude o provedor de IA.</p>';
                    };

                    xhr.onerror = function() {
                        loadingEl.style.display = 'none';
                        contentEl.innerHTML = '<p style="color:#dc3232;">❌ Erro de rede.</p>';
                    };

                    xhr.send(
                        'action='    + encodeURIComponent(action)
                        + '&nonce='  + encodeURIComponent(NONCE)
                        + '&keyword='+ encodeURIComponent(keyword)
                        + '&provider=' + encodeURIComponent(provider)
                    );
                });
            });
        })();
        </script>
        <?php
    }
}
