<?php

namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;

/**
 * SARAService — Specialized AI for Ranking & Authority.
 * CORRIGIDO v1.0.0: usa generateText() + AIResponse corretamente.
 */
class SARAService {

    private $system_prompt;
    private $ai;

    public function __construct() {
        $this->ai            = new AIManager();
        $this->system_prompt = $this->buildSystemPrompt();
    }

    public function chat( string $question, array $site_context = [], array $history = [], string $provider = '' ): string {
        $provider = ProviderResolver::for('article_generation', $provider);
        $ctx      = $this->buildSiteContext($site_context);
        $hist     = '';
        foreach (array_slice($history, -4) as $t) {
            $role  = $t['role'] === 'user' ? 'Usuário' : 'SARA';
            $hist .= "\n{$role}: " . mb_substr($t['content'], 0, 300);
        }
        $prompt  = $this->system_prompt;
        $prompt .= $ctx  ? "\n\n=== DADOS DO SITE ===\n{$ctx}"  : '';
        $prompt .= $hist ? "\n\n=== HISTÓRICO ===\n{$hist}"    : '';
        // Auto-salvar contexto relevante na memória
        $memory_triggers = ['nicho', 'segmento', 'publico', 'audiencia', 'objetivo', 'foco', 'sobre meu site'];
        foreach ($memory_triggers as $trigger) {
            if (stripos($question, $trigger) !== false) {
                self::save_memory('contexto_' . sanitize_key($trigger), mb_substr($question, 0, 300));
                break;
            }
        }
        $prompt .= "\n\n=== PERGUNTA ===\nUsuário: {$question}\n\nSARA:";
        $result  = $this->callAI($prompt, $provider);
        return $result ?: 'Não consegui processar. Verifique se a API Key do provedor de IA está configurada em Configurações > Provedores de IA.';
    }

    public function analyzeKeyword( string $keyword, string $provider = '' ): array {
        $provider = ProviderResolver::for('article_generation', $provider);
        $task = "Analise a keyword: \"{$keyword}\"\n\nRetorne APENAS JSON válido sem markdown:\n"
              . '{"keyword":"","intent":"informacional","difficulty":"media","opportunity_score":50,"geo_score":50,'
              . '"suggested_title":"","suggested_h2s":["h2 1","h2 2","h2 3"],'
              . '"lsi_keywords":["lsi1","lsi2"],"long_tail":["lt1","lt2"],"paa_questions":["q1?","q2?"],'
              . '"content_angle":"","seo_recommendations":["rec1"],"geo_recommendations":["geo1"],"competitor_insight":""}';
        $result = $this->callAI($this->system_prompt . "\n\n=== ANÁLISE KEYWORD ===\n" . $task, $provider);
        return $result ? $this->parseJson($result) : [];
    }

