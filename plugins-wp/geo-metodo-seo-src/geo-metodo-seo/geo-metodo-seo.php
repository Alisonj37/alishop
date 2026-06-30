<?php
/**
 * Plugin Name: GEO Método SEO
 * Description: Plataforma editorial com IA para WordPress focada em SEO, GEO, AEO e LLMs. Inclui SARA Autopilot, Writer Manual, YouTube para Artigo, Web Stories, GEO Glossário SEO, banco global de títulos, Rank Math, schemas, E-E-A-T, imagens, interlinking, atualização de posts antigos, proteção de créditos e logs profissionais.
 * Version: 1.0.0
 * Author: Alison Jean
 * Text Domain: geo-metodo-seo
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GEO_METODO_SEO_VERSION', '1.0.0');
define('GEO_METODO_SEO_PATH', plugin_dir_path(__FILE__));
define('GEO_METODO_SEO_URL', plugin_dir_url(__FILE__));

// Compatibilidade PHP 7.4+: polyfills para funções nativas do PHP 8 usadas por módulos modernos.
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos((string) $haystack, (string) $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

// Core
require_once GEO_METODO_SEO_PATH . 'includes/Core/Plugin.php';
require_once GEO_METODO_SEO_PATH . 'includes/Core/Loader.php';
require_once GEO_METODO_SEO_PATH . 'includes/Core/Activator.php';
require_once GEO_METODO_SEO_PATH . 'includes/Core/Deactivator.php';

// LogService carregado primeiro — vários controllers e services dependem dele.
// Carregar aqui (antes dos Admin Controllers) evita fatal error se algum
// controller usar LogService:: durante o carregamento.
require_once GEO_METODO_SEO_PATH . 'includes/Services/LogService.php';

// Admin
require_once GEO_METODO_SEO_PATH . 'includes/Admin/AdminController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/SettingsController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/IndividualGeneratorController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/BulkGeneratorController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/ClusterController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/DashboardController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/AnalysisController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/LogController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/TemplateController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/TitleGeneratorController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/MultisiteController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/LicenseController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/GlossaryController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/DiagnosticsController.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/ProductionGuardController.php';
// 1.0.0: Onboarding Wizard — assistente de configuração inicial pra novos usuários
require_once GEO_METODO_SEO_PATH . 'includes/Admin/OnboardingController.php';
\GeoMetodoSEO\Admin\OnboardingController::register_hooks();

// 1.0.0 — March 2026 Core Update: Quality + Schema modules
if (file_exists(GEO_METODO_SEO_PATH . 'includes/Quality/ThinContentValidator.php')) {
    require_once GEO_METODO_SEO_PATH . 'includes/Quality/ThinContentValidator.php';
}
if (file_exists(GEO_METODO_SEO_PATH . 'includes/Quality/OriginalDataInjector.php')) {
    require_once GEO_METODO_SEO_PATH . 'includes/Quality/OriginalDataInjector.php';
}
if (file_exists(GEO_METODO_SEO_PATH . 'includes/Schema/SpeakableSchemaInjector.php')) {
    require_once GEO_METODO_SEO_PATH . 'includes/Schema/SpeakableSchemaInjector.php';
}
if (file_exists(GEO_METODO_SEO_PATH . 'includes/Admin/AuthorProfileFields.php')) {
    require_once GEO_METODO_SEO_PATH . 'includes/Admin/AuthorProfileFields.php';
}

// License
require_once GEO_METODO_SEO_PATH . 'includes/License/LicenseManager.php';

function geo_metodo_license_active(): bool {
    return class_exists('\GeoMetodoSEO\License\LicenseManager')
        && \GeoMetodoSEO\License\LicenseManager::isActive();
}

function geo_metodo_trial_can_generate(): bool {
    return class_exists('\GeoMetodoSEO\License\LicenseManager')
        && \GeoMetodoSEO\License\LicenseManager::canGenerate();
}

function geo_metodo_license_required_message(): string {
    return 'Licenca obrigatoria. Ative uma licenca ou use apenas o teste de 5 artigos em Gerar Artigo Individual.';
}

add_action('admin_init', function() {
    if (!current_user_can('manage_options')) return;

    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        $action = sanitize_key($_REQUEST['action'] ?? '');
        $is_plugin_ajax = strpos($action, 'geo_') === 0 || strpos($action, 'sara_') === 0;
        if ($is_plugin_ajax && !geo_metodo_license_active()) {
            wp_send_json_error(['message' => geo_metodo_license_required_message()], 403);
        }
        return;
    }

    $page = sanitize_key($_GET['page'] ?? '');
    if (!$page || (strpos($page, 'geo-') !== 0 && strpos($page, 'sara-') !== 0)) return;

    $free_pages = ['geo-settings', 'geo-license', 'geo-individual'];
    if (!geo_metodo_license_active() && !in_array($page, $free_pages, true)) {
        wp_safe_redirect(admin_url('admin.php?page=geo-license&geo_locked=1'));
        exit;
    }
}, 1);

add_action('admin_notices', function() {
    if (!current_user_can('manage_options') || geo_metodo_license_active()) return;
    $page = sanitize_key($_GET['page'] ?? '');
    if (strpos($page, 'geo-') !== 0 && strpos($page, 'sara-') !== 0) return;

    $used = class_exists('\GeoMetodoSEO\License\LicenseManager')
        ? \GeoMetodoSEO\License\LicenseManager::getTrialCount()
        : 0;
    $limit = class_exists('\GeoMetodoSEO\License\LicenseManager')
        ? \GeoMetodoSEO\License\LicenseManager::TRIAL_LIMIT
        : 5;

    echo '<div class="notice notice-warning"><p><strong>GEO Metodo SEO:</strong> sem licenca ativa. ';
    echo 'Configuracoes esta livre e Gerar Artigo Individual permite teste de ' . intval($limit) . ' artigos ';
    echo '(' . intval($used) . '/' . intval($limit) . ' usados). ';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=geo-license')) . '">Adquirir / Ativar Licenca</a></p></div>';
});

// Config
require_once GEO_METODO_SEO_PATH . 'includes/Config/ConfigManager.php';

// ── REGRA DE ORDEM: LogService DEVE ser o primeiro service carregado.
// Todos os outros modules fazem LogService::record/log — se chamado antes
// de ser carregado causa PHP Fatal Error "Class not found".
require_once GEO_METODO_SEO_PATH . 'includes/Services/LogService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Publisher/GeoMetodoSEO_Publisher.php';
require_once GEO_METODO_SEO_PATH . 'includes/Production/ProductionGuard.php';

// AI — providers usam LogService (com class_exists nos providers individuais)
require_once GEO_METODO_SEO_PATH . 'includes/AI/AIResponse.php';
require_once GEO_METODO_SEO_PATH . 'includes/AI/AIProviderInterface.php';
require_once GEO_METODO_SEO_PATH . 'includes/AI/ProviderResolver.php';
require_once GEO_METODO_SEO_PATH . 'includes/AI/Providers/OpenAIProvider.php';
require_once GEO_METODO_SEO_PATH . 'includes/AI/Providers/GroqProvider.php';
require_once GEO_METODO_SEO_PATH . 'includes/AI/Providers/GeminiProvider.php';
require_once GEO_METODO_SEO_PATH . 'includes/AI/Providers/ClaudeProvider.php';
require_once GEO_METODO_SEO_PATH . 'includes/AI/Providers/PerplexityProvider.php';
require_once GEO_METODO_SEO_PATH . 'includes/AI/AIManager.php';

// Services — ordem respeitando dependências
require_once GEO_METODO_SEO_PATH . 'includes/Services/TemplateEngine.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/ContextEngine.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/SingleMasterPromptService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/H2QualityValidator.php';
require_once GEO_METODO_SEO_PATH . 'includes/AI/PromptSizeAnalyzer.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/MarchUpdateGuard.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/ContentFormatter.php';
require_once GEO_METODO_SEO_PATH . 'includes/TitleBank/SeoGeoTitleBank.php';
require_once GEO_METODO_SEO_PATH . 'includes/Glossary/GeoGlossaryExpertAgent.php';
require_once GEO_METODO_SEO_PATH . 'includes/Glossary/GeoGlossaryService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/ReportService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/QualityReportService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/ObservabilityService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/OperationalDashboardService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/AjaxSecurityAuditService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/WebStoryAmpValidator.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/SafeImageSideload.php';

// Imagens — ordem obrigatória:
// 1. ReplicateImageService, FalAI, HuggingFace (dependem de LogService ✅ já carregado)
// 2. LibraryImageService (depende de LogService ✅, NÃO depende de ImageGeneratorService)
// 3. ImageGeneratorService (depende de LibraryImageService ✅)
require_once GEO_METODO_SEO_PATH . 'includes/Services/ReplicateImageService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/ImagePromptGenerator.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/FalAIImageService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/HuggingFaceImageService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/LibraryImageService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/ImageGeneratorService.php';

// Media e conteúdo
require_once GEO_METODO_SEO_PATH . 'includes/Services/GeoMediaMasterService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/ContentUpdater.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/MPCContentGenerator.php';

// Repositories
require_once GEO_METODO_SEO_PATH . 'includes/Repositories/PostRepository.php';
require_once GEO_METODO_SEO_PATH . 'includes/Repositories/ClusterRepository.php';

// Database
require_once GEO_METODO_SEO_PATH . 'includes/Database/DatabaseManager.php';

// SEO
require_once GEO_METODO_SEO_PATH . 'includes/SEO/RankMathIntegration.php';
require_once GEO_METODO_SEO_PATH . 'includes/SEO/InternalLinkingService.php';
require_once GEO_METODO_SEO_PATH . 'includes/SEO/ExternalLinkingService.php';
require_once GEO_METODO_SEO_PATH . 'includes/SEO/LinkOrchestrator.php';
require_once GEO_METODO_SEO_PATH . 'includes/SEO/ClusterEngine.php';

// Pipeline (depois de todas as dependencias)
require_once GEO_METODO_SEO_PATH . 'includes/Services/NagaImageService.php';
require_once GEO_METODO_SEO_PATH . 'includes/AI/Providers/NagaProvider.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/ArticlePipeline.php';

// EEAT
require_once GEO_METODO_SEO_PATH . 'includes/EEAT/EEATEngine.php';

// Helpers
require_once GEO_METODO_SEO_PATH . 'includes/Helpers/SecurityHelper.php';

// v1.0.0 — Demais módulos
require_once GEO_METODO_SEO_PATH . 'includes/Services/SARAService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/YouTubeToArticleService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/YouTubeVideoFinder.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/ZernioSocialService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/SocialPostsGenerator.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/TTSService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Services/WebStoriesService.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/SARAController.php';
require_once GEO_METODO_SEO_PATH . 'includes/SEO/SearchConsoleService.php';



use GeoMetodoSEO\Core\Plugin;
use GeoMetodoSEO\Core\Activator;
use GeoMetodoSEO\Core\Deactivator;


// GEO Glossário SEO
if (class_exists('GeoMetodoSEO\Glossary\GeoGlossaryService')) {
    \GeoMetodoSEO\Glossary\GeoGlossaryService::register_hooks();
}
if (class_exists('GeoMetodoSEO\Admin\GlossaryController')) {
    \GeoMetodoSEO\Admin\GlossaryController::register_hooks();
}
if (class_exists('GeoMetodoSEO\Admin\DiagnosticsController')) {
    \GeoMetodoSEO\Admin\DiagnosticsController::register_hooks();
}
if (class_exists('GeoMetodoSEO\\Services\\OperationalDashboardService')) {
    \GeoMetodoSEO\Services\OperationalDashboardService::register_hooks();
}
if (class_exists('GeoMetodoSEO\\Production\\ProductionGuard')) {
    \GeoMetodoSEO\Production\ProductionGuard::register_hooks();
}

// 1.0.0: Re-Sideloader carregado AQUI (e não no fim do arquivo) para que
// admin_init / wp_ajax_* peguem os hooks na hora certa do boot do plugin.
require_once GEO_METODO_SEO_PATH . 'includes/Tools/ImageReSideloader.php';
require_once GEO_METODO_SEO_PATH . 'includes/Admin/ReSideloaderController.php';
if (class_exists('GeoMetodoSEO\\Tools\\ImageReSideloader')) {
    \GeoMetodoSEO\Tools\ImageReSideloader::register_hooks();
}
if (class_exists('GeoMetodoSEO\TitleBank\SeoGeoTitleBank')) {
    \GeoMetodoSEO\TitleBank\SeoGeoTitleBank::register_cron();
}

// 1.0.0 — March 2026 Core Update: registro dos módulos novos
if (class_exists('GeoMetodoSEO\\Admin\\AuthorProfileFields')) {
    \GeoMetodoSEO\Admin\AuthorProfileFields::register();
}
if (class_exists('GeoMetodoSEO\\Schema\\SpeakableSchemaInjector')) {
    add_action('wp_head', ['\\GeoMetodoSEO\\Schema\\SpeakableSchemaInjector', 'output_schema'], 25);
}

// 1.0.0 — Admin notice quando geração de imagem falhar
// 1.0.0 — Versão profissional: mostra MOTIVO REAL (créditos / API down / configuração) por provider
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;

    // Permite dispensar
    if (isset($_GET['geo_dismiss_image_notices']) && check_admin_referer('geo_dismiss_image')) {
        update_option('geo_image_failure_notices', [], false);
        update_option('geo_image_failure_reasons', [], false);
        wp_safe_redirect(remove_query_arg(['geo_dismiss_image_notices', '_wpnonce']));
        exit;
    }

    $notices = (array) get_option('geo_image_failure_notices', []);
    $reasons = (array) get_option('geo_image_failure_reasons', []);

    if (empty($notices) && empty($reasons)) return;

    // Filtra reasons das últimas 24h
    $cutoff = time() - 86400;
    $reasons_24h = array_filter($reasons, function($r) use ($cutoff) {
        return (int)($r['time'] ?? 0) >= $cutoff;
    });

    // Agrupa por tipo pra mostrar a causa raiz
    $by_type = [];
    foreach ($reasons_24h as $r) {
        $type = $r['type'] ?? 'unknown';
        if (!isset($by_type[$type])) $by_type[$type] = ['count' => 0, 'sample' => $r['message'] ?? ''];
        $by_type[$type]['count']++;
    }

    $dismiss_url = wp_nonce_url(add_query_arg('geo_dismiss_image_notices', '1'), 'geo_dismiss_image');

    // Diagnóstico de causa raiz
    $diagnostics = [
        'naga_no_credits'             => ['icon' => '💳', 'title' => 'Naga.ac SEM CRÉDITOS', 'action' => '<a href="https://naga.ac/dashboard" target="_blank" class="button button-primary">Recarregar Naga.ac</a>'],
        'naga_no_key'                 => ['icon' => '🔑', 'title' => 'Naga.ac NÃO CONFIGURADO', 'action' => 'Configure em <strong>SARA Autopilot → Configurações → API Keys</strong>'],
        'naga_unauthorized'           => ['icon' => '🔑', 'title' => 'Naga.ac CHAVE INVÁLIDA', 'action' => 'API key da Naga foi revogada ou expirou. <a href="https://naga.ac/dashboard" target="_blank">Gerar nova chave</a>'],
        'naga_rate_limit'             => ['icon' => '⏱️', 'title' => 'Naga.ac RATE-LIMIT', 'action' => 'Muitas requests em pouco tempo. Diminua a frequência ou aguarde alguns minutos.'],
        'naga_request_failed'         => ['icon' => '🌐', 'title' => 'Naga.ac INALCANÇÁVEL', 'action' => 'Problema de conexão. Verifique <strong>allow_url_fopen</strong>, firewall ou DNS na hospedagem.'],
        'naga_http_error'             => ['icon' => '⚠️', 'title' => 'Naga.ac ERRO HTTP', 'action' => 'API retornou erro inesperado. Veja o detalhe abaixo.'],
        'naga_sideload_failed'        => ['icon' => '⬇️', 'title' => 'Naga gerou mas SIDELOAD FALHOU', 'action' => 'Imagem gerada mas WordPress não consegue baixar. Verifique <strong>permissões de uploads</strong> e <strong>memory_limit</strong>.'],
        'legacy_image_unreachable'    => ['icon' => '🌐', 'title' => 'URLs legadas INALCANÇÁVEL', 'action' => 'WordPress não consegue alcançar legacy_image.ai. Verifique <strong>allow_url_fopen=On</strong>, firewall, DNS, ou se mod_security bloqueia URLs externas.'],
        'legacy_image_rate_limit'     => ['icon' => '⏱️', 'title' => 'URLs legadas SOBRECARREGADO', 'action' => 'API gratuita está com fila. Tente em alguns minutos.'],
        'legacy_image_server_error'   => ['icon' => '🔧', 'title' => 'URLs legadas FORA DO AR', 'action' => 'Servidor da API caiu temporariamente. Aguarde.'],
        'legacy_image_sideload_failed'=> ['icon' => '⬇️', 'title' => 'URL legada gerou mas SIDELOAD FALHOU', 'action' => 'WordPress não consegue baixar. Verifique <strong>memory_limit</strong>, <strong>upload_max_filesize</strong> e permissões da pasta uploads.'],
        'replicate_no_credits'        => ['icon' => '💳', 'title' => 'Replicate SEM CRÉDITOS', 'action' => '<a href="https://replicate.com/account/billing" target="_blank" class="button button-primary">Recarregar Replicate</a>'],        'inject_failed'               => ['icon' => '❌', 'title' => 'Falha ao inserir imagem em H2', 'action' => 'Veja motivos específicos abaixo (provider que falhou).'],
    ];

    echo '<div class="notice notice-error" style="border-left-color:#d63638;padding:14px 18px;">';
    echo '<h3 style="margin:0 0 10px;color:#d63638;">⚠️ Diagnóstico — Geração de imagens com problema</h3>';

    if (!empty($by_type)) {
        echo '<p><strong>Causas detectadas nas últimas 24h:</strong></p>';
        echo '<table class="widefat" style="background:#fff;margin:8px 0;">';
        echo '<thead><tr><th></th><th>Problema</th><th>Ocorrências</th><th>O que fazer</th></tr></thead><tbody>';
        // Ordena por count desc
        uasort($by_type, function($a, $b) { return $b['count'] - $a['count']; });
        foreach ($by_type as $type => $data) {
            $diag = $diagnostics[$type] ?? ['icon' => '❓', 'title' => $type, 'action' => 'Causa não mapeada — veja log completo abaixo.'];
            echo '<tr>';
            echo '<td style="font-size:20px;">' . $diag['icon'] . '</td>';
            echo '<td><strong>' . esc_html($diag['title']) . '</strong></td>';
            echo '<td><span style="background:#fff7ed;color:#c2410c;padding:2px 8px;border-radius:10px;font-weight:600;">' . (int)$data['count'] . '</span></td>';
            echo '<td>' . wp_kses_post($diag['action']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    if (!empty($notices)) {
        $recent = array_slice($notices, -5);
        echo '<p style="margin-top:14px;"><strong>Últimos artigos afetados:</strong></p><ul style="margin-left:20px;list-style:disc;">';
        foreach ($recent as $n) {
            $time = date('d/m H:i', (int)($n['time'] ?? 0));
            $post_link = $n['post_id']
                ? '<a href="' . esc_url(get_edit_post_link((int)$n['post_id'])) . '">post #' . (int)$n['post_id'] . '</a>'
                : '—';
            echo '<li>' . esc_html($time) . ' — ' . $post_link . ' — keyword: <code>' . esc_html((string)($n['keyword'] ?? '')) . '</code></li>';
        }
        echo '</ul>';
    }

    echo '<p style="margin-top:14px;display:flex;gap:8px;">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=geo-resideload')) . '" class="button button-primary">🖼️ Ferramenta Recuperar Imagens</a>';
    echo '<a href="' . esc_url($dismiss_url) . '" class="button">Dispensar avisos</a>';
    echo '</p>';

    echo '</div>';
});

register_activation_hook(__FILE__, [Activator::class, 'activate']);

// 1.0.0: Trigger do Onboarding Wizard — só pra primeira instalação
register_activation_hook(__FILE__, function() {
    if (get_option('geo_onboarding_completed', '0') !== '1') {
        set_transient('geo_activation_redirect', 1, 30);
    }
});

register_deactivation_hook(__FILE__, [Deactivator::class, 'deactivate']);
register_deactivation_hook(__FILE__, function() {
    if (class_exists('GeoMetodoSEO\\TitleBank\\SeoGeoTitleBank')) {
        \GeoMetodoSEO\TitleBank\SeoGeoTitleBank::unregister_cron();
    }
    // 1.0.0: limpar cron do Re-Sideloader
    if (class_exists('GeoMetodoSEO\\Tools\\ImageReSideloader')) {
        \GeoMetodoSEO\Tools\ImageReSideloader::unregister_cron();
    }
});

// 1.0.0 — Hooks globais para captura de eventos críticos no LogService unificado.

// Capturar erros AJAX wp_send_json_error em handlers geo_*
// (substitui a função, então nossos handlers continuam logando, mas pega tudo)
add_action('admin_init', function() {
    // Log de mudanças de configuração importantes
    if (!empty($_POST['geo_settings_save_nonce']) && current_user_can('manage_options')) {
        if (class_exists('\GeoMetodoSEO\Services\LogService')) {
            \GeoMetodoSEO\Services\LogService::record(
                'settings', 'info', 'Configurações do plugin foram atualizadas',
                ['action' => 'save_settings']
            );
        }
    }
});

// Hook quando a fila do plugin processa um post (sucesso ou falha)
add_action('geo_queue_processed', function($post_id, $status, $message = '') {
    if (!class_exists('\GeoMetodoSEO\Services\LogService')) return;
    $type = ($status === 'completed') ? 'success' : (($status === 'failed') ? 'error' : 'info');
    \GeoMetodoSEO\Services\LogService::record(
        'pipeline', $type,
        $message ?: "Job da fila processado: post #{$post_id} ({$status})",
        ['action' => 'queue_processed', 'post_id' => (int)$post_id, 'context' => ['status' => $status]]
    );
}, 10, 3);

// Intervalo de 5 minutos para o agendamento automatico
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['geo_five_minutes'])) {
        $schedules['geo_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'A cada 5 minutos (GEO Metodo SEO)',
        ];
    }
    return $schedules;
});

function geo_metodo_acquire_lock(string $key, int $ttl): bool {
    $option = '_geo_lock_' . sanitize_key($key);
    $now = time();
    $expires = (int) get_option($option, 0);
    if ($expires > $now) {
        return false;
    }
    if (add_option($option, $now + $ttl, '', 'no')) {
        return true;
    }
    $expires = (int) get_option($option, 0);
    if ($expires <= $now) {
        update_option($option, $now + $ttl, false);
        return true;
    }
    return false;
}

function geo_metodo_release_lock(string $key): void {
    delete_option('_geo_lock_' . sanitize_key($key));
}


if (!function_exists('geo_cluster_provider_candidates')) {
    /**
     * MODO ESTRITO DO CLUSTER:
     * O Cluster NÃO usa fallback de provider. Ele usa somente o provider/modelo escolhido
     * pelo usuário para pilar/satélites. Se falhar, retorna erro limpo.
     * Mantido apenas por compatibilidade com versões anteriores.
     */
    function geo_cluster_provider_candidates(string $primary = ''): array {
        $primary = \GeoMetodoSEO\AI\ProviderResolver::for('cluster_generation', sanitize_key((string)$primary));
        return $primary ? [$primary] : [];
    }
}

