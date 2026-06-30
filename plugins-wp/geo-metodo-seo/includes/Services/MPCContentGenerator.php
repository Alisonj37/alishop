<?php
namespace GeoMetodoSEO\Services;

use GeoMetodoSEO\AI\AIManager;

if (!defined('ABSPATH')) exit;

/**
 * MPCContentGenerator
 *
 * v1.0.0: MPC agora opera por Prompt Mestre Único por padrão.
 * Mantém a qualidade SEO/GEO/AEO/LLM/E-E-A-T, mas reduz a geração de 9-13 chamadas
 * para 1 chamada principal, evitando prejuízo de créditos sem remover o recurso MPC.
 */
class MPCContentGenerator {

    private AIManager $ai;
    private string $provider;
    private ?string $model;

    public function __construct(AIManager $ai, string $provider, ?string $model = null) {
        $this->ai = $ai;
        $this->provider = $provider;
        $this->model = $model;
    }

    /**
     * @return array{title:string, meta:string, slug:string, content:string}
     */
    public function generate(string $keyword, string $language = 'pt-BR', string $tone = 'profissional', string $size = 'large'): array {
        return $this->generate_single_master($keyword, $language, $tone, $size);
    }

    private function generate_single_master(string $keyword, string $language, string $tone, string $size = 'large'): array {
        $niche = get_option('sara_niche', 'Tecnologia');
        $year  = date('Y');

        // FIX v1.0.0-WORDCOUNT-INDIVIDUAL: converter $size em target_words real.
        // Antes: usava option fixa geo_single_master_target_words=2800 → ignorava escolha do user.
        $size_to_words = ['small' => 1500, 'medium' => 2500, 'large' => 3500];
        $target_words  = $size_to_words[$size] ?? (int) get_option('geo_single_master_target_words', 2500);

        // Fix: gerar LSI e entidades básicos a partir da keyword
        $entities = $this->derive_entities($keyword, $niche);
        $lsi      = $this->derive_lsi($keyword, $niche, $year);
        $sec_kws  = $this->derive_secondary_keywords($keyword, $year);

        // FIX-LINKS: buscar links internos e externos para passar para a IA
        $int_links_mpc = class_exists('\GeoMetodoSEO\Services\ContextEngine')
            ? \GeoMetodoSEO\Services\ContextEngine::get_internal_links($keyword, 0, $niche)
            : [];
        // Fontes NÃO são escolhidas aqui (pré-conteúdo). A fonte real é inserida
        // depois no ArticlePipeline com base no CONTEÚDO gerado (entidade citada).
        $ext_links_mpc = [];

        $prompt = SingleMasterPromptService::article_json_prompt([
            'keyword'             => $keyword,
            'language'            => $language,
            'tone'                => $tone,
            'scope'               => 'MPC Single Master Prompt',
            'target_words'        => $target_words,
            'niche'               => $niche,
            'entities'            => $entities,
            'lsi_keywords'        => $lsi,
            'secondary_keywords'  => $sec_kws,
            'internal_links'      => $int_links_mpc,
            'external_links'      => $ext_links_mpc,
        ]);

        LogService::record('ai', 'info', 'MPC Single Master Prompt: iniciando geração em 1 chamada.', [
            'action' => 'mpc_single_master_start',
            'context' => ['keyword' => $keyword, 'provider' => $this->provider, 'model' => $this->model],
        ]);

        $response = $this->ai->generateText($prompt, $this->provider, $this->model);
        if ($response && !$response->hasError()) {
            $data = ContentFormatter::extractJson((string)$response->getContent());
            if (is_array($data) && !empty($data)) {
                $content = $this->buildContent($data, $keyword);
                LogService::record('ai', 'success', 'MPC Single Master Prompt: artigo retornado em 1 chamada.', [
                    'action' => 'mpc_single_master_success',
                    'context' => ['keyword' => $keyword],
                ]);
                return [
                    'title' => sanitize_text_field((string)($data['title_seo'] ?? (ucwords($keyword) . ' — Guia Completo ' . date('Y')))),
                    'meta' => sanitize_text_field((string)($data['meta_description'] ?? ('Guia completo sobre ' . $keyword . '.'))),
                    'slug' => sanitize_title((string)($data['url_slug'] ?? $keyword)),
                    'content' => $content,
                ];
            }
        }

        $error = $response ? (string)$response->getError() : 'resposta vazia';
        LogService::record('ai', 'warning', 'MPC Single Master falhou; usando fallback local sem nova chamada: ' . $error, [
            'action' => 'mpc_single_master_fallback',
            'context' => ['keyword' => $keyword],
        ]);

        return [
            'title' => ucwords($keyword) . ' — Guia Completo ' . date('Y'),
            'meta' => 'Guia completo sobre ' . $keyword . ' com critérios práticos, SEO, GEO, AEO e dúvidas frequentes.',
            'slug' => sanitize_title($keyword),
            'content' => $this->localFallbackContent($keyword),
        ];
    }

