<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\License\LicenseManager;

class AdminController {

    public function register_menu() {
        $plan = LicenseManager::getPlan();
        $has_license = LicenseManager::isActive();
        $badge_map = [
            'trial'  => 'TRIAL',
            'basic'  => 'BASIC',
            'pro'    => 'PRO',
            'agency' => 'AGENCY',
            'master' => 'MASTER',
        ];
        $badge = $badge_map[$plan] ?? 'TRIAL';

        add_menu_page(
            'GEO Metodo SEO',
            'GEO SEO ' . $badge,
            'manage_options',
            'geo-metodo-seo',
            [$this, 'render_dashboard'],
            'dashicons-chart-line'
        );

        add_submenu_page(
            'geo-metodo-seo',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'geo-dashboard',
            $this->licensed_callback([new DashboardController(), 'render_page'])
        );

        add_submenu_page(
            'geo-metodo-seo',
            'Adquirir Licenca',
            $has_license ? 'Licenca Ativa' : 'Adquirir Licenca',
            'manage_options',
            'geo-license',
            [new LicenseController(), 'render_page']
        );

        add_submenu_page(
            'geo-metodo-seo',
            'Gerar Artigo',
            'Gerar Artigo',
            'manage_options',
            'geo-individual',
            [new IndividualGeneratorController(), 'render_page']
        );

        add_submenu_page(
            'geo-metodo-seo',
            'Geracao em Massa',
            'Geracao em Massa',
            'manage_options',
            'geo-bulk',
            $this->licensed_callback([new BulkGeneratorController(), 'render_page'])
        );

        add_submenu_page(
            'geo-metodo-seo',
            'Templates',
            'Templates',
            'manage_options',
            'geo-templates',
            $this->licensed_callback([new TemplateController(), 'render_page'])
        );

        add_submenu_page(
            'geo-metodo-seo',
            'Gerador de Titulos',
            'Gerador de Titulos',
            'manage_options',
            'geo-title-generator',
            $this->licensed_callback([new TitleGeneratorController(), 'render_page'])
        );

        add_submenu_page(
            'geo-metodo-seo',
            'GEO Glossario SEO',
            'GEO Glossario SEO',
            'manage_options',
            'geo-glossario-seo',
            $this->licensed_callback([new GlossaryController(), 'render_page'])
        );

        add_submenu_page(
            'geo-metodo-seo',
            'Cluster SEO',
            'Cluster SEO',
            'manage_options',
            'geo-cluster',
            $this->licensed_callback([new ClusterController(), 'render_page'])
        );

        add_submenu_page(
            'geo-metodo-seo',
            'Analise SEO/GEO',
            'Analise SEO/GEO',
            'manage_options',
            'geo-analysis',
            $this->licensed_callback([new AnalysisController(), 'render_page'])
        );


        add_submenu_page(
            'geo-metodo-seo',
            'Seguranca de Producao',
            'Seguranca de Producao',
            'manage_options',
            'geo-production-guard',
            $this->licensed_callback([new ProductionGuardController(), 'render_page'])
        );

        add_submenu_page(
            'geo-metodo-seo',
            'Diagnostico e Manual',
            'Diagnostico e Manual',
            'manage_options',
            'geo-diagnostics',
            $this->licensed_callback([new DiagnosticsController(), 'render_page'])
        );

        add_submenu_page(
            'geo-metodo-seo',
            'Logs',
            'Logs',
            'manage_options',
            'geo-logs',
            $this->licensed_callback([new LogController(), 'render_page'])
        );

        if (class_exists('GeoMetodoSEO\\Admin\\ReSideloaderController')) {
            add_submenu_page(
                'geo-metodo-seo',
                'Recuperar Imagens',
                'Recuperar Imagens',
                'manage_options',
                'geo-resideload',
                $this->licensed_callback([new \GeoMetodoSEO\Admin\ReSideloaderController(), 'render_page'])
            );
        }

        add_submenu_page(
            'geo-metodo-seo',
            'Configuracoes',
            'Configuracoes',
            'manage_options',
            'geo-settings',
            [new SettingsController(), 'render_page']
        );

        if (class_exists('GeoMetodoSEO\\Admin\\SARAController')) {
            add_submenu_page(
                'geo-metodo-seo',
                'SARA - IA Especialista',
                'SARA - IA',
                'manage_options',
                'geo-sara',
                $this->licensed_callback([new \GeoMetodoSEO\Admin\SARAController(), 'render_page'])
            );
        }
    }

    public function register_network_menu() {
        add_menu_page(
            'GEO SEO Rede',
            'GEO SEO Rede',
            'manage_network_options',
            'geo-network',
            [new MultisiteController(), 'render_page'],
            'dashicons-chart-line'
        );
    }

    public function render_dashboard() {
        if (!LicenseManager::isActive()) {
            $this->render_license_required('Dashboard');
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>GEO Metodo SEO</h1>';
        echo '<p>Painel inicial do plugin.</p>';
        echo '</div>';
    }

    private function licensed_callback(callable $callback): callable {
        return function() use ($callback) {
            if (!LicenseManager::isActive()) {
                $this->render_license_required();
                return;
            }
            call_user_func($callback);
        };
    }

    private function render_license_required(string $title = 'Recurso bloqueado'): void {
        $trial_count = LicenseManager::getTrialCount();
        $trial_limit = LicenseManager::TRIAL_LIMIT;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<div style="max-width:760px;margin:28px 0;padding:28px;background:#fff;border-left:5px solid #d63638;box-shadow:0 1px 3px rgba(0,0,0,.12);">';
        echo '<h2 style="margin-top:0;color:#d63638;">Licenca obrigatoria</h2>';
        echo '<p>Esta area do GEO Metodo SEO fica bloqueada ate ativar uma licenca valida.</p>';
        echo '<p>Sem licenca, somente <strong>Configuracoes</strong> fica livre e <strong>Gerar Artigo Individual</strong> permite teste de ate <strong>' . intval($trial_limit) . ' artigos</strong>.</p>';
        echo '<p>Uso do teste individual: <strong>' . intval($trial_count) . '/' . intval($trial_limit) . '</strong>.</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=geo-license')) . '" class="button button-primary button-large">Adquirir / Ativar Licenca</a> ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=geo-settings')) . '" class="button button-large">Abrir Configuracoes</a> ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=geo-individual')) . '" class="button button-large">Testar Gerar Artigo</a></p>';
        echo '</div></div>';
    }
}