if (!function_exists('geo_cluster_process_article_with_recovery')) {
    /**
     * Gera um artigo do Cluster em MODO ESTRITO.
     * Não troca para outro provider. Não faz fallback. Não consome outro crédito.
     * Retorna post_id em sucesso ou WP_Error em falha.
     */
    function geo_cluster_process_article_with_recovery(array $job) {
        $keyword = sanitize_text_field((string)($job['keyword'] ?? ''));
        if ($keyword === '') {
            return new WP_Error('geo_cluster_empty_keyword', 'Keyword vazia no job do Cluster.');
        }

        $selected_provider = sanitize_key((string)($job['provider'] ?? ''));
        $provider = \GeoMetodoSEO\AI\ProviderResolver::for('cluster_generation', $selected_provider);
        if (!$provider) {
            return new WP_Error('geo_cluster_no_provider', 'Nenhum provider escolhido/configurado para o Cluster.');
        }
        if (!\GeoMetodoSEO\AI\ProviderResolver::isConfigured($provider)) {
            return new WP_Error('geo_cluster_provider_not_configured', 'Provider escolhido para o Cluster sem API key configurada: ' . $provider);
        }
        if (class_exists('GeoMetodoSEO\\License\\LicenseManager') && !\GeoMetodoSEO\License\LicenseManager::isProviderAllowed($provider)) {
            return new WP_Error('geo_cluster_provider_not_allowed', 'Provider escolhido não permitido pela licença/plano: ' . $provider);
        }

        $language = sanitize_text_field((string)($job['language'] ?? 'pt-BR'));
        $role = sanitize_key((string)($job['role'] ?? ''));
        $post_status = ($role === 'pilar' || !empty($job['is_first'])) ? 'publish' : sanitize_text_field((string)($job['post_status'] ?? 'future'));
        if (!in_array($post_status, ['draft', 'publish', 'pending', 'future'], true)) $post_status = 'draft';
        $category = sanitize_text_field((string)($job['category'] ?? 'auto'));
        $article_size = in_array(($job['article_size'] ?? ''), ['small','medium','large','cluster_satellite','cluster_pillar'], true) ? $job['article_size'] : 'medium';
        $model = !empty($job['model']) ? sanitize_text_field((string)$job['model']) : \GeoMetodoSEO\AI\ProviderResolver::modelFor('cluster_generation', $provider);

        if (class_exists('GeoMetodoSEO\\Services\\LogService')) {
            \GeoMetodoSEO\Services\LogService::record('cluster', 'info', 'Cluster: gerando "' . $keyword . '" via provider escolhido: ' . $provider, [
                'action' => 'cluster_strict_provider_start',
                'context' => ['provider' => $provider, 'model' => $model, 'role' => $role, 'keyword' => $keyword, 'article_size' => $article_size],
            ]);
        }

        try {
            @set_time_limit(180);
            $pipeline = new \GeoMetodoSEO\Services\ArticlePipeline($provider, $model ?: null, $job['template_id'] ?? null);
            if (method_exists($pipeline, 'set_embed_youtube_video')) {
                $pipeline->set_embed_youtube_video(!empty($job['embed_video']));
            }
            if (!empty($job['image_source']) && class_exists('\\GeoMetodoSEO\\Services\\LibraryImageService')) {
                \GeoMetodoSEO\Services\LibraryImageService::set_mode_override(sanitize_key($job['image_source']));
            }
            $scheduled_at = '';
            if ($post_status === 'future' && !empty($job['scheduled_at'])) {
                // Aceita tanto timestamp da fila quanto string do formulário (ex: 2026-05-28T09:30).
                // Antes, strings eram convertidas para int e viravam timestamp inválido (ex: 2026 segundos após 1970).
                if (is_numeric($job['scheduled_at'])) {
                    $scheduled_at = wp_date('Y-m-d\TH:i', (int)$job['scheduled_at'], new DateTimeZone('America/Sao_Paulo'));
                } else {
                    $scheduled_at = sanitize_text_field((string)$job['scheduled_at']);
                }
            }

            $post_id = $pipeline->process(
                $keyword,
                $language,
                $post_status,
                $scheduled_at,
                $job['tone'] ?? '',
                $category,
                $article_size
            );

            if ($post_id && !is_wp_error($post_id)) {
                if (class_exists('GeoMetodoSEO\\Services\\LogService')) {
                    \GeoMetodoSEO\Services\LogService::record('cluster', 'success', 'Cluster: artigo gerado via provider escolhido ' . $provider . ' (post #' . (int)$post_id . ')', [
                        'action' => 'cluster_article_generated_strict',
                        'post_id' => (int)$post_id,
                        'context' => ['provider' => $provider, 'model' => $model, 'keyword' => $keyword, 'role' => $role],
                    ]);
                }
                return (int)$post_id;
            }

            $message = is_wp_error($post_id) ? $post_id->get_error_message() : 'ArticlePipeline retornou vazio/falso.';
            if (class_exists('GeoMetodoSEO\\Services\\LogService')) {
                \GeoMetodoSEO\Services\LogService::record('cluster', 'error', 'Cluster: provider escolhido falhou — ' . $provider . ': ' . $message, [
                    'action' => 'cluster_selected_provider_failed',
                    'context' => ['provider' => $provider, 'model' => $model, 'keyword' => $keyword, 'error' => $message],
                ]);
            }
            return new WP_Error('geo_cluster_selected_provider_failed', 'Cluster falhou no provider escolhido (' . $provider . '): ' . $message);
        } catch (\Throwable $e) {
            if (class_exists('GeoMetodoSEO\\Services\\LogService')) {
                \GeoMetodoSEO\Services\LogService::record('cluster', 'error', 'Cluster: erro fatal/exception no provider escolhido ' . $provider . ': ' . $e->getMessage(), [
                    'action' => 'cluster_selected_provider_exception',
                    'context' => ['provider' => $provider, 'model' => $model, 'keyword' => $keyword, 'file' => basename($e->getFile()) . ':' . $e->getLine()],
                ]);
            }
            return new WP_Error('geo_cluster_selected_provider_exception', 'Cluster falhou no provider escolhido (' . $provider . '): ' . $e->getMessage());
        }
    }
}

// Worker do agendamento automatico
add_action('geo_auto_schedule_event', function() {
    if (!geo_metodo_license_active()) return;
    if (!geo_metodo_acquire_lock('schedule_worker', 5 * MINUTE_IN_SECONDS)) return;

    $queue = get_option('geo_scheduled_queue', []);
    if (empty($queue)) {
        geo_metodo_release_lock('schedule_worker');
        return;
    }

    $now     = time();
    $changed = false;

    foreach ($queue as &$job) {
        if ($job['status'] !== 'pending') continue;
        if (($job['scheduled_at'] ?? PHP_INT_MAX) > $now) continue;

        $job['status'] = 'processing';
        $job['started_at'] = time();
        $job['updated_at'] = time();
        $job['last_error'] = '';
        update_option('geo_scheduled_queue', $queue);

        @set_time_limit(240);

        try {
            if (($job['type'] ?? '') === 'cluster') {
                // Jobs do Cluster só são processados quando chegam no horário;
                // neste momento devem publicar agora, não criar post_status=future vencido.
                $job['post_status'] = 'publish';
                $post_id = geo_cluster_process_article_with_recovery($job);
            } else {
                $pipeline = new \GeoMetodoSEO\Services\ArticlePipeline(
                    \GeoMetodoSEO\AI\ProviderResolver::for('bulk_generation', $job['provider'] ?? ''),
                    $job['model']    ?? null,
                    $job['template_id'] ?? null
                );
                if (method_exists($pipeline, 'set_embed_youtube_video')) {
                    $pipeline->set_embed_youtube_video(!empty($job['embed_video']));
                }
                if (!empty($job['image_source']) && class_exists('\\GeoMetodoSEO\\Services\\LibraryImageService')) {
                    \GeoMetodoSEO\Services\LibraryImageService::set_mode_override(sanitize_key($job['image_source']));
                }
                $post_status = $job['post_status'] ?? 'publish';
                $category    = $job['category']    ?? 'auto';
                $article_size = in_array(($job['article_size'] ?? ''), ['small','medium','large','cluster_satellite','cluster_pillar'], true) ? $job['article_size'] : get_option('geo_article_size', 'large');

                $post_id = $pipeline->process(
                    $job['keyword'],
                    $job['language'] ?? 'pt-BR',
                    $post_status,
                    '',
                    $job['tone'] ?? '',
                    $category,
                    $article_size
                );
            }

            $job['status']  = ($post_id && !is_wp_error($post_id)) ? 'done' : 'failed';
            $job['post_id'] = ($post_id && !is_wp_error($post_id)) ? $post_id : null;
            $job['done_at'] = time();
            $job['updated_at'] = time();
            if ($job['status'] === 'failed') {
                $job['last_error'] = is_wp_error($post_id) ? $post_id->get_error_message() : 'ArticlePipeline retornou vazio/falso.';
            }
            if ($job['status'] === 'done' && class_exists('GeoMetodoSEO\\TitleBank\\SeoGeoTitleBank')) {
                \GeoMetodoSEO\TitleBank\SeoGeoTitleBank::mark_used_by_title((string)$job['keyword'], (($job['type'] ?? '') === 'cluster' ? 'cluster_cron' : 'bulk_cron'), (int)$post_id);
            }
        } catch (\Throwable $e) {
            $job['status'] = 'failed';
            $job['done_at'] = time();
            $job['updated_at'] = time();
            $job['last_error'] = $e->getMessage();
            \GeoMetodoSEO\Services\LogService::log('error', 'Worker sequencial: ' . $e->getMessage());
        }

        $changed = true;
        break; // Processa 1 artigo por execucao para evitar timeout
    }
    unset($job);

    if ($changed) {
        update_option('geo_scheduled_queue', $queue);
    }

    geo_metodo_release_lock('schedule_worker');
});

if (!wp_next_scheduled('geo_auto_schedule_event')) {
    wp_schedule_event(time() + 60, 'geo_five_minutes', 'geo_auto_schedule_event');
}

// AJAX: status da fila em tempo real
add_action('wp_ajax_geo_queue_status', function() {
    check_ajax_referer('geo_queue_status_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permissao negada');
    }
    $controller = new \GeoMetodoSEO\Admin\BulkGeneratorController();
    $controller->ajax_queue_status();
});

// AJAX: processar artigo individual (usado por Geracao em Massa e Cluster)
add_action('wp_ajax_geo_process_single_article', function() {
    check_ajax_referer('geo_process_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permissao negada');
    }
    set_time_limit(300);

    $keyword      = sanitize_text_field($_POST['keyword']      ?? '');
    $raw_provider  = sanitize_text_field($_POST['provider'] ?? '');
    $is_cluster_ajax = !empty($_POST['is_cluster']) || (sanitize_text_field($_POST['source'] ?? '') === 'cluster');
    $provider     = \GeoMetodoSEO\AI\ProviderResolver::for($is_cluster_ajax ? 'cluster_generation' : 'article_generation', $raw_provider);
    $language     = sanitize_text_field($_POST['language']     ?? 'pt-BR');
    $post_status  = sanitize_text_field($_POST['post_status']  ?? 'draft');
    $scheduled_at = sanitize_text_field($_POST['scheduled_at'] ?? '');
    $model        = sanitize_text_field($_POST['model']        ?? '');
    $category_id  = sanitize_text_field($_POST['category_id']  ?? 'auto');

    // Seletor de modo de imagem por geração (sobrepõe a opção global).
    // 'ai' = gerar com IA | 'library' = usar biblioteca | '' = usar config global
    $image_source = sanitize_key($_POST['image_source'] ?? '');
    if (in_array($image_source, ['ai', 'library'], true)
        && class_exists('\\GeoMetodoSEO\\Services\\LibraryImageService')) {
        \GeoMetodoSEO\Services\LibraryImageService::set_mode_override($image_source);
    }
    $art_size     = in_array($_POST['article_size'] ?? '', ['small','medium','large','cluster_satellite','cluster_pillar'], true) ? sanitize_text_field($_POST['article_size']) : ($is_cluster_ajax ? 'medium' : get_option('geo_article_size', 'large'));
    if ($is_cluster_ajax && sanitize_key($_POST['cluster_role'] ?? '') === 'satelite') {
        $art_size = 'cluster_satellite';
    }
    if ($is_cluster_ajax && sanitize_key($_POST['cluster_role'] ?? '') === 'pilar') {
        $art_size = 'cluster_pillar';
    }

    if (empty($keyword)) {
        wp_send_json_error(['message' => 'Keyword vazia']);
    }

    // Resolver categoria: no Cluster, passar o ID numérico diretamente
    // para evitar que o PostRepository crie categoria nova por mismatch de nome.
    // Para artigos individuais, manter compatibilidade com nome.
    $resolved_cat = 'auto';
    if (!empty($category_id) && $category_id !== 'auto' && $category_id !== '_new_') {
        if (is_numeric($category_id)) {
            if ($is_cluster_ajax) {
                // Cluster: passar ID direto — PostRepository aceita numérico e busca o term correto
                $resolved_cat = $category_id; // ID numérico como string, ex: "4"
            } else {
                // Individual: converter para nome (comportamento atual)
                $term = get_term((int) $category_id, 'category');
                if ($term && !is_wp_error($term)) {
                    $resolved_cat = $term->name;
                }
            }
        } else {
            $resolved_cat = $category_id;
        }
    }

    if ($is_cluster_ajax) {
        $post_id = geo_cluster_process_article_with_recovery([
            'keyword'      => $keyword,
            'provider'     => $provider,
            'language'     => $language,
            'post_status'  => $post_status,
            'category'     => $resolved_cat,
            'article_size' => $art_size ?? 'medium',
            'scheduled_at' => $scheduled_at,
            'model'        => $model,
            'role'         => sanitize_text_field($_POST['cluster_role'] ?? ''),
            'is_first'     => sanitize_text_field($_POST['cluster_role'] ?? '') === 'pilar',
        ]);
    } else {
        $pipeline = new \GeoMetodoSEO\Services\ArticlePipeline($provider, $model ?: null);
        $pipeline->set_embed_youtube_video(($_POST['embed_video'] ?? '0') === '1');
        $post_id  = $pipeline->process($keyword, $language, $post_status, $scheduled_at, '', $resolved_cat, $art_size ?? 'large');
    }

    if ($post_id && !is_wp_error($post_id)) {
        if (class_exists('GeoMetodoSEO\TitleBank\SeoGeoTitleBank')) {
            \GeoMetodoSEO\TitleBank\SeoGeoTitleBank::mark_used_by_title($keyword, 'bulk_or_ajax', (int)$post_id);
        }
        wp_send_json_success([
            'post_id'  => $post_id,
            'title'    => get_the_title($post_id),
            'edit_url' => get_edit_post_link($post_id),
            'view_url' => get_permalink($post_id),
        ]);
    } else {
        $msg = is_wp_error($post_id) ? $post_id->get_error_message() : ('Pipeline falhou para: ' . $keyword);
        wp_send_json_error(['message' => $msg]);
    }
});

// AJAX: salvar registro de cluster
add_action('wp_ajax_geo_save_cluster', function() {
    check_ajax_referer('geo_process_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permissao negada');
    }

    $pillar_id     = intval($_POST['pillar_id'] ?? 0);
    $satellite_ids = array_map('intval', json_decode(stripslashes($_POST['satellite_ids'] ?? '[]'), true) ?: []);
    $keyword       = sanitize_text_field($_POST['keyword'] ?? '');

    if (!$pillar_id) {
        \GeoMetodoSEO\Services\LogService::record('cluster', 'error', 'Tentativa de salvar cluster sem pilar', ['action' => 'save_cluster']);
        wp_send_json_error(['message' => 'Artigo pilar nao definido']);
    }

    $repo       = new \GeoMetodoSEO\Repositories\ClusterRepository();
    $cluster_id = $repo->create($pillar_id, $satellite_ids, $keyword);

    // Linkagem cruzada do cluster: pilar ↔ satélites e satélites ↔ satélites.
    // Sem isso, os artigos ficavam isolados (a relação existia só na tabela, não no HTML).
    geo_interlink_cluster($pillar_id, $satellite_ids);

    \GeoMetodoSEO\Services\LogService::record(
        'cluster', 'success',
        "Cluster #{$cluster_id} criado: pilar #{$pillar_id} + " . count($satellite_ids) . " satélites (com interlinking)",
        ['action' => 'save_cluster', 'post_id' => $pillar_id, 'context' => ['cluster_id' => $cluster_id, 'keyword' => $keyword]]
    );

    wp_send_json_success(['cluster_id' => $cluster_id]);
});

/**
 * Insere linkagem interna cruzada num cluster:
 *  - O pilar ganha uma seção "Artigos do cluster" linkando todos os satélites.
 *  - Cada satélite linka para o pilar (no início) e para os outros satélites (no fim).
 */
if (!function_exists('geo_interlink_cluster')) {
    function geo_interlink_cluster(int $pillar_id, array $satellite_ids): void {
        $satellite_ids = array_values(array_filter(array_map('intval', $satellite_ids)));
        if ($pillar_id <= 0 || empty($satellite_ids)) return;

        $pillar_title = get_the_title($pillar_id);
        $pillar_url   = get_permalink($pillar_id);

        // 1) PILAR: adicionar bloco com todos os satélites
        if ($pillar_url) {
            $items = '';
            foreach ($satellite_ids as $sid) {
                $surl = get_permalink($sid);
                $stit = get_the_title($sid);
                if ($surl && $stit) {
                    $items .= '<li><a href="' . esc_url($surl) . '">' . esc_html($stit) . '</a></li>';
                }
            }
            if ($items !== '') {
                $pillar_post = get_post($pillar_id);
                $block = "\n<!-- geo-cluster-links -->\n<div class=\"geo-cluster-links\" style=\"margin:32px 0;padding:24px;background:transparent;border:1px solid #e2e8f0;border-radius:12px;\">\n"
                    . "<h2 style=\"margin-top:0;\">Explore o cluster completo</h2>\n"
                    . "<p>Aprofunde cada tópico nos artigos relacionados deste tema:</p>\n"
                    . "<ul style=\"line-height:1.9;\">" . $items . "</ul>\n</div>\n";
                if ($pillar_post && strpos($pillar_post->post_content, 'geo-cluster-links') === false) {
                    \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                        'ID' => $pillar_id,
                        'post_content' => $pillar_post->post_content . $block,
                    ], false, true, 'cluster_interlink_pillar');
                }
            }
        }

        // 2) SATÉLITES: cada um linka para o pilar + para os outros satélites
        foreach ($satellite_ids as $sid) {
            $spost = get_post($sid);
            if (!$spost) continue;
            $content = $spost->post_content;
            if (strpos($content, 'geo-cluster-links') !== false) continue; // já linkado

            // Link para o pilar (contextual, no topo do conteúdo)
            $pillar_link = '';
            if ($pillar_url && $pillar_title) {
                $pillar_link = "\n<p class=\"geo-cluster-pillar-link\">📌 Este artigo faz parte do guia completo sobre <a href=\""
                    . esc_url($pillar_url) . "\"><strong>" . esc_html($pillar_title) . "</strong></a>.</p>\n";
            }

            // Links para os outros satélites (no fim)
            $others = '';
            foreach ($satellite_ids as $oid) {
                if ($oid === $sid) continue;
                $ourl = get_permalink($oid);
                $otit = get_the_title($oid);
                if ($ourl && $otit) {
                    $others .= '<li><a href="' . esc_url($ourl) . '">' . esc_html($otit) . '</a></li>';
                }
            }
            $others_block = '';
            if ($others !== '') {
                $others_block = "\n<!-- geo-cluster-links -->\n<div class=\"geo-cluster-links\" style=\"margin:32px 0;padding:24px;background:transparent;border:1px solid #e2e8f0;border-radius:12px;\">\n"
                    . "<h2 style=\"margin-top:0;\">Continue aprendendo</h2>\n"
                    . "<ul style=\"line-height:1.9;\">" . $others . "</ul>\n</div>\n";
            }

            // Inserir link do pilar após o 1º parágrafo, e os outros no fim
            $new_content = $content;
            if ($pillar_link && strpos($content, 'geo-cluster-pillar-link') === false) {
                if (preg_match('/<\/p>/i', $new_content)) {
                    $new_content = preg_replace('/(<\/p>)/i', '$1' . $pillar_link, $new_content, 1);
                } else {
                    $new_content = $pillar_link . $new_content;
                }
            }
            $new_content .= $others_block;

            if ($new_content !== $content) {
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                    'ID' => $sid,
                    'post_content' => $new_content,
                ], false, true, 'cluster_interlink_satellite');
            }
        }

        \GeoMetodoSEO\Services\LogService::record('cluster', 'success',
            'Interlinking do cluster aplicado: pilar #' . $pillar_id . ' ↔ ' . count($satellite_ids) . ' satélites',
            ['action' => 'cluster_interlink_done', 'post_id' => $pillar_id]);
    }
}

// ── ContentUpdater: reescrita automática diária ───────────────────────────
add_action('geo_daily_rewrite_event', function() {
    $updater = new \GeoMetodoSEO\Services\ContentUpdater();
    $updater->update_old_posts();
});

if (!wp_next_scheduled('geo_daily_rewrite_event')) {
    wp_schedule_event(time(), 'daily', 'geo_daily_rewrite_event');
}

// ── AJAX: Melhorar artigo existente (reescrita parcial) ───────────────────
add_action('wp_ajax_geo_improve_article', function() {
    check_ajax_referer('geo_improve_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permissao negada');
    }

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => 'post_id inválido']);
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(['message' => 'Post não encontrado']);
    }

    $updater = new \GeoMetodoSEO\Services\ContentUpdater();
    $result  = $updater->rewrite_post($post);

    if ($result) {
        wp_send_json_success([
            'message'  => 'Artigo melhorado com sucesso!',
            'edit_url' => get_edit_post_link($post_id),
        ]);
    } else {
        wp_send_json_error(['message' => 'Falha ao melhorar o artigo. Verifique os logs.']);
    }
});

// ============================================================================
// AJAX: Ferramentas de Análise de Keyword (IndividualGeneratorController)
// ============================================================================

/**
 * Helper: chama a IA e retorna texto ou false.
 */
function geo_ai_call($prompt, $provider = null) {
    $provider = \GeoMetodoSEO\AI\ProviderResolver::for('article_generation', $provider);
    $ai       = new \GeoMetodoSEO\AI\AIManager();
    $response = $ai->generateText($prompt, $provider);
    if ($response->hasError()) return false;
    return $response->getContent();
}

/**
 * Helper: verifica nonce e permissão — encerra com erro se falhar.
 */
function geo_check_analysis_request() {
    check_ajax_referer('geo_analysis_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permissao negada');
    }
    $kw = sanitize_text_field($_POST['keyword'] ?? '');
    if (empty($kw)) {
        wp_send_json_error(['message' => 'Keyword vazia']);
    }
    return $kw;
}

// 1. Verificar Canibalização
add_action('wp_ajax_geo_check_cannibalization', function() {
    check_ajax_referer('geo_analysis_nonce', 'nonce');
    $keyword = geo_check_analysis_request();
    global $wpdb;
    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_status, p.post_date
           FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
          WHERE pm.meta_key = '_geo_keyword'
            AND pm.meta_value LIKE %s
            AND p.post_status != 'trash'
          ORDER BY p.post_date DESC",
        '%' . $wpdb->esc_like($keyword) . '%'
    ));

    if (empty($posts)) {
        $html = '<div style="padding:14px;background:#edfaed;border-left:4px solid #46b450;border-radius:4px;">'
              . '✅ <strong>Sem canibalização detectada</strong> — Nenhum artigo encontrado com a keyword '
              . '"<em>' . esc_html($keyword) . '</em>".</div>';
    } else {
        $html = '<div style="padding:14px;background:#fff8e5;border-left:4px solid #ffb900;border-radius:4px;">'
              . '⚠️ <strong>' . count($posts) . ' artigo(s) com keyword similar:</strong>'
              . '<ul style="margin:10px 0 0 16px;">';
        foreach ($posts as $p) {
            $html .= '<li style="margin-bottom:4px;"><a href="' . esc_url(get_edit_post_link($p->ID)) . '" target="_blank">'
                   . esc_html($p->post_title) . '</a>'
                   . ' <span style="color:#888;font-size:12px;">(' . esc_html($p->post_status)
                   . ' — ' . esc_html(date('d/m/Y', strtotime($p->post_date))) . ')</span></li>';
        }
        $html .= '</ul></div>';
    }
    wp_send_json_success(['title' => '⚠️ Verificacao de Canibalizacao', 'html' => $html]);
});

