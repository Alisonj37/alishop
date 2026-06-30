<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * ZernioSocialService
 *
 * Integração com a API Zernio (https://zernio.com) para publicar/agendar posts
 * em múltiplas redes sociais a partir de um artigo do WordPress.
 *
 * Autenticação: Bearer token (geo_zernio_api_key). Sem OAuth.
 * Endpoint: https://zernio.com/api/v1/posts
 *
 * O plugin NÃO publica nada automaticamente sem ação do usuário — o usuário
 * clica em "Compartilhar nas redes" para um post específico.
 */
class ZernioSocialService {

    private const API_BASE = 'https://zernio.com/api/v1';

    public static function is_configured(): bool {
        return trim((string) get_option('geo_zernio_api_key', '')) !== '';
    }

    private static function api_key(): string {
        return trim((string) get_option('geo_zernio_api_key', ''));
    }

    /**
     * Lista as contas sociais conectadas na Zernio.
     * Retorna ['success'=>bool, 'accounts'=>[...], 'message'=>...]
     */
    public static function get_accounts(): array {
        if (!self::is_configured()) {
            return ['success' => false, 'message' => 'API Key da Zernio não configurada.'];
        }
        $resp = wp_remote_get(self::API_BASE . '/accounts', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . self::api_key(),
                'Content-Type'  => 'application/json',
            ],
        ]);
        if (is_wp_error($resp)) {
            return ['success' => false, 'message' => 'Erro de conexão: ' . $resp->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200) {
            $msg = is_array($body) && !empty($body['message']) ? $body['message'] : ('HTTP ' . $code);
            return ['success' => false, 'message' => 'Zernio: ' . $msg];
        }
        // A API pode retornar 'accounts' ou 'data' dependendo da versão
        $accounts = $body['accounts'] ?? $body['data'] ?? (is_array($body) ? $body : []);
        return ['success' => true, 'accounts' => $accounts];
    }

    /**
     * Publica/agenda um post a partir de um artigo do WordPress.
     *
     * @param int    $post_id      ID do artigo
     * @param array  $platforms    Array de plataformas: [['platform'=>'twitter','accountId'=>'acc_x'], ...]
     * @param string $custom_text  Texto opcional (se vazio, gera a partir do post)
     * @param string $scheduled_at ISO 8601 opcional para agendar; vazio = publicar agora
     */
    public static function share_post(int $post_id, array $platforms, string $custom_text = '', string $scheduled_at = ''): array {
        if (!self::is_configured()) {
            return ['success' => false, 'message' => 'API Key da Zernio não configurada.'];
        }
        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'message' => 'Post não encontrado.'];
        }
        if (empty($platforms)) {
            return ['success' => false, 'message' => 'Nenhuma plataforma/conta selecionada.'];
        }

        $content = $custom_text !== '' ? $custom_text : self::build_social_text($post);
        $link    = get_permalink($post_id);

        $payload = [
            'content'   => $content,
            'platforms' => array_values($platforms),
        ];
        // Anexar o link do artigo
        if ($link) {
            $payload['link'] = $link;
        }
        // Imagem destacada como mídia, se houver
        $thumb = get_the_post_thumbnail_url($post_id, 'large');
        if ($thumb) {
            $payload['media'] = [['type' => 'image', 'url' => $thumb]];
        }
        // Agendamento opcional
        if ($scheduled_at !== '') {
            $payload['scheduled_for'] = $scheduled_at;
            $payload['timezone'] = wp_timezone_string();
        }

        $resp = wp_remote_post(self::API_BASE . '/posts', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . self::api_key(),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'message' => 'Erro de conexão: ' . $resp->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code >= 200 && $code < 300) {
            $zernio_id = $body['post']['_id'] ?? $body['id'] ?? '';
            update_post_meta($post_id, '_geo_zernio_shared', '1');
            update_post_meta($post_id, '_geo_zernio_post_id', sanitize_text_field((string)$zernio_id));
            if (class_exists('\\GeoMetodoSEO\\Services\\LogService')) {
                LogService::record('social', 'success',
                    'Post compartilhado nas redes via Zernio (' . count($platforms) . ' plataformas)',
                    ['action' => 'zernio_shared', 'post_id' => $post_id,
                     'context' => ['platforms' => count($platforms), 'scheduled' => $scheduled_at !== '']]);
            }
            return [
                'success'   => true,
                'message'   => $scheduled_at !== '' ? 'Post agendado nas redes sociais!' : 'Post publicado nas redes sociais!',
                'zernio_id' => $zernio_id,
            ];
        }

        $msg = is_array($body) && !empty($body['message']) ? $body['message'] : ('HTTP ' . $code);
        if (class_exists('\\GeoMetodoSEO\\Services\\LogService')) {
            LogService::record('social', 'error', 'Falha ao compartilhar via Zernio: ' . $msg,
                ['action' => 'zernio_share_failed', 'post_id' => $post_id]);
        }
        return ['success' => false, 'message' => 'Zernio: ' . $msg];
    }

    /**
     * Monta o texto social a partir do post (título + resumo + sem HTML).
     */
    private static function build_social_text(\WP_Post $post): string {
        $title   = get_the_title($post);
        $excerpt = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(wp_strip_all_tags($post->post_content), 30, '');
        $text = $title;
        if ($excerpt) {
            $text .= "\n\n" . $excerpt;
        }
        // Tags como hashtags (até 3)
        $tags = get_the_tags($post->ID);
        if ($tags && !is_wp_error($tags)) {
            $hashtags = [];
            foreach (array_slice($tags, 0, 3) as $tag) {
                $hashtags[] = '#' . preg_replace('/\s+/', '', $tag->name);
            }
            if ($hashtags) $text .= "\n\n" . implode(' ', $hashtags);
        }
        return mb_substr($text, 0, 2500);
    }
}
