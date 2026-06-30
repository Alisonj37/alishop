# Auditoria Técnica — Prompt de Reescrita do GEO Método SEO

**Data:** 2026-06-30  
**Arquivo auditado:** `includes/Services/ContentUpdater.php` (linhas 119–127)  
**Versão do plugin:** 1.x  
**Branch:** `claude/audit-cleanup-plugin-review-vwgzfm`

---

## 1. Prompt Atual (literal)

Localizado em `ContentUpdater::rewrite_post()`, linhas 119–127:

```
Você é um especialista em SEO e redação de conteúdo.

Atualize e melhore o artigo abaixo para {$year}.
Mantenha o tema sobre "{$keyword}" mas adicione informações mais recentes,
melhore o SEO e torne o texto mais natural e envolvente.
Mantenha a mesma estrutura (H2s, FAQ, etc.) mas expanda as seções curtas.
Não troque, não invente e não remova as imagens originais; quando houver
necessidade, apenas preserve os espaços de mídia.
Retorne o artigo completo em HTML (use <p>, <h2>, <h3>, <ul>, <li>, <strong>).
NÃO inclua o título principal H1 — apenas o corpo do artigo.

ARTIGO ATUAL:
{$truncated_content}
```

**Variáveis injetadas atualmente:**
| Variável | Origem | Observação |
|---|---|---|
| `{$year}` | `date('Y')` | Apenas o ano, sem contexto de data mais rica |
| `{$keyword}` | `get_post_meta($post->ID, '_geo_keyword', true)` ou `$post->post_title` | Fallback amplo demais (título completo ≠ keyword) |
| `{$truncated_content}` | `mb_substr(wp_strip_all_tags($content), 0, 8000)` | Strip tags elimina a estrutura HTML que a IA precisaria ver |

**Contexto ausente:** nicho do site, categoria, tom de voz, palavra-chave principal separada do título, meta description esperada, formato de saída estruturado.

---

## 2. Avaliação por Critério

### 2.1 SEO

| Critério | Status | Detalhe |
|---|---|---|
| Keyword no primeiro H2 | ❌ | Não instrui posicionamento da KW principal |
| Keyword no primeiro parágrafo | ❌ | Sem instrução de "lead" com a keyword |
| Meta description solicitada | ❌ | Não existe — o campo nunca é gerado pelo prompt |
| Heading hierarchy (H2 → H3) | ⚠️ | Mencionado superficialmente ("H2s, FAQ etc.") |
| Links internos | ❌ | Não solicita nenhuma sugestão |
| Tamanho mínimo de conteúdo | ❌ | Sem target de palavras/palavras por seção |
| Sem H1 no corpo | ✅ | Instrução explícita e correta |
| Variações semânticas / LSI | ❌ | Não mencionado |
| Instrução de não repetir KW exata | ❌ | Sem frequência/densidade |

### 2.2 GEO (Generative Engine Optimization)

| Critério | Status | Detalhe |
|---|---|---|
| Resposta direta no 1º parágrafo (featured snippet) | ❌ | Sem instrução de "answer first" |
| Frases curtas no início de cada seção | ❌ | Não mencionado |
| Definições claras de termos | ❌ | Não mencionado |
| Conteúdo "AI Overview friendly" (Google SGE) | ❌ | Sem instrução de estrutura concisa |
| Listas e bullets para sequências | ❌ | Não instrui formatos específicos |
| Contexto de nicho para delimitar escopo | ❌ | Ausente — IA pode se desviar do nicho |

### 2.3 AEO (Answer Engine Optimization)

| Critério | Status | Detalhe |
|---|---|---|
| FAQ com perguntas reais de usuário | ❌ | Mencionado "FAQ" mas sem instrução de formato |
| Perguntas formuladas como busca real | ❌ | Sem instrução do estilo "Como...", "O que é..." |
| Respostas do FAQ com 50-120 palavras | ❌ | Sem instrução de tamanho ideal |
| Definição no primeiro parágrafo | ❌ | Sem instrução de definição direta |
| Números e dados concretos | ❌ | Não solicitado |
| Estrutura Q&A separada para schema | ❌ | FAQ não é retornado como array estruturado |

### 2.4 E-E-A-T (Experience, Expertise, Authoritativeness, Trustworthiness)

| Critério | Status | Detalhe |
|---|---|---|
| **Experience**: exemplos práticos / casos reais | ❌ | Não solicitado |
| **Expertise**: linguagem técnica precisa do nicho | ❌ | Sem contexto de nicho; sem instrução de profundidade técnica |
| **Authoritativeness**: citar conceitos estabelecidos | ❌ | Não mencionado |
| **Trustworthiness**: guard anti-alucinação | ⚠️ | Existe "não invente" apenas para imagens — não para fatos, dados, datas, versões |
| Guard contra estatísticas inventadas | ❌ | Ausente — IA pode citar "80% dos usuários" sem fonte |
| Guard contra nomes de empresas inventados | ❌ | Ausente |
| Guard contra versões de software falsas | ❌ | Ausente |

