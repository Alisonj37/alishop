<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * YouTubeVideoFinder
 *
 * Busca um vídeo do YouTube RELACIONADO ao título/keyword do artigo e devolve
 * o HTML de embed responsivo. Garante relevância:
 *   - busca pela keyword de foco (ou título)
 *   - filtra por relevância textual entre o título do vídeo e a keyword
 *   - descarta vídeos sem nenhuma palavra em comum com o tema (evita off-topic)
 *
 * Usa a YouTube Data API v3 (geo_youtube_api_key). Sem chave → retorna ''.
 */
class YouTubeVideoFinder {

    private const SEARCH_ENDPOINT = 'https://www.googleapis.com/youtube/v3/search';
    private const CACHE_PREFIX    = 'geo_yt_find_';
    private const CACHE_TTL       = 86400; // 24h

    /**
     * Retorna o HTML de embed de um vídeo relacionado, ou '' se nada relevante.
     */
    public static function get_embed_html(string $keyword, string $title = '', string $language = 'pt'): string {
        $video = self::find_relevant_video($keyword, $title, $language);
        if (empty($video['id'])) return '';

        return self::build_embed_html($video['id'], $video['title'] ?? $title ?: $keyword);
    }

    /**
     * Encontra o vídeo mais relevante. Retorna ['id'=>..., 'title'=>...] ou [].
     */
    public static function find_relevant_video(string $keyword, string $title = '', string $language = 'pt'): array {
        $api_key = trim((string) get_option('geo_youtube_api_key', ''));
        if ($api_key === '') return [];

        $query = trim($keyword ?: $title);
        if ($query === '') return [];

        // Limpar a query: a API rejeita (HTTP 400) queries com aspas não balanceadas
        // ou certos caracteres. Manter só texto útil para a busca.
        $query = str_replace(['"', "'", '`', '“', '”', '‘', '’'], '', $query);
        $query = preg_replace('/\s+/u', ' ', $query);
        $query = trim(mb_substr($query, 0, 100)); // YouTube limita o tamanho da query
        if ($query === '') return [];

        // Cache por query
        $cache_key = self::CACHE_PREFIX . md5($query . '|' . $language);
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        // relevanceLanguage precisa ser um código ISO-639-1 de 2 letras válido.
        // 'pt-BR' → 'pt'. Se vier algo inválido, omitir o parâmetro (não mandar vazio).
        $rel_lang = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $language), 0, 2));
        $valid_langs = ['pt','en','es','fr','de','it','ru','ja','ko','zh','ar','hi','nl','pl','tr'];
        if (!in_array($rel_lang, $valid_langs, true)) {
            $rel_lang = '';
        }

        $params = [
            'part'            => 'snippet',
            'q'               => $query,
            'type'            => 'video',
            'maxResults'      => 5,
            'safeSearch'      => 'moderate',
            'videoEmbeddable' => 'true',
            'order'           => 'relevance',
            'key'             => $api_key,
        ];
        // Só incluir relevanceLanguage se for válido (evita HTTP 400)
        if ($rel_lang !== '') {
            $params['relevanceLanguage'] = $rel_lang;
        }
        $url = self::SEARCH_ENDPOINT . '?' . http_build_query($params);

        $response = wp_remote_get($url, ['timeout' => 12]);
        if (is_wp_error($response)) {
            self::log('warning', 'YouTubeVideoFinder: falha de rede — ' . $response->get_error_message());
            return [];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            // Capturar a mensagem REAL de erro da API para diagnóstico.
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $reason = '';
            $detail = '';
            if (isset($body['error'])) {
                $detail = $body['error']['message'] ?? '';
                $reason = $body['error']['errors'][0]['reason'] ?? '';
            }
            // Mensagem clara conforme o tipo de erro
            $hint = '';
            if ($reason === 'quotaExceeded' || stripos($detail, 'quota') !== false) {
                $hint = ' — Cota diária da YouTube API esgotada. Aguarde 24h ou aumente a cota no Google Cloud.';
            } elseif ($reason === 'keyInvalid' || stripos($detail, 'API key not valid') !== false) {
                $hint = ' — Chave de API inválida. Verifique geo_youtube_api_key.';
            } elseif ($reason === 'accessNotConfigured' || stripos($detail, 'has not been used') !== false || stripos($detail, 'disabled') !== false) {
                $hint = ' — A "YouTube Data API v3" NÃO está ativada no seu projeto do Google Cloud. Ative em console.cloud.google.com → APIs e Serviços → Biblioteca → YouTube Data API v3 → Ativar.';
            } elseif ($reason === 'ipRefererBlocked' || stripos($detail, 'referer') !== false || stripos($detail, 'blocked') !== false) {
                $hint = ' — A chave tem restrição de IP/referer que bloqueia o servidor. Remova as restrições da chave ou libere o IP do site.';
            }
            self::log('warning', 'YouTubeVideoFinder: HTTP ' . $code . ' na busca de vídeo' . $hint
                . ($detail ? ' [API: ' . mb_substr($detail, 0, 200) . ']' : ''));
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $items = $data['items'] ?? [];
        if (empty($items)) {
            set_transient($cache_key, [], self::CACHE_TTL);
            return [];
        }

        // Selecionar o vídeo MAIS relevante por sobreposição de termos.
        $best = self::pick_most_relevant($items, $query);
        if (empty($best)) {
            set_transient($cache_key, [], self::CACHE_TTL);
            return [];
        }

        set_transient($cache_key, $best, self::CACHE_TTL);
        return $best;
    }

    /**
     * Escolhe o vídeo cujo título tem maior sobreposição de palavras com a query.
     * Descarta vídeos sem nenhuma palavra significativa em comum (off-topic).
     */
    private static function pick_most_relevant(array $items, string $query): array {
        $query_terms = self::significant_terms($query);
        if (empty($query_terms)) {
            // Sem termos significativos — usar o 1º resultado do YouTube (já ordenado por relevância)
            $first = $items[0] ?? null;
            if ($first && !empty($first['id']['videoId'])) {
                return [
                    'id'    => sanitize_text_field($first['id']['videoId']),
                    'title' => sanitize_text_field($first['snippet']['title'] ?? ''),
                ];
            }
            return [];
        }

        $best = [];
        $best_score = 0;
        foreach ($items as $item) {
            $vid = $item['id']['videoId'] ?? '';
            if (!$vid) continue;
            $vtitle = (string) ($item['snippet']['title'] ?? '');
            $vdesc  = (string) ($item['snippet']['description'] ?? '');
            $video_terms = self::significant_terms($vtitle . ' ' . $vdesc);

            $overlap = count(array_intersect($query_terms, $video_terms));
            if ($overlap > $best_score) {
                $best_score = $overlap;
                $best = [
                    'id'    => sanitize_text_field($vid),
                    'title' => sanitize_text_field($vtitle),
                ];
            }
        }

        // Exigir ao menos 1 termo significativo em comum — senão é off-topic.
        if ($best_score < 1) return [];
        return $best;
    }

    /**
     * Extrai termos significativos (remove stopwords e palavras curtas).
     */
    private static function significant_terms(string $text): array {
        $text = mb_strtolower(wp_strip_all_tags($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $words = preg_split('/\s+/u', trim($text)) ?: [];

        $stopwords = [
            'a','o','as','os','de','da','do','das','dos','e','em','um','uma','para','por','com','que',
            'no','na','nos','nas','se','ao','aos','à','às','the','of','to','and','in','for','on','is',
            'como','qual','quais','sobre','mais','seu','sua','está','são','foi','ser','tem','vai',
        ];
        $terms = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 4) continue;
            if (in_array($w, $stopwords, true)) continue;
            $terms[] = $w;
        }
        return array_values(array_unique($terms));
    }

    /**
     * HTML de embed responsivo (16:9) com lazy loading.
     */
    public static function build_embed_html(string $video_id, string $label = ''): string {
        $video_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $video_id);
        if ($video_id === '') return '';
        $label = $label !== '' ? esc_attr($label) : 'Vídeo relacionado';

        return "\n<figure class=\"geo-youtube-embed\" style=\"margin:32px 0;\">\n"
            . "<div style=\"position:relative;width:100%;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:12px;\">\n"
            . "<iframe src=\"https://www.youtube.com/embed/{$video_id}\" "
            . "title=\"{$label}\" "
            . "style=\"position:absolute;top:0;left:0;width:100%;height:100%;border:0;\" "
            . "loading=\"lazy\" allowfullscreen "
            . "allow=\"accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture\"></iframe>\n"
            . "</div>\n</figure>\n";
    }

    private static function log(string $level, string $message): void {
        if (class_exists('\\GeoMetodoSEO\\Services\\LogService')) {
            \GeoMetodoSEO\Services\LogService::record('media', $level, $message, ['action' => 'youtube_video_finder']);
        }
    }
}
