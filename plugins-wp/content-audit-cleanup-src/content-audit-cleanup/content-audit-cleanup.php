<?php
/**
 * Plugin Name: Content Audit & Cleanup (Universal)
 * Plugin URI: https://aiconteudo.com.br
 * Description: Auditoria e limpeza de conteúdo para qualquer nicho. Classifica posts (Manter, Fundir, Noindex, Remover, Revisar, Precisa Atualizar), detecta sitemap apontando para domínio de staging, e prepara posts desatualizados para reescrita via GEO Método SEO.
 * Version: 2.0.0
 * Author: Alison Jean
 * Text Domain: content-audit-cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CAC_VERSION', '2.0.0' );
define( 'CAC_META_STATUS', '_cac_audit_status' );
define( 'CAC_META_NOINDEX', '_cac_noindex_applied' );
define( 'CAC_META_NEEDS_UPDATE', '_cac_needs_update' );
define( 'CAC_META_SENT_TO_GEO', '_cac_sent_to_geo' );
define( 'CAC_OPTION_NICHE_KEYWORDS', 'cac_niche_keywords' );
define( 'CAC_OPTION_STALE_MONTHS', 'cac_stale_months' );
define( 'CAC_OPTION_SITEMAP_URL', 'cac_sitemap_url' );

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
        add_action( 'admin_post_cac_apply_action', array( $this, 'handle_bulk_action' ) );
        add_action( 'admin_post_cac_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_cac_check_sitemap', array( $this, 'handle_check_sitemap' ) );
        add_filter( 'manage_posts_columns', array( $this, 'add_audit_column' ) );
        add_action( 'manage_posts_custom_column', array( $this, 'render_audit_column' ), 10, 2 );

        add_filter( 'rank_math/frontend/robots', array( $this, 'filter_rankmath_robots' ) );
        add_action( 'wp_head', array( $this, 'output_manual_noindex' ), 1 );

        add_action( 'cac_daily_scan', array( $this, 'run_stale_scan' ) );
        if ( ! wp_next_scheduled( 'cac_daily_scan' ) ) {
            wp_schedule_event( time(), 'daily', 'cac_daily_scan' );
        }
    }

    public function register_menu() {
        add_menu_page( 'Auditoria de Conteúdo', 'Auditoria de Conteúdo', 'manage_options', 'content-audit-cleanup', array( $this, 'render_admin_page' ), 'dashicons-broom', 58 );
        add_submenu_page( 'content-audit-cleanup', 'Configurações de Nicho', 'Configurações', 'manage_options', 'cac-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'content-audit-cleanup', 'Verificação de Sitemap', 'Sitemap', 'manage_options', 'cac-sitemap', array( $this, 'render_sitemap_page' ) );
    }

    /* ---------------- CONFIGURAÇÕES POR SITE ---------------- */

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( isset( $_GET['cac_saved'] ) ) echo '<div class="notice notice-success"><p>Configurações salvas.</p></div>';

        $keywords = get_option( CAC_OPTION_NICHE_KEYWORDS, $this->default_keywords_template() );
        $stale_months = get_option( CAC_OPTION_STALE_MONTHS, 8 );
        $sitemap_url = get_option( CAC_OPTION_SITEMAP_URL, home_url( '/sitemap_index.xml' ) );
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
                        <td><textarea name="noindex_keywords" id="cac_noindex_kw" rows="4" cols="70"><?php echo esc_textarea( implode( "\n", $keywords['noindex'] ) ); ?></textarea>
                        <p class="description">Uma palavra/expressão por linha.</p></td>
                    </tr>
                    <tr>
                        <th><label for="cac_remover_kw">Palavras-chave: Remover (risco reputacional)</label></th>
                        <td><textarea name="remover_keywords" id="cac_remover_kw" rows="3" cols="70"><?php echo esc_textarea( implode( "\n", $keywords['remover'] ) ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="cac_revisar_kw">Palavras-chave: Revisar categoria</label></th>
                        <td><textarea name="revisar_keywords" id="cac_revisar_kw" rows="3" cols="70"><?php echo esc_textarea( implode( "\n", $keywords['revisar'] ) ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="cac_fundir_kw">Palavras-chave: Fundir (conteúdo duplicado)</label></th>
                        <td><textarea name="fundir_keywords" id="cac_fundir_kw" rows="2" cols="70"><?php echo esc_textarea( implode( "\n", $keywords['fundir'] ) ); ?></textarea></td>
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

            <h2>Templates rápidos por tipo de projeto</h2>
            <p>
                <button type="button" class="button" onclick="cacFillTemplate('marketing')">Marketing/SEO/Blog pessoal</button>
                <button type="button" class="button" onclick="cacFillTemplate('noticias')">Portal de Notícias (GEANews)</button>
                <button type="button" class="button" onclick="cacFillTemplate('afiliados')">Afiliados/E-commerce (AliShop)</button>
                <button type="button" class="button" onclick="cacFillTemplate('receitas')">Nicho de Receitas (YTRG)</button>
                <button type="button" class="button" onclick="cacFillTemplate('generico')">Genérico (qualquer nicho novo)</button>
            </p>
        </div>
        <script>
        function cacFillTemplate(type) {
            var templates = {
                marketing: { remover: "política\nassassinato\ntragédia\nguerra", revisar: "inovação genérica\ntecnologia geral" },
                noticias: { remover: "boato não confirmado\nfake news", revisar: "" },
                afiliados: { remover: "concorrente direto\nmarca registrada de terceiro", revisar: "produto descontinuado" },
                receitas: { remover: "", revisar: "receita sem foto\nreceita sem ingredientes claros" },
                generico: { remover: "conteúdo sensível\npolítica\ntragédia", revisar: "" }
            };
            var t = templates[type];
            if (!t) return;
            document.getElementById('cac_remover_kw').value = t.remover;
            document.getElementById('cac_revisar_kw').value = t.revisar;
            alert('Sugestão aplicada. Ajuste as palavras-chave de Noindex pra refletir o nicho real deste site e clique em Salvar.');
        }
        </script>
        <?php
    }

    private function default_keywords_template() {
        return array( 'noindex' => array(), 'remover' => array(), 'fundir' => array(), 'revisar' => array() );
    }

    public function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sem permissão.' );
        check_admin_referer( 'cac_save_settings' );

        $parse = function( $field ) {
            $raw = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
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
        update_option( CAC_OPTION_STALE_MONTHS, isset( $_POST['stale_months'] ) ? intval( $_POST['stale_months'] ) : 8 );
        update_option( CAC_OPTION_SITEMAP_URL, isset( $_POST['sitemap_url'] ) ? esc_url_raw( $_POST['sitemap_url'] ) : '' );

        wp_redirect( admin_url( 'admin.php?page=cac-settings&cac_saved=1' ) );
        exit;
    }

    /* ---------------- VERIFICAÇÃO DE SITEMAP/STAGING ---------------- */

    public function render_sitemap_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $results = get_option( 'cac_sitemap_scan_results', array() );
        ?>
        <div class="wrap">
            <h1>🔗 Verificação de Sitemap & Links de Staging</h1>
            <p>Escaneia posts, páginas e widgets procurando links de domínios de staging conhecidos (stackstaging.com, .local, localhost, wpenginepowered.com, kinsta.cloud, etc) em vez do domínio de produção real.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cac_check_sitemap' ); ?>
                <input type="hidden" name="action" value="cac_check_sitemap">
                <button type="submit" class="button button-primary">🔍 Escanear agora</button>
            </form>

            <?php if ( ! empty( $results ) ) : ?>
                <h2 style="margin-top:25px;">Resultado do último escaneamento</h2>
                <?php if ( empty( $results['found'] ) ) : ?>
                    <div class="notice notice-success"><p>✅ Nenhum link de staging encontrado.</p></div>
                <?php else : ?>
                    <div class="notice notice-error"><p>⚠️ Encontrados <?php echo count( $results['found'] ); ?> locais com link suspeito de staging:</p></div>
                    <table class="widefat">
                        <thead><tr><th>Local</th><th>Link encontrado</th><th>Ação</th></tr></thead>
                        <tbody>
                        <?php foreach ( $results['found'] as $item ) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url( $item['edit_link'] ); ?>" target="_blank"><?php echo esc_html( $item['title'] ); ?></a></td>
                                <td><code><?php echo esc_html( $item['url'] ); ?></code></td>
                                <td>Corrigir manualmente para: <code><?php echo esc_html( get_option( CAC_OPTION_SITEMAP_URL, home_url( '/sitemap_index.xml' ) ) ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <p class="description">Escaneado em: <?php echo esc_html( $results['scanned_at'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_check_sitemap() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sem permissão.' );
        check_admin_referer( 'cac_check_sitemap' );

        $staging_patterns = array(
            'stackstaging.com', 'wpengine.com/staging', '.staging.', 'staging.',
            'localhost', '.local', 'tempurl.host', 'cloudwaysapps.com',
            'wpenginepowered.com', 'kinsta.cloud', 'flywheelsites.com',
        );

        $found = array();

        $posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => -1 ) );
        foreach ( $posts as $p ) {
            foreach ( $staging_patterns as $pattern ) {
                if ( stripos( $p->post_content, $pattern ) !== false ) {
                    preg_match( '/https?:\/\/[^\s"\'<>]*' . preg_quote( $pattern, '/' ) . '[^\s"\'<>]*/i', $p->post_content, $m );
                    $found[] = array( 'title' => get_the_title( $p->ID ) . ' (' . $p->post_type . ')', 'url' => isset( $m[0] ) ? $m[0] : $pattern, 'edit_link' => get_edit_post_link( $p->ID, '' ) );
                    break;
                }
            }
        }

        $widgets = get_option( 'widget_text', array() );
        if ( is_array( $widgets ) ) {
            foreach ( $widgets as $idx => $w ) {
                if ( ! is_array( $w ) ) continue;
                $content = isset( $w['text'] ) ? $w['text'] : ( isset( $w['content'] ) ? $w['content'] : '' );
                foreach ( $staging_patterns as $pattern ) {
                    if ( stripos( $content, $pattern ) !== false ) {
                        $found[] = array( 'title' => 'Widget de Texto/HTML #' . $idx, 'url' => $pattern . ' (encontrado no widget)', 'edit_link' => admin_url( 'widgets.php' ) );
                        break;
                    }
                }
            }
        }

        update_option( 'cac_sitemap_scan_results', array( 'found' => $found, 'scanned_at' => current_time( 'd/m/Y H:i' ) ) );
        wp_redirect( admin_url( 'admin.php?page=cac-sitemap' ) );
        exit;
    }

    /* ---------------- TELA PRINCIPAL ---------------- */

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset( $_GET['cac_run_audit'] ) && check_admin_referer( 'cac_run_audit' ) ) {
            $count = $this->run_auto_audit();
            $stale = $this->run_stale_scan();
            echo '<div class="notice notice-success"><p>Auditoria aplicada a ' . intval( $count ) . ' posts. ' . intval( $stale ) . ' posts identificados como desatualizados.</p></div>';
        }

        $keywords = get_option( CAC_OPTION_NICHE_KEYWORDS, $this->default_keywords_template() );
        $has_rules = ! empty( $keywords['noindex'] ) || ! empty( $keywords['remover'] );
        $filter = isset( $_GET['cac_filter'] ) ? sanitize_text_field( $_GET['cac_filter'] ) : 'all';

        $args = array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft', 'pending' ), 'posts_per_page' => 300, 'orderby' => 'date', 'order' => 'DESC' );
        if ( $filter === 'precisa_atualizar' ) {
            $args['meta_query'] = array( array( 'key' => CAC_META_NEEDS_UPDATE, 'value' => '1' ) );
        } elseif ( $filter !== 'all' ) {
            $args['meta_query'] = array( array( 'key' => CAC_META_STATUS, 'value' => $filter ) );
        }
        $query = new WP_Query( $args );
        $counts = $this->get_status_counts();
        ?>
        <style>
            .cac-status-pill { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; }
            .cac-status-manter { background:#d4f4dd; color:#1a7a36; }
            .cac-status-fundir { background:#fff3cd; color:#8a6500; }
            .cac-status-noindex { background:#ffe1c2; color:#a14d00; }
            .cac-status-remover { background:#fddede; color:#a30000; }
            .cac-status-revisar { background:#e2e2ff; color:#3a3aa0; }
            .cac-status-indefinido { background:#eee; color:#555; }
            .cac-bulkbar { margin: 12px 0; padding: 12px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px; }
            .cac-badge-update { background:#fff0c2; color:#8a5a00; padding:2px 8px; border-radius:10px; font-size:11px; }
            .cac-badge-geo { background:#dff0ff; color:#0050a0; padding:2px 8px; border-radius:10px; font-size:11px; }
        </style>
        <div class="wrap">
            <h1>🧹 Auditoria de Conteúdo & Limpeza</h1>

            <?php if ( ! $has_rules ) : ?>
                <div class="notice notice-warning"><p>⚠️ Configure as palavras-chave de nicho deste site antes de rodar a auditoria — vá em <a href="<?php echo esc_url( admin_url( 'admin.php?page=cac-settings' ) ); ?>">Configurações</a>.</p></div>
            <?php endif; ?>

            <p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=content-audit-cleanup&cac_run_audit=1' ), 'cac_run_audit' ) ); ?>" class="button button-primary">▶ Rodar auditoria automática</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cac-settings' ) ); ?>" class="button">⚙️ Configurar nicho deste site</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cac-sitemap' ) ); ?>" class="button">🔗 Verificar sitemap/staging</a>
            </p>

            <div style="margin:15px 0;">
                <strong>Filtrar:</strong>
                <a class="button <?php echo $filter==='all'?'button-primary':''; ?>" href="<?php echo esc_url( admin_url('admin.php?page=content-audit-cleanup&cac_filter=all') ); ?>">Todos</a>
                <a class="button <?php echo $filter==='manter'?'button-primary':''; ?>" href="<?php echo esc_url( admin_url('admin.php?page=content-audit-cleanup&cac_filter=manter') ); ?>">Manter (<?php echo intval($counts['manter']); ?>)</a>
                <a class="button <?php echo $filter==='fundir'?'button-primary':''; ?>" href="<?php echo esc_url( admin_url('admin.php?page=content-audit-cleanup&cac_filter=fundir') ); ?>">Fundir (<?php echo intval($counts['fundir']); ?>)</a>
                <a class="button <?php echo $filter==='noindex'?'button-primary':''; ?>" href="<?php echo esc_url( admin_url('admin.php?page=content-audit-cleanup&cac_filter=noindex') ); ?>">Noindex (<?php echo intval($counts['noindex']); ?>)</a>
                <a class="button <?php echo $filter==='remover'?'button-primary':''; ?>" href="<?php echo esc_url( admin_url('admin.php?page=content-audit-cleanup&cac_filter=remover') ); ?>">Remover (<?php echo intval($counts['remover']); ?>)</a>
                <a class="button <?php echo $filter==='revisar'?'button-primary':''; ?>" href="<?php echo esc_url( admin_url('admin.php?page=content-audit-cleanup&cac_filter=revisar') ); ?>">Revisar (<?php echo intval($counts['revisar']); ?>)</a>
                <a class="button <?php echo $filter==='precisa_atualizar'?'button-primary':''; ?>" href="<?php echo esc_url( admin_url('admin.php?page=content-audit-cleanup&cac_filter=precisa_atualizar') ); ?>">📅 Precisa Atualizar (<?php echo intval($counts['precisa_atualizar']); ?>)</a>
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
                    <button type="submit" class="button button-primary" onclick="return confirm('Confirma aplicar essa ação?');">Aplicar</button>
                </div>

                <table class="widefat">
                    <thead><tr>
                        <th style="width:30px;"><input type="checkbox" onclick="document.querySelectorAll('.cac-check').forEach(c=>c.checked=this.checked)"></th>
                        <th>Título</th><th style="width:130px;">Status</th><th style="width:140px;">Sinalizações</th><th style="width:100px;">Atualizado em</th>
                    </tr></thead>
                    <tbody>
                    <?php if ( $query->have_posts() ) : while ( $query->have_posts() ) : $query->the_post();
                        $post_id = get_the_ID();
                        $status = get_post_meta( $post_id, CAC_META_STATUS, true ) ?: 'indefinido';
                        $needs_update = get_post_meta( $post_id, CAC_META_NEEDS_UPDATE, true );
                        $sent_to_geo = get_post_meta( $post_id, CAC_META_SENT_TO_GEO, true );
                        ?>
                        <tr>
                            <td><input type="checkbox" class="cac-check" name="post_ids[]" value="<?php echo esc_attr($post_id); ?>"></td>
                            <td><a href="<?php echo esc_url( get_edit_post_link($post_id) ); ?>" target="_blank"><?php the_title(); ?></a></td>
                            <td><span class="cac-status-pill cac-status-<?php echo esc_attr($status); ?>"><?php echo esc_html( ucfirst($status) ); ?></span></td>
                            <td>
                                <?php if ( $needs_update ) : ?><span class="cac-badge-update">📅 desatualizado</span><?php endif; ?>
                                <?php if ( $sent_to_geo ) : ?><span class="cac-badge-geo">📤 enviado p/ GEO</span><?php endif; ?>
                            </td>
                            <td><?php echo get_the_modified_date(); ?></td>
                        </tr>
                    <?php endwhile; wp_reset_postdata(); else: ?>
                        <tr><td colspan="5">Nenhum post para esse filtro.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <h2 style="margin-top:25px;">Sobre a integração com GEO Método SEO</h2>
            <p>Este plugin marca os posts desatualizados e dispara a action <code>cac_send_post_to_geo_metodo</code> (com o <code>post_id</code> como parâmetro) quando você usa "Enviar para reescrita". Pra reescrita acontecer de verdade, falta conectar essa action à função real do GEO Método SEO que dispara a reescrita — preciso ver o código-fonte daquele plugin pra saber o nome certo da função/endpoint. Me mostra o arquivo principal dele que eu já deixo a ponte funcionando de verdade, em vez de só marcação.</p>
        </div>
        <?php
    }

    private function get_status_counts() {
        global $wpdb;
        $counts = array( 'manter'=>0,'fundir'=>0,'noindex'=>0,'remover'=>0,'revisar'=>0,'indefinido'=>0,'precisa_atualizar'=>0 );
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_value, COUNT(*) c FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key=%s AND p.post_type='post' AND p.post_status!='trash' GROUP BY meta_value", CAC_META_STATUS
        ) );
        $classified = 0;
        foreach ( $results as $row ) { if ( isset($counts[$row->meta_value]) ) { $counts[$row->meta_value]=(int)$row->c; $classified+=(int)$row->c; } }
        $total = (int) wp_count_posts('post')->publish + (int) wp_count_posts('post')->draft;
        $counts['indefinido'] = max(0, $total - $classified);
        $counts['precisa_atualizar'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value='1'", CAC_META_NEEDS_UPDATE
        ) );
        return $counts;
    }

    private function run_auto_audit() {
        $rules = get_option( CAC_OPTION_NICHE_KEYWORDS, $this->default_keywords_template() );
        $posts = get_posts( array( 'post_type'=>'post','post_status'=>array('publish','draft','pending'),'posts_per_page'=>-1,'fields'=>'ids' ) );
        $count = 0;
        foreach ( $posts as $post_id ) {
            $title = mb_strtolower( get_the_title( $post_id ) );
            $matched = null;
            foreach ( array('remover','noindex','fundir','revisar') as $status ) {
                if ( empty( $rules[$status] ) ) continue;
                foreach ( $rules[$status] as $kw ) {
                    if ( $kw !== '' && mb_strpos( $title, mb_strtolower($kw) ) !== false ) { $matched = $status; break 2; }
                }
            }
            if ( $matched ) {
                update_post_meta( $post_id, CAC_META_STATUS, $matched );
                $count++;
                if ( in_array( $matched, array('noindex','remover'), true ) ) {
                    $this->apply_noindex( $post_id );
                }
            }
        }
        return $count;
    }

    public function run_stale_scan() {
        $stale_months = (int) get_option( CAC_OPTION_STALE_MONTHS, 8 );
        $cutoff = strtotime( "-{$stale_months} months" );
        $posts = get_posts( array( 'post_type'=>'post','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids' ) );
        $count = 0;
        foreach ( $posts as $post_id ) {
            $status = get_post_meta( $post_id, CAC_META_STATUS, true );
            if ( in_array( $status, array( 'noindex', 'remover' ), true ) ) continue;
            $modified = get_post_modified_time( 'U', false, $post_id );
            if ( $modified && $modified < $cutoff ) {
                update_post_meta( $post_id, CAC_META_NEEDS_UPDATE, '1' );
                $count++;
            } else {
                delete_post_meta( $post_id, CAC_META_NEEDS_UPDATE );
            }
        }
        return $count;
    }

    public function handle_bulk_action() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sem permissão.' );
        check_admin_referer( 'cac_bulk_action' );

        $post_ids = isset($_POST['post_ids']) ? array_map('intval', (array) $_POST['post_ids']) : array();
        $bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        if ( empty($post_ids) || empty($bulk_action) ) { wp_redirect( admin_url('admin.php?page=content-audit-cleanup') ); exit; }

        foreach ( $post_ids as $post_id ) {
            switch ( $bulk_action ) {
                case 'apply_noindex': $this->apply_noindex( $post_id ); break;
                case 'remove_noindex': $this->remove_noindex( $post_id ); break;
                case 'trash': wp_trash_post( $post_id ); break;
                case 'mark_manter': update_post_meta( $post_id, CAC_META_STATUS, 'manter' ); break;
                case 'mark_fundir': update_post_meta( $post_id, CAC_META_STATUS, 'fundir' ); break;
                case 'mark_revisar': update_post_meta( $post_id, CAC_META_STATUS, 'revisar' ); break;
                case 'send_to_geo':
                    update_post_meta( $post_id, CAC_META_SENT_TO_GEO, '1' );
                    do_action( 'cac_send_post_to_geo_metodo', $post_id );
                    break;
            }
        }
        wp_redirect( admin_url( 'admin.php?page=content-audit-cleanup&cac_done=1' ) );
        exit;
    }

    private function apply_noindex( $post_id ) {
        update_post_meta( $post_id, 'rank_math_robots', array( 'noindex' ) );
        update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
        update_post_meta( $post_id, CAC_META_NOINDEX, 1 );
    }

    private function remove_noindex( $post_id ) {
        delete_post_meta( $post_id, 'rank_math_robots' );
        delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex' );
        delete_post_meta( $post_id, CAC_META_NOINDEX );
    }

    public function filter_rankmath_robots( $robots ) {
        if ( is_singular('post') ) {
            $post_id = get_queried_object_id();
            if ( get_post_meta( $post_id, CAC_META_NOINDEX, true ) ) $robots['noindex'] = 'noindex';
        }
        return $robots;
    }

    public function output_manual_noindex() {
        if ( is_singular('post') ) {
            $post_id = get_queried_object_id();
            if ( get_post_meta( $post_id, CAC_META_NOINDEX, true ) && ! function_exists('rank_math') && ! defined('WPSEO_VERSION') ) {
                echo '<meta name="robots" content="noindex, follow" />' . "\n";
            }
        }
    }

    public function add_audit_column( $columns ) { $columns['cac_status'] = 'Auditoria'; return $columns; }

    public function render_audit_column( $column, $post_id ) {
        if ( $column === 'cac_status' ) {
            $status = get_post_meta( $post_id, CAC_META_STATUS, true ) ?: 'indefinido';
            echo '<span class="cac-status-pill cac-status-' . esc_attr($status) . '">' . esc_html( ucfirst($status) ) . '</span>';
            if ( get_post_meta( $post_id, CAC_META_NEEDS_UPDATE, true ) ) echo ' <span title="Desatualizado">📅</span>';
        }
    }
}

Content_Audit_Cleanup::instance();

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'cac_daily_scan' );
});
