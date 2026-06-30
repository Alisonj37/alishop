<?php
namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * ContextEngine — Motor de prompt especialista para geração de artigos.
 *
 * Gera artigos de nível profissional otimizados para:
 * - Google Search (SEO on-page, E-E-A-T, Core Web Vitals)
 * - Generative Engine Optimization (ChatGPT, Gemini, Perplexity, Claude)
 * - LLMs.txt e AI Visibility
 *
 * Funciona para QUALQUER nicho (tecnologia, receitas, saúde, finanças, etc.)
 * e QUALQUER idioma (pt-BR, en, es, fr, de, etc.)
 *
 * @since 1.0.0
 */
class ContextEngine {

    /**
     * InstruçÃµes de idioma — garante que o artigo seja escrito no idioma correto
     * com nuances culturais específicas de cada mercado.
     */
    private $lang_map = [
        'pt-BR' => [
            'instruction' => 'Escreva TODO o conteúdo em Português do Brasil (pt-BR). Use linguagem natural, fluente e envolvente. Evite estrangeirismos desnecessários. Adapte exemplos e referências ao contexto brasileiro.',
            'year_label'  => 'Ano atual',
            'quick_answer' => 'Resposta Rápida',
        ],
        'en'    => [
            'instruction' => 'Write ALL content in American English (en-US). Use natural, conversational yet authoritative language. Use examples and references relevant to the US/global market.',
            'year_label'  => 'Current year',
            'quick_answer' => 'Quick Answer',
        ],
        'es'    => [
            'instruction' => 'Escribe TODO el contenido en EspaÃ±ol. Usa un lenguaje natural, fluido y atractivo. Adapta los ejemplos al contexto hispanohablante.',
            'year_label'  => 'AÃ±o actual',
            'quick_answer' => 'Respuesta Rápida',
        ],
        'fr'    => [
            'instruction' => 'Rédigez TOUT le contenu en Français. Utilisez un langage naturel, fluide et engageant. Adaptez les exemples au contexte francophone.',
            'year_label'  => 'Année actuelle',
            'quick_answer' => 'Réponse Rapide',
        ],
        'de'    => [
            'instruction' => 'Schreiben Sie alle Inhalte auf Deutsch. Verwenden Sie eine natÃ¼rliche, flÃ¼ssige und ansprechende Sprache.',
            'year_label'  => 'Aktuelles Jahr',
            'quick_answer' => 'Schnelle Antwort',
        ],
        'it'    => [
            'instruction' => 'Scrivi TUTTO il contenuto in Italiano. Usa un linguaggio naturale, fluente e coinvolgente.',
            'year_label'  => 'Anno corrente',
            'quick_answer' => 'Risposta Rapida',
        ],
    ];

    /**
     * InstruçÃµes de tom de voz.
     */
    private $tone_map = [
        'profissional'  => 'Tom: Profissional e autoritativo — claro, objetivo, baseado em dados. Como um consultor sênior explicando para um cliente importante.',
        'persuasivo'    => 'Tom: Persuasivo e convincente — use dados, provas sociais e urgência. Como um copywriter especialista convertendo leitores.',
        'informal'      => 'Tom: Informal e próximo — conversacional, sem jargÃµes desnecessários. Como um amigo especialista explicando algo.',
        'tecnico'       => 'Tom: Técnico e especializado — terminologia precisa, detalhes técnicos, referências a padrÃµes e estudos.',
        'storytelling'  => 'Tom: Narrativo e envolvente — conte histórias, use casos reais, construa narrativa emocional com dados.',
        'educativo'     => 'Tom: Didático e acessível — explique passo a passo, use analogias simples, elimine jargÃµes. Como um professor excelente.',
    ];

