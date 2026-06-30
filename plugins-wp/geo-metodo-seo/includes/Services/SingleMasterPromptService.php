<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) exit;

/**
 * SingleMasterPromptService
 * Cria prompts mestres únicos por gerador para manter qualidade SEO/GEO/AEO/LLM
 * sem fragmentar o artigo em 9-13 chamadas de IA.
 */
class SingleMasterPromptService {

    public static function language_label(string $language): string {
        $map = [
            'pt-BR' => 'Português do Brasil',
            'pt-PT' => 'Português de Portugal',
            'en' => 'English',
            'en-US' => 'American English',
            'en-GB' => 'British English',
            'es' => 'Español',
            'es-ES' => 'Español',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'it' => 'Italiano',
        ];
        return $map[$language] ?? $map[explode('-', $language)[0] ?? 'pt-BR'] ?? 'Português do Brasil';
    }

    public static function tone_instruction(string $tone): string {
        $map = [
            'profissional' => 'Tom profissional, claro, objetivo, editorial e autoritativo.',
            'persuasivo' => 'Tom persuasivo, com argumentos fortes, benefícios claros e linguagem convincente sem exageros.',
            'informal' => 'Tom informal e próximo, mas mantendo precisão e utilidade.',
            'tecnico' => 'Tom técnico e especializado, com terminologia correta e explicações profundas.',
            'storytelling' => 'Tom narrativo com exemplos, contexto e progressão lógica.',
            'educativo' => 'Tom educativo, didático e acessível, com explicações passo a passo.',
        ];
        return $map[$tone] ?? $map['profissional'];
    }

