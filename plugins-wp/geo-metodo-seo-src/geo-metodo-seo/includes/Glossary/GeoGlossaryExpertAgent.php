<?php
namespace GeoMetodoSEO\Glossary;

if (!defined('ABSPATH')) { exit; }

/**
 * GeoGlossaryExpertAgent
 *
 * Agente editorial interno para o GEO Glossário SEO.
 * Não é fine-tuning externo: é uma camada de prompt mestre, briefing defensivo,
 * validação semântica e guardrails anti-invenção.
 */
class GeoGlossaryExpertAgent {

    public function title_prompt(string $theme, array $letters, int $per_letter, array $existing_titles = [], array $briefing = []): string {
        $theme = sanitize_text_field($theme);
        $letters_str = implode(', ', array_map('strtoupper', $letters));
        $existing = implode("\n", array_slice(array_filter($existing_titles), 0, 260));
        $year = date('Y');
        $context = trim((string)($briefing['context'] ?? ''));
        $audience = trim((string)($briefing['audience'] ?? ''));
        $intents = trim((string)($briefing['intents'] ?? ''));
        $extra = trim((string)($briefing['extra'] ?? ''));
        $briefing_block = "BRIEFING DO GLOSSÁRIO:\n"
            . "- Contexto/nicho: " . ($context ?: 'não informado') . "\n"
            . "- Público-alvo: " . ($audience ?: 'não informado') . "\n"
            . "- Intenções permitidas: " . ($intents ?: 'definição, como funciona, comparação, problema e solução, termos técnicos') . "\n"
            . "- Instruções extras: " . ($extra ?: 'não informado') . "\n\n";

        return "Você é o AGENTE GEO GLOSSÁRIO SEO, um editor especialista sênior em SEO, GEO (Generative Engine Optimization), AEO, otimização para LLMs e arquitetura semântica de conteúdo.\n\n"
            . "MISSÃO: criar títulos de glossário profissionais, úteis e semanticamente precisos para o tema: \"{$theme}\".\n"
            . $briefing_block
            . "Letras solicitadas: {$letters_str}. Gere EXATAMENTE {$per_letter} títulos por letra. Ano editorial: {$year}.\n\n"
            . "REGRAS MESTRAS ANTI-INVENÇÃO:\n"
            . "- Não invente termos, ferramentas, siglas, metodologias ou conceitos que não existam no nicho.\n"
            . "- Não crie título apenas porque começa com a letra; o termo precisa fazer sentido real dentro do tema.\n"
            . "- Não use promessas, números, estatísticas, datas, rankings ou fontes não confirmadas.\n"
            . "- Não gere assunto genérico que poderia servir para qualquer tema.\n"
            . "- Não repita sinônimos óbvios, variações pequenas ou títulos com a mesma intenção.\n"
            . "- Evite clickbait e linguagem artificial.\n\n"
            . "REGRAS DE LETRA E INTENÇÃO:\n"
            . "- O título completo pode começar com: O que é, Como, Quando, Por que, Qual, Quais, Guia, Checklist, Diferença entre, Estratégia de, Métrica de, Ferramenta de, Processo de, Técnica de, Termo.\n"
            . "- O CONCEITO PRINCIPAL depois dessa estrutura deve começar com a letra correspondente.\n"
            . "- Exemplo para A: \"O que é automação de marketing e como aplicar em SEO\".\n"
            . "- Exemplo para B: \"Como backlinks ajudam na autoridade temática\".\n"
            . "- Cada título deve ter intenção de busca real: definição, comparação, aplicação, erro comum, processo, métrica ou ferramenta legítima do tema.\n\n"
            . "PADRÃO DE QUALIDADE SEO/GEO/AEO/LLM:\n"
            . "- Título claro, natural e profissional.\n"
            . "- Termo com valor para uma página de glossário.\n"
            . "- Potencial de linkagem interna com artigos do site.\n"
            . "- Cobertura de lacuna semântica, não volume por volume.\n"
            . "- Linguagem brasileira natural.\n\n"
            . "TÍTULOS JÁ EXISTENTES/PENDENTES PARA EVITAR:\n{$existing}\n\n"
            . "Responda SOMENTE JSON válido neste formato, sem markdown e sem texto extra:\n"
            . '{"items":[{"letter":"A","title":"O que é automação de marketing e como aplicar em SEO","intent":"informacional","reason":"explica conceito real do tema e cobre dúvida recorrente"}]}';
    }

