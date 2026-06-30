<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) exit;

/**
 * Auditoria passiva de rotas AJAX do plugin.
 * Não bloqueia execução; apenas mostra pontos que precisam revisão manual.
 */
class AjaxSecurityAuditService {

    public static function scan(): array {
        $root = defined('GEO_METODO_SEO_PATH') ? GEO_METODO_SEO_PATH : dirname(__DIR__, 2) . '/';
        $files = array_merge(
            glob($root . '*.php') ?: [],
            self::rglob($root . 'includes', '*.php'),
            self::rglob($root . 'sara-autopilot', '*.php')
        );

        $rows = [];
        foreach ($files as $file) {
            if (!is_readable($file)) continue;
            $code = (string) file_get_contents($file);
            if (!preg_match_all('/add_action\s*\(\s*[\'\"]wp_ajax_([^\'\"]+)[\'\"]/i', $code, $m)) continue;
            foreach ($m[1] as $action) {
                $rows[] = [
                    'action' => sanitize_key($action),
                    'file' => str_replace($root, '', $file),
                    'nonce' => self::has_nonce_check($code),
                    'capability' => self::has_capability_check($code),
                    'sanitize' => self::has_sanitization($code),
                ];
            }
        }
        usort($rows, fn($a, $b) => strcmp($a['action'], $b['action']));
        return $rows;
    }

    public static function render_panel(): void {
        if (!current_user_can('manage_options')) return;
        $rows = self::scan();
        $total = count($rows);
        $ok = 0;
        foreach ($rows as $r) {
            if ($r['nonce'] && $r['capability']) $ok++;
        }
        ?>
        <div class="geo-card">
            <h2>Auditoria AJAX</h2>
            <p class="description">Varredura passiva das rotas AJAX do plugin. Ela não substitui auditoria manual, mas ajuda a localizar rotas que precisam revisão.</p>
            <p><strong><?php echo esc_html($ok); ?></strong> de <strong><?php echo esc_html($total); ?></strong> rotas aparentam ter nonce + capability no arquivo onde foram registradas.</p>
            <div style="max-height:420px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;">
                <table class="widefat striped">
                    <thead><tr><th>Ação</th><th>Arquivo</th><th>Nonce</th><th>Capability</th><th>Sanitize</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $status = ($r['nonce'] && $r['capability']) ? 'OK' : 'Revisar';
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($r['action']); ?></code></td>
                            <td><?php echo esc_html($r['file']); ?></td>
                            <td><?php echo $r['nonce'] ? '✅' : '⚠️'; ?></td>
                            <td><?php echo $r['capability'] ? '✅' : '⚠️'; ?></td>
                            <td><?php echo $r['sanitize'] ? '✅' : '⚠️'; ?></td>
                            <td><?php echo $status === 'OK' ? '<span class="geo-ok">OK</span>' : '<span class="geo-warn">Revisar</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private static function rglob(string $dir, string $pattern): array {
        if (!is_dir($dir)) return [];
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $out[] = $file->getPathname();
            }
        }
        return $out;
    }

    private static function has_nonce_check(string $code): bool {
        return preg_match('/check_ajax_referer|wp_verify_nonce|self::check_nonce|SecurityHelper::verify_nonce/i', $code) === 1;
    }

    private static function has_capability_check(string $code): bool {
        return preg_match('/current_user_can|manage_options|edit_posts|SecurityHelper::current_user_can/i', $code) === 1;
    }

    private static function has_sanitization(string $code): bool {
        return preg_match('/sanitize_text_field|sanitize_key|absint|intval|wp_kses_post|esc_url_raw|sanitize_textarea_field/i', $code) === 1;
    }
}
