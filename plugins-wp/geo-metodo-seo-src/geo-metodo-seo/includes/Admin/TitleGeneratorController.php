<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\Helpers\SecurityHelper;
use GeoMetodoSEO\TitleBank\SeoGeoTitleBank;

class TitleGeneratorController {

    private $niches = [
        'tecnologia'       => 'Tecnologia e IA',
        'seo'              => 'SEO e Marketing',
        'financas'         => 'Financas e Investimentos',
        'saude'            => 'Saude e Bem-estar',
        'ecommerce'        => 'E-commerce e Vendas',
        'educacao'         => 'Educacao',
        'ciencia'          => 'Ciencia e Pesquisa',
        'negocios'         => 'Negocios e Empreendedorismo',
        'sustentabilidade' => 'Sustentabilidade e Meio Ambiente',
        'direito'          => 'Direito e Legislacao',
        'geral'            => 'Geral',
    ];

    private $title_types = [
        'como_fazer'    => 'Como Fazer (How-to)',
        'listicle'      => 'Listicle (Os X melhores...)',
        'guia'          => 'Guia Completo',
        'comparativo'   => 'Comparativo',
        'review'        => 'Review / Analise',
        'pergunta'      => 'Pergunta',
        'curiosidade'   => 'Curiosidade / Voce Sabia',
        'urgencia'      => 'Urgencia / Novidade',
        'misto'         => 'Todos os tipos (variado)',
    ];

