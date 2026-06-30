<?php

namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Config\ConfigManager;

/**
 * TTSService — Converte artigos em áudio e embeda player no post.
 *
 * Provedores suportados (em ordem de prioridade):
 *   1. ElevenLabs — qualidade premium, voz customizável
 *   2. Google Cloud TTS — barato, multilíngue
 *   3. OpenAI TTS (tts-1, tts-1-hd) — opcional
 *
 * O áudio é salvo na Media Library do WordPress.
 * O player HTML5 é inserido automaticamente no início do post.
 *
 * @since 1.0.0
 */
class TTSService {

    private $openai_key;
    private $elevenlabs_key;
    private $google_key;

    public function __construct() {
        $this->openai_key     = ConfigManager::get('openai_api_key', '');
        $this->elevenlabs_key = get_option('geo_elevenlabs_api_key', '');
        $this->google_key     = get_option('geo_google_tts_key', '');
    }

    public function isConfigured(): bool {
        return !empty($this->openai_key)
            || !empty($this->elevenlabs_key)
            || !empty($this->google_key);
    }

    public function getProvider(): string {
        $pref = sanitize_key((string) get_option('geo_tts_provider', 'auto'));
        if ($pref === 'openai' && !empty($this->openai_key)) return 'openai';
        if ($pref === 'elevenlabs' && !empty($this->elevenlabs_key)) return 'elevenlabs';
        if ($pref === 'google' && !empty($this->google_key)) return 'google';
        // Auto: não força OpenAI primeiro; prioriza providers dedicados se configurados.
        if (!empty($this->elevenlabs_key)) return 'elevenlabs';
        if (!empty($this->google_key)) return 'google';
        if (!empty($this->openai_key)) return 'openai';
        return '';
    }

    /**
     * Converte conteúdo de um post em áudio e embeda o player.
     *
     * @param int $post_id
     * @return bool Sucesso ou falha
     */
    public function processPost( int $post_id ): bool {
        $post = get_post($post_id);
        if (!$post) return false;

        // Checar se já tem áudio
        if (get_post_meta($post_id, '_geo_audio_url', true)) return true;

        // Extrair texto limpo do post (sem HTML)
        $text = $this->extractText($post->post_content, $post->post_title);

        if (strlen($text) < 50) return false;

        // Truncar para evitar custos excessivos (máx 4000 chars por padrão)
        $max_chars = (int) get_option('geo_tts_max_chars', 4000);
        if (strlen($text) > $max_chars) {
            $text = mb_substr($text, 0, $max_chars) . '...';
        }

        $provider = $this->getProvider();
        if (empty($provider)) return false;

        // Gerar áudio
        $audio_data = $this->generate($text, $provider);
        if (!$audio_data) return false;

        // Salvar na Media Library
        $audio_url = $this->saveToMediaLibrary($audio_data, $post_id, $post->post_title);
        if (!$audio_url) return false;

        // Salvar URL no meta
        update_post_meta($post_id, '_geo_audio_url', $audio_url);
        update_post_meta($post_id, '_geo_audio_provider', $provider);
        update_post_meta($post_id, '_geo_audio_generated_at', time());

        // Inserir player no conteúdo — protegido: se a inserção falhar (ex: cascata
        // de hooks save_post), o áudio já está salvo e não perdemos o trabalho.
        try {
            $this->embedPlayer($post_id, $audio_url, $post->post_title);
        } catch (\Throwable $e) {
            LogService::log('warning', "TTS: áudio salvo mas player não inserido no post #{$post_id} — " . $e->getMessage());
        }

        LogService::log('info', "TTS: áudio gerado para post #{$post_id} via {$provider}");
        return true;
    }

    /**
     * Extrai texto limpo do HTML do post.
     */
    private function extractText( string $html_content, string $title ): string {
        // Remove shortcodes
        $text = strip_shortcodes($html_content);
        // Remove tags HTML
        $text = wp_strip_all_tags($text);
        // Limpar espaços
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Prefixar com título para contexto
        return $title . '. ' . $text;
    }

    /**
     * Gera áudio via provedor escolhido.
     * Retorna string binária do áudio ou false.
     */
    private function generate( string $text, string $provider ) {
        switch ($provider) {
            case 'openai':     return $this->generateOpenAI($text);
            case 'elevenlabs': return $this->generateElevenLabs($text);
            case 'google':     return $this->generateGoogle($text);
        }
        return false;
    }

