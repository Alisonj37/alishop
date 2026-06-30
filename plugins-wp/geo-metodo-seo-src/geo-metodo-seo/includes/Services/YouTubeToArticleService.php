<?php

namespace GeoMetodoSEO\Services;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Config\ConfigManager;
use GeoMetodoSEO\AI\ProviderResolver;
use GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher;

/**
 * YouTubeToArticleService — Converte vídeos do YouTube em artigos otimizados.
 *
 * Fluxo:
 *   1. Extrai metadados e transcrição/legendas do vídeo via YouTube Data API v3
 *   2. Se não houver legenda oficial, usa Whisper (OpenAI) via download do áudio
 *   3. Envia transcrição + metadados para a IA reescrever como artigo SEO completo
 *   4. Retorna array compatível com ArticlePipeline::process() para salvar no WP
 *
 * @since 1.0.0
 */
class YouTubeToArticleService {

    private $yt_api_key;
    private $openai_key;

    public function __construct() {
        $this->yt_api_key = get_option('geo_youtube_api_key', '');
        $this->openai_key = ConfigManager::get('openai_api_key', '');
    }

    public function isConfigured(): bool {
        // YouTube API é obrigatória; OpenAI só é necessária para fallback de transcrição via Whisper.
        // A escrita do artigo pode usar qualquer provider de texto configurado via ProviderResolver.
        return !empty($this->yt_api_key) && ProviderResolver::isConfigured(ProviderResolver::for('youtube_article'));
    }

