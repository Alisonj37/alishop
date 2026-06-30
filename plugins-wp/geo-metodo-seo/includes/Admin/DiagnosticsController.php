<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\Config\ConfigManager;
use GeoMetodoSEO\Services\QualityReportService;

class DiagnosticsController {
    public static function register_hooks(): void {
        add_action('wp_ajax_geo_diag_test_api', [__CLASS__, 'ajax_test_api']);
        add_action('wp_ajax_geo_diag_set_mode', [__CLASS__, 'ajax_set_mode']);
        add_action('wp_ajax_geo_diag_quality_report', [__CLASS__, 'ajax_quality_report']);
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) wp_die('Sem permissão.');
        $nonce = wp_create_nonce('geo_diagnostics_nonce');
        $mode = get_option('geo_operation_mode', 'safe');
        $last_posts = get_posts(['post_type' => ['post','geo_glossary'], 'post_status' => ['publish','draft','future'], 'posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC']);
        ?>
        <div class="wrap geo-diag-wrap">
            <h1>🧭 Diagnóstico Geral — GEO Método SEO</h1>
            <p>Manual interno, testes de API sem gerar artigo, modo seguro/produção e relatório de qualidade por artigo.</p>

            <style>
                .geo-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;margin:18px 0;max-width:1120px}.geo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.geo-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#f0f0f1}.geo-ok{color:#047857}.geo-bad{color:#b91c1c}.geo-warn{color:#b45309}.geo-pre{white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;max-height:360px;overflow:auto}
            </style>

            <div class="geo-card">
                <h2>1) Status rápido</h2>
                <div class="geo-grid">
                    <?php foreach ($this->status_cards() as $card): ?>
                        <div style="border:1px solid #eee;border-radius:8px;padding:12px;">
                            <strong><?php echo esc_html($card['label']); ?></strong><br>
                            <span class="<?php echo esc_attr($card['class']); ?>"><?php echo esc_html($card['value']); ?></span>
                            <p class="description"><?php echo esc_html($card['hint']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="geo-card">
                <h2>2) Testar API sem gerar artigo</h2>
                <p class="description">Envia um prompt mínimo. Isso pode consumir poucos tokens, mas não cria artigo, post ou imagem.</p>
                <p>
                    <select id="geo-api-provider">
                        <option value="groq">Groq</option><option value="openai">OpenAI</option><option value="gemini">Gemini</option><option value="claude">Claude</option><option value="perplexity">Perplexity</option><option value="naga">Naga texto</option>
                    </select>
                    <input type="text" id="geo-api-model" class="regular-text" placeholder="Modelo opcional">
                    <button class="button button-primary" id="geo-test-api">Testar API</button>
                </p>
                <div id="geo-api-result" class="geo-pre" style="display:none;"></div>
            </div>

            <div class="geo-card">
                <h2>3) Modo seguro / modo produção</h2>
                <p>Modo atual: <span class="geo-badge"><?php echo esc_html($mode === 'production' ? 'Produção' : 'Seguro'); ?></span></p>
                <p>
                    <button class="button" data-mode="safe">Ativar modo seguro</button>
                    <button class="button button-primary" data-mode="production">Ativar modo produção controlado</button>
                </p>
                <p class="description"><strong>Seguro:</strong> configura o painel de diagnóstico. <strong>Produção:</strong> cada provider trabalha separado.</p>
            </div>

            <div class="geo-card">
                <h2>4) Relatório de qualidade por artigo</h2>
                <p>
                    <select id="geo-report-post">
                        <option value="">Escolha um post recente...</option>
                        <?php foreach ($last_posts as $p): ?>
                            <option value="<?php echo esc_attr($p->ID); ?>">#<?php echo esc_html($p->ID . ' — ' . get_the_title($p)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" id="geo-report-post-id" placeholder="ou Post ID" style="width:110px;">
                    <button class="button button-primary" id="geo-run-report">Analisar qualidade</button>
                </p>
                <div id="geo-report-result"></div>
            </div>

            <?php if (class_exists('GeoMetodoSEO\Services\ObservabilityService')) { \GeoMetodoSEO\Services\ObservabilityService::render_dashboard(); } ?>
            <?php if (class_exists('GeoMetodoSEO\Services\OperationalDashboardService')) { \GeoMetodoSEO\Services\OperationalDashboardService::render_queue_monitor(); } ?>
            <?php if (class_exists('GeoMetodoSEO\Services\AjaxSecurityAuditService')) { \GeoMetodoSEO\Services\AjaxSecurityAuditService::render_panel(); } ?>
            <?php if (class_exists('GeoMetodoSEO\Services\WebStoryAmpValidator')) { \GeoMetodoSEO\Services\WebStoryAmpValidator::render_panel(); } ?>

            <div class="geo-card">
                <h2>5) Manual interno de uso</h2>
                <?php $this->render_manual(); ?>
            </div>
        </div>
        <script>
        (function($){
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            function showPre(sel, data){ $(sel).show().text(typeof data === 'string' ? data : JSON.stringify(data,null,2)); }
            $('#geo-test-api').on('click', function(){
                $('#geo-api-result').show().text('Testando...');
                $.post(ajaxurl,{action:'geo_diag_test_api',nonce:nonce,provider:$('#geo-api-provider').val(),model:$('#geo-api-model').val()},function(r){ showPre('#geo-api-result', r); });
            });
            $('[data-mode]').on('click', function(){
                if(!confirm('Aplicar este modo de operação agora?')) return;
                $.post(ajaxurl,{action:'geo_diag_set_mode',nonce:nonce,mode:$(this).data('mode')},function(r){ alert(r.success ? r.data.message : (r.data && r.data.message || 'Erro')); if(r.success) location.reload(); });
            });
            $('#geo-run-report').on('click', function(){
                let post_id = $('#geo-report-post-id').val() || $('#geo-report-post').val();
                if(!post_id){ alert('Informe ou escolha um post.'); return; }
                $('#geo-report-result').html('<p>Analisando...</p>');
                $.post(ajaxurl,{action:'geo_diag_quality_report',nonce:nonce,post_id:post_id},function(r){
                    if(!r.success){ $('#geo-report-result').html('<p class="geo-bad">Erro: '+(r.data && r.data.message || 'falha')+'</p>'); return; }
                    let d=r.data, html='<h3>Score: '+d.score+'/100</h3><p><strong>'+d.title+'</strong> — '+d.words+' palavras, '+d.images+' imagens, '+d.internal_links+' links internos, '+d.external_links+' externos</p><table class="widefat striped"><thead><tr><th>Item</th><th>Status</th><th>Pontos</th><th>Orientação</th></tr></thead><tbody>';
                    d.checks.forEach(c=>{ html+='<tr><td>'+c.label+'</td><td>'+(c.ok?'✅ OK':'⚠️ Ajustar')+'</td><td>'+c.points+'/'+c.max+'</td><td>'+c.hint+'</td></tr>'; });
                    html+='</tbody></table><p><a class="button" target="_blank" href="'+d.edit_url+'">Editar post</a> <a class="button" target="_blank" href="'+d.view_url+'">Ver post</a></p>';
                    $('#geo-report-result').html(html);
                });
            });

            $(document).on('click', '.geo-retry-job', function(){
                if(!confirm('Reenfileirar este job agora?')) return;
                $.post(ajaxurl,{action:'geo_diag_retry_job',nonce:nonce,job_id:$(this).data('job')},function(r){ alert(r.success ? r.data.message : (r.data && r.data.message || 'Erro')); if(r.success) location.reload(); });
            });
            $(document).on('click', '#geo-clear-stuck-jobs', function(){
                if(!confirm('Recuperar jobs travados e limpar locks expirados?')) return;
                $.post(ajaxurl,{action:'geo_diag_clear_stuck_jobs',nonce:nonce},function(r){ alert(r.success ? r.data.message : (r.data && r.data.message || 'Erro')); if(r.success) location.reload(); });
            });
            $(document).on('click', '.geo-reprocess-media', function(){
                $('#geo-observability-result').show().text('Reprocessando mídia...');
                $.post(ajaxurl,{action:'geo_diag_reprocess_media',nonce:nonce,post_id:$(this).data('post')},function(r){ showPre('#geo-observability-result', r); });
            });
            $(document).on('click', '.geo-post-timeline', function(){
                $('#geo-observability-result').show().text('Carregando timeline...');
                $.post(ajaxurl,{action:'geo_diag_post_timeline',nonce:nonce,post_id:$(this).data('post')},function(r){ showPre('#geo-observability-result', r); });
            });
        })(jQuery);
        </script>
        <?php
    }

    private function status_cards(): array {
        $cards = [];
        $keys = [
            'OpenAI' => ['option' => 'geo_openai_api_key', 'hint' => 'Texto/IA. Chave: https://platform.openai.com/api-keys'],
            'Groq' => ['option' => 'geo_groq_api_key', 'hint' => 'Prompt visual, títulos e rascunhos econômicos. Chave: https://console.groq.com/keys'],
            'Gemini' => ['option' => 'geo_gemini_api_key', 'hint' => 'Texto/IA. Chave: https://aistudio.google.com/app/apikey'],
            'Claude' => ['option' => 'geo_claude_api_key', 'hint' => 'Texto/IA. Chave: https://console.anthropic.com/settings/keys'],
            'Perplexity' => ['option' => 'geo_perplexity_api_key', 'hint' => 'Pesquisa/IA. Chave: https://www.perplexity.ai/settings/api'],
            'Replicate Flux Schnell' => ['option' => 'geo_replicate_api_key', 'hint' => 'Imagem: corpo primário e fallback da featured. Token: https://replicate.com/account/api-tokens'],
            'Fal.ai gpt-image-2' => ['option' => 'geo_falai_api_key', 'hint' => 'Imagem: featured primária e fallback do corpo. Chave: https://fal.ai/dashboard/keys'],
            'Naga.ac' => ['option' => 'geo_naga_api_key', 'hint' => 'Imagem: Web Stories primário. Painel: https://naga.ac/dashboard'],
            'HuggingFace Flux Schnell' => ['option' => 'geo_huggingface_api_key', 'hint' => 'Imagem: fallback de Web Stories. Token: https://huggingface.co/settings/tokens'],
        ];
        foreach ($keys as $name => $opt) {
            $ok = trim((string) get_option($opt['option'], '')) !== '';
            $cards[] = [
                'label' => $name,
                'value' => $ok ? 'Configurado' : 'Sem chave',
                'class' => $ok ? 'geo-ok' : 'geo-warn',
                'hint' => $ok ? 'Chave encontrada no WordPress. ' . $opt['hint'] : 'Configure antes de usar. ' . $opt['hint'],
            ];
        }
        $cards[] = ['label' => 'Modo', 'value' => get_option('geo_operation_mode', 'safe') === 'production' ? 'Produção controlado' : 'Seguro', 'class' => 'geo-ok', 'hint' => 'Mostra o modo operacional escolhido.'];
        $cards[] = ['label' => 'WP-Cron', 'value' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'Desativado' : 'Ativo', 'class' => (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'geo-warn' : 'geo-ok', 'hint' => 'Necessário para filas e automações.'];
        return $cards;
    }

    private function render_manual(): void { ?>
        <h3>Fluxo recomendado</h3>
        <ol>
            <li>Use o <strong>Gerador de Títulos</strong> com briefing completo e o provedor de texto configurado no plugin.</li>
            <li>Valide os títulos no banco global antes de gerar artigos.</li>
            <li>Teste primeiro no <strong>Writer Manual</strong> em rascunho.</li>
            <li>Use <strong>GEO Glossário SEO</strong> para termos conceituais e suporte semântico.</li>
            <li>Use <strong>YouTube → Artigo</strong> apenas com vídeos confiáveis e revise o rascunho.</li>
            <li>Ative <strong>SARA Autopilot</strong> só depois dos testes manuais.</li>
        </ol>
        <h3>Configuração segura</h3>
        <p>Providers trabalham separados. Escolha o provider desejado em cada módulo.</p>
        <h3>Quando usar cada provider</h3>
        <p><strong>Providers de texto:</strong> OpenAI, Groq, Gemini, Claude, Perplexity e Naga podem ser usados conforme configuração. <strong>Replicate Flux Schnell:</strong> imagens do corpo do artigo. <strong>Fal.ai gpt-image-2:</strong> imagem destacada e fallback do corpo. <strong>Naga.ac:</strong> Web Stories. <strong>HuggingFace Flux Schnell:</strong> fallback de Web Stories. Não existe mais URL legada como gerador novo.</p>
        <h3>Checklist antes de publicar</h3>
        <p>Verifique título, meta descrição, Rank Math, FAQ, schema, imagens, links internos, autor/E-E-A-T e se não há promessa ou dado inventado.</p>
    <?php }

    public static function ajax_test_api(): void {
        check_ajax_referer('geo_diagnostics_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.']);
        $provider = sanitize_key($_POST['provider'] ?? 'groq');
        $model = sanitize_text_field($_POST['model'] ?? '');
        $key = get_option('geo_' . $provider . '_api_key', '');
        if (trim((string) $key) === '') {
            wp_send_json_error(['message' => 'API key não configurada para ' . $provider]);
        }
        $ai = new AIManager();
        $start = microtime(true);
        $res = $ai->generateText('Responda apenas com a palavra OK. Este é um teste técnico curto de API.', $provider, $model ?: null);
        $ms = round((microtime(true) - $start) * 1000);
        if (!$res || $res->hasError()) {
            wp_send_json_error(['provider' => $provider, 'model' => $model, 'latency_ms' => $ms, 'message' => $res ? $res->getError() : 'sem resposta']);
        }
        wp_send_json_success(['provider' => $provider, 'model' => $model ?: 'padrão', 'latency_ms' => $ms, 'content' => trim($res->getContent())]);
    }

    public static function ajax_set_mode(): void {
        check_ajax_referer('geo_diagnostics_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.']);
        $mode = sanitize_key($_POST['mode'] ?? 'safe');
        if ($mode === 'production') {
            update_option('geo_operation_mode', 'production', false);
            $msg = 'Modo produção ativado. Providers continuam independentes, com providers independentes.';
        } else {
            update_option('geo_operation_mode', 'safe', false);
            update_option('faq_generator_enabled', '0', false);
            update_option('auto_expand_enabled', '0', false);
            $msg = 'Modo seguro ativado. Providers continuam independentes.';
        }
        wp_send_json_success(['message' => $msg]);
    }

    public static function ajax_quality_report(): void {
        check_ajax_referer('geo_diagnostics_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.']);
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $report = QualityReportService::analyze($post_id);
        if (empty($report['success'])) wp_send_json_error($report);
        wp_send_json_success($report);
    }
}

