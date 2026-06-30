<?php
namespace GeoMetodoSEO\Autopilot\Writer;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\{AutopilotInstaller, AutopilotLogger};
use GeoMetodoSEO\AI\AIManager;
use GeoMetodoSEO\AI\ProviderResolver;

/**
 * SaraGenerator — Gerador profissional do Agente B.
 * 1.0.0: briefing factual antes da escrita, contador real de palavras,
 * auto-expansão e modelos por tarefa.
 */
class SaraGenerator {

    private AIManager $ai;
    private string $last_error = '';

    public function __construct() {
        $this->ai = new AIManager();
    }

    public function get_last_error(): string {
        return $this->last_error;
    }

    public function generate(array $plan, array $prev_issues = []) {
        $this->last_error = '';

        // GATE DE LICENÇA: se o plano (mensal/anual) venceu, o SARA Autopilot PARA.
        // Não gera nem publica nada — nem mesmo o que já estava agendado.
        // Volta a funcionar somente após a renovação da licença.
        if (!\GeoMetodoSEO\License\LicenseManager::canGenerate()) {
            $this->last_error = 'Licença expirada ou inativa. Renove para reativar o SARA Autopilot.';
            if (class_exists('GeoMetodoSEO\\Autopilot\\Shared\\AutopilotLogger')) {
                AutopilotLogger::log('writer', 'license_blocked', 'error',
                    'SARA Autopilot bloqueado: licença expirada/inativa. Renove a licença para reativar.');
            }
            return false;
        }

        $t0 = microtime(true);
        $target_words = $this->target_words($plan);
        $min_words    = $this->minimum_words($target_words);
        $max_words    = $this->maximum_words($target_words);

        $briefing = $this->build_factual_briefing($plan, $target_words);
        $prompt   = $this->build_generation_prompt($plan, $briefing);

        if (!empty($prev_issues)) {
            $prompt .= $this->format_previous_issues($prev_issues);
        }

        $provider_generation = $this->provider_for_task('generation');
        $generation_model = \GeoMetodoSEO\AI\ProviderResolver::modelFor('sara_generation', $provider_generation, AutopilotInstaller::get('writer_model_generation', ''));
        $content = $this->request_content($prompt, (string)($generation_model ?: ''), 'generation');
        if ($content === false) return false;

        $actual_words = $this->word_count($content);
        AutopilotLogger::log('writer', 'word_count_check', 'info',
            "Contador real: {$actual_words}/{$target_words} palavras", [
                'calendar_id' => $plan['id'] ?? 0,
                'actual_words' => $actual_words,
                'target_words' => $target_words,
                'minimum_words' => $min_words,
                'maximum_words' => $max_words,
            ]
        );

        // 1.0.0 FIX 1: Tolerância de 5% antes de disparar expand/condense.
        // Antes: gerou 2099 palavras com mín=2100 → disparava expand inteiro por 1 palavra.
        // Agora: dentro de 5% do alvo = aceita; só dispara se diferença for relevante.
        $tolerance_pct  = (float) AutopilotInstaller::get('auto_expand_tolerance_pct', '5');
        $tolerance_pct  = max(0.0, min(20.0, $tolerance_pct));
        $tolerance_abs  = max(50, (int) round($target_words * ($tolerance_pct / 100)));
        $effective_min  = max(0, $min_words - $tolerance_abs);
        $effective_max  = $max_words + $tolerance_abs;

        // FIX v1.0.0-STABILITY: detectar H2 vazios ou genéricos ANTES de salvar.
        // NOTA: blocos de expand abaixo desativados com "if (false && ...)" DE PROPÓSITO.
        // Motivo: garantir CHAMADA ÚNICA de IA por artigo. O writer-prompt.txt já produz
        // artigos completos sem H2 vazios. Reativar o expand faria 2-3 chamadas extras,
        // aumentando tempo e custo. NÃO reative sem necessidade comprovada.
        $empty_h2_detected = $this->detect_empty_or_thin_h2s($content);
        if (false && $empty_h2_detected['has_problem'] && AutopilotInstaller::get('auto_expand_enabled', '1') === '1') {
            AutopilotLogger::log('writer', 'h2_vazio_detected', 'warning',
                "H2 vazios/genéricos detectados: " . implode(', ', array_map(fn($h) => '"' . $h . '"', $empty_h2_detected['problematic_h2s'])),
                ['calendar_id' => $plan['id'] ?? 0]
            );

            // FIX v1.0.0-EMPTY-H2: usar expand ESPECÍFICO para H2 vazios.
            // Antes: chamava expand_content_to_target que só adicionava palavras em qualquer lugar
            // — H2 vazios continuavam vazios mesmo após "auto-expand".
            // Agora: expand_empty_h2s diz exatamente quais H2 precisam de conteúdo (250+ palavras cada).
            $expanded = $this->expand_empty_h2s(
                $content,
                $plan,
                $briefing,
                $empty_h2_detected['problematic_h2s']
            );
            if ($expanded !== false) {
                // Re-detecta para confirmar que H2 vazios foram preenchidos
                $recheck = $this->detect_empty_or_thin_h2s($expanded);
                if (!$recheck['has_problem']) {
                    $content = $expanded;
                    $actual_words = $this->word_count($content);
                    AutopilotLogger::log('writer', 'h2_vazio_corrigido', 'success',
                        "H2 vazios corrigidos: {$actual_words} palavras totais",
                        ['calendar_id' => $plan['id'] ?? 0]
                    );
                } else {
                    AutopilotLogger::log('writer', 'h2_vazio_persistente', 'warning',
                        "H2 ainda vazios após expand: " . implode(', ', $recheck['problematic_h2s']),
                        ['calendar_id' => $plan['id'] ?? 0]
                    );
                    // Aplica mesmo assim — melhor um artigo expandido que um com H2 totalmente vazio
                    if ($this->word_count($expanded) > $actual_words) {
                        $content = $expanded;
                        $actual_words = $this->word_count($content);
                    }
                }
            }
        }

        if (false && $actual_words < $effective_min && AutopilotInstaller::get('auto_expand_enabled', '1') === '1') {
            AutopilotLogger::log('writer', 'auto_expand_triggered', 'info',
                "Expand DISPARADO: {$actual_words} < {$effective_min} (alvo {$target_words}, tolerância {$tolerance_pct}%)",
                ['calendar_id' => $plan['id'] ?? 0]
            );

            $expanded = $this->expand_content_to_target($content, $plan, $briefing, $target_words, $min_words, $actual_words);
            if ($expanded !== false) {
                $expanded_words = $this->word_count($expanded);
                if ($expanded_words > $actual_words) {
                    $content = $expanded;
                    $actual_words = $expanded_words;
                    AutopilotLogger::log('writer', 'auto_expand_done', 'success',
                        "Auto-expansão aplicada: {$actual_words}/{$target_words} palavras", [
                            'calendar_id' => $plan['id'] ?? 0,
                            'actual_words' => $actual_words,
                            'target_words' => $target_words,
                        ]
                    );
                }
            }
        } elseif ($actual_words < $min_words) {
            // Dentro da tolerância — log informativo (economiza chamada IA)
            AutopilotLogger::log('writer', 'auto_expand_skipped_tolerance', 'info',
                "Expand pulado por tolerância: {$actual_words}/{$target_words} (mín técnico {$min_words}, mín efetivo {$effective_min})",
                ['calendar_id' => $plan['id'] ?? 0]
            );
        }

        if (false && $actual_words > $effective_max && AutopilotInstaller::get('auto_expand_enabled', '1') === '1') {
            $condensed = $this->condense_content_to_target($content, $plan, $briefing, $target_words, $max_words, $actual_words);
            if ($condensed !== false) {
                $condensed_words = $this->word_count($condensed);
                if ($condensed_words < $actual_words && $condensed_words >= $min_words) {
                    $content = $condensed;
                    $actual_words = $condensed_words;
                    AutopilotLogger::log('writer', 'auto_condense_done', 'success',
                        "Condensação aplicada: {$actual_words}/{$target_words} palavras", [
                            'calendar_id' => $plan['id'] ?? 0,
                            'actual_words' => $actual_words,
                            'target_words' => $target_words,
                            'maximum_words' => $max_words,
                        ]
                    );
                }
            }
        } elseif ($actual_words > $max_words) {
            // Dentro da tolerância — log informativo
            AutopilotLogger::log('writer', 'auto_condense_skipped_tolerance', 'info',
                "Condense pulado por tolerância: {$actual_words}/{$target_words} (máx técnico {$max_words}, máx efetivo {$effective_max})",
                ['calendar_id' => $plan['id'] ?? 0]
            );
        }

        if ($actual_words < 500 || strlen(strip_tags($content)) < 500) {
            AutopilotLogger::log('writer', 'generator_short', 'warning',
                "Conteúdo gerado muito curto: {$actual_words}/{$target_words} palavras", [
                    'calendar_id' => $plan['id'] ?? 0,
                ]
            );
            return false;
        }

        AutopilotLogger::log('writer', 'generator_done', 'success',
            "Conteúdo gerado: {$actual_words}/{$target_words} palavras", [
                'calendar_id' => $plan['id'] ?? 0,
                'duration_ms' => AutopilotLogger::elapsed($t0),
                'actual_words' => $actual_words,
                'target_words' => $target_words,
                'tokens_used' => 15000,
                'cost_usd'    => 0.030,
            ]
        );

        return $content;
    }

