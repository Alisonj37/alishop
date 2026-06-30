<?php
namespace GeoMetodoSEO\Services;

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;

if (!defined('ABSPATH')) exit;

/**
 * Gera prompts visuais em ingles usando Groq antes de chamar providers de imagem.
 */
class ImagePromptGenerator {

    public function generate(array $context): string {
        $title    = sanitize_text_field((string)($context['title'] ?? ''));
        $keyword  = sanitize_text_field((string)($context['keyword'] ?? ''));
        $category = sanitize_text_field((string)($context['category'] ?? ''));
        $section  = sanitize_text_field((string)($context['section'] ?? ''));
        $type     = sanitize_key((string)($context['type'] ?? 'body'));
        $ratio    = sanitize_text_field((string)($context['aspect_ratio'] ?? '16:9'));

        $base = trim($title . ' ' . $keyword . ' ' . $category . ' ' . $section);
        $cache_key = 'geo_visual_prompt_' . md5($type . '|' . $ratio . '|' . $base);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // 1.0.0 PERFORMANCE FIX: fallback determinístico é DEFAULT (rápido, 0ms IA).
        // Groq agora é OPT-IN via option 'geo_use_groq_for_image_prompt' (default '0').
        // Motivo: na v1.0.0 cada imagem disparava chamada Groq = 1 featured + 3 body = 4 calls
        // extras de IA por artigo. Isso tornava cada artigo lento e gastava cota.
        // O fallback determinístico é tão bom quanto o Groq pra prompts visuais.
        $use_groq = (string) get_option('geo_use_groq_for_image_prompt', '0') === '1';

        if ($type === 'web_story' || !$use_groq) {
            $fallback = $this->fallback_prompt($title, $keyword, $category, $section, $type, $ratio);
            set_transient($cache_key, $fallback, 12 * HOUR_IN_SECONDS);
            return $fallback;
        }

        $instruction = 'Create ONE concise English image-generation prompt for a WordPress article. '
            . 'The image must match the focus keyword, H1/title, category and section context. '
            . 'Use concrete real-world visual details, avoid generic unrelated scenes, no text, no letters, no logos, no watermark. '
            . 'Return only the prompt, no markdown.';

        $prompt = $instruction . "\n\n"
            . 'Image type: ' . $type . "\n"
            . 'Aspect ratio: ' . $ratio . "\n"
            . 'H1/title: ' . $title . "\n"
            . 'Focus keyword: ' . $keyword . "\n"
            . 'Category: ' . $category . "\n"
            . 'Section/H2 context: ' . $section;

        try {
            $ai = new AIManager();
            $provider = ProviderResolver::for('image_prompt');
            $model = ProviderResolver::modelFor('image_prompt', $provider, 'llama-3.3-70b-versatile');
            $response = $ai->generateText($prompt, $provider, $model);
            if (is_object($response) && method_exists($response, 'hasError') && !$response->hasError()) {
                $text = trim((string)$response->getContent());
                $text = trim(preg_replace('/^```[a-z]*|```$/i', '', $text));
                $text = sanitize_text_field($text);
                if ($text !== '') {
                    $text = $this->normalize_prompt($text, $type, $ratio);
                    set_transient($cache_key, $text, 12 * HOUR_IN_SECONDS);
                    return $text;
                }
            }
        } catch (\Throwable $e) {
            LogService::record('media', 'warning', 'Groq nao gerou prompt visual: ' . $e->getMessage(), [
                'action' => 'visual_prompt_groq_failed',
            ]);
        }

        $fallback = $this->fallback_prompt($title, $keyword, $category, $section, $type, $ratio);
        set_transient($cache_key, $fallback, HOUR_IN_SECONDS);
        return $fallback;
    }

    private function fallback_prompt(string $title, string $keyword, string $category, string $section, string $type, string $ratio): string {
        $subject = trim($keyword !== '' ? $keyword : $title);
        $context = trim($section !== '' ? $section : $category);
        $scene = $this->build_contextual_scene($subject, $title, $category, $section, $type);

        $prompt = $scene;
        if ($title !== '') {
            $prompt .= '. Article title: "' . $title . '"';
        }
        if ($subject !== '') {
            $prompt .= '. Exact article subject: "' . $subject . '"';
        }
        if ($context !== '') {
            $prompt .= '. Section context: "' . $context . '"';
        }

        $prompt .= '. The image must be photorealistic, editorial and directly related to the article topic.';
        $prompt .= ' Show only concrete relevant objects, people, interfaces or actions that truly match the subject.';
        $prompt .= ' Avoid generic office scenes unless the topic is really about office work.';
        $prompt .= ' Avoid fantasy, abstract symbolism, unrelated landscapes, unrelated animals, unrelated people or random decorative elements.';
        $prompt .= ' High detail, sharp focus, natural lighting, realistic textures, professional composition.';

        return $this->normalize_prompt($prompt, $type, $ratio);
    }