    /**
     * Extrai ID do vídeo de qualquer URL do YouTube.
     */
    public function extractVideoId( string $url ): string {
        $patterns = [
            '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
            '/youtu\.be\/([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $url, $m)) return $m[1];
        }
        // Se já for um ID puro
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) return $url;
        return '';
    }

    /**
     * Busca metadados do vídeo via YouTube Data API v3.
     */
    public function getVideoMetadata( string $video_id ): array {
        // Tentar YouTube Data API v3 primeiro (se API Key configurada)
        if (!empty($this->yt_api_key)) {
            $response = wp_remote_get(
                'https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
                    'part'  => 'snippet,contentDetails,statistics',
                    'id'    => $video_id,
                    'key'   => $this->yt_api_key,
                ]),
                ['timeout' => 15]
            );
            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                $item = $data['items'][0] ?? null;
                if ($item) {
                    return [
                        'title'        => $item['snippet']['title'] ?? '',
                        'description'  => substr($item['snippet']['description'] ?? '', 0, 1000),
                        'channel'      => $item['snippet']['channelTitle'] ?? '',
                        'published_at' => $item['snippet']['publishedAt'] ?? '',
                        'duration'     => $item['contentDetails']['duration'] ?? '',
                        'views'        => $item['statistics']['viewCount'] ?? 0,
                        'tags'         => $item['snippet']['tags'] ?? [],
                        'thumbnail'    => $item['snippet']['thumbnails']['maxres']['url']
                                       ?? $item['snippet']['thumbnails']['high']['url'] ?? '',
                        'video_id'     => $video_id,
                        'video_url'    => 'https://www.youtube.com/watch?v=' . $video_id,
                    ];
                }
            }
        }

        // Fallback: oEmbed público (funciona SEM API Key — como funcionava antes)
        $oembed = wp_remote_get(
            'https://www.youtube.com/oembed?url=' . urlencode('https://www.youtube.com/watch?v=' . $video_id) . '&format=json',
            ['timeout' => 10]
        );
        if (!is_wp_error($oembed) && wp_remote_retrieve_response_code($oembed) === 200) {
            $oe = json_decode(wp_remote_retrieve_body($oembed), true);
            if (!empty($oe['title'])) {
                return [
                    'title'        => $oe['title'] ?? '',
                    'description'  => '',
                    'channel'      => $oe['author_name'] ?? '',
                    'published_at' => '',
                    'duration'     => '',
                    'views'        => 0,
                    'tags'         => [],
                    'thumbnail'    => $oe['thumbnail_url'] ?? '',
                    'video_id'     => $video_id,
                    'video_url'    => 'https://www.youtube.com/watch?v=' . $video_id,
                    'keyword'      => $oe['title'] ?? '',
                ];
            }
        }

        // Último recurso: retornar dados mínimos para não bloquear a geração
        return [
            'title'        => 'Vídeo ' . $video_id,
            'description'  => '',
            'channel'      => '',
            'published_at' => '',
            'duration'     => '',
            'views'        => 0,
            'tags'         => [],
            'thumbnail'    => 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg',
            'video_id'     => $video_id,
            'video_url'    => 'https://www.youtube.com/watch?v=' . $video_id,
            'keyword'      => '',
        ];
    }

    /**
     * Busca legendas/transcrição via YouTube Captions API.
     * Retorna texto da legenda ou string vazia se não disponível.
     */
    public function getCaptions( string $video_id ): string {
        // YouTube não permite download direto de legendas via API pública sem OAuth.
        // Usamos o endpoint de timedtext que é público para vídeos com legendas automáticas.
        $url = 'https://www.youtube.com/api/timedtext?lang=pt&v=' . $video_id . '&fmt=json3';
        $response = wp_remote_get($url, ['timeout' => 10, 'user-agent' => 'Mozilla/5.0']);

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (!empty($data['events'])) {
                $text = '';
                foreach ($data['events'] as $event) {
                    foreach ($event['segs'] ?? [] as $seg) {
                        $text .= ($seg['utf8'] ?? '');
                    }
                }
                $text = trim(preg_replace('/\s+/', ' ', $text));
                if (strlen($text) > 200) return $text;
            }
        }

        // Tentar inglês como fallback
        $url_en = 'https://www.youtube.com/api/timedtext?lang=en&v=' . $video_id . '&fmt=json3';
        $response_en = wp_remote_get($url_en, ['timeout' => 10, 'user-agent' => 'Mozilla/5.0']);

        if (!is_wp_error($response_en)) {
            $body = wp_remote_retrieve_body($response_en);
            $data = json_decode($body, true);
            if (!empty($data['events'])) {
                $text = '';
                foreach ($data['events'] as $event) {
                    foreach ($event['segs'] ?? [] as $seg) {
                        $text .= ($seg['utf8'] ?? '');
                    }
                }
                $text = trim(preg_replace('/\s+/', ' ', $text));
                if (strlen($text) > 200) return $text;
            }
        }

        return '';
    }

    /**
     * Usa IA para transformar metadados + transcrição em artigo SEO completo.
     *
     * @return array{title: string, content: string, excerpt: string, keyword: string, seo_title: string, meta_desc: string}|false
     */
    public function generateArticle( string $video_id, string $language = 'pt-BR', string $provider = 'openai', string $model = '', int $word_target = 0 ) {
        @set_time_limit(180); // Evita corte por max_execution_time em servidores lentos
        $meta = $this->getVideoMetadata($video_id);
        if (empty($meta)) return false;

        $transcript = $this->getCaptions($video_id);

        // Montar contexto para a IA
        $context = "Título do vídeo: {$meta['title']}\n";
        $context .= "Canal: {$meta['channel']}\n";
        $context .= "Visualizações: " . number_format((int)$meta['views']) . "\n";

        if (!empty($meta['description'])) {
            $context .= "Descrição: {$meta['description']}\n";
        }

        if (!empty($meta['tags'])) {
            $context .= "Tags: " . implode(', ', array_slice($meta['tags'], 0, 15)) . "\n";
        }

        if (!empty($transcript)) {
            $transcript_excerpt = mb_substr($transcript, 0, 4000);
            $context .= "\nTranscrição do vídeo:\n{$transcript_excerpt}";
        }

        $lang_instruction = $language === 'pt-BR' ? 'em português brasileiro' : "em {$language}";

        $year = date('Y');
        $ai   = new \GeoMetodoSEO\AI\AIManager();
        $provider = ProviderResolver::for('youtube_article', $provider);
        $model    = $model ?: ProviderResolver::modelFor('youtube_article', $provider);

        // 1.0.0 — Modelos por tarefa + meta visual de palavras, sem defaults OpenAI hardcoded.
        $meta_model_saved = (string) $this->saraConfig('youtube_model_metadata', '');
        $article_model_saved = (string) $this->saraConfig('youtube_model_article', '');
        $briefing_model_saved = (string) $this->saraConfig('writer_model_briefing', '');
        $expansion_model_saved = (string) $this->saraConfig('writer_model_expansion', '');
        $meta_model      = $meta_model_saved ?: ProviderResolver::modelFor('youtube_article', $provider, $model);
        $article_model   = $article_model_saved ?: ProviderResolver::modelFor('youtube_article', $provider, $model);
        $briefing_model  = $briefing_model_saved ?: ProviderResolver::modelFor('sara_briefing', $provider, $model);
        $expansion_model = $expansion_model_saved ?: ProviderResolver::modelFor('sara_expansion', $provider, $article_model);
        $configured_target = $word_target > 0 ? $word_target : (int)$this->saraConfig('youtube_word_count_target', '2600');
        // FIX v1.0.0-WORDCOUNT-ALL: aceitar 800-6000 + folga apertada 15%.
        $target_words    = max(800, min(6000, $configured_target));
        $minimum_words   = max(500, (int)floor($target_words * 0.90));
        $maximum_words   = (int)ceil($target_words * 1.15);
        $auto_expand     = $this->saraConfig('auto_expand_enabled', '0') === '1';

        // v1.0.0: YouTube -> Artigo em Single Master Prompt por padrão.
        // Evita as chamadas separadas de metadados + briefing + artigo + expansão/condensação.
        if ((string) get_option('geo_youtube_single_master_prompt_enabled', '1') === '1') {
            return $this->generateArticleSingleMaster($video_id, $meta, $context, $language, $provider, $model, $target_words, $minimum_words, $maximum_words);
        }

        // ── CHAMADA 1: Metadados (JSON pequeno — sem HTML dentro) ──────────────
        $meta_prompt = "Com base neste vídeo do YouTube em {$lang_instruction}:\n\n{$context}\n\n"
            . "Retorne APENAS este JSON sem markdown (campos curtos, sem HTML):\n"
            . '{"title":"[Título SEO máx 60 chars com keyword e '.$year.']",'
            . '"keyword":"[keyword principal]",'
            . '"seo_title":"[meta title máx 60 chars]",'
            . '"meta_desc":"[meta descrição 150 chars com keyword e benefício]",'
            . '"excerpt":"[resumo 2-3 frases]",'
            . '"quick_answer":"[resposta direta em 1-2 frases, 40-75 palavras, objetiva, sem repetir o título]"}';

        $meta_response = $ai->generateText($meta_prompt, $provider, $meta_model ?: ($model ?: null));
        if (!$meta_response || $meta_response->hasError()) {
            LogService::log('error', 'YouTubeToArticle: falha metadados (' . $provider . '): ' . ($meta_response ? $meta_response->getError() : 'nulo'));
            return false;
        }
        $meta_raw = trim($meta_response->getContent());
        $meta_raw = preg_replace('/^```json\s*/i', '', $meta_raw);
        $meta_raw = preg_replace('/^```\s*/i', '', $meta_raw);
        $meta_raw = preg_replace('/```\s*$/i', '', trim($meta_raw));
        $data = json_decode(trim($meta_raw), true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data['title'])) {
            // Tentar extrair JSON do texto
            if (preg_match('/{[^{}]+}/s', $meta_raw, $m)) {
                $data = json_decode($m[0], true);
            }
            if (empty($data['title'])) {
                // Criar metadados básicos a partir do título do vídeo
                $data = [
                    'title'        => mb_substr($meta['title'] ?? 'Artigo sobre o vídeo', 0, 60),
                    'keyword'      => $meta['keyword'] ?? $meta['title'] ?? '',
                    'seo_title'    => mb_substr($meta['title'] ?? '', 0, 60),
                    'meta_desc'    => mb_substr($meta['description'] ?? '', 0, 155),
                    'excerpt'      => mb_substr($meta['description'] ?? '', 0, 200),
                    'quick_answer' => '',
                ];
            }
        }

        // ── CHAMADA 2: Briefing factual antes da escrita — anti-invenção ─────
        $briefing = $this->buildFactualBriefing($context, $data, $target_words, $provider, $briefing_model);

        // ── CHAMADA 3: Conteúdo HTML puro (sem JSON — evita parse errors) ─────
        $keyword_art = $data['keyword'] ?? $meta['title'] ?? 'video';
        $embed_html  = '<div class="geo-video-embed" style="margin:32px 0;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.15);">'
                     . '<iframe width="100%" height="450" src="https://www.youtube.com/embed/' . $video_id . '" frameborder="0" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" style="display:block;"></iframe>'
                     . '<p style="background:#f5f5f5;padding:12px 16px;margin:0;font-size:13px;color:#666;">📹 Assista ao vídeo original e continue lendo o artigo completo abaixo</p></div>';

        $content_prompt = "Você é editor-chefe especialista em SEO, GEO e E-E-A-T. Crie um artigo completo {$lang_instruction} sobre: \"{$keyword_art}\"\n\n"
            . "Contexto do vídeo:\n{$context}\n\n"
            . "BRIEFING FACTUAL OBRIGATÓRIO — NÃO SAIA DESSE CONTEXTO:\n{$briefing}\n\n"
            . "META VISUAL/REAL DE PALAVRAS: {$target_words} palavras. Mínimo aceitável após contador real: {$minimum_words}. Máximo recomendado: {$maximum_words}.\n\n"
            . "ESTRUTURA OBRIGATÓRIA DO ARTIGO:\n"
            . "[INTRODUÇÃO: 220-300 palavras com contexto, promessa editorial clara e keyword de forma natural]\n"
            . "{$embed_html}\n"
            . "[H2: O que é + definição técnica — 280 palavras]\n"
            . "[H2: Como funciona — mecanismo com H3s — MÁXIMO 300 palavras]\n"
            . "[TABELA OBRIGATÓRIA EM HTML REAL: se o briefing trouxer dados confirmados para comparar, faça tabela comparativa. Se NÃO houver ficha técnica/preço/testes confirmados, NÃO crie tabela cheia de 'Não informado'. Nesse caso, faça uma matriz útil de verificação com colunas: O que verificar, Status no contexto, Como confirmar, Impacto para o leitor. Use sempre <table><thead><tbody><tr><th><td>. Nunca use markdown ou texto corrido.]\n"
            . "[H2: Benefícios principais — lista com critérios verificáveis — MÁXIMO 250 palavras]\n"
            . "[H2: Guia prático — 5 passos com H3s obrigatórios — MÁXIMO 300 palavras]\n"
            . "[<blockquote style=\"border-left:4px solid #8B5CF6;padding:16px 20px;background:rgba(139,92,246,0.08);margin:28px 0;border-radius:0 8px 8px 0;\"><strong>💡 Dica de Especialista:</strong> [insight não óbvio]</blockquote>]\n"
            . "[H2: Erros comuns e como evitar — MÁXIMO 250 palavras com H3s]\n"
            . "[H2: Conclusão — OBRIGATÓRIO — síntese + recomendação + próxima ação — 150 palavras]\n"
            . "[H2: Conclusão — 220 palavras com síntese e CTA]\n"
            . "[NÃO criar FAQ aqui. O plugin gera FAQ oficial em camada separada para evitar duplicidade.]\n\n"
            . "REGRAS:\n"
            . "- Se o vídeo/título falar de modelo ainda não confirmado, rumor, lançamento futuro ou compra sem dados oficiais, mude o ângulo para 'pontos para verificar antes de comprar' e não prometa recomendação conclusiva.\n"
            . "- Não publique tabela comparativa com maioria das células 'Não informado'. Transforme isso em uma tabela de verificação/evidências útil para o leitor.\n"
            . "- Meta: {$target_words} palavras; mínimo aceitável: {$minimum_words}; máximo recomendado: {$maximum_words}. Não ultrapasse o máximo recomendado; corte redundância em vez de aumentar volume.\n"
            . "- NÃO use imagens placeholder — sem <img> no corpo\n"
            . "- Não invente dados, preços, estudos, datas, especificações ou fontes. Use apenas o contexto do vídeo/transcrição e conhecimento estável; quando não houver certeza, escreva de forma cautelosa.\n"
            . "- NÃO inclua bloco de Resposta Rápida no HTML; o plugin insere somente uma resposta rápida oficial no topo.\n"
            . "- Retorne APENAS o HTML do conteúdo (sem H1, sem JSON, sem markdown)";

        $content_response = $ai->generateText($content_prompt, $provider, $article_model ?: ($model ?: null));
        if (!$content_response || $content_response->hasError()) {
            LogService::log('error', 'YouTubeToArticle: falha conteúdo (' . $provider . '): ' . ($content_response ? $content_response->getError() : 'nulo'));
            return false;
        }
        $html_content = trim($content_response->getContent());
        $html_content = preg_replace('/^```html?\s*/i', '', $html_content);
        $html_content = preg_replace('/```\s*$/i', '', $html_content);
        $html_content = trim($html_content);
        $html_content = $this->removeQuickAnswerBlocks($html_content);

        $actual_words = $this->countWords($html_content);
        LogService::log('info', "YouTubeToArticle: contador real {$actual_words}/{$target_words} palavras antes da expansão");
        if ($auto_expand && $actual_words < $minimum_words) {
            $expanded = $this->expandYouTubeArticle($html_content, $briefing, $keyword_art, $target_words, $minimum_words, $actual_words, $provider, $expansion_model);
            if ($expanded) {
                $expanded_words = $this->countWords($expanded);
                if ($expanded_words > $actual_words) {
                    $html_content = $expanded;
                    $actual_words = $expanded_words;
                    LogService::log('success', "YouTubeToArticle: auto-expansão aplicada {$actual_words}/{$target_words} palavras");
                }
            }
        }

        if (empty($html_content) || strlen(strip_tags($html_content)) < 300) {
            LogService::log('error', 'YouTubeToArticle: conteúdo muito curto (' . strlen($html_content) . ' chars)');
            return false;
        }

        if ($actual_words > $maximum_words) {
            $condensed = $this->condenseYouTubeArticle($html_content, $briefing, $keyword_art, $target_words, $maximum_words, $actual_words, $provider, $expansion_model);
            if ($condensed) {
                $condensed_words = $this->countWords($condensed);
                if ($condensed_words < $actual_words && $condensed_words >= $minimum_words) {
                    $html_content = $condensed;
                    $actual_words = $condensed_words;
                    LogService::log('success', "YouTubeToArticle: condensação aplicada {$actual_words}/{$target_words} palavras");
                }
            }
        }

        // FIX v1.0.0-STABILITY: detectar H2 vazios e expandir.
        if (class_exists('\GeoMetodoSEO\Services\H2QualityValidator')) {
            $h2_check = \GeoMetodoSEO\Services\H2QualityValidator::detect_thin_h2s($html_content);
            if ($h2_check['has_problem']) {
                \GeoMetodoSEO\Services\H2QualityValidator::log_problem($h2_check, 0, 'youtube_pipeline');
                $expand_prompt = \GeoMetodoSEO\Services\H2QualityValidator::build_expand_prompt($html_content, $h2_check['problematic_h2s'], $keyword_art);
                $expand_response = $ai->generateText($expand_prompt, $provider, $article_model ?: ($model ?: null));
                if ($expand_response && !$expand_response->hasError()) {
                    $expanded = trim($expand_response->getContent());
                    $expanded = preg_replace('/^```html?\s*|^```\s*|```\s*$/i', '', $expanded);
                    if (trim($expanded) !== '' && strpos($expanded, '<h2') !== false) {
                        $html_content = trim($expanded);
                        LogService::log('success', '[youtube_pipeline] H2 vazios corrigidos');
                    }
                }
            }
        }

        $data['content'] = $html_content;
        $data['word_count_real'] = $actual_words ?? $this->countWords($html_content);
        $data['word_count_target'] = $target_words;
        $data['word_count_status'] = ($data['word_count_real'] >= $minimum_words) ? 'ok' : 'below_target';

        // Adicionar metadados do vídeo
        $data['youtube_video_id']  = $video_id;
        $data['youtube_video_url'] = $meta['video_url'];
        $data['source_channel']    = $meta['channel'];
        $data['source_title']      = $meta['title'];
        $data['thumbnail_url']     = $meta['thumbnail'];

        return $data;
    }


    /**
     * v1.0.0 — YouTube Article Single Master Prompt.
     * Uma chamada principal retorna metadados + HTML completo para reduzir custo sem perder profundidade.
     */
    private function generateArticleSingleMaster(string $video_id, array $meta, string $context, string $language, string $provider, string $model, int $target_words, int $minimum_words, int $maximum_words) {
        $ai = new \GeoMetodoSEO\AI\AIManager();
        $lang_instruction = $language === 'pt-BR' ? 'português brasileiro' : $language;
        $year = date('Y');
        $prompt = "Você é editor-chefe especialista em SEO, GEO, AEO, LLM Optimization, E-E-A-T e transformação de vídeos do YouTube em artigos long-form.\n\n"
            . "Transforme o vídeo abaixo em um artigo publicável.\n"
            . "IDIOMA: {$lang_instruction}\nANO: {$year}\nMETA: {$target_words} palavras. MÍNIMO: {$minimum_words}. MÁXIMO: {$maximum_words}.\n\n"
            . "CONTEXTO DO VÍDEO:\n{$context}\n\n"
            . "REGRAS OBRIGATÓRIAS:\n"
            . "1. Não invente dados, preços, datas, estudos, fontes, estatísticas, especificações ou acontecimentos fora do vídeo.\n"
            . "2. Use apenas o contexto do vídeo/transcrição e conhecimento estável. Quando faltar dado, escreva com cautela.\n"
            . "3. Keyword: use no 1º parágrafo, a cada 250-350 palavras, máx 1x por parágrafo.\n"
            . "4. SPEAKABLE — escreva para VOZ: sujeito explícito em cada frase, voz ativa, máx 2-3 frases diretas por parágrafo de abertura.\n"
            . "5. GEO — cada H2 deve ser autossuficiente: uma IA que ler só aquela seção entende a resposta completa.\n"
            . "6. AEO — 1º parágrafo de cada H2: resposta direta em 40-70 palavras (candidato a Featured Snippet).\n"
            . "7. ORIGINALIDADE — cada parágrafo: dado do vídeo OU comparação prática OU erro+solução OU insight não-óbvio.\n"
            . "8. PROIBIDO: 'no mundo de hoje', 'cada vez mais', 'é fundamental', 'é essencial', 'vamos explorar', 'guia completo', 'em resumo'.\n"
            . "9. O HTML deve conter: introdução, H2/H3, tabela HTML real, guia prático, erros comuns, conclusão com próximo passo.\n"
            . "10. Não inclua FAQ (gerado separadamente). Não inclua H1. Não inclua imagens.\n\n"
            . "FORMATO DE RESPOSTA — siga EXATAMENTE esta estrutura (NÃO use JSON):\n"
            . "TITULO: [título SEO até 60 caracteres]\n"
            . "KEYWORD: [keyword principal extraída do vídeo]\n"
            . "META_DESC: [meta description até 155 caracteres com keyword e benefício]\n"
            . "RESUMO: [resumo editorial de 2 frases]\n"
            . "RESPOSTA_RAPIDA: [resposta rápida de 40-75 palavras, clara para voz, com sujeito explícito]\n"
            . "---HTML---\n"
            . "[aqui o HTML completo do artigo, começando direto com <p> ou <h2>]\n\n"
            . "Comece a resposta diretamente com 'TITULO:'. Não use ```. Não use JSON.";

        $response = $ai->generateText($prompt, $provider, $model ?: null);
        if (!$response || $response->hasError()) {
            LogService::log('error', 'YouTubeToArticle Single Master: falha (' . $provider . '): ' . ($response ? $response->getError() : 'nulo'));
            return false;
        }

        $raw = trim((string)$response->getContent());
        $raw = preg_replace('/^```\w*\s*/i', '', $raw);
        $raw = preg_replace('/```\s*$/i', '', trim($raw));

        // Parse dos marcadores — robusto, sem depender de JSON
        $data = [];
        $fields = [
            'title'        => '/TITULO:\s*(.+?)(?:\n|$)/iu',
            'keyword'      => '/KEYWORD:\s*(.+?)(?:\n|$)/iu',
            'meta_desc'    => '/META_DESC:\s*(.+?)(?:\n|$)/iu',
            'excerpt'      => '/RESUMO:\s*(.+?)(?:\n|$)/iu',
            'quick_answer' => '/RESPOSTA_RAPIDA:\s*(.+?)(?:\n|$)/iu',
        ];
        foreach ($fields as $key => $pattern) {
            if (preg_match($pattern, $raw, $m)) {
                $data[$key] = trim($m[1]);
            }
        }

        // HTML vem depois de ---HTML---
        if (preg_match('/---HTML---\s*(.+)$/su', $raw, $m)) {
            $html_content = trim($m[1]);
        } else {
            // Fallback: pegar tudo após a última linha de marcador
            $html_content = trim(preg_replace('/^.*RESPOSTA_RAPIDA:.*?(?:\n|$)/su', '', $raw));
        }

        $html_content = preg_replace('/^```html?\s*/i', '', $html_content);
        $html_content = preg_replace('/```\s*$/i', '', $html_content);
        $html_content = $this->removeQuickAnswerBlocks(trim($html_content));

        if (empty($html_content) || strlen(strip_tags($html_content)) < 300) {
            LogService::log('error', 'YouTubeToArticle Single Master: conteúdo muito curto (' . strlen($html_content) . ' chars)');
            return false;
        }

        $actual_words = $this->countWords($html_content);

        // EXPANSÃO OBRIGATÓRIA: se o artigo veio abaixo do mínimo (ex.: pediu 2500
        // e veio 1140), expandir até atingir a meta. Isso roda mesmo com o
        // auto_expand global desligado — o usuário escolheu um tamanho e ele deve
        // ser respeitado. Faz até 2 passadas de expansão.
        $expand_attempts = 0;
        while ($actual_words < $minimum_words && $expand_attempts < 2) {
            $expand_attempts++;
            $faltam = $target_words - $actual_words;
            $expand_prompt = "Você é editor especialista. O artigo HTML abaixo está com {$actual_words} palavras, "
                . "mas precisa ter no mínimo {$minimum_words} (meta {$target_words}). "
                . "EXPANDA o artigo adicionando aproximadamente {$faltam} palavras de conteúdo ÚTIL e ESPECÍFICO: "
                . "aprofunde explicações existentes, adicione exemplos práticos, detalhe os passos, "
                . "acrescente subseções H3 relevantes e nuances do tema. "
                . "REGRAS: não invente dados/estatísticas/fontes; não repita o que já foi dito; "
                . "mantenha todo o HTML existente e apenas ACRESCENTE conteúdo de qualidade; "
                . "não adicione FAQ, não adicione H1, não use blocos de código markdown. "
                . "Retorne o artigo HTML COMPLETO (o original expandido), começando com <p> ou <h2>.\n\n"
                . "ARTIGO ATUAL:\n{$html_content}";
            $exp_resp = $ai->generateText($expand_prompt, $provider, $model ?: null);
            if (!$exp_resp || $exp_resp->hasError()) break;
            $expanded = trim($exp_resp->getContent());
            $expanded = preg_replace('/^```html?\s*/i', '', $expanded);
            $expanded = preg_replace('/```\s*$/i', '', trim($expanded));
            $expanded = $this->removeQuickAnswerBlocks(trim($expanded));
            $new_words = $this->countWords($expanded);
            // Só aceita se realmente cresceu e tem H2
            if ($new_words > $actual_words && strpos($expanded, '<h2') !== false) {
                $html_content = $expanded;
                $actual_words = $new_words;
                LogService::log('success', "YouTubeToArticle: expansão {$expand_attempts} → {$actual_words}/{$target_words} palavras");
            } else {
                break;
            }
        }

        $data['title'] = sanitize_text_field((string)($data['title'] ?? ($meta['title'] ?? 'Artigo YouTube')));
        $data['keyword'] = sanitize_text_field((string)($data['keyword'] ?? ($meta['title'] ?? 'video')));
        $data['seo_title'] = sanitize_text_field((string)($data['seo_title'] ?? $data['title']));
        $data['meta_desc'] = sanitize_text_field((string)($data['meta_desc'] ?? mb_substr((string)($meta['description'] ?? ''), 0, 155)));
        $data['excerpt'] = sanitize_textarea_field((string)($data['excerpt'] ?? $data['meta_desc']));
        $data['quick_answer'] = sanitize_textarea_field((string)($data['quick_answer'] ?? ''));
        $data['content'] = $html_content;
        $data['word_count_real'] = $actual_words;
        $data['word_count_target'] = $target_words;
        $data['word_count_status'] = ($actual_words >= $minimum_words) ? 'ok' : 'below_target';
        $data['youtube_video_id']  = $video_id;
        $data['youtube_video_url'] = $meta['video_url'];
        $data['source_channel']    = $meta['channel'];
        $data['source_title']      = $meta['title'];
        $data['thumbnail_url']     = $meta['thumbnail'];

        LogService::log('success', "YouTubeToArticle Single Master: artigo gerado em 1 chamada ({$actual_words}/{$target_words} palavras)");
        return $data;
    }

    /**
     * Cria post WordPress a partir dos dados gerados.
     * Compatível com o pipeline existente.
     *
     * @return int|WP_Error Post ID ou erro
     */
    public function createPost( array $data, string $post_status = 'draft', string $category_id = '' ) {
        $title   = sanitize_text_field($data['title'] ?? '');
        // Permitir iframe do YouTube (wp_kses_post remove iframes por padrão)
        $raw_content = $data['content'] ?? '';
        $allowed_html = array_merge(wp_kses_allowed_html('post'), [
            'iframe' => [
                'src'             => true, 'width'           => true, 'height'          => true,
                'frameborder'     => true, 'allowfullscreen' => true, 'allow'           => true,
                'style'           => true, 'class'           => true, 'title'           => true,
            ],
            'figure' => ['style' => true, 'class' => true],
            'figcaption' => ['style' => true, 'class' => true],
        ]);
        $content = wp_kses($raw_content, $allowed_html);
        $content = $this->removeQuickAnswerBlocks($content);
        $excerpt = sanitize_textarea_field($data['excerpt'] ?? '');

        if (empty($title) || empty($content)) return new \WP_Error('empty', 'Título ou conteúdo vazio');

        $publish_result = GeoMetodoSEO_Publisher::publish([
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'status' => $post_status,
            'post_type' => 'post',
            'author_id' => get_current_user_id() ?: 1,
            'category_ids' => (!empty($category_id) && is_numeric($category_id)) ? [(int)$category_id] : [],
            'focus_keyword' => (string)($data['keyword'] ?? $title),
            'seo_title' => $title,
            'meta_description' => $excerpt ?: mb_substr(wp_strip_all_tags($content), 0, 160),
            'source_module' => 'youtube_article',
            'custom_meta' => [
                '_geo_keyword' => (string)($data['keyword'] ?? $title),
                '_geo_generation_context' => 'youtube_article',
                '_geo_youtube_video_id' => (string)($data['youtube_video_id'] ?? ''),
            ],
        ]);
        if (empty($publish_result['success'])) {
            return new \WP_Error('publisher_failed', $publish_result['error'] ?? 'Falha ao criar post via Publisher central.');
        }
        $post_id = (int)$publish_result['post_id'];

        // FORÇAR embed do YouTube no artigo (independente do que a IA gerou)
        $video_id = $data['youtube_video_id'] ?? '';
        if ($video_id) {
            $embed_html = '<div class="geo-video-embed" style="position:relative;width:100%;max-width:880px;margin:32px auto;aspect-ratio:16/9;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.15);">'
                        . '<iframe src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" '
                        . 'style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" '
                        . 'allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
                        . 'loading="lazy" title="Vídeo do YouTube"></iframe>'
                        . '</div>';

            // Verificar se embed já existe no conteúdo
            if (strpos($content, 'youtube.com/embed/' . $video_id) === false) {
                // Inserir após o primeiro parágrafo
                $content = preg_replace('/<\/p>/i', '</p>' . $embed_html, $content, 1);
                // Se não havia nenhum </p> (conteúdo sem parágrafo fechado), prepend.
                if (strpos($content, 'youtube.com/embed/' . $video_id) === false) {
                    $content = $embed_html . $content;
                }
            }
            // Salvar IMEDIATAMENTE o conteúdo com o embed (antes era salvo só se
            // houvesse quick_answer — sem isso o iframe se perdia e virava "clique
            // para assistir no YouTube"). update_post sem passar por wp_kses_post,
            // que removeria o iframe.
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
        }

        // quick_answer: inserir UMA ÚNICA resposta rápida oficial no topo.
        // 1.0.0: remove qualquer bloco criado pela IA para evitar duplicação.
        $content = $this->removeQuickAnswerBlocks($content);
        if (!empty($data['quick_answer'])) {
            $quick_box = '<div class="geo-quick-answer" style="background:#f0f8ff;border-left:4px solid #0073aa;padding:16px 20px;margin:0 0 28px;border-radius:0 8px 8px 0;">'
                       . '<strong style="display:block;margin-bottom:8px;color:#0073aa;">⚡ Resposta Rápida</strong>'
                       . '<p style="margin:0;font-size:16px;line-height:1.6;">' . $this->limitWords(wp_strip_all_tags((string)$data['quick_answer']), 75) . '</p>'
                       . '</div>';
            $content = $quick_box . $content;
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
        }

        // Aplicar linkagem interna
        $linker = new \GeoMetodoSEO\SEO\InternalLinkingService();
        $linked_content = $linker->apply($post_id, $content);
        if ($linked_content !== $content) {
            $content = $linked_content;
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
        }

        // FONTES REAIS para artigo de YouTube:
        // A fonte PRIMÁRIA e sempre verdadeira é o PRÓPRIO VÍDEO do YouTube que
        // originou o artigo (canal + vídeo). Isso é uma fonte real e verificável,
        // ao contrário de adivinhar órgãos por palavra-chave. Entidades extras
        // (ex.: GitHub, Python) entram só se realmente citadas no texto.
        $video_id_src = $data['youtube_video_id'] ?? '';
        $channel_name = trim((string)($data['channel'] ?? $data['author'] ?? ''));
        $sources_html = '';

        if ($video_id_src) {
            $video_url   = 'https://www.youtube.com/watch?v=' . $video_id_src;
            $video_label = $channel_name !== ''
                ? 'Vídeo original no YouTube — canal ' . esc_html($channel_name)
                : 'Vídeo original no YouTube';
            $sources_html .= '<li><a href="' . esc_url($video_url) . '" target="_blank" rel="noopener noreferrer">' . $video_label . '</a></li>';
        }

        // Entidades adicionais REAIS citadas no conteúdo (sem inventar)
        if (class_exists('\GeoMetodoSEO\Services\ContextEngine')) {
            $keyword_yt   = $data['keyword'] ?? $title;
            $post_content = get_post_field('post_content', $post_id);
            $ext_links    = \GeoMetodoSEO\Services\ContextEngine::get_authority_links($keyword_yt, $post_content);
            $added_ext = 0;
            foreach ($ext_links as $link) {
                if ($added_ext >= 2) break;
                if (empty($link['url']) || empty($link['anchor'])) continue;
                if (stripos($sources_html, $link['url']) !== false) continue;
                $sources_html .= '<li><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link['anchor']) . '</a></li>';
                $added_ext++;
            }
        }

        // Inserir o bloco de fontes só se houver ao menos uma fonte real.
        if ($sources_html !== '') {
            $post_content = get_post_field('post_content', $post_id);
            if (stripos($post_content, 'geo-youtube-sources') === false) {
                $block = "\n\n<aside class=\"geo-youtube-sources sara-official-sources\" style=\"margin:24px 0;padding:16px 18px;border:1px solid rgba(148,163,184,.35);border-radius:6px;\">"
                       . '<p style="margin:0 0 8px;font-weight:600;">📚 Fontes</p>'
                       . '<ul style="margin:0;padding-left:20px;">' . $sources_html . '</ul></aside>';
                $post_content .= $block;
                \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $post_content]);
                $content = $post_content;
            }
        }

        // 1.0.0: Imagens internas do YouTube → Artigo agora usam APENAS:
        //   1. Replicate Flux Schnell (primário econômico para imagens do corpo)
        //   2. Fal.ai gpt-image-2 (fallback do corpo)
        // ZERO chamadas diretas pra api.openai.com pra imagens — economia significativa.
        if (!empty($data['thumbnail_url'])) {
            $this->setThumbnailFromUrl($post_id, $data['thumbnail_url'], $title);
        }

        if (class_exists('GeoMetodoSEO\Autopilot\Writer\SaraGlobalPostProcessor')) {
            $content = \GeoMetodoSEO\Autopilot\Writer\SaraGlobalPostProcessor::finalize($post_id, [
                'title' => $title,
                'keyword' => $data['keyword'] ?? $title,
                'category' => !empty($category_id) ? (get_the_category_by_ID((int)$category_id) ?: 'geral') : 'geral',
                'niche' => \GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller::get('site_niche', 'Tecnologia'),
                'target_words' => (int)($data['word_count_target'] ?? $this->saraConfig('youtube_word_count_target', '2600')),
                'enable_faq' => true,
                'internal_image_count' => ImageGeneratorService::body_images_count(),
                // 1.0.0 BUG FIX CRÍTICO #5/5: voltou TRUE.
                // YouTube → Artigo via AJAX precisa imagens na hora.
                'process_internal_images_now' => class_exists(__NAMESPACE__ . '\\LibraryImageService') ? LibraryImageService::is_library_mode() : false,
            ]);
        }

        // v1.0.0: Imagens internas do YouTube → Artigo geradas pela cadeia oficial Replicate → Fal.ai.
        // Sem chamadas diretas à OpenAI. Inserção depois do pós-processamento global pra não sobrescrever FAQ/autor/E-E-A-T.
        $post_after_global = get_post($post_id);
        $content_for_images = $post_after_global ? (string)$post_after_global->post_content : $content;
        $content_with_youtube_images = $this->insertYouTubeContextImages($post_id, $content_for_images, $data, $title);
        if ($content_with_youtube_images !== $content_for_images) {
            $content = $content_with_youtube_images;
            update_post_meta($post_id, '_geo_youtube_context_images_done', substr_count($content, 'geo-youtube-context-image'));
            LogService::log('success', "YouTubeToArticle: imagens contextuais inseridas imediatamente no post #{$post_id}");
        } else {
            // Se o servidor/API impedir a inserção imediata, mantém fila como fallback, sem quebrar o artigo.
            $this->queueYouTubeContextImages($post_id, $data, $title);
            LogService::log('warning', "YouTubeToArticle: imagens contextuais não inseridas imediatamente; fila acionada para post #{$post_id}");
        }

        // Meta dados
        update_post_meta($post_id, '_geo_provider', 'youtube');
        update_post_meta($post_id, '_geo_text_provider_used', sanitize_text_field($data['text_provider_used'] ?? ($data['provider'] ?? '')));
        update_post_meta($post_id, '_geo_text_model_used', sanitize_text_field($data['text_model_used'] ?? ($data['model'] ?? '')));
        update_post_meta($post_id, '_geo_generation_context', 'youtube_article');
        update_post_meta($post_id, '_geo_keyword', $data['keyword'] ?? $title);
        update_post_meta($post_id, '_geo_youtube_video_id', $data['youtube_video_id'] ?? '');
        update_post_meta($post_id, '_geo_youtube_channel', $data['source_channel'] ?? '');
        update_post_meta($post_id, '_sara_word_count_real', (int)($data['word_count_real'] ?? $this->countWords($content)));
        update_post_meta($post_id, '_sara_word_count_target', (int)($data['word_count_target'] ?? $this->saraConfig('youtube_word_count_target', '2600')));
        update_post_meta($post_id, '_sara_word_count_status', sanitize_text_field($data['word_count_status'] ?? 'unknown'));

        // RankMath / Yoast
        if (!empty($data['seo_title'])) {
            update_post_meta($post_id, 'rank_math_title', $data['seo_title']);
            update_post_meta($post_id, '_yoast_wpseo_title', $data['seo_title']);
        }
        if (!empty($data['meta_desc'])) {
            update_post_meta($post_id, 'rank_math_description', $data['meta_desc']);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $data['meta_desc']);
        }
        if (!empty($data['keyword'])) {
            $exact_keyword = trim((string)($data['keyword'] ?? $title));
            update_post_meta($post_id, 'rank_math_focus_keyword', $exact_keyword);
            update_post_meta($post_id, '_rank_math_focus_keyword', $exact_keyword);
            update_post_meta($post_id, '_geo_keyword_exact', $exact_keyword);
        }

        LogService::log('info', "YouTubeToArticle: post #{$post_id} criado a partir do vídeo {$data['youtube_video_id']}");

        return $post_id;
    }

    /**
     * Faz download e seta thumbnail a partir de URL externa.
     */
    /**
     * Enfileira imagens contextuais do YouTube para processamento em segundo plano.
     * Usa a cadeia oficial Replicate -> Fal.ai, sem travar o AJAX.
     */
    private function queueYouTubeContextImages(int $post_id, array $data, string $title): void {
        update_post_meta($post_id, '_geo_youtube_context_image_job', wp_json_encode([
            'title'   => $title,
            'keyword' => $data['keyword'] ?? $title,
            'created' => current_time('mysql'),
        ], JSON_UNESCAPED_UNICODE));

        if (!wp_next_scheduled('geo_youtube_process_context_images', [$post_id])) {
            wp_schedule_single_event(time() + 20, 'geo_youtube_process_context_images', [$post_id]);
        }
        LogService::log('info', "YouTubeToArticle: imagens contextuais enfileiradas para post #{$post_id}");
    }

    /**
     * Executado pelo WP-Cron para inserir imagens internas do YouTube sem causar 504 no AJAX.
     */
    public function processQueuedContextImages(int $post_id): void {
        $post = get_post($post_id);
        if (!$post) return;
        if (substr_count((string)$post->post_content, 'geo-youtube-context-image') >= 1) {
            delete_post_meta($post_id, '_geo_youtube_context_image_job');
            return;
        }
        $raw = get_post_meta($post_id, '_geo_youtube_context_image_job', true);
        $job = $raw ? json_decode((string)$raw, true) : [];
        $title = (string)($job['title'] ?? $post->post_title);
        $keyword = (string)($job['keyword'] ?? get_post_meta($post_id, '_geo_keyword', true) ?: $title);
        $data = [
            'keyword' => $keyword,
        ];
        $content = $this->insertYouTubeContextImages($post_id, (string)$post->post_content, $data, $title);
        if ($content !== (string)$post->post_content) {
            update_post_meta($post_id, '_geo_youtube_context_images_done', substr_count($content, 'geo-youtube-context-image'));
            LogService::log('success', "YouTubeToArticle: imagens contextuais inseridas em segundo plano no post #{$post_id}");
        } else {
            LogService::log('warning', "YouTubeToArticle: nenhuma imagem contextual inserida no post #{$post_id}");
        }
        delete_post_meta($post_id, '_geo_youtube_context_image_job');
    }

    /**
     * Inserir imagens contextuais no YouTube -> Artigo pela cadeia oficial Replicate -> Fal.ai.
     */
    private function insertYouTubeContextImages(int $post_id, string $content, array $data, string $title): string {
        $img_keyword = sanitize_text_field($data['keyword'] ?? $title);
        $image = new ImageGeneratorService();
        $target = ImageGeneratorService::body_images_count();
        $interval = ImageGeneratorService::h2_interval();

        if (!preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $inserted = 0;
        $h2_seen = 0;
        $offset_shift = 0;
        foreach ($matches[0] as $idx => $h2_match) {
            if ($inserted >= $target) break;
            $h2_text = trim(wp_strip_all_tags($matches[1][$idx][0] ?? ''));
            if ($h2_text === '' || preg_match('/faq|perguntas\s+frequentes|conclus[aã]o/iu', $h2_text)) continue;
            $h2_seen++;
            if (($h2_seen % $interval) !== 0) continue;

            $pos = (int)$h2_match[1] + $offset_shift;
            $after = substr($content, $pos + strlen($h2_match[0]), 400);
            if (preg_match('/<(?:figure|img)\b/i', $after)) continue;

            $attachment_id = $image->generate_body_attachment([
                'title' => $title,
                'keyword' => $img_keyword,
                'category' => 'YouTube',
                'section' => $h2_text,
            ], $post_id);
            if (is_wp_error($attachment_id) || !$attachment_id) continue;

            $alt = trim($img_keyword . ' - ' . $h2_text);
            $figure = \GeoMetodoSEO\Services\LibraryImageService::build_attachment_figure_html((int)$attachment_id, $alt, ['geo-youtube-context-image'], ['style' => 'max-width:100%;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,0.12);height:auto;'], $alt);
            if ($figure === '') continue;
            \GeoMetodoSEO\Services\LibraryImageService::remember_body_image($post_id, (int)$attachment_id, \GeoMetodoSEO\Services\LibraryImageService::is_library_mode() ? 'library' : 'ai');

            $insert_at = $pos + strlen($h2_match[0]);
            $content = substr($content, 0, $insert_at) . $figure . substr($content, $insert_at);
            $offset_shift += strlen($figure);
            $inserted++;
        }

        if ($inserted > 0) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::update_post(['ID' => $post_id, 'post_content' => $content]);
        }

        return $content;
    }
    private function setThumbnailFromUrl( int $post_id, string $url, string $title ): void {
        $attachment_id = SafeImageSideload::attachment_id($url, $post_id, $title);
        if ($attachment_id) {
            \GeoMetodoSEO\Publisher\GeoMetodoSEO_Publisher::set_featured_image($post_id, $attachment_id);
        }
    }


    /** 1.0.0 — Lê config da SARA mesmo quando o serviço roda fora do dashboard. */
    private function saraConfig(string $key, string $default = ''): string {
        if (class_exists('\GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller')) {
            return (string) \GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller::get($key, $default);
        }
        return $default;
    }

    /** 1.0.0 — Briefing factual antes da escrita. */
    private function buildFactualBriefing(string $context, array $data, int $target_words, string $provider, string $model): string {
        $ai = new \GeoMetodoSEO\AI\AIManager();
        $title = $data['title'] ?? '';
        $keyword = $data['keyword'] ?? '';
        $prompt = "Você é editor factual. Monte um BRIEFING FACTUAL para transformar um vídeo do YouTube em artigo long-form.\n\n"
            . "Use SOMENTE o contexto do vídeo abaixo. Não invente datas, preços, especificações, números, estudos, fontes ou fatos externos.\n"
            . "Se faltar informação, marque como lacuna e oriente escrita cautelosa.\n\n"
            . "Título planejado: {$title}\nKeyword: {$keyword}\nMeta de palavras: {$target_words}\n\nContexto do vídeo:\n{$context}\n\n"
            . "Retorne em tópicos: fatos confirmados, lacunas, perguntas obrigatórias, entidades/termos, limites anti-invenção e ângulo editorial seguro. "
            . "Se faltar ficha técnica/preço/testes de produto, determine que o artigo deve usar uma matriz de verificação em vez de tabela comparativa especulativa.";
        $response = $ai->generateText($prompt, $provider, $model ?: null);
        if ($response && !$response->hasError() && trim((string)$response->getContent()) !== '') {
            LogService::log('success', 'YouTubeToArticle: briefing factual criado antes da escrita');
            return mb_substr(trim(strip_tags($response->getContent())), 0, 6000);
        }
        LogService::log('warning', 'YouTubeToArticle: briefing factual por IA falhou; usando briefing local defensivo');
        return "FATOS CONFIRMADOS: usar apenas título, canal, descrição, tags e transcrição disponíveis.\n"
             . "LACUNAS: não inventar datas, preços, estatísticas, estudos, especificações ou fontes externas não fornecidas.\n"
             . "ÂNGULO: artigo útil, cauteloso, natural, com foco em explicar o conteúdo do vídeo e responder dúvidas reais.";
    }

    /** 1.0.0 — Expande artigo curto sem inventar e sem duplicar resposta rápida. */
    private function expandYouTubeArticle(string $html, string $briefing, string $keyword, int $target_words, int $minimum_words, int $actual_words, string $provider, string $model) {
        $ai = new \GeoMetodoSEO\AI\AIManager();
        $missing = max(300, $target_words - $actual_words);
        $prompt = "O artigo YouTube → Artigo ficou abaixo da meta no contador real.\n\n"
            . "Palavras atuais: {$actual_words}\nMeta: {$target_words}\nMínimo aceitável: {$minimum_words}\nFaltam aprox.: {$missing}\nKeyword: {$keyword}\n\n"
            . "BRIEFING FACTUAL — não invente nada fora dele:\n{$briefing}\n\n"
            . "Expanda o artigo completo em HTML puro, mantendo o conteúdo existente, sem duplicar Resposta Rápida, sem criar dados/fatos/fontes não fornecidas, sem enrolação.\n"
            . "Adicione profundidade com seções úteis, exemplos cautelosos, critérios, limitações, passos práticos e comparação quando fizer sentido.\n\nARTIGO ATUAL:\n{$html}";
        $response = $ai->generateText($prompt, $provider, $model ?: null);
        if (!$response || $response->hasError() || trim((string)$response->getContent()) === '') {
            LogService::log('warning', 'YouTubeToArticle: auto-expansão falhou — ' . ($response ? $response->getError() : 'resposta nula'));
            return false;
        }
        $expanded = trim($response->getContent());
        $expanded = preg_replace('/^```html?\s*/i', '', $expanded);
        $expanded = preg_replace('/```\s*$/i', '', $expanded);
        return $this->removeQuickAnswerBlocks(trim($expanded));
    }

    /** 1.0.0 — Condensa artigo YouTube se passar muito da meta escolhida. */
    private function condenseYouTubeArticle(string $html, string $briefing, string $keyword, int $target_words, int $maximum_words, int $actual_words, string $provider, string $model) {
        $ai = new \GeoMetodoSEO\AI\AIManager();
        $prompt = "O artigo YouTube → Artigo passou muito da meta de palavras.

"
            . "Palavras atuais: {$actual_words}
Meta: {$target_words}
Máximo recomendado: {$maximum_words}
Keyword: {$keyword}

"
            . "BRIEFING FACTUAL — não invente nada fora dele:
{$briefing}

"
            . "Condense mantendo HTML puro, embed do YouTube, tabela HTML se existir, sem resposta rápida, sem FAQ, sem inventar dados/fatos/fontes e sem perder informações úteis. "
            . "Retorne o artigo completo com aproximadamente {$target_words} palavras e nunca acima de {$maximum_words}.

ARTIGO ATUAL:
{$html}";
        $response = $ai->generateText($prompt, $provider, $model ?: null);
        if (!$response || $response->hasError() || trim((string)$response->getContent()) === '') {
            LogService::log('warning', 'YouTubeToArticle: condensação falhou — ' . ($response ? $response->getError() : 'resposta nula'));
            return false;
        }
        $condensed = trim($response->getContent());
        $condensed = preg_replace('/^```html?\s*/i', '', $condensed);
        $condensed = preg_replace('/```\s*$/i', '', $condensed);
        return $this->removeQuickAnswerBlocks(trim($condensed));
    }

    private function limitWords(string $text, int $limit): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        preg_match_all("/[\\p{L}\\p{N}][\\p{L}\\p{N}'’\\-]*/u", $text, $m, PREG_OFFSET_CAPTURE);
        $words = $m[0] ?? [];
        if (count($words) <= $limit) return esc_html($text);
        $last = $words[$limit - 1][1] + strlen($words[$limit - 1][0]);
        return esc_html(rtrim(mb_substr($text, 0, $last), ' ,;:.-') . '.');
    }

    /** 1.0.0 — Contador real com suporte a português/acentos. */
    private function countWords(string $html): int {
        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text, $m);
        return count($m[0] ?? []);
    }

    /**
     * Remove blocos de resposta rápida duplicados vindos da IA ou de gerações anteriores.
     */
    private function removeQuickAnswerBlocks(string $content): string {
        $patterns = [
            '/<div\s+class="[^"]*(?:geo|sara)-quick-answer[^"]*"[^>]*>.*?<\/div>\s*/isu',
            '/<section\s+class="[^"]*(?:geo|sara)-quick-answer[^"]*"[^>]*>.*?<\/section>\s*/isu',
            '/<p[^>]*>\s*<strong[^>]*>\s*⚡?\s*Resposta\s+R[áa]pida:?\s*<\/strong>.*?<\/p>\s*/isu',
        ];
        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', $content) ?? $content;
        }
        return trim($content);
    }

}