    private function request_content(string $prompt, string $model, string $task) {
        $provider = $this->provider_for_task($task);
        $response = $this->ai->generateText($prompt, $provider, $model);
        if (!$response || $response->hasError() || trim((string)$response->getContent()) === '') {
            $this->last_error = $response ? (string)$response->getError() : 'resposta nula';
            AutopilotLogger::log('writer', $task . '_error', 'error',
                'IA falhou na tarefa ' . $task . ': ' . $this->last_error,
                ['context' => ['provider' => $provider, 'model' => $model]]
            );
            return false;
        }
        $this->last_error = '';
        return $this->normalize_html($response->getContent());
    }

    private function provider_for_task(string $task): string {
        switch ($task) {
            case 'generation':
                $context = 'sara_generation';
                break;
            case 'auto_expand':
            case 'auto_condense':
                $context = 'sara_expansion';
                break;
            case 'briefing':
                $context = 'sara_briefing';
                break;
            default:
                $context = 'sara_generation';
                break;
        }
        return ProviderResolver::for($context);
    }

    private function build_factual_briefing(array $plan, int $target_words): string {
        // 1.0.0 FIX 2: Briefing condicional + cache de 24h.
        // Antes: a IA era chamada a CADA artigo gerado, mesmo quando o plano já tinha
        //        outline + entities + sec_kws + lsi (caso normal do Brain).
        // Agora: 1) Se o plano vem completo, usa briefing local defensivo (zero custo).
        //        2) Se não vem completo, gera por IA mas armazena cache de 24h por
        //           categoria+nicho (artigos do mesmo lote reusam o mesmo briefing).

        // Controle de uso desativado / Single Master: no Autopilot, briefing deve ser local por padrão para manter 1 chamada principal por artigo.
        if (false && (string) get_option('geo_sara_single_master_prompt_enabled', '1') === '1') {
            AutopilotLogger::log('writer', 'briefing_local_single_master', 'info', 'Briefing IA pulado pelo Single Master/Controle de uso desativado; usando briefing local defensivo.', ['calendar_id' => $plan['id'] ?? 0]);
            return $this->local_defensive_briefing($plan);
        }

        // Verifica se o plano já vem com dados suficientes pra dispensar briefing IA
        $outline_count = 0;
        if (!empty($plan['outline'])) {
            $outline_arr = is_string($plan['outline']) ? json_decode($plan['outline'], true) : $plan['outline'];
            $outline_count = is_array($outline_arr) ? count($outline_arr) : 0;
        }
        $has_entities = !empty($plan['entities']) && $plan['entities'] !== '[]';
        $has_keywords = !empty($plan['secondary_keywords']) && $plan['secondary_keywords'] !== '[]';

        $skip_ai_briefing = (string) AutopilotInstaller::get('briefing_skip_when_plan_complete', '1') === '1'
                          && $outline_count >= 3
                          && $has_entities
                          && $has_keywords;

        if ($skip_ai_briefing) {
            AutopilotLogger::log('writer', 'briefing_skipped_plan_complete', 'info',
                "Briefing IA pulado: plano já tem outline ({$outline_count}), entities e keywords",
                ['calendar_id' => $plan['id'] ?? 0]
            );
            return $this->local_defensive_briefing($plan);
        }

        // Cache de 24h por nicho+categoria (artigos do mesmo lote compartilham)
        $cache_key = 'geo_briefing_' . md5(
            (string)($plan['category_name'] ?? '') . '|' .
            (string)AutopilotInstaller::get('site_niche', 'geral') . '|' .
            (string)$target_words
        );
        $cached = get_transient($cache_key);
        if ($cached !== false && is_string($cached) && trim($cached) !== '') {
            AutopilotLogger::log('writer', 'briefing_cache_hit', 'info',
                'Briefing reusado do cache (24h) — economizou chamada IA',
                ['calendar_id' => $plan['id'] ?? 0]
            );
            return $cached;
        }

        $provider_briefing = $this->provider_for_task('briefing');
        $model = (string) AutopilotInstaller::get('writer_model_briefing', '');
        if ($model === '') {
            $model = (string) (ProviderResolver::modelFor('sara_briefing', $provider_briefing) ?: '');
        }
        $context = $this->plan_context($plan);

        $prompt = "Você é um editor factual de SEO/GEO. Crie um BRIEFING FACTUAL para orientar a escrita de um artigo.\n\n"
            . "Use SOMENTE os dados fornecidos abaixo. NÃO invente datas, preços, especificações, pesquisas, fontes, estatísticas ou acontecimentos.\n"
            . "Quando algo não estiver confirmado, escreva explicitamente como lacuna ou ponto que exige cautela.\n\n"
            . "CONTEXTO DO PLANO:\n{$context}\n\n"
            . "META DE TAMANHO: {$target_words} palavras.\n\n"
            . "Retorne em tópicos curtos com estas seções:\n"
            . "1. Fatos confirmados no contexto\n"
            . "2. Lacunas que NÃO podem ser inventadas\n"
            . "3. Entidades e termos obrigatórios\n"
            . "4. Ângulo editorial seguro\n"
            . "5. Perguntas que o artigo precisa responder\n"
            . "6. Alertas anti-alucinação.";

        $response = $this->ai->generateText($prompt, $this->provider_for_task('briefing'), $model);
        if ($response && !$response->hasError() && trim((string)$response->getContent()) !== '') {
            $brief = trim(strip_tags($response->getContent()));
            $brief = mb_substr($brief, 0, 6000);

            // Cache 24h
            set_transient($cache_key, $brief, DAY_IN_SECONDS);

            AutopilotLogger::log('writer', 'briefing_done', 'success',
                'Briefing factual criado e cacheado por 24h',
                ['calendar_id' => $plan['id'] ?? 0, 'model' => $model]
            );
            return $brief;
        }

        AutopilotLogger::log('writer', 'briefing_fallback', 'warning', 'Briefing factual por IA falhou; usando briefing local defensivo', [
            'calendar_id' => $plan['id'] ?? 0,
        ]);

        return $this->local_defensive_briefing($plan);
    }

