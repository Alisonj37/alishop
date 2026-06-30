<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\License\LicenseManager;
use GeoMetodoSEO\AI\ProviderResolver;

class ClusterController {

    private $providers = [
        'openai'     => 'OpenAI',
        'groq'       => 'Groq (LLaMA 3.3 70B)',
        'gemini'     => 'Gemini',
        'claude'     => 'Claude',
        'perplexity' => 'Perplexity',
        'naga'       => 'Naga.ac (Gemini grátis)',
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
        if (!LicenseManager::can('cluster_seo')) {
            echo LicenseManager::lockedHtml('Cluster SEO');
            return;
        }
        $nonce          = wp_create_nonce('geo_process_article_nonce');
        $nonce_analysis = wp_create_nonce('geo_analysis_nonce');
        $provider = ProviderResolver::for('cluster_generation');
        $language = get_option('geo_default_language', 'pt-BR');

        // Existing cluster queue jobs for display
        $all_jobs     = function_exists('geo_mark_stale_scheduled_jobs') ? geo_mark_stale_scheduled_jobs('cluster') : get_option('geo_scheduled_queue', []);
        $cluster_jobs = array_filter($all_jobs, function($j) {
            return isset($j['type']) && $j['type'] === 'cluster';
        });
        ?>
        <div class="wrap">
            <h1>Cluster SEO</h1>
            <p class="description" style="font-size:14px;">
                Cria um artigo pilar e artigos satelites vinculados. Cada artigo e processado em sequencia com progresso em tempo real.
            </p>

            <div style="display:flex; gap:30px; flex-wrap:wrap; margin-top:20px; align-items:flex-start;">

                <!-- Formulario -->
                <div style="flex:1; min-width:300px; max-width:500px;">
                    <h2 style="font-size:16px;">🕸️ Configurar Cluster</h2>
                    <form id="geo-cluster-form">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="geo_main_kw">Keyword Principal (Pilar)</label></th>
                                <td>
                                    <input type="text" id="geo_main_kw" class="large-text"
                                           placeholder="Ex: SEO para pequenas empresas" required>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="geo_satellites_kw">Keywords Satelites</label></th>
                                <td>
                                    <textarea id="geo_satellites_kw" rows="8" style="width:100%;"
                                              placeholder="Opcional. Deixe vazio para o plugin planejar automaticamente 5 satélites a partir do título pilar."></textarea>
                                    <p class="description">Opcional: uma keyword por linha. Se ficar vazio, o plugin cria automaticamente 5 satélites baseados no título pilar.</p>
                                </td>
                            </tr>

                            <!-- Auto-schedule toggle -->
                            <tr>
                                <th>Agendamento Automatico</th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="geo-cluster-auto-schedule">
                                        Ativar agendamento automatico
                                    </label>
                                    <p class="description">
                                        Pilar e satélites usam os provedores escolhidos abaixo &bull;
                                        Max 5 arts/dia &bull; Intervalo 4h
                                    </p>
                                </td>
                            </tr>

                            <!-- Horario de inicio (agendamento) -->
                            <tr id="geo-cluster-start-time-row" style="display:none;">
                                <th><label for="geo-cluster-start-time">Horario de inicio</label></th>
                                <td>
                                    <input type="time" id="geo-cluster-start-time" value="08:00" class="regular-text">
                                    <p class="description">Hora do primeiro artigo do dia (fuso do servidor WordPress).</p>
                                </td>
                            </tr>

                            <!-- Provedor pilar (modo imediato) -->
                            <tr id="geo-cluster-provider-rows">
                                <th><label for="geo_provider_pillar">Provedor do Pilar</label></th>
                                <td>
                                    <select id="geo_provider_pillar" class="regular-text">
                                        <?php foreach ($this->providers as $val => $label): ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($provider, $val); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr id="geo-cluster-provider-sat-row">
                                <th><label for="geo_provider_sat">Provedor dos Satelites</label></th>
                                <td>
                                    <select id="geo_provider_sat" class="regular-text">
                                        <?php foreach ($this->providers as $val => $label): ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($provider, $val); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="geo_language_cluster">Idioma</label></th>
                                <td>
                                    <select id="geo_language_cluster" class="regular-text">
                                        <?php foreach ($this->languages as $val => $label): ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($language, $val); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>

                            <!-- Status (modo imediato) -->
                            <tr id="geo-cluster-status-row">
                                <th><label for="geo_status_cluster">Status dos Posts</label></th>
                                <td>
                                    <select id="geo_status_cluster" class="regular-text"
                                            onchange="geoToggleScheduledCluster(this.value)">
                                        <?php foreach ($this->statuses as $val => $label): ?>
                                            <option value="<?php echo esc_attr($val); ?>">
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="geo_category_cluster">Categoria</label></th>
                                <td>
                                    <select id="geo_category_cluster" class="regular-text">
                                        <option value="auto">🤖 Automático (pela keyword)</option>
                                        <?php foreach (get_categories(['hide_empty' => false, 'orderby' => 'name']) as $cat): ?>
                                        <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description" style="margin:0;">Categoria para o pilar e satélites do cluster.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="geo_embed_video_cluster">Vídeo YouTube</label></th>
                                <td>
                                    <label style="display:flex;align-items:center;gap:8px;">
                                        <input type="checkbox" id="geo_embed_video_cluster" value="1">
                                        <span>Embedar vídeo relacionado ao tema no pilar e nos satélites</span>
                                    </label>
                                    <p class="description" style="margin:4px 0 0;">Cada artigo recebe um vídeo do YouTube relevante ao seu próprio tema. Requer YouTube Data API Key.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="geo_image_source_cluster">Fonte das Imagens</label></th>
                                <td>
                                    <select id="geo_image_source_cluster" class="regular-text">
                                        <option value="">⚙️ Config global (<?php echo get_option('geo_image_source','ai') === 'library' ? 'Biblioteca' : 'IA'; ?>)</option>
                                        <option value="ai">🤖 Gerar com IA</option>
                                        <option value="library">🖼️ Minha Biblioteca</option>
                                    </select>
                                    <p class="description" style="margin:4px 0 0;">Vale para o pilar e todos os satélites do cluster.</p>
                                </td>
                            </tr>
                            <tr id="geo-cluster-scheduled-row" style="display:none;">
                                <th><label for="geo_scheduled_cluster">Agendar para</label></th>
                                <td>
                                    <input type="datetime-local" id="geo_scheduled_cluster" class="regular-text">
                                </td>
                            </tr>
                        </table>

                        <p style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:16px;">
                            <!-- Modo imediato -->
                            <button type="submit" id="geo-cluster-btn" class="button button-primary button-large">
                                🕸️ Criar Cluster
                            </button>
                            <!-- Modo agendamento -->
                            <button type="button" id="geo-cluster-schedule-btn"
                                    class="button button-primary button-large"
                                    style="display:none;"
                                    onclick="geoScheduleCluster()">
                                📅 Agendar Cluster
                            </button>
                            <!-- Canibalização -->
                            <button type="button" id="geo-cluster-cannib-btn"
                                    class="button button-secondary">
                                ⚠️ Verificar Canibalização
                            </button>
                        </p>
                    </form>
                    <div id="geo-cluster-cannib-result" style="display:none;margin-top:16px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;"></div>

                    <!-- Feedback agendamento -->
                    <div id="geo-cluster-schedule-feedback" style="display:none; margin-top:16px;"></div>
                </div>

                <!-- Progresso + historico -->
                <div style="flex:2; min-width:400px;">
                    <h2 style="font-size:16px;">
                        📊 Progresso
                        <span id="geo-cluster-counter" style="font-size:13px; color:#666; font-weight:normal; margin-left:10px;"></span>
                    </h2>
                    <div id="geo-cluster-progress" style="display:none; margin-bottom:24px;">
                        <div id="geo-cluster-table"></div>
                    </div>
                    <h2 style="font-size:16px; margin-top:8px;">📂 Clusters Criados</h2>
                    <?php $this->render_cluster_list(); ?>
                </div>
            </div>

            <!-- Fila de agendamento cluster -->
            <div style="max-width:900px; margin-top:40px;">
                <hr style="margin-bottom:24px; border:none; border-top:1px solid #ddd;">
                <h2 style="font-size:16px; margin-bottom:10px;">
                    📅 Fila de Agendamento — Cluster
                    <button type="button" class="button button-small" style="margin-left:12px; font-size:12px;"
                            onclick="geoRefreshClusterQueue()">🔄 Atualizar</button>
                    <button type="button" class="button button-small" style="margin-left:6px; font-size:12px; color:#dc3232;"
                            onclick="geoClearClusterDone()">🗑️ Limpar Concluidos</button>
                </h2>
                <div id="geo-cluster-queue-wrap">
                    <?php
                    if (!empty($cluster_jobs)) {
                        echo geo_render_schedule_table(array_values($cluster_jobs));
                    } else {
                        echo '<p style="color:#666; font-size:13px;">Nenhum artigo agendado via Cluster ainda.</p>';
                    }
                    ?>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var AJAX_URL = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var NONCE    = '<?php echo esc_js($nonce); ?>';

            // ── Toggle status row for datetime-local (modo imediato) ──────────
            window.geoToggleScheduledCluster = function(val) {
                var r = document.getElementById('geo-cluster-scheduled-row');
                if (r) r.style.display = (val === 'future') ? '' : 'none';
            };

            // ── Toggle auto-schedule mode ─────────────────────────────────────
            var toggleEl = document.getElementById('geo-cluster-auto-schedule');
            toggleEl.addEventListener('change', function() {
                var on = this.checked;
                document.getElementById('geo-cluster-start-time-row').style.display   = on ? '' : 'none';
                document.getElementById('geo-cluster-provider-rows').style.display    = on ? 'none' : '';
                document.getElementById('geo-cluster-provider-sat-row').style.display = on ? 'none' : '';
                document.getElementById('geo-cluster-status-row').style.display       = on ? 'none' : '';
                document.getElementById('geo-cluster-scheduled-row').style.display    = 'none';
                document.getElementById('geo-cluster-btn').style.display              = on ? 'none' : '';
                document.getElementById('geo-cluster-schedule-btn').style.display     = on ? '' : 'none';
            });


            function geoAutoClusterSatellites(mainTitle) {
                mainTitle = String(mainTitle || '').trim().replace(/[.]+$/,'');
                if (!mainTitle) return [];
                var clean = mainTitle
                    .replace(/^como\s+/i, '')
                    .replace(/^o que é\s+/i, '')
                    .replace(/^quais são\s+/i, '')
                    .replace(/\s+/g, ' ')
                    .trim();
                return [
                    'Como aplicar ' + clean + ' na prática dentro do WordPress',
                    'Principais recursos de ' + clean + ' para SEO, GEO e AEO',
                    'Erros comuns ao usar ' + clean + ' e como evitar problemas',
                    'Como configurar ' + clean + ' com biblioteca de imagens e automação',
                    clean + ' vs plugins SEO tradicionais: diferenças e quando usar'
                ];
            }


            // ── Immediate cluster generation ──────────────────────────────────
            document.getElementById('geo-cluster-form').addEventListener('submit', function(e) {
                e.preventDefault();

                if (document.getElementById('geo-cluster-auto-schedule').checked) return;

                var mainKw = document.getElementById('geo_main_kw').value.trim();
                if (!mainKw) { alert('Informe a keyword principal do pilar.'); return; }

                var satRaw = document.getElementById('geo_satellites_kw').value;
                var satellites = satRaw.split('\n')
                    .map(function(k) { return k.trim(); })
                    .filter(function(k) { return k.length > 0; });
                if (satellites.length === 0) {
                    satellites = geoAutoClusterSatellites(mainKw).slice(0, 5);
                    document.getElementById('geo_satellites_kw').value = satellites.join('\n');
                }

                var providerPillar = document.getElementById('geo_provider_pillar').value;
                var providerSat    = document.getElementById('geo_provider_sat').value;
                var language       = document.getElementById('geo_language_cluster').value;
                var postStatus     = document.getElementById('geo_status_cluster').value;
                var scheduledAt    = document.getElementById('geo_scheduled_cluster')
                                        ? document.getElementById('geo_scheduled_cluster').value : '';

                document.getElementById('geo-cluster-btn').disabled = true;
                document.getElementById('geo-cluster-progress').style.display = 'block';

                var allKeywords = [{ kw: mainKw, type: 'Pilar', provider: providerPillar }];
                satellites.forEach(function(s) {
                    allKeywords.push({ kw: s, type: 'Satelite', provider: providerSat });
                });

                renderClusterTable(allKeywords);

                var state = { done: 0, failed: 0, pillarId: 0, satelliteIds: [] };
                processClusterNext(allKeywords, 0, language, postStatus, scheduledAt, mainKw, state);
            });

            // ── Schedule cluster ──────────────────────────────────────────────
            window.geoScheduleCluster = function() {
                var mainKw = document.getElementById('geo_main_kw').value.trim();
                if (!mainKw) { alert('Informe a keyword principal do pilar.'); return; }

                var satRaw = document.getElementById('geo_satellites_kw').value;
                var satellites = satRaw.split('\n')
                    .map(function(k) { return k.trim(); })
                    .filter(function(k) { return k.length > 0; });

                var language  = document.getElementById('geo_language_cluster').value;
                var startTime = document.getElementById('geo-cluster-start-time').value || '08:00';

                var schedBtn = document.getElementById('geo-cluster-schedule-btn');
                schedBtn.disabled = true;
                schedBtn.textContent = '⏳ Agendando...';

                var categoryCluster = document.getElementById('geo_category_cluster') ? document.getElementById('geo_category_cluster').value : 'auto';
                var providerPillar = document.getElementById('geo_provider_pillar').value;
                var providerSat = document.getElementById('geo_provider_sat').value;
                var payload = 'action=geo_schedule_cluster'
                    + '&nonce='       + encodeURIComponent(NONCE)
                    + '&main_kw='     + encodeURIComponent(mainKw)
                    + '&satellites='  + encodeURIComponent(JSON.stringify(satellites))
                    + '&language='    + encodeURIComponent(language)
                    + '&start_time='  + encodeURIComponent(startTime)
                    + '&provider_pillar=' + encodeURIComponent(providerPillar)
                    + '&provider_sat=' + encodeURIComponent(providerSat)
                    + '&category_id=' + encodeURIComponent(categoryCluster);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.timeout = 15000;

                xhr.onload = function() {
                    schedBtn.disabled = false;
                    schedBtn.textContent = '📅 Agendar Cluster';
                    var fb = document.getElementById('geo-cluster-schedule-feedback');
                    fb.style.display = 'block';
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data && res.data.html) {
                            fb.innerHTML = res.data.html;
                            document.getElementById('geo-cluster-queue-wrap').innerHTML = res.data.html;
                        } else {
                            var msg = (res.data && res.data.message) ? res.data.message : 'Erro desconhecido';
                            fb.innerHTML = '<div class="notice notice-error"><p>❌ ' + msg + '</p></div>';
                        }
                    } catch(err) {
                        fb.innerHTML = '<div class="notice notice-error"><p>❌ Erro ao processar resposta.</p></div>';
                    }
                };