### 2.5 Técnico / Implementação

| Critério | Status | Detalhe |
|---|---|---|
| Saída estruturada (JSON) | ❌ | Retorna apenas HTML livre — sem title, meta_description, faq_items separados |
| `title` como campo separado | ❌ | Não solicitado |
| `meta_description` como campo separado | ❌ | Não solicitado |
| `faq_items` como array | ❌ | Não solicitado |
| `schema_type` inferido | ❌ | Não solicitado |
| `internal_links_sugeridos` | ❌ | Não solicitado |
| Versionamento do prompt | ❌ | Sem campo de versão — impossível rastrear qual prompt gerou qual reescrita |
| Log de reescrita (post_id, data, validação) | ❌ | Apenas `_geo_rewritten_at` e `_geo_rewrite_count` — sem prompt_version nem validation_passed |
| `response_format: json_object` na Groq | ✅ | **Não usado** — GroqProvider.php não tem esse parâmetro (correto) |
| Truncagem de conteúdo | ⚠️ | `mb_substr(wp_strip_all_tags($content), 0, 8000)` remove toda a estrutura HTML antes de truncar |
| Prompt com variáveis modulares | ❌ | Hardcoded — sem nicho, categoria, tom de voz |

### Pontuação Geral

| Dimensão | Score |
|---|---|
| SEO | 1 / 9 |
| GEO | 0 / 6 |
| AEO | 0 / 6 |
| E-E-A-T | 0.5 / 5 |
| Técnico | 1 / 11 |
| **Total** | **2.5 / 37 (6.7%)** |

---

## 3. Problemas Críticos Identificados

### 3.1 Ausência de saída estruturada

O prompt pede HTML livre. Isso significa que após a reescrita:
- `title` não é atualizado (o `wp_update_post` no `GeoMetodoSEO_Publisher` não recebe título novo)
- `meta_description` nunca é preenchida automaticamente
- FAQ fica embutido no HTML mas não alimenta o `FAQPage` schema separadamente
- Nenhum campo de SEO técnico é populado

### 3.2 Guard anti-alucinação insuficiente

O único guard existente é: `"Não troque, não invente e não remova as imagens originais"` — isso só protege imagens. A IA pode livremente inventar:
- "Segundo estudo de 2024 da Universidade X..."
- "A versão 4.2 do plugin lançada em março..."
- "80% das empresas brasileiras adotaram..."

### 3.3 `wp_strip_all_tags` antes da truncagem destrói a estrutura

```php
$truncated_content = mb_substr(wp_strip_all_tags($content), 0, 8000);
```

A IA recebe texto puro sem a estrutura de headings do artigo original. Ela não sabe quais H2 existiam, onde estavam os blocos, quais eram as seções. O resultado é uma reescrita que frequentemente altera a estrutura em vez de expandir as seções existentes.

### 3.4 Keyword fallback amplo demais

```php
$keyword = $keyword ?: $post->post_title;
```

Quando não há `_geo_keyword`, a keyword vira o título completo: `"Como fazer SEO em 2026: guia definitivo"`. A IA recebe um "tema" de 8 palavras em vez de uma keyword de 2-3 palavras.

### 3.5 Ausência de versionamento do prompt

Não há como saber qual versão do prompt gerou qual reescrita. Isso impede A/B testing, rollback e análise de qualidade.

---

## 4. Prompt Melhorado Proposto (v2.0)

### 4.1 Versão modular com todas as variáveis