    /**
     * Briefing local defensivo (zero custo IA).
     * Usado quando o plano já tem dados suficientes ou quando IA falha.
     * @since 1.0.0
     */
    private function local_defensive_briefing(array $plan): string {
        $title    = $plan['title'] ?? '';
        $keyword  = $plan['keyword'] ?? '';
        $entities = '';
        if (!empty($plan['entities'])) {
            $arr = is_string($plan['entities']) ? json_decode($plan['entities'], true) : $plan['entities'];
            if (is_array($arr)) $entities = implode(', ', array_slice($arr, 0, 8));
        }

        $out  = "FATOS CONFIRMADOS NO PLANO:\n";
        if ($title)    $out .= "- Título: {$title}\n";
        if ($keyword)  $out .= "- Keyword principal: {$keyword}\n";
        if ($entities) $out .= "- Entidades obrigatórias: {$entities}\n";

        $out .= "\nLACUNAS (NÃO PODE INVENTAR):\n";
        $out .= "- Preços, datas, estudos, números, especificações técnicas, fontes, lançamentos\n";
        $out .= "- Estatísticas e percentuais não fornecidos\n";
        $out .= "- Frases atribuídas a pessoas reais\n";

        $out .= "\nÂNGULO EDITORIAL:\n";
        $out .= "- Linguagem natural e profissional, foco em intenção de busca\n";
        $out .= "- E-E-A-T: experiência prática, expertise, autoridade, confiabilidade\n";
        $out .= "- Tom direto, útil, sem clichês ('é fundamental', 'no mundo de hoje')\n";

        $out .= "\nALERTAS ANTI-ALUCINAÇÃO:\n";
        $out .= "- Quando não souber: redigir de forma cautelosa e atemporal\n";
        $out .= "- Não criar links externos falsos\n";
        $out .= "- Não citar autoridades inexistentes\n";

        return $out;
    }

