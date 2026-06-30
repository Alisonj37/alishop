<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Onboarding Wizard — assistente de configuração inicial.
 *
 * Aparece automaticamente após primeira ativação até o usuário
 * configurar pelo menos 1 provider de texto + 1 provider de imagem (mínimo viável pra começar).
 *
 * Fluxo:
 *  1. Boas-vindas (apresenta o plugin)
 *  2. Configurar Provider de texto
 *  3. Configurar Provider de imagem
 *  4. Configuração de qualidade (modo conservador/standard)
 *  5. Testar e finalizar
 *
 * @since 1.0.0
 */
class OnboardingController {

    const OPT_COMPLETED = 'geo_onboarding_completed';
    const OPT_STARTED   = 'geo_onboarding_started_at';
    const OPT_STEP      = 'geo_onboarding_current_step';

    /**
     * Hooks de inicialização.
     */
    public static function register_hooks(): void {
        // Adiciona página oculta (acessível só via URL direta)
        add_action('admin_menu', [__CLASS__, 'register_page'], 99);

        // Redireciona pra wizard após ativação
        add_action('admin_init', [__CLASS__, 'maybe_redirect_to_wizard']);

        // Admin notice se onboarding não foi completado
        add_action('admin_notices', [__CLASS__, 'maybe_show_notice']);

        // AJAX handlers
        add_action('wp_ajax_geo_onboarding_save_step', [__CLASS__, 'ajax_save_step']);
        add_action('wp_ajax_geo_onboarding_test_provider', [__CLASS__, 'ajax_test_provider']);
        add_action('wp_ajax_geo_onboarding_complete', [__CLASS__, 'ajax_complete']);
        add_action('wp_ajax_geo_onboarding_dismiss', [__CLASS__, 'ajax_dismiss']);
    }

    /**
     * Verifica se onboarding já foi completado.
     */
    public static function is_completed(): bool {
        return get_option(self::OPT_COMPLETED, '0') === '1';
    }

    /**
     * Marca como completado.
     */
    public static function mark_completed(): void {
        update_option(self::OPT_COMPLETED, '1', false);
    }

