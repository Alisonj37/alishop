<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

class ReplicateImageService {

    public function is_configured() {
        return !empty(get_option('geo_replicate_api_key'));
    }

    public function generate($prompt, $aspect_ratio = '16:9') {
        $api_key = get_option('geo_replicate_api_key');
        $model   = get_option('geo_replicate_model', 'black-forest-labs/flux-schnell');

        if (!$api_key || !$model) {
            \GeoMetodoSEO\Services\LogService::record(
                'replicate', 'warning',
                'Replicate não configurado (sem API key ou modelo)',
                ['action' => 'generate']
            );
            return false;
        }

        $start = microtime(true);

        // Suporta formato "owner/model" e "owner/model:version"
        if (strpos($model, ':') !== false) {
            $result = $this->generate_versioned($api_key, $model, $prompt, $aspect_ratio);
        } else {
            $result = $this->generate_latest($api_key, $model, $prompt, $aspect_ratio);
        }

        $duration = (int) round((microtime(true) - $start) * 1000);

        if (!empty($result)) {
            \GeoMetodoSEO\Services\LogService::record(
                'replicate', 'success',
                "Imagem gerada com {$model}",
                ['action' => 'generate', 'duration_ms' => $duration, 'context' => ['model' => $model]]
            );
        } else {
            \GeoMetodoSEO\Services\LogService::record(
                'replicate', 'error',
                "Falha ao gerar imagem com {$model}",
                ['action' => 'generate', 'duration_ms' => $duration, 'context' => [
                    'model' => $model, 'aspect_ratio' => $aspect_ratio,
                    'prompt_preview' => mb_substr($prompt, 0, 200),
                ]]
            );
        }

        return $result;
    }

    public function generate_schnell(string $prompt, string $aspect_ratio = '16:9') {
        $api_key = get_option('geo_replicate_api_key');
        if (!$api_key) {
            return new \WP_Error('geo_replicate_missing_key', 'Replicate nao configurado.');
        }

        $start = microtime(true);

        // API Replicate: modelos oficiais usam endpoint /v1/models/{owner}/{name}/predictions
        // NÃO usar /v1/predictions com campo "model" — isso retorna erro "version is required"
        $response = wp_remote_post(
            'https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                    'Prefer'        => 'wait=20',
                ],
                'body' => wp_json_encode([
                    'input' => [
                        'prompt'        => $prompt,
                        'aspect_ratio'  => $aspect_ratio,
                        'output_format' => 'jpg',
                        'num_outputs'   => 1,
                    ],
                ]),
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $body_parsed = json_decode($raw_body, true);

        $result = $this->parse_response($response, $api_key);
        $duration = (int) round((microtime(true) - $start) * 1000);
        if ($result) {
            LogService::record('replicate', 'success', 'Imagem Flux Schnell gerada via Replicate', [
                'action' => 'generate_schnell',
                'duration_ms' => $duration,
                'context' => ['aspect_ratio' => $aspect_ratio],
            ]);
            return $result;
        }

        // 1.0.0: mensagem de erro DETALHADA pra debug no admin
        $detail = '';
        if ($code === 401 || $code === 403) {
            $detail = 'API key invalida (HTTP ' . $code . ')';
        } elseif ($code === 402) {
            $detail = 'SEM CREDITOS (HTTP 402) - recarregar em replicate.com/account/billing';
        } elseif ($code === 429) {
            $detail = 'rate-limit (HTTP 429)';
        } elseif ($code >= 500) {
            $detail = 'servidor Replicate fora do ar (HTTP ' . $code . ')';
        } elseif ($code !== 200 && $code !== 201) {
            $err_msg = $body_parsed['detail'] ?? $body_parsed['error'] ?? ('HTTP ' . $code);
            $detail = is_string($err_msg) ? $err_msg : ('HTTP ' . $code);
        } else {
            $detail = 'polling sem resultado em 20s (modelo lento ou em queue)';
        }

        return new \WP_Error('geo_replicate_schnell_failed', 'Replicate Flux Schnell falhou: ' . $detail);
    }

