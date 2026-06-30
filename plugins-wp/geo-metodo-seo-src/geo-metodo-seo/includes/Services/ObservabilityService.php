<?php
namespace GeoMetodoSEO\Services;

use GeoMetodoSEO\AI\ProviderResolver;

if (!defined('ABSPATH')) exit;

/**
 * ObservabilityService
 *
 * Camada somente leitura para diagnóstico de providers, saúde operacional,
 * custo estimado e histórico recente por conteúdo. Não altera o motor de geração.
 */
class ObservabilityService {

    public static function render_dashboard(): void {
        echo '<div class="geo-card">';
        echo '<h2>📡 Observabilidade profissional</h2>';
        echo '<p class="description">Diagnóstico de providers, saúde operacional e conteúdos recentes. Esta camada não gera artigos nem chama IA.</p>';
        self::render_provider_diagnostics();
        self::render_health_check();
        self::render_provider_mode_state();
        self::render_recent_content_report();
        echo '</div>';
    }

    public static function text_providers(): array {
        return [
            'openai'     => ['label' => 'OpenAI', 'option' => 'geo_openai_api_key', 'contexts' => ['global', 'article_generation', 'manual_writer']],
            'groq'       => ['label' => 'Groq', 'option' => 'geo_groq_api_key', 'contexts' => ['economy', 'title', 'glossary', 'image_prompt']],
            'gemini'     => ['label' => 'Gemini', 'option' => 'geo_gemini_api_key', 'contexts' => ['article_generation', 'youtube_article']],
            'claude'     => ['label' => 'Claude', 'option' => 'geo_claude_api_key', 'contexts' => ['article_generation', 'editing']],
            'perplexity' => ['label' => 'Perplexity', 'option' => 'geo_perplexity_api_key', 'contexts' => ['research', 'web_context']],
            'naga'       => ['label' => 'Naga.ac Texto', 'option' => 'geo_naga_api_key', 'contexts' => ['economy', 'article_generation', 'glossary']],
        ];
    }

    public static function image_providers(): array {
        return [
            'falai'       => ['label' => 'Fal.ai', 'option' => 'geo_falai_api_key', 'role' => 'Featured premium + fallback body/story'],
            'replicate'   => ['label' => 'Replicate', 'option' => 'geo_replicate_api_key', 'role' => 'Body images + fallback featured/story'],
            'naga'        => ['label' => 'Naga.ac Imagem', 'option' => 'geo_naga_api_key', 'role' => 'Web Stories + fallback images'],
            'huggingface' => ['label' => 'HuggingFace', 'option' => 'geo_huggingface_api_key', 'role' => 'Fallback Web Stories'],
            'unsplash'    => ['label' => 'Unsplash', 'option' => 'geo_unsplash_api_key', 'role' => 'Stock photos'],
            'pexels'      => ['label' => 'Pexels', 'option' => 'geo_pexels_api_key', 'role' => 'Stock photos'],
            'pixabay'     => ['label' => 'Pixabay', 'option' => 'geo_pixabay_api_key', 'role' => 'Stock photos'],
            'pollinations'=> ['label' => 'Pollinations/Flux', 'option' => '', 'role' => 'Fallback público final com sideload local'],
        ];
    }

