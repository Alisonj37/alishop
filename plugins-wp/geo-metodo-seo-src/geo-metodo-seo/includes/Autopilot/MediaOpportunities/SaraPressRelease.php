<?php
declare(strict_types=1);

namespace GeoMetodoSEO\Autopilot\MediaOpportunities;

if (!defined('ABSPATH')) { exit; }

use GeoMetodoSEO\Autopilot\Shared\AutopilotLogger;
use GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller;

/**
 * SaraPressRelease — Gera press releases white-hat baseados em conteúdo já publicado.
 *
 * Não envia automaticamente para nenhum lugar. Gera o texto + sugestão de:
 *   - Lista de jornalistas/sites para você enviar manualmente
 *   - Pitch curto (subject line) para email
 *   - Versão completa (corpo)
 *   - Versão social (LinkedIn/Twitter)
 *
 * 4 templates disponíveis:
 *   1. PRODUCT_LAUNCH — lançamento de produto/feature/conteúdo importante
 *   2. RESEARCH       — pesquisa/dado original baseado em série de posts
 *   3. UPDATE         — atualização significativa de algo existente
 *   4. CASE_STUDY     — caso de sucesso (precisa de dados/resultados)
 *
 * Compliance: gera só o texto. Distribuição é responsabilidade do usuário.
 *
 * @since 1.0.0
 */
class SaraPressRelease {

    public const TEMPLATES = [
        'product_launch' => [
            'name' => '🚀 Lançamento de Produto/Feature',
            'description' => 'Anuncia algo novo: produto, ferramenta, série de conteúdo importante.',
            'needs_post' => true,
        ],
        'research' => [
            'name' => '📊 Pesquisa / Dado Original',
            'description' => 'Apresenta resultados de pesquisa, levantamento ou dado coletado pelo seu site.',
            'needs_post' => true,
        ],
        'update' => [
            'name' => '🔄 Atualização Importante',
            'description' => 'Comunica mudança significativa em algo que já existe (versão nova, expansão, etc).',
            'needs_post' => true,
        ],
        'case_study' => [
            'name' => '✅ Case de Sucesso',
            'description' => 'Conta um caso real com resultados (exige dados concretos no post).',
            'needs_post' => true,
        ],
    ];

    /**
     * Gerar press release a partir de um post existente.
     *
     * @param int    $post_id      ID do post WordPress que serve de base
     * @param string $template_key product_launch | research | update | case_study
     * @param array  $extra        Campos adicionais opcionais (key facts, contact, etc)
     *
     * @return array{success:bool, package?:array, message?:string}
     */
    public static function generate(int $post_id, string $template_key, array $extra = []): array {
        if (!isset(self::TEMPLATES[$template_key])) {
            return ['success' => false, 'message' => 'Template inválido.'];
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return ['success' => false, 'message' => 'Post não encontrado ou não publicado.'];
        }

        if (!class_exists('\GeoMetodoSEO\Autopilot\Brain\SaraApiClient')) {
            return ['success' => false, 'message' => 'API de IA não disponível.'];
        }

        try {
            $context = self::build_post_context($post);
            $prompt  = self::build_prompt($template_key, $context, $extra);

            $api = new \GeoMetodoSEO\Autopilot\Brain\SaraApiClient();
            $raw = '';

            if (method_exists($api, 'simple_complete')) {
                $raw = (string) $api->simple_complete($prompt);
            } elseif (method_exists($api, 'request_with_backoff')) {
                $raw = (string) $api->request_with_backoff($prompt, 'press_release');
            }

            $package = self::parse_response($raw);

            if (empty($package['headline']) || empty($package['body'])) {
                return ['success' => false, 'message' => 'IA não retornou um press release completo.'];
            }

            // Salvar como post meta para histórico
            $existing = get_post_meta($post_id, '_sara_press_releases', true);
            $existing = is_array($existing) ? $existing : [];
            $existing[] = [
                'template'  => $template_key,
                'created'   => current_time('mysql'),
                'package'   => $package,
            ];
            // Manter só os 5 mais recentes
            $existing = array_slice($existing, -5);
            update_post_meta($post_id, '_sara_press_releases', $existing);

            AutopilotLogger::log('media', 'press_release_generated', 'success',
                "Press release '{$template_key}' gerado para post #{$post_id}");

            return ['success' => true, 'package' => $package];

        } catch (\Throwable $e) {
            AutopilotLogger::log('media', 'press_release_error', 'error',
                "#{$post_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erro: ' . $e->getMessage()];
        }
    }

    /**
     * Construir contexto a partir do post.
     */
    private static function build_post_context(\WP_Post $post): array {
        $content_text = wp_strip_all_tags($post->post_content);
        $content_text = preg_replace('/\s+/u', ' ', $content_text);
        $content_text = mb_substr(trim($content_text), 0, 3000);

        $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);

        $niche = get_option('sara_site_niche', '');
        if (empty($niche) && class_exists('\GeoMetodoSEO\Autopilot\Shared\AutopilotInstaller')) {
            $niche = AutopilotInstaller::get('site_niche', '');
        }

        return [
            'title'       => $post->post_title,
            'url'         => get_permalink($post),
            'excerpt'     => $post->post_excerpt ?: mb_substr($content_text, 0, 300),
            'content'     => $content_text,
            'categories'  => $categories,
            'site_name'   => get_bloginfo('name'),
            'site_url'    => get_site_url(),
            'niche'       => $niche,
            'date'        => date('d/m/Y'),
        ];
    }

