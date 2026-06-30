<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\Indexing;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;
use GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller;

/**
 * SaraIndexNow — Submete URLs ao protocolo IndexNow.
 *
 * Protocolo aberto criado pela Microsoft, suportado por Bing, Yandex, Seznam e Naver.
 * Embora o Google não use IndexNow diretamente, o Bing reporta crawls para o Google
 * indiretamente (e a indexação no Bing ajuda em buscas via ChatGPT/Copilot/Perplexity).
 *
 * Vantagens vs Google Indexing API:
 * - Não exige OAuth nem Service Account
 * - Não exige Google Cloud Console
 * - Não exige verificação de propriedade adicional (usa um arquivo de chave no domínio)
 * - Endpoint público, gratuito, sem rate limit estrito
 *
 * Como usar:
 * 1) Plugin gera automaticamente a key e cria /[chave].txt na raiz
 * 2) Hook em save_post / publish_post submete a URL
 * 3) URLs são submetidas em batch (até 10.000 por request)
 *
 * @since 1.0.0
 * @link  https://www.indexnow.org/documentation
 */
class SaraIndexNow {

    /** Endpoint do IndexNow (Microsoft Bing — repassa para Yandex e outros) */
    private const ENDPOINT = 'https://api.indexnow.org/IndexNow';

    /** Option name para armazenar a chave gerada uma única vez */
    private const KEY_OPTION = 'sara_indexnow_key';

    /** Option para batch buffer (acumula URLs até flush) */
    private const BUFFER_OPTION = 'sara_indexnow_buffer';

    /** Option para enable/disable */
    private const ENABLED_OPTION = 'sara_indexnow_enabled';

    /**
     * Bootstrap: registrar hooks. Chamado uma vez em init.
     */
    public static function register(): void {
        // Servir o arquivo de chave dinamicamente (sem precisar criar arquivo físico)
        add_action('init', [__CLASS__, 'serve_key_file'], 1);

        // Auto-submeter quando post for publicado/atualizado
        add_action('transition_post_status', [__CLASS__, 'on_status_change'], 10, 3);

        // Flush do buffer a cada 5 minutos (cron)
        add_action('sara_indexnow_flush', [__CLASS__, 'flush_buffer']);
        if (!wp_next_scheduled('sara_indexnow_flush')) {
            wp_schedule_event(time() + 300, 'hourly', 'sara_indexnow_flush');
        }
    }

    /**
     * Status: enabled e key existem?
     */
    public static function is_enabled(): bool {
        return get_option(self::ENABLED_OPTION, '0') === '1';
    }

    public static function set_enabled(bool $on): void {
        update_option(self::ENABLED_OPTION, $on ? '1' : '0');
        if ($on) self::ensure_key();
    }

    /**
     * Garantir que a key exista. Cria uma vez e nunca regenera.
     * Especificação: 8-128 chars, hex (0-9, a-f, A-F).
     */
    public static function ensure_key(): string {
        $key = get_option(self::KEY_OPTION, '');
        if ($key && preg_match('/^[a-f0-9]{32,128}$/i', $key)) {
            return $key;
        }
        // Gerar 64 chars hex (= 32 bytes)
        $key = bin2hex(random_bytes(32));
        update_option(self::KEY_OPTION, $key);
        return $key;
    }

    /**
     * Servir o arquivo de chave em /{key}.txt — exigido pelo protocolo.
     * IndexNow valida a posse do domínio buscando este arquivo antes de aceitar URLs.
     */
    public static function serve_key_file(): void {
        if (!self::is_enabled()) return;

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($request_uri, PHP_URL_PATH) ?: '';
        $path = ltrim($path, '/');

        // Match {key}.txt na raiz
        if (!preg_match('/^([a-f0-9]{32,128})\.txt$/i', $path, $m)) return;

        $stored_key = self::ensure_key();
        if (!hash_equals($stored_key, $m[1])) return; // Não é nossa key — ignorar

        // Servir
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $stored_key;
        exit;
    }