```
VERSÃO DO PROMPT: geo_rewrite_v2.0

Você é um especialista sênior em SEO, GEO (Generative Engine Optimization) e
E-E-A-T (Experience, Expertise, Authoritativeness, Trustworthiness) com
experiência comprovada no nicho de {nicho_do_site}.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CONTEXTO DO ARTIGO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- Nicho do site: {nicho_do_site}
- Categoria do post: {categoria}
- Título original: {titulo_original}
- Palavra-chave principal: {palavra_chave_principal}
- Tom de voz da marca: {tom_de_voz_da_marca}
- Ano atual: {ano_atual}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ARTIGO ORIGINAL (estrutura HTML preservada):
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{conteudo_original}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
TAREFA
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Reescreva e melhore o artigo acima. Siga TODAS as regras abaixo.

━━ REGRAS SEO ━━
1. A palavra-chave "{palavra_chave_principal}" deve aparecer no primeiro
   parágrafo e no primeiro H2 do artigo.
2. Use variações semânticas e LSI keywords ao longo do texto — evite repetir
   a keyword exata mais de 1 vez a cada 200 palavras.
3. Cada seção H2 deve ter pelo menos 150 palavras de conteúdo útil.
4. Mantenha hierarquia clara: H2 → H3 → parágrafos. Não pule níveis.
5. NÃO inclua o título H1 — apenas o corpo do artigo (H2 em diante).
6. Sugira 3 a 5 textos âncora de links internos sobre assuntos relacionados
   ao nicho "{nicho_do_site}" (apenas o texto âncora + assunto, sem URLs).

━━ REGRAS GEO (Generative Engine Optimization) ━━
1. O PRIMEIRO PARÁGRAFO deve responder diretamente à pergunta implícita do
   título em 2-3 frases curtas — ideal para featured snippet e AI Overview.
2. Comece cada seção H2 com 1-2 frases objetivas que resumam o ponto central.
3. Apresente definições claras na primeira aparição de cada termo técnico.
4. Prefira listas numeradas para sequências e bullets para comparações.

━━ REGRAS AEO (Answer Engine Optimization) ━━
1. Crie uma seção FAQ com 4 a 6 perguntas que usuários realmente pesquisam
   sobre "{palavra_chave_principal}".
2. Formule as perguntas como busca real: "Como...", "O que é...",
   "Qual a diferença entre...", "Vale a pena...".
3. Cada resposta do FAQ deve ter entre 60 e 120 palavras — ideal para
   snippet direto nos resultados.
4. Inclua pelo menos 1 dado numérico concreto ou estatística contextual
   em cada resposta do FAQ (use linguagem cautelosa se não tiver dado real).

━━ REGRAS E-E-A-T ━━
1. EXPERIENCE: inclua pelo menos 1 exemplo prático, cenário ou caso de uso
   (pode ser genérico e hipotético — mas apresentado de forma tangível).
2. EXPERTISE: use terminologia técnica adequada ao nicho "{nicho_do_site}".
   Não simplifique demais; o leitor tem conhecimento intermediário.
3. AUTHORITATIVENESS: faça referência a conceitos estabelecidos no nicho,
   sem inventar pesquisas, fontes, nomes de especialistas ou estudos.
4. TRUSTWORTHINESS — REGRAS ANTI-ALUCINAÇÃO (CRÍTICO):
   - NUNCA invente: estatísticas, percentuais, datas exatas, nomes de
     empresas reais, versões de software, resultados garantidos, pesquisas
     ou estudos com números específicos.
   - Se não tiver dado real verificável, use: "estudos indicam",
     "especialistas apontam", "estimativas do setor sugerem" — sem número.
   - Não garanta resultados ("você VAI conseguir", "CERTAMENTE irá").
   - Não cite leis, regulamentos ou decisões judiciais sem certeza absoluta.

━━ QUALIDADE E TOM ━━
- Mínimo de 900 palavras no campo content_html.
- Tom de voz: {tom_de_voz_da_marca}.
- Idioma: português brasileiro — sem arcaísmos nem gírias.
- PROIBIDO usar clichês: "é fundamental", "é essencial", "à frente da curva",
  "no cenário atual", "vale ressaltar", "não é à toa", "é importante destacar".
- NÃO termine com call-to-action genérico ("gostou? compartilhe!").
- NÃO repita frases inteiras do artigo original.
- Preserve tags de mídia do original: não remova <img>, <figure> nem
  blocos <!-- wp:image --> — apenas mantenha-os no lugar correto.

━━ TIPO DE SCHEMA RECOMENDADO ━━
Escolha o mais adequado com base no conteúdo:
- Artigo "Como fazer" / tutorial passo a passo → "HowTo"
- Artigo informativo geral → "Article"
- Artigo com FAQ → "FAQPage" (pode combinar: "Article+FAQPage")
- Review ou comparativo → "Review"
- Definição / glossário → "DefinedTerm"

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
FORMATO DE SAÍDA — OBRIGATÓRIO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Retorne SOMENTE o objeto JSON abaixo. Sem texto antes ou depois.
Sem markdown. Sem backticks. Sem explicação. Apenas o JSON.

{
  "title": "Título otimizado para SEO entre 55 e 65 caracteres, contendo a palavra-chave",
  "meta_description": "Meta description entre 140 e 160 caracteres com a palavra-chave e um convite à leitura",
  "content_html": "<p>Conteúdo completo em HTML. Mínimo 900 palavras. Sem H1.</p>",
  "faq_items": [
    {
      "question": "Pergunta real que usuários pesquisam?",
      "answer": "Resposta direta entre 60 e 120 palavras, sem clichês."
    }
  ],
  "schema_type": "Article",
  "internal_links_sugeridos": [
    {
      "anchor": "texto âncora sugerido",
      "assunto": "sobre o que seria o artigo vinculado"
    }
  ]
}
```

