<?php
namespace GeoMetodoSEO\Admin;

use GeoMetodoSEO\Tools\ImageReSideloader;

if (!defined('ABSPATH')) exit;

/**
 * Página admin para recuperação de imagens externas.
 *
 * Fluxo de uso:
 *  1. Usuário clica "🔍 Escanear" → vê quantos posts têm URLs externas
 *  2. Clica "🚀 Executar lote" → processa 5 posts por vez (recursivo via AJAX)
 *  3. Cada lote mostra log detalhado: quantas URLs foram baixadas, quantas regeneradas, quantas falharam
 *  4. Cron diário processa 5 posts automaticamente
 *
 * @since 1.0.0
 */
class ReSideloaderController {

    public static function register_hooks(): void {
        // Página é registrada via AdminController (submenu)
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Permissão negada');
        }

        // Toggle do cron automático
        if (isset($_POST['geo_resideload_save_settings']) && check_admin_referer('geo_resideload_settings')) {
            update_option('geo_resideload_auto_enabled', !empty($_POST['auto_enabled']) ? '1' : '0');
            update_option('geo_resideload_auto_batch', max(1, min(20, (int) ($_POST['auto_batch'] ?? 5))));
            echo '<div class="notice notice-success is-dismissible"><p>✅ Configurações salvas.</p></div>';
        }

        $auto_enabled = get_option('geo_resideload_auto_enabled', '1') === '1';
        $auto_batch   = (int) get_option('geo_resideload_auto_batch', 5);
        $nonce        = wp_create_nonce('geo_resideload');

        // Stats últimos posts processados
        global $wpdb;
        $recent_processed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_geo_resideloaded_at'
               AND meta_value >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $total_urls_fixed = (int) $wpdb->get_var(
            "SELECT SUM(meta_value) FROM {$wpdb->postmeta}
             WHERE meta_key = '_geo_resideloaded_count'"
        );

        ?>
        <div class="wrap">
            <h1>🖼️ Recuperação de Imagens Externas</h1>
            <p style="font-size:15px;max-width:900px;">
                Posts publicados com URLs de <code>replicate.delivery</code>, <code>legacy_image.ai</code>,
                <code>tempfile.aiquickdraw.com</code> e similares têm imagens que <strong>expiram em 24-72h</strong>.
                Esta ferramenta baixa cada uma e salva permanentemente em <code>wp-content/uploads/</code>.
                Se a URL já expirou, gera uma imagem nova pela cadeia oficial Fal.ai/Replicate.
            </p>

            <!-- Cards de status -->
            <div style="display:flex;gap:14px;margin:20px 0;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:14px;border-radius:8px;">
                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;">Posts processados (7 dias)</div>
                    <div style="font-size:28px;font-weight:700;color:#2271b1;margin-top:4px;"><?php echo esc_html($recent_processed); ?></div>
                </div>
                <div style="flex:1;min-width:200px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #46b450;padding:14px;border-radius:8px;">
                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;">URLs corrigidas (total)</div>
                    <div style="font-size:28px;font-weight:700;color:#46b450;margin-top:4px;"><?php echo esc_html($total_urls_fixed); ?></div>
                </div>
                <div style="flex:1;min-width:200px;background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo $auto_enabled ? '#46b450' : '#f59e0b'; ?>;padding:14px;border-radius:8px;">
                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;">Cron diário</div>
                    <div style="font-size:18px;font-weight:600;color:<?php echo $auto_enabled ? '#46b450' : '#f59e0b'; ?>;margin-top:8px;">
                        <?php echo $auto_enabled ? '✅ Ativo (' . $auto_batch . ' posts/dia)' : '⏸️ Desativado'; ?>
                    </div>
                </div>
            </div>

