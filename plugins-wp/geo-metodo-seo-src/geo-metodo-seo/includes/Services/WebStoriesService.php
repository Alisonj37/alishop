<?php

namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher;

/**
 * WebStoriesService — Google Web Stories (AMP) nativas no WordPress.
 *
 * NÃO requer plugin externo. Registra CPT próprio 'geo-web-story'.
 * Suporta: imagens por provedor, 10+ slides, AdSense, Analytics, logo do site.
 *
 * @since 1.0.0
 */
class WebStoriesService {

    private $ai;

    // Provedores de imagem suportados
    private $image_providers = ['auto', 'naga', 'huggingface', 'falai', 'replicate', 'unsplash', 'pexels', 'pixabay', 'none'];

    public function __construct() {
        $this->ai = new AIManager();
    }

    // ──────────────────────────────────────────────
    // CPT + Template AMP
    // ──────────────────────────────────────────────

    public static function registerCPT(): void {
        register_post_type('geo-web-story', [
            'label'               => 'Web Stories',
            'labels'              => [
                'name'          => 'Web Stories (GEO)',
                'singular_name' => 'Web Story',
                'add_new_item'  => 'Nova Web Story',
                'edit_item'     => 'Editar Web Story',
                'view_item'     => 'Ver Web Story',
                'all_items'     => 'Todas as Web Stories',
            ],
            'public'              => true,
            'has_archive'         => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'rewrite'             => ['slug' => 'web-story', 'with_front' => false],
            'supports'            => ['title', 'thumbnail'],
            'publicly_queryable'  => true,
            'exclude_from_search' => false,
            'capability_type'     => 'post',
        ]);
    }

