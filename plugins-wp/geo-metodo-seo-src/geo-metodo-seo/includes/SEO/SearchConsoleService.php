<?php

namespace GeoMetodoSEO\SEO;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Config\ConfigManager;

/**
 * SearchConsoleService — Integração com Google Search Console API.
 * Usa OAuth2 para autenticar e Search Analytics API para buscar dados.
 *
 * @since 1.0.0
 */
class SearchConsoleService {

    const TOKEN_OPTION  = 'geo_gsc_token';
    const CLIENT_OPTION = 'geo_gsc_client';
    const ERROR_OPTION  = 'geo_gsc_last_error';   // 1.0.0 — debug
    const CACHE_TTL     = 3600; // 1 hora

    /**
     * Retorna URL de autorização OAuth2 do Google.
     */
    public function get_auth_url(): string {
        $client_id    = get_option('geo_gsc_client_id', '');
        $redirect_uri = admin_url('admin.php?page=geo-settings&gsc_callback=1');

        $params = http_build_query([
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/webmasters.readonly',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);

        return 'https://accounts.google.com/o/oauth2/auth?' . $params;
    }

    /**
     * Salvar erro para diagnóstico (acessível via get_last_error).
     * @since 1.0.0
     */
    private function log_error(string $where, string $message): void {
        update_option(self::ERROR_OPTION, [
            'where'   => $where,
            'message' => $message,
            'time'    => current_time('mysql'),
        ], false);

        // 1.0.0: log no sistema unificado com módulo gsc
        if (class_exists('\GeoMetodoSEO\Services\LogService')) {
            \GeoMetodoSEO\Services\LogService::record(
                'gsc', 'error', $message,
                ['action' => $where]
            );
        }

        if (class_exists('\GeoMetodoSEO\Autopilot\Shared\AutopilotLogger')) {
            \GeoMetodoSEO\Autopilot\Shared\AutopilotLogger::log(
                'system', 'gsc_' . $where, 'error', $message
            );
        }
    }

    /**
     * Recupera o último erro do GSC para exibir no admin.
     * @since 1.0.0
     */
    public function get_last_error(): ?array {
        $err = get_option(self::ERROR_OPTION, null);
        return is_array($err) ? $err : null;
    }

    /**
     * Limpa erro registrado.
     * @since 1.0.0
     */
    public function clear_last_error(): void {
        delete_option(self::ERROR_OPTION);
    }

    /**
     * Troca o code OAuth pelo access_token e refresh_token.
     * Chamar após o callback do Google.
     */
    public function handle_callback( string $code ): bool {
        $client_id     = get_option('geo_gsc_client_id', '');
        $client_secret = get_option('geo_gsc_client_secret', '');
        $redirect_uri  = admin_url('admin.php?page=geo-settings&gsc_callback=1');

        if (empty($client_id) || empty($client_secret)) {
            $this->log_error('callback', 'Client ID/Secret OAuth não preenchidos.');
            return false;
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 20,
            'body'    => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->log_error('callback', 'WP HTTP error: ' . $response->get_error_message());
            return false;
        }

        $code_http = wp_remote_retrieve_response_code($response);
        $data      = json_decode(wp_remote_retrieve_body($response), true);

        if ($code_http !== 200 || empty($data['access_token'])) {
            $err = $data['error'] ?? 'unknown';
            $desc = $data['error_description'] ?? "HTTP {$code_http}";
            $msg  = "Google retornou: {$err} — {$desc}";

            // Mensagens mais amigáveis para erros comuns
            if ($err === 'redirect_uri_mismatch') {
                $msg .= " | URI esperado: {$redirect_uri} — verifique se está EXATAMENTE igual no Google Cloud Console (sem espaços extras, sem barra final diferente).";
            } elseif ($err === 'invalid_grant') {
                $msg .= " | O code expirou ou já foi usado. Tente conectar de novo.";
            } elseif ($err === 'invalid_client') {
                $msg .= " | Client ID ou Secret incorretos.";
            }

            $this->log_error('callback', $msg);
            return false;
        }

        update_option(self::TOKEN_OPTION, [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_at'    => time() + ($data['expires_in'] ?? 3600),
        ]);

        $this->clear_last_error();

        // 1.0.0
        if (class_exists('\GeoMetodoSEO\Services\LogService')) {
            \GeoMetodoSEO\Services\LogService::record(
                'gsc', 'success', 'Conectado ao Google Search Console com sucesso',
                ['action' => 'connect']
            );
        }

        return true;
    }

    /**
     * Remove token (desconectar GSC).
     */
    public function disconnect(): void {
        delete_option(self::TOKEN_OPTION);
        delete_transient('geo_gsc_top_pages');
        $this->clear_last_error();
    }

    /**
     * Renova access_token usando refresh_token.
     */
    private function refresh_token(): bool {
        $token = get_option(self::TOKEN_OPTION, []);

        if (empty($token['refresh_token'])) {
            $this->log_error('refresh', 'Sem refresh_token — refaça a conexão clicando em "Conectar com Google" (com prompt=consent).');
            return false;
        }

        $client_id     = get_option('geo_gsc_client_id', '');
        $client_secret = get_option('geo_gsc_client_secret', '');

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 20,
            'body'    => [
                'refresh_token' => $token['refresh_token'],
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->log_error('refresh', 'WP HTTP error: ' . $response->get_error_message());
            return false;
        }

        $code_http = wp_remote_retrieve_response_code($response);
        $data      = json_decode(wp_remote_retrieve_body($response), true);

        if ($code_http !== 200 || empty($data['access_token'])) {
            $err  = $data['error'] ?? 'unknown';
            $desc = $data['error_description'] ?? "HTTP {$code_http}";
            $this->log_error('refresh', "Google: {$err} — {$desc}");
            return false;
        }

        $token['access_token'] = $data['access_token'];
        $token['expires_at']   = time() + ($data['expires_in'] ?? 3600);
        update_option(self::TOKEN_OPTION, $token);

        return true;
    }

    /**
     * Retorna access_token válido (renova se necessário).
     */
    private function get_valid_token(): string {
        $token = get_option(self::TOKEN_OPTION, []);

        if (empty($token['access_token'])) return '';

        // Renovar se expira em menos de 5 minutos
        if (($token['expires_at'] ?? 0) < time() + 300) {
            if (!$this->refresh_token()) return '';
            $token = get_option(self::TOKEN_OPTION, []);
        }

        return $token['access_token'] ?? '';
    }

    /**
     * Busca top páginas com impressões, cliques, CTR e posição.
     */
    public function get_top_pages( int $days = 28, int $limit = 50 ): array {
        $access_token = $this->get_valid_token();

        if (empty($access_token)) {
            $this->log_error('top_pages', 'Sem access_token válido — verifique se está conectado.');
            return [];
        }

        $site_url   = get_site_url();
        $end_date   = date('Y-m-d', strtotime('-3 days'));
        $start_date = date('Y-m-d', strtotime('-' . ($days + 3) . ' days'));

        $property = get_option('geo_gsc_property', $site_url . '/');

        $body = [
            'startDate'  => $start_date,
            'endDate'    => $end_date,
            'dimensions' => ['page'],
            'rowLimit'   => $limit,
            'orderBy'    => [['fieldName' => 'impressions', 'sortOrder' => 'DESCENDING']],
        ];

        $response = wp_remote_post(
            'https://searchconsole.googleapis.com/webmasters/v3/sites/'
            . urlencode($property) . '/searchAnalytics/query',
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode($body),
            ]
        );

        if (is_wp_error($response)) {
            $this->log_error('top_pages', 'WP HTTP error: ' . $response->get_error_message());
            return [];
        }

        $code_http = wp_remote_retrieve_response_code($response);
        $data      = json_decode(wp_remote_retrieve_body($response), true);

        if ($code_http !== 200) {
            $msg = $data['error']['message'] ?? "HTTP {$code_http}";
            // Erros comuns
            if (str_contains(strtolower($msg), 'permission')) {
                $msg .= " | Verifique se o usuário OAuth tem acesso à propriedade {$property} no GSC.";
            }
            if (str_contains(strtolower($msg), 'does not match')) {
                $msg .= " | A propriedade '{$property}' não foi encontrada. Confirme em GEO → Configurações.";
            }
            $this->log_error('top_pages', $msg);
            return [];
        }

        $rows = $data['rows'] ?? [];

        $result = [];
        foreach ($rows as $row) {
            $page_url = $row['keys'][0] ?? '';
            $post_id  = url_to_postid($page_url);
            if (!$post_id && $page_url) {
                $normalized = rtrim($page_url, '/') . '/';
                $post_id = url_to_postid($normalized);
            }
            if (!$post_id && $page_url) {
                $path = trim((string) parse_url($page_url, PHP_URL_PATH), '/');
                if ($path !== '') {
                    $maybe = get_page_by_path($path, OBJECT, ['post', 'page', 'geo_glossary', 'geo-web-story']);
                    if ($maybe) $post_id = (int)$maybe->ID;

                    // Fallback para estruturas com base de CPT/categoria: usar apenas o slug final.
                    if (!$post_id) {
                        $parts = array_values(array_filter(explode('/', $path)));
                        $slug = end($parts);
                        if ($slug) {
                            $found = get_posts([
                                'name' => sanitize_title($slug),
                                'post_type' => ['post', 'page', 'geo_glossary', 'geo-web-story'],
                                'post_status' => 'publish',
                                'posts_per_page' => 1,
                                'fields' => 'ids',
                            ]);
                            if (!empty($found[0])) $post_id = (int)$found[0];
                        }
                    }
                }
            }

            $result[] = [
                'url'         => $page_url,
                'post_id'     => $post_id ?: 0,
                'post_title'  => $post_id ? get_the_title($post_id) : basename(rtrim($page_url, '/')),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'ctr'         => round(($row['ctr'] ?? 0) * 100, 1),
                'position'    => round(($row['position'] ?? 0), 1),
                'edit_url'    => $post_id ? get_edit_post_link($post_id) : '',
                'opportunity' => $this->calc_opportunity($row),
            ];
        }

        return $result;
    }

    /**
     * Busca keywords que trazem tráfego para uma URL específica.
     */
    public function get_keywords_for_url( string $page_url, int $days = 28 ): array {
        $access_token = $this->get_valid_token();

        if (empty($access_token)) return [];

        $property   = get_option('geo_gsc_property', get_site_url() . '/');
        $end_date   = date('Y-m-d', strtotime('-3 days'));
        $start_date = date('Y-m-d', strtotime('-' . ($days + 3) . ' days'));

        $body = [
            'startDate'              => $start_date,
            'endDate'                => $end_date,
            'dimensions'             => ['query'],
            'rowLimit'               => 25,
            'dimensionFilterGroups'  => [[
                'filters' => [[
                    'dimension'  => 'page',
                    'operator'   => 'equals',
                    'expression' => $page_url,
                ]],
            ]],
        ];

        $response = wp_remote_post(
            'https://searchconsole.googleapis.com/webmasters/v3/sites/'
            . urlencode($property) . '/searchAnalytics/query',
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode($body),
            ]
        );

        if (is_wp_error($response)) return [];

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return array_map(fn($r) => [
            'query'       => $r['keys'][0] ?? '',
            'impressions' => (int) $r['impressions'],
            'clicks'      => (int) $r['clicks'],
            'ctr'         => round($r['ctr'] * 100, 1),
            'position'    => round($r['position'], 1),
        ], $data['rows'] ?? []);
    }

    /**
     * Calcula score de oportunidade para otimização.
     * ALTA: posição 5-20 + muitas impressões = grande oportunidade de subir.
     * MEDIA: posição top 5 com CTR baixo, ou posição 20+ com impressões razoáveis.
     */
    private function calc_opportunity( array $row ): string {
        $pos = (float) ($row['position'] ?? 0);
        $imp = (int) ($row['impressions'] ?? 0);
        $ctr = (float) ($row['ctr'] ?? 0);

        if ($pos >= 5 && $pos <= 20 && $imp > 100) return 'ALTA';
        if ($pos >= 1 && $pos < 5 && $ctr < 0.05)  return 'MEDIA';
        if ($pos > 20 && $imp > 50)                 return 'MEDIA';

        return 'BAIXA';
    }

    /**
     * Verifica se o GSC está conectado.
     */
    public function is_connected(): bool {
        $token = get_option(self::TOKEN_OPTION, []);
        return !empty($token['access_token']);
    }

}
