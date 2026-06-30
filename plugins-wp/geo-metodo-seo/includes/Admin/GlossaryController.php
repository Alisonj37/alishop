<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Glossary\GeoGlossaryService;
use GeoMetodoSEO\AI\ProviderResolver;

class GlossaryController {

    public static function register_hooks(): void {
        add_action('wp_ajax_geo_glossary_generate_titles', [__CLASS__, 'ajax_generate_titles']);
        add_action('wp_ajax_geo_glossary_generate_article', [__CLASS__, 'ajax_generate_article']);
        add_action('wp_ajax_geo_glossary_delete_title', [__CLASS__, 'ajax_delete_title']);
        // 1.0.0: bulk generation
        add_action('wp_ajax_geo_glossary_generate_bulk', [__CLASS__, 'ajax_generate_bulk']);
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Permissão negada');
        }
        $service = new GeoGlossaryService();
        $titles = $service->get_titles();
        $pending = array_filter($titles, fn($r) => ($r['status'] ?? '') === 'pending');
        $generated = array_filter($titles, fn($r) => ($r['status'] ?? '') === 'generated');
        $nonce = wp_create_nonce('geo_glossary_nonce');
        $page_id = (int) get_option(GeoGlossaryService::OPT_PAGE_ID, 0);
        $page_url = $page_id ? get_permalink($page_id) : '';
        $providers = ['openai' => 'OpenAI', 'groq' => 'Groq', 'gemini' => 'Gemini', 'claude' => 'Claude', 'perplexity' => 'Perplexity', 'naga' => 'Naga.ac'];
        $current_provider = ProviderResolver::for('glossary', get_option('geo_glossary_ai_provider', ''));
        $current_model = (string)get_option('geo_glossary_ai_model', '');
        ?>
        <div class="wrap geo-glossary-admin">
            <h1>📚 GEO Glossário SEO</h1>
            <p style="max-width:980px;color:#555;font-size:14px;">Gere títulos de glossário de A a Z por tema, crie termos com imagem destacada, linkagem interna e publicação em rascunho, publicada ou agendada. Os títulos ficam salvos até cada termo ser gerado.</p>

            <?php if ($page_url): ?>
                <div class="notice notice-success inline"><p><strong>Página pública:</strong> <a href="<?php echo esc_url($page_url); ?>" target="_blank"><?php echo esc_html($page_url); ?></a></p></div>
            <?php endif; ?>

