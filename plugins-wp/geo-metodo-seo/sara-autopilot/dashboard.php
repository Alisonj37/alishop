<?php
/**
 * SARA AUTOPILOT — Dashboard Unificado
 * Controle dos 2 agentes: Brain (planejador) e Writer (gerador)
 */
if (!defined('ABSPATH')) exit;

use GeoMetodoSEO\Autopilot\Shared\{AutopilotInstaller, AutopilotLogger};
use GeoMetodoSEO\Autopilot\Brain\{SaraBrain, SaraIndexer};
use GeoMetodoSEO\Autopilot\Writer\SaraWriter;
use GeoMetodoSEO\Autopilot\Professional\SaraHealthMonitor;

// Garantir tabelas criadas antes de qualquer query
try {
    GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller::create_tables();
} catch (\Throwable $e) {
    // Silenciar se já existem
}

try {
    $brain_status  = (new SaraBrain())->status();
    $writer_status = (new SaraWriter())->status();
    $indexer       = new SaraIndexer();
    $logs          = AutopilotLogger::recent('', 30);
    $cost_30d      = AutopilotLogger::total_cost(30);
    $health        = class_exists(SaraHealthMonitor::class) ? SaraHealthMonitor::snapshot() : null;
} catch (\Throwable $e) {
    $brain_status  = ['enabled' => false, 'indexed_posts' => 0, 'next_run' => '—', 'last_run' => '—', 'site_mode' => 'conservative'];
    $writer_status = ['enabled' => false, 'publish_mode' => 'draft', 'pending_today' => 0, 'done_today' => 0, 'next_job' => null];
    $indexer       = null;
    $logs          = [];
    $cost_30d      = 0;
    $health        = null;
    echo '<div class="notice notice-error"><p>⚠️ Erro ao carregar dados: ' . esc_html($e->getMessage()) . '</p></div>';
}

global $wpdb;
$cal_table = $wpdb->prefix . 'sara_editorial_calendar';

// Usar o fuso configurado no WordPress. O date() do PHP pode usar UTC/servidor
// e esconder artigos planejados quando o site está em America/Sao_Paulo.
$today        = current_time('Y-m-d');
$tomorrow     = date('Y-m-d', strtotime($today . ' +1 day'));
$week_end     = date('Y-m-d', strtotime($today . ' +7 days'));
$display_today = date_i18n('d/m/Y', current_time('timestamp'));

$calendar_today = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$cal_table} WHERE scheduled_date = %s ORDER BY scheduled_time ASC",
    $today
), ARRAY_A) ?: [];

$calendar_tomorrow = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$cal_table} WHERE scheduled_date = %s ORDER BY scheduled_time ASC",
    $tomorrow
), ARRAY_A) ?: [];

$calendar_upcoming = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$cal_table}
     WHERE scheduled_date >= %s AND status IN ('pending','retrying','processing')
     ORDER BY scheduled_date ASC, scheduled_time ASC
     LIMIT 50",
    $today
), ARRAY_A) ?: [];

$calendar_week = $wpdb->get_results($wpdb->prepare(
    "SELECT scheduled_date, COUNT(*) as total,
     SUM(status='done') as done, SUM(status='pending') as pending, SUM(status='failed') as failed
     FROM {$cal_table}
     WHERE scheduled_date BETWEEN %s AND %s
     GROUP BY scheduled_date ORDER BY scheduled_date ASC",
    $today, $week_end
), ARRAY_A) ?: [];

