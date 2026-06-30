<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\License\LicenseManager;
use GeoMetodoSEO\Helpers\SecurityHelper;

class LicenseController {

    public function render_page() {
        $message = '';
        $error   = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            SecurityHelper::verify_nonce($_POST['_wpnonce'] ?? '', 'geo_license_action');
            SecurityHelper::current_user_can_manage();

            $action = sanitize_text_field($_POST['geo_license_action'] ?? '');

            if ($action === 'activate') {
                $key    = sanitize_text_field($_POST['geo_license_key_input'] ?? '');
                $result = LicenseManager::activate($key);

                if (($result['status'] ?? '') === 'active') {
                    $message = 'Licença ativada com sucesso! Plano: ' . LicenseManager::planLabel($result['plan'] ?? '');
                } else {
                    $error = $result['message'] ?? 'Falha ao ativar licença. Verifique a chave e tente novamente.';
                }

            } elseif ($action === 'deactivate') {
                LicenseManager::deactivate();
                $message = 'Licença desativada neste site. O slot foi liberado.';

            } elseif ($action === 'refresh') {
                LicenseManager::clearCache();
                $result = LicenseManager::validate();
                if (($result['status'] ?? '') === 'active') {
                    $message = 'Licença revalidada com sucesso!';
                } else {
                    $error = 'Status da licença: ' . ($result['message'] ?? $result['status'] ?? 'Desconhecido');
                }
            }
        }

        $license_data  = LicenseManager::getLicenseData();
        $plan          = $license_data['plan']       ?? 'trial';
        $status        = $license_data['status']     ?? 'trial';
        $expires_at    = $license_data['expires_at'] ?? '';
        $sites_limit   = $license_data['sites_limit'] ?? 1;
        $sites_used    = $license_data['sites_used']  ?? 0;
        $saved_key     = get_option('geo_license_key', '');
        $trial_count   = LicenseManager::getTrialCount();
        $trial_expired = LicenseManager::isTrialExpired();
        $domain        = LicenseManager::getDomainRoot();

