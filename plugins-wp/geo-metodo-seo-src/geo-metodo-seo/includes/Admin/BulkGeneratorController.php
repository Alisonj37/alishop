<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\TitleBank\SeoGeoTitleBank;

use GeoMetodoSEO\Helpers\SecurityHelper;
use GeoMetodoSEO\License\LicenseManager;
use GeoMetodoSEO\AI\ProviderResolver;

class BulkGeneratorController {

    private $providers = [
        'openai'     => 'OpenAI',
        'groq'       => 'Groq',
        'gemini'     => 'Gemini',
        'claude'     => 'Claude',
        'perplexity' => 'Perplexity',
        'naga'       => 'Naga.ac',
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
        if (!LicenseManager::can('bulk_generation')) {
            echo LicenseManager::lockedHtml('Geração em Massa');
            return;
        }
        $nonce          = wp_create_nonce('geo_process_article_nonce');
        $nonce_analysis = wp_create_nonce('geo_analysis_nonce');
        $provider = ProviderResolver::for('bulk_generation');
        $language = get_option('geo_default_language', 'pt-BR');

        // Fila atual de bulk (agendamento automático)
        $queue      = get_option('geo_scheduled_queue', []);
        $bulk_queue = array_values(array_filter($queue, fn($j) => ($j['type'] ?? '') === 'bulk'));

        // Sessão anterior incompleta
        $session          = get_option('geo_bulk_session', null);
        $has_resume       = false;
        $resume_total     = 0;
        $resume_done      = 0;
        $resume_kws_json  = '[]';
        $resume_session_id = '';

        if ($session && is_array($session)) {
            $age = time() - ($session['created_at'] ?? 0);
            if ($age < 86400) { // < 24h
                $pending_count = count(array_filter($session['keywords'] ?? [], fn($k) => $k['status'] === 'pending'));
                if ($pending_count > 0) {
                    $has_resume        = true;
                    $resume_total      = count($session['keywords']);
                    $resume_done       = count(array_filter($session['keywords'], fn($k) => $k['status'] === 'done'));
                    $resume_kws_json   = wp_json_encode($session['keywords']);
                    $resume_session_id = $session['id'] ?? '';
                }
            }
        }

        $notification_email = get_option('geo_notification_email', '');
        ?>
        <div class="wrap">
            <h1>Geracao em Massa</h1>
            <p class="description" style="font-size:14px;">
                Insira keywords (uma por linha). Gere imediatamente em tempo real ou agende automaticamente com até 5 artigos por dia.
            </p>

            <!-- ── Retomar sessao anterior ──────────────────────────────── -->
            <?php if ($has_resume): ?>
            <div style="margin:16px 0; padding:14px 18px; background:#fff8e1; border:1px solid #f0c040; border-radius:6px; display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                <span style="font-size:20px;">🔄</span>
                <div style="flex:1;">
                    <strong>Sessao anterior incompleta encontrada!</strong>
                    <span style="font-size:13px; color:#666; margin-left:8px;">
                        <?php echo intval($resume_done); ?> de <?php echo intval($resume_total); ?> artigos concluídos.
                    </span>
                </div>
                <button type="button" class="button button-primary" id="geo-resume-btn"
                        data-keywords="<?php echo esc_attr($resume_kws_json); ?>"
                        data-session="<?php echo esc_attr($resume_session_id); ?>">
                    ▶️ Retomar Geração
                </button>
                <button type="button" class="button" id="geo-discard-session-btn">
                    🗑️ Descartar
                </button>
            </div>
            <?php endif; ?>

            <div style="display:flex; gap:30px; flex-wrap:wrap; margin-top:20px; align-items:flex-start;">

                <!-- Formulario -->
                <div style="flex:1; min-width:300px; max-width:480px;">
                    <h2 style="font-size:16px;">📝 Keywords</h2>
                    <form id="geo-bulk-form">
                        <?php wp_nonce_field('geo_bulk_start', '_wpnonce_bulk'); ?>

                        <?php echo SeoGeoTitleBank::render_picker('#geo_keywords', 'multi'); ?>
                        <textarea id="geo_keywords" rows="12"
                                  style="width:100%; font-family:monospace; font-size:13px;"
                                  placeholder="como fazer SEO&#10;o que e GEO&#10;marketing digital 2025&#10;..."></textarea>

                        <table class="form-table" style="margin-top:12px;" role="presentation">
                            <tr>
                                <th style="padding:6px 0;"><label for="geo_provider_bulk">Provedor</label></th>
                                <td style="padding:6px 0;">
                                    <select id="geo_provider_bulk" class="regular-text">
                                        <?php foreach ($this->providers as $val => $label): ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($provider, $val); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:6px 0;"><label for="geo_language_bulk">Idioma</label></th>
                                <td style="padding:6px 0;">
                                    <select id="geo_language_bulk" class="regular-text">
                                        <?php foreach ($this->languages as $val => $label): ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($language, $val); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:6px 0;"><label for="geo_size_bulk">Tamanho</label></th>
                                <td style="padding:6px 0;">
                                    <select id="geo_size_bulk" class="regular-text">
                                        <option value="small"  <?php selected(get_option('geo_article_size','large'),'small'); ?>>📄 Pequeno — até 1.500 palavras</option>
                                        <option value="medium" <?php selected(get_option('geo_article_size','large'),'medium'); ?>>📋 Médio — até 2.200 palavras</option>
                                        <option value="large"  <?php selected(get_option('geo_article_size','large'),'large'); ?>>📚 Grande — até 3.500 palavras</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:6px 0;"><label for="geo_embed_video_bulk">Vídeo YouTube</label></th>
                                <td style="padding:6px 0;">
                                    <label style="display:flex;align-items:center;gap:8px;">
                                        <input type="checkbox" id="geo_embed_video_bulk" value="1">
                                        <span>Embedar vídeo relacionado ao tema em cada artigo</span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:6px 0;"><label for="geo_image_source_bulk">Imagens</label></th>
                                <td style="padding:6px 0;">
                                    <select id="geo_image_source_bulk" class="regular-text">
                                        <option value="">⚙️ Config global (<?php echo get_option('geo_image_source','ai') === 'library' ? 'Biblioteca' : 'IA'; ?>)</option>
                                        <option value="ai">🤖 Gerar com IA</option>
                                        <option value="library">🖼️ Minha Biblioteca</option>
                                    </select>
                                </td>
                            </tr>
                            <!-- Modo imediato: status -->
                            <tr id="geo-bulk-status-row">
                                <th style="padding:6px 0;"><label for="geo_status_bulk">Status</label></th>
                                <td style="padding:6px 0;">
                                    <select id="geo_status_bulk" class="regular-text"
                                            onchange="geoToggleScheduledBulk(this.value)">
                                        <?php foreach ($this->statuses as $val => $label): ?>
                                            <option value="<?php echo esc_attr($val); ?>">
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:6px 0;"><label for="geo_category_bulk">Categoria</label></th>
                                <td style="padding:6px 0;">
                                    <select id="geo_category_bulk" class="regular-text">
                                        <option value="auto">🤖 Automático (pela keyword)</option>
                                        <?php foreach (get_categories(['hide_empty' => false, 'orderby' => 'name']) as $cat): ?>
                                            <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                                        <?php endforeach; ?>
                                        <option value="_new_">✚ Criar nova pela keyword</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="geo-bulk-scheduled-row" style="display:none;">
                                <th style="padding:6px 0;"><label for="geo_scheduled_bulk">Agendar para</label></th>
                                <td style="padding:6px 0;">
                                    <input type="datetime-local" id="geo_scheduled_bulk" class="regular-text">
                                    <p class="description">Todos os artigos serao agendados para esta data/hora.</p>
                                </td>
                            </tr>
                        </table>

                        <!-- Toggle Agendamento Automático -->
                        <div style="margin-top:16px; padding:14px 16px; background:#f0f8ff; border:1px solid #b8d8f0; border-radius:6px;">
                            <label style="font-weight:600; font-size:14px; cursor:pointer;">
                                <input type="checkbox" id="geo-auto-schedule-toggle" style="margin-right:6px;">
                                📅 Agendamento Automático
                            </label>
                            <p class="description" style="margin:4px 0 0 22px;">
                                Máx. 5 artigos/dia · Intervalo de 4h · Publicação automática via WP-Cron
                            </p>

                            <div id="geo-auto-schedule-opts" style="display:none; margin-top:12px; padding-top:10px; border-top:1px solid #cde;">
                                <table role="presentation" style="border-spacing:0;">
                                    <tr>
                                        <td style="padding:4px 12px 4px 0; white-space:nowrap;">
                                            <label for="geo-start-time" style="font-size:13px;">⏰ Hora de início do 1º artigo:</label>
                                        </td>
                                        <td>
                                            <input type="time" id="geo-start-time" value="08:00"
                                                   class="regular-text" style="width:100px;">
                                        </td>
                                    </tr>
                                </table>
                                <p class="description" style="margin:6px 0 0;">
                                    Ex: início 08:00 → artigos às 08:00, 12:00, 16:00, 20:00, 00:00 · próximo dia: 08:00...
                                </p>
                            </div>
                        </div>

                        <p style="margin-top:16px;">
                            <button type="submit" id="geo-bulk-btn" class="button button-primary button-large">
                                ⚡ Iniciar Geracao
                            </button>
                            <button type="button" id="geo-schedule-btn"
                                    class="button button-primary button-large" style="display:none;">
                                📅 Agendar Publicacoes
                            </button>
                            &nbsp;
                            <button type="button" id="geo-bulk-check-cannib" class="button" style="margin-left:8px;">
                                ⚠️ Verificar Canibalizacao
                            </button>
                        </p>
                        <div id="geo-cannib-result" style="display:none;margin-top:12px;"></div>
                    </form>
                </div>

                <!-- Progresso / Resultado -->
                <div style="flex:2; min-width:400px;">
                    <h2 style="font-size:16px;">
                        📊 Progresso
                        <span id="geo-bulk-counter" style="font-size:13px; color:#666; font-weight:normal; margin-left:10px;"></span>
                    </h2>
                    <div id="geo-bulk-progress" style="display:none;">
                        <div id="geo-bulk-table"></div>
                    </div>
                    <div id="geo-bulk-placeholder" style="color:#888; font-size:14px;">
                        Preencha as keywords e clique em <strong>Iniciar Geracao</strong> ou <strong>Agendar Publicacoes</strong>.
                    </div>
                </div>
            </div>

            <!-- Fila de Agendamentos Bulk -->
            <div style="max-width:1000px; margin-top:40px;">
                <h2 style="font-size:16px; display:flex; align-items:center; gap:12px;">
                    📋 Fila de Agendamentos — Geração em Massa
                    <button type="button" id="geo-bulk-refresh" class="button button-small">🔄 Atualizar</button>
                    <button type="button" id="geo-bulk-clear" class="button button-small">🗑️ Limpar Concluídos</button>
                </h2>
                <div id="geo-bulk-queue-wrap">
                    <?php if (empty($bulk_queue)): ?>
                        <p style="color:#666;">Nenhum agendamento na fila.</p>
                    <?php else: ?>
                        <?php echo geo_render_schedule_table($bulk_queue); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var AJAX_URL            = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var NONCE               = '<?php echo esc_js($nonce); ?>';
            var NONCE_ANALYSIS      = '<?php echo esc_js($nonce_analysis); ?>';
            var NOTIFICATION_EMAIL  = '<?php echo esc_js($notification_email); ?>';
            var currentSessionId    = '';

            // Toggle status de data agendada (modo imediato)
            function geoToggleScheduledBulk(val) {
                var r = document.getElementById('geo-bulk-scheduled-row');
                if (r) r.style.display = (val === 'future') ? '' : 'none';
            }
            window.geoToggleScheduledBulk = geoToggleScheduledBulk;

            // Toggle agendamento automático
            var toggleChk = document.getElementById('geo-auto-schedule-toggle');
            var schedOpts = document.getElementById('geo-auto-schedule-opts');
            // Verificação de canibalização no Bulk
            document.getElementById('geo-bulk-check-cannib').addEventListener('click', function() {
                var raw = document.getElementById('geo_keywords') ? document.getElementById('geo_keywords').value : '';
                var keywords = raw.split('\n').map(k => k.trim()).filter(k => k.length > 0);
                if (!keywords.length) { alert('Adicione keywords primeiro.'); return; }
                var btn = this;
                btn.disabled = true; btn.textContent = '⏳ Verificando...';
                var resultDiv = document.getElementById('geo-cannib-result');
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<p style="color:#666;">Verificando ' + keywords.length + ' keywords...</p>';
                var html = '<h4 style="margin:0 0 8px;">Resultado da Verificação de Canibalização</h4>';
                var done = 0;
                keywords.forEach(function(kw) {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        done++;
                        try {
                            var r = JSON.parse(xhr.responseText);
                            if (r.success) html += r.data.html;
                        } catch(e) {}
                        if (done === keywords.length) {
                            resultDiv.innerHTML = html;
                            btn.disabled = false; btn.textContent = '⚠️ Verificar Canibalizacao';
                        }
                    };
                    xhr.send('action=geo_check_cannibalization&nonce=' + encodeURIComponent(NONCE_ANALYSIS) + '&keyword=' + encodeURIComponent(kw));
                });
            });