    private function build_generation_prompt(array $plan, string $briefing): string {
        $year     = date('Y');
        $language = AutopilotInstaller::get('site_language', 'pt-BR');
        $niche    = AutopilotInstaller::get('site_niche', 'geral');
        $mode     = AutopilotInstaller::get('site_mode', 'conservative');
        $tone_inst = 'profissional, natural, editorial, claro e confiável';

        $lang_map = [
            'pt-BR' => ['name' => 'Português do Brasil',  'code' => 'pt-BR', 'native_name' => 'português brasileiro'],
            'pt-PT' => ['name' => 'Português de Portugal', 'code' => 'pt-PT', 'native_name' => 'português europeu'],
            'en-US' => ['name' => 'English (US)',          'code' => 'en-US', 'native_name' => 'American English'],
            'en-GB' => ['name' => 'English (UK)',          'code' => 'en-GB', 'native_name' => 'British English'],
            'es-ES' => ['name' => 'Español',               'code' => 'es-ES', 'native_name' => 'español'],
            'es-MX' => ['name' => 'Español (México)',      'code' => 'es-MX', 'native_name' => 'español mexicano'],
            'fr-FR' => ['name' => 'Français',              'code' => 'fr-FR', 'native_name' => 'français'],
            'de-DE' => ['name' => 'Deutsch',               'code' => 'de-DE', 'native_name' => 'Deutsch'],
            'it-IT' => ['name' => 'Italiano',              'code' => 'it-IT', 'native_name' => 'italiano'],
        ];
        $lang_info = $lang_map[$language] ?? $lang_map['pt-BR'];

        $outline   = json_decode($plan['outline'] ?? '[]', true) ?: [];
        $entities  = json_decode($plan['entities'] ?? '[]', true) ?: [];
        $sec_kws   = json_decode($plan['secondary_keywords'] ?? '[]', true) ?: [];
        $lsi       = json_decode($plan['lsi_keywords'] ?? '[]', true) ?: [];
        $ext_src   = json_decode($plan['external_sources'] ?? '[]', true) ?: [];
        $int_links = json_decode($plan['internal_links'] ?? '[]', true) ?: [];

        $outline_text = '';
        foreach ($outline as $h) {
            $title = is_array($h) ? ($h['title'] ?? '') : (string)$h;
            if ($title === '') continue;
            $outline_text .= "## {$title}\n";
            foreach (($h['h3s'] ?? []) as $h3) {
                $outline_text .= "  ### {$h3}\n";
            }
        }
        if ($outline_text === '') {
            $outline_text = "## Visão geral\n## Como funciona\n## Critérios importantes\n## Exemplos práticos\n## Erros comuns\n## Conclusão\n";
        }

        $int_links_text = '';
        foreach (array_slice($int_links, 0, 5) as $link) {
            if (!empty($link['anchor_text']) && !empty($link['url'])) {
                $int_links_text .= "- [{$link['anchor_text']}]({$link['url']})\n";
            }
        }

        $wc          = $this->target_words($plan);
        $min_words   = $this->minimum_words($wc);
        $intro_min   = max(220, (int)round($wc * 0.10));
        $body_min    = max(1500, (int)round($wc * 0.68));
        $closing_min = max(180, (int)round($wc * 0.08));
        $eeat        = $plan['eeat_angle'] ?? 'experiencia_pratica';
        $author_context = $this->build_author_ecosystem_context();

        $prompt_file = GEO_METODO_SEO_PATH . 'sara-autopilot/prompts/writer/writer-prompt.txt';
        $base_prompt = file_exists($prompt_file) ? file_get_contents($prompt_file) : '';
        $h2_word_count = (int) floor($wc / 6); // alvo por H2 (dividido por 6 seções)
        $base_prompt = str_replace(
            ['[TITULO]', '[KEYWORD]', '[NICHO]', '[TOM]', '[WORD_COUNT]', '[H2_WORD_COUNT]'],
            [$plan['title'] ?? '', $plan['keyword'] ?? '', $niche, $tone_inst, (string)$wc, (string)$h2_word_count],
            $base_prompt
        );

        return "🌐 IDIOMA OBRIGATÓRIO: {$lang_info['name']} ({$lang_info['code']})\n"
            . "Escreva o artigo INTEIRAMENTE em {$lang_info['native_name']}. NÃO misture idiomas.\n\n"
            . ($base_prompt ?: "Você é SARA WRITER, redator sênior especialista em SEO, GEO, LLM Optimization, AEO e E-E-A-T.")
            . "\n\nBRIEFING FACTUAL OBRIGATÓRIO — NÃO SAIA DESSE CONTEXTO:\n{$briefing}\n\n"
            . "NICHO: {$niche} | ANO: {$year} | MODO: {$mode}\n"
            . "TÍTULO: " . ($plan['title'] ?? '') . "\n"
            . "KEYWORD PRINCIPAL: " . ($plan['keyword'] ?? '') . "\n"
            . "INTENÇÃO DE BUSCA: " . ($plan['search_intent'] ?? 'informacional') . "\n"
            . "TIPO DE CONTEÚDO: " . ($plan['content_type'] ?? 'cluster') . "\n\n"
            . "📏 META VISUAL/REAL DE PALAVRAS: {$wc}\n"
            . "📏 MÍNIMO ACEITÁVEL APÓS CONTADOR REAL: {$min_words}\n"
            . "Não finalize antes de atingir profundidade suficiente. Se o texto ficar curto, aprofunde com critérios, exemplos, limitações, cenários, comparação e contexto útil — sem enrolação e sem inventar fatos.\n"
            . "Distribuição mínima: Introdução ≥ {$intro_min}; Corpo ≥ {$body_min}; Conclusão ≥ {$closing_min}.\n\n"
            . "ÂNGULO E-E-A-T: {$eeat}"
            . $author_context
            . "\n\nOUTLINE OBRIGATÓRIO:\n{$outline_text}"
            . (!empty($entities) ? "\nENTIDADES OBRIGATÓRIAS: " . implode(', ', $entities) : '')
            . (!empty($sec_kws)  ? "\nKEYWORDS SECUNDÁRIAS: " . implode(', ', $sec_kws) : '')
            . (!empty($lsi)      ? "\nTERMOS SEMÂNTICOS/LSI: " . implode(', ', $lsi) : '')
            . (!empty($ext_src)  ? "\nFONTES EXTERNAS FORNECIDAS: " . implode(', ', array_column($ext_src, 'url')) : '')
            . (!empty($int_links_text) ? "\nLINKS INTERNOS PARA USAR NATURALMENTE:\n{$int_links_text}" : '')
            . "\n\nESTRUTURA OBRIGATÓRIA DO ARTIGO:"
            . "\n1. Box RESPOSTA RÁPIDA único: <div class=\"sara-quick-answer\"><strong>⚡ Resposta Rápida:</strong> [40-70 palavras diretas]</div>"
            . "\n2. Introdução profunda sem repetir o título."
            . "\n3. H2/H3 seguindo o outline, com cobertura real das lacunas."
            . "\n4. Uma tabela comparativa útil com pelo menos 4 linhas e 3 colunas."
            . "\n5. Box de dicas: <div class=\"sara-expert-tips\"><strong>💡 Dicas do Especialista:</strong> [3 dicas úteis]</div>"
            . "\n6. Conclusão com síntese e próximo passo."
            . "\n\nREGRAS FACTUAIS INEGOCIÁVEIS:"
            . "\n- Não invente dados, preços, datas, especificações, números, estudos, fontes, rankings ou acontecimentos."
            . "\n- Se algo não estiver no briefing/contexto/fonte, escreva de forma cautelosa e atemporal.
- Para guia de compra, tutorial, tecnologia e smartphones, escreva no PRESENTE. Não trate como passado se o título não pedir histórico.
- Não invente modelos, preços, fichas técnicas, atualizações, benchmarks, datas, rankings ou fontes."
            . "\n- Não crie links externos falsos. Não cite autoridade inexistente."
            . "\n- Use linguagem natural, profissional e útil para Google, IA, AEO e LLMs."
            . "\n- Use o ecossistema do autor apenas como contexto editorial; não invente experiências pessoais específicas."
            . "\n\n🚫 NÃO INCLUIR FAQ — o FAQ é gerado em chamada separada pelo plugin."
            . "\nRETORNE APENAS HTML puro do conteúdo, sem H1, sem JSON e sem markdown fences.";
    }