    public function article_prompt(string $title, string $theme, string $letter, string $related_context = ''): string {
        $briefing = $this->defensive_briefing($title, $theme, $letter);
        $year = date('Y');
        return "Você é o AGENTE GEO GLOSSÁRIO SEO, um especialista mestre em SEO, GEO, AEO, LLM Optimization, arquitetura semântica e glossários editoriais profissionais.\n\n"
            . "MISSÃO: criar uma página de glossário profissional, segura e útil sobre: \"{$title}\".\n"
            . "Tema principal: {$theme}. Letra do glossário: {$letter}. Ano: {$year}.\n\n"
            . "BRIEFING DEFENSIVO DO AGENTE:\n{$briefing}\n\n"
            . "REGRAS ANTI-INVENÇÃO OBRIGATÓRIAS:\n"
            . "- Não invente dados, datas, preços, estatísticas, estudos, fontes, autores, ferramentas, versões, funcionalidades ou exemplos factuais específicos.\n"
            . "- Se algo não estiver confirmado, escreva de forma cautelosa e conceitual.\n"
            . "- Não cite fontes externas inexistentes.\n"
            . "- Não transforme opinião em fato.\n"
            . "- Não faça promessas de ranking, tráfego, indexação ou resultado garantido.\n\n"
            . "TERMOS SEMÂNTICOS/LSI:\n"
            . "- Inclua 3-5 termos semanticamente relacionados ao conceito dentro do texto, em contexto natural.\n"
            . "- Na seção 'Termos relacionados', liste 5-8 termos do mesmo campo semântico — são fundamentais para interlinking e Knowledge Graph.\n"
            . "- Esses termos semânticos aumentam topical authority e a chance de o conteúdo ser citado por IAs.\n\n"
            . "ENTIDADES NOMEADAS:\n"
            . "- Se o conceito envolver marcas, ferramentas, padrões técnicos ou organizações reais, mencione-os pelo nome oficial.\n"
            . "- Explique brevemente cada entidade na 1ª menção: 'Schema.org, o vocabulário de dados estruturados do Google, permite...'\n"
            . "- Entidades aumentam o Knowledge Graph score e a chance de citação por Perplexity, ChatGPT e Gemini.\n\n"
            . "SPEAKABLE SCHEMA:\n"
            . "- O 1º parágrafo após cada H2 será marcado com Schema Speakable pelo plugin.\n"
            . "- Escreva esses parágrafos para VOZ: sem referências visuais ('veja tabela'), sujeito explícito em cada frase, voz ativa, máx 2-3 frases diretas.\n"
            . "- A Resposta Rápida inicial também é Speakable: escreva como se fosse lida por Google Assistant.\n\n"
            . "GEO — GENERATIVE ENGINE OPTIMIZATION:\n"
            . "- Cada H2 deve ser autossuficiente: uma IA que ler apenas aquela seção entende a resposta completa.\n"
            . "- Use linguagem de resposta direta: 'X funciona porque...', 'A diferença entre X e Y é...'\n"
            . "- Inclua contexto temporal quando relevante: 'em {$year}', 'atualmente'.\n\n"
            . "AEO — ANSWER ENGINE OPTIMIZATION:\n"
            . "- 1º parágrafo de cada H2: resposta direta em 40-70 palavras (candidato a Featured Snippet).\n"
            . "- Use listas <ul>/<ol> para passos, critérios ou erros.\n\n"
            . "GOOGLE MARCH 2026 CORE + SPAM UPDATE:\n"
            . "- ORIGINALIDADE: cada parágrafo deve trazer: dado numérico OU comparação prática OU erro+solução OU insight contra-intuitivo.\n"
            . "- ANTI-AGGREGATOR: cada H2 começa com resposta direta nos primeiros 60 palavras.\n"
            . "- E-E-A-T: use 'na prática', 'ao comparar X com Y', 'em casos reais'.\n"
            . "- PROIBIDO: 'no mundo de hoje', 'cada vez mais', 'é fundamental', 'é essencial', 'vamos explorar', 'guia completo', 'guia definitivo'.\n\n"
            . "TAMANHO: entre 900 e 1200 palavras. Não estenda artificialmente.\n\n"
            . "ESTRUTURA HTML OBRIGATÓRIA:\n"
            . "<div class='sara-quick-answer geo-quick-answer'><strong>⚡ Resposta Rápida:</strong> definição direta em até 70 palavras, clara para voz e featured snippet.</div>\n"
            . "<h2>O que significa {$title}</h2>\n"
            . "<p>1º parágrafo: resposta direta 40-70 palavras (Speakable + Featured Snippet).</p>\n"
            . "<h2>Como funciona na prática</h2>\n"
            . "<p>1º parágrafo: resposta direta 40-70 palavras (Speakable).</p>\n"
            . "<h2>Por que esse conceito importa para SEO, GEO e IAs</h2>\n"
            . "<h2>Exemplos de uso real</h2>\n"
            . "<h2>Erros comuns ao interpretar esse termo</h2>\n"
            . "<h2>Termos relacionados</h2>\n"
            . "<ul><li><strong>Termo A:</strong> breve relação com o conceito.</li></ul>\n\n"
            . "ARTIGOS INTERNOS PARA LINKAR NATURALMENTE (somente se fizer sentido):\n{$related_context}\n\n"
            . "FORMATO DE SAÍDA: retorne SOMENTE HTML limpo para WordPress. Não use markdown. Não escreva notas fora do HTML.";
    }