            var bulkBtn   = document.getElementById('geo-bulk-btn');
            var schedBtn  = document.getElementById('geo-schedule-btn');
            var statusRow = document.getElementById('geo-bulk-status-row');

            toggleChk.addEventListener('change', function() {
                var on = this.checked;
                schedOpts.style.display = on ? 'block' : 'none';
                bulkBtn.style.display   = on ? 'none'  : '';
                schedBtn.style.display  = on ? ''      : 'none';
                if (statusRow) statusRow.style.display = on ? 'none' : '';
            });

            // ── Resume sessao anterior ────────────────────────────────────
            var resumeBtn = document.getElementById('geo-resume-btn');
            if (resumeBtn) {
                resumeBtn.addEventListener('click', function() {
                    var savedKws   = JSON.parse(this.getAttribute('data-keywords') || '[]');
                    var sessionId  = this.getAttribute('data-session') || '';
                    currentSessionId = sessionId;

                    // Monta lista de todos + identifica os pendentes
                    var pending = savedKws.filter(function(k) { return k.status === 'pending'; });
                    var all     = savedKws;

                    if (!pending.length) { alert('Nenhuma keyword pendente.'); return; }

                    document.getElementById('geo-bulk-placeholder').style.display = 'none';
                    document.getElementById('geo-bulk-progress').style.display    = 'block';
                    this.disabled = true;

                    // Renderiza tabela completa (incluindo já feitos)
                    renderTableFromSession(all);

                    // Determina settings da sessão salva (pode não estar disponível, usa defaults)
                    var provider   = document.getElementById('geo_provider_bulk').value;
                    var language   = document.getElementById('geo_language_bulk').value;
                    var artSize    = document.getElementById('geo_size_bulk') ? document.getElementById('geo_size_bulk').value : 'large';
                    var postStatus = document.getElementById('geo_status_bulk') ? document.getElementById('geo_status_bulk').value : 'draft';
                    var categoryId = document.getElementById('geo_category_bulk') ? document.getElementById('geo_category_bulk').value : 'auto';
                    var scheduled  = '';

                    var counts = {
                        done:   all.filter(function(k){ return k.status === 'done'; }).length,
                        failed: all.filter(function(k){ return k.status === 'failed'; }).length
                    };

                    // Localiza indice dos pendentes e inicia a partir do primeiro
                    var pendingIndices = [];
                    all.forEach(function(k, i){ if(k.status === 'pending') pendingIndices.push(i); });
                    processResumeNext(all, pendingIndices, 0, provider, language, postStatus, scheduled, artSize, categoryId, counts);
                });
            }