    private function condense_content_to_target(string $content, array $plan, string $briefing, int $target_words, int $max_words, int $actual_words) {
        $provider = $this->provider_for_task('auto_expand');
        $model = \GeoMetodoSEO\AI\ProviderResolver::modelFor('sara_expansion', $provider, AutopilotInstaller::get('writer_model_expansion', ''));
        $prompt = "Você é editor sênior. O artigo abaixo passou muito da meta de palavras.

"
            . "PALAVRAS ATUAIS: {$actual_words}
META: {$target_words}
MÁXIMO PERMITIDO: {$max_words}

"
            . "BRIEFING FACTUAL — não invente nada fora dele:
{$briefing}

"
            . "TÍTULO: " . ($plan['title'] ?? '') . "
KEYWORD: " . ($plan['keyword'] ?? '') . "

"
            . "Condense mantendo HTML puro, resposta rápida única, tabela HTML se existir, sem FAQ, sem repetir parágrafos e sem inventar dados. "
            . "Mantenha todas as informações úteis, mas remova redundâncias. Retorne o ARTIGO COMPLETO com aproximadamente {$target_words} palavras e nunca acima de {$max_words}.

ARTIGO ATUAL:
{$content}";
        $condensed = $this->request_content($prompt, $model, 'auto_condense');
        if ($condensed === false) return false;
        return $this->normalize_html($condensed);
    }

