<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Helpers\SecurityHelper;

class TemplateController {

    private function get_table() {
        global $wpdb;
        return $wpdb->prefix . 'geo_templates';
    }

    public function render_page() {
        global $wpdb;
        $table   = $this->get_table();
        $message = '';
        $error   = '';

        // Handle actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            SecurityHelper::verify_nonce($_POST['_wpnonce'] ?? '', 'geo_templates_action');
            SecurityHelper::current_user_can_manage();

            $action = sanitize_text_field($_POST['geo_template_action'] ?? '');

            if ($action === 'save') {
                $id   = intval($_POST['geo_template_id'] ?? 0);
                $name = sanitize_text_field($_POST['geo_template_name'] ?? '');
                $raw_sections = $_POST['geo_sections'] ?? [];

                if (empty($name)) {
                    $error = 'Informe o nome do template.';
                } else {
                    $sections = [];
                    if (is_array($raw_sections)) {
                        foreach ($raw_sections as $sec) {
                            $heading = sanitize_text_field($sec['heading'] ?? '');
                            $prompt  = sanitize_textarea_field($sec['prompt'] ?? '');
                            if ($heading !== '') {
                                $sections[] = ['heading' => $heading, 'prompt' => $prompt];
                            }
                        }
                    }

                    $data = [
                        'name'     => $name,
                        'sections' => wp_json_encode($sections),
                    ];

                    if ($id > 0) {
                        $wpdb->update($table, $data, ['id' => $id]);
                        $message = 'Template atualizado com sucesso!';
                    } else {
                        $wpdb->insert($table, $data);
                        $message = 'Template criado com sucesso!';
                    }
                }
            } elseif ($action === 'delete') {
                $id = intval($_POST['geo_template_id'] ?? 0);
                if ($id > 0) {
                    $wpdb->delete($table, ['id' => $id]);
                    $message = 'Template removido.';
                }
            }
        }

        $templates = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");

        // Check if editing
        $editing = null;
        if (isset($_GET['edit']) && intval($_GET['edit']) > 0) {
            $editing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", intval($_GET['edit'])));
        }

        $nonce = wp_create_nonce('geo_templates_action');

        ?>
        <div class="wrap">
            <h1>Templates de Artigo — GEO Metodo SEO v<?php echo GEO_METODO_SEO_VERSION; ?></h1>
            <p class="description">
                Crie templates personalizados com secoes H2 e prompts especificos. Use na geracao individual e em massa.
            </p>

            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible"><p><strong><?php echo esc_html($message); ?></strong></p></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <div style="display:flex; gap:32px; flex-wrap:wrap; margin-top:20px;">

                <!-- Form -->
                <div style="flex:1; min-width:420px; max-width:680px;">
                    <h2 style="font-size:16px;"><?php echo $editing ? 'Editar Template' : 'Novo Template'; ?></h2>
                    <form method="post" id="geo-template-form">
                        <?php wp_nonce_field('geo_templates_action'); ?>
                        <input type="hidden" name="geo_template_action" value="save">
                        <input type="hidden" name="geo_template_id" value="<?php echo $editing ? intval($editing->id) : 0; ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="geo_template_name">Nome do Template</label></th>
                                <td>
                                    <input type="text" id="geo_template_name" name="geo_template_name"
                                           value="<?php echo esc_attr($editing->name ?? ''); ?>"
                                           class="large-text" placeholder="Ex: Artigo Padrao SEO" required>
                                </td>
                            </tr>
                        </table>

                        <h3 style="font-size:14px; margin-top:20px;">Secoes H2 <small style="color:#666;">(arraste para reordenar)</small></h3>
                        <div id="geo-sections-list" style="margin-bottom:12px;">
                            <?php
                            $init_sections = [];
                            if ($editing && !empty($editing->sections)) {
                                $init_sections = json_decode($editing->sections, true) ?: [];
                            }
                            if (empty($init_sections)) {
                                $init_sections = [
                                    ['heading' => 'Introducao', 'prompt' => ''],
                                    ['heading' => 'O que e {{keyword}}', 'prompt' => ''],
                                    ['heading' => 'Como funciona {{keyword}}', 'prompt' => ''],
                                    ['heading' => 'Beneficios de {{keyword}}', 'prompt' => ''],
                                    ['heading' => 'Conclusao', 'prompt' => ''],
                                ];
                            }
                            foreach ($init_sections as $i => $sec):
                            ?>
                                <div class="geo-section-row" style="background:#f9f9f9; border:1px solid #ddd; border-radius:4px; padding:12px; margin-bottom:8px; cursor:move;">
                                    <div style="display:flex; gap:8px; align-items:flex-start;">
                                        <span style="font-size:18px; color:#999; margin-top:4px; cursor:grab;">☰</span>
                                        <div style="flex:1;">
                                            <input type="text"
                                                   name="geo_sections[<?php echo $i; ?>][heading]"
                                                   value="<?php echo esc_attr($sec['heading']); ?>"
                                                   placeholder="Titulo da secao H2"
                                                   class="large-text geo-sec-heading"
                                                   style="margin-bottom:6px;">
                                            <textarea name="geo_sections[<?php echo $i; ?>][prompt]"
                                                      placeholder="Prompt especifico para esta secao (opcional). Use {{keyword}} para inserir a keyword."
                                                      class="large-text geo-sec-prompt"
                                                      rows="2"
                                                      style="font-size:12px;"><?php echo esc_textarea($sec['prompt']); ?></textarea>
                                        </div>
                                        <button type="button" class="button geo-remove-section" style="color:#dc3232;">✕</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" id="geo-add-section" class="button">+ Adicionar Secao</button>

