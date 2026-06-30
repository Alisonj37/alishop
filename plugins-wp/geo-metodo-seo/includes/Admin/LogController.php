<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Services\LogService;

/**
 * LogController — Página unificada de Logs do plugin.
 *
 * @since 1.0.0 Reescrita completa com filtros, busca, paginação e stats.
 */
class LogController {

    private $type_styles = [
        'info'    => 'background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;',
        'success' => 'background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;',
        'warning' => 'background:#fef3c7;color:#a16207;border:1px solid #fde68a;',
        'error'   => 'background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;',
    ];

    private $type_icons = [
        'info'    => 'ℹ️',
        'success' => '✅',
        'warning' => '⚠️',
        'error'   => '❌',
    ];

    private $module_labels = [
        'generator'    => '✍️ Gerador Individual',
        'bulk'         => '📚 Geração em Massa',
        'template'     => '📄 Templates',
        'title'        => '✏️ Títulos',
        'cluster'      => '🌐 Cluster SEO',
        'schema'       => '🏗️ Schemas',
        'eeat'         => '🧠 E-E-A-T',
        'gsc'          => '🔍 Google Search Console',
        'falai'        => '🎨 Fal.ai gpt-image-2',
        'replicate'    => '🤖 Replicate',
        'huggingface'  => '🤗 HuggingFace Flux Schnell',
        'naga'         => '🐉 Naga AI',
        'tts'          => '🔊 Text-to-Speech',
        'youtube'      => '🎬 YouTube',
        'webstories'   => '📱 Web Stories',
        'pipeline'     => '⚙️ Pipeline de Artigos',
        'updater'      => '🔄 Content Updater',
        'autopilot'    => '🤖 SARA Autopilot',
        'license'      => '🔑 Licença',
        'ai'           => '🧠 IA (texto)',
        'seo'          => '📈 SEO',
        'media'        => '📰 Media Opportunities',
        'indexing'     => '🔗 IndexNow',
        'refresher'    => '♻️ Content Refresher',
        'settings'     => '⚙️ Configurações',
        'writer'       => '✍️ SARA Writer',
        'brain'        => '🧠 SARA Brain',
        'system'       => '🖥️ Sistema',
        'other'        => '📦 Outros',
    ];

