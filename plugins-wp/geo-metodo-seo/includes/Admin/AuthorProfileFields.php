<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

/**
 * AuthorProfileFields — Adiciona campos de E-E-A-T no perfil do WordPress.
 *
 * Google March 2026 expandiu E-E-A-T pra TODO tipo de conteúdo (não só
 * health/finance/legal). Autores precisam mostrar credenciais visíveis.
 *
 * Adiciona ao perfil do usuário:
 *  - Área de Expertise
 *  - Anos de Experiência
 *  - Credenciais / Certificações
 *  - LinkedIn URL
 *
 * Esses campos são lidos pelo EEATEngine pra montar o Author Box reforçado.
 *
 * @since 1.0.0
 */
class AuthorProfileFields {

    public static function register(): void {
        add_action('show_user_profile', [__CLASS__, 'render_fields']);
        add_action('edit_user_profile', [__CLASS__, 'render_fields']);
        add_action('personal_options_update', [__CLASS__, 'save_fields']);
        add_action('edit_user_profile_update', [__CLASS__, 'save_fields']);
    }

    public static function render_fields($user): void {
        $credentials = get_user_meta($user->ID, 'geo_credentials', true);
        $linkedin    = get_user_meta($user->ID, 'geo_linkedin', true);
        $years       = get_user_meta($user->ID, 'geo_years_experience', true);
        $area        = get_user_meta($user->ID, 'geo_expertise_area', true);
        ?>
        <h2>📝 GEO Método SEO — Credenciais E-E-A-T (Author Box)</h2>
        <p class="description">Esses campos aparecem no Author Box reforçado dos artigos. Google March 2026 valoriza autoria visível.</p>
        <table class="form-table">
            <tr>
                <th><label for="geo_expertise_area">Área de Expertise</label></th>
                <td>
                    <input type="text" id="geo_expertise_area" name="geo_expertise_area"
                           value="<?php echo esc_attr((string) $area); ?>" class="regular-text"
                           placeholder="Ex: Especialista em SEO e Marketing Digital" />
                    <p class="description">Aparece logo abaixo do nome no Author Box.</p>
                </td>
            </tr>
            <tr>
                <th><label for="geo_years_experience">Anos de Experiência</label></th>
                <td>
                    <input type="number" id="geo_years_experience" name="geo_years_experience"
                           value="<?php echo esc_attr((string) $years); ?>" min="0" max="60" />
                    <p class="description">Quantos anos de prática na área.</p>
                </td>
            </tr>
            <tr>
                <th><label for="geo_credentials">Credenciais / Certificações</label></th>
                <td>
                    <input type="text" id="geo_credentials" name="geo_credentials"
                           value="<?php echo esc_attr((string) $credentials); ?>" class="regular-text"
                           placeholder="Ex: Certificado Google Ads, MBA em Marketing" />
                    <p class="description">Certificações ou formação relevante.</p>
                </td>
            </tr>
            <tr>
                <th><label for="geo_linkedin">LinkedIn URL</label></th>
                <td>
                    <input type="url" id="geo_linkedin" name="geo_linkedin"
                           value="<?php echo esc_attr((string) $linkedin); ?>" class="regular-text"
                           placeholder="https://linkedin.com/in/seuperfil" />
                    <p class="description">Link público pro LinkedIn (sinaliza autoria real).</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function save_fields(int $user_id): bool {
        if (!current_user_can('edit_user', $user_id)) return false;
        $fields = ['geo_expertise_area', 'geo_years_experience', 'geo_credentials', 'geo_linkedin'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $value = sanitize_text_field(wp_unslash((string) $_POST[$f]));
                if ($f === 'geo_linkedin') {
                    $value = esc_url_raw($value);
                }
                update_user_meta($user_id, $f, $value);
            }
        }
        return true;
    }
}
