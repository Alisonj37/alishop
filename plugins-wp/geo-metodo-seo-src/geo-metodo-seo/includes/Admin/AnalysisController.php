<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

class AnalysisController {

    public function render_page() {
        $nonce = wp_create_nonce('geo_analysis_score_nonce');

        $posts = get_posts([
            'posts_per_page' => 200,
            'post_status'    => ['publish', 'draft', 'pending', 'future'],
            'meta_key'       => '_geo_keyword',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $history = get_option('geo_score_history', []);
        ?>
        <div class="wrap">
            <h1>📊 Análise SEO / GEO / LLM</h1>
            <p class="description" style="font-size:14px;">
                Selecione um artigo gerado pelo plugin para calcular e visualizar os scores de otimização.
            </p>

            <!-- Seletor de artigo -->
            <div style="max-width:720px; margin:24px 0; padding:20px 24px; background:#fff; border:1px solid #ddd; border-radius:6px;">
                <table class="form-table" role="presentation" style="margin:0;">
                    <tr>
                        <th style="width:160px;"><label for="geo-analysis-select">Artigo</label></th>
                        <td>
                            <select id="geo-analysis-select" style="width:100%; max-width:500px;">
                                <option value="">— Selecione um artigo gerado pelo plugin —</option>
                                <?php foreach ($posts as $post):
                                    $kw = get_post_meta($post->ID, '_geo_keyword', true);
                                ?>
                                <option value="<?php echo intval($post->ID); ?>">
                                    <?php echo esc_html($post->post_title ?: '(sem título) #' . $post->ID); ?>
                                    <?php if ($kw): ?> — <?php echo esc_html($kw); endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <p style="margin-top:14px;">
                    <button id="geo-score-btn" class="button button-primary button-large">
                        📊 Calcular Scores
                    </button>
                    <span id="geo-score-loading" style="display:none; margin-left:12px; vertical-align:middle;">
                        <span class="spinner is-active" style="float:none; vertical-align:middle;"></span>
                        <em> Calculando...</em>
                    </span>
                </p>
            </div>

            <!-- Dashboard de resultados (preenchido via AJAX) -->
            <div id="geo-score-dashboard" style="display:none; max-width:1000px;"></div>

            <!-- Histórico -->
            <div style="max-width:1000px; margin-top:48px;">
                <h2 style="font-size:16px;">📋 Histórico de Análises (últimas 10)</h2>
                <?php if (empty($history)): ?>
                    <p style="color:#666;">Nenhuma análise realizada ainda.</p>
                <?php else: ?>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Artigo</th>
                                <th style="width:110px; text-align:center;">SEO</th>
                                <th style="width:110px; text-align:center;">GEO</th>
                                <th style="width:110px; text-align:center;">LLM</th>
                                <th style="width:150px;">Analisado em</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($h['post_id'])): ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($h['post_id'])); ?>" target="_blank">
                                            <?php echo esc_html($h['title'] ?? '#' . $h['post_id']); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo esc_html($h['title'] ?? '—'); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;"><?php echo $this->score_badge($h['scores']['seo']['score'] ?? 0); ?></td>
                                <td style="text-align:center;"><?php echo $this->score_badge($h['scores']['geo']['score'] ?? 0); ?></td>
                                <td style="text-align:center;"><?php echo $this->score_badge($h['scores']['llm']['score'] ?? 0); ?></td>
                                <td style="font-size:12px; color:#666;">
                                    <?php echo isset($h['date']) ? esc_html(date('d/m/Y H:i', $h['date'])) : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <script>
        (function() {
            var AJAX_URL = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var NONCE    = '<?php echo esc_js($nonce); ?>';

            document.getElementById('geo-score-btn').addEventListener('click', function() {
                var post_id = document.getElementById('geo-analysis-select').value;
                if (!post_id) { alert('Selecione um artigo para analisar.'); return; }

                this.disabled = true;
                document.getElementById('geo-score-loading').style.display  = 'inline';
                document.getElementById('geo-score-dashboard').style.display = 'none';

                var btn = this;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', AJAX_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.timeout = 30000;

                xhr.onload = function() {
                    btn.disabled = false;
                    document.getElementById('geo-score-loading').style.display = 'none';
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success && res.data && res.data.html) {
                            var dash = document.getElementById('geo-score-dashboard');
                            dash.innerHTML     = res.data.html;
                            dash.style.display = 'block';
                        } else {
                            alert('Erro: ' + ((res.data && res.data.message) ? res.data.message : 'Desconhecido'));
                        }
                    } catch(e) {
                        alert('Erro ao processar resposta.');
                    }
                };

                xhr.ontimeout = function() {
                    btn.disabled = false;
                    document.getElementById('geo-score-loading').style.display = 'none';
                    alert('Timeout ao calcular scores.');
                };

                xhr.onerror = function() {
                    btn.disabled = false;
                    document.getElementById('geo-score-loading').style.display = 'none';
                    alert('Erro de rede.');
                };

                xhr.send(
                    'action=geo_analyze_article'
                    + '&nonce='   + encodeURIComponent(NONCE)
                    + '&post_id=' + encodeURIComponent(post_id)
                );
            });
        })();
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Score calculation
    // -------------------------------------------------------------------------

    public function calculate_scores($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $content    = $post->post_content;
        $text       = wp_strip_all_tags($content);
        $keyword    = get_post_meta($post_id, '_geo_keyword', true);
        $word_count = str_word_count($text);

        // === SEO Score (0-100) ===
        $seo = 0;
        $seo_improvements = [];

        // Contagem de palavras (25 pts)
        if ($word_count >= 2000)     { $seo += 25; }
        elseif ($word_count >= 1500) { $seo += 20; }
        elseif ($word_count >= 1000) { $seo += 15; }
        elseif ($word_count >= 500)  { $seo += 10; }
        else                         { $seo_improvements[] = 'Aumente o conteúdo para no mínimo 1.500 palavras'; }

        // Keyword density (20 pts)
        if ($keyword && $word_count > 0) {
            $kw_count = substr_count(mb_strtolower($text), mb_strtolower($keyword));
            $density  = ($kw_count / $word_count) * 100;
            if ($density >= 0.5 && $density <= 2.5)     { $seo += 20; }
            elseif ($density > 0 && $density < 0.5)     { $seo += 8;  $seo_improvements[] = 'Keyword density baixa (' . round($density, 2) . '%) — ideal entre 0,5% e 2,5%'; }
            elseif ($density > 2.5)                     { $seo += 8;  $seo_improvements[] = 'Keyword density alta (' . round($density, 2) . '%) — risco de keyword stuffing'; }
            else                                        { $seo_improvements[] = 'Keyword principal não encontrada no conteúdo'; }
        }

        // H2 headings (15 pts)
        preg_match_all('/<h2[\s>]/i', $content, $h2m);
        $h2_count = count($h2m[0]);
        if ($h2_count >= 5)      { $seo += 15; }
        elseif ($h2_count >= 3)  { $seo += 10; }
        elseif ($h2_count >= 1)  { $seo += 5;  $seo_improvements[] = 'Adicione mais seções H2 (mínimo 5 recomendado)'; }
        else                     { $seo_improvements[] = 'Adicione headings H2 para estruturar o conteúdo'; }

        // Links internos (20 pts)
        $site_host = parse_url(get_site_url(), PHP_URL_HOST);
        preg_match_all('/<a\s[^>]*href=["\']https?:\/\/' . preg_quote($site_host, '/') . '/i', $content, $intlm);
        $int_links = count($intlm[0]);
        if ($int_links >= 3)     { $seo += 20; }
        elseif ($int_links >= 1) { $seo += 10; $seo_improvements[] = 'Adicione mais links internos (mínimo 3)'; }
        else                     { $seo_improvements[] = 'Nenhum link interno encontrado no artigo'; }

        // Imagem destaque (10 pts)
        if (has_post_thumbnail($post_id)) { $seo += 10; }
        else                              { $seo_improvements[] = 'Adicione uma imagem destaque (featured image)'; }

        // Meta descrição/excerpt (10 pts)
        if (!empty($post->post_excerpt)) { $seo += 10; }
        else                             { $seo_improvements[] = 'Adicione uma meta descrição/excerpt ao artigo'; }

        // === GEO Score (0-100) ===
        $geo = 0;
        $geo_improvements = [];

        // Resposta direta / snippet (20 pts)
        if (preg_match('/<h2[^>]*>[^<]*resposta[^<]*<\/h2>/i', $content)) { $geo += 20; }
        else { $geo_improvements[] = 'Adicione seção "Resposta Rápida" no início do artigo'; }

        // Seção FAQ (20 pts)
        if (preg_match('/<h2[^>]*>[^<]*(faq|perguntas\s+frequentes)[^<]*<\/h2>/i', $content)) { $geo += 20; }
        else { $geo_improvements[] = 'Adicione seção de FAQ (Perguntas Frequentes)'; }

        // Schema markup (20 pts)
        if (get_post_meta($post_id, 'geo_schema', true)) { $geo += 20; }
        else { $geo_improvements[] = 'Schema markup não encontrado — verifique as configurações E-E-A-T'; }

        // H3 subheadings (20 pts)
        preg_match_all('/<h3[\s>]/i', $content, $h3m);
        $h3_count = count($h3m[0]);
        if ($h3_count >= 3)      { $geo += 20; }
        elseif ($h3_count >= 1)  { $geo += 10; $geo_improvements[] = 'Adicione mais subheadings H3 (mínimo 3 recomendado)'; }
        else                     { $geo_improvements[] = 'Adicione subheadings H3 para hierarquia semântica'; }

        // Tabela comparativa (20 pts)
        if (preg_match('/<table/i', $content)) { $geo += 20; }
        else { $geo_improvements[] = 'Adicione uma tabela comparativa ou de dados estruturados'; }

        // === LLM Score (0-100) ===
        $llm = 0;
        $llm_improvements = [];

        // Author box E-E-A-T (20 pts)
        if (strpos($content, 'geo-author-box') !== false) { $llm += 20; }
        else { $llm_improvements[] = 'Configure o perfil do autor (E-E-A-T) nas Configurações'; }

        // Profundidade de conteúdo (20 pts)
        if ($word_count >= 1500)     { $llm += 20; }
        elseif ($word_count >= 800)  { $llm += 10; $llm_improvements[] = 'Aprofunde o conteúdo para pelo menos 1.500 palavras'; }
        else                         { $llm_improvements[] = 'Conteúdo muito curto para ser citado por LLMs'; }

        // Links externos com nofollow (20 pts)
        preg_match_all('/<a\s[^>]*rel=["\'][^"\']*nofollow/i', $content, $extlm);
        $ext_links = count($extlm[0]);
        if ($ext_links >= 2)     { $llm += 20; }
        elseif ($ext_links >= 1) { $llm += 10; $llm_improvements[] = 'Adicione mais links externos com fontes de autoridade'; }
        else                     { $llm_improvements[] = 'Adicione pelo menos 2 links externos com rel=nofollow'; }

        // Autor configurado (20 pts)
        if (get_post_meta($post_id, 'geo_author_name', true)) { $llm += 20; }
        else { $llm_improvements[] = 'Configure nome e credenciais do autor nas Configurações'; }

        // Freshness — publicado nos últimos 60 dias (20 pts)
        $age_days = (time() - get_post_time('U', false, $post_id)) / DAY_IN_SECONDS;
        if ($age_days <= 60)       { $llm += 20; }
        elseif ($age_days <= 180)  { $llm += 10; $llm_improvements[] = 'Atualize o conteúdo para manter a relevância'; }
        else                       { $llm_improvements[] = 'Conteúdo desatualizado — considere revisar e republicar'; }

        return [
            'seo' => ['score' => min(100, $seo), 'improvements' => $seo_improvements],
            'geo' => ['score' => min(100, $geo), 'improvements' => $geo_improvements],
            'llm' => ['score' => min(100, $llm), 'improvements' => $llm_improvements],
            'meta' => [
                'word_count' => $word_count,
                'h2_count'   => $h2_count,
                'h3_count'   => $h3_count ?? 0,
                'int_links'  => $int_links,
                'ext_links'  => $ext_links,
                'keyword'    => $keyword,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Dashboard HTML renderer
    // -------------------------------------------------------------------------

    public function render_dashboard_html($scores, $post_id) {
        $post  = get_post($post_id);
        $title = $post ? $post->post_title : '#' . $post_id;
        $meta  = $scores['meta'];

        ob_start();
        ?>
        <div style="border-top:3px solid #0073aa; padding-top:24px;">
            <h2 style="font-size:16px; margin-bottom:4px;">
                Resultado: <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" target="_blank"><?php echo esc_html($title); ?></a>
            </h2>
            <p style="color:#666; font-size:13px; margin-top:0;">
                <?php echo intval($meta['word_count']); ?> palavras &nbsp;|&nbsp;
                <?php echo intval($meta['h2_count']); ?> H2 &nbsp;|&nbsp;
                <?php echo intval($meta['h3_count']); ?> H3 &nbsp;|&nbsp;
                <?php echo intval($meta['int_links']); ?> links internos &nbsp;|&nbsp;
                <?php echo intval($meta['ext_links']); ?> links externos
                <?php if ($meta['keyword']): ?>&nbsp;|&nbsp; Keyword: <strong><?php echo esc_html($meta['keyword']); ?></strong><?php endif; ?>
            </p>

            <!-- Score Cards -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px,1fr)); gap:20px; margin:20px 0;">
                <?php
                $cards = [
                    'seo' => ['label' => 'Score SEO',  'icon' => '🔎', 'color' => '#2196F3', 'desc' => 'Palavras, keyword density, headings, links, imagem e meta'],
                    'geo' => ['label' => 'Score GEO',  'icon' => '🤖', 'color' => '#9C27B0', 'desc' => 'Resposta direta, FAQ, schema, H3, tabela comparativa'],
                    'llm' => ['label' => 'Score LLM',  'icon' => '✨', 'color' => '#4CAF50', 'desc' => 'E-E-A-T, profundidade, links externos, autor, atualidade'],
                ];
                foreach ($cards as $key => $card):
                    $score = $scores[$key]['score'];
                    $improvements = $scores[$key]['improvements'];
                    $bar_color = $score >= 80 ? '#46b450' : ($score >= 50 ? '#ffb900' : '#dc3232');
                ?>
                <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px; border-top:4px solid <?php echo $card['color']; ?>;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <span style="font-size:15px; font-weight:600;"><?php echo $card['icon'] . ' ' . $card['label']; ?></span>
                        <span style="font-size:28px; font-weight:700; color:<?php echo $bar_color; ?>;"><?php echo intval($score); ?></span>
                    </div>

                    <!-- Barra de progresso -->
                    <div style="background:#eee; border-radius:6px; height:10px; margin-bottom:12px; overflow:hidden;">
                        <div style="width:<?php echo intval($score); ?>%; height:10px; background:<?php echo $bar_color; ?>; border-radius:6px; transition:width 0.4s;"></div>
                    </div>

                    <p style="font-size:12px; color:#888; margin:0 0 12px;"><?php echo esc_html($card['desc']); ?></p>

                    <?php if (!empty($improvements)): ?>
                    <div style="border-top:1px solid #f0f0f0; padding-top:10px;">
                        <p style="font-size:12px; font-weight:600; color:#555; margin:0 0 6px;">💡 Melhorias sugeridas:</p>
                        <ul style="margin:0; padding-left:16px; font-size:12px; color:#555;">
                            <?php foreach ($improvements as $imp): ?>
                                <li style="margin-bottom:4px;"><?php echo esc_html($imp); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php else: ?>
                    <p style="font-size:12px; color:#46b450; margin:0;">✅ Sem melhorias críticas identificadas</p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Score médio -->
            <?php
            $avg = round(($scores['seo']['score'] + $scores['geo']['score'] + $scores['llm']['score']) / 3);
            $avg_color = $avg >= 80 ? '#46b450' : ($avg >= 50 ? '#ffb900' : '#dc3232');
            ?>
            <div style="background:#f9f9f9; border:1px solid #e5e5e5; border-radius:8px; padding:16px 20px; display:flex; align-items:center; gap:20px;">
                <div>
                    <span style="font-size:13px; color:#666;">Score Médio Geral</span><br>
                    <span style="font-size:36px; font-weight:700; color:<?php echo $avg_color; ?>;"><?php echo $avg; ?>/100</span>
                </div>
                <div style="flex:1;">
                    <div style="background:#eee; border-radius:6px; height:14px; overflow:hidden;">
                        <div style="width:<?php echo $avg; ?>%; height:14px; background:<?php echo $avg_color; ?>; border-radius:6px;"></div>
                    </div>
                    <p style="font-size:12px; color:#888; margin:6px 0 0;">
                        <?php if ($avg >= 80): ?>🟢 Excelente — artigo bem otimizado
                        <?php elseif ($avg >= 60): ?>🟡 Bom — algumas melhorias recomendadas
                        <?php elseif ($avg >= 40): ?>🟠 Regular — diversas melhorias necessárias
                        <?php else: ?>🔴 Crítico — revisão completa recomendada
                        <?php endif; ?>
                    </p>
                </div>
                <div style="text-align:right; font-size:13px; color:#666;">
                    SEO <strong><?php echo $scores['seo']['score']; ?></strong> &nbsp;
                    GEO <strong><?php echo $scores['geo']['score']; ?></strong> &nbsp;
                    LLM <strong><?php echo $scores['llm']['score']; ?></strong>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function score_badge($score) {
        $color = $score >= 80 ? '#46b450' : ($score >= 50 ? '#ffb900' : '#dc3232');
        return '<span style="background:' . $color . ';color:#fff;padding:2px 10px;border-radius:12px;font-size:13px;font-weight:700;">' . intval($score) . '</span>';
    }
}