    public static function render_provider_diagnostics(): void {
        echo '<h3>1) Diagnóstico dos provedores de texto</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Provider</th><th>Status</th><th>Modelo resolvido</th><th>Opção/API</th><th>Contextos</th></tr></thead><tbody>';
        foreach (self::text_providers() as $key => $p) {
            $configured = trim((string)get_option($p['option'], '')) !== '';
            $model = class_exists(ProviderResolver::class) ? ProviderResolver::modelFor('article_generation', $key) : '';
            echo '<tr>';
            echo '<td><strong>' . esc_html($p['label']) . '</strong><br><code>' . esc_html($key) . '</code></td>';
            echo '<td>' . ($configured ? '<span class="geo-ok">✅ Configurado</span>' : '<span class="geo-warn">⚠️ Sem chave</span>') . '</td>';
            echo '<td><code>' . esc_html($model ?: 'padrão do provider') . '</code></td>';
            echo '<td><code>' . esc_html($p['option']) . '</code></td>';
            echo '<td>' . esc_html(implode(', ', $p['contexts'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h3 style="margin-top:20px;">2) Diagnóstico dos provedores de imagem</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Provider</th><th>Status</th><th>Função na cadeia</th><th>Opção/API</th></tr></thead><tbody>';
        foreach (self::image_providers() as $key => $p) {
            $configured = $p['option'] === '' ? true : trim((string)get_option($p['option'], '')) !== '';
            echo '<tr>';
            echo '<td><strong>' . esc_html($p['label']) . '</strong><br><code>' . esc_html($key) . '</code></td>';
            echo '<td>' . ($configured ? '<span class="geo-ok">✅ Disponível</span>' : '<span class="geo-warn">⚠️ Sem chave</span>') . '</td>';
            echo '<td>' . esc_html($p['role']) . '</td>';
            echo '<td>' . ($p['option'] ? '<code>' . esc_html($p['option']) . '</code>' : '<em>sem chave</em>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public static function render_health_check(): void {
        $upload = wp_get_upload_dir();
        $checks = [
            ['WP-Cron', !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON), 'Necessário para filas, autopilot e jobs assíncronos.'],
            ['Uploads gravável', !empty($upload['basedir']) && is_writable($upload['basedir']), 'Necessário para salvar imagens na biblioteca.'],
            ['download_url()', function_exists('download_url'), 'Necessário para sideload de imagens.'],
            ['media_handle_sideload()', function_exists('media_handle_sideload'), 'Necessário para salvar imagens externas.'],
            ['Rank Math', defined('RANK_MATH_VERSION') || class_exists('RankMath'), 'SEO meta/focus keyword.'],
            ['IndexNow key', trim((string)get_option('geo_indexnow_key', '')) !== '', 'Envio de URLs para indexação.'],
            ['Search Console token', (bool)get_option('geo_gsc_tokens') || (bool)get_option('geo_search_console_tokens'), 'Content Refresher/GSC.'],
            ['Memória PHP >= 256M', self::memory_to_bytes(ini_get('memory_limit')) >= 268435456 || self::memory_to_bytes(ini_get('memory_limit')) < 0, 'Recomendado para geração longa e imagens.'],
        ];
        echo '<h3 style="margin-top:22px;">3) Health Check operacional</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Item</th><th>Status</th><th>Observação</th></tr></thead><tbody>';
        foreach ($checks as $c) {
            echo '<tr><td><strong>' . esc_html($c[0]) . '</strong></td><td>' . ($c[1] ? '<span class="geo-ok">✅ OK</span>' : '<span class="geo-warn">⚠️ Atenção</span>') . '</td><td>' . esc_html($c[2]) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function render_provider_mode_state(): void {
        echo '<h3 style="margin-top:22px;">4) Modo dos providers</h3>';
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><td><strong>Modo dos providers</strong></td><td><span class="geo-ok">Independentes</span></td></tr>';
                        echo '<tr><td>Regra atual</td><td>O plugin usa somente o provider selecionado em cada módulo.</td></tr>';
        echo '</tbody></table>';
    }

    public static function render_recent_content_report(int $limit = 12): void {
        $posts = get_posts([
            'post_type' => ['post', 'page', 'geo_glossary', 'geo-web-story'],
            'post_status' => ['publish', 'draft', 'future', 'pending'],
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        echo '<h3 style="margin-top:22px;">5) Relatório rápido por conteúdo recente</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Conteúdo</th><th>Provider texto</th><th>Imagem</th><th>Corpo</th><th>IA/logs</th><th>Ações</th></tr></thead><tbody>';
        if (!$posts) {
            echo '<tr><td colspan="6">Nenhum conteúdo recente encontrado.</td></tr>';
        }
        foreach ($posts as $p) {
            $provider = get_post_meta($p->ID, '_geo_text_provider_used', true) ?: get_post_meta($p->ID, '_geo_provider', true) ?: '—';
            $model = get_post_meta($p->ID, '_geo_text_model_used', true) ?: '—';
            $body_count = get_post_meta($p->ID, '_geo_body_images_count', true);
            if ($body_count === '') {
                preg_match_all('/<figure\b|<img\b/i', (string)$p->post_content, $m);
                $body_count = count($m[0] ?? []);
            }
            $featured = get_post_thumbnail_id($p->ID) ? '✅ Sim' : '⚠️ Não';
            $logs = self::count_logs_for_post($p->ID);
            echo '<tr>';
            echo '<td><strong>#' . esc_html((string)$p->ID) . ' — ' . esc_html(get_the_title($p)) . '</strong><br><code>' . esc_html($p->post_type) . '</code> ' . esc_html($p->post_status) . '</td>';
            echo '<td>' . esc_html($provider) . '<br><code>' . esc_html($model) . '</code></td>';
            echo '<td>' . esc_html($featured) . '</td>';
            echo '<td>' . esc_html((string)$body_count) . ' imagens</td>';
            echo '<td>' . esc_html((string)$logs) . ' logs</td>';
            echo '<td><a class="button button-small" href="' . esc_url(get_edit_post_link($p->ID)) . '">Editar</a> <button class="button button-small geo-reprocess-media" data-post="' . esc_attr($p->ID) . '">Reprocessar mídia</button> <button class="button button-small geo-post-timeline" data-post="' . esc_attr($p->ID) . '">Timeline</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table><div id="geo-observability-result" class="geo-pre" style="display:none;margin-top:12px;"></div>';
    }

    public static function count_logs_for_post(int $post_id): int {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_logs';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id));
    }

    public static function timeline_for_post(int $post_id, int $limit = 25): array {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_logs';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, created_at, type, module, action, message, context FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT %d",
            $post_id, $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private static function memory_to_bytes($value): int {
        $value = trim((string)$value);
        if ($value === '-1') return -1;
        $num = (int)$value;
        $unit = strtolower(substr($value, -1));
        if ($unit === 'g') return $num * 1024 * 1024 * 1024;
        if ($unit === 'm') return $num * 1024 * 1024;
        if ($unit === 'k') return $num * 1024;
        return $num;
    }
}