    /**
     * OpenAI TTS — modelo tts-1 (rápido) ou tts-1-hd (qualidade).
     */
    private function generateOpenAI( string $text ) {
        $model = get_option('geo_tts_openai_model', 'tts-1');
        $voice = get_option('geo_tts_openai_voice', 'nova'); // alloy, echo, fable, onyx, nova, shimmer

        $response = wp_remote_post('https://api.openai.com/v1/audio/speech', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openai_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
            ]),
        ]);

        if (is_wp_error($response)) return false;
        if (wp_remote_retrieve_response_code($response) !== 200) return false;

        return wp_remote_retrieve_body($response);
    }

    /**
     * ElevenLabs TTS — alta qualidade, voz customizável.
     */
    private function generateElevenLabs( string $text ) {
        $voice_id = get_option('geo_tts_elevenlabs_voice_id', 'pNInz6obpgDQGcFmaJgB'); // default: Adam

        $response = wp_remote_post(
            "https://api.elevenlabs.io/v1/text-to-speech/{$voice_id}",
            [
                'timeout' => 60,
                'headers' => [
                    'xi-api-key'   => $this->elevenlabs_key,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'audio/mpeg',
                ],
                'body' => wp_json_encode([
                    'text'     => $text,
                    'model_id' => 'eleven_multilingual_v2',
                    'voice_settings' => [
                        'stability'        => 0.5,
                        'similarity_boost' => 0.75,
                    ],
                ]),
            ]
        );

        if (is_wp_error($response)) return false;
        if (wp_remote_retrieve_response_code($response) !== 200) return false;

        return wp_remote_retrieve_body($response);
    }

    /**
     * Google Cloud TTS.
     */
    private function generateGoogle( string $text ) {
        $language = get_option('geo_tts_google_lang', 'pt-BR');
        $voice    = get_option('geo_tts_google_voice', 'pt-BR-Wavenet-A');

        $response = wp_remote_post(
            'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . $this->google_key,
            [
                'timeout' => 60,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode([
                    'input'       => ['text' => $text],
                    'voice'       => ['languageCode' => $language, 'name' => $voice],
                    'audioConfig' => ['audioEncoding' => 'MP3'],
                ]),
            ]
        );

        if (is_wp_error($response)) return false;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['audioContent'])) return false;

        return base64_decode($data['audioContent']);
    }

    /**
     * Salva binário do áudio na Media Library do WordPress.
     */
    private function saveToMediaLibrary( string $audio_data, int $post_id, string $title ): string {
        if ($audio_data === '') return '';

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            LogService::log('error', 'TTS: erro no diretório de uploads — ' . $upload_dir['error']);
            return '';
        }
        // Garantir que a pasta existe
        if (!file_exists($upload_dir['path'])) {
            wp_mkdir_p($upload_dir['path']);
        }

        $filename = sanitize_title($title) . '-audio-' . $post_id . '-' . wp_rand(1000, 9999) . '.mp3';
        $filepath = trailingslashit($upload_dir['path']) . $filename;

        if (file_put_contents($filepath, $audio_data) === false) {
            LogService::log('error', 'TTS: falha ao escrever o arquivo de áudio (permissão da pasta uploads?)');
            return '';
        }

        if (!function_exists('wp_insert_attachment')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $attachment_id = wp_insert_attachment([
            'guid'           => trailingslashit($upload_dir['url']) . $filename,
            'post_mime_type' => 'audio/mpeg',
            'post_title'     => 'Áudio: ' . $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $filepath, $post_id, true);

        if (is_wp_error($attachment_id) || !$attachment_id) {
            LogService::log('error', 'TTS: falha ao inserir o anexo de áudio — '
                . (is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'ID vazio'));
            @unlink($filepath);
            return '';
        }

        // Gerar metadados do áudio (duração, etc) — sem quebrar se falhar
        try {
            $metadata = wp_generate_attachment_metadata($attachment_id, $filepath);
            if (!is_wp_error($metadata) && is_array($metadata)) {
                wp_update_attachment_metadata($attachment_id, $metadata);
            }
        } catch (\Throwable $e) {
            // Metadados de áudio são opcionais — não bloquear por causa disso
        }

        update_post_meta($post_id, '_geo_audio_attachment_id', $attachment_id);
        return (string) wp_get_attachment_url($attachment_id);
    }

    /**
     * Insere player de áudio HTML5 no início do conteúdo do post.
     */
    private function embedPlayer( int $post_id, string $audio_url, string $title ): void {
        $post = get_post($post_id);
        if (!$post) return;

        $player_html = sprintf(
            '<div class="geo-audio-player" style="background:linear-gradient(135deg,#f8f9ff,#e8f0fe);border:1px solid #c5d5f5;border-radius:10px;padding:16px 20px;margin:0 0 28px;display:flex;align-items:center;gap:14px;">'
            . '<div style="font-size:28px;">🎧</div>'
            . '<div style="flex:1;">'
            . '<p style="margin:0 0 6px;font-size:13px;font-weight:600;color:#1a1a2e;">Ouça este artigo</p>'
            . '<audio controls style="width:100%;height:36px;">'
            . '<source src="%s" type="audio/mpeg">'
            . 'Seu navegador não suporta áudio HTML5.'
            . '</audio>'
            . '</div>'
            . '</div>',
            esc_url($audio_url)
        );

        $new_content = $player_html . $post->post_content;

        \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post([
            'ID'           => $post_id,
            'post_content' => $new_content,
        ]);
    }

    /**
     * Remove áudio e player de um post.
     */
    public function removeAudio( int $post_id ): void {
        $attachment_id = get_post_meta($post_id, '_geo_audio_attachment_id', true);
        if ($attachment_id) wp_delete_attachment($attachment_id, true);

        delete_post_meta($post_id, '_geo_audio_url');
        delete_post_meta($post_id, '_geo_audio_provider');
        delete_post_meta($post_id, '_geo_audio_generated_at');
        delete_post_meta($post_id, '_geo_audio_attachment_id');

        // Remover player do conteúdo
        $post = get_post($post_id);
        if ($post) {
            $clean = preg_replace('/<div class="geo-audio-player".*?<\/div>\s*/s', '', $post->post_content);
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $clean]);
        }
    }

}
