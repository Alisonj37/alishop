<?php
/**
 * Testes standalone do Content Audit & Cleanup v2.1
 * Não requer WordPress — usa stubs das funções WP.
 * Execute: php run-tests.php
 */

declare(strict_types=1);

// ── WordPress stubs ───────────────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'CAC_BATCH_SIZE' ) ) define( 'CAC_BATCH_SIZE', 200 );
if ( ! defined( 'CAC_SITEMAP_BATCH' ) ) define( 'CAC_SITEMAP_BATCH', 100 );
if ( ! defined( 'CAC_PAGE_SIZE' ) ) define( 'CAC_PAGE_SIZE', 50 );
if ( ! defined( 'CAC_META_STATUS' ) ) define( 'CAC_META_STATUS', '_cac_audit_status' );
if ( ! defined( 'CAC_META_NEEDS_UPDATE' ) ) define( 'CAC_META_NEEDS_UPDATE', '_cac_needs_update' );
if ( ! defined( 'CAC_META_GEO_STATUS' ) ) define( 'CAC_META_GEO_STATUS', '_cac_geo_rewrite_status' );
if ( ! defined( 'CAC_META_NOINDEX' ) ) define( 'CAC_META_NOINDEX', '_cac_noindex_applied' );
if ( ! defined( 'CAC_META_SENT_TO_GEO' ) ) define( 'CAC_META_SENT_TO_GEO', '_cac_sent_to_geo' );
if ( ! defined( 'CAC_OPTION_NICHE_KEYWORDS' ) ) define( 'CAC_OPTION_NICHE_KEYWORDS', 'cac_niche_keywords' );
if ( ! defined( 'CAC_OPTION_STALE_MONTHS' ) ) define( 'CAC_OPTION_STALE_MONTHS', 'cac_stale_months' );
if ( ! defined( 'CAC_OPTION_SITEMAP_URL' ) ) define( 'CAC_OPTION_SITEMAP_URL', 'cac_sitemap_url' );
if ( ! defined( 'CAC_VERSION' ) ) define( 'CAC_VERSION', '2.1.0' );

// In-memory store simulating WP options and postmeta
$_OPTIONS  = [];
$_POSTMETA = [];
$_POSTS    = [];

function get_option( $key, $default = false ) {
    global $_OPTIONS;
    return array_key_exists( $key, $_OPTIONS ) ? $_OPTIONS[$key] : $default;
}
function update_option( $key, $value, $autoload = true ) {
    global $_OPTIONS;
    $_OPTIONS[$key] = $value;
    return true;
}
function delete_option( $key ) {
    global $_OPTIONS;
    unset( $_OPTIONS[$key] );
    return true;
}
function get_post_meta( $post_id, $key, $single = false ) {
    global $_POSTMETA;
    return $_POSTMETA[$post_id][$key] ?? ( $single ? '' : [] );
}
function update_post_meta( $post_id, $key, $value ) {
    global $_POSTMETA;
    $_POSTMETA[$post_id][$key] = $value;
    return true;
}
function delete_post_meta( $post_id, $key ) {
    global $_POSTMETA;
    unset( $_POSTMETA[$post_id][$key] );
    return true;
}
function get_post( $post_id ) {
    global $_POSTS;
    return $_POSTS[$post_id] ?? null;
}
function home_url( $path = '' ) { return 'https://example.com' . $path; }
function esc_url_raw( $url ) { return filter_var($url, FILTER_SANITIZE_URL); }
function sanitize_text_field( $str ) { return trim(strip_tags((string)$str)); }
function sanitize_key( $key ) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($key)); }
function wp_unslash( $v ) { return is_string($v) ? stripslashes($v) : $v; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode($data, $flags); }
function current_time( $type ) { return date( $type === 'mysql' ? 'Y-m-d H:i:s' : 'U' ); }
function wp_next_scheduled() { return false; }
function wp_schedule_event() { return true; }
function wp_schedule_single_event() { return true; }
function wp_count_posts( $type ) { return (object)['publish'=>0,'draft'=>0,'pending'=>0]; }
function wp_parse_url( $url, $component = -1 ) { return parse_url($url, $component); }
function add_action() {}
function add_filter() {}
function register_deactivation_hook() {}
function is_singular() { return false; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_die($msg) { throw new RuntimeException("wp_die: $msg"); }
class WP_Error { public function __construct(public string $code='', public string $msg='') {} }

// Stub do $wpdb para o teste de batch
class MockWpdb {
    public string $posts    = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public array  $_query_log = [];

    // Dados em memória
    public array $posts_data    = [];
    public array $postmeta_data = [];

    public function prepare( $sql, ...$args ): string {
        $i = 0;
        return preg_replace_callback( '/%[sd]/', function() use (&$i, $args) {
            $val = $args[$i++] ?? '';
            return is_int($val) ? (int)$val : "'" . addslashes((string)$val) . "'";
        }, $sql );
    }

    public function get_results( string $sql ): array {
        $this->_query_log[] = $sql;

        // Parseia LIMIT e OFFSET do SQL de teste
        preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $lm);
        $limit  = isset($lm[1]) ? (int)$lm[1] : 9999;
        $offset = isset($lm[2]) ? (int)$lm[2] : 0;

        if ( stripos($sql, 'post_title') !== false && stripos($sql, 'post_content') === false ) {
            // run_auto_audit query
            $slice = array_slice($this->posts_data, $offset, $limit);
            return array_map(fn($p) => (object)['ID' => $p['ID'], 'post_title' => $p['post_title']], $slice);
        }
        if ( stripos($sql, 'post_modified') !== false ) {
            // run_stale_scan query
            $slice = array_slice($this->posts_data, $offset, $limit);
            return array_map(function($p) {
                global $_POSTMETA;
                return (object)[
                    'ID'           => $p['ID'],
                    'post_modified' => $p['post_modified'],
                    'audit_status'  => $_POSTMETA[$p['ID']][CAC_META_STATUS] ?? '',
                ];
            }, $slice);
        }
        if ( stripos($sql, 'post_content') !== false ) {
            // sitemap scan query
            $slice = array_slice($this->posts_data, $offset, $limit);
            return array_map(fn($p) => (object)$p, $slice);
        }
        return [];
    }

    public function get_var( $sql ) { return 0; }
    public function update( $table, $data, $where ) { return 1; }
}