    /**
     * Intercepta request para geo-web-story e serve HTML AMP puro.
     * Impede o tema WordPress de envolver o conteúdo.
     */
    public static function serveAMPTemplate(): void {
        if (!is_singular('geo-web-story')) return;

        $post = get_post();
        if (!$post || !get_post_meta($post->ID, '_geo_web_story', true)) return;

        // 1. Tentar meta _geo_amp_html (versão v1.0.0+)
        $amp_html = get_post_meta($post->ID, '_geo_amp_html', true);

        // 2. Fallback: post_content direto (versões anteriores ou se meta não existe)
        if (empty($amp_html) || strpos($amp_html, 'amp-story') === false) {
            $amp_html = $post->post_content;
        }

        // 3. Se ainda sem HTML AMP válido, tentar ler do banco sem filtros
        if (empty($amp_html) || strpos($amp_html, 'amp-story') === false) {
            global $wpdb;
            $raw = $wpdb->get_var($wpdb->prepare(
                "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d",
                $post->ID
            ));
            if ($raw && strpos($raw, 'amp-story') !== false) {
                $amp_html = $raw;
                update_metadata('post', $post->ID, '_geo_amp_html', $raw);
            }
        }

        if (empty($amp_html) || strpos($amp_html, 'amp-story') === false) {
            $source_id = (int) get_post_meta($post->ID, '_geo_web_story_source', true);
            if ($source_id && get_post($source_id)) {
                $ws     = new self();
                $result = $ws->generateFromPost($source_id, '', 'unsplash');
                if (!is_wp_error($result)) {
                    wp_redirect(get_permalink($post->ID));
                    exit;
                }
            }
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Web Story</title>
<style>body{font-family:sans-serif;background:#1a1a2e;color:#fff;padding:40px;text-align:center;}</style>
</head><body><h2>⏳ Web Story sendo regenerada</h2>
<p>Esta story estava em formato antigo. A regeneração falhou — acesse SARA → Web Stories e clique "Gerar Story" para este artigo.</p>
</body></html>';
            exit;
        }

        // ── FIX AMP: sanitizar HTML antes de servir ──────────────────────────
        // Remove scripts de componentes AMP não usados no HTML (causa reprovação no validador)
        // e corrige a ordem do <head> se necessário.
        $amp_html = self::sanitizeAmpHtml($amp_html, $post->ID);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Robots-Tag: index, follow');
        echo $amp_html;
        exit;
    }

    /**
     * Sanitiza o HTML AMP antes de servir:
     * 1. Remove scripts de componentes não usados no body (amp-video, amp-sidebar, etc.)
     * 2. Garante que o amp-boilerplate está correto e na posição certa
     * 3. Salva o HTML corrigido no meta para não precisar corrigir toda vez
     */
    private static function sanitizeAmpHtml(string $html, int $post_id): string {
        $original = $html;

        // 1. Componentes que podem ter sido incluídos mas não são usados nas stories simples
        $unused_components = [
            'amp-video',
            'amp-sidebar',
            'amp-accordion',
            'amp-carousel',
            'amp-lightbox',
            'amp-iframe',
            'amp-social-share',
            'amp-form',
            'amp-bind',
            'amp-live-list',
        ];

        foreach ($unused_components as $component) {
            // Verificar se o componente é realmente usado no body
            $is_used = stripos($html, '<' . $component) !== false;
            if (!$is_used) {
                // Remover o script custom-element deste componente
                $html = preg_replace(
                    '/<script[^>]+custom-element=["\']' . preg_quote($component, '/') . '["\'][^>]*><\/script>\s*/i',
                    '',
                    $html
                );
            }
        }

        // 2. Garantir boilerplate correto — só corrige se estiver AUSENTE ou MALFORMADO.
        // Reprocessar um boilerplate já correto com regex gananciosa pode corrompê-lo
        // (era a causa do erro "formato dos tags amp-boilerplate incorreto" no teste Google).
        $boilerplate_correto = '<style amp-boilerplate>body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-ms-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}</style><noscript><style amp-boilerplate>body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}</style></noscript>';

        // Conta quantos blocos de boilerplate existem
        $boilerplate_count = preg_match_all('/<style amp-boilerplate>/i', $html);

        if ($boilerplate_count === 0) {
            // Ausente → inserir antes de </head>
            $html = preg_replace('/<\/head>/i', "  " . $boilerplate_correto . "\n</head>", $html, 1);
        } elseif ($boilerplate_count > 2) {
            // Duplicado (mais de 2 = style + noscript style) → remover todos e inserir 1 correto
            $html = preg_replace('/<style amp-boilerplate>.*?<\/style>/is', '', $html);
            $html = preg_replace('/<noscript>\s*<style amp-boilerplate>.*?<\/style>\s*<\/noscript>/is', '', $html);
            $html = preg_replace('/<\/head>/i', "  " . $boilerplate_correto . "\n</head>", $html, 1);
        }
        // Se houver exatamente 1-2 (o formato correto: 1 style + 1 noscript style), não mexe.

        // 3. Se o HTML mudou, salvar no meta para evitar processar toda vez
        if ($html !== $original) {
            update_post_meta($post_id, '_geo_amp_html', $html);
        }

        return $html;
    }

    // ──────────────────────────────────────────────
    // Geração principal
    // ──────────────────────────────────────────────

    /**
     * Gera Web Story completa a partir de um post.
     *
     * @param int    $post_id
     * @param string $provider      Provedor de IA
     * @param string $image_provider 'naga'|'huggingface'|'none'
     * @return int|\WP_Error
     */
    public function generateFromPost( int $post_id, string $provider = '', string $image_provider = 'unsplash' ) {
        $post = get_post($post_id);
        if (!$post) return new \WP_Error('not_found', 'Post nao encontrado');

        $provider       = ProviderResolver::for('webstories', $provider);
        $image_provider = in_array($image_provider, $this->image_providers) ? $image_provider : 'auto';

        $keyword    = get_post_meta($post_id, '_geo_keyword', true) ?: $post->post_title;
        $excerpt    = wp_strip_all_tags($post->post_excerpt ?: wp_trim_words($post->post_content, 40));
        $content    = mb_substr(wp_strip_all_tags($post->post_content), 0, 2500);
        $post_url   = get_permalink($post_id);
        $thumb_id   = get_post_thumbnail_id($post_id);
        $thumb_url  = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : '';

        // Coletar TODAS as imagens do artigo original (featured + imagens do corpo).
        // Estas são usadas primeiro, uma por slide; só completa com IA se faltar.
        $article_images = $this->collectArticleImages($post_id, $post->post_content, $thumb_url);
        LogService::log('info', 'WebStory: ' . count($article_images) . ' imagens coletadas do artigo original.');

        // 1. Web Stories rapido: 12 slides locais a partir do artigo, sem gastar IA de texto.
        $slides = $this->generateFallbackSlides($post->post_title, $keyword, $content, $excerpt);
        LogService::log('info', 'WebStory rapido: 12 slides locais gerados sem chamada de IA.');

        // 2. Preencher todos os slides: imagens do artigo primeiro, IA completa o resto.
        if ($image_provider !== 'none') {
            $slides = $this->enrichWithImages($slides, $keyword, $image_provider, (string)$thumb_url, $post_id, $article_images);
        }

        // 3. Construir HTML AMP completo
        $story_html = $this->buildStoryHTML($post->post_title, $keyword, $slides, $post_url, $thumb_url);

        // 4. Salvar e retornar ID, preservando slides editaveis em meta
        return $this->saveStory($post->post_title, $story_html, $post_id, $thumb_id, $slides, $keyword);
    }

    // ──────────────────────────────────────────────
    // Geração de slides via IA
    // ──────────────────────────────────────────────

    private function generateSlides( string $title, string $keyword, string $content, string $excerpt, string $provider ): array {
        return $this->generateFallbackSlides($title, $keyword, $content, $excerpt);
    }

    private function generateFallbackSlides( string $title, string $keyword, string $content, string $excerpt ): array {
        $text = trim(wp_strip_all_tags($content ?: $excerpt ?: $title));
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $sentences = array_values(array_filter(array_map('trim', $sentences)));
        if (empty($sentences)) {
            $sentences = [$excerpt ?: $title];
        }

        $colors = ['#1a1a2e', '#0f3460', '#2d1b69', '#1e3c72', '#4a1942', '#134e5e', '#243b55', '#312e81', '#0f766e', '#7c2d12', '#374151', '#111827'];
        $emojis = ['💡', '📱', '⚙️', '✅', '🚀', '👉'];
        $slides = [];

        for ($i = 0; $i < 12; $i++) {
            $source = $sentences[$i % count($sentences)];
            $slide_title = $i === 0 ? $title : wp_trim_words($source, 7, '');
            if ($i === 11) {
                $slide_title = 'Leia o artigo completo';
                $source = 'Veja o guia completo para entender os detalhes, exemplos e proximos passos sobre ' . $keyword . '.';
            }
            $slides[] = [
                'slide' => $i + 1,
                'title' => $slide_title ?: $title,
                'text' => wp_trim_words($source, 34, '.'),
                'bg_color' => $colors[$i % count($colors)],
                'text_color' => '#ffffff',
                'emoji' => $emojis[$i % count($emojis)],
                'image_prompt' => 'professional vertical editorial photo about ' . sanitize_text_field($keyword . ' ' . $slide_title) . ', realistic, relevant to the article, no text, no logo',
            ];
        }

        return $slides;
    }

    // ──────────────────────────────────────────────
    // Imagens nos slides
    // ──────────────────────────────────────────────

    /**
     * Enriquece cada slide com imagem pela cadeia obrigatoria Naga.ac -> HuggingFace.
     */
    /**
     * Coleta TODAS as imagens do artigo original, em ordem:
     * 1) imagem destacada (featured)
     * 2) todas as <img> do conteúdo (na ordem em que aparecem)
     * Remove duplicatas e retorna URLs em tamanho grande quando possível.
     *
     * @return string[] Lista de URLs de imagem (sem repetição)
     */
    private function collectArticleImages( int $post_id, string $post_content, string $thumb_url = '' ): array {
        $urls = [];

        // 1. Featured image primeiro
        if ($thumb_url !== '') {
            $urls[] = $thumb_url;
        }

        // 2. Todas as <img src="..."> do conteúdo, na ordem
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $post_content, $m)) {
            foreach ($m[1] as $src) {
                $src = trim($src);
                if ($src === '') continue;
                // Ignorar imagens base64 inline e ícones/emojis
                if (stripos($src, 'data:image') === 0) continue;
                if (preg_match('/\.(svg|gif)(\?|$)/i', $src)) continue;
                // Normalizar para tamanho grande se for um attachment do WP
                $full = $this->resolveAttachmentLargeUrl($src);
                $urls[] = $full ?: $src;
            }
        }

        // 3. Galerias do WordPress (ids de attachment no shortcode [gallery ids="..."])
        if (preg_match_all('/\[gallery[^\]]*ids=["\']([0-9,\s]+)["\']/i', $post_content, $gm)) {
            foreach ($gm[1] as $ids_str) {
                foreach (array_filter(array_map('trim', explode(',', $ids_str))) as $aid) {
                    $u = wp_get_attachment_image_url((int)$aid, 'large');
                    if ($u) $urls[] = $u;
                }
            }
        }

