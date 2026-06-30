<?php
/**
 * Fixtures de teste para o Content Audit & Cleanup
 * Cobre todos os cenários do requisito:
 *  1. Post dentro do nicho, atualizado → deve ficar sem classificação (manter)
 *  2. Post dentro do nicho, desatualizado → deve virar 'precisa_atualizar'
 *  3. Post fora do nicho → noindex
 *  4. Post com palavra de risco reputacional → remover
 *  5. Post duplicado / conteúdo similar → fundir
 *  6. Post/widget com link de staging → detectado pelo scan de sitemap
 *  7-15. Variações adicionais para cobertura completa
 *
 * Este arquivo é executado via WP-CLI: wp eval 'require "/setup/fixtures.php";'
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Não pode ser chamado diretamente.' );
}

echo "Criando fixtures de teste...\n";

// ── Configurar regras de nicho (simula site de Marketing/SEO) ─────────────
update_option( 'cac_niche_keywords', array(
    'noindex' => array( 'política', 'futebol', 'novela', 'culinária', 'receita' ),
    'remover' => array( 'assassinato', 'tragédia', 'escândalo sexual', 'fake news' ),
    'fundir'  => array( 'guia completo de seo', 'o que é seo' ),
    'revisar' => array( 'dica rápida', 'novidade do mercado' ),
) );
update_option( 'cac_stale_months', 8 );
update_option( 'cac_sitemap_url', 'http://localhost:8080/sitemap_index.xml' );

echo "✅ Regras de nicho configuradas (Marketing/SEO)\n";

// ── Função auxiliar para criar post ──────────────────────────────────────
function cac_create_post( array $args, string $description = '' ): int {
    $defaults = array(
        'post_type'   => 'post',
        'post_status' => 'publish',
        'post_author' => 1,
    );
    $post_id = wp_insert_post( array_merge( $defaults, $args ) );
    if ( is_wp_error( $post_id ) ) {
        echo "❌ Erro ao criar post '{$args['post_title']}': " . $post_id->get_error_message() . "\n";
        return 0;
    }
    echo "✅ Post #{$post_id}: {$args['post_title']}" . ( $description ? " [{$description}]" : '' ) . "\n";
    return $post_id;
}

// ── Cenário 1: Post dentro do nicho, atualizado (deve ficar 'manter') ─────
$p1 = cac_create_post( array(
    'post_title'    => 'Como fazer SEO em 2026: guia definitivo',
    'post_content'  => '<p>Este artigo explica as melhores técnicas de SEO para 2026. Inclui estratégias de link building, otimização on-page e conteúdo semântico.</p>',
    'post_date'     => date( 'Y-m-d H:i:s', strtotime( '-1 month' ) ),
    'post_modified' => date( 'Y-m-d H:i:s', strtotime( '-1 month' ) ),
), 'nicho=SEO, atualizado' );

// ── Cenário 2: Post dentro do nicho, desatualizado (deve virar 'precisa_atualizar') ─
$p2 = cac_create_post( array(
    'post_title'    => 'Ferramentas de SEO gratuitas que funcionam',
    'post_content'  => '<p>Lista das melhores ferramentas gratuitas para SEO em 2022.</p>',
    'post_date'     => date( 'Y-m-d H:i:s', strtotime( '-14 months' ) ),
    'post_modified' => date( 'Y-m-d H:i:s', strtotime( '-14 months' ) ),
), 'nicho=SEO, desatualizado 14 meses' );
// Forçar post_modified via SQL (wp_insert_post reseta o modified)
global $wpdb;
$wpdb->update( $wpdb->posts, array(
    'post_modified'     => date( 'Y-m-d H:i:s', strtotime( '-14 months' ) ),
    'post_modified_gmt' => date( 'Y-m-d H:i:s', strtotime( '-14 months' ) ),
), array( 'ID' => $p2 ) );

// ── Cenário 3: Post fora do nicho (deve cair em 'noindex') ────────────────
$p3 = cac_create_post( array(
    'post_title'   => 'Receita de bolo de chocolate delicioso',
    'post_content' => '<p>Aprenda a fazer um bolo de chocolate com esta receita simples e rápida.</p>',
), 'fora do nicho → noindex' );

// ── Cenário 4: Post com palavra de risco reputacional (deve cair em 'remover') ─
$p4 = cac_create_post( array(
    'post_title'   => 'Escândalo sexual na empresa de marketing digital',
    'post_content' => '<p>Um caso chocante revelado hoje abalou o mercado.</p>',
), 'risco reputacional → remover' );

// ── Cenário 5: Post duplicado / título muito similar (deve cair em 'fundir') ─
$p5a = cac_create_post( array(
    'post_title'   => 'O que é SEO: guia completo de SEO para iniciantes',
    'post_content' => '<p>SEO significa Search Engine Optimization. Este guia completo de SEO cobre todos os fundamentos.</p>',
), 'duplicado A → fundir' );
$p5b = cac_create_post( array(
    'post_title'   => 'Guia completo de SEO: tudo o que você precisa saber',
    'post_content' => '<p>Neste guia completo de SEO você vai aprender otimização para mecanismos de busca.</p>',
), 'duplicado B → fundir' );

// ── Cenário 6: Post com link de staging no conteúdo ──────────────────────
$p6 = cac_create_post( array(
    'post_title'   => 'Recursos de SEO avançado',
    'post_content' => '<p>Confira nossa lista de recursos em <a href="https://meusite.stackstaging.com/recursos-seo">https://meusite.stackstaging.com/recursos-seo</a> para mais detalhes.</p>',
), 'link de staging no conteúdo' );

// ── Cenário 7: Post para revisar ─────────────────────────────────────────
$p7 = cac_create_post( array(
    'post_title'   => 'Dica rápida de SEO para iniciantes',
    'post_content' => '<p>Uma dica rápida: use a keyword no título.</p>',
), 'categoria revisar' );

// ── Cenário 8: Post em draft ──────────────────────────────────────────────
$p8 = cac_create_post( array(
    'post_title'   => 'Rascunho: análise de backlinks',
    'post_content' => '<p>Artigo em rascunho sobre análise de backlinks.</p>',
    'post_status'  => 'draft',
), 'draft — deve aparecer na auditoria' );

// ── Cenário 9: Post com futebol (noindex) ────────────────────────────────
$p9 = cac_create_post( array(
    'post_title'   => 'Futebol e SEO: o que têm em comum?',
    'post_content' => '<p>Analogia entre futebol e estratégia de SEO.</p>',
), 'palavra noindex no título' );

// ── Cenário 10: Post com novela (noindex) ─────────────────────────────────
$p10 = cac_create_post( array(
    'post_title'   => 'Novela das 9: como o marketing digital pode aprender com ela',
    'post_content' => '<p>Reflexão sobre marketing e novelas.</p>',
), 'palavra noindex no título' );

// ── Cenário 11: Post com política (noindex+remover via prioridade) ─────────
$p11 = cac_create_post( array(
    'post_title'   => 'Política e marketing: análise crítica',
    'post_content' => '<p>Análise das estratégias de marketing político.</p>',
), 'noindex pelo keyword política' );

// ── Cenário 12: Post de tragédia (remover) ────────────────────────────────
$p12 = cac_create_post( array(
    'post_title'   => 'Tragédia no mercado financeiro: o que fazer',
    'post_content' => '<p>Análise da crise econômica recente.</p>',
), 'remover pelo keyword tragédia' );

// ── Cenário 13: Novidade de mercado (revisar) ─────────────────────────────
$p13 = cac_create_post( array(
    'post_title'   => 'Novidade do mercado: Google lança novo algoritmo',
    'post_content' => '<p>O Google anunciou hoje um novo algoritmo de ranqueamento.</p>',
), 'revisar pelo keyword novidade do mercado' );

// ── Cenário 14: Post muito antigo, dentro do nicho ────────────────────────
$p14 = cac_create_post( array(
    'post_title'   => 'Técnicas de SEO de 2018 que ainda funcionam',
    'post_content' => '<p>Análise de técnicas de SEO antigas que resistem ao tempo.</p>',
    'post_date'    => '2018-03-15 10:00:00',
), 'antigo 2018 → precisa_atualizar' );
$wpdb->update( $wpdb->posts, array(
    'post_modified'     => '2018-03-15 10:00:00',
    'post_modified_gmt' => '2018-03-15 10:00:00',
), array( 'ID' => $p14 ) );

// ── Cenário 15: Widget de texto com link de staging ───────────────────────
$existing_widgets = get_option( 'widget_text', array() );
$existing_widgets[ '_multiwidget' ] = 1;
$widget_idx = 2;
$existing_widgets[ $widget_idx ] = array(
    'title'  => 'Widget de Staging (Teste)',
    'text'   => 'Acesse nossa versão de testes em: https://meusite.kinsta.cloud/teste para mais informações.',
    'filter' => false,
);
$sidebar_widgets = get_option( 'sidebars_widgets', array() );
if ( ! isset( $sidebar_widgets['sidebar-1'] ) ) {
    $sidebar_widgets['sidebar-1'] = array();
}
$sidebar_widgets['sidebar-1'][] = 'text-' . $widget_idx;
update_option( 'widget_text', $existing_widgets );
update_option( 'sidebars_widgets', $sidebar_widgets );
echo "✅ Widget com link de staging criado (widget_text #{$widget_idx})\n";

// ── Resumo ────────────────────────────────────────────────────────────────
echo "\n=== FIXTURES CRIADAS ===\n";
echo "Total de posts: " . wp_count_posts( 'post' )->publish . " publicados + " . wp_count_posts( 'post' )->draft . " drafts\n";
echo "\nPró-ximo passo:\n";
echo "  1. Acesse http://localhost:8080/wp-admin (admin / admin123)\n";
echo "  2. Vá em Auditoria de Conteúdo → Configurações e confirme as regras\n";
echo "  3. Clique em 'Rodar auditoria automática'\n";
echo "  4. Verifique a classificação de cada post contra a tabela abaixo:\n\n";
echo "  Post  | Título (resumido)                          | Esperado\n";
echo "  ------|---------------------------------------------|-------------------\n";
echo "  #p1   | Como fazer SEO em 2026                     | indefinido (atualizado)\n";
echo "  #p2   | Ferramentas de SEO gratuitas               | precisa_atualizar\n";
echo "  #p3   | Receita de bolo de chocolate               | noindex (receita)\n";
echo "  #p4   | Escândalo sexual na empresa                | remover\n";
echo "  #p5a  | O que é SEO: guia completo                 | fundir\n";
echo "  #p5b  | Guia completo de SEO                       | fundir\n";
echo "  #p6   | Recursos de SEO avançado                   | indefinido + staging detectado\n";
echo "  #p7   | Dica rápida de SEO                         | revisar\n";
echo "  #p8   | Rascunho: análise de backlinks             | indefinido (draft)\n";
echo "  #p9   | Futebol e SEO                              | noindex\n";
echo "  #p10  | Novela das 9: marketing digital            | noindex\n";
echo "  #p11  | Política e marketing                       | noindex\n";
echo "  #p12  | Tragédia no mercado financeiro             | remover\n";
echo "  #p13  | Novidade do mercado: Google                | revisar\n";
echo "  #p14  | Técnicas de SEO de 2018                    | precisa_atualizar\n\n";
echo "  5. Vá em Auditoria → Sitemap e clique em Escanear agora\n";
echo "     Deve detectar: post #{$p6} e widget de texto #2 (kinsta.cloud)\n";
