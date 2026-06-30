<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) exit;

/**
 * Validação local básica de Web Stories AMP.
 * Não chama API externa; salva score/issues em meta para diagnóstico.
 */
class WebStoryAmpValidator {

    public static function validate_post(int $post_id): array {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'geo-web-story') {
            return ['score' => 0, 'issues' => ['Post não é uma Web Story.']];
        }
        $html = (string) get_post_meta($post_id, '_geo_amp_html', true);
        if ($html === '') $html = (string) $post->post_content;
        $result = self::validate_html($html);
        update_post_meta($post_id, '_geo_webstory_amp_score', (int) $result['score']);
        update_post_meta($post_id, '_geo_webstory_amp_issues', $result['issues']);
        update_post_meta($post_id, '_geo_webstory_amp_checked_at', current_time('mysql'));
        return $result;
    }

    public static function validate_html(string $html): array {
        $issues = [];
        $score = 100;
        $checks = [
            'html amp' => preg_match('/<html[^>]+(amp|⚡)/i', $html) === 1,
            'amp-story' => stripos($html, '<amp-story') !== false,
            'AMP runtime' => stripos($html, 'cdn.ampproject.org/v0.js') !== false,
            'amp-story script' => stripos($html, 'amp-story-1.0.js') !== false,
            'canonical' => preg_match('/<link[^>]+rel=["\']canonical["\']/i', $html) === 1,
            'viewport' => preg_match('/<meta[^>]+name=["\']viewport["\']/i', $html) === 1,
            'boilerplate' => stripos($html, 'amp-boilerplate') !== false,
        ];
        foreach ($checks as $label => $ok) {
            if (!$ok) { $issues[] = 'Ausente: ' . $label; $score -= 10; }
        }

        preg_match_all('/<amp-story-page\b/i', $html, $pages);
        $page_count = count($pages[0] ?? []);
        if ($page_count < 5) { $issues[] = 'Poucos slides/páginas AMP: ' . $page_count; $score -= 15; }

        preg_match_all('/<amp-img\b[^>]*>/i', $html, $imgs);
        foreach (($imgs[0] ?? []) as $idx => $img) {
            if (!preg_match('/\bwidth=["\'][^"\']+["\']/i', $img)) { $issues[] = 'amp-img #' . ($idx + 1) . ' sem width'; $score -= 3; }
            if (!preg_match('/\bheight=["\'][^"\']+["\']/i', $img)) { $issues[] = 'amp-img #' . ($idx + 1) . ' sem height'; $score -= 3; }
            if (!preg_match('/\blayout=["\'][^"\']+["\']/i', $img)) { $issues[] = 'amp-img #' . ($idx + 1) . ' sem layout'; $score -= 3; }
        }
        if (preg_match_all('/<script\b(?![^>]+cdn\.ampproject\.org)[^>]*src=/i', $html, $bad_scripts)) {
            $n = count($bad_scripts[0] ?? []);
            if ($n > 0) { $issues[] = 'Scripts externos não AMP detectados: ' . $n; $score -= 20; }
        }
        $score = max(0, min(100, $score));
        return ['score' => $score, 'issues' => $issues, 'page_count' => $page_count, 'image_count' => count($imgs[0] ?? [])];
    }

    public static function render_panel(): void {
        if (!current_user_can('manage_options')) return;
        $stories = get_posts([
            'post_type' => 'geo-web-story',
            'post_status' => ['publish','draft','future'],
            'posts_per_page' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        ?>
        <div class="geo-card">
            <h2>Validação Web Stories AMP</h2>
            <p class="description">Validação local básica. Para publicação final, ainda recomenda-se testar a URL em uma ferramenta oficial AMP/Google.</p>
            <table class="widefat striped">
                <thead><tr><th>Story</th><th>Score</th><th>Última checagem</th><th>Problemas</th><th>Ação</th></tr></thead>
                <tbody>
                <?php if (empty($stories)): ?>
                    <tr><td colspan="5">Nenhuma Web Story encontrada.</td></tr>
                <?php endif; ?>
                <?php foreach ($stories as $story):
                    $score = get_post_meta($story->ID, '_geo_webstory_amp_score', true);
                    $issues = get_post_meta($story->ID, '_geo_webstory_amp_issues', true);
                    if ($score === '') {
                        $r = self::validate_post((int)$story->ID);
                        $score = $r['score'];
                        $issues = $r['issues'];
                    }
                    $issues = is_array($issues) ? $issues : [];
                    ?>
                    <tr>
                        <td>#<?php echo esc_html($story->ID); ?> — <?php echo esc_html(get_the_title($story)); ?></td>
                        <td><strong><?php echo esc_html((string)$score); ?>/100</strong></td>
                        <td><?php echo esc_html((string)get_post_meta($story->ID, '_geo_webstory_amp_checked_at', true)); ?></td>
                        <td><?php echo $issues ? esc_html(implode(' | ', array_slice($issues, 0, 4))) : '<span class="geo-ok">Sem problemas críticos locais</span>'; ?></td>
                        <td><a class="button" target="_blank" href="<?php echo esc_url(get_permalink($story)); ?>">Ver</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