            <!-- Painel principal -->
            <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;margin:18px 0;">
                <h2 style="margin-top:0;">🔍 Escanear posts</h2>
                <p>Veja quantos posts têm URLs externas SEM mudar nada. Use isso primeiro pra dimensionar o trabalho.</p>

                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:14px 0;">
                    <label>Janela: últimos
                        <input type="number" id="resideload-days" value="30" min="1" max="365" style="width:80px"> dias
                    </label>
                    <label>Limite:
                        <input type="number" id="resideload-limit" value="100" min="10" max="500" style="width:80px"> posts
                    </label>
                    <button class="button button-primary" id="btn-resideload-scan">🔍 Escanear agora</button>
                    <button class="button" id="btn-resideload-diag">🩺 Diagnóstico</button>
                </div>

                <div id="resideload-scan-result" style="display:none;margin-top:14px;"></div>
                <div id="resideload-diag-result" style="display:none;margin-top:14px;"></div>
            </div>

            <!-- Painel de execução -->
            <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;margin:18px 0;">
                <h2 style="margin-top:0;">🚀 Executar correção</h2>
                <p>Processa <strong>lotes de 5 posts por vez</strong> (evita timeout). O JavaScript chama o servidor recursivamente até acabar.</p>

                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:14px 0;">
                    <label>Lote:
                        <input type="number" id="resideload-batch" value="5" min="1" max="20" style="width:80px"> posts/chamada
                    </label>
                    <button class="button button-primary button-hero" id="btn-resideload-run">🚀 Iniciar correção em massa</button>
                    <button class="button button-secondary" id="btn-resideload-stop" style="display:none;">⏸️ Parar</button>
                </div>

                <div id="resideload-progress" style="display:none;margin-top:14px;padding:14px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:8px;">
                    <strong>Progresso:</strong> <span id="resideload-status">Iniciando...</span>
                    <div style="margin-top:8px;height:8px;background:#eaeaea;border-radius:4px;overflow:hidden;">
                        <div id="resideload-bar" style="height:100%;background:#2271b1;width:0%;transition:width 0.4s;"></div>
                    </div>
                    <div style="display:flex;gap:14px;margin-top:10px;font-size:13px;flex-wrap:wrap;">
                        <span>📊 Posts: <strong id="rs-processed">0</strong></span>
                        <span style="color:#46b450;">✅ Corrigidas: <strong id="rs-fixed">0</strong></span>
                        <span style="color:#0284c7;">➕ Adicionadas: <strong id="rs-added">0</strong></span>
                        <span style="color:#f59e0b;">🔄 Regeneradas: <strong id="rs-regen">0</strong></span>
                        <span style="color:#c0392b;">❌ Falhas: <strong id="rs-failed">0</strong></span>
                    </div>
                    <div id="resideload-log" style="margin-top:12px;max-height:350px;overflow-y:auto;font-size:12px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px;"></div>
                </div>
            </div>