$site_mode = AutopilotInstaller::get('site_mode', 'conservative');
$nonce     = wp_create_nonce('sara_autopilot_nonce');
?>
<div class="wrap" id="sara-autopilot">
<style>
#sara-autopilot{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
.ap-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:20px 0;}
.ap-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.ap-card h3{margin:0 0 16px;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.ap-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;text-transform:uppercase;}
.badge-green{background:#d1fae5;color:#065f46;}
.badge-yellow{background:#fef3c7;color:#92400e;}
.badge-red{background:#fee2e2;color:#991b1b;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.ap-stat{text-align:center;padding:12px;}
.ap-stat .num{font-size:32px;font-weight:800;color:#1a1a2e;}
.ap-stat .label{font-size:12px;color:#6b7280;margin-top:2px;}
.ap-stats-row{display:flex;gap:12px;flex-wrap:wrap;}
.btn-run{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;transition:.2s;}
.btn-run:hover{opacity:.85;}
.btn-run:disabled{opacity:.5;cursor:default;}
.btn-secondary{background:#f3f4f6;color:#374151;border:1px solid #d1d5db;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:13px;}
.ap-table{width:100%;border-collapse:collapse;font-size:13px;}
.ap-table th{background:#f8fafc;padding:10px 12px;text-align:left;border-bottom:2px solid #e5e7eb;font-weight:600;color:#374151;}
.ap-table td{padding:9px 12px;border-bottom:1px solid #f1f5f9;}
.ap-table tr:last-child td{border-bottom:none;}
.ap-table tr:hover td{background:#fafafa;}
.status-done{color:#065f46;font-weight:600;}
.status-pending{color:#92400e;}
.status-processing{color:#1e40af;}
.status-failed{color:#991b1b;}
.ap-log{font-size:12px;max-height:300px;overflow-y:auto;background:#1a1a2e;color:#94a3b8;padding:16px;border-radius:8px;font-family:monospace;}
.ap-log .log-success{color:#34d399;}
.ap-log .log-error{color:#f87171;}
.ap-log .log-warning{color:#fbbf24;}
.ap-log .log-start{color:#60a5fa;}
.config-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.config-field label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;}
.config-field input,.config-field select,.config-field textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;}
.ap-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;}
.ap-header h2{margin:0;font-size:24px;display:flex;align-items:center;gap:10px;}
.cost-display{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #86efac;padding:12px 20px;border-radius:10px;text-align:center;}
.cost-display .amount{font-size:28px;font-weight:800;color:#166534;}
.mode-toggle{display:flex;gap:8px;align-items:center;}
.mode-btn{padding:8px 16px;border-radius:8px;border:2px solid transparent;cursor:pointer;font-size:13px;font-weight:600;transition:.2s;}
.mode-btn.active-conservative{background:#fef3c7;border-color:#f59e0b;color:#92400e;}
.mode-btn.active-aggressive{background:#dbeafe;border-color:#3b82f6;color:#1e40af;}
.mode-btn:not(.active-conservative):not(.active-aggressive){background:#f9fafb;color:#6b7280;}
</style>

<div class="ap-header">
    <h2>🤖 SARA AUTOPILOT <span class="ap-badge badge-blue">v4.0</span></h2>
    <div style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:13px;color:#6b7280;">Modo do site:</span>
        <div class="mode-toggle">
            <button class="mode-btn <?php echo $site_mode==='conservative'?'active-conservative':''; ?>"
                    onclick="setSiteMode('conservative')" id="btn-conservative">
                🌱 Conservador
            </button>
            <button class="mode-btn <?php echo $site_mode==='aggressive'?'active-aggressive':''; ?>"
                    onclick="setSiteMode('aggressive')" id="btn-aggressive">
                🚀 Agressivo
            </button>
        </div>
        <div class="cost-display">
            <div class="amount">$<?php echo number_format($cost_30d, 4); ?></div>
            <div style="font-size:11px;color:#166534;">últimos 30 dias</div>
        </div>
    </div>
</div>

<?php if (!empty($health)): ?>
<div class="ap-card" style="border:2px solid <?php echo $health['score'] >= 85 ? '#86efac' : ($health['score'] >= 65 ? '#facc15' : '#fca5a5'); ?>;background:<?php echo $health['score'] >= 85 ? '#f0fdf4' : ($health['score'] >= 65 ? '#fffbeb' : '#fef2f2'); ?>;margin-bottom:20px;">
    <h3>🩺 SARA Health Monitor <span class="ap-badge <?php echo $health['score'] >= 85 ? 'badge-green' : ($health['score'] >= 65 ? 'badge-yellow' : 'badge-red'); ?>"><?php echo esc_html($health['status']); ?> — <?php echo (int)$health['score']; ?>/100</span></h3>
    <div class="ap-stats-row">
        <div class="ap-stat"><div class="num"><?php echo (int)$health['counts']['pending']; ?></div><div class="label">Jobs pendentes</div></div>
        <div class="ap-stat"><div class="num"><?php echo (int)$health['counts']['processing']; ?></div><div class="label">Processando</div></div>
        <div class="ap-stat"><div class="num"><?php echo (int)$health['counts']['done_today']; ?></div><div class="label">Concluídos hoje</div></div>
        <div class="ap-stat"><div class="num"><?php echo (int)$health['counts']['failed_7d']; ?></div><div class="label">Falhas 7 dias</div></div>
    </div>
    <div class="health-list">
        <?php foreach ($health['checks'] as $check): ?>
            <div class="health-item">
                <span class="<?php echo $check['ok'] ? 'health-ok' : 'health-bad'; ?>"><?php echo $check['ok'] ? '✅' : '❌'; ?></span>
                <?php echo esc_html($check['label']); ?>
                <?php if (!$check['ok']): ?><br><small><?php echo esc_html($check['fix']); ?></small><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p style="margin:12px 0 0;font-size:12px;color:#374151;">
        Próximo Brain: <strong><?php echo esc_html($health['cron']['brain_next'] ?: 'sem cron'); ?></strong> ·
        Próximo Writer: <strong><?php echo esc_html($health['cron']['writer_next'] ?: 'sem cron'); ?></strong> ·
        Modelo artigo: <strong><?php echo esc_html($health['models']['generation']); ?></strong> ·
        Modelo revisão: <strong><?php echo esc_html($health['models']['scoring']); ?></strong>
    </p>
    <p style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn-secondary" onclick="saraRepair(this)">🛠️ Reparar SARA com segurança</button>
    </p>
</div>
<?php endif; ?>

<!-- TESTAR WRITER MANUALMENTE -->
<div class="ap-card" style="margin-bottom:20px;background:linear-gradient(135deg,#f0f9ff,#fef3c7);border:2px solid #6366f1;">
    <h3>⚡ Testar Writer Manualmente <span class="ap-badge badge-blue">Sem cron, sem fila</span></h3>
    <p style="font-size:13px;color:#374151;margin:0 0 16px;">
        Geração imediata sob demanda. Não interfere no Brain ou cron automático.
    </p>

    <form id="manual-writer-form">
        <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">

        <?php if (class_exists('GeoMetodoSEO\TitleBank\SeoGeoTitleBank')) echo \GeoMetodoSEO\TitleBank\SeoGeoTitleBank::render_picker('#mw-title', 'single'); ?>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">
                Título do Artigo <span style="color:#dc2626;">*</span>
                <small style="color:#6b7280;font-weight:400;">(30-100 caracteres)</small>
            </label>
            <input type="text" name="title" id="mw-title" required minlength="30" maxlength="100"
                   placeholder="Ex: 23 Erros Fatais de SEO Técnico que 89% dos Sites WordPress Cometem"
                   style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
            <p style="font-size:11px;color:#6b7280;margin:4px 0 0;">
                <span id="mw-char-count">0</span>/100 caracteres
            </p>
        </div>

        <div class="config-grid">
            <div class="config-field">
                <label>Categoria</label>
                <select name="category_id" required>
                    <option value="">— Selecione —</option>
                    <?php
                    $cats = get_categories(['hide_empty' => false]);
                    foreach ($cats as $cat):
                    ?>
                    <option value="<?php echo (int)$cat->term_id; ?>"><?php echo esc_html($cat->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="config-field">
                <label>Tom de Voz</label>
                <select name="tone">
                    <option value="profissional">📊 Profissional</option>
                    <option value="casual">💬 Casual</option>
                    <option value="tecnico">🔧 Técnico</option>
                    <option value="persuasivo">🎯 Persuasivo</option>
                </select>
            </div>
            <div class="config-field">
                <label>Quantidade de Palavras</label>
                <input type="number" name="word_count" value="<?php echo esc_attr(AutopilotInstaller::get('conservative_word_count', '1500')); ?>" min="800" max="3000" step="100">
                <small style="font-size:11px;color:#6b7280;">Mín: 800 — Máx: 3.000</small>
            </div>
            <div class="config-field">
                <label>Provedor de IA</label>
                <select name="provider" id="mw-ai-provider" onchange="saraUpdateManualModels(this.value)">
                    <option value="">— Provedor global —</option>
                    <option value="openai" <?php selected(AutopilotInstaller::get('writer_provider_generation', ''), 'openai'); ?>>OpenAI / GPT</option>
                    <option value="groq" <?php selected(AutopilotInstaller::get('writer_provider_generation', ''), 'groq'); ?>>Groq / LLaMA</option>
                    <option value="gemini" <?php selected(AutopilotInstaller::get('writer_provider_generation', ''), 'gemini'); ?>>Google Gemini</option>
                    <option value="claude" <?php selected(AutopilotInstaller::get('writer_provider_generation', ''), 'claude'); ?>>Claude</option>
                    <option value="perplexity" <?php selected(AutopilotInstaller::get('writer_provider_generation', ''), 'perplexity'); ?>>Perplexity</option>
                    <option value="naga" <?php selected(AutopilotInstaller::get('writer_provider_generation', ''), 'naga'); ?>>Naga.ac</option>
                </select>
                <small style="font-size:11px;color:#6b7280;">Escolha o provedor real para este teste manual. Em branco usa o provedor global.</small>
            </div>
            <div class="config-field">
                <label>Modelo de IA</label>
                <select name="ai_model" id="mw-ai-model"></select>
                <small style="font-size:11px;color:#6b7280;">Modelos mudam conforme o provedor. Deixe vazio para usar o padrão seguro.</small>
            </div>
            <div class="config-field">
                <label>&nbsp;</label>
                <div style="display:flex;flex-direction:column;gap:6px;font-size:13px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="strict_model" value="1" checked>
                        🔒 Forçar modelo selecionado sem provider selecionado
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="gen_image" value="1" checked>
                        🖼️ Gerar Imagem Featured
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="use_library" value="1">
                        📚 Usar minha Biblioteca de imagens (em vez de IA)
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="gen_faq" value="1" checked>
                        ❓ Gerar FAQ com Schema
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="embed_video" value="1">
                        ▶️ Embedar vídeo do YouTube relacionado
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="auto_publish" value="1">
                        🚀 Publicar Automaticamente
                    </label>
                </div>
            </div>
        </div>

        <div style="margin-top:18px;display:flex;align-items:center;gap:12px;">
            <button type="submit" class="btn-run" id="mw-submit-btn" style="font-size:14px;padding:12px 24px;">
                ⚡ Gerar Artigo Agora
            </button>
            <span id="mw-status" style="font-size:12px;color:#6b7280;"></span>
        </div>
    </form>

    <!-- Resultado da geração manual -->
    <div id="mw-result" style="display:none;margin-top:20px;padding:20px;background:#fff;border-radius:10px;border:1px solid #e5e7eb;"></div>
</div>

<!-- Modal de Preview -->
<div id="mw-preview-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:99999;align-items:center;justify-content:center;padding:40px;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:900px;max-height:90vh;display:flex;flex-direction:column;">
        <div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:18px;">📄 Preview do Artigo</h3>
            <button onclick="closePreviewModal()" style="background:transparent;border:none;font-size:24px;cursor:pointer;">×</button>
        </div>
        <div id="mw-preview-content" style="padding:24px;overflow-y:auto;flex:1;line-height:1.6;color:#1f2937;"></div>
        <div style="padding:16px 24px;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:flex-end;">
            <button class="btn-secondary" onclick="closePreviewModal()">Fechar</button>
            <a id="mw-edit-btn" class="btn-secondary" target="_blank" href="#">✏ Editar no WP</a>
            <a id="mw-publish-btn" class="btn-run" target="_blank" href="#">🚀 Ver Publicado</a>
        </div>
    </div>
</div>

<!-- Status dos 2 Agentes -->
<div class="ap-grid">
    <!-- Agente A — SARA BRAIN -->
    <div class="ap-card">
        <h3>🧠 AGENTE A — SARA BRAIN
            <span class="ap-badge <?php echo $brain_status['enabled']?'badge-green':'badge-red'; ?>">
                <?php echo $brain_status['enabled']?'Ativo':'Inativo'; ?>
            </span>
        </h3>
        <div class="ap-stats-row">
            <div class="ap-stat">
                <div class="num"><?php echo number_format($brain_status['indexed_posts']); ?></div>
                <div class="label">Posts Indexados</div>
            </div>
            <div class="ap-stat">
                <div class="num" style="font-size:18px;"><?php echo esc_html($brain_status['next_run']); ?></div>
                <div class="label">Próxima Execução</div>
            </div>
        </div>
        <p style="font-size:12px;color:#6b7280;margin:8px 0;">
            Última execução: <?php echo esc_html($brain_status['last_run']); ?>
        </p>
        <div style="display:flex;gap:8px;margin-top:12px;">
            <button class="btn-run" id="btn-run-brain" onclick="runBrain()">
                ▶ Executar Brain Agora
            </button>
            <button class="btn-secondary" onclick="document.getElementById('ap-config').scrollIntoView({behavior:'smooth'})">
                ⚙ Configurar
            </button>
        </div>
    </div>

    <!-- Agente B — SARA WRITER -->
    <div class="ap-card">
        <h3>✍️ AGENTE B — SARA WRITER
            <span class="ap-badge <?php echo $writer_status['enabled']?'badge-green':'badge-red'; ?>">
                <?php echo $writer_status['enabled']?'Ativo':'Inativo'; ?>
            </span>
            <span class="ap-badge <?php echo $writer_status['publish_mode']==='publish'?'badge-blue':'badge-yellow'; ?>">
                <?php echo $writer_status['publish_mode']==='publish'?'Publicar':'Rascunho'; ?>
            </span>
        </h3>
        <div class="ap-stats-row">
            <div class="ap-stat">
                <div class="num"><?php echo $writer_status['pending_today']; ?></div>
                <div class="label">Pendentes Hoje</div>
            </div>
            <div class="ap-stat">
                <div class="num" style="color:#065f46;"><?php echo $writer_status['done_today']; ?></div>
                <div class="label">Publicados Hoje</div>
            </div>
        </div>
        <p style="font-size:12px;color:#6b7280;margin:8px 0;">
            Próximo job: <?php echo $writer_status['next_job'] ? date_i18n('d/m H:i', $writer_status['next_job']) : 'Nenhum agendado'; ?>
        </p>
        <div style="display:flex;gap:8px;margin-top:12px;">
            <button class="btn-run" id="btn-run-writer" onclick="runWriterNext()">
                ▶ Executar Próximo Job
            </button>
        </div>
    </div>
</div>

<!-- Calendário Editorial de Hoje -->
<div class="ap-card" style="margin-bottom:20px;">
    <h3>📅 Calendário de Hoje — <?php echo esc_html($display_today); ?></h3>
    <?php if (empty($calendar_today)): ?>
    <p style="color:#6b7280;font-style:italic;">Nenhum artigo planejado para hoje.</p>
    <?php if (!empty($calendar_tomorrow)): ?>
    <p style="color:#2563eb;margin-top:-6px;">Há <?php echo count($calendar_tomorrow); ?> artigo(s) planejado(s) para amanhã. Veja a lista em “Próximos artigos planejados”.</p>
    <?php else: ?>
    <p style="color:#6b7280;margin-top:-6px;">Execute o Brain para planejar os próximos artigos.</p>
    <?php endif; ?>
    <?php else: ?>
    <table class="ap-table">
        <thead>
            <tr>
                <th>Horário</th>
                <th>Título</th>
                <th>Categoria</th>
                <th>Palavras</th>
                <th>Status</th>
                <th>Score</th>
                <th>Ação</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($calendar_today as $job): ?>
            <tr>
                <td><?php echo esc_html(substr($job['scheduled_time'], 0, 5)); ?></td>
                <td style="max-width:300px;"><strong><?php echo esc_html(wp_trim_words($job['title'], 8)); ?></strong>
                    <br><small style="color:#6b7280;"><?php echo esc_html($job['keyword']); ?></small></td>
                <td><?php echo esc_html($job['category_name']); ?></td>
                <td><?php echo number_format((int)$job['word_count_target']); ?><?php if (!empty($job['post_id'])) { $real = (int)get_post_meta((int)$job['post_id'], '_sara_word_count_real_final', true); if (!$real) $real = (int)get_post_meta((int)$job['post_id'], '_sara_word_count_real', true); if ($real) echo '<br><small style="color:#64748b;">real: ' . number_format($real) . '</small>'; } ?></td>
                <td><span class="status-<?php echo esc_attr($job['status']); ?>">
                    <?php echo esc_html(ucfirst($job['status'])); ?></span></td>
                <td><?php echo $job['quality_score'] ? $job['quality_score'] . '/100' : '—'; ?></td>
                <td>
                    <?php if ($job['status'] === 'pending'): ?>
                    <button class="btn-secondary" style="padding:4px 10px;font-size:12px;"
                            onclick="runJob(<?php echo (int)$job['id']; ?>)">▶ Rodar</button>
                    <?php elseif ($job['post_id']): ?>
                    <a href="<?php echo get_edit_post_link($job['post_id']); ?>"
                       target="_blank" class="btn-secondary" style="padding:4px 10px;font-size:12px;text-decoration:none;">
                       ✏ Editar</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Próximos artigos planejados -->
<div class="ap-card" style="margin-bottom:20px;">
    <h3>🗓️ Próximos artigos planejados</h3>
    <?php if (empty($calendar_upcoming)): ?>
    <p style="color:#6b7280;font-style:italic;">Nenhum artigo pendente encontrado para hoje ou próximos dias.</p>
    <?php else: ?>
    <table class="ap-table">
        <thead>
            <tr>
                <th>Data</th>
                <th>Horário</th>
                <th>Título</th>
                <th>Categoria</th>
                <th>Palavras</th>
                <th>Status</th>
                <th>Ação</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($calendar_upcoming as $job): ?>
            <tr>
                <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($job['scheduled_date']))); ?></td>
                <td><?php echo esc_html(substr($job['scheduled_time'], 0, 5)); ?></td>
                <td style="max-width:360px;"><strong><?php echo esc_html(wp_trim_words($job['title'], 10)); ?></strong>
                    <br><small style="color:#6b7280;"><?php echo esc_html($job['keyword']); ?></small></td>
                <td><?php echo esc_html($job['category_name']); ?></td>
                <td><?php echo number_format((int)$job['word_count_target']); ?><?php if (!empty($job['post_id'])) { $real = (int)get_post_meta((int)$job['post_id'], '_sara_word_count_real_final', true); if (!$real) $real = (int)get_post_meta((int)$job['post_id'], '_sara_word_count_real', true); if ($real) echo '<br><small style="color:#64748b;">real: ' . number_format($real) . '</small>'; } ?></td>
                <td><span class="status-<?php echo esc_attr($job['status']); ?>">
                    <?php echo esc_html(ucfirst($job['status'])); ?></span></td>
                <td>
                    <?php if (in_array($job['status'], ['pending','retrying'], true)): ?>
                    <button class="btn-secondary" style="padding:4px 10px;font-size:12px;"
                            onclick="runJob(<?php echo (int)$job['id']; ?>)">▶ Rodar</button>
                    <?php elseif (!empty($job['post_id'])): ?>
                    <a href="<?php echo get_edit_post_link((int)$job['post_id']); ?>"
                       target="_blank" class="btn-secondary" style="padding:4px 10px;font-size:12px;text-decoration:none;">
                       ✏ Editar</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- SARA Brain v2.0 — Nicho + Categorias -->
<div class="ap-card" style="margin-bottom:20px;">
    <h3>🎯 Brain v2.0 — Nicho e Categorias <span class="ap-badge badge-blue">Anti-clichê</span></h3>
    <?php
    $cur_niche      = get_option('sara_niche', '');
    $cur_confidence = (int) get_option('sara_niche_confidence', 0);
    $cur_active     = (array) get_option('sara_active_categories', []);
    $available_cats = \GeoMetodoSEO\Autopilot\Brain\SaraCategoryFilter::available_categories();
    $articles_per_cat = (int) AutopilotInstaller::get('brain_articles_per_category', '2');
    ?>
    <form id="brain-v2-form">
        <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">

        <!-- Nicho com auto-detect -->
        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">
                Nicho do Site
                <?php if ($cur_confidence): ?>
                <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;margin-left:8px;">
                    Auto-detectado (<?php echo $cur_confidence; ?>% confiança)
                </span>
                <?php endif; ?>
            </label>
            <div style="display:flex;gap:8px;">
                <input type="text" name="sara_niche" id="sara-niche-input"
                       value="<?php echo esc_attr($cur_niche ?: 'Não detectado ainda'); ?>"
                       style="flex:1;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                <button type="button" class="btn-secondary" onclick="detectNiche(this)" style="white-space:nowrap;">
                    🔍 Auto-detectar
                </button>
            </div>
            <p style="font-size:12px;color:#6b7280;margin-top:6px;">
                Analisa nome do site, descrição e os 30 posts mais recentes para identificar o nicho.
            </p>
        </div>

        <!-- Categorias ativas (2-5) -->
        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">
                Categorias Ativas <span style="color:#6b7280;font-weight:400;">(escolha 2 a 5)</span>
            </label>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;background:#f9fafb;padding:14px;border-radius:8px;border:1px solid #e5e7eb;">
                <?php foreach ($available_cats as $slug => $name): ?>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;padding:6px 8px;border-radius:6px;transition:.2s;"
                       onmouseover="this.style.background='#fff'" onmouseout="this.style.background='transparent'">
                    <input type="checkbox" name="sara_active_categories[]" value="<?php echo esc_attr($slug); ?>"
                           <?php echo in_array($slug, $cur_active, true) ? 'checked' : ''; ?>
                           class="brain-cat-cb" onchange="checkCatLimit()">
                    <?php echo esc_html($name); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <p style="font-size:12px;color:#6b7280;margin-top:6px;">
                <span id="cat-counter" style="font-weight:600;"><?php echo count($cur_active); ?></span> de 5 selecionadas.
                O Brain vai gerar artigos para essas categorias todos os dias.
            </p>
        </div>

        <!-- Artigos por categoria -->
        <div class="config-grid">
            <div class="config-field">
                <label>Artigos por Categoria por Dia</label>
                <select name="sara_articles_per_category">
                    <option value="1" <?php selected($articles_per_cat, 1); ?>>1 artigo</option>
                    <option value="2" <?php selected($articles_per_cat, 2); ?>>2 artigos (recomendado)</option>
                    <option value="3" <?php selected($articles_per_cat, 3); ?>>3 artigos</option>
                </select>
                <p style="font-size:11px;color:#6b7280;margin-top:4px;">
                    Total estimado: <span id="total-articles"><?php echo count($cur_active) * $articles_per_cat; ?></span> artigos/dia
                </p>
            </div>
        </div>

        <div style="margin-top:20px;">
            <button type="submit" class="btn-run">💾 Salvar Configurações do Brain</button>
        </div>
    </form>
</div>

<?php
$model_options = [
    '' => '— Usar modelo padrão do provedor —',
    'gpt-5.5' => 'OpenAI GPT-5.5 ⚠️ premium/caro',
    'gpt-5.5-pro' => 'GPT-5.5 Pro ⚠️ premium máximo',
    'gpt-5.5-mini' => 'GPT-5.5 Mini ⚠️ premium leve',
    'gpt-5.4' => 'GPT-5.4 custo-benefício avançado',
    'gpt-5.4-mini' => 'GPT-5.4 Mini ⚡ econômico',
    'gpt-5' => 'GPT-5 compatível',
    'gpt-4.1' => 'OpenAI GPT-4.1 fallback estável',
    'gpt-4.1-mini' => 'OpenAI GPT-4.1 Mini fallback econômico',
    'llama-3.3-70b-versatile' => 'Groq Llama 3.3 70B',
    'openai/gpt-oss-120b' => 'Groq GPT OSS 120B',
    'openai/gpt-oss-20b' => 'Groq GPT OSS 20B',
    'gemini-2.5-flash:free' => 'Naga/Gemini 2.5 Flash Free',
    'gemini-1.5-pro' => 'Google Gemini 1.5 Pro',
    'claude-sonnet-4-6' => 'Claude Sonnet 4.6 ⭐',
    'sonar' => 'Perplexity Sonar',
];
$render_model_select = function(string $name, string $current) use ($model_options) {
    echo '<select name="' . esc_attr($name) . '">';
    foreach ($model_options as $value => $label) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
};

$provider_options = [
    '' => '— Usar provedor global —',
    'openai' => 'OpenAI / GPT',
    'groq' => 'Groq (texto rápido/econômico)',
    'gemini' => 'Gemini',
    'claude' => 'Claude',
    'perplexity' => 'Perplexity',
    'naga' => 'Naga texto',
];
$render_provider_select = function(string $name, string $current) use ($provider_options) {
    echo '<select name="' . esc_attr($name) . '">';
    foreach ($provider_options as $value => $label) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
};
?>

<!-- Configurações dos Agentes -->
<div class="ap-card" id="ap-config" style="margin-bottom:20px;">
    <h3>⚙️ Configurações dos Agentes</h3>
    <form id="ap-config-form">
        <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">
        <div class="config-grid">
            <div class="config-field">
                <label>Nicho do Site</label>
                <input type="text" name="site_niche"
                       value="<?php echo esc_attr(AutopilotInstaller::get('site_niche', '')); ?>"
                       placeholder="ex: marketing digital, saúde, finanças">
            </div>
            <div class="config-field">
                <label>Idioma Principal</label>
                <select name="site_language">
                    <?php
                    $lang = AutopilotInstaller::get('site_language', 'pt-BR');
                    $langs = ['pt-BR'=>'Português do Brasil','en-US'=>'English','es-ES'=>'Español','fr-FR'=>'Français'];
                    foreach ($langs as $v => $l):
                    ?>
                    <option value="<?php echo $v; ?>" <?php selected($lang, $v); ?>><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="config-field">
                <label>Horário do Brain (diário)</label>
                <input type="time" name="brain_run_time"
                       value="<?php echo esc_attr(AutopilotInstaller::get('brain_run_time', '22:00')); ?>">
            </div>
            <div class="config-field">
                <label>Horários do Writer</label>
                <?php
                // BUG FIX 1.0.0: exibir em formato amigável "07:00, 09:00, 11:00"
                // independente de como está salvo (JSON ou string corrompida)
                $raw_times = AutopilotInstaller::get('writer_schedule_times', '["07:00","09:00","11:00"]');
                $parsed_times = \GeoMetodoSEO\Autopilot\Shared\ScheduleManager::parse_writer_times($raw_times);
                $display_times = implode(', ', $parsed_times);
                ?>
                <input type="text" name="writer_schedule_times"
                       value="<?php echo esc_attr($display_times); ?>"
                       placeholder="07:00, 09:00, 11:00">
                <small style="color:#6b7280;display:block;margin-top:4px;">
                    Separe os horários por vírgula. Cada artigo será agendado em um horário diferente.
                </small>
            </div>
            <div class="config-field">
                <label>Artigos por Categoria por Dia</label>
                <input type="number" name="brain_articles_per_category" min="1" max="5"
                       value="<?php echo esc_attr(AutopilotInstaller::get('brain_articles_per_category', '1')); ?>">
            </div>
            <div class="config-field">
                <label>Modo de Publicação</label>
                <select name="writer_publish_mode">
                    <option value="draft" <?php selected(AutopilotInstaller::get('writer_publish_mode','draft'),'draft'); ?>>Rascunho (revisão manual)</option>
                    <option value="publish" <?php selected(AutopilotInstaller::get('writer_publish_mode','draft'),'publish'); ?>>Publicar Diretamente</option>
                </select>
            </div>
            <div class="config-field">
                <label>Score Mínimo para Publicar (0-100)</label>
                <input type="number" name="writer_quality_threshold" min="50" max="100"
                       value="<?php echo esc_attr(AutopilotInstaller::get('writer_quality_threshold', '75')); ?>">
            </div>
            <div class="config-field">
                <label>Meta de palavras — Conservador</label>
                <input type="number" name="conservative_word_count" min="800" max="3000" step="100"
                       value="<?php echo esc_attr(AutopilotInstaller::get('conservative_word_count', '1500')); ?>">
                <small style="color:#6b7280;display:block;margin-top:4px;">Mínimo recomendado: 2.300 palavras.</small>
            </div>
            <div class="config-field">
                <label>Meta de palavras — Agressivo/Pilar</label>
                <input type="number" name="aggressive_word_count" min="2300" max="5000" step="100"
                       value="<?php echo esc_attr(AutopilotInstaller::get('aggressive_word_count', '4000')); ?>">
                <small style="color:#6b7280;display:block;margin-top:4px;">Use 3.000–4.000 para artigos pilares.</small>
            </div>
            <div class="config-field">
                <label>Meta de palavras — YouTube → Artigo</label>
                <input type="number" name="youtube_word_count_target" min="2300" max="4000" step="100"
                       value="<?php echo esc_attr(AutopilotInstaller::get('youtube_word_count_target', '2600')); ?>">
                <small style="color:#6b7280;display:block;margin-top:4px;">O contador real valida e expande se ficar curto.</small>
            </div>
            <div class="config-field">
                <label>Auto-expansão se vier curto</label>
                <select name="auto_expand_enabled">
                    <option value="1" <?php selected(AutopilotInstaller::get('auto_expand_enabled','0'),'1'); ?>>✅ Ativar</option>
                    <option value="0" <?php selected(AutopilotInstaller::get('auto_expand_enabled','0'),'0'); ?>>❌ Desativar</option>
                </select>
            </div>
            <div class="config-card" style="border:2px solid #10b981;background:#ecfdf5;grid-column:1/-1;">
                <h3 style="margin-top:0;color:#065f46;">🔌 Providers independentes</h3>
                <p style="margin:0;color:#065f46;">Cada provider trabalha separado. O plugin não troca automaticamente para outro provider.</p>
            </div>

            <div class="config-field">
                <label>Provider — Artigos SARA</label>
                <?php $render_provider_select('writer_provider_generation', AutopilotInstaller::get('writer_provider_generation', '')); ?>
                <small style="color:#6b7280;display:block;margin-top:4px;">Escolha um provedor específico ou deixe em branco para usar o provedor global do plugin.</small>
            </div>
            <div class="config-field">
                <label>Provider — Briefing factual</label>
                <?php $render_provider_select('writer_provider_briefing', AutopilotInstaller::get('writer_provider_briefing', '')); ?>
            </div>
            <div class="config-field">
                <label>Modelo — Briefing factual</label>
                <?php $render_model_select('writer_model_briefing', AutopilotInstaller::get('writer_model_briefing', '')); ?>
                <small style="color:#6b7280;display:block;margin-top:4px;">Usado antes da escrita para listar fatos confirmados, lacunas e limites.</small>
            </div>
            <div class="config-field">
                <label>Modelo — Artigos SARA</label>
                <?php $render_model_select('writer_model_generation', AutopilotInstaller::get('writer_model_generation', '')); ?>
            </div>
            <div class="config-field">
                <label>Provider — Auto-expansão</label>
                <?php $render_provider_select('writer_provider_expansion', AutopilotInstaller::get('writer_provider_expansion', '')); ?>
            </div>
            <div class="config-field">
                <label>Modelo — Auto-expansão</label>
                <?php $render_model_select('writer_model_expansion', AutopilotInstaller::get('writer_model_expansion', '')); ?>
            </div>
            <div class="config-field">
                <label>Provider — Revisão/Score</label>
                <?php $render_provider_select('writer_provider_scoring', AutopilotInstaller::get('writer_provider_scoring', '')); ?>
            </div>
            <div class="config-field">
                <label>Modelo — Revisão/Score</label>
                <?php $render_model_select('writer_model_scoring', AutopilotInstaller::get('writer_model_scoring', '')); ?>
            </div>
            <div class="config-field">
                <label>Modelo — YouTube Metadados</label>
                <?php $render_model_select('youtube_model_metadata', AutopilotInstaller::get('youtube_model_metadata', '')); ?>
            </div>
            <div class="config-field">
                <label>Modelo — YouTube Artigo</label>
                <?php $render_model_select('youtube_model_article', AutopilotInstaller::get('youtube_model_article', '')); ?>
                <small style="color:#6b7280;display:block;margin-top:4px;">Separado para não depender do modelo geral do plugin.</small>
            </div>
            <div class="config-field">
                <label>Retries automáticos por Job</label>
                <input type="number" name="writer_max_job_retries" min="0" max="5"
                       value="<?php echo esc_attr(AutopilotInstaller::get('writer_max_job_retries', '3')); ?>">
            </div>
            <div class="config-field">
                <label>Refresh: idade mínima do artigo</label>
                <input type="number" name="refresher_min_age_days" min="30" max="730"
                       value="<?php echo esc_attr(AutopilotInstaller::get('refresher_min_age_days', '120')); ?>">
            </div>

            <div class="config-field">
                <label>Naga.ac API Key</label>
                <input type="password" name="autopilot_naga_api_key"
                       value="<?php echo esc_attr(get_option('geo_naga_api_key', AutopilotInstaller::get('autopilot_naga_api_key', ''))); ?>"
                       placeholder="Cole sua API key da Naga.ac">
                <small style="color:#6b7280;display:block;margin-top:4px;">Salva em <code>geo_naga_api_key</code> e fica disponível para SARA Autopilot, imagens, Writer e Web Stories. <a href="https://naga.ac/dashboard" target="_blank" rel="noopener noreferrer">Abrir painel Naga.ac</a></small>
            </div>
            <div class="config-field">
                <label>Modelo de imagem Naga.ac</label>
                <input type="text" name="geo_naga_model"
                       value="<?php echo esc_attr(get_option('geo_naga_model', 'dall-e-3:free')); ?>"
                       placeholder="dall-e-3:free">
                <small style="color:#6b7280;display:block;margin-top:4px;">Ex.: <code>dall-e-3:free</code>, <code>flux-1-schnell:free</code>, <code>sdxl:free</code>.</small>
            </div>
            <div class="config-field">
                <label>Modelo de texto Naga.ac</label>
                <input type="text" name="geo_naga_text_model"
                       value="<?php echo esc_attr(get_option('geo_naga_text_model', 'gemini-2.5-flash:free')); ?>"
                       placeholder="gemini-2.5-flash:free">
                <small style="color:#6b7280;display:block;margin-top:4px;">Usado quando o provedor de texto for Naga.</small>
            </div>
            <div class="config-field">
                <label>Provedor de Imagens</label>
                <?php $cur_prov = AutopilotInstaller::get('writer_image_provider', 'ai_auto'); ?>
                <select name="writer_image_provider">
                    <optgroup label="🤖 IA (geração)">
                        <option value="ai_auto"   <?php selected($cur_prov, 'ai_auto'); ?>>🤖 IA Auto oficial (Featured: Fal.ai → Replicate | Corpo: Replicate → Fal.ai | Web Stories: Naga.ac → HuggingFace)</option>
                        <option value="replicate" <?php selected($cur_prov, 'replicate'); ?>>⚡ Replicate Flux Schnell</option>
                        <option value="falai"     <?php selected($cur_prov, 'falai'); ?>>🎨 Fal.ai gpt-image-2</option>
                        <option value="naga"      <?php selected($cur_prov, 'naga'); ?>>🐉 Naga.ac para Web Stories</option>
                        <option value="huggingface" <?php selected($cur_prov, 'huggingface'); ?>>🤗 HuggingFace Flux Schnell</option>
                    </optgroup>
                    <optgroup label="📷 Bancos de fotos (gratuitos)">
                        <option value="unsplash"  <?php selected($cur_prov, 'unsplash'); ?>>📷 Unsplash</option>
                        <option value="pexels"    <?php selected($cur_prov, 'pexels'); ?>>📷 Pexels</option>
                        <option value="pixabay"   <?php selected($cur_prov, 'pixabay'); ?>>📷 Pixabay</option>
                    </optgroup>
                </select>
                <small style="color:#6b7280;display:block;margin-top:4px;">
                    Cadeia oficial v1.0.0: Featured Fal.ai → Replicate; Corpo Replicate → Fal.ai; Web Stories Naga.ac → HuggingFace. Links: <a href="https://fal.ai/dashboard/keys" target="_blank" rel="noopener noreferrer">Fal.ai</a> · <a href="https://replicate.com/account/api-tokens" target="_blank" rel="noopener noreferrer">Replicate</a> · <a href="https://naga.ac/dashboard" target="_blank" rel="noopener noreferrer">Naga.ac</a> · <a href="https://huggingface.co/settings/tokens" target="_blank" rel="noopener noreferrer">HuggingFace</a>.
                </small>
            </div>
            <div class="config-field">
                <label>Ativar Brain</label>
                <select name="brain_enabled">
                    <option value="1" <?php selected(AutopilotInstaller::get('brain_enabled','1'),'1'); ?>>✅ Sim</option>
                    <option value="0" <?php selected(AutopilotInstaller::get('brain_enabled','1'),'0'); ?>>❌ Não</option>
                </select>
            </div>
            <div class="config-field">
                <label>Ativar Writer</label>
                <select name="writer_enabled">
                    <option value="1" <?php selected(AutopilotInstaller::get('writer_enabled','1'),'1'); ?>>✅ Sim</option>
                    <option value="0" <?php selected(AutopilotInstaller::get('writer_enabled','1'),'0'); ?>>❌ Não</option>
                </select>
            </div>
        </div>
        <div style="margin-top:16px;">
            <button type="submit" class="btn-run">💾 Salvar Configurações</button>
        </div>
    </form>
</div>

<!-- 1.0.0: Indexação rápida (IndexNow) e Learning Loop -->
<div class="ap-card" style="border:2px solid #93c5fd;background:#eff6ff;">
    <h3 style="color:#1e40af;">⚡ Indexação Rápida & Atualização Inteligente</h3>
    <p style="color:#1e3a8a;margin:0 0 14px;font-size:13px;">
        IndexNow submete URLs ao Bing/Yandex automaticamente quando você publica/atualiza um post (gratuito, sem OAuth).
        O Refresher atualiza artigos antigos com dados novos a cada semana (mantém URL e SEO).
    </p>

    <?php
    $idx = \GeoMetodoSEO\Autopilot\Indexing\SaraIndexNow::get_status();
    $ref = \GeoMetodoSEO\Autopilot\Learning\SaraContentRefresher::get_status();
    ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

        <!-- IndexNow -->
        <div style="padding:14px;background:#fff;border-radius:8px;border:1px solid #cbd5e1;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <strong style="color:#0f172a;">🔍 IndexNow (Bing + Yandex)</strong>
                <span style="font-size:11px;padding:3px 8px;border-radius:10px;<?php echo $idx['enabled'] ? 'background:#d1fae5;color:#065f46;' : 'background:#fee2e2;color:#991b1b;'; ?>">
                    <?php echo $idx['enabled'] ? '✅ ATIVO' : '⏸️ INATIVO'; ?>
                </span>
            </div>
            <div style="font-size:12px;color:#475569;margin-bottom:10px;">
                URLs no buffer: <strong><?php echo (int)$idx['buffer_size']; ?></strong>
                <?php if ($idx['enabled']): ?>
                    <br><span style="font-size:11px;">Verificação: <a href="<?php echo esc_url($idx['key_url']); ?>" target="_blank" style="color:#1e40af;">testar key</a></span>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <?php if ($idx['enabled']): ?>
                    <button class="btn-secondary" style="font-size:12px;" onclick="indexnowToggle(this, false)">⏸️ Desativar</button>
                    <?php if ((int)$idx['buffer_size'] > 0): ?>
                        <button class="btn-secondary" style="font-size:12px;background:#3b82f6;color:#fff;border:none;" onclick="indexnowFlush(this)">📤 Enviar Agora</button>
                    <?php endif; ?>
                <?php else: ?>
                    <button class="btn-secondary" style="font-size:12px;background:#3b82f6;color:#fff;border:none;" onclick="indexnowToggle(this, true)">▶️ Ativar IndexNow</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Content Refresher -->
        <div style="padding:14px;background:#fff;border-radius:8px;border:1px solid #cbd5e1;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <strong style="color:#0f172a;">🔄 Content Refresher</strong>
                <span style="font-size:11px;padding:3px 8px;border-radius:10px;<?php echo $ref['enabled'] ? 'background:#d1fae5;color:#065f46;' : 'background:#fee2e2;color:#991b1b;'; ?>">
                    <?php echo $ref['enabled'] ? '✅ ATIVO' : '⏸️ INATIVO'; ?>
                </span>
            </div>
            <div style="font-size:12px;color:#475569;margin-bottom:10px;">
                Candidatos: <strong><?php echo (int)$ref['candidates']; ?></strong>
                (posts ≥ <?php echo (int)$ref['min_age']; ?> dias sem update há ≥ 30 dias)
                <?php if ($ref['enabled'] && $ref['next_run']): ?>
                    <br><span style="font-size:11px;">Próxima exec: <?php echo esc_html($ref['next_run']); ?></span>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button class="btn-secondary" style="font-size:12px;background:#0ea5e9;color:#fff;border:none;" onclick="refresherLoadCandidates(this)">👀 Ver artigos candidatos</button>
                <?php if ($ref['enabled']): ?>
                    <button class="btn-secondary" style="font-size:12px;" onclick="refresherToggle(this, false)">⏸️ Desativar</button>
                    <?php if ((int)$ref['candidates'] > 0): ?>
                        <button class="btn-secondary" style="font-size:12px;background:#16a34a;color:#fff;border:none;" onclick="refresherRunNow(this)">🔄 Reescrever antigos agora</button>
                    <?php endif; ?>
                <?php else: ?>
                    <button class="btn-secondary" style="font-size:12px;background:#16a34a;color:#fff;border:none;" onclick="refresherToggle(this, true)">▶️ Ativar Refresher</button>
                <?php endif; ?>
            </div>
            <div id="refresher-candidates" style="margin-top:10px;display:none;"></div>
        </div>

    </div>

    <div id="indexlearn-result" style="margin-top:14px;display:none;padding:12px;border-radius:6px;font-family:monospace;font-size:12px;white-space:pre-wrap;"></div>
</div>

<!-- 1.0.0: Status do Google Search Console (usa o módulo OAuth já existente) -->
<?php
$gsc_connected = false;
$gsc_property  = '';
if (class_exists('\GeoMetodoSEO\SEO\SearchConsoleService')) {
    $gsc_svc = new \GeoMetodoSEO\SEO\SearchConsoleService();
    $gsc_connected = $gsc_svc->is_connected();
    $gsc_property  = (string) get_option('geo_gsc_property', '');
}
$gsc_settings_url = admin_url('admin.php?page=geo-settings');
?>
<div class="ap-card" style="border:2px solid <?php echo $gsc_connected ? '#10b981' : '#cbd5e1'; ?>;">
    <h3>🔗 Google Search Console <span style="font-size:12px;color:<?php echo $gsc_connected ? '#059669' : '#64748b'; ?>;font-weight:normal;"><?php echo $gsc_connected ? '✅ Conectado' : '⚪ Não conectado'; ?></span></h3>
    <p style="color:#475569;margin:0 0 14px;font-size:13px;">
        <strong>Opcional, mas recomendado.</strong> Quando conectado, o Refresher (acima)
        prioriza atualizar artigos com baixa performance real (CTR &lt; 2% e posição 11-30 —
        oportunidades de subir para o top 10), em vez de só usar critério temporal.
    </p>
    <?php if ($gsc_connected): ?>
        <div style="background:#ecfdf5;padding:12px 14px;border-radius:8px;font-size:13px;color:#064e3b;line-height:1.6;">
            <strong>Propriedade:</strong> <code><?php echo esc_html($gsc_property); ?></code><br>
            ✅ O Refresher vai usar dados reais do GSC quando rodar.
        </div>
        <p style="margin-top:10px;font-size:12px;">
            Para gerenciar a conexão, vá em
            <a href="<?php echo esc_url($gsc_settings_url); ?>"><strong>GEO Método SEO → Configurações → Google Search Console</strong></a>.
        </p>
    <?php else: ?>
        <div style="background:#fef3c7;padding:12px 14px;border-radius:8px;font-size:13px;color:#78350f;line-height:1.6;">
            ⚠️ GSC não está conectado. Para ativar a otimização baseada em performance real,
            vá em <a href="<?php echo esc_url($gsc_settings_url); ?>"><strong>GEO Método SEO → Configurações → Google Search Console</strong></a>
            e conecte com sua conta Google.
        </div>
        <p style="margin-top:10px;font-size:12px;color:#475569;">
            Sem GSC, o Refresher continua funcionando — usa critério temporal (idade dos posts).
        </p>
    <?php endif; ?>
</div>

<!-- 1.0.0: Media Opportunities (HARO white-hat) + Press Release -->
<?php
$media_enabled = get_option('sara_media_enabled', '0') === '1';
$media_lang    = get_option('sara_media_lang_filter', 'both');
$media_stats   = ['total' => 0, 'matched' => 0, 'pending' => 0];
if (class_exists('\GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaMatcher')) {
    $media_stats = \GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaMatcher::get_stats();
}
?>
<div class="ap-card" style="border:2px solid <?php echo $media_enabled ? '#8b5cf6' : '#cbd5e1'; ?>;">
    <h3>📰 Oportunidades de Mídia <span style="font-size:12px;color:<?php echo $media_enabled ? '#7c3aed' : '#64748b'; ?>;font-weight:normal;"><?php echo $media_enabled ? '✅ Ativo' : '⚪ Inativo'; ?></span></h3>
    <p style="color:#475569;margin:0 0 14px;font-size:13px;">
        Coleta queries de jornalistas de fontes públicas (Reddit r/journorequests, r/HARO, Twitter #journorequest, SourceBottle)
        e usa IA para filtrar as relevantes ao seu nicho. Gera draft de pitch que você revisa e envia manualmente.
        <strong>Tudo white-hat:</strong> só lê feeds públicos, não envia nada automaticamente.
    </p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
        <label style="font-weight:600;font-size:13px;">Idioma:</label>
        <select id="media-lang" style="padding:4px 8px;border:1px solid #cbd5e1;border-radius:4px;">
            <option value="both" <?php selected($media_lang, 'both'); ?>>🌐 Inglês + Português</option>
            <option value="en"   <?php selected($media_lang, 'en'); ?>>🇬🇧 Apenas Inglês</option>
            <option value="pt"   <?php selected($media_lang, 'pt'); ?>>🇧🇷 Apenas Português</option>
        </select>

        <?php if ($media_enabled): ?>
            <button class="btn-secondary" style="font-size:12px;" onclick="mediaToggle(this, false)">⏸️ Desativar</button>
            <button class="btn-secondary" style="font-size:12px;background:#7c3aed;color:#fff;border:none;" onclick="mediaCollectNow(this)">🔄 Coletar Agora</button>
            <button class="btn-secondary" style="font-size:12px;" onclick="mediaLoadOpportunities()">📋 Ver Oportunidades</button>
        <?php else: ?>
            <button class="btn-secondary" style="font-size:12px;background:#7c3aed;color:#fff;border:none;" onclick="mediaToggle(this, true)">▶️ Ativar Coleta</button>
        <?php endif; ?>
    </div>

    <?php if ($media_enabled && $media_stats['total'] > 0): ?>
        <div style="background:#f5f3ff;padding:10px 14px;border-radius:6px;font-size:13px;color:#5b21b6;">
            📊 <strong><?php echo (int)$media_stats['matched']; ?></strong> oportunidades relevantes |
            <?php echo (int)$media_stats['total']; ?> coletadas total |
            <?php echo (int)($media_stats['rejected'] ?? 0); ?> descartadas pelo filtro de IA
        </div>
        <div style="background:#ecfdf5;border:1px solid #6ee7b7;padding:8px 12px;border-radius:6px;font-size:12px;color:#065f46;margin-top:8px;">
            💡 Clique em <strong>"📋 Ver Oportunidades"</strong> acima. Em cada oportunidade você verá o botão <strong>"📄 Gerar Artigo"</strong> (verde) para criar um artigo completo a partir dela.
        </div>
    <?php endif; ?>

    <div id="media-opps-list" style="margin-top:14px;display:none;"></div>
    <div id="media-result" style="margin-top:14px;display:none;padding:12px;border-radius:6px;font-family:monospace;font-size:12px;white-space:pre-wrap;"></div>
</div>

<!-- 1.0.0: Press Release Generator -->
<div class="ap-card" style="border:2px solid #cbd5e1;">
    <h3>📄 Gerador de Press Release</h3>
    <p style="color:#475569;margin:0 0 14px;font-size:13px;">
        Gera press release white-hat baseado em um post existente. Você recebe headline, corpo, versões para social
        e lista de tipos de veículos para enviar manualmente. <strong>Não envia nada automaticamente.</strong>
    </p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div class="config-field">
            <label>Post Base</label>
            <select id="pr-post-id" style="width:100%;">
                <option value="">— carregando posts —</option>
            </select>
            <small style="color:#6b7280;">Posts publicados (50 mais recentes)</small>
        </div>
        <div class="config-field">
            <label>Template</label>
            <select id="pr-template" style="width:100%;">
                <option value="product_launch">🚀 Lançamento</option>
                <option value="research">📊 Pesquisa / Dado Original</option>
                <option value="update">🔄 Atualização Importante</option>
                <option value="case_study">✅ Case de Sucesso</option>
            </select>
        </div>
    </div>

    <details style="margin-bottom:12px;">
        <summary style="cursor:pointer;font-weight:600;color:#0f172a;font-size:13px;">➕ Campos opcionais (recomendado)</summary>
        <div style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="config-field">
                <label>Email de contato (PR)</label>
                <input type="email" id="pr-contact" placeholder="press@aiconteudo.com.br" style="width:100%;">
            </div>
            <div class="config-field">
                <label>Porta-voz</label>
                <input type="text" id="pr-spokesperson" placeholder="Arlens Silva, fundador" style="width:100%;">
            </div>
        </div>
        <div class="config-field" style="margin-top:10px;">
            <label>Fatos-chave adicionais</label>
            <textarea id="pr-key-facts" rows="3" placeholder="Dados extras que não estão no post (ex: número de usuários, datas, prêmios...)" style="width:100%;"></textarea>
        </div>
    </details>

    <button class="btn-run" onclick="prGenerate(this)">📝 Gerar Press Release</button>

    <div id="pr-result" style="margin-top:14px;display:none;"></div>
</div>

<!-- Gerador de Posts para Redes Sociais -->
<div class="ap-card" style="border:2px solid #6ee7b7;">
    <h3>📱 Gerador de Posts para Redes Sociais</h3>
    <p style="color:#475569;margin:0 0 14px;font-size:13px;">
        Gera um post <strong>separado e otimizado para cada rede</strong> (Facebook, Instagram, X, LinkedIn, Reddit, Pinterest)
        a partir de um artigo publicado. Cada post inclui SEO/GEO, o link do artigo como referência e a imagem do artigo.
        <strong>Econômico:</strong> gera todas as redes em uma única chamada de IA.
    </p>

    <div style="margin-bottom:12px;">
        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Artigo base</label>
        <select id="sp-post" style="width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;">
            <option value="">— selecione um post —</option>
        </select>
        <p style="font-size:11px;color:#64748b;margin:4px 0 0;">Posts publicados (50 mais recentes)</p>
    </div>

    <div style="margin-bottom:12px;">
        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Redes (marque as que quer gerar)</label>
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <label style="display:flex;align-items:center;gap:5px;font-size:13px;"><input type="checkbox" class="sp-net" value="facebook" checked> 📘 Facebook</label>
            <label style="display:flex;align-items:center;gap:5px;font-size:13px;"><input type="checkbox" class="sp-net" value="instagram" checked> 📷 Instagram</label>
            <label style="display:flex;align-items:center;gap:5px;font-size:13px;"><input type="checkbox" class="sp-net" value="twitter" checked> 🐦 X (Twitter)</label>
            <label style="display:flex;align-items:center;gap:5px;font-size:13px;"><input type="checkbox" class="sp-net" value="linkedin" checked> 💼 LinkedIn</label>
            <label style="display:flex;align-items:center;gap:5px;font-size:13px;"><input type="checkbox" class="sp-net" value="reddit" checked> 🤖 Reddit</label>
            <label style="display:flex;align-items:center;gap:5px;font-size:13px;"><input type="checkbox" class="sp-net" value="pinterest" checked> 📌 Pinterest</label>
        </div>
    </div>

    <button class="btn-run" style="background:#059669;" onclick="spGenerate(this)">📱 Gerar Posts Sociais</button>

    <div id="sp-result" style="margin-top:14px;display:none;"></div>
</div>

<!-- 1.0.0: Manutenção e Diagnóstico -->
<div class="ap-card" style="border:2px solid #fde68a;background:#fffbeb;">
    <h3 style="color:#92400e;">🛠️ Manutenção e Diagnóstico</h3>
    <p style="color:#78350f;margin:0 0 14px;font-size:13px;">
        Use estas ferramentas se o Brain gerou títulos ruins, se jobs ficaram presos em "pending",
        ou se o Writer automático não está executando.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <button class="btn-secondary" onclick="maintForceReplan(this)" style="background:#dc2626;color:#fff;border:none;">
            🔄 Apagar Pendentes & Re-planejar Agora
        </button>
        <button class="btn-secondary" onclick="maintClearPending(this)" style="background:#f59e0b;color:#fff;border:none;">
            🗑️ Apagar Apenas Pendentes
        </button>
        <button class="btn-secondary" onclick="maintClearLogs(this)">
            🧹 Limpar Logs
        </button>
        <button class="btn-secondary" onclick="maintRepairSara(this)" style="background:#2563eb;color:#fff;border:none;">
            🩺 Reparar SARA com segurança
        </button>
        <button class="btn-secondary" onclick="maintDiagnoseWriter(this)">
            🔍 Diagnosticar Writer Automático
        </button>
        <button class="btn-secondary" onclick="maintTestFalAI(this)" style="background:#0891b2;color:#fff;border:none;">
            🎨 Testar Fal.ai gpt-image-2 (gera 1 imagem)
        </button>
    </div>
    <div id="maint-result" style="margin-top:14px;display:none;padding:12px;border-radius:6px;font-family:monospace;font-size:12px;white-space:pre-wrap;"></div>
</div>

<!-- Log de Execução -->
<div class="ap-card">
    <h3>📋 Log de Execução <button class="btn-secondary" style="font-size:11px;padding:4px 10px;" onclick="refreshLogs(this)">🔄 Atualizar</button></h3>
    <div class="ap-log" id="ap-log">
    <?php foreach ($logs as $log): ?>
        <div class="log-<?php echo esc_attr($log['status']); ?>">
            [<?php echo esc_html(substr($log['created_at'], 5, 14)); ?>]
            [<?php echo esc_html(strtoupper($log['agent'])); ?>]
            <?php echo esc_html($log['action']); ?>:
            <?php echo esc_html(mb_substr($log['message'], 0, 200)); ?>
            <?php if ($log['cost_usd']): ?><span style="color:#fbbf24;">($<?php echo number_format($log['cost_usd'],6); ?>)</span><?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<script>
const NONCE = '<?php echo $nonce; ?>';
const AJAX  = '<?php echo admin_url('admin-ajax.php'); ?>';
const ZERNIO_ON = <?php echo \GeoMetodoSEO\Services\ZernioSocialService::is_configured() ? 'true' : 'false'; ?>;

function post(action, data, btn) {
    if (btn) { btn.disabled = true; const orig = btn.textContent; btn.textContent = '⏳ Aguarde...'; }
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', NONCE);
    Object.entries(data || {}).forEach(([k,v]) => fd.append(k, v));
    fetch(AJAX, {method:'POST', body:fd})
        .then(r => r.json())
        .then(r => {
            alert(r.data?.message || (r.success ? 'Concluído!' : 'Erro'));
            if (r.success) location.reload();
        })
        .catch(() => alert('Erro de conexão'))
        .finally(() => { if (btn) { btn.disabled = false; } });
}

function runBrain() { post('sara_autopilot_run_brain', {}, document.getElementById('btn-run-brain')); }
function runWriterNext() { post('sara_autopilot_run_writer_next', {}, document.getElementById('btn-run-writer')); }
function runJob(id) { post('sara_autopilot_run_job', {calendar_id: id}, null); }

function setSiteMode(mode) {
    fetch(AJAX, {method:'POST', body: new URLSearchParams({action:'sara_autopilot_set_mode', nonce:NONCE, mode:mode})})
        .then(r => r.json())
        .then(() => location.reload());
}

function refreshLogs(btn) {
    var logEl = document.getElementById('ap-log');
    if (!logEl) {
        console.error('[SARA] Elemento #ap-log não encontrado');
        return;
    }

    // Feedback visual
    var originalText = '';
    if (btn) {
        originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '⏳ Atualizando...';
    }

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({action: 'sara_autopilot_get_logs', nonce: NONCE})
    })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function(r) {
        if (r && r.success && r.data && typeof r.data.html === 'string') {
            logEl.innerHTML = r.data.html || '<div style="color:#94a3b8;padding:8px;">Sem logs ainda.</div>';
            // Pequeno feedback visual de sucesso
            if (btn) {
                btn.textContent = '✅ Atualizado';
                setTimeout(function() {
                    btn.disabled = false;
                    btn.textContent = originalText || '🔄 Atualizar';
                }, 800);
            }
        } else {
            throw new Error(r && r.data && r.data.message ? r.data.message : 'Resposta inválida');
        }
    })
    .catch(function(err) {
        console.error('[SARA] Erro ao atualizar logs:', err);
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText || '🔄 Atualizar';
        }
        // Mostrar erro inline no próprio log para o usuário ver
        var errMsg = '<div style="color:#f87171;padding:8px;">⚠️ Falha ao atualizar logs: ' + (err.message || 'erro desconhecido') + '</div>';
        if (logEl.innerHTML.indexOf('Falha ao atualizar') === -1) {
            logEl.insertAdjacentHTML('afterbegin', errMsg);
        }
    });
}

function detectNiche(btn) {
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳ Detectando...';
    fetch(AJAX, {method:'POST', body: new URLSearchParams({action:'sara_autopilot_detect_niche', nonce:NONCE})})
        .then(r => r.json())
        .then(r => {
            if (r.success) {
                document.getElementById('sara-niche-input').value = r.data.niche;
                alert('✅ ' + r.data.message + '\n\nTop categorias: ' + (r.data.top_categories || []).join(', '));
            } else {
                alert('Erro: ' + (r.data?.message || 'falha'));
            }
        })
        .finally(() => { btn.disabled = false; btn.textContent = orig; });
}

function checkCatLimit() {
    const checked = document.querySelectorAll('.brain-cat-cb:checked');
    document.getElementById('cat-counter').textContent = checked.length;
    if (checked.length > 5) {
        alert('Máximo 5 categorias. Desmarque alguma antes de adicionar nova.');
        event.target.checked = false;
        document.getElementById('cat-counter').textContent = checked.length - 1;
    }
    const perCat = parseInt(document.querySelector('[name="sara_articles_per_category"]')?.value || 2);
    const total = document.getElementById('total-articles');
    if (total) total.textContent = checked.length * perCat;
}

document.getElementById('brain-v2-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const checked = document.querySelectorAll('.brain-cat-cb:checked');
    if (checked.length < 2) {
        alert('⚠️ Selecione no mínimo 2 categorias para o Brain funcionar.');
        return;
    }
    const fd = new FormData(this);
    fd.append('action', 'sara_autopilot_save_config');
    fetch(AJAX, {method:'POST', body:fd})
        .then(r => r.json())
        .then(r => { alert(r.data?.message || 'Salvo!'); location.reload(); });
});

document.getElementById('ap-config-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'sara_autopilot_save_config');
    fetch(AJAX, {method:'POST', body:fd})
        .then(r => r.json())
        .then(r => { alert(r.data?.message || 'Salvo!'); });
});

// ── MANUAL WRITER ──────────────────────────────────────────────────────
document.getElementById('mw-title').addEventListener('input', function() {
    document.getElementById('mw-char-count').textContent = this.value.length;
});


var SARA_MANUAL_MODELS = {
    '': [['','— Modelo padrão do provedor global —']],
    'openai': [['','— Padrão OpenAI —'],['gpt-4.1-mini','GPT-4.1 Mini'],['gpt-4.1','GPT-4.1'],['gpt-5','GPT-5 compatível'],['gpt-5.4-mini','GPT-5.4 Mini'],['gpt-5.4','GPT-5.4'],['gpt-5.5-mini','GPT-5.5 Mini ⚠️ premium'],['gpt-5.5','GPT-5.5 ⚠️ premium/caro'],['gpt-5.5-pro','GPT-5.5 Pro ⚠️ premium máximo']],
    'groq': [['','— Padrão Groq —'],['openai/gpt-oss-120b','GPT OSS 120B ⭐'],['llama-3.3-70b-versatile','Llama 3.3 70B ✅'],['openai/gpt-oss-20b','GPT OSS 20B'],['llama-3.1-8b-instant','Llama 3.1 8B Instant'],['groq/compound-mini','Compound Mini'],['groq/compound','Compound'],['qwen/qwen3-32b','Qwen 3 32B'],['meta-llama/llama-4-scout-17b-16e-instruct','Llama 4 Scout']],
    'gemini': [['','— Padrão Gemini —'],['gemini-3.1-flash-lite','Gemini 3.1 Flash Lite ⭐'],['gemini-2.5-flash-lite','Gemini 2.5 Flash Lite'],['gemini-2.5-flash','Gemini 2.5 Flash'],['gemini-3-flash','Gemini 3 Flash']],
    'claude': [['','— Padrão Claude —'],['claude-sonnet-4-6','Claude Sonnet 4.6 ⭐'],['claude-haiku-4-5-20251001','Claude Haiku 4.5'],['claude-opus-4-6','Claude Opus 4.6'],['claude-sonnet-4-5','Claude Sonnet 4.5']],
    'perplexity': [['','— Padrão Perplexity —'],['sonar','Sonar'],['sonar-pro','Sonar Pro']],
    'naga': [['','— Padrão Naga —'],['gemini-2.5-flash:free','Gemini 2.5 Flash :free'],['llama-3.3-70b-versatile:free','LLaMA 3.3 70B :free'],['gpt-4o-mini:free','GPT-4o Mini :free']]
};
var SARA_MANUAL_SAVED_MODEL = <?php echo wp_json_encode(AutopilotInstaller::get('writer_model_generation', '')); ?>;
function saraUpdateManualModels(provider) {
    var sel = document.getElementById('mw-ai-model');
    if (!sel) return;
    var effectiveProvider = provider || '<?php echo esc_js(\GeoMetodoSEO\AI\ProviderResolver::for('manual_writer')); ?>';
    var list = SARA_MANUAL_MODELS[effectiveProvider] || SARA_MANUAL_MODELS[''];
    sel.innerHTML = '';
    list.forEach(function(pair){
        var opt = document.createElement('option');
        opt.value = pair[0];
        opt.textContent = pair[1];
        sel.appendChild(opt);
    });
    if (SARA_MANUAL_SAVED_MODEL) {
        var exists = Array.prototype.some.call(sel.options, function(o){ return o.value === SARA_MANUAL_SAVED_MODEL; });
        sel.value = exists ? SARA_MANUAL_SAVED_MODEL : '';
    }
}
saraUpdateManualModels(document.getElementById('mw-ai-provider') ? document.getElementById('mw-ai-provider').value : '');
document.getElementById('manual-writer-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn      = document.getElementById('mw-submit-btn');
    const statusEl = document.getElementById('mw-status');
    const resultEl = document.getElementById('mw-result');

    btn.disabled    = true;
    btn.textContent = '⏳ Gerando...';
    statusEl.innerHTML = '<span style="color:#f59e0b;">📝 Chamando IA, gerando conteúdo...</span>';
    resultEl.style.display = 'none';

    const fd = new FormData(this);
    fd.append('action', 'sara_writer_manual_test');

    // Atualização de progresso simulada
    let step = 0;
    const steps = [
        '📝 Gerando conteúdo via modelo selecionado...',
        '🖼️ Buscando imagem featured...',
        '🏗️ Aplicando schemas SEO/GEO/AEO...',
        '🔗 Adicionando links internos...',
        '💾 Salvando no WordPress...',
    ];
    const interval = setInterval(() => {
        if (step < steps.length) {
            statusEl.innerHTML = '<span style="color:#f59e0b;">' + steps[step] + '</span>';
            step++;
        }
    }, 4000);

    fetch(AJAX, { method: 'POST', body: fd })
        .then(function(r) {
            return r.text().then(function(text) {
                clearInterval(interval);
                // 1.0.0: Se vier HTML em vez de JSON, extrair erro PHP visível
                try {
                    return JSON.parse(text);
                } catch (e) {
                    // Tentar achar mensagem de erro PHP no HTML
                    var phpErr = text.match(/<b>(?:Fatal error|Parse error|Warning|Notice)<\/b>[^<]+/i);
                    var snippet = phpErr ? phpErr[0].replace(/<[^>]+>/g, '') : text.substring(0, 300);
                    throw new Error('Servidor retornou HTML em vez de JSON. ' + snippet);
                }
            });
        })
        .then(r => {
            if (!r.success) {
                statusEl.innerHTML = '<span style="color:#dc2626;">❌ ' + (r.data && r.data.message ? r.data.message : 'Falha') + '</span>';
                return;
            }
            const d = r.data;
            statusEl.innerHTML = '<span style="color:#16a34a;">✅ Gerado em ' + (d.duration_ms/1000).toFixed(1) + 's</span>';

            resultEl.style.display = 'block';
            resultEl.innerHTML =
                '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:16px;">' +
                    '<div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Status</div>' +
                    '<div style="font-size:14px;font-weight:700;color:' + (d.status==='published'?'#16a34a':'#f59e0b') + ';">' +
                    (d.status === 'published' ? '🚀 Publicado' : '📝 Rascunho') + '</div></div>' +
                    '<div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Palavras</div>' +
                    '<div style="font-size:14px;font-weight:700;">' + d.word_count_real + '</div></div>' +
                    '<div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Tempo</div>' +
                    '<div style="font-size:14px;font-weight:700;">' + (d.duration_ms/1000).toFixed(1) + 's</div></div>' +
                    '<div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Custo</div>' +
                    '<div style="font-size:14px;font-weight:700;color:#16a34a;">$' + d.cost_usd + '</div></div>' +
                    '<div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Modelo</div>' +
                    '<div style="font-size:14px;font-weight:700;">' + (d.model_requested || '—') + (d.strict_model ? ' 🔒' : '') + '</div></div>' +
                '</div>' +
                '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                    '<button class="btn-run" onclick="showPreview()">👁️ Visualizar Artigo</button>' +
                    '<a class="btn-secondary" href="' + d.edit_url + '" target="_blank">✏ Editar no WP</a>' +
                    (d.status === 'published'
                        ? '<a class="btn-secondary" href="' + d.post_url + '" target="_blank">🌐 Ver Publicado</a>'
                        : '<a class="btn-secondary" href="' + d.preview_url + '" target="_blank">👁️ Preview</a>') +
                '</div>';

            window.lastGeneratedPost = d;
        })
        .catch(err => {
            clearInterval(interval);
            statusEl.innerHTML = '<span style="color:#dc2626;">❌ Erro: ' + err.message + '</span>';
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = '⚡ Gerar Artigo Agora';
        });
});

function showPreview() {
    const d = window.lastGeneratedPost;
    if (!d) return;
    document.getElementById('mw-preview-content').innerHTML = '<h2>' + (document.getElementById('mw-title').value) + '</h2>' + d.preview_html;
    document.getElementById('mw-edit-btn').href    = d.edit_url;
    document.getElementById('mw-publish-btn').href = d.post_url;
    document.getElementById('mw-preview-modal').style.display = 'flex';
}

function closePreviewModal() {
    document.getElementById('mw-preview-modal').style.display = 'none';
}

// Auto-refresh do log a cada 30s (sem botão para não interferir)
// ── 1.0.0 IndexNow e Refresher ─────────────────────────────────────
function _ilShowResult(html, type) {
    var box = document.getElementById('indexlearn-result');
    if (!box) return;
    box.style.display = 'block';
    var bg = type === 'error' ? '#fee2e2' : (type === 'warning' ? '#fef3c7' : '#d1fae5');
    var fg = type === 'error' ? '#7f1d1d' : (type === 'warning' ? '#78350f' : '#064e3b');
    box.style.background = bg;
    box.style.color      = fg;
    box.innerHTML = html;
}

function _ilCallAjax(action, btn, extraParams, onSuccess) {
    var originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ ...';

    var params = Object.assign({action: action, nonce: NONCE}, extraParams || {});

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams(params)
    })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (r && r.success) {
            onSuccess(r.data);
        } else {
            _ilShowResult('❌ ' + (r && r.data && r.data.message ? r.data.message : 'Falha desconhecida'), 'error');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = originalText;
        _ilShowResult('❌ Erro de rede: ' + (err.message || 'desconhecido'), 'error');
    });
}

function indexnowToggle(btn, enable) {
    if (enable && !confirm('Ativar IndexNow?\n\nO plugin vai gerar uma chave única e servir em /<chave>.txt na raiz do site. URLs publicadas/atualizadas serão enviadas automaticamente ao Bing/Yandex.\n\nContinuar?')) return;

    _ilCallAjax('sara_indexnow_toggle', btn, {enable: enable ? '1' : '0'}, function(data) {
        _ilShowResult(data.message + (data.key_url ? '\n🔑 Chave: ' + data.key_url : ''), 'success');
        setTimeout(function() { location.reload(); }, 2000);
    });
}

function indexnowFlush(btn) {
    _ilCallAjax('sara_indexnow_flush', btn, {include_all:'1'}, function(data) {
        _ilShowResult(data.message, data.success ? 'success' : 'warning');
        setTimeout(function() { location.reload(); }, 2000);
    });
}

function refresherToggle(btn, enable) {
    if (enable && !confirm('Ativar Content Refresher?\n\nUma vez por semana (domingo 03:00), o plugin vai atualizar até 2 posts antigos com dados novos via IA. Mantém URL/título e adiciona seção de "Atualização".\n\nContinuar?')) return;

    _ilCallAjax('sara_refresher_toggle', btn, {enable: enable ? '1' : '0'}, function(data) {
        _ilShowResult(data.message, 'success');
        setTimeout(function() { location.reload(); }, 1500);
    });
}

function refresherRunNow(btn) {
    if (!confirm('Executar Refresher agora?\n\nVai atualizar até 2 posts antigos imediatamente. Pode levar 1-3 minutos por post (chamada à IA).')) return;

    _ilCallAjax('sara_refresher_run_now', btn, {}, function(data) {
        _ilShowResult(data.message, 'success');
        setTimeout(function() { location.reload(); }, 3000);
    });
}
function refresherLoadCandidates(btn) {
    var box = document.getElementById('refresher-candidates');
    if (!box) return;
    var orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Carregando...';
    fetch(AJAX, {method:'POST', body:new URLSearchParams({action:'sara_refresher_get_candidates', nonce:NONCE})})
        .then(function(r){ return r.json(); })
        .then(function(r){
            btn.disabled = false;
            btn.textContent = orig;
            box.style.display = 'block';
            if (r && r.success) {
                box.innerHTML = r.data.html || '<div style="padding:10px;color:#64748b;">Sem candidatos.</div>';
            } else {
                box.innerHTML = '<div style="padding:10px;background:#fee2e2;color:#7f1d1d;border-radius:6px;">❌ ' + (r && r.data && r.data.message ? r.data.message : 'falha') + '</div>';
            }
        })
        .catch(function(err){
            btn.disabled = false;
            btn.textContent = orig;
            box.style.display = 'block';
            box.innerHTML = '<div style="padding:10px;background:#fee2e2;color:#7f1d1d;border-radius:6px;">❌ Erro de rede: ' + (err.message || '') + '</div>';
        });
}

function refresherRefreshPost(btn, postId) {
    if (!confirm('Reescrever/atualizar este artigo antigo agora?\n\nO plugin preserva a imagem destacada, o slug, a categoria e as imagens internas existentes.')) return;
    _ilCallAjax('sara_refresher_refresh_post', btn, {post_id: postId}, function(data) {
        _ilShowResult(data.message, data.featured_preserved ? 'success' : 'warning');
        refresherLoadCandidates(document.querySelector('button[onclick^="refresherLoadCandidates"]') || btn);
    });
}
// ── /IndexNow e Refresher ──────────────────────────────────────────


// ── 1.0.0 Media Opportunities + Press Release ──────────────────────
function _mediaShowResult(html, type) {
    var box = document.getElementById('media-result');
    if (!box) return;
    box.style.display = 'block';
    var bg = type === 'error' ? '#fee2e2' : (type === 'warning' ? '#fef3c7' : '#d1fae5');
    var fg = type === 'error' ? '#7f1d1d' : (type === 'warning' ? '#78350f' : '#064e3b');
    box.style.background = bg;
    box.style.color = fg;
    box.innerHTML = html;
}

function mediaToggle(btn, enable) {
    var lang = (document.getElementById('media-lang') || {}).value || 'both';
    var orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ ...';

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({
            action: 'sara_media_toggle',
            nonce: NONCE,
            enable: enable ? '1' : '0',
            lang: lang
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        if (r && r.success) {
            _mediaShowResult(r.data.message, 'success');
            setTimeout(function() { location.reload(); }, 800);
        } else {
            btn.disabled = false;
            btn.textContent = orig;
            _mediaShowResult('❌ ' + (r && r.data && r.data.message ? r.data.message : 'Erro'), 'error');
        }
    });
}

function mediaCollectNow(btn) {
    var orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Coletando (até 60s)...';

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({action: 'sara_media_collect_now', nonce: NONCE, lang: (document.getElementById('media-lang') ? document.getElementById('media-lang').value : 'both')})
    })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        btn.disabled = false;
        btn.textContent = orig;
        if (r && r.success) {
            _mediaShowResult(r.data.message, 'success');
            setTimeout(function() { mediaLoadOpportunities(); }, 600);
        } else {
            _mediaShowResult('❌ ' + (r && r.data && r.data.message ? r.data.message : 'Erro'), 'error');
        }
    })
    .catch(function(e) {
        btn.disabled = false;
        btn.textContent = orig;
        _mediaShowResult('❌ Erro de rede: ' + (e.message || ''), 'error');
    });
}

function mediaLoadOpportunities() {
    var listBox = document.getElementById('media-opps-list');
    if (!listBox) return;
    listBox.style.display = 'block';
    listBox.innerHTML = '<div style="color:#6b7280;padding:12px;font-size:13px;">⏳ Carregando oportunidades...</div>';

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({action: 'sara_media_get_opportunities', nonce: NONCE, limit: '20', lang: (document.getElementById('media-lang') ? document.getElementById('media-lang').value : 'both')})
    })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        if (!r || !r.success) {
            listBox.innerHTML = '<div style="color:#dc2626;padding:12px;">❌ Erro ao carregar.</div>';
            return;
        }
        var items = r.data.items || [];
        if (items.length === 0) {
            listBox.innerHTML = '<div style="background:#f8fafc;padding:14px;border-radius:6px;color:#475569;font-size:13px;">📭 Nenhuma oportunidade relevante encontrada ainda. Clique em "🔄 Coletar Agora" para buscar.</div>';
            return;
        }

        var html = '<div style="display:flex;flex-direction:column;gap:10px;">';
        items.forEach(function(item) {
            var scoreColor = item.relevance_score >= 70 ? '#16a34a' : (item.relevance_score >= 50 ? '#d97706' : '#64748b');
            var sourceIcon = {
                'reddit': '🔸',
                'twitter': '🐦',
                'sourcebottle': '📡'
            }[item.source_type] || '📰';
            var langFlag = item.lang === 'pt' ? '🇧🇷' : '🇬🇧';

            var titleSafe = (item.title || '').replace(/[<>]/g, '');
            var contentSafe = (item.content || '').substring(0, 250).replace(/[<>]/g, '');
            var reasonSafe = (item.relevance_reason || '').replace(/[<>]/g, '');
            var pitchHtml = '';
            if (item.generated_pitch) {
                pitchHtml = '<div style="margin-top:10px;padding:10px 12px;background:#ecfeff;border-left:3px solid #0891b2;border-radius:4px;">' +
                            '<div style="font-size:11px;color:#0e7490;font-weight:600;margin-bottom:4px;">💬 PITCH GERADO:</div>' +
                            '<div style="font-size:12px;color:#164e63;white-space:pre-wrap;font-family:Georgia,serif;">' +
                            (item.generated_pitch || '').replace(/[<>]/g, '') + '</div>' +
                            '<button onclick="mediaCopyPitch(this, ' + item.id + ')" style="margin-top:6px;padding:4px 10px;font-size:11px;border:1px solid #0891b2;background:#fff;color:#0e7490;border-radius:4px;cursor:pointer;">📋 Copiar pitch</button>' +
                            '</div>';
            }

            html += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px;">' +
                    '<div style="display:flex;justify-content:space-between;align-items:start;gap:8px;margin-bottom:6px;">' +
                        '<div style="font-weight:600;color:#0f172a;font-size:13px;line-height:1.4;flex:1;">' +
                        sourceIcon + ' ' + langFlag + ' ' + titleSafe + '</div>' +
                        '<div style="background:' + scoreColor + ';color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap;">★ ' + item.relevance_score + '</div>' +
                    '</div>' +
                    '<div style="font-size:11px;color:#64748b;margin-bottom:6px;">' + (item.source_name || '') + ' · ' + (item.collected_at || '') + '</div>' +
                    (contentSafe ? '<div style="font-size:12px;color:#475569;margin-bottom:8px;line-height:1.5;">' + contentSafe + (contentSafe.length >= 250 ? '...' : '') + '</div>' : '') +
                    (reasonSafe ? '<div style="font-size:11px;color:#7c3aed;margin-bottom:8px;font-style:italic;">💡 ' + reasonSafe + '</div>' : '') +
                    '<div style="display:flex;gap:6px;flex-wrap:wrap;">' +
                        (item.link ? '<a href="' + item.link + '" target="_blank" rel="noopener" style="padding:4px 10px;font-size:11px;background:#f1f5f9;color:#0f172a;border-radius:4px;text-decoration:none;">🔗 Ver original</a>' : '') +
                        '<button onclick="mediaGeneratePitch(this, ' + item.id + ')" style="padding:4px 10px;font-size:11px;background:#7c3aed;color:#fff;border:none;border-radius:4px;cursor:pointer;">📝 ' + (item.generated_pitch ? 'Regerar' : 'Gerar') + ' Pitch</button>' +
                        '<button onclick="mediaGenerateArticle(this, ' + item.id + ')" style="padding:4px 10px;font-size:11px;background:#059669;color:#fff;border:none;border-radius:4px;cursor:pointer;">📄 Gerar Artigo</button>' +
                        '<button onclick="mediaDismiss(this, ' + item.id + ')" style="padding:4px 10px;font-size:11px;background:#fff;border:1px solid #cbd5e1;color:#64748b;border-radius:4px;cursor:pointer;">🚫 Descartar</button>' +
                    '</div>' +
                    pitchHtml +
                    '</div>';
        });
        html += '</div>';
        listBox.innerHTML = html;
    });
}

function mediaGeneratePitch(btn, id) {
    var orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Gerando...';

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({action: 'sara_media_generate_pitch', nonce: NONCE, id: id})
    })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        btn.disabled = false;
        btn.textContent = orig;
        if (r && r.success) {
            mediaLoadOpportunities();
        } else {
            alert('Erro: ' + (r && r.data && r.data.message ? r.data.message : 'falha'));
        }
    });
}