        // Remover duplicatas preservando a ordem
        $urls = array_values(array_unique(array_filter($urls)));
        return $urls;
    }

    /** Tenta resolver a URL "large" de uma imagem que é attachment do WP. */
    private function resolveAttachmentLargeUrl( string $src ): string {
        $attachment_id = attachment_url_to_postid($src);
        if ($attachment_id) {
            $large = wp_get_attachment_image_url($attachment_id, 'large');
            if ($large) return $large;
        }
        return '';
    }

    /**
     * Garante que a imagem esteja hospedada no próprio site (válida para AMP).
     * Se já for local, retorna como está. Se for externa, faz sideload.
     * Imagens do artigo geralmente já são locais, então normalmente é instantâneo.
     */
    private function ensureHostedImage( string $url, int $post_id, string $title ): string {
        if ($url === '') return '';

        // Já é do próprio site? Então já está hospedada e válida.
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $img_host  = wp_parse_url($url, PHP_URL_HOST);
        if ($img_host && $site_host && stripos($img_host, $site_host) !== false) {
            return $url; // local — usar direto
        }

        // Externa → tentar sideload (usa o fallback manual robusto já existente)
        if (class_exists(__NAMESPACE__ . '\SafeImageSideload')) {
            $hosted = SafeImageSideload::url($url, $post_id, $title);
            if ($hosted !== '') return $hosted;
        }
        // Se o sideload falhar, ainda retornamos a URL original (melhor ter a imagem)
        return $url;
    }

    private function enrichWithImages( array $slides, string $keyword, string $image_provider, string $fallback_image_url = '', int $post_id = 0, array $article_images = [] ): array {
        // Web Stories geram 10-12 imagens em sequência. Cada media_handle_sideload
        // cria thumbnails e consome memória. Sem margem suficiente, o WordPress
        // falha com "Não foi possível inserir o anexo no banco de dados" do 2º slide
        // em diante, fazendo todos os slides reusarem a mesma imagem de fallback.
        @set_time_limit(300);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }

        $gradients = [
            ['#1a1a2e', '#16213e'], ['#0f3460', '#533483'],
            ['#2d1b69', '#11998e'], ['#1e3c72', '#2a5298'],
            ['#4a1942', '#c84b31'], ['#134e5e', '#71b280'],
        ];

        $photo_provider = in_array($image_provider, ['unsplash', 'pexels', 'pixabay'], true) ? $image_provider : 'auto';
        $total_slides = count($slides);

        // ── REGRA: cada slide PRECISA de uma imagem, e elas devem ser variadas. ──
        // Estratégia em ordem de prioridade:
        //   1) Usar as imagens do ARTIGO ORIGINAL (featured + corpo), uma por slide.
        //   2) Quando as imagens do artigo acabarem, COMPLETAR o resto com IA.
        //   3) Se a IA falhar, distribuir as imagens já obtidas (rotação) para
        //      nenhum slide ficar sem imagem.
        // Sideload das imagens do artigo para ficarem hospedadas e válidas no AMP.
        $article_pool = [];
        foreach ($article_images as $img_url) {
            if (!is_string($img_url) || $img_url === '') continue;
            $article_pool[] = $img_url;
        }
        $article_count = count($article_pool);
        $article_ptr = 0;

        $obtained_urls = []; // todas as imagens reais já aplicadas (para fallback rotativo)
        $library_mode = class_exists(__NAMESPACE__ . '\LibraryImageService') && LibraryImageService::is_library_mode();

        foreach ($slides as $i => &$slide) {
            $slide['gradient'] = $gradients[$i % count($gradients)];
            if ($image_provider === 'none') {
                $slide['image_url'] = '';
                continue;
            }

            $url = '';

            // 1) PRIMEIRO: imagem do artigo original (uma por slide, em ordem)
            if ($article_ptr < $article_count) {
                $candidate = $article_pool[$article_ptr];
                $article_ptr++;
                // Garantir que a imagem esteja hospedada (sideload se externa)
                $hosted = $this->ensureHostedImage($candidate, $post_id, $keyword . ' slide ' . ($i + 1));
                $url = $hosted ?: $candidate;
                $slide['image_source'] = 'article_original';
            }

            // 2) Imagens do artigo acabaram → COMPLETAR com IA (ou foto/biblioteca)
            if ($url === '') {
                if ($library_mode) {
                    $url = $this->fetchLibraryImageForSlide($keyword, $slide, $i + 1, $post_id);
                    if ($url === '') {
                        $url = $this->fetchAiImageForSlide($keyword, $slide, 'auto', $i + 1);
                    }
                } elseif (in_array($image_provider, ['auto', 'naga', 'huggingface', 'falai', 'replicate'], true)) {
                    $url = $this->fetchAiImageForSlide($keyword, $slide, $image_provider, $i + 1);
                } else {
                    $url = $this->fetchPhotoForSlide($keyword, $slide, $photo_provider, $i + 1);
                    if ($url === '') {
                        $url = $this->fetchAiImageForSlide($keyword, $slide, 'auto', $i + 1);
                    }
                }
                if ($url !== '') {
                    $slide['image_source'] = 'ai_completed';
                }
            }

            // 3) Última rede de segurança: rotacionar entre imagens já obtidas
            if ($url === '' && !empty($obtained_urls)) {
                $url = $obtained_urls[$i % count($obtained_urls)];
                $slide['image_fallback'] = 'rotated_from_obtained';
            }

            $slide['image_url'] = $url;
            if ($url === '') {
                $slide['image_error'] = 'image_generation_failed';
            } elseif (empty($slide['image_fallback'])) {
                $obtained_urls[] = $url; // imagem real e única deste slide
            }

            // Liberar memória entre slides
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
            gc_collect_cycles();
        }
        unset($slide);

        // Passada final: qualquer slide ainda SEM imagem recebe uma das obtidas
        // (rotação), garantindo a regra "todo slide tem imagem".
        if (!empty($obtained_urls)) {
            $rot = 0;
            foreach ($slides as $i => &$slide3) {
                if (empty($slide3['image_url'])) {
                    $slide3['image_url'] = $obtained_urls[$rot % count($obtained_urls)];
                    $slide3['image_fallback'] = 'final_rotation';
                    $rot++;
                }
            }
            unset($slide3);
        }

        $with_images = count(array_filter($slides, static function ($slide) {
            return !empty($slide['image_url']);
        }));
        LogService::log('info', 'WebStory: imagens contextuais aplicadas em ' . $with_images . '/' . count($slides) . ' slides.');

        return $slides;
    }
    /**
     * Salva imagem remota na biblioteca para reduzir falhas de AMP/hotlink em Web Stories.
     * Mantém URL externa como fallback quando o download falha.
     */
    private function localizeImageUrl( string $url, string $title ): string {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        if (!class_exists(__NAMESPACE__ . '\\SafeImageSideload')) {
            return '';
        }

        $local_url = SafeImageSideload::url($url, 0, $title);
        if (!$local_url) {
            LogService::log('error', 'WebStory: falha ao salvar imagem do slide — ' . SafeImageSideload::last_error());
            return '';
        }

        return $local_url;
    }

    /**
     * Busca pool de N imagens diferentes de uma vez (1 chamada de API).
     * Usado para distribuir imagens diferentes por slide.
     */
    private function buildSlideImageQuery( string $keyword, array $slide ): string {
        $parts = [
            $keyword,
            (string)($slide['title'] ?? ''),
            (string)($slide['text'] ?? ''),
        ];
        $query = trim(implode(' ', array_filter($parts)));
        return sanitize_text_field(wp_trim_words($query, 12, ''));
    }

    private function buildSlideAiPrompt( string $keyword, array $slide ): string {
        $title = sanitize_text_field((string)($slide['title'] ?? $keyword));
        $text = sanitize_text_field((string)($slide['text'] ?? ''));
        $base = sanitize_text_field((string)($slide['image_prompt'] ?? ''));
        if ($base === '') {
            $base = 'professional vertical editorial image about ' . $keyword . ' and ' . $title;
        }

        return trim($base . ', focus topic: ' . $keyword . ', slide title: ' . $title . ', slide context: ' . wp_trim_words($text, 18, '') . ', realistic, contextual, vertical 9:16, no text, no letters, no logo, no watermark');
    }

    private function collectWebStoryLibraryUrls(int $post_id, string $fallback_image_url = ''): array {
        $urls = [];
        $seen = [];

        $push_url = static function (string $url) use (&$urls, &$seen): void {
            $url = trim($url);
            if ($url === '' || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;
            $urls[] = $url;
        };

        $push_attachment = function (int $attachment_id) use (&$push_url): void {
            $attachment_id = absint($attachment_id);
            if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
                return;
            }
            $src = wp_get_attachment_image_url($attachment_id, 'large');
            if (!$src) {
                $src = wp_get_attachment_url($attachment_id);
            }
            if ($src) {
                $push_url((string) $src);
            }
        };

        if ($post_id > 0) {
            $featured_id = absint(get_post_thumbnail_id($post_id));
            if ($featured_id > 0) {
                $push_attachment($featured_id);
            }

            foreach (['_geo_body_image_ids', '_sara_body_image_ids'] as $meta_key) {
                $ids = get_post_meta($post_id, $meta_key, true);
                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        $push_attachment((int) $id);
                    }
                }
            }

            $content = (string) get_post_field('post_content', $post_id);
            if ($content !== '' && class_exists(__NAMESPACE__ . '\LibraryImageService')) {
                $ids = LibraryImageService::extract_attachment_ids_from_content($content);
                foreach ((array) $ids as $id) {
                    $push_attachment((int) $id);
                }
            }
        }

        if (empty($urls) && $fallback_image_url !== '') {
            $push_url($fallback_image_url);
        }

        if (!empty($urls)) {
            LogService::log('info', 'WebStory: pool da Biblioteca/Artigo montado com ' . count($urls) . ' imagens reutilizáveis.');
        }

        return $urls;
    }

    private function fetchPhotoForSlide( string $keyword, array $slide, string $provider, int $slide_number ): string {
        $query = $this->buildSlideImageQuery($keyword, $slide);
        $providers = [];
        if (in_array($provider, ['unsplash', 'pexels', 'pixabay'], true)) {
            $providers[] = $provider;
        }
        foreach (['pexels', 'unsplash', 'pixabay'] as $candidate) {
            if (!in_array($candidate, $providers, true)) {
                $providers[] = $candidate;
            }
        }

        foreach ($providers as $candidate) {
            $pool = $this->fetchImagePool($query, $candidate, 3);
            if (!empty($pool)) {
                LogService::log('info', 'WebStory: imagem contextual do slide ' . $slide_number . ' via ' . $candidate . ' usando busca "' . $query . '".');
                $local = $this->localizeImageUrl((string)$pool[0], $keyword . '-webstory-slide-' . $slide_number);
                return $local ?: (string)$pool[0];
            }
        }

        return '';
    }

    private function fetchLibraryImageForSlide( string $keyword, array $slide, int $slide_number, int $post_id = 0 ): string {
        $image = new ImageGeneratorService();
        $attachment_id = $image->generate_story_attachment([
            'title' => (string)($slide['title'] ?? $keyword),
            'keyword' => $keyword,
            'section' => (string)($slide['title'] ?? ('slide ' . $slide_number)),
            'category' => 'web-story',
        ], $post_id);

        if (!is_wp_error($attachment_id) && $attachment_id) {
            $url = wp_get_attachment_url((int)$attachment_id) ?: '';
            if ($url !== '') {
                LogService::log('info', 'WebStory: imagem do slide ' . $slide_number . ' selecionada da Biblioteca de Mídia.');
                return $url;
            }
        }

        LogService::log('warning', 'WebStory: biblioteca sem imagem valida para o slide ' . $slide_number . '. Nenhuma API de imagem foi chamada porque Fonte de Imagens=Biblioteca.');
        return '';
    }

    private function fetchAiImageForSlide( string $keyword, array $slide, string $provider, int $slide_number ): string {
        $prompt = $this->buildSlideAiPrompt($keyword, $slide);
        $image = new ImageGeneratorService();
        $attachment_id = $image->generate_story_attachment([
            'title' => (string)($slide['title'] ?? $keyword),
            'keyword' => $keyword,
            'section' => $prompt,
            'category' => 'web-story',
        ], 0);

        if (!is_wp_error($attachment_id) && $attachment_id) {
            $url = wp_get_attachment_url((int)$attachment_id) ?: '';
            if ($url !== '') return $url;
        }

        LogService::log('warning', 'WebStory: motor central nao retornou imagem para o slide ' . $slide_number . '.');
        return '';
    }

    private function buildStoryImageQuery( string $keyword, array $slides ): string {
        $parts = [$keyword];
        foreach (array_slice($slides, 0, 4) as $slide) {
            $parts[] = (string)($slide['title'] ?? '');
        }
        $query = trim(implode(' ', array_filter($parts)));
        return sanitize_text_field(wp_trim_words($query, 14, ''));
    }

    private function fetchBestImagePool( string $query, string $provider, int $count ): array {
        $providers = [];
        if (in_array($provider, ['unsplash', 'pexels', 'pixabay'], true)) {
            $providers[] = $provider;
        }
        foreach (['pexels', 'unsplash', 'pixabay'] as $candidate) {
            if (!in_array($candidate, $providers, true)) {
                $providers[] = $candidate;
            }
        }

        foreach ($providers as $candidate) {
            $pool = $this->fetchImagePool($query, $candidate, $count);
            if (!empty($pool)) {
                LogService::log('info', 'WebStory: pool rapido de imagens via ' . $candidate . ' (' . count($pool) . ' imagens).');
                return $pool;
            }
        }

        return [];
    }

    private function fetchSingleStoryImage( string $query, string $keyword ): string {
        $image_service = new ImageGeneratorService();
        $url = $image_service->generate_web_story_url([
            'title'    => $keyword,
            'keyword'  => $keyword,
            'section'  => $query,
            'category' => 'web story',
        ]);

        if (is_wp_error($url) || !$url) {
            return '';
        }

        return $this->localizeImageUrl((string)$url, $keyword . '-webstory-cover') ?: (string)$url;
    }

    private function fetchImagePool( string $keyword, string $provider, int $count = 12 ): array {
        $urls = [];
        $count = min($count, 30);

        switch ($provider) {
            case 'unsplash':
                $key = get_option('geo_unsplash_api_key', '');
                if (!$key) break;
                $r = wp_remote_get('https://api.unsplash.com/search/photos?' . http_build_query([
                    'query' => $keyword, 'per_page' => $count, 'orientation' => 'portrait', 'order_by' => 'relevant'
                ]), ['timeout' => 10, 'headers' => ['Authorization' => 'Client-ID ' . $key]]);
                if (!is_wp_error($r)) {
                    $data = json_decode(wp_remote_retrieve_body($r), true);
                    foreach ($data['results'] ?? [] as $photo) {
                        $url = $photo['urls']['regular'] ?? '';
                        if ($url) $urls[] = $url;
                    }
                }
                break;

            case 'pexels':
                $key = get_option('geo_pexels_api_key', '');
                if (!$key) break;
                $r = wp_remote_get('https://api.pexels.com/v1/search?' . http_build_query([
                    'query' => $keyword, 'per_page' => $count, 'orientation' => 'portrait'
                ]), ['timeout' => 10, 'headers' => ['Authorization' => $key]]);
                if (!is_wp_error($r)) {
                    $data = json_decode(wp_remote_retrieve_body($r), true);
                    foreach ($data['photos'] ?? [] as $photo) {
                        $url = $photo['src']['portrait'] ?? '';
                        if ($url) $urls[] = $url;
                    }
                }
                break;

            case 'pixabay':
                $key = get_option('geo_pixabay_api_key', '');
                if (!$key) break;
                $r = wp_remote_get('https://pixabay.com/api/?' . http_build_query([
                    'key' => $key, 'q' => $keyword, 'image_type' => 'photo',
                    'orientation' => 'vertical', 'per_page' => min($count, 20), 'safesearch' => 'true'
                ]), ['timeout' => 10]);
                if (!is_wp_error($r)) {
                    $data = json_decode(wp_remote_retrieve_body($r), true);
                    foreach ($data['hits'] ?? [] as $hit) {
                        $url = $hit['largeImageURL'] ?? '';
                        if ($url) $urls[] = $url;
                    }
                }
                break;
        }

        // Embaralhar para variedade
        shuffle($urls);
        return $urls;
    }

    private function fetchImage( string $prompt, string $keyword, string $provider ): string {
        $image_service = new ImageGeneratorService();
        $url = $image_service->generate_web_story_url([
            'title'    => $keyword,
            'keyword'  => $keyword,
            'section'  => $prompt,
            'category' => 'web story',
        ]);

        return is_wp_error($url) ? '' : (string) $url;
    }

    private function buildStoryHTML( string $title, string $keyword, array $slides, string $source_url, string $cover_url ): string {
        $site_name      = get_bloginfo('name');
        $site_url       = get_site_url();
        $site_desc      = get_bloginfo('description');

        // Logo do site — tenta pegar automaticamente
        $logo_url = $this->getSiteLogo();
        $favicon  = get_site_icon_url(32) ?: $site_url . '/favicon.ico';

        // Configurações AdSense
        $adsense_pub_id  = get_option('geo_webstory_adsense_pub_id', '');  // ex: pub-1234567890123456
        $adsense_slot_id = get_option('geo_webstory_adsense_slot_id', ''); // ex: 1234567890

        // Google Analytics
        $ga4_id = get_option('geo_webstory_ga4_id', '') ?: get_option('geo_google_analytics_id', ''); // ex: G-XXXXXXXXXX

        // Poster (capa)
        $poster = !empty($cover_url) ? $cover_url : '';

        // ── Head: scripts AMP obrigatórios ──
        // REGRA AMP: apenas scripts dos componentes REALMENTE usados no HTML.
        // amp-video foi removido — não há nenhum <amp-video> nos slides gerados.
        // Adicionar amp-video sem usar o componente reprova o teste AMP do Google.
        $amp_scripts  = '<script async src="https://cdn.ampproject.org/v0.js"></script>' . "\n";
        $amp_scripts .= '<script async custom-element="amp-story" src="https://cdn.ampproject.org/v0/amp-story-1.0.js"></script>' . "\n";

        // AdSense script — APENAS se pub_id E slot_id existem (mesma condição do
        // bloco no corpo). Adicionar o script sem o componente <amp-story-auto-ads>
        // no corpo reprova o teste AMP ("script presente mas não usado").
        if (!empty($adsense_pub_id) && !empty($adsense_slot_id)) {
            $amp_scripts .= '<script async custom-element="amp-story-auto-ads" src="https://cdn.ampproject.org/v0/amp-story-auto-ads-0.1.js"></script>' . "\n";
        }

        // Analytics script (apenas se GA4 configurado)
        if (!empty($ga4_id)) {
            $amp_scripts .= '<script async custom-element="amp-analytics" src="https://cdn.ampproject.org/v0/amp-analytics-0.1.js"></script>' . "\n";
        }

        // ── Páginas ──
        $pages_html = '';
        $total      = count($slides);

        foreach ($slides as $i => $slide) {
            $bg_color    = $this->sanitizeColor($slide['bg_color'] ?? '#1a1a2e');
            $text_color  = $this->sanitizeColor($slide['text_color'] ?? '#ffffff');
            $emoji       = esc_html(mb_substr($slide['emoji'] ?? '', 0, 6));
            $text        = esc_html(mb_substr($slide['text'] ?? '', 0, 100));
            $slide_title = esc_html(mb_substr($slide['title'] ?? '', 0, 80));
            $image_url   = esc_url($slide['image_url'] ?? '');
            $page_id     = 'p' . ($i + 1);
            $is_last     = ($i === $total - 1);
            $is_first    = ($i === 0);

            $pages_html .= "<amp-story-page id=\"{$page_id}\" auto-advance-after=\"6s\">\n";

            // Layer 1: imagem de fundo (se houver)
            if (!empty($image_url)) {
                $pages_html .= "  <amp-story-grid-layer template=\"fill\">\n";
                // Google Web Stories: imagens devem ser 640x853px mínimo, recomendado 720x1280 (9:16)
                $pages_html .= "    <amp-img src=\"{$image_url}\" width=\"720\" height=\"1280\" layout=\"fill\" object-fit=\"cover\" alt=\"" . esc_attr($keyword) . "\"></amp-img>\n";
                // Overlay escuro para legibilidade
                $overlay_opacity = $is_first ? '0.55' : '0.45';
                $pages_html .= "    <div style=\"position:absolute;inset:0;background:{$bg_color};opacity:{$overlay_opacity};\"></div>\n";
                $pages_html .= "  </amp-story-grid-layer>\n";
            } else {
                // Fundo com gradiente profissional
                $grad = $slide['gradient'] ?? ['#1a1a2e', '#16213e'];
                $grad1 = is_array($grad) ? $grad[0] : '#1a1a2e';
                $grad2 = is_array($grad) ? $grad[1] : '#16213e';
                $pages_html .= "  <amp-story-grid-layer template=\"fill\">\n";
                $pages_html .= "    <div style=\"background:linear-gradient(145deg,{$grad1},{$grad2});width:100%;height:100%;\"></div>\n";

                // Imagem de capa no primeiro slide se disponível
                if ($is_first && !empty($cover_url)) {
                    $pages_html .= "    <amp-img src=\"" . esc_url($cover_url) . "\" width=\"720\" height=\"1280\" layout=\"fill\" object-fit=\"cover\" style=\"opacity:0.35;\"></amp-img>\n";
                }
                $pages_html .= "  </amp-story-grid-layer>\n";
            }

            // Layer 2: cabeçalho (logo + nome do site)
            $pages_html .= "  <amp-story-grid-layer template=\"thirds\">\n";
            $pages_html .= "    <div grid-area=\"upper-third\" style=\"padding:20px 24px;display:flex;align-items:center;gap:10px;\">\n";
            if (!empty($logo_url)) {
                $pages_html .= "      <amp-img src=\"" . esc_url($logo_url) . "\" width=\"32\" height=\"32\" layout=\"fixed\" style=\"border-radius:50%;\"></amp-img>\n";
            }
            $pages_html .= "      <span style=\"color:rgba(255,255,255,0.9);font-size:13px;font-family:sans-serif;font-weight:600;text-shadow:0 1px 3px rgba(0,0,0,0.5);\">" . esc_html($site_name) . "</span>\n";
            $pages_html .= "    </div>\n";

            // Layer 3: conteúdo central — texto dentro de card com fundo profissional
            // para garantir legibilidade sobre qualquer imagem.
            $pages_html .= "    <div grid-area=\"middle-third\" style=\"padding:0 24px;display:flex;flex-direction:column;align-items:center;justify-content:center;\">\n";
            if ($emoji) {
                $pages_html .= "      <div style=\"font-size:72px;line-height:1;margin-bottom:20px;text-shadow:0 2px 8px rgba(0,0,0,0.5);\">{$emoji}</div>\n";
            }
            if ($slide_title || $text) {
                // Card: fundo escuro semi-transparente com blur — não atrapalha a leitura.
                $card_bg = $is_first
                    ? 'background:linear-gradient(135deg,rgba(21,71,245,0.92),rgba(13,29,99,0.92));'
                    : 'background:rgba(15,23,42,0.78);';
                $pages_html .= "      <div style=\"{$card_bg}backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-radius:18px;padding:24px 22px;max-width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.35);border:1px solid rgba(255,255,255,0.12);\">\n";
                if ($slide_title) {
                    $font_size = $is_first ? '34px' : '22px';
                    $pages_html .= "        <h2 style=\"color:#ffffff;font-size:{$font_size};font-weight:800;line-height:1.25;margin:0;font-family:sans-serif;text-align:center;\">{$slide_title}</h2>\n";
                }
                if ($text) {
                    $margin_top = $slide_title ? 'margin:14px 0 0;' : 'margin:0;';
                    $pages_html .= "        <p style=\"color:rgba(255,255,255,0.95);font-size:18px;line-height:1.55;{$margin_top}font-family:sans-serif;text-align:center;\">{$text}</p>\n";
                }
                $pages_html .= "      </div>\n";
            }
            $pages_html .= "    </div>\n";

            // Rodapé: indicador de progresso ou keyword
            $pages_html .= "    <div grid-area=\"lower-third\" style=\"padding:0 32px 32px;text-align:center;\">\n";
            if (!$is_last) {
                $progress = ($i + 1) . '/' . $total;
                $pages_html .= "      <span style=\"color:rgba(255,255,255,0.6);font-size:12px;font-family:sans-serif;\">{$progress} · " . esc_html($keyword) . "</span>\n";
            }
            $pages_html .= "    </div>\n";
            $pages_html .= "  </amp-story-grid-layer>\n";

            // CTA no último slide
            if ($is_last) {
                $pages_html .= "  <amp-story-cta-layer>\n";
                $pages_html .= "    <a href=\"" . esc_url($source_url) . "\" style=\"display:inline-block;background:#0073aa;color:#fff;padding:14px 32px;border-radius:10px;font-size:18px;font-weight:700;text-decoration:none;font-family:sans-serif;box-shadow:0 4px 12px rgba(0,0,0,0.3);\">📖 Ler artigo completo</a>\n";
                $pages_html .= "  </amp-story-cta-layer>\n";
            }

            $pages_html .= "</amp-story-page>\n\n";
        }

        // ── AdSense Auto Ads ──
        $adsense_block = '';
        if (!empty($adsense_pub_id) && !empty($adsense_slot_id)) {
            $adsense_block = "<amp-story-auto-ads>\n"
                           . "  <script type=\"application/json\">\n"
                           . "  {\n"
                           . "    \"ad-attributes\": {\n"
                           . "      \"type\": \"adsense\",\n"
                           . "      \"data-ad-client\": \"" . esc_attr($adsense_pub_id) . "\",\n"
                           . "      \"data-ad-slot\": \"" . esc_attr($adsense_slot_id) . "\"\n"
                           . "    }\n"
                           . "  }\n"
                           . "  </script>\n"
                           . "</amp-story-auto-ads>\n\n";
        }

        // ── Google Analytics 4 ──
        $analytics_block = '';
        if (!empty($ga4_id)) {
            $story_path = str_replace($site_url, '', get_permalink());
            $analytics_block = "<amp-analytics type=\"gtag\" data-credentials=\"include\">\n"
                             . "  <script type=\"application/json\">\n"
                             . "  {\n"
                             . "    \"vars\": {\n"
                             . "      \"gtag_id\": \"" . esc_attr($ga4_id) . "\",\n"
                             . "      \"config\": {\n"
                             . "        \"" . esc_attr($ga4_id) . "\": {\n"
                             . "          \"groups\": \"default\",\n"
                             . "          \"page_title\": \"" . esc_attr($title) . "\",\n"
                             . "          \"page_location\": \"" . esc_url($source_url) . "\",\n"
                             . "          \"content_group\": \"Web Story\"\n"
                             . "        }\n"
                             . "      }\n"
                             . "    }\n"
                             . "  }\n"
                             . "  </script>\n"
                             . "</amp-analytics>\n\n";
        }

        // ── Poster attributes ──
        $poster_attr = '';
        if (!empty($poster)) {
            $poster_attr = "\n  poster-portrait-src=\"" . esc_url($poster) . "\"\n  poster-square-src=\"" . esc_url($poster) . "\"";
        }

        // ── Schema.org para Web Story ──
        // O Google exige dados estruturados de Article para Web Stories aparecerem
        // na busca. WebPage sozinho não é detectado como "dados de artigo".
        $now_iso = current_time('c');
        $schema = json_encode([
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => mb_substr($title, 0, 110),
            'description'   => wp_trim_words($site_desc ?: $title, 20),
            'image'         => !empty($poster) ? [$poster] : [],
            'datePublished' => $now_iso,
            'dateModified'  => $now_iso,
            'author'        => [
                '@type' => 'Organization',
                'name'  => $site_name,
            ],
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => $site_name,
                'logo'  => ['@type' => 'ImageObject', 'url' => $logo_url ?: $favicon],
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => $source_url,
            ],
            'keywords'      => $keyword,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<!DOCTYPE html>
<html amp lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">
  ' . $amp_scripts . '
  <style amp-boilerplate>body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-ms-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}</style><noscript><style amp-boilerplate>body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}</style></noscript>
  <title>' . esc_html($title) . '</title>
  <meta name="description" content="' . esc_attr(wp_trim_words($title . '. ' . $keyword, 25)) . '">
  <meta name="keywords" content="' . esc_attr($keyword) . '">
  <meta property="og:title" content="' . esc_attr($title) . '">
  <meta property="og:type" content="article">
  <meta property="og:url" content="' . esc_url($source_url) . '">
  ' . (!empty($poster) ? '<meta property="og:image" content="' . esc_url($poster) . '">' : '') . '
  <link rel="canonical" href="' . esc_url($source_url) . '">
  <link rel="shortcut icon" href="' . esc_url($favicon) . '">
  <script type="application/ld+json">' . $schema . '</script>
