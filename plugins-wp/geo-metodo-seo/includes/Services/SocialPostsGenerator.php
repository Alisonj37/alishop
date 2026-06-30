<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * SocialPostsGenerator
 *
 * Gera posts de redes sociais SEPARADOS (um por rede) a partir de um artigo
 * publicado. Cada post é otimizado para a rede, inclui SEO/GEO, o link do
 * artigo como referência e a imagem destacada do artigo.
 *
 * Econômico: gera TODAS as redes em UMA ÚNICA chamada de IA (não uma por rede).
 *
 * Redes suportadas: Facebook, Instagram, X (Twitter), LinkedIn, Reddit, Pinterest.
 */
class SocialPostsGenerator {

    /** Redes suportadas e suas características */
    private const NETWORKS = [
        'facebook'  => ['label' => 'Facebook',  'limit' => 'até 400 palavras, tom conversacional, 2-3 hashtags'],
        'instagram' => ['label' => 'Instagram', 'limit' => 'até 150 palavras, emojis, 8-12 hashtags no final, primeira linha como gancho'],
        'twitter'   => ['label' => 'X (Twitter)', 'limit' => 'até 270 caracteres, direto, 1-2 hashtags'],
        'linkedin'  => ['label' => 'LinkedIn',  'limit' => 'até 300 palavras, tom profissional, insight de valor, 3-5 hashtags'],
        'reddit'    => ['label' => 'Reddit',    'limit' => 'título + corpo, tom autêntico sem marketing, sem hashtags, agrega valor à comunidade'],
        'pinterest' => ['label' => 'Pinterest', 'limit' => 'até 100 palavras, descritivo e inspiracional, 3-5 hashtags, foco visual'],
    ];

    /**
     * Gera posts sociais para todas as redes a partir de um post.
     * @return array ['success'=>bool, 'posts'=>['facebook'=>['text'=>...,'hashtags'=>...], ...], 'image'=>url, 'link'=>url]
     */
    public static function generate(int $post_id, array $networks = []): array {
        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'message' => 'Post não encontrado.'];
        }

        $networks = empty($networks) ? array_keys(self::NETWORKS) : array_intersect($networks, array_keys(self::NETWORKS));
        if (empty($networks)) {
            return ['success' => false, 'message' => 'Nenhuma rede válida selecionada.'];
        }

        $title   = get_the_title($post_id);
        $link    = get_permalink($post_id);
        $excerpt = has_excerpt($post_id) ? get_the_excerpt($post_id) : wp_trim_words(wp_strip_all_tags($post->post_content), 60, '');
        $keyword = get_post_meta($post_id, '_geo_focus_keyword', true) ?: $title;
        $image   = get_the_post_thumbnail_url($post_id, 'large') ?: '';

        // Idioma: usa o idioma em que o post foi gerado, senão o padrão do plugin.
        $post_lang = get_post_meta($post_id, '_geo_language', true);
        $lang_code = $post_lang ?: get_option('geo_default_language', 'pt-BR');
        $lang_map = [
            'pt-BR' => 'português brasileiro', 'pt-PT' => 'português de Portugal',
            'en' => 'inglês', 'en-US' => 'inglês americano', 'es' => 'espanhol',
            'fr' => 'francês', 'de' => 'alemão', 'it' => 'italiano',
        ];
        $lang_name = $lang_map[$lang_code] ?? ($lang_map[explode('-', $lang_code)[0]] ?? 'português brasileiro');

        // Montar o prompt — UMA chamada para todas as redes (econômico)
        $net_instructions = '';
        foreach ($networks as $net) {
            $net_instructions .= "- {$net}: " . self::NETWORKS[$net]['limit'] . "\n";
        }

        $prompt = "Você é especialista em social media e SEO/GEO. A partir do artigo abaixo, "
            . "crie um post de rede social SEPARADO e OTIMIZADO para cada rede solicitada.\n\n"
            . "ARTIGO:\n"
            . "Título: {$title}\n"
            . "Palavra-chave de foco: {$keyword}\n"
            . "Resumo: {$excerpt}\n"
            . "Link do artigo: {$link}\n\n"
            . "REGRAS:\n"
            . "1. Cada post deve ser ÚNICO e adaptado ao estilo da rede (não repita o mesmo texto).\n"
            . "2. Inclua a palavra-chave de foco de forma natural (SEO/GEO).\n"
            . "3. SEMPRE mencione que há um artigo completo e inclua o link: {$link}\n"
            . "4. Escreva em {$lang_name}.\n"
            . "5. Não invente dados, números ou fatos que não estejam no resumo.\n"
            . "6. Hashtags relevantes ao tema (não genéricas).\n\n"
            . "REDES E LIMITES:\n{$net_instructions}\n"
            . "FORMATO DE RESPOSTA — retorne SOMENTE JSON válido, sem markdown, neste formato:\n"
            . "{\n";
        $json_fields = [];
        foreach ($networks as $net) {
            $json_fields[] = "  \"{$net}\": \"texto completo do post para {$net}, com link e hashtags incluídos\"";
        }
        $prompt .= implode(",\n", $json_fields) . "\n}";

        // Chamada única
        $ai = new \GeoMetodoSEO\AI\AIManager();
        $provider = \GeoMetodoSEO\AI\ProviderResolver::for('individual_generation');
        $model    = \GeoMetodoSEO\AI\ProviderResolver::modelFor('individual_generation', $provider);

        $response = $ai->generateText($prompt, $provider, $model ?: null);
        if (!$response || $response->hasError()) {
            return ['success' => false, 'message' => 'Falha na IA: ' . ($response ? $response->getError() : 'sem resposta')];
        }

        $raw = trim((string)$response->getContent());
        $raw = preg_replace('/^```json\s*/i', '', $raw);
        $raw = preg_replace('/```\s*$/i', '', trim($raw));
        $data = json_decode($raw, true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $raw, $m)) {
            $data = json_decode($m[0], true);
        }
        if (!is_array($data)) {
            return ['success' => false, 'message' => 'A IA não retornou um JSON válido. Tente novamente.'];
        }

        // Montar resultado limpo
        $posts = [];
        foreach ($networks as $net) {
            if (!empty($data[$net])) {
                $posts[$net] = [
                    'label' => self::NETWORKS[$net]['label'],
                    'text'  => trim((string)$data[$net]),
                ];
            }
        }

        if (empty($posts)) {
            return ['success' => false, 'message' => 'Nenhum post foi gerado. Tente novamente.'];
        }

        if (class_exists('\\GeoMetodoSEO\\Services\\LogService')) {
            LogService::record('social', 'success',
                'Posts sociais gerados para post #' . $post_id . ' (' . count($posts) . ' redes, 1 chamada de IA)',
                ['action' => 'social_posts_generated', 'post_id' => $post_id]);
        }

        return [
            'success' => true,
            'posts'   => $posts,
            'image'   => $image,
            'link'    => $link,
            'title'   => $title,
        ];
    }
}
