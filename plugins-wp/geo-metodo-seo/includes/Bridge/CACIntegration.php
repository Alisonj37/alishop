<?php
/**
 * Bridge: Content Audit & Cleanup → GEO Método SEO
 *
 * DECISÃO ARQUITETURAL:
 * Este arquivo vive dentro do GEO Método SEO (não como mu-plugin) porque:
 *  1. Ele usa classes internas do GEO (ContentUpdater, LogService) que só existem
 *     quando o plugin está ativo.
 *  2. O CAC dispara um hook WordPress (cac_send_post_to_geo_metodo) — se o GEO
 *     não estiver ativo, o hook simplesmente não tem listener e nada quebra.
 *  3. Manter tudo dentro do GEO facilita versionamento e desinstalação limpa.
 *
 * FLUXO:
 *  CAC "Enviar para reescrita"
 *   → do_action('cac_send_post_to_geo_metodo', $post_id)
 *   → CACIntegration::queue_rewrite($post_id)
 *      → seta _cac_geo_rewrite_status = 'pending'
 *      → agenda WP Cron single event: cac_geo_single_rewrite (30s de delay)
 *   → cac_geo_single_rewrite($post_id) [via cron]
 *      → ContentUpdater::rewrite_post($post)
 *      → seta _cac_geo_rewrite_status = 'done' | 'failed'
 *
 * GARANTIA DE RASTREABILIDADE:
 * A meta _cac_geo_rewrite_status fica visível na tela de auditoria do CAC e
 * na coluna "Auditoria" na lista de posts do WordPress Admin.
 */

namespace GeoMetodoSEO\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GeoMetodoSEO\Config\ConfigManager;
use GeoMetodoSEO\Services\ContentUpdater;
use GeoMetodoSEO\Services\LogService;

class CACIntegration {

    private const META_GEO_STATUS = '_cac_geo_rewrite_status';
    private const CRON_HOOK       = 'cac_geo_single_rewrite';
    private const CRON_DELAY      = 30; // segundos — deixa o redirect do CAC completar antes

    public static function register_hooks(): void {
        add_action( 'cac_send_post_to_geo_metodo',     array( __CLASS__, 'queue_rewrite' ), 10, 1 );
        add_action( self::CRON_HOOK,                    array( __CLASS__, 'process_rewrite' ), 10, 1 );
        // Expõe classificação semântica barata para o CAC (Camada 2).
        add_filter( 'cac_ai_cheap_classify',            array( __CLASS__, 'cheap_classify' ), 10, 2 );
    }

    /**
     * Classificação semântica de post via modelo rápido (Groq llama-3.1-8b-instant).
     * Retorna uma única palavra: MANTER | NOINDEX | FUNDIR | REVISAR | REMOVER | DUVIDA.
     * Chamado via apply_filters('cac_ai_cheap_classify', '', $prompt).
     *
     * @param string $default Valor padrão (string vazia se GEO não disponível).
     * @param string $prompt  Prompt de classificação montado pelo CAC.
     */
    public static function cheap_classify( string $default, string $prompt ): string {
        $api_key = ConfigManager::get( 'groq_api_key' );
        if ( empty( $api_key ) ) {
            return $default;
        }

        $response = wp_remote_post( 'https://api.groq.com/openai/v1/chat/completions', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => 'llama-3.1-8b-instant',
                'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
                'max_tokens'  => 8,
                'temperature' => 0.1,
                'stream'      => false,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return $default;
        if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) return $default;

        $body    = json_decode( wp_remote_retrieve_body( $response ), true );
        $content = trim( $body['choices'][0]['message']['content'] ?? '' );

        // Extrai apenas a primeira palavra em maiúsculas (a classificação)
        if ( preg_match('/\b(MANTER|NOINDEX|FUNDIR|REVISAR|REMOVER|DUVIDA)\b/i', $content, $m) ) {
            return strtoupper( $m[1] );
        }

        return $default;
    }

    /**
     * Enfileira a reescrita de um post recebido do CAC.
     * Chamado sincronicamente durante o bulk action — deve retornar rápido.
     *
     * @param int $post_id
     */
    public static function queue_rewrite( int $post_id ): void {
        if ( $post_id <= 0 ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'post' ) {
            LogService::log(
                'error',
                "CAC Bridge: post #{$post_id} não encontrado ou tipo inválido — reescrita ignorada."
            );
            return;
        }

        // Evita enfileirar o mesmo post duas vezes
        if ( wp_next_scheduled( self::CRON_HOOK, array( $post_id ) ) ) {
            return;
        }

        update_post_meta( $post_id, self::META_GEO_STATUS, 'pending' );

        wp_schedule_single_event(
            time() + self::CRON_DELAY,
            self::CRON_HOOK,
            array( $post_id )
        );

        LogService::record(
            'bridge', 'info',
            "CAC Bridge: post #{$post_id} enfileirado para reescrita via GEO Método SEO.",
            array( 'action' => 'cac_queue_rewrite', 'post_id' => $post_id )
        );
    }