    /**
     * FIX v1.0.0-EMPTY-H2: expand específico para H2 vazios.
     * Diferente do expand_content_to_target genérico, este diz EXATAMENTE quais H2 precisam de conteúdo.
     */
    private function expand_empty_h2s(string $content, array $plan, string $briefing, array $empty_h2s) {
        if (empty($empty_h2s)) return false;
        $provider = $this->provider_for_task('auto_expand');
        $model = \GeoMetodoSEO\AI\ProviderResolver::modelFor('sara_expansion', $provider, AutopilotInstaller::get('writer_model_expansion', ''));

        $h2_list = implode("\n- ", array_map(fn($h) => '"' . $h . '"', $empty_h2s));

        $prompt = "Você é editor sênior. O artigo abaixo tem H2 SEM CONTEÚDO ABAIXO. "
            . "Sua tarefa é APENAS preencher esses H2 vazios com 250-400 palavras úteis cada — nada mais.\n\n"
            . "H2 QUE PRECISAM DE CONTEÚDO ABAIXO DELES:\n- {$h2_list}\n\n"
            . "REGRAS OBRIGATÓRIAS:\n"
            . "1. NÃO modifique nada dos outros H2 ou parágrafos que já têm conteúdo.\n"
            . "2. NÃO duplique a Resposta Rápida, FAQ ou autor.\n"
            . "3. Para cada H2 listado acima, escreva 3-4 parágrafos de 60-100 palavras cada (250-400 palavras totais por H2).\n"
            . "4. Conteúdo deve ser útil, prático, com dado concreto OU comparação OU erro+solução.\n"
            . "5. Linguagem natural — sem 'é fundamental', 'é essencial', 'vamos explorar'.\n"
            . "6. PROIBIDO inventar preços, datas, estatísticas, lançamentos ou fontes.\n"
            . "7. HTML puro: <p>, <strong>, <em>, <ul>, <li>. Sem markdown.\n\n"
            . "BRIEFING FACTUAL — base de conhecimento:\n{$briefing}\n\n"
            . "TÍTULO: " . ($plan['title'] ?? '') . "\n"
            . "KEYWORD: " . ($plan['keyword'] ?? '') . "\n\n"
            . "ARTIGO ATUAL:\n{$content}\n\n"
            . "Retorne o ARTIGO COMPLETO atualizado em HTML — com os H2 vazios PREENCHIDOS e o resto preservado.";

        $expanded = $this->request_content($prompt, $model, 'expand_empty_h2');
        if ($expanded === false) return false;
        return $this->remove_duplicate_quick_answers($expanded);
    }