// 2. Analisar Intenção
add_action('wp_ajax_geo_analyze_intent', function() {
    $keyword  = geo_check_analysis_request();
    $provider = sanitize_text_field($_POST['provider'] ?? '');

    $prompt = 'Analise a intencao de busca da keyword: "' . $keyword . '".' . "\n"
            . 'Responda SOMENTE em JSON valido com estes campos:'  . "\n"
            . '{"intent":"informational","confidence":85,"description":"explicacao em portugues",'
            . '"micro_intent":"objetivo especifico do usuario","content_format":"formato de conteudo recomendado"}' . "\n"
            . 'Valores validos para intent: informational, commercial, transactional, navigational.';

    $text = geo_ai_call($prompt, $provider);
    if (!$text) wp_send_json_error(['message' => 'Erro na API de IA. Verifique a API Key.']);

    $data = \GeoMetodoSEO\Services\ContentFormatter::extractJson($text);

    $intent_map = [
        'informational'  => ['label' => 'Informacional',  'color' => '#2196F3'],
        'commercial'     => ['label' => 'Comercial',      'color' => '#FF9800'],
        'transactional'  => ['label' => 'Transacional',   'color' => '#4CAF50'],
        'navigational'   => ['label' => 'Navegacional',   'color' => '#9C27B0'],
    ];
    $intent = $data['intent'] ?? 'informational';
    $color  = $intent_map[$intent]['color'] ?? '#666';
    $label  = $intent_map[$intent]['label'] ?? ucfirst($intent);

    $html = '<div style="padding:16px;background:#f9f9f9;border-radius:6px;">'
          . '<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">'
          . '<span style="background:' . $color . ';color:#fff;padding:5px 16px;border-radius:20px;font-weight:700;font-size:15px;">' . esc_html($label) . '</span>'
          . '<span style="color:#666;font-size:13px;">Confianca: <strong>' . intval($data['confidence'] ?? 0) . '%</strong></span>'
          . '</div>'
          . '<p style="margin:6px 0;"><strong>Descricao:</strong> ' . esc_html($data['description'] ?? '') . '</p>'
          . '<p style="margin:6px 0;"><strong>Objetivo do usuario:</strong> ' . esc_html($data['micro_intent'] ?? '') . '</p>'
          . '<p style="margin:6px 0;"><strong>Formato recomendado:</strong> ' . esc_html($data['content_format'] ?? '') . '</p>'
          . '</div>';

    wp_send_json_success(['title' => '🎯 Intencao de Busca: ' . $keyword, 'html' => $html]);
});

// 3. Sugerir Headings
add_action('wp_ajax_geo_suggest_headings', function() {
    $keyword  = geo_check_analysis_request();
    $provider = sanitize_text_field($_POST['provider'] ?? '');

    $prompt = 'Gere uma estrutura de headings H2/H3 para um artigo sobre "' . $keyword . '" em portugues brasileiro.' . "\n"
            . 'Responda SOMENTE em JSON: {"headings":[{"level":"h2","text":"..."},{"level":"h3","text":"..."}]}' . "\n"
            . 'Gere 6 a 8 H2 com 2 a 3 H3 cada um.';

    $text = geo_ai_call($prompt, $provider);
    if (!$text) wp_send_json_error(['message' => 'Erro na API de IA.']);

    $data = \GeoMetodoSEO\Services\ContentFormatter::extractJson($text);
    $headings = $data['headings'] ?? [];

    $html = '<div style="font-family:monospace;font-size:13px;background:#f6f7f7;padding:16px;border-radius:6px;line-height:1.8;">';
    foreach ($headings as $h) {
        $level = $h['level'] ?? 'h2';
        $text2 = esc_html($h['text'] ?? '');
        if ($level === 'h2') {
            $html .= '<div style="color:#0073aa;font-weight:700;">## ' . $text2 . '</div>';
        } else {
            $html .= '<div style="color:#444;padding-left:20px;">### ' . $text2 . '</div>';
        }
    }
    if (empty($headings)) {
        $html .= '<p style="color:#666;">' . esc_html($text) . '</p>';
    }
    $html .= '</div>';

    wp_send_json_success(['title' => '📑 Estrutura de Headings: ' . $keyword, 'html' => $html]);
});

// 4. AI Context Engine
add_action('wp_ajax_geo_context_engine', function() {
    $keyword  = geo_check_analysis_request();
    $provider = sanitize_text_field($_POST['provider'] ?? '');

    $prompt = 'Analise o contexto semantico da keyword "' . $keyword . '" em portugues.' . "\n"
            . 'Responda SOMENTE em JSON: {"main_topic":"...","semantic_field":"...","related_entities":["..."],'
            . '"lsi_terms":["..."],"user_questions":["..."]}' . "\n"
            . 'Forneça ao menos 8 entidades, 10 termos LSI e 5 perguntas.';

    $text = geo_ai_call($prompt, $provider);
    if (!$text) wp_send_json_error(['message' => 'Erro na API de IA.']);

    $data = \GeoMetodoSEO\Services\ContentFormatter::extractJson($text);

    $tag_style = 'display:inline-block;background:#e8f4fd;color:#0073aa;padding:3px 10px;border-radius:12px;font-size:12px;margin:3px;';
    $lsi_style = 'display:inline-block;background:#f0faf0;color:#2e7d32;padding:3px 10px;border-radius:12px;font-size:12px;margin:3px;';

    $html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';

    $html .= '<div style="background:#f9f9f9;padding:14px;border-radius:6px;">'
           . '<p style="font-weight:600;margin:0 0 8px;font-size:13px;">🏷️ Entidades Relacionadas</p>';
    foreach ((array)($data['related_entities'] ?? []) as $e) {
        $html .= '<span style="' . $tag_style . '">' . esc_html($e) . '</span>';
    }
    $html .= '</div>';

    $html .= '<div style="background:#f9f9f9;padding:14px;border-radius:6px;">'
           . '<p style="font-weight:600;margin:0 0 8px;font-size:13px;">🔗 Termos LSI</p>';
    foreach ((array)($data['lsi_terms'] ?? []) as $t) {
        $html .= '<span style="' . $lsi_style . '">' . esc_html($t) . '</span>';
    }
    $html .= '</div>';

    $html .= '<div style="background:#f9f9f9;padding:14px;border-radius:6px;grid-column:1/-1;">'
           . '<p style="font-weight:600;margin:0 0 8px;font-size:13px;">❓ Perguntas dos Usuarios</p><ol style="margin:0;padding-left:18px;font-size:13px;">';
    foreach ((array)($data['user_questions'] ?? []) as $q) {
        $html .= '<li style="margin-bottom:4px;">' . esc_html($q) . '</li>';
    }
    $html .= '</ol></div>';

    if (!empty($data['main_topic']) || !empty($data['semantic_field'])) {
        $html .= '<div style="background:#fff3e0;padding:14px;border-radius:6px;grid-column:1/-1;font-size:13px;">'
               . '<strong>Topico principal:</strong> ' . esc_html($data['main_topic'] ?? '') . '&nbsp;&nbsp;'
               . '<strong>Campo semantico:</strong> ' . esc_html($data['semantic_field'] ?? '') . '</div>';
    }

    $html .= '</div>';

    wp_send_json_success(['title' => '🧠 Contexto Semantico: ' . $keyword, 'html' => $html]);
});

