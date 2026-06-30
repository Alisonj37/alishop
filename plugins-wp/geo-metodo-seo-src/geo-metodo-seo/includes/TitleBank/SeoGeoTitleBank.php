<?php
namespace GeoMetodoSEO\TitleBank;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\Services\ContentFormatter;
use GeoMetodoSEO\Services\LogService;

class SeoGeoTitleBank {
    const OPT = 'geo_seogeo_title_bank_v1';

    /** @var array<string,array<int,string>> */
    private static $existing_title_cache = [];

    public static function all(): array {
        $data = get_option(self::OPT, []);
        return is_array($data) ? $data : [];
    }

    /**
     * Save bank with transient-based lock to prevent race conditions.
     * @since 1.0.0: lock added (was raw update_option before).
     */
    public static function save(array $data): void {
        $lock_key = 'geo_titlebank_lock';
        $max_wait = 30; // tentativas (300ms cada = 9s total)

        for ($i = 0; $i < $max_wait; $i++) {
            if (!get_transient($lock_key)) {
                break;
            }
            usleep(300000); // 300ms
        }

        set_transient($lock_key, '1', 5);

        try {
            update_option(self::OPT, $data, false);
            self::$existing_title_cache = [];
        } finally {
            delete_transient($lock_key);
        }
    }