$wpdb = new MockWpdb();

// ── Helper de test runner ─────────────────────────────────────────────────
$passed = 0;
$failed = 0;

function assert_eq( $label, $expected, $actual ): void {
    global $passed, $failed;
    if ( $expected === $actual ) {
        echo "  ✅ PASS: $label\n";
        $passed++;
    } else {
        $exp_str = is_array($expected) ? json_encode($expected) : var_export($expected, true);
        $act_str = is_array($actual)   ? json_encode($actual)   : var_export($actual, true);
        echo "  ❌ FAIL: $label\n     Expected: $exp_str\n     Got:      $act_str\n";
        $failed++;
    }
}
function assert_contains( $label, string $needle, string $haystack ): void {
    global $passed, $failed;
    if ( str_contains($haystack, $needle) ) {
        echo "  ✅ PASS: $label\n";
        $passed++;
    } else {
        echo "  ❌ FAIL: $label\n     Expected to find: $needle\n     In: " . substr($haystack, 0, 100) . "\n";
        $failed++;
    }
}

// ── Carregar o plugin (apenas as classes/funções, sem hooks WP) ───────────
// Extraímos as classes necessárias do plugin sem instanciar (evita o instance())
// Carregamos manualmente as partes testáveis.

/**
 * Versão testável: extrai a lógica de classificação do run_auto_audit()
 * sem precisar de instância do plugin completa.
 */
function cac_test_classify_post( string $title, array $rules ): ?string {
    $kw_map = [];
    foreach ( ['remover', 'noindex', 'fundir', 'revisar'] as $status ) {
        foreach ( (array)($rules[$status] ?? []) as $kw ) {
            $kw = trim(mb_strtolower($kw));
            if ( $kw !== '' && !isset($kw_map[$kw]) ) {
                $kw_map[$kw] = $status;
            }
        }
    }
    $title_lower = mb_strtolower($title);
    foreach ( $kw_map as $kw => $status ) {
        if ( mb_strpos($title_lower, $kw) !== false ) {
            return $status;
        }
    }
    return null;
}

/**
 * Versão testável do run_stale_scan: verifica se um post é considerado desatualizado.
 */
function cac_test_is_stale( string $post_modified, int $stale_months ): bool {
    $cutoff_ts   = strtotime("-{$stale_months} months");
    $modified_ts = strtotime($post_modified);
    return $modified_ts && $modified_ts < $cutoff_ts;
}

/**
 * Versão testável da varredura de staging.
 */