// 5. Mapa de Conteúdo
add_action('wp_ajax_geo_content_map', function() {
    $keyword  = geo_check_analysis_request();
    $provider = sanitize_text_field($_POST['provider'] ?? '');

    $prompt = 'Crie um mapa de conteudo detalhado para um artigo sobre "' . $keyword . '" em portugues.' . "\n"
            . 'Responda SOMENTE em JSON: {"sections":[{"title":"...","type":"intro|main|comparison|guide|faq|conclusion",'
            . '"target_words":300,"key_points":["...","..."]}]}' . "\n"
            . 'Gere 7 a 9 secoes.';

    $text = geo_ai_call($prompt, $provider);
    if (!$text) wp_send_json_error(['message' => 'Erro na API de IA.']);

    $data     = \GeoMetodoSEO\Services\ContentFormatter::extractJson($text);
    $sections = $data['sections'] ?? [];

    $type_colors = [
        'intro'      => '#2196F3',
        'main'       => '#0073aa',
        'comparison' => '#9C27B0',
        'guide'      => '#4CAF50',
        'faq'        => '#FF9800',
        'conclusion' => '#607D8B',
    ];

    $html  = '<div style="display:flex;flex-direction:column;gap:10px;">';
    $total = 0;
    foreach ($sections as $i => $sec) {
        $type    = $sec['type'] ?? 'main';
        $color   = $type_colors[$type] ?? '#0073aa';
        $words   = intval($sec['target_words'] ?? 200);
        $total  += $words;
        $points  = (array)($sec['key_points'] ?? []);

        $html .= '<div style="border-left:4px solid ' . $color . ';padding:10px 14px;background:#f9f9f9;border-radius:0 6px 6px 0;">'
               . '<div style="display:flex;justify-content:space-between;align-items:center;">'
               . '<strong style="font-size:13px;">' . ($i + 1) . '. ' . esc_html($sec['title'] ?? '') . '</strong>'
               . '<span style="font-size:11px;background:' . $color . ';color:#fff;padding:2px 8px;border-radius:10px;">'
               . esc_html(ucfirst($type)) . ' · ~' . $words . ' palavras</span>'
               . '</div>';
        if (!empty($points)) {
            $html .= '<ul style="margin:6px 0 0 16px;font-size:12px;color:#555;">';
            foreach ($points as $pt) {
                $html .= '<li>' . esc_html($pt) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';
    }
    if (empty($sections)) {
        $html .= '<p style="color:#666;">' . esc_html($text) . '</p>';
    }
    $html .= '<div style="text-align:right;font-size:12px;color:#888;margin-top:4px;">Total estimado: ~' . $total . ' palavras</div>';
    $html .= '</div>';

    wp_send_json_success(['title' => '🗺️ Mapa de Conteudo: ' . $keyword, 'html' => $html]);
});

// 6. People Also Ask
add_action('wp_ajax_geo_people_also_ask', function() {
    $keyword  = geo_check_analysis_request();
    $provider = sanitize_text_field($_POST['provider'] ?? '');

    $prompt = 'Gere 8 perguntas "People Also Ask" relacionadas a keyword "' . $keyword . '" em portugues brasileiro.' . "\n"
            . 'Responda SOMENTE em JSON: {"questions":[{"question":"...","brief_answer":"..."}]}';

    $text = geo_ai_call($prompt, $provider);
    if (!$text) wp_send_json_error(['message' => 'Erro na API de IA.']);

    $data      = \GeoMetodoSEO\Services\ContentFormatter::extractJson($text);
    $questions = $data['questions'] ?? [];

    $html = '<div style="display:flex;flex-direction:column;gap:8px;">';
    foreach ($questions as $i => $q) {
        $html .= '<details style="background:#f9f9f9;border:1px solid #e5e5e5;border-radius:6px;padding:0;">'
               . '<summary style="padding:10px 14px;cursor:pointer;font-size:13px;font-weight:600;">'
               . '❓ ' . esc_html($q['question'] ?? '') . '</summary>'
               . '<p style="padding:8px 14px 12px;margin:0;font-size:13px;color:#444;border-top:1px solid #eee;">'
               . esc_html($q['brief_answer'] ?? '') . '</p>'
               . '</details>';
    }
    if (empty($questions)) {
        $html .= '<p style="color:#666;">' . esc_html($text) . '</p>';
    }
    $html .= '</div>';

    wp_send_json_success(['title' => '❓ People Also Ask: ' . $keyword, 'html' => $html]);
});

// 7. Content Gaps
add_action('wp_ajax_geo_content_gaps', function() {
    $keyword  = geo_check_analysis_request();
    $provider = sanitize_text_field($_POST['provider'] ?? '');

    global $wpdb;
    $existing = $wpdb->get_col(
        "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
          WHERE meta_key = '_geo_keyword' LIMIT 30"
    );

    $existing_list = implode(', ', array_map('sanitize_text_field', $existing));

    $prompt = 'Voce e um especialista em estrategia de conteudo SEO.' . "\n"
            . 'Artigos ja publicados no site: ' . ($existing_list ?: 'nenhum') . '.' . "\n"
            . 'Nova keyword considerada: "' . $keyword . '".' . "\n"
            . 'Identifique gaps de conteudo: topicos complementares ainda nao cobertos.' . "\n"
            . 'Responda SOMENTE em JSON: {"gaps":[{"topic":"...","reason":"...","priority":"alta|media|baixa"}]}' . "\n"
            . 'Liste 5 a 7 gaps.';

    $text = geo_ai_call($prompt, $provider);
    if (!$text) wp_send_json_error(['message' => 'Erro na API de IA.']);

    $data = \GeoMetodoSEO\Services\ContentFormatter::extractJson($text);
    $gaps = $data['gaps'] ?? [];

    $priority_colors = ['alta' => '#dc3232', 'media' => '#ffb900', 'baixa' => '#46b450'];

    $html = '<div style="display:flex;flex-direction:column;gap:8px;">';
    foreach ($gaps as $g) {
        $pri   = strtolower($g['priority'] ?? 'media');
        $color = $priority_colors[$pri] ?? '#888';
        $html .= '<div style="padding:10px 14px;background:#f9f9f9;border-left:4px solid ' . $color . ';border-radius:0 6px 6px 0;">'
               . '<div style="display:flex;justify-content:space-between;align-items:center;">'
               . '<strong style="font-size:13px;">' . esc_html($g['topic'] ?? '') . '</strong>'
               . '<span style="font-size:11px;background:' . $color . ';color:#fff;padding:2px 8px;border-radius:10px;">'
               . esc_html(ucfirst($pri)) . '</span></div>'
               . '<p style="margin:4px 0 0;font-size:12px;color:#555;">' . esc_html($g['reason'] ?? '') . '</p>'
               . '</div>';
    }
    if (empty($gaps)) {
        $html .= '<p style="color:#666;">' . esc_html($text) . '</p>';
    }
    $html .= '</div>';

    wp_send_json_success(['title' => '📊 Content Gaps: ' . $keyword, 'html' => $html]);
});

// 8. Extrair Entidades
add_action('wp_ajax_geo_extract_entities', function() {
    $keyword  = geo_check_analysis_request();
    $provider = sanitize_text_field($_POST['provider'] ?? '');

    $prompt = 'Extraia entidades semanticas do topico "' . $keyword . '" em portugues.' . "\n"
            . 'Responda SOMENTE em JSON: {"entities":[{"name":"...","type":"person|place|brand|concept|event","relevance":90}]}' . "\n"
            . 'Liste 10 a 15 entidades, ordenadas por relevancia decrescente.';

    $text = geo_ai_call($prompt, $provider);
    if (!$text) wp_send_json_error(['message' => 'Erro na API de IA.']);

    $data     = \GeoMetodoSEO\Services\ContentFormatter::extractJson($text);
    $entities = $data['entities'] ?? [];

    $type_styles = [
        'person'  => 'background:#e3f2fd;color:#1565c0;',
        'place'   => 'background:#e8f5e9;color:#2e7d32;',
        'brand'   => 'background:#fce4ec;color:#c62828;',
        'concept' => 'background:#f3e5f5;color:#6a1b9a;',
        'event'   => 'background:#fff3e0;color:#e65100;',
    ];

    $html = '<div style="margin-bottom:10px;font-size:12px;color:#666;">';
    foreach (['person','place','brand','concept','event'] as $t) {
        $s = $type_styles[$t];
        $html .= '<span style="' . $s . 'padding:2px 8px;border-radius:10px;margin-right:6px;">'
               . ucfirst($t) . '</span>';
    }
    $html .= '</div><div>';

    foreach ($entities as $e) {
        $type  = strtolower($e['type'] ?? 'concept');
        $style = $type_styles[$type] ?? $type_styles['concept'];
        $rel   = intval($e['relevance'] ?? 0);
        $html .= '<span style="' . $style . 'display:inline-block;padding:4px 12px;border-radius:14px;font-size:13px;margin:3px;font-weight:500;">'
               . esc_html($e['name'] ?? '') . ' <span style="opacity:.6;font-size:11px;">' . $rel . '%</span>'
               . '</span>';
    }
    if (empty($entities)) {
        $html .= '<p style="color:#666;">' . esc_html($text) . '</p>';
    }
    $html .= '</div>';

    wp_send_json_success(['title' => '🏷️ Entidades Semanticas: ' . $keyword, 'html' => $html]);
});

// 9. SERP Simulation
add_action('wp_ajax_geo_serp_simulation', function() {
    check_ajax_referer('geo_analysis_nonce', 'nonce');
    $keyword = geo_check_analysis_request();

    $site_name = get_bloginfo('name');
    $site_url  = get_site_url();
    $host      = str_replace(['https://', 'http://'], '', rtrim($site_url, '/'));
    $slug      = sanitize_title($keyword);
    $breadcrumb = $host . ' › ' . $slug;

    $year  = date('Y');
    $title = ucwords($keyword) . ': Guia Completo ' . $year . ' | ' . $site_name;
    $desc  = 'Descubra tudo sobre ' . $keyword
           . ': definição completa, como funciona, benefícios, guia prático passo a passo e perguntas frequentes. Atualizado em ' . $year . '.';
    $url   = $site_url . '/' . $slug . '/';

    if (mb_strlen($title) > 60)  $title = mb_substr($title, 0, 57) . '...';
    if (mb_strlen($desc) > 160)  $desc  = mb_substr($desc, 0, 157) . '...';

    $t_len = mb_strlen($title);
    $d_len = mb_strlen($desc);
    $t_ok  = $t_len <= 60;
    $d_ok  = $d_len <= 160;

    $html = '<div style="font-family:arial,sans-serif;max-width:600px;">'
          . '<p style="font-size:12px;color:#202124;margin:0 0 2px;">' . esc_html($breadcrumb) . ' ▾</p>'
          . '<p style="font-size:20px;color:#1a0dab;font-weight:400;margin:0 0 3px;line-height:1.3;">'
          . '<a href="' . esc_url($url) . '" style="color:#1a0dab;text-decoration:none;">' . esc_html($title) . '</a></p>'
          . '<p style="font-size:14px;color:#4d5156;margin:0;line-height:1.58;">' . esc_html($desc) . '</p>'
          . '</div>'
          . '<div style="margin-top:14px;display:flex;gap:20px;font-size:12px;">'
          . '<span style="color:' . ($t_ok ? '#46b450' : '#dc3232') . ';">'
          . ($t_ok ? '✅' : '⚠️') . ' Título: ' . $t_len . '/60 chars</span>'
          . '<span style="color:' . ($d_ok ? '#46b450' : '#dc3232') . ';">'
          . ($d_ok ? '✅' : '⚠️') . ' Descrição: ' . $d_len . '/160 chars</span>'
          . '</div>';

    wp_send_json_success(['title' => '🔍 Simulacao SERP: ' . $keyword, 'html' => $html]);
});

// 10. Calcular Score SEO/GEO/LLM de artigo existente
add_action('wp_ajax_geo_analyze_article', function() {
    check_ajax_referer('geo_analysis_score_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permissao negada');

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error(['message' => 'Post ID invalido']);

    $controller = new \GeoMetodoSEO\Admin\AnalysisController();
    $scores     = $controller->calculate_scores($post_id);

    if (!$scores) {
        wp_send_json_error(['message' => 'Artigo nao encontrado ou sem conteudo']);
    }

    // Salva no histórico
    $history = get_option('geo_score_history', []);
    array_unshift($history, [
        'post_id' => $post_id,
        'title'   => get_the_title($post_id),
        'scores'  => $scores,
        'date'    => current_time('timestamp'),
    ]);
    update_option('geo_score_history', array_slice($history, 0, 10));

    $html = $controller->render_dashboard_html($scores, $post_id);
    wp_send_json_success(['html' => $html]);
});

// ============================================================================
// AGENDAMENTO AUTOMÁTICO — helpers e AJAX handlers
// ============================================================================

/**
 * Calcula timestamps para N artigos com slots de 4h a partir de start_hour.
 * Máximo 5 artigos por dia: 08:00, 12:00, 16:00, 20:00, 00:00 (meia-noite).
 */
function geo_calculate_schedule($count, $start_hour, $start_min = 0) {
    $tz  = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTimeImmutable('now', $tz);
    $base = $now->setTime((int)$start_hour, (int)$start_min, 0);

    // Se o primeiro slot já passou no horário de São Paulo, começar amanhã.
    if ($base->getTimestamp() <= $now->getTimestamp()) {
        $base = $base->modify('+1 day');
    }

    $slots = [];
    for ($i = 0; $i < $count; $i++) {
        $group   = (int) floor($i / 5);
        $pos     = $i % 5;
        $hours   = $pos * 4;
        $slots[] = $base->modify('+' . $group . ' days')->modify('+' . $hours . ' hours')->getTimestamp();
    }
    return $slots;
}
if (!function_exists('geo_mark_stale_scheduled_jobs')) {
    /**
     * Evita jobs eternamente em "Gerando...".
     * Se um worker morrer por timeout/fatal sem conseguir finalizar a fila, marca como failed.
     */
    function geo_mark_stale_scheduled_jobs($type = '', $max_age = 240) {
        $queue = get_option('geo_scheduled_queue', []);
        if (!is_array($queue) || empty($queue)) return $queue;

        $changed = false;
        $now = time();

        foreach ($queue as $i => &$job) {
            if ($type && (($job['type'] ?? '') !== $type)) continue;
            if (($job['status'] ?? '') !== 'processing') continue;

            $started = (int)($job['started_at'] ?? $job['updated_at'] ?? $job['created_at'] ?? 0);
            if ($started > 0 && ($now - $started) > (int)$max_age) {
                $job['status'] = 'failed';
                $job['done_at'] = $now;
                $job['last_error'] = 'Timeout/worker travado: job ficou em processamento por mais de ' . (int)round($max_age / 60) . ' minutos.';
                $changed = true;
                if (class_exists('GeoMetodoSEO\\Services\\LogService')) {
                    \GeoMetodoSEO\Services\LogService::log('error', 'Fila: job marcado como falha por timeout/travamento — ' . ($job['keyword'] ?? 'sem keyword'));
                }
            }
        }
        unset($job);

        if ($changed) {
            update_option('geo_scheduled_queue', $queue);
        }
        return $queue;
    }
}

/**
 * Renderiza tabela HTML da fila de agendamento.
 */
function geo_render_schedule_table($jobs, $notice = '') {
    $status_map = [
        'pending'    => ['⏳ Aguardando', '#888'],
        'processing' => ['⚙️ Gerando...',  '#0073aa'],
        'done'       => ['✅ Publicado',   '#46b450'],
        'failed'     => ['❌ Falhou',      '#dc3232'],
    ];

    $html = '';
    if ($notice) {
        $html .= '<div style="padding:10px 14px;background:#edfaed;border-left:4px solid #46b450;border-radius:4px;margin-bottom:14px;">'
               . '✅ <strong>' . esc_html($notice) . '</strong></div>';
    }

    $html .= '<table class="widefat fixed striped" style="margin-top:4px;">'
           . '<thead><tr>'
           . '<th style="width:36px;">#</th>'
           . '<th>Keyword</th>'
           . '<th style="width:100px;">Tipo</th>'
           . '<th style="width:100px;">Provedor</th>'
           . '<th style="width:150px;">Agendado para</th>'
           . '<th style="width:130px;">Status</th>'
           . '<th style="width:80px;">Artigo</th>'
           . '<th style="width:60px;">Ação</th>'
           . '</tr></thead><tbody>';

    foreach ($jobs as $idx => $job) {
        [$st_label, $st_color] = $status_map[$job['status']] ?? ['—', '#888'];
        $type_label = ($job['role'] ?? $job['type'] ?? 'bulk');
        $type_color = $type_label === 'pilar' ? '#0073aa' : ($type_label === 'satelite' ? '#888' : '#555');

        $post_link = '';
        if (!empty($job['post_id'])) {
            $post_link = '<a href="' . esc_url(get_edit_post_link((int) $job['post_id'])) . '" target="_blank">#' . (int) $job['post_id'] . '</a>';
        }

        // Botão cancelar apenas para jobs pendentes
        $cancel_btn = '';
        if (($job['status'] ?? '') === 'pending') {
            $cancel_btn = '<button class="geo-cancel-job" data-idx="' . $idx . '" '
                        . 'style="background:#dc3232;color:#fff;border:none;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:11px;" '
                        . 'title="Cancelar este artigo">✖ Cancelar</button>';
        }

        $html .= '<tr id="geo-job-row-' . $idx . '">'
               . '<td>' . ($idx + 1) . '</td>'
               . '<td>' . esc_html($job['keyword']) . '</td>'
               . '<td><span style="color:' . $type_color . ';font-weight:600;font-size:12px;">' . esc_html(ucfirst($type_label)) . '</span></td>'
               . '<td style="font-size:12px;">' . esc_html($job['provider'] ?? '') . '</td>'
               . '<td style="font-size:12px;">' . esc_html((new DateTimeImmutable('@' . (int) ($job['scheduled_at'] ?? 0)))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i')) . '</td>'
               . '<td><span style="color:' . $st_color . ';font-weight:600;font-size:12px;">' . $st_label . '</span></td>'
               . '<td>' . $post_link . '</td>'
               . '<td>' . $cancel_btn . '</td>'
               . '</tr>';
    }

    $html .= '</tbody></table>';
    // JavaScript para botões de cancelar
    $nonce_cancel = wp_create_nonce('geo_cancel_job_nonce');
    $html .= '<script>
document.querySelectorAll(".geo-cancel-job").forEach(function(btn) {
    btn.addEventListener("click", function() {
        var idx = this.getAttribute("data-idx");
        if (!confirm("Cancelar este artigo da fila?")) return;
        var b = this;
        b.disabled = true; b.textContent = "...";
        var d = new FormData();
        d.append("action", "geo_cancel_queue_job");
        d.append("nonce", "' . $nonce_cancel . '");
        d.append("job_index", idx);
        fetch(ajaxurl, {method:"POST", body:d})
            .then(function(r){return r.json();})
            .then(function(res){
                if (res.success) {
                    var row = document.getElementById("geo-job-row-" + idx);
                    if (row) {
                        row.style.opacity = "0.4";
                        row.querySelector("td:nth-child(6)").innerHTML = "<span style=\"color:#999;font-size:12px;\">Cancelado</span>";
                        b.remove();
                    }
                } else {
                    b.disabled = false; b.textContent = "✖ Cancelar";
                    alert("Erro: " + (res.data?.message || "tente novamente"));
                }
            });
    });
});
</script>';
    return $html;
}

// AJAX: Agendar Geração em Massa
add_action('wp_ajax_geo_schedule_bulk', function() {
    check_ajax_referer('geo_process_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permissao negada');

    $keywords   = array_filter(array_map('sanitize_text_field',
                    json_decode(stripslashes($_POST['keywords'] ?? '[]'), true) ?: []));
    $provider   = sanitize_text_field($_POST['provider']   ?? \GeoMetodoSEO\AI\ProviderResolver::for('article_generation'));
    $language   = sanitize_text_field($_POST['language']   ?? 'pt-BR');
    $start_time = sanitize_text_field($_POST['start_time'] ?? '08:00');
    $article_size = in_array(($_POST['article_size'] ?? ''), ['small','medium','large'], true) ? sanitize_text_field($_POST['article_size']) : get_option('geo_article_size', 'large');
    $category_id = sanitize_text_field($_POST['category_id'] ?? 'auto');
    $embed_video = ($_POST['embed_video'] ?? '0') === '1';
    $image_source = sanitize_key($_POST['image_source'] ?? '');
    if (!in_array($image_source, ['ai', 'library'], true)) $image_source = '';

    // Resolver category_id em nome de categoria para manter compatibilidade com ArticlePipeline.
    $resolved_cat = 'auto';
    if (!empty($category_id) && $category_id !== 'auto' && $category_id !== '_new_') {
        if (is_numeric($category_id)) {
            $term = get_term((int) $category_id, 'category');
            if ($term && !is_wp_error($term)) $resolved_cat = $term->name;
        } else {
            $resolved_cat = $category_id;
        }
    }

    if (empty($keywords)) wp_send_json_error(['message' => 'Nenhuma keyword informada']);

    $parts      = explode(':', $start_time);
    $start_hour = max(0, min(23, (int) ($parts[0] ?? 8)));
    $start_min  = max(0, min(59, (int) ($parts[1] ?? 0)));

    $timestamps = geo_calculate_schedule(count($keywords), $start_hour, $start_min);

    $queue    = get_option('geo_scheduled_queue', []);
    $new_jobs = [];

    foreach (array_values($keywords) as $i => $kw) {
        $job = [
            'id'           => wp_unique_id('geo_bulk_'),
            'keyword'      => $kw,
            'provider'     => $provider,
            'language'     => $language,
            'article_size' => $article_size,
            'category'     => $resolved_cat,
            'embed_video'  => $embed_video,
            'image_source' => $image_source,
            'scheduled_at' => $timestamps[$i],
            'type'         => 'bulk',
            'role'         => 'bulk',
            'status'       => 'pending',
            'post_id'      => null,
            'created_at'   => time(),
        ];
        $queue[]    = $job;
        $new_jobs[] = $job;
    }

    update_option('geo_scheduled_queue', $queue);
    if (class_exists('GeoMetodoSEO\\TitleBank\\SeoGeoTitleBank')) {
        foreach ($keywords as $kw_mark) {
            \GeoMetodoSEO\TitleBank\SeoGeoTitleBank::mark_used_by_title((string)$kw_mark, 'bulk_scheduled', 0);
        }
    }

    if (!wp_next_scheduled('geo_auto_schedule_event')) {
        wp_schedule_event(time() + 60, 'geo_five_minutes', 'geo_auto_schedule_event');
    }

    $html = geo_render_schedule_table($new_jobs, count($new_jobs) . ' artigos agendados com sucesso!');
    wp_send_json_success(['html' => $html, 'count' => count($new_jobs)]);
});


if (!function_exists('geo_generate_cluster_satellites_from_pillar')) {
    function geo_generate_cluster_satellites_from_pillar(string $main_kw, int $count = 5): array {
        $main_kw = trim(wp_strip_all_tags($main_kw));
        if ($main_kw === '') return [];
        $clean = preg_replace('/\s+/u', ' ', $main_kw) ?: $main_kw;
        $clean = preg_replace('/^(como|o que é|quais são|qual é)\s+/iu', '', $clean) ?: $clean;
        $clean = trim($clean, " \t\n\r\0\x0B.?!");
        $items = [
            'Como aplicar ' . $clean . ' na prática dentro do WordPress',
            'Principais recursos de ' . $clean . ' para SEO, GEO e AEO',
            'Erros comuns ao usar ' . $clean . ' e como evitar problemas',
            'Como configurar ' . $clean . ' com biblioteca de imagens e automação',
            $clean . ' vs plugins SEO tradicionais: diferenças e quando usar',
        ];
        return array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', $items)))), 0, max(1, $count));
    }
}

// AJAX: Agendar Cluster
add_action('wp_ajax_geo_schedule_cluster', function() {
    check_ajax_referer('geo_process_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permissao negada');

    $main_kw     = sanitize_text_field($_POST['main_kw']    ?? '');
    $satellites  = array_filter(array_map('sanitize_text_field',
                    json_decode(stripslashes($_POST['satellites'] ?? '[]'), true) ?: []));
    if (empty($satellites) && !empty($_POST['main_kw'])) {
        $satellites = geo_generate_cluster_satellites_from_pillar(sanitize_text_field($_POST['main_kw']), 5);
    }
    $language    = sanitize_text_field($_POST['language']   ?? 'pt-BR');
    $start_time  = sanitize_text_field($_POST['start_time'] ?? '08:00');
    $category_id = sanitize_text_field($_POST['category_id'] ?? 'auto');
    $provider_pillar = \GeoMetodoSEO\AI\ProviderResolver::for('cluster_generation', sanitize_text_field($_POST['provider_pillar'] ?? ''));
    $provider_sat    = \GeoMetodoSEO\AI\ProviderResolver::for('cluster_generation', sanitize_text_field($_POST['provider_sat'] ?? $provider_pillar));

    // Cluster: passar o ID numérico da categoria diretamente nos jobs.
    // O PostRepository agora aceita ID numérico e resolve o term_id correto,
    // evitando que pilar e satélites fiquem em categorias diferentes por mismatch de nome.
    $resolved_cat = 'auto';
    if (!empty($category_id) && $category_id !== 'auto') {
        if (is_numeric($category_id)) {
            // Verificar se o term existe antes de salvar o ID
            $term = get_term((int) $category_id, 'category');
            if ($term && !is_wp_error($term)) {
                $resolved_cat = $category_id; // Mantém como ID numérico (string)
            }
        } else {
            $resolved_cat = $category_id;
        }
    }

    if (empty($main_kw)) wp_send_json_error(['message' => 'Keyword do pilar vazia']);

    $parts      = explode(':', $start_time);
    $start_hour = max(0, min(23, (int) ($parts[0] ?? 8)));
    $start_min  = max(0, min(59, (int) ($parts[1] ?? 0)));

    // Pilar + satélites em ordem
    $all_kws = array_merge([$main_kw], array_values($satellites));
    $timestamps = geo_calculate_schedule(count($all_kws), $start_hour, $start_min);

    $queue    = get_option('geo_scheduled_queue', []);
    $new_jobs = [];

    foreach ($all_kws as $i => $kw) {
        $is_pillar = ($i === 0);
        $is_first = ($i === 0);
        $fire_at  = $timestamps[$i];
        $job = [
            'id'           => wp_unique_id('geo_cluster_'),
            'keyword'      => $kw,
            'provider'     => $is_pillar ? $provider_pillar : $provider_sat,
            'language'     => $language,
            'article_size' => $is_pillar ? 'medium' : 'cluster_satellite',
            'scheduled_at' => $fire_at,
            // Como a fila/cron gera o artigo somente quando o horário chega,
            // todos os jobs devem publicar no momento da execução.
            'post_status'  => 'publish',
            'is_first'     => $is_pillar,
            'category'     => $resolved_cat,
            'type'         => 'cluster',
            'role'         => $is_pillar ? 'pilar' : 'satelite',
            'status'       => 'pending',
            'post_id'      => null,
            'created_at'   => time(),
        ];
        $queue[]   = $job;
        $job_index = count($queue) - 1;
        $new_jobs[] = $job;
        wp_schedule_single_event($fire_at, 'geo_process_single_keyword_event', [$kw, $job_index]);
    }

    update_option('geo_scheduled_queue', $queue);

    if (!wp_next_scheduled('geo_auto_schedule_event')) {
        wp_schedule_event(time() + 60, 'geo_five_minutes', 'geo_auto_schedule_event');
    }

    $html = geo_render_schedule_table($new_jobs,
        '1 pilar + ' . count($satellites) . ' satélites agendados! Pilar: ' . strtoupper($provider_pillar) . ' · Satélites: ' . strtoupper($provider_sat));
    wp_send_json_success(['html' => $html, 'count' => count($new_jobs)]);
});

// AJAX: Atualizar fila (refresh)
add_action('wp_ajax_geo_get_schedule_queue', function() {
    check_ajax_referer('geo_process_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permissao negada');

    $type  = sanitize_text_field($_POST['type'] ?? '');   // 'bulk' | 'cluster' | '' = todos
    $queue = function_exists('geo_mark_stale_scheduled_jobs') ? geo_mark_stale_scheduled_jobs($type) : get_option('geo_scheduled_queue', []);

    if ($type) {
        $queue = array_values(array_filter($queue, fn($j) => ($j['type'] ?? '') === $type));
    }

    if (empty($queue)) {
        wp_send_json_success(['html' => '<p style="color:#666;">Nenhum agendamento na fila.</p>', 'pending' => 0]);
    }

    $pending = count(array_filter($queue, fn($j) => $j['status'] === 'pending'));
    $html    = geo_render_schedule_table($queue);
    wp_send_json_success(['html' => $html, 'pending' => $pending]);
});

// AJAX: Limpar pausas de providers (botão "Liberar Providers" no painel SARA)
add_action('wp_ajax_geo_clear_provider_pauses', function() {
    check_ajax_referer('sara_autopilot_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada']);
        return;
    }
    $provider = sanitize_key($_POST['provider'] ?? '');

    // Limpar pausas individuais de providers (transients)
    if (class_exists('GeoMetodoSEO\AI\AIManager')) {
        \GeoMetodoSEO\AI\AIManager::clear_provider_pauses($provider);
    }

    // Limpar TAMBÉM a pausa global da SARA (sara_ai_quota_paused_until)
    // Esta option bloqueia todos os jobs quando qualquer provider dá quota error
    delete_option('sara_ai_quota_paused_until');
    delete_option('sara_ai_quota_last_error');

    // Limpar pausas individuais por provider (transients do AIManager)
    foreach (['openai', 'groq', 'gemini', 'claude', 'perplexity', 'naga'] as $p) {
        delete_transient('geo_provider_paused_' . $p);
        delete_transient('geo_ai_provider_pause_' . $p);
    }

    wp_send_json_success(['message' => 'Todos os providers liberados. SARA retomará na próxima execução.']);
});

// AJAX: Limpar concluídos/falhos da fila
add_action('wp_ajax_geo_clear_schedule_done', function() {
    check_ajax_referer('geo_process_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permissao negada');

    $type  = sanitize_text_field($_POST['type'] ?? ''); // 'bulk' | 'cluster' | '' = todos
    $queue = get_option('geo_scheduled_queue', []);

    if ($type) {
        // Preserve pending/processing of other types; only clear done/failed of this type
        $queue = array_values(array_filter($queue, function($j) use ($type) {
            $same_type = ($j['type'] ?? '') === $type;
            if ($same_type) {
                return !in_array($j['status'], ['done', 'failed'], true);
            }
            return true;
        }));
    } else {
        $queue = array_values(array_filter($queue, fn($j) => !in_array($j['status'], ['done', 'failed'], true)));
    }

    update_option('geo_scheduled_queue', $queue);

    $remaining = $type
        ? array_values(array_filter($queue, fn($j) => ($j['type'] ?? '') === $type))
        : $queue;
    $html = empty($remaining)
        ? '<p style="color:#666; font-size:13px;">Nenhum agendamento na fila.</p>'
        : geo_render_schedule_table($remaining);
    wp_send_json_success(['message' => 'Fila limpa.', 'html' => $html]);
});

// ============================================================================
// AJAX: Cache de progresso em massa (sessao)
// ============================================================================

// Criar nova sessao de geração em massa
add_action('wp_ajax_geo_bulk_save_session', function() {
    check_ajax_referer('geo_process_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permissao negada');

    $raw_kws  = json_decode(stripslashes($_POST['keywords'] ?? '[]'), true) ?: [];
    $keywords = array_values(array_filter(array_map('sanitize_text_field', $raw_kws)));

    if (empty($keywords)) wp_send_json_error(['message' => 'Nenhuma keyword']);

    $session_id = wp_generate_uuid4();
    $items = array_map(fn($kw) => ['kw' => $kw, 'status' => 'pending', 'post_id' => null, 'title' => ''], $keywords);

    update_option('geo_bulk_session', [
        'id'         => $session_id,
        'created_at' => time(),
        'keywords'   => $items,
    ], false);

    wp_send_json_success(['session_id' => $session_id]);
});

// Atualizar progresso de um artigo na sessao
add_action('wp_ajax_geo_bulk_mark_progress', function() {
    check_ajax_referer('geo_process_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permissao negada');

    $session_id = sanitize_text_field($_POST['session_id'] ?? '');
    $idx        = (int) ($_POST['idx'] ?? -1);
    $status     = sanitize_text_field($_POST['status']     ?? 'pending');
    $post_id    = (int) ($_POST['post_id']   ?? 0);
    $title      = sanitize_text_field($_POST['title']      ?? '');

    $session = get_option('geo_bulk_session', null);
    if (!$session || ($session['id'] ?? '') !== $session_id) {
        wp_send_json_error('Sessao invalida');
    }

    if (isset($session['keywords'][$idx])) {
        $session['keywords'][$idx]['status']  = $status;
        $session['keywords'][$idx]['post_id'] = $post_id ?: null;
        $session['keywords'][$idx]['title']   = $title;
        update_option('geo_bulk_session', $session, false);
    }

    wp_send_json_success(['updated' => true]);
});

// Descartar sessao atual
add_action('wp_ajax_geo_bulk_discard_session', function() {
    check_ajax_referer('geo_process_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permissao negada');
    delete_option('geo_bulk_session');
    wp_send_json_success(['discarded' => true]);
});

// ============================================================================
// AJAX: Enviar email de notificacao ao final de geracao em massa / cluster
// ============================================================================
add_action('wp_ajax_geo_send_completion_email', function() {
    check_ajax_referer('geo_process_article_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permissao negada');

    $email  = sanitize_email(get_option('geo_notification_email', ''));
    if (!$email) wp_send_json_success(['skipped' => true]);

    $source  = sanitize_text_field($_POST['source']  ?? 'Geracao em Massa');
    $done    = (int) ($_POST['done']   ?? 0);
    $failed  = (int) ($_POST['failed'] ?? 0);
    $raw     = json_decode(stripslashes($_POST['titles'] ?? '[]'), true) ?: [];
    $titles  = array_map('sanitize_text_field', $raw);

    $subject = '[GEO SEO] ' . $source . ' concluida — ' . $done . ' gerados';
    $body    = "Geracao concluida via GEO Metodo SEO.\n\n"
             . "Fonte: {$source}\n"
             . "✅ Artigos gerados: {$done}\n"
             . "❌ Com erro: {$failed}\n\n";

    if (!empty($titles)) {
        $body .= "Titulos gerados:\n";
        foreach ($titles as $i => $t) {
            $body .= ($i + 1) . ". {$t}\n";
        }
    }

    $body .= "\n-- GEO Metodo SEO v" . GEO_METODO_SEO_VERSION;

    $sent = wp_mail($email, $subject, $body);
    wp_send_json_success(['sent' => $sent, 'to' => $email]);
});

// ============================================================================
// Exportar CSV do Dashboard (admin_post handler)
// ============================================================================
add_action('admin_post_geo_export_csv', function() {
    check_admin_referer('geo_export_csv');
    if (!current_user_can('manage_options')) wp_die('Permissao negada');

    $service = new \GeoMetodoSEO\Services\ReportService();
    $posts   = $service->get_articles(['limit' => 500]);
    $data    = $service->format_data($posts);

    // Score SEO simples (baseado em AnalysisController)
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="geo-artigos-' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8

    fputcsv($out, ['ID', 'Titulo', 'Keyword', 'Palavras', 'Score SEO', 'Provedor IA', 'Modelo IA', 'Status', 'Data'], ';');

    foreach ($data as $row) {
        $post_id  = (int) $row['id'];
        $words    = (int) $row['words'];
        $content  = get_post_field('post_content', $post_id);
        $h2_count = substr_count(strtolower($content), '<h2');
        $kw       = $row['keyword'] ?? '';
        $kw_dens  = $words > 0 ? round(substr_count(strtolower(strip_tags($content)), strtolower($kw)) / $words * 100, 1) : 0;
        $score    = min(100, (int)(
            ($words >= 1500 ? 30 : ($words >= 800 ? 15 : 0))
            + ($h2_count >= 4 ? 20 : ($h2_count >= 2 ? 10 : 0))
            + ($kw_dens >= 0.5 && $kw_dens <= 3 ? 20 : 10)
            + (has_post_thumbnail($post_id) ? 20 : 0)
            + (!empty(get_post_field('post_excerpt', $post_id)) ? 10 : 0)
        ));

        $model = get_post_meta($post_id, '_geo_model', true) ?: '—';

        fputcsv($out, [
            $post_id,
            $row['title'],
            $kw,
            $words,
            $score,
            $row['provider'] ?: '—',
            $model,
            $row['status'],
            date('d/m/Y H:i', strtotime($row['date'])),
        ], ';');
    }

    fclose($out);
    exit;
});

// ============================================================================
// SARA AUTOPILOT v4.0 — Agentes Brain + Writer
// ============================================================================
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Shared/AutopilotInstaller.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Shared/AutopilotLogger.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Shared/ScheduleManager.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Brain/SaraApiClient.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Brain/SaraTitleValidator.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Brain/SaraNicheDetector.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Brain/SaraCategoryFilter.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Brain/SaraIndexer.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Brain/SaraPlanner.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Brain/SaraBrain.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Writer/SaraGenerator.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Writer/SaraQualityGate.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Writer/SaraImageHandler.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Writer/SaraSchemaManager.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Writer/SaraContentValidator.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Writer/SaraDeepFAQGenerator.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Writer/SaraGlobalPostProcessor.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Writer/SaraImageQueue.php';

// 1.0.0: Processar imagens contextuais do YouTube em segundo plano para evitar 504 no AJAX.
add_action('geo_youtube_process_context_images', function($post_id) {
    if (class_exists('GeoMetodoSEO\Services\YouTubeToArticleService')) {
        $svc = new \GeoMetodoSEO\Services\YouTubeToArticleService();
        $svc->processQueuedContextImages((int)$post_id);
    }
});

require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Writer/SaraWriter.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Writer/SaraWriterManual.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Professional/SaraRetryManager.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Professional/SaraEditorialGuard.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Professional/SaraHealthMonitor.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/AutopilotController.php';

// 1.0.0: Indexação e Learning Loop
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Indexing/SaraIndexNow.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/Learning/SaraContentRefresher.php';
// 1.0.0: GSC já existe via SearchConsoleService (OAuth) — não duplicamos

// 1.0.0: Media Opportunities + Press Release (HARO/PR white-hat)
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/MediaOpportunities/SaraMediaCollector.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/MediaOpportunities/SaraMediaMatcher.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/MediaOpportunities/SaraPitchGenerator.php';
require_once GEO_METODO_SEO_PATH . 'includes/Autopilot/MediaOpportunities/SaraPressRelease.php';

// 1.0.0: ImageReSideloader é carregado MUITO antes (junto com outros register_hooks),
// não aqui em baixo. Isso garante que admin_init / wp_ajax_* peguem nossos handlers.

// Boot do Autopilot
GeoMetodoSEO\Autopilot\AutopilotController::boot();

// 1.0.0: Boot dos módulos de indexação e learning
GeoMetodoSEO\Autopilot\Indexing\SaraIndexNow::register();
GeoMetodoSEO\Autopilot\Learning\SaraContentRefresher::register();

// 1.0.0: Monitor profissional e autorreparo conservador da SARA
add_action('sara_autopilot_health_repair', function() {
    if (class_exists('\GeoMetodoSEO\Autopilot\Professional\SaraHealthMonitor')) {
        \GeoMetodoSEO\Autopilot\Professional\SaraHealthMonitor::repair();
    }
});

if (!wp_next_scheduled('sara_autopilot_health_repair')) {
    wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sara_autopilot_health_repair');
}
// 1.0.0: SearchConsoleService já é carregado pelo SettingsController (OAuth)

// 1.0.0: Media Opportunities — registra cron 4x/dia
GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaCollector::register();

// 1.0.0: Garantir tabela existe (idempotente — só cria se não existir)
register_activation_hook(__FILE__, function() {
    if (class_exists('\GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaCollector')) {
        \GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaCollector::ensure_table();
    }
    // 1.0.0 — schema rico do LogService
    if (class_exists('\GeoMetodoSEO\Services\LogService')) {
        \GeoMetodoSEO\Services\LogService::ensure_schema();
    }
});
add_action('init', function() {
    // Garantir que a tabela existe mesmo se o plugin foi atualizado sem deactivate/activate
    if (get_option('sara_media_table_v1', '0') !== '1' &&
        class_exists('\GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaCollector')) {
        \GeoMetodoSEO\Autopilot\MediaOpportunities\SaraMediaCollector::ensure_table();
        update_option('sara_media_table_v1', '1');
    }
    // 1.0.0 — garante schema do LogService (idempotente — só roda 1x via flag)
    if (get_option('geo_logs_schema_v2', '0') !== '1' &&
        class_exists('\GeoMetodoSEO\Services\LogService')) {
        \GeoMetodoSEO\Services\LogService::ensure_schema();
        update_option('geo_logs_schema_v2', '1');
    }
}, 5);

add_action('geo_logs_daily_cleanup', function() {
    if (class_exists('\GeoMetodoSEO\Services\LogService')) {
        \GeoMetodoSEO\Services\LogService::cleanup_old();
    }
});

if (!wp_next_scheduled('geo_logs_daily_cleanup')) {
    wp_schedule_event(time() + 3 * HOUR_IN_SECONDS, 'daily', 'geo_logs_daily_cleanup');
}

// ============================================================================
// Schema Global do Site — WebSite + Organization (todas as páginas)
// Roda uma vez por página via transient cache (24h) para performance
// ============================================================================
add_action('wp_head', function() {
    $site_url  = get_site_url();
    $site_name = get_bloginfo('name');
    $lang      = get_bloginfo('language') ?: 'pt-BR';

    // Dados da Organização (configurados no plugin)
    $org_phone       = get_option('geo_org_phone', '');
    $org_email       = get_option('geo_org_email', '');
    $org_slogan      = get_option('geo_org_slogan', '');
    $org_founded     = get_option('geo_org_founded', '');
    $org_desc        = get_option('geo_org_description', get_bloginfo('description'));
    $logo_url        = get_site_icon_url(512) ?: '';
    $tw              = get_option('geo_social_twitter', '');
    $li              = get_option('geo_social_linkedin', '');
    $ig              = get_option('geo_social_instagram', '');
    $fb              = get_option('geo_social_facebook', '');

    $same_as = array_values(array_filter([$tw, $li, $ig, $fb]));

    $graph = [];

    // ── WebSite com SearchAction ──────────────────────────────────────────
    $website = [
        '@type'           => 'WebSite',
        '@id'             => $site_url . '/#website',
        'url'             => $site_url . '/',
        'name'            => $site_name,
        'description'     => $org_desc ?: $site_name,
        'inLanguage'      => $lang,
        'publisher'       => ['@id' => $site_url . '/#organization'],
        'potentialAction' => [[
            '@type'       => 'SearchAction',
            'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $site_url . '/?s={search_term_string}'],
            'query-input' => 'required name=search_term_string',
        ]],
        'copyrightYear'   => date('Y'),
        'copyrightHolder' => ['@id' => $site_url . '/#organization'],
    ];
    $graph[] = $website;

    // ── Organization completo ─────────────────────────────────────────────
    $org = [
        '@type'       => 'Organization',
        '@id'         => $site_url . '/#organization',
        'name'        => $site_name,
        'url'         => $site_url . '/',
        'description' => $org_desc ?: $site_name,
    ];
    if ($logo_url) {
        $org['logo'] = ['@type' => 'ImageObject', '@id' => $site_url . '/#logo', 'url' => $logo_url, 'width' => 512, 'height' => 512];
        $org['image'] = ['@id' => $site_url . '/#logo'];
    }
    if ($org_slogan)  $org['slogan']       = $org_slogan;
    if ($org_founded) $org['foundingDate'] = $org_founded;
    if ($org_phone)   $org['telephone']    = $org_phone;
    if ($org_email)   $org['email']        = $org_email;
    if (!empty($same_as)) $org['sameAs']   = $same_as;
    $org['contactPoint'] = array_values(array_filter([[
        '@type'           => 'ContactPoint',
        'contactType'     => 'customer service',
        'availableLanguage' => ['Portuguese', 'pt-BR'],
        'areaServed'      => 'BR',
        'telephone'       => $org_phone ?: null,
        'email'           => $org_email ?: null,
    ]]));
    if (empty(array_filter($org['contactPoint'][0]))) unset($org['contactPoint']);
    $graph[] = $org;

    $ld = ['@context' => 'https://schema.org', '@graph' => $graph];
    echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}, 2);

// ============================================================================
// wp_head: CSS do FAQ + Schema JSON-LD (AEO, SEO, GEO, LLM)
// ============================================================================
add_action('wp_head', function() {
    if (!is_singular('post')) return; // Apenas posts (não páginas)
    $post_id = get_the_ID();
    if (!$post_id) return;

    // ── CSS das caixas geradas pelo plugin ────────────────────────────────
    echo '<style>
.geo-faq-item{background:#f9f9f9;border-left:4px solid #0073aa;padding:16px 20px;margin-bottom:12px;border-radius:4px;}
.geo-faq-item strong{display:block;font-size:16px;margin-bottom:8px;color:#1a1a1a;}
.geo-faq-item p{margin:0;color:#444;line-height:1.6;}
</style>' . "\n";

    // ── Schema JSON-LD — @graph unificado (AEO + SEO + GEO + LLM) ─────────
    // O Rank Math gera Article/Breadcrumb básico. O GEO Método SEO adiciona:
    // FAQPage (rich results das perguntas), HowTo (tutoriais), Speakable (AEO voz)
    // e enriquece o Article com campos completos que o Rank Math não preenche.

    $post       = get_post($post_id);
    $title      = get_the_title($post_id);
    $permalink  = get_permalink($post_id);
    $excerpt    = get_post_field('post_excerpt', $post_id) ?: wp_trim_words(strip_tags($post->post_content), 30);
    $date_pub   = get_post_field('post_date', $post_id);
    $date_mod   = get_post_field('post_modified', $post_id);
    $thumb_url  = get_the_post_thumbnail_url($post_id, 'large') ?: '';
    $site_name  = get_bloginfo('name');
    $site_url   = get_site_url();
    $word_count = str_word_count(strip_tags($post->post_content));
    $keyword    = get_post_meta($post_id, '_geo_keyword', true);
    $lang       = get_bloginfo('language') ?: 'pt-BR';

    // Autor do plugin (ecossistema configurado)
    $author_name  = get_option('geo_author_name', $site_name);
    $author_spec  = get_option('geo_author_specialty', '');
    $author_photo = get_option('geo_author_photo', '');
    $author_url   = get_option('geo_author_editorial_page', '');
    $tw           = get_option('geo_social_twitter', '');
    $li           = get_option('geo_social_linkedin', '');

    $author_knowsabout  = get_option('geo_author_knowsabout', '');
    $author_awards      = get_option('geo_author_awards', '');
    $author_credentials = get_option('geo_author_credentials', '');

    $author_schema = [
        '@type' => 'Person',
        '@id'   => $site_url . '/#author',
        'name'  => $author_name,
        'worksFor' => ['@type' => 'Organization', '@id' => $site_url . '/#organization'],
    ];
    if ($author_spec)  $author_schema['jobTitle']  = $author_spec;
    if ($author_photo) $author_schema['image']     = ['@type' => 'ImageObject', 'url' => $author_photo, 'width' => 200, 'height' => 200];
    if ($author_url)   $author_schema['url']       = $author_url;
    $same_as = array_values(array_filter([$tw, $li, $author_url]));
    if (!empty($same_as)) $author_schema['sameAs'] = $same_as;

    // knowsAbout — áreas de expertise (crucial para GEO/LLM)
    if (!empty($author_knowsabout)) {
        $author_schema['knowsAbout'] = array_map('trim', explode(',', $author_knowsabout));
    }

    // award — prêmios e reconhecimentos (E-E-A-T)
    if (!empty($author_awards)) {
        $author_schema['award'] = array_map('trim', explode(',', $author_awards));
    }

    // hasCredential — EducationalOccupationalCredential (E-E-A-T avançado)
    if (!empty($author_credentials)) {
        $creds = array_filter(array_map('trim', explode(',', $author_credentials)));
        $author_schema['hasCredential'] = array_map(function($c) {
            return [
                '@type'              => 'EducationalOccupationalCredential',
                'credentialCategory' => 'Certification',
                'name'               => $c,
            ];
        }, $creds);
    }

    $graph = [];

    // ── 1. Article Schema completo (enriquece o Rank Math) ────────────────
    $article = [
        '@type'            => 'Article',
        '@id'              => $permalink . '#article',
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $permalink],
        'headline'         => $title,
        'name'             => $title,
        'description'      => $excerpt,
        'url'              => $permalink,
        'datePublished'    => $date_pub ? date('c', strtotime($date_pub)) : '',
        'dateModified'     => $date_mod ? date('c', strtotime($date_mod)) : '',
        'author'           => $author_schema,
        'publisher'        => [
            '@type' => 'Organization',
            '@id'   => $site_url . '/#organization',
            'name'  => $site_name,
            'url'   => $site_url,
            'logo'  => ['@type' => 'ImageObject', 'url' => get_site_icon_url(64) ?: $site_url],
        ],
        'inLanguage'       => $lang,
        'wordCount'        => $word_count,
        'keywords'         => $keyword,
        'isAccessibleForFree' => true,
        // Speakable — AEO: identifica partes do conteúdo para assistentes de voz
        'speakable'        => [
            '@type'       => 'SpeakableSpecification',
            'cssSelector' => ['.geo-quick-answer', 'h1.entry-title', '.entry-content p:first-of-type'],
        ],
    ];
    if ($thumb_url) {
        $article['image'] = [
            '@type' => 'ImageObject',
            'url'   => $thumb_url,
            '@id'   => $permalink . '#primaryimage',
        ];
    }
    $graph[] = $article;

    // ── 2. FAQPage Schema (rich results das perguntas no Google) ─────────
    $faq_schema_raw = get_post_meta($post_id, 'geo_faq_schema', true);
    $faq_entities   = [];

    if ($faq_schema_raw) {
        $faq_data = json_decode($faq_schema_raw, true);
        if (!empty($faq_data['mainEntity'])) {
            $faq_entities = $faq_data['mainEntity'];
        }
    }

    // Se não tem schema salvo, extrai do conteúdo HTML em tempo real
    if (empty($faq_entities)) {
        $post_content = get_post_field('post_content', $post_id);
        // Tenta formato geo-faq-item
        if (preg_match_all('/<div[^>]*geo-faq-item[^>]*>\s*<strong[^>]*>(.*?)<\/strong>\s*<p[^>]*>(.*?)<\/p>/si', $post_content, $m, PREG_SET_ORDER)) {
            foreach (array_slice($m, 0, 10) as $pair) {
                $q = trim(strip_tags($pair[1]));
                $a = trim(strip_tags($pair[2]));
                if ($q && $a) {
                    $faq_entities[] = [
                        '@type' => 'Question',
                        'name'  => $q,
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => mb_substr($a, 0, 500)],
                    ];
                }
            }
        }
        // Fallback: FAQ em strong + p dentro de seção FAQ
        if (empty($faq_entities) && preg_match('/<h2[^>]*>[^<]*(?:FAQ|Perguntas)[^<]*<\/h2>(.*?)(?=<h2|$)/si', $post_content, $sec)) {
            if (preg_match_all('/<strong[^>]*>(.*?\?)<\/strong>\s*<p[^>]*>(.*?)<\/p>/si', $sec[1], $m2, PREG_SET_ORDER)) {
                foreach (array_slice($m2, 0, 10) as $pair) {
                    $q = trim(strip_tags($pair[1]));
                    $a = trim(strip_tags($pair[2]));
                    if ($q && strlen($a) > 20) {
                        $faq_entities[] = [
                            '@type' => 'Question',
                            'name'  => $q,
                            'acceptedAnswer' => ['@type' => 'Answer', 'text' => mb_substr($a, 0, 500)],
                        ];
                    }
                }
            }
        }
    }

    if (!empty($faq_entities)) {
        $graph[] = [
            '@type'      => 'FAQPage',
            '@id'        => $permalink . '#faqpage',
            'mainEntity' => $faq_entities,
        ];
    }

    // ── 3. HowTo Schema — para artigos "Como fazer" ─────────────────────
    $kw_lower = strtolower($keyword ?? '');
    $is_howto = preg_match('/^como\s|^how\s|passo\s?a\s?passo|tutorial|guia/i', $kw_lower);
    if ($is_howto) {
        // Extrair passos do conteúdo (H3s dentro do guia passo a passo)
        $steps = [];
        if (preg_match('/<h2[^>]*>[^<]*(?:passo|guia|como)[^<]*<\/h2>(.*?)(?=<h2|$)/si', $post->post_content, $m)) {
            preg_match_all('/<h3[^>]*>(.*?)<\/h3>\s*<p[^>]*>(.*?)<\/p>/si', $m[1], $step_matches, PREG_SET_ORDER);
            foreach (array_slice($step_matches, 0, 8) as $i => $step) {
                $steps[] = [
                    '@type'    => 'HowToStep',
                    'position' => $i + 1,
                    'name'     => trim(strip_tags($step[1])),
                    'text'     => mb_substr(trim(strip_tags($step[2])), 0, 300),
                    'url'      => $permalink . '#passo-' . ($i + 1),
                ];
            }
        }
        if (!empty($steps)) {
            $graph[] = [
                '@type'       => 'HowTo',
                '@id'         => $permalink . '#howto',
                'name'        => $title,
                'description' => $excerpt,
                'step'        => $steps,
                'inLanguage'  => $lang,
            ];
        }
    }

    // ── 4. BreadcrumbList ────────────────────────────────────────────────
    $categories = get_the_category($post_id);
    $breadcrumb_items = [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $site_url . '/'],
    ];
    if (!empty($categories)) {
        $cat = $categories[0];
        $breadcrumb_items[] = [
            '@type'    => 'ListItem',
            'position' => 2,
            'name'     => $cat->name,
            'item'     => get_category_link($cat->term_id),
        ];
        $breadcrumb_items[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $title];
    } else {
        $breadcrumb_items[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $title];
    }
    $graph[] = [
        '@type'           => 'BreadcrumbList',
        '@id'             => $permalink . '#breadcrumb',
        'itemListElement' => $breadcrumb_items,
    ];

    // ── Render @graph unificado ──────────────────────────────────────────
    if (!empty($graph)) {
        $ld = ['@context' => 'https://schema.org', '@graph' => $graph];
        echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>' . "\n";
    }
}, 5);

// ── Open Graph + Twitter Cards nos posts do plugin ────────────────────────────
add_action('wp_head', function() {
    if (!is_singular('post')) return;
    $post_id = get_the_ID();
    if (!$post_id) return;

    $post      = get_post($post_id);
    $title     = get_the_title($post_id);
    $excerpt   = get_post_field('post_excerpt', $post_id) ?: wp_trim_words(strip_tags($post->post_content), 30);
    $permalink = get_permalink($post_id);
    $thumb_url = get_the_post_thumbnail_url($post_id, 'large') ?: '';
    $site_name = get_bloginfo('name');
    $date_pub  = get_post_field('post_date', $post_id);
    $date_mod  = get_post_field('post_modified', $post_id);
    $author    = get_option('geo_author_name', $site_name);
    $tw_handle = get_option('geo_social_twitter', '');
    // Extrair handle do Twitter se for URL completa
    if ($tw_handle) {
        preg_match('/twitter\.com\/([^\/\?]+)/i', $tw_handle, $tw_match);
        $tw_handle = '@' . ltrim($tw_match[1] ?? basename(rtrim($tw_handle, '/')), '@');
    }

    // Verificar se o tema/Rank Math já gera OG — evitar duplicar
    // Usamos prioridade 30 (depois do Rank Math que usa 10-20)
    echo "
<!-- Open Graph: GEO Método SEO -->
";
    echo '<meta property="og:locale" content="pt_BR">' . "
";
    echo '<meta property="og:type" content="article">' . "
";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "
";
    echo '<meta property="og:description" content="' . esc_attr(mb_substr($excerpt, 0, 200)) . '">' . "
";
    echo '<meta property="og:url" content="' . esc_url($permalink) . '">' . "
";
    echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . "
";
    echo '<meta property="og:updated_time" content="' . esc_attr(date('c', strtotime($date_mod))) . '">' . "
";
    if ($thumb_url) {
        // Tentar pegar dimensões reais da imagem
        $thumb_id = get_post_thumbnail_id($post_id);
        $img_meta = $thumb_id ? wp_get_attachment_metadata($thumb_id) : [];
        $img_w    = $img_meta['width']  ?? 1200;
        $img_h    = $img_meta['height'] ?? 675;
        echo '<meta property="og:image" content="' . esc_url($thumb_url) . '">' . "
";
        echo '<meta property="og:image:width" content="' . $img_w . '">' . "
";
        echo '<meta property="og:image:height" content="' . $img_h . '">' . "
";
        echo '<meta property="og:image:alt" content="' . esc_attr($title) . '">' . "
";
        echo '<meta property="og:image:type" content="image/jpeg">' . "
";
    }
    // Article-specific OG
    echo '<meta property="article:published_time" content="' . esc_attr(date('c', strtotime($date_pub))) . '">' . "
";
    echo '<meta property="article:modified_time" content="' . esc_attr(date('c', strtotime($date_mod))) . '">' . "
";
    echo '<meta property="article:author" content="' . esc_attr($author) . '">' . "
";
    $cats = get_the_category($post_id);
    if (!empty($cats)) {
        echo '<meta property="article:section" content="' . esc_attr($cats[0]->name) . '">' . "
";
    }
    $tags = get_the_tags($post_id);
    if ($tags) {
        foreach (array_slice($tags, 0, 5) as $tag) {
            echo '<meta property="article:tag" content="' . esc_attr($tag->name) . '">' . "
";
        }
    }

    // Twitter Cards
    echo "
<!-- Twitter Cards: GEO Método SEO -->
";
    echo '<meta name="twitter:card" content="summary_large_image">' . "
";
    echo '<meta name="twitter:title" content="' . esc_attr(mb_substr($title, 0, 70)) . '">' . "
";
    echo '<meta name="twitter:description" content="' . esc_attr(mb_substr($excerpt, 0, 200)) . '">' . "
";
    if ($thumb_url) {
        echo '<meta name="twitter:image" content="' . esc_url($thumb_url) . '">' . "
";
        echo '<meta name="twitter:image:alt" content="' . esc_attr($title) . '">' . "
";
    }
    if ($tw_handle && strlen($tw_handle) > 1) {
        echo '<meta name="twitter:site" content="' . esc_attr($tw_handle) . '">' . "
";
        echo '<meta name="twitter:creator" content="' . esc_attr($tw_handle) . '">' . "
";
    }
}, 30);

// ============================================================================
// v1.0.0 — Sistema de Licenças
// ============================================================================

// Cron: revalidar licença a cada 24h
add_action('geo_license_revalidate_event', function() {
    \GeoMetodoSEO\License\LicenseManager::clearCache();
    \GeoMetodoSEO\License\LicenseManager::validate();
});

if (!wp_next_scheduled('geo_license_revalidate_event')) {
    wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'geo_license_revalidate_event');
}