    public function render_page() {
        $nonce = wp_create_nonce('geo_title_generator');
        $providers = [
            'openai'     => 'OpenAI',
            'groq'       => 'Groq',
            'gemini'     => 'Gemini',
            'claude'     => 'Claude',
            'perplexity' => 'Perplexity',
            'naga'       => 'Naga.ac',
        ];
        $providers_json = wp_json_encode($providers);
        $current_provider = ProviderResolver::for('title_generation', get_option('geo_title_ai_provider', ''));
        $current_model = (string) get_option('geo_title_ai_model', '');
        $model_provider_map = [
            'llama-3.3-70b-versatile' => 'groq',
            'openai/gpt-oss-120b' => 'groq',
            'openai/gpt-oss-20b' => 'groq',
            'llama-3.1-8b-instant' => 'groq',
            'groq/compound-mini' => 'groq',
            'groq/compound' => 'groq',
            'qwen/qwen3-32b' => 'groq',
            'meta-llama/llama-4-scout-17b-16e-instruct' => 'groq',
            'gpt-4.1' => 'openai',
            'gpt-4.1-mini' => 'openai',
            'gemini-3.1-flash-lite' => 'gemini',
            'gemini-2.5-flash' => 'gemini',
            'claude-sonnet-4-6' => 'claude',
            'claude-3-5-haiku-latest' => 'claude',
            'sonar' => 'perplexity',
            'gemini-2.5-flash:free' => 'naga',
        ];
        $model_map_json = wp_json_encode($model_provider_map);
        if ($current_model !== '' && isset($model_provider_map[$current_model]) && $model_provider_map[$current_model] !== $current_provider) {
            $current_model = '';
        }
        ?>
        <div class="wrap">
            <h1>Gerador de Titulos — GEO Metodo SEO v<?php echo GEO_METODO_SEO_VERSION; ?></h1>
            <p class="description">Gere títulos profissionais com briefing completo, intenção real de busca, SEO, GEO, AEO, LLM e anti-canibalização.</p>

            <div style="max-width:900px; margin-top:20px; background:#fff; border:1px solid #ddd; border-radius:6px; padding:24px;">
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="geo_tg_tema">Tema principal</label></th>
                        <td>
                            <input type="text" id="geo_tg_tema" class="large-text"
                                   placeholder="Ex: Tecnologia Xiaomi, SEO para IA, WordPress, smartphones de entrada..." required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geo_tg_contexto">Nicho / contexto</label></th>
                        <td>
                            <textarea id="geo_tg_contexto" class="large-text" rows="3" placeholder="Ex: smartphones Xiaomi, Redmi, POCO, HyperOS, reviews, comparativos, guias de compra, bateria, câmera, desempenho e custo-benefício."></textarea>
                            <p class="description">Use este campo para explicar exatamente o recorte editorial. Isso reduz títulos genéricos.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geo_tg_publico">Público-alvo</label></th>
                        <td>
                            <textarea id="geo_tg_publico" class="large-text" rows="2" placeholder="Ex: usuários brasileiros que querem comprar celular Xiaomi, comparar modelos, resolver problemas e entender atualizações."></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geo_tg_nicho">Nicho</label></th>
                        <td>
                            <select id="geo_tg_nicho" class="regular-text">
                                <?php foreach ($this->niches as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geo_tg_tipo">Tipo de Titulo</label></th>
                        <td>
                            <select id="geo_tg_tipo" class="regular-text">
                                <?php foreach ($this->title_types as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Intenção de busca</th>
                        <td>
                            <label><input type="checkbox" class="geo_tg_intent" value="informacional" checked> Informacional</label><br>
                            <label><input type="checkbox" class="geo_tg_intent" value="comparativa" checked> Comparativa</label><br>
                            <label><input type="checkbox" class="geo_tg_intent" value="guia de compra" checked> Guia de compra</label><br>
                            <label><input type="checkbox" class="geo_tg_intent" value="problema e solução" checked> Problema e solução</label><br>
                            <label><input type="checkbox" class="geo_tg_intent" value="tutorial / como fazer" checked> Tutorial / como fazer</label><br>
                            <label><input type="checkbox" class="geo_tg_intent" value="review / análise"> Review / análise</label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geo_tg_extra">Instruções extras</label></th>
                        <td>
                            <textarea id="geo_tg_extra" class="large-text" rows="4" placeholder="Ex: Não invente preços, lançamentos, fichas técnicas ou modelos não confirmados. Priorize dúvidas reais do usuário brasileiro."></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geo_tg_count">Quantidade de títulos</label></th>
                        <td>
                            <input type="number" id="geo_tg_count" value="50" min="5" max="80" style="width:90px;">
                            <span class="description">Máximo 80 por geração.</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Anti-canibalização</th>
                        <td><label><input type="checkbox" id="geo_tg_anti_cannibal" value="1" checked> Evitar títulos parecidos com artigos, rascunhos, páginas e glossários existentes</label></td>
                    </tr>
                    <tr>
                        <th><label for="geo_tg_provider">Provedor de IA</label></th>
                        <td>
                            <select id="geo_tg_provider" class="regular-text">
                                <?php foreach ($providers as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($current_provider, $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geo_tg_model">Modelo</label></th>
                        <td>
                            <input type="text" id="geo_tg_model" class="regular-text" value="<?php echo esc_attr($current_model); ?>" placeholder="Deixe vazio para usar o modelo padrão do provedor selecionado">
                            <p class="description">Deixe vazio para o plugin escolher automaticamente o modelo correto de OpenAI, Groq, Gemini, Claude, Perplexity ou Naga.ac.</p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" id="geo-tg-btn" class="button button-primary button-large">
                        🚀 Gerar títulos profissionais SEO/GEO
                    </button>
                    <span id="geo-tg-loading" style="display:none; margin-left:14px; vertical-align:middle;">
                        <span class="spinner is-active" style="float:none; vertical-align:middle;"></span>
                        <em>Gerando títulos com briefing SEO/GEO...</em>
                    </span>
                </p>
            </div>

            <div style="max-width:860px;margin-top:20px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:18px;">
                <h2 style="font-size:16px;margin-top:0;">📌 Títulos pendentes salvos</h2>
                <p class="description">Estes títulos ficam disponíveis no Gerar Artigo Individual, Geração em Massa e Writer Manual. Quando um artigo for gerado/agendado com um deles, ele sai da lista global.</p>
                <?php echo SeoGeoTitleBank::render_picker('#geo-title-generator-dummy', 'single'); ?>
            </div>
            <input type="hidden" id="geo-title-generator-dummy" value="">

            <div style="max-width:860px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-top:20px;">
                <h2 style="font-size:16px;">📦 Backup / Restauração do Banco</h2>
                <p class="description">Exporte para backup ou para sincronizar entre sites.</p>

                <p>
                    <button class="button" id="geo-tb-export-pending" type="button">📥 Exportar pendentes</button>
                    <button class="button" id="geo-tb-export-all" type="button">📥 Exportar todos</button>
                </p>

                <hr>
                <p>
                    <textarea id="geo-tb-import-json" rows="6" style="width:100%;font-family:monospace;font-size:12px;" placeholder="Cole aqui o JSON exportado de outro site..."></textarea>
                </p>
                <p>
                    <label><input type="radio" name="geo-tb-import-mode" value="merge" checked> Mesclar (adiciona sem sobrescrever)</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="geo-tb-import-mode" value="replace"> Substituir tudo (CUIDADO)</label>
                </p>
                <p>
                    <button class="button button-primary" id="geo-tb-import" type="button">📤 Importar JSON</button>
                </p>

                <hr>
                <p><strong>🧹 Limpar títulos usados antigos</strong></p>
                <p>
                    <label>Remover títulos com status "usado" há mais de
                        <input type="number" id="geo-tb-cleanup-days" value="30" min="1" max="365" style="width:80px;"> dias
                    </label>
                    &nbsp;
                    <button class="button" id="geo-tb-cleanup" type="button">🧹 Limpar agora</button>
                </p>
            </div>

            <!-- Results -->
            <div id="geo-tg-results" style="display:none; margin-top:28px; max-width:860px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                    <h2 style="font-size:16px; margin:0;" id="geo-tg-result-title">50 Titulos Gerados</h2>
                    <button type="button" id="geo-tg-export" class="button">📥 Exportar TXT</button>
                </div>
                <div id="geo-tg-error" style="display:none; color:#dc3232; margin-bottom:12px;"></div>
                <div id="geo-tg-list"></div>
            </div>
        </div>

        <style>
        .geo-title-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid #eee;
            background: #fff;
        }
        .geo-title-row:hover { background: #f9f9f9; }
        .geo-title-num {
            color: #999;
            font-size: 12px;
            min-width: 28px;
            text-align: right;
        }
        .geo-title-text {
            flex: 1;
            font-size: 14px;
        }
        .geo-title-copy, .geo-title-gen {
            white-space: nowrap;
        }
        </style>

        <script>
        (function(){
            var AJAX   = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var NONCE  = '<?php echo esc_js($nonce); ?>';
            var titles = [];

            var GEO_TG_MODEL_PROVIDER_MAP = <?php echo $model_map_json ?: '{}'; ?>;
            var geoTgProviderEl = document.getElementById('geo_tg_provider');
            var geoTgModelEl = document.getElementById('geo_tg_model');
            if (geoTgProviderEl && geoTgModelEl) {
                geoTgProviderEl.addEventListener('change', function() {
                    var m = (geoTgModelEl.value || '').trim();
                    if (m && GEO_TG_MODEL_PROVIDER_MAP[m] && GEO_TG_MODEL_PROVIDER_MAP[m] !== geoTgProviderEl.value) {
                        geoTgModelEl.value = '';
                    }
                });
            }

            document.getElementById('geo-tg-btn').addEventListener('click', function() {
                var tema     = document.getElementById('geo_tg_tema').value.trim();
                var contexto = document.getElementById('geo_tg_contexto').value.trim();
                var publico  = document.getElementById('geo_tg_publico').value.trim();
                var nicho    = document.getElementById('geo_tg_nicho').value;
                var tipo     = document.getElementById('geo_tg_tipo').value;
                var provider = document.getElementById('geo_tg_provider').value;
                var model    = document.getElementById('geo_tg_model').value.trim();
                var count    = parseInt(document.getElementById('geo_tg_count').value, 10) || 50;
                var extra    = document.getElementById('geo_tg_extra').value.trim();
                var anti     = document.getElementById('geo_tg_anti_cannibal').checked ? 1 : 0;
                var intents  = [];
                document.querySelectorAll('.geo_tg_intent:checked').forEach(function(el){ intents.push(el.value); });

                if (!tema) { alert('Informe o tema principal.'); return; }
                if (count < 5 || count > 80) { alert('A quantidade deve ficar entre 5 e 80 títulos.'); return; }

                document.getElementById('geo-tg-btn').disabled = true;
                document.getElementById('geo-tg-loading').style.display = 'inline-block';
                document.getElementById('geo-tg-results').style.display = 'none';
                document.getElementById('geo-tg-error').style.display = 'none';
                document.getElementById('geo-tg-list').innerHTML = '';
                titles = [];

                var data = 'action=geo_generate_titles'
                    + '&nonce='    + encodeURIComponent(NONCE)
                    + '&tema='     + encodeURIComponent(tema)
                    + '&contexto=' + encodeURIComponent(contexto)
                    + '&publico='  + encodeURIComponent(publico)
                    + '&nicho='    + encodeURIComponent(nicho)
                    + '&tipo='     + encodeURIComponent(tipo)
                    + '&provider=' + encodeURIComponent(provider)
                    + '&model='    + encodeURIComponent(model)
                    + '&count='    + encodeURIComponent(count)
                    + '&intents='  + encodeURIComponent(intents.join(','))
                    + '&extra='    + encodeURIComponent(extra)
                    + '&anti_cannibal=' + encodeURIComponent(anti);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.timeout = 120000;

                xhr.onload = function() {
                    document.getElementById('geo-tg-btn').disabled = false;
                    document.getElementById('geo-tg-loading').style.display = 'none';
                    document.getElementById('geo-tg-results').style.display = 'block';

                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data && res.data.titles) {
                            titles = res.data.titles;
                            renderTitles(titles, tema);
                        } else {
                            var msg = (res.data && res.data.message) ? res.data.message : 'Erro desconhecido.';
                            showError(msg);
                        }
                    } catch(e) {
                        showError('Erro ao processar resposta da IA.');
                    }
                };

                xhr.onerror = xhr.ontimeout = function() {
                    document.getElementById('geo-tg-btn').disabled = false;
                    document.getElementById('geo-tg-loading').style.display = 'none';
                    showError('Timeout ou erro de rede. Tente novamente.');
                };

                xhr.send(data);
            });

            function showError(msg) {
                document.getElementById('geo-tg-results').style.display = 'block';
                var el = document.getElementById('geo-tg-error');
                el.style.display = 'block';
                el.textContent = '❌ ' + msg;
            }

            function renderTitles(list, tema) {
                var container = document.getElementById('geo-tg-list');
                container.innerHTML = '';
                document.getElementById('geo-tg-result-title').textContent = list.length + ' Títulos Gerados';
                var wrapper = document.createElement('div');
                wrapper.style.cssText = 'border:1px solid #ddd; border-radius:6px; overflow:hidden;';

                list.forEach(function(title, i) {
                    var row = document.createElement('div');
                    row.className = 'geo-title-row';
                    row.innerHTML =
                        '<span class="geo-title-num">' + (i + 1) + '</span>'
                        + '<span class="geo-title-text">' + escHtml(title) + '</span>'
                        + '<button type="button" class="button button-small geo-title-copy" data-title="' + escAttr(title) + '">Copiar</button>'
                        + '<a href="' + escAttr(geoIndividualUrl(title)) + '" class="button button-small button-primary geo-title-gen" target="_blank">Gerar Artigo</a>';
                    wrapper.appendChild(row);
                });

                container.appendChild(wrapper);

                // Copy buttons
                container.querySelectorAll('.geo-title-copy').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var t = this.getAttribute('data-title');
                        navigator.clipboard.writeText(t).then(function() {
                            btn.textContent = '✓ Copiado';
                            setTimeout(function() { btn.textContent = 'Copiar'; }, 1500);
                        }).catch(function() {
                            var ta = document.createElement('textarea');
                            ta.value = t;
                            document.body.appendChild(ta);
                            ta.select();
                            document.execCommand('copy');
                            document.body.removeChild(ta);
                            btn.textContent = '✓ Copiado';
                            setTimeout(function() { btn.textContent = 'Copiar'; }, 1500);
                        });
                    });
                });
            }

            function geoIndividualUrl(title) {
                return '<?php echo esc_js(admin_url('admin.php?page=geo-individual')); ?>&geo_prefill=' + encodeURIComponent(title);
            }

            document.getElementById('geo-tg-export').addEventListener('click', function() {
                if (!titles.length) return;
                var text = titles.map(function(t, i) { return (i+1) + '. ' + t; }).join('\n');
                var blob = new Blob([text], { type: 'text/plain' });
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href = url;
                a.download = 'titulos-geo-seo.txt';
                a.click();
                URL.revokeObjectURL(url);
            });

            function downloadJson(json, filename){
                var blob = new Blob([json], { type: 'application/json' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url; a.download = filename; a.click();
                URL.revokeObjectURL(url);
            }

            function exportBank(only_pending){
                jQuery.post(ajaxurl, {
                    action: 'geo_titlebank_export',
                    nonce: NONCE,
                    only_pending: only_pending ? 1 : 0
                }, function(r){
                    if (r.success) downloadJson(r.data.json, r.data.filename);
                    else alert('Erro: ' + (r.data && r.data.message || 'desconhecido'));
                });
            }

            var btnExportPending = document.getElementById('geo-tb-export-pending');
            if (btnExportPending) btnExportPending.addEventListener('click', function(){ exportBank(true); });
            var btnExportAll = document.getElementById('geo-tb-export-all');
            if (btnExportAll) btnExportAll.addEventListener('click', function(){ exportBank(false); });

            var btnImport = document.getElementById('geo-tb-import');
            if (btnImport) btnImport.addEventListener('click', function(){
                var json = document.getElementById('geo-tb-import-json').value.trim();
                if (!json) { alert('Cole o JSON antes de importar.'); return; }
                var mode = document.querySelector('input[name="geo-tb-import-mode"]:checked').value;
                if (mode === 'replace' && !confirm('Tem certeza? Isso vai SUBSTITUIR todo o banco atual.')) return;
                jQuery.post(ajaxurl, {
                    action: 'geo_titlebank_import',
                    nonce: NONCE,
                    json: json, mode: mode
                }, function(r){
                    if (r.success) {
                        alert('Importado: ' + r.data.added + ' títulos, ignorados: ' + r.data.skipped + '. Total: ' + r.data.total);
                        location.reload();
                    } else {
                        alert('Erro: ' + (r.data && r.data.message || 'desconhecido'));
                    }
                });
            });

            var btnCleanup = document.getElementById('geo-tb-cleanup');
            if (btnCleanup) btnCleanup.addEventListener('click', function(){
                var days = parseInt(document.getElementById('geo-tb-cleanup-days').value, 10) || 30;
                if (!confirm('Remover títulos usados há mais de ' + days + ' dias?')) return;
                jQuery.post(ajaxurl, {
                    action: 'geo_titlebank_cleanup',
                    nonce: NONCE,
                    days: days
                }, function(r){
                    if (r.success) {
                        alert(r.data.message);
                        location.reload();
                    } else {
                        alert('Erro: ' + (r.data && r.data.message || 'desconhecido'));
                    }
                });
            });

            function escHtml(str) {
                return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
            function escAttr(str) {
                return str.replace(/"/g,'&quot;').replace(/'/g,'&#39;');
            }
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler: generate 50 titles.
     */
    public static function ajax_generate_titles() {
        check_ajax_referer('geo_title_generator', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissao.']);

        $tema     = sanitize_text_field($_POST['tema']     ?? '');
        $contexto = sanitize_textarea_field($_POST['contexto'] ?? '');
        $publico  = sanitize_textarea_field($_POST['publico']  ?? '');
        $nicho    = sanitize_text_field($_POST['nicho']    ?? 'geral');
        $tipo     = sanitize_text_field($_POST['tipo']     ?? 'misto');
        $provider = ProviderResolver::for('title_generation', sanitize_text_field($_POST['provider'] ?? get_option('geo_title_ai_provider', '')));
        $model    = sanitize_text_field($_POST['model'] ?? get_option('geo_title_ai_model', ''));
        $model_provider_map = [
            'llama-3.3-70b-versatile' => 'groq',
            'openai/gpt-oss-120b' => 'groq',
            'openai/gpt-oss-20b' => 'groq',
            'llama-3.1-8b-instant' => 'groq',
            'groq/compound-mini' => 'groq',
            'groq/compound' => 'groq',
            'qwen/qwen3-32b' => 'groq',
            'meta-llama/llama-4-scout-17b-16e-instruct' => 'groq',
            'gpt-4.1' => 'openai',
            'gpt-4.1-mini' => 'openai',
            'gemini-3.1-flash-lite' => 'gemini',
            'gemini-2.5-flash' => 'gemini',
            'claude-sonnet-4-6' => 'claude',
            'claude-3-5-haiku-latest' => 'claude',
            'sonar' => 'perplexity',
            'gemini-2.5-flash:free' => 'naga',
        ];
        if ($model !== '' && isset($model_provider_map[$model]) && $model_provider_map[$model] !== $provider) {
            $model = '';
        }
        if ($model === '') { $model = (string) ProviderResolver::modelFor('title_generation', $provider); }
        $count    = max(5, min(80, (int) ($_POST['count'] ?? 50)));
        $intents  = sanitize_text_field($_POST['intents'] ?? 'informacional,comparativa,guia de compra,problema e solução,tutorial / como fazer');
        $extra    = sanitize_textarea_field($_POST['extra'] ?? '');
        $anti     = (string) ($_POST['anti_cannibal'] ?? '1') === '1';

        if (empty($tema)) wp_send_json_error(['message' => 'Tema nao informado.']);

        $briefing = [
            'contexto' => $contexto,
            'publico' => $publico,
            'intents' => $intents,
            'extra' => $extra,
            'anti_cannibal' => $anti,
        ];

        $result = SeoGeoTitleBank::generate($tema, $nicho, $tipo, $count, $provider, $model ?: '', $briefing);
        if (empty($result['success'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'Falha ao gerar títulos.']);
        }

        update_option('geo_title_ai_provider', $provider, false);
        update_option('geo_title_ai_model', $model ?: '', false);
        wp_send_json_success([
            'titles' => $result['titles'] ?? [],
            'count' => count($result['titles'] ?? []),
            'added' => $result['added'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'pending' => $result['pending'] ?? [],
        ]);
    }

    public static function ajax_export_titlebank() {
        check_ajax_referer('geo_title_generator', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.']);

        $only_pending = !empty($_POST['only_pending']);
        $json = SeoGeoTitleBank::export_json($only_pending);

        wp_send_json_success([
            'json' => $json,
            'filename' => 'titlebank-export-' . date('Y-m-d-His') . '.json',
        ]);
    }

    public static function ajax_import_titlebank() {
        check_ajax_referer('geo_title_generator', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.']);

        $json = wp_unslash($_POST['json'] ?? '');
        $mode = sanitize_text_field($_POST['mode'] ?? 'merge');

        $result = SeoGeoTitleBank::import_json($json, $mode);
        if (empty($result['success'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'Falha na importação.']);
        }
        wp_send_json_success($result);
    }

    public static function ajax_cleanup_titlebank() {
        check_ajax_referer('geo_title_generator', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.']);

        $days = (int) ($_POST['days'] ?? 30);
        $days = max(1, min(365, $days));

        $removed = SeoGeoTitleBank::cleanup_old_used($days);
        $remaining = count(SeoGeoTitleBank::all());

        wp_send_json_success([
            'removed' => $removed,
            'remaining' => $remaining,
            'message' => "{$removed} títulos antigos removidos. Restam {$remaining} no banco.",
        ]);
    }

}