function mediaGenerateArticle(btn, id) {
    var orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Gerando artigo...';

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({action: 'sara_media_generate_article', nonce: NONCE, id: id})
    })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        btn.disabled = false;
        if (r && r.success) {
            btn.textContent = '✅ Artigo criado!';
            btn.style.background = '#059669';
            if (r.data && r.data.edit_link) {
                if (confirm('Artigo "' + (r.data.title || '') + '" criado como rascunho!\n\nAbrir para editar agora?')) {
                    window.open(r.data.edit_link, '_blank');
                }
            }
            setTimeout(function() { mediaLoadOpportunities(); }, 1200);
        } else {
            btn.textContent = orig;
            alert('Erro: ' + (r && r.data && r.data.message ? r.data.message : 'falha ao gerar artigo'));
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = orig;
        alert('Erro de conexão ao gerar artigo.');
    });
}

function mediaCopyPitch(btn, id) {
    // Achar o pitch text dentro do mesmo bloco
    var pitchDiv = btn.parentElement.querySelector('div[style*="font-family:Georgia"]');
    if (!pitchDiv) return;
    var text = pitchDiv.textContent || '';

    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.textContent;
            btn.textContent = '✅ Copiado!';
            setTimeout(function() { btn.textContent = orig; }, 1500);

            // Marcar como "copied" no backend
            fetch(AJAX, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'sara_media_mark_action',
                    nonce: NONCE,
                    id: id,
                    user_action: 'copied'
                })
            });
        });
    }
}