// ============================================================================
// v5.9.0 — AJAX: Pre-visualizacao de artigo
// ============================================================================
add_action('wp_ajax_geo_preview_article', function() {
    check_ajax_referer('geo_preview_article', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissao.']);

    @set_time_limit(120);

    $keyword     = sanitize_text_field($_POST['keyword']     ?? '');
    $provider    = sanitize_text_field($_POST['provider']    ?? \GeoMetodoSEO\AI\ProviderResolver::for('article_generation'));
    $model       = sanitize_text_field($_POST['model']       ?? '');
    $language    = sanitize_text_field($_POST['language']    ?? 'pt-BR');
    $template_id = intval($_POST['template_id']              ?? 0);

    if (empty($keyword)) wp_send_json_error(['message' => 'Keyword vazia.']);

    $pipeline = new \GeoMetodoSEO\Services\ArticlePipeline($provider, $model ?: null, $template_id ?: null);
    $preview  = $pipeline->preview($keyword, $language);

    if (!$preview) {
        wp_send_json_error(['message' => 'Falha ao gerar pre-visualizacao. Verifique a API Key.']);
    }

    wp_send_json_success($preview);
});

// ============================================================================
// v5.9.0 — AJAX: Gerador de Titulos por Nicho
// ============================================================================
add_action('wp_ajax_geo_generate_titles', function() {
    check_ajax_referer('geo_title_generator', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sem permissao.']);
    }
    \GeoMetodoSEO\Admin\TitleGeneratorController::ajax_generate_titles();
});
add_action('wp_ajax_geo_titlebank_export', [\GeoMetodoSEO\Admin\TitleGeneratorController::class, 'ajax_export_titlebank']);
add_action('wp_ajax_geo_titlebank_import', [\GeoMetodoSEO\Admin\TitleGeneratorController::class, 'ajax_import_titlebank']);
add_action('wp_ajax_geo_titlebank_cleanup', [\GeoMetodoSEO\Admin\TitleGeneratorController::class, 'ajax_cleanup_titlebank']);


// ============================================================================
// v1.0.0 — Banco Global de Títulos SEO/GEO
// ============================================================================
add_action('wp_ajax_geo_title_bank_pending', function() {
    check_ajax_referer('geo_title_bank_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão.']);
    wp_send_json_success(['titles' => \GeoMetodoSEO\TitleBank\SeoGeoTitleBank::pending(100)]);
});

// ============================================================================
// v5.9.0 — admin_post: Exportar / Importar Configuracoes
// ============================================================================
add_action('admin_post_geo_export_settings', function() {
    \GeoMetodoSEO\Admin\SettingsController::handle_export();
});

add_action('admin_post_geo_import_settings', function() {
    \GeoMetodoSEO\Admin\SettingsController::handle_import();
});

