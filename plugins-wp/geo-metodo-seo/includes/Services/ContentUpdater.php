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

    const PROMPT_VERSION = 'geo_rewrite_v1.0.0';

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

        $year   = date( 'Y' );
        $prompt = $this->build_prompt( [
            'nicho_do_site'           => (string) get_option( 'sara_niche', 'Geral' ),
            'titulo_original'         => $post->post_title,
            'conteudo_original'       => mb_substr( $content, 0, 12000 ),
            'categoria'               => $this->get_post_category( $post->ID ),
            'palavra_chave_principal' => $keyword,
            'tom_de_voz_da_marca'     => (string) get_option( 'geo_brand_voice', 'profissional e direto' ),
            'ano_atual'               => $year,
        ] );

        // Usar provedor do post original se disponível
        $post_provider = get_post_meta( $post->ID, '_geo_provider', true );
        $provider      = ProviderResolver::for('content_refresher', is_string($post_provider) ? $post_provider : $this->provider);
        $model         = ProviderResolver::modelFor('content_refresher', $provider);

        $response = $this->ai->generateText( $prompt, $provider, $model );

        if ( $response->hasError() ) {
            LogService::log( 'error', 'ContentUpdater: falha ao reescrever post #' . $post->ID . ' — ' . $response->getError() );
            $this->append_rewrite_log( $post->ID, [
                'prompt_version'    => self::PROMPT_VERSION,
                'provider'          => $provider,
                'model'             => $model,
                'validation_passed' => false,
                'validation_errors' => [ 'provider_error: ' . $response->getError() ],
                'char_count_before' => strlen( $content ),
                'char_count_after'  => 0,
            ] );
            return false;
        }

        $parsed = $this->parse_rewrite_response( $response->getContent() );
        $errors = $this->validate_response( $parsed );

        if ( ! empty( $errors ) ) {
            LogService::log(
                'error',
                'ContentUpdater: resposta inválida para post #' . $post->ID . ' — ' . implode( ', ', $errors ),
                $post->ID
            );
            $this->append_rewrite_log( $post->ID, [
                'prompt_version'    => self::PROMPT_VERSION,
                'provider'          => $provider,
                'model'             => $model,
                'validation_passed' => false,
                'validation_errors' => $errors,
                'char_count_before' => strlen( $content ),
                'char_count_after'  => 0,
            ] );
            return false;
        }

        // Extrair campos — content_html e title são obrigatórios; demais têm fallback
        $new_content      = wp_kses_post( $parsed['content_html'] );
        $suggested_title  = sanitize_text_field( $parsed['title'] );
        $meta_description = ! empty( $parsed['meta_description'] )
            ? sanitize_text_field( $parsed['meta_description'] )
            : mb_substr( wp_strip_all_tags( $new_content ), 0, 155 );
        $schema_type      = ! empty( $parsed['schema_type'] )
            ? sanitize_text_field( $parsed['schema_type'] )
            : 'Article';

        $new_content = $this->restore_media_blocks( $new_content, $original_media_blocks );

        // Segurança: nunca publicar se imagens foram perdidas após tentativa de restauração.
        $img_before = preg_match_all( '/<img[\s>]/i', $content );
        $img_after  = preg_match_all( '/<img[\s>]/i', $new_content );
        if ( $img_before > 0 && $img_after < $img_before ) {
            $this->append_rewrite_log( $post->ID, [
                'prompt_version'    => self::PROMPT_VERSION,
                'provider'          => $provider,
                'model'             => $model,
                'validation_passed' => false,
                'validation_errors' => [ "image_loss: {$img_before} imgs no original, {$img_after} após restauração" ],
                'char_count_before' => strlen( $content ),
                'char_count_after'  => strlen( $new_content ),
            ] );
            LogService::log(
                'error',
                "ContentUpdater: post #{$post->ID} BLOQUEADO — {$img_before} imagens no original, {$img_after} após restauração. Post mantido intacto.",
                $post->ID
            );
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
        // SEO title vem do JSON; post_title nunca é sobrescrito automaticamente
        $rm->apply( $post->ID, $suggested_title, $keyword, $meta_description );

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

        update_post_meta( $post->ID, '_geo_rewritten_at',     current_time( 'mysql' ) );
        update_post_meta( $post->ID, '_geo_rewrite_count',    (int) get_post_meta( $post->ID, '_geo_rewrite_count', true ) + 1 );
        update_post_meta( $post->ID, '_geo_suggested_title',  $suggested_title );
        update_post_meta( $post->ID, '_geo_meta_description', $meta_description );
        update_post_meta( $post->ID, '_geo_schema_type',      $schema_type );

        $this->append_rewrite_log( $post->ID, [
            'prompt_version'    => self::PROMPT_VERSION,
            'provider'          => $provider,
            'model'             => $model,
            'validation_passed' => true,
            'validation_errors' => [],
            'char_count_before' => strlen( $content ),
            'char_count_after'  => strlen( $new_content ),
            'title_suggested'   => $suggested_title,
            'meta_desc_set'     => ! empty( $parsed['meta_description'] ),
            'faq_count'         => count( is_array( $parsed['faq_items'] ?? null ) ? $parsed['faq_items'] : [] ),
        ] );

        LogService::log(
            'success',
            'ContentUpdater: post #' . $post->ID . ' reescrito (' . self::PROMPT_VERSION . ') + schemas regenerados (keyword: ' . $keyword . ')',
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
     * Lógica aditiva: verifica cada imagem original individualmente pelo src —
     * só reinserindo as que a IA removeu, sem duplicar as que ela preservou.
     */
    private function restore_media_blocks( string $new_content, array $media_blocks ): string {
        if ( empty( $media_blocks ) ) return $new_content;

        // Lógica aditiva: verifica cada bloco pelo seu src único.
        $missing_blocks = [];
        foreach ( $media_blocks as $block ) {
            if ( preg_match('/src=["\']([^"\']+)["\']/', $block, $m) ) {
                // Só reinsere se este src específico está ausente no novo conteúdo.
                if ( strpos( $new_content, $m[1] ) === false ) {
                    $missing_blocks[] = $block;
                }
            } else {
                // Bloco Gutenberg sem src visível: reinsere se o bloco completo está ausente.
                if ( strpos( $new_content, $block ) === false ) {
                    $missing_blocks[] = $block;
                }
            }
        }

        if ( empty( $missing_blocks ) ) return $new_content;

        // Distribui blocos ausentes após H2s ou prepend se não houver H2.
        $parts = preg_split('/(<h2[^>]*>.*?<\/h2>)/is', $new_content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ( ! is_array($parts) || count($parts) < 3 ) {
            return implode("\n\n", $missing_blocks) . "\n\n" . $new_content;
        }

        $out = '';
        $media_index = 0;
        foreach ( $parts as $part ) {
            $out .= $part;
            if ( preg_match('/^<h2/i', $part) && isset($missing_blocks[$media_index]) ) {
                $out .= "\n\n" . $missing_blocks[$media_index] . "\n\n";
                $media_index++;
            }
        }

        while ( isset($missing_blocks[$media_index]) ) {
            $out .= "\n\n" . $missing_blocks[$media_index];
            $media_index++;
        }

        return $out;
    }

    // ─── Prompt v1.0.0 — build, parse, validate, log ────────────────────────

    /**
     * Constrói o prompt modular geo_rewrite_v1.0.0.
     * Nunca usa response_format: json_object — compatível com Groq via parsing manual.
     *
     * @param array{
     *   nicho_do_site: string,
     *   titulo_original: string,
     *   conteudo_original: string,
     *   categoria: string,
     *   palavra_chave_principal: string,
     *   tom_de_voz_da_marca: string,
     *   ano_atual: string
     * } $vars
     */
    private function build_prompt( array $vars ): string {
        $v = [
            'nicho_do_site'           => $vars['nicho_do_site']           ?? 'Geral',
            'titulo_original'         => $vars['titulo_original']         ?? '',
            'conteudo_original'       => $vars['conteudo_original']       ?? '',
            'categoria'               => $vars['categoria']               ?? 'Sem categoria',
            'palavra_chave_principal' => $vars['palavra_chave_principal'] ?? '',
            'tom_de_voz_da_marca'     => $vars['tom_de_voz_da_marca']     ?? 'profissional e direto',
            'ano_atual'               => $vars['ano_atual']               ?? date( 'Y' ),
        ];

        $kw    = $v['palavra_chave_principal'];
        $nicho = $v['nicho_do_site'];

        return <<<PROMPT
VERSÃO DO PROMPT: {$v['ano_atual']}-geo_rewrite_v1.0.0

Você é um especialista sênior em SEO, GEO (Generative Engine Optimization) e E-E-A-T (Experience, Expertise, Authoritativeness, Trustworthiness) com experiência comprovada no nicho de {$nicho}.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CONTEXTO DO ARTIGO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- Nicho do site: {$nicho}
- Categoria do post: {$v['categoria']}
- Título original: {$v['titulo_original']}
- Palavra-chave principal: {$kw}
- Tom de voz da marca: {$v['tom_de_voz_da_marca']}
- Ano atual: {$v['ano_atual']}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ARTIGO ORIGINAL (estrutura HTML preservada):
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$v['conteudo_original']}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
TAREFA
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Reescreva e melhore o artigo acima. Siga TODAS as regras abaixo.

━━ REGRAS SEO ━━
1. A palavra-chave "{$kw}" deve aparecer no primeiro parágrafo e no primeiro H2.
2. Use variações semânticas e LSI keywords — evite repetir a keyword exata mais de 1 vez a cada 200 palavras.
3. Cada seção H2 deve ter pelo menos 150 palavras de conteúdo útil.
4. Mantenha hierarquia clara: H2 → H3 → parágrafos. Não pule níveis.
5. NÃO inclua o título H1 — apenas o corpo do artigo (H2 em diante).
6. Sugira 3 a 5 textos âncora de links internos sobre assuntos relacionados ao nicho "{$nicho}" (apenas texto âncora + assunto, sem URLs).

━━ REGRAS GEO (Generative Engine Optimization) ━━
1. O PRIMEIRO PARÁGRAFO deve responder diretamente à pergunta implícita do título em 2-3 frases curtas — ideal para featured snippet e AI Overview.
2. Comece cada seção H2 com 1-2 frases objetivas que resumam o ponto central.
3. Apresente definições claras na primeira aparição de cada termo técnico.
4. Prefira listas numeradas para sequências e bullets para comparações.

━━ REGRAS AEO (Answer Engine Optimization) ━━
1. Crie uma seção FAQ com 4 a 6 perguntas que usuários realmente pesquisam sobre "{$kw}".
2. Formule as perguntas como busca real: "Como...", "O que é...", "Qual a diferença entre...", "Vale a pena...".
3. Cada resposta do FAQ deve ter entre 60 e 120 palavras — ideal para snippet direto nos resultados.
4. Inclua pelo menos 1 dado numérico concreto ou contextual por resposta (use linguagem cautelosa se não tiver dado real verificável).

━━ REGRAS E-E-A-T ━━
1. EXPERIENCE: inclua pelo menos 1 exemplo prático ou caso de uso (pode ser genérico e hipotético, mas apresentado de forma tangível).
2. EXPERTISE: use terminologia técnica adequada ao nicho "{$nicho}". O leitor tem conhecimento intermediário.
3. AUTHORITATIVENESS: faça referência a conceitos estabelecidos no nicho, sem inventar pesquisas, fontes ou nomes de especialistas.
4. TRUSTWORTHINESS — ANTI-ALUCINAÇÃO (CRÍTICO):
   - NUNCA invente: estatísticas, percentuais, datas exatas, nomes de empresas reais, versões de software, resultados garantidos, pesquisas ou estudos com números específicos.
   - Se não tiver dado real verificável, use: "estudos indicam", "especialistas apontam", "estimativas do setor sugerem" — sem número.
   - Não garanta resultados ("você VAI conseguir", "CERTAMENTE irá").

━━ QUALIDADE E TOM ━━
- Mínimo de 900 palavras no campo content_html.
- Tom de voz: {$v['tom_de_voz_da_marca']}.
- Idioma: português brasileiro.
- PROIBIDO: "é fundamental", "é essencial", "à frente da curva", "no cenário atual", "vale ressaltar", "não é à toa", "é importante destacar".
- NÃO termine com call-to-action genérico ("gostou? compartilhe!").
- NÃO repita frases inteiras do artigo original.
- Preserve tags de mídia: não remova <img>, <figure> nem blocos <!-- wp:image -->.

━━ TIPO DE SCHEMA RECOMENDADO ━━
- Tutorial "Como fazer" → "HowTo"
- Artigo informativo geral → "Article"
- Artigo com FAQ → "FAQPage"
- Review ou comparativo → "Review"
- Definição / glossário → "DefinedTerm"

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
FORMATO DE SAÍDA — OBRIGATÓRIO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Retorne SOMENTE o objeto JSON abaixo. Sem texto antes ou depois.
Sem markdown. Sem backticks. Sem explicação. Apenas o JSON.

{
  "title": "Título otimizado para SEO entre 55 e 65 caracteres, contendo a palavra-chave",
  "meta_description": "Meta description entre 140 e 160 caracteres com a palavra-chave e um convite à leitura",
  "content_html": "<p>Conteúdo completo em HTML. Mínimo 900 palavras. Sem H1.</p>",
  "faq_items": [
    {
      "question": "Pergunta real que usuários pesquisam?",
      "answer": "Resposta direta entre 60 e 120 palavras, sem clichês."
    }
  ],
  "schema_type": "Article",
  "internal_links_sugeridos": [
    {
      "anchor": "texto âncora sugerido",
      "assunto": "sobre o que seria o artigo vinculado"
    }
  ]
}
PROMPT;
    }

    /**
     * Retorna o nome da categoria principal do post.
     */
    private function get_post_category( int $post_id ): string {
        $cats = get_the_category( $post_id );
        return ! empty( $cats ) ? (string) $cats[0]->name : 'Sem categoria';
    }

    /**
     * Faz o parse manual do JSON retornado pela IA, removendo eventuais code fences.
     * Nunca usa response_format: json_object — compatível com Groq e todos os providers.
     *
     * @return array|null Array com campos do JSON, ou null se o parse falhar.
     */
    private function parse_rewrite_response( string $raw ): ?array {
        $raw = trim( $raw );

        // Remover code fences (```json ... ``` ou ``` ... ```)
        $raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
        $raw = preg_replace( '/\s*```$/i',          '', $raw );
        $raw = trim( $raw );

        // Extrair do primeiro { até o último } caso haja texto residual
        if ( preg_match( '/(\{[\s\S]+\})/u', $raw, $m ) ) {
            $raw = $m[1];
        }

        $parsed = json_decode( $raw, true );

        return is_array( $parsed ) ? $parsed : null;
    }

    /**
     * Valida os campos obrigatórios do JSON de reescrita.
     *
     * Campos obrigatórios (hard fail se inválidos):
     *   - content_html  → ausente ou strlen < 500
     *   - title         → ausente ou mb_strlen < 20
     *
     * Campos opcionais (fallback silencioso, nunca causam falha):
     *   meta_description, faq_items, schema_type, internal_links_sugeridos
     *
     * @param array|null $data Resultado de parse_rewrite_response().
     * @return string[]  Lista de erros. Vazia = válido e pode publicar.
     */
    private function validate_response( ?array $data ): array {
        if ( $data === null ) {
            return [ 'json_parse_failed' ];
        }

        $errors = [];

        if ( empty( $data['content_html'] ) || strlen( $data['content_html'] ) < 500 ) {
            $errors[] = 'content_html ausente ou muito curto (< 500 chars)';
        }

        if ( empty( $data['title'] ) || mb_strlen( (string) $data['title'] ) < 20 ) {
            $errors[] = 'title ausente ou muito curto (< 20 chars)';
        }

        return $errors;
    }

    /**
     * Appenda uma entrada ao log de reescritas do post (_geo_rewrite_log).
     * Mantém as últimas 20 entradas para evitar meta gigante.
     * Em falha: nenhum campo de post é alterado antes desta chamada.
     *
     * @param int   $post_id
     * @param array $entry  {prompt_version, provider, model, validation_passed, validation_errors, ...}
     */
    private function append_rewrite_log( int $post_id, array $entry ): void {
        $raw = get_post_meta( $post_id, '_geo_rewrite_log', true );

        if ( is_array( $raw ) ) {
            $log = $raw;
        } elseif ( is_string( $raw ) && $raw !== '' ) {
            $log = json_decode( $raw, true );
            $log = is_array( $log ) ? $log : [];
        } else {
            $log = [];
        }

        $log[] = array_merge( [ 'date' => current_time( 'mysql' ) ], $entry );

        if ( count( $log ) > 20 ) {
            $log = array_slice( $log, -20 );
        }

        update_post_meta( $post_id, '_geo_rewrite_log', $log );
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