    public static function article_json_prompt(array $args): string {
        $keyword      = sanitize_text_field((string)($args['keyword'] ?? ''));
        $language     = sanitize_text_field((string)($args['language'] ?? 'pt-BR'));
        $tone         = sanitize_text_field((string)($args['tone'] ?? 'profissional'));
        $scope        = sanitize_text_field((string)($args['scope'] ?? 'gerador_individual'));
        $category     = sanitize_text_field((string)($args['category'] ?? ''));
        $niche        = sanitize_text_field((string)($args['niche'] ?? get_option('sara_niche', 'Tecnologia')));
        $year         = date('Y');
        $lang         = self::language_label($language);
        $tone_inst    = self::tone_instruction($tone);
        // FIX v1.0.0-WORDCOUNT-ALL: aceitar 800-6000 (antes forçava mínimo 1800).
        $target_words = max(800, min(6000, (int)($args['target_words'] ?? get_option('geo_single_master_target_words', 2500))));
        $min_words    = (int) floor($target_words * 0.90);
        $max_words    = (int) ceil($target_words * 1.10);
        $intent       = self::detect_intent($keyword);

        // LSI, entidades e keywords secundárias vindas do plano/Brain
        $entities  = array_filter((array)($args['entities'] ?? []));
        $sec_kws   = array_filter((array)($args['secondary_keywords'] ?? []));
        $lsi       = array_filter((array)($args['lsi_keywords'] ?? []));

        // FIX-LINKS: links internos e externos para a IA inserir naturalmente
        $internal_links = array_filter((array)($args['internal_links'] ?? []));
        $external_links = array_filter((array)($args['external_links'] ?? []));

        // Montar bloco de links usando o método do ContextEngine
        $links_block = '';
        if (!empty($internal_links) || !empty($external_links)) {
            if (class_exists('\GeoMetodoSEO\Services\ContextEngine')) {
                $ctx = new \GeoMetodoSEO\Services\ContextEngine(new \GeoMetodoSEO\AI\AIManager());
                $links_block = $ctx->build_links_block($internal_links, $external_links);
            }
        }

        $entities_block = !empty($entities)
            ? "\nENTIDADES OBRIGATÓRIAS (marcas, modelos, tecnologias): " . implode(', ', array_slice($entities, 0, 10))
              . "\n→ Mencione cada uma ao menos 1x em contexto real. Explique brevemente na 1ª menção. Nunca invente dados sobre elas."
            : '';
        $sec_kws_block = !empty($sec_kws)
            ? "\nKEYWORDS SECUNDÁRIAS: " . implode(', ', array_slice($sec_kws, 0, 8))
              . "\n→ Use nos H2, H3 e primeiros parágrafos de cada seção."
            : '';
        $lsi_block = !empty($lsi)
            ? "\nTERMOS SEMÂNTICOS/LSI: " . implode(', ', array_slice($lsi, 0, 12))
              . "\n→ Distribua 1-2 termos por seção H2. Não force todos no mesmo parágrafo."
            : '';

        return "🛑 REGRA DE TAMANHO INEGOCIÁVEL — LEIA PRIMEIRO:\n"
            . "Escreva aproximadamente entre {$min_words} e {$max_words} palavras de conteúdo total.\n"
            . "META: {$target_words} palavras.\n"
            . "Evite ultrapassar {$max_words} palavras; remova redundância em vez de inflar o texto.\n"
            . "ESTRATÉGIA: 5-6 seções H2 com ~" . (int)floor($target_words / 6) . " palavras cada. MÁXIMO ABSOLUTO POR H2: 300 palavras.\n"
            . "NÃO encha de exemplos hipotéticos. NÃO repita ideias. NÃO escreva parágrafos genéricos só para encher.\n\n"
            . "📐 REGRA CRÍTICA DE H2 E H3:\n"
            . "- MÁXIMO 250-300 palavras por H2 — se precisar de mais, crie outro H2 separado\n"
            . "- H2 com mais de 200 palavras ou com 2+ subtópicos: OBRIGATÓRIO pelo menos 1 H3\n"
            . "- H3 SEMPRE dentro de H2, nunca solto. Máximo 3 H3 por H2. Nunca H4 ou H5\n"
            . "- Use <ul>/<ol> quando listar 3+ itens em sequência\n\n"
            . "✅ CONCLUSÃO OBRIGATÓRIA: último campo = conclusão. PROIBIDO terminar sem ela.\n\n"
            . "🚫 ANTI-COMMODITY — REGRA DO GOOGLE 2026:\n"
            . "O Google penaliza conteúdo genérico. Cada H2 DEVE ter pelo menos um:\n"
            . "- Exemplo real e específico do nicho {$niche} (não hipotético)\n"
            . "- Comparação concreta com prós/contras explícitos\n"
            . "- Erro comum do nicho + solução direta\n"
            . "- Insight contra-intuitivo que a maioria não sabe\n"
            . "Se um H2 puder ser publicado em qualquer nicho sem mudar nada, reescreva.\n\n"
            . "📱 PARÁGRAFOS CURTOS — OBRIGATÓRIO:\n"
            . "Cada <p> MÁXIMO 60-80 palavras. NUNCA mais de 100 palavras por <p>. Uma ideia por parágrafo.\n\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
            . "Você é um editor-chefe especialista em SEO técnico, GEO (Generative Engine Optimization), AEO, LLM Optimization, E-E-A-T e conteúdo útil para Google Search, Google Discover e respostas de IA.\n\n"
            . "GERADOR/ESCOPO: {$scope}\n"
            . "IDIOMA OBRIGATÓRIO: {$lang}\n"
            . "TEMA/KEYWORD PRINCIPAL: {$keyword}\n"
            . "NICHO/CATEGORIA: {$niche} / {$category}\n"
            . "INTENÇÃO DE BUSCA: {$intent}\n"
            . "ANO ATUAL: {$year}\n"
            . "TOM: {$tone_inst}\n"
            . "TAMANHO ALVO: {$target_words} palavras (mínimo {$min_words}, máximo absoluto {$max_words}).\n"
            . $entities_block . $sec_kws_block . $lsi_block . "\n\n"
            . "TÍTULO OBRIGATÓRIO: A PRIMEIRA linha da resposta deve ser um comentário HTML com o título do artigo, "
            . "TRADUZIDO/ADAPTADO para o idioma {$lang} (NUNCA deixe o título em outro idioma, mesmo que a keyword esteja em inglês). "
            . "Formato exato: <!-- TITLE: seu título aqui, atraente, com a keyword, até 60 caracteres, no idioma {$lang} -->\n\n"
            . "OBJETIVO: gerar em UMA ÚNICA CHAMADA um artigo completo, profundo e publicável, cobrindo as lacunas de SEO, GEO, AEO, LLM e E-E-A-T.\n\n"
            . "REGRAS ABSOLUTAS DE QUALIDADE:\n"
            . "1. Não invente estatísticas, preços, datas, estudos, benchmarks, fichas técnicas, fontes, lançamentos ou afirmações factuais não verificáveis.\n"
            . "2. Quando não houver dado confirmado, use linguagem cautelosa: 'em geral', 'pode variar', 'depende do contexto', 'verifique na fonte oficial'.\n"
            . "3. Use a keyword principal no primeiro parágrafo, em pelo menos um H2 e naturalmente ao longo do texto (1x por 250-350 palavras, máx 1x por parágrafo).\n"
            . "4. Cubra definição, contexto, funcionamento, critérios de decisão, benefícios, riscos, erros comuns, aplicação prática e conclusão.\n"
            . "5. Escreva para humanos, mas estruturado para LLMs: parágrafos curtos, resposta direta, subtítulos claros, listas e tabela.\n"
            . "6. SPEAKABLE — a Resposta Rápida e o 1º parágrafo de cada H2 são marcados com Schema Speakable pelo plugin. Escreva-os para voz: sem 'veja a tabela abaixo', sem referências visuais, com sujeito explícito em cada frase, máx 2-3 frases diretas.\n"
            . "7. GEO — cada seção H2 deve ser autossuficiente: uma IA que ler apenas aquela seção deve entender a resposta sem o resto do artigo.\n"
            . "8. AEO — o 1º parágrafo de cada H2 é o candidato ao Featured Snippet: resposta direta em 40-70 palavras, com dado concreto apenas se estiver disponível no briefing.\n"
            . "9. PRIMEIRAS 100 PALAVRAS: devem responder diretamente a intenção de busca. Sem 'neste artigo vamos ver'. Keyword no 1º parágrafo. O leitor aprende algo útil mesmo lendo só o início.\n"
            . "10. HIERARQUIA DE HEADINGS: H3 sempre dentro de H2, nunca solto. Nunca H4/H5. Máx 3 H3 por H2.\n"
            . "11. LINKS INTERNOS (se fornecidos no contexto): inserir no texto com anchor text descritivo (nunca 'clique aqui'). Máx 1 link por 300 palavras. Se não couber naturalmente, não force.\n"
            . "12. LINKS EXTERNOS (se fornecidos no contexto): máx 2 por artigo. Nunca crie URLs — use apenas as fornecidas.\n"
            . "13. PROIBIDO: 'no mundo de hoje', 'cada vez mais', 'é fundamental', 'é essencial', 'vamos explorar', 'guia completo', 'guia definitivo', 'em resumo', 'vale ressaltar'.\n"
            . "14. ORIGINALIDADE — cada parágrafo deve ter: comparação prática OU erro comum + solução OU insight não-óbvio OU explicação de causa e efeito. Use dado numérico somente se verificável.\n\n"
            . $links_block
            . "FORMATO DE SAÍDA: retorne APENAS um JSON válido com estes campos exatos:\n"
            . "{\n"
            . "  \"title_seo\": \"Título SEO com keyword, atrativo e natural\",\n"
            . "  \"meta_description\": \"Meta description com keyword e benefício claro, até 155 caracteres\",\n"
            . "  \"url_slug\": \"slug-em-kebab-case\",\n"
            . "  \"resumo_snippet\": \"<div class='sara-quick-answer geo-quick-answer'><strong>⚡ Resposta Rápida:</strong> resposta direta de 45-75 palavras sobre {$keyword}, clara para voz e featured snippet.</div>\",\n"
            . "  \"introducao\": \"<p>Introdução com keyword no 1º parágrafo.</p><p>Contextualização e promessa editorial.</p>\",\n"
            . "  \"secao_definicao\": \"<p>Definição clara — 1º parágrafo apto para Speakable e Featured Snippet.</p><h3>Por que isso importa</h3><p>Contexto com dado concreto ou comparação.</p>\",\n"
            . "  \"secao_funcionamento\": \"<p>Como funciona — resposta direta em 40-70 palavras.</p><h3>Elementos principais</h3><ul><li>...</li></ul>\",\n"
            . "  \"tabela_comparativa\": \"<p>Introdução da tabela.</p><table style='width:100%;border-collapse:collapse;margin:20px 0;'><thead><tr><th>Critério</th><th>O que avaliar</th><th>Impacto</th></tr></thead><tbody><tr><td>...</td><td>...</td><td>...</td></tr></tbody></table><p>Análise objetiva.</p>\",\n"
            . "  \"secao_beneficios\": \"<p>Benefícios com dado concreto no 1º parágrafo.</p><ul><li><strong>Benefício:</strong> explicação prática com quantificação.</li></ul>\",\n"
            . "  \"secao_guia\": \"<p>Guia prático — resposta direta no 1º parágrafo.</p><h3>Passo 1</h3><p>...</p><h3>Passo 2</h3><p>...</p>\",\n"
            . "  \"dica_especialista\": \"<blockquote style='border-left:4px solid #8B5CF6;padding:16px 20px;background:rgba(139,92,246,0.08);margin:28px 0;border-radius:0 8px 8px 0;'><strong>💡 Dica de Especialista:</strong> insight avançado, contra-intuitivo e acionável sobre {$keyword}.</blockquote>\",\n"
            . "  \"erros_comuns\": \"<p>Erros mais frequentes — resposta direta no 1º parágrafo.</p><h3>Erro 1</h3><p>Como evitar com dado concreto.</p><h3>Erro 2</h3><p>Como evitar.</p>\",\n"
            . "  \"tendencias_2026\": \"<p><strong>Tendência em {$year}:</strong> análise cautelosa e útil.</p><p><strong>O que observar:</strong> pontos de atenção práticos.</p>\",\n"
            . "  \"conclusao\": \"<p>Síntese do que o leitor aprendeu — 2-3 frases objetivas.</p><p>Recomendação concreta baseada no conteúdo.</p><p>Próxima ação específica que o leitor pode fazer agora.</p>\",\n"
            . "  \"faq_texto\": \"<div class='geo-faq-item'><strong>Pergunta específica sobre {$keyword}?</strong><p>Resposta completa e útil.</p></div><div class='geo-faq-item'><strong>Outra pergunta real?</strong><p>Resposta completa.</p></div>\"\n"
            . "}\n\n"
            . "REGRAS ABSOLUTAS DE FORMATO:\n"
            . "1. Retorne SOMENTE o objeto JSON — sem texto antes, sem texto depois.\n"
            . "2. NÃO use markdown fences (sem ```json, sem ```html, sem ```).\n"
            . "3. NÃO adicione explicações, comentários ou notas fora do JSON.\n"
            . "4. Cada campo deve ter conteúdo REAL, extenso e específico sobre {$keyword}.\n"
            . "5. O artigo final montado deve atingir pelo menos {$min_words} palavras.\n"
            . "Comece sua resposta diretamente com { e termine com }";
    }