// v1.0.0 — CPT Web Stories
add_action('init', function() {
    if (class_exists('GeoMetodoSEO\\Services\\WebStoriesService')) {
        \GeoMetodoSEO\Services\WebStoriesService::registerCPT();
    }
});
add_action('template_redirect', function() {
    if (class_exists('GeoMetodoSEO\\Services\\WebStoriesService')) {
        \GeoMetodoSEO\Services\WebStoriesService::serveAMPTemplate();
    }
});
add_action('admin_init', function() {
    if (isset($_GET['gsc_callback'], $_GET['code']) && current_user_can('manage_options')
        && class_exists('GeoMetodoSEO\\SEO\\SearchConsoleService')) {
        $gsc = new \GeoMetodoSEO\SEO\SearchConsoleService();
        $ok  = $gsc->handle_callback(sanitize_text_field(wp_unslash($_GET['code'])));
        wp_redirect(admin_url('admin.php?page=geo-metodo-seo&gsc=' . ($ok ? 'connected' : 'error'))); exit;
    }
    if (isset($_GET['geo_gsc_disconnect']) && current_user_can('manage_options')
        && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'geo_gsc_disconnect')
        && class_exists('GeoMetodoSEO\\SEO\\SearchConsoleService')) {
        (new \GeoMetodoSEO\SEO\SearchConsoleService())->disconnect();
        wp_redirect(admin_url('admin.php?page=geo-settings&gsc=disconnected')); exit;
    }
    // 1.0.0 — Limpar último erro do GSC
    if (isset($_GET['geo_gsc_clear_error']) && current_user_can('manage_options')
        && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'geo_gsc_clear_error')
        && class_exists('GeoMetodoSEO\\SEO\\SearchConsoleService')) {
        (new \GeoMetodoSEO\SEO\SearchConsoleService())->clear_last_error();
        wp_redirect(admin_url('admin.php?page=geo-settings&gsc=error_cleared')); exit;
    }
});
add_action('init', function() {
    if (isset($_SERVER['REQUEST_URI'])) {
        $p = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        if ($p === '/llms.txt') {
            $c = get_option('geo_llmstxt_content', '');
            if ($c) { header('Content-Type: text/plain; charset=utf-8'); echo $c; exit; }
        }
    }
});
// AJAX SARA
add_action('wp_ajax_geo_sara_chat', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissao']);
    if (!class_exists('GeoMetodoSEO\\Services\\SARAService')) wp_send_json_error(['message' => 'SARA nao carregada. Verifique se os novos arquivos foram enviados via FTP.']);
    @set_time_limit(120);
    $sara = new \GeoMetodoSEO\Services\SARAService();
    $provider = sanitize_text_field($_POST['provider'] ?? '');
    wp_send_json_success(['answer' => $sara->chat(sanitize_textarea_field($_POST['question'] ?? ''), [], json_decode(stripslashes($_POST['history'] ?? '[]'), true) ?: [], $provider)]);
});
add_action('wp_ajax_geo_sara_keyword', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options') || !class_exists('GeoMetodoSEO\\Services\\SARAService')) wp_send_json_error(['message' => 'Classe nao encontrada']);
    @set_time_limit(120);
    $provider = sanitize_text_field($_POST['provider'] ?? '');
    $d = (new \GeoMetodoSEO\Services\SARAService())->analyzeKeyword(sanitize_text_field($_POST['keyword'] ?? ''), $provider);
    empty($d) ? wp_send_json_error(['message' => 'Falha']) : wp_send_json_success($d);
});
add_action('wp_ajax_geo_sara_competitor', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão']);
    if (!class_exists('GeoMetodoSEO\\Services\\SARAService')) wp_send_json_error(['message' => 'SARAService não carregado']);
    @set_time_limit(100);

    $keyword    = sanitize_text_field($_POST['keyword'] ?? '');
    $web_search = (bool)($_POST['web_search'] ?? false);
    $provider   = sanitize_text_field($_POST['provider'] ?? \GeoMetodoSEO\AI\ProviderResolver::for('article_generation'));

    if (empty($keyword)) wp_send_json_error(['message' => 'Keyword vazia']);

    $sara = new \GeoMetodoSEO\Services\SARAService();
    $d    = $sara->analyzeCompetitors($keyword, $web_search, $provider);

    if (empty($d)) {
        // Diagnóstico direto
        $ai   = new \GeoMetodoSEO\AI\AIManager();
        $test = $ai->generateText('Responda apenas: ok', $provider);
        if (!$test || $test->hasError()) {
            $err = $test ? $test->getError() : 'sem resposta';
            wp_send_json_error(['message' => "Falha na IA ({$provider}): {$err}"]);
        }
        wp_send_json_error(['message' => 'Análise retornou vazia. Tente sem a busca web marcada.']);
    }
    wp_send_json_success($d);
});
add_action('wp_ajax_geo_sara_audit', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão']);
    if (!class_exists('GeoMetodoSEO\\Services\\SARAService')) wp_send_json_error(['message' => 'SARAService não carregado']);
    @set_time_limit(120);

    $post_id  = (int)($_POST['post_id'] ?? 0);
    $provider = sanitize_text_field($_POST['provider'] ?? \GeoMetodoSEO\AI\ProviderResolver::for('article_generation'));
    // FIX v1.0.0-WORDCOUNT: aceitar 800-6000 conforme escolha do usuário.
    $word_count = max(800, min(6000, (int)($_POST['word_count'] ?? 0)));

    if (!$post_id || !get_post($post_id)) {
        wp_send_json_error(['message' => 'Post ID inválido — selecione um artigo']);
    }

    $sara = new \GeoMetodoSEO\Services\SARAService();
    $d    = $sara->auditPost($post_id, $provider);

    if (empty($d)) {
        // Diagnóstico: testar a IA diretamente
        $ai   = new \GeoMetodoSEO\AI\AIManager();
        $test = $ai->generateText('Responda: ok', $provider);
        if (!$test || $test->hasError()) {
            $err = $test ? $test->getError() : 'sem resposta';
            wp_send_json_error(['message' => "Falha na IA ({$provider}): {$err}"]);
        }
        wp_send_json_error(['message' => 'Auditoria falhou — a IA respondeu mas retornou JSON inválido. Tente novamente.']);
    }

    wp_send_json_success($d);
});
add_action('wp_ajax_geo_sara_test', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    @set_time_limit(30);
    $prov = sanitize_text_field($_POST['provider'] ?? \GeoMetodoSEO\AI\ProviderResolver::for('article_generation'));
    $ai = new \GeoMetodoSEO\AI\AIManager();
    $r  = $ai->generateText('Responda apenas: SARA online com ' . $prov, $prov);
    (!$r || $r->hasError() || !$r->getContent()) ? wp_send_json_error(['message' => 'Falhou: ' . ($r ? $r->getError() : 'sem resposta')]) : wp_send_json_success(['answer' => $r->getContent(), 'provider' => $prov]);
});
add_action('wp_ajax_geo_sara_llmstxt', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options') || !class_exists('GeoMetodoSEO\\Services\\SARAService')) wp_send_json_error();
    @set_time_limit(120);
    $c = (new \GeoMetodoSEO\Services\SARAService())->generateLlmsTxt();
    empty($c) ? wp_send_json_error(['message' => 'Falha']) : wp_send_json_success(['content' => $c]);
});
add_action('wp_ajax_geo_sara_llmstxt_save', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    $c = sanitize_textarea_field($_POST['content'] ?? '');
    update_option('geo_llmstxt_content', $c);
    if (is_writable(ABSPATH)) file_put_contents(ABSPATH . 'llms.txt', $c);
    wp_send_json_success(['message' => 'Salvo']);
});
add_action('wp_ajax_geo_youtube_to_article', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sem permissão.']);
    }
    if (!class_exists('GeoMetodoSEO\\Services\\YouTubeToArticleService')) {
        wp_send_json_error(['message' => 'YouTubeToArticleService não carregado. Reative o plugin e tente novamente.']);
    }

    @set_time_limit(420);
    $buffer_started = false;
    if (!headers_sent()) {
        ob_start();
        $buffer_started = true;
    }

    try {
        $yt    = new \GeoMetodoSEO\Services\YouTubeToArticleService();
        $vid   = $yt->extractVideoId(esc_url_raw($_POST['url'] ?? ''));
        if (!$vid) {
            if ($buffer_started) { @ob_end_clean(); }
            wp_send_json_error(['message' => 'URL inválida. Cole uma URL pública do YouTube.']);
        }

        $lang       = sanitize_text_field($_POST['lang']  ?? 'pt-BR');
        $model      = sanitize_text_field($_POST['model'] ?? '');
        $provider   = sanitize_text_field($_POST['provider'] ?? \GeoMetodoSEO\AI\ProviderResolver::for('article_generation'));
        // FIX v1.0.0-WORDCOUNT: aceitar 800-6000 conforme escolha do usuário.
    $word_count = max(800, min(6000, (int)($_POST['word_count'] ?? 0)));

        \GeoMetodoSEO\Services\LogService::log('info', "YouTubeToArticle: iniciando vídeo {$vid} provider={$provider} model=" . ($model ?: 'configuração') . " words={$word_count}");

        $data = $yt->generateArticle($vid, $lang, $provider, $model, $word_count);

        if (!$data) {
            $ai   = new \GeoMetodoSEO\AI\AIManager();
            $test = $ai->generateText('Responda apenas: ok', $provider, $model ?: null);
            if (!$test || $test->hasError()) {
                $err = $test ? $test->getError() : 'sem resposta da API';
                if ($buffer_started) { @ob_end_clean(); }
                wp_send_json_error(['message' => "Falha na API ({$provider}/" . ($model ?: 'padrão') . "): {$err}. Verifique chave, acesso ao modelo e billing."]);
            }
            $logs = \GeoMetodoSEO\Services\LogService::get_recent(6);
            $last_err = '';
            foreach ($logs as $log) {
                $msg_val = is_object($log) ? ($log->message ?? '') : ($log['message'] ?? '');
                if (stripos($msg_val, 'YouTubeToArticle') !== false || stripos($msg_val, 'OpenAI') !== false) {
                    $last_err = wp_strip_all_tags((string)$msg_val);
                    break;
                }
            }
            if ($buffer_started) { @ob_end_clean(); }
            wp_send_json_error(['message' => $last_err ?: 'Falha ao gerar artigo. Veja Logs do Plugin para o detalhe técnico.']);
        }

        $pid = $yt->createPost($data, sanitize_text_field($_POST['status'] ?? 'draft'), sanitize_text_field($_POST['category'] ?? ''));
        if (is_wp_error($pid)) {
            if ($buffer_started) { @ob_end_clean(); }
            wp_send_json_error(['message' => $pid->get_error_message()]);
        }

        if ($buffer_started) { @ob_end_clean(); }
        wp_send_json_success([
            'post_id'      => $pid,
            'title'        => $data['title'],
            'word_count'   => (int)($data['word_count_real'] ?? 0),
            'word_target'  => (int)($data['word_count_target'] ?? 0),
            'edit_url'     => get_edit_post_link($pid, 'raw'),
            'view_url'     => get_permalink($pid),
            'message'      => 'Artigo criado. Imagens internas do YouTube foram enfileiradas em segundo plano para evitar timeout.',
        ]);
    } catch (\Throwable $e) {
        \GeoMetodoSEO\Services\LogService::log('error', 'YouTubeToArticle: erro fatal capturado — ' . $e->getMessage());
        if ($buffer_started) { @ob_end_clean(); }
        wp_send_json_error(['message' => 'Erro técnico no YouTube → Artigo: ' . $e->getMessage()]);
    }
});
add_action('wp_ajax_geo_tts_generate', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissao']);
    if (!class_exists('GeoMetodoSEO\\Services\\TTSService')) wp_send_json_error(['message' => 'TTSService nao carregado']);

    @set_time_limit(100);
    $post_id = (int)($_POST['post_id'] ?? 0);
    $tts     = new \GeoMetodoSEO\Services\TTSService();

    // Verificar se está configurado
    if (!$tts->isConfigured()) {
        $openai_key  = get_option('geo_openai_api_key', '') ?: get_option('geo_api_key_openai', '');
        $el_key      = get_option('geo_elevenlabs_api_key', '');
        $google_key  = get_option('geo_tts_google_key', '');
        $hint = 'Nenhum provedor TTS configurado. ';
        if (!$openai_key) $hint .= 'Configure a OpenAI API Key (TTS-1) ';
        if (!$el_key)     $hint .= 'ou a ElevenLabs API Key ';
        $hint .= 'em Configuracoes > TTS.';
        wp_send_json_error(['message' => $hint]);
    }

    // Remover áudio anterior se existir
    $old_url = get_post_meta($post_id, '_geo_audio_url', true);
    if ($old_url) {
        $old_id = get_post_meta($post_id, '_geo_audio_attachment_id', true);
        if ($old_id) wp_delete_attachment($old_id, true);
        delete_post_meta($post_id, '_geo_audio_url');
        delete_post_meta($post_id, '_geo_audio_attachment_id');
    }

    // try/catch para garantir que qualquer erro vire JSON limpo, nunca HTTP 500.
    // O erro crítico do WordPress acontecia quando processPost lançava exceção/fatal
    // e o servidor devolvia HTML de erro em vez de JSON.
    try {
        $ok = $tts->processPost($post_id);
    } catch (\Throwable $e) {
        if (class_exists('GeoMetodoSEO\\Services\\LogService')) {
            \GeoMetodoSEO\Services\LogService::log('error', 'TTS: exceção ao gerar áudio — ' . $e->getMessage());
        }
        wp_send_json_error(['message' => 'Erro ao gerar áudio: ' . $e->getMessage()
            . '. Verifique a API Key do provedor e os créditos disponíveis.']);
    }

    if ($ok) {
        $audio_url = get_post_meta($post_id, '_geo_audio_url', true);
        wp_send_json_success(['message' => 'Audio gerado com sucesso!', 'audio_url' => $audio_url]);
    } else {
        // Tentar diagnosticar o motivo
        $provider = get_option('geo_tts_provider', 'openai');
        $el_key   = get_option('geo_elevenlabs_api_key', '');
        $el_voice  = get_option('geo_tts_elevenlabs_voice_id', 'pNInz6obpgDQGcFmaJgB');

        $hint = 'Falha ao gerar audio. ';
        if ($provider === 'elevenlabs') {
            if (empty($el_key)) {
                $hint .= 'ElevenLabs: API Key nao configurada.';
            } else {
                // Testar a API do ElevenLabs
                $test = wp_remote_get('https://api.elevenlabs.io/v1/voices/' . $el_voice, [
                    'timeout' => 10,
                    'headers' => ['xi-api-key' => $el_key],
                ]);
                $code = is_wp_error($test) ? 0 : wp_remote_retrieve_response_code($test);
                if ($code === 401) $hint .= 'ElevenLabs: API Key invalida ou sem creditos.';
                elseif ($code === 404) $hint .= 'ElevenLabs: Voice ID nao encontrado. Verifique o Voice ID nas Configuracoes.';
                elseif ($code !== 200) $hint .= "ElevenLabs: erro HTTP {$code}. Verifique seus creditos.";
                else $hint .= 'ElevenLabs: falha ao salvar o audio. Verifique permissoes da pasta uploads.';
            }
        } else {
            $hint .= 'Verifique a API Key do OpenAI e os creditos disponíveis.';
        }

        wp_send_json_error(['message' => $hint]);
    }
});
add_action('wp_ajax_geo_tts_remove', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options') || !class_exists('GeoMetodoSEO\\Services\\TTSService')) wp_send_json_error();
    (new \GeoMetodoSEO\Services\TTSService())->removeAudio((int)($_POST['post_id'] ?? 0));
    wp_send_json_success();
});
add_action('wp_ajax_geo_webstory_generate', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options') || !class_exists('GeoMetodoSEO\\Services\\WebStoriesService')) wp_send_json_error(['message' => 'Servico nao carregado']);
    @set_time_limit(300);
    $ws = new \GeoMetodoSEO\Services\WebStoriesService();
    $r  = $ws->generateFromPost((int)($_POST['post_id'] ?? 0), '', sanitize_text_field($_POST['image_provider'] ?? 'auto'));
    is_wp_error($r) ? wp_send_json_error(['message' => $r->get_error_message()]) : wp_send_json_success(['story_id' => $r, 'story_url' => get_permalink($r), 'edit_url' => get_edit_post_link($r, 'raw'), 'message' => 'Story gerada!']);
});
add_action('wp_ajax_geo_webstory_batch', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options') || !class_exists('GeoMetodoSEO\\Services\\WebStoriesService')) wp_send_json_error();
    @set_time_limit(600);
    $ids = array_map('intval', json_decode(stripslashes($_POST['post_ids'] ?? '[]'), true) ?: []);
    $results = (new \GeoMetodoSEO\Services\WebStoriesService())->generateBatch($ids, '', sanitize_text_field($_POST['image_provider'] ?? 'auto'));
    wp_send_json_success(['results' => $results, 'count' => count(array_filter($results, fn($r) => isset($r['story_id']) && empty($r['skipped'])))]);
});
add_action('wp_ajax_geo_webstory_save_settings', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    update_option('geo_webstory_adsense_pub_id',  sanitize_text_field($_POST['pub_id']  ?? ''));
    update_option('geo_webstory_adsense_slot_id', sanitize_text_field($_POST['slot_id'] ?? ''));
    update_option('geo_webstory_ga4_id',          sanitize_text_field($_POST['ga4_id']  ?? ''));
    wp_send_json_success();
});

// ── Worker paralelo por keyword (geo_process_single_keyword_event) ──────────
// CRÍTICO: sem este handler, todos os crons de paralelismo são perdidos
add_action('geo_process_single_keyword_event', function($keyword, $job_index) {
    // GATE DE LICENÇA: artigo agendado só roda se a licença estiver ATIVA.
    // Plano vencido (mensal/anual) = agendados NÃO são processados. canGenerate()
    // respeita a expiração; volta a rodar após renovar.
    if (!class_exists('\GeoMetodoSEO\License\LicenseManager')
        || !\GeoMetodoSEO\License\LicenseManager::canGenerate()) {
        return;
    }
    $queue = get_option('geo_scheduled_queue', []);
    if (!isset($queue[$job_index])) return;

    $job = &$queue[$job_index];

    // Verificações de segurança
    if ($job['keyword'] !== $keyword) return;
    if ($job['status'] !== 'pending') return;

    // Lock individual por keyword+índice
    $lock_key = 'geo_lock_' . md5($keyword . '_' . $job_index);
    if (!geo_metodo_acquire_lock($lock_key, 10 * MINUTE_IN_SECONDS)) {
        // Se já existe lock, não muda o status. O guard de stale jobs limpa travamentos antigos.
        return;
    }

    $job['status'] = 'processing';
    $job['started_at'] = time();
    $job['updated_at'] = time();
    $job['last_error'] = '';
    update_option('geo_scheduled_queue', $queue);
    @set_time_limit(240);

    try {
        if (($job['type'] ?? '') === 'cluster') {
            // Jobs do Cluster só são processados quando chegam no horário;
            // neste momento devem publicar agora, não criar post_status=future vencido.
            $job['post_status'] = 'publish';
            $post_id = geo_cluster_process_article_with_recovery($job);
        } else {
            $pipeline = new \GeoMetodoSEO\Services\ArticlePipeline(
                \GeoMetodoSEO\AI\ProviderResolver::for('bulk_generation', $job['provider'] ?? ''),
                $job['model']    ?? null,
                $job['template_id'] ?? null
            );

            $post_status = ($job['is_first'] ?? false) ? 'publish' : ($job['post_status'] ?? 'draft');
            $category    = $job['category'] ?? 'auto';

            $post_id = $pipeline->process(
                $keyword,
                $job['language']  ?? 'pt-BR',
                $post_status,
                '',
                $job['tone']      ?? '',
                $category,
                in_array(($job['article_size'] ?? ''), ['small','medium','large','cluster_satellite','cluster_pillar'], true) ? $job['article_size'] : get_option('geo_article_size', 'medium')
            );
        }

        $job['status']  = ($post_id && !is_wp_error($post_id)) ? 'done' : 'failed';
        $job['post_id'] = ($post_id && !is_wp_error($post_id)) ? $post_id : null;
        $job['done_at'] = time();
        $job['updated_at'] = time();
        if ($job['status'] === 'failed') {
            $job['last_error'] = is_wp_error($post_id) ? $post_id->get_error_message() : 'ArticlePipeline retornou vazio/falso.';
        }

        if ($job['status'] === 'done') {
            if (class_exists('GeoMetodoSEO\\TitleBank\\SeoGeoTitleBank')) {
                \GeoMetodoSEO\TitleBank\SeoGeoTitleBank::mark_used_by_title((string)$keyword, 'bulk_parallel_cron', (int)$post_id);
            }
            \GeoMetodoSEO\Services\LogService::log('success', 'Worker paralelo: artigo gerado para "' . $keyword . '" (post #' . $post_id . ')');
        } else {
            \GeoMetodoSEO\Services\LogService::log('error', 'Worker paralelo: falha ao gerar "' . $keyword . '"');
        }

    } catch (\Throwable $e) {
        $job['status'] = 'failed';
        $job['done_at'] = time();
        $job['updated_at'] = time();
        $job['last_error'] = $e->getMessage();
        \GeoMetodoSEO\Services\LogService::log('error', 'Worker paralelo exception para "' . $keyword . '": ' . $e->getMessage());
    } finally {
        geo_metodo_release_lock($lock_key);
        update_option('geo_scheduled_queue', $queue);
    }
}, 10, 2);





// ============================================================================
// Web Stories — Geração Automática após criação de artigo
// ============================================================================

// Hook: quando um artigo é gerado pelo plugin, criar Web Story automaticamente
add_action('geo_article_generated', function($post_id, $keyword) {
    // Verificar se a opção está ativada
    if (!get_option('geo_auto_webstory', 0)) return;

    // Evitar duplicatas (se já tem story, não gerar novamente)
    $existing = get_post_meta($post_id, '_geo_web_story_id', true);
    if ($existing && get_post($existing)) return;

    // Não bloquear a resposta principal — agendar para daqui 30s via cron
    if (!wp_next_scheduled('geo_generate_story_delayed', [$post_id])) {
        wp_schedule_single_event(time() + 30, 'geo_generate_story_delayed', [$post_id]);
    }

}, 10, 2);

// Fallback global: alguns fluxos do plugin não disparam geo_article_generated.
// Este hook garante Web Story automática para todo post publicado pelo plugin.
if (!function_exists('geo_metodo_schedule_auto_webstory')) {
    function geo_metodo_schedule_auto_webstory(int $post_id): void {
        if (!get_option('geo_auto_webstory', 0)) return;
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        $existing = get_post_meta($post_id, '_geo_web_story_id', true);
        if ($existing && get_post($existing)) return;
        if (wp_next_scheduled('geo_generate_story_delayed', [$post_id])) return;
        wp_schedule_single_event(time() + 45, 'geo_generate_story_delayed', [$post_id]);
    }
}
add_action('transition_post_status', function($new_status, $old_status, $post) {
    if ($new_status === 'publish' && is_object($post) && isset($post->ID)) {
        geo_metodo_schedule_auto_webstory((int)$post->ID);
    }
}, 30, 3);
add_action('save_post_post', function($post_id, $post, $update) {
    if ($update) geo_metodo_schedule_auto_webstory((int)$post_id);
}, 30, 3);

// Worker do cron de geração automática de story
add_action('geo_generate_story_delayed', function($post_id) {
    if (!class_exists('GeoMetodoSEO\\Services\\WebStoriesService')) return;

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;

    // Usar a cadeia oficial: Featured Fal.ai -> Replicate; Corpo Replicate -> Fal.ai; Web Stories Naga.ac -> HuggingFace
    $ws = new \GeoMetodoSEO\Services\WebStoriesService();
    $result = $ws->generateFromPost((int)$post_id, '', 'auto');

    if (!is_wp_error($result)) {
        \GeoMetodoSEO\Services\LogService::log('info', "Web Story automática gerada para post #{$post_id}");
    } else {
        \GeoMetodoSEO\Services\LogService::log('error', "Web Story automática falhou para post #{$post_id}: " . $result->get_error_message());
    }
});

// Registrar o evento cron
if (!wp_next_scheduled('geo_generate_story_delayed')) {
    // Evento é schedule_single_event — não precisa registrar aqui, só o handler
}

// ── AJAX: Cancelar job pendente da fila ──────────────────────────────────────
add_action('wp_ajax_geo_cancel_queue_job', function() {
    check_ajax_referer('geo_cancel_job_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão']);

    $idx   = (int)($_POST['job_index'] ?? -1);
    $queue = get_option('geo_scheduled_queue', []);

    if (!isset($queue[$idx])) {
        wp_send_json_error(['message' => 'Job não encontrado']);
    }

    if ($queue[$idx]['status'] !== 'pending') {
        wp_send_json_error(['message' => 'Só é possível cancelar jobs com status "pendente"']);
    }

    $keyword = $queue[$idx]['keyword'] ?? '';
    $queue[$idx]['status']      = 'cancelled';
    $queue[$idx]['cancelled_at'] = time();

    update_option('geo_scheduled_queue', $queue);

    // Remover cron individual se existir
    $timestamp = wp_next_scheduled('geo_process_single_keyword_event', [$keyword, $idx]);
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'geo_process_single_keyword_event', [$keyword, $idx]);
    }

    \GeoMetodoSEO\Services\LogService::log('info', "Job cancelado: #{$idx} — {$keyword}");
    wp_send_json_success(['message' => "Job '{$keyword}' cancelado com sucesso"]);
});