function mediaDismiss(btn, id) {
    if (!confirm('Descartar esta oportunidade?')) return;

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({
            action: 'sara_media_mark_action',
            nonce: NONCE,
            id: id,
            user_action: 'dismissed'
        })
    })
    .then(function() { mediaLoadOpportunities(); });
}

// ── Press Release ──────────────────────────────────────────────────
function _prShowResult(html, type) {
    var box = document.getElementById('pr-result');
    if (!box) return;
    box.style.display = 'block';
    box.innerHTML = html;
}

function prLoadPosts() {
    var sel = document.getElementById('pr-post-id');
    if (!sel) return;

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({action: 'sara_press_release_list_posts', nonce: NONCE})
    })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        if (r && r.success && r.data.posts) {
            var html = '<option value="">— selecione um post —</option>';
            r.data.posts.forEach(function(p) {
                var safeTitle = (p.title || '').replace(/[<>"']/g, '');
                html += '<option value="' + p.id + '">' + safeTitle + ' (' + p.date + ')</option>';
            });
            sel.innerHTML = html;
            // Popular também o dropdown do gerador de posts sociais
            var spSel = document.getElementById('sp-post');
            if (spSel) spSel.innerHTML = html;
        }
    });
}

// ── Gerador de Posts Sociais ──────────────────────────────────────────
function spGenerate(btn) {
    var postId = (document.getElementById('sp-post') || {}).value || '';
    if (!postId) { alert('Selecione um artigo base.'); return; }

    var nets = [];
    document.querySelectorAll('.sp-net:checked').forEach(function(c) { nets.push(c.value); });
    if (!nets.length) { alert('Marque ao menos uma rede.'); return; }

    var box = document.getElementById('sp-result');
    var orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Gerando posts...';
    box.style.display = 'block';
    box.innerHTML = '<div style="color:#6b7280;padding:12px;">⏳ Gerando posts otimizados para cada rede...</div>';

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({
            action: 'geo_social_posts_generate',
            nonce: NONCE,
            post_id: postId,
            networks: JSON.stringify(nets)
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        btn.disabled = false;
        btn.textContent = orig;
        if (!r || !r.success) {
            box.innerHTML = '<div style="color:#dc2626;padding:12px;">❌ ' + (r && r.data && r.data.message ? r.data.message : 'Falha ao gerar') + '</div>';
            return;
        }
        var d = r.data;
        var imgTag = d.image ? '<img src="' + d.image + '" style="max-width:120px;border-radius:6px;margin-bottom:10px;">' : '';
        var html = '<div style="background:#f0fdf4;padding:14px;border-radius:8px;">' +
                   '<div style="color:#16a34a;font-weight:600;margin-bottom:6px;">✅ Posts gerados! Copie cada um e publique (ou use o Zernio).</div>' +
                   (d.image ? '<div style="font-size:11px;color:#475569;margin-bottom:6px;">🖼️ Imagem do artigo (anexe ao publicar):</div>' + imgTag : '');

        var icons = {facebook:'📘 Facebook', instagram:'📷 Instagram', twitter:'🐦 X (Twitter)', linkedin:'💼 LinkedIn', reddit:'🤖 Reddit', pinterest:'📌 Pinterest'};
        Object.keys(d.posts).forEach(function(net) {
            var p = d.posts[net];
            var safe = (p.text || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            var tid = 'sp-txt-' + net;
            html += '<div style="background:#fff;border:1px solid #d1fae5;border-radius:6px;padding:12px;margin-top:10px;">' +
                    '<div style="font-weight:700;font-size:13px;color:#065f46;margin-bottom:6px;">' + (icons[net] || net) + '</div>' +
                    '<textarea id="' + tid + '" rows="5" style="width:100%;font-size:12px;border:1px solid #e2e8f0;border-radius:4px;padding:8px;">' + safe + '</textarea>' +
                    '<button onclick="spCopy(\'' + tid + '\', this)" style="margin-top:6px;padding:5px 12px;font-size:12px;background:#059669;color:#fff;border:none;border-radius:4px;cursor:pointer;">📋 Copiar</button>' +
                    '</div>';
        });
        html += '</div>';
        box.innerHTML = html;
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = orig;
        box.innerHTML = '<div style="color:#dc2626;padding:12px;">❌ Erro de conexão.</div>';
    });
}

function spCopy(textareaId, btn) {
    var ta = document.getElementById(textareaId);
    if (!ta) return;
    ta.select();
    document.execCommand('copy');
    var o = btn.textContent;
    btn.textContent = '✅ Copiado!';
    setTimeout(function() { btn.textContent = o; }, 1500);
}

function prGenerate(btn) {
    var postId   = (document.getElementById('pr-post-id') || {}).value || '';
    var template = (document.getElementById('pr-template') || {}).value || '';
    var contact  = (document.getElementById('pr-contact') || {}).value || '';
    var spokes   = (document.getElementById('pr-spokesperson') || {}).value || '';
    var facts    = (document.getElementById('pr-key-facts') || {}).value || '';

    if (!postId) {
        _prShowResult('<div style="color:#dc2626;padding:12px;background:#fee2e2;border-radius:6px;">Selecione um post primeiro.</div>', 'error');
        return;
    }

    var orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Gerando press release (até 90s)...';

    var params = new URLSearchParams();
    params.append('action', 'sara_press_release_generate');
    params.append('nonce', NONCE);
    params.append('post_id', postId);
    params.append('template', template);
    if (contact) params.append('contact_email', contact);
    if (spokes)  params.append('spokesperson', spokes);
    if (facts)   params.append('key_facts', facts);

    fetch(AJAX, { method: 'POST', body: params })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        btn.disabled = false;
        btn.textContent = orig;

        if (!r || !r.success) {
            _prShowResult('<div style="color:#dc2626;padding:12px;background:#fee2e2;border-radius:6px;">❌ ' + (r && r.data && r.data.message ? r.data.message : 'Falha') + '</div>', 'error');
            return;
        }

        var pkg = r.data.package || {};
        var section = function(label, content, copyable) {
            if (!content) return '';
            var safe = String(content).replace(/[<>]/g, function(c) { return c === '<' ? '&lt;' : '&gt;'; });
            var copyBtn = '';
            if (copyable) {
                copyBtn = '<button onclick="navigator.clipboard.writeText(this.parentElement.querySelector(\'.pr-content\').textContent).then(()=>{this.textContent=\'✅\';setTimeout(()=>this.textContent=\'📋\',1500)})" style="float:right;padding:2px 8px;font-size:11px;border:1px solid #cbd5e1;background:#fff;border-radius:4px;cursor:pointer;">📋</button>';
            }
            return '<div style="margin-bottom:14px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px;">' +
                   '<div style="font-weight:600;color:#0f172a;font-size:12px;margin-bottom:6px;">' + label + copyBtn + '</div>' +
                   '<div class="pr-content" style="font-size:13px;color:#334155;line-height:1.6;white-space:pre-wrap;">' + safe + '</div>' +
                   '</div>';
        };

        var outletsHtml = '';
        if (Array.isArray(pkg.target_outlets) && pkg.target_outlets.length) {
            outletsHtml = '<div style="margin-bottom:14px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:12px;">' +
                          '<div style="font-weight:600;color:#78350f;font-size:12px;margin-bottom:6px;">🎯 ENVIE PARA ESTES TIPOS DE VEÍCULOS</div>' +
                          '<ul style="margin:0;padding-left:20px;font-size:13px;color:#78350f;">';
            pkg.target_outlets.forEach(function(o) {
                outletsHtml += '<li>' + String(o).replace(/[<>]/g, '') + '</li>';
            });
            outletsHtml += '</ul></div>';
        }

        var zernioBtn = '';
        if (pkg.social_linkedin || pkg.social_twitter) {
            // Guardar os textos sociais para o compartilhamento
            window._prSocial = {
                postId: postId,
                linkedin: pkg.social_linkedin || '',
                twitter: pkg.social_twitter || ''
            };
            if (ZERNIO_ON) {
                zernioBtn = '<div style="margin-bottom:14px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;padding:12px;">' +
                            '<div style="font-weight:600;color:#065f46;font-size:12px;margin-bottom:8px;">🚀 COMPARTILHAR NAS REDES SOCIAIS (via Zernio)</div>' +
                            '<button onclick="prShareZernio(this)" style="padding:8px 16px;font-size:13px;background:#059669;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">📤 Compartilhar versões sociais</button>' +
                            '<p style="margin:8px 0 0;font-size:11px;color:#047857;">Publica as versões LinkedIn e Twitter/X nas contas conectadas na Zernio.</p>' +
                            '</div>';
            } else {
                zernioBtn = '<div style="margin-bottom:14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:12px;">' +
                            '<div style="font-weight:600;color:#9a3412;font-size:12px;margin-bottom:6px;">🚀 COMPARTILHAR NAS REDES SOCIAIS</div>' +
                            '<p style="margin:0;font-size:12px;color:#9a3412;">Para publicar estas versões automaticamente nas redes, configure a <strong>Zernio API Key</strong> em Configurações. Depois de configurada, o botão de compartilhar aparece aqui.</p>' +
                            '</div>';
            }
        }

        var output = '<div style="background:#f8fafc;padding:14px;border-radius:8px;">' +
                     '<div style="color:#16a34a;font-weight:600;margin-bottom:14px;">✅ Press Release gerado!</div>' +
                     section('📧 SUBJECT LINE (assunto do email)', pkg.subject_line, true) +
                     section('📰 HEADLINE', pkg.headline, true) +
                     section('💬 SUBHEADLINE', pkg.subheadline, true) +
                     section('🎯 LEAD (parágrafo de abertura)', pkg.lead, true) +
                     section('📝 BODY (corpo completo)', pkg.body, true) +
                     section('🏢 BOILERPLATE (sobre o site)', pkg.boilerplate, true) +
                     section('💼 LINKEDIN', pkg.social_linkedin, true) +
                     section('🐦 TWITTER/X', pkg.social_twitter, true) +
                     zernioBtn +
                     section('📨 PITCH POR EMAIL (curto, para enviar a jornalistas)', pkg.pitch_email, true) +
                     outletsHtml +
                     '</div>';

        _prShowResult(output, 'success');
    })
    .catch(function(e) {
        btn.disabled = false;
        btn.textContent = orig;
        _prShowResult('<div style="color:#dc2626;padding:12px;background:#fee2e2;border-radius:6px;">❌ Erro de rede: ' + (e.message || '') + '</div>', 'error');
    });
}