                xhr.ontimeout = function() {
                    schedBtn.disabled = false;
                    schedBtn.textContent = '📅 Agendar Cluster';
                    document.getElementById('geo-cluster-schedule-feedback').innerHTML =
                        '<div class="notice notice-error"><p>⏰ Timeout — tente novamente.</p></div>';
                };

                xhr.onerror = function() {
                    schedBtn.disabled = false;
                    schedBtn.textContent = '📅 Agendar Cluster';
                    document.getElementById('geo-cluster-schedule-feedback').innerHTML =
                        '<div class="notice notice-error"><p>❌ Erro de rede.</p></div>';
                };

                xhr.send(payload);
            };

            // ── Queue refresh ─────────────────────────────────────────────────
            // Verificação de Canibalização no Cluster
            document.getElementById('geo-cluster-cannib-btn').addEventListener('click', function() {
                var mainEl  = document.querySelector('[name="geo_pilar_keyword"]');
                var satEl   = document.querySelector('[name="geo_satellite_keywords"]');
                var mainKw  = mainEl ? mainEl.value.trim() : '';
                var satKws  = satEl ? satEl.value.split('\n').map(k => k.trim()).filter(k => k) : [];
                var allKws  = mainKw ? [mainKw, ...satKws] : satKws;
                if (!allKws.length) { alert('Informe as keywords do cluster primeiro.'); return; }
                var btn = this; btn.disabled = true; btn.textContent = '⏳ Verificando...';
                var div = document.getElementById('geo-cluster-cannib-result');
                div.style.display = 'block';
                div.innerHTML = '<p style="color:#666;">Verificando ' + allKws.length + ' keyword(s)...</p>';
                var html = '<h4 style="margin:0 0 10px;">Canibalização — Cluster</h4>';
                var done = 0;
                allKws.forEach(function(kw) {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        done++;
                        try { var r = JSON.parse(xhr.responseText); if (r.success) html += r.data.html; } catch(e) {}
                        if (done === allKws.length) {
                            div.innerHTML = html;
                            btn.disabled = false; btn.textContent = '⚠️ Verificar Canibalização';
                        }
                    };
                    xhr.send('action=geo_check_cannibalization&nonce=<?php echo esc_js($nonce_analysis); ?>&keyword=' + encodeURIComponent(kw));
                });
            });

            window.geoRefreshClusterQueue = function() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data && res.data.html) {
                            document.getElementById('geo-cluster-queue-wrap').innerHTML = res.data.html;
                        }
                    } catch(e) {}
                };
                xhr.send('action=geo_get_schedule_queue&nonce=' + encodeURIComponent(NONCE) + '&type=cluster');
            };

            // ── Clear done jobs ───────────────────────────────────────────────
            window.geoClearClusterDone = function() {
                if (!confirm('Remover todos os jobs concluidos/com falha do Cluster?')) return;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data && res.data.html) {
                            document.getElementById('geo-cluster-queue-wrap').innerHTML = res.data.html;
                        }
                    } catch(e) {}
                };
                xhr.send('action=geo_clear_schedule_done&nonce=' + encodeURIComponent(NONCE) + '&type=cluster');
            };

            // ── Cluster table render ──────────────────────────────────────────
            function renderClusterTable(items) {
                var html = '<table class="widefat fixed striped">'
                    + '<thead><tr>'
                    + '<th style="width:80px;">Tipo</th>'
                    + '<th>Keyword</th>'
                    + '<th style="width:120px;">Provedor</th>'
                    + '<th style="width:160px;">Status</th>'
                    + '<th>Resultado</th>'
                    + '</tr></thead>'
                    + '<tbody id="geo-cluster-tbody">';

                items.forEach(function(item, i) {
                    var badge = item.type === 'Pilar'
                        ? '<span style="background:#0073aa;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">Pilar</span>'
                        : '<span style="background:#888;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">Satelite</span>';
                    html += '<tr id="geo-crow-' + i + '">'
                        + '<td>' + badge + '</td>'
                        + '<td>' + esc(item.kw) + '</td>'
                        + '<td style="font-size:12px; color:#666;">' + esc(item.provider) + '</td>'
                        + '<td id="geo-cst-' + i + '"><span style="color:#999;">⏳ Aguardando</span></td>'
                        + '<td id="geo-cres-' + i + '">—</td>'
                        + '</tr>';
                });

                html += '</tbody></table>';
                document.getElementById('geo-cluster-table').innerHTML = html;
            }

            function processClusterNext(items, idx, language, postStatus, scheduledAt, mainKw, state) {
                var total   = items.length;
                var counter = document.getElementById('geo-cluster-counter');
                if (counter) {
                    counter.textContent = 'Artigo ' + (idx + 1) + '/' + total
                        + ' | ✅ ' + state.done + ' | ❌ ' + state.failed;
                }

                if (idx >= total) {
                    saveClusterRecord(state.pillarId, state.satelliteIds, mainKw);
                    if (counter) {
                        counter.innerHTML = '✅ Cluster finalizado! '
                            + state.done + ' gerados, '
                            + (state.failed > 0
                                ? '<span style="color:#dc3232;">' + state.failed + ' falhas</span>'
                                : '0 falhas');
                    }
                    document.getElementById('geo-cluster-btn').disabled = false;
                    return;
                }

                var item = items[idx];
                setCStatus(idx, '<span style="color:#0073aa;">⚙️ Gerando...</span>');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.timeout = 125000;

                xhr.onload = function() {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data && res.data.post_id) {
                            state.done++;
                            var pid = res.data.post_id;
                            if (item.type === 'Pilar') {
                                state.pillarId = pid;
                            } else {
                                state.satelliteIds.push(pid);
                            }
                            setCStatus(idx, '<span style="color:#46b450;font-weight:600;">✅ Gerado</span>');
                            setCResult(idx, '<a href="' + res.data.edit_url + '" target="_blank">'
                                + esc(res.data.title || item.kw) + '</a>');
                        } else {
                            state.failed++;
                            var msg = (res.data && res.data.message) ? res.data.message : 'Falha desconhecida';
                            setCStatus(idx, '<span style="color:#dc3232;font-weight:600;">❌ Falha</span>');
                            setCResult(idx, '<span style="color:#dc3232;font-size:12px;">' + esc(msg) + '</span>');
                        }
                    } catch(err) {
                        state.failed++;
                        setCStatus(idx, '<span style="color:#dc3232;">❌ Erro</span>');
                    }
                    processClusterNext(items, idx + 1, language, postStatus, scheduledAt, mainKw, state);
                };

                xhr.ontimeout = function() {
                    state.failed++;
                    setCStatus(idx, '<span style="color:#dc3232;">⏰ Timeout</span>');
                    processClusterNext(items, idx + 1, language, postStatus, scheduledAt, mainKw, state);
                };

                xhr.onerror = function() {
                    state.failed++;
                    setCStatus(idx, '<span style="color:#dc3232;">❌ Erro de rede</span>');
                    processClusterNext(items, idx + 1, language, postStatus, scheduledAt, mainKw, state);
                };

                xhr.send(
                    'action=geo_process_single_article'
                    + '&nonce='        + encodeURIComponent(NONCE)
                    + '&keyword='      + encodeURIComponent(item.kw)
                    + '&provider='     + encodeURIComponent(item.provider)
                    + '&language='     + encodeURIComponent(language)
                    + '&post_status='  + encodeURIComponent(postStatus)
                    + '&scheduled_at=' + encodeURIComponent(scheduledAt)
                    + '&category_id=' + encodeURIComponent(document.getElementById('geo_category_cluster') ? document.getElementById('geo_category_cluster').value : 'auto')
                    + '&is_cluster=1'
                    + '&source=cluster'
                    + '&cluster_role=' + encodeURIComponent(item.type === 'Pilar' ? 'pilar' : 'satelite')
                    + '&article_size=' + encodeURIComponent(item.type === 'Pilar' ? 'cluster_pillar' : 'cluster_satellite')
                    + '&embed_video=' + (document.getElementById('geo_embed_video_cluster') && document.getElementById('geo_embed_video_cluster').checked ? '1' : '0')
                    + '&image_source=' + encodeURIComponent(document.getElementById('geo_image_source_cluster') ? document.getElementById('geo_image_source_cluster').value : '')
                );
            }

            function saveClusterRecord(pillarId, satelliteIds, keyword) {
                if (!pillarId) return;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {};
                xhr.send(
                    'action=geo_save_cluster'
                    + '&nonce='          + encodeURIComponent(NONCE)
                    + '&pillar_id='      + encodeURIComponent(pillarId)
                    + '&satellite_ids='  + encodeURIComponent(JSON.stringify(satelliteIds))
                    + '&keyword='        + encodeURIComponent(keyword)
                );
            }

            function setCStatus(idx, html) {
                var el = document.getElementById('geo-cst-' + idx);
                if (el) el.innerHTML = html;
            }

            function setCResult(idx, html) {
                var el = document.getElementById('geo-cres-' + idx);
                if (el) el.innerHTML = html;
            }

            function esc(s) {
                var d = document.createElement('div');
                d.appendChild(document.createTextNode(String(s)));
                return d.innerHTML;
            }
        })();
        </script>
        <?php
    }

    private function render_cluster_list() {
        global $wpdb;

        $table    = $wpdb->prefix . 'geo_clusters';
        $clusters = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 20"
        );

        if (empty($clusters)) {
            echo '<p style="color:#666;">Nenhum cluster criado ainda.</p>';
            return;
        }

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>
                <th style="width:40px;">ID</th>
                <th>Keyword Principal</th>
                <th style="width:90px;">Pilar</th>
                <th style="width:100px;">Satelites</th>
                <th style="width:140px;">Criado em</th>
              </tr></thead><tbody>';

        foreach ($clusters as $cluster) {
            $satellites  = json_decode($cluster->satellite_post_ids, true) ?: [];
            $pillar_link = $cluster->pillar_post_id
                ? '<a href="' . esc_url(get_edit_post_link($cluster->pillar_post_id)) . '" target="_blank">#' . intval($cluster->pillar_post_id) . '</a>'
                : '—';

            echo '<tr>';
            echo '<td>' . intval($cluster->id) . '</td>';
            echo '<td>' . esc_html($cluster->keyword) . '</td>';
            echo '<td>' . $pillar_link . '</td>';
            echo '<td>' . count($satellites) . ' arts.</td>';
            echo '<td style="font-size:12px;">' . esc_html($cluster->created_at) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