// ── SARA: Handlers de Memória ────────────────────────────────────────────────
add_action('wp_ajax_geo_sara_memory_get', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    wp_send_json_success(['memory' => \GeoMetodoSEO\Services\SARAService::get_memory()]);
});

add_action('wp_ajax_geo_sara_memory_clear', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    \GeoMetodoSEO\Services\SARAService::clear_memory();
    wp_send_json_success(['message' => 'Memória limpa']);
});

add_action('wp_ajax_geo_sara_memory_save', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    $key   = sanitize_key($_POST['key'] ?? 'nota');
    $value = sanitize_textarea_field($_POST['value'] ?? '');
    if ($value) \GeoMetodoSEO\Services\SARAService::save_memory($key, $value);
    wp_send_json_success();
});

// ── SARA: Melhorias Pontuais (cirúrgico — substitui DENTRO do artigo) ──────────
add_action('wp_ajax_geo_sara_surgical_improvements', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão']);
    if (!class_exists('GeoMetodoSEO\\Services\\SARAService')) wp_send_json_error(['message' => 'SARA não carregada']);

    $post_id = (int)($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error(['message' => 'Post ID inválido']);

    $post = get_post($post_id);
    if (!$post) wp_send_json_error(['message' => 'Post não encontrado']);

    @set_time_limit(100);

    $provider_request = sanitize_text_field($_POST['provider'] ?? '');
    $sara    = new \GeoMetodoSEO\Services\SARAService();
    $audit   = $sara->auditPost($post_id, $provider_request);
    if (empty($audit)) wp_send_json_error(['message' => 'Falha na auditoria. Verifique a API Key.']);

    $keyword  = get_post_meta($post_id, '_geo_keyword', true) ?: $post->post_title;
    $provider = \GeoMetodoSEO\AI\ProviderResolver::for('article_generation', $provider_request ?? sanitize_text_field($_POST['provider'] ?? ''));
    $year     = date('Y');
    $content  = $post->post_content;

    $author_name = get_option('geo_author_name', get_bloginfo('name'));
    $applied     = [];
    $ai          = new \GeoMetodoSEO\AI\AIManager();
    $improvements = $audit['improvements'] ?? [];
    $critical     = $audit['critical_issues'] ?? [];

    // ── Função interna: identificar e SUBSTITUIR seção problemática ──────────
    // Em vez de ADICIONAR conteúdo, LOCALIZAMOS onde o problema existe e REESCREVEMOS
    // aquela seção específica no lugar correto.

    // Extrair seções do artigo por H2
    $sections = [];
    // Dividir por H2/H3
    $parts = preg_split('/(<h[23][^>]*>.*?<\/h[23]>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $sections_raw = [];
    $current_title = '__intro__';
    $current_body  = '';
    foreach ($parts as $part) {
        if (preg_match('/^<h[23]/i', $part)) {
            if ($current_body || $current_title !== '__intro__') {
                $sections_raw[] = ['title' => $current_title, 'body' => $current_body];
            }
            $current_title = $part;
            $current_body  = '';
        } else {
            $current_body .= $part;
        }
    }
    if ($current_body || $current_title !== '__intro__') {
        $sections_raw[] = ['title' => $current_title, 'body' => $current_body];
    }

    // Para cada problema, identificar qual seção corrigir e reescrever APENAS ela
    $issues_to_fix = array_merge(
        array_slice($critical, 0, 2),
        array_map(function($i) { return ($i['priority'] ?? '') === 'alta' ? $i['action'] : null; }, $improvements)
    );
    $issues_to_fix = array_filter($issues_to_fix);

    foreach (array_slice($issues_to_fix, 0, 4) as $issue) {
        if (!$issue) continue;

        // Identificar qual seção do artigo precisa ser corrigida
        $locate_prompt = 'Artigo sobre "' . $keyword . '". Problema identificado: "' . $issue . '"' . "

"
                       . 'Seções disponíveis:' . "
"
                       . implode("
", array_map(function($s, $i) {
                             return $i . ': ' . wp_strip_all_tags($s['title']) . ' — ' . mb_substr(wp_strip_all_tags($s['body']), 0, 100);
                         }, $sections_raw, array_keys($sections_raw)))
                       . "

Qual é o ÍNDICE NUMÉRICO (0, 1, 2...) da seção onde este problema deve ser corrigido? "
                       . 'Responda APENAS com o número.';

        $locate_response = $ai->generateText($locate_prompt, $provider);
        $section_idx = 0;
        if ($locate_response && !$locate_response->hasError()) {
            $idx_raw = trim(preg_replace('/[^0-9]/', '', $locate_response->getContent()));
            if (is_numeric($idx_raw) && isset($sections_raw[(int)$idx_raw])) {
                $section_idx = (int)$idx_raw;
            }
        }

        $target_section = $sections_raw[$section_idx] ?? null;
        if (!$target_section) continue;

        $section_content = $target_section['title'] . $target_section['body'];
        $section_text    = mb_substr(wp_strip_all_tags($section_content), 0, 1000);

        // Reescrever APENAS esta seção para corrigir o problema
        $rewrite_prompt = 'Você é especialista em SEO, GEO e E-E-A-T. Reescreva APENAS a seção abaixo para corrigir este problema: "' . $issue . '"' . "

"
                        . 'Seção atual:' . "
" . $section_text . "

"
                        . 'REGRAS ESTRITAS:' . "
"
                        . '- Mantenha o mesmo H2/H3 título (ou melhore levemente)' . "
"
                        . '- Mantenha o mesmo contexto da seção' . "
"
                        . '- Corrija APENAS o problema indicado, não reescreva o que está bom' . "
"
                        . '- NÃO adicione novas seções, NÃO duplique conteúdo' . "
"
                        . '- Use no máximo 2 links internos, nenhum link externo novo' . "
"
                        . '- Ano: ' . $year . '. Autor: ' . $author_name . "
"
                        . 'Retorne APENAS o HTML da seção reescrita (com o H2/H3 e o conteúdo).';

        $rewrite_response = $ai->generateText($rewrite_prompt, $provider);
        if (!$rewrite_response || $rewrite_response->hasError()) continue;

        $new_section = wp_kses_post($rewrite_response->getContent());
        $new_section = preg_replace('/^```html?\s*/i', '', trim($new_section));
        $new_section = preg_replace('/```\s*$/i', '', $new_section);

        if (strlen(wp_strip_all_tags($new_section)) < 50) continue;

        // SUBSTITUIR a seção no conteúdo original (não adicionar)
        $old_section_escaped = preg_quote($section_content, '/');
        $new_content = preg_replace('/' . $old_section_escaped . '/s', $new_section, $content, 1, $count);

        if ($count > 0) {
            $content = $new_content;
            $applied[] = 'Corrigido na seção "' . mb_substr(wp_strip_all_tags($target_section['title']), 0, 60) . '": ' . mb_substr($issue, 0, 60);
        }
    }

    if (empty($applied)) {
        wp_send_json_error(['message' => 'Não foi possível localizar as seções para corrigir. O artigo pode ser muito longo ou já está correto.']);
    }

    // Aplicar linkagem interna
    $linker  = new \GeoMetodoSEO\SEO\InternalLinkingService();
    $content = $linker->apply($post_id, $content);

    // Salvar sem duplicar
    \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content, 'post_status' => 'publish']);

    // Reaplicar E-E-A-T com dados do plugin
    $eeat = new \GeoMetodoSEO\EEAT\EEATEngine();
    $eeat->apply($post_id);

    update_post_meta($post_id, '_geo_sara_improved_at', current_time('mysql'));

    wp_send_json_success([
        'count'    => count($applied),
        'applied'  => $applied,
        'edit_url' => get_edit_post_link($post_id, 'raw'),
        'view_url' => get_permalink($post_id),
    ]);
});

// ── SARA: Aplicar melhorias da auditoria e republicar ────────────────────────
add_action('wp_ajax_geo_sara_apply_improvements', function() {
    check_ajax_referer('geo_sara_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Sem permissão']);
    if (!class_exists('GeoMetodoSEO\\Services\\SARAService')) wp_send_json_error(['message' => 'SARA não carregada']);

    $post_id = (int)($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error(['message' => 'Post ID inválido']);

    $post = get_post($post_id);
    if (!$post) wp_send_json_error(['message' => 'Post não encontrado']);

    @set_time_limit(100);

    // 1. Fazer nova auditoria para pegar os problemas atuais
    $provider_request = sanitize_text_field($_POST['provider'] ?? '');
    $sara = new \GeoMetodoSEO\Services\SARAService();
    $audit = $sara->auditPost($post_id, $provider_request);

    if (empty($audit)) {
        wp_send_json_error(['message' => 'Falha ao auditar. Verifique a API Key.']);
    }

    $score_before       = $audit['overall_score'] ?? 0;
    $critical_issues    = $audit['critical_issues'] ?? [];
    $improvements       = $audit['improvements'] ?? [];
    $geo_improvements   = $audit['geo_improvements'] ?? [];
    $missing_elements   = $audit['missing_elements'] ?? [];
    $keyword            = get_post_meta($post_id, '_geo_keyword', true) ?: $post->post_title;
    $provider           = \GeoMetodoSEO\AI\ProviderResolver::for('article_generation', $provider_request ?? sanitize_text_field($_POST['provider'] ?? ''));
    $year               = date('Y');

    // 2. Montar lista de melhorias para aplicar
    $all_improvements = [];
    foreach ($critical_issues as $issue) {
        $all_improvements[] = "CRÍTICO: {$issue}";
    }
    foreach ($improvements as $imp) {
        if (($imp['priority'] ?? '') === 'alta') {
            $all_improvements[] = "ALTA PRIORIDADE: " . ($imp['action'] ?? '');
        }
    }
    foreach ($geo_improvements as $geo) {
        $all_improvements[] = "GEO/LLM: {$geo}";
    }
    foreach ($missing_elements as $missing) {
        $all_improvements[] = "ADICIONAR: {$missing}";
    }

    if (empty($all_improvements)) {
        wp_send_json_error(['message' => 'Nenhuma melhoria crítica encontrada. Artigo já está bom!']);
    }

    // 3. Reescrever o artigo aplicando todas as melhorias
    $content_atual = wp_strip_all_tags($post->post_content);
    $content_short = mb_substr($content_atual, 0, 6000);
    $improvements_list = implode("
- ", $all_improvements);

    $year = date('Y');
    $prompt = 'Você é um especialista sênior em SEO, GEO e E-E-A-T com 15 anos de experiência em criação de conteúdo de alta autoridade.' . "\n\n"
            . 'Reescreva o artigo sobre "' . $keyword . '" corrigindo estes problemas: ' . $improvements_list . "\n\n"
            . 'ESTRUTURA OBRIGATÓRIA DO ARTIGO REESCRITO:' . "\n"
            . '1. RESPOSTA RÁPIDA (2-3 frases diretas para featured snippet)' . "\n"
            . '   Formato: <div class="geo-quick-answer" style="background:linear-gradient(135deg,rgba(0,200,255,0.08),rgba(139,92,246,0.08));border-left:4px solid #00C8FF;padding:18px 22px;margin:0 0 32px;border-radius:0 10px 10px 0;"><strong style="color:#00C8FF;display:block;margin-bottom:8px;">⚡ Resposta Rápida</strong><p>[resposta direta]</p></div>' . "\n"
            . '2. INTRODUÇÃO com dado/estatística impactante (150-200 palavras)' . "\n"
            . '3. H2: O que é [keyword] — definição técnica precisa (250-300 palavras)' . "\n"
            . '4. H2: Como funciona — mecanismo detalhado com H3s (300-350 palavras)' . "\n"
            . '5. TABELA COMPARATIVA — mínimo 5 linhas com dados reais' . "\n"
            . '   Formato: <table style="width:100%;border-collapse:collapse;margin:24px 0;"><thead><tr style="background:#1a1a2e;color:#fff;"><th style="padding:12px;border:1px solid #ddd;">Critério</th>...</tr></thead><tbody>...</tbody></table>' . "\n"
            . '6. H2: Benefícios práticos — 5-6 benefícios com dados mensuráveis (300 palavras)' . "\n"
            . '7. H2: Guia passo a passo — 5 passos com H3s acionáveis (350 palavras)' . "\n"
            . '8. DICA DE ESPECIALISTA' . "\n"
            . '   Formato: <blockquote style="border-left:4px solid #8B5CF6;padding:16px 20px;background:rgba(139,92,246,0.08);margin:28px 0;border-radius:0 8px 8px 0;"><strong>💡 Dica de Especialista:</strong> [insight avançado único]</blockquote>' . "\n"
            . '9. H2: Erros comuns e como evitar (200-250 palavras)' . "\n"
            . '10. H2: Tendências em ' . $year . ' (200 palavras)' . "\n"
            . '11. FAQ (8 perguntas específicas sobre a keyword — NÃO genéricas)' . "\n"
            . '    Formato: <div class="geo-faq-item"><strong>[Pergunta específica?]</strong><p>[Resposta com mínimo 80 palavras e exemplo concreto.]</p></div>' . "\n"
            . '12. CONCLUSÃO com CTA (150 palavras)' . "\n\n"
            . 'REGRAS ABSOLUTAS:' . "\n"
            . '- NUNCA duplicar conteúdo — cada seção tem conteúdo único' . "\n"
            . '- NUNCA inventar estatísticas ou dados sem fonte verificável' . "\n"
            . '- Ano de referência: ' . $year . ' — NUNCA use anos anteriores como atuais' . "\n"
            . '- Autor do artigo: ' . get_option('geo_author_name', get_bloginfo('name')) . ' — não inventar' . "\n"
            . '- Use no máximo 3 links externos e priorize links internos' . "\n"
            . '- Mínimo 2.500 palavras totais' . "\n\n"
            . 'ARTIGO ATUAL (trecho para contexto):\n' . $content_short . "\n\n"
            . 'Retorne APENAS o HTML do conteúdo (sem H1, sem JSON, sem markdown). Use tags HTML: <p>, <h2>, <h3>, <ul>, <li>, <strong>, <table>, <blockquote>, <div>.';

    $ai = new \GeoMetodoSEO\AI\AIManager();
    $response = $ai->generateText($prompt, $provider);

    if (!$response || $response->hasError() || !$response->getContent()) {
        wp_send_json_error(['message' => 'Falha na IA: ' . ($response ? $response->getError() : 'sem resposta')]);
    }

    $new_content = $response->getContent();

    // Limpar markdown caso a IA retorne
    $new_content = preg_replace('/^```html?\s*/i', '', trim($new_content));
    $new_content = preg_replace('/```\s*$/i', '', $new_content);

    // Validar tamanho mínimo
    if (strlen(wp_strip_all_tags($new_content)) < 1000) {
        wp_send_json_error(['message' => 'Conteúdo gerado muito curto. Tente novamente.']);
    }

    // Aplicar sanitização permitindo iframes
    $allowed = array_merge(wp_kses_allowed_html('post'), [
        'iframe' => ['src' => true, 'width' => true, 'height' => true, 'frameborder' => true, 'allowfullscreen' => true, 'allow' => true, 'style' => true],
        'figure' => ['style' => true, 'class' => true],
        'figcaption' => ['style' => true, 'class' => true],
    ]);
    $new_content = wp_kses($new_content, $allowed);

    // 4. Aplicar linkagem interna
    $linker = new \GeoMetodoSEO\SEO\InternalLinkingService();
    $new_content = $linker->apply($post_id, $new_content);

    // 5. Atualizar o post
    $update_result = \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
        'ID'           => $post_id,
        'post_content' => $new_content,
        'post_status'  => 'publish',
    ]);

    if (is_wp_error($update_result)) {
        wp_send_json_error(['message' => 'Erro ao salvar: ' . $update_result->get_error_message()]);
    }

    // 6. Aplicar imagens no corpo via cadeia oficial Replicate -> Fal.ai
    $pipeline = new \GeoMetodoSEO\Services\ArticlePipeline($provider);
    $new_content_with_images = $pipeline->insert_body_images_public($post_id, $new_content, $keyword);
    if ($new_content_with_images && strlen($new_content_with_images) > strlen($new_content)) {
        $new_content = $new_content_with_images;
        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $new_content]);
    }

    // 7. Limpar _geo_faq_raw antigo para que o EEATEngine extraia as novas FAQs do conteúdo reescrito
    delete_post_meta($post_id, '_geo_faq_raw');
    delete_post_meta($post_id, 'geo_faq_schema');    // Limpar schema FAQ antigo
    delete_post_meta($post_id, 'geo_article_schema'); // Limpar schema Article antigo
    delete_post_meta($post_id, 'geo_person_schema');  // Limpar Person schema antigo

    // Reaplicar E-E-A-T completo: extrai novas FAQs, regenera schemas, refaz box do autor
    $eeat = new \GeoMetodoSEO\EEAT\EEATEngine();
    $eeat->apply($post_id);

    // Reaplicar Rank Math / Yoast com keyword atual
    $rm = new \GeoMetodoSEO\SEO\RankMathIntegration();
    $rm->apply($post_id, get_the_title($post_id), $keyword, mb_substr(wp_strip_all_tags($new_content), 0, 155));

    // 8. Atualizar meta de auditoria
    update_post_meta($post_id, '_geo_sara_improved_at', current_time('mysql'));
    update_post_meta($post_id, '_geo_sara_score_before', $score_before);
    update_post_meta($post_id, '_geo_keyword', $keyword); // Garantir keyword mantida

    // 7. Re-auditar para ver melhoria
    $score_after_estimate = min(100, $score_before + (count($all_improvements) * 5));

    $applied_list = array_slice($all_improvements, 0, 8);

    \GeoMetodoSEO\Services\LogService::log('success', "SARA aplicou " . count($all_improvements) . " melhorias no post #{$post_id}");

    wp_send_json_success([
        'score_before'        => $score_before,
        'score_after'         => $score_after_estimate,
        'improvements_applied' => count($all_improvements),
        'applied_list'        => $applied_list,
        'edit_url'            => get_edit_post_link($post_id, 'raw'),
        'view_url'            => get_permalink($post_id),
        'message'             => 'Artigo melhorado e republicado com sucesso!',
    ]);
});




// ============================================================================
// Web Stories — Editor slide por slide no admin
// ============================================================================
add_action('add_meta_boxes', function() {
    add_meta_box(
        'geo_webstory_slides_editor',
        'Editor de Slides da Web Story',
        'geo_render_webstory_slides_metabox',
        'geo-web-story',
        'normal',
        'high'
    );
});

add_action('admin_enqueue_scripts', function($hook) {
    global $post;
    if (($hook === 'post.php' || $hook === 'post-new.php') && $post && $post->post_type === 'geo-web-story') {
        wp_enqueue_media();
    }
});

function geo_render_webstory_slides_metabox(\WP_Post $post): void {
    if (!current_user_can('edit_post', $post->ID)) return;

    wp_nonce_field('geo_save_webstory_slides', 'geo_webstory_slides_nonce');
    $slides = get_post_meta($post->ID, '_geo_web_story_slides', true);
    if (!is_array($slides) || empty($slides)) {
        echo '<p style="color:#b32d2e;">Esta Web Story foi criada em uma versão antiga ou ainda não possui slides editáveis. Gere novamente a story pelo painel SARA para habilitar a edição slide por slide.</p>';
        return;
    }

    echo '<p style="color:#555;margin-top:0;">Edite título, texto, emoji, cores e imagem de cada slide. Ao salvar/atualizar o post, o HTML AMP da Web Story é reconstruído automaticamente.</p>';
    echo '<div id="geo-ws-slides-editor">';
    foreach ($slides as $i => $slide) {
        $idx = (int)$i;
        $num = $idx + 1;
        $title = esc_attr($slide['title'] ?? '');
        $text  = esc_textarea($slide['text'] ?? '');
        $emoji = esc_attr($slide['emoji'] ?? '');
        $bg    = esc_attr($slide['bg_color'] ?? '#1a1a2e');
        $tc    = esc_attr($slide['text_color'] ?? '#ffffff');
        $img   = esc_url($slide['image_url'] ?? '');
        $prompt = esc_attr($slide['image_prompt'] ?? '');
        echo '<div class="geo-ws-slide-card" style="border:1px solid #dcdcde;border-radius:10px;padding:14px;margin:0 0 14px;background:#fff;">';
        echo '<h3 style="margin:0 0 12px;">Slide ' . esc_html((string)$num) . '</h3>';
        echo '<div style="display:grid;grid-template-columns:1fr 220px;gap:14px;align-items:start;">';
        echo '<div>';
        echo '<label style="font-weight:600;display:block;margin-bottom:4px;">Título</label>';
        echo '<input type="text" name="geo_ws_slides['.$idx.'][title]" value="'.$title.'" style="width:100%;margin-bottom:10px;">';
        echo '<label style="font-weight:600;display:block;margin-bottom:4px;">Texto</label>';
        echo '<textarea name="geo_ws_slides['.$idx.'][text]" rows="4" style="width:100%;margin-bottom:10px;">'.$text.'</textarea>';
        echo '<div style="display:grid;grid-template-columns:90px 120px 120px 1fr;gap:10px;align-items:end;">';
        echo '<div><label style="font-weight:600;display:block;margin-bottom:4px;">Emoji</label><input type="text" name="geo_ws_slides['.$idx.'][emoji]" value="'.$emoji.'" style="width:100%;"></div>';
        echo '<div><label style="font-weight:600;display:block;margin-bottom:4px;">Fundo</label><input type="color" name="geo_ws_slides['.$idx.'][bg_color]" value="'.$bg.'" style="width:100%;height:34px;"></div>';
        echo '<div><label style="font-weight:600;display:block;margin-bottom:4px;">Texto</label><input type="color" name="geo_ws_slides['.$idx.'][text_color]" value="'.$tc.'" style="width:100%;height:34px;"></div>';
        echo '<div><label style="font-weight:600;display:block;margin-bottom:4px;">Prompt da imagem</label><input type="text" name="geo_ws_slides['.$idx.'][image_prompt]" value="'.$prompt.'" style="width:100%;"></div>';
        echo '</div>';
        echo '</div>';
        echo '<div>';
        echo '<label style="font-weight:600;display:block;margin-bottom:6px;">Imagem do slide</label>';
        echo '<img class="geo-ws-preview" src="'.$img.'" style="width:100%;aspect-ratio:9/16;object-fit:cover;background:#f6f7f7;border:1px solid #ddd;border-radius:8px;margin-bottom:8px;'.($img?'':'display:none;').'">';
        echo '<input type="hidden" class="geo-ws-image-url" name="geo_ws_slides['.$idx.'][image_url]" value="'.$img.'">';
        echo '<button type="button" class="button geo-ws-upload">Trocar imagem</button> ';
        echo '<button type="button" class="button geo-ws-clear">Remover</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
    echo '<script>(function(){document.querySelectorAll(".geo-ws-slide-card").forEach(function(card){var upload=card.querySelector(".geo-ws-upload"),clear=card.querySelector(".geo-ws-clear"),input=card.querySelector(".geo-ws-image-url"),preview=card.querySelector(".geo-ws-preview"); if(upload){upload.addEventListener("click",function(e){e.preventDefault(); var frame=wp.media({title:"Escolher imagem do slide",button:{text:"Usar esta imagem"},multiple:false}); frame.on("select",function(){var att=frame.state().get("selection").first().toJSON(); var url=(att.sizes&&att.sizes.large?att.sizes.large.url:att.url); input.value=url; preview.src=url; preview.style.display="block";}); frame.open();});} if(clear){clear.addEventListener("click",function(e){e.preventDefault(); input.value=""; preview.removeAttribute("src"); preview.style.display="none";});}});})();</script>';
}

add_action('save_post_geo-web-story', function($post_id, $post, $update) {
    static $geo_ws_saving = false;
    if ($geo_ws_saving) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['geo_webstory_slides_nonce']) || !wp_verify_nonce($_POST['geo_webstory_slides_nonce'], 'geo_save_webstory_slides')) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (empty($_POST['geo_ws_slides']) || !is_array($_POST['geo_ws_slides'])) return;
    if (!class_exists('GeoMetodoSEO\\Services\\WebStoriesService')) return;

    $slides = [];
    foreach ($_POST['geo_ws_slides'] as $slide) {
        if (!is_array($slide)) continue;
        $slides[] = [
            'title'        => sanitize_text_field($slide['title'] ?? ''),
            'text'         => sanitize_textarea_field($slide['text'] ?? ''),
            'emoji'        => sanitize_text_field($slide['emoji'] ?? ''),
            'bg_color'     => sanitize_hex_color($slide['bg_color'] ?? '') ?: '#1a1a2e',
            'text_color'   => sanitize_hex_color($slide['text_color'] ?? '') ?: '#ffffff',
            'image_prompt' => sanitize_text_field($slide['image_prompt'] ?? ''),
            'image_url'    => esc_url_raw($slide['image_url'] ?? ''),
        ];
    }

    $service = new \GeoMetodoSEO\Services\WebStoriesService();
    update_post_meta($post_id, '_geo_web_story_slides', $service->normalizeSlides($slides));
    $geo_ws_saving = true;
    $rebuilt = $service->rebuildStoryFromMeta($post_id);
    $geo_ws_saving = false;
    if (is_wp_error($rebuilt)) {
        \GeoMetodoSEO\Services\LogService::log('error', 'WebStory editor: falha ao reconstruir story #' . $post_id . ' — ' . $rebuilt->get_error_message());
    } else {
        \GeoMetodoSEO\Services\LogService::log('success', 'WebStory editor: story #' . $post_id . ' atualizada slide por slide');
    }
}, 10, 3);