// Compartilhar versões sociais do Press Release via Zernio
function prShareZernio(btn) {
    if (!window._prSocial || !window._prSocial.postId) {
        alert('Gere o press release primeiro.');
        return;
    }
    var orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Buscando contas conectadas...';

    // 1. Buscar contas conectadas na Zernio
    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({action: 'geo_zernio_accounts', nonce: NONCE})
    })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        if (!r || !r.success || !r.data || !Array.isArray(r.data.accounts) || !r.data.accounts.length) {
            btn.disabled = false;
            btn.textContent = orig;
            alert('Nenhuma conta conectada na Zernio. Conecte suas redes em zernio.com primeiro.');
            return;
        }
        // 2. Montar lista de plataformas (todas as contas conectadas)
        var platforms = r.data.accounts.map(function(a) {
            return { platform: a.platform || a.type || '', accountId: a.accountId || a.id || a._id || '' };
        }).filter(function(p) { return p.platform && p.accountId; });

        if (!platforms.length) {
            btn.disabled = false;
            btn.textContent = orig;
            alert('As contas conectadas não retornaram IDs válidos.');
            return;
        }

        // 3. Texto: usar a versão LinkedIn (mais completa) como base
        var text = window._prSocial.linkedin || window._prSocial.twitter || '';

        btn.textContent = '⏳ Publicando em ' + platforms.length + ' rede(s)...';
        fetch(AJAX, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'geo_zernio_share',
                nonce: NONCE,
                post_id: window._prSocial.postId,
                platforms: JSON.stringify(platforms),
                text: text
            })
        })
        .then(function(r2) { return r2.json(); })
        .then(function(r2) {
            btn.disabled = false;
            if (r2 && r2.success) {
                btn.textContent = '✅ Compartilhado!';
                btn.style.background = '#047857';
            } else {
                btn.textContent = orig;
                alert('Erro: ' + (r2 && r2.data && r2.data.message ? r2.data.message : 'falha ao compartilhar'));
            }
        });
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = orig;
        alert('Erro de conexão com a Zernio.');
    });
}