    /**
     * Hook em transition_post_status — adiciona ao buffer quando publicado/atualizado.
     */
    public static function on_status_change(string $new_status, string $old_status, $post): void {
        if (!self::is_enabled()) return;
        if (!is_object($post) || !isset($post->ID)) return;
        $allowed_types = apply_filters('sara_indexnow_allowed_post_types', ['post', 'geo-web-story', 'geo_glossary', 'page']);
        if (!in_array($post->post_type, $allowed_types, true)) return;

        // Submeter quando: publicado novo OU atualização de post já publicado
        $is_publish = ($new_status === 'publish' && $old_status !== 'publish');
        $is_update  = ($new_status === 'publish' && $old_status === 'publish');

        if (!$is_publish && !$is_update) return;

        $url = get_permalink($post->ID);
        if (!$url) return;

        // Post novo publicado → submeter, MAS com throttle para não floodar a API.
        // Antes causava loop de HTTP 429: cada publicação re-enfileirava e re-tentava
        // sem limite. Agora respeita um intervalo mínimo entre submits imediatos.
        if ($is_publish) {
            $last_submit = (int) get_option('sara_indexnow_last_submit', 0);
            $now = time();
            // Throttle: no máximo 1 submit imediato a cada 30s. Se publicou vários
            // artigos em sequência (autopilot), os demais vão para o buffer.
            if ($now - $last_submit >= 30) {
                update_option('sara_indexnow_last_submit', $now, false);
                self::submit_batch([$url]);
                self::ping_google_sitemap();
            } else {
                self::add_to_buffer($url);
            }
            return;
        }

        // Atualização de post já publicado → buffer (agrupa para não floodar a API)
        self::add_to_buffer($url);
    }

    /**
     * Adicionar URL ao buffer. Flush automático se passar de 10 URLs.
     */
    public static function add_to_buffer(string $url): void {
        $url = esc_url_raw($url);
        if (!$url) return;

        $buffer = get_option(self::BUFFER_OPTION, []);
        if (!is_array($buffer)) $buffer = [];

        if (!in_array($url, $buffer, true)) {
            $buffer[] = $url;
            update_option(self::BUFFER_OPTION, $buffer, false);
        }

        // Flush imediato se passar do limite
        if (count($buffer) >= 10) {
            self::flush_buffer();
        }
    }

    /**
     * Enviar tudo o que está no buffer e limpar.
     */
    public static function flush_buffer(): bool {
        if (!self::is_enabled()) return false;

        $buffer = get_option(self::BUFFER_OPTION, []);
        if (!is_array($buffer) || empty($buffer)) return true; // Nada a fazer

        // Limpar o buffer ANTES da chamada para não duplicar se der retry
        update_option(self::BUFFER_OPTION, [], false);

        return self::submit_batch($buffer);
    }

    /**
     * Avisa o Google sobre conteúdo novo via ping de sitemap.
     * O Google descontinuou o ping clássico, mas manter o sitemap atualizado
     * (Rank Math/Yoast/WP core) é o caminho oficial. Aqui apenas garantimos que
     * o sitemap seja "tocado" para invalidar cache e acelerar o recrawl.
     */
    private static function ping_google_sitemap(): void {
        $sitemap = function_exists('get_sitemap_url') ? get_sitemap_url('index') : home_url('/sitemap.xml');
        if (!$sitemap) return;
        // Pré-aquece o sitemap (gera/atualiza o cache) para o próximo rastreamento do Google.
        wp_remote_get($sitemap, ['timeout' => 8, 'blocking' => false]);
        if (class_exists('\\GeoMetodoSEO\\Autopilot\\Shared\\AutopilotLogger')) {
            AutopilotLogger::log('system', 'google_sitemap_pinged', 'info',
                'Sitemap pré-aquecido para descoberta pelo Google: ' . $sitemap);
        }
    }