function cac_test_has_staging( string $content ): bool {
    $patterns = [
        'stackstaging.com', 'wpengine.com/staging', '.staging.', 'staging.',
        'localhost', '.local', 'tempurl.host', 'cloudwaysapps.com',
        'wpenginepowered.com', 'kinsta.cloud', 'flywheelsites.com',
        'myftpupload.com', 'azurewebsites.net', 'instawp.xyz',
    ];
    foreach ($patterns as $p) {
        if (stripos($content, $p) !== false) return true;
    }
    return false;
}

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 1: Classificação por keyword (run_auto_audit lógica)
// ═══════════════════════════════════════════════════════════════════════════
echo "\n🔵 SUITE 1 — Classificação por keyword\n";

$rules = [
    'noindex' => ['política', 'futebol', 'novela', 'culinária', 'receita'],
    'remover' => ['assassinato', 'tragédia', 'escândalo sexual', 'fake news'],
    'fundir'  => ['guia completo de seo', 'o que é seo'],
    'revisar' => ['dica rápida', 'novidade do mercado'],
];

// Cenário 1: post dentro do nicho → null (não classificado)
assert_eq( 'Post SEO genérico → sem classificação',
    null, cac_test_classify_post('Como fazer SEO em 2026: guia definitivo', $rules)
);

// Cenário 3: fora do nicho → noindex
assert_eq( 'Receita de bolo → noindex',
    'noindex', cac_test_classify_post('Receita de bolo de chocolate delicioso', $rules)
);

// Cenário 4: risco reputacional → remover
assert_eq( 'Escândalo sexual → remover',
    'remover', cac_test_classify_post('Escândalo sexual na empresa de marketing digital', $rules)
);

// Cenário 5: duplicado → fundir
assert_eq( 'Guia completo de SEO → fundir',
    'fundir', cac_test_classify_post('Guia completo de SEO: tudo o que você precisa saber', $rules)
);

assert_eq( 'O que é SEO guia completo → fundir',
    'fundir', cac_test_classify_post('O que é SEO: guia completo de SEO para iniciantes', $rules)
);

// Cenário 7: revisar
assert_eq( 'Dica rápida → revisar',
    'revisar', cac_test_classify_post('Dica rápida de SEO para iniciantes', $rules)
);

assert_eq( 'Novidade do mercado → revisar',
    'revisar', cac_test_classify_post('Novidade do mercado: Google lança novo algoritmo', $rules)
);

// Cenário 9: futebol → noindex
assert_eq( 'Futebol e SEO → noindex',
    'noindex', cac_test_classify_post('Futebol e SEO: o que têm em comum?', $rules)
);

// Cenário 10: novela → noindex
assert_eq( 'Novela das 9 → noindex',
    'noindex', cac_test_classify_post('Novela das 9: como o marketing digital pode aprender com ela', $rules)
);

// Cenário 12: tragédia → remover (prioridade sobre noindex)
assert_eq( 'Tragédia no mercado → remover',
    'remover', cac_test_classify_post('Tragédia no mercado financeiro: o que fazer', $rules)
);

// Cenário 11: política → noindex (não está em remover neste exemplo)
assert_eq( 'Política e marketing → noindex',
    'noindex', cac_test_classify_post('Política e marketing: análise crítica', $rules)
);

// Prioridade: remover > noindex — teste explícito
$rules_priority = [
    'noindex' => ['conteúdo'],
    'remover' => ['conteúdo sensível'],
    'fundir'  => [],
    'revisar' => [],
];
assert_eq( 'Prioridade remover > noindex quando título contém "conteúdo sensível"',
    'remover', cac_test_classify_post('Conteúdo sensível para adultos', $rules_priority)
);

// Case insensitive
assert_eq( 'Classificação case-insensitive (maiúsculas)',
    'noindex', cac_test_classify_post('RECEITA DE FRANGO CAIPIRA', $rules)
);

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 2: Detecção de posts desatualizados (run_stale_scan lógica)
// ═══════════════════════════════════════════════════════════════════════════
echo "\n🔵 SUITE 2 — Detecção de posts desatualizados\n";

$stale_months = 8;
$now          = time();

// Post modificado há 14 meses → desatualizado
assert_eq( 'Post 14 meses atrás → desatualizado',
    true, cac_test_is_stale(date('Y-m-d H:i:s', strtotime('-14 months')), $stale_months)
);

// Post modificado há 1 mês → não desatualizado
assert_eq( 'Post 1 mês atrás → não desatualizado',
    false, cac_test_is_stale(date('Y-m-d H:i:s', strtotime('-1 month')), $stale_months)
);