            var discardBtn = document.getElementById('geo-discard-session-btn');
            if (discardBtn) {
                discardBtn.addEventListener('click', function() {
                    if (!confirm('Descartar sessao anterior?')) return;
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', AJAX_URL, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() { location.reload(); };
                    xhr.send('action=geo_bulk_discard_session&nonce=' + encodeURIComponent(NONCE));
                });
            }

            // ── Modo IMEDIATO ─────────────────────────────────────────────
            document.getElementById('geo-bulk-form').addEventListener('submit', function(e) {
                e.preventDefault();
                if (toggleChk.checked) return;

                var raw      = document.getElementById('geo_keywords').value;
                var keywords = raw.split('\n').map(function(k){return k.trim();}).filter(Boolean);
                if (!keywords.length) { alert('Adicione pelo menos uma keyword.'); return; }

                var provider    = document.getElementById('geo_provider_bulk').value;
                var language    = document.getElementById('geo_language_bulk').value;
                var artSize     = document.getElementById('geo_size_bulk') ? document.getElementById('geo_size_bulk').value : 'large';
                var postStatus  = document.getElementById('geo_status_bulk').value;
                var categoryId  = document.getElementById('geo_category_bulk') ? document.getElementById('geo_category_bulk').value : 'auto';
                var scheduledAt = document.getElementById('geo_scheduled_bulk')
                                    ? document.getElementById('geo_scheduled_bulk').value : '';

                bulkBtn.disabled = true;
                document.getElementById('geo-bulk-placeholder').style.display = 'none';
                document.getElementById('geo-bulk-progress').style.display    = 'block';

                renderTable(keywords);

                // Criar sessao no servidor
                var sessionPayload = 'action=geo_bulk_save_session'
                    + '&nonce='    + encodeURIComponent(NONCE)
                    + '&keywords=' + encodeURIComponent(JSON.stringify(keywords));
                var xhrSession = new XMLHttpRequest();
                xhrSession.open('POST', AJAX_URL, true);
                xhrSession.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhrSession.onload = function() {
                    try {
                        var res = JSON.parse(xhrSession.responseText);
                        if (res.success) currentSessionId = res.data.session_id;
                    } catch(e) {}
                };
                xhrSession.send(sessionPayload);

                processNext(keywords, 0, provider, language, postStatus, scheduledAt, categoryId, artSize, {done:0, failed:0}, []);
            });

