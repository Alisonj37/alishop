# Changelog — Content Audit & Cleanup (Universal)

## [2.1.0] — 2026-06-30

### Segurança
- **Validação de post ownership em bulk action**: cada `$post_id` recebido via POST agora é verificado com `get_post()` antes de qualquer operação, eliminando vetor de IDOR (ex: usuário enviando IDs de outros tipos de post).
- **`wp_safe_redirect()` em lugar de `wp_redirect()`**: em todos os handlers de `admin_post_*` para evitar open redirect.
- **`sanitize_key()` no `$bulk_action`**: substituído `sanitize_text_field` por `sanitize_key` e adicionada whitelist de ações permitidas antes do `switch`, impedindo ações arbitrárias.
- **Validação rigorosa do JSON importado**: `handle_import_config()` verifica campo `plugin` do JSON antes de atualizar qualquer opção.
- **`max(1, min(36, ...))` no `stale_months`**: evita valores absurdos (negativos ou > 36 meses).
- **`array_filter($post_ids)`** após `array_map('intval', ...)`: descarta zeros e negativos que poderiam causar queries indesejadas.

### Performance
- **`run_auto_audit()` agora usa batches de 200 posts** via `$wpdb->get_results()` com `LIMIT/OFFSET`, substituindo `get_posts(posts_per_page=-1)`. Elimina risco de timeout/OOM em sites com milhares de posts.
- **`run_stale_scan()` agora usa batches de 200 posts** via `$wpdb` com JOIN em `postmeta`, eliminando `get_posts(posts_per_page=-1)`.
- **`handle_check_sitemap()` processa posts em batches de 100** via `$wpdb` raw query com `LIMIT/OFFSET`, eliminando `get_posts(posts_per_page=-1, fields=all)` que carregava todo o `post_content` em memória de uma vez.
- **Paginação real na tela de auditoria**: substituído `posts_per_page=300` fixo por `CAC_PAGE_SIZE=50` com links de paginação via `paginate_links()`. Carrega apenas 50 posts por página.
- **`get_status_counts()` usa `DISTINCT` e JOIN com posts**: evita contar metas de posts na lixeira.
- **Pre-compilação do mapa keyword→status** em `run_auto_audit()`: construído uma vez antes do loop, eliminando loop duplo dentro de loop (O(n²) → O(n)).
- **`@set_time_limit(120)` no `handle_check_sitemap()`** e `300` no trigger manual de auditoria: garante que o PHP não encerre a varredura prematuramente em hospedagens compartilhadas.

### Novas Funcionalidades
- **Export/Import de configuração de nicho (JSON)**: botões na tela de Configurações para exportar e importar regras de um site para outro. O arquivo JSON inclui todas as keyword lists, `stale_months` e `sitemap_url`. Handler `handle_export_config()` e `handle_import_config()` com nonce próprio.
- **Verificação de Block Widgets (Gutenberg)**: `handle_check_sitemap()` agora varre a opção `widget_block` (widgets de bloco do editor Gutenberg), não só `widget_text`.
- **Verificação do Customizer**: varre todos os `theme_mods` do tema ativo em busca de strings de staging.
- **Verificação do robots.txt do Rank Math**: checa a opção `rank_math_robots_txt_content` (onde o Rank Math armazena o conteúdo do robots.txt).
- **Verificação do arquivo robots.txt físico**: se o arquivo `ABSPATH/robots.txt` existir e for legível, é varrido.
- **Verificação do sitemap.xml real**: faz fetch HTTP do sitemap configurado e compara cada `<loc>` com o `home_url()` de produção, detectando se o próprio sitemap está apontando para domínio errado.
- **Meta `_cac_geo_rewrite_status` (pending|done|failed)**: rastreia o status da reescrita pelo GEO Método SEO. Exibido na coluna "Sinalizações" da tela de auditoria e na coluna "Auditoria" da lista de posts com badges coloridos:
  - ⏳ amarelo = reescrita aguardando processamento
  - ✅ verde = reescrito com sucesso
  - ❌ vermelho = falha na reescrita
- **`sources_checked` no resultado do scan de sitemap**: informa quais fontes foram varridas no último escaneamento.
- **Template "Noindex" no preenchimento rápido**: os templates rápidos por tipo de projeto agora também preenchem a lista de palavras-chave de Noindex.

### Integração com GEO Método SEO
- **Bridge `CACIntegration.php`** adicionado ao GEO Método SEO (`includes/Bridge/CACIntegration.php`).
- **Fluxo assíncrono via WP Cron**: ao clicar "Enviar para reescrita", o CAC seta `_cac_geo_rewrite_status=pending` e o GEO agenda um `wp_schedule_single_event` com 30s de delay (para não bloquear o redirect). O cron chama `ContentUpdater::rewrite_post()`, que usa o provider AI configurado no GEO Método SEO.
- **Atualização de status em tempo real**: após a reescrita, o cron atualiza a meta para `done` ou `failed`, refletindo o resultado real na tela do CAC.
- **Verificação de licença**: a reescrita só é processada se o GEO Método SEO tiver licença ativa.
- **Log no sistema de logs do GEO**: todas as etapas (enfileiramento, início, sucesso, falha) são registradas via `LogService::record()` na categoria `bridge`.

### Correções Menores
- Filtros de contagem (`get_status_counts()`) agora excluem corretamente posts `auto-draft` e da lixeira.
- Nonce de auditoria via GET (`cac_run_audit`) verificado com `check_admin_referer()` antes de executar (sem mudança de comportamento, mas agora explicitamente documentado).
- CSS dos badges de status na coluna de auditoria da lista de posts: usa `style` inline em vez de depender de CSS carregado apenas na página principal do CAC.

## [2.0.0] — versão original (gerada por IA sem execução real)

- Classificação de posts por keyword (Manter, Fundir, Noindex, Remover, Revisar)
- Detecção de posts desatualizados com scan diário via cron
- Verificação básica de sitemap/staging (posts + widgets de texto)
- Tela de auditoria com filtros e bulk actions
- Integração básica com Rank Math e Yoast (apply_noindex)
- Configurações por site com templates rápidos por tipo de projeto