// Carregar lista de posts ao montar
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('pr-post-id')) prLoadPosts();
});
// ── /Media + PR ────────────────────────────────────────────────────

// ── 1.0.0 Manutenção e Diagnóstico ─────────────────────────────────
function _maintShowResult(html, type) {
    var box = document.getElementById('maint-result');
    if (!box) return;
    box.style.display = 'block';
    var bg = type === 'error' ? '#fee2e2' : (type === 'warning' ? '#fef3c7' : '#d1fae5');
    var fg = type === 'error' ? '#7f1d1d' : (type === 'warning' ? '#78350f' : '#064e3b');
    box.style.background = bg;
    box.style.color      = fg;
    box.innerHTML = html;
}

function _maintCallAjax(action, btn, confirmMsg, onSuccess) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    var originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Processando...';

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({action: action, nonce: NONCE})
    })
    .then(function(r) { return r.json(); })
    .then(function(r) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (r && r.success) {
            onSuccess(r.data);
        } else {
            _maintShowResult('❌ Erro: ' + (r && r.data && r.data.message ? r.data.message : 'falha'), 'error');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = originalText;
        _maintShowResult('❌ Erro de rede: ' + (err.message || 'desconhecido'), 'error');
    });
}

function maintClearPending(btn) {
    _maintCallAjax(
        'sara_autopilot_clear_pending',
        btn,
        'Apagar TODOS os jobs pendentes do calendário?\n\nEles serão removidos e seus crons agendados serão cancelados. O Brain pode ser executado depois para gerar novos.',
        function(data) {
            _maintShowResult(data.message, 'warning');
            setTimeout(function() { location.reload(); }, 1500);
        }
    );
}

