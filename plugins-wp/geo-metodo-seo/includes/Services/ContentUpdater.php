<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\EEAT\EEATEngine;
use GeoMetodoSEO\SEO\RankMathIntegration;
use GeoMetodoSEO\Config\ConfigManager;

/**
 * Reescrita automática de posts antigos gerados pelo GEO Método SEO.
 *
 * Lógica:
 *  - Encontra posts com meta _geo_keyword publicados há mais de $days dias
 *  - Exclui posts reescritos nos últimos $days dias (_geo_rewritten_at)
 *  - Reescreve até $per_day posts por execução (evita sobrecarga de API)
 *  - Salva data da reescrita em _geo_rewritten_at
 *
 * Agendamento via WP Cron (diário) registrado no plugin principal.
 */
class ContentUpdater {

    private AIManager $ai;
    private string    $provider;
    private int       $days_threshold;
    private int       $per_day;

    public function __construct() {
        $this->ai             = new AIManager();
        $this->provider       = ProviderResolver::for('content_refresher');
        $this->days_threshold = max(30, (int) get_option( 'geo_rewrite_days', 120 ));
        $this->per_day        = max(1, min(20, (int) get_option( 'geo_rewrite_per_day', 3 )));
    }

    /**
     * Ponto de entrada chamado pelo WP Cron.
     * Verifica se a reescrita automática está habilitada antes de executar.
     */
    public function update_old_posts(): void {
        if ( ! (bool) get_option( 'geo_auto_rewrite', 0 ) ) {
            return;
        }

        $posts = $this->find_posts_to_rewrite();

        foreach ( $posts as $post ) {
            $this->rewrite_post( $post );
        }
    }

