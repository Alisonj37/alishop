<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) exit;

/**
 * Orquestrador central de imagens v1.0.0.
 *
 * Objetivo: estabilidade real. Nenhum artigo deve depender de apenas um provider.
 * Cadeias resilientes:
 * - Featured: Fal.ai -> Replicate -> Naga.ac -> bancos de imagem -> Pollinations.
 * - Body: Replicate -> Fal.ai -> Naga.ac -> bancos de imagem -> Pollinations.
 * - Web Stories: Naga.ac -> HuggingFace -> Fal.ai -> Replicate -> bancos de imagem -> Pollinations.
 */
class ImageGeneratorService {

    private ImagePromptGenerator $prompt_generator;

    public function __construct() {
        $this->prompt_generator = new ImagePromptGenerator();
    }

    public static function body_images_count(): int {
        $value = absint(get_option('geo_body_images_count', 3));
        return in_array($value, [2, 3, 4, 5], true) ? $value : 3;
    }

    public static function h2_interval(): int {
        $value = absint(get_option('geo_image_h2_interval', 3));
        return in_array($value, [3, 4], true) ? $value : 3;
    }

    public static function featured_required(): bool {
        return (bool) get_option('geo_featured_image_required', 1);
    }

    /** Compatibilidade com chamadas antigas. */
    public function generate($title, $keyword = '') {
        $result = $this->generate_featured_attachment([
            'title' => (string)$title,
            'keyword' => (string)$keyword,
            'category' => '',
        ], 0);

        return is_wp_error($result) ? false : (int)$result;
    }