            // ── Modo AGENDAMENTO AUTOMÁTICO ───────────────────────────────
            schedBtn.addEventListener('click', function() {
                var raw      = document.getElementById('geo_keywords').value;
                var keywords = raw.split('\n').map(function(k){return k.trim();}).filter(Boolean);
                if (!keywords.length) { alert('Adicione pelo menos uma keyword.'); return; }

                var provider  = document.getElementById('geo_provider_bulk').value;
                var language  = document.getElementById('geo_language_bulk').value;
                var artSize   = document.getElementById('geo_size_bulk') ? document.getElementById('geo_size_bulk').value : 'large';
                var categoryId = document.getElementById('geo_category_bulk') ? document.getElementById('geo_category_bulk').value : 'auto';
                var startTime = document.getElementById('geo-start-time').value || '08:00';

                schedBtn.disabled = true;
                document.getElementById('geo-bulk-placeholder').style.display = 'none';
                document.getElementById('geo-bulk-progress').style.display    = 'block';
                document.getElementById('geo-bulk-table').innerHTML =
                    '<p style="color:#0073aa;">⏳ Criando agendamento...</p>';

                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.timeout = 15000;

                xhr.onload = function() {
                    schedBtn.disabled = false;
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data && res.data.html) {
                            document.getElementById('geo-bulk-table').innerHTML = res.data.html;
                            document.getElementById('geo-bulk-counter').textContent =
                                res.data.count + ' artigos agendados';
                            refreshQueue();
                        } else {
                            var msg = (res.data && res.data.message) ? res.data.message : 'Erro desconhecido';
                            document.getElementById('geo-bulk-table').innerHTML =
                                '<p style="color:#dc3232;">❌ ' + msg + '</p>';
                        }
                    } catch(e) {
                        document.getElementById('geo-bulk-table').innerHTML =
                            '<p style="color:#dc3232;">❌ Erro ao processar resposta.</p>';
                    }
                };
                xhr.ontimeout = function() { schedBtn.disabled = false; alert('Timeout.'); };