    public static function pending(int $limit = 80): array {
        $rows = array_values(array_filter(self::all(), fn($r) => ($r['status'] ?? '') === 'pending'));
        usort($rows, fn($a,$b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        return array_slice($rows, 0, $limit);
    }

    public static function count_pending(): int {
        return count(self::pending(9999));
    }


    /**
     * Schedule daily cleanup. Call this from the plugin bootstrap.
     * @since 1.0.0
     */
    public static function register_cron(): void {
        add_action('geo_titlebank_daily_cleanup', [__CLASS__, 'cleanup_old_used']);
        self::ensure_default_options();
        if (!wp_next_scheduled('geo_titlebank_daily_cleanup')) {
            wp_schedule_event(time() + 3600, 'daily', 'geo_titlebank_daily_cleanup');
        }
    }

    /**
     * Ensure option rows exist with safe defaults for admin visibility.
     * @since 1.0.0
     */
    public static function ensure_default_options(): void {
        $defaults = [
            'geo_titlebank_cleanup_days' => 30,
            'geo_titlebank_angle_threshold' => 1,
            'geo_titlebank_auto_mark' => '1',
            'geo_titlebank_match_threshold' => 0.92,
        ];
        foreach ($defaults as $key => $value) {
            if (get_option($key, null) === null) {
                update_option($key, $value, false);
            }
        }
    }

    /**
     * Unschedule cron — call this on plugin deactivation.
     * @since 1.0.0
     */
    public static function unregister_cron(): void {
        $ts = wp_next_scheduled('geo_titlebank_daily_cleanup');
        if ($ts) wp_unschedule_event($ts, 'geo_titlebank_daily_cleanup');
    }

    /**
     * Remove "used" titles older than N days (default 30).
     * Returns number of titles removed.
     * @since 1.0.0
     */
    public static function cleanup_old_used(?int $days = null): int {
        $days = (int) ($days ?? get_option('geo_titlebank_cleanup_days', 30));
        if ($days < 1) return 0;
        $threshold = strtotime("-{$days} days");
        if (!$threshold) return 0;

        $bank = self::all();
        $removed = 0;
        foreach ($bank as $id => $row) {
            if (($row['status'] ?? '') !== 'used') continue;
            $used_at = strtotime((string)($row['used_at'] ?? ''));
            if ($used_at && $used_at < $threshold) {
                unset($bank[$id]);
                $removed++;
            }
        }
        if ($removed > 0) {
            self::save($bank);
            if (class_exists(LogService::class)) {
                LogService::record('title', 'info',
                    "Banco de títulos: {$removed} títulos 'used' antigos removidos (> {$days} dias)",
                    ['action' => 'titlebank_cleanup']);
            }
        }
        return $removed;
    }

    /**
     * Manual cleanup trigger (admin button).
     * @since 1.0.0
     */
    public static function force_cleanup(): array {
        $removed = self::cleanup_old_used();
        return ['success' => true, 'removed' => $removed, 'remaining' => count(self::all())];
    }

    public static function generate(string $theme, string $niche = 'geral', string $type = 'misto', int $count = 50, string $provider = '', string $model = '', array $briefing = []): array {
        $theme = sanitize_text_field($theme);
        $niche = sanitize_text_field($niche ?: 'geral');
        $type  = sanitize_text_field($type ?: 'misto');
        $provider = ProviderResolver::for('title_generation', $provider ?: get_option('geo_title_ai_provider', ''));
        $model = $model ?: ProviderResolver::modelFor('title_generation', $provider);
        $count = max(5, min(80, $count));
        if ($theme === '') return ['success' => false, 'message' => 'Tema vazio.'];

        $existing = !empty($briefing['anti_cannibal']) ? self::collect_existing_titles($theme) : [];
        $prompt = self::build_prompt($theme, $niche, $type, $count, $existing, $briefing);
        $ai = new AIManager();
        $response = $ai->generateText($prompt, $provider, $model ?: null);
        if (!$response || $response->hasError() || trim((string)$response->getContent()) === '') {
            return ['success' => false, 'message' => 'Falha na IA: ' . ($response ? $response->getError() : 'sem resposta')];
        }

        $titles = self::extract_titles($response->getContent());
        $bank = self::all();
        $added = 0; $skipped = 0; $out = [];
        foreach ($titles as $title) {
            if (count($out) >= $count) break;
            [$ok, $reason] = self::validate_title($title, $theme, $bank, $briefing);
            if (!$ok) { $skipped++; continue; }
            $row = self::add_pending($bank, $title, $theme, $niche, self::detect_intent($title), 'ai_' . $provider);
            if ($row) { $added++; $out[] = $row['title']; } else { $skipped++; }
        }

        if ($added < $count) {
            foreach (self::fallback_candidates($theme, $niche, $type) as $title) {
                if ($added >= $count) break;
                [$ok] = self::validate_title($title, $theme, $bank, $briefing);
                if (!$ok) { $skipped++; continue; }
                $row = self::add_pending($bank, $title, $theme, $niche, self::detect_intent($title), 'local_expert_fallback');
                if ($row) { $added++; $out[] = $row['title']; } else { $skipped++; }
            }
        }

        self::save($bank);
        if (class_exists(LogService::class)) {
            LogService::record('title', 'success', "Banco de títulos SEO/GEO: {$added} adicionados, {$skipped} ignorados", ['action' => 'title_bank_generate', 'context' => array_merge(compact('theme','niche','type','provider'), ['briefing' => $briefing])]);
        }
        return ['success' => true, 'titles' => $out, 'added' => $added, 'skipped' => $skipped, 'pending' => self::pending(80)];
    }

    private static function build_prompt(string $theme, string $niche, string $type, int $count, array $existing, array $briefing = []): string {
        $year = date('Y');
        $existing_txt = implode("
", array_slice($existing, 0, 260));
        $contexto = trim((string)($briefing['contexto'] ?? ''));
        $publico = trim((string)($briefing['publico'] ?? ''));
        $intents = trim((string)($briefing['intents'] ?? ''));
        $extra = trim((string)($briefing['extra'] ?? ''));
        $anti = !empty($briefing['anti_cannibal']) ? 'ativada' : 'desativada';

        $briefing_txt = "BRIEFING EDITORIAL DO USUÁRIO:
"
            . "- Tema principal: {$theme}
"
            . "- Nicho: {$niche}
"
            . "- Tipo preferido: {$type}
"
            . "- Contexto/recorte: " . ($contexto !== '' ? $contexto : 'não informado') . "
"
            . "- Público-alvo: " . ($publico !== '' ? $publico : 'não informado') . "
"
            . "- Intenções desejadas: " . ($intents !== '' ? $intents : 'misto') . "
"
            . "- Instruções extras: " . ($extra !== '' ? $extra : 'nenhuma') . "
"
            . "- Anti-canibalização: {$anti}
";

        return "Você é um AGENTE ESPECIALISTA EM TÍTULOS SEO/GEO/AEO/LLM, editor sênior de conteúdo para Google, AI Overviews, ChatGPT, Gemini e Perplexity.

"
            . "MISSÃO: gerar exatamente {$count} títulos profissionais em português brasileiro para artigos sobre: \"{$theme}\".
"
            . "Nicho: {$niche}. Tipo preferido: {$type}. Ano editorial: {$year}.

"
            . $briefing_txt . "
"
            . "REGRAS OBRIGATÓRIAS:
"
            . "- Não gere títulos básicos, genéricos, vagos ou parecidos entre si.
"
            . "- Use obrigatoriamente o contexto, público-alvo, intenções e instruções extras quando forem informados.
"
            . "- Se o usuário especificou marca, produto, plataforma ou recorte editorial, os títulos devem permanecer nesse ecossistema.
"
            . "- Não use títulos como 'Guia completo de...', 'Tudo sobre...', 'O que é...' de forma genérica. Só use se houver ângulo específico.
"
            . "- Cada título deve ter intenção de busca real: informacional, tutorial, comparativo, problema/solução, comercial investigativa ou opinião técnica cautelosa.
"
            . "- Cada título precisa ter uma lacuna específica: público, problema, ferramenta, cenário, etapa, erro, comparação ou objetivo.
"
            . "- Não invente dados, fontes, estudos, rankings, estatísticas ou promessas de resultado.
"
            . "- Evite canibalização: não gere títulos iguais ou muito próximos dos já existentes.
"
            . "- Títulos entre 45 e 90 caracteres, naturais, clicáveis e editoriais.
"
            . "- Inclua SEO/GEO/IA/entidades relacionadas quando fizer sentido real, sem forçar.

"
            . "TÍTULOS JÁ EXISTENTES/PENDENTES PARA EVITAR:
{$existing_txt}

"
            . "FORMATO: JSON puro, sem markdown, com este formato:
"
            . '{"items":[{"title":"Como usar entidades semânticas para melhorar SEO em buscas com IA","intent":"tutorial","reason":"lacuna específica e intenção real"}]}' ;
    }

    private static function extract_titles(string $raw): array {
        $raw = trim($raw);
        $data = class_exists(ContentFormatter::class) ? ContentFormatter::extractJson($raw) : null;
        $items = is_array($data) ? ($data['items'] ?? $data) : null;
        $titles = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                $t = is_array($item) ? ($item['title'] ?? '') : $item;
                $t = trim(wp_strip_all_tags((string)$t));
                if ($t !== '') $titles[] = $t;
            }
        }
        if (!$titles && preg_match('/\[[\s\S]+\]/u', $raw, $m)) {
            $arr = json_decode($m[0], true);
            if (is_array($arr)) foreach ($arr as $t) if (is_string($t)) $titles[] = trim($t);
        }
        if (!$titles) {
            foreach (preg_split('/\R+/', $raw) as $line) {
                $line = preg_replace('/^[-*\d\.\)\s]+/u', '', trim($line));
                $line = trim($line, " \t\n\r\0\x0B\"'");
                if (mb_strlen($line) > 20) $titles[] = $line;
            }
        }
        return array_values(array_unique(array_filter($titles)));
    }

    private static function add_pending(array &$bank, string $title, string $theme, string $niche, string $intent, string $source): ?array {
        $id = md5(self::normalize($title));
        if (isset($bank[$id])) return null;
        $row = [
            'id' => $id,
            'title' => trim($title),
            'theme' => $theme,
            'niche' => $niche,
            'intent' => $intent,
            'status' => 'pending',
            'source' => $source,
            'created_at' => current_time('mysql'),
            'used_at' => '',
            'used_context' => '',
            'post_id' => 0,
        ];
        $bank[$id] = $row;
        return $row;
    }

    /**
     * Mark a title as used. Optional via setting.
     * @since 1.0.0: respects 'geo_titlebank_auto_mark' setting (default '1' = on).
     *               Threshold now configurable via 'geo_titlebank_match_threshold'.
     *
     * @param string $title    Title to mark
     * @param string $context  Context where used (manual, individual, bulk_cron, etc)
     * @param int    $post_id  Resulting post ID
     * @return bool  True if any title was marked
     */
    public static function mark_used_by_title(string $title, string $context = 'unknown', int $post_id = 0): bool {
        $auto_mark = (string) get_option('geo_titlebank_auto_mark', '1') === '1';
        if (!$auto_mark) {
            return false;
        }

        $title = trim(wp_strip_all_tags($title));
        if ($title === '') return false;

        $threshold = (float) get_option('geo_titlebank_match_threshold', 0.92);
        $threshold = max(0.70, min(1.0, $threshold));

        $bank = self::all();
        $norm = self::normalize($title);
        $changed = false;

        foreach ($bank as $id => &$row) {
            if (($row['status'] ?? '') !== 'pending') continue;
            $rn = self::normalize((string)($row['title'] ?? ''));
            if ($rn === $norm || self::similarity($rn, $norm) >= $threshold) {
                $row['status'] = 'used';
                $row['used_at'] = current_time('mysql');
                $row['used_context'] = $context;
                $row['post_id'] = $post_id;
                $changed = true;
            }
        }
        unset($row);
        if ($changed) self::save($bank);
        return $changed;
    }

    /**
     * Manual mark for admin button — bypasses auto_mark setting.
     * @since 1.0.0
     */
    public static function mark_used_manual(string $id, int $post_id = 0): bool {
        $bank = self::all();
        if (!isset($bank[$id])) return false;
        $bank[$id]['status'] = 'used';
        $bank[$id]['used_at'] = current_time('mysql');
        $bank[$id]['used_context'] = 'manual_mark';
        $bank[$id]['post_id'] = $post_id;
        self::save($bank);
        return true;
    }

    public static function render_picker(string $target_selector, string $mode = 'single'): string {
        $pending = self::pending(60);
        $nonce = wp_create_nonce('geo_title_bank_nonce');
        ob_start(); ?>
        <div class="geo-title-bank-picker" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;margin:14px 0;">
            <strong>🧠 Banco de Títulos SEO/GEO</strong>
            <span style="font-size:12px;color:#64748b;"> — <?php echo count($pending); ?> títulos pendentes sem uso</span>
            <?php if (empty($pending)): ?>
                <p style="margin:8px 0 0;color:#64748b;font-size:13px;">Nenhum título pendente. Use o Gerador de Títulos para criar títulos profissionais.</p>
            <?php else: ?>
                <div style="display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap;">
                    <select class="geo-title-bank-select" style="max-width:100%;min-width:260px;">
                        <option value="">— escolher título salvo —</option>
                        <?php foreach ($pending as $r): ?>
                            <option value="<?php echo esc_attr($r['title']); ?>" data-id="<?php echo esc_attr($r['id']); ?>"><?php echo esc_html($r['title']); ?><?php echo !empty($r['intent']) ? ' [' . esc_html($r['intent']) . ']' : ''; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button geo-title-bank-use" data-target="<?php echo esc_attr($target_selector); ?>" data-mode="<?php echo esc_attr($mode); ?>">Usar título</button>
                    <?php if ($mode === 'multi'): ?><button type="button" class="button geo-title-bank-use-all" data-target="<?php echo esc_attr($target_selector); ?>">Usar todos pendentes</button><?php endif; ?>
                </div>
                <p style="margin:6px 0 0;color:#64748b;font-size:12px;">Ao gerar/agendar um artigo com este título, ele será marcado como usado e sumirá da lista global.</p>
            <?php endif; ?>
        </div>
        <script>
        window.geoTitleBankBind = window.geoTitleBankBind || function(){
            document.querySelectorAll('.geo-title-bank-use').forEach(function(btn){ if(btn.dataset.bound) return; btn.dataset.bound='1'; btn.addEventListener('click', function(){
                var wrap=this.closest('.geo-title-bank-picker'); var sel=wrap.querySelector('.geo-title-bank-select'); if(!sel || !sel.value) return alert('Escolha um título.');
                var target=document.querySelector(this.dataset.target); if(!target) return alert('Campo de destino não encontrado.');
                if(this.dataset.mode==='multi' && target.tagName==='TEXTAREA') { target.value = (target.value ? target.value.trim()+'\n' : '') + sel.value; } else { target.value=sel.value; }
                target.dispatchEvent(new Event('input',{bubbles:true})); target.focus();
            }); });
            document.querySelectorAll('.geo-title-bank-use-all').forEach(function(btn){ if(btn.dataset.bound) return; btn.dataset.bound='1'; btn.addEventListener('click', function(){
                var target=document.querySelector(this.dataset.target); if(!target) return alert('Campo de destino não encontrado.');
                var opts=[].slice.call(this.closest('.geo-title-bank-picker').querySelectorAll('option')).map(function(o){return o.value;}).filter(Boolean);
                target.value = (target.value ? target.value.trim()+'\n' : '') + opts.join('\n'); target.dispatchEvent(new Event('input',{bubbles:true})); target.focus();
            }); });
        }; window.geoTitleBankBind();
        </script>
        <?php return ob_get_clean();
    }

    public static function collect_existing_titles(string $theme = ''): array {
        $titles = [];
        foreach (self::all() as $r) if (!empty($r['title'])) $titles[] = $r['title'];
        $args = ['post_type' => ['post','page','geo_glossary'], 'post_status' => ['publish','draft','future','pending','private'], 'posts_per_page' => 500, 'fields' => 'ids'];
        foreach (get_posts($args) as $pid) $titles[] = get_the_title($pid);
        if ($theme) {
            $args['s'] = $theme;
            foreach (get_posts($args) as $pid) $titles[] = get_the_title($pid);
        }
        return array_values(array_unique(array_filter($titles)));
    }

    public static function validate_title(string $title, string $theme, array $bank = [], array $briefing = []): array {
        $title = trim(wp_strip_all_tags($title));
        if (mb_strlen($title) < 35 || mb_strlen($title) > 115) return [false, 'tamanho fraco'];
        $bad = ['guia completo de', 'tudo sobre', 'melhores dicas de', 'segredos de', 'imperdível', 'garantido', 'chocante', 'milagroso'];
        $tl = mb_strtolower(remove_accents($title));
        foreach ($bad as $b) if (strpos($tl, $b) !== false) return [false, 'genérico/clickbait'];
        $generic_patterns = ['/^o que e [a-z0-9\s]{3,25}$/i','/^como fazer [a-z0-9\s]{3,25}$/i','/^guia de [a-z0-9\s]{3,25}$/i'];
        foreach ($generic_patterns as $p) if (preg_match($p, remove_accents($tl))) return [false, 'genérico'];
        if (!self::theme_relevance_check($title, $theme, $briefing)) return [false, 'fora do tema'];
        if (!self::has_specific_angle($title)) return [false, 'sem ângulo específico'];
        if (self::exists_or_cannibalizes($title, $theme, $bank)) return [false, 'duplicado/canibalização'];
        return [true, 'ok'];
    }

    private static function theme_relevance_check(string $title, string $theme, array $briefing = []): bool {
        $title_l = mb_strtolower(remove_accents($title));
        $theme_l = mb_strtolower(remove_accents($theme . ' ' . (string)($briefing['contexto'] ?? '') . ' ' . (string)($briefing['publico'] ?? '')));
        $theme_words = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/i', ' ', $theme_l));
        $theme_words = array_values(array_filter($theme_words, fn($w) => mb_strlen($w) >= 3 && !in_array($w, ['para','com','sem','sobre','como','que'], true)));
        if (empty($theme_words)) return true;
        foreach ($theme_words as $w) {
            if (strpos($title_l, $w) !== false) return true;
        }
        $semantic_groups = [
            'seo' => ['google','busca','ranking','indexacao','palavra chave','keyword','conteudo','schema','link','backlink','snippet','search console','rank math','yoast','serp'],
            'ia' => ['inteligencia artificial','ai','llm','chatgpt','gemini','perplexity','modelos','respostas de ia','ai overviews','busca generativa'],
            'marketing' => ['funil','conversao','trafego','campanha','lead','vendas','conteudo','anuncios','copywriting'],
            'wordpress' => ['plugin','tema','rank math','yoast','elementor','woocommerce','site','blog'],
        ];
        foreach ($semantic_groups as $root => $words) {
            if (strpos($theme_l, $root) !== false) {
                foreach ($words as $w) if (strpos($title_l, $w) !== false) return true;
            }
        }
        return false;
    }

    /**
     * Check if title has a specific angle (not too generic).
     * @since 1.0.0: threshold now configurable, default lowered from 2 to 1.
     *               Word list expanded with more legitimate angles.
     */
    private static function has_specific_angle(string $title, array $briefing = []): bool {
        $t = mb_strtolower(remove_accents($title));
        $angles = [
            'como','por que','quando','diferen','compar','erros','evitar',
            'ferramenta','passo','checklist','estrateg','metric','auditoria',
            'exemplo','vale a pena','antes de','depois de',
            'iniciante','avancado','profissional','especialista',
            'wordpress','google','ia','seo','geo','aeo','llm',
            'shopify','elementor','rank math','yoast','schema',
            'guia','tutorial','review','analise','planejamento',
            'b2b','b2c','ecommerce','startup','agencia','equipe',
            'tendencia','futuro','novidade','atualizacao','versao',
        ];

        $extra_angles = preg_split('/[,;]+/', (string)($briefing['intents'] ?? ''));
        foreach ($extra_angles as $ea) {
            $ea = trim(mb_strtolower(remove_accents($ea)));
            if ($ea !== '') $angles[] = $ea;
        }

        $required = (int) get_option('geo_titlebank_angle_threshold', 1);
        $required = max(1, min(3, $required));

        $hits = 0;
        foreach ($angles as $a) {
            if (strpos($t, $a) !== false) $hits++;
            if ($hits >= $required) return true;
        }
        return $hits >= $required;
    }

    public static function exists_or_cannibalizes(string $title, string $theme = '', array $bank = []): bool {
        $norm = self::normalize($title);
        foreach ($bank ?: self::all() as $row) {
            $t = self::normalize((string)($row['title'] ?? ''));
            if ($t && ($t === $norm || self::similarity($t, $norm) >= 0.86)) return true;
        }

        $cache_key = md5((string) $theme);
        if (!isset(self::$existing_title_cache[$cache_key])) {
            self::$existing_title_cache[$cache_key] = array_values(array_filter(array_map([__CLASS__, 'normalize'], self::collect_existing_titles($theme))));
        }
        foreach (self::$existing_title_cache[$cache_key] as $t) {
            if ($t && ($t === $norm || self::similarity($t, $norm) >= 0.82)) return true;
        }
        return false;
    }

    public static function normalize(string $s): string {
        $s = mb_strtolower(remove_accents(wp_strip_all_tags($s)));
        $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
        $stop = ['o','a','os','as','um','uma','de','do','da','dos','das','e','em','no','na','nos','nas','para','com','sem','por','que','como','qual','quais'];
        $words = array_filter(preg_split('/\s+/', $s), fn($w) => $w !== '' && !in_array($w, $stop, true));
        return trim(implode(' ', $words));
    }

    private static function similarity(string $a, string $b): float {
        if ($a === '' || $b === '') return 0.0;
        similar_text($a, $b, $pct);
        $wa = array_unique(explode(' ', $a)); $wb = array_unique(explode(' ', $b));
        $inter = count(array_intersect($wa,$wb)); $union = max(1, count(array_unique(array_merge($wa,$wb))));
        return max($pct/100, $inter/$union);
    }

    private static function detect_intent(string $title): string {
        $t = mb_strtolower(remove_accents($title));
        if (preg_match('/\b(como|passo a passo|configurar|usar|criar|fazer)\b/', $t)) return 'tutorial';
        if (preg_match('/\b(vs|versus|comparativo|diferen[cc]a|melhor|qual)\b/', $t)) return 'comparativa';
        if (preg_match('/\b(erro|problema|corrigir|evitar|por que)\b/', $t)) return 'problema/solução';
        if (preg_match('/\b(preço|vale a pena|comprar|ferramenta|plano)\b/', $t)) return 'comercial investigativa';
        return 'informacional';
    }

    private static function fallback_candidates(string $theme, string $niche, string $type): array {
        $t = trim($theme);
        $templates = [
            "Como criar clusters de conteúdo sobre {$t} sem canibalizar palavras-chave",
            "Como adaptar {$t} para aparecer melhor em respostas de IA",
            "Por que {$t} precisa de entidades semânticas para ranquear melhor",
            "Como usar Search Console para encontrar lacunas em {$t}",
            "Erros de conteúdo sobre {$t} que reduzem autoridade temática",
            "Como estruturar um artigo sobre {$t} para Google AI Overviews",
            "Diferença entre SEO tradicional e otimização de {$t} para IA",
            "Como criar FAQ estratégico sobre {$t} sem conteúdo genérico",
            "Como evitar canibalização ao publicar vários artigos sobre {$t}",
            "Checklist de SEO on-page para conteúdos sobre {$t}",
            "Como usar links internos para fortalecer páginas sobre {$t}",
            "Por que artigos sobre {$t} precisam de intenção de busca clara",
            "Como transformar {$t} em pauta editorial com alto potencial semântico",
            "Quais métricas analisar antes de publicar conteúdo sobre {$t}",
            "Como escrever títulos sobre {$t} que não pareçam genéricos",
            "Como usar schema e FAQPage em conteúdos sobre {$t}",
            "O que revisar antes de publicar um artigo sobre {$t}",
            "Como atualizar posts antigos sobre {$t} sem perder SEO",
            "Como combinar GEO e AEO em conteúdos sobre {$t}",
            "Como criar uma estratégia de topical authority para {$t}",
        ];
        $angles = ['iniciante','WordPress','Google','IA generativa','Search Console','Rank Math','conteúdo pilar','blog de nicho','links internos','FAQPage','AI Overviews','LLMs','entidades semânticas','calendário editorial','auditoria SEO','interlinking'];
        $actions = ['Como aplicar','Como corrigir','Como planejar','Como medir','Como otimizar','Como comparar','Como validar','Como automatizar com segurança','Como criar um checklist de','Como evitar erros em'];
        foreach ($actions as $a) {
            foreach ($angles as $angle) {
                $templates[] = "{$a} {$t} para {$angle} sem gerar conteúdo genérico";
                $templates[] = "Por que {$t} em {$angle} exige intenção de busca real";
            }
        }
        return array_values(array_unique($templates));
    }


    /**
     * Export bank as JSON string.
     * @since 1.0.0
     */
    public static function export_json(bool $only_pending = false): string {
        $bank = self::all();
        if ($only_pending) {
            $bank = array_filter($bank, fn($r) => ($r['status'] ?? '') === 'pending');
        }
        $payload = [
            'version' => '1.0.0',
            'exported_at' => current_time('mysql'),
            'site_url' => home_url(),
            'count' => count($bank),
            'titles' => array_values($bank),
        ];
        return wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Import bank from JSON string.
     * Modes: merge or replace.
     * @since 1.0.0
     */
    public static function import_json(string $json, string $mode = 'merge'): array {
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['titles']) || !is_array($data['titles'])) {
            return ['success' => false, 'message' => 'JSON inválido ou vazio.'];
        }

        $bank = ($mode === 'replace') ? [] : self::all();
        $added = 0;
        $skipped = 0;

        foreach ($data['titles'] as $row) {
            if (empty($row['title'])) { $skipped++; continue; }
            $id = $row['id'] ?? md5(self::normalize((string)$row['title']));

            if ($mode === 'merge' && isset($bank[$id])) {
                $skipped++;
                continue;
            }

            $bank[$id] = [
                'id' => $id,
                'title' => trim((string)$row['title']),
                'theme' => sanitize_text_field((string)($row['theme'] ?? '')),
                'niche' => sanitize_text_field((string)($row['niche'] ?? 'geral')),
                'intent' => sanitize_text_field((string)($row['intent'] ?? 'informacional')),
                'status' => in_array(($row['status'] ?? 'pending'), ['pending','used'], true) ? $row['status'] : 'pending',
                'source' => sanitize_text_field((string)($row['source'] ?? 'imported')),
                'created_at' => $row['created_at'] ?? current_time('mysql'),
                'used_at' => $row['used_at'] ?? '',
                'used_context' => $row['used_context'] ?? '',
                'post_id' => (int) ($row['post_id'] ?? 0),
            ];
            $added++;
        }

        self::save($bank);

        if (class_exists(LogService::class)) {
            LogService::record('title', 'success',
                "Banco de títulos: {$added} importados, {$skipped} ignorados (modo: {$mode})",
                ['action' => 'titlebank_import']);
        }

        return [
            'success' => true,
            'added' => $added,
            'skipped' => $skipped,
            'total' => count($bank),
            'mode' => $mode,
        ];
    }

}