// ============================================================================
// Web Stories — Listagem pública + Shortcode [geo_web_stories]
// ============================================================================

// 1. Shortcode: [geo_web_stories limit="12" cols="3"]
// Uso: cole numa página do WordPress para mostrar todas as stories geradas
add_shortcode('geo_web_stories', function($atts) {
    $atts  = shortcode_atts(['limit' => 12, 'cols' => 3, 'title' => 'Web Stories'], $atts, 'geo_web_stories');
    $limit = max(1, min(50, (int) $atts['limit']));
    $cols  = max(1, min(4, (int) $atts['cols']));

    $stories = get_posts([
        'post_type'      => 'geo-web-story',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [['key' => '_geo_web_story', 'value' => '1']],
    ]);

    if (empty($stories)) {
        return '<p style="color:#888;">Nenhuma Web Story gerada ainda.</p>';
    }

    $gap    = 16;
    $pct    = floor(100 / $cols) . '%';
    $html   = '<div class="geo-stories-grid" style="display:flex;flex-wrap:wrap;gap:' . $gap . 'px;margin:24px 0;">';

    foreach ($stories as $story) {
        $source_id  = (int) get_post_meta($story->ID, '_geo_web_story_source', true);
        $story_url  = get_permalink($story->ID);
        $thumb_url  = get_the_post_thumbnail_url($story->ID, 'medium') ?: '';

        // Se não tem thumbnail próprio, pega do artigo original
        if (!$thumb_url && $source_id) {
            $thumb_url = get_the_post_thumbnail_url($source_id, 'medium') ?: '';
        }

        $title      = get_the_title($story->ID);
        // Remover prefixo "Web Story: " do título para exibição
        $display_title = preg_replace('/^Web Story:\s*/i', '', $title);

        $html .= '<div class="geo-story-card" style="width:calc(' . $pct . ' - ' . $gap . 'px);min-width:140px;flex-shrink:0;">';
        $html .= '<a href="' . esc_url($story_url) . '" target="_blank" rel="noopener" '
               . 'style="display:block;text-decoration:none;color:inherit;border-radius:14px;overflow:hidden;'
               . 'box-shadow:0 4px 16px rgba(0,0,0,0.15);transition:transform .2s;position:relative;aspect-ratio:9/16;">';

        // Thumbnail ou gradiente
        if ($thumb_url) {
            $html .= '<img src="' . esc_url($thumb_url) . '" alt="' . esc_attr($display_title) . '" '
                   . 'style="width:100%;height:100%;object-fit:cover;display:block;">';
        } else {
            $html .= '<div style="width:100%;height:100%;background:linear-gradient(145deg,#1a1a2e,#0f3460);"></div>';
        }

        // Overlay com título
        $html .= '<div style="position:absolute;bottom:0;left:0;right:0;padding:16px 12px 12px;'
               . 'background:linear-gradient(transparent,rgba(0,0,0,0.75));color:#fff;">';
        $html .= '<div style="font-size:11px;font-weight:600;letter-spacing:1px;opacity:.7;margin-bottom:4px;">📱 WEB STORY</div>';
        $html .= '<div style="font-size:13px;font-weight:700;line-height:1.3;">' . esc_html(wp_trim_words($display_title, 8)) . '</div>';
        $html .= '</div>';
        $html .= '</a>';
        $html .= '</div>';
    }

    $html .= '</div>';

    // Link para ver mais (se houver mais stories)
    $total = wp_count_posts('geo-web-story')->publish ?? 0;
    if ($total > $limit) {
        $html .= '<p style="text-align:center;margin-top:8px;">'
               . '<a href="' . esc_url(get_post_type_archive_link('geo-web-story') ?: '') . '" '
               . 'style="color:#0073aa;font-size:14px;">Ver todas as Web Stories (' . $total . ') →</a>'
               . '</p>';
    }

    return $html;
});

// 2. Ativar arquivo público das Web Stories (has_archive)
// Filtro que faz o CPT ter página de arquivo em /web-stories/
add_filter('register_post_type_args', function($args, $post_type) {
    if ($post_type === 'geo-web-story') {
        $args['has_archive']  = 'web-stories';
        $args['show_in_nav_menus'] = true;
    }
    return $args;
}, 10, 2);

// 3. Template de arquivo das Web Stories (fallback se o tema não tiver)
add_filter('archive_template', function($template) {
    if (is_post_type_archive('geo-web-story')) {
        // Se o tema tem archive-geo-web-story.php, usa ele. Senão, nosso fallback.
        $theme_template = locate_template(['archive-geo-web-story.php']);
        if ($theme_template) return $theme_template;

        // Fallback: renderizar inline
        add_filter('the_content', '__return_empty_string');
        add_action('wp', function() {
            add_filter('the_title', function($t) {
                return is_post_type_archive('geo-web-story') ? 'Web Stories' : $t;
            });
        });
    }
    return $template;
});

// ── CSS Frontend: Adaptar caixas do plugin ao tema do site ───────────────────
add_action('wp_enqueue_scripts', function() {
    if (!is_singular('post')) return; // Só em posts

    $css = "
/* ── GEO Método SEO — Estilos Frontend ── */

/* Detectar tema escuro automaticamente */
:root {
    --geo-bg-card:      rgba(255,255,255,0.05);
    --geo-bg-card-alt:  rgba(255,255,255,0.03);
    --geo-border:       rgba(255,255,255,0.1);
    --geo-text:         inherit;
    --geo-text-muted:   rgba(255,255,255,0.6);
    --geo-accent:       #00C8FF;
    --geo-accent-2:     #8B5CF6;
    --geo-shadow:       0 4px 20px rgba(0,0,0,0.3);
}

/* ── Resposta Rápida ── */
.geo-quick-answer {
    background: linear-gradient(135deg, rgba(0,200,255,0.08), rgba(139,92,246,0.08)) !important;
    border-left: 4px solid #00C8FF !important;
    border-radius: 0 10px 10px 0 !important;
    padding: 18px 22px !important;
    margin: 0 0 32px !important;
    backdrop-filter: blur(4px);
}
.geo-quick-answer strong {
    color: #00C8FF !important;
    font-size: 13px !important;
    letter-spacing: 0.5px !important;
    text-transform: uppercase !important;
}
.geo-quick-answer p {
    margin: 8px 0 0 !important;
    font-size: 16px !important;
    line-height: 1.7 !important;
    color: inherit !important;
}

/* ── FAQ Items ── */
.geo-faq-item {
    background: var(--geo-bg-card) !important;
    border: 1px solid var(--geo-border) !important;
    border-radius: 10px !important;
    padding: 18px 20px !important;
    margin-bottom: 12px !important;
    transition: border-color 0.2s;
}
.geo-faq-item:hover {
    border-color: rgba(0,200,255,0.3) !important;
}
.geo-faq-item strong {
    font-size: 15px !important;
    line-height: 1.5 !important;
    display: block !important;
    margin-bottom: 10px !important;
    color: inherit !important;
}
.geo-faq-item p {
    margin: 0 !important;
    line-height: 1.7 !important;
    opacity: 0.85 !important;
    font-size: 14px !important;
}

/* ── Box do Autor ── */
.geo-author-box {
    background: var(--geo-bg-card) !important;
    border: 1px solid var(--geo-border) !important;
    border-radius: 12px !important;
    margin-top: 48px !important;
    padding: 24px !important;
}
.geo-author-box strong {
    color: inherit !important;
}
.geo-author-box p {
    color: var(--geo-text-muted) !important;
}

/* ── Embed YouTube ── */
.geo-video-embed {
    margin: 36px 0 !important;
    border-radius: 14px !important;
    overflow: hidden !important;
    box-shadow: var(--geo-shadow) !important;
    border: 1px solid var(--geo-border) !important;
}
.geo-video-embed p {
    background: rgba(255,255,255,0.05) !important;
    color: var(--geo-text-muted) !important;
    padding: 12px 16px !important;
    margin: 0 !important;
    font-size: 13px !important;
}

/* ── Tabelas ── */
.entry-content table,
.post-content table {
    width: 100% !important;
    border-collapse: collapse !important;
    margin: 28px 0 !important;
    border-radius: 10px !important;
    overflow: hidden !important;
    border: 1px solid var(--geo-border) !important;
    font-size: 14px !important;
}
.entry-content table thead tr,
.post-content table thead tr {
    background: linear-gradient(135deg, #0073aa, #005a87) !important;
    color: #fff !important;
}
.entry-content table thead th,
.post-content table thead th {
    padding: 14px 16px !important;
    font-weight: 600 !important;
    border: none !important;
}
.entry-content table tbody td,
.post-content table tbody td {
    padding: 12px 16px !important;
    border-bottom: 1px solid var(--geo-border) !important;
    color: inherit !important;
}
.entry-content table tbody tr:nth-child(odd),
.post-content table tbody tr:nth-child(odd) {
    background: var(--geo-bg-card-alt) !important;
}
.entry-content table tbody tr:hover,
.post-content table tbody tr:hover {
    background: rgba(0,200,255,0.04) !important;
}

/* ── Blockquote (Dica de Especialista) ── */
.entry-content blockquote,
.post-content blockquote {
    background: linear-gradient(135deg, rgba(0,115,170,0.1), rgba(139,92,246,0.1)) !important;
    border-left: 4px solid var(--geo-accent-2) !important;
    border-radius: 0 10px 10px 0 !important;
    padding: 18px 22px !important;
    margin: 28px 0 !important;
    color: inherit !important;
}

/* ── Placeholder de imagem ── */
img[src*='placeholder.com'],
img[src*='via.placeholder'] {
    border-radius: 10px !important;
    border: 1px solid var(--geo-border) !important;
    opacity: 0.6 !important;
    filter: grayscale(20%) !important;
}

/* ── Imagens no corpo ── */
.entry-content figure,
.post-content figure {
    border-radius: 10px !important;
    overflow: hidden !important;
    margin: 28px 0 !important;
}
.entry-content figcaption,
.post-content figcaption {
    opacity: 0.6 !important;
    font-size: 12px !important;
    padding: 8px 0 !important;
    font-style: italic !important;
}
";

    wp_register_style('geo-metodo-seo-frontend', false);
    wp_enqueue_style('geo-metodo-seo-frontend');
    wp_add_inline_style('geo-metodo-seo-frontend', $css);
});

function run_geo_metodo_seo() {
    $plugin = new Plugin();
    $plugin->run();
}
run_geo_metodo_seo();

// ── Meta Box: Reescrita Parcial de Seção no Editor WordPress ─────────────────
add_action('add_meta_boxes', function() {
    add_meta_box(
        'geo_rewrite_section',
        '✏️ GEO — Reescrever Seção',
        'geo_render_rewrite_section_metabox',
        'post',
        'side',
        'high'
    );
});

function geo_render_rewrite_section_metabox(\WP_Post $post): void {
    $nonce = wp_create_nonce('geo_rewrite_section_nonce');
    $provider = \GeoMetodoSEO\AI\ProviderResolver::for('article_generation');
    ?>
    <div id="geo-rewrite-box" style="font-size:13px;">
        <p style="color:#666;margin-top:0;">Selecione o texto que quer reescrever no editor, depois clique no botão.</p>

        <div style="margin-bottom:10px;">
            <label style="font-weight:600;display:block;margin-bottom:4px;">Instrução para a IA:</label>
            <select id="geo-rewrite-instruction" style="width:100%;padding:4px 6px;border:1px solid #ddd;border-radius:4px;">
                <option value="melhore">🔄 Melhorar qualidade geral</option>
                <option value="seo">🔍 Otimizar para SEO</option>
                <option value="geo">🤖 Otimizar para GEO/LLMs</option>
                <option value="expandir">📝 Expandir e detalhar mais</option>
                <option value="resumir">✂️ Resumir e tornar mais direto</option>
                <option value="eeat">🏆 Adicionar E-E-A-T (dados e autoridade)</option>
                <option value="humanizar">💬 Humanizar o texto</option>
                <option value="engajamento">❤️ Aumentar engajamento</option>
            </select>
        </div>

        <div style="margin-bottom:10px;">
            <label style="font-weight:600;display:block;margin-bottom:4px;">Ou instrução personalizada:</label>
            <textarea id="geo-rewrite-custom" placeholder="Ex: Reescreva em tom mais técnico com exemplos de Python..."
                style="width:100%;height:60px;padding:6px;border:1px solid #ddd;border-radius:4px;font-size:12px;resize:vertical;"></textarea>
        </div>

        <button type="button" id="geo-rewrite-section-btn"
            style="width:100%;background:#0073aa;color:#fff;border:none;padding:10px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px;">
            ✏️ Reescrever Seleção
        </button>

        <div id="geo-rewrite-status" style="display:none;margin-top:10px;padding:10px;border-radius:6px;font-size:12px;"></div>
    </div>

    <script>
    (function() {
        var btn     = document.getElementById('geo-rewrite-section-btn');
        var status  = document.getElementById('geo-rewrite-status');
        var instrSel = document.getElementById('geo-rewrite-instruction');
        var instrCustom = document.getElementById('geo-rewrite-custom');

        btn.addEventListener('click', function() {
            // Tentar pegar texto selecionado do editor Gutenberg ou Classic
            var selectedText = '';

            // Gutenberg (editor de blocos)
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/block-editor')) {
                var selection = wp.data.select('core/block-editor').getSelectedBlock();
                if (selection && selection.attributes && selection.attributes.content) {
                    selectedText = selection.attributes.content;
                }
            }

            // Classic Editor (TinyMCE)
            if (!selectedText && typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                selectedText = tinymce.activeEditor.selection.getContent({format: 'html'});
            }

            // Fallback: textarea
            if (!selectedText) {
                var textarea = document.getElementById('content');
                if (textarea) {
                    selectedText = textarea.value.substring(textarea.selectionStart, textarea.selectionEnd);
                }
            }

            if (!selectedText || selectedText.trim().length < 20) {
                status.style.display = 'block';
                status.style.background = '#fff3cd';
                status.style.color = '#856404';
                status.innerHTML = '⚠️ Selecione pelo menos 20 caracteres no editor antes de clicar.';
                return;
            }

            var instruction = instrCustom.value.trim() || instrSel.value;
            btn.disabled = true;
            btn.textContent = '⏳ Reescrevendo...';
            status.style.display = 'block';
            status.style.background = '#e8f4f8';
            status.style.color = '#0c5460';
            status.innerHTML = '🤖 IA processando...';

            var data = new FormData();
            data.append('action', 'geo_rewrite_section');
            data.append('nonce', '<?php echo esc_js($nonce); ?>');
            data.append('post_id', '<?php echo intval($post->ID); ?>');
            data.append('selected_text', selectedText);
            data.append('instruction', instruction);
            data.append('keyword', '<?php echo esc_js(get_post_meta($post->ID, "_geo_keyword", true)); ?>');

            fetch(ajaxurl, {method: 'POST', body: data})
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    btn.textContent = '✏️ Reescrever Seleção';

                    if (res.success) {
                        var newText = res.data.rewritten;

                        // Substituir no editor Gutenberg
                        if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                            var block = wp.data.select('core/block-editor').getSelectedBlock();
                            if (block) {
                                wp.data.dispatch('core/block-editor').updateBlockAttributes(
                                    block.clientId, {content: newText}
                                );
                                status.style.background = '#d4edda';
                                status.style.color = '#155724';
                                status.innerHTML = '✅ Bloco atualizado com sucesso!';
                                return;
                            }
                        }

                        // Classic Editor
                        if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                            tinymce.activeEditor.selection.setContent(newText);
                            status.style.background = '#d4edda';
                            status.style.color = '#155724';
                            status.innerHTML = '✅ Texto substituído no editor!';
                            return;
                        }

                        // Fallback: mostrar na caixa para copiar
                        status.style.background = '#d4edda';
                        status.style.color = '#155724';
                        status.innerHTML = '✅ Texto reescrito (copie abaixo):<br><br>'
                            + '<textarea style="width:100%;height:120px;font-size:11px;border:1px solid #ddd;padding:6px;">'
                            + newText.replace(/</g,'&lt;').replace(/>/g,'&gt;')
                            + '</textarea>';
                    } else {
                        status.style.background = '#f8d7da';
                        status.style.color = '#721c24';
                        status.innerHTML = '❌ ' + (res.data && res.data.message ? res.data.message : 'Erro ao reescrever');
                    }
                })
                .catch(function(e) {
                    btn.disabled = false;
                    btn.textContent = '✏️ Reescrever Seleção';
                    status.style.background = '#f8d7da';
                    status.style.color = '#721c24';
                    status.innerHTML = '❌ Erro de conexão';
                });
        });
    })();
    </script>
    <?php
}

// AJAX handler: reescrever seção
add_action('wp_ajax_geo_rewrite_section', function() {
    check_ajax_referer('geo_rewrite_section_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Sem permissão']);

    $selected = wp_kses_post(stripslashes($_POST['selected_text'] ?? ''));
    $instruction = sanitize_text_field($_POST['instruction'] ?? 'melhore');
    $keyword  = sanitize_text_field($_POST['keyword'] ?? '');
    $post_id  = (int)($_POST['post_id'] ?? 0);

    if (empty($selected) || strlen($selected) < 20) {
        wp_send_json_error(['message' => 'Texto selecionado muito curto']);
    }

    $instr_map = [
        'melhore'     => 'Melhore a qualidade geral, clareza e fluência do texto',
        'seo'         => 'Otimize para SEO: inclua a keyword naturalmente, melhore estrutura e densidade semântica',
        'geo'         => 'Otimize para GEO/LLMs: adicione definições claras, dados verificáveis e estrutura AI-friendly',
        'expandir'    => 'Expanda e aprofunde o conteúdo com mais detalhes, exemplos e dados',
        'resumir'     => 'Resuma e torne mais direto, mantendo apenas as informações mais importantes',
        'eeat'        => 'Adicione elementos E-E-A-T: dados de pesquisas, exemplos reais, perspectiva de especialista',
        'humanizar'   => 'Humanize o texto: torne mais natural, conversacional e menos robótico',
        'engajamento' => 'Aumente o engajamento: mais dinâmico, envolvente e com gatilhos emocionais',
    ];

    $instruction_text = $instr_map[$instruction] ?? $instruction;

    $keyword_context = $keyword ? "\nKeyword principal do artigo: \"{$keyword}\"" : '';
    $year = date('Y');

    $prompt = "Você é um especialista em SEO, GEO e redação de conteúdo de alta qualidade.\n\n"
            . "Reescreva o trecho de artigo abaixo seguindo esta instrução: {$instruction_text}.{$keyword_context}\n\n"
            . "REGRAS:\n"
            . "- Mantenha o mesmo formato HTML do original (parágrafos, listas, títulos)\n"
            . "- Não adicione introdução ou conclusão — reescreva APENAS o trecho fornecido\n"
            . "- Melhore a qualidade sem perder o contexto do tema\n"
            . "- Use linguagem natural, fluente e especialista\n"
            . "- Inclua dados ou exemplos concretos quando possível\n"
            . "- Ano de referência: {$year}\n\n"
            . "TRECHO ORIGINAL:\n{$selected}\n\n"
            . "Retorne APENAS o HTML reescrito, sem comentários, sem explicações, sem markdown.";

    $provider = \GeoMetodoSEO\AI\ProviderResolver::for('article_generation');
    $ai       = new \GeoMetodoSEO\AI\AIManager();
    $response = $ai->generateText($prompt, $provider);

    if (!$response || $response->hasError() || !$response->getContent()) {
        wp_send_json_error(['message' => 'Falha na IA: ' . ($response ? $response->getError() : 'sem resposta')]);
    }

    $rewritten = wp_kses_post($response->getContent());

    // Remover possível markdown wrapper
    $rewritten = preg_replace('/^```html?\s*/i', '', trim($rewritten));
    $rewritten = preg_replace('/```\s*$/', '', $rewritten);

    \GeoMetodoSEO\Services\LogService::log('info', "Reescrita parcial: post #{$post_id}, instrução: {$instruction}");

    wp_send_json_success(['rewritten' => $rewritten]);
});



/**
 * Rede de segurança para o embed do YouTube em artigos criados via "YouTube → Artigo".
 *
 * Em alguns servidores, o WordPress remove o <iframe> do conteúdo salvo quando o
 * usuário/cron não tem a capability 'unfiltered_html', fazendo o vídeo virar apenas
 * um link ("clique para assistir no YouTube"). Este filtro reinsere o player na
 * exibição, garantindo que o vídeo sempre apareça embeddado.
 */
add_filter('the_content', function ($content) {
    if (is_admin() || !is_singular('post')) {
        return $content;
    }
    $post_id  = get_the_ID();
    if (!$post_id) return $content;
    $video_id = get_post_meta($post_id, '_geo_youtube_video_id', true);
    if (empty($video_id)) return $content;

    // Se o iframe do vídeo já está presente, não fazer nada.
    if (strpos($content, 'youtube.com/embed/' . $video_id) !== false) {
        return $content;
    }

    // Iframe ausente (foi removido na sanitização) → reinserir após o 1º parágrafo.
    $embed = '<div class="geo-video-embed" style="position:relative;width:100%;max-width:880px;margin:32px auto;aspect-ratio:16/9;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.15);">'
           . '<iframe src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" '
           . 'style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" '
           . 'allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
           . 'loading="lazy" title="Vídeo do YouTube"></iframe></div>';

    if (preg_match('/<\/p>/i', $content)) {
        return preg_replace('/<\/p>/i', '</p>' . $embed, $content, 1);
    }
    return $embed . $content;
}, 20);