    /**
     * Retorna posts candidatos à reescrita.
     *
     * @return \WP_Post[]
     */
    public function find_posts_to_rewrite(): array {
        $cutoff_date    = date( 'Y-m-d H:i:s', strtotime( '-' . $this->days_threshold . ' days' ) );
        $rewrite_cutoff = date( 'Y-m-d H:i:s', strtotime( '-' . $this->days_threshold . ' days' ) );

        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $this->per_day,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'AND',
                // Não reescrito recentemente. Agora detecta qualquer post antigo publicado,
                // mesmo quando ele não possui _geo_keyword.
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_geo_rewritten_at',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_geo_rewritten_at',
                        'value'   => $rewrite_cutoff,
                        'compare' => '<',
                        'type'    => 'DATETIME',
                    ],
                ],
            ],
            'date_query' => [
                [
                    'before'    => $cutoff_date,
                    'inclusive' => false,
                ],
            ],
        ];

        $query = new \WP_Query( $args );
        return $query->posts ?: [];
    }

    /**
     * Reescreve um único post e atualiza os metadados.
     *
     * @param \WP_Post $post
     */
    public function rewrite_post( \WP_Post $post ): bool {
        $keyword = get_post_meta( $post->ID, '_geo_keyword', true );
        $keyword = $keyword ?: $post->post_title;
        $content = $post->post_content;

        if ( empty( $content ) ) {
            return false;
        }

        $original_thumbnail_id = get_post_thumbnail_id( $post->ID );
        $original_media_blocks = $this->extract_media_blocks( $content );

        // Truncar para não exceder limites de tokens (~8k chars ≈ 2k tokens)
        $truncated_content = mb_substr( wp_strip_all_tags( $content ), 0, 8000 );
        $year              = date( 'Y' );

        $prompt = "Você é um especialista em SEO e redação de conteúdo.\n\n"
                . "Atualize e melhore o artigo abaixo para {$year}.\n"
                . "Mantenha o tema sobre \"{$keyword}\" mas adicione informações mais recentes, "
                . "melhore o SEO e torne o texto mais natural e envolvente.\n"
                . "Mantenha a mesma estrutura (H2s, FAQ, etc.) mas expanda as seções curtas.\n"
                . "Não troque, não invente e não remova as imagens originais; quando houver necessidade, apenas preserve os espaços de mídia.\n"
                . "Retorne o artigo completo em HTML (use <p>, <h2>, <h3>, <ul>, <li>, <strong>).\n"
                . "NÃO inclua o título principal H1 — apenas o corpo do artigo.\n\n"
                . "ARTIGO ATUAL:\n{$truncated_content}";

        // Usar provedor do post original se disponível
        $post_provider = get_post_meta( $post->ID, '_geo_provider', true );
        $provider      = ProviderResolver::for('content_refresher', is_string($post_provider) ? $post_provider : $this->provider);
        $model         = ProviderResolver::modelFor('content_refresher', $provider);

        $response = $this->ai->generateText( $prompt, $provider, $model );

        if ( $response->hasError() ) {
            LogService::log( 'error', 'ContentUpdater: falha ao reescrever post #' . $post->ID . ' — ' . $response->getError() );
            return false;
        }

        $new_content = wp_kses_post( $response->getContent() );
        $new_content = $this->restore_media_blocks( $new_content, $original_media_blocks );

        if ( strlen( $new_content ) < 500 ) {
            LogService::log( 'error', 'ContentUpdater: conteúdo reescrito muito curto para post #' . $post->ID );
            return false;
        }

        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post( [
            'ID'           => $post->ID,
            'post_content' => $new_content,
        ] );

        // Garantir que a imagem destacada original permaneça igual após a reescrita.
        if ( $original_thumbnail_id ) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::set_featured_image( $post->ID, $original_thumbnail_id );
        }

        // Limpar schemas antigos e regenerar com o novo conteúdo
        delete_post_meta( $post->ID, '_geo_faq_raw' );
        delete_post_meta( $post->ID, 'geo_faq_schema' );
        delete_post_meta( $post->ID, 'geo_article_schema' );
        delete_post_meta( $post->ID, 'geo_person_schema' );

        // Reaplicar E-E-A-T: extrai novas FAQs, regenera schemas, refaz box do autor
        $eeat = new \GeoMetodoSEO\EEAT\EEATEngine();
        $eeat->apply( $post->ID );

        // Reaplicar Rank Math / Yoast
        $rm = new \GeoMetodoSEO\SEO\RankMathIntegration();
        $rm->apply( $post->ID, $post->post_title, $keyword, mb_substr( wp_strip_all_tags( $new_content ), 0, 155 ) );

        // Auditor global de mídia também na reescrita: preserva a destacada e completa corpo se a IA removeu imagens.
        if (class_exists('\GeoMetodoSEO\Services\GeoMediaMasterService')) {
            try {
                $media = new \GeoMetodoSEO\Services\GeoMediaMasterService();
                $media->ensure_post_media($post->ID, [
                    'keyword' => $keyword ?: $post->post_title,
                    'title' => $post->post_title,
                    'body_images' => \GeoMetodoSEO\Services\ImageGeneratorService::body_images_count(),
                    'require_featured' => true,
                    'process_now' => false,
                    'category' => 'rewrite',
                    'niche' => get_option('sara_niche', 'Tecnologia'),
                ]);
            } catch (\Throwable $e) {
                LogService::log('warning', 'ContentUpdater: GeoMediaMasterService falhou — ' . $e->getMessage(), $post->ID);
            }
        }

        update_post_meta( $post->ID, '_geo_rewritten_at', current_time( 'mysql' ) );
        update_post_meta( $post->ID, '_geo_rewrite_count', (int) get_post_meta( $post->ID, '_geo_rewrite_count', true ) + 1 );

        LogService::log(
            'success',
            'ContentUpdater: post #' . $post->ID . ' reescrito + schemas regenerados (keyword: ' . $keyword . ')',
            $post->ID
        );

        return true;
    }


    /**
     * Extrai imagens/blocos de mídia originais para preservar durante a reescrita.
     */
    private function extract_media_blocks( string $content ): array {
        $blocks = [];

        if ( preg_match_all('/<!--\s*wp:(image|gallery|media-text)[\s\S]*?<!--\s*\/wp:\1\s*-->/i', $content, $m) ) {
            foreach ( $m[0] as $block ) {
                $blocks[] = $block;
            }
        }

        if ( preg_match_all('/<figure[^>]*>\s*<img[\s\S]*?<\/figure>|<img\s[^>]*>/i', $content, $m2) ) {
            foreach ( $m2[0] as $img ) {
                if ( ! in_array( $img, $blocks, true ) ) $blocks[] = $img;
            }
        }

        return array_values( array_unique( $blocks ) );
    }

    /**
     * Reinsere as imagens originais no conteúdo reescrito sem mudar URLs/IDs.
     */
    private function restore_media_blocks( string $new_content, array $media_blocks ): string {
        if ( empty( $media_blocks ) ) return $new_content;

        // Se a IA já preservou imagens, não duplica.
        if ( preg_match('/<img\s/i', $new_content) || strpos($new_content, '<!-- wp:image') !== false ) {
            return $new_content;
        }

        $parts = preg_split('/(<h2[^>]*>.*?<\/h2>)/is', $new_content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ( ! is_array($parts) || count($parts) < 3 ) {
            return implode("\n\n", $media_blocks) . "\n\n" . $new_content;
        }

        $out = '';
        $media_index = 0;
        foreach ( $parts as $idx => $part ) {
            $out .= $part;
            if ( preg_match('/^<h2/i', $part) && isset($media_blocks[$media_index]) ) {
                $out .= "\n\n" . $media_blocks[$media_index] . "\n\n";
                $media_index++;
            }
        }

        while ( isset($media_blocks[$media_index]) ) {
            $out .= "\n\n" . $media_blocks[$media_index];
            $media_index++;
        }

        return $out;
    }

    // ─── Helpers para o Dashboard ────────────────────────────────────────────

    /**
     * Total de posts reescritos pelo ContentUpdater.
     */
    public static function count_rewritten(): int {
        // FIX performance: posts_per_page=1 é suficiente quando só usamos found_posts.
        $query = new \WP_Query( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => '_geo_rewritten_at', 'compare' => 'EXISTS' ] ],
        ] );
        return (int) $query->found_posts;
    }

    /**
     * Posts que serão reescritos nos próximos 7 dias.
     * (Publicados entre (days - 7) e days dias atrás, sem reescrita recente.)
     */
    public static function count_upcoming( int $days = 120 ): int {
        $from = date( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );
        $to   = date( 'Y-m-d H:i:s', strtotime( '-' . ( $days - 7 ) . ' days' ) );

        $query = new \WP_Query( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [ 'relation' => 'OR',
                  [ 'key' => '_geo_rewritten_at', 'compare' => 'NOT EXISTS' ],
                  [ 'key' => '_geo_rewritten_at', 'value' => $from, 'compare' => '<', 'type' => 'DATETIME' ],
                ],
            ],
            'date_query' => [
                [ 'after' => $to, 'before' => $from, 'inclusive' => true ],
            ],
        ] );
        return (int) $query->found_posts;
    }
}