                        <p style="margin-top:20px;">
                            <button type="submit" class="button button-primary button-large">
                                <?php echo $editing ? '💾 Atualizar Template' : '✅ Salvar Template'; ?>
                            </button>
                            <?php if ($editing): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=geo-templates')); ?>"
                                   class="button" style="margin-left:8px;">Cancelar</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <!-- Templates list -->
                <div style="flex:1; min-width:280px;">
                    <h2 style="font-size:16px;">Templates Salvos</h2>
                    <?php if (empty($templates)): ?>
                        <p style="color:#666;">Nenhum template criado ainda.</p>
                    <?php else: ?>
                        <table class="widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th style="width:70px;">Secoes</th>
                                    <th style="width:110px;">Acoes</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($templates as $tpl): ?>
                                <?php
                                $secs = json_decode($tpl->sections, true) ?: [];
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($tpl->name); ?></strong></td>
                                    <td style="text-align:center;"><?php echo count($secs); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=geo-templates&edit=' . $tpl->id)); ?>"
                                           class="button button-small">Editar</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Remover este template?');">
                                            <?php wp_nonce_field('geo_templates_action'); ?>
                                            <input type="hidden" name="geo_template_action" value="delete">
                                            <input type="hidden" name="geo_template_id" value="<?php echo intval($tpl->id); ?>">
                                            <button type="submit" class="button button-small" style="color:#dc3232;">Remover</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
        <script>
        (function(){
            var list = document.getElementById('geo-sections-list');
            var idx  = <?php echo count($init_sections); ?>;

            // Sortable drag-and-drop
            if (list && typeof Sortable !== 'undefined') {
                Sortable.create(list, {
                    handle: 'span',
                    animation: 150,
                    onEnd: function() { reindex(); }
                });
            }

            function reindex() {
                var rows = list.querySelectorAll('.geo-section-row');
                rows.forEach(function(row, i) {
                    row.querySelectorAll('input[type=text]').forEach(function(inp) {
                        inp.name = inp.name.replace(/\[\d+\]/, '[' + i + ']');
                    });
                    row.querySelectorAll('textarea').forEach(function(ta) {
                        ta.name = ta.name.replace(/\[\d+\]/, '[' + i + ']');
                    });
                });
            }

            document.getElementById('geo-add-section').addEventListener('click', function() {
                var row = document.createElement('div');
                row.className = 'geo-section-row';
                row.style.cssText = 'background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:12px;margin-bottom:8px;cursor:move;';
                row.innerHTML = '<div style="display:flex;gap:8px;align-items:flex-start;">'
                    + '<span style="font-size:18px;color:#999;margin-top:4px;cursor:grab;">☰</span>'
                    + '<div style="flex:1;">'
                    + '<input type="text" name="geo_sections[' + idx + '][heading]" placeholder="Titulo da secao H2" class="large-text geo-sec-heading" style="margin-bottom:6px;">'
                    + '<textarea name="geo_sections[' + idx + '][prompt]" placeholder="Prompt especifico para esta secao (opcional). Use {{keyword}}." class="large-text geo-sec-prompt" rows="2" style="font-size:12px;"></textarea>'
                    + '</div>'
                    + '<button type="button" class="button geo-remove-section" style="color:#dc3232;">✕</button>'
                    + '</div>';
                list.appendChild(row);
                idx++;
                row.querySelector('.geo-remove-section').addEventListener('click', function() {
                    row.remove(); reindex();
                });
            });

            list.addEventListener('click', function(e) {
                if (e.target.classList.contains('geo-remove-section')) {
                    e.target.closest('.geo-section-row').remove();
                    reindex();
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Get all templates as array for use in select dropdowns.
     */
    public static function get_templates_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_templates';
        return $wpdb->get_results("SELECT id, name FROM {$table} ORDER BY name ASC");
    }

    /**
     * Get sections of a given template by ID.
     * Returns array of ['heading'=>'...','prompt'=>'...']
     */
    public static function get_template_sections(int $id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_templates';
        $row   = $wpdb->get_row($wpdb->prepare("SELECT sections FROM {$table} WHERE id = %d", $id));
        if (!$row) return [];
        return json_decode($row->sections, true) ?: [];
    }
}