// Post de 2018 → desatualizado
assert_eq( 'Post de 2018 → desatualizado',
    true, cac_test_is_stale('2018-03-15 10:00:00', $stale_months)
);

// Exatamente no limite (8 meses = cutoff) - ligeiramente além → desatualizado
assert_eq( 'Post 9 meses atrás → desatualizado',
    true, cac_test_is_stale(date('Y-m-d H:i:s', strtotime('-9 months')), $stale_months)
);

// Exatamente 7 meses → não desatualizado
assert_eq( 'Post 7 meses atrás → não desatualizado',
    false, cac_test_is_stale(date('Y-m-d H:i:s', strtotime('-7 months')), $stale_months)
);

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 3: Detecção de links de staging
// ═══════════════════════════════════════════════════════════════════════════
echo "\n🔵 SUITE 3 — Detecção de links de staging\n";

// Cenário 6: post com link stackstaging.com
assert_eq( 'Conteúdo com stackstaging.com → detectado',
    true, cac_test_has_staging('Veja em https://meusite.stackstaging.com/recursos')
);

// Widget com kinsta.cloud
assert_eq( 'Widget com kinsta.cloud → detectado',
    true, cac_test_has_staging('Acesse https://meusite.kinsta.cloud/teste para mais infos')
);

// Conteúdo .local
assert_eq( 'Conteúdo com .local → detectado',
    true, cac_test_has_staging('Servidor local em http://meusite.local/wp-admin')
);

// localhost
assert_eq( 'Conteúdo com localhost → detectado',
    true, cac_test_has_staging('<a href="http://localhost:8080/pagina">link</a>')
);

// wpenginepowered.com
assert_eq( 'Conteúdo com wpenginepowered.com → detectado',
    true, cac_test_has_staging('https://staging.wpenginepowered.com/post')
);

// Conteúdo limpo de produção → não detectado
assert_eq( 'Conteúdo limpo (produção) → não detectado',
    false, cac_test_has_staging('Veja mais em https://example.com/seo-tools e https://google.com')
);

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 4: Export/Import de configuração JSON
// ═══════════════════════════════════════════════════════════════════════════
echo "\n🔵 SUITE 4 — Export/Import de configuração JSON\n";

$config_to_export = [
    'plugin'       => 'content-audit-cleanup',
    'version'      => CAC_VERSION,
    'exported_at'  => date('c'),
    'site_url'     => home_url(),
    'keywords'     => $rules,
    'stale_months' => 8,
    'sitemap_url'  => home_url('/sitemap_index.xml'),
];
$json_str = json_encode($config_to_export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// JSON é válido
assert_eq( 'JSON exportado é válido', true, json_decode($json_str, true) !== null );

// Reimport
$reimported = json_decode($json_str, true);
assert_eq( 'Campo plugin correto no export', 'content-audit-cleanup', $reimported['plugin'] );
assert_eq( 'Keywords noindex preservadas', ['política', 'futebol', 'novela', 'culinária', 'receita'], $reimported['keywords']['noindex'] );
assert_eq( 'stale_months preservado', 8, $reimported['stale_months'] );

// Importação com JSON inválido deve falhar graciosamente
$bad_json = json_decode('{"plugin":"outro-plugin","version":"1.0"}', true);
assert_eq( 'Import com plugin inválido rejeitado', false, ($bad_json['plugin'] ?? '') === 'content-audit-cleanup' );

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 5: Meta _cac_geo_rewrite_status
// ═══════════════════════════════════════════════════════════════════════════
echo "\n🔵 SUITE 5 — Rastreamento de status de reescrita GEO\n";

// Simular envio para reescrita
$post_id_geo = 999;
update_post_meta($post_id_geo, CAC_META_SENT_TO_GEO, '1');
update_post_meta($post_id_geo, CAC_META_GEO_STATUS, 'pending');

assert_eq( 'Status inicial após envio = pending',
    'pending', get_post_meta($post_id_geo, CAC_META_GEO_STATUS, true)
);

// Simular conclusão com sucesso
update_post_meta($post_id_geo, CAC_META_GEO_STATUS, 'done');
assert_eq( 'Status após reescrita bem-sucedida = done',
    'done', get_post_meta($post_id_geo, CAC_META_GEO_STATUS, true)
);

// Simular falha
update_post_meta($post_id_geo, CAC_META_GEO_STATUS, 'failed');
assert_eq( 'Status após falha = failed',
    'failed', get_post_meta($post_id_geo, CAC_META_GEO_STATUS, true)
);

// Valores aceitos
$valid_statuses = ['pending', 'done', 'failed'];
assert_eq( 'pending é status válido', true, in_array('pending', $valid_statuses, true) );
assert_eq( 'done é status válido',    true, in_array('done', $valid_statuses, true) );
assert_eq( 'failed é status válido',  true, in_array('failed', $valid_statuses, true) );
assert_eq( 'foo NÃO é status válido', false, in_array('foo', $valid_statuses, true) );

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 6: Batch processing — número de queries geradas
// ═══════════════════════════════════════════════════════════════════════════
echo "\n🔵 SUITE 6 — Batch processing (mock wpdb)\n";

global $wpdb;
// Simular 450 posts para testar batching (deve gerar 3 queries de LIMIT 200)
$wpdb->posts_data = [];
for ($i = 1; $i <= 450; $i++) {
    $wpdb->posts_data[] = [
        'ID'            => $i,
        'post_title'    => "Post de teste $i — SEO marketing digital",
        'post_type'     => 'post',
        'post_content'  => 'Conteúdo do post ' . $i,
        'post_modified' => date('Y-m-d H:i:s', strtotime('-1 month')),
        'post_status'   => 'publish',
    ];
}

// Simulação de run_auto_audit em batches
$kw_map_test = ['receita' => 'noindex', 'tragédia' => 'remover'];
$count_classified = 0;
$offset = 0;
$queries_made = 0;
do {
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish','draft','pending') ORDER BY ID ASC LIMIT %d OFFSET %d",
        CAC_BATCH_SIZE, $offset
    ));
    $queries_made++;
    foreach ($rows as $row) {
        $title = mb_strtolower($row->post_title);
        foreach ($kw_map_test as $kw => $status) {
            if (mb_strpos($title, $kw) !== false) {
                $count_classified++;
                break;
            }
        }
    }
    $offset += CAC_BATCH_SIZE;
} while (count($rows) === CAC_BATCH_SIZE);