    private function buildContent(array $data, string $keyword): string {
        $year = date('Y');
        $parts = [];

        if (!empty($data['resumo_snippet'])) {
            $parts[] = '<div class="geo-quick-answer" style="background:linear-gradient(135deg,rgba(0,200,255,0.08),rgba(139,92,246,0.08));border-left:4px solid #00C8FF;padding:18px 22px;margin:0 0 32px;border-radius:0 10px 10px 0;">' . wp_kses_post((string)$data['resumo_snippet']) . '</div>';
        }
        if (!empty($data['introducao'])) {
            $parts[] = wp_kses_post((string)$data['introducao']);
        }

        $map = [
            'secao_definicao' => 'O que é ' . $keyword,
            'secao_funcionamento' => 'Como funciona',
            'tabela_comparativa' => 'Dados e comparativo',
            'secao_beneficios' => 'Benefícios principais',
            'secao_guia' => 'Guia prático',
            'dica_especialista' => '',
            'erros_comuns' => 'Erros comuns e como evitar',
            'tendencias_2026' => 'Tendências em ' . $year,
            'conclusao' => 'Conclusão',
            'faq_texto' => 'Perguntas frequentes',
        ];

        foreach ($map as $key => $heading) {
            if (empty($data[$key])) continue;
            if ($heading !== '') {
                $parts[] = '<h2>' . esc_html($heading) . '</h2>';
            }
            $parts[] = wp_kses_post((string)$data[$key]);
        }

        return implode("\n\n", array_filter($parts));
    }

    private function localFallbackContent(string $keyword): string {
        $kw = esc_html($keyword);
        return '<p><strong>Resposta rápida:</strong> ' . $kw . ' exige análise de intenção de busca, critérios práticos, SEO, GEO, AEO e adaptação para respostas de IA.</p>'
            . '<h2>O que é ' . $kw . '</h2><p>' . $kw . ' é o tema central desta análise. O conteúdo deve explicar definição, aplicação prática, riscos, benefícios e critérios de decisão sem inventar dados não confirmados.</p>'
            . '<h2>Como avaliar na prática</h2><p>Observe intenção de busca, confiabilidade das fontes, utilidade real para o leitor, clareza da estrutura, cobertura semântica e compatibilidade com mecanismos de busca e modelos de linguagem.</p>'
            . '<h2>Conclusão</h2><p>O melhor caminho é combinar conteúdo útil, estrutura clara, dados verificáveis e linguagem natural para atender leitores, Google e sistemas de IA.</p>';
    }

    /**
     * Gera entidades nomeadas básicas a partir da keyword + nicho.
     * Usado quando o Brain não fornece entidades (Artigo Individual, Massa).
     */
    private function derive_entities(string $keyword, string $niche): array {
        $kw_lower = mb_strtolower($keyword);
        $entities = [];
        $tech = ['samsung', 'xiaomi', 'motorola', 'apple', 'iphone', 'android', 'google', 'qualcomm', 'snapdragon', 'mediatek', 'oneplus', 'realme', 'oppo'];
        foreach ($tech as $e) {
            if (str_contains($kw_lower, $e)) {
                $entities[] = ucfirst($e);
            }
        }
        if (empty($entities)) {
            $entities[] = sanitize_text_field($niche);
        }
        return array_values(array_unique($entities));
    }

    /**
     * Gera termos LSI básicos derivados da keyword.
     */
    private function derive_lsi(string $keyword, string $niche, string $year): array {
        $lsi = [
            'como usar ' . $keyword,
            'vantagens do ' . $keyword,
            'dicas de ' . $keyword,
            $keyword . ' passo a passo',
            'erros comuns em ' . $keyword,
            $keyword . ' ' . $year,
        ];
        $niche_lower = mb_strtolower($niche);
        if (str_contains($niche_lower, 'tecnologia') || str_contains($niche_lower, 'celular') || str_contains($niche_lower, 'smartphone')) {
            $lsi[] = 'custo-benefício';
            $lsi[] = 'desempenho';
            $lsi[] = 'comparativo';
        }
        return array_values(array_unique(array_slice($lsi, 0, 8)));
    }

    /**
     * Gera keywords secundárias básicas derivadas da keyword.
     */
    private function derive_secondary_keywords(string $keyword, string $year): array {
        return array_values(array_unique([
            'melhor ' . $keyword,
            $keyword . ' ' . $year,
            'como escolher ' . $keyword,
            $keyword . ' vale a pena',
            'guia ' . $keyword,
        ]));
    }
}