    /**
     * Montar prompt customizado por template.
     */
    private static function build_prompt(string $template_key, array $context, array $extra): string {
        $template_descriptions = [
            'product_launch' => 'um LANÇAMENTO ou ANÚNCIO IMPORTANTE',
            'research'       => 'uma PESQUISA ou DADO ORIGINAL',
            'update'         => 'uma ATUALIZAÇÃO SIGNIFICATIVA',
            'case_study'     => 'um CASE DE SUCESSO com resultados concretos',
        ];

        $template_focus = [
            'product_launch' => 'O que é, por que importa, para quem é, onde acessar.',
            'research'       => 'Metodologia, números-chave, conclusões inesperadas, implicações.',
            'update'         => 'O que mudou, por que mudou, impacto para os usuários, próximos passos.',
            'case_study'     => 'Problema inicial, abordagem, resultados quantitativos, lições aprendidas.',
        ];

        $extra_context = '';
        if (!empty($extra['key_facts'])) {
            $extra_context .= "\nFATOS-CHAVE FORNECIDOS:\n" . wp_strip_all_tags((string)$extra['key_facts']);
        }
        if (!empty($extra['contact_email'])) {
            $extra_context .= "\nCONTATO PARA IMPRENSA: " . sanitize_email((string)$extra['contact_email']);
        }
        if (!empty($extra['spokesperson'])) {
            $extra_context .= "\nPORTA-VOZ: " . sanitize_text_field((string)$extra['spokesperson']);
        }

        $description = $template_descriptions[$template_key];
        $focus       = $template_focus[$template_key];

        return <<<PROMPT
Você é um redator profissional de press releases. Vai escrever um release sobre {$description}.

INFORMAÇÕES DO POST:
Título: {$context['title']}
URL: {$context['url']}
Site: {$context['site_name']} ({$context['site_url']})
Nicho: {$context['niche']}
Data: {$context['date']}

CONTEÚDO COMPLETO DO POST:
{$context['content']}
{$extra_context}

FOCO DESTE TEMPLATE: {$focus}

TAREFA:
Escreva um press release completo em PORTUGUÊS DO BRASIL no formato JSON exato abaixo, sem markdown, sem code fences:

{
  "subject_line": "Linha de assunto curta (máx 80 chars) — direta, intrigante, sem clickbait",
  "headline": "Manchete principal (máx 100 chars)",
  "subheadline": "Subtítulo explicando o ângulo (máx 150 chars)",
  "lead": "Parágrafo de abertura (50-80 palavras) com os 5W: quem, o quê, quando, onde, por quê",
  "body": "Corpo completo do release (300-500 palavras) em parágrafos. Use \\n\\n entre parágrafos. Cite estatísticas/dados específicos do post. Inclua 1 quote atribuído (ex: 'Segundo {$context['site_name']}, ...') e link {$context['url']}",
  "boilerplate": "Sobre o {$context['site_name']} (parágrafo curto sobre o site, 40-60 palavras)",
  "social_linkedin": "Versão LinkedIn (até 250 palavras, hashtags relevantes)",
  "social_twitter": "Versão Twitter/X (até 280 caracteres, com link)",
  "pitch_email": "Pitch curto para enviar diretamente a jornalistas (3-4 frases máximo, personalizável)",
  "target_outlets": ["Lista de 5 tipos de veículos/sites do nicho que poderiam cobrir essa pauta"]
}

REGRAS:
- NÃO use clichês ("revolucionário", "inovador", "à frente da curva", "no mundo de hoje")
- NÃO invente dados que não estão no post
- Sempre baseie afirmações no conteúdo fornecido
- Tom: profissional, factual, com substância
- Inclua o link {$context['url']} no body
- RETORNE APENAS O JSON, nada mais
PROMPT;
    }

    /**
     * Parsear resposta JSON da IA.
     */
    private static function parse_response(string $raw): array {
        $clean = trim($raw);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/```\s*$/', '', $clean);
        $clean = trim($clean);

        $parsed = json_decode($clean, true);

        if (!is_array($parsed)) {
            // Tentar extrair JSON entre primeira { e última }
            if (preg_match('/\{[\s\S]*\}/', $clean, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }

        if (!is_array($parsed)) return [];

        // Sanitizar campos
        $fields = ['subject_line', 'headline', 'subheadline', 'lead', 'body',
                   'boilerplate', 'social_linkedin', 'social_twitter', 'pitch_email'];

        $package = [];
        foreach ($fields as $f) {
            $package[$f] = isset($parsed[$f]) ? (string)$parsed[$f] : '';
        }
        $package['target_outlets'] = is_array($parsed['target_outlets'] ?? null)
            ? array_slice(array_map('strval', $parsed['target_outlets']), 0, 10)
            : [];

        return $package;
    }

    /**
     * Listar press releases já gerados para um post.
     */
    public static function list_for_post(int $post_id): array {
        $list = get_post_meta($post_id, '_sara_press_releases', true);
        return is_array($list) ? $list : [];
    }
}