    /**
     * Registra a página do wizard (oculta do menu principal).
     */
    public static function register_page(): void {
        add_submenu_page(
            null, // sem parent = oculto do menu
            'Configuração Inicial — GEO Método SEO',
            '🚀 Configuração Inicial',
            'manage_options',
            'geo-onboarding',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Redireciona pra wizard após primeira ativação.
     */
    public static function maybe_redirect_to_wizard(): void {
        if (!get_transient('geo_activation_redirect')) return;
        delete_transient('geo_activation_redirect');

        if (self::is_completed()) return;
        if (wp_doing_ajax()) return;
        if (isset($_GET['activate-multi'])) return;

        wp_safe_redirect(admin_url('admin.php?page=geo-onboarding'));
        exit;
    }

    /**
     * Aviso persistente até completar onboarding.
     */
    public static function maybe_show_notice(): void {
        if (!current_user_can('manage_options')) return;
        if (self::is_completed()) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos((string)$screen->id, 'geo-onboarding') !== false) return;

        $url = esc_url(admin_url('admin.php?page=geo-onboarding'));
        echo '<div class="notice notice-info" style="border-left-color:#2271b1;padding:12px 16px;">';
        echo '<h3 style="margin:0 0 8px;">👋 Bem-vindo ao GEO Método SEO!</h3>';
        echo '<p style="margin:0 0 8px;">Configure as APIs essenciais em 5 minutos pra começar a gerar artigos profissionais.</p>';
        echo '<p style="margin:0;">';
        echo '<a href="' . $url . '" class="button button-primary">🚀 Iniciar Configuração</a> ';
        echo '<a href="#" class="button button-link" id="geo-onboarding-dismiss">Dispensar (configurar depois)</a>';
        echo '</p>';
        echo '</div>';

        // Script pra dismiss
        $nonce = wp_create_nonce('geo_onboarding');
        ?>
        <script>
        document.getElementById('geo-onboarding-dismiss')?.addEventListener('click', function(e) {
            e.preventDefault();
            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=geo_onboarding_dismiss&nonce=<?php echo esc_js($nonce); ?>'
            }).then(() => this.closest('.notice').remove());
        });
        </script>
        <?php
    }

    /**
     * Renderiza o wizard.
     */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Permissão negada');
        }

        $nonce = wp_create_nonce('geo_onboarding');
        $step = (int) get_option(self::OPT_STEP, 1);

        // Estado atual das APIs
        $openai_set     = !empty(get_option('geo_openai_api_key', ''));
        $falai_set      = !empty(get_option('geo_falai_api_key', ''));
        $replicate_set  = !empty(get_option('geo_replicate_api_key', ''));
        $groq_set       = !empty(get_option('geo_groq_api_key', ''));
        $claude_set     = !empty(get_option('geo_claude_api_key', ''));

        ?>
        <div class="wrap" id="geo-onboarding-wrap">
            <style>
                #geo-onboarding-wrap { max-width: 900px; margin: 24px auto; }
                .geo-ob-card {
                    background: #fff;
                    border: 1px solid #dcdcde;
                    border-radius: 12px;
                    padding: 32px;
                    margin: 20px 0;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
                }
                .geo-ob-steps {
                    display: flex;
                    gap: 8px;
                    margin: 24px 0;
                    overflow-x: auto;
                }
                .geo-ob-step {
                    flex: 1;
                    min-width: 140px;
                    padding: 12px 16px;
                    border-radius: 8px;
                    background: #f0f0f1;
                    text-align: center;
                    font-size: 13px;
                    border-bottom: 3px solid transparent;
                    transition: all 0.2s;
                }
                .geo-ob-step.active {
                    background: #e0eaf5;
                    border-bottom-color: #2271b1;
                    font-weight: 600;
                }
                .geo-ob-step.done {
                    background: #edfaef;
                    border-bottom-color: #46b450;
                }
                .geo-ob-step .num {
                    display: inline-block;
                    width: 24px;
                    height: 24px;
                    line-height: 24px;
                    border-radius: 50%;
                    background: #fff;
                    margin-right: 6px;
                    font-weight: 700;
                }
                .geo-ob-step.done .num { background: #46b450; color: #fff; }
                .geo-ob-step.active .num { background: #2271b1; color: #fff; }
                .geo-ob-input {
                    width: 100%;
                    padding: 12px 16px;
                    border: 2px solid #dcdcde;
                    border-radius: 8px;
                    font-size: 14px;
                    font-family: monospace;
                    transition: border-color 0.2s;
                }
                .geo-ob-input:focus { border-color: #2271b1; outline: none; }
                .geo-ob-btn {
                    padding: 12px 24px;
                    border-radius: 8px;
                    border: none;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 600;
                    transition: all 0.2s;
                }
                .geo-ob-btn-primary {
                    background: #2271b1;
                    color: #fff;
                }
                .geo-ob-btn-primary:hover { background: #135e96; }
                .geo-ob-btn-secondary {
                    background: #f0f0f1;
                    color: #2c3338;
                }
                .geo-ob-btn-success {
                    background: #46b450;
                    color: #fff;
                }
                .geo-ob-status {
                    display: inline-block;
                    padding: 4px 10px;
                    border-radius: 12px;
                    font-size: 12px;
                    font-weight: 600;
                    margin-left: 8px;
                }
                .geo-ob-status.ok { background: #edfaef; color: #00794d; }
                .geo-ob-status.warn { background: #fef3cd; color: #856404; }
                .geo-ob-status.err { background: #fef2f2; color: #c0392b; }
                .geo-ob-feature-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 12px;
                    margin: 16px 0;
                }
                .geo-ob-feature {
                    padding: 12px;
                    background: #f6f7f7;
                    border-radius: 8px;
                    text-align: center;
                    font-size: 13px;
                }
                .geo-ob-help {
                    background: #f0f9ff;
                    border-left: 4px solid #0284c7;
                    padding: 14px 18px;
                    border-radius: 0 8px 8px 0;
                    margin: 16px 0;
                    font-size: 14px;
                }
                .geo-ob-help a { color: #0284c7; text-decoration: underline; }
            </style>

            <h1 style="font-size: 28px; margin-bottom: 8px;">🚀 Configuração Inicial — GEO Método SEO</h1>
            <p style="font-size: 16px; color: #50575e;">
                Vamos configurar tudo em 5 minutos. Você só precisa de <strong>2 chaves de API essenciais</strong>
                pra começar a gerar artigos profissionais.
            </p>

            <!-- Steps indicator -->
            <div class="geo-ob-steps">
                <div class="geo-ob-step <?php echo $step==1?'active':($step>1?'done':''); ?>" data-step="1">
                    <span class="num"><?php echo $step>1?'✓':'1'; ?></span> Boas-vindas
                </div>
                <div class="geo-ob-step <?php echo $step==2?'active':($step>2?'done':''); ?>" data-step="2">
                    <span class="num"><?php echo $step>2?'✓':'2'; ?></span> IA de texto
                </div>
                <div class="geo-ob-step <?php echo $step==3?'active':($step>3?'done':''); ?>" data-step="3">
                    <span class="num"><?php echo $step>3?'✓':'3'; ?></span> Fal.ai (imagem)
                </div>
                <div class="geo-ob-step <?php echo $step==4?'active':($step>4?'done':''); ?>" data-step="4">
                    <span class="num"><?php echo $step>4?'✓':'4'; ?></span> Qualidade
                </div>
                <div class="geo-ob-step <?php echo $step==5?'active':($step>5?'done':''); ?>" data-step="5">
                    <span class="num"><?php echo $step>5?'✓':'5'; ?></span> Finalizar
                </div>
            </div>

            <!-- STEP 1: Boas-vindas -->
            <div class="geo-ob-card" id="step-1" style="<?php echo $step==1?'':'display:none'; ?>">
                <h2 style="margin-top:0;">👋 Bem-vindo, <?php echo esc_html(wp_get_current_user()->display_name); ?>!</h2>
                <p style="font-size: 16px; line-height: 1.6;">
                    O <strong>GEO Método SEO</strong> é uma plataforma editorial profissional com IA pra WordPress
                    que automatiza geração de conteúdo otimizado pra <strong>SEO + GEO + AEO + LLMs</strong>.
                </p>

                <h3 style="margin-top: 24px;">✨ O que você vai conseguir fazer:</h3>
                <div class="geo-ob-feature-grid">
                    <div class="geo-ob-feature">
                        <div style="font-size: 28px;">🤖</div>
                        <strong>SARA Autopilot</strong><br>
                        Gera artigos sozinha
                    </div>
                    <div class="geo-ob-feature">
                        <div style="font-size: 28px;">📝</div>
                        <strong>9 fluxos</strong><br>
                        Individual, massa, cluster
                    </div>
                    <div class="geo-ob-feature">
                        <div style="font-size: 28px;">🎨</div>
                        <strong>8 providers</strong><br>
                        de imagem com fallback
                    </div>
                    <div class="geo-ob-feature">
                        <div style="font-size: 28px;">🎓</div>
                        <strong>E-E-A-T</strong><br>
                        Schemas completos
                    </div>
                    <div class="geo-ob-feature">
                        <div style="font-size: 28px;">📊</div>
                        <strong>GEO Score</strong><br>
                        Otimização pra LLMs
                    </div>
                    <div class="geo-ob-feature">
                        <div style="font-size: 28px;">🛡️</div>
                        <strong>Quality Guard</strong><br>
                        Google March 2026
                    </div>
                </div>

                <div class="geo-ob-help">
                    💡 <strong>Tempo total de configuração:</strong> ~5 minutos<br>
                    Você vai configurar 2 APIs essenciais e ajustar 1 setting de qualidade.
                </div>

                <div style="text-align: right; margin-top: 24px;">
                    <button class="geo-ob-btn geo-ob-btn-primary" data-next="2">
                        Começar Configuração →
                    </button>
                </div>
            </div>

            <!-- STEP 2: OpenAI -->
            <div class="geo-ob-card" id="step-2" style="<?php echo $step==2?'':'display:none'; ?>">
                <h2 style="margin-top:0;">🧠 Configurar IA de texto</h2>
                <p>
                    Configure pelo menos um provedor de texto para <strong>gerar artigos de qualidade</strong>. Você pode usar OpenAI, Groq, Gemini, Claude, Perplexity ou Naga.ac.
                    Plugin usa GPT-4.1 como default.
                </p>

                <div class="geo-ob-help">
                    📋 <strong>Como pegar sua API key:</strong>
                    <ol style="margin: 8px 0 0 20px;">
                        <li>Acesse <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a></li>
                        <li>Clique em <strong>"Create new secret key"</strong></li>
                        <li>Copie a chave (começa com <code>sk-</code>)</li>
                        <li>Cole abaixo</li>
                    </ol>
                    💰 <strong>Custo estimado:</strong> $0.01/artigo (~$3/mês para 300 artigos)
                </div>

                <h3>API Key do provedor de texto:</h3>
                <input type="password" id="ob-openai-key"
                       class="geo-ob-input"
                       value="<?php echo esc_attr(get_option('geo_openai_api_key', '')); ?>"
                       placeholder="sk-proj-xxxxxxxxxxxxxxxx">
                <div id="ob-openai-result" style="margin-top: 12px;"></div>

                <div style="display: flex; justify-content: space-between; margin-top: 24px;">
                    <button class="geo-ob-btn geo-ob-btn-secondary" data-prev="1">← Voltar</button>
                    <div>
                        <button class="geo-ob-btn geo-ob-btn-secondary" id="ob-test-openai">🧪 Testar conexão</button>
                        <button class="geo-ob-btn geo-ob-btn-primary" id="ob-save-openai" data-next="3">
                            Salvar e Continuar →
                        </button>
                    </div>
                </div>
            </div>

            <!-- STEP 3: Fal.ai -->
            <div class="geo-ob-card" id="step-3" style="<?php echo $step==3?'':'display:none'; ?>">
                <h2 style="margin-top:0;">🎨 Configurar Fal.ai (geração de imagens)</h2>
                <p>
                    Fal.ai é o provedor principal pra <strong>imagens featured de alta qualidade</strong>.
                    Plugin usa Flux-2-Pro como default (2-4 segundos por imagem).
                </p>

                <div class="geo-ob-help">
                    📋 <strong>Como pegar sua API key:</strong>
                    <ol style="margin: 8px 0 0 20px;">
                        <li>Acesse <a href="https://fal.ai/dashboard/keys" target="_blank">fal.ai/dashboard/keys</a></li>
                        <li>Clique em <strong>"Create New Key"</strong></li>
                        <li>Dê um nome (ex: "WordPress")</li>
                        <li>Copie a chave gerada</li>
                        <li>Adicione $5-10 de crédito em fal.ai/dashboard/billing</li>
                    </ol>
                    💰 <strong>Custo estimado:</strong> $0.04 featured + $0.015 body images (~$15/mês para 300 artigos)
                </div>

                <h3>API Key do Fal.ai:</h3>
                <input type="password" id="ob-falai-key"
                       class="geo-ob-input"
                       value="<?php echo esc_attr(get_option('geo_falai_api_key', '')); ?>"
                       placeholder="fal-xxxxxxxxxxxxxxxx">
                <div id="ob-falai-result" style="margin-top: 12px;"></div>

                <details style="margin-top: 24px;">
                    <summary style="cursor: pointer; font-weight: 600;">🔄 Quer adicionar fallbacks? (opcional, recomendado)</summary>
                    <div style="padding: 16px; background: #f6f7f7; border-radius: 8px; margin-top: 12px;">
                        <p style="font-size: 13px;">
                            <strong>Replicate</strong> é fallback de Fal.ai. Se Fal cair, Replicate assume.
                        </p>
                        <p>
                            <strong>API Key do Replicate:</strong><br>
                            <small>Pegar em <a href="https://replicate.com/account/api-tokens" target="_blank">replicate.com/account/api-tokens</a></small>
                        </p>
                        <input type="password" id="ob-replicate-key"
                               class="geo-ob-input"
                               value="<?php echo esc_attr(get_option('geo_replicate_api_key', '')); ?>"
                               placeholder="r8_xxxxxxxxxxxxxxxx"
                               style="margin-top: 8px;">
                    </div>
                </details>

                <div style="display: flex; justify-content: space-between; margin-top: 24px;">
                    <button class="geo-ob-btn geo-ob-btn-secondary" data-prev="2">← Voltar</button>
                    <div>
                        <button class="geo-ob-btn geo-ob-btn-secondary" id="ob-test-falai">🧪 Testar conexão</button>
                        <button class="geo-ob-btn geo-ob-btn-primary" id="ob-save-falai" data-next="4">
                            Salvar e Continuar →
                        </button>
                    </div>
                </div>
            </div>

            <!-- STEP 4: Qualidade -->
            <div class="geo-ob-card" id="step-4" style="<?php echo $step==4?'':'display:none'; ?>">
                <h2 style="margin-top:0;">🎯 Configuração de Qualidade</h2>
                <p>
                    Defina como o plugin deve gerar artigos. <strong>Você pode mudar isso depois</strong> nas configurações.
                </p>

                <h3>Modo de operação:</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px;">
                    <label style="display: block; padding: 20px; border: 2px solid #dcdcde; border-radius: 8px; cursor: pointer;">
                        <input type="radio" name="ob_mode" value="conservador" checked>
                        <strong style="display: block; font-size: 16px; margin: 8px 0;">🛡️ Conservador (recomendado)</strong>
                        <small style="color: #666;">
                            • 2.500 palavras alvo<br>
                            • 3 imagens no corpo<br>
                            • Custo: ~$0.05/artigo<br>
                            • Ideal pra começar
                        </small>
                    </label>
                    <label style="display: block; padding: 20px; border: 2px solid #dcdcde; border-radius: 8px; cursor: pointer;">
                        <input type="radio" name="ob_mode" value="standard">
                        <strong style="display: block; font-size: 16px; margin: 8px 0;">🚀 Standard</strong>
                        <small style="color: #666;">
                            • 3.000 palavras alvo<br>
                            • 4 imagens no corpo<br>
                            • Custo: ~$0.07/artigo<br>
                            • Mais profundo
                        </small>
                    </label>
                </div>

                <h3 style="margin-top: 24px;">Configurações adicionais:</h3>
                <label style="display: block; margin: 12px 0;">
                    <input type="checkbox" id="ob-auto-expand" checked>
                    <strong>Auto-Expand</strong> — expande artigos curtos automaticamente (+$0.005)
                </label>
                <label style="display: block; margin: 12px 0;">
                    <input type="checkbox" id="ob-featured-required" checked>
                    <strong>Bloquear publicação</strong> se featured image falhar
                </label>
                <label style="display: block; margin: 12px 0;">
                    <input type="checkbox" id="ob-quality-gate-ai">
                    <strong>Quality Gate IA</strong> — usa IA pra validar qualidade (+$0.001, mais rigoroso)
                </label>

                <div style="display: flex; justify-content: space-between; margin-top: 24px;">
                    <button class="geo-ob-btn geo-ob-btn-secondary" data-prev="3">← Voltar</button>
                    <button class="geo-ob-btn geo-ob-btn-primary" id="ob-save-quality" data-next="5">
                        Salvar e Continuar →
                    </button>
                </div>
            </div>

            <!-- STEP 5: Finalizar -->
            <div class="geo-ob-card" id="step-5" style="<?php echo $step==5?'':'display:none'; ?>">
                <h2 style="margin-top:0;">🎉 Tudo pronto!</h2>
                <p style="font-size: 16px;">
                    Suas configurações foram salvas com sucesso. Agora você pode gerar seu primeiro artigo!
                </p>

                <h3 style="margin-top: 24px;">📋 Resumo da configuração:</h3>
                <table class="widefat striped" style="margin-top: 12px;">
                    <tbody id="ob-summary">
                        <!-- preenchido via JS -->
                    </tbody>
                </table>

                <div class="geo-ob-help" style="margin-top: 24px;">
                    💡 <strong>Próximos passos sugeridos:</strong>
                    <ol style="margin: 8px 0 0 20px;">
                        <li>Gerar seu <strong>primeiro artigo individual</strong> pra ver tudo funcionando</li>
                        <li>Configurar o <strong>SARA Autopilot</strong> pra rodar automaticamente</li>
                        <li>Adicionar <strong>fallbacks extras</strong> (Groq, Claude) pra economia</li>
                    </ol>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=geo-individual')); ?>"
                       class="geo-ob-btn geo-ob-btn-primary" id="ob-finish-and-generate">
                        🚀 Gerar primeiro artigo
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=geo-metodo-seo')); ?>"
                       class="geo-ob-btn geo-ob-btn-success" id="ob-finish-dashboard">
                        🏠 Ir pro Dashboard
                    </a>
                    <button class="geo-ob-btn geo-ob-btn-secondary" id="ob-finish-settings">
                        ⚙️ Mais configurações avançadas
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function($) {
            const nonce = '<?php echo esc_js($nonce); ?>';

            function showStep(step) {
                $('.geo-ob-card').hide();
                $('#step-' + step).fadeIn(200);

                $('.geo-ob-step').each(function() {
                    const s = parseInt($(this).data('step'));
                    $(this).removeClass('active done');
                    if (s < step) $(this).addClass('done');
                    if (s === step) $(this).addClass('active');
                });

                $.post(ajaxurl, {
                    action: 'geo_onboarding_save_step',
                    nonce: nonce,
                    step: step
                });

                window.scrollTo({top: 0, behavior: 'smooth'});
            }

            $(document).on('click', '[data-next]', function(e) {
                if (this.tagName === 'A') return; // links normais
                e.preventDefault();
                const next = $(this).data('next');
                showStep(next);
            });

            $(document).on('click', '[data-prev]', function(e) {
                e.preventDefault();
                showStep($(this).data('prev'));
            });

            // Testar OpenAI
            $('#ob-test-openai').on('click', function() {
                const $btn = $(this);
                const key = $('#ob-openai-key').val().trim();
                if (!key) {
                    $('#ob-openai-result').html('<span class="geo-ob-status err">❌ Cole a chave primeiro</span>');
                    return;
                }
                $btn.prop('disabled', true).text('Testando...');
                $('#ob-openai-result').html('<span class="geo-ob-status warn">⏳ Testando...</span>');

                $.post(ajaxurl, {
                    action: 'geo_onboarding_test_provider',
                    nonce: nonce,
                    provider: 'openai',
                    api_key: key
                }).done(function(res) {
                    if (res.success) {
                        $('#ob-openai-result').html('<span class="geo-ob-status ok">✅ ' + res.data.message + '</span>');
                    } else {
                        $('#ob-openai-result').html('<span class="geo-ob-status err">❌ ' + res.data.message + '</span>');
                    }
                }).always(function() {
                    $btn.prop('disabled', false).text('🧪 Testar conexão');
                });
            });

            // Salvar OpenAI
            $('#ob-save-openai').on('click', function(e) {
                e.preventDefault();
                const key = $('#ob-openai-key').val().trim();
                if (!key) {
                    $('#ob-openai-result').html('<span class="geo-ob-status err">❌ Cole sua API key antes de continuar</span>');
                    return;
                }
                $.post(ajaxurl, {
                    action: 'geo_onboarding_save_step',
                    nonce: nonce,
                    step: 3,
                    save_keys: { openai: key }
                }).done(function() {
                    showStep(3);
                });
            });

            // Testar Fal.ai
            $('#ob-test-falai').on('click', function() {
                const $btn = $(this);
                const key = $('#ob-falai-key').val().trim();
                if (!key) {
                    $('#ob-falai-result').html('<span class="geo-ob-status err">❌ Cole a chave primeiro</span>');
                    return;
                }
                $btn.prop('disabled', true).text('Testando...');
                $('#ob-falai-result').html('<span class="geo-ob-status warn">⏳ Testando (pode levar 10-15s)...</span>');

                $.post(ajaxurl, {
                    action: 'geo_onboarding_test_provider',
                    nonce: nonce,
                    provider: 'falai',
                    api_key: key
                }).done(function(res) {
                    if (res.success) {
                        $('#ob-falai-result').html('<span class="geo-ob-status ok">✅ ' + res.data.message + '</span>');
                    } else {
                        $('#ob-falai-result').html('<span class="geo-ob-status err">❌ ' + res.data.message + '</span>');
                    }
                }).always(function() {
                    $btn.prop('disabled', false).text('🧪 Testar conexão');
                });
            });

            // Salvar Fal.ai (+ replicate opcional)
            $('#ob-save-falai').on('click', function(e) {
                e.preventDefault();
                const falai_key = $('#ob-falai-key').val().trim();
                const replicate_key = $('#ob-replicate-key').val().trim();
                if (!falai_key) {
                    $('#ob-falai-result').html('<span class="geo-ob-status err">❌ Cole sua API key do Fal.ai antes de continuar</span>');
                    return;
                }
                const keys = { falai: falai_key };
                if (replicate_key) keys.replicate = replicate_key;
                $.post(ajaxurl, {
                    action: 'geo_onboarding_save_step',
                    nonce: nonce,
                    step: 4,
                    save_keys: keys
                }).done(function() {
                    showStep(4);
                });
            });

            // Salvar Qualidade
            $('#ob-save-quality').on('click', function(e) {
                e.preventDefault();
                const mode = $('input[name="ob_mode"]:checked').val();
                const settings = {
                    site_mode: mode,
                    auto_expand: $('#ob-auto-expand').prop('checked') ? '1' : '0',
                    featured_required: $('#ob-featured-required').prop('checked') ? '1' : '0',
                    quality_gate_ai: $('#ob-quality-gate-ai').prop('checked') ? '1' : '0',
                };
                $.post(ajaxurl, {
                    action: 'geo_onboarding_save_step',
                    nonce: nonce,
                    step: 5,
                    save_settings: settings
                }).done(function() {
                    // Preenche resumo
                    let html = '';
                    html += '<tr><th>IA de texto</th><td>' + (<?php echo $openai_set?'true':'false'; ?> || $('#ob-openai-key').val() ? '✅ Configurado' : '❌ Não configurado') + '</td></tr>';
                    html += '<tr><th>Fal.ai</th><td>' + (<?php echo $falai_set?'true':'false'; ?> || $('#ob-falai-key').val() ? '✅ Configurado' : '❌ Não configurado') + '</td></tr>';
                    html += '<tr><th>Replicate (fallback)</th><td>' + ($('#ob-replicate-key').val() ? '✅ Configurado' : '⚠️ Opcional, não configurado') + '</td></tr>';
                    html += '<tr><th>Modo de operação</th><td><strong>' + (mode === 'conservador' ? '🛡️ Conservador (2500 palavras, 3 imgs)' : '🚀 Standard (3000 palavras, 4 imgs)') + '</strong></td></tr>';
                    html += '<tr><th>Auto-Expand</th><td>' + (settings.auto_expand === '1' ? '✅ Ativo' : '❌ Desativo') + '</td></tr>';
                    html += '<tr><th>Featured obrigatória</th><td>' + (settings.featured_required === '1' ? '✅ Ativo' : '❌ Desativo') + '</td></tr>';
                    html += '<tr><th>Quality Gate IA</th><td>' + (settings.quality_gate_ai === '1' ? '✅ Ativo' : '⚠️ Desativo (modo local)') + '</td></tr>';
                    $('#ob-summary').html(html);

                    showStep(5);
                });
            });

            // Finalizar
            $('#ob-finish-and-generate, #ob-finish-dashboard, #ob-finish-settings').on('click', function(e) {
                const $btn = $(this);
                e.preventDefault();
                $.post(ajaxurl, {
                    action: 'geo_onboarding_complete',
                    nonce: nonce
                }).done(function() {
                    if ($btn.is('#ob-finish-settings')) {
                        window.location.href = '<?php echo esc_url(admin_url('admin.php?page=geo-settings')); ?>';
                    } else {
                        window.location.href = $btn.attr('href');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * AJAX: salvar step + opcionalmente chaves/settings.
     */
    public static function ajax_save_step(): void {
        check_ajax_referer('geo_onboarding', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }

        $step = max(1, min(5, (int) ($_POST['step'] ?? 1)));
        update_option(self::OPT_STEP, $step, false);

        // Salva chaves se vieram
        if (isset($_POST['save_keys']) && is_array($_POST['save_keys'])) {
            $keys = $_POST['save_keys'];
            $map = [
                'openai'    => 'geo_openai_api_key',
                'groq'      => 'geo_groq_api_key',
                'gemini'    => 'geo_gemini_api_key',
                'claude'    => 'geo_claude_api_key',
                'perplexity'=> 'geo_perplexity_api_key',
                'naga'      => 'geo_naga_api_key',
                'falai'     => 'geo_falai_api_key',
                'replicate' => 'geo_replicate_api_key',
                'groq'      => 'geo_groq_api_key',
                'claude'    => 'geo_claude_api_key',
            ];
            foreach ($keys as $provider => $key) {
                $option = $map[$provider] ?? null;
                if ($option) {
                    update_option($option, sanitize_text_field((string)$key), false);
                }
            }
        }

        // Salva settings se vieram
        if (isset($_POST['save_settings']) && is_array($_POST['save_settings'])) {
            $s = $_POST['save_settings'];
            if (isset($s['site_mode'])) {
                update_option('geo_site_mode', sanitize_key($s['site_mode']));
            }
            if (isset($s['auto_expand'])) {
                update_option('auto_expand_enabled', $s['auto_expand'] === '1' ? '1' : '0', false);
            }
            if (isset($s['featured_required'])) {
                update_option('geo_featured_image_required', $s['featured_required'] === '1' ? '1' : '0', false);
            }
            if (isset($s['quality_gate_ai'])) {
                update_option('quality_gate_ai_enabled', $s['quality_gate_ai'] === '1' ? '1' : '0', false);
            }
        }

        wp_send_json_success(['step' => $step]);
    }

    /**
     * AJAX: testa conexão de um provider.
     */
    public static function ajax_test_provider(): void {
        check_ajax_referer('geo_onboarding', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }

        $provider = sanitize_key($_POST['provider'] ?? '');
        $key = trim((string) ($_POST['api_key'] ?? ''));
        if (empty($key)) {
            wp_send_json_error(['message' => 'API key vazia']);
        }

        $start = microtime(true);

        switch ($provider) {
            case 'openai':
                $result = self::test_openai($key);
                break;
            case 'groq':
                $result = self::test_groq($key);
                break;
            case 'gemini':
                $result = self::test_gemini($key);
                break;
            case 'claude':
                $result = self::test_claude($key);
                break;
            case 'perplexity':
                $result = self::test_perplexity($key);
                break;
            case 'naga':
                $result = self::test_naga($key);
                break;
            case 'falai':
                $result = self::test_falai($key);
                break;
            default:
                wp_send_json_error(['message' => 'Provider não suportado']);
        }

        $duration = (int) round((microtime(true) - $start) * 1000);

        if ($result['ok']) {
            wp_send_json_success([
                'message' => $result['message'] . ' (' . $duration . 'ms)',
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['message'],
            ]);
        }
    }

    /**
     * Teste real de conexão OpenAI.
     */
    private static function test_openai(string $key): array {
        $response = wp_remote_get('https://api.openai.com/v1/models', [
            'headers' => ['Authorization' => 'Bearer ' . $key],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => 'Erro de rede: ' . $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 401) return ['ok' => false, 'message' => 'API key inválida (HTTP 401)'];
        if ($code === 429) return ['ok' => false, 'message' => 'Rate limit (HTTP 429) - aguarde'];
        if ($code !== 200) return ['ok' => false, 'message' => 'HTTP ' . $code];
        return ['ok' => true, 'message' => 'Conexão OK — chave válida'];
    }


    private static function test_groq(string $key): array {
        $response = wp_remote_get('https://api.groq.com/openai/v1/models', [
            'headers' => ['Authorization' => 'Bearer ' . $key],
            'timeout' => 10,
        ]);
        return self::http_test_result($response, 'Groq');
    }

    private static function test_gemini(string $key): array {
        $response = wp_remote_get('https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($key), ['timeout' => 10]);
        return self::http_test_result($response, 'Gemini');
    }

    private static function test_claude(string $key): array {
        $response = wp_remote_get('https://api.anthropic.com/v1/models', [
            'headers' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
            'timeout' => 10,
        ]);
        return self::http_test_result($response, 'Claude');
    }

    private static function test_perplexity(string $key): array {
        $response = wp_remote_get('https://api.perplexity.ai/models', [
            'headers' => ['Authorization' => 'Bearer ' . $key],
            'timeout' => 10,
        ]);
        return self::http_test_result($response, 'Perplexity');
    }

    private static function test_naga(string $key): array {
        $response = wp_remote_get('https://api.naga.ac/v1/models', [
            'headers' => ['Authorization' => 'Bearer ' . $key],
            'timeout' => 10,
        ]);
        return self::http_test_result($response, 'Naga.ac');
    }

    private static function http_test_result($response, string $label): array {
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $label . ': erro de rede: ' . $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) return ['ok' => true, 'message' => $label . ': conexão OK'];
        if ($code === 401 || $code === 403) return ['ok' => false, 'message' => $label . ': API key inválida (HTTP ' . $code . ')'];
        if ($code === 429) return ['ok' => false, 'message' => $label . ': rate limit (HTTP 429)'];
        return ['ok' => false, 'message' => $label . ': HTTP ' . $code];
    }

    /**
     * Teste real de conexão Fal.ai (com Schnell, mais rápido).
     */
    private static function test_falai(string $key): array {
        $response = wp_remote_post('https://fal.run/fal-ai/flux/schnell', [
            'headers' => [
                'Authorization' => 'Key ' . $key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'prompt' => 'Simple blue circle on white background',
                'image_size' => 'square',
                'num_images' => 1,
                'num_inference_steps' => 4,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => 'Erro de rede: ' . $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 401 || $code === 403) return ['ok' => false, 'message' => 'API key inválida (HTTP ' . $code . ')'];
        if ($code === 402) return ['ok' => false, 'message' => 'Sem créditos — recarregue em fal.ai/dashboard/billing'];
        if ($code === 429) return ['ok' => false, 'message' => 'Rate limit (HTTP 429) - aguarde'];
        if ($code !== 200) return ['ok' => false, 'message' => 'HTTP ' . $code];

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['images'][0]['url'])) {
            return ['ok' => true, 'message' => 'Conexão OK — imagem teste gerada'];
        }
        return ['ok' => false, 'message' => 'Resposta sem URL de imagem'];
    }

    /**
     * AJAX: marca onboarding como completo.
     */
    public static function ajax_complete(): void {
        check_ajax_referer('geo_onboarding', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }
        self::mark_completed();
        wp_send_json_success(['completed' => true]);
    }

    /**
     * AJAX: dispensar onboarding (não completar agora).
     */
    public static function ajax_dismiss(): void {
        check_ajax_referer('geo_onboarding', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }
        update_option('geo_onboarding_dismissed', '1', false);
        wp_send_json_success();
    }
}