    public function analyzeCompetitors( string $keyword, bool $use_web_search = false, string $provider = '' ): array {
        $provider = ProviderResolver::for('article_generation', $provider);
        $web_block = '';

        // 1ª opção: SerpAPI (dados reais do Google — melhor qualidade)
        $serpapi_key = get_option('geo_serpapi_key', '');
        if ($serpapi_key) {
            $serp_response = wp_remote_get(
                'https://serpapi.com/search.json?' . http_build_query([
                    'q' => $keyword, 'api_key' => $serpapi_key,
                    'num' => 10, 'hl' => 'pt', 'gl' => 'br',
                ]),
                ['timeout' => 15]
            );
            if (!is_wp_error($serp_response) && wp_remote_retrieve_response_code($serp_response) === 200) {
                $serp_json = json_decode(wp_remote_retrieve_body($serp_response), true);
                $results   = array_slice($serp_json['organic_results'] ?? [], 0, 10);
                if (!empty($results)) {
                    $lines = ["=== TOP 10 GOOGLE — DADOS REAIS (SerpAPI) ==="];
                    foreach ($results as $r) {
                        $lines[] = "- Título: " . ($r['title'] ?? '') . " | URL: " . ($r['link'] ?? '') . " | Snippet: " . mb_substr($r['snippet'] ?? '', 0, 150);
                    }
                    $web_block = "\n\n" . implode("\n", $lines);
                }
            }
        }

        // 2ª opção: busca web via OpenAI somente quando o provedor selecionado for OpenAI.
        // Se o usuário escolheu Groq/Gemini/Claude/Perplexity/Naga, não aciona OpenAI escondido.
        if (!$web_block && $use_web_search && $provider === 'openai') {
            try {
                $openai = new \GeoMetodoSEO\AI\Providers\OpenAIProvider();
                $web    = $openai->generateWithSearch('Top 5 resultados Google para: "' . $keyword . '". Liste título, URL e diferenciais de conteúdo.');
                if ($web && $web->getContent()) {
                    $web_block = "\n\n=== DADOS SERP ===\n" . $web->getContent();
                }
            } catch (\Exception $e) {}
        }

        $year = date('Y');
        $task = "Você é especialista em SEO e análise competitiva. Keyword: \"{$keyword}\" (ano {$year})\n\n"
              . ($web_block ? "Dados dos concorrentes:{$web_block}\n\n" : "Analise esta keyword com base no seu conhecimento do mercado.\n\n")
              . "Retorne APENAS JSON válido sem markdown, sem texto antes ou depois:\n"
              . '{"keyword":"' . addslashes($keyword) . '","serp_overview":"[Visão geral do cenário competitivo — dificuldade, tipo de conteúdo dominante, oportunidades]","content_gaps":["[gap específico 1]","[gap específico 2]","[gap específico 3]"],"winning_angle":"[Ângulo único para ganhar: o que os top 10 não cobrem bem]","must_have_sections":["[seção obrigatória 1]","[seção obrigatória 2]","[seção obrigatória 3]","[seção obrigatória 4]"],"differentiation_tips":["[dica diferenciação 1]","[dica diferenciação 2]","[dica diferenciação 3]"],"geo_opportunity":"[Como otimizar para aparecer em respostas de IA: ChatGPT, Gemini, Perplexity]","difficulty_assessment":"[Dificuldade: Baixa/Média/Alta — com justificativa baseada em dados]"}';

        $result = $this->callAI($task, $provider);

        if (!$result) return [];
        $data = $this->parseJson($result);

        // Validar campos mínimos
        if (empty($data['keyword']) && empty($data['serp_overview']) && empty($data['content_gaps'])) {
            LogService::log('error', 'SARA Competitors: JSON sem campos esperados | raw: ' . mb_substr($result, 0, 200));
            return [];
        }

        return $data;
    }

