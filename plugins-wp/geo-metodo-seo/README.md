# GEO Método SEO — Plataforma Editorial com IA para WordPress

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://aiconteudo.com.br)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://www.php.net)
[![License](https://img.shields.io/badge/license-Commercial-green.svg)](LICENSE.txt)

> Plataforma editorial profissional com IA para WordPress focada em **SEO**, **GEO** (Generative Engine Optimization), **AEO** (Answer Engine Optimization) e **LLM Optimization**.

---

## 📑 Sumário

- [Sobre](#-sobre)
- [Funcionalidades principais](#-funcionalidades-principais)
- [Requisitos](#-requisitos)
- [Instalação](#-instalação)
- [Configuração rápida (5 minutos)](#-configuração-rápida-5-minutos)
- [Provedores de IA suportados](#-provedores-de-ia-suportados)
- [Custos estimados](#-custos-estimados)
- [Estrutura do plugin](#-estrutura-do-plugin)
- [Compatibilidade](#-compatibilidade)
- [Suporte](#-suporte)
- [Licença](#-licença)

---

## 🎯 Sobre

O **GEO Método SEO** é um plugin WordPress que automatiza a geração de conteúdo otimizado para SEO + GEO + AEO + LLMs, com cadeia profissional de fallback para texto e imagem.

**O plugin entrega:**
- 🤖 Sistema autônomo (SARA Autopilot) que planeja, escreve e publica artigos sozinho
- 📝 9 fluxos de geração (Individual, Massa, Cluster, YouTube, Glossário, Web Stories, etc)
- 🎨 Cadeia de **8 providers de imagem** com fallback automático
- 🧠 Cadeia de **6 providers de texto** com modo economia
- 🎓 E-E-A-T completo (Experience, Expertise, Authoritativeness, Trustworthiness)
- 📊 Schema.org JSON-LD (Article, FAQ, Speakable, Person)
- 🛡️ Validação contra Google March 2026 Core Update

---

## ✨ Funcionalidades principais

### 🤖 SARA Autopilot
Sistema autônomo de geração contínua:
- **Brain**: planeja categoria + keyword + briefing automaticamente
- **Writer**: gera artigos no horário agendado
- **Quality Gate**: valida antes de publicar (ThinContent + Editorial Guard)
- **Health Monitor**: monitora saúde do sistema
- **Retry Manager**: 1-3 retentativas automáticas com backoff
- **Content Refresher**: atualiza posts com baixa performance no GSC

### 📝 Geração de conteúdo
- **Artigo Individual**: 1 artigo controlado via admin
- **Em Massa**: até 50 artigos em lote
- **Cluster Topical**: pillar + satellites com interlinking
- **YouTube → Artigo**: converte vídeo em post SEO
- **Glossário SEO A-Z**: gera termos do nicho em massa
- **Web Stories AMP**: stories verticais (5-7 slides)
- **Reescrever Artigo**: atualiza posts antigos com IA
- **Auditoria de Conteúdo**: score SEO + GEO + E-E-A-T

### 🎨 Geração de imagens (cadeia profissional)

**Featured Image (1 por artigo):**
```
Fal.ai (Flux-2-Pro) → Replicate (Flux Schnell) → Naga.ac → Stock Photos → Pollinations
```

**Body Images (2-5 configuráveis):**
```
Replicate (Flux Schnell) → Fal.ai → Naga.ac → Stock Photos → Pollinations
```

**Web Stories (5-7 slides 9:16):**
```
Naga.ac (DALL-E 3 free) → HuggingFace (Flux Schnell) → Replicate → Fal.ai → Pollinations
```

**Pipeline obrigatório de qualidade:**
```
Título + Keyword + Categoria
       ↓
O provedor configurado gera prompt visual quando habilitado
       ↓
API de imagem do provedor primário
       ↓
Sideload automático para wp-content/uploads/
       ↓
Aplicado como featured ou inserido após H2
```

### 📊 SEO técnico profissional
- **Schema.org JSON-LD completo**: Article, FAQ, Speakable, Person, BreadcrumbList
- **GEO Score**: análise de Generative Engine Optimization
- **llms.txt**: arquivo automático que orienta crawlers de IA (ChatGPT, Perplexity, Claude)
- **Rank Math integration**: focus keyword + meta description automática
- **Yoast SEO compatibility**
- **IndexNow**: submit automático ao Bing/Yandex
- **Internal linking** inteligente (até 4 por artigo)
- **External linking** com 2 links de autoridade

### 🎓 E-E-A-T
- Perfis de autor com schema Person
- Caixa de autor reforçada no rodapé
- Página editorial automática
- Disclosure transparency (avisos de IA)

### 🛡️ Quality & Compliance
- **March 2026 Google Core Update Guard**
- **ThinContentValidator**: bloqueia conteúdo raso (<1200 palavras)
- **Anti-AI-pattern detection**: detecta "é crucial", "por fim", "vale ressaltar"
- **OriginalDataInjector**: injeta dados que Google premia
- **Word count enforcement**: força 3000+ palavras quando configurado

### 📱 Recursos especiais
- **Web Stories AMP**: validador nativo embutido
- **FAQ Generator**: 8 perguntas com Schema FAQ
- **Speakable Schema**: marca trechos pra Google Assistant
- **TTS (Text-to-Speech)**: gera áudio do artigo (OpenAI Audio)
- **Tabela HTML automática**: dados estruturados Google-friendly
- **Resposta rápida**: featured snippet otimizado
- **Multisite support**

### 🔍 Observabilidade
- **ObservabilityService**: dashboard somente leitura de:
  - Status de cada provider (configurado/sem chave)
  - Modelo resolvido por contexto
  - Saúde operacional do sistema
  - Cost Guard state (modo economia)
  - Histórico de conteúdo recente
- **AjaxSecurityAuditService**: auditoria interna das próprias rotas AJAX
- **OperationalDashboardService**: métricas operacionais
- **Logs detalhados** por módulo (info, warning, error)

---

## 📋 Requisitos

### Mínimo
- **WordPress**: 6.0 ou superior
- **PHP**: 8.0 ou superior (testado em 8.0, 8.1, 8.2, 8.3)
- **MySQL**: 5.7+ ou MariaDB 10.3+
- **Memória PHP**: 256 MB (recomendado 512 MB)
- **max_execution_time**: 60s (recomendado 180s)

### Recomendado
- **Hospedagem**: VPS dedicado ou cloud (Hostinger Business, Locaweb Cloud, AWS, DigitalOcean)
- **HTTPS**: certificado SSL ativo
- **Cache**: compatível com WP Rocket, W3 Total Cache, LiteSpeed Cache
- **CDN**: Cloudflare, BunnyCDN ou similar (para imagens)
- **Backup automático**: UpdraftPlus ou Duplicator

### NÃO funciona em
- ❌ WordPress.com gratuito (precisa hosted)
- ❌ Hospedagens com `mod_security` muito agressivo
- ❌ PHP 7.x (descontinuado)

---

## 🚀 Instalação

### Via WordPress Admin (recomendado)

1. **Compre o plugin** em [aiconteudo.com.br](https://aiconteudo.com.br)
2. Receba o **ZIP** por e-mail + sua **chave de licença**
3. WordPress → **Plugins → Adicionar Novo → Enviar Plugin**
4. Faça upload do arquivo `geo-metodo-seo-v1_0_0-COMMERCIAL-FIRST-RELEASE.zip`
5. Clique em **Ativar**
6. Vá em **GEO Método SEO → Licença** e cole sua chave
7. Siga o **Wizard de Configuração Inicial** que vai abrir automaticamente

### Via FTP (alternativa)

```bash
1. Faça upload da pasta geo-metodo-seo/ para /wp-content/plugins/
2. WordPress → Plugins → ativa "GEO Método SEO"
3. Cole sua chave de licença
4. Siga o wizard
```

---

## ⚡ Configuração rápida (5 minutos)

Para começar a gerar artigos **rapidamente** com qualidade profissional, configure só **2 API keys essenciais**:

### Passo 1 — OpenAI (texto)
1. Crie conta em [platform.openai.com](https://platform.openai.com)
2. Vá em **API Keys** → **Create new secret key**
3. Copie a chave (começa com `sk-`)
4. Cole em **GEO Método SEO → Configurações → API Keys → OpenAI**

**Custo estimado:** $5-10/mês para 300 artigos/mês

### Passo 2 — Fal.ai (imagens)
1. Crie conta em [fal.ai](https://fal.ai)
2. Vá em **Dashboard → API Keys → Create new key**
3. Copie a chave
4. Cole em **GEO Método SEO → Configurações → API Keys → Fal.ai**

**Custo estimado:** $15-20/mês para 300 artigos com imagens

### Passo 3 — Gerar primeiro artigo
1. Vá em **GEO Método SEO → Gerar Artigo Individual**
2. Digite a **keyword foco** (ex: "melhor smartphone 2026")
3. Escolha **categoria** e **nicho**
4. Clique em **Gerar**

Em ~60 segundos seu primeiro artigo profissional está publicado. 🎉

### Passo 4 (opcional) — Adicionar fallbacks

Para **garantia máxima**, configure também:
- **Replicate** (fallback de imagem) — [replicate.com](https://replicate.com)
- **Groq** (texto economy mode) — [console.groq.com](https://console.groq.com)
- **Anthropic Claude** (writer alternativo) — [console.anthropic.com](https://console.anthropic.com)

---

## 🔌 Provedores de IA suportados

### 📝 Texto (6 providers)

| Provider | Endpoint | Contextos | Custo médio |
|----------|----------|-----------|-------------|
| **OpenAI** | api.openai.com | Default, artigos, manual writer | $0.01/artigo |
| **Anthropic Claude** | api.anthropic.com | Artigos, edição | $0.015/artigo |
| **Groq** | api.groq.com | Economy, títulos, glossário, prompts visuais | Quase grátis |
| **Google Gemini** | generativelanguage.googleapis.com | Artigos, YouTube | $0.005/artigo |
| **Perplexity** | api.perplexity.ai | Pesquisa, contexto web | $0.02/research |
| **Naga.ac** | api.naga.ac | Economy mode, glossário | Grátis tier |

### 🖼️ Imagem (8 providers)

| Provider | Modelo | Papel | Custo |
|----------|--------|-------|-------|
| **Fal.ai** | Flux-2-Pro + Schnell | Featured premium | $0.04 / $0.005 |
| **Replicate** | Flux Schnell | Body images | $0.003/MP |
| **Naga.ac** | DALL-E 3 free | Web Stories + fallback | Grátis |
| **HuggingFace** | Flux Schnell | Fallback Web Stories | Grátis tier |
| **Unsplash** | Stock photos | Fallback antes Pollinations | Grátis com chave |
| **Pexels** | Stock photos | Fallback antes Pollinations | Grátis |
| **Pixabay** | Stock photos | Fallback antes Pollinations | Grátis |
| **Pollinations** | Flux público | Último recurso (sideload local) | Grátis |

---

## 💰 Custos estimados

### Cenário 1 — Hobby (10 artigos/mês)
- OpenAI: $0.10
- Fal.ai: $0.50
- **Total: ~$1/mês**

### Cenário 2 — Profissional (100 artigos/mês)
- OpenAI: $1
- Fal.ai: $5
- Replicate: $1 (fallback)
- **Total: ~$7/mês**

### Cenário 3 — Agência (1000 artigos/mês, multi-sites)
- OpenAI: $10
- Fal.ai: $50
- Replicate: $10
- **Total: ~$70/mês**

> 💡 **Dica:** ative o **Modo Economia** nas configurações para reduzir 50-70% dos custos usando Groq + Naga free tier sem perder muita qualidade.

---

## 📂 Estrutura do plugin

```
geo-metodo-seo/
├── geo-metodo-seo.php          # Arquivo principal
├── uninstall.php                # Limpeza ao desinstalar
├── README.md                    # Esta documentação
├── CHANGELOG.md                 # Histórico de versões
├── LICENSE.txt                  # Licença comercial
├── includes/
│   ├── AI/                      # Providers de IA (texto)
│   ├── Admin/                   # Telas administrativas (17 controllers)
│   ├── Autopilot/               # Sistema autônomo (SARA)
│   ├── Config/                  # Configurações
│   ├── Core/                    # Plugin core (Activator, Plugin)
│   ├── Database/                # Migrations
│   ├── EEAT/                    # E-E-A-T engine
│   ├── Glossary/                # Geração de glossário
│   ├── Helpers/                 # Utilitários
│   ├── License/                 # Sistema de licenças
│   ├── Quality/                 # Validadores (ThinContent, March Update)
│   ├── Repositories/            # Camada de dados
│   ├── SEO/                     # Cluster, linking, GEO score
│   ├── Schema/                  # JSON-LD generators
│   ├── Services/                # 28 services (imagem, texto, etc)
│   ├── TitleBank/               # Banco de títulos
│   └── Tools/                   # ImageReSideloader
└── sara-autopilot/
    ├── dashboard.php            # Dashboard SARA
    └── prompts/                 # Prompts mestres
        ├── brain/
        └── writer/
```

**Total:** 113 arquivos PHP, ~35.000 linhas de código.

**Tabelas de banco:** 9 customizadas
- `wp_geo_jobs`, `wp_geo_clusters`, `wp_geo_logs`, `wp_geo_bulk_sessions`, `wp_geo_templates`
- `wp_sara_semantic_index`, `wp_sara_editorial_calendar`, `wp_sara_autopilot_config`, `wp_sara_execution_log`

---

## ✅ Compatibilidade

### Temas testados
- ✅ Asap Theme (Asap, Asap Pro)
- ✅ GeneratePress
- ✅ Astra
- ✅ Kadence
- ✅ Hello Elementor
- ✅ Blocksy
- ✅ Twenty Twenty-Three / Twenty Twenty-Four

### Plugins compatíveis
- ✅ Rank Math SEO (integração nativa)
- ✅ Yoast SEO (compatível)
- ✅ WP Rocket
- ✅ W3 Total Cache
- ✅ LiteSpeed Cache
- ✅ Elementor / Elementor Pro
- ✅ WPML / Polylang (parcial)
- ✅ WooCommerce (não interfere)
- ✅ UpdraftPlus

### Plugins que podem conflitar
- ⚠️ Outros plugins de geração de conteúdo IA (desativar antes)
- ⚠️ Plugins de cache em modo agressivo podem cachear AJAX (excluir `/wp-admin/admin-ajax.php`)

---

## 🆘 Suporte

### Documentação
- **Site oficial**: [aiconteudo.com.br](https://aiconteudo.com.br)
- **Blog**: [aiconteudo.com.br/blog](https://aiconteudo.com.br/blog)
- **FAQ**: [aiconteudo.com.br/faq](https://aiconteudo.com.br/faq)

### Canais de suporte
- 📧 **E-mail**: suporte@aiconteudo.com.br
- 💬 **Telegram**: t.me/aiconteudo
- 📺 **YouTube**: youtube.com/@aiconteudo

### Resposta esperada
- **Plan Basic**: 48-72h
- **Plan Pro**: 24h
- **Plan Agency**: 4-8h (prioridade)

### Reportar bugs
Envie e-mail para suporte@aiconteudo.com.br com:
1. Versão do plugin
2. Versão do WordPress + PHP
3. Hospedagem
4. Descrição do problema
5. Print do erro (se houver)
6. Log de `wp-content/debug.log` (se habilitado)

---

## 📜 Licença

Software comercial proprietário. Veja [LICENSE.txt](LICENSE.txt) para os termos completos.

**Resumindo:**
- ✅ Use em quantos sites sua licença permite (Basic 1, Pro 3, Agency ilimitado)
- ✅ Modifique pra uso interno
- ❌ Não pode revender ou redistribuir
- ❌ Não pode disponibilizar download público

---

## 👤 Autor

**Alison Jean**
- Site: [aiconteudo.com.br](https://aiconteudo.com.br)
- Especialista em SEO técnico, GEO (Generative Engine Optimization) e automação de conteúdo com IA

---

## 🙏 Agradecimentos

Este plugin foi construído com base em meses de iteração e feedback real de uso em produção. Cada feature existe porque resolveu um problema real de geração de conteúdo profissional em escala.

**Stack open-source utilizada:**
- WordPress core APIs
- WP-Cron
- WordPress REST API

**Providers comerciais integrados:**
- OpenAI, Anthropic, Google, Groq, Perplexity
- Fal.ai, Replicate, Naga.ac, HuggingFace
- Unsplash, Pexels, Pixabay

---

**Versão atual: v1.0.0**
**Última atualização: Maio/2026**