                xhr.send(
                    'action=geo_schedule_bulk'
                    + '&nonce='     + encodeURIComponent(NONCE)
                    + '&keywords='  + encodeURIComponent(JSON.stringify(keywords))
                    + '&provider='  + encodeURIComponent(provider)
                    + '&language='  + encodeURIComponent(language)
                    + '&article_size=' + encodeURIComponent(artSize)
                    + '&category_id=' + encodeURIComponent(categoryId)
                    + '&embed_video=' + (document.getElementById('geo_embed_video_bulk') && document.getElementById('geo_embed_video_bulk').checked ? '1' : '0')
                    + '&image_source=' + encodeURIComponent(document.getElementById('geo_image_source_bulk') ? document.getElementById('geo_image_source_bulk').value : '')
                    + '&start_time='+ encodeURIComponent(startTime)
                );
            });

            // ── Fila: refresh + clear ─────────────────────────────────────
            function refreshQueue() {
                var wrap = document.getElementById('geo-bulk-queue-wrap');
                wrap.innerHTML = '<p style="color:#666;">Carregando...</p>';
                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success) wrap.innerHTML = res.data.html;
                    } catch(e) {}
                };
                xhr.send('action=geo_get_schedule_queue&nonce=' + encodeURIComponent(NONCE) + '&type=bulk');
            }

            document.getElementById('geo-bulk-refresh').addEventListener('click', refreshQueue);

            document.getElementById('geo-bulk-clear').addEventListener('click', function() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() { refreshQueue(); };
                xhr.send('action=geo_clear_schedule_done&nonce=' + encodeURIComponent(NONCE) + '&type=bulk');
            });

            // ── Processamento sequencial imediato ─────────────────────────
            function renderTable(keywords) {
                var html = '<table class="widefat fixed striped">'
                    + '<thead><tr><th style="width:36px;">#</th><th>Keyword</th>'
                    + '<th style="width:160px;">Status</th><th>Resultado</th>'
                    + '</tr></thead><tbody id="geo-bulk-tbody">';
                keywords.forEach(function(kw, i) {
                    html += '<tr id="geo-brow-' + i + '">'
                        + '<td>' + (i+1) + '</td><td>' + esc(kw) + '</td>'
                        + '<td id="geo-bst-' + i + '"><span style="color:#999;">⏳ Aguardando</span></td>'
                        + '<td id="geo-bres-' + i + '">—</td></tr>';
                });
                html += '</tbody></table>';
                document.getElementById('geo-bulk-table').innerHTML = html;
            }

            function renderTableFromSession(items) {
                var html = '<table class="widefat fixed striped">'
                    + '<thead><tr><th style="width:36px;">#</th><th>Keyword</th>'
                    + '<th style="width:160px;">Status</th><th>Resultado</th>'
                    + '</tr></thead><tbody id="geo-bulk-tbody">';
                items.forEach(function(item, i) {
                    var stHtml = '<span style="color:#999;">⏳ Aguardando</span>';
                    var resHtml = '—';
                    if (item.status === 'done') {
                        stHtml  = '<span style="color:#46b450;font-weight:600;">✅ Gerado</span>';
                        resHtml = item.title ? esc(item.title) : '✅';
                    } else if (item.status === 'failed') {
                        stHtml = '<span style="color:#dc3232;font-weight:600;">❌ Falha</span>';
                    }
                    html += '<tr id="geo-brow-' + i + '">'
                        + '<td>' + (i+1) + '</td><td>' + esc(item.kw) + '</td>'
                        + '<td id="geo-bst-' + i + '">' + stHtml + '</td>'
                        + '<td id="geo-bres-' + i + '">' + resHtml + '</td></tr>';
                });
                html += '</tbody></table>';
                document.getElementById('geo-bulk-table').innerHTML = html;
            }

            function processNext(keywords, idx, provider, language, postStatus, scheduledAt, categoryId, artSize, counts, results) {
                updateCounter(idx, keywords.length, counts);
                if (idx >= keywords.length) {
                    document.getElementById('geo-bulk-counter').innerHTML =
                        '✅ Finalizado — ' + counts.done + ' gerados, '
                        + (counts.failed > 0 ? '<span style="color:#dc3232;">' + counts.failed + ' falhas</span>' : '0 falhas');
                    document.getElementById('geo-bulk-btn').disabled = false;
                    if (NOTIFICATION_EMAIL) sendCompletionEmail(counts, results, 'Geracao em Massa');
                    return;
                }
                var kw = keywords[idx];
                setStatus(idx, '<span style="color:#0073aa;">⚙️ Gerando...</span>');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.timeout = 200000;

                xhr.onload = function() {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data && res.data.post_id) {
                            counts.done++;
                            var title = res.data.title || kw;
                            results.push(title);
                            setStatus(idx, '<span style="color:#46b450;font-weight:600;">✅ Gerado</span>');
                            setResult(idx, '<a href="' + res.data.edit_url + '" target="_blank">' + esc(title) + '</a>');
                            markProgress(idx, kw, 'done', res.data.post_id, title);
                        } else {
                            counts.failed++;
                            var msg = (res.data && res.data.message) ? res.data.message : 'Erro';
                            setStatus(idx, '<span style="color:#dc3232;font-weight:600;">❌ Falha</span>');
                            setResult(idx, '<span style="color:#dc3232;font-size:12px;">' + esc(msg) + '</span>');
                            markProgress(idx, kw, 'failed', 0, '');
                        }
                    } catch(err) {
                        counts.failed++;
                        setStatus(idx, '<span style="color:#dc3232;">❌ Erro</span>');
                        markProgress(idx, kw, 'failed', 0, '');
                    }
                    processNext(keywords, idx+1, provider, language, postStatus, scheduledAt, categoryId, artSize, counts, results);
                };
                xhr.ontimeout = function() {
                    counts.failed++;
                    setStatus(idx, '<span style="color:#dc3232;">⏰ Timeout</span>');
                    markProgress(idx, kw, 'failed', 0, '');
                    processNext(keywords, idx+1, provider, language, postStatus, scheduledAt, categoryId, artSize, counts, results);
                };
                xhr.onerror = function() {
                    counts.failed++;
                    setStatus(idx, '<span style="color:#dc3232;">❌ Rede</span>');
                    markProgress(idx, kw, 'failed', 0, '');
                    processNext(keywords, idx+1, provider, language, postStatus, scheduledAt, categoryId, artSize, counts, results);
                };
                xhr.send('action=geo_process_single_article'
                    + '&nonce='        + encodeURIComponent(NONCE)
                    + '&keyword='      + encodeURIComponent(kw)
                    + '&provider='     + encodeURIComponent(provider)
                    + '&language='     + encodeURIComponent(language)
                    + '&article_size=' + encodeURIComponent(artSize)
                    + '&post_status='  + encodeURIComponent(postStatus)
                    + '&scheduled_at=' + encodeURIComponent(scheduledAt)
                    + '&category_id='  + encodeURIComponent(categoryId)
                    + '&embed_video='  + (document.getElementById('geo_embed_video_bulk') && document.getElementById('geo_embed_video_bulk').checked ? '1' : '0')
                    + '&image_source=' + encodeURIComponent(document.getElementById('geo_image_source_bulk') ? document.getElementById('geo_image_source_bulk').value : ''));
            }

            function processResumeNext(allItems, pendingIndices, pIdx, provider, language, postStatus, scheduled, artSize, categoryId, counts) {
                var total = allItems.length;
                var pTotal = pendingIndices.length;
                updateCounter(counts.done + counts.failed, total, counts);

                if (pIdx >= pTotal) {
                    var results = allItems.filter(function(k){return k.status==='done';}).map(function(k){return k.title||k.kw;});
                    document.getElementById('geo-bulk-counter').innerHTML =
                        '✅ Finalizado — ' + counts.done + ' gerados, '
                        + (counts.failed > 0 ? '<span style="color:#dc3232;">' + counts.failed + ' falhas</span>' : '0 falhas');
                    var resumeBtn = document.getElementById('geo-resume-btn');
                    if (resumeBtn) resumeBtn.disabled = false;
                    if (NOTIFICATION_EMAIL) sendCompletionEmail(counts, results, 'Geracao em Massa (Retomada)');
                    return;
                }

                var realIdx = pendingIndices[pIdx];
                var kw      = allItems[realIdx].kw;
                setStatus(realIdx, '<span style="color:#0073aa;">⚙️ Gerando...</span>');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.timeout = 200000;

                xhr.onload = function() {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data && res.data.post_id) {
                            counts.done++;
                            var title = res.data.title || kw;
                            allItems[realIdx].status = 'done';
                            allItems[realIdx].title  = title;
                            setStatus(realIdx, '<span style="color:#46b450;font-weight:600;">✅ Gerado</span>');
                            setResult(realIdx, '<a href="' + res.data.edit_url + '" target="_blank">' + esc(title) + '</a>');
                            markProgress(realIdx, kw, 'done', res.data.post_id, title);
                        } else {
                            counts.failed++;
                            allItems[realIdx].status = 'failed';
                            setStatus(realIdx, '<span style="color:#dc3232;font-weight:600;">❌ Falha</span>');
                            markProgress(realIdx, kw, 'failed', 0, '');
                        }
                    } catch(err) {
                        counts.failed++;
                        allItems[realIdx].status = 'failed';
                        setStatus(realIdx, '<span style="color:#dc3232;">❌ Erro</span>');
                        markProgress(realIdx, kw, 'failed', 0, '');
                    }
                    processResumeNext(allItems, pendingIndices, pIdx+1, provider, language, postStatus, scheduled, artSize, categoryId, counts);
                };
                xhr.ontimeout  = function() {
                    counts.failed++;
                    allItems[realIdx].status = 'failed';
                    setStatus(realIdx, '<span style="color:#dc3232;">⏰ Timeout</span>');
                    markProgress(realIdx, kw, 'failed', 0, '');
                    processResumeNext(allItems, pendingIndices, pIdx+1, provider, language, postStatus, scheduled, artSize, categoryId, counts);
                };
                xhr.onerror = function() {
                    counts.failed++;
                    allItems[realIdx].status = 'failed';
                    setStatus(realIdx, '<span style="color:#dc3232;">❌ Rede</span>');
                    markProgress(realIdx, kw, 'failed', 0, '');
                    processResumeNext(allItems, pendingIndices, pIdx+1, provider, language, postStatus, scheduled, artSize, categoryId, counts);
                };
                xhr.send('action=geo_process_single_article'
                    + '&nonce='        + encodeURIComponent(NONCE)
                    + '&keyword='      + encodeURIComponent(kw)
                    + '&provider='     + encodeURIComponent(provider)
                    + '&language='     + encodeURIComponent(language)
                    + '&article_size=' + encodeURIComponent(artSize)
                    + '&post_status='  + encodeURIComponent(postStatus)
                    + '&scheduled_at=' + encodeURIComponent(scheduled)
                    + '&category_id='  + encodeURIComponent(categoryId)
                    + '&embed_video='  + (document.getElementById('geo_embed_video_bulk') && document.getElementById('geo_embed_video_bulk').checked ? '1' : '0')
                    + '&image_source=' + encodeURIComponent(document.getElementById('geo_image_source_bulk') ? document.getElementById('geo_image_source_bulk').value : ''));
            }

            // ── Salvar progresso no servidor ──────────────────────────────
            function markProgress(idx, kw, status, postId, title) {
                if (!currentSessionId) return;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {};
                xhr.send('action=geo_bulk_mark_progress'
                    + '&nonce='      + encodeURIComponent(NONCE)
                    + '&session_id=' + encodeURIComponent(currentSessionId)
                    + '&idx='        + encodeURIComponent(idx)
                    + '&status='     + encodeURIComponent(status)
                    + '&post_id='    + encodeURIComponent(postId)
                    + '&title='      + encodeURIComponent(title));
            }

            // ── Notificacao email ao final ────────────────────────────────
            function sendCompletionEmail(counts, titles, source) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {};
                xhr.send('action=geo_send_completion_email'
                    + '&nonce='   + encodeURIComponent(NONCE)
                    + '&source='  + encodeURIComponent(source)
                    + '&done='    + encodeURIComponent(counts.done)
                    + '&failed='  + encodeURIComponent(counts.failed)
                    + '&titles='  + encodeURIComponent(JSON.stringify(titles)));
            }

            function updateCounter(idx, total, counts) {
                var el = document.getElementById('geo-bulk-counter');
                if (el) el.textContent = 'Artigo ' + (idx+1) + '/' + total
                    + ' | ✅ ' + counts.done + ' | ❌ ' + counts.failed;
            }
            function setStatus(idx, html) { var el = document.getElementById('geo-bst-'+idx); if(el) el.innerHTML = html; }
            function setResult(idx, html) { var el = document.getElementById('geo-bres-'+idx); if(el) el.innerHTML = html; }
            function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(String(s))); return d.innerHTML; }
        })();
        </script>
        <?php
    }

    public function ajax_queue_status() {
        check_ajax_referer('geo_queue_status_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissao.'], 403);
        }
        global $wpdb;
        $table = $wpdb->prefix . 'geo_jobs';
        $jobs  = $wpdb->get_results(
            "SELECT id, keyword, status, created_at FROM {$table} ORDER BY created_at DESC LIMIT 30"
        );
        wp_send_json_success($jobs);
    }
}