    public function auditPost( int $post_id, string $provider = '' ): array {
        $post = get_post($post_id);
        if (!$post) return [];
        $provider   = ProviderResolver::for('article_generation', $provider);
        $keyword    = get_post_meta($post_id, '_geo_keyword', true) ?: $post->post_title;
        $word_count = str_word_count(wp_strip_all_tags($post->post_content));
        $content    = mb_substr(wp_strip_all_tags($post->post_content), 0, 2500);
        $year       = date('Y');
        $pub_date   = get_the_date('Y', $post_id);
        $has_faq    = strpos($post->post_content, 'geo-faq-item') !== false || strpos($post->post_content, 'FAQPage') !== false;
        $has_table  = strpos($post->post_content, '<table') !== false;
        $has_img    = strpos($post->post_content, '<img') !== false;
        $has_h2     = substr_count($post->post_content, '<h2') >= 3;
        $rank_score = get_post_meta($post_id, 'rank_math_seo_score', true);

        $task = "AUDITORIA REAL DO ARTIGO — baseie-se APENAS no conteúdo fornecido abaixo. NAO invente problemas.

"
              . "ANO ATUAL: {$year} (artigo publicado em: {$pub_date})
"
              . "IMPORTANTE: O ano {$year} NAO é futuro — é o ano ATUAL. Nao critique o uso de {$year} como 'ano futuro'.

"
              . "DADOS REAIS VERIFICADOS DO ARTIGO:
"
              . "- Titulo: {$post->post_title}
"
              . "- Keyword: {$keyword}
"
              . "- Palavras: {$word_count}
"
              . "- FAQ presente: " . ($has_faq ? 'SIM' : 'NAO') . "
"
              . "- Tabela presente: " . ($has_table ? 'SIM' : 'NAO') . "
"
              . "- Imagens no corpo: " . ($has_img ? 'SIM' : 'NAO') . "
"
              . "- H2s suficientes (3+): " . ($has_h2 ? 'SIM' : 'NAO') . "
"
              . "- Score Rank Math: " . ($rank_score ?: 'nao disponivel') . "

"
              . "CONTEUDO DO ARTIGO (primeiros 2500 chars):
{$content}

"
              . "REGRAS ABSOLUTAS para a auditoria:
"
              . "1. Se o dado confirma que FAQ existe, NAO diga que falta FAQ
"
              . "2. Se o dado confirma que ha imagens, NAO diga que faltam imagens
"
              . "3. Se o dado confirma que ha tabela, NAO diga que falta tabela
"
              . "4. {$year} eh o ano ATUAL — NUNCA critique como 'ano futuro' ou 'conteudo perecivel'
"
              . "5. Identifique apenas problemas REAIS baseados no conteudo fornecido
"
              . "6. NAO invente problemas que nao existem

"
              . "Retorne APENAS JSON valido sem markdown:
"
              . '{"overall_score":0,"seo_score":0,"geo_score":0,"eeat_score":0,"readability_score":0,'
              . '"critical_issues":["problema real identificado no conteudo"],'
              . '"improvements":[{"priority":"alta","action":"acao especifica baseada no conteudo real","impact":"impacto esperado"}],'
              . '"geo_improvements":["melhoria geo baseada no conteudo real"],"recommended_additions":["adicao especifica"]}';
        $result = $this->callAI($this->system_prompt . "

=== AUDITORIA POST ===
" . $task, $provider);
        if (!$result) return [];
        $data = $this->parseJson($result);
        if (!empty($data['overall_score'])) {
            update_post_meta($post_id, '_geo_sara_score', $data['overall_score']);
            update_post_meta($post_id, '_geo_sara_audit', time());
        }
        return $data;
    }

    public function generateCluster( string $main_keyword, int $num_satellites = 10, string $provider = '' ): array {
        $provider = ProviderResolver::for('article_generation', $provider);
        $task = "Keyword pilar: \"{$main_keyword}\" | Satélites: {$num_satellites}\n\n"
              . "Retorne APENAS JSON válido sem markdown:\n"
              . '{"pillar":{"keyword":"","title":"","word_count":3000},'
              . '"satellites":[{"keyword":"","title":"","word_count":1200,"internal_link_anchor":""}],'
              . '"geo_strategy":""}';
        $result = $this->callAI($this->system_prompt . "\n\n=== CLUSTER SEMÂNTICO ===\n" . $task, $provider);
        return $result ? $this->parseJson($result) : [];
    }

    public function generateLlmsTxt( string $provider = '' ): string {
        $provider  = ProviderResolver::for('article_generation', $provider);
        $site_name = get_bloginfo('name');
        $site_url  = get_site_url();
        $posts     = get_posts(['numberposts' => 15, 'post_status' => 'publish']);
        $list      = '';
        foreach ($posts as $p) {
            $list .= "- [{$p->post_title}]({$site_url}/?p={$p->ID})\n";
        }
        $task = "Site: {$site_name} ({$site_url})\nDescrição: " . get_bloginfo('description') . "\n"
              . "Posts recentes:\n{$list}\n\n"
              . "Gere um arquivo llms.txt completo seguindo o padrão GEO, com seções: # nome, ## About, ## Expertise, ## Content, ## Guidelines. "
              . "Retorne APENAS o texto puro do arquivo, sem JSON, sem blocos de código.";
        return $this->callAI($this->system_prompt . "\n\n=== GERAR LLMS.TXT ===\n" . $task, $provider);
    }

    public function calculateAIVisibilityScore( array $site_data = [] ): array {
        $score = 0; $details = [];

        $llms = get_option('geo_llmstxt_content', '');
        if (!empty($llms)) { $score += 15; $details[] = ['item' => 'llms.txt gerado', 'status' => 'ok', 'points' => 15]; }
        else { $details[] = ['item' => 'llms.txt', 'status' => 'missing', 'points' => 0, 'action' => 'Gerar na aba llms.txt']; }

        $author = get_option('geo_author_name', '');
        if ($author) { $score += 10; $details[] = ['item' => 'Perfil de autor E-E-A-T', 'status' => 'ok', 'points' => 10]; }
        else { $details[] = ['item' => 'Perfil de autor E-E-A-T', 'status' => 'missing', 'points' => 0, 'action' => 'Configurar E-E-A-T em Configurações']; }

        $faq = get_posts(['numberposts' => 1, 'post_status' => 'publish', 'meta_key' => '_geo_faq_raw']);
        if (!empty($faq)) { $score += 10; $details[] = ['item' => 'Artigos com FAQ', 'status' => 'ok', 'points' => 10]; }
        else { $details[] = ['item' => 'Artigos com FAQ', 'status' => 'missing', 'points' => 0, 'action' => 'Gerar artigos com E-E-A-T ativado']; }

        $gsc = get_option('geo_gsc_token', []);
        if (!empty($gsc['access_token'])) { $score += 10; $details[] = ['item' => 'Google Search Console', 'status' => 'ok', 'points' => 10]; }
        else { $details[] = ['item' => 'Google Search Console', 'status' => 'missing', 'points' => 0, 'action' => 'Conectar GSC em Configurações']; }

        $cnt = (int) wp_count_posts()->publish;
        $vp  = min(15, (int) ($cnt / 5));
        $score += $vp;
        $details[] = ['item' => "{$cnt} posts publicados", 'status' => $cnt >= 30 ? 'ok' : 'partial', 'points' => $vp, 'action' => $cnt < 30 ? 'Publicar mais conteúdo' : ''];

        $cl = get_option('geo_cluster_list', []);
        if (count($cl) >= 1) { $score += 10; $details[] = ['item' => 'Clusters de conteúdo', 'status' => 'ok', 'points' => 10]; }
        else { $details[] = ['item' => 'Clusters', 'status' => 'missing', 'points' => 0, 'action' => 'Criar em Cluster SEO']; }

        $audio = get_posts(['numberposts' => 1, 'post_status' => 'publish', 'meta_key' => '_geo_audio_url']);
        if (!empty($audio)) { $score += 5; $details[] = ['item' => 'Artigos com áudio TTS', 'status' => 'ok', 'points' => 5]; }
        else { $details[] = ['item' => 'Artigos com áudio TTS', 'status' => 'missing', 'points' => 0, 'action' => 'Usar TTS na aba TTS']; }

        if (strpos(get_site_url(), 'https') === 0) { $score += 10; $details[] = ['item' => 'HTTPS ativo', 'status' => 'ok', 'points' => 10]; }
        else { $details[] = ['item' => 'HTTPS', 'status' => 'bad', 'points' => 0, 'action' => 'Urgente: ativar SSL/HTTPS']; }

        if (defined('RANK_MATH_VERSION') || defined('WPSEO_VERSION')) { $score += 5; $details[] = ['item' => 'Plugin SEO (RankMath/Yoast)', 'status' => 'ok', 'points' => 5]; }
        else { $details[] = ['item' => 'Plugin SEO', 'status' => 'missing', 'points' => 0, 'action' => 'Instalar Rank Math SEO']; }

        $yt = get_posts(['numberposts' => 1, 'post_status' => 'publish', 'meta_key' => '_geo_youtube_video_id']);
        if (!empty($yt)) { $score += 5; $details[] = ['item' => 'Artigos YouTube', 'status' => 'ok', 'points' => 5]; }
        else { $details[] = ['item' => 'Artigos YouTube', 'status' => 'missing', 'points' => 0, 'action' => 'Usar YouTube → Artigo']; }

        return ['score' => min(100, $score), 'label' => $this->scoreLabel(min(100,$score)), 'details' => $details];
    }

    // ── Helpers ──────────────────────────────────

    private function callAI( string $prompt, string $provider = '' ): string {
        $provider = ProviderResolver::for('article_generation', $provider);
        try {
            $response = $this->ai->generateText($prompt, $provider);
        } catch (\Exception $e) {
            LogService::log('error', 'SARA callAI exception: ' . $e->getMessage());
            return '';
        }
        if (!$response || $response->hasError() || !$response->getContent()) {
            $err = $response ? $response->getError() : 'null response';
            LogService::log('error', "SARA [{$provider}]: {$err}");
            return '';
        }
        return $response->getContent();
    }

    private function parseJson( string $raw ): array {
        $clean = trim(preg_replace('/^```(json)?\s*/im', '', $raw));
        $clean = trim(preg_replace('/```\s*$/im', '', $clean));
        $data  = json_decode($clean, true);
        if (json_last_error() !== JSON_ERROR_NONE && preg_match('/\{.+\}/s', $clean, $m)) {
            $data = json_decode($m[0], true);
        }
        return is_array($data) ? $data : [];
    }

    private function scoreLabel( int $score ): string {
        if ($score >= 80) return 'Excelente';
        if ($score >= 60) return 'Bom';
        if ($score >= 40) return 'Regular';
        return 'Precisa melhorar';
    }

    // ── Memória persistente da SARA ─────────────────────────────────────────────

    public static function save_memory( string $key, string $value ): void {
        $memory = get_option('geo_sara_memory', []);
        $memory[sanitize_key($key)] = [
            'value'   => mb_substr(sanitize_textarea_field($value), 0, 400),
            'updated' => current_time('mysql'),
        ];
        if (count($memory) > 60) {
            $memory = array_slice($memory, -60, 60, true);
        }
        update_option('geo_sara_memory', $memory);
    }

    public static function get_memory(): array {
        return get_option('geo_sara_memory', []);
    }

    public static function clear_memory(): void {
        delete_option('geo_sara_memory');
    }

    private function buildMemoryBlock(): string {
        $memory = self::get_memory();
        if (empty($memory)) return '';
        $lines = ['=== MEMÓRIA ACUMULADA SOBRE ESTE SITE ==='];
        foreach ($memory as $key => $entry) {
            $lines[] = '- ' . $key . ': ' . $entry['value'];
        }
        return implode("\n", $lines) . "\n";
    }

    private function buildSiteContext( array $site_context ): string {
        if (empty($site_context)) {
            $cats        = get_categories(['hide_empty' => true, 'number' => 5]);
            $author_name = get_option('geo_author_name', '');
            $provider    = ProviderResolver::for('article_generation');
            $model       = get_option('geo_model_' . $provider, '');
            $memory_block = $this->buildMemoryBlock();
            return $memory_block
                 . 'Site: '       . get_bloginfo('name') . "\n"
                 . 'URL: '        . get_site_url()       . "\n"
                 . 'Posts: '      . (int) wp_count_posts()->publish . "\n"
                 . 'Categorias: ' . implode(', ', wp_list_pluck($cats, 'name')) . "\n"
                 . 'Autor: '      . ($author_name ?: 'não configurado') . "\n"
                 . 'IA: '         . $provider . ($model ? " ({$model})" : '') . "\n"
                 . 'GSC: '        . (!empty(get_option('geo_gsc_token', [])['access_token']) ? 'conectado' : 'não conectado');
        }
        $lines = [];
        foreach ($site_context as $k => $v) {
            $lines[] = "{$k}: " . (is_array($v) ? implode(', ', $v) : $v);
        }
        return implode(' | ', $lines);
    }

    private function buildSystemPrompt(): string {
        $site_name = get_bloginfo('name');
        $site_url  = get_site_url();
        return "Você é a SARA (Specialized AI for Ranking & Authority), IA especialista em SEO, GEO e LLM Optimization do site \"{$site_name}\" ({$site_url}).\n\n"
             . "SEO TÉCNICO: Core Web Vitals (LCP<2.5s, INP<100ms, CLS<0.1), mobile-first, Schema.org, canonicals, sitemaps, crawl budget, PageSpeed, algoritmos Google (Helpful Content, SpamBrain, Core Updates), link building topical.\n\n"
             . "GEO (Generative Engine Optimization): LLMs favorecem E-E-A-T alta, fatos verificáveis, entidades nomeadas, estrutura lógica. Formato GEO: definição > contexto > dados > exemplos > conclusão. llms.txt instrui LLMs. Parágrafos curtos e listas aumentam citação no ChatGPT, Gemini e Perplexity.\n\n"
             . "CONTENT STRATEGY: Intenção de busca, clusters pilar+satélites, LSI keywords, PAA, featured snippets (40-60 palavras), cauda longa, content freshness.\n\n"
             . "E-E-A-T: Experience, Expertise, Authoritativeness, Trustworthiness. Author bios reais, HTTPS, política de privacidade, dados de contato.\n\n"
             . "RESPONDA SEMPRE em português brasileiro. Recomendações acionáveis por prioridade de impacto. Use ✅ ❌ ⚠️ moderadamente. Retorne JSON estruturado para análises quando solicitado.";
    }
}