            <!-- Configurações cron -->
            <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;margin:18px 0;">
                <h2 style="margin-top:0;">⚙️ Cron diário automático</h2>
                <form method="post">
                    <?php wp_nonce_field('geo_resideload_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th>Ativar processamento automático</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="auto_enabled" value="1" <?php checked($auto_enabled); ?>>
                                    Sim, rodar todo dia automaticamente
                                </label>
                                <p class="description">Roda 1 vez por dia em horário aleatório (definido pelo WP-Cron).</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Posts por dia (lote)</th>
                            <td>
                                <input type="number" name="auto_batch" value="<?php echo esc_attr($auto_batch); ?>" min="1" max="20" style="width:80px">
                                <p class="description">Quantos posts processar em cada execução automática. Default: 5.</p>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" name="geo_resideload_save_settings" value="1" class="button button-primary">💾 Salvar configurações</button>
                </form>
            </div>
        </div>

        <script>
        (function($){
            const nonce = '<?php echo esc_js($nonce); ?>';
            let stop_requested = false;
            let totals = { processed: 0, fixed: 0, regen: 0, failed: 0, added: 0 };

            function log_line(html) {
                $('#resideload-log').prepend('<div style="padding:6px 0;border-bottom:1px solid #f0f0f0;">' + html + '</div>');
            }

            // 1.0.0+: Diagnóstico — verifica AJAX + mostra ambiente + razões de falha recentes
            $('#btn-resideload-diag').on('click', function(){
                const btn = $(this);
                btn.prop('disabled', true).text('🩺 Diagnosticando...');
                $('#resideload-diag-result').show().html('<p>Testando conexão...</p>');

                $.post(ajaxurl, {
                    action: 'geo_resideload_scan',
                    nonce: nonce,
                    days_back: 1,
                    limit: 5
                }).done(function(res){
                    let html = '<div style="background:#f0f9ff;border-left:4px solid #0284c7;padding:14px;border-radius:6px;">';
                    html += '<h3 style="margin-top:0;">🩺 Diagnóstico do sistema</h3>';
                    html += '<table class="widefat striped">';
                    html += '<tr><td><strong>AJAX endpoint</strong></td><td>✅ funcionando</td></tr>';
                    html += '<tr><td><strong>Resposta JSON</strong></td><td>' + (res.success ? '✅ válida' : '❌ ' + (res.data && res.data.message || 'erro')) + '</td></tr>';
                    html += '<tr><td><strong>Nonce</strong></td><td>✅ válido</td></tr>';
                    if (res.data && res.data.debug) {
                        Object.keys(res.data.debug).forEach(function(k){
                            html += '<tr><td><strong>' + k + '</strong></td><td>' + res.data.debug[k] + '</td></tr>';
                        });
                    }
                    if (res.data && res.data.posts) {
                        html += '<tr><td><strong>Posts encontrados (último 1 dia)</strong></td><td>' + res.data.posts.length + '</td></tr>';
                    }
                    html += '</table>';

                    // 1.0.0: razões de falha recentes
                    if (res.data && res.data.failure_reasons_summary) {
                        html += '<h3 style="margin-top:16px;">📊 Causas de falha (últimas 24h)</h3>';
                        const fr = res.data.failure_reasons_summary;
                        if (Object.keys(fr).length === 0) {
                            html += '<p style="color:#46b450;">✅ Nenhuma falha registrada nas últimas 24h.</p>';
                        } else {
                            html += '<table class="widefat striped">';
                            html += '<thead><tr><th>Tipo</th><th>Ocorrências</th><th>Mensagem exemplo</th></tr></thead><tbody>';
                            Object.keys(fr).forEach(function(type){
                                html += '<tr><td><code>' + type + '</code></td><td><strong>' + fr[type].count + '</strong></td><td><small>' + $('<div>').text(fr[type].sample).html() + '</small></td></tr>';
                            });
                            html += '</tbody></table>';
                        }
                    }

                    html += '<p style="margin-top:12px;"><strong>Se o sistema funciona mas o escaneamento não retorna posts:</strong><br>';
                    html += '1. Aumente a janela de dias (talvez sejam posts mais antigos)<br>';
                    html += '2. Verifique se os posts estão como <strong>publish</strong> (não draft)<br>';
                    html += '3. Confirme que são <strong>post_type=post</strong> (não página/custom)</p>';
                    html += '</div>';
                    $('#resideload-diag-result').html(html);
                }).fail(function(xhr){
                    let html = '<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:14px;border-radius:6px;">';
                    html += '<h3 style="margin-top:0;">❌ AJAX falhou</h3>';
                    html += '<p><strong>Status HTTP:</strong> ' + xhr.status + ' ' + xhr.statusText + '</p>';
                    html += '<p><strong>Resposta:</strong></p>';
                    html += '<pre style="background:#fff;padding:10px;border:1px solid #ccc;max-height:200px;overflow:auto;">' + (xhr.responseText || '(vazio)').substring(0, 1500) + '</pre>';
                    html += '</div>';
                    $('#resideload-diag-result').html(html);
                }).always(function(){
                    btn.prop('disabled', false).text('🩺 Diagnóstico');
                });
            });

            // SCAN
            $('#btn-resideload-scan').on('click', function(){
                const btn = $(this);
                btn.prop('disabled', true).text('🔍 Escaneando...');
                $('#resideload-scan-result').show().html('<p>Buscando posts...</p>');

                $.post(ajaxurl, {
                    action: 'geo_resideload_scan',
                    nonce: nonce,
                    days_back: $('#resideload-days').val(),
                    limit: $('#resideload-limit').val()
                }).done(function(res){
                    if (!res.success) {
                        $('#resideload-scan-result').html('<div class="notice notice-error"><p>Erro: ' + (res.data.message || 'desconhecido') + '</p></div>');
                        return;
                    }
                    const d = res.data;
                    let html = '<div style="background:#edfaef;border-left:4px solid #46b450;padding:14px;border-radius:6px;">';
                    html += '<h3 style="margin-top:0;">Resultado do escaneamento</h3>';
                    html += '<p><strong>' + d.posts.length + ' posts</strong> precisam de recuperação.</p>';
                    html += '<ul style="margin-left:20px;list-style:disc;">';
                    html += '<li><strong>' + (d.total_external_urls || 0) + '</strong> URLs externas (vão expirar)</li>';
                    html += '<li><strong>' + (d.total_broken_imgs || 0) + '</strong> tags img quebradas (src vazio)</li>';
                    html += '<li><strong>' + (d.total_missing_images || 0) + '</strong> imagens faltando (regra: 4 no corpo + 1 destaque)</li>';
                    html += '</ul>';
                    if (d.posts.length > 0) {
                        html += '<table class="widefat striped" style="margin-top:14px;"><thead><tr><th>ID</th><th>Título</th><th>Problemas detectados</th><th>Data</th></tr></thead><tbody>';
                        d.posts.slice(0, 30).forEach(function(p){
                            html += '<tr><td><a href="post.php?post=' + p.ID + '&action=edit" target="_blank">#' + p.ID + '</a></td>';
                            html += '<td>' + $('<div>').text(p.title).html() + '</td>';
                            html += '<td><small>' + p.reasons + '</small></td>';
                            html += '<td><small>' + p.date.substring(0, 10) + '</small></td></tr>';
                        });
                        html += '</tbody></table>';
                        if (d.posts.length > 30) html += '<p><small>Mostrando 30 de ' + d.posts.length + '</small></p>';
                    } else {
                        html += '<p>🎉 Todos os posts estão OK!</p>';
                    }
                    html += '</div>';
                    $('#resideload-scan-result').html(html);
                }).fail(function(xhr){
                    $('#resideload-scan-result').html('<div class="notice notice-error"><p>Erro técnico: ' + xhr.status + ' - ' + (xhr.responseText || '').substring(0, 200) + '</p></div>');
                }).always(function(){
                    btn.prop('disabled', false).text('🔍 Escanear agora');
                });
            });

            // RUN
            $('#btn-resideload-run').on('click', function(){
                if (!confirm('Iniciar correção em massa?\n\nVai baixar URLs externas + adicionar imagens faltando + remover imagens quebradas dos posts publicados nos últimos ' + $('#resideload-days').val() + ' dias.\n\nClique OK para começar.')) return;
                stop_requested = false;
                totals = { processed: 0, fixed: 0, regen: 0, failed: 0, added: 0 };
                $('#btn-resideload-run').prop('disabled', true);
                $('#btn-resideload-stop').show();
                $('#resideload-progress').show();
                $('#resideload-log').empty();
                $('#rs-processed').text('0');
                $('#rs-fixed').text('0');
                $('#rs-added').text('0');
                $('#rs-regen').text('0');
                $('#rs-failed').text('0');
                $('#resideload-bar').css('width', '0%');
                process_next_batch();
            });

            $('#btn-resideload-stop').on('click', function(){
                stop_requested = true;
                $(this).prop('disabled', true).text('⏸️ Parando...');
            });

            function process_next_batch() {
                if (stop_requested) {
                    $('#resideload-status').html('⏸️ <strong>Pausado pelo usuário.</strong> Os posts já processados foram salvos.');
                    $('#btn-resideload-run').prop('disabled', false);
                    $('#btn-resideload-stop').hide().prop('disabled', false).text('⏸️ Parar');
                    return;
                }

                $.post(ajaxurl, {
                    action: 'geo_resideload_run',
                    nonce: nonce,
                    batch: $('#resideload-batch').val(),
                    days_back: $('#resideload-days').val()
                }, null, 'json').done(function(res){
                    if (!res.success) {
                        $('#resideload-status').html('❌ <strong>Erro:</strong> ' + (res.data.message || 'desconhecido'));
                        $('#btn-resideload-run').prop('disabled', false);
                        $('#btn-resideload-stop').hide();
                        return;
                    }
                    const d = res.data;
                    totals.processed += d.processed || 0;
                    totals.fixed     += d.fixed_urls || 0;
                    totals.regen     += d.regenerated || 0;
                    totals.failed    += d.failed_urls || 0;
                    totals.added     += d.added || 0;

                    // Log
                    if (d.log && d.log.length) {
                        d.log.forEach(function(entry){
                            if (typeof entry === 'string') {
                                log_line('<em>' + entry + '</em>');
                                return;
                            }
                            const icon = entry.failed > 0 ? '⚠️' : '✅';
                            const link = '<a href="post.php?post=' + entry.post_id + '&action=edit" target="_blank">#' + entry.post_id + '</a>';
                            const title = $('<div>').text(entry.title || '').html();
                            let msg = icon + ' ' + link + ' <strong>' + title + '</strong> — ';
                            const parts = [];
                            if (entry.fixed > 0)       parts.push('<span style="color:#46b450;">' + entry.fixed + ' corrigidas</span>');
                            if (entry.added > 0)       parts.push('<span style="color:#0284c7;">' + entry.added + ' adicionadas</span>');
                            if (entry.regenerated > 0) parts.push('<span style="color:#f59e0b;">' + entry.regenerated + ' regeneradas</span>');
                            if (entry.failed > 0)      parts.push('<span style="color:#c0392b;">' + entry.failed + ' falhas</span>');
                            if (parts.length === 0)    parts.push('<em style="color:#6b7280;">sem mudanças</em>');
                            msg += parts.join(', ');
                            log_line(msg);
                        });
                    }

                    // Totals
                    $('#rs-processed').text(totals.processed);
                    $('#rs-fixed').text(totals.fixed);
                    $('#rs-added').text(totals.added);
                    $('#rs-regen').text(totals.regen);
                    $('#rs-failed').text(totals.failed);

                    // Progresso
                    const total_estimate = totals.processed + (d.remaining || 0);
                    const pct = total_estimate > 0 ? Math.round((totals.processed / total_estimate) * 100) : 100;
                    $('#resideload-bar').css('width', pct + '%');

                    if (d.remaining === 0 || d.processed === 0) {
                        $('#resideload-status').html('🎉 <strong>Concluído!</strong> ' + totals.processed + ' posts processados, ' + totals.fixed + ' URLs corrigidas, ' + totals.added + ' imagens adicionadas, ' + totals.regen + ' regeneradas, ' + totals.failed + ' falhas.');
                        $('#btn-resideload-run').prop('disabled', false);
                        $('#btn-resideload-stop').hide();
                    } else {
                        $('#resideload-status').html('⏳ Processando... <strong>' + totals.processed + '</strong> posts feitos, <strong>' + d.remaining + '</strong> restantes');
                        // Próximo lote em 1.5s
                        setTimeout(process_next_batch, 1500);
                    }
                }).fail(function(xhr){
                    $('#resideload-status').html('❌ <strong>Erro técnico:</strong> ' + xhr.status + '. Os já processados foram salvos. Tente novamente.');
                    $('#btn-resideload-run').prop('disabled', false);
                    $('#btn-resideload-stop').hide();
                });
            }
        })(jQuery);
        </script>
        <?php
    }
}