    private function generate_latest($api_key, $model, $prompt, $aspect_ratio = '16:9') {
        $url      = 'https://api.replicate.com/v1/models/' . $model . '/predictions';
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'wait=20',
            ],
            'body'    => json_encode([
                'input' => [
                    'prompt'        => $prompt,
                    'aspect_ratio'  => $aspect_ratio,
                    'output_format' => 'jpg',
                    'num_outputs'   => 1,
                ],
            ]),
            'timeout' => 30,
        ]);

        return $this->parse_response($response, $api_key);
    }

    private function generate_versioned($api_key, $model_with_version, $prompt) {
        $parts   = explode(':', $model_with_version, 2);
        $version = $parts[1];

        $response = wp_remote_post('https://api.replicate.com/v1/predictions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'wait=20',
            ],
            'body'    => json_encode([
                'version' => $version,
                'input'   => [
                    'prompt'        => $prompt,
                    'aspect_ratio'  => '16:9',
                    'num_outputs'   => 1,
                ],
            ]),
            'timeout' => 30,
        ]);

        return $this->parse_response($response, $api_key);
    }

    private function parse_response($response, $api_key) {
        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        // Rate limited — aguarda e tenta uma vez
        if ($code === 429) {
            return false;
        }

        if ($code < 200 || $code >= 300) {
            return false;
        }

        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $output = $body['output'] ?? null;

        // Resposta sincrona ja com output
        if (is_array($output) && !empty($output)) {
            return is_string($output[0]) ? $output[0] : false;
        }

        if (is_string($output) && !empty($output)) {
            return $output;
        }

        // 1.0.0 BUG FIX CRÍTICO: Codex desligou polling na v1.0.0+ pra "evitar 504".
        // Resultado: TODA prediction com status 'processing' (que é o NORMAL no Schnell)
        // retornava false → Replicate "não retornava imagem" mesmo funcionando.
        // Logs do user: "Replicate Flux Schnell nao retornou imagem" = isto aqui.
        //
        // Agora: reativa polling. Schnell normalmente termina em 5-15s.
        // 10 tentativas × 2s = 20s máximo (suficiente sem risco de 504).
        if (!empty($body['id']) && in_array($body['status'] ?? '', ['starting', 'processing'], true)) {
            LogService::record('replicate', 'info', 'Prediction processando, iniciando polling (20s max)', [
                'action' => 'polling_started',
                'context' => ['prediction_id' => $body['id']],
            ]);
            $polled = $this->poll_prediction($body['id'], $api_key);
            if ($polled) {
                return $polled;
            }
            LogService::record('replicate', 'warning', 'Polling expirou em 20s sem retorno', [
                'action' => 'polling_timeout',
                'context' => ['prediction_id' => $body['id']],
            ]);
            return false;
        }

        return false;
    }

    private function poll_prediction($prediction_id, $api_key) {
        $url = 'https://api.replicate.com/v1/predictions/' . $prediction_id;

        // 1.0.0: polling robusto. Flux Schnell normalmente termina em 3-8s, mas em pico
        // pode levar até 15-20s. Antes: 3 tentativas × 2s = 6s (insuficiente em pico).
        // Agora: 10 tentativas × 2s = 20s (cobre Schnell mesmo em pico de uso).
        for ($i = 0; $i < 10; $i++) {
            sleep(2);

            $response = wp_remote_get($url, [
                'headers' => ['Authorization' => 'Bearer ' . $api_key],
                'timeout' => 10,
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            $body   = json_decode(wp_remote_retrieve_body($response), true);
            $status = $body['status'] ?? '';
            $output = $body['output'] ?? null;

            if ($status === 'succeeded') {
                if (is_array($output) && !empty($output)) {
                    return is_string($output[0]) ? $output[0] : false;
                }
                if (is_string($output) && !empty($output)) {
                    return $output;
                }
                return false;
            }

            if ($status === 'failed' || $status === 'canceled') {
                break;
            }
        }

        return false;
    }

    public function save_to_library($image_url, $title) {
        if (!class_exists(__NAMESPACE__ . '\\SafeImageSideload')) {
            return false;
        }
        $attachment_id = SafeImageSideload::attachment_id((string) $image_url, 0, (string) $title);
        return $attachment_id ?: false;
    }
}