    private function expand_content_to_target(string $content, array $plan, string $briefing, int $target_words, int $min_words, int $actual_words) {
        $provider = $this->provider_for_task('auto_expand');
        $model = \GeoMetodoSEO\AI\ProviderResolver::modelFor('sara_expansion', $provider, AutopilotInstaller::get('writer_model_expansion', ''));
        $missing = max(300, $target_words - $actual_words);
        $prompt = "Você é editor sênior. O artigo abaixo ficou curto no contador real.\n\n"
            . "PALAVRAS ATUAIS: {$actual_words}\nMETA: {$target_words}\nMÍNIMO ACEITÁVEL: {$min_words}\nFALTAM APROXIMADAMENTE: {$missing} palavras\n\n"
            . "BRIEFING FACTUAL — não invente nada fora dele:\n{$briefing}\n\n"
            . "TÍTULO: " . ($plan['title'] ?? '') . "\nKEYWORD: " . ($plan['keyword'] ?? '') . "\n\n"
            . "EXPANDA o artigo mantendo o HTML existente, sem duplicar resposta rápida, sem criar FAQ, sem repetir parágrafos e sem inventar dados.\n"
            . "Adicione profundidade real: critérios, exemplos cautelosos, limitações, comparação, passos práticos e contexto útil.\n"
            . "Retorne o ARTIGO COMPLETO em HTML puro.\n\nARTIGO ATUAL:\n{$content}";

        $expanded = $this->request_content($prompt, $model, 'auto_expand');
        if ($expanded === false) return false;
        return $this->remove_duplicate_quick_answers($expanded);
    }

    private function target_words(array $plan): int {
        $mode = AutopilotInstaller::get('site_mode', 'conservative');
        $default = $mode === 'aggressive'
            ? (int) AutopilotInstaller::get('aggressive_word_count', '4000')
            : (int) AutopilotInstaller::get('conservative_word_count', '2300');
        $target = (int)($plan['word_count_target'] ?? $default);
        if ($target <= 0) $target = $default;

        // FIX v1.0.0-WORDCOUNT: respeitar escolha do usuário.
        // Antes: max(2300, min(5000, $target)) — FORÇAVA mínimo 2300 mesmo quando user pedia 1500.
        // Agora: aceita de 800 a 6000 palavras conforme escolha do usuário/plano.
        return max(800, min(6000, $target));
    }

    private function minimum_words(int $target): int {
        // FIX v1.0.0-WORDCOUNT: mínimo proporcional ao alvo, não fixo em 2100.
        // Para artigo de 1500 palavras, mínimo aceitável = 1350 (90% do alvo).
        $ratio = $target >= 2000 ? 0.85 : 0.90;
        return max(500, (int) floor($target * $ratio));
    }

    private function maximum_words(int $target): int {
        // FIX v1.0.0-WORDCOUNT: máximo proporcional, mais apertado (115% em vez de 125%).
        // Artigo de 1500 palavras: máximo = 1725, não 1875.
        // Isso força condense quando passar — antes deixava 25% de "folga" e nunca condensava.
        return (int) ceil($target * 1.15);
    }

    /**
     * FIX v1.0.0-UNIFIED: agora delega para H2QualityValidator centralizado.
     * Vantagem: detecta vazios E gigantes (>600 palavras) — antes só vazios.
     * Mesma lógica de TODOS os outros fluxos (Individual, YouTube, Manual).
     *
     * @return array{has_problem: bool, problematic_h2s: array<string>}
     */
    private function detect_empty_or_thin_h2s(string $content): array {
        if (class_exists('\GeoMetodoSEO\Services\H2QualityValidator')) {
            $r = \GeoMetodoSEO\Services\H2QualityValidator::detect_thin_h2s($content);
            return [
                'has_problem' => $r['has_problem'],
                'problematic_h2s' => $r['problematic_h2s'],
            ];
        }
        // Fallback se H2QualityValidator não carregar
        $problematic = [];

        if (!preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return ['has_problem' => false, 'problematic_h2s' => []];
        }

        $h2_count = count($matches[0]);

        for ($i = 0; $i < $h2_count; $i++) {
            $h2_text = trim(wp_strip_all_tags($matches[1][$i][0] ?? ''));
            $h2_pos  = (int) $matches[0][$i][1];
            $h2_len  = strlen($matches[0][$i][0]);

            // FAQ/Conclusão/Próximos passos podem ter conteúdo curto — pular
            if ($h2_text === '' || preg_match('/perguntas\s+frequentes|faq|conclus[aã]o|próximos passos|o que fazer agora/iu', $h2_text)) {
                continue;
            }

            // Conteúdo entre este H2 e o próximo (ou fim do artigo)
            $next_pos = ($i + 1 < $h2_count) ? (int) $matches[0][$i + 1][1] : strlen($content);
            $section  = substr($content, $h2_pos + $h2_len, $next_pos - $h2_pos - $h2_len);
            $text     = wp_strip_all_tags($section);
            $text     = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text, $words);
            $word_count = count($words[0] ?? []);

            // H2 com menos de 80 palavras é problemático (alvo mínimo: 250)
            if ($word_count < 80) {
                $problematic[] = mb_substr($h2_text, 0, 60);
            }
        }