    /**
     * Enriquece o prompt com contexto especialista.
     * Produz artigos de nível profissional para qualquer nicho e idioma.
     */
    public function enrich(string $keyword, string $language = 'pt-BR', string $tone = '', string $size = 'large', array $internal_links = [], array $external_links = []): string {
        $year     = date('Y');

        // FIX v1.0.0-WORDCOUNT-INDIVIDUAL: definir total_words real conforme escolha do user.
        // Antes: $total_words, $w_intro, $w_def, $w_func, $w_benef NUNCA eram definidos.
        // Prompt saía com "TOTAL OBRIGATÓRIO:  palavras" (vazio) → IA escrevia o que quisesse.
        // Por isso Artigo Individual saía com 4000+ palavras quando user pedia "small".
        $size_map = [
            'small'  => 1500,   // ~1500 palavras
            'medium' => 2500,   // ~2500 palavras
            'large'  => 3500,   // ~3500 palavras
        ];
        $total_words = $size_map[$size] ?? 2500;
        // Cálculo proporcional por seção (introdução, definição, funcionamento, benefícios)
        $w_intro  = (int) round($total_words * 0.08);   // ~8% por parágrafo de intro
        $w_def    = (int) round($total_words * 0.07);   // ~7% por parágrafo de definição
        $w_func   = (int) round($total_words * 0.08);   // ~8% por parágrafo de funcionamento
        $w_benef  = (int) round($total_words * 0.06);   // ~6% por parágrafo de benefício
        $min_words = (int) floor($total_words * 0.90);
        $max_words = (int) ceil($total_words * 1.10);

        // 1.0.0 BUG FIX: normalizar código de idioma.
        // Antes: $lang_map só tinha 'pt-BR', 'en', 'es', etc
        // Quando user escolhia 'en-US', procura ['en-US'] → não acha → cai pra pt-BR.
        $lang_key = $language;
        if (!isset($this->lang_map[$lang_key])) {
            // Tentar prefixo (en-US → en, es-MX → es)
            $prefix = explode('-', $language)[0];
            $lang_key = isset($this->lang_map[$prefix]) ? $prefix : 'pt-BR';
        }
        $lang     = $this->lang_map[$lang_key];
        $lang_instr = $lang['instruction'];
        $intent   = $this->detect_intent($keyword);
        $tone_instr = !empty($tone) && isset($this->tone_map[$tone])
                    ? "\n" . $this->tone_map[$tone]
                    : "\nTom: Especialista e autoritativo — baseado em critérios verificáveis, linguagem cautelosa e melhores práticas do mercado.";

        // Detectar nicho para personalizar exemplos
        $niche_hint = $this->detect_niche($keyword);

        return "ðŸ›‘ REGRA DE TAMANHO INEGOCIÃVEL — LEIA PRIMEIRO:
Escreva aproximadamente entre {$min_words} e {$max_words} palavras de conteúdo total no JSON.
META: {$total_words} palavras. Se chegar perto de {$max_words}, corte redundÃ¢ncias em vez de inflar o texto.
ESTRATÃ‰GIA: 5-6 seçÃµes H2 com no máximo " . (int)floor($total_words / 6) . " palavras cada. MÃXIMO ABSOLUTO POR H2: 300 palavras.
NÃƒO encha de exemplos hipotéticos. NÃƒO repita ideias. NÃƒO escreva parágrafos genéricos só para encher.

ðŸ“ REGRA CRÃTICA DE H2 E H3:
- MÃXIMO 250-300 palavras por H2 — se precisar de mais, crie outro H2 separado
- H2 com mais de 200 palavras ou com 2+ subtópicos: OBRIGATÓRIO pelo menos 1 H3
- H3 SEMPRE dentro de H2, nunca solto. Máximo 3 H3 por H2. Nunca H4 ou H5
- Use <ul>/<ol> quando listar 3+ itens em sequência

âœ… CONCLUSÃƒO OBRIGATÓRIA:
O último campo antes do FAQ deve ser a conclusão. PROIBIDO terminar sem conclusão.
Estrutura da conclusão: síntese (o que o leitor aprendeu) + recomendação concreta + próxima ação específica.

â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”
ðŸš« ANTI-COMMODITY — REGRA DO GOOGLE 2026
â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”
O Google (guia oficial Mai/2026) penaliza conteúdo commodity. Conteúdo commodity é:
- Resumo de informaçÃµes que qualquer pessoa já sabe
- Parágrafos que poderiam ser publicados em qualquer site de qualquer nicho
- Estrutura previsível sem perspectiva única

Para NÃƒO ser commodity, cada H2 DEVE ter pelo menos um:
- Exemplo REAL e específico do nicho (não hipotético)
- Comparação concreta entre 2-3 abordagens com prós/contras explícitos
- Erro comum do nicho + solução direta e prática
- Insight contra-intuitivo (algo que a maioria faz errado)

â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”
ðŸ“± REGRA DE PARÃGRAFO CURTO — OBRIGATÓRIO
â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”
Usuários leem no celular em diagonal. Parágrafos longos = bounce rate alto.
- Cada <p> tem MÃXIMO 60-80 palavras (3-4 linhas)
- NUNCA um <p> com mais de 100 palavras — divida em 2
- Uma ideia por parágrafo — 2 ideias = 2 parágrafos separados
- Use <ul>/<ol> para quebrar visualmente quando listar 3+ itens

â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”

Você é um editor-chefe e especialista sênior em SEO, GEO (Generative Engine Optimization) e criação de conteúdo de alta autoridade, com 15 anos de experiência.

{$lang_instr}{$tone_instr}

â•”â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•—
â•‘  REGRAS ABSOLUTAS — VIOLACAO = ARTIGO INVALIDO        â•‘
â•šâ•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

PROIBICOES ABSOLUTAS — NUNCA faca isso:
1. NUNCA invente estatisticas, percentuais ou numeros (ex: 460% mais produtivo, 98% de precisao)
2. NUNCA atribua dados a fontes reais sem ter certeza absoluta que existem
3. NUNCA fabrique nomes de estudos, relatorios ou pesquisas
4. NUNCA invente depoimentos ou cases de empresas com numeros precisos inventados
5. NUNCA use frases como estudos mostram sem citar a fonte real e verificavel
6. Se nao souber um criterio verificavel, use linguagem qualitativa e cautelosa sem atribuir a especialistas sem fonte
7. NUNCA duplique secoes — cada H2 deve ter conteudo unico, nao repetido em outro H2
8. NUNCA mencione produtos, modelos ou versoes de anos anteriores a 2026 — use apenas o que existe em 2026
9. Tabelas comparativas: use modelos/ferramentas/versoes de 2026 reais, nunca Samsung S22, iPhone 13 ou produtos pre-2026
10. Dicas de especialista OBRIGATORIO: inclua pelo menos 1 blockquote com dica profissional nao obvia

DADOS PERMITIDOS (apenas o que voce tem certeza que existe):
OK: Fatos publicos verificaveis sobre empresas e produtos conhecidos
OK: Caracteristicas documentadas de ferramentas (ex: limite de tokens documentado)
OK: Principios gerais do setor sem numeros especificos inventados
OK: Exemplos hipoteticos sinalizados claramente como tal
OK: Tendencias gerais sem percentuais fabricados

TEMA DO ARTIGO: \"{$keyword}\"
INTENÇÃƒO DE BUSCA: {$intent}
{$niche_hint}
ANO ATUAL: {$year} — use SEMPRE este ano. NUNCA cite anos anteriores como recentes.

â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
REQUISITOS DE QUALIDADE — NÃVEL E-E-A-T MÃXIMO
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

EXPERTISE (Especialidade):
- Demonstre conhecimento profundo com terminologia técnica precisa do nicho
- Use dados, estatísticas ou fontes somente quando estiverem no briefing/contexto com URL ou referência verificável
- Apresente perspectivas que só quem tem experiência real no assunto saberia
- Mencione ferramentas, técnicas e abordagens específicas do nicho

EXPERIENCE (Experiência):
- Use linguagem de quem JÃ FEZ aquilo que está explicando
- Inclua exemplos práticos sem inventar resultados mensuráveis; use números apenas se forem verificáveis
- Mencione erros comuns e como evitá-los (perspectiva de quem já errou)
- Adicione insights e nuances que artigos genéricos nunca têm

AUTHORITATIVENESS (Autoridade):
- Cite fontes reconhecidas apenas quando o contexto fornecer fonte verificável
- Faça referências a estudos, pesquisas e dados somente quando houver link ou identificação real no briefing
- Mencione especialistas somente se forem relevantes e verificáveis
- Use comparaçÃµes qualitativas quando não houver dados confirmados

TRUSTWORTHINESS (Confiabilidade):
- Seja honesto sobre limitaçÃµes, desafios e quando algo não funciona
- Indique quando a informação pode variar por contexto/região
- Evite hipérboles e promessas exageradas
- Inclua advertências relevantes quando necessário

OTIMIZAÇÃƒO GEO (para aparecer em respostas de IA):
- Resposta direta e objetiva nos primeiros parágrafos (ideal para featured snippets e LLMs)
- Defina termos técnicos de forma clara (LLMs extraem definiçÃµes)
- Use estrutura: Definição → Contexto → Como funciona → Benefícios → Como fazer → FAQ
- Evite marcadores vagos como \"segundo especialistas\", \"estudos mostram\" ou \"dados indicam\" sem fonte real
- Entidades nomeadas: pessoas, empresas, ferramentas, conceitos bem definidos
- Formato AI-friendly: parágrafos de 3-4 linhas, frases assertivas, listas estruturadas

â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
REGRAS ABSOLUTAS DE FORMATAÇÃƒO
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
1. Retorne SOMENTE o objeto JSON — sem texto antes ou depois, sem markdown externo
2. TODOS os 12 campos são OBRIGATÓRIOS com conteúdo REAL e EXTENSO
3. META DE TAMANHO: aproximadamente {$total_words} palavras, aceitando a faixa entre {$min_words} e {$max_words}
4. MÃNIMO ACEITÃVEL: {$min_words} palavras. MÃXIMO ABSOLUTO: {$max_words} palavras. Ultrapassar prejudica SEO.
5. Cada seção deve ser proporcional ao tamanho total de {$total_words} palavras
5. Use HTML dentro dos valores: <p>, <ul>, <li>, <ol>, <strong>, <em>, <h3>, <blockquote>, <table>, <tr>, <td>, <th>
6. NÃƒO use markdown (asteriscos, hashtags) dentro dos valores JSON
7. Dados específicos somente se verificáveis no briefing; caso contrário use critérios qualitativos e linguagem cautelosa
8. A tabela comparativa OBRIGATÓRIA: mínimo 5 linhas com critérios verificáveis ou qualitativos relevantes ao nicho
9. OBRIGATÓRIO: title_seo deve conter a keyword exata \"{$keyword}\" ou variação próxima
10. OBRIGATÓRIO: meta_description deve conter a keyword \"{$keyword}\" e um benefício claro
11. OBRIGATÓRIO: url_slug derivado de \"{$keyword}\" em kebab-case
12. OBRIGATÓRIO: keyword \"{$keyword}\" no primeiro parágrafo da introdução
13. Densidade da keyword: mínimo 0,5% (1 ocorrência a cada 200 palavras)
14. NÃƒO repita as mesmas frases em seçÃµes diferentes — cada seção deve agregar valor único

â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
ðŸ›¡ï¸ GOOGLE MARCH 2026 CORE+SPAM UPDATE — REGRAS CRÃTICAS
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
15. ORIGINALIDADE: cada parágrafo deve trazer ao menos UM destes elementos:
    - Dado numérico específico somente se fornecido no briefing/contexto
    - Exemplo prático sem inventar empresa, cidade, percentual ou prazo
    - Comparação prática entre 2-3 abordagens (com pros/contras)
    - Erro comum + solução prática
    - Insight contra-intuitivo ou perspectiva única
16. ANTI-AGGREGATOR: cada H2 começa com resposta direta nos primeiros 60 palavras
17. E-E-A-T: use \"na prática\" e \"comparando X com Y\" sem alegar testes reais ou análise de casos não fornecidos
18. PROIBIDO ABSOLUTAMENTE: \"no mundo de hoje\", \"cada vez mais\", \"Ã  frente da curva\",
    \"é fundamental\", \"é essencial\", \"é importante\", \"vamos explorar\", \"guia completo\",
    \"guia definitivo\", \"imperdível\", \"garantido\"
19. MEDIÇÃƒO CONCRETA: quando citar resultado, quantifique apenas se houver fonte verificável; caso contrário explique tendência, contexto e limitação.
20. Não reformule conteúdo genérico que já está em todo lugar. Adicione valor real ou não escreva.

â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
ESTRUTURA JSON OBRIGATÓRIA
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

IMPORTANTE: os textos entre colchetes abaixo indicam estrutura, nao permissao para inventar dados.
Quando aparecer \"criterio verificavel\", \"resultado esperado qualitativo\", \"caso real\" ou similar, use somente se o briefing trouxer fonte verificavel.
Se nao houver fonte, substitua por criterio qualitativo, exemplo hipotetico sinalizado como exemplo ou orientacao cautelosa.
Nao use background branco ou cinza fixo em blocos, tabelas ou cards; prefira background:transparent, color:inherit e bordas rgba.

{
  \"title_seo\": \"[Título OBRIGATÓRIO com a keyword '{$keyword}', 50-60 chars, com número ou ano {$year} se relevante — DEVE conter a keyword]\",

  \"meta_description\": \"[Descrição persuasiva 150-160 chars — a keyword '{$keyword}' DEVE aparecer — benefício principal + CTA implícito]\",

  \"url_slug\": \"[slug de '{$keyword}' em minúsculas, sem acentos, palavras separadas por hífen]\",

  \"resumo_snippet\": \"<p><strong>[Resposta direta em 2-3 frases sobre o que é {$keyword} em {$year}. Ideal para featured snippet. Use a keyword na primeira frase.]</strong></p>\",

  \"introducao\": \"<p>[Abertura com dado ou contexto verificavel e util de {$year} — mencione a keyword '{$keyword}' aqui — {$w_intro} palavras — NOMES REAIS de dados, ferramentas, empresas]</p><p>[Por que urgente em {$year} — tendência, crescimento do mercado, impacto — {$w_intro} palavras]</p><p>[O que o leitor vai aprender: lista dos principais tópicos do artigo, valor que vai obter — 60 palavras]</p><p>[Gancho: por que este artigo é diferente dos outros sobre o mesmo tema — 60 palavras]</p>\",

  \"secao_definicao\": \"<p>[Definição técnica precisa com contexto histórico e evolução — {$w_def} palavras]</p><p>[Como o conceito é aplicado na prática em {$year} com EXEMPLO REAL de empresa/produto NOMEADO — {$w_def} palavras]</p><p>[Diferença entre conceitos relacionados — esclarecimento técnico com exemplos — {$w_def} palavras]</p><ul><li><strong>[Componente/Aspecto 1]:</strong> [explicação detalhada com exemplo real — 50 palavras]</li><li><strong>[Componente/Aspecto 2]:</strong> [explicação detalhada com exemplo real — 50 palavras]</li><li><strong>[Componente/Aspecto 3]:</strong> [explicação detalhada com exemplo real — 50 palavras]</li><li><strong>[Componente/Aspecto 4]:</strong> [explicação detalhada com exemplo real — 50 palavras]</li><li><strong>[Componente/Aspecto 5]:</strong> [explicação detalhada com exemplo real — 50 palavras]</li></ul><p>[Parágrafo de fechamento com importÃ¢ncia estratégica e por que é relevante para o leitor — 80 palavras]</p>\",

  \"secao_funcionamento\": \"<p>[Visão geral técnica de como funciona — analogia poderosa para facilitar entendimento — 100 palavras]</p><h3>[Etapa/Mecanismo 1: Nome descritivo]</h3><p>[Explicação técnica com FERRAMENTA REAL NOMEADA + resultado mensurável — {$w_func} palavras]</p><h3>[Etapa/Mecanismo 2: Nome descritivo]</h3><p>[Explicação técnica detalhada com exemplo prático, ferramentas específicas e resultado esperado — 120 palavras]</p><h3>[Etapa/Mecanismo 3: Nome descritivo]</h3><p>[Explicação técnica detalhada com exemplo prático, ferramentas específicas e resultado esperado — 120 palavras]</p><h3>[Etapa/Mecanismo 4: Nome descritivo]</h3><p>[Insight avançado com criterio verificavel — {$w_func} palavras]</p><p>[Insight avançado: o que a maioria das pessoas não sabe sobre como funciona — perspectiva de especialista — 80 palavras]</p>\",

  \"tabela_comparativa\": \"<p>[Introdução da tabela: o que ela compara e por que é útil para a decisão do leitor — 60 palavras]</p><table style='width:100%;border-collapse:collapse;margin:20px 0;font-size:15px;'><thead><tr style='background:#1a1a2e;color:#fff;'><th style='border:1px solid #ddd;padding:12px;text-align:left;'>[Critério de Comparação]</th><th style='border:1px solid #ddd;padding:12px;'>[Opção/Ferramenta A]</th><th style='border:1px solid #ddd;padding:12px;'>[Opção/Ferramenta B]</th><th style='border:1px solid #ddd;padding:12px;'>[Opção/Ferramenta C]</th></tr></thead><tbody><tr><td style='border:1px solid #ddd;padding:10px;'><strong>[Critério 1]</strong></td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td></tr><tr style='background:transparent;color:inherit;'><td style='border:1px solid #ddd;padding:10px;'><strong>[Critério 2]</strong></td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td></tr><tr><td style='border:1px solid #ddd;padding:10px;'><strong>[Critério 3]</strong></td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td></tr><tr style='background:transparent;color:inherit;'><td style='border:1px solid #ddd;padding:10px;'><strong>[Critério 4]</strong></td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td></tr><tr><td style='border:1px solid #ddd;padding:10px;'><strong>[Critério 5]</strong></td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td><td style='border:1px solid #ddd;padding:10px;text-align:center;'>[criterio verificavel sem numero inventado]</td></tr></tbody></table><p>[Análise e recomendação baseada nos criterios da tabela — qual opção é melhor para cada perfil de usuário — 80 palavras]</p>\",

  \"secao_beneficios\": \"<p>[Criterio verificavel com FONTE NOMEADA que comprova o valor — {$w_benef} palavras]</p><p>[Contexto: quem se beneficia mais e por quê — público específico — 80 palavras]</p><ul><li><strong>[Benefício 1 — nome específico]:</strong> [explicação com dado mensurável e exemplo real de empresa/pessoa que obteve esse benefício — {$w_benef} palavras]</li><li><strong>[Benefício 2 — nome específico]:</strong> [explicação com dado mensurável e exemplo real — 70 palavras]</li><li><strong>[Benefício 3 — nome específico]:</strong> [explicação com dado mensurável e exemplo real — 70 palavras]</li><li><strong>[Benefício 4 — nome específico]:</strong> [explicação com dado mensurável e exemplo real — 70 palavras]</li><li><strong>[Benefício 5 — nome específico]:</strong> [explicação com dado mensurável e exemplo real — 70 palavras]</li><li><strong>[Benefício 6 — nome específico]:</strong> [explicação com dado mensurável e exemplo real — 70 palavras]</li></ul><p>[Caso de uso real: empresa ou profissional que aplicou e obteve resultado concreto com números — 90 palavras]</p><p>[Fechamento motivacional — próximo passo que o leitor deveria dar — 60 palavras]</p>\",

  \"secao_guia\": \"<p>[Introdução do guia com contextualização — para quem é e o que vai conseguir ao final — 80 palavras]</p><h3>Passo 1: [Nome específico e acionável]</h3><p>[Instrução detalhada: O QUE + COMO + FERRAMENTA NOMEADA + resultado mensurável + erro a evitar — {$w_guia} palavras]</p><h3>Passo 2: [Nome específico e acionável]</h3><p>[Instrução detalhada com: O QUE fazer + COMO fazer + ferramentas específicas + resultado esperado + erro comum a evitar — 130 palavras]</p><h3>Passo 3: [Nome específico e acionável]</h3><p>[Instrução detalhada com: O QUE fazer + COMO fazer + ferramentas específicas + resultado esperado + erro comum a evitar — 130 palavras]</p><h3>Passo 4: [Nome específico e acionável]</h3><p>[Instrução detalhada com: O QUE fazer + COMO fazer + ferramentas específicas + resultado esperado + erro comum a evitar — 130 palavras]</p><h3>Passo 5: [Nome específico e acionável]</h3><p>[Instrução detalhada com: O QUE fazer + COMO fazer + ferramentas específicas + resultado esperado + erro comum a evitar — 130 palavras]</p>\",

  \"conclusao\": \"<p>[Síntese com perspectiva NOVA não mencionada antes — {$w_concl} palavras]</p><p>[Visão de futuro para {$year}+ com tendências específicas — {$w_trend} palavras]</p><p>[CTA específico: próximo passo concreto que o leitor deve tomar HOJE — acionável e com urgência — 60 palavras]</p>\",

  \"faq_texto\": \"[Gere {$n_faq} perguntas REAIS e ESPECÃFICAS que pessoas buscam sobre '{$keyword}'. NUNCA use perguntas genéricas. Cubra: definição básica, como funciona, comparação com alternativas, erros comuns, custos/tempo, casos específicos de uso, perspectiva {$year}. Cada resposta: MÃNIMO {$w_faq} palavras, com exemplo concreto. Use EXATAMENTE este formato HTML sem exceçÃµes: <div class=\\\"geo-faq-item\\\"><strong>[Pergunta específica sobre {$keyword} aqui?]</strong><p>[Resposta completa com mínimo 100 palavras, exemplo real, dado verificável e recomendação prática.]</p></div>]\"
,

  \"erros_comuns\": \"<h3>[Erro Comum 1 — nome específico e real sobre {$keyword}]</h3><p>[Por que profissionais cometem este erro + consequências reais + como evitar com passo concreto — mínimo 80 palavras]</p><h3>[Erro Comum 2 — nome específico]</h3><p>[Por que acontece + como detectar + solução prática — mínimo 80 palavras]</p><h3>[Erro Comum 3 — nome específico]</h3><p>[Por que acontece + impacto real + como corrigir — mínimo 80 palavras]</p>\",

  \"tendencias_2026\": \"<p><strong>[Tendência 1 em {$year}: título específico]:</strong> [descrição detalhada do que está mudando, dados reais, impacto prático para profissionais — 100 palavras]</p><p><strong>[Tendência 2 em {$year}: título específico]:</strong> [descrição detalhada, exemplos reais de empresas ou casos, como aproveitar — 100 palavras]</p><p><strong>[Tendência 3 — previsão 2027]:</strong> [o que especialistas preveem, por que importa agora — 80 palavras]</p>\",

  \"dica_especialista\": \"<blockquote style='border-left:4px solid #8B5CF6;padding:16px 20px;background:rgba(139,92,246,0.08);margin:28px 0;border-radius:0 8px 8px 0;'><strong>ðŸ’¡ Dica de Especialista:</strong> [insight avançado e NÃƒO ÓBVIO sobre '{$keyword}' que apenas quem tem experiência real saberia — surpreendente, específico, acionável HOJE — MÃNIMO 80 palavras. NUNCA seja genérico aqui.]</blockquote>\"}

SUBSTITUIÇÃƒO OBRIGATÓRIA: Substitua TODOS os textos entre [ ] pelo conteúdo REAL sobre \"{$keyword}\".
FORMATO ABSOLUTO: Retorne APENAS o JSON puro. Sem markdown fences (sem ```json, sem ```html, sem ```). Sem texto antes ou depois. Comece com { e termine com }."
            . $this->build_links_block($internal_links, $external_links);
    }

    /**
     * Monta o bloco de instruçÃµes de links internos e externos para o prompt.
     * Chamado por enrich() e pode ser reutilizado em outros prompts.
     */
    public function build_links_block(array $internal_links = [], array $external_links = []): string {
        $block = '';

        // Limite conservador: máx 3 internos + 2 externos
        // Evita estourar o context window do Groq (8000 TPM on_demand)
        // e outros modelos com janela menor
        $max_internal = 3;
        $max_external = 2;

        if (!empty($internal_links)) {
            $block .= "\n\nðŸ”— LINKS INTERNOS (inserir 1-3 naturalmente no texto, anchor descritivo, nunca 'clique aqui', máx 1 por parágrafo):\n";
            foreach (array_slice($internal_links, 0, $max_internal) as $link) {
                $anchor = mb_substr(trim($link['anchor'] ?? $link['title'] ?? ''), 0, 60);
                $url    = $link['url'] ?? '';
                if ($anchor && $url) {
                    $block .= "- {$anchor}: {$url}\n";
                }
            }
        }

        if (!empty($external_links)) {
            $block .= "\nðŸŒ LINKS EXTERNOS (inserir 1-2, rel=\"noopener noreferrer\" target=\"_blank\"):\n";
            foreach (array_slice($external_links, 0, $max_external) as $link) {
                $anchor = mb_substr(trim($link['anchor'] ?? ''), 0, 60);
                $url    = $link['url'] ?? '';
                if ($anchor && $url) {
                    $block .= "- {$anchor}: {$url}\n";
                }
            }
        }

        return $block;
    }

    /**
     * Retorna links de autoridade para o nicho detectado pela keyword.
     * Reutilizado por ArticlePipeline, YouTubeToArticleService e qualquer gerador.
     */
    /**
     * Retorna fontes oficiais APENAS de entidades realmente mencionadas no
     * conteúdo do artigo. Não usa mais um mapa genérico de nicho que colava
     * fontes sem relação (ex: OpenAI/Google AI em artigo que não fala disso).
     *
     * Regra: uma fonte só entra se a marca/entidade dela aparecer DE FATO no
     * texto (ou na keyword). Se nada bater, retorna vazio — melhor nenhuma
     * fonte do que uma fonte falsa.
     *
     * @param string $keyword Keyword do artigo
     * @param string $content (opcional) Conteúdo do artigo para casar entidades reais
     */
    public static function get_authority_links(string $keyword, string $content = ''): array {
        // Texto onde procurar entidades: keyword + conteúdo (sem HTML)
        $haystack = ' ' . mb_strtolower($keyword . ' ' . wp_strip_all_tags($content)) . ' ';

        // Cada entidade tem termos ESPECÍFICOS (nomes próprios de marcas/órgãos).
        // Evitamos palavras comuns do português ("ações", "switch", "pix") que
        // causavam falsos positivos (ex.: "automação de ações" casava CVM).
        $entities = [
            // IA / ferramentas específicas
            ['terms' => ['openai', 'chatgpt', 'gpt-4', 'gpt-5', 'dall-e', 'dall·e'], 'anchor' => 'OpenAI', 'url' => 'https://openai.com'],
            ['terms' => ['google gemini', 'gemini'], 'anchor' => 'Google Gemini', 'url' => 'https://gemini.google.com/'],
            ['terms' => ['google whisk', 'whisk'], 'anchor' => 'Google Labs', 'url' => 'https://labs.google/'],
            ['terms' => ['imagen', 'deepmind', 'google ai'], 'anchor' => 'Google DeepMind', 'url' => 'https://deepmind.google/'],
            ['terms' => ['claude', 'anthropic'], 'anchor' => 'Anthropic', 'url' => 'https://www.anthropic.com'],
            ['terms' => ['midjourney'], 'anchor' => 'Midjourney', 'url' => 'https://www.midjourney.com/'],
            ['terms' => ['perplexity'], 'anchor' => 'Perplexity AI', 'url' => 'https://www.perplexity.ai/'],
            ['terms' => ['github copilot', 'copilot'], 'anchor' => 'GitHub Copilot', 'url' => 'https://github.com/features/copilot'],
            ['terms' => ['canva'], 'anchor' => 'Canva', 'url' => 'https://www.canva.com/'],
            // Programação / dev
            ['terms' => ['github'], 'anchor' => 'GitHub', 'url' => 'https://github.com/'],
            ['terms' => ['stack overflow', 'stackoverflow'], 'anchor' => 'Stack Overflow', 'url' => 'https://stackoverflow.com/'],
            ['terms' => ['python'], 'anchor' => 'Python', 'url' => 'https://www.python.org/'],
            ['terms' => ['javascript', 'node.js', 'nodejs'], 'anchor' => 'MDN Web Docs', 'url' => 'https://developer.mozilla.org/'],
            // Marcas mobile
            ['terms' => ['xiaomi', 'redmi', 'poco', 'hyperos'], 'anchor' => 'Xiaomi', 'url' => 'https://www.mi.com/br/'],
            ['terms' => ['samsung', 'galaxy', 'one ui'], 'anchor' => 'Samsung Brasil', 'url' => 'https://www.samsung.com/br/'],
            ['terms' => ['iphone', 'ipados', 'macbook', 'ipad', 'apple'], 'anchor' => 'Apple Brasil', 'url' => 'https://www.apple.com/br/'],
            ['terms' => ['motorola', 'moto g', 'moto edge'], 'anchor' => 'Motorola Brasil', 'url' => 'https://www.motorola.com.br/'],
            ['terms' => ['android'], 'anchor' => 'Android', 'url' => 'https://www.android.com/intl/pt-BR_br/'],
            // Plataformas / serviços
            ['terms' => ['wordpress', 'elementor', 'woocommerce'], 'anchor' => 'WordPress.org', 'url' => 'https://br.wordpress.org/'],
            ['terms' => ['google search console', 'search console', 'google search central'], 'anchor' => 'Google Search Central', 'url' => 'https://developers.google.com/search'],
            ['terms' => ['google ads', 'google adsense', 'adsense'], 'anchor' => 'Google Ads', 'url' => 'https://ads.google.com/intl/pt-BR_br/home/'],
            ['terms' => ['meta ads', 'facebook ads', 'instagram ads', 'meta for business'], 'anchor' => 'Meta for Business', 'url' => 'https://www.facebook.com/business'],
            ['terms' => ['playstation'], 'anchor' => 'PlayStation', 'url' => 'https://www.playstation.com/pt-br/'],
            ['terms' => ['xbox'], 'anchor' => 'Xbox', 'url' => 'https://www.xbox.com/pt-BR'],
            ['terms' => ['nintendo'], 'anchor' => 'Nintendo', 'url' => 'https://www.nintendo.com/pt-br/'],
            ['terms' => ['steam'], 'anchor' => 'Steam', 'url' => 'https://store.steampowered.com/'],
            // Órgãos oficiais — termos inequívocos (nomes próprios completos)
            ['terms' => ['anvisa'], 'anchor' => 'Anvisa', 'url' => 'https://www.gov.br/anvisa/pt-br'],
            ['terms' => ['banco central', 'selic', 'tesouro direto'], 'anchor' => 'Banco Central do Brasil', 'url' => 'https://www.bcb.gov.br/'],
            ['terms' => ['cvm', 'comissão de valores', 'bolsa de valores'], 'anchor' => 'CVM', 'url' => 'https://www.gov.br/cvm/pt-br'],
            ['terms' => ['receita federal', 'imposto de renda', 'declaração do ir'], 'anchor' => 'Receita Federal', 'url' => 'https://www.gov.br/receitafederal/pt-br'],
            ['terms' => ['ministério da saúde', 'ministerio da saude'], 'anchor' => 'Ministério da Saúde', 'url' => 'https://www.gov.br/saude/pt-br'],
            ['terms' => ['anatel'], 'anchor' => 'Anatel', 'url' => 'https://www.gov.br/anatel/pt-br'],
        ];

        $matched = [];
        foreach ($entities as $ent) {
            foreach ($ent['terms'] as $term) {
                // Match por PALAVRA INTEIRA (com fronteiras), não substring.
                // Evita "ações" casar dentro de "automação", "ios" dentro de "vídeos", etc.
                $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
                if (preg_match($pattern, $haystack)) {
                    $matched[$ent['url']] = ['anchor' => $ent['anchor'], 'url' => $ent['url']];
                    break; // já casou esta entidade
                }
            }
        }

        // No máximo 3 fontes, todas REAIS e citadas. Se nada casou, vazio.
        return array_slice(array_values($matched), 0, 3);
    }

    /**
     * Retorna fontes de autoridade como texto para o briefing do html_prompt.
     */
    public static function get_authority_links_text(string $keyword, string $content = ''): string {
        $links = self::get_authority_links($keyword, $content);
        if (empty($links)) return '';
        $parts = array_map(fn($l) => $l['anchor'] . ' (' . $l['url'] . ')', $links);
        return 'Fontes oficiais relacionadas (use só se realmente relevante, NÃO invente dados): ' . implode(', ', $parts) . '.';
    }

    /**
     * Busca posts internos publicados da mesma categoria para passar como links internos.
     * Reutilizado por ArticlePipeline e YouTubeToArticleService.
     */
    public static function get_internal_links(string $keyword, int $exclude_post_id = 0, string $category = ''): array {
        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 8,
            'orderby'        => 'relevance',
            'fields'         => 'ids',
        ];

        if ($exclude_post_id) {
            $args['post__not_in'] = [$exclude_post_id];
        }

        // Buscar por categoria se disponível
        if ($category) {
            $cat_obj = get_category_by_slug(sanitize_title($category));
            if ($cat_obj && !is_wp_error($cat_obj)) {
                $args['cat'] = $cat_obj->term_id;
            }
        }

        // Busca por keyword como fallback
        if (empty($args['cat'])) {
            $args['s'] = sanitize_text_field($keyword);
            unset($args['orderby']);
        }

        $ids = get_posts($args);
        if (empty($ids) && !empty($category)) {
            // Tenta sem categoria se não achou
            unset($args['cat']);
            $args['s'] = sanitize_text_field($keyword);
            $ids = get_posts($args);
        }

        $links = [];
        foreach (array_slice((array)$ids, 0, 5) as $id) {
            $post = get_post($id);
            if (!$post) continue;
            $links[] = [
                'anchor' => get_the_title($id),
                'url'    => get_permalink($id),
                'title'  => get_the_title($id),
            ];
        }

        return $links;
    }

    /**
     * Detecta a intenção de busca baseada na keyword.
     */
    private function detect_intent(string $keyword): string {
        $kw = strtolower($keyword);

        $transactional = ['comprar','compra','preco','preço','custo','valor','barato','melhor preco','buy','price','cost','cheap'];
        $commercial    = ['melhor','melhores','comparar','vs','versus','review','avaliacao','avaliação','vale a pena','best','compare','top'];
        $howto         = ['como','como fazer','como usar','como criar','passo a passo','tutorial','guia','how to','how','guide'];
        $local         = ['perto','próximo','em sp','em rj','em','cidade','bairro','near','local'];

        foreach ($transactional as $t) {
            if (strpos($kw, $t) !== false) return 'Transacional — usuário quer comprar ou contratar';
        }
        foreach ($commercial as $c) {
            if (strpos($kw, $c) !== false) return 'Comercial — usuário está comparando opçÃµes antes de decidir';
        }
        foreach ($howto as $h) {
            if (strpos($kw, $h) !== false) return 'Informacional How-To — usuário quer aprender a fazer algo específico';
        }
        foreach ($local as $l) {
            if (strpos($kw, $l) !== false) return 'Navegacional/Local — usuário busca por localização específica';
        }

        return 'Informacional — usuário quer entender o tema em profundidade';
    }

    /**
     * Detecta o nicho do artigo para personalizar exemplos e referências.
     */
    private function detect_niche(string $keyword): string {
        $kw = strtolower($keyword);

        $niches = [
            'tecnologia'  => ['ia','inteligencia artificial','gpt','llm','software','app','programacao','dev','codigo','api','saas','cloud','blockchain','crypto','nft','machine learning','deep learning','neural','robot','automacao'],
            'saude'       => ['saude','saúde','dieta','emagrecimento','treino','exercicio','suplemento','vitamina','proteina','academia','fitness','mental','ansiedade','depressao','medico','medica','hospital','remedio'],
            'financas'    => ['investimento','açÃµes','bolsa','crypto','bitcoin','renda','dinheiro','finanças','banco','credito','debito','juros','economia','finance','money','invest','trading'],
            'receitas'    => ['receita','cozinha','culinaria','gastronomia','comida','prato','ingrediente','sobremesa','bolo','cookie','frango','carne','vegano','vegetariano','drink','cocktail','food','recipe'],
            'marketing'   => ['marketing','seo','trafego','conversao','leads','vendas','copywriting','email','social media','instagram','tiktok','youtube','conteudo','brand','branding','ads','facebook'],
            'educacao'    => ['aprender','estudar','curso','certificado','diploma','faculdade','escola','ensino','treinamento','capacitacao','learn','study','course','education','skill'],
            'negocios'    => ['negocios','empresa','startup','empreendedorismo','gestao','lideranca','rh','funcionario','cliente','produto','servico','business','entrepreneur','management','strategy'],
            'viagem'      => ['viagem','viaje','turismo','hotel','passagem','destino','mochilao','travel','trip','vacation','tourism','flight'],
            'moda'        => ['moda','roupa','estilo','look','tendencia','fashion','outfit','roupas','calcado','bolsa','acessorio'],
            'casa'        => ['decoracao','reforma','jardim','moveis','arquitetura','interiores','organizacao','limpeza','home','house','decor'],
        ];

        foreach ($niches as $niche => $terms) {
            foreach ($terms as $term) {
                if (strpos($kw, $term) !== false) {
                    return "NICHO DETECTADO: {$niche} — use exemplos, ferramentas e referências específicas deste nicho.";
                }
            }
        }

        return 'NICHO: Geral — use exemplos práticos e referências verificáveis relevantes ao tema.';
    }
}