</head>
<body>
<amp-story
  standalone
  title="' . esc_attr($title) . '"
  publisher="' . esc_attr($site_name) . '"
  publisher-logo-src="' . esc_url($logo_url ?: $favicon) . '"' . $poster_attr . '>

' . $adsense_block . $analytics_block . $pages_html . '
</amp-story>
</body>
</html>';
    }

    // ──────────────────────────────────────────────
    // Salvar
    // ──────────────────────────────────────────────

    private function saveStory( string $title, string $story_html, int $source_post_id, ?int $thumb_id = null, array $slides = [], string $keyword = '' ) {
        $existing = (int) get_post_meta($source_post_id, '_geo_web_story_id', true);

        // Salvar placeholder no post_content (o kses remove tags AMP)
        // O HTML AMP real vai no meta _geo_amp_html para preservar as tags
        $safe_excerpt = wp_trim_words(strip_tags($story_html), 30);

        if ($existing && get_post($existing)) {
            $post_id = \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
                'ID'           => $existing,
                'post_title'   => 'Web Story: ' . $title,
                'post_content' => $safe_excerpt,
                'post_status'  => 'publish',
            ]);
        } else {
            $publish_result = GeoMetodoSEO_Publisher::publish([
                'title' => 'Web Story: ' . $title,
                'content' => $story_html,
                'status' => 'publish',
                'post_type' => 'geo-web-story',
                'author_id' => get_current_user_id() ?: 1,
                'featured_image_id' => $thumb_id ?: 0,
                'focus_keyword' => $keyword ?: $title,
                'seo_title' => 'Web Story: ' . $title,
                'meta_description' => wp_trim_words(wp_strip_all_tags($story_html), 25, ''),
                'source_module' => 'webstories',
                'custom_meta' => [
                    '_geo_web_story' => 1,
                    '_geo_web_story_source' => $source_post_id,
                ],
            ]);
            $post_id = !empty($publish_result['success']) ? (int)$publish_result['post_id'] : new \WP_Error('publisher_failed', $publish_result['error'] ?? 'Falha ao criar Web Story via Publisher central.');
        }

        if (is_wp_error($post_id)) return $post_id;

        update_post_meta($post_id, '_geo_web_story', 1);
        update_post_meta($post_id, '_geo_web_story_source', $source_post_id);
        update_post_meta($post_id, '_geo_web_story_generated', time());
        update_post_meta($post_id, '_geo_amp_html', $story_html);
        if (!empty($slides)) update_post_meta($post_id, '_geo_web_story_slides', $this->normalizeSlides($slides));
        if ($keyword !== '') update_post_meta($post_id, '_geo_web_story_keyword', sanitize_text_field($keyword));

        if ($thumb_id) \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::set_featured_image($post_id, $thumb_id);

        update_post_meta($source_post_id, '_geo_web_story_id', $post_id);
        update_post_meta($source_post_id, '_geo_web_story_url', get_permalink($post_id));

        $this->addStoryBadgeToPost($source_post_id, get_permalink($post_id));

        flush_rewrite_rules(false);

        if (class_exists(__NAMESPACE__ . '\\WebStoryAmpValidator')) {
            WebStoryAmpValidator::validate_post((int) $post_id);
        }

        LogService::log('info', "WebStory #{$post_id} gerada para post #{$source_post_id}");

        return $post_id;
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Busca o logo do site automaticamente.
     * Tenta: site icon customizado → WordPress logo via Customizer → favicon
     */
    private function getSiteLogo(): string {
        // 1. Custom logo via Customizer
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_data = wp_get_attachment_image_src($custom_logo_id, 'thumbnail');
            if (!empty($logo_data[0])) return $logo_data[0];
        }

        // 2. Site icon (favicon de alta resolução)
        $icon_url = get_site_icon_url(512);
        if ($icon_url) return $icon_url;

        // 3. Fallback: favicon.ico
        return get_site_url() . '/favicon.ico';
    }


    /**
     * Recria o HTML AMP de uma Web Story a partir dos slides salvos/editados no admin.
     * Usado pelo metabox de edição slide por slide.
     */
    public function rebuildStoryFromMeta( int $story_id ) {
        $story = get_post($story_id);
        if (!$story || $story->post_type !== 'geo-web-story') {
            return new \WP_Error('not_found', 'Web Story não encontrada');
        }

        $source_id = (int) get_post_meta($story_id, '_geo_web_story_source', true);
        $source    = $source_id ? get_post($source_id) : null;
        if (!$source) {
            return new \WP_Error('source_not_found', 'Post original não encontrado');
        }

        $slides = get_post_meta($story_id, '_geo_web_story_slides', true);
        if (!is_array($slides) || empty($slides)) {
            return new \WP_Error('slides_not_found', 'Slides editáveis não encontrados');
        }

        $keyword   = get_post_meta($story_id, '_geo_web_story_keyword', true) ?: get_post_meta($source_id, '_geo_keyword', true) ?: $source->post_title;
        $thumb_id  = get_post_thumbnail_id($story_id) ?: get_post_thumbnail_id($source_id);
        $thumb_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : '';
        $html      = $this->buildStoryHTML($source->post_title, $keyword, $this->normalizeSlides($slides), get_permalink($source_id), $thumb_url);

        update_post_meta($story_id, '_geo_amp_html', $html);
        update_post_meta($story_id, '_geo_web_story_slides', $this->normalizeSlides($slides));
        update_post_meta($story_id, '_geo_web_story_generated', time());

        // Mantém o post_content leve para o admin, mas o frontend serve o HTML do meta.
        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
            'ID'           => $story_id,
            'post_content' => wp_trim_words(strip_tags($html), 30),
        ]);

        if (class_exists(__NAMESPACE__ . '\\WebStoryAmpValidator')) {
            WebStoryAmpValidator::validate_post((int) $story_id);
        }

        return $html;
    }

    /** Normaliza slides para salvar/ler no banco sem quebrar o AMP. */
    public function normalizeSlides( array $slides ): array {
        $out = [];
        $i   = 1;
        foreach ($slides as $slide) {
            if (!is_array($slide)) continue;
            $out[] = [
                'slide'        => $i,
                'title'        => sanitize_text_field($slide['title'] ?? ('Slide ' . $i)),
                'text'         => sanitize_textarea_field($slide['text'] ?? ''),
                'bg_color'     => $this->sanitizeColor((string)($slide['bg_color'] ?? '#1a1a2e')),
                'text_color'   => $this->sanitizeColor((string)($slide['text_color'] ?? '#ffffff')),
                'emoji'        => sanitize_text_field($slide['emoji'] ?? ''),
                'image_prompt' => sanitize_text_field($slide['image_prompt'] ?? ''),
                'image_url'    => esc_url_raw($slide['image_url'] ?? ''),
                'gradient'     => $slide['gradient'] ?? ['#1a1a2e', '#16213e'],
            ];
            $i++;
        }
        return array_slice($out, 0, 20);
    }

    private function addStoryBadgeToPost( int $post_id, string $story_url ): void {
        $post = get_post($post_id);
        if (!$post) return;
        if (strpos($post->post_content, 'geo-web-story-cta') !== false) return;

        $badge = "\n\n" . '<div class="geo-web-story-cta" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:14px;padding:20px 24px;margin:32px 0;display:flex;align-items:center;gap:16px;">'
               . '<div style="font-size:38px;flex-shrink:0;">📱</div>'
               . '<div style="flex:1;">'
               . '<strong style="color:#fff;font-size:16px;display:block;margin-bottom:4px;">Versão Web Story disponível</strong>'
               . '<span style="color:rgba(255,255,255,0.85);font-size:13px;">Visual, rápido e otimizado para Google Discover.</span>'
               . '</div>'
               . '<a href="' . esc_url($story_url) . '" style="background:rgba(255,255,255,0.2);color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;white-space:nowrap;border:1px solid rgba(255,255,255,0.35);">Ver Story ›</a>'
               . '</div>';

        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $post->post_content . $badge]);
    }

    private function sanitizeColor( string $color ): string {
        $color = trim($color);
        return preg_match('/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color) ? $color : '#1a1a2e';
    }

    public function generateBatch( array $post_ids, string $provider = '', string $image_provider = 'naga' ): array {
        $results = [];
        foreach ($post_ids as $pid) {
            $pid = (int)$pid;
            $existing = (int) get_post_meta($pid, '_geo_web_story_id', true);
            if ($existing && get_post($existing)) {
                $results[$pid] = ['skipped' => true, 'message' => 'Este post já possui Web Story', 'story_id' => $existing, 'story_url' => get_permalink($existing)];
                continue;
            }
            $result        = $this->generateFromPost($pid, $provider, $image_provider);
            $results[$pid] = is_wp_error($result)
                ? ['error' => $result->get_error_message()]
                : ['story_id' => $result, 'story_url' => get_permalink($result)];
            if (is_wp_error($result)) {
                LogService::log('warning', 'WebStory batch: item falhou, continuando sem pausa artificial.');
            }
        }
        return $results;
    }
}

