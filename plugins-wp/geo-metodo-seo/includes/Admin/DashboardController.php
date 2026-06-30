<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Services\ReportService;

class DashboardController {

    private $provider_labels = [
        'openai'     => 'OpenAI',
        'groq'       => 'Groq',
        'gemini'     => 'Gemini',
        'claude'     => 'Claude',
        'perplexity' => 'Perplexity',
    ];

    public function render_page() {

        $service = new ReportService();
        $posts   = $service->get_articles();
        $data    = $service->format_data($posts);

        $total        = count($data);
        $total_words  = array_sum(array_column($data, 'words'));
        $with_image   = count(array_filter($data, fn($r) => $r['has_image'] === 'Sim'));
        $by_provider  = $this->count_by_provider($posts);
        $by_status    = $this->count_by_status($data);

        global $wpdb;
        $jobs_table   = $wpdb->prefix . 'geo_jobs';
        $pending      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'pending'");
        $done         = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'done'");
        $errors       = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'error'");

        $csv_nonce = wp_create_nonce('geo_export_csv');

        // Cost data
        $cost_data = $this->get_cost_data();

        // v1.0.0 rewrite stats
        $rewritten_total   = \GeoMetodoSEO\Services\ContentUpdater::count_rewritten();
        $rewritten_upcoming = \GeoMetodoSEO\Services\ContentUpdater::count_upcoming((int) get_option('geo_rewrite_days', 120));

        ?>
        <div class="wrap">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                <h1>Dashboard — GEO Metodo SEO v<?php echo GEO_METODO_SEO_VERSION; ?></h1>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="geo_export_csv">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($csv_nonce); ?>">
                    <button type="submit" class="button button-secondary">
                        📥 Exportar CSV
                    </button>
                </form>
            </div>

            <!-- Estatisticas gerais -->
            <div style="display:flex; gap:16px; flex-wrap:wrap; margin:20px 0;">
                <?php
                $cards = [
                    ['📝', 'Artigos Gerados',     $total,                    '#0073aa'],
                    ['📖', 'Total de Palavras',    number_format($total_words, 0, ',', '.'), '#46b450'],
                    ['🖼️', 'Com Imagem Destaque',  $with_image,               '#8b5cf6'],
                    ['⏳', 'Jobs Pendentes',        $pending,                  '#f0a500'],
                    ['✅', 'Jobs Concluidos',       $done,                     '#46b450'],
                    ['❌', 'Jobs com Erro',         $errors,                   '#dc3232'],
                    ['💰', 'Custo do Mes (USD)',    '$' . number_format($cost_data['month_total'], 3), '#e67e22'],
                    ['📊', 'Custo Medio/Artigo',   '$' . number_format($cost_data['avg_cost'], 3),    '#9b59b6'],
                    ['🔄', 'Artigos Reescritos',    $rewritten_total,                                  '#16a085'],
                    ['📅', 'A Reescrever (7 dias)', $rewritten_upcoming,                               '#8e44ad'],
                ];
                foreach ($cards as $card):
                ?>
                <div style="background:#fff; border:1px solid #ddd; border-radius:6px; padding:16px 24px; min-width:150px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <div style="font-size:28px;"><?php echo $card[0]; ?></div>
                    <div style="font-size:24px; font-weight:700; color:<?php echo $card[3]; ?>;"><?php echo $card[2]; ?></div>
                    <div style="font-size:12px; color:#666; margin-top:4px;"><?php echo esc_html($card[1]); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; gap:24px; flex-wrap:wrap; margin-top:8px;">

                <!-- Por provedor -->
                <div style="flex:1; min-width:260px;">
                    <h2 style="font-size:16px;">🤖 Artigos por Provedor de IA</h2>
                    <table class="widefat fixed striped">
                        <thead><tr><th>Provedor</th><th style="width:80px; text-align:right;">Artigos</th></tr></thead>
                        <tbody>
                        <?php if (empty($by_provider)): ?>
                            <tr><td colspan="2" style="color:#666;">Nenhum dado ainda.</td></tr>
                        <?php else: ?>
                            <?php foreach ($by_provider as $prov => $count): ?>
                            <tr>
                                <td><?php echo esc_html($this->provider_labels[$prov] ?? $prov); ?></td>
                                <td style="text-align:right; font-weight:600;"><?php echo intval($count); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Por status -->
                <div style="flex:1; min-width:220px;">
                    <h2 style="font-size:16px;">📊 Artigos por Status</h2>
                    <table class="widefat fixed striped">
                        <thead><tr><th>Status</th><th style="width:80px; text-align:right;">Qtd</th></tr></thead>
                        <tbody>
                        <?php foreach ($by_status as $status => $count): ?>
                            <tr>
                                <td><?php echo esc_html($status); ?></td>
                                <td style="text-align:right; font-weight:600;"><?php echo intval($count); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Custo por Provedor -->
            <?php if (!empty($cost_data['by_provider'])): ?>
            <div style="margin-top:24px;">
                <h2 style="font-size:16px;">💰 Custo de API — Ultimos 30 Dias</h2>
                <div style="display:flex; gap:24px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:240px;">
                        <table class="widefat fixed striped">
                            <thead><tr><th>Provedor</th><th style="width:100px; text-align:right;">Artigos</th><th style="width:100px; text-align:right;">Custo (USD)</th></tr></thead>
                            <tbody>
                            <?php foreach ($cost_data['by_provider'] as $prov => $cd): ?>
                                <tr>
                                    <td><?php echo esc_html($this->provider_labels[$prov] ?? $prov); ?></td>
                                    <td style="text-align:right;"><?php echo intval($cd['count']); ?></td>
                                    <td style="text-align:right; font-weight:600;">$<?php echo number_format($cd['cost'], 3); ?></td>
                                </tr>
                            <?php endforeach; ?>
                                <tr style="font-weight:700; background:#f0f8ff;">
                                    <td>TOTAL</td>
                                    <td style="text-align:right;"><?php echo intval($cost_data['month_articles']); ?></td>
                                    <td style="text-align:right;">$<?php echo number_format($cost_data['month_total'], 3); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($cost_data['daily'])): ?>
                    <div style="flex:2; min-width:360px;">
                        <div style="font-size:13px; color:#666; margin-bottom:8px;">Custo diario — ultimos 30 dias</div>
                        <?php
                        $max_daily = max(array_column($cost_data['daily'], 'cost') + [0.001]);
                        foreach ($cost_data['daily'] as $day):
                            $pct = round(($day['cost'] / $max_daily) * 100);
                        ?>
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                            <span style="font-size:11px; color:#666; min-width:72px;"><?php echo esc_html(date('d/m', strtotime($day['day']))); ?></span>
                            <div style="flex:1; background:#eee; border-radius:3px; height:14px;">
                                <div style="width:<?php echo $pct; ?>%; background:#0073aa; height:14px; border-radius:3px;"></div>
                            </div>
                            <span style="font-size:11px; min-width:52px; text-align:right;">$<?php echo number_format($day['cost'], 3); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Ultimos artigos -->
            <h2 style="font-size:16px; margin-top:28px;">📰 Ultimos Artigos Gerados</h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:50px;">ID</th>
                        <th>Titulo</th>
                        <th style="width:120px;">Keyword</th>
                        <th style="width:90px;">Provedor</th>
                        <th style="width:80px; text-align:right;">Palavras</th>
                        <th style="width:80px;">Status</th>
                        <th style="width:80px;">Imagem</th>
                        <th style="width:140px;">Data</th>
                        <th style="width:80px;">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($data)): ?>
                    <tr><td colspan="9" style="color:#666; text-align:center;">Nenhum artigo gerado ainda. Use <a href="<?php echo esc_url(admin_url('admin.php?page=geo-individual')); ?>">Gerar Artigo</a> ou <a href="<?php echo esc_url(admin_url('admin.php?page=geo-bulk')); ?>">Geracao em Massa</a>.</td></tr>
                <?php else: ?>
                    <?php foreach (array_slice($data, 0, 20) as $row): ?>
                    <tr>
                        <td><?php echo intval($row['id']); ?></td>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>" target="_blank">
                                <?php echo esc_html(wp_trim_words($row['title'], 10)); ?>
                            </a>
                        </td>
                        <td style="font-size:12px;"><?php echo esc_html($row['keyword']); ?></td>
                        <td style="font-size:12px;"><?php echo esc_html($this->provider_labels[$row['provider']] ?? '—'); ?></td>
                        <td style="text-align:right;"><?php echo number_format((int)$row['words'], 0, ',', '.'); ?></td>
                        <td><span style="font-size:11px;"><?php echo esc_html($row['status']); ?></span></td>
                        <td><?php echo $row['has_image'] === 'Sim' ? '✅' : '—'; ?></td>
                        <td style="font-size:11px;"><?php echo esc_html(date('d/m/Y H:i', strtotime($row['date']))); ?></td>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>" target="_blank" class="button button-small">Editar</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function count_by_provider($posts) {
        $counts = [];
        foreach ($posts as $post) {
            $prov = get_post_meta($post->ID, '_geo_provider', true) ?: 'openai';
            $counts[$prov] = ($counts[$prov] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    private function count_by_status($data) {
        $counts = [];
        foreach ($data as $row) {
            $s = $row['status'];
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
        return $counts;
    }

    private function get_cost_data(): array {
        global $wpdb;

        $since = date('Y-m-d H:i:s', strtotime('-30 days'));

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_date FROM {$wpdb->posts}
             WHERE post_type = 'post' AND post_status != 'trash'
             AND post_date >= %s",
            $since
        ));

        $month_total   = 0.0;
        $month_articles = 0;
        $by_provider   = [];
        $daily         = [];

        foreach ($posts as $post) {
            $cost     = (float) get_post_meta($post->ID, '_geo_api_cost', true);
            $provider = get_post_meta($post->ID, '_geo_provider', true) ?: 'openai';
            $day      = date('Y-m-d', strtotime($post->post_date));

            if ($cost <= 0) {
                // Estimate from model if not saved
                $model = get_post_meta($post->ID, '_geo_model', true);
                $cost  = \GeoMetodoSEO\Admin\IndividualGeneratorController::$cost_estimates[$model] ?? 0.005;
            }

            $month_total     += $cost;
            $month_articles++;
            $by_provider[$provider]['count'] = ($by_provider[$provider]['count'] ?? 0) + 1;
            $by_provider[$provider]['cost']  = ($by_provider[$provider]['cost']  ?? 0.0) + $cost;
            $daily[$day]     = ($daily[$day] ?? 0.0) + $cost;
        }

        // Sort daily ASC and build array
        ksort($daily);
        $daily_arr = [];
        foreach ($daily as $d => $c) {
            $daily_arr[] = ['day' => $d, 'cost' => $c];
        }
        // Keep last 30
        $daily_arr = array_slice($daily_arr, -30);

        return [
            'month_total'    => $month_total,
            'month_articles' => $month_articles,
            'avg_cost'       => $month_articles > 0 ? $month_total / $month_articles : 0,
            'by_provider'    => $by_provider,
            'daily'          => $daily_arr,
        ];
    }
}
