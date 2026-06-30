<?php
/**
 * Plugin Name: Content Audit & Cleanup (Universal)
 * Plugin URI: https://aiconteudo.com.br
 * Description: Auditoria e limpeza de conteúdo para qualquer nicho. Classifica posts (Manter, Fundir, Noindex, Remover, Revisar, Precisa Atualizar), detecta sitemap apontando para domínio de staging, e prepara posts desatualizados para reescrita via GEO Método SEO.
 * Version: 2.1.0
 * Author: Alison Jean
 * Text Domain: content-audit-cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CAC_VERSION', '2.1.0' );
define( 'CAC_META_STATUS',      '_cac_audit_status' );
define( 'CAC_META_NOINDEX',     '_cac_noindex_applied' );
define( 'CAC_META_NEEDS_UPDATE','_cac_needs_update' );
define( 'CAC_META_SENT_TO_GEO', '_cac_sent_to_geo' );
define( 'CAC_META_GEO_STATUS',  '_cac_geo_rewrite_status' ); // pending|done|failed
define( 'CAC_OPTION_NICHE_KEYWORDS', 'cac_niche_keywords' );
define( 'CAC_OPTION_STALE_MONTHS',   'cac_stale_months' );
define( 'CAC_OPTION_SITEMAP_URL',    'cac_sitemap_url' );
define( 'CAC_BATCH_SIZE',       200 );  // posts por lote em run_auto_audit / run_stale_scan
define( 'CAC_SITEMAP_BATCH',    100 );  // posts por lote na varredura de sitemap
define( 'CAC_PAGE_SIZE',         50 );  // posts por página na tela de auditoria

class Content_Audit_Cleanup {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_cac_apply_action',   array( $this, 'handle_bulk_action' ) );
        add_action( 'admin_post_cac_save_settings',  array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_cac_check_sitemap',  array( $this, 'handle_check_sitemap' ) );
        add_action( 'admin_post_cac_export_config',  array( $this, 'handle_export_config' ) );
        add_action( 'admin_post_cac_import_config',  array( $this, 'handle_import_config' ) );

        add_filter( 'manage_posts_columns',       array( $this, 'add_audit_column' ) );
        add_action( 'manage_posts_custom_column', array( $this, 'render_audit_column' ), 10, 2 );

        add_filter( 'rank_math/frontend/robots', array( $this, 'filter_rankmath_robots' ) );
        add_action( 'wp_head', array( $this, 'output_manual_noindex' ), 1 );

        add_action( 'cac_daily_scan', array( $this, 'run_stale_scan' ) );
        if ( ! wp_next_scheduled( 'cac_daily_scan' ) ) {
            wp_schedule_event( time(), 'daily', 'cac_daily_scan' );
        }
    }

    public function register_menu() {
        add_menu_page(
            'Auditoria de Conteúdo', 'Auditoria de Conteúdo',
            'manage_options', 'content-audit-cleanup',
            array( $this, 'render_admin_page' ), 'dashicons-broom', 58
        );
        add_submenu_page(
            'content-audit-cleanup', 'Configurações de Nicho', 'Configurações',
            'manage_options', 'cac-settings', array( $this, 'render_settings_page' )
        );
        add_submenu_page(
            'content-audit-cleanup', 'Verificação de Sitemap', 'Sitemap',
            'manage_options', 'cac-sitemap', array( $this, 'render_sitemap_page' )
        );
    }

    /* ─────────────────────── CONFIGURAÇÕES ─────────────────────────── */

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sem permissão.', 'content-audit-cleanup' ) );
        }
        if ( isset( $_GET['cac_saved'] ) ) {
            echo '<div class="notice notice-success"><p>Configurações salvas.</p></div>';
        }
        if ( isset( $_GET['cac_imported'] ) ) {
            echo '<div class="notice notice-success"><p>Configuração importada com sucesso.</p></div>';
        }
        if ( isset( $_GET['cac_import_error'] ) ) {
            echo '<div class="notice notice-error"><p>Erro ao importar: arquivo JSON inválido.</p></div>';
        }

        $keywords    = get_option( CAC_OPTION_NICHE_KEYWORDS, $this->default_keywords_template() );
        $stale_months = (int) get_option( CAC_OPTION_STALE_MONTHS, 8 );
        $sitemap_url  = get_option( CAC_OPTION_SITEMAP_URL, home_url( '/sitemap_index.xml' ) );
        ?>
        <div class="wrap">
            <h1>⚙️ Configurações — Auditoria de Conteúdo</h1>
            <p>Cada site/nicho tem suas próprias regras. Configure aqui o que é <strong>fora do nicho deste site</strong>, o prazo de "desatualizado", e a URL correta do sitemap.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cac_save_settings' ); ?>
                <input type="hidden" name="action" value="cac_save_settings">
                <table class="form-table">
                    <tr>
                        <th><label for="cac_noindex_kw">Palavras-chave: Noindex (fora do nicho)</label></th>
                        <td>
                            <textarea name="noindex_keywords" id="cac_noindex_kw" rows="4" cols="70"><?php echo esc_textarea( implode( "\n", (array) $keywords['noindex'] ) ); ?></textarea>
                            <p class="description">Uma palavra/expressão por linha. Posts cujo título contenha essas palavras serão marcados como Noindex.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="cac_remover_kw">Palavras-chave: Remover (risco reputacional)</label></th>
                        <td><textarea name="remover_keywords" id="cac_remover_kw" rows="3" cols="70"><?php echo esc_textarea( implode( "\n", (array) $keywords['remover'] ) ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="cac_revisar_kw">Palavras-chave: Revisar categoria</label></th>
                        <td><textarea name="revisar_keywords" id="cac_revisar_kw" rows="3" cols="70"><?php echo esc_textarea( implode( "\n", (array) $keywords['revisar'] ) ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="cac_fundir_kw">Palavras-chave: Fundir (conteúdo duplicado)</label></th>
                        <td><textarea name="fundir_keywords" id="cac_fundir_kw" rows="2" cols="70"><?php echo esc_textarea( implode( "\n", (array) $keywords['fundir'] ) ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="cac_stale">Considerar desatualizado após (meses)</label></th>
                        <td><input type="number" name="stale_months" id="cac_stale" value="<?php echo esc_attr( $stale_months ); ?>" min="1" max="36" style="width:80px;"> meses</td>
                    </tr>
                    <tr>
                        <th><label for="cac_sitemap">URL correta do sitemap deste site</label></th>
                        <td><input type="text" name="sitemap_url" id="cac_sitemap" value="<?php echo esc_attr( $sitemap_url ); ?>" style="width:500px;"></td>
                    </tr>
                </table>
                <?php submit_button( 'Salvar configurações' ); ?>
            </form>

            <hr>
            <h2>Templates rápidos por tipo de projeto</h2>
            <p>
                <button type="button" class="button" onclick="cacFillTemplate('marketing')">Marketing/SEO/Blog pessoal</button>
                <button type="button" class="button" onclick="cacFillTemplate('noticias')">Portal de Notícias</button>
                <button type="button" class="button" onclick="cacFillTemplate('afiliados')">Afiliados/E-commerce</button>
                <button type="button" class="button" onclick="cacFillTemplate('receitas')">Nicho de Receitas</button>
                <button type="button" class="button" onclick="cacFillTemplate('generico')">Genérico</button>
            </p>

            <hr>
            <h2>Exportar / Importar Configuração de Nicho</h2>
            <p>Copie rapidamente as regras de um site para outro. O JSON exportado contém todas as palavras-chave e o prazo de desatualização.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:10px;">
                <?php wp_nonce_field( 'cac_export_config' ); ?>
                <input type="hidden" name="action" value="cac_export_config">
                <button type="submit" class="button">⬇ Exportar configuração (JSON)</button>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="display:inline-block;">
                <?php wp_nonce_field( 'cac_import_config' ); ?>
                <input type="hidden" name="action" value="cac_import_config">
                <input type="file" name="cac_import_file" accept=".json" required>
                <button type="submit" class="button">⬆ Importar configuração (JSON)</button>
            </form>
        </div>
        <script>
        function cacFillTemplate(type) {
            var templates = {
                marketing: { noindex: "política\nguerra\ntragédia\nassassinato", remover: "política\nassassinato\ntragédia\nguerra", revisar: "inovação genérica\ntecnologia geral", fundir: "" },
                noticias:  { noindex: "", remover: "boato não confirmado\nfake news", revisar: "", fundir: "" },
                afiliados: { noindex: "notícias\npolitica\nfutebol", remover: "concorrente direto\nmarca registrada de terceiro", revisar: "produto descontinuado", fundir: "" },
                receitas:  { noindex: "politica\nnoticias\ntecnologia", remover: "", revisar: "receita sem foto\nreceita sem ingredientes claros", fundir: "" },
                generico:  { noindex: "", remover: "conteúdo sensível\npolítica\ntragédia", revisar: "", fundir: "" }
            };
            var t = templates[type];
            if (!t) return;
            document.getElementById('cac_noindex_kw').value = t.noindex || '';
            document.getElementById('cac_remover_kw').value = t.remover || '';
            document.getElementById('cac_revisar_kw').value = t.revisar || '';
            document.getElementById('cac_fundir_kw').value  = t.fundir  || '';
            alert('Sugestão aplicada. Ajuste conforme o nicho real deste site e clique em Salvar.');
        }
        </script>
        <?php
    }

    private function default_keywords_template() {
        return array( 'noindex' => array(), 'remover' => array(), 'fundir' => array(), 'revisar' => array() );
    }

    public function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sem permissão.', 'content-audit-cleanup' ) );
        }
        check_admin_referer( 'cac_save_settings' );

        $parse = function( $field ) {
            $raw   = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
            $lines = preg_split( '/\r\n|\r|\n/', $raw );
            $lines = array_map( 'trim', $lines );
            $lines = array_map( 'sanitize_text_field', $lines );
            return array_values( array_filter( $lines ) );
        };

        $keywords = array(
            'noindex' => $parse( 'noindex_keywords' ),
            'remover' => $parse( 'remover_keywords' ),
            'fundir'  => $parse( 'fundir_keywords' ),
            'revisar' => $parse( 'revisar_keywords' ),
        );

        update_option( CAC_OPTION_NICHE_KEYWORDS, $keywords );
        update_option( CAC_OPTION_STALE_MONTHS, max( 1, min( 36, (int) ( $_POST['stale_months'] ?? 8 ) ) ) );

        $sitemap_raw = isset( $_POST['sitemap_url'] ) ? wp_unslash( $_POST['sitemap_url'] ) : '';
        update_option( CAC_OPTION_SITEMAP_URL, esc_url_raw( $sitemap_raw ) );

        wp_safe_redirect( admin_url( 'admin.php?page=cac-settings&cac_saved=1' ) );
        exit;
    }

    /* ─────────────────────── EXPORT / IMPORT CONFIG ──────────────────── */

    public function handle_export_config() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sem permissão.', 'content-audit-cleanup' ) );
        }
        check_admin_referer( 'cac_export_config' );

        $data = array(
            'plugin'        => 'content-audit-cleanup',
            'version'       => CAC_VERSION,
            'exported_at'   => current_time( 'c' ),
            'site_url'      => home_url(),
            'keywords'      => get_option( CAC_OPTION_NICHE_KEYWORDS, $this->default_keywords_template() ),
            'stale_months'  => (int) get_option( CAC_OPTION_STALE_MONTHS, 8 ),
            'sitemap_url'   => get_option( CAC_OPTION_SITEMAP_URL, '' ),
        );

        $filename = 'cac-config-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . date( 'Y-m-d' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    public function handle_import_config() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sem permissão.', 'content-audit-cleanup' ) );
        }
        check_admin_referer( 'cac_import_config' );

        if ( empty( $_FILES['cac_import_file']['tmp_name'] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=cac-settings&cac_import_error=1' ) );
            exit;
        }

        $tmp  = $_FILES['cac_import_file']['tmp_name'];
        $json = file_get_contents( $tmp );
        if ( $json === false ) {
            wp_safe_redirect( admin_url( 'admin.php?page=cac-settings&cac_import_error=1' ) );
            exit;
        }

        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'content-audit-cleanup' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=cac-settings&cac_import_error=1' ) );
            exit;
        }

        // Sanitize imported keywords
        $sanitize_list = function( $list ) {
            if ( ! is_array( $list ) ) return array();
            return array_values( array_filter( array_map( 'sanitize_text_field', $list ) ) );
        };

        $kw = $data['keywords'] ?? array();
        update_option( CAC_OPTION_NICHE_KEYWORDS, array(
            'noindex' => $sanitize_list( $kw['noindex'] ?? array() ),
            'remover' => $sanitize_list( $kw['remover'] ?? array() ),
            'fundir'  => $sanitize_list( $kw['fundir']  ?? array() ),
            'revisar' => $sanitize_list( $kw['revisar'] ?? array() ),
        ) );

        if ( isset( $data['stale_months'] ) ) {
            update_option( CAC_OPTION_STALE_MONTHS, max( 1, min( 36, (int) $data['stale_months'] ) ) );
        }
        if ( ! empty( $data['sitemap_url'] ) ) {
            update_option( CAC_OPTION_SITEMAP_URL, esc_url_raw( $data['sitemap_url'] ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=cac-settings&cac_imported=1' ) );
        exit;
    }

    /* ─────────────────────── SITEMAP / STAGING ───────────────────────── */

    public function render_sitemap_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sem permissão.', 'content-audit-cleanup' ) );
        }
        $results = get_option( 'cac_sitemap_scan_results', array() );
        ?>
        <div class="wrap">
            <h1>🔗 Verificação de Sitemap & Links de Staging</h1>
            <p>Escaneia posts, páginas, widgets, Customizer, robots.txt e o próprio sitemap.xml em busca de domínios de staging (stackstaging.com, .local, localhost, wpenginepowered.com, kinsta.cloud, etc.)</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cac_check_sitemap' ); ?>
                <input type="hidden" name="action" value="cac_check_sitemap">
                <button type="submit" class="button button-primary">🔍 Escanear agora</button>
                <span class="description" style="margin-left:8px;">Em sites com muitos posts isso pode levar alguns segundos.</span>
            </form>

            <?php if ( ! empty( $results ) ) : ?>
                <h2 style="margin-top:25px;">Resultado do último escaneamento</h2>
                <?php if ( empty( $results['found'] ) ) : ?>
                    <div class="notice notice-success"><p>✅ Nenhum link de staging encontrado.</p></div>
                <?php else : ?>
                    <div class="notice notice-error"><p>⚠️ Encontrados <strong><?php echo count( $results['found'] ); ?></strong> locais com link suspeito de staging:</p></div>
                    <table class="widefat">
                        <thead><tr><th>Local</th><th>Link encontrado</th><th>Sugestão de correção</th></tr></thead>
                        <tbody>
                        <?php foreach ( $results['found'] as $item ) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url( $item['edit_link'] ); ?>" target="_blank"><?php echo esc_html( $item['title'] ); ?></a></td>
                                <td><code><?php echo esc_html( $item['url'] ); ?></code></td>
                                <td style="font-size:12px;">Substituir pelo domínio de produção: <code><?php echo esc_html( home_url() ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <p class="description">Escaneado em: <?php echo esc_html( $results['scanned_at'] ); ?>
                <?php if ( ! empty( $results['sources_checked'] ) ) : ?>
                 — Fontes verificadas: <?php echo esc_html( implode( ', ', $results['sources_checked'] ) ); ?>
                <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_check_sitemap() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sem permissão.', 'content-audit-cleanup' ) );
        }
        check_admin_referer( 'cac_check_sitemap' );

        @set_time_limit( 120 );

        $staging_patterns = array(
            'stackstaging.com', 'wpengine.com/staging', '.staging.', 'staging.',
            'localhost', '.local', 'tempurl.host', 'cloudwaysapps.com',
            'wpenginepowered.com', 'kinsta.cloud', 'flywheelsites.com',
            'myftpupload.com', 'azurewebsites.net', 'instawp.xyz',
        );

        $found           = array();
        $sources_checked = array();

        // ── 1. Posts e páginas em batches ────────────────────────────────
        $sources_checked[] = 'posts/páginas';
        global $wpdb;
        $offset = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title, post_type, post_content
                 FROM {$wpdb->posts}
                 WHERE post_type IN ('post','page') AND post_status = 'publish'
                 ORDER BY ID ASC LIMIT %d OFFSET %d",
                CAC_SITEMAP_BATCH, $offset
            ) );

            foreach ( $rows as $p ) {
                foreach ( $staging_patterns as $pattern ) {
                    if ( stripos( $p->post_content, $pattern ) !== false ) {
                        preg_match(
                            '/https?:\/\/[^\s"\'<>]*' . preg_quote( $pattern, '/' ) . '[^\s"\'<>]*/i',
                            $p->post_content, $m
                        );
                        $found[] = array(
                            'title'     => get_the_title( $p->ID ) . ' (' . $p->post_type . ')',
                            'url'       => $m[0] ?? $pattern,
                            'edit_link' => get_edit_post_link( $p->ID, '' ),
                        );
                        break;
                    }
                }
            }

            $offset += CAC_SITEMAP_BATCH;
        } while ( count( $rows ) === CAC_SITEMAP_BATCH );

        // ── 2. Widgets de texto clássicos (widget_text) ──────────────────
        $sources_checked[] = 'widgets de texto';
        $widgets = get_option( 'widget_text', array() );
        if ( is_array( $widgets ) ) {
            foreach ( $widgets as $idx => $w ) {
                if ( ! is_array( $w ) ) continue;
                $content = $w['text'] ?? ( $w['content'] ?? '' );
                foreach ( $staging_patterns as $pattern ) {
                    if ( stripos( $content, $pattern ) !== false ) {
                        $found[] = array(
                            'title'     => 'Widget de Texto #' . $idx,
                            'url'       => $pattern . ' (widget de texto)',
                            'edit_link' => admin_url( 'widgets.php' ),
                        );
                        break;
                    }
                }
            }
        }

        // ── 3. Block widgets / Gutenberg widgets (widget_block) ──────────
        $sources_checked[] = 'block widgets';
        $block_widgets = get_option( 'widget_block', array() );
        if ( is_array( $block_widgets ) ) {
            foreach ( $block_widgets as $idx => $w ) {
                if ( ! is_array( $w ) ) continue;
                $content = $w['content'] ?? '';
                foreach ( $staging_patterns as $pattern ) {
                    if ( stripos( $content, $pattern ) !== false ) {
                        $found[] = array(
                            'title'     => 'Block Widget #' . $idx,
                            'url'       => $pattern . ' (block widget)',
                            'edit_link' => admin_url( 'widgets.php' ),
                        );
                        break;
                    }
                }
            }
        }

        // ── 4. Customizer: theme_mods ─────────────────────────────────────
        $sources_checked[] = 'Customizer';
        $theme_mods = get_theme_mods();
        if ( is_array( $theme_mods ) ) {
            foreach ( $theme_mods as $mod_key => $mod_value ) {
                if ( ! is_string( $mod_value ) ) continue;
                foreach ( $staging_patterns as $pattern ) {
                    if ( stripos( $mod_value, $pattern ) !== false ) {
                        $found[] = array(
                            'title'     => 'Customizer — ' . esc_html( $mod_key ),
                            'url'       => $pattern . ' (theme_mod: ' . $mod_key . ')',
                            'edit_link' => admin_url( 'customize.php' ),
                        );
                        break;
                    }
                }
            }
        }

        // ── 5. Rank Math robots.txt option ───────────────────────────────
        $sources_checked[] = 'robots.txt (Rank Math)';
        $rm_robots = get_option( 'rank_math_robots_txt_content', '' );
        if ( $rm_robots ) {
            foreach ( $staging_patterns as $pattern ) {
                if ( stripos( $rm_robots, $pattern ) !== false ) {
                    $found[] = array(
                        'title'     => 'Rank Math — robots.txt',
                        'url'       => $pattern . ' (robots.txt gerenciado pelo Rank Math)',
                        'edit_link' => admin_url( 'admin.php?page=rank-math-general' ),
                    );
                }
            }
        }

        // ── 6. Arquivo robots.txt físico ─────────────────────────────────
        $robots_file = ABSPATH . 'robots.txt';
        if ( is_readable( $robots_file ) ) {
            $sources_checked[] = 'robots.txt (arquivo físico)';
            $robots_content = file_get_contents( $robots_file );
            if ( $robots_content ) {
                foreach ( $staging_patterns as $pattern ) {
                    if ( stripos( $robots_content, $pattern ) !== false ) {
                        $found[] = array(
                            'title'     => 'Arquivo robots.txt (físico)',
                            'url'       => $pattern . ' (robots.txt)',
                            'edit_link' => admin_url( 'options-reading.php' ),
                        );
                        break;
                    }
                }
            }
        }

        // ── 7. Sitemap XML real — verificar <loc> vs home_url() ──────────
        $sources_checked[] = 'sitemap.xml';
        $sitemap_url = get_option( CAC_OPTION_SITEMAP_URL, home_url( '/sitemap_index.xml' ) );
        $production_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $sitemap_content = $this->fetch_sitemap_safely( $sitemap_url );
        if ( $sitemap_content ) {
            preg_match_all( '/<loc>(.*?)<\/loc>/i', $sitemap_content, $loc_matches );
            foreach ( $loc_matches[1] as $loc ) {
                $loc       = trim( $loc );
                $loc_host  = wp_parse_url( $loc, PHP_URL_HOST );
                if ( ! $loc_host || $loc_host === $production_host ) continue;
                // loc tem host diferente do de produção — checar se é staging
                $is_staging = false;
                foreach ( $staging_patterns as $pat ) {
                    if ( stripos( $loc, $pat ) !== false ) {
                        $is_staging = true;
                        break;
                    }
                }
                if ( $is_staging || $loc_host !== $production_host ) {
                    $found[] = array(
                        'title'     => 'Sitemap XML — &lt;loc&gt; com host incorreto',
                        'url'       => esc_url( $loc ),
                        'edit_link' => admin_url( 'admin.php?page=rank-math-sitemap' ),
                    );
                }
            }
        }

        // Limitar array de resultados pra não inflar a opção além do necessário
        $found = array_slice( $found, 0, 500 );

        update_option( 'cac_sitemap_scan_results', array(
            'found'           => $found,
            'scanned_at'      => current_time( 'd/m/Y H:i' ),
            'sources_checked' => $sources_checked,
        ) );

        wp_safe_redirect( admin_url( 'admin.php?page=cac-sitemap' ) );
        exit;
    }

    /**
     * Busca o sitemap com timeout curto pra não bloquear a requisição de admin.
     */
    private function fetch_sitemap_safely( string $url ): string {
        if ( empty( $url ) ) return '';
        $response = wp_remote_get( $url, array(
            'timeout'    => 10,
            'user-agent' => 'Content-Audit-Cleanup/' . CAC_VERSION,
            'sslverify'  => false,
        ) );
        if ( is_wp_error( $response ) ) return '';
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) return '';
        return wp_remote_retrieve_body( $response );
    }

    /* ─────────────────────── TELA PRINCIPAL ─────────────────────────── */

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sem permissão.', 'content-audit-cleanup' ) );
        }

        if ( isset( $_GET['cac_run_audit'] ) && check_admin_referer( 'cac_run_audit' ) ) {
            @set_time_limit( 300 );
            $count = $this->run_auto_audit();
            $stale = $this->run_stale_scan();
            printf(
                '<div class="notice notice-success"><p>Auditoria concluída: <strong>%d</strong> posts classificados, <strong>%d</strong> identificados como desatualizados.</p></div>',
                (int) $count, (int) $stale
            );
        }

        if ( isset( $_GET['cac_done'] ) ) {
            echo '<div class="notice notice-success"><p>Ação aplicada com sucesso.</p></div>';
        }

        $keywords  = get_option( CAC_OPTION_NICHE_KEYWORDS, $this->default_keywords_template() );
        $has_rules = ! empty( $keywords['noindex'] ) || ! empty( $keywords['remover'] );
        $filter    = isset( $_GET['cac_filter'] ) ? sanitize_key( $_GET['cac_filter'] ) : 'all';

        // ── Paginação real ────────────────────────────────────────────────
        $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $args = array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'draft', 'pending' ),
            'posts_per_page' => CAC_PAGE_SIZE,
            'paged'          => $current_page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        );
        if ( $filter === 'precisa_atualizar' ) {
            $args['meta_query'] = array( array( 'key' => CAC_META_NEEDS_UPDATE, 'value' => '1' ) );
        } elseif ( $filter === 'indefinido' ) {
            $args['meta_query'] = array( array( 'key' => CAC_META_STATUS, 'compare' => 'NOT EXISTS' ) );
        } elseif ( $filter !== 'all' ) {
            $args['meta_query'] = array( array( 'key' => CAC_META_STATUS, 'value' => $filter ) );
        }
        $query  = new WP_Query( $args );
        $counts = $this->get_status_counts();

        $total_pages = $query->max_num_pages;
        $base_url    = admin_url( 'admin.php?page=content-audit-cleanup&cac_filter=' . $filter );
        ?>
        <style>
            .cac-status-pill { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; }
            .cac-status-manter    { background:#d4f4dd; color:#1a7a36; }
            .cac-status-fundir    { background:#fff3cd; color:#8a6500; }
            .cac-status-noindex   { background:#ffe1c2; color:#a14d00; }
            .cac-status-remover   { background:#fddede; color:#a30000; }
            .cac-status-revisar   { background:#e2e2ff; color:#3a3aa0; }
            .cac-status-indefinido{ background:#eee; color:#555; }
            .cac-bulkbar { margin:12px 0; padding:12px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px; }
            .cac-badge-update  { background:#fff0c2; color:#8a5a00; padding:2px 8px; border-radius:10px; font-size:11px; margin:2px 2px; display:inline-block; }
            .cac-badge-geo     { background:#dff0ff; color:#0050a0; padding:2px 8px; border-radius:10px; font-size:11px; margin:2px 2px; display:inline-block; }
            .cac-badge-pending { background:#fff3cd; color:#7a5200; padding:2px 8px; border-radius:10px; font-size:11px; margin:2px 2px; display:inline-block; }
            .cac-badge-done    { background:#d4f4dd; color:#1a7a36; padding:2px 8px; border-radius:10px; font-size:11px; margin:2px 2px; display:inline-block; }
            .cac-badge-failed  { background:#fddede; color:#a30000; padding:2px 8px; border-radius:10px; font-size:11px; margin:2px 2px; display:inline-block; }
        </style>
        <div class="wrap">
            <h1>🧹 Auditoria de Conteúdo & Limpeza</h1>

            <?php if ( ! $has_rules ) : ?>
                <div class="notice notice-warning">
                    <p>⚠️ Configure as palavras-chave de nicho deste site antes de rodar a auditoria — vá em <a href="<?php echo esc_url( admin_url( 'admin.php?page=cac-settings' ) ); ?>">Configurações</a>.</p>
                </div>
            <?php endif; ?>

            <p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=content-audit-cleanup&cac_run_audit=1' ), 'cac_run_audit' ) ); ?>" class="button button-primary">▶ Rodar auditoria automática</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cac-settings' ) ); ?>" class="button">⚙️ Configurar nicho</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cac-sitemap' ) ); ?>" class="button">🔗 Verificar sitemap/staging</a>
            </p>

            <div style="margin:15px 0;">
                <strong>Filtrar:</strong>
                <?php
                $filters = array(
                    'all'             => 'Todos',
                    'manter'          => 'Manter (' . (int)$counts['manter'] . ')',
                    'fundir'          => 'Fundir (' . (int)$counts['fundir'] . ')',
                    'noindex'         => 'Noindex (' . (int)$counts['noindex'] . ')',
                    'remover'         => 'Remover (' . (int)$counts['remover'] . ')',
                    'revisar'         => 'Revisar (' . (int)$counts['revisar'] . ')',
                    'indefinido'      => 'Indefinido (' . (int)$counts['indefinido'] . ')',
                    'precisa_atualizar' => '📅 Desatualizados (' . (int)$counts['precisa_atualizar'] . ')',
                );
                foreach ( $filters as $key => $label ) {
                    $active = $filter === $key ? 'button-primary' : '';
                    $url    = admin_url( 'admin.php?page=content-audit-cleanup&cac_filter=' . $key );
                    echo '<a class="button ' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
                }
                ?>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cac_bulk_action' ); ?>
                <input type="hidden" name="action" value="cac_apply_action">
                <div class="cac-bulkbar">
                    <strong>Ação em massa:</strong>
                    <select name="bulk_action">
                        <option value="">— escolher —</option>
                        <option value="apply_noindex">Aplicar Noindex</option>
                        <option value="remove_noindex">Remover Noindex</option>
                        <option value="trash">Mover para lixeira</option>
                        <option value="mark_manter">Marcar: Manter</option>
                        <option value="mark_fundir">Marcar: Fundir</option>
                        <option value="mark_revisar">Marcar: Revisar categoria</option>
                        <option value="send_to_geo">📤 Enviar para reescrita (GEO Método SEO)</option>
                    </select>
                    <button type="submit" class="button button-primary" onclick="return confirm('Confirma aplicar essa ação nos posts selecionados?');">Aplicar</button>
                </div>

                <table class="widefat">
                    <thead><tr>
                        <th style="width:30px;"><input type="checkbox" id="cac-check-all" onclick="document.querySelectorAll('.cac-check').forEach(c=>c.checked=this.checked)"></th>
                        <th>Título</th>
                        <th style="width:130px;">Status</th>
                        <th style="width:180px;">Sinalizações</th>
                        <th style="width:110px;">Modificado em</th>
                    </tr></thead>
                    <tbody>
                    <?php if ( $query->have_posts() ) :
                        while ( $query->have_posts() ) : $query->the_post();
                            $post_id    = get_the_ID();
                            $status     = get_post_meta( $post_id, CAC_META_STATUS, true ) ?: 'indefinido';
                            $needs_upd  = get_post_meta( $post_id, CAC_META_NEEDS_UPDATE, true );
                            $sent_to_geo = get_post_meta( $post_id, CAC_META_SENT_TO_GEO, true );
                            $geo_status  = get_post_meta( $post_id, CAC_META_GEO_STATUS, true );
                            ?>
                            <tr>
                                <td><input type="checkbox" class="cac-check" name="post_ids[]" value="<?php echo esc_attr( $post_id ); ?>"></td>
                                <td><a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank"><?php the_title(); ?></a></td>
                                <td><span class="cac-status-pill cac-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
                                <td>
                                    <?php if ( $needs_upd ) : ?>
                                        <span class="cac-badge-update">📅 desatualizado</span>
                                    <?php endif; ?>
                                    <?php if ( $sent_to_geo ) : ?>
                                        <?php if ( $geo_status === 'pending' ) : ?>
                                            <span class="cac-badge-pending">⏳ reescrita aguardando</span>
                                        <?php elseif ( $geo_status === 'done' ) : ?>
                                            <span class="cac-badge-done">✅ reescrito pelo GEO</span>
                                        <?php elseif ( $geo_status === 'failed' ) : ?>
                                            <span class="cac-badge-failed">❌ reescrita falhou</span>
                                        <?php else : ?>
                                            <span class="cac-badge-geo">📤 enviado p/ GEO</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( get_the_modified_date( 'd/m/Y' ) ); ?></td>
                            </tr>
                        <?php endwhile; wp_reset_postdata();
                    else : ?>
                        <tr><td colspan="5">Nenhum post encontrado para esse filtro.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav" style="margin-top:10px;">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links( array(
                                'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                                'format'    => '',
                                'current'   => $current_page,
                                'total'     => $total_pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            ) );
                            ?>
                            <span class="displaying-num" style="margin-left:10px;">Página <?php echo (int)$current_page; ?> de <?php echo (int)$total_pages; ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    private function get_status_counts() {
        global $wpdb;
        $counts  = array( 'manter'=>0,'fundir'=>0,'noindex'=>0,'remover'=>0,'revisar'=>0,'indefinido'=>0,'precisa_atualizar'=>0 );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_value, COUNT(*) c
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type = 'post'
               AND p.post_status NOT IN ('trash','auto-draft')
             GROUP BY pm.meta_value",
            CAC_META_STATUS
        ) );

        $classified = 0;
        foreach ( $results as $row ) {
            if ( isset( $counts[ $row->meta_value ] ) ) {
                $counts[ $row->meta_value ] = (int) $row->c;
                $classified += (int) $row->c;
            }
        }

        $total = (int) wp_count_posts( 'post' )->publish
               + (int) wp_count_posts( 'post' )->draft
               + (int) wp_count_posts( 'post' )->pending;
        $counts['indefinido'] = max( 0, $total - $classified );

        $counts['precisa_atualizar'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value = '1'
               AND p.post_type = 'post' AND p.post_status != 'trash'",
            CAC_META_NEEDS_UPDATE
        ) );

        return $counts;
    }

    /* ─────────────────────── AUDIT (BATCH) ──────────────────────────── */

    /**
     * Classifica posts por keyword match em batches de CAC_BATCH_SIZE.
     * Usa wpdb direto pra evitar overhead do WP_Query/get_posts com posts_per_page=-1.
     * Retorna o total de posts classificados nesta execução.
     */
    private function run_auto_audit(): int {
        global $wpdb;
        $rules = get_option( CAC_OPTION_NICHE_KEYWORDS, $this->default_keywords_template() );

        // Pré-compila lookup de keywords → status (prioridade: remover > noindex > fundir > revisar)
        $kw_map = array();
        foreach ( array( 'remover', 'noindex', 'fundir', 'revisar' ) as $status ) {
            foreach ( (array) ( $rules[ $status ] ?? array() ) as $kw ) {
                $kw = trim( mb_strtolower( $kw ) );
                if ( $kw !== '' && ! isset( $kw_map[ $kw ] ) ) {
                    $kw_map[ $kw ] = $status;
                }
            }
        }

        if ( empty( $kw_map ) ) {
            return 0;
        }

        $count  = 0;
        $offset = 0;

        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE post_type = 'post'
                   AND post_status IN ('publish','draft','pending')
                 ORDER BY ID ASC LIMIT %d OFFSET %d",
                CAC_BATCH_SIZE, $offset
            ) );

            foreach ( $rows as $row ) {
                $title   = mb_strtolower( $row->post_title );
                $matched = null;

                foreach ( $kw_map as $kw => $status ) {
                    if ( mb_strpos( $title, $kw ) !== false ) {
                        $matched = $status;
                        break;
                    }
                }

                if ( $matched ) {
                    update_post_meta( (int) $row->ID, CAC_META_STATUS, $matched );
                    $count++;
                    if ( in_array( $matched, array( 'noindex', 'remover' ), true ) ) {
                        $this->apply_noindex( (int) $row->ID );
                    }
                }
            }

            $offset += CAC_BATCH_SIZE;
        } while ( count( $rows ) === CAC_BATCH_SIZE );

        return $count;
    }

    /**
     * Marca posts desatualizados em batches de CAC_BATCH_SIZE.
     * Retorna o número de posts marcados como desatualizados.
     */
    public function run_stale_scan(): int {
        global $wpdb;
        $stale_months = max( 1, (int) get_option( CAC_OPTION_STALE_MONTHS, 8 ) );
        $cutoff_ts    = strtotime( "-{$stale_months} months" );
        $cutoff_date  = date( 'Y-m-d H:i:s', $cutoff_ts );

        $count  = 0;
        $offset = 0;

        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID, p.post_modified, pm.meta_value AS audit_status
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm
                       ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_type = 'post' AND p.post_status = 'publish'
                 ORDER BY p.ID ASC LIMIT %d OFFSET %d",
                CAC_META_STATUS, CAC_BATCH_SIZE, $offset
            ) );

            foreach ( $rows as $row ) {
                $pid = (int) $row->ID;
                // Não marcar como desatualizado posts já marcados pra noindex/remover
                if ( in_array( $row->audit_status, array( 'noindex', 'remover' ), true ) ) {
                    delete_post_meta( $pid, CAC_META_NEEDS_UPDATE );
                    continue;
                }
                $modified_ts = strtotime( $row->post_modified );
                if ( $modified_ts && $modified_ts < $cutoff_ts ) {
                    update_post_meta( $pid, CAC_META_NEEDS_UPDATE, '1' );
                    $count++;
                } else {
                    delete_post_meta( $pid, CAC_META_NEEDS_UPDATE );
                }
            }

            $offset += CAC_BATCH_SIZE;
        } while ( count( $rows ) === CAC_BATCH_SIZE );

        return $count;
    }

    /* ─────────────────────── BULK ACTION ────────────────────────────── */

    public function handle_bulk_action() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sem permissão.', 'content-audit-cleanup' ) );
        }
        check_admin_referer( 'cac_bulk_action' );

        $raw_ids     = isset( $_POST['post_ids'] ) ? (array) $_POST['post_ids'] : array();
        $post_ids    = array_map( 'intval', $raw_ids );
        $post_ids    = array_values( array_filter( $post_ids, fn( $id ) => $id > 0 ) );
        $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';

        if ( empty( $post_ids ) || empty( $bulk_action ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=content-audit-cleanup' ) );
            exit;
        }

        $allowed_actions = array(
            'apply_noindex', 'remove_noindex', 'trash',
            'mark_manter', 'mark_fundir', 'mark_revisar', 'send_to_geo',
        );
        if ( ! in_array( $bulk_action, $allowed_actions, true ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=content-audit-cleanup' ) );
            exit;
        }

        foreach ( $post_ids as $post_id ) {
            // Verifica que o post existe e pertence ao site (evita IDOR cross-site em future multisite)
            $post = get_post( $post_id );
            if ( ! $post || $post->post_type !== 'post' ) {
                continue;
            }

            switch ( $bulk_action ) {
                case 'apply_noindex':
                    $this->apply_noindex( $post_id );
                    break;
                case 'remove_noindex':
                    $this->remove_noindex( $post_id );
                    break;
                case 'trash':
                    wp_trash_post( $post_id );
                    break;
                case 'mark_manter':
                    update_post_meta( $post_id, CAC_META_STATUS, 'manter' );
                    break;
                case 'mark_fundir':
                    update_post_meta( $post_id, CAC_META_STATUS, 'fundir' );
                    break;
                case 'mark_revisar':
                    update_post_meta( $post_id, CAC_META_STATUS, 'revisar' );
                    break;
                case 'send_to_geo':
                    update_post_meta( $post_id, CAC_META_SENT_TO_GEO, '1' );
                    update_post_meta( $post_id, CAC_META_GEO_STATUS, 'pending' );
                    /**
                     * Dispara a integração com o GEO Método SEO.
                     * O hook é implementado em geo-metodo-seo/includes/Bridge/CACIntegration.php.
                     * Se o GEO Método SEO não estiver ativo, a action simplesmente não faz nada.
                     *
                     * @param int $post_id ID do post a ser reescrito.
                     */
                    do_action( 'cac_send_post_to_geo_metodo', $post_id );
                    break;
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=content-audit-cleanup&cac_done=1' ) );
        exit;
    }

    /* ─────────────────────── NOINDEX HELPERS ────────────────────────── */

    private function apply_noindex( int $post_id ): void {
        update_post_meta( $post_id, 'rank_math_robots',                 array( 'noindex' ) );
        update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
        update_post_meta( $post_id, CAC_META_NOINDEX,                   1 );
    }

    private function remove_noindex( int $post_id ): void {
        delete_post_meta( $post_id, 'rank_math_robots' );
        delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex' );
        delete_post_meta( $post_id, CAC_META_NOINDEX );
    }

    public function filter_rankmath_robots( $robots ) {
        if ( is_singular( 'post' ) ) {
            $post_id = get_queried_object_id();
            if ( get_post_meta( $post_id, CAC_META_NOINDEX, true ) ) {
                $robots['noindex'] = 'noindex';
            }
        }
        return $robots;
    }

    public function output_manual_noindex(): void {
        if ( is_singular( 'post' ) ) {
            $post_id = get_queried_object_id();
            if (
                get_post_meta( $post_id, CAC_META_NOINDEX, true )
                && ! function_exists( 'rank_math' )
                && ! defined( 'WPSEO_VERSION' )
            ) {
                echo '<meta name="robots" content="noindex, follow" />' . "\n";
            }
        }
    }

    /* ─────────────────────── COLUNA NA LISTA DE POSTS ─────────────── */

    public function add_audit_column( $columns ) {
        $columns['cac_status'] = 'Auditoria';
        return $columns;
    }

    public function render_audit_column( $column, $post_id ) {
        if ( $column !== 'cac_status' ) return;
        $status     = get_post_meta( $post_id, CAC_META_STATUS, true ) ?: 'indefinido';
        $needs_upd  = get_post_meta( $post_id, CAC_META_NEEDS_UPDATE, true );
        $geo_status = get_post_meta( $post_id, CAC_META_GEO_STATUS, true );

        $colors = array(
            'manter'    => '#d4f4dd:#1a7a36',
            'fundir'    => '#fff3cd:#8a6500',
            'noindex'   => '#ffe1c2:#a14d00',
            'remover'   => '#fddede:#a30000',
            'revisar'   => '#e2e2ff:#3a3aa0',
            'indefinido'=> '#eee:#555',
        );
        list( $bg, $color ) = explode( ':', $colors[ $status ] ?? '#eee:#555' );
        echo '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $color ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
        if ( $needs_upd )           echo ' <span title="Desatualizado">📅</span>';
        if ( $geo_status === 'pending' ) echo ' <span title="Reescrita aguardando GEO">⏳</span>';
        if ( $geo_status === 'done' )    echo ' <span title="Reescrito pelo GEO">✅</span>';
        if ( $geo_status === 'failed' )  echo ' <span title="Falha na reescrita">❌</span>';
    }
}

Content_Audit_Cleanup::instance();

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'cac_daily_scan' );
} );
