<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\ProviderResolver;

use GeoMetodoSEO\Helpers\SecurityHelper;
use GeoMetodoSEO\License\LicenseManager;

class SettingsController {

    private $api_fields = [
        'openai_api_key'     => 'OpenAI API Key',
        'groq_api_key'       => 'Groq API Key',
        'gemini_api_key'     => 'Gemini API Key',
        'claude_api_key'     => 'Claude API Key',
        'perplexity_api_key' => 'Perplexity API Key',
        'replicate_api_key'  => 'Replicate API Key (imagens Flux)',
        'falai_api_key'      => 'Fal.ai API Key (featured)',
        'huggingface_api_key'=> 'HuggingFace API Key (Web Stories)',
    ];

    private $api_links = [
        'openai_api_key'      => ['label' => 'Criar chave OpenAI', 'url' => 'https://platform.openai.com/api-keys'],
        'groq_api_key'        => ['label' => 'Criar chave Groq', 'url' => 'https://console.groq.com/keys'],
        'gemini_api_key'      => ['label' => 'Criar chave Gemini', 'url' => 'https://aistudio.google.com/app/apikey'],
        'claude_api_key'      => ['label' => 'Criar chave Claude', 'url' => 'https://console.anthropic.com/settings/keys'],
        'perplexity_api_key'  => ['label' => 'Criar chave Perplexity', 'url' => 'https://www.perplexity.ai/settings/api'],
        'replicate_api_key'   => ['label' => 'Criar token Replicate', 'url' => 'https://replicate.com/account/api-tokens'],
        'falai_api_key'       => ['label' => 'Criar chave Fal.ai', 'url' => 'https://fal.ai/dashboard/keys'],
        'huggingface_api_key' => ['label' => 'Criar token HuggingFace', 'url' => 'https://huggingface.co/settings/tokens'],
        'naga_api_key'        => ['label' => 'Abrir painel Naga.ac', 'url' => 'https://naga.ac/dashboard'],
    ];

    private $social_fields = [
        'social_twitter'   => 'Twitter / X',
        'social_linkedin'  => 'LinkedIn',
        'social_instagram' => 'Instagram',
        'social_facebook'  => 'Facebook',
    ];

    /**
     * Modelos disponíveis por provedor (usados também no IndividualGeneratorController via JS).
     */
    public static function get_provider_models() {
        return [
            'openai' => [
                // Premium — só use com Controle de uso e limite diário
                'gpt-5.5'      => 'GPT-5.5 ⚠️ premium / caro',
                'gpt-5.5-pro'  => 'GPT-5.5 Pro ⚠️ premium máximo',
                'gpt-5.5-mini' => 'GPT-5.5 Mini ⚠️ premium leve',
                'gpt-5.4'      => 'GPT-5.4 avançado',
                'gpt-5.4-mini' => 'GPT-5.4 Mini',
                'gpt-5'        => 'GPT-5 compatível',
                // Padrão seguro
                'gpt-4.1'      => 'GPT-4.1 padrão seguro',
                'gpt-4.1-mini' => 'GPT-4.1 Mini econômico',
                'gpt-4.1-nano' => 'GPT-4.1 Nano rascunhos',
                'o4-mini'      => 'o4 Mini (raciocínio)',
            ],
            'groq' => [
                'openai/gpt-oss-120b'                         => 'GPT OSS 120B ⭐ (Premium / artigos avançados)',
                'llama-3.3-70b-versatile'                     => 'Llama 3.3 70B ✅ (Produção estável — padrão)',
                'openai/gpt-oss-20b'                          => 'GPT OSS 20B (Rápido / massa)',
                'llama-3.1-8b-instant'                        => 'Llama 3.1 8B Instant (Ultra rápido)',
                'groq/compound-mini'                          => 'Groq Compound Mini (Pesquisa)',
                'groq/compound'                               => 'Groq Compound (Pesquisa avançada)',
                'qwen/qwen3-32b'                              => 'Qwen 3 32B (Preview / JSON)',
                'meta-llama/llama-4-scout-17b-16e-instruct'   => 'Llama 4 Scout (Multimodal preview)',
                'gemma2-9b-it'                       => 'Gemma 2 9B',
                'deepseek-r1-distill-llama-70b'      => 'DeepSeek R1 Distill 70B',
            ],
            'gemini' => [
                'gemini-2.5-pro-preview' => 'Gemini 2.5 Pro Preview (padrão)',
                'gemini-3.1-flash-lite'  => 'Gemini 3.1 Flash Lite ⭐ (500 RPD free)',
                'gemini-2.5-flash-lite'  => 'Gemini 2.5 Flash Lite (20 RPD free)',
                'gemini-2.5-flash'       => 'Gemini 2.5 Flash (20 RPD free)',
                'gemini-3-flash'         => 'Gemini 3 Flash (20 RPD free)',
                'gemini-2.5-pro'         => 'Gemini 2.5 Pro (PAGO — sem free tier)',
                'gemini-3.1-pro'         => 'Gemini 3.1 Pro (PAGO — sem free tier)',
                'gemini-2.0-flash'       => 'Gemini 2.0 Flash (depreciado)',
                'gemini-2.0-flash-lite'  => 'Gemini 2.0 Flash Lite (depreciado)',
                'gemini-1.5-pro'         => 'Gemini 1.5 Pro (depreciado)',
                'gemini-1.5-flash'       => 'Gemini 1.5 Flash (depreciado)',
            ],
            'claude' => [
                'claude-sonnet-4-6'          => 'Claude Sonnet 4.6 ⭐ (melhor)',
                'claude-sonnet-4-5'          => 'Claude Sonnet 4.5',
                'claude-opus-4-6'            => 'Claude Opus 4.6 (premium)',
                'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5 (rápido)',
            ],
            'perplexity' => [
                'sonar-pro'           => 'Sonar Pro (padrão)',
                'sonar'               => 'Sonar',
                'sonar-reasoning'     => 'Sonar Reasoning',
                'sonar-reasoning-pro' => 'Sonar Reasoning Pro',
                'r1-1776'             => 'R1-1776',
            ],
        ];
    }

    public function render_page() {

        $saved = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            SecurityHelper::verify_nonce($_POST['_wpnonce'] ?? '', 'geo_settings_save');
            SecurityHelper::current_user_can_manage();
            $this->save();
            $saved = true;
        }

        $providers = [
            'openai'     => 'OpenAI',
            'groq'       => 'Groq',
            'gemini'     => 'Gemini',
            'claude'     => 'Claude',
            'perplexity' => 'Perplexity',
                'naga'        => 'Naga.ac (Gemini/GPT-5/multi)',
        ];

        $languages = [
            'pt-BR' => 'Portugues do Brasil (pt-BR)',
            'en'    => 'English (en)',
            'es'    => 'Espanol (es)',
            'fr'    => 'Francais (fr)',
        ];

        $all_models       = self::get_provider_models();
        $current_provider = ProviderResolver::for('article_generation', get_option('geo_ai_provider', ''));
        $current_language = get_option('geo_default_language', 'pt-BR');

        // 1.0.0: padrão seguro de custo. GPT-5.5/Pro não é ativado automaticamente.
        if (!get_option('geo_model_upgrade_6933_provider_defaults_done')) {
            $old_openai = get_option('geo_model_openai', '');
            if ($old_openai === '' || in_array($old_openai, ['gpt-4.1', 'gpt-4.1-mini'], true)) {
                update_option('geo_model_openai', 'gpt-4.1');
            }
            update_option('geo_model_upgrade_6933_provider_defaults_done', 1);
        }

        ?>
        <div class="wrap">
            <h1>Configuracoes — GEO Metodo SEO v<?php echo GEO_METODO_SEO_VERSION; ?></h1>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Configuracoes salvas com sucesso!</strong></p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('geo_settings_save'); ?>