    public function generate_featured_attachment(array $context, int $post_id = 0) {
        $title    = sanitize_text_field((string)($context['title'] ?? ''));
        $keyword  = sanitize_text_field((string)($context['keyword'] ?? $title));
        $category = sanitize_text_field((string)($context['category'] ?? ''));

        // ── MODO BIBLIOTECA: usar imagens da biblioteca WordPress ──────────
        if (LibraryImageService::is_library_mode()) {
            if ($post_id > 0) {
                $cat_slug = LibraryImageService::resolve_category_slug($post_id);
            } elseif ($category) {
                $cat_slug = sanitize_key($category);
            } else {
                $cat_slug = 'global';
            }
            LogService::record('media', 'info',
                'LibraryMode: buscando destaque — categoria slug="' . $cat_slug . '" post_id=' . $post_id,
                ['action' => 'library_featured_lookup', 'context' => ['cat_slug' => $cat_slug, 'post_id' => $post_id]]
            );
            $id = LibraryImageService::set_featured($post_id, $keyword, $title, $cat_slug);
            if ($id) return $id;
            return new \WP_Error('geo_library_featured_empty',
                'Modo Biblioteca: nenhuma imagem encontrada para categoria "' . $cat_slug . '". Verifique os IDs Min/Max em GEO SEO → Configurações → Fonte de Imagens.');
        }
        // ──────────────────────────────────────────────────────────────────

        $visual_prompt = $this->prompt_generator->generate([
            'type' => 'featured',
            'title' => $title,
            'keyword' => $keyword,
            'category' => $category,
            'section' => (string)($context['section'] ?? ''),
            'aspect_ratio' => '16:9',
        ]);

        $attempts = [];
        $label = trim($title ?: $keyword ?: 'imagem-destaque');

        // 1) Fal.ai — melhor qualidade quando configurado.
        $fal = new FalAIImageService();
        if ($fal->is_configured()) {
            $url = $fal->generate($visual_prompt, '1792x1024', 'high');
            $id = $this->try_sideload_result($url, $post_id, $label, 'falai_featured', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'falai=nao_configurado';
        }

        // 2) Replicate Flux Schnell — fallback confiável para featured.
        $replicate = new ReplicateImageService();
        if ($replicate->is_configured()) {
            $url = $replicate->generate_schnell($visual_prompt, '16:9');
            $id = $this->try_sideload_result($url, $post_id, $label, 'replicate_featured', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'replicate=nao_configurado';
        }

        // 3) Naga.ac também deve servir para featured, não apenas Web Stories.
        $naga = new NagaImageService();
        if ($naga->isConfigured()) {
            $naga_model = (string)get_option('geo_naga_model', 'dall-e-3:free');
            if (false && strpos($naga_model, ':free') === false) {
                $naga_model = 'dall-e-3:free';
            }
            $url = $naga->generate($visual_prompt, '1792x1024', $naga_model);
            $id = $this->try_sideload_result($url, $post_id, $label, 'naga_featured', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'naga=nao_configurado';
        }

        // 4) Banco de fotos com query curta e objetiva. Melhor que Pollinations quando houver chave.
        $stock_query = trim(($keyword ?: $title) . ' ' . $category);
        $stock_url = $this->stock_image_url($stock_query, 'landscape');
        if ($stock_url) {
            $id = $this->try_sideload_result($stock_url, $post_id, $label, 'stock_featured', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'stock=sem_resultado_ou_sem_chave';
        }

        // 5) Pollinations — ultimo fallback gratuito, com prompt endurecido para realismo/contexto.
        $poll_url = $this->pollinations_url($visual_prompt, 1200, 675, $post_id . '|featured|' . $keyword);
        $id = $this->try_sideload_result($poll_url, $post_id, $label, 'pollinations_featured', $attempts, 12582912);
        if ($id) return $id;

        $message = 'Imagem destacada falhou em todos os provedores. Tentativas: ' . implode(' | ', $attempts);
        LogService::record('media', 'error', $message, [
            'post_id' => $post_id,
            'action' => 'featured_image_failed_all',
            'context' => ['title' => $title, 'keyword' => $keyword],
        ]);
        $this->add_failure_notice($message, $post_id, $keyword);

        return new \WP_Error('geo_featured_image_failed_all', $message);
    }

    public function generate_body_attachment(array $context, int $post_id = 0) {
        $title    = sanitize_text_field((string)($context['title'] ?? ''));
        $keyword  = sanitize_text_field((string)($context['keyword'] ?? $title));
        $category = sanitize_text_field((string)($context['category'] ?? ''));
        $section  = sanitize_text_field((string)($context['section'] ?? ''));

        // ── MODO BIBLIOTECA ───────────────────────────────────────────────
        if (LibraryImageService::is_library_mode()) {
            if ($post_id > 0) {
                $cat_slug = LibraryImageService::resolve_category_slug($post_id);
            } elseif ($category) {
                $cat_slug = sanitize_key($category);
            } else {
                $cat_slug = 'global';
            }
            $id = LibraryImageService::get_body_id($post_id, $section ?: $keyword, $keyword, $cat_slug);
            if ($id) {
                if ($post_id > 0) LibraryImageService::remember_body_image($post_id, (int)$id, 'library');
                return $id;
            }
            // FALLBACK: biblioteca vazia para esta categoria → gerar com IA.
            // Antes retornava WP_Error e o artigo ficava sem imagens de corpo.
            // Agora cai para a geração com IA abaixo (Replicate/Fal.ai/etc).
            if (class_exists('\\GeoMetodoSEO\\Services\\LogService')) {
                \GeoMetodoSEO\Services\LogService::record('media', 'info',
                    'Modo Biblioteca sem imagens para "' . $cat_slug . '" — gerando imagem de corpo com IA',
                    ['action' => 'library_body_fallback_to_ai', 'post_id' => $post_id,
                     'context' => ['cat_slug' => $cat_slug]]);
            }
        }
        // ─────────────────────────────────────────────────────────────────

        $visual_prompt = $this->prompt_generator->generate([
            'type' => 'body',
            'title' => $title,
            'keyword' => $keyword,
            'category' => $category,
            'section' => $section,
            'aspect_ratio' => '16:9',
        ]);

        $attempts = [];
        $label = trim(($keyword ?: $title) . ' ' . $section);
        if ($label === '') $label = 'imagem-corpo';

        // 1) Replicate primeiro para body: rápido e estável quando configurado.
        $replicate = new ReplicateImageService();
        if ($replicate->is_configured()) {
            $url = $replicate->generate_schnell($visual_prompt, '16:9');
            $id = $this->try_sideload_result($url, $post_id, $label, 'replicate_body', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'replicate=nao_configurado';
        }

        // 2) Fal.ai — fallback de alta qualidade para body images.
        $fal = new FalAIImageService();
        if ($fal->is_configured()) {
            $url = $fal->generate($visual_prompt, '1792x1024', 'medium');
            $id = $this->try_sideload_result($url, $post_id, $label, 'falai_body', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'falai=nao_configurado';
        }

        // 3) Naga.ac como fallback de body.
        $naga = new NagaImageService();
        if ($naga->isConfigured()) {
            $naga_model = (string)get_option('geo_naga_model', 'dall-e-3:free');
            if (false && strpos($naga_model, ':free') === false) {
                $naga_model = 'dall-e-3:free';
            }
            $url = $naga->generate($visual_prompt, '1792x1024', $naga_model);
            $id = $this->try_sideload_result($url, $post_id, $label, 'naga_body', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'naga=nao_configurado';
        }

        // 4) Banco de fotos com query da secao. Preferivel ao Pollinations quando houver chave.
        $stock_url = $this->stock_image_url(trim($section . ' ' . $keyword . ' ' . $category), 'landscape');
        if ($stock_url) {
            $id = $this->try_sideload_result($stock_url, $post_id, $label, 'stock_body', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'stock=sem_resultado_ou_sem_chave';
        }

        // 5) Pollinations sem chave: ultimo fallback gratuito, com prompt mais rigido.
        $poll_url = $this->pollinations_url($visual_prompt, 1200, 675, $post_id . '|body|' . $keyword . '|' . $section);
        $id = $this->try_sideload_result($poll_url, $post_id, $label, 'pollinations_body', $attempts, 12582912);
        if ($id) return $id;

        $message = 'Imagem de corpo falhou em todos os provedores. Tentativas: ' . implode(' | ', $attempts);
        LogService::record('media', 'warning', $message, [
            'post_id' => $post_id,
            'action' => 'body_image_failed_all',
            'context' => ['keyword' => $keyword, 'section' => $section],
        ]);
        return new \WP_Error('geo_body_image_failed_all', $message);
    }

    public function generate_story_attachment(array $context, int $post_id = 0) {
        $title    = sanitize_text_field((string)($context['title'] ?? ''));
        $keyword  = sanitize_text_field((string)($context['keyword'] ?? $title));
        $category = sanitize_text_field((string)($context['category'] ?? ''));
        $section  = sanitize_text_field((string)($context['section'] ?? ''));
        $label    = trim(($keyword ?: $title ?: 'web-story') . ' ' . $section);

        // ── MODO BIBLIOTECA: Web Stories também usam biblioteca ───────────
        if (LibraryImageService::is_library_mode()) {
            if ($post_id > 0) {
                $cat_slug = LibraryImageService::resolve_category_slug($post_id);
            } elseif ($category) {
                $cat_slug = sanitize_key($category);
            } else {
                $cat_slug = 'global';
            }
            $id = LibraryImageService::get_body_id($post_id, $section ?: $title, $keyword, $cat_slug);
            if ($id) {
                if ($post_id > 0) LibraryImageService::remember_body_image($post_id, (int)$id, 'library');
                return $id;
            }
            return new \WP_Error('geo_library_story_empty', 'Modo Biblioteca: nenhuma imagem válida encontrada para Web Story na categoria "' . $cat_slug . '".');
        }
        // ─────────────────────────────────────────────────────────────────

        $visual_prompt = $this->prompt_generator->generate([
            'type' => 'web_story',
            'title' => $title,
            'keyword' => $keyword,
            'category' => $category,
            'section' => $section,
            'aspect_ratio' => '9:16',
        ]);

        $attempts = [];

        // 1) Naga.ac — excelente para vertical/Story quando configurado.
        $naga = new NagaImageService();
        if ($naga->isConfigured()) {
            $naga_model = (string)get_option('geo_naga_model', 'dall-e-3:free');
            if (false && strpos($naga_model, ':free') === false) {
                $naga_model = 'dall-e-3:free';
            }
            $url = $naga->generate($visual_prompt, '720x1280', $naga_model);
            $id = $this->try_sideload_result($url, $post_id, $label, 'naga_webstory', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'naga=nao_configurado';
        }

        // 2) HuggingFace — fallback vertical gratuito/baixo custo.
        $hf = new HuggingFaceImageService();
        if ($hf->is_configured()) {
            $url = $hf->generate($visual_prompt, '9:16');
            $id = $this->try_sideload_result($url, $post_id, $label, 'huggingface_webstory', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'huggingface=nao_configurado';
        }

        // 3) Fal.ai — entra também em Web Stories se configurado.
        $fal = new FalAIImageService();
        if ($fal->is_configured()) {
            $url = $fal->generate($visual_prompt, '1024x1792', 'medium');
            $id = $this->try_sideload_result($url, $post_id, $label, 'falai_webstory', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'falai=nao_configurado';
        }

        // 4) Replicate — também deve servir como fallback para Stories.
        $replicate = new ReplicateImageService();
        if ($replicate->is_configured()) {
            $url = $replicate->generate_schnell($visual_prompt, '9:16');
            $id = $this->try_sideload_result($url, $post_id, $label, 'replicate_webstory', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'replicate=nao_configurado';
        }

        // 5) Banco de fotos vertical.
        $stock_url = $this->stock_image_url(trim($section . ' ' . $keyword . ' ' . $category), 'portrait');
        if ($stock_url) {
            $id = $this->try_sideload_result($stock_url, $post_id, $label, 'stock_webstory', $attempts, 12582912);
            if ($id) return $id;
        } else {
            $attempts[] = 'stock=sem_resultado_ou_sem_chave';
        }

        // 6) Pollinations — último fallback para não abortar Story por imagem.
        $poll_url = $this->pollinations_url($visual_prompt, 720, 1280, 'story|' . $keyword . '|' . $title . '|' . $section);
        $id = $this->try_sideload_result($poll_url, $post_id, $label, 'pollinations_webstory', $attempts, 12582912);
        if ($id) return $id;

        LogService::record('webstories', 'warning', 'Imagem de Web Story falhou em todos os provedores. Tentativas: ' . implode(' | ', $attempts), [
            'post_id' => $post_id,
            'action' => 'webstory_image_failed_all',
            'context' => ['title' => $title, 'keyword' => $keyword, 'section' => $section],
        ]);
        return new \WP_Error('geo_webstory_image_failed_all', 'Imagem de Web Story falhou em todos os provedores.');
    }

    public function generate_web_story_url(array $context) {
        $id = $this->generate_story_attachment($context, 0);
        if (!is_wp_error($id) && $id) {
            return wp_get_attachment_url((int)$id) ?: '';
        }
        return '';
    }

    private function try_sideload_result($url, int $post_id, string $title, string $provider, array &$attempts, int $max_bytes = 12582912): int {
        if (is_wp_error($url)) {
            $attempts[] = $provider . '=' . $url->get_error_message();
            return 0;
        }
        if (!$url || !is_string($url)) {
            $attempts[] = $provider . '=sem_url';
            return 0;
        }
        $id = $this->sideload($url, $post_id, $title, $max_bytes);
        if ($id) {
            LogService::record('media', 'success', 'Imagem salva via ' . $provider, [
                'post_id' => $post_id,
                'action' => $provider . '_saved',
                'context' => ['attachment_id' => $id],
            ]);
            return $id;
        }
        $attempts[] = $provider . '=sideload_failed:' . SafeImageSideload::last_error();
        return 0;
    }

    private function sideload(string $url, int $post_id, string $title, int $max_bytes = 12582912): int {
        $id = SafeImageSideload::attachment_id($url, $post_id, $title, $max_bytes);
        if ($id) {
            update_post_meta($id, '_wp_attachment_image_alt', mb_substr(wp_strip_all_tags($title), 0, 160));
        }
        return (int)$id;
    }

    private function pollinations_url(string $prompt, int $width, int $height, string $seed_context = ''): string {
        $prompt = trim($prompt);
        if ($prompt === '') {
            $prompt = 'Photorealistic editorial image directly related to the article topic, realistic, no text, no logos, no watermark';
        }
        $wrapped = 'Photorealistic editorial image. Use only elements directly related to the article subject. ' . $prompt
            . '. Realistic scene, natural lighting, sharp focus, concrete relevant objects and actions only.'
            . ' Avoid generic unrelated office scenes, random people, abstract symbolism, fantasy elements and unrelated backgrounds.'
            . ' No text, no letters, no logos, no watermark.';
        $seed = abs(crc32($seed_context ?: $wrapped)) % 999999;
        return 'https://image.pollinations.ai/prompt/' . rawurlencode(mb_substr($wrapped, 0, 850))
            . '?width=' . absint($width)
            . '&height=' . absint($height)
            . '&nologo=true&model=flux&seed=' . $seed;
    }

    private function stock_image_url(string $query, string $orientation = 'landscape'): string {
        $query = $this->stock_query($query);
        if ($query === '') return '';

        $unsplash = get_option('geo_unsplash_api_key', '');
        if ($unsplash) {
            $r = wp_remote_get('https://api.unsplash.com/search/photos?' . http_build_query([
                'query' => $query,
                'per_page' => 5,
                'orientation' => $orientation === 'portrait' ? 'portrait' : 'landscape',
                'order_by' => 'relevant',
            ]), ['timeout' => 10, 'headers' => ['Authorization' => 'Client-ID ' . $unsplash]]);
            if (!is_wp_error($r)) {
                $data = json_decode(wp_remote_retrieve_body($r), true);
                $url = $data['results'][0]['urls']['regular'] ?? '';
                if ($url) return esc_url_raw($url);
            }
        }

        $pexels = get_option('geo_pexels_api_key', '');
        if ($pexels) {
            $r = wp_remote_get('https://api.pexels.com/v1/search?' . http_build_query([
                'query' => $query,
                'per_page' => 5,
                'orientation' => $orientation === 'portrait' ? 'portrait' : 'landscape',
            ]), ['timeout' => 10, 'headers' => ['Authorization' => $pexels]]);
            if (!is_wp_error($r)) {
                $data = json_decode(wp_remote_retrieve_body($r), true);
                $url = $data['photos'][0]['src']['large'] ?? '';
                if ($url) return esc_url_raw($url);
            }
        }

        $pixabay = get_option('geo_pixabay_api_key', '');
        if ($pixabay) {
            $r = wp_remote_get('https://pixabay.com/api/?' . http_build_query([
                'key' => $pixabay,
                'q' => $query,
                'image_type' => 'photo',
                'orientation' => $orientation === 'portrait' ? 'vertical' : 'horizontal',
                'per_page' => 5,
                'safesearch' => 'true',
            ]), ['timeout' => 10]);
            if (!is_wp_error($r)) {
                $data = json_decode(wp_remote_retrieve_body($r), true);
                $url = $data['hits'][0]['largeImageURL'] ?? '';
                if ($url) return esc_url_raw($url);
            }
        }

        return '';
    }

    private function stock_query(string $query): string {
        $query = strtolower(remove_accents(wp_strip_all_tags($query)));
        $query = preg_replace('/[^a-z0-9\s]+/', ' ', $query) ?? $query;
        $query = preg_replace('/\b(como|guia|o que e|qual|quais|para|sobre|artigo|imagem|foto|editorial|premium|high|quality|realistic|photorealistic|hero|section|context|exact|subject|related|topic)\b/u', ' ', $query) ?? $query;
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? $query);
        $words = array_values(array_filter(explode(' ', $query)));
        return trim(implode(' ', array_slice($words, 0, 7)));
    }

    private function add_failure_notice(string $message, int $post_id, string $keyword): void {
        $notices = (array) get_option('geo_image_failure_notices', []);
        $notices[] = [
            'time' => time(),
            'post_id' => absint($post_id),
            'keyword' => sanitize_text_field($keyword),
            'message' => sanitize_text_field($message),
        ];
        update_option('geo_image_failure_notices', array_slice($notices, -20), false);
    }
}