        $status_labels = [
            'active'          => ['✅ Ativa',                '#46b450'],
            'trial'           => ['🆓 Trial',                '#0073aa'],
            'invalid'         => ['❌ Inválida',             '#dc3232'],
            'expired'         => ['⏰ Expirada',             '#f0a500'],
            'site_limit'      => ['🚫 Limite de Sites',      '#dc3232'],
            'disabled'        => ['🚫 Desabilitada',         '#dc3232'],
            'error'           => ['⚠️ Erro de Conexão',      '#f0a500'],
        ];
        [$status_label, $status_color] = $status_labels[$status] ?? ['— Desconhecido', '#888'];
        ?>
        <div class="wrap">
            <h1>Licença — GEO Metodo SEO v<?php echo GEO_METODO_SEO_VERSION; ?></h1>

            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible"><p><strong><?php echo esc_html($message); ?></strong></p></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice notice-error"><p>❌ <?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <!-- Status atual -->
            <div style="display:flex;gap:20px;flex-wrap:wrap;margin:24px 0;">
                <?php
                $badge_color = ['trial'=>'#888','basic'=>'#0073aa','pro'=>'#8b5cf6','agency'=>'#e67e22'];
                $bc = $badge_color[$plan] ?? '#888';
                $cards = [
                    ['Plano Atual',        LicenseManager::planLabel($plan),                       $bc],
                    ['Status',             $status_label,                                           $status_color],
                    ['Trial Artigos',      $trial_count . '/' . LicenseManager::TRIAL_LIMIT,       $trial_expired ? '#dc3232' : '#46b450'],
                    ['Domínio Registrado', $domain,                                                 '#555'],
                ];
                if ($plan !== 'trial' && $sites_limit) {
                    $cards[] = ['Sites',  $sites_used . '/' . $sites_limit, '#0073aa'];
                }
                if ($expires_at) {
                    $cards[] = ['Expira em', date('d/m/Y', strtotime($expires_at)), '#888'];
                }
                foreach ($cards as $card):
                ?>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 22px;min-width:160px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.07);">
                    <div style="font-size:12px;color:#888;margin-bottom:4px;"><?php echo esc_html($card[0]); ?></div>
                    <div style="font-size:15px;font-weight:700;color:<?php echo esc_attr($card[2]); ?>;"><?php echo esc_html($card[1]); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Recursos do plano -->
            <div style="background:#f9f9f9;border:1px solid #ddd;border-radius:8px;padding:18px 24px;max-width:560px;margin-bottom:28px;">
                <h3 style="margin-top:0;font-size:14px;">Recursos do plano atual</h3>
                <?php
                $all_features = [
                    'bulk_generation'  => 'Geração em Massa',
                    'cluster_seo'      => 'Cluster SEO',
                    'all_providers'    => 'Todos os Provedores de IA',
                    'multisite'        => 'Suporte Multisite',
                    'export_settings'  => 'Exportar/Importar Configurações',
                ];
                foreach ($all_features as $feat => $label):
                    $ok = LicenseManager::can($feat);
                ?>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="color:<?php echo $ok ? '#46b450' : '#dc3232'; ?>;font-size:16px;"><?php echo $ok ? '✅' : '❌'; ?></span>
                        <span style="font-size:13px;color:<?php echo $ok ? '#333' : '#aaa'; ?>;"><?php echo esc_html($label); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($plan === 'trial'): ?>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="color:#0073aa;font-size:16px;">ℹ️</span>
                        <span style="font-size:13px;">Trial: apenas OpenAI e Groq disponíveis</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Formulário de ativação -->
            <div style="max-width:560px;">
                <h2 style="font-size:16px;">Ativar / Gerenciar Licença</h2>

                <form method="post">
                    <?php wp_nonce_field('geo_license_action'); ?>
                    <input type="hidden" name="geo_license_action" value="activate">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="geo_license_key_input">Chave de Licença</label></th>
                            <td>
                                <input type="text" id="geo_license_key_input" name="geo_license_key_input"
                                       value="<?php echo esc_attr($saved_key); ?>"
                                       class="large-text"
                                       placeholder="AIC-BASIC-XXXX-XXXX">
                                <p class="description">
                                    Formato: <code>AIC-BASIC-XXXX-XXXX</code>, <code>AIC-PRO-XXXX-XXXX</code> ou <code>AIC-AGENCY-XXXX-XXXX</code><br>
                                    Adquira em: <a href="https://aiconteudo.com.br/plugin" target="_blank">aiconteudo.com.br/plugin</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="submit" class="button button-primary button-large">
                            🔑 Ativar Licença
                        </button>
                    </div>
                </form>

                <?php if (!empty($saved_key)): ?>
                <div style="margin-top:20px;padding-top:20px;border-top:1px solid #eee;">
                    <h3 style="font-size:14px;margin-top:0;">Ações da Licença Ativa</h3>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">

                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('geo_license_action'); ?>
                            <input type="hidden" name="geo_license_action" value="refresh">
                            <button type="submit" class="button">🔄 Revalidar Agora</button>
                        </form>

                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('Desativar a licença neste site? O slot ficará disponível para outro domínio.');">
                            <?php wp_nonce_field('geo_license_action'); ?>
                            <input type="hidden" name="geo_license_action" value="deactivate">
                            <button type="submit" class="button" style="color:#dc3232;">
                                🔓 Desativar este Site
                            </button>
                        </form>
                    </div>
                    <p class="description" style="margin-top:10px;">
                        "Desativar este Site" libera o slot da licença, permitindo ativá-la em outro domínio.
                        O plano voltará para Trial neste site.
                    </p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Trial esgotado -->
            <?php if ($trial_expired && $plan === 'trial'): ?>
            <div style="max-width:560px;margin-top:28px;padding:20px;background:#fff8f8;border:2px solid #dc3232;border-radius:8px;">
                <h3 style="color:#dc3232;margin-top:0;">⛔ Trial Esgotado</h3>
                <p>Você usou todos os <?php echo LicenseManager::TRIAL_LIMIT; ?> artigos do trial gratuito.</p>
                <p>Para continuar gerando artigos, adquira uma licença:</p>
                <a href="https://aiconteudo.com.br/plugin" target="_blank"
                   class="button button-primary button-large">Adquirir Licença →</a>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