    /**
     * Submeter um batch de URLs ao IndexNow.
     *
     * @param string[] $urls Lista de URLs já validadas
     * @return bool true se 200/202, false em qualquer erro
     */
    public static function submit_batch(array $urls): bool {
        if (empty($urls)) return true;

        $home_url = home_url('/');
        $host     = parse_url($home_url, PHP_URL_HOST) ?: '';
        if (!$host) {
            AutopilotLogger::log('system', 'indexnow_error', 'error',
                'Não foi possível determinar o host do site');
            return false;
        }

        $key = self::ensure_key();

        $body = [
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $home_url . $key . '.txt',
            'urlList'     => array_values(array_unique($urls)),
        ];

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            AutopilotLogger::log('system', 'indexnow_error', 'error',
                'Falha de rede: ' . $response->get_error_message());
            // Re-adicionar ao buffer para retry no próximo cron
            $current = get_option(self::BUFFER_OPTION, []);
            update_option(self::BUFFER_OPTION, array_merge($urls, is_array($current) ? $current : []), false);
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body_resp = wp_remote_retrieve_body($response);

        // Códigos válidos do IndexNow:
        // 200 = OK
        // 202 = aceito (validação assíncrona)
        // 400 = bad request, 403 = key inválida, 422 = URLs inválidas, 429 = rate limit
        if ($code === 200 || $code === 202) {
            update_option('sara_indexnow_retry_count', 0, false); // reset no sucesso
            AutopilotLogger::log('system', 'indexnow_submit', 'success',
                count($urls) . " URLs submetidas ao IndexNow (HTTP {$code})");
            return true;
        }

        // 422 = problema com URLs (não vale a pena retentar)
        // 403 = chave inválida (não vale retentar — re-emitir manualmente)
        if (in_array($code, [400, 403, 422], true)) {
            AutopilotLogger::log('system', 'indexnow_rejected', 'error',
                "IndexNow rejeitou (HTTP {$code}): " . substr((string)$body_resp, 0, 200));
            return false;
        }

        // 429 / 5xx → re-adicionar para retry, MAS com limite de tentativas.
        // Sem limite, o buffer entrava em loop infinito de HTTP 429 (milhares de logs).
        $retry_count = (int) get_option('sara_indexnow_retry_count', 0);
        if ($retry_count >= 3) {
            // Já tentou 3x — desistir desse batch para parar o loop.
            AutopilotLogger::log('system', 'indexnow_giveup', 'warning',
                "IndexNow indisponível após 3 tentativas (HTTP {$code}) — batch descartado. Tente novamente mais tarde.");
            update_option('sara_indexnow_retry_count', 0, false);
            delete_option(self::BUFFER_OPTION); // limpar buffer para não acumular
            return false;
        }
        update_option('sara_indexnow_retry_count', $retry_count + 1, false);
        AutopilotLogger::log('system', 'indexnow_retry', 'warning',
            "IndexNow temporariamente indisponível (HTTP {$code}) — tentativa " . ($retry_count + 1) . "/3");
        $current = get_option(self::BUFFER_OPTION, []);
        update_option(self::BUFFER_OPTION, array_merge($urls, is_array($current) ? $current : []), false);
        return false;
    }

    /**
     * Enfileira conteúdos publicados para indexação manual: posts, Web Stories, Glossário e páginas.
     * Usado pelo botão "Enviar Agora" para não depender apenas do hook de publicação.
     */
    public static function enqueue_published_content(int $limit = 200): int {
        if (!self::is_enabled()) return 0;
        $limit = max(1, min(1000, $limit));
        $posts = get_posts([
            'post_type'      => apply_filters('sara_indexnow_allowed_post_types', ['post', 'geo-web-story', 'geo_glossary', 'page']),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);
        $added = 0;
        foreach ($posts as $post_id) {
            $url = get_permalink((int)$post_id);
            if ($url) {
                self::add_to_buffer($url);
                $added++;
            }
        }
        return $added;
    }

    /**
     * Estatísticas para o dashboard.
     */
    public static function get_status(): array {
        $buffer = get_option(self::BUFFER_OPTION, []);
        return [
            'enabled'     => self::is_enabled(),
            'key'         => self::ensure_key(),
            'key_url'     => home_url('/' . self::ensure_key() . '.txt'),
            'buffer_size' => is_array($buffer) ? count($buffer) : 0,
        ];
    }
}