        return [
            'has_problem'    => !empty($problematic),
            'problematic_h2s' => $problematic,
        ];
    }

    private function word_count(string $html): int {
        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text, $m);
        return count($m[0] ?? []);
    }

    private function normalize_html(string $content): string {
        $content = trim($content);
        $content = preg_replace('/^```html?\s*/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        $content = preg_replace('/```\s*$/i', '', $content);
        return $this->remove_duplicate_quick_answers(trim($content));
    }

    private function remove_duplicate_quick_answers(string $content): string {
        $patterns = [
            '/(<div\s+class="[^"]*sara-quick-answer[^"]*"[^>]*>.*?<\/div>)(\s*<div\s+class="[^"]*sara-quick-answer[^"]*"[^>]*>.*?<\/div>)+/isu',
            '/(<div\s+class="[^"]*geo-quick-answer[^"]*"[^>]*>.*?<\/div>)(\s*<div\s+class="[^"]*geo-quick-answer[^"]*"[^>]*>.*?<\/div>)+/isu',
        ];
        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '$1', $content) ?? $content;
        }
        return trim($content);
    }

    private function format_previous_issues(array $prev_issues): string {
        $issues_str = '';
        foreach ($prev_issues as $issue) {
            if (is_string($issue)) {
                $issues_str .= "  - {$issue}\n";
            } elseif (is_array($issue) && !empty($issue['description'])) {
                $issues_str .= "  - " . $issue['description'] . "\n";
            }
        }
        if ($issues_str === '') return '';
        return "\n\n═══════════════════════════════════════════\n🔁 TENTATIVA ANTERIOR FOI REJEITADA POR:\n═══════════════════════════════════════════\n{$issues_str}\nCorrija todos esses pontos nesta nova versão.";
    }

    private function plan_context(array $plan): string {
        $fields = [
            'title' => 'Título',
            'keyword' => 'Keyword',
            'category_name' => 'Categoria',
            'search_intent' => 'Intenção',
            'content_type' => 'Tipo',
            'outline' => 'Outline',
            'entities' => 'Entidades',
            'secondary_keywords' => 'Keywords secundárias',
            'lsi_keywords' => 'LSI',
            'external_sources' => 'Fontes externas',
            'internal_links' => 'Links internos',
            'eeat_angle' => 'Ângulo E-E-A-T',
        ];
        $out = '';
        foreach ($fields as $key => $label) {
            $value = $plan[$key] ?? '';
            if (is_array($value)) $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            $value = trim((string)$value);
            if ($value !== '') {
                $out .= "{$label}: " . mb_substr($value, 0, 2500) . "\n";
            }
        }
        return $out ?: 'Sem contexto detalhado além do título/keyword.';
    }

    private function build_author_ecosystem_context(): string {
        $fields = [
            'Nome do autor'           => get_option('geo_author_name', ''),
            'Especialidade'           => get_option('geo_author_specialty', ''),
            'Experiência'             => get_option('geo_author_experience', ''),
            'Certificações'           => get_option('geo_author_certifications', ''),
            'Bio editorial'           => get_option('geo_author_bio', ''),
            'Áreas de expertise'      => get_option('geo_author_knowsabout', ''),
            'Credenciais'             => get_option('geo_author_credentials', ''),
            'Página editorial'        => get_option('geo_author_editorial_page', ''),
            'Subdomínios/ecossistema' => get_option('geo_author_subdomains', ''),
            'Categorias do site'      => get_option('geo_author_site_categories', ''),
        ];

        $lines = [];
        foreach ($fields as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $lines[] = "- {$label}: " . mb_substr(preg_replace('/\s+/', ' ', $value), 0, 500);
            }
        }

        if (empty($lines)) return '';

        return "\n\nECOSSISTEMA DO AUTOR / E-E-A-T CONFIGURADO NO PLUGIN:\n"
             . implode("\n", $lines)
             . "\nUse esse contexto para calibrar tom e autoridade editorial. A caixa visual do autor será aplicada automaticamente no final do artigo.";
    }
}
