<?php

namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Services\SARAService;
use GeoMetodoSEO\Services\YouTubeToArticleService;
use GeoMetodoSEO\Services\TTSService;
use GeoMetodoSEO\License\LicenseManager;

/**
 * SARAController — Painel admin da IA Especialista SARA.
 * Inclui: chat com SARA, análise de keywords, análise de concorrentes,
 * auditoria de posts, geração de llms.txt, AI Visibility Score,
 * YouTube → Artigo, e gerenciamento de TTS.
 *
 * @since 1.0.0
 */
class SARAController {

    private function provider_select_html(string $id, string $current = '', string $style = ''): string {
        $current = \GeoMetodoSEO\AI\ProviderResolver::normalize($current);
        $providers = [
            '' => '— Provedor global —',
            'openai' => 'OpenAI / GPT',
            'groq' => 'Groq / Llama',
            'gemini' => 'Google Gemini',
            'claude' => 'Claude',
            'perplexity' => 'Perplexity',
            'naga' => 'Naga.ac',
        ];
        $style = $style ?: 'height:38px;padding:0 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;';
        $html = '<select id="' . esc_attr($id) . '" style="' . esc_attr($style) . '">';
        foreach ($providers as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    public function render_page(): void {
        $active_tab = sanitize_key($_GET['sara_tab'] ?? 'chat');
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:28px;">🤖</span>
                SARA — IA Especialista GEO/SEO/LLM
                <span style="font-size:12px;background:#7c3aed;color:#fff;padding:2px 10px;border-radius:20px;font-weight:400;">v1.0.0</span>
            </h1>
            <p style="color:#666;margin-top:-8px;">Specialized AI for Ranking & Authority — sua consultora de SEO, GEO e LLM Optimization</p>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <?php
                $tabs = [
                    'chat'        => '💬 Chat com SARA',
                    'keyword'     => '🔍 Analisar Keyword',
                    'competitor'  => '📊 Concorrentes SERP',
                    'audit'       => '🔎 Auditar Post',
                    'youtube'     => '▶️ YouTube → Artigo',
                    'tts'         => '🎧 TTS — Artigo → Áudio',
                    'visibility'  => '🌐 AI Visibility Score',
                    'llmstxt'     => '📄 llms.txt',
                    'webstories'  => '📱 Web Stories',
                    'diagnostico' => '🔧 Diagnóstico',
                ];
                foreach ($tabs as $tab_key => $tab_label) {
                    $active_class = $active_tab === $tab_key ? ' nav-tab-active' : '';
                    $url = admin_url("admin.php?page=geo-sara&sara_tab={$tab_key}");
                    echo "<a href=\"" . esc_url($url) . "\" class=\"nav-tab{$active_class}\">{$tab_label}</a>";
                }
                ?>
            </nav>

            <?php
            switch ($active_tab) {
                case 'chat':       $this->render_chat();       break;
                case 'keyword':    $this->render_keyword();    break;
                case 'competitor': $this->render_competitor(); break;
                case 'audit':      $this->render_audit();      break;
                case 'youtube':    $this->render_youtube();    break;
                case 'tts':        $this->render_tts();        break;
                case 'visibility': $this->render_visibility(); break;
                case 'llmstxt':    $this->render_llmstxt();    break;
                case 'webstories': $this->render_webstories(); break;
                case 'diagnostico': $this->render_diagnostico(); break;
            }
            ?>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    // TAB: Chat
    // ──────────────────────────────────────────────
    private function render_chat(): void {
        ?>
        <div style="max-width:860px;">
            <div style="background:#f9f7ff;border:1px solid #e0d6ff;border-radius:8px;padding:16px 20px;margin-bottom:20px;">
                <strong>💡 O que você pode perguntar para a SARA:</strong>
                <ul style="margin:8px 0 0 20px;color:#555;">
                    <li>"Quais keywords de cauda longa eu deveria escrever para o nicho de finanças pessoais?"</li>
                    <li>"Como eu melhoro meu E-E-A-T para o Google?"</li>
                    <li>"Explica o que é GEO e como aplicar no meu blog"</li>
                    <li>"Como estruturar um artigo para ranquear no ChatGPT?"</li>
                    <li>"Quais erros de SEO técnico mais prejudicam o ranqueamento?"</li>
                </ul>
            </div>

            <div id="sara-chat-box" style="background:#fff;border:1px solid #ddd;border-radius:8px;min-height:400px;max-height:600px;overflow-y:auto;padding:20px;margin-bottom:16px;">
                <div style="text-align:center;color:#888;padding:60px 0;">
                    <div style="font-size:48px;margin-bottom:12px;">🤖</div>
                    <p>Olá! Sou a SARA, sua consultora especialista em SEO, GEO e LLM Optimization.<br>
                    Como posso ajudar o seu site a ranquear melhor hoje?</p>
                </div>
            </div>

            <div style="display:flex;gap:8px;">
                <textarea id="sara-question" rows="3" placeholder="Digite sua pergunta para a SARA..." style="flex:1;border:1px solid #ddd;border-radius:6px;padding:10px;font-size:14px;resize:vertical;"></textarea>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <div style="margin-bottom:8px;display:flex;gap:8px;">
                            <button type="button" id="sara-view-memory" class="button" style="font-size:12px;padding:4px 10px;">🧠 Ver Memória</button>
                            <button type="button" id="sara-clear-memory" class="button" style="font-size:12px;padding:4px 10px;color:#dc3232;">🗑️ Limpar</button>
                        </div>
                        <div id="sara-memory-panel" style="display:none;background:#f9f9f9;border:1px solid #ddd;border-radius:8px;padding:12px;margin-bottom:12px;max-height:200px;overflow-y:auto;">
                            <div id="sara-memory-display"></div>
                        </div>
                        <?php echo $this->provider_select_html('sara-chat-provider', '', 'height:36px;padding:0 8px;border:1px solid #ddd;border-radius:4px;font-size:12px;max-width:170px;'); ?>
                        <button id="sara-send-btn" class="button button-primary" style="height:50px;padding:0 20px;">Enviar</button>
                    <button id="sara-clear-btn" class="button" style="height:42px;">Limpar</button>
                </div>
            </div>

            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                <span style="font-size:12px;color:#888;padding-top:6px;">Sugestões:</span>
                <?php
                $suggestions = [
                    'Como melhorar meu E-E-A-T?',
                    'O que é GEO e como usar?',
                    'Keywords de cauda longa para meu nicho',
                    'Como ranquear no ChatGPT?',
                    'Auditoria do meu conteúdo',
                ];
                foreach ($suggestions as $s):
                ?>
                <button class="sara-suggestion button" data-q="<?php echo esc_attr($s); ?>" style="font-size:12px;height:auto;padding:4px 10px;"><?php echo esc_html($s); ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        (function() {
            var chatBox = document.getElementById('sara-chat-box');
            var question = document.getElementById('sara-question');
            var sendBtn  = document.getElementById('sara-send-btn');
            var clearBtn = document.getElementById('sara-clear-btn');
            var history  = [];
            var initialized = false;

            function appendMessage(role, content) {
                if (!initialized) {
                    chatBox.innerHTML = '';
                    initialized = true;
                }
                var isUser = (role === 'user');
                var div = document.createElement('div');
                div.style.cssText = 'margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;' + (isUser ? 'flex-direction:row-reverse;' : '');
                var avatar = '<div style="width:34px;height:34px;border-radius:50%;background:' + (isUser ? '#0073aa' : '#7c3aed') + ';display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">' + (isUser ? '👤' : '🤖') + '</div>';
                var bubble = document.createElement('div');
                bubble.style.cssText = 'background:' + (isUser ? '#e8f4fd' : '#f9f7ff') + ';padding:12px 16px;border-radius:' + (isUser ? '12px 2px 12px 12px' : '2px 12px 12px 12px') + ';max-width:80%;font-size:14px;line-height:1.6;white-space:pre-wrap;';
                bubble.textContent = content;
                div.innerHTML = avatar;
                div.appendChild(bubble);
                chatBox.appendChild(div);
                chatBox.scrollTop = chatBox.scrollHeight;
            }

            function sendMessage() {
                var q = question.value.trim();
                if (!q) return;

                appendMessage('user', q);
                history.push({role: 'user', content: q});
                question.value = '';
                sendBtn.disabled = true;
                sendBtn.textContent = '...';

                var loadingDiv = document.createElement('div');
                loadingDiv.id = 'sara-loading';
                loadingDiv.style.cssText = 'display:flex;gap:10px;align-items:center;margin-bottom:16px;';
                loadingDiv.innerHTML = '<div style="width:34px;height:34px;border-radius:50%;background:#7c3aed;display:flex;align-items:center;justify-content:center;">🤖</div><div style="background:#f9f7ff;padding:12px 16px;border-radius:2px 12px 12px 12px;"><span style="animation:pulse 1s infinite;">Analisando...</span></div>';
                chatBox.appendChild(loadingDiv);
                chatBox.scrollTop = chatBox.scrollHeight;

                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    document.getElementById('sara-loading')?.remove();
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Enviar';
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            appendMessage('sara', resp.data.answer);
                            history.push({role: 'sara', content: resp.data.answer});
                        } else {
                            appendMessage('sara', 'Erro: ' + (resp.data?.message || 'Tente novamente'));
                        }
                    } catch(e) {
                        appendMessage('sara', 'Erro ao processar resposta.');
                    }
                };
                xhr.onerror = function() {
                    document.getElementById('sara-loading')?.remove();
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Enviar';
                    appendMessage('sara', 'Erro de conexão. Tente novamente.');
                };
                var prov = document.getElementById('sara-chat-provider') ? document.getElementById('sara-chat-provider').value : '';
                xhr.send('action=geo_sara_chat&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&question=' + encodeURIComponent(q) + '&history=' + encodeURIComponent(JSON.stringify(history.slice(-8))) + '&provider=' + encodeURIComponent(prov));
            }

            sendBtn.addEventListener('click', sendMessage);
            question.addEventListener('keydown', function(e) { if (e.ctrlKey && e.key === 'Enter') sendMessage(); });
            clearBtn.addEventListener('click', function() {
                history = [];
                initialized = false;
                chatBox.innerHTML = '<div style="text-align:center;color:#888;padding:60px 0;"><div style="font-size:48px;margin-bottom:12px;">🤖</div><p>Chat limpo. Como posso ajudar?</p></div>';
            });
            document.querySelectorAll('.sara-suggestion').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    question.value = this.dataset.q;
                    sendMessage();
                });
            });
        })();
        </script>
        <?php
    }

    // ──────────────────────────────────────────────
    // TAB: Keyword Analysis
    // ──────────────────────────────────────────────
    private function render_keyword(): void {
        ?>
        <div style="max-width:760px;">
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">
                <h3 style="margin-top:0;">🔍 Análise Completa de Keyword</h3>
                <p style="color:#666;">Digite uma keyword e a SARA vai analisar: dificuldade, intenção, keywords LSI, cauda longa, perguntas PAA, estrutura ideal, e como ranquear nos LLMs.</p>
                <div style="display:flex;gap:10px;margin-top:16px;">
                    <input type="text" id="kw-input" placeholder="Ex: melhores investimentos 2026" style="flex:1;height:38px;padding:0 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;">
                    <?php echo $this->provider_select_html('kw-provider'); ?>
                    <button id="kw-analyze-btn" class="button button-primary" style="height:38px;">Analisar</button>
                </div>
            </div>
            <div id="kw-result" style="display:none;"></div>
        </div>
        <script>
        document.getElementById('kw-analyze-btn').addEventListener('click', function() {
            var kw  = document.getElementById('kw-input').value.trim();
            var prov = document.getElementById('kw-provider').value;
            if (!kw) return;
            var btn = this;
            btn.disabled = true; btn.textContent = 'Analisando...';
            var res = document.getElementById('kw-result');
            res.style.display = 'block';
            res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:30px;text-align:center;color:#888;">🤖 SARA está analisando "<strong>' + kw + '</strong>"...<br><small>Isso pode levar 20-40 segundos</small></div>';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                btn.disabled = false; btn.textContent = 'Analisar';
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success && r.data) {
                        var d = r.data;
                        var oppColor = d.opportunity_score >= 70 ? '#16a34a' : (d.opportunity_score >= 40 ? '#b45309' : '#dc2626');
                        var geoColor = d.geo_score >= 70 ? '#7c3aed' : (d.geo_score >= 40 ? '#b45309' : '#dc2626');
                        res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">'
                            + '<h3 style="margin-top:0;">📊 ' + kw + '</h3>'
                            + '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">'
                            + '<div style="background:#f5f5f5;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:11px;color:#666;">Intenção</div><div style="font-size:18px;font-weight:600;">' + (d.intent || '—') + '</div></div>'
                            + '<div style="background:#f5f5f5;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:11px;color:#666;">Dificuldade</div><div style="font-size:18px;font-weight:600;">' + (d.difficulty || '—') + '</div></div>'
                            + '<div style="background:#f5f5f5;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:11px;color:#666;">Oportunidade SEO</div><div style="font-size:24px;font-weight:700;color:' + oppColor + ';">' + (d.opportunity_score || 0) + '</div></div>'
                            + '<div style="background:#f5f5f5;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:11px;color:#666;">Score GEO/IA</div><div style="font-size:24px;font-weight:700;color:' + geoColor + ';">' + (d.geo_score || 0) + '</div></div>'
                            + '</div>'
                            + (d.suggested_title ? '<p><strong>Título sugerido:</strong> ' + d.suggested_title + '</p>' : '')
                            + (d.content_angle ? '<p><strong>Ângulo editorial:</strong> ' + d.content_angle + '</p>' : '')
                            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">'
                            + (d.suggested_h2s?.length ? '<div><strong>H2s sugeridos:</strong><ul>' + d.suggested_h2s.map(h => '<li>' + h + '</li>').join('') + '</ul></div>' : '')
                            + (d.lsi_keywords?.length ? '<div><strong>Keywords LSI:</strong><ul>' + d.lsi_keywords.map(k => '<li>' + k + '</li>').join('') + '</ul></div>' : '')
                            + (d.long_tail?.length ? '<div><strong>Cauda longa:</strong><ul>' + d.long_tail.map(k => '<li>' + k + '</li>').join('') + '</ul></div>' : '')
                            + (d.paa_questions?.length ? '<div><strong>Perguntas PAA:</strong><ul>' + d.paa_questions.map(q => '<li>' + q + '</li>').join('') + '</ul></div>' : '')
                            + '</div>'
                            + (d.geo_recommendations?.length ? '<div style="margin-top:16px;background:#f9f7ff;padding:12px;border-radius:6px;border-left:3px solid #7c3aed;"><strong>🌐 GEO/IA Recommendations:</strong><ul>' + d.geo_recommendations.map(r => '<li>' + r + '</li>').join('') + '</ul></div>' : '')
                            + '</div>';
                    } else {
                        res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;color:#dc2626;">Erro: ' + (r.data?.message || 'Tente novamente') + '</div>';
                    }
                } catch(e) { res.innerHTML = '<div style="padding:20px;color:#dc2626;">Erro ao processar resposta</div>'; }
            };
            xhr.send('action=geo_sara_keyword&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&keyword=' + encodeURIComponent(kw) + '&provider=' + encodeURIComponent(prov));
        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────────
    // TAB: Competitor Analysis
    // ──────────────────────────────────────────────
    private function render_competitor(): void {
        ?>
        <div style="max-width:760px;">
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">
                <h3 style="margin-top:0;">📊 Análise de Concorrentes SERP</h3>
                <p style="color:#666;">A SARA analisa o cenário competitivo de uma keyword e identifica gaps de conteúdo, ângulos únicos e como superar os concorrentes.</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
                    <input type="text" id="comp-kw" placeholder="Keyword para analisar" style="flex:1;min-width:200px;height:38px;padding:0 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;">
                    <?php echo $this->provider_select_html('serp-provider', \GeoMetodoSEO\AI\ProviderResolver::for('article_generation')); ?>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;"><input type="checkbox" id="comp-websearch"> Busca web (SerpAPI)</label>
                    <button id="comp-analyze-btn" class="button button-primary" style="height:38px;">Analisar Concorrentes</button>
                </div>
            </div>
            <div id="comp-result" style="display:none;"></div>
        </div>
        <script>
        document.getElementById('comp-analyze-btn').addEventListener('click', function() {
            var kw = document.getElementById('comp-kw').value.trim();
            var ws = document.getElementById('comp-websearch').checked ? 1 : 0;
            if (!kw) return;
            var btn = this;
            btn.disabled = true; btn.textContent = 'Analisando...';
            var res = document.getElementById('comp-result');
            res.style.display = 'block';
            res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:30px;text-align:center;color:#888;">🤖 Analisando concorrentes para "<strong>' + kw + '</strong>"...<br><small>Com busca web pode demorar 30-60 segundos</small></div>';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                btn.disabled = false; btn.textContent = 'Analisar Concorrentes';
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success && r.data) {
                        var d = r.data;
                        res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">'
                            + '<h3 style="margin-top:0;">Análise: ' + kw + '</h3>'
                            + (d.serp_overview ? '<p style="background:#f5f5f5;padding:12px;border-radius:6px;">' + d.serp_overview + '</p>' : '')
                            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">'
                            + (d.content_gaps?.length ? '<div><strong>🎯 Gaps de Conteúdo:</strong><ul>' + d.content_gaps.map(g => '<li style="color:#16a34a;">' + g + '</li>').join('') + '</ul></div>' : '')
                            + (d.must_have_sections?.length ? '<div><strong>📋 Seções Obrigatórias:</strong><ul>' + d.must_have_sections.map(s => '<li>' + s + '</li>').join('') + '</ul></div>' : '')
                            + (d.differentiation_tips?.length ? '<div><strong>💡 Como se Diferenciar:</strong><ul>' + d.differentiation_tips.map(t => '<li style="color:#0073aa;">' + t + '</li>').join('') + '</ul></div>' : '')
                            + '</div>'
                            + (d.winning_angle ? '<div style="margin-top:16px;background:#e8f4fd;padding:12px;border-radius:6px;"><strong>🏆 Ângulo Vencedor:</strong> ' + d.winning_angle + '</div>' : '')
                            + (d.geo_opportunity ? '<div style="margin-top:12px;background:#f9f7ff;padding:12px;border-radius:6px;border-left:3px solid #7c3aed;"><strong>🌐 Oportunidade GEO/IA:</strong> ' + d.geo_opportunity + '</div>' : '')
                            + (d.difficulty_assessment ? '<div style="margin-top:12px;background:#fff8e1;padding:12px;border-radius:6px;"><strong>⚠️ Dificuldade:</strong> ' + d.difficulty_assessment + '</div>' : '')
                            + '</div>';
                    } else {
                        res.innerHTML = '<div style="padding:20px;color:#dc2626;">Erro: ' + (r.data?.message || 'Tente novamente') + '</div>';
                    }
                } catch(e) {
                    var raw = (xhr.responseText || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    if (raw.length > 500) raw = raw.substring(0, 500) + '...';
                    res.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:20px;color:#dc2626;">'
                        + '<strong>❌ Erro técnico no retorno do servidor</strong><br>'
                        + '<small>Status HTTP: ' + xhr.status + '</small><br>'
                        + '<p style="margin-top:8px;">O servidor não retornou JSON válido. Detalhe:</p>'
                        + '<pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:6px;max-height:180px;overflow:auto;">' + (raw || 'Resposta vazia') + '</pre>'
                        + '</div>';
                }
            };
            var serpProv = document.getElementById('serp-provider') ? document.getElementById('serp-provider').value : '<?php echo esc_js(\GeoMetodoSEO\AI\ProviderResolver::for('article_generation')); ?>';
            xhr.send('action=geo_sara_competitor&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&keyword=' + encodeURIComponent(kw) + '&web_search=' + ws + '&provider=' + encodeURIComponent(serpProv));
        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────────
    // TAB: Post Audit
    // ──────────────────────────────────────────────
    private function render_audit(): void {
        $posts = get_posts(['numberposts' => 50, 'post_status' => 'publish', 'orderby' => 'date']);
        ?>
        <div style="max-width:760px;">
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">
                <h3 style="margin-top:0;">🔎 Auditoria de Conteúdo</h3>
                <p style="color:#666;">A SARA analisa qualquer post publicado e retorna um score de SEO + GEO + E-E-A-T com melhorias priorizadas.</p>
                <div style="display:flex;gap:10px;margin-top:16px;">
                    <select id="audit-post" style="flex:1;height:38px;padding:0 8px;border:1px solid #ddd;border-radius:4px;font-size:14px;">
                        <option value="">Selecione um post...</option>
                        <?php foreach ($posts as $p): ?>
                        <option value="<?php echo $p->ID; ?>">[<?php echo $p->ID; ?>] <?php echo esc_html(wp_trim_words($p->post_title, 10)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $this->provider_select_html('audit-provider', \GeoMetodoSEO\AI\ProviderResolver::for('article_generation')); ?>
                    <button id="audit-btn" class="button button-primary" style="height:38px;">Auditar</button>
                </div>
            </div>
            <div id="audit-result" style="display:none;"></div>
        </div>
        <script>
        document.getElementById('audit-btn').addEventListener('click', function() {
            var pid = document.getElementById('audit-post').value;
            if (!pid) return;
            var btn = this;
            btn.disabled = true; btn.textContent = 'Auditando...';
            var res = document.getElementById('audit-result');
            res.style.display = 'block';
            res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:30px;text-align:center;color:#888;">🤖 SARA auditando post #' + pid + '...<br><small>30-50 segundos</small></div>';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                btn.disabled = false; btn.textContent = 'Auditar';
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success && r.data) {
                        var d = r.data;
                        var sc = d.overall_score || 0;
                        var scColor = sc >= 70 ? '#16a34a' : (sc >= 40 ? '#b45309' : '#dc2626');
                        res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">'
                            + '<div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">'
                            + '<div style="width:80px;height:80px;border-radius:50%;background:' + scColor + ';display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;">'
                            + '<div style="font-size:26px;font-weight:700;">' + sc + '</div>'
                            + '<div style="font-size:10px;">/ 100</div></div>'
                            + '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;flex:1;">'
                            + '<div style="text-align:center;background:#f5f5f5;padding:8px;border-radius:6px;"><div style="font-size:10px;color:#666;">SEO</div><div style="font-size:18px;font-weight:600;">' + (d.seo_score || 0) + '</div></div>'
                            + '<div style="text-align:center;background:#f5f5f5;padding:8px;border-radius:6px;"><div style="font-size:10px;color:#666;">GEO</div><div style="font-size:18px;font-weight:600;color:#7c3aed;">' + (d.geo_score || 0) + '</div></div>'
                            + '<div style="text-align:center;background:#f5f5f5;padding:8px;border-radius:6px;"><div style="font-size:10px;color:#666;">E-E-A-T</div><div style="font-size:18px;font-weight:600;">' + (d.eeat_score || 0) + '</div></div>'
                            + '<div style="text-align:center;background:#f5f5f5;padding:8px;border-radius:6px;"><div style="font-size:10px;color:#666;">Legib.</div><div style="font-size:18px;font-weight:600;">' + (d.readability_score || 0) + '</div></div>'
                            + '</div></div>'
                            + (d.critical_issues?.length ? '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:12px;margin-bottom:12px;"><strong>❌ Problemas Críticos:</strong><ul>' + d.critical_issues.map(i => '<li style="color:#dc2626;">' + i + '</li>').join('') + '</ul></div>' : '')
                            + (d.improvements?.length ? '<div><strong>🎯 Melhorias Priorizadas:</strong><table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:13px;"><thead><tr style="background:#f5f5f5;"><th style="padding:8px;text-align:left;">Prioridade</th><th style="padding:8px;text-align:left;">Ação</th><th style="padding:8px;text-align:left;">Impacto</th></tr></thead><tbody>' + d.improvements.map(i => '<tr style="border-bottom:1px solid #eee;"><td style="padding:8px;"><span style="background:' + (i.priority==='alta' ? '#fef2f2;color:#dc2626' : i.priority==='média' ? '#fff8e1;color:#b45309' : '#f0f9f0;color:#16a34a') + ';padding:2px 8px;border-radius:4px;font-size:11px;">' + i.priority + '</span></td><td style="padding:8px;">' + i.action + '</td><td style="padding:8px;color:#666;">' + i.impact + '</td></tr>').join('') + '</tbody></table></div>' : '')
                            + (d.geo_improvements?.length ? '<div style="margin-top:16px;background:#f9f7ff;padding:12px;border-radius:6px;border-left:3px solid #7c3aed;"><strong>🌐 Melhorias GEO/IA:</strong><ul>' + d.geo_improvements.map(g => '<li>' + g + '</li>').join('') + '</ul></div>' : '')
                            + (d.overall_score < 90
    ? '<div style="margin-top:20px;border-top:1px solid #eee;padding-top:20px;">'
    + '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px;">'
    + '<div style="font-size:22px;margin-bottom:10px;">🛠️ O que fazer com essas melhorias?</div>'
    + '<p style="color:#666;font-size:13px;margin:0 0 16px;">Escolha como a SARA deve aplicar as melhorias identificadas:</p>'
    + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'
    + '<div style="background:#fff;border:2px solid #0073aa;border-radius:8px;padding:16px;">'
    + '<div style="font-size:24px;margin-bottom:8px;">🎯</div>'
    + '<strong style="color:#0073aa;display:block;margin-bottom:6px;">Melhorias Pontuais</strong>'
    + '<p style="font-size:12px;color:#666;margin:0 0 12px;">Aplica cada correção exatamente onde o problema está, sem reescrever o resto. Ideal para artigos quase bons.</p>'
    + '<button id="geo-apply-surgical-btn" data-post-id="' + pid + '" style="width:100%;background:#0073aa;color:#fff;border:none;padding:10px;border-radius:6px;font-weight:600;font-size:13px;cursor:pointer;">✅ Aplicar Pontuamente</button>'
    + '</div>'
    + '<div style="background:#fff;border:2px solid #7c3aed;border-radius:8px;padding:16px;">'
    + '<div style="font-size:24px;margin-bottom:8px;">🔄</div>'
    + '<strong style="color:#7c3aed;display:block;margin-bottom:6px;">Reescrever Artigo</strong>'
    + '<p style="font-size:12px;color:#666;margin:0 0 12px;">A SARA reescreve o artigo inteiro com todas as melhorias aplicadas. Para artigos com score baixo.</p>'
    + '<button id="geo-apply-improvements-btn" data-post-id="' + pid + '" style="width:100%;background:#7c3aed;color:#fff;border:none;padding:10px;border-radius:6px;font-weight:600;font-size:13px;cursor:pointer;">🔄 Reescrever Tudo</button>'
    + '</div>'
    + '</div>'
    + '</div>'
    + '</div>'
    : '<div style="margin-top:16px;background:#f0f9f0;border:1px solid #86efac;border-radius:8px;padding:12px;text-align:center;"><strong style="color:#16a34a;">🏆 Artigo já está com boa pontuação!</strong></div>')
                            + '</div>';

                        // Listener: Melhorias Pontuais (cirúrgico)
                        setTimeout(function() {
                            var surgicalBtn = document.getElementById('geo-apply-surgical-btn');
                            if (surgicalBtn) {
                                surgicalBtn.addEventListener('click', function() {
                                    var btn = this;
                                    var postId = btn.getAttribute('data-post-id');
                                    btn.disabled = true;
                                    btn.textContent = '⏳ Aplicando...';
                                    var xhrSurgical = new XMLHttpRequest();
                                    xhrSurgical.open('POST', ajaxurl);
                                    xhrSurgical.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                                    xhrSurgical.timeout = 150000;
                                    xhrSurgical.onload = function() {
                                        try {
                                            var sr = JSON.parse(xhrSurgical.responseText);
                                            if (sr.success) {
                                                res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;">'
                                                    + '<div style="text-align:center;margin-bottom:20px;">'
                                                    + '<div style="font-size:48px;">🎯</div>'
                                                    + '<h3 style="color:#0073aa;margin:8px 0;">Melhorias Aplicadas Pontualmente!</h3>'
                                                    + '<p style="color:#666;">' + (sr.data.count || 0) + ' correções aplicadas nas seções específicas</p>'
                                                    + '</div>'
                                                    + '<ul style="text-align:left;">' + (sr.data.applied || []).map(function(a){ return '<li style="margin-bottom:6px;color:#555;">✅ ' + a + '</li>'; }).join('') + '</ul>'
                                                    + '<div style="text-align:center;margin-top:16px;display:flex;gap:12px;justify-content:center;">'
                                                    + '<a href="' + sr.data.edit_url + '" target="_blank" class="button button-primary">✏️ Ver no Editor</a>'
                                                    + '<a href="' + sr.data.view_url + '" target="_blank" class="button">👁️ Ver Artigo</a>'
                                                    + '</div>'
                                                    + '</div>';
                                            } else {
                                                btn.disabled = false; btn.textContent = '✅ Aplicar Pontualmente';
                                                alert('Erro: ' + (sr.data?.message || 'Tente novamente'));
                                            }
                                        } catch(e) { btn.disabled = false; btn.textContent = '✅ Aplicar Pontualmente'; }
                                    };
                                    xhrSurgical.ontimeout = function() { btn.disabled = false; btn.textContent = '✅ Aplicar Pontualmente'; };
                                    var auditProv = document.getElementById('audit-provider') ? document.getElementById('audit-provider').value : '';
                                    xhrSurgical.send('action=geo_sara_surgical_improvements&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&post_id=' + postId + '&provider=' + encodeURIComponent(auditProv));
                                });
                            }
                        }, 100);

                        // Listener: Reescrever Artigo Completo
                        setTimeout(function() {
                            var applyBtn = document.getElementById('geo-apply-improvements-btn');
                            if (applyBtn) {
                                applyBtn.addEventListener('click', function() {
                                    var btn = this;
                                    var postId = btn.getAttribute('data-post-id');
                                    btn.disabled = true;
                                    btn.textContent = '⏳ SARA aplicando melhorias...';
                                    btn.style.opacity = '0.7';

                                    // Mostrar progresso
                                    var progressDiv = document.createElement('div');
                                    progressDiv.style.cssText = 'margin-top:12px;background:rgba(255,255,255,0.2);border-radius:6px;padding:10px;font-size:12px;';
                                    progressDiv.textContent = '🤖 Analisando problemas e reescrevendo seções...';
                                    btn.parentNode.appendChild(progressDiv);

                                    var xhrApply = new XMLHttpRequest();
                                    xhrApply.open('POST', ajaxurl);
                                    xhrApply.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                                    xhrApply.timeout = 150000;
                                    xhrApply.onload = function() {
                                        try {
                                            var applyRes = JSON.parse(xhrApply.responseText);
                                            if (applyRes.success) {
                                                var aData = applyRes.data;
                                                res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;">'
                                                    + '<div style="text-align:center;margin-bottom:20px;">'
                                                    + '<div style="font-size:48px;">🎉</div>'
                                                    + '<h3 style="color:#16a34a;margin:8px 0;">Melhorias Aplicadas!</h3>'
                                                    + '<p style="color:#666;">' + (aData.improvements_applied || 0) + ' melhorias aplicadas com sucesso</p>'
                                                    + '</div>'
                                                    + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">'
                                                    + '<div style="background:#f0f9f0;border-radius:8px;padding:14px;text-align:center;">'
                                                    + '<div style="font-size:11px;color:#666;">Score Antes</div>'
                                                    + '<div style="font-size:32px;font-weight:700;color:#888;">' + (aData.score_before || 0) + '</div>'
                                                    + '</div>'
                                                    + '<div style="background:#f0f9f0;border-radius:8px;padding:14px;text-align:center;">'
                                                    + '<div style="font-size:11px;color:#666;">Score Estimado</div>'
                                                    + '<div style="font-size:32px;font-weight:700;color:#16a34a;">' + (aData.score_after || 0) + '+</div>'
                                                    + '</div>'
                                                    + '</div>'
                                                    + '<div style="background:#f9f7ff;border-radius:8px;padding:14px;margin-bottom:16px;">'
                                                    + '<strong>📋 Melhorias Aplicadas:</strong><ul style="margin:8px 0 0;">'
                                                    + (aData.applied_list || []).map(function(item) { return '<li style="color:#555;margin-bottom:4px;">' + item + '</li>'; }).join('')
                                                    + '</ul></div>'
                                                    + '<div style="text-align:center;display:flex;gap:12px;justify-content:center;">'
                                                    + '<a href="' + aData.edit_url + '" target="_blank" class="button button-primary">✏️ Ver no Editor</a>'
                                                    + '<a href="' + aData.view_url + '" target="_blank" class="button">👁️ Ver Artigo</a>'
                                                    + '</div>'
                                                    + '</div>';
                                            } else {
                                                btn.disabled = false;
                                                btn.textContent = '✅ Aplicar Melhorias e Republicar';
                                                btn.style.opacity = '1';
                                                progressDiv.textContent = '❌ Erro: ' + (applyRes.data?.message || 'Tente novamente');
                                                progressDiv.style.background = 'rgba(220,38,38,0.2)';
                                            }
                                        } catch(e) {
                                            btn.disabled = false; btn.textContent = '✅ Aplicar Melhorias e Republicar';
                                        }
                                    };
                                    xhrApply.ontimeout = function() {
                                        btn.disabled = false; btn.textContent = '✅ Aplicar Melhorias e Republicar';
                                        progressDiv.textContent = '⏱️ Timeout — o artigo pode ser muito longo. Tente novamente.';
                                    };
                                    var applyProv = document.getElementById('audit-provider') ? document.getElementById('audit-provider').value : '';
                                    xhrApply.send('action=geo_sara_apply_improvements&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&post_id=' + postId + '&provider=' + encodeURIComponent(applyProv));
                                });
                            }
                        }, 100);

                    } else {
                        res.innerHTML = '<div style="padding:20px;color:#dc2626;">Erro: ' + (r.data?.message || 'Tente novamente') + '</div>';
                    }
                } catch(e) {
                    var raw = (xhr.responseText || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    if (raw.length > 500) raw = raw.substring(0, 500) + '...';
                    res.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:20px;color:#dc2626;">'
                        + '<strong>❌ Erro técnico no retorno do servidor</strong><br>'
                        + '<small>Status HTTP: ' + xhr.status + '</small><br>'
                        + '<p style="margin-top:8px;">O servidor não retornou JSON válido. Detalhe:</p>'
                        + '<pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:6px;max-height:180px;overflow:auto;">' + (raw || 'Resposta vazia') + '</pre>'
                        + '</div>';
                }
            };
            var auditProv = document.getElementById('audit-provider') ? document.getElementById('audit-provider').value : '';
            xhr.send('action=geo_sara_audit&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&post_id=' + pid + '&provider=' + encodeURIComponent(auditProv));
        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────────
    // TAB: YouTube
    // ──────────────────────────────────────────────
    private function render_youtube(): void {
        $yt = new YouTubeToArticleService();
        $categories = get_categories(['hide_empty' => false, 'number' => 50]);
        ?>
        <div style="max-width:760px;">
            <?php if (!$yt->isConfigured()): ?>
            <div style="background:#fff8e1;border:1px solid #fcd34d;border-radius:8px;padding:16px;margin-bottom:20px;">
                ⚠️ Configure a <strong>YouTube Data API Key</strong> em Configurações para usar esta funcionalidade.
                <a href="<?php echo admin_url('admin.php?page=geo-settings'); ?>">Ir para Configurações →</a>
            </div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">
                <h3 style="margin-top:0;">▶️ YouTube → Artigo SEO</h3>
                <p style="color:#666;">Cole a URL de qualquer vídeo do YouTube. A SARA extrai a transcrição, metadados e cria um artigo SEO completo e original.</p>

                <div style="margin-top:16px;">
                    <label style="font-weight:600;font-size:13px;">URL do Vídeo</label>
                    <input type="url" id="yt-url" placeholder="https://www.youtube.com/watch?v=..." style="width:100%;height:38px;padding:0 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;margin-top:4px;box-sizing:border-box;">
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
                    <div>
                        <label style="font-weight:600;font-size:13px;">Idioma</label>
                        <select id="yt-lang" style="width:100%;height:38px;padding:0 8px;border:1px solid #ddd;border-radius:4px;margin-top:4px;">
                            <option value="pt-BR">Português (BR)</option>
                            <option value="en">Inglês</option>
                            <option value="es">Espanhol</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight:600;font-size:13px;">Provedor</label>
                        <select id="yt-provider" style="width:100%;height:38px;padding:0 8px;border:1px solid #ddd;border-radius:4px;margin-top:4px;margin-bottom:6px;" onchange="geoUpdateYtModels(this.value)">
                            <option value="">— Provedor global —</option>
                            <option value="openai">OpenAI</option>
                            <option value="groq">Groq</option>
                            <option value="gemini">Gemini</option>
                            <option value="claude">Claude</option>
                            <option value="perplexity">Perplexity</option>
                            <option value="naga">Naga.ac</option>
                        </select>
                        <label style="font-weight:600;font-size:13px;display:block;margin-top:8px;">Modelo</label>
                        <select id="yt-model" style="width:100%;height:38px;padding:0 8px;border:1px solid #ddd;border-radius:4px;margin-top:4px;">
                            <option value="">— Modelo padrão do provedor —</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight:600;font-size:13px;">Categoria</label>
                        <select id="yt-category" style="width:100%;height:38px;padding:0 8px;border:1px solid #ddd;border-radius:4px;margin-top:4px;">
                            <option value="">Sem categoria</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat->term_id; ?>"><?php echo esc_html($cat->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight:600;font-size:13px;">Status</label>
                        <select id="yt-status" style="width:100%;height:38px;padding:0 8px;border:1px solid #ddd;border-radius:4px;margin-top:4px;">
                            <option value="draft">Rascunho</option>
                            <option value="publish">Publicar</option>
                            <option value="pending">Pendente revisão</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight:600;font-size:13px;">Meta de palavras</label>
                        <input type="number" id="yt-word-count" min="2300" max="4000" step="100"
                               value="<?php echo esc_attr(\GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller::get('youtube_word_count_target', '2600')); ?>"
                               style="width:100%;height:38px;padding:0 8px;border:1px solid #ddd;border-radius:4px;margin-top:4px;">
                        <small style="color:#666;display:block;margin-top:4px;">O plugin conta as palavras reais e expande se ficar abaixo.</small>
                    </div>
                </div>

                <button id="yt-generate-btn" class="button button-primary" style="margin-top:16px;height:40px;padding:0 24px;" <?php echo !$yt->isConfigured() ? 'disabled' : ''; ?>>
                    🎬 Gerar Artigo do Vídeo
                </button>
            </div>

            <div id="yt-result" style="display:none;"></div>
        </div>
        <script>
        document.getElementById('yt-generate-btn').addEventListener('click', function() {
            var url    = document.getElementById('yt-url').value.trim();
            var lang   = document.getElementById('yt-lang').value;
            var cat    = document.getElementById('yt-category').value;
            var status = document.getElementById('yt-status').value;
            var words  = document.getElementById('yt-word-count').value;
            if (!url) { alert('Cole a URL do vídeo'); return; }
            var btn = this;
            btn.disabled = true; btn.textContent = '⏳ Gerando artigo...';
            var res = document.getElementById('yt-result');
            res.style.display = 'block';
            res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:30px;text-align:center;color:#888;"><div style="font-size:40px;">🎬</div><p>Extraindo transcrição e gerando artigo com IA...<br><small>Pode demorar 30-60 segundos</small></p></div>';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                btn.disabled = false; btn.textContent = '🎬 Gerar Artigo do Vídeo';
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        res.innerHTML = '<div style="background:#f0f9f0;border:1px solid #86efac;border-radius:8px;padding:20px;">'
                            + '<h3 style="margin-top:0;color:#16a34a;">✅ Artigo criado com sucesso!</h3>'
                            + '<p><strong>Post ID:</strong> ' + r.data.post_id + '</p>'
                            + '<p><strong>Título:</strong> ' + r.data.title + '</p>'
                            + (r.data.word_count ? '<p><strong>Palavras:</strong> ' + r.data.word_count + ' / meta ' + (r.data.word_target || '-') + '</p>' : '')
                            + '<a href="' + r.data.edit_url + '" class="button button-primary" target="_blank">Editar Artigo</a> '
                            + '<a href="' + r.data.view_url + '" class="button" target="_blank">Ver no Site</a>'
                            + '</div>';
                    } else {
                        res.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:20px;color:#dc2626;">❌ Erro: ' + (r.data?.message || 'Tente novamente') + '</div>';
                    }
                } catch(e) {
                    var raw = (xhr.responseText || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    if (raw.length > 500) raw = raw.substring(0, 500) + '...';
                    res.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:20px;color:#dc2626;">'
                        + '<strong>❌ Erro técnico no retorno do servidor</strong><br>'
                        + '<small>Status HTTP: ' + xhr.status + '</small><br>'
                        + '<p style="margin-top:8px;">O servidor não retornou JSON válido. Detalhe:</p>'
                        + '<pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:6px;max-height:180px;overflow:auto;">' + (raw || 'Resposta vazia') + '</pre>'
                        + '</div>';
                }
            };
            var model    = document.getElementById('yt-model').value;
            var provider = document.getElementById('yt-provider').value;
            xhr.send('action=geo_youtube_to_article&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&url=' + encodeURIComponent(url) + '&lang=' + encodeURIComponent(lang) + '&category=' + encodeURIComponent(cat) + '&status=' + encodeURIComponent(status) + '&model=' + encodeURIComponent(model) + '&provider=' + encodeURIComponent(provider) + '&word_count=' + encodeURIComponent(words));
        });

        // Atualizar modelos quando provedor muda
        var ytModels = {
            'openai':     [['','— Padrão —'],['gpt-5.5','GPT-5.5 ⭐ Artigo qualidade máxima'],['gpt-5.5-pro','GPT-5.5 Pro 🧠'],['gpt-5.4','GPT-5.4 ⭐'],['gpt-5','GPT-5 compatível'],['gpt-4.1','GPT-4.1 fallback'],['gpt-4.1-mini','GPT-4.1 Mini fallback'],['o4-mini','o4-mini']],
            'claude':     [['','— Padrão —'],['claude-sonnet-4-6','Claude Sonnet 4.6 ⭐'],['claude-sonnet-4-5','Claude Sonnet 4.5'],['claude-opus-4-6','Claude Opus 4.6'],['claude-haiku-4-5-20251001','Claude Haiku 4.5']],
            'gemini':     [['','— Padrão —'],['gemini-3.1-flash-lite','Gemini 3.1 Flash Lite ⭐'],['gemini-2.5-flash-lite','Gemini 2.5 Flash Lite'],['gemini-2.5-flash','Gemini 2.5 Flash'],['gemini-3-flash','Gemini 3 Flash']],
            'groq':       [['','— Padrão —'],['openai/gpt-oss-120b','GPT OSS 120B ⭐ Premium'],['llama-3.3-70b-versatile','Llama 3.3 70B ✅ Produção'],['openai/gpt-oss-20b','GPT OSS 20B (rápido)'],['llama-3.1-8b-instant','Llama 3.1 8B Instant'],['groq/compound-mini','Compound Mini (pesquisa)'],['groq/compound','Compound (pesquisa avançada)'],['qwen/qwen3-32b','Qwen 3 32B (preview)'],['meta-llama/llama-4-scout-17b-16e-instruct','Llama 4 Scout (multimodal)']],
            'perplexity': [['','— Padrão —'],['sonar-pro','Sonar Pro ⭐'],['sonar','Sonar'],['sonar-reasoning-pro','Sonar Reasoning Pro']],
            'naga':       [['','— Padrão —'],['gemini-2.5-flash:free','Gemini 2.5 Flash Free'],['glm-4.5-air:free','GLM 4.5 Air Free'],['llama-3.1-8b-instruct:free','Llama 3.1 8B Free']],
        };
        function geoUpdateYtModels(prov) {
            var sel = document.getElementById('yt-model');
            sel.innerHTML = '';
            if (!prov) { prov = '<?php echo esc_js(\GeoMetodoSEO\AI\ProviderResolver::for('youtube_article')); ?>'; }
            var opts = ytModels[prov] || [['','— Modelo padrão do provedor —']];
            opts.forEach(function(o) {
                var opt = document.createElement('option');
                opt.value = o[0]; opt.text = o[1];
                sel.appendChild(opt);
            });
        }
        geoUpdateYtModels(document.getElementById('yt-provider') ? document.getElementById('yt-provider').value : '');
        </script>
        <?php
    }

    // ──────────────────────────────────────────────
    // TAB: TTS
    // ──────────────────────────────────────────────
    private function render_tts(): void {
        $tts = new TTSService();
        $posts = get_posts(['numberposts' => 50, 'post_status' => 'publish', 'orderby' => 'date']);
        ?>
        <div style="max-width:760px;">
            <?php if (!$tts->isConfigured()): ?>
            <div style="background:#fff8e1;border:1px solid #fcd34d;border-radius:8px;padding:16px;margin-bottom:20px;">
                ⚠️ Configure pelo menos uma API de TTS (OpenAI, ElevenLabs ou Google) em Configurações.
                <a href="<?php echo admin_url('admin.php?page=geo-settings'); ?>">Ir para Configurações →</a>
            </div>
            <?php else: ?>
            <div style="background:#f0f9f0;border:1px solid #86efac;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;">
                ✅ Provedor ativo: <strong><?php echo ucfirst($tts->getProvider()); ?></strong>
            </div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">
                <h3 style="margin-top:0;">🎧 Converter Artigos em Áudio</h3>
                <p style="color:#666;">Gera um arquivo MP3 do artigo e insere um player de áudio no topo do post. Aumenta tempo de sessão e acessibilidade.</p>

                <select id="tts-post" style="width:100%;height:38px;padding:0 8px;border:1px solid #ddd;border-radius:4px;font-size:14px;margin-bottom:12px;">
                    <option value="">Selecione um post...</option>
                    <?php foreach ($posts as $p):
                        $has_audio = get_post_meta($p->ID, '_geo_audio_url', true);
                    ?>
                    <option value="<?php echo $p->ID; ?>">[<?php echo $p->ID; ?>] <?php echo esc_html(wp_trim_words($p->post_title, 10)); ?><?php echo $has_audio ? ' 🎧' : ''; ?></option>
                    <?php endforeach; ?>
                </select>

                <div style="display:flex;gap:8px;">
                    <button id="tts-generate-btn" class="button button-primary" style="height:38px;" <?php echo !$tts->isConfigured() ? 'disabled' : ''; ?>>🎧 Gerar Áudio</button>
                    <button id="tts-remove-btn" class="button" style="height:38px;">🗑️ Remover Áudio</button>
                </div>
            </div>
            <div id="tts-result" style="display:none;"></div>
        </div>
        <script>
        document.getElementById('tts-generate-btn').addEventListener('click', function() {
            var pid = document.getElementById('tts-post').value;
            if (!pid) return;
            var btn = this;
            btn.disabled = true; btn.textContent = '⏳ Gerando áudio...';
            var res = document.getElementById('tts-result');
            res.style.display = 'block';
            res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;text-align:center;color:#888;">🎧 Convertendo artigo em áudio...<br><small>Pode demorar 20-60 segundos</small></div>';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                btn.disabled = false; btn.textContent = '🎧 Gerar Áudio';
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        res.innerHTML = '<div style="background:#f0f9f0;border:1px solid #86efac;border-radius:8px;padding:16px;">✅ ' + r.data.message + '<br><audio controls style="margin-top:10px;width:100%;"><source src="' + r.data.audio_url + '" type="audio/mpeg"></audio></div>';
                    } else {
                        res.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:16px;color:#dc2626;">❌ ' + (r.data?.message || 'Erro') + '</div>';
                    }
                } catch(e) {
                    var raw = (xhr.responseText || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    if (raw.length > 500) raw = raw.substring(0, 500) + '...';
                    res.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:20px;color:#dc2626;">'
                        + '<strong>❌ Erro técnico no retorno do servidor</strong><br>'
                        + '<small>Status HTTP: ' + xhr.status + '</small><br>'
                        + '<p style="margin-top:8px;">O servidor não retornou JSON válido. Detalhe:</p>'
                        + '<pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:6px;max-height:180px;overflow:auto;">' + (raw || 'Resposta vazia') + '</pre>'
                        + '</div>';
                }
            };
            xhr.send('action=geo_tts_generate&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&post_id=' + pid);
        });
        document.getElementById('tts-remove-btn').addEventListener('click', function() {
            var pid = document.getElementById('tts-post').value;
            if (!pid || !confirm('Remover áudio do post #' + pid + '?')) return;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                var r = JSON.parse(xhr.responseText);
                document.getElementById('tts-result').style.display = 'block';
                document.getElementById('tts-result').innerHTML = r.success ? '<div style="background:#f0f9f0;border:1px solid #86efac;border-radius:8px;padding:12px;">✅ Áudio removido.</div>' : '<div style="padding:12px;color:#dc2626;">Erro.</div>';
            };
            xhr.send('action=geo_tts_remove&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&post_id=' + pid);
        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────────
    // TAB: AI Visibility Score
    // ──────────────────────────────────────────────
    private function render_visibility(): void {
        $sara  = new SARAService();
        $score = $sara->calculateAIVisibilityScore();
        $sc    = $score['score'];
        $color = $sc >= 70 ? '#16a34a' : ($sc >= 40 ? '#b45309' : '#dc2626');
        ?>
        <div style="max-width:760px;">
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px;">
                <h3 style="margin-top:0;">🌐 AI Visibility Score</h3>
                <p style="color:#666;">Mede o quão bem seu site está posicionado para aparecer em respostas de LLMs como ChatGPT, Gemini, Perplexity e Claude.</p>

                <div style="display:flex;align-items:center;gap:24px;margin:20px 0;">
                    <div style="width:100px;height:100px;border-radius:50%;background:<?php echo $color; ?>;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;flex-shrink:0;">
                        <div style="font-size:34px;font-weight:700;"><?php echo $sc; ?></div>
                        <div style="font-size:11px;">/100</div>
                    </div>
                    <div>
                        <h2 style="margin:0;color:<?php echo $color; ?>"><?php echo esc_html($score['label']); ?></h2>
                        <p style="margin:4px 0 0;color:#666;font-size:14px;">
                            <?php if ($sc >= 70) echo 'Seu site está bem otimizado para LLMs. Continue assim!';
                            elseif ($sc >= 40) echo 'Bom progresso, mas há melhorias importantes a fazer.';
                            else echo 'Prioridade: implemente as melhorias abaixo para aumentar sua visibilidade em IAs.'; ?>
                        </p>
                    </div>
                </div>

                <h4>Detalhamento:</h4>
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead><tr style="background:#f5f5f5;">
                        <th style="padding:8px;text-align:left;">Item</th>
                        <th style="padding:8px;text-align:center;">Status</th>
                        <th style="padding:8px;text-align:center;">Pontos</th>
                        <th style="padding:8px;text-align:left;">Ação</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($score['details'] as $detail):
                        $status_icon = $detail['status'] === 'ok' ? '✅' : ($detail['status'] === 'partial' ? '⚠️' : ($detail['status'] === 'bad' ? '❌' : '—'));
                    ?>
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:8px;"><?php echo esc_html($detail['item']); ?></td>
                        <td style="padding:8px;text-align:center;"><?php echo $status_icon; ?></td>
                        <td style="padding:8px;text-align:center;font-weight:600;"><?php echo (int)($detail['points'] ?? 0); ?></td>
                        <td style="padding:8px;color:#0073aa;font-size:12px;"><?php echo esc_html($detail['action'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    // TAB: llms.txt
    // ──────────────────────────────────────────────
    private function render_llmstxt(): void {
        $existing = get_option('geo_llmstxt_content', '');
        ?>
        <div style="max-width:760px;">
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">
                <h3 style="margin-top:0;">📄 llms.txt — Otimização para LLMs</h3>
                <p style="color:#666;">O arquivo <code>llms.txt</code> é um padrão emergente (como robots.txt) que instrui LLMs sobre o conteúdo do seu site. Sites com llms.txt têm mais chance de serem citados pelo ChatGPT, Gemini e Perplexity.</p>

                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <button id="llms-generate-btn" class="button button-primary" style="height:38px;">🤖 Gerar com SARA</button>
                    <?php if ($existing): ?>
                    <button id="llms-save-btn" class="button" style="height:38px;">💾 Salvar no Servidor</button>
                    <?php endif; ?>
                </div>

                <textarea id="llms-content" rows="20" style="width:100%;font-family:monospace;font-size:13px;border:1px solid #ddd;border-radius:4px;padding:12px;box-sizing:border-box;"><?php echo esc_textarea($existing); ?></textarea>

                <div style="margin-top:8px;display:flex;gap:8px;">
                    <button id="llms-save-local-btn" class="button">💾 Salvar Conteúdo</button>
                    <span style="font-size:12px;color:#888;padding-top:8px;">URL do arquivo: <code><?php echo esc_html(get_site_url()); ?>/llms.txt</code></span>
                </div>
            </div>
        </div>
        <script>
        document.getElementById('llms-generate-btn').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true; btn.textContent = '⏳ Gerando...';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                btn.disabled = false; btn.textContent = '🤖 Gerar com SARA';
                var r = JSON.parse(xhr.responseText);
                if (r.success) document.getElementById('llms-content').value = r.data.content;
            };
            xhr.send('action=geo_sara_llmstxt&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>');
        });
        document.getElementById('llms-save-local-btn').addEventListener('click', function() {
            var content = document.getElementById('llms-content').value;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                var r = JSON.parse(xhr.responseText);
                alert(r.success ? '✅ Conteúdo salvo!' : '❌ Erro ao salvar');
            };
            xhr.send('action=geo_sara_llmstxt_save&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&content=' + encodeURIComponent(content));
        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────────
    // TAB: Web Stories
    // ──────────────────────────────────────────────
    private function render_webstories(): void {
        $posts = get_posts(['numberposts' => 50, 'post_status' => 'publish', 'orderby' => 'date']);
        $adsense_pub  = get_option('geo_webstory_adsense_pub_id', '');
        $adsense_slot = get_option('geo_webstory_adsense_slot_id', '');
        $ga4_id       = get_option('geo_webstory_ga4_id', '');
        ?>
        <div style="max-width:860px;">

            <div style="background:#f0f9f0;border:1px solid #86efac;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:13px;">
                ✅ <strong>Funciona sem nenhum plugin externo.</strong> Stories acessíveis em <code>/web-story/slug/</code>. Após instalar, vá em <strong>Configurações → Links Permanentes → Salvar</strong> para ativar as URLs.
            </div>

            <!-- Configurações AdSense + Analytics -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">
                <h3 style="margin-top:0;">⚙️ Configurações de Monetização e Rastreamento</h3>
                <p style="color:#666;font-size:13px;">Aplicadas em todas as Web Stories geradas. Salve antes de gerar.</p>
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th style="width:200px;padding:8px 0;font-size:13px;">AdSense Publisher ID</th>
                        <td><input type="text" id="ws-adsense-pub" value="<?php echo esc_attr($adsense_pub); ?>"
                            placeholder="pub-1234567890123456" style="width:280px;height:34px;padding:0 10px;border:1px solid #ddd;border-radius:4px;">
                            <span style="font-size:12px;color:#888;margin-left:8px;">Ex: pub-1234567890123456</span></td>
                    </tr>
                    <tr>
                        <th style="padding:8px 0;font-size:13px;">AdSense Slot ID</th>
                        <td><input type="text" id="ws-adsense-slot" value="<?php echo esc_attr($adsense_slot); ?>"
                            placeholder="1234567890" style="width:180px;height:34px;padding:0 10px;border:1px solid #ddd;border-radius:4px;">
                            <span style="font-size:12px;color:#888;margin-left:8px;">ID do ad unit para Web Stories</span></td>
                    </tr>
                    <tr>
                        <th style="padding:8px 0;font-size:13px;">Google Analytics 4 ID</th>
                        <td><input type="text" id="ws-ga4" value="<?php echo esc_attr($ga4_id); ?>"
                            placeholder="G-XXXXXXXXXX" style="width:180px;height:34px;padding:0 10px;border:1px solid #ddd;border-radius:4px;">
                            <span style="font-size:12px;color:#888;margin-left:8px;">Tracking automático de visualizações</span></td>
                    </tr>
                </table>
                <button id="ws-save-settings-btn" class="button" style="margin-top:8px;">💾 Salvar Configurações</button>
                <span id="ws-settings-saved" style="display:none;color:#16a34a;margin-left:10px;font-size:13px;">✅ Salvo!</span>
            </div>

            <!-- Geração -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
                    <h3 style="margin-top:0;">📱 Gerar Story Individual</h3>
                    <p style="color:#666;font-size:13px;">Gera 10+ slides com imagens, AdSense e Analytics configurados.</p>

                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Post</label>
                    <div style="display:flex;gap:6px;margin-bottom:6px;">
                        <button type="button" onclick="wsFilterPosts('all')" id="ws-filter-all"
                            style="font-size:11px;padding:3px 8px;border:1px solid #0073aa;background:#0073aa;color:#fff;border-radius:4px;cursor:pointer;">
                            Todos
                        </button>
                        <button type="button" onclick="wsFilterPosts('sem')" id="ws-filter-sem"
                            style="font-size:11px;padding:3px 8px;border:1px solid #ddd;background:#fff;color:#333;border-radius:4px;cursor:pointer;">
                            Sem Story
                        </button>
                        <button type="button" onclick="wsFilterPosts('com')" id="ws-filter-com"
                            style="font-size:11px;padding:3px 8px;border:1px solid #ddd;background:#fff;color:#333;border-radius:4px;cursor:pointer;">
                            Com Story 📱
                        </button>
                    </div>
                    <select id="ws-post" style="width:100%;height:36px;padding:0 8px;border:1px solid #ddd;border-radius:4px;font-size:12px;margin-bottom:10px;">
                        <option value="">Selecione um post...</option>
                        <?php foreach ($posts as $p):
                            $has = get_post_meta($p->ID, '_geo_web_story_id', true);
                            $story_url = $has ? get_permalink((int)$has) : '';
                        ?>
                        <option value="<?php echo $p->ID; ?>"
                            data-has-story="<?php echo $has ? '1' : '0'; ?>">
                            <?php echo $has ? '📱' : '📄'; ?>
                            [<?php echo $p->ID; ?>] <?php echo esc_html(wp_trim_words($p->post_title, 7)); ?>
                            <?php echo $has ? ' ✓ Story' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <script>
                    function wsFilterPosts(filter) {
                        var sel = document.getElementById('ws-post');
                        var opts = sel.querySelectorAll('option[data-has-story]');
                        opts.forEach(function(opt) {
                            var has = opt.getAttribute('data-has-story') === '1';
                            if (filter === 'all') opt.style.display = '';
                            else if (filter === 'sem') opt.style.display = has ? 'none' : '';
                            else if (filter === 'com') opt.style.display = has ? '' : 'none';
                        });
                        // Atualizar botões ativos
                        ['all','sem','com'].forEach(function(f) {
                            var btn = document.getElementById('ws-filter-' + f);
                            if (btn) {
                                btn.style.background = f === filter ? '#0073aa' : '#fff';
                                btn.style.color = f === filter ? '#fff' : '#333';
                                btn.style.borderColor = f === filter ? '#0073aa' : '#ddd';
                            }
                        });
                        sel.value = '';
                    }
                    </script>

                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Imagens</label>
                    <select id="ws-img-provider" style="width:100%;height:36px;padding:0 8px;border:1px solid #ddd;border-radius:4px;font-size:12px;margin-bottom:12px;">
                        <option value="unsplash" selected>📷 Unsplash ⭐ (melhor cobertura)</option>
                        <option value="pexels">📷 Pexels</option>
                        <option value="pixabay">📷 Pixabay</option>
                        <option value="naga">🎨 Naga.ac (Web Stories)</option>
                        <option value="auto">Automático (Naga → HuggingFace → Fal.ai → Replicate → Stock → Pollinations)</option>
                        <option value="none">❌ Sem imagens</option>
                    </select>

                    <button id="ws-generate-btn" class="button button-primary" style="width:100%;height:38px;">📱 Gerar Web Story</button>
                </div>

                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
                    <h3 style="margin-top:0;">📱 Gerar em Lote</h3>
                    <p style="color:#666;font-size:13px;">Ctrl+click para selecionar múltiplos posts. Posts que já têm Web Story não aparecem aqui para evitar duplicação.</p>

                    <select id="ws-batch-posts" multiple style="width:100%;height:110px;padding:4px;border:1px solid #ddd;border-radius:4px;font-size:12px;margin-bottom:8px;">
                        <?php
                        $batch_posts = array_values(array_filter($posts, function($p) {
                            $existing = (int) get_post_meta($p->ID, '_geo_web_story_id', true);
                            return !($existing && get_post($existing));
                        }));
                        foreach (array_slice($batch_posts, 0, 30) as $p): ?>
                        <option value="<?php echo $p->ID; ?>">[<?php echo $p->ID; ?>] <?php echo esc_html(wp_trim_words($p->post_title, 6)); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Imagens</label>
                    <select id="ws-batch-img" style="width:100%;height:36px;padding:0 8px;border:1px solid #ddd;border-radius:4px;font-size:12px;margin-bottom:10px;">
                        <option value="unsplash" selected>📷 Unsplash ⭐ (melhor cobertura)</option>
                        <option value="pexels">📷 Pexels</option>
                        <option value="pixabay">📷 Pixabay</option>
                        <option value="naga">🎨 Naga.ac (Web Stories)</option>
                        <option value="auto">🤖 Automático</option>
                        <option value="none">❌ Sem imagens</option>
                    </select>

                    <button id="ws-batch-btn" class="button" style="width:100%;height:38px;">📱 Gerar Lote</button>
                </div>
            </div>

            <div id="ws-result" style="display:none;margin-bottom:20px;"></div>

            <!-- Lista de stories existentes -->
            <?php
            $stories = get_posts(['post_type' => 'geo-web-story', 'numberposts' => 30, 'meta_key' => '_geo_web_story', 'meta_value' => 1]);
            if (!empty($stories)):
            ?>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
                <h3 style="margin-top:0;">📱 Web Stories Geradas (<?php echo count($stories); ?>)</h3>
                <table class="widefat striped" style="font-size:13px;">
                    <thead><tr>
                        <th>Story</th>
                        <th style="width:160px;">Post Original</th>
                        <th style="width:120px;">Data</th>
                        <th style="width:110px;">Ações</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($stories as $story):
                        $source_id  = (int) get_post_meta($story->ID, '_geo_web_story_source', true);
                        $source     = $source_id ? get_post($source_id) : null;
                        $gen_time   = (int) get_post_meta($story->ID, '_geo_web_story_generated', true);
                        $story_url  = get_permalink($story->ID);
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url($story_url); ?>" target="_blank"><?php echo esc_html(wp_trim_words($story->post_title, 8)); ?></a></td>
                        <td style="font-size:12px;"><?php echo $source ? esc_html(wp_trim_words($source->post_title, 5)) : '—'; ?></td>
                        <td style="font-size:11px;"><?php echo $gen_time ? date('d/m/Y H:i', $gen_time) : '—'; ?></td>
                        <td>
                            <a href="<?php echo esc_url($story_url); ?>" target="_blank" class="button button-small" style="font-size:11px;">Ver</a>
                            <a href="<?php echo esc_url(get_edit_post_link($story->ID)); ?>" target="_blank" class="button button-small" style="font-size:11px;">Editar Slides</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var NONCE = '<?php echo wp_create_nonce('geo_sara_nonce'); ?>';

            // Salvar configurações
            document.getElementById('ws-save-settings-btn').addEventListener('click', function() {
                var btn = this;
                btn.disabled = true;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    btn.disabled = false;
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        document.getElementById('ws-settings-saved').style.display = 'inline';
                        setTimeout(function() { document.getElementById('ws-settings-saved').style.display = 'none'; }, 3000);
                    }
                };
                xhr.send('action=geo_webstory_save_settings&nonce=' + NONCE
                    + '&pub_id=' + encodeURIComponent(document.getElementById('ws-adsense-pub').value)
                    + '&slot_id=' + encodeURIComponent(document.getElementById('ws-adsense-slot').value)
                    + '&ga4_id=' + encodeURIComponent(document.getElementById('ws-ga4').value));
            });

            // Gerar individual
            document.getElementById('ws-generate-btn').addEventListener('click', function() {
                var pid  = document.getElementById('ws-post').value;
                var img  = document.getElementById('ws-img-provider').value;
                if (!pid) { alert('Selecione um post'); return; }
                var btn = this, res = document.getElementById('ws-result');
                btn.disabled = true; btn.textContent = '⏳ Gerando slides e imagens...';
                res.style.display = 'block';
                res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;text-align:center;color:#888;"><div style="font-size:36px;">📱</div><p>Gerando 10 slides com IA' + (img !== 'none' ? ' + imagens via ' + img : '') + '...<br><small>30-60 segundos</small></p></div>';
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    btn.disabled = false; btn.textContent = '📱 Gerar Web Story';
                    try {
                        var r = JSON.parse(xhr.responseText);
                        if (r.success) {
                            res.innerHTML = '<div style="background:#f0f9f0;border:1px solid #86efac;border-radius:8px;padding:16px;">✅ Web Story gerada com sucesso!<br>'
                                + '<a href="' + r.data.story_url + '" target="_blank" class="button button-primary" style="margin-top:8px;display:inline-block;">📱 Ver Story</a> '
                                + '<a href="' + r.data.edit_url + '" target="_blank" class="button" style="margin-top:8px;display:inline-block;">Editar Slides</a></div>';
                        } else {
                            res.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:16px;color:#dc2626;">❌ ' + (r.data?.message || 'Erro') + '</div>';
                        }
                    } catch(e) { res.innerHTML = '<div style="padding:16px;color:#dc2626;">Erro ao processar</div>'; }
                };
                xhr.send('action=geo_webstory_generate&nonce=' + NONCE + '&post_id=' + pid + '&image_provider=' + encodeURIComponent(img));
            });

            // Gerar lote
            document.getElementById('ws-batch-btn').addEventListener('click', function() {
                var ids = Array.from(document.getElementById('ws-batch-posts').selectedOptions).map(o => o.value);
                var img = document.getElementById('ws-batch-img').value;
                if (!ids.length) { alert('Selecione pelo menos um post'); return; }
                var btn = this, res = document.getElementById('ws-result');
                btn.disabled = true; btn.textContent = '⏳ Gerando ' + ids.length + ' stories...';
                res.style.display = 'block';
                res.innerHTML = '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;text-align:center;color:#888;">📱 Gerando ' + ids.length + ' stories...<br><small>~' + (ids.length * 40) + 's</small></div>';
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    btn.disabled = false; btn.textContent = '📱 Gerar Lote';
                    try {
                        var r = JSON.parse(xhr.responseText);
                        if (r.success) {
                            res.innerHTML = '<div style="background:#f0f9f0;border:1px solid #86efac;border-radius:8px;padding:16px;">✅ ' + r.data.count + '/' + ids.length + ' stories novas geradas. Posts que já tinham story foram ignorados automaticamente. Recarregue para ver a lista.</div>';
                        } else {
                            res.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:16px;color:#dc2626;">❌ ' + (r.data?.message || 'Erro') + '</div>';
                        }
                    } catch(e) { res.innerHTML = '<div style="padding:16px;color:#dc2626;">Erro</div>'; }
                };
                xhr.send('action=geo_webstory_batch&nonce=' + NONCE + '&post_ids=' + encodeURIComponent(JSON.stringify(ids)) + '&image_provider=' + encodeURIComponent(img));
            });
        })();
        </script>
        <?php
    }



    // ──────────────────────────────────────────────
    // TAB: Diagnóstico
    // ──────────────────────────────────────────────
    private function render_diagnostico(): void {
        $ai_provider = \GeoMetodoSEO\AI\ProviderResolver::for('article_generation');
        $openai_key  = get_option('geo_openai_api_key', '');
        $claude_key  = get_option('geo_claude_api_key', '');
        $gemini_key  = get_option('geo_gemini_api_key', '');
        $groq_key    = get_option('geo_groq_api_key', '');
        $yt_key      = get_option('geo_youtube_api_key', '');
        $tts_prov    = get_option('geo_tts_provider', 'openai');
        $falai_key    = get_option('geo_falai_api_key', '');
        $replicate_key = get_option('geo_replicate_api_key', '');
        $naga_key     = get_option('geo_naga_api_key', '');
        $huggingface_key = get_option('geo_huggingface_api_key', '');
        $unsplash    = get_option('geo_unsplash_api_key', '');
        $pexels      = get_option('geo_pexels_api_key', '');
        $gsc_token   = get_option('geo_gsc_token', []);

        $checks = [
            ['item' => 'Provedor IA ativo', 'ok' => !empty($ai_provider), 'value' => $ai_provider, 'action' => 'Configurar em Configurações → Provedores de IA'],
            ['item' => 'Provider de texto configurado', 'ok' => (!empty($openai_key) || !empty($claude_key) || !empty($gemini_key) || !empty($groq_key) || !empty(get_option('geo_perplexity_api_key', '')) || !empty($naga_key)), 'value' => $ai_provider ?: 'nenhum', 'action' => 'Configure OpenAI, Groq, Gemini, Claude, Perplexity ou Naga.ac'],
            ['item' => 'OpenAI API Key (opcional)', 'ok' => true, 'value' => $openai_key ? '✓ ' . substr($openai_key, 0, 8) . '...' : 'não configurada', 'action' => 'Use apenas se quiser OpenAI'],
            ['item' => 'Claude API Key', 'ok' => true, 'value' => $claude_key ? '✓ Configurada' : 'não configurada', 'action' => ''],
            ['item' => 'Gemini API Key', 'ok' => true, 'value' => $gemini_key ? '✓ Configurada' : 'não configurada', 'action' => ''],
            ['item' => 'Groq API Key', 'ok' => true, 'value' => $groq_key ? '✓ Configurada' : 'não configurada', 'action' => ''],
            ['item' => 'YouTube API Key', 'ok' => !empty($yt_key), 'value' => $yt_key ? '✓ Configurada' : 'não configurada', 'action' => 'Necessária para YouTube → Artigo'],
            ['item' => 'TTS Provider', 'ok' => true, 'value' => $tts_prov . ($tts_prov === 'openai' && !empty($openai_key) ? ' ✓' : ($tts_prov === 'openai' ? ' (precisa OpenAI key)' : '')), 'action' => ''],
            ['item' => 'Fal.ai gpt-image-2', 'ok' => !empty($falai_key), 'value' => $falai_key ? '✓ Configurada' : 'não configurada', 'action' => 'https://fal.ai/dashboard/keys'],
            ['item' => 'Replicate Flux Schnell', 'ok' => !empty($replicate_key), 'value' => $replicate_key ? '✓ Configurada' : 'não configurada', 'action' => 'https://replicate.com/account/api-tokens'],
            ['item' => 'Naga.ac Web Stories', 'ok' => !empty($naga_key), 'value' => $naga_key ? '✓ Configurada' : 'não configurada', 'action' => 'https://naga.ac/dashboard'],
            ['item' => 'HuggingFace Flux Schnell', 'ok' => !empty($huggingface_key), 'value' => $huggingface_key ? '✓ Configurada' : 'não configurada', 'action' => 'https://huggingface.co/settings/tokens'],
            ['item' => 'Unsplash API', 'ok' => !empty($unsplash), 'value' => $unsplash ? '✓ Configurada' : 'não configurada', 'action' => ''],
            ['item' => 'Pexels API', 'ok' => !empty($pexels), 'value' => $pexels ? '✓ Configurada' : 'não configurada', 'action' => ''],
            ['item' => 'Google Search Console', 'ok' => !empty($gsc_token['access_token']), 'value' => !empty($gsc_token['access_token']) ? '✓ Conectado' : 'não conectado', 'action' => 'Conectar em Configurações'],
            ['item' => 'Plugin Web Stories (Google)', 'ok' => post_type_exists('web-story'), 'value' => post_type_exists('web-story') ? '✓ Instalado' : 'não instalado (opcional)', 'action' => ''],
            ['item' => 'Rank Math SEO', 'ok' => defined('RANK_MATH_VERSION'), 'value' => defined('RANK_MATH_VERSION') ? '✓ Ativo' : 'não instalado', 'action' => ''],
            ['item' => 'PHP Version', 'ok' => version_compare(PHP_VERSION, '7.4', '>='), 'value' => PHP_VERSION, 'action' => ''],
            ['item' => 'WordPress Version', 'ok' => version_compare(get_bloginfo('version'), '5.8', '>='), 'value' => get_bloginfo('version'), 'action' => ''],
        ];

        // Determinar qual provider o chat SARA vai usar (compatível PHP 7.4+)
        $key_map = [
            'openai'     => $openai_key,
            'claude'     => $claude_key,
            'gemini'     => $gemini_key,
            'groq'       => $groq_key,
            'perplexity' => get_option('geo_perplexity_api_key', ''),
            'naga'       => $naga_key ?: get_option('autopilot_naga_api_key', ''),
        ];
        $sara_provider_ok = !empty($key_map[$ai_provider] ?? '');
        ?>
        <div style="max-width:760px;">

            <?php if (!$sara_provider_ok): ?>
            <div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:8px;padding:16px 20px;margin-bottom:20px;">
                <strong style="color:#dc2626;">⚠️ PROBLEMA DETECTADO: Chat da SARA não vai funcionar</strong><br>
                O provedor ativo é <strong><?php echo esc_html($ai_provider); ?></strong> mas a API Key dele não está configurada.<br>
                <a href="<?php echo admin_url('admin.php?page=geo-settings'); ?>" class="button button-primary" style="margin-top:8px;">Ir para Configurações</a>
            </div>
            <?php else: ?>
            <div style="background:#f0f9f0;border:2px solid #86efac;border-radius:8px;padding:16px 20px;margin-bottom:20px;">
                <strong style="color:#16a34a;">✅ SARA pronta para usar</strong><br>
                Provedor: <strong><?php echo esc_html($ai_provider); ?></strong> com API Key configurada.
            </div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">
                <h3 style="margin-top:0;">🔧 Status das Configurações</h3>
                <table class="widefat fixed" style="font-size:13px;">
                    <thead><tr>
                        <th style="width:30px;"></th>
                        <th>Componente</th>
                        <th>Status</th>
                        <th>Ação necessária</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($checks as $c): ?>
                    <tr>
                        <td style="text-align:center;"><?php echo $c['ok'] ? '✅' : '⚠️'; ?></td>
                        <td><strong><?php echo esc_html($c['item']); ?></strong></td>
                        <td style="font-size:12px;color:<?php echo $c['ok'] ? '#16a34a' : '#b45309'; ?>;"><?php echo esc_html($c['value']); ?></td>
                        <td style="font-size:12px;color:#666;"><?php echo esc_html($c['action']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">
                <h3 style="margin-top:0;">🧪 Teste de Conexão com a IA</h3>
                <p style="color:#666;font-size:13px;">Envia um prompt simples para verificar se a IA está respondendo corretamente.</p>
                <div style="display:flex;gap:8px;">
                    <select id="test-provider" style="height:38px;padding:0 8px;border:1px solid #ddd;border-radius:4px;">
                        <option value="">— Provedor global —</option>
                        <option value="openai">OpenAI</option>
                        <option value="groq">Groq</option>
                        <option value="gemini">Gemini</option>
                        <option value="claude">Claude</option>
                        <option value="perplexity">Perplexity</option>
                        <option value="naga">Naga.ac</option>
                    </select>
                    <button id="test-ai-btn" class="button button-primary" style="height:38px;">Testar IA</button>
                </div>
                <div id="test-result" style="margin-top:12px;display:none;"></div>
            </div>

            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
                <h3 style="margin-top:0;">📋 Últimos Logs da SARA</h3>
                <?php
                // Buscar logs da SARA nos últimos logs do plugin
                $logs = get_option('geo_log_entries', []);
                $sara_logs = array_filter($logs, function($l) {
                    return isset($l['message']) && (strpos($l['message'], 'SARA') !== false || strpos($l['message'], 'callAI') !== false);
                });
                $sara_logs = array_slice(array_reverse($sara_logs), 0, 10);
                if (!empty($sara_logs)):
                ?>
                <div style="background:#f5f5f5;border-radius:6px;padding:12px;font-family:monospace;font-size:12px;max-height:200px;overflow-y:auto;">
                    <?php foreach ($sara_logs as $log): ?>
                    <div style="margin-bottom:4px;color:<?php echo ($log['level'] ?? '') === 'error' ? '#dc2626' : '#333'; ?>">
                        [<?php echo esc_html($log['level'] ?? 'info'); ?>] <?php echo esc_html($log['message'] ?? ''); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="color:#888;font-size:13px;">Nenhum log da SARA encontrado. Use o chat e os logs aparecerão aqui.</p>
                <?php endif; ?>
            </div>

        </div>
        <script>
        document.getElementById('test-ai-btn').addEventListener('click', function() {
            var prov = document.getElementById('test-provider').value;
            var btn = this;
            btn.disabled = true; btn.textContent = '⏳ Testando...';
            var res = document.getElementById('test-result');
            res.style.display = 'block';
            res.innerHTML = '<div style="background:#f5f5f5;padding:12px;border-radius:6px;">Enviando prompt de teste para ' + prov + '...</div>';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                btn.disabled = false; btn.textContent = 'Testar IA';
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        res.innerHTML = '<div style="background:#f0f9f0;border:1px solid #86efac;border-radius:6px;padding:12px;"><strong style="color:#16a34a;">✅ ' + prov + ' respondeu corretamente!</strong><br><em style="font-size:13px;">' + r.data.answer + '</em></div>';
                    } else {
                        res.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:12px;color:#dc2626;"><strong>❌ Erro:</strong> ' + (r.data?.message || 'Falha na conexão') + '</div>';
                    }
                } catch(e) { res.innerHTML = '<div style="padding:12px;color:#dc2626;">Erro ao processar resposta do servidor</div>'; }
            };
            xhr.onerror = function() {
                btn.disabled = false; btn.textContent = 'Testar IA';
                res.innerHTML = '<div style="padding:12px;color:#dc2626;">Erro de rede. Verifique a conexão.</div>';
            };
            xhr.send('action=geo_sara_test&nonce=<?php echo wp_create_nonce('geo_sara_nonce'); ?>&provider=' + prov);
        });
        </script>
        <?php
    }
}