function maintForceReplan(btn) {
    _maintCallAjax(
        'sara_autopilot_force_replan',
        btn,
        '🔄 RE-PLANEJAR AGORA\n\nIsto vai:\n1) Apagar todos os jobs pendentes\n2) Cancelar os crons\n3) Executar o Brain agora para gerar novos títulos\n\nContinuar?',
        function(data) {
            _maintShowResult(data.message, 'success');
            setTimeout(function() { location.reload(); }, 2500);
        }
    );
}

function maintClearLogs(btn) {
    _maintCallAjax(
        'sara_autopilot_clear_logs',
        btn,
        'Limpar TODOS os logs de execução?',
        function(data) {
            _maintShowResult(data.message, 'success');
            setTimeout(function() { location.reload(); }, 1500);
        }
    );
}
function maintRepairSara(btn) {
    _maintCallAjax(
        'sara_autopilot_repair',
        btn,
        'Executar reparo seguro da SARA?\n\nIsto recria/verifica tabelas, libera jobs travados e reajusta crons sem apagar artigos publicados.',
        function(data) {
            _maintShowResult(data.message || '✅ Reparos aplicados.', 'success');
            setTimeout(function() { location.reload(); }, 1800);
        }
    );
}

function maintDiagnoseWriter(btn) {
    _maintCallAjax(
        'sara_autopilot_diagnose_writer',
        btn,
        null,
        function(data) {
            var lines = [];
            lines.push('🕒 Agora: ' + data.now);
            lines.push('✍️ Writer ativo: ' + (data.writer_enabled ? 'SIM ✅' : 'NÃO ❌'));
            lines.push('⚠️ WP-Cron desabilitado: ' + (data.wp_cron_disabled ? 'SIM (precisa cron real do servidor!)' : 'não'));
            lines.push('');
            lines.push('📊 Status dos jobs:');
            for (var k in data.by_status) {
                lines.push('   - ' + k + ': ' + data.by_status[k]);
            }
            lines.push('');
            lines.push('⏰ Jobs ATRASADOS (passou do horário): ' + data.missed_count);
            if (data.missed_jobs && data.missed_jobs.length) {
                data.missed_jobs.forEach(function(j) {
                    lines.push('   #' + j.id + ' [' + j.scheduled + '] ' + j.title + (j.cron_at ? ' (cron: ' + j.cron_at + ')' : ' ⚠️ SEM CRON'));
                });
            }
            lines.push('');
            lines.push('📅 Jobs futuros: ' + data.upcoming_count);
            lines.push('🚨 Jobs sem cron agendado: ' + data.no_cron_count);
            if (data.last_writer_log) {
                lines.push('');
                lines.push('📝 Último log do Writer:');
                lines.push('   [' + data.last_writer_log.created_at + '] ' + data.last_writer_log.action + ': ' + data.last_writer_log.message);
            }

            var hasIssue = data.missed_count > 0 || data.no_cron_count > 0 || data.wp_cron_disabled || !data.writer_enabled;
            _maintShowResult(lines.join('\n'), hasIssue ? 'warning' : 'success');
        }
    );
}
// v1.0.0 — Testar Fal.ai gpt-image-2
function maintTestFalAI(btn) {
    var orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Testando (até 90s)...';

    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({action: 'sara_falai_test', nonce: NONCE})
    })
    .then(function(r) {
        return r.text().then(function(text) {
            try { return JSON.parse(text); }
            catch (e) { throw new Error('Servidor retornou HTML: ' + text.substring(0, 200)); }
        });
    })
    .then(function(r) {
        btn.disabled = false;
        btn.textContent = orig;
        if (r.success) {
            var okMessage = r.data && r.data.message;
            if (typeof okMessage !== 'string') okMessage = JSON.stringify(okMessage || 'Fal.ai OK');
            var html = '✅ ' + okMessage;
            if (r.data.image_url) {
                html += '\n\nImagem teste:\n' + r.data.image_url +
                        '\n\n(Abra o link em nova aba para ver)';
            }
            _maintShowResult(html, 'success');
        } else {
            var errMessage = r.data && r.data.message ? r.data.message : 'falha';
            if (typeof errMessage !== 'string') errMessage = JSON.stringify(errMessage);
            var html = '❌ ' + errMessage;
            if (r.data && r.data.last_err) {
                html += '\n\nÚltimo erro registrado:\n' +
                        '[' + r.data.last_err.where + '] ' + r.data.last_err.message +
                        '\nEm: ' + r.data.last_err.time;
            }
            _maintShowResult(html, 'error');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = orig;
        _maintShowResult('❌ Erro: ' + (err.message || ''), 'error');
    });
}

function saraRepair(btn) {
    var orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Reparando...';
    fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({action: 'sara_autopilot_repair', nonce: NONCE})
    }).then(function(r){ return r.json(); }).then(function(r){
        btn.disabled = false;
        btn.textContent = orig;
        if (r.success) {
            alert(r.data.message || 'SARA reparada.');
            location.reload();
        } else {
            alert('Erro: ' + (r.data && r.data.message ? r.data.message : 'falha'));
        }
    }).catch(function(err){
        btn.disabled = false;
        btn.textContent = orig;
        alert('Erro de rede: ' + (err.message || ''));
    });
}

// ── /Manutenção ────────────────────────────────────────────────────

setInterval(function() { refreshLogs(null); }, 30000);
</script>
</div>