    /**
     * Processa a reescrita de um post. Chamado pelo WP Cron.
     *
     * @param int $post_id
     */
    public static function process_rewrite( int $post_id ): void {
        if ( $post_id <= 0 ) {
            return;
        }

        // Verificar licença ativa
        if (
            function_exists( 'geo_metodo_license_active' )
            && ! geo_metodo_license_active()
        ) {
            update_post_meta( $post_id, self::META_GEO_STATUS, 'failed' );
            update_post_meta( $post_id, '_cac_geo_fail_reason', 'Sem licença ativa do GEO Método SEO.' );
            LogService::log(
                'error',
                "CAC Bridge: post #{$post_id} — reescrita cancelada: sem licença ativa do GEO Método SEO."
            );
            return;
        }

        // Verificar que algum provider de IA está configurado antes de tentar
        $provider = \GeoMetodoSEO\AI\ProviderResolver::for('content_refresher');
        if ( empty( $provider ) ) {
            // Se não tem provider específico para reescrita, tenta o padrão global
            $provider = \GeoMetodoSEO\AI\ProviderResolver::for('article_generation');
        }
        if ( empty( $provider ) ) {
            update_post_meta( $post_id, self::META_GEO_STATUS, 'failed' );
            update_post_meta( $post_id, '_cac_geo_fail_reason', 'Nenhum provider de IA configurado. Vá em GEO Método SEO → Configurações e configure ao menos uma API key (Groq, OpenAI, etc).' );
            LogService::log(
                'error',
                "CAC Bridge: post #{$post_id} — nenhum provider de IA configurado para reescrita. Configure em GEO → Configurações."
            );
            return;
        }

        if ( ! \GeoMetodoSEO\AI\ProviderResolver::isConfigured( $provider ) ) {
            update_post_meta( $post_id, self::META_GEO_STATUS, 'failed' );
            update_post_meta( $post_id, '_cac_geo_fail_reason', "Provider '{$provider}' selecionado mas sem API key. Configure em GEO → Configurações → API Keys." );
            LogService::log(
                'error',
                "CAC Bridge: post #{$post_id} — provider '{$provider}' sem API key configurada."
            );
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            update_post_meta( $post_id, self::META_GEO_STATUS, 'failed' );
            update_post_meta( $post_id, '_cac_geo_fail_reason', 'Post não encontrado no banco de dados.' );
            LogService::log( 'error', "CAC Bridge: post #{$post_id} não encontrado para reescrita." );
            return;
        }

        LogService::record(
            'bridge', 'info',
            "CAC Bridge: iniciando reescrita do post #{$post_id} ({$post->post_title}) via provider '{$provider}'",
            array( 'action' => 'cac_process_rewrite', 'post_id' => $post_id, 'provider' => $provider )
        );

        @set_time_limit( 180 );

        try {
            $updater = new ContentUpdater();
            $result  = $updater->rewrite_post( $post );

            if ( $result ) {
                update_post_meta( $post_id, self::META_GEO_STATUS, 'done' );
                update_post_meta( $post_id, '_cac_geo_rewritten_at', current_time( 'mysql' ) );
                delete_post_meta( $post_id, '_cac_geo_fail_reason' );
                LogService::record(
                    'bridge', 'success',
                    "CAC Bridge: post #{$post_id} reescrito com sucesso pelo GEO Método SEO via '{$provider}'.",
                    array( 'action' => 'cac_rewrite_done', 'post_id' => $post_id )
                );
            } else {
                update_post_meta( $post_id, self::META_GEO_STATUS, 'failed' );
                $log = get_post_meta( $post_id, '_geo_rewrite_log', true );
                $last_error = '';
                if ( is_array( $log ) && ! empty( $log ) ) {
                    $last = end( $log );
                    $last_error = implode( ', ', (array) ( $last['validation_errors'] ?? [] ) );
                }
                update_post_meta( $post_id, '_cac_geo_fail_reason', $last_error ?: 'ContentUpdater retornou false — veja o log no GEO.' );
                LogService::log(
                    'error',
                    "CAC Bridge: ContentUpdater retornou false para post #{$post_id}. Erros: " . ( $last_error ?: 'desconhecido' )
                );
            }
        } catch ( \Throwable $e ) {
            update_post_meta( $post_id, self::META_GEO_STATUS, 'failed' );
            update_post_meta( $post_id, '_cac_geo_fail_reason', 'Exceção: ' . $e->getMessage() );
            LogService::log(
                'error',
                "CAC Bridge: exceção ao reescrever post #{$post_id} — " . $e->getMessage()
            );
        }
    }
}
