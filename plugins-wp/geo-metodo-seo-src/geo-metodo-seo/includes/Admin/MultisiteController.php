<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Helpers\SecurityHelper;
use GeoMetodoSEO\License\LicenseManager;

class MultisiteController {

    private $shared_keys = [
        'openai_api_key', 'groq_api_key', 'gemini_api_key',
        'claude_api_key', 'perplexity_api_key', 'replicate_api_key',
    ];

    public function render_page() {
        if (!is_multisite()) {
            echo '<div class="wrap"><p>Esta pagina e disponivel apenas em instalacoes Multisite.</p></div>';
            return;
        }
        if (!LicenseManager::can('multisite')) {
            echo LicenseManager::lockedHtml('Suporte Multisite');
            return;
        }

        $message = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('geo_multisite_save');
            if (!current_user_can('manage_network_options')) wp_die('Sem permissao.');

            $mode = sanitize_text_field($_POST['geo_ms_mode'] ?? 'individual');
            update_site_option('geo_ms_mode', $mode);

            if ($mode === 'shared' && isset($_POST['geo_shared'])) {
                foreach ($this->shared_keys as $key) {
                    $val = sanitize_text_field($_POST['geo_shared'][$key] ?? '');
                    if ($val !== '') {
                        update_site_option('geo_shared_' . $key, $val);
                    }
                }
            }

            $message = 'Configuracoes de rede salvas!';
        }

        $current_mode = get_site_option('geo_ms_mode', 'individual');

        ?>
        <div class="wrap">
            <h1>GEO SEO — Administracao de Rede (Multisite)</h1>
            <p class="description">Configure como as API Keys sao compartilhadas entre os sites da rede.</p>

            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible"><p><strong><?php echo esc_html($message); ?></strong></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('geo_multisite_save'); ?>

                <h2 class="title" style="margin-top:24px;">Modo de API Keys</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Configuracao</th>
                        <td>
                            <label style="display:block; margin-bottom:8px;">
                                <input type="radio" name="geo_ms_mode" value="individual"
                                       <?php checked($current_mode, 'individual'); ?>>
                                <strong>Independente</strong> — cada site configura suas proprias API Keys
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="geo_ms_mode" value="shared"
                                       <?php checked($current_mode, 'shared'); ?>>
                                <strong>Compartilhado</strong> — API Keys definidas aqui sao usadas por todos os sites da rede
                            </label>
                        </td>
                    </tr>
                </table>

                <h2 class="title" style="margin-top:28px;">API Keys Compartilhadas da Rede</h2>
                <p class="description">Preenchidas apenas quando o modo "Compartilhado" esta ativo. Deixe em branco para nao alterar.</p>
                <table class="form-table" role="presentation">
                    <?php
                    $labels = [
                        'openai_api_key'     => 'OpenAI API Key',
                        'groq_api_key'       => 'Groq API Key',
                        'gemini_api_key'     => 'Gemini API Key',
                        'claude_api_key'     => 'Claude API Key',
                        'perplexity_api_key' => 'Perplexity API Key',
                        'replicate_api_key'  => 'Replicate API Key',
                    ];
                    foreach ($this->shared_keys as $key):
                        $val = get_site_option('geo_shared_' . $key, '');
                    ?>
                    <tr>
                        <th><label><?php echo esc_html($labels[$key] ?? $key); ?></label></th>
                        <td>
                            <input type="password" name="geo_shared[<?php echo esc_attr($key); ?>]"
                                   value="<?php echo esc_attr($val); ?>"
                                   class="regular-text" autocomplete="new-password">
                            <?php echo $val ? '<span style="color:#46b450;font-weight:600;">&#10003; Configurada</span>' : ''; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <h2 class="title" style="margin-top:28px;">Sites da Rede</h2>
                <?php
                $sites = get_sites(['number' => 50]);
                if ($sites):
                ?>
                <table class="widefat fixed striped" style="max-width:600px;">
                    <thead><tr><th>ID</th><th>Site</th><th>URL</th></tr></thead>
                    <tbody>
                    <?php foreach ($sites as $site): ?>
                        <tr>
                            <td><?php echo intval($site->blog_id); ?></td>
                            <td><?php echo esc_html($site->blogname ?? $site->domain); ?></td>
                            <td><a href="<?php echo esc_url($site->siteurl ?? ''); ?>" target="_blank"><?php echo esc_html($site->domain); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p style="color:#666;">Nenhum site encontrado.</p>
                <?php endif; ?>

                <?php submit_button('Salvar Configuracoes de Rede', 'primary large'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Get API key: if multisite shared mode, return network key, else site key.
     */
    public static function get_api_key(string $key): string {
        if (is_multisite() && get_site_option('geo_ms_mode') === 'shared') {
            $val = get_site_option('geo_shared_' . $key, '');
            if ($val) return $val;
        }
        return get_option('geo_' . $key, '');
    }
}