    public function render_page() {
        // Garantir schema novo
        LogService::ensure_schema();

        // Tratar limpeza
        $cleared = false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['geo_clear_logs'])) {
            check_admin_referer('geo_clear_logs_nonce');
            if (current_user_can('manage_options')) {
                $clear_filters = [];
                if (!empty($_POST['clear_module'])) $clear_filters['module'] = sanitize_text_field($_POST['clear_module']);
                if (!empty($_POST['clear_type']))   $clear_filters['type']   = sanitize_text_field($_POST['clear_type']);
                if (!empty($_POST['clear_older_days']) && (int)$_POST['clear_older_days'] > 0) {
                    $clear_filters['older_than_days'] = (int)$_POST['clear_older_days'];
                }
                LogService::clear($clear_filters);
                $cleared = true;
            }
        }

        // Coletar filtros do GET
        $filters = [
            'type'    => isset($_GET['type'])    ? sanitize_text_field($_GET['type'])      : '',
            'module'  => isset($_GET['module'])  ? sanitize_text_field($_GET['module'])    : '',
            'period'  => isset($_GET['period'])  ? sanitize_text_field($_GET['period'])    : 'all',
            'search'  => isset($_GET['search'])  ? sanitize_text_field($_GET['search'])    : '',
            'post_id' => isset($_GET['post_id']) ? (int) $_GET['post_id']                  : 0,
            'limit'   => 200,
            'offset'  => isset($_GET['paged']) ? (max(1, (int)$_GET['paged']) - 1) * 200    : 0,
        ];

        $result    = LogService::query($filters);
        $rows      = $result['rows'];
        $total     = $result['total'];
        $modules   = LogService::distinct_modules();
        $stats_24h = LogService::get_stats_24h();
        $page_url  = admin_url('admin.php?page=geo-logs');

        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                📋 Logs do Plugin
                <span style="font-size:13px;color:#666;font-weight:normal;">
                    <?php echo number_format($total); ?> evento<?php echo $total !== 1 ? 's' : ''; ?>
                    <?php if (!empty($filters['type']) || !empty($filters['module']) || !empty($filters['search']) || !empty($filters['post_id']) || $filters['period'] !== 'all'): ?>
                        (filtrado)
                    <?php endif; ?>
                </span>
            </h1>

            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible"><p>✅ Logs limpos com sucesso.</p></div>
            <?php endif; ?>

            <?php $this->render_stats_24h($stats_24h); ?>

            <?php $this->render_filters($filters, $modules, $page_url); ?>

            <?php $this->render_clear_form($modules); ?>

            <?php $this->render_table($rows, $total, $filters, $page_url); ?>
        </div>

        <style>
        .geo-logs-stats {
            display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
            gap:8px; margin:14px 0;
        }
        .geo-logs-stat-card {
            background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:10px 12px;
        }
        .geo-logs-stat-title { font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px; }
        .geo-logs-stat-num   { font-size:22px;font-weight:700;color:#0f172a;margin-top:2px; }
        .geo-logs-stat-bd    { font-size:10px;color:#475569;margin-top:4px;display:flex;gap:6px;flex-wrap:wrap; }
        .geo-logs-filters {
            background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin:14px 0;
        }
        .geo-logs-filters .row { display:flex;flex-wrap:wrap;gap:10px;align-items:end; }
        .geo-logs-filters label {
            display:block; font-size:11px; font-weight:600; color:#475569;
            margin-bottom:3px; text-transform:uppercase; letter-spacing:.3px;
        }
        .geo-logs-filters input, .geo-logs-filters select {
            padding:6px 8px; border:1px solid #cbd5e1; border-radius:4px; min-width:130px;
        }
        .geo-logs-filters .field { display:flex;flex-direction:column; }
        .geo-logs-row-context {
            background:#f8fafc; padding:8px 12px; font-family:monospace; font-size:11px;
            color:#334155; border-top:1px solid #e2e8f0; white-space:pre-wrap; word-break:break-all;
        }
        .geo-logs-clear-form {
            background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;margin:14px 0;
        }
        </style>
        <?php
    }

    private function render_stats_24h(array $stats_24h): void {
        if (empty($stats_24h)) return;
        ?>
        <div class="geo-logs-stats">
            <?php foreach ($stats_24h as $module => $s):
                $label = $this->module_labels[$module] ?? '📦 ' . esc_html($module);
            ?>
                <a href="<?php echo esc_url(add_query_arg(['module' => $module, 'period' => '24h'], admin_url('admin.php?page=geo-logs'))); ?>"
                   class="geo-logs-stat-card" style="text-decoration:none;color:inherit;">
                    <div class="geo-logs-stat-title"><?php echo $label; ?></div>
                    <div class="geo-logs-stat-num"><?php echo number_format($s['total']); ?></div>
                    <div class="geo-logs-stat-bd">
                        <?php if ($s['error'])   echo '<span style="color:#dc2626;">❌ '. $s['error']   .'</span>'; ?>
                        <?php if ($s['warning']) echo '<span style="color:#d97706;">⚠️ '. $s['warning'] .'</span>'; ?>
                        <?php if ($s['success']) echo '<span style="color:#16a34a;">✅ '. $s['success'] .'</span>'; ?>
                        <?php if ($s['info'])    echo '<span style="color:#0284c7;">ℹ️ '.  $s['info']    .'</span>'; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_filters(array $filters, array $modules, string $page_url): void {
        ?>
        <form method="get" class="geo-logs-filters">
            <input type="hidden" name="page" value="geo-logs">

            <div class="row">
                <div class="field">
                    <label>Busca</label>
                    <input type="search" name="search" value="<?php echo esc_attr($filters['search']); ?>"
                           placeholder="Buscar em mensagem ou ação..." style="min-width:240px;">
                </div>

                <div class="field">
                    <label>Tipo</label>
                    <select name="type">
                        <option value="">Todos</option>
                        <option value="error"   <?php selected($filters['type'], 'error'); ?>>❌ Erro</option>
                        <option value="warning" <?php selected($filters['type'], 'warning'); ?>>⚠️ Aviso</option>
                        <option value="success" <?php selected($filters['type'], 'success'); ?>>✅ Sucesso</option>
                        <option value="info"    <?php selected($filters['type'], 'info'); ?>>ℹ️ Info</option>
                    </select>
                </div>

                <div class="field">
                    <label>Módulo</label>
                    <select name="module">
                        <option value="">Todos os módulos</option>
                        <?php
                        // Mostrar primeiro os que têm logs no banco
                        foreach ($modules as $m):
                            $label = $this->module_labels[$m] ?? esc_html($m);
                        ?>
                            <option value="<?php echo esc_attr($m); ?>" <?php selected($filters['module'], $m); ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Período</label>
                    <select name="period">
                        <option value="all" <?php selected($filters['period'], 'all'); ?>>Todos</option>
                        <option value="24h" <?php selected($filters['period'], '24h'); ?>>Últimas 24h</option>
                        <option value="7d"  <?php selected($filters['period'], '7d');  ?>>Últimos 7 dias</option>
                        <option value="30d" <?php selected($filters['period'], '30d'); ?>>Últimos 30 dias</option>
                    </select>
                </div>

                <div class="field">
                    <label>Post ID</label>
                    <input type="number" name="post_id" value="<?php echo $filters['post_id'] ? (int)$filters['post_id'] : ''; ?>"
                           placeholder="0" style="min-width:80px;width:100px;">
                </div>

                <div>
                    <button type="submit" class="button button-primary">🔎 Filtrar</button>
                    <a href="<?php echo esc_url($page_url); ?>" class="button">🔄 Limpar filtros</a>
                </div>
            </div>
        </form>
        <?php
    }

    private function render_clear_form(array $modules): void {
        ?>
        <details class="geo-logs-clear-form">
            <summary style="cursor:pointer;font-weight:600;color:#7f1d1d;">🗑️ Limpar logs (com filtros opcionais)</summary>
            <form method="post" style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                <?php wp_nonce_field('geo_clear_logs_nonce'); ?>
                <input type="hidden" name="geo_clear_logs" value="1">

                <div class="field">
                    <label style="font-size:11px;color:#7f1d1d;font-weight:600;">Módulo (opcional)</label>
                    <select name="clear_module" style="padding:6px 8px;border:1px solid #fca5a5;border-radius:4px;">
                        <option value="">Todos</option>
                        <?php foreach ($modules as $m): $label = $this->module_labels[$m] ?? esc_html($m); ?>
                            <option value="<?php echo esc_attr($m); ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label style="font-size:11px;color:#7f1d1d;font-weight:600;">Tipo (opcional)</label>
                    <select name="clear_type" style="padding:6px 8px;border:1px solid #fca5a5;border-radius:4px;">
                        <option value="">Todos</option>
                        <option value="info">ℹ️ Info</option>
                        <option value="success">✅ Sucesso</option>
                        <option value="warning">⚠️ Aviso</option>
                        <option value="error">❌ Erro</option>
                    </select>
                </div>

                <div class="field">
                    <label style="font-size:11px;color:#7f1d1d;font-weight:600;">Antes de N dias atrás (opcional)</label>
                    <input type="number" name="clear_older_days" min="0" placeholder="0 = todos"
                           style="padding:6px 8px;border:1px solid #fca5a5;border-radius:4px;width:120px;">
                </div>

                <button type="submit" class="button" style="background:#dc2626;color:#fff;border:none;"
                        onclick="return confirm('Tem certeza? Esta ação não pode ser desfeita.')">
                    🗑️ Limpar logs com esses filtros
                </button>
            </form>
        </details>
        <?php
    }

    private function render_table(array $rows, int $total, array $filters, string $page_url): void {
        if (empty($rows)) {
            echo '<p style="color:#666;padding:20px;text-align:center;background:#f8fafc;border-radius:8px;margin-top:14px;">Nenhum log encontrado com esses filtros.</p>';
            return;
        }

        // Paginação
        $current_page = max(1, (int) ($_GET['paged'] ?? 1));
        $total_pages  = (int) ceil($total / 200);
        ?>
        <table class="widefat fixed striped" style="margin-top:8px;">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th style="width:155px;">Data/Hora</th>
                    <th style="width:90px;">Tipo</th>
                    <th style="width:130px;">Módulo</th>
                    <th style="width:110px;">Ação</th>
                    <th style="width:75px;">Post</th>
                    <th>Mensagem</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $log):
                $type    = $log->type ?? 'info';
                $style   = $this->type_styles[$type] ?? 'background:#f0f0f0;color:#555;border:1px solid #ddd;';
                $icon    = $this->type_icons[$type]  ?? '•';
                $module  = $log->module ?? 'other';
                $mlabel  = $this->module_labels[$module] ?? esc_html($module);

                $context_data = null;
                if (!empty($log->context)) {
                    $context_data = json_decode($log->context, true);
                }
            ?>
                <tr>
                    <td style="color:#999;font-size:11px;"><?php echo intval($log->id); ?></td>
                    <td style="font-size:11px;white-space:nowrap;color:#334155;"><?php echo esc_html($log->created_at); ?></td>
                    <td>
                        <span style="<?php echo esc_attr($style); ?>padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">
                            <?php echo esc_html($icon . ' ' . strtoupper($type)); ?>
                        </span>
                    </td>
                    <td style="font-size:11px;">
                        <a href="<?php echo esc_url(add_query_arg('module', $module, admin_url('admin.php?page=geo-logs'))); ?>"
                           style="text-decoration:none;color:#475569;">
                            <?php echo $mlabel; ?>
                        </a>
                    </td>
                    <td style="font-size:11px;color:#64748b;font-family:monospace;"><?php echo esc_html($log->action ?? '—'); ?></td>
                    <td style="font-size:11px;">
                        <?php if (!empty($log->post_id) && (int)$log->post_id > 0): ?>
                            <a href="<?php echo esc_url(get_edit_post_link((int)$log->post_id)); ?>" target="_blank">
                                #<?php echo intval($log->post_id); ?>
                            </a>
                        <?php else: ?>
                            <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;word-break:break-word;">
                        <?php echo esc_html($log->message ?? ''); ?>
                        <?php if (!empty($log->duration_ms)): ?>
                            <span style="color:#94a3b8;font-size:10px;font-family:monospace;">[<?php echo (int)$log->duration_ms; ?>ms]</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($context_data) && is_array($context_data)): ?>
                <tr>
                    <td colspan="7" class="geo-logs-row-context">
                        <strong>Context:</strong> <?php echo esc_html(wp_json_encode($context_data)); ?>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <div class="tablenav" style="margin-top:14px;">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo number_format($total); ?> itens</span>
                    <span class="pagination-links">
                        <?php
                        $params = $_GET;
                        for ($p = 1; $p <= min($total_pages, 20); $p++) {
                            $params['paged'] = $p;
                            $url = add_query_arg($params, admin_url('admin.php'));
                            if ($p === $current_page) {
                                echo '<span class="page-numbers current" style="background:#0073aa;color:#fff;padding:4px 10px;margin:0 2px;border-radius:3px;">'.$p.'</span>';
                            } else {
                                echo '<a href="'.esc_url($url).'" style="padding:4px 10px;margin:0 2px;background:#f1f5f9;border-radius:3px;text-decoration:none;color:#0f172a;">'.$p.'</a>';
                            }
                        }
                        if ($total_pages > 20) echo '<span style="margin-left:8px;color:#64748b;">... +' . ($total_pages - 20) . ' páginas</span>';
                        ?>
                    </span>
                </div>
            </div>
        <?php endif;
    }
}