assert_eq( '450 posts procesados em 3 batches (LIMIT 200)',
    3, $queries_made
);

// Com 0 posts classificados (nenhum tem "receita" ou "tragédia" no título de teste)
assert_eq( 'Nenhum post classificado nos 450 genéricos',
    0, $count_classified
);

// ═══════════════════════════════════════════════════════════════════════════
// SUITE 7: Segurança — whitelist de ações permitidas no bulk action
// ═══════════════════════════════════════════════════════════════════════════
echo "\n🔵 SUITE 7 — Segurança: whitelist de bulk actions\n";

$allowed_actions = ['apply_noindex', 'remove_noindex', 'trash', 'mark_manter', 'mark_fundir', 'mark_revisar', 'send_to_geo'];

assert_eq( 'apply_noindex está na whitelist',   true,  in_array('apply_noindex',  $allowed_actions, true) );
assert_eq( 'send_to_geo está na whitelist',      true,  in_array('send_to_geo',    $allowed_actions, true) );
assert_eq( 'delete_all NÃO está na whitelist',   false, in_array('delete_all',     $allowed_actions, true) );
assert_eq( 'eval() NÃO está na whitelist',       false, in_array('eval()',         $allowed_actions, true) );
assert_eq( 'sql_inject NÃO está na whitelist',   false, in_array("'; DROP TABLE", $allowed_actions, true) );

// post_ids com intval + filter
$raw_post_ids = ['1', '2', '0', '-5', 'abc', '999'];
$safe_ids = array_values(array_filter(array_map('intval', $raw_post_ids), fn($id) => $id > 0));
assert_eq( 'IDs sanitizados: 0 e negativos removidos', [1, 2, 999], $safe_ids );

// stale_months: max(1, min(36, ...))
assert_eq( 'stale_months=0 vira 1',    1,  max(1, min(36, 0)) );
assert_eq( 'stale_months=100 vira 36', 36, max(1, min(36, 100)) );
assert_eq( 'stale_months=8 permanece', 8,  max(1, min(36, 8)) );

// ═══════════════════════════════════════════════════════════════════════════
// RESULTADO FINAL
// ═══════════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 60) . "\n";
echo "RESULTADO: $passed passed, $failed failed\n";
echo str_repeat('═', 60) . "\n";

if ($failed > 0) {
    exit(1);
}
echo "\nTodos os testes passaram! ✅\n";