    private function normalize_prompt(string $prompt, string $type, string $ratio): string {
        $prompt = preg_replace('/\s+/u', ' ', trim($prompt)) ?: trim($prompt);
        $base_negative = 'no text, no letters, no logos, no watermark';
        if (stripos($prompt, 'no text') === false) {
            $prompt .= ', ' . $base_negative;
        }
        if ($type === 'featured' && stripos($prompt, 'hero image') === false) {
            $prompt .= ', realistic editorial hero image';
        }
        if ($type === 'body' && stripos($prompt, 'section-specific') === false) {
            $prompt .= ', realistic section-specific editorial image';
        }
        if ($type === 'web_story' && stripos($prompt, 'portrait') === false) {
            $prompt .= ', vertical portrait composition for a Web Story with one clear main subject';
        }
        if (stripos($prompt, 'photorealistic') === false) {
            $prompt .= ', photorealistic';
        }
        if (stripos($prompt, 'aspect ratio') === false && stripos($prompt, $ratio) === false) {
            $prompt .= ', aspect ratio ' . $ratio;
        }
        return mb_substr($prompt, 0, 1100);
    }

    private function build_contextual_scene(string $subject, string $title, string $category, string $section, string $type): string {
        $ctx = mb_strtolower(remove_accents(trim($subject . ' ' . $title . ' ' . $category . ' ' . $section)));
        $lead = $type === 'featured'
            ? 'Photorealistic editorial hero image'
            : ($type === 'web_story' ? 'Photorealistic editorial vertical image' : 'Photorealistic editorial image');

        if (preg_match('/xiaomi|redmi|poco|android|smartphone|celular|telefone|iphone|samsung|motorola|galaxy|pixel/u', $ctx)) {
            return $lead . ' showing a real smartphone context directly related to the article, such as device comparison, settings screen, software update, camera test, battery test or buying decision scene';
        }
        if (preg_match('/seo|geo|aeo|llm|rank math|wordpress|google|blog|conteudo|content marketing|marketing de conteudo/u', $ctx)) {
            return $lead . ' showing a real digital marketing or SEO workflow, such as analytics dashboard, content planning, website optimization, search results analysis or editorial strategy scene';
        }
        if (preg_match('/instagram|youtube|tiktok|social media|trafego|anuncio|ads|afiliado|copy/u', $ctx)) {
            return $lead . ' showing a real creator or marketer workflow, such as content production, campaign analysis, video creation, social media management or digital business activity';
        }
        if (preg_match('/carro|veiculo|automovel|moto|honda|toyota|fiat|chevrolet|hyundai|renault/u', $ctx)) {
            return $lead . ' showing the real vehicle or driving context of the article, such as car exterior, dashboard, maintenance, comparison or road use scene';
        }
        if (preg_match('/saude|health|dieta|emagrecimento|fitness|treino|nutricao|suplemento/u', $ctx)) {
            return $lead . ' showing a realistic health, fitness or nutrition context directly related to the article, such as exercise, meal planning, wellness consultation or product usage';
        }
        if (preg_match('/dinheiro|financas|renda extra|investimento|cartao|emprestimo|cripto|bitcoin/u', $ctx)) {
            return $lead . ' showing a realistic personal finance context, such as budgeting, investment analysis, money management, online income workflow or banking interface';
        }
        if (preg_match('/viagem|hotel|turismo|passagem|aeroporto/u', $ctx)) {
            return $lead . ' showing a realistic travel context, such as airport, hotel, route planning, luggage or destination activity directly matching the article';
        }

        $focus = trim($section !== '' ? $section : ($subject !== '' ? $subject : $title));
        return $lead . ' directly illustrating the real topic of the article with concrete elements related to "' . $focus . '"';
    }

}