                <!-- API Keys -->
                <h2 class="title" style="margin-top:24px;">🔑 API Keys — Providers de IA</h2>
                <p class="description">Cole a API Key de cada provider que você usa. Apenas providers com key configurada ficam disponíveis nos geradores.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="geo_default_language">Idioma Padrão dos Artigos</label></th>
                        <td>
                            <select id="geo_default_language" name="geo_default_language" class="regular-text">
                                <?php foreach ($languages as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($current_language, $val); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Idioma usado na geração em massa e em clusters. Na geração individual você pode escolher por artigo.</p>
                        </td>
                    </tr>
                    <?php foreach ($this->api_fields as $key => $label): ?>
                    <tr>
                        <th scope="row">
                            <label for="geo_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                   id="geo_<?php echo esc_attr($key); ?>"
                                   name="geo_<?php echo esc_attr($key); ?>"
                                   value="<?php echo esc_attr(get_option('geo_' . $key, '')); ?>"
                                   class="regular-text"
                                   autocomplete="new-password">
                            <?php if (get_option('geo_' . $key)): ?>
                                <span style="color:#46b450;font-weight:600;">&#10003; Configurada</span>
                            <?php else: ?>
                                <span style="color:#dc3232;">&#10007; Nao configurada</span>
                            <?php endif; ?>
                            <?php if (isset($this->api_links[$key])): ?>
                                <p class="description">
                                    <a href="<?php echo esc_url($this->api_links[$key]['url']); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html($this->api_links[$key]['label']); ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Modelo Replicate/Flux -->
                    <tr>
                        <th scope="row"><label for="geo_replicate_model">Modelo Replicate (Flux)</label></th>
                        <td>
                            <input type="text" id="geo_replicate_model" name="geo_replicate_model"
                                   value="<?php echo esc_attr(get_option('geo_replicate_model', 'black-forest-labs/flux-schnell')); ?>"
                                   class="large-text">
                            <p class="description">
                                Formato: <code>owner/model</code> ou <code>owner/model:versao_hash</code><br>
                                Opcoes: <code>black-forest-labs/flux-schnell</code> (rapido) |
                                <code>black-forest-labs/flux-dev</code> (alta qualidade)
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_body_images_count">Imagens no corpo do artigo</label></th>
                        <td>
                            <select id="geo_body_images_count" name="geo_body_images_count">
                                <?php foreach ([2, 3, 4, 5] as $n): ?>
                                    <option value="<?php echo esc_attr($n); ?>" <?php selected((int)get_option('geo_body_images_count', 3), $n); ?>><?php echo esc_html($n); ?> imagens</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Quantidade usada em artigos, SARA, YouTube e glossario.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_image_h2_interval">Intervalo entre imagens</label></th>
                        <td>
                            <select id="geo_image_h2_interval" name="geo_image_h2_interval">
                                <option value="3" <?php selected((int)get_option('geo_image_h2_interval', 3), 3); ?>>A cada 3 H2</option>
                                <option value="4" <?php selected((int)get_option('geo_image_h2_interval', 3), 4); ?>>A cada 4 H2</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_featured_image_required">Imagem destacada obrigatoria</label></th>
                        <td>
                            <label><input type="checkbox" id="geo_featured_image_required" name="geo_featured_image_required" value="1" <?php checked((bool)get_option('geo_featured_image_required', 1)); ?>> Bloquear publicacao quando a featured falhar</label>
                            <?php $geo_body_n = (int)get_option('geo_body_images_count', 3); $geo_cost = 0.04 + ($geo_body_n * 0.003); ?>
                            <p class="description"><strong>Cadeia ativa:</strong> Featured Fal.ai -> Replicate; Corpo Replicate -> Fal.ai; Web Stories Naga.ac -> HuggingFace. Custo estimado/artigo: US$ <?php echo esc_html(number_format($geo_cost, 3)); ?>.</p>
                        </td>
                    </tr>
                </table>

                <!-- ═══════════════════════════════════════════════════════
                     IMAGENS DA BIBLIOTECA
                ════════════════════════════════════════════════════════ -->
                <h2 class="title" style="margin-top:32px;">🖼️ Fonte de Imagens</h2>
                <p class="description">Escolha se o plugin vai gerar imagens com IA ou usar suas imagens da biblioteca do WordPress.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>Fonte de imagens</label></th>
                        <td>
                            <?php $img_source = get_option('geo_image_source', 'ai'); ?>
                            <label style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                                <input type="radio" name="geo_image_source" value="ai" <?php checked($img_source, 'ai'); ?>>
                                <span><strong>🤖 Gerar com IA</strong> — Fal.ai, Replicate, DALL-E, Pollinations (padrão)</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:10px;">
                                <input type="radio" name="geo_image_source" value="library" <?php checked($img_source, 'library'); ?>>
                                <span><strong>📚 Usar minha biblioteca</strong> — IDs das imagens que você fez upload no WordPress</span>
                            </label>
                            <p class="description" style="margin-top:8px;">
                                Quando <strong>Biblioteca</strong> está ativo, <em>nenhuma IA de imagem é chamada</em>. As imagens da IA ficam completamente desativadas.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php
                // Carregar categorias para montar o painel
                $all_cats = get_categories(['hide_empty' => false, 'number' => 100]);
                ?>

                <div id="geo-library-panel" style="<?php echo $img_source === 'library' ? '' : 'display:none;'; ?>">
                    <h3 style="margin:24px 0 8px;padding:12px 16px;background:#f0f6ff;border-left:4px solid #1547F5;border-radius:4px;">
                        📚 Configurar Range de IDs por Categoria
                    </h3>
                    <p class="description" style="margin-bottom:4px;">
                        Informe o <strong>ID mínimo</strong> e o <strong>ID máximo</strong> das imagens de cada categoria.<br>
                        O plugin vai buscar automaticamente todas as imagens da sua biblioteca entre esses dois valores.
                    </p>
                    <p class="description" style="margin-bottom:16px;padding:8px 12px;background:#f8f9fa;border-radius:4px;">
                        <strong>Como encontrar os IDs:</strong> Vá em <em>Biblioteca de Mídia → clique na imagem</em> → o número na URL é o ID.
                        Exemplo: <code>post.php?post=<strong>123</strong></code> → ID é <strong>123</strong>
                    </p>

                    <!-- ── GLOBAL ── -->
                    <div style="background:#fff;border:1px solid #c3d0e8;border-radius:8px;padding:20px;margin-bottom:16px;">
                        <h4 style="margin:0 0 4px;color:#1547F5;">🌐 Global <small style="font-weight:400;color:#64748B;">(fallback — usado quando a categoria não tem range próprio)</small></h4>
                        <p class="description" style="margin-bottom:14px;">Configure aqui para que todas as categorias sem range específico usem estas imagens.</p>
                        <?php
                        // Helper para exibir info de range
                        $render_range_row = function(string $slug, string $type, string $label) {
                            $min_key  = 'geo_lib_' . $type . '_min_' . $slug;
                            $max_key  = 'geo_lib_' . $type . '_max_' . $slug;
                            $ptr_key  = 'geo_lib_' . $type . '_ptr_' . $slug;
                            $cur_min  = (int) get_option($min_key, 0);
                            $cur_max  = (int) get_option($max_key, 0);
                            $cur_ptr  = (int) get_option($ptr_key, 0);
                            $total    = 0;
                            if ($cur_min > 0 && $cur_max >= $cur_min) {
                                global $wpdb;
                                $total = (int) $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(ID) FROM {$wpdb->posts}
                                     WHERE post_type='attachment' AND post_mime_type LIKE 'image/%%'
                                     AND post_status='inherit' AND ID BETWEEN %d AND %d",
                                    $cur_min, $cur_max
                                ));
                            }
                            echo '<tr>';
                            echo '<th scope="row" style="width:200px;padding:8px 0;">';
                            echo '<label>' . esc_html($label) . '</label>';
                            echo '</th>';
                            echo '<td style="padding:8px 0;">';
                            echo '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
                            // Min
                            echo '<div>';
                            echo '<label style="font-size:11px;color:#64748B;display:block;margin-bottom:2px;">ID Mínimo</label>';
                            echo '<input type="number" name="' . esc_attr($min_key) . '" value="' . esc_attr($cur_min ?: '') . '" min="1" placeholder="Ex: 100" style="width:100px;">';
                            echo '</div>';
                            // Max
                            echo '<div>';
                            echo '<label style="font-size:11px;color:#64748B;display:block;margin-bottom:2px;">ID Máximo</label>';
                            echo '<input type="number" name="' . esc_attr($max_key) . '" value="' . esc_attr($cur_max ?: '') . '" min="1" placeholder="Ex: 200" style="width:100px;">';
                            echo '</div>';
                            // Info
                            if ($cur_min > 0 && $cur_max >= $cur_min) {
                                $pos = $total > 0 ? (($cur_ptr % $total) + 1) : 0;
                                echo '<div style="background:#f0f6ff;border:1px solid #c3d0e8;border-radius:6px;padding:6px 12px;font-size:12px;">';
                                echo '<strong style="color:#1547F5;">' . $total . '</strong> imagens encontradas';
                                if ($total > 0) echo ' · próxima: <strong>' . $pos . '/' . $total . '</strong>';
                                echo '</div>';
                            } else {
                                echo '<span style="font-size:12px;color:#94A3B8;">Configure Min e Max para ver o total</span>';
                            }
                            echo '</div>';
                            echo '</td>';
                            echo '</tr>';
                        };
                        ?>
                        <table class="form-table" role="presentation" style="margin:0;">
                            <?php
                            $render_range_row('global', 'featured', '🖼️ Destaque — Range de IDs');
                            $render_range_row('global', 'body',     '📷 Corpo — Range de IDs');
                            ?>
                        </table>
                    </div>

                    <!-- ── POR CATEGORIA ── -->
                    <?php if (!empty($all_cats)): ?>
                    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
                        <h4 style="margin:0 0 6px;color:#1E293B;">📁 Por Categoria <small style="font-weight:400;color:#64748B;">(sobrescreve o Global para essa categoria)</small></h4>
                        <p class="description" style="margin-bottom:16px;">Deixe em branco para usar o range Global.</p>
                        <?php foreach ($all_cats as $cat): ?>
                        <div style="border-top:1px solid #f0f0f0;padding:14px 0;">
                            <strong style="display:block;margin-bottom:8px;color:#334155;font-size:13px;">
                                📂 <?php echo esc_html($cat->name); ?>
                                <span style="font-weight:400;color:#94A3B8;">(<?php echo esc_html($cat->count); ?> posts · slug: <code><?php echo esc_html($cat->slug); ?></code>)</span>
                            </strong>
                            <table class="form-table" role="presentation" style="margin:0;">
                                <?php
                                $render_range_row($cat->slug, 'featured', '🖼️ Destaque');
                                $render_range_row($cat->slug, 'body',     '📷 Corpo');
                                ?>
                            </table>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <p style="margin-top:12px;padding:10px 14px;background:#FFF7ED;border-left:4px solid #F97316;border-radius:4px;font-size:13px;">
                        <strong>⚠️ Importante:</strong> Ao salvar, os ponteiros são resetados e o cache é limpo automaticamente.
                        As imagens são buscadas diretamente da sua biblioteca — o plugin ignora IDs que não existem ou não são imagens.
                    </p>
                </div>

                <script>
                (function(){
                    var radios = document.querySelectorAll('input[name="geo_image_source"]');
                    var panel  = document.getElementById('geo-library-panel');
                    radios.forEach(function(r){
                        r.addEventListener('change', function(){
                            panel.style.display = this.value === 'library' ? '' : 'none';
                        });
                    });
                })();
                </script>

                <!-- Provider + Modelo por Gerador -->
                <h2 class="title" style="margin-top:32px;">🧠 Provider e Modelo — por Gerador</h2>
                <p class="description">
                    Cada gerador usa <strong>seu próprio provider e modelo</strong>. Sem fallback automático — se o provider falhar, você vê o erro e escolhe outro.<br>
                    Deixe em <em>"— Padrão —"</em> para usar o Provider Padrão Global.
                </p>
                <?php
                $render_gen_row = function(string $lbl, string $pk, string $mk, string $desc='') use ($all_models, $providers) {
                    $cp = get_option($pk, '');
                    $cm = get_option($mk, '');
                    echo '<tr>';
                    echo '<th scope="row" style="vertical-align:top;padding-top:14px;">';
                    echo '<strong>' . esc_html($lbl) . '</strong>';
                    if ($desc) echo '<br><span style="font-weight:400;color:#64748B;font-size:11px;">' . esc_html($desc) . '</span>';
                    echo '</th><td style="padding-top:14px;">';
                    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">';
                    // Provider
                    echo '<div><label style="font-size:11px;color:#64748B;display:block;margin-bottom:2px;">Provider</label>';
                    echo '<select name="' . esc_attr($pk) . '" style="min-width:140px;" onchange="geoUpdMdl(this,\'' . esc_attr($mk) . '\')">';
                    echo '<option value="">— Padrão —</option>';
                    foreach ($providers as $pv => $pl) {
                        $ok = \GeoMetodoSEO\AI\ProviderResolver::isConfigured($pv);
                        echo '<option value="' . esc_attr($pv) . '"' . selected($cp,$pv,false) . '>' . esc_html($pl) . ($ok?' ✓':' (sem key)') . '</option>';
                    }
                    echo '</select></div>';
                    // Modelo
                    echo '<div><label style="font-size:11px;color:#64748B;display:block;margin-bottom:2px;">Modelo</label>';
                    echo '<select name="' . esc_attr($mk) . '" id="geo_mdl_' . esc_attr($mk) . '" style="min-width:210px;">';
                    echo '<option value="">— Padrão do provider —</option>';
                    $show = ($cp && isset($all_models[$cp])) ? [$cp => $all_models[$cp]] : $all_models;
                    foreach ($show as $pv2 => $mds) {
                        if (count($show) > 1) echo '<optgroup label="' . esc_attr(strtoupper($pv2)) . '">';
                        foreach ($mds as $mv => $ml) echo '<option value="' . esc_attr($mv) . '"' . selected($cm,$mv,false) . '>' . esc_html($ml) . '</option>';
                        if (count($show) > 1) echo '</optgroup>';
                    }
                    echo '</select></div></div></td></tr>';
                };
                ?>
                <table class="form-table" role="presentation">
                    <tr><th colspan="2" style="padding-bottom:0;">
                        <strong style="color:#1547F5;">⚙️ Provider Padrão Global</strong>
                        <span style="font-weight:400;color:#64748B;font-size:12px;margin-left:8px;">usado quando o gerador não tem provider próprio</span>
                    </th></tr>
                    <?php $render_gen_row('Padrão Global', 'geo_default_provider', 'geo_default_model'); ?>
                    <tr><th colspan="2" style="padding-top:16px;padding-bottom:0;">
                        <hr style="margin:0 0 8px;border-color:#e2e8f0;">
                        <strong>📝 Por Gerador</strong>
                    </th></tr>
                    <?php
                    $render_gen_row('Gerador Individual',   'geo_individual_provider', 'geo_individual_model', 'SARA → Gerar Artigo Individual');
                    $render_gen_row('Gerador em Massa',     'geo_bulk_provider',       'geo_bulk_model',       'SARA → Gerar em Massa');
                    $render_gen_row('Cluster SEO',          'geo_cluster_provider',    'geo_cluster_model',    'SARA → Cluster SEO');
                    $render_gen_row('YouTube → Artigo',     'geo_youtube_provider',    'geo_youtube_model',    'SARA → YouTube para Artigo');
                    $render_gen_row('SARA Autopilot Auto',  'geo_sara_provider',       'geo_sara_model',       'Brain, geração automática');
                    $render_gen_row('Writer Manual',        'geo_manual_provider',     'geo_manual_model',     'SARA → Writer Manual');
                    $render_gen_row('Glossário',            'geo_glossary_provider',   'geo_glossary_model',   'Gerador de Glossário');
                    $render_gen_row('Content Refresher',    'geo_refresher_provider',  'geo_refresher_model',  'Atualização de artigos antigos');
                    ?>
                    <tr><th colspan="2" style="padding-top:16px;padding-bottom:0;">
                        <hr style="margin:0 0 8px;border-color:#e2e8f0;">
                        <strong>🎯 Modelo Padrão por Provider</strong>
                        <span style="font-weight:400;color:#64748B;font-size:12px;margin-left:8px;">usado quando o gerador não tem modelo específico</span>
                    </th></tr>
                    <?php
                    $prov_labels2 = ['openai'=>'OpenAI','groq'=>'Groq','gemini'=>'Gemini','claude'=>'Claude','perplexity'=>'Perplexity','naga'=>'Naga.ac'];
                    foreach ($all_models as $prov => $models):
                        $cur_def_model = get_option('geo_default_model_' . $prov, get_option('geo_model_' . $prov, array_key_first($models)));
                    ?>
                    <tr>
                        <th scope="row"><label for="geo_default_model_<?php echo esc_attr($prov); ?>"><?php echo esc_html($prov_labels2[$prov] ?? $prov); ?></label></th>
                        <td>
                            <select id="geo_default_model_<?php echo esc_attr($prov); ?>" name="geo_default_model_<?php echo esc_attr($prov); ?>" class="regular-text">
                                <?php foreach ($models as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($cur_def_model, $val); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <script>
                var geoMdls=<?php echo json_encode($all_models); ?>;
                function geoUpdMdl(sel,mk){
                    var p=sel.value,el=document.getElementById('geo_mdl_'+mk);
                    if(!el)return;var cur=el.value;
                    el.innerHTML='<option value="">— Padrão do provider —</option>';
                    var src=p&&geoMdls[p]?{[p]:geoMdls[p]}:geoMdls;
                    for(var pv in src){
                        var grp=Object.keys(src).length>1?document.createElement('optgroup'):null;
                        if(grp){grp.label=pv.toUpperCase();el.appendChild(grp);}
                        for(var mv in src[pv]){var o=document.createElement('option');o.value=mv;o.text=src[pv][mv];if(mv===cur)o.selected=true;(grp||el).appendChild(o);}
                    }
                }
                </script>

                <!-- Notificacoes -->
                <h2 class="title" style="margin-top:32px;">📧 Notificacoes por Email</h2>
                <p class="description">Receba um email ao final de cada geracao em massa ou cluster com o resumo dos resultados.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="geo_notification_email">Email de Notificacao</label></th>
                        <td>
                            <input type="email" id="geo_notification_email" name="geo_notification_email"
                                   value="<?php echo esc_attr(get_option('geo_notification_email', get_option('admin_email'))); ?>"
                                   class="regular-text" placeholder="email@exemplo.com">
                            <p class="description">Deixe em branco para desativar as notificacoes.</p>
                        </td>
                    </tr>
                </table>

                <!-- E-E-A-T Autor -->
                <h2 class="title" style="margin-top:32px;">✍️ E-E-A-T — Perfil do Autor</h2>
                <p class="description">Dados exibidos na assinatura de cada artigo gerado. Completo aumenta os sinais de E-E-A-T para o Google.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="geo_author_name">Nome do Autor</label></th>
                        <td>
                            <input type="text" id="geo_author_name" name="geo_author_name"
                                   value="<?php echo esc_attr(get_option('geo_author_name', 'Alison Jean')); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_photo">Foto do Autor (URL)</label></th>
                        <td>
                            <input type="url" id="geo_author_photo" name="geo_author_photo"
                                   value="<?php echo esc_attr(get_option('geo_author_photo', '')); ?>"
                                   class="large-text" placeholder="https://example.com/foto.jpg">
                            <?php if (get_option('geo_author_photo')): ?>
                                <br><img src="<?php echo esc_url(get_option('geo_author_photo')); ?>"
                                         style="width:60px;height:60px;border-radius:50%;object-fit:cover;margin-top:8px;">
                            <?php endif; ?>
                            <p class="description">URL da foto de perfil. Use uma imagem quadrada para melhor resultado.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_specialty">Especialidade</label></th>
                        <td>
                            <input type="text" id="geo_author_specialty" name="geo_author_specialty"
                                   value="<?php echo esc_attr(get_option('geo_author_specialty', '')); ?>"
                                   class="regular-text" placeholder="Ex: Especialista em SEO e Marketing Digital">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_experience">Anos de Experiencia</label></th>
                        <td>
                            <input type="text" id="geo_author_experience" name="geo_author_experience"
                                   value="<?php echo esc_attr(get_option('geo_author_experience', '')); ?>"
                                   class="small-text" placeholder="Ex: 10 anos">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_certifications">Certificacoes</label></th>
                        <td>
                            <input type="text" id="geo_author_certifications" name="geo_author_certifications"
                                   value="<?php echo esc_attr(get_option('geo_author_certifications', '')); ?>"
                                   class="large-text" placeholder="Ex: Google Analytics, HubSpot Content Marketing, SEMrush">
                            <p class="description">Liste as certificacoes separadas por virgula.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_bio">Bio do Autor</label></th>
                        <td>
                            <textarea id="geo_author_bio" name="geo_author_bio" rows="4"
                                      class="large-text"><?php echo esc_textarea(get_option('geo_author_bio', 'Especialista em SEO e GEO.')); ?></textarea>
                            <p class="description">Breve descricao exibida na assinatura dos artigos.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_editorial_page">Pagina Editorial (URL)</label></th>
                        <td>
                            <input type="url" id="geo_author_editorial_page" name="geo_author_editorial_page"
                                   value="<?php echo esc_attr(get_option('geo_author_editorial_page', '')); ?>"
                                   class="large-text" placeholder="https://seusite.com/editorial">
                            <p class="description">Link para a pagina editorial ou de politica de conteudo do site.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_subdomains">Subdominios</label></th>
                        <td>
                            <textarea id="geo_author_subdomains" name="geo_author_subdomains" rows="4"
                                      class="large-text" placeholder="https://blog.seusite.com&#10;https://noticias.seusite.com"><?php echo esc_textarea(get_option('geo_author_subdomains', '')); ?></textarea>
                            <p class="description">Um subdominio por linha (URL completa). Aparecem como links na assinatura.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_site_categories">Categorias do Site</label></th>
                        <td>
                            <textarea id="geo_author_site_categories" name="geo_author_site_categories" rows="4"
                                      class="large-text" placeholder="https://seusite.com/categoria/seo&#10;https://seusite.com/categoria/marketing"><?php echo esc_textarea(get_option('geo_author_site_categories', '')); ?></textarea>
                            <p class="description">Uma categoria por linha (URL completa). Aparecem como links na assinatura.</p>
                        </td>
                    </tr>
                </table>

                <!-- Organização AEO/GEO -->
                <h2 class="title" style="margin-top:32px;">🏢 Organização <small style="font-size:12px;color:#888;">(Schema.org — AEO/GEO/LLM)</small></h2>
                <p class="description">Dados que aparecem no Schema Organization e são lidos pelo Google, ChatGPT e Gemini para identificar seu site como fonte autorizada.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="geo_org_phone">Telefone / WhatsApp</label></th>
                        <td>
                            <input type="text" id="geo_org_phone" name="geo_org_phone"
                                   value="<?php echo esc_attr(get_option('geo_org_phone','')); ?>"
                                   class="regular-text" placeholder="+55-11-99999-9999">
                            <p class="description">Formato: +55-11-99999-9999</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_org_email">Email de Contato</label></th>
                        <td>
                            <input type="email" id="geo_org_email" name="geo_org_email"
                                   value="<?php echo esc_attr(get_option('geo_org_email','')); ?>"
                                   class="regular-text" placeholder="contato@seusite.com.br">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_org_slogan">Slogan do Site</label></th>
                        <td>
                            <input type="text" id="geo_org_slogan" name="geo_org_slogan"
                                   value="<?php echo esc_attr(get_option('geo_org_slogan','')); ?>"
                                   class="large-text" placeholder="SEO · GEO · IA">
                            <p class="description">Campo <code>slogan</code> no Schema — ajuda LLMs a entender o posicionamento do site.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_org_founded">Ano de Fundação</label></th>
                        <td>
                            <input type="text" id="geo_org_founded" name="geo_org_founded"
                                   value="<?php echo esc_attr(get_option('geo_org_founded','')); ?>"
                                   class="small-text" placeholder="2023">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_org_description">Descrição da Organização</label></th>
                        <td>
                            <textarea id="geo_org_description" name="geo_org_description" rows="3"
                                      class="large-text" placeholder="2-3 frases sobre o que o site faz. Usado pelo Google Knowledge Graph e LLMs."><?php echo esc_textarea(get_option('geo_org_description','')); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_knowsabout">Áreas de Expertise do Autor</label></th>
                        <td>
                            <input type="text" id="geo_author_knowsabout" name="geo_author_knowsabout"
                                   value="<?php echo esc_attr(get_option('geo_author_knowsabout','')); ?>"
                                   class="large-text" placeholder="SEO, GEO, Inteligência Artificial, Marketing Digital">
                            <p class="description">Separado por vírgulas — campo <code>knowsAbout</code> no schema Person. Sinaliza expertise para LLMs.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_awards">Prêmios e Reconhecimentos</label></th>
                        <td>
                            <input type="text" id="geo_author_awards" name="geo_author_awards"
                                   value="<?php echo esc_attr(get_option('geo_author_awards','')); ?>"
                                   class="large-text" placeholder="Prêmio X 2024, Certificação Google Analytics">
                            <p class="description">Separado por vírgulas — campo <code>award</code> no schema Person. Fortalece E-E-A-T.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_author_credentials">Credenciais / Certificações</label></th>
                        <td>
                            <input type="text" id="geo_author_credentials" name="geo_author_credentials"
                                   value="<?php echo esc_attr(get_option('geo_author_credentials','')); ?>"
                                   class="large-text" placeholder="Google Analytics Certified, HubSpot Content Marketing">
                            <p class="description">Separado por vírgulas â€” gera <code>EducationalOccupationalCredential</code> no schema Person. Crucial para E-E-A-T avançado.</p>
                        </td>
                    </tr>
                </table>

                <!-- Redes Sociais -->
                <h2 class="title" style="margin-top:32px;">🌐 Redes Sociais do Autor</h2>
                <p class="description">Links exibidos na assinatura dos artigos.</p>
                <table class="form-table" role="presentation">
                    <?php foreach ($this->social_fields as $key => $label): ?>
                    <tr>
                        <th scope="row">
                            <label for="geo_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                        </th>
                        <td>
                            <input type="url" id="geo_<?php echo esc_attr($key); ?>" name="geo_<?php echo esc_attr($key); ?>"
                                   value="<?php echo esc_attr(get_option('geo_' . $key, '')); ?>"
                                   class="regular-text" placeholder="https://">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <!-- Geração v1.0.0 -->
                <h2 class="title" style="margin-top:32px;">⚡ Geração — v1.0.0</h2>
                <p class="description">Configurações das novas funcionalidades: MPC, tom de voz, cadeia oficial de imagens e reescrita automática.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="geo_use_mpc">Modo MPC (Multi Prompt Chain)</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="geo_use_mpc" name="geo_use_mpc" value="1" <?php checked((bool) get_option('geo_use_mpc', 0)); ?>>
                                Ativar MPC — 6 chamadas de IA focadas em vez de 1 (artigos mais detalhados, porém mais lentos)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_article_size">Tamanho Padrão do Artigo</label></th>
                        <td>
                            <select id="geo_article_size" name="geo_article_size" class="regular-text">
                                <option value="small"  <?php selected(get_option('geo_article_size','large'),'small'); ?>>📄 Pequeno — completo até 1.500 palavras</option>
                                <option value="medium" <?php selected(get_option('geo_article_size','large'),'medium'); ?>>📋 Médio — completo até 2.200 palavras</option>
                                <option value="large"  <?php selected(get_option('geo_article_size','large'),'large'); ?>>📚 Grande — completo até 3.500 palavras</option>
                            </select>
                            <p class="description">Define o volume de conteúdo gerado em cada seção. Pode ser sobrescrito na geração individual e em massa.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_default_tone">Tom de Voz Padrão</label></th>
                        <td>
                            <?php
                            $tones = [
                                ''              => '— Sem preferência (IA decide) —',
                                'profissional'  => 'Profissional',
                                'persuasivo'    => 'Persuasivo',
                                'informal'      => 'Informal',
                                'tecnico'       => 'Técnico',
                                'storytelling'  => 'Storytelling',
                                'educativo'     => 'Educativo',
                            ];
                            $cur_tone = get_option('geo_default_tone', '');
                            ?>
                            <select id="geo_default_tone" name="geo_default_tone" class="regular-text">
                                <?php foreach ($tones as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($cur_tone, $val); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Pode ser sobrescrito na geração individual.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_body_images_count">Imagens no corpo do artigo</label></th>
                        <td>
                            <select id="geo_body_images_count" name="geo_body_images_count">
                                <?php foreach ([2,3,4,5] as $n): ?>
                                    <option value="<?php echo esc_attr($n); ?>" <?php selected((int)get_option('geo_body_images_count', 3), $n); ?>><?php echo esc_html($n); ?> imagens</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Quantidade usada em Gerar Artigo Individual, massa, SARA, YouTube e Glossario.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_image_h2_interval">Intervalo entre imagens</label></th>
                        <td>
                            <select id="geo_image_h2_interval" name="geo_image_h2_interval">
                                <option value="3" <?php selected((int)get_option('geo_image_h2_interval', 3), 3); ?>>A cada 3 H2</option>
                                <option value="4" <?php selected((int)get_option('geo_image_h2_interval', 3), 4); ?>>A cada 4 H2</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_featured_image_required">Imagem destacada obrigatoria</label></th>
                        <td>
                            <label><input type="checkbox" id="geo_featured_image_required" name="geo_featured_image_required" value="1" <?php checked((bool)get_option('geo_featured_image_required', 1)); ?>> Bloquear publicacao quando Fal.ai e Replicate falharem na featured image</label>
                        </td>
                    </tr>
                </table>

                <h2 class="title" style="margin-top:32px;">Imagens v1.0.0</h2>
                <p class="description"><strong>Cadeia ativa:</strong> Featured: Fal.ai -> Replicate. Corpo: Replicate -> Fal.ai. Web Stories: Naga.ac -> HuggingFace.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Custo estimado/artigo</th>
                        <td>
                            <?php $geo_body_n = (int)get_option('geo_body_images_count', 3); $geo_cost = 0.04 + ($geo_body_n * 0.003); ?>
                            <strong>US$ <?php echo esc_html(number_format($geo_cost, 3)); ?></strong>
                            <span class="description">Featured Fal.ai US$0.04 + <?php echo esc_html($geo_body_n); ?> imagens Replicate Schnell.</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_naga_api_key">Naga.ac API Key</label></th>
                        <td>
                            <input type="password" id="geo_naga_api_key" name="geo_naga_api_key" value="<?php echo esc_attr(get_option('geo_naga_api_key', '')); ?>" class="regular-text" autocomplete="off">
                            <span class="description"> Web Stories primario.</span>
                            <p class="description">
                                <a href="<?php echo esc_url($this->api_links['naga_api_key']['url']); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html($this->api_links['naga_api_key']['label']); ?>
                                </a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_naga_model">Modelo Naga Web Stories</label></th>
                        <td><input type="text" id="geo_naga_model" name="geo_naga_model" value="<?php echo esc_attr(get_option('geo_naga_model', 'dall-e-3:free')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_auto_rewrite">Reescrita Automatica</label></th>
                        <td><label><input type="checkbox" id="geo_auto_rewrite" name="geo_auto_rewrite" value="1" <?php checked((bool) get_option('geo_auto_rewrite', 0)); ?>> Reescrever artigos antigos automaticamente via WP Cron</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_rewrite_days">Reescrever artigos com mais de (dias)</label></th>
                        <td><input type="number" id="geo_rewrite_days" name="geo_rewrite_days" min="30" max="730" value="<?php echo intval(get_option('geo_rewrite_days', 120)); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_rewrite_per_day">Artigos reescritos por dia</label></th>
                        <td><input type="number" id="geo_rewrite_per_day" name="geo_rewrite_per_day" min="1" max="20" value="<?php echo intval(get_option('geo_rewrite_per_day', 3)); ?>" class="small-text"></td>
                    </tr>
                </table>

                <!-- v1.0.0 - Bancos de Imagem Gratuitos -->
                <!-- v1.0.0 - Bancos de Imagem Gratuitos -->
                <h2 class="title" style="margin-top:32px;">🖼️ Bancos de Imagem Gratuitos</h2>
                <p class="description">APIs gratuitas usadas como fallback de custo zero. Configure uma ou mais.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="geo_unsplash_api_key">Unsplash Access Key</label></th>
                        <td>
                            <input type="password" id="geo_unsplash_api_key" name="geo_unsplash_api_key"
                                   value="<?php echo esc_attr(get_option('geo_unsplash_api_key', '')); ?>"
                                   class="regular-text" placeholder="Client-ID...">
                            <p class="description"><a href="https://unsplash.com/developers" target="_blank">unsplash.com/developers</a> — gratuito, 50 req/hora</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_pexels_api_key">Pexels API Key</label></th>
                        <td>
                            <input type="password" id="geo_pexels_api_key" name="geo_pexels_api_key"
                                   value="<?php echo esc_attr(get_option('geo_pexels_api_key', '')); ?>"
                                   class="regular-text" placeholder="...">
                            <p class="description"><a href="https://www.pexels.com/api/" target="_blank">pexels.com/api</a> — gratuito, 200 req/hora</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_pixabay_api_key">Pixabay API Key</label></th>
                        <td>
                            <input type="password" id="geo_pixabay_api_key" name="geo_pixabay_api_key"
                                   value="<?php echo esc_attr(get_option('geo_pixabay_api_key', '')); ?>"
                                   class="regular-text" placeholder="...">
                            <p class="description"><a href="https://pixabay.com/api/docs/" target="_blank">pixabay.com/api</a> — gratuito, 100 req/hora</p>
                        </td>
                    </tr>
                </table>

                <!-- v1.0.0 — YouTube → Artigo -->
                <h2 class="title" style="margin-top:32px;">▶️ YouTube → Artigo</h2>
                <p class="description">Converte vídeos do YouTube em artigos SEO. <a href="https://console.cloud.google.com" target="_blank">Criar API Key no Google Cloud →</a> (ativar "YouTube Data API v3")</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="geo_serpapi_key">SerpAPI Key <small style="color:#888;">(análise concorrentes real)</small></label></th>
                        <td>
                            <input type="password" id="geo_serpapi_key" name="geo_serpapi_key"
                                   value="<?php echo esc_attr(get_option('geo_serpapi_key', '')); ?>"
                                   class="regular-text" autocomplete="off">
                            <p class="description">Opcional. Permite à SARA analisar os 10 primeiros resultados reais do Google para qualquer keyword. <a href="https://serpapi.com" target="_blank">Obter API key →</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_youtube_api_key">YouTube Data API Key</label></th>
                        <td>
                            <input type="password" id="geo_youtube_api_key" name="geo_youtube_api_key"
                                   value="<?php echo esc_attr(get_option('geo_youtube_api_key', '')); ?>"
                                   class="regular-text" placeholder="AIza...">
                            <p class="description"><?php echo get_option('geo_youtube_api_key') ? '<span style="color:#16a34a;">✅ Configurada</span>' : '<span style="color:#888;">Não configurada</span>'; ?> — necessária para embedar vídeos do YouTube nos artigos.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_embed_youtube_video">Vídeo no Autopilot</label></th>
                        <td>
                            <label><input type="checkbox" id="geo_embed_youtube_video" name="geo_embed_youtube_video" value="1" <?php checked(get_option('geo_embed_youtube_video', '0') === '1'); ?>> Embedar vídeo do YouTube relacionado nos artigos do SARA Autopilot automático</label>
                            <p class="description">Nos geradores manuais (Individual, Massa, Cluster, Writer Manual) a opção de vídeo é escolhida por geração. Esta opção controla apenas o Autopilot automático.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_zernio_api_key">Zernio API Key (redes sociais)</label></th>
                        <td>
                            <input type="password" id="geo_zernio_api_key" name="geo_zernio_api_key"
                                   value="<?php echo esc_attr(get_option('geo_zernio_api_key', '')); ?>"
                                   class="regular-text" placeholder="zer_...">
                            <p class="description">
                                <?php echo get_option('geo_zernio_api_key') ? '<span style="color:#16a34a;">✅ Configurada</span>' : '<span style="color:#888;">Não configurada</span>'; ?>
                                — permite compartilhar artigos em 15 redes sociais (Instagram, X, Facebook, LinkedIn, TikTok, etc) com um clique.
                                Crie sua chave em <a href="https://zernio.com" target="_blank" rel="noopener">zernio.com</a> e conecte suas contas lá.
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- v1.0.0 — TTS -->
                <h2 class="title" style="margin-top:32px;">🎧 Text-to-Speech (Artigo → Áudio)</h2>
                <p class="description">Converte artigos em áudio MP3. OpenAI TTS usa a chave OpenAI existente.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="geo_tts_provider">Provedor TTS</label></th>
                        <td>
                            <select id="geo_tts_provider" name="geo_tts_provider">
                                <option value="openai" <?php selected(get_option('geo_tts_provider', 'openai'), 'openai'); ?>>OpenAI TTS (usa chave OpenAI existente)</option>
                                <option value="elevenlabs" <?php selected(get_option('geo_tts_provider'), 'elevenlabs'); ?>>ElevenLabs</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_tts_openai_voice">Voz OpenAI</label></th>
                        <td>
                            <select id="geo_tts_openai_voice" name="geo_tts_openai_voice">
                                <?php foreach (['alloy','echo','fable','onyx','nova','shimmer'] as $v): ?>
                                <option value="<?php echo $v; ?>" <?php selected(get_option('geo_tts_openai_voice', 'nova'), $v); ?>><?php echo ucfirst($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Nova e Shimmer = femininas. Onyx e Echo = masculinas.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_elevenlabs_api_key">ElevenLabs API Key</label></th>
                        <td>
                            <input type="password" id="geo_elevenlabs_api_key" name="geo_elevenlabs_api_key"
                                   value="<?php echo esc_attr(get_option('geo_elevenlabs_api_key', '')); ?>"
                                   class="regular-text" placeholder="xi-...">
                            <p class="description"><a href="https://elevenlabs.io" target="_blank">elevenlabs.io</a> — voz mais natural</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_tts_max_chars">Máx. caracteres por áudio</label></th>
                        <td>
                            <input type="number" id="geo_tts_max_chars" name="geo_tts_max_chars"
                                   value="<?php echo intval(get_option('geo_tts_max_chars', 4000)); ?>"
                                   min="500" max="50000" class="small-text">
                            <span class="description"> chars (padrão: 4000 = ~3 min de áudio)</span>
                        </td>
                    </tr>
                </table>

                <!-- v1.0.0 — Google Search Console -->
                <h2 class="title" style="margin-top:32px;">🔍 Google Search Console</h2>
                <p class="description">
                    Exibe dados de impressões, CTR e posição no dashboard.<br>
                    <strong>Setup:</strong> 1) <a href="https://console.cloud.google.com" target="_blank">Google Cloud Console</a>
                    → 2) Ative "Google Search Console API"
                    → 3) Crie credencial OAuth 2.0 (Web Application)
                    → 4) URI de redirecionamento autorizado: <code id="gsc-redirect-uri"><?php echo esc_html(admin_url('admin.php?page=geo-settings&gsc_callback=1')); ?></code>
                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('gsc-redirect-uri').textContent).then(function(){this.textContent='✅ Copiado!';setTimeout(()=>this.textContent='📋 Copiar',2000)},()=>{})" style="margin-left:8px;padding:2px 8px;font-size:11px;cursor:pointer;border:1px solid #ddd;border-radius:4px;background:#fff;">📋 Copiar</button>
                    <br><small style="color:#dc2626;font-weight:600;">⚠️ Cole este URI EXATAMENTE no Google Cloud Console → APIs e Serviços → Credenciais → seu OAuth Client → URIs de redirecionamento autorizados. Qualquer diferença causa erro "redirect_uri_mismatch".</small>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Status</th>
                        <td>
                            <?php
                            $gsc_token = get_option('geo_gsc_token', []);
                            if (!empty($gsc_token['access_token'])): ?>
                                <span style="color:#16a34a;font-weight:bold;">✅ Conectado</span>
                                &nbsp;<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=geo-settings&geo_gsc_disconnect=1'), 'geo_gsc_disconnect')); ?>"
                                   class="button button-small" style="color:#dc2626;">Desconectar</a>
                            <?php else: ?>
                                <span style="color:#888;">Não conectado</span>
                                <?php if (get_option('geo_gsc_client_id')): ?>
                                &nbsp;<a href="<?php
                                    if (class_exists('GeoMetodoSEO\SEO\SearchConsoleService')) {
                                        echo esc_url((new \GeoMetodoSEO\SEO\SearchConsoleService())->get_auth_url());
                                    }
                                ?>" class="button button-primary button-small">Conectar com Google</a>
                                <?php else: ?>
                                <span style="color:#888;"> — preencha Client ID e Secret abaixo primeiro</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php
                            // 1.0.0 — Mostrar último erro do GSC (ajuda diagnosticar falhas silenciosas)
                            if (class_exists('\GeoMetodoSEO\SEO\SearchConsoleService')) {
                                $gsc_svc_dbg = new \GeoMetodoSEO\SEO\SearchConsoleService();
                                $last_err    = $gsc_svc_dbg->get_last_error();
                                if ($last_err): ?>
                                    <div style="margin-top:12px;padding:12px 14px;background:#fef2f2;border-left:4px solid #dc2626;border-radius:4px;">
                                        <strong style="color:#7f1d1d;">⚠️ Último erro do GSC:</strong>
                                        <div style="font-size:12px;color:#7f1d1d;margin-top:4px;font-family:monospace;">
                                            [<?php echo esc_html($last_err['where'] ?? '?'); ?>] <?php echo esc_html($last_err['message'] ?? ''); ?>
                                        </div>
                                        <small style="color:#94a3b8;">Registrado em: <?php echo esc_html($last_err['time'] ?? '?'); ?></small>
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=geo-settings&geo_gsc_clear_error=1'), 'geo_gsc_clear_error')); ?>"
                                           class="button button-small" style="margin-left:8px;">Limpar erro</a>
                                    </div>
                                <?php endif;
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_gsc_client_id">Client ID OAuth</label></th>
                        <td><input type="text" id="geo_gsc_client_id" name="geo_gsc_client_id"
                               value="<?php echo esc_attr(get_option('geo_gsc_client_id', '')); ?>"
                               class="regular-text" placeholder="xxxx.apps.googleusercontent.com"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_gsc_client_secret">Client Secret OAuth</label></th>
                        <td><input type="password" id="geo_gsc_client_secret" name="geo_gsc_client_secret"
                               value="<?php echo esc_attr(get_option('geo_gsc_client_secret', '')); ?>"
                               class="regular-text" placeholder="GOCSPX-..."></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_gsc_property">Property GSC</label></th>
                        <td>
                            <input type="text" id="geo_gsc_property" name="geo_gsc_property"
                                   value="<?php echo esc_attr(get_option('geo_gsc_property', get_site_url() . '/')); ?>"
                                   class="regular-text" placeholder="https://seusite.com/">
                            <p class="description">URL com / no final, ou <code>sc-domain:seusite.com</code></p>
                        </td>
                    </tr>
                </table>

                <!-- v1.0.0 — Web Stories: AdSense + Analytics -->
                <h2 class="title" style="margin-top:32px;">📱 Web Stories — Monetização</h2>
                <p class="description">Configurações de AdSense e Analytics aplicadas em todas as Web Stories geradas.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="geo_auto_webstory">Geração Automática</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="geo_auto_webstory" name="geo_auto_webstory"
                                       value="1" <?php checked((bool) get_option('geo_auto_webstory', 0)); ?>>
                                <strong>Gerar Web Story automaticamente</strong> quando um artigo for publicado
                            </label>
                            <p class="description">
                                Quando ativado, cada artigo gerado pelo plugin cria uma Web Story automaticamente em segundo plano.<br>
                                <strong>Cadeia de imagens:</strong> Featured Fal.ai → Replicate; Corpo Replicate → Fal.ai; Web Stories Naga.ac → HuggingFace.<br>
                                ⚠️ Pode aumentar o tempo total de geração. Recomendado ativar apenas se Replicate ou Naga.ac estiver configurado.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_webstory_adsense_pub_id">AdSense Publisher ID</label></th>
                        <td>
                            <input type="text" id="geo_webstory_adsense_pub_id" name="geo_webstory_adsense_pub_id"
                                   value="<?php echo esc_attr(get_option('geo_webstory_adsense_pub_id', '')); ?>"
                                   class="regular-text" placeholder="pub-1234567890123456">
                            <p class="description">Necessário para anúncios nas Web Stories (Google exige mín. 8 slides)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_webstory_adsense_slot_id">AdSense Slot ID</label></th>
                        <td><input type="text" id="geo_webstory_adsense_slot_id" name="geo_webstory_adsense_slot_id"
                               value="<?php echo esc_attr(get_option('geo_webstory_adsense_slot_id', '')); ?>"
                               class="regular-text" placeholder="1234567890"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="geo_webstory_ga4_id">Google Analytics 4 ID</label></th>
                        <td><input type="text" id="geo_webstory_ga4_id" name="geo_webstory_ga4_id"
                               value="<?php echo esc_attr(get_option('geo_webstory_ga4_id', '')); ?>"
                               class="regular-text" placeholder="G-XXXXXXXXXX"></td>
                    </tr>
                </table>

                                <?php submit_button('Salvar Configuracoes', 'primary large'); ?>
            </form>

            <!-- Export / Import -->
            <hr style="margin:40px 0;">
            <h2 class="title">🔄 Exportar / Importar Configuracoes</h2>
            <p class="description">Transfira as configuracoes do plugin entre instalacoes WordPress. Por segurança, chaves/API keys são mascaradas no export padrão.</p>

            <div style="display:flex; gap:24px; flex-wrap:wrap; margin-top:16px;">
                <div>
                    <h3 style="font-size:14px;">Exportar</h3>
                    <p class="description">Gera um arquivo JSON com todas as configuracoes atuais.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="geo_export_settings">
                        <?php wp_nonce_field('geo_export_settings_nonce'); ?>
                        <label style="display:block;margin:8px 0;"><input type="checkbox" name="geo_export_include_secrets" value="1"> Incluir API keys e tokens no arquivo exportado</label>
                        <button type="submit" class="button button-secondary">📤 Exportar Configuracoes (JSON)</button>
                    </form>
                </div>
                <div>
                    <h3 style="font-size:14px;">Importar</h3>
                    <p class="description">Carrega configuracoes a partir de um JSON exportado.</p>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="geo_import_settings">
                        <?php wp_nonce_field('geo_import_settings_nonce'); ?>
                        <input type="file" name="geo_settings_file" accept=".json" style="margin-right:8px;">
                        <button type="submit" class="button button-secondary">📥 Importar Configuracoes</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * All option keys managed by this plugin.
     */
    private static function all_option_keys(): array {
        $keys = [
            'geo_openai_api_key', 'geo_groq_api_key', 'geo_gemini_api_key',
            'geo_claude_api_key', 'geo_perplexity_api_key', 'geo_replicate_api_key',
            'geo_naga_api_key', 'geo_naga_model', 'geo_naga_text_model', 'autopilot_naga_api_key',
            // Provider padrão global (novo) + compatibilidade com opção antiga
            'geo_default_provider', 'geo_default_model', 'geo_ai_provider',
            // Provider + modelo por gerador (novo sistema direto)
            'geo_individual_provider', 'geo_individual_model',
            'geo_bulk_provider',       'geo_bulk_model',
            'geo_cluster_provider',    'geo_cluster_model',
            'geo_youtube_provider',    'geo_youtube_model',
            'geo_sara_provider',       'geo_sara_model',
            'geo_manual_provider',     'geo_manual_model',
            'geo_glossary_provider',   'geo_glossary_model',
            'geo_refresher_provider',  'geo_refresher_model',
            'geo_default_language', 'geo_replicate_model',
            'geo_author_name', 'geo_author_photo', 'geo_author_specialty',
            'geo_author_experience', 'geo_author_certifications', 'geo_author_bio',
            'geo_author_editorial_page', 'geo_author_subdomains', 'geo_author_site_categories',
            'geo_notification_email',
            'geo_social_twitter', 'geo_social_linkedin', 'geo_social_instagram', 'geo_social_facebook',
            'geo_org_phone', 'geo_org_email', 'geo_org_slogan', 'geo_org_founded', 'geo_org_description',
            'geo_author_knowsabout', 'geo_author_awards', 'geo_author_credentials',
            'geo_use_mpc', 'geo_article_size', 'geo_default_tone', 'geo_body_images_count', 'geo_image_h2_interval', 'geo_featured_image_required', 'geo_embed_youtube_video',
            'geo_auto_rewrite', 'geo_rewrite_days', 'geo_rewrite_per_day',
            'geo_auto_webstory', 'geo_webstory_adsense_pub_id', 'geo_webstory_adsense_slot_id', 'geo_webstory_ga4_id',
            'geo_falai_api_key', 'geo_huggingface_api_key',
            'geo_unsplash_api_key', 'geo_pexels_api_key', 'geo_pixabay_api_key',
            'geo_youtube_api_key', 'geo_tts_provider', 'geo_tts_openai_voice', 'geo_tts_max_chars',
            'geo_elevenlabs_api_key', 'geo_gsc_client_id', 'geo_gsc_client_secret', 'geo_gsc_property', 'geo_gsc_token',
            'geo_zernio_api_key',
            'sara_indexnow_enabled', 'sara_indexnow_key', 'sara_indexnow_buffer',
            'sara_refresher_enabled', 'sara_niche', 'sara_active_categories',
            'geo_glossary_ai_provider', 'geo_glossary_ai_model', 'geo_glossary_naga_model', 'geo_glossary_titles_v1',
        ];
        foreach (array_keys(self::get_provider_models()) as $prov) {
            $keys[] = 'geo_model_' . $prov;
            $keys[] = 'geo_default_model_' . $prov; // novo sistema
        }

        // Exportar tudo que o próprio plugin gravou nas configurações.
        // Isso corrige export parcial: agora leva GEO, SARA, Autopilot, IndexNow,
        // Web Stories, GSC, Glossário, TitleBank e chaves salvas pelo plugin.
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            $like_prefixes = ['geo\_%', 'sara\_%', 'autopilot\_%'];
            foreach ($like_prefixes as $like) {
                $rows = $wpdb->get_col($wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like
                ));
                foreach ((array)$rows as $row) {
                    $keys[] = (string)$row;
                }
            }
        }
        $keys = array_values(array_unique(array_filter($keys)));
        sort($keys);
        return $keys;
    }

    private static function is_secret_option(string $key): bool {
        return (bool) preg_match('/(_api_key|api_key|secret|token|license|licence|client_secret|password|passwd|access_key|refresh_token)/i', $key);
    }

    private static function sanitize_import_value($value) {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $k => $v) {
                $clean[sanitize_key((string) $k)] = self::sanitize_import_value($v);
            }
            return $clean;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        return sanitize_text_field((string) $value);
    }

    public static function handle_export() {
        check_admin_referer('geo_export_settings_nonce');
        if (!current_user_can('manage_options')) wp_die('Sem permissao.');
        $include_secrets = !empty($_POST['geo_export_include_secrets']);
        $config = [
            '_geo_export_meta' => [
                'plugin' => 'GEO Metodo SEO',
                'version' => defined('GEO_METODO_SEO_VERSION') ? GEO_METODO_SEO_VERSION : '',
                'site_url' => home_url('/'),
                'exported_at' => current_time('mysql'),
                'contains_secrets' => $include_secrets,
            ],
            'options' => [],
        ];
        foreach (self::all_option_keys() as $key) {
            $value = get_option($key, null);
            if ($value !== null) {
                if (!$include_secrets && self::is_secret_option((string) $key) && $value !== '') {
                    $config['options'][$key] = '__GEO_SECRET_MASKED__';
                } else {
                    $config['options'][$key] = $value;
                }
            }
        }

        $json     = wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = 'geo-metodo-seo-config-' . wp_date('Y-m-d') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    public static function handle_import() {
        check_admin_referer('geo_import_settings_nonce');
        if (!current_user_can('manage_options')) wp_die('Sem permissao.');
        $redirect = admin_url('admin.php?page=geo-settings');

        if (empty($_FILES['geo_settings_file']['tmp_name'])) {
            wp_redirect(add_query_arg('geo_import', 'no_file', $redirect));
            exit;
        }

        $file = $_FILES['geo_settings_file'];
        if (!empty($file['size']) && (int) $file['size'] > 1048576) {
            wp_redirect(add_query_arg('geo_import', 'too_large', $redirect));
            exit;
        }
        $content = file_get_contents($file['tmp_name']);
        if (!is_string($content) || trim($content) === '') {
            wp_redirect(add_query_arg('geo_import', 'invalid', $redirect));
            exit;
        }
        $config  = json_decode($content, true);

        if (!is_array($config) || array_values($config) === $config) {
            wp_redirect(add_query_arg('geo_import', 'invalid', $redirect));
            exit;
        }

        $payload = isset($config['options']) && is_array($config['options']) ? $config['options'] : $config;
        unset($payload['_geo_export_meta']);

        $allowed = self::all_option_keys();
        $imported = 0;
        foreach ($payload as $key => $value) {
            if (in_array($key, $allowed, true)) {
                if ($value === '__GEO_SECRET_MASKED__') {
                    continue;
                }
                update_option($key, self::sanitize_import_value($value), false);
                $imported++;
            }
        }
        if (get_option('geo_naga_api_key', '') !== '') {
            update_option('autopilot_naga_api_key', get_option('geo_naga_api_key', ''), false);
        }

        wp_redirect(add_query_arg(['geo_import' => 'ok', 'geo_count' => $imported], $redirect));
        exit;
    }

    private function save() {
        // Checkbox options (devem ser salvas explicitamente com 0 quando desmarcadas)
        update_option('geo_use_mpc',          isset($_POST['geo_use_mpc'])          ? 1 : 0);
        update_option('geo_use_legacy_image', 0);
        update_option('geo_auto_rewrite',     isset($_POST['geo_auto_rewrite'])     ? 1 : 0);
        update_option('geo_auto_webstory',    isset($_POST['geo_auto_webstory'])    ? 1 : 0);
        update_option('geo_featured_image_required', isset($_POST['geo_featured_image_required']) ? 1 : 0);
        update_option('geo_embed_youtube_video', isset($_POST['geo_embed_youtube_video']) ? '1' : '0');

        // ── Fonte de imagens: IA ou Biblioteca ────────────────────────────
        $img_source = in_array($_POST['geo_image_source'] ?? 'ai', ['ai', 'library'], true)
            ? sanitize_key($_POST['geo_image_source'])
            : 'ai';
        update_option('geo_image_source', $img_source);

        // Salvar ranges Min/Max (sempre — para preservar configurações mesmo alternando entre modos)
        $slugs_to_save = ['global'];
        $cats_to_save  = get_categories(['hide_empty' => false, 'number' => 200]);
        foreach ($cats_to_save as $c) $slugs_to_save[] = sanitize_key($c->slug);

        foreach ($slugs_to_save as $slug) {
            foreach (['featured', 'body'] as $type) {
                $min_key  = 'geo_lib_' . $type . '_min_' . $slug;
                $max_key  = 'geo_lib_' . $type . '_max_' . $slug;
                $ptr_key  = 'geo_lib_' . $type . '_ptr_' . $slug;

                $old_min  = (int) get_option($min_key, 0);
                $old_max  = (int) get_option($max_key, 0);
                $new_min  = absint($_POST[$min_key] ?? 0);
                $new_max  = absint($_POST[$max_key] ?? 0);

                // Validar: max deve ser >= min
                if ($new_max > 0 && $new_min > 0 && $new_max < $new_min) {
                    $new_max = $new_min; // Corrigir silenciosamente
                }

                update_option($min_key, $new_min, false);
                update_option($max_key, $new_max, false);

                // Resetar ponteiro e limpar cache se o range mudou
                if ($old_min !== $new_min || $old_max !== $new_max) {
                    update_option($ptr_key, 0, false);
                    // Limpar cache de ambos os ranges (antigo e novo)
                    if ($old_min > 0 && $old_max > 0) {
                        delete_transient('geo_lib_range_' . $old_min . '_' . $old_max);
                    }
                    if ($new_min > 0 && $new_max > 0) {
                        delete_transient('geo_lib_range_' . $new_min . '_' . $new_max);
                    }
                }
            }
        }

        if (isset($_POST['geo_body_images_count'])) {
            $body_images_count = absint($_POST['geo_body_images_count']);
            update_option('geo_body_images_count', in_array($body_images_count, [2, 3, 4, 5], true) ? $body_images_count : 3);
        }
        if (isset($_POST['geo_image_h2_interval'])) {
            $h2_interval = absint($_POST['geo_image_h2_interval']);
            update_option('geo_image_h2_interval', in_array($h2_interval, [3, 4], true) ? $h2_interval : 3);
        }

        if (isset($_POST['geo_rewrite_days'])) {
            update_option('geo_rewrite_days', max(30, intval($_POST['geo_rewrite_days'])));
        }
        if (isset($_POST['geo_rewrite_per_day'])) {
            update_option('geo_rewrite_per_day', max(1, intval($_POST['geo_rewrite_per_day'])));
        }

        $text_keys = [
            'openai_api_key', 'groq_api_key', 'gemini_api_key',
            'claude_api_key', 'perplexity_api_key', 'replicate_api_key',
            'naga_api_key', 'naga_model', 'naga_text_model',
            'ai_provider', 'default_language', 'replicate_model',
            'author_name', 'author_specialty', 'author_experience', 'author_certifications',
            'default_tone',
            // v1.0.0 novos campos
            'falai_api_key', 'huggingface_api_key', 'body_images_count', 'image_h2_interval', 'featured_image_required',
            'unsplash_api_key', 'pexels_api_key', 'pixabay_api_key',
            'youtube_api_key', 'tts_provider', 'tts_openai_voice', 'tts_max_chars',
            'elevenlabs_api_key', 'gsc_client_id', 'gsc_client_secret', 'gsc_property',
            'auto_webstory', 'webstory_adsense_pub_id', 'webstory_adsense_slot_id', 'webstory_ga4_id',
            'zernio_api_key',
            // ⚠️ CRÍTICO: Provider + Modelo por Gerador. Sem estas chaves aqui, a tela
            // "Provider e Modelo — por Gerador" mostrava os selects mas NUNCA salvava
            // a escolha — por isso o SARA caía sempre no Claude e a seleção de OpenAI
            // era ignorada. Agora cada gerador salva e respeita seu provider/modelo.
            'default_provider', 'default_model',
            'individual_provider', 'individual_model',
            'bulk_provider', 'bulk_model',
            'cluster_provider', 'cluster_model',
            'youtube_provider', 'youtube_model',
            'sara_provider', 'sara_model',
            'manual_provider', 'manual_model',
            'glossary_provider', 'glossary_model',
            'refresher_provider', 'refresher_model',
        ];

        // Per-provider default model keys (geo_default_model_{provider})
        foreach (array_keys(self::get_provider_models()) as $prov) {
            $text_keys[] = 'default_model_' . $prov;
        }

        // Per-provider model keys
        foreach (array_keys(self::get_provider_models()) as $prov) {
            $text_keys[] = 'model_' . $prov;
        }

        foreach ($text_keys as $key) {
            if (isset($_POST['geo_' . $key])) {
                update_option('geo_' . $key, sanitize_text_field($_POST['geo_' . $key]), false);
            }
        }
        if (isset($_POST['geo_naga_api_key'])) {
            update_option('autopilot_naga_api_key', sanitize_text_field($_POST['geo_naga_api_key']), false);
        }

        if (isset($_POST['geo_author_bio'])) {
            update_option('geo_author_bio', sanitize_textarea_field($_POST['geo_author_bio']));
        }

        if (isset($_POST['geo_author_subdomains'])) {
            update_option('geo_author_subdomains', sanitize_textarea_field($_POST['geo_author_subdomains']));
        }

        if (isset($_POST['geo_author_site_categories'])) {
            update_option('geo_author_site_categories', sanitize_textarea_field($_POST['geo_author_site_categories']));
        }

        if (isset($_POST['geo_notification_email'])) {
            update_option('geo_notification_email', sanitize_email($_POST['geo_notification_email']));
        }

        $url_keys = array_merge(
            array_keys($this->social_fields),
            ['author_photo', 'author_editorial_page']
        );

        foreach ($url_keys as $key) {
            if (isset($_POST['geo_' . $key])) {
                update_option('geo_' . $key, esc_url_raw($_POST['geo_' . $key]));
            }
        }

        // Ao salvar, limpar pausas de providers. Se o usuário trocou de provider
        // (ex: Claude sem créditos → OpenAI), a pausa antiga não deve mais bloquear.
        // Cada provider é independente; a troca tem efeito imediato.
        if (class_exists('\\GeoMetodoSEO\\AI\\AIManager')) {
            \GeoMetodoSEO\AI\AIManager::clear_provider_pauses();
        }
    }
}



