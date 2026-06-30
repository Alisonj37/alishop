<?php
namespace GeoMetodoSEO\Admin;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Production\ProductionGuard;

class ProductionGuardController {

    public function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $nonce = wp_create_nonce('geo_production_guard');
        $post_id = absint($_GET['post_id'] ?? 0);
        $recent = get_posts([
            'post_type' => 'post',
            'post_status' => ['publish','draft','future','pending'],
            'numberposts' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_geo_published_via', 'value' => 'central_publisher', 'compare' => '='],
                ['key' => '_geo_production_status', 'compare' => 'EXISTS'],
                ['key' => '_geo_generated_at', 'compare' => 'EXISTS'],
            ],
        ]);

        echo '<div class="wrap">';
        echo '<h1>Segurança de Produção</h1>';
        echo '<p>Validação operacional do Publisher central, imagens internas, schema/GEO/AEO e publicação segura.</p>';

        if ($post_id > 0) {
            $this->render_post_status($post_id, $nonce);
        }

        echo '<h2>Testador automático do Publisher</h2>';
        echo '<p>Cria posts de teste para validar draft, publish e future sem depender dos providers de IA.</p>';
        echo '<p>';
        echo '<button class="button" data-geo-test="draft">Criar rascunho de teste</button> ';
        echo '<button class="button" data-geo-test="publish">Criar publicado de teste</button> ';
        echo '<button class="button" data-geo-test="future">Criar agendado de teste</button>';
        echo '</p><pre id="geo-prod-test-result" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:900px;white-space:pre-wrap;"></pre>';

        echo '<h2>Últimos posts gerados/publicados pelo plugin</h2>';
        if (empty($recent)) {
            echo '<p>Nenhum post recente encontrado com metadados do GEO Método SEO.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Post</th><th>Status WP</th><th>Status produção</th><th>Imagens internas</th><th>Ações</th></tr></thead><tbody>';
            foreach ($recent as $p) {
                $prod = get_post_meta($p->ID, '_geo_production_status', true) ?: 'não validado';
                $img = class_exists(ProductionGuard::class) ? ProductionGuard::internal_images_status((int)$p->ID) : ['status'=>'indisponível'];
                echo '<tr>';
                echo '<td><strong>' . esc_html(get_the_title($p)) . '</strong><br><code>ID ' . intval($p->ID) . '</code></td>';
                echo '<td>' . esc_html(get_post_status($p)) . '</td>';
                echo '<td>' . esc_html($prod) . '</td>';
                echo '<td>' . esc_html($img['status'] ?? '') . ' — concluídas: ' . intval($img['done'] ?? 0) . '</td>';
                echo '<td><a class="button" href="' . esc_url(admin_url('admin.php?page=geo-production-guard&post_id=' . intval($p->ID))) . '">Validar</a> ';
                echo '<button class="button" data-geo-reprocess="' . intval($p->ID) . '">Reprocessar imagens</button></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        $this->render_script($nonce);
        echo '</div>';
    }

    private function render_post_status(int $post_id, string $nonce): void {
        $post = get_post($post_id);
        if (!$post) {
            echo '<div class="notice notice-error"><p>Post não encontrado.</p></div>';
            return;
        }
        $validation = ProductionGuard::validate_post($post_id);
        $report_raw = get_post_meta($post_id, '_geo_production_report', true);
        $report = $report_raw ? json_decode((string)$report_raw, true) : [];
        echo '<h2>Validação do post: ' . esc_html(get_the_title($post)) . '</h2>';
        echo '<p><a class="button" href="' . esc_url(get_edit_post_link($post_id)) . '">Editar post</a> <a class="button" target="_blank" href="' . esc_url(get_permalink($post_id)) . '">Ver no frontend</a> <button class="button button-primary" data-geo-validate="' . intval($post_id) . '">Revalidar agora</button> <button class="button" data-geo-reprocess="' . intval($post_id) . '">Reprocessar imagens internas</button></p>';
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Item</th><th>Status</th><th>Mensagem</th></tr></thead><tbody>';
        foreach ((array)($validation['checks'] ?? []) as $key => $check) {
            $ok = !empty($check['ok']);
            echo '<tr><td><code>' . esc_html($key) . '</code></td><td>' . ($ok ? '<span style="color:#008a20;font-weight:600">OK</span>' : '<span style="color:#b32d2e;font-weight:600">Atenção</span>') . '</td><td>' . esc_html($check['message'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>Status das imagens internas</h3><pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1000px;white-space:pre-wrap;">' . esc_html(wp_json_encode($validation['internal_images'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '<h3>Relatório final salvo no post</h3><pre id="geo-prod-post-result" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1000px;max-height:420px;overflow:auto;white-space:pre-wrap;">' . esc_html($report ? wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : 'Relatório final ainda não salvo para este post.') . '</pre>';
    }

    private function render_script(string $nonce): void {
        ?>
<script>
(function(){
  const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
  const nonce = <?php echo wp_json_encode($nonce); ?>;
  function post(action, data){
    const body = new URLSearchParams(Object.assign({action, nonce}, data || {}));
    return fetch(ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body}).then(r=>r.json());
  }
  document.addEventListener('click', function(e){
    const test = e.target && e.target.getAttribute('data-geo-test');
    const rep = e.target && e.target.getAttribute('data-geo-reprocess');
    const val = e.target && e.target.getAttribute('data-geo-validate');
    if(test){
      e.preventDefault();
      const out = document.getElementById('geo-prod-test-result');
      if(out) out.textContent = 'Executando teste...';
      post('geo_production_publisher_test', {mode:test}).then(j=>{ if(out) out.textContent = JSON.stringify(j, null, 2); });
    }
    if(rep){
      e.preventDefault();
      post('geo_production_reprocess_images', {post_id:rep}).then(j=>{ alert((j.data && j.data.message) ? j.data.message : JSON.stringify(j)); location.reload(); });
    }
    if(val){
      e.preventDefault();
      post('geo_production_validate_post', {post_id:val}).then(j=>{ const out=document.getElementById('geo-prod-post-result'); if(out) out.textContent = JSON.stringify(j, null, 2); });
    }
  });
})();
</script>
        <?php
    }
}