    public function defensive_briefing(string $title, string $theme, string $letter): string {
        $focus = $this->extract_focus_term($title);
        return "- Termo foco detectado: " . ($focus ?: $title) . "\n"
            . "- Tema permitido: {$theme}\n"
            . "- Letra esperada do conceito principal: {$letter}\n"
            . "- O texto deve explicar definição, uso, contexto, erros e termos relacionados.\n"
            . "- O texto não deve inventar prova, fonte, ferramenta ou dado não fornecido.\n"
            . "- Se houver dúvida factual, usar linguagem cautelosa: 'em geral', 'pode', 'costuma', 'depende do contexto'.";
    }

    public function validate_title(string $title, string $theme, string $letter): array {
        $title = trim(wp_strip_all_tags($title));
        $t = mb_strtolower($title);
        if (mb_strlen($title) < 24 || mb_strlen($title) > 125) return [false, 'tamanho inadequado'];
        if (!preg_match('/^(o que|como|quando|por que|qual|quais|guia|checklist|diferen[cç]a|estrat[eé]gia|m[eé]trica|ferramenta|processo|t[eé]cnica|termo)\b/iu', $title)) return [false, 'início fora do padrão'];
        foreach (['segredo','chocante','milagroso','garantido','hack secreto','imperdível','detona','explosivo','absurdo'] as $bad) {
            if (strpos($t, $bad) !== false) return [false, 'clickbait'];
        }
        $focus = $this->extract_focus_term($title);
        if (!$focus) return [false, 'sem termo foco'];
        $first = mb_strtoupper(mb_substr(remove_accents($focus), 0, 1));
        if ($first !== mb_strtoupper($letter)) return [false, 'termo foco não começa com a letra'];
        if (!$this->theme_relevance_check($title, $theme)) return [false, 'fora do tema'];
        return [true, 'ok'];
    }

    public function validate_article_html(string $html, string $title, string $theme): array {
        $plain = trim(wp_strip_all_tags($html));
        $words = str_word_count($plain);
        if ($words < 650) return [false, 'conteúdo curto demais'];
        foreach (['O que significa','Como funciona na prática','Erros comuns','Termos relacionados'] as $heading) {
            if (stripos($html, $heading) === false) return [false, "seção obrigatória ausente: {$heading}"];
        }
        if (preg_match('/\b(estudo comprova|pesquisa revelou|segundo especialistas|dados mostram que|ranking oficial)\b/iu', $plain)) {
            return [false, 'possível afirmação factual sem fonte'];
        }
        return [true, 'ok'];
    }

    public function extract_focus_term(string $title): string {
        $s = trim(wp_strip_all_tags($title));
        $patterns = [
            '/^o que (?:é|são)\s+/iu', '/^como\s+/iu', '/^quando\s+/iu', '/^por que\s+/iu', '/^qual\s+/iu', '/^quais\s+/iu',
            '/^guia (?:de|do|da|dos|das)?\s*/iu', '/^checklist (?:de|do|da|dos|das)?\s*/iu',
            '/^diferen[cç]a entre\s+/iu', '/^estrat[eé]gia (?:de|do|da|dos|das)?\s*/iu',
            '/^m[eé]trica (?:de|do|da|dos|das)?\s*/iu', '/^ferramenta (?:de|do|da|dos|das)?\s*/iu',
            '/^processo (?:de|do|da|dos|das)?\s*/iu', '/^t[eé]cnica (?:de|do|da|dos|das)?\s*/iu', '/^termo (?:de|do|da|dos|das)?\s*/iu'
        ];
        foreach ($patterns as $p) $s = preg_replace($p, '', $s);
        $s = trim($s);
        $parts = preg_split('/\s+(?:e|para|em|no|na|nos|nas|com|sem|que|quando|como)\s+/iu', $s);
        return trim($parts[0] ?? $s);
    }

    private function theme_relevance_check(string $title, string $theme): bool {
        $title_l = mb_strtolower(remove_accents($title));
        $theme_l = mb_strtolower(remove_accents($theme));
        $theme_words = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/i', ' ', $theme_l));
        $theme_words = array_values(array_filter($theme_words, fn($w) => mb_strlen($w) >= 4));
        foreach ($theme_words as $w) {
            if (strpos($title_l, $w) !== false) return true;
        }
        $allowed = ['seo','geo','aeo','llm','ia','inteligencia artificial','google','busca','conteudo','marketing','wordpress','site','blog','trafego','autoridade','ranking','schema','entidade','keyword','palavra-chave','link','backlink','crawler','indexacao','semantica','copywriting','funil','conversao','analytics'];
        foreach ($allowed as $w) {
            if (strpos($theme_l, $w) !== false && strpos($title_l, $w) !== false) return true;
        }
        // Não bloquear demais temas curtos; o prompt ainda restringe. Mas títulos 100% genéricos são rejeitados acima.
        return count($theme_words) <= 1;
    }
}