            <div id="geo-glossary-msg" style="display:none;margin:12px 0;padding:12px;border-radius:6px;"></div>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;margin:18px 0;max-width:1100px;">
                <h2>1) Gerador de títulos do glossário</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="geo-glossary-theme">Tema principal</label></th>
                        <td><input type="text" id="geo-glossary-theme" class="regular-text" placeholder="Ex: marketing digital, SEO para IA, smartphones, WordPress" /></td>
                    </tr>
                    <tr>
                        <th><label for="geo-glossary-context">Nicho / contexto</label></th>
                        <td>
                            <textarea id="geo-glossary-context" class="large-text" rows="3" placeholder="Ex: glossário sobre Xiaomi, Redmi, POCO, HyperOS, atualizações, problemas comuns e guias de compra."></textarea>
                            <p class="description">Explique o recorte editorial para evitar termos genéricos no glossário.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geo-glossary-audience">Público-alvo</label></th>
                        <td><textarea id="geo-glossary-audience" class="large-text" rows="2" placeholder="Ex: usuários brasileiros que pesquisam conceitos, dúvidas e termos técnicos antes de comprar ou resolver problemas."></textarea></td>
                    </tr>
                    <tr>
                        <th>Intenção de busca</th>
                        <td>
                            <label><input type="checkbox" class="geo-glossary-intent" value="definição" checked> Definição</label><br>
                            <label><input type="checkbox" class="geo-glossary-intent" value="como funciona" checked> Como funciona</label><br>
                            <label><input type="checkbox" class="geo-glossary-intent" value="comparação" checked> Comparação</label><br>
                            <label><input type="checkbox" class="geo-glossary-intent" value="problema e solução" checked> Problema e solução</label><br>
                            <label><input type="checkbox" class="geo-glossary-intent" value="termos técnicos" checked> Termos técnicos</label><br>
                            <label><input type="checkbox" class="geo-glossary-intent" value="SEO/GEO/AEO/LLM" checked> SEO/GEO/AEO/LLM</label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geo-glossary-extra">Instruções extras</label></th>
                        <td><textarea id="geo-glossary-extra" class="large-text" rows="4" placeholder="Ex: Não invente lançamentos, preços, modelos ou especificações. Priorize termos reais e úteis para o público brasileiro."></textarea></td>
                    </tr>
                    <tr>
                        <th>Letras</th>
                        <td>
                            <label><input type="checkbox" id="geo-glossary-all-letters"> Todas A-Z</label>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;max-width:780px;">
                                <?php foreach (range('A','Z') as $l): ?>
                                    <label style="border:1px solid #ddd;border-radius:5px;padding:5px 8px;background:#fafafa;"><input type="checkbox" class="geo-glossary-letter" value="<?php echo esc_attr($l); ?>"> <?php echo esc_html($l); ?></label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geo-glossary-per-letter">Títulos por letra</label></th>
                        <td><input type="number" id="geo-glossary-per-letter" value="10" min="1" max="15" /> <span class="description">Máximo 15 por letra.</span></td>
                    </tr>
                    <tr>
                        <th><label for="geo-glossary-provider">Provider de texto</label></th>
                        <td>
                            <select id="geo-glossary-provider">
                                <?php foreach ($providers as $k => $label): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($current_provider, $k); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" id="geo-glossary-model" class="regular-text" value="<?php echo esc_attr($current_model); ?>" placeholder="Modelo opcional; vazio usa padrão seguro" style="margin-left:8px;" />
                        </td>
                    </tr>
                </table>
                <p>
                    <button class="button button-primary" id="geo-glossary-generate-titles">Gerar títulos do glossário</button>
                </p>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;margin:18px 0;max-width:1200px;">
                <h2>2) Títulos pendentes</h2>
                <p class="description">Eles não somem após gerar títulos. Só saem da lista pendente quando o glossário correspondente for criado.</p>

                <!-- 1.0.0: Painel de geração em massa -->
                <div style="background:#f6f7f7;border:1px solid #c3c4c7;border-radius:8px;padding:14px;margin:10px 0 16px;">
                    <h3 style="margin:0 0 8px;">⚡ Geração em massa</h3>
                    <p class="description" style="margin:0 0 10px;">Gera artigos para TODOS os títulos pendentes (ou apenas os marcados acima). Processa em lotes de 3 para evitar timeout.</p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <label>Data/hora para agendar:
                            <input type="datetime-local" id="geo-glossary-bulk-schedule" />
                        </label>
                        <span style="margin-left:auto;">
                            <button class="button button-primary geo-glossary-bulk" data-status="draft">📝 Gerar todos como Rascunho</button>
                            <button class="button button-primary geo-glossary-bulk" data-status="publish">🚀 Gerar e Publicar todos</button>
                            <button class="button button-primary geo-glossary-bulk" data-status="future">📅 Gerar e Agendar todos</button>
                        </span>
                    </div>
                    <div id="geo-glossary-bulk-progress" style="display:none;margin-top:12px;padding:10px;background:#fff;border:1px solid #dcdcde;border-radius:6px;">
                        <strong>Progresso:</strong>
                        <span id="geo-glossary-bulk-status">Iniciando...</span>
                        <div style="margin-top:6px;height:6px;background:#eaeaea;border-radius:3px;overflow:hidden;">
                            <div id="geo-glossary-bulk-bar" style="height:100%;background:#2271b1;width:0%;transition:width 0.3s;"></div>
                        </div>
                        <div id="geo-glossary-bulk-log" style="margin-top:10px;max-height:200px;overflow-y:auto;font-size:12px;"></div>
                    </div>
                </div>

                <table class="widefat striped" id="geo-glossary-pending-table">
                    <thead><tr><th><input type="checkbox" id="geo-glossary-select-all"></th><th>Letra</th><th>Tema</th><th>Título</th><th>Intenção</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php if (empty($pending)): ?>
                        <tr><td colspan="6">Nenhum título pendente.</td></tr>
                    <?php else: foreach ($pending as $row): ?>
                        <tr data-id="<?php echo esc_attr($row['id']); ?>">
                            <td><input type="checkbox" class="geo-glossary-row-check" value="<?php echo esc_attr($row['id']); ?>"></td>
                            <td><strong><?php echo esc_html($row['letter']); ?></strong></td>
                            <td><?php echo esc_html($row['theme']); ?></td>
                            <td><?php echo esc_html($row['title']); ?><br><small style="color:#666;"><?php echo esc_html($row['reason'] ?? ''); ?></small></td>
                            <td><?php echo esc_html($row['intent'] ?? 'informacional'); ?></td>
                            <td>
                                <button class="button geo-glossary-generate-one" data-status="draft" title="Gerar como rascunho">📝</button>
                                <button class="button button-primary geo-glossary-generate-one" data-status="publish" title="Gerar e publicar">🚀</button>
                                <button class="button geo-glossary-generate-one" data-status="future" title="Gerar e agendar (use o datetime acima)">📅</button>
                                <button class="button geo-glossary-delete-one" title="Remover">🗑️</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;margin:18px 0;max-width:1200px;">
                <h2>3) Glossários gerados</h2>
                <table class="widefat striped">
                    <thead><tr><th>Letra</th><th>Título</th><th>Status</th><th>Post</th></tr></thead>
                    <tbody>
                    <?php if (empty($generated)): ?>
                        <tr><td colspan="4">Nenhum glossário gerado ainda.</td></tr>
                    <?php else: foreach (array_reverse($generated) as $row): $pid = (int)($row['post_id'] ?? 0); ?>
                        <tr>
                            <td><strong><?php echo esc_html($row['letter']); ?></strong></td>
                            <td><?php echo esc_html($row['title']); ?></td>
                            <td>Gerado em <?php echo esc_html($row['generated_at'] ?? ''); ?></td>
                            <td><?php if ($pid): ?><a href="<?php echo esc_url(get_edit_post_link($pid)); ?>">Editar</a> | <a href="<?php echo esc_url(get_permalink($pid)); ?>" target="_blank">Ver</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script>
        (function($){
            const nonce = <?php echo json_encode($nonce); ?>;
            function msg(text, ok){
                const box = $('#geo-glossary-msg');
                box.text(text).css({display:'block', background: ok ? '#edfaef' : '#fdeaea', border: '1px solid ' + (ok ? '#46b450' : '#d63638')});
            }
            $('#geo-glossary-all-letters').on('change', function(){ $('.geo-glossary-letter').prop('checked', this.checked); });
            $('#geo-glossary-generate-titles').on('click', function(e){
                e.preventDefault();
                const btn = $(this), letters = $('.geo-glossary-letter:checked').map(function(){return this.value;}).get();
                const intents = $('.geo-glossary-intent:checked').map(function(){return this.value;}).get();
                btn.prop('disabled', true).text('Gerando...');
                $.post(ajaxurl, {
                    action:'geo_glossary_generate_titles', nonce:nonce,
                    theme: $('#geo-glossary-theme').val(), letters: letters,
                    context: $('#geo-glossary-context').val(), audience: $('#geo-glossary-audience').val(),
                    intents: intents.join(','), extra: $('#geo-glossary-extra').val(),
                    per_letter: $('#geo-glossary-per-letter').val(),
                    provider: $('#geo-glossary-provider').val(), model: $('#geo-glossary-model').val()
                }).done(function(res){
                    if(res && res.success){ msg(res.data.message || 'Títulos gerados.', true); setTimeout(()=>location.reload(), 900); }
                    else msg((res && res.data && res.data.message) ? res.data.message : 'Erro ao gerar títulos.', false);
                }).fail(function(xhr){ msg('Erro técnico: ' + xhr.status + ' ' + (xhr.responseText || '').substring(0,180), false); })
                  .always(function(){ btn.prop('disabled', false).text('Gerar títulos do glossário'); });
            });
            $('.geo-glossary-generate-one').on('click', function(e){
                e.preventDefault();
                const tr = $(this).closest('tr'), btn = $(this);
                // 1.0.0: lê data-status do botão clicado (📝 draft / 🚀 publish / 📅 future)
                const status = btn.data('status') || $('#geo-glossary-default-status').val() || 'draft';
                const scheduled = $('#geo-glossary-bulk-schedule').val() || $('#geo-glossary-schedule').val() || '';
                if (status === 'future' && !scheduled) {
                    msg('Para agendar, defina data/hora no campo acima.', false);
                    return;
                }
                const originalText = btn.html();
                btn.prop('disabled', true).text('...');
                $.post(ajaxurl, {
                    action:'geo_glossary_generate_article', nonce:nonce,
                    id: tr.data('id'), status: status, scheduled_at: scheduled,
                    provider: $('#geo-glossary-provider').val(), model: $('#geo-glossary-model').val()
                }).done(function(res){
                    if(res && res.success){
                        msg((res.data.message || 'Glossário gerado.') + (res.data.edit_url ? ' — Editar: ' + res.data.edit_url : ''), true);
                        tr.fadeOut(400, function(){ $(this).remove(); });
                    }
                    else msg((res && res.data && res.data.message) ? res.data.message : 'Erro ao gerar glossário.', false);
                }).fail(function(xhr){ msg('Erro técnico: ' + xhr.status + ' ' + (xhr.responseText || '').substring(0,180), false); })
                  .always(function(){ btn.prop('disabled', false).html(originalText); });
            });
            $('.geo-glossary-delete-one').on('click', function(e){
                e.preventDefault();
                if(!confirm('Remover este título pendente?')) return;
                const tr = $(this).closest('tr');
                $.post(ajaxurl, {action:'geo_glossary_delete_title', nonce:nonce, id: tr.data('id')}).done(function(res){
                    if(res && res.success){ tr.remove(); msg('Título removido.', true); }
                    else msg('Falha ao remover título.', false);
                });
            });

            // 1.0.0: select-all checkbox
            $('#geo-glossary-select-all').on('change', function(){
                $('.geo-glossary-row-check').prop('checked', this.checked);
            });

            // 1.0.0: BULK generation com lotes recursivos
            $('.geo-glossary-bulk').on('click', function(e){
                e.preventDefault();
                const status = $(this).data('status') || 'draft';
                const scheduled = $('#geo-glossary-bulk-schedule').val() || '';
                if (status === 'future' && !scheduled) {
                    msg('Para agendar em massa, defina data/hora no campo acima.', false);
                    return;
                }
                // IDs marcados (vazio = todos pendentes)
                const checkedIds = $('.geo-glossary-row-check:checked').map(function(){ return this.value; }).get();
                const totalToProcess = checkedIds.length > 0 ? checkedIds.length : $('#geo-glossary-pending-table tbody tr[data-id]').length;
                if (totalToProcess === 0) {
                    msg('Nenhum título pendente para processar.', false);
                    return;
                }
                const statusLabel = status === 'publish' ? 'publicar' : (status === 'future' ? 'agendar' : 'gerar como rascunho');
                if (!confirm('Confirmar: ' + statusLabel + ' ' + totalToProcess + ' glossário(s)? Custo aproximado: $' + (totalToProcess * 0.04).toFixed(2))) return;

                // Disable todos os botões bulk durante o processamento
                $('.geo-glossary-bulk').prop('disabled', true);
                $('#geo-glossary-bulk-progress').show();
                $('#geo-glossary-bulk-log').empty();

                let processedTotal = 0;
                let successTotal = 0;
                let failedTotal = 0;

                function processBatch(){
                    $.post(ajaxurl, {
                        action: 'geo_glossary_generate_bulk',
                        nonce: nonce,
                        status: status,
                        scheduled_at: scheduled,
                        batch_size: 3,
                        ids: checkedIds,
                        provider: $('#geo-glossary-provider').val(),
                        model: $('#geo-glossary-model').val()
                    }, null, 'json').done(function(res){
                        if (!res || !res.success) {
                            $('#geo-glossary-bulk-status').text('Erro: ' + (res && res.data && res.data.message ? res.data.message : 'falha desconhecida'));
                            $('.geo-glossary-bulk').prop('disabled', false);
                            return;
                        }
                        const d = res.data;
                        processedTotal += d.processed || 0;
                        successTotal += d.success || 0;
                        failedTotal += d.failed || 0;

                        // Log dos resultados
                        (d.results || []).forEach(function(r){
                            const icon = r.success ? '✅' : '❌';
                            const editLink = r.post_id ? ' <a href="post.php?post=' + r.post_id + '&action=edit" target="_blank">[editar]</a>' : '';
                            $('#geo-glossary-bulk-log').append('<div>' + icon + ' <strong>' + r.letter + '</strong> ' + r.title + editLink + (r.success ? '' : ' — ' + r.message) + '</div>');
                        });

                        // Atualiza progresso
                        const progressTotal = processedTotal + (d.remaining || 0);
                        const pct = progressTotal > 0 ? Math.round((processedTotal / progressTotal) * 100) : 100;
                        $('#geo-glossary-bulk-bar').css('width', pct + '%');
                        $('#geo-glossary-bulk-status').text(
                            'Processados: ' + processedTotal + ' (' + successTotal + ' OK, ' + failedTotal + ' falhas) — Restam: ' + (d.remaining || 0)
                        );

                        if (d.finished) {
                            $('#geo-glossary-bulk-status').html('<strong style="color:#46b450;">✅ Concluído!</strong> ' + successTotal + ' glossários gerados, ' + failedTotal + ' falhas.');
                            $('.geo-glossary-bulk').prop('disabled', false);
                            setTimeout(function(){ location.reload(); }, 3500);
                        } else {
                            // Próximo lote após 800ms (respiro)
                            setTimeout(processBatch, 800);
                        }
                    }).fail(function(xhr){
                        $('#geo-glossary-bulk-status').text('Erro técnico: ' + xhr.status + ' — tente novamente. Os já processados foram salvos.');
                        $('.geo-glossary-bulk').prop('disabled', false);
                    });
                }
                processBatch();
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function ajax_generate_titles(): void {
        check_ajax_referer('geo_glossary_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permissão negada']);
        $theme = sanitize_text_field($_POST['theme'] ?? '');
        $letters = $_POST['letters'] ?? [];
        if (!is_array($letters)) $letters = [];
        $letters = array_map('sanitize_text_field', $letters);
        $per = (int)($_POST['per_letter'] ?? 10);
        $briefing = [
            'context' => sanitize_textarea_field($_POST['context'] ?? ''),
            'audience' => sanitize_textarea_field($_POST['audience'] ?? ''),
            'intents' => sanitize_text_field($_POST['intents'] ?? ''),
            'extra' => sanitize_textarea_field($_POST['extra'] ?? ''),
        ];
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $model = sanitize_text_field($_POST['model'] ?? '');
        $service = new GeoGlossaryService();
        $result = $service->generate_titles($theme, $letters, $per, $provider, $model, $briefing);
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }

    public static function ajax_generate_article(): void {
        check_ajax_referer('geo_glossary_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permissão negada']);
        @set_time_limit(240);
        $id = sanitize_text_field($_POST['id'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? 'draft');
        $scheduled = sanitize_text_field($_POST['scheduled_at'] ?? '');
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $model = sanitize_text_field($_POST['model'] ?? '');
        $service = new GeoGlossaryService();
        $result = $service->generate_glossary_article($id, $status, $scheduled, $provider, $model);
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }

    public static function ajax_delete_title(): void {
        check_ajax_referer('geo_glossary_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permissão negada']);
        $id = sanitize_text_field($_POST['id'] ?? '');
        $service = new GeoGlossaryService();
        $state = $service->get_titles();
        if (isset($state[$id]) && ($state[$id]['status'] ?? '') === 'pending') {
            unset($state[$id]);
            $service->save_titles($state);
        }
        wp_send_json_success(['message' => 'Removido']);
    }

    /**
     * Geração em massa de glossários a partir de títulos pendentes.
     * Processa em lotes pequenos para evitar timeout. Retorna progresso.
     * @since 1.0.0
     */
    public static function ajax_generate_bulk(): void {
        check_ajax_referer('geo_glossary_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permissão negada']);
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $status      = sanitize_text_field($_POST['status'] ?? 'draft');
        $scheduled   = sanitize_text_field($_POST['scheduled_at'] ?? '');
        $provider    = sanitize_text_field($_POST['provider'] ?? '');
        $model       = sanitize_text_field($_POST['model'] ?? '');
        // Quantos processar nessa request (lote). Default 3 — equilibra tempo vs progresso.
        $batch_size  = max(1, min(10, (int)($_POST['batch_size'] ?? 3)));
        // IDs específicos selecionados, ou vazio = todos pendentes
        $ids         = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids         = array_map('sanitize_text_field', $ids);

        $service = new GeoGlossaryService();
        $state   = $service->get_titles();

        // Filtra pendentes
        $pending = [];
        foreach ($state as $id => $row) {
            if (($row['status'] ?? '') !== 'pending') continue;
            if (!empty($ids) && !in_array($id, $ids, true)) continue;
            $pending[$id] = $row;
        }

        if (empty($pending)) {
            wp_send_json_success([
                'finished'  => true,
                'processed' => 0,
                'remaining' => 0,
                'results'   => [],
                'message'   => 'Nenhum título pendente para processar.',
            ]);
        }

        // Processa apenas o lote da request atual
        $batch     = array_slice($pending, 0, $batch_size, true);
        $results   = [];
        $success   = 0;
        $failed    = 0;

        foreach ($batch as $id => $row) {
            $result = $service->generate_glossary_article($id, $status, $scheduled, $provider, $model);
            $is_ok = !empty($result['success']);
            $results[] = [
                'id'      => $id,
                'title'   => $row['title'] ?? '',
                'letter'  => $row['letter'] ?? '',
                'success' => $is_ok,
                'message' => $result['message'] ?? '',
                'post_id' => $result['post_id'] ?? 0,
            ];
            if ($is_ok) $success++;
            else $failed++;

            // Pequena pausa entre artigos pra dar respiro pro Controle de uso
            usleep(500000); // 500ms
        }

        // Recalcula pendentes restantes
        $state_after = $service->get_titles();
        $remaining = 0;
        foreach ($state_after as $r) {
            if (($r['status'] ?? '') === 'pending') {
                if (!empty($ids) && !in_array($r['id'] ?? '', $ids, true)) continue;
                $remaining++;
            }
        }

        wp_send_json_success([
            'finished'   => $remaining === 0,
            'processed'  => count($batch),
            'success'    => $success,
            'failed'     => $failed,
            'remaining'  => $remaining,
            'results'    => $results,
            'message'    => sprintf('Lote: %d processados (%d sucesso, %d falha). Restam %d.',
                count($batch), $success, $failed, $remaining),
        ]);
    }
}