### 4.2 Variáveis e suas origens (para implementação)

| Variável no prompt | Origem no PHP | Fallback |
|---|---|---|
| `{nicho_do_site}` | `get_option('sara_niche', '')` | `'Geral'` |
| `{titulo_original}` | `$post->post_title` | — |
| `{conteudo_original}` | `$post->post_content` (com HTML) | máx. 12.000 chars |
| `{categoria}` | `get_the_category($post->ID)[0]->name` | `'Sem categoria'` |
| `{palavra_chave_principal}` | `get_post_meta($post->ID, '_geo_keyword', true)` → Rank Math → Yoast → 2-3 palavras do título | — |
| `{tom_de_voz_da_marca}` | `get_option('geo_brand_voice', 'profissional e direto')` | `'profissional e direto'` |
| `{ano_atual}` | `date('Y')` | — |

### 4.3 Parsing do JSON (sem `response_format: json_object`)

```php
// Limpar code fences que a IA pode adicionar
$raw = trim($response->getContent());
$raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
$raw = preg_replace('/\s*```$/i', '', $raw);
$raw = trim($raw);

// Extrair JSON do primeiro { até o último }
if (preg_match('/(\{[\s\S]+\})/u', $raw, $m)) {
    $raw = $m[1];
}

$parsed = json_decode($raw, true);
$valid  = is_array($parsed)
    && !empty($parsed['content_html'])
    && !empty($parsed['title'])
    && strlen($parsed['content_html']) >= 500;
```

### 4.4 Validação do JSON retornado

```php
function cac_validate_rewrite_output(array $data): array {
    $errors = [];

    if (empty($data['title']) || mb_strlen($data['title']) < 20) {
        $errors[] = 'title ausente ou muito curto';
    }
    if (empty($data['meta_description']) || mb_strlen($data['meta_description']) < 80) {
        $errors[] = 'meta_description ausente ou muito curta';
    }
    if (empty($data['content_html']) || mb_strlen($data['content_html']) < 500) {
        $errors[] = 'content_html ausente ou muito curto';
    }
    if (!is_array($data['faq_items']) || count($data['faq_items']) < 2) {
        $errors[] = 'faq_items ausente ou com menos de 2 itens';
    }
    if (empty($data['schema_type'])) {
        $errors[] = 'schema_type ausente';
    }

    return $errors;
}
```

---

## 5. Rewrite Log — Estrutura Proposta

Novo post meta: `_geo_rewrite_log` (array JSON serializado):

```json
[
  {
    "date": "2026-06-30 14:22:11",
    "prompt_version": "geo_rewrite_v2.0",
    "provider": "groq",
    "model": "llama-3.3-70b-versatile",
    "validation_passed": true,
    "validation_errors": [],
    "char_count_before": 4821,
    "char_count_after": 6203,
    "title_changed": true,
    "meta_desc_changed": true
  }
]
```

O log é appendado (não sobrescrito), mantendo histórico completo de reescritas.

---

## 6. Impacto Esperado da Mudança

| Métrica | Antes (v1) | Depois (v2) |
|---|---|---|
| Campos atualizados após reescrita | 1 (post_content) | 5 (title, meta_desc, content, faq schema, rank math) |
| Proteção anti-alucinação | Apenas imagens | Estatísticas, datas, versões, nomes |
| Rastreabilidade | `_geo_rewritten_at` + count | Log completo com prompt_version e validation |
| Compatibilidade com Groq | ✅ (não usa json_object) | ✅ (mantido — parsing manual) |
| Cobertura AEO/GEO | 0% | FAQ estruturado + answer-first |
| E-E-A-T | Superficial | Instruções explícitas por pilar |

---

## 7. Plano de Implementação (após validação do prompt)

1. **`ContentUpdater::rewrite_post()`** — substituir o prompt hardcoded pelo modular v2.0
2. **`ContentUpdater::build_prompt()`** — novo método privado que injeta variáveis
3. **`ContentUpdater::parse_rewrite_response()`** — parsing JSON sem `response_format`
4. **`ContentUpdater::validate_response()`** — validação dos campos obrigatórios
5. **`ContentUpdater::apply_rewrite()`** — aplicar `title`, `meta_description`, `content_html`, FAQs, schema
6. **`ContentUpdater::append_rewrite_log()`** — persistir log em `_geo_rewrite_log`
7. Atualização do Rank Math com novo title + meta via `RankMathIntegration::apply()`
8. Regeneração de schemas via `EEATEngine::apply()` com FAQ estruturado

---

*Este documento aguarda validação do prompt v2.0 (Seção 4.1) antes da implementação.*