    public static function html_prompt(array $args): string {
        $keyword      = sanitize_text_field((string)($args['keyword'] ?? ''));
        $title        = sanitize_text_field((string)($args['title'] ?? $keyword));
        $language     = sanitize_text_field((string)($args['language'] ?? 'pt-BR'));
        $scope        = sanitize_text_field((string)($args['scope'] ?? 'sara_writer'));
        $niche        = sanitize_text_field((string)($args['niche'] ?? get_option('sara_niche', 'Tecnologia')));
        $briefing     = (string)($args['briefing'] ?? '');
        $outline      = (string)($args['outline'] ?? '');
        // FIX v1.0.0-WORDCOUNT-ALL: aceitar 800-6000 (antes forçava mínimo 1800).
        $target_words = max(800, min(6000, (int)($args['target_words'] ?? 2500)));
        $min_words    = (int) floor($target_words * 0.90);
        $max_words    = (int) ceil($target_words * 1.10);
        $lang         = self::language_label($language);
        $year         = date('Y');

        // LSI, entidades e keywords secundárias
        $entities = array_filter((array)($args['entities'] ?? []));
        $sec_kws  = array_filter((array)($args['secondary_keywords'] ?? []));

        // FIX-LINKS: links internos e externos para a IA inserir naturalmente
        $internal_links_html = array_filter((array)($args['internal_links'] ?? []));
        $external_links_html = array_filter((array)($args['external_links'] ?? []));
        $links_block_html = '';
        if (!empty($internal_links_html) || !empty($external_links_html)) {
            if (class_exists('\GeoMetodoSEO\Services\ContextEngine')) {
                $ctx = new \GeoMetodoSEO\Services\ContextEngine(new \GeoMetodoSEO\AI\AIManager());
                $links_block_html = $ctx->build_links_block($internal_links_html, $external_links_html);
            }
        }
        $lsi      = array_filter((array)($args['lsi_keywords'] ?? []));

        $entities_block = !empty($entities)
            ? "\nENTIDADES OBRIGATÓRIAS: " . implode(', ', array_slice($entities, 0, 10))
              . "\n→ Mencione cada uma ao menos 1x. Explique brevemente na 1ª menção."
            : '';
        $sec_kws_block = !empty($sec_kws)
            ? "\nKEYWORDS SECUNDÁRIAS: " . implode(', ', array_slice($sec_kws, 0, 8))
              . "\n→ Use nos H2, H3 e primeiros parágrafos de cada seção."
            : '';
        $lsi_block = !empty($lsi)
            ? "\nTERMOS SEMÂNTICOS/LSI: " . implode(', ', array_slice($lsi, 0, 12))
              . "\n→ 1-2 termos por seção H2, distribuídos organicamente."
            : '';

        return "🛑 REGRA DE TAMANHO INEGOCIÁVEL — LEIA PRIMEIRO:\n"
            . "Escreva aproximadamente entre {$min_words} e {$max_words} palavras de conteúdo total.\n"
            . "META: {$target_words} palavras.\n"
            . "Evite ultrapassar {$max_words} palavras; remova redundância em vez de inflar o texto.\n"
            . "ESTRATÉGIA: 5-6 seções H2 com ~" . (int)floor($target_words / 6) . " palavras cada. MÁXIMO ABSOLUTO POR H2: 300 palavras.\n\n"
            . "📐 REGRA CRÍTICA DE H2 E H3:\n"
            . "- MÁXIMO 250-300 palavras por H2 — se precisar de mais, crie outro H2 separado\n"
            . "- H2 com mais de 200 palavras ou 2+ subtópicos: OBRIGATÓRIO pelo menos 1 H3\n"
            . "- H3 SEMPRE dentro de H2, nunca solto. Máximo 3 H3 por H2. Nunca H4 ou H5\n"
            . "- Use <ul>/<ol> quando listar 3+ itens em sequência\n\n"
            . "✅ CONCLUSÃO OBRIGATÓRIA: artigo SEM conclusão = REJEITADO.\n\n"
            . "🚫 ANTI-COMMODITY — REGRA DO GOOGLE 2026:\n"
            . "Cada H2 DEVE ter pelo menos um: exemplo real do nicho {$niche} | comparação com prós/contras | erro comum + solução | insight contra-intuitivo.\n"
            . "Se um H2 puder ser publicado em qualquer nicho sem mudar nada, reescreva.\n\n"
            . "📱 PARÁGRAFOS CURTOS — OBRIGATÓRIO:\n"
            . "Cada <p> MÁXIMO 60-80 palavras. NUNCA mais de 100 palavras por <p>. Uma ideia por parágrafo.\n\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
            . "Você é um redator sênior especialista em SEO, GEO, AEO, LLM Optimization, E-E-A-T e conteúdo útil para Google e IA.\n\n"
            . "ESCOPO: {$scope}\nIDIOMA: {$lang}\nTÍTULO: {$title}\nKEYWORD PRINCIPAL: {$keyword}\nNICHO: {$niche}\nANO: {$year}\n"
            . "TAMANHO ALVO: {$target_words} palavras (mínimo {$min_words}, máximo absoluto {$max_words}).\n"
            . $entities_block . $sec_kws_block . $lsi_block . "\n\n"
            . "BRIEFING FACTUAL:\n{$briefing}\n\n"
            . "OUTLINE/ESTRUTURA OBRIGATÓRIA:\n{$outline}\n\n"
            . "Gere em UMA ÚNICA CHAMADA um artigo HTML completo, profundo e publicável.\n\n"
            . $links_block_html
            . "REGRAS OBRIGATÓRIAS:\n"
            . "1. Não invente dados, fontes, datas, preços, estudos, estatísticas ou especificações. Use cautela quando faltar dado confirmado.\n"
            . "2. Keyword principal: no 1º parágrafo, a cada 250-350 palavras, máx 1x por parágrafo.\n"
            . "3. PRIMEIRAS 100 PALAVRAS: devem responder diretamente a intenção de busca. Sem 'neste artigo vamos ver'. O leitor aprende algo útil mesmo lendo só o início.\n"
            . "4. HIERARQUIA DE HEADINGS: H3 sempre dentro de H2, nunca solto. Nunca H4/H5. Máx 3 H3 por H2. Proporção: 5-7 H2 com 0-3 H3 cada.\n"
            . "5. SPEAKABLE — a Resposta Rápida e o 1º parágrafo de cada H2 serão marcados com Schema Speakable pelo plugin. Escreva para VOZ: sem referências visuais ('veja tabela abaixo'), sujeito explícito em cada frase, voz ativa, máx 2-3 frases diretas por parágrafo de abertura.\n"
            . "6. GEO — cada H2 deve ser autossuficiente: uma IA que ler só aquela seção entende a resposta completa.\n"
            . "7. AEO — 1º parágrafo de cada H2: resposta direta em 40-70 palavras, com dado concreto apenas se estiver disponível no briefing.\n"
            . "8. LINKS INTERNOS (se fornecidos no contexto): inserir no texto com anchor text descritivo (nunca 'clique aqui'). Máx 1 link por 300 palavras. Se não couber naturalmente, não force.\n"
            . "9. LINKS EXTERNOS (se fornecidos): máx 2 por artigo. Nunca crie URLs — use apenas as fornecidas.\n"
            . "10. ORIGINALIDADE — cada parágrafo: comparação prática OU erro+solução OU insight não-óbvio. Use dado numérico somente se verificável.\n"
            . "11. PROIBIDO: 'no mundo de hoje', 'cada vez mais', 'é fundamental', 'é essencial', 'vamos explorar', 'guia completo', 'em resumo', 'vale ressaltar'.\n"
            . "12. Inclua: Resposta Rápida, introdução, H2/H3 seguindo outline, tabela HTML, dicas especialista, erros comuns, próximos passos.\n"
            . "13. Não inclua FAQ (gerado separadamente). Não inclua H1. Sem markdown. Sem JSON.\n\n"
            . "Retorne somente HTML limpo para WordPress.";
    }

    private static function detect_intent(string $keyword): string {
        $kw = mb_strtolower($keyword);
        if (preg_match('/comprar|preço|preco|valor|custo|barato|promoção|promocao/u', $kw)) return 'transacional';
        if (preg_match('/melhor|melhores|review|vale a pena|comparativo|vs|versus/u', $kw)) return 'comercial';
        if (preg_match('/como|guia|tutorial|passo a passo|o que é|o que e/u', $kw)) return 'informacional/how-to';
        return 'informacional';
    }
}
